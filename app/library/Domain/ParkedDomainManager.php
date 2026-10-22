<?php
declare(strict_types=1);

namespace app\library\Domain;

use app\library\Nginx\NginxConfigGenerator;
use app\library\Nginx\NginxReloader;
use app\library\Nginx\ParkedNginxConfig;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use RuntimeException;
use Throwable;

/**
 * Orkestrasi domain parking: validasi → simpan metadata → tulis konten →
 * tulis config Nginx → reload, dengan **rollback transaksional** bila salah
 * satu tahap gagal (pola "uji dulu" ala {@see \app\library\Nginx\NginxConfigGuard}).
 *
 * Mengapa rollback wajib: `NginxReloader::reload()` menjalankan `nginx -t` atas
 * SELURUH config host. Satu file `_parked_*.conf` invalid yang tertinggal di
 * disk memblokir reload Nginx seluruh host (semua app + renewal SSL ikut
 * macet). Karena itu config yang gagal diuji harus dibatalkan bersama metadata
 * dan berkas kontennya.
 *
 * Fase 1 hanya server lokal (`server_id='local'`); entri server lain ditolak
 * fail-fast tanpa efek samping.
 *
 * API (`create`/`update`/`delete`) **tidak pernah melempar** — selalu
 * mengembalikan array dengan kunci `ok`, `error`, `reloaded`, `rolled_back`
 * (+ `created`/`domain` sesuai operasi). Pesan `error` berbahasa Indonesia:
 * penyebab + tindakan perbaikan. Bila rollback gagal, `error` memuat
 * `PERINGATAN: rollback gagal: …`.
 *
 * instance & tanpa state lintas-request — semua dependensi disuntikkan
 * (`ParkedDomainStore`, `AppStore`, `NginxReloader`, `NginxConfigGenerator`,
 * `ParkedDomainContent`, `SslIssuer` opsional) sehingga aman untuk worker
 * Webman persistent dan dapat diganti fake di tes.
 */
final class ParkedDomainManager
{
    private const MODE_HTML = 'html';
    private const MODE_REDIRECT = 'redirect';
    private const LOCAL_SERVER = 'local';

    /** Pesan baku fase 1 (dipakai create/update/delete). */
    public const LOCAL_ONLY_MESSAGE = 'Fase 1 hanya mendukung server lokal.';

    private readonly ParkedNginxConfig $config;

    /**
     * @param SslIssuer|null $issuer pemanggil `revoke()` best-effort saat domain
     *        berganti/dihapus. `null` = tanpa revoke (tes lama & pemanggil yang
     *        tidak mengelola sertifikat); runtime mengirim `new SslIssuer()`
     *        (`DomainController::manager()`).
     */
    public function __construct(
        private readonly ParkedDomainStore $store,
        private readonly AppStore $apps,
        private readonly NginxReloader $reloader,
        private readonly NginxConfigGenerator $nginxWriter,
        private readonly ParkedDomainContent $content,
        private readonly ?SslIssuer $issuer = null,
    ) {
        $this->config = new ParkedNginxConfig($nginxWriter);
    }

    /**
     * Buat 1–2 entri parkir (opsi `add_www` membuat entri kedua dengan isi
     * identik tetapi id/direktori sendiri).
     *
     * Urutan: validasi & keunikan → prasyarat direktori (mode html) →
     * simpan entri → tulis konten → tulis config tiap entri → reload SEKALI.
     * Gagal di tahap mana pun setelah entri disimpan ⇒ rollback (hapus config
     * yang sempat ditulis, hapus direktori konten, hapus entri).
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,error:string,created:array<int,array<string,mixed>>,reloaded:bool,rolled_back:bool}
     */
    public function create(array $input, string $actorId = ''): array
    {
        $serverId = trim(is_scalar($input['server_id'] ?? null) ? (string) $input['server_id'] : self::LOCAL_SERVER);
        if ($serverId !== '' && $serverId !== self::LOCAL_SERVER) {
            return $this->createFailure(self::LOCAL_ONLY_MESSAGE);
        }

        try {
            $payload = $this->normalizeInput($input);
        } catch (Throwable $e) {
            return $this->createFailure($e->getMessage());
        }

        $addWww = self::truthy($input['add_www'] ?? false);
        if ($addWww && str_starts_with($payload['domain'], 'www.')) {
            return $this->createFailure(
                'Domain "' . $payload['domain'] . '" sudah berawalan "www." — opsi tambah www tidak berlaku. '
                . 'Daftarkan domain tanpa awalan "www." lalu aktifkan opsi tambah www.'
            );
        }

        $domains = [$payload['domain']];
        if ($addWww) {
            $domains[] = 'www.' . $payload['domain'];
        }

        try {
            // Keunikan dulu (tanpa efek samping), baru prasyarat direktori
            // (ensureWritable boleh membuat direktori akar).
            foreach ($domains as $domain) {
                ParkedDomainValidator::assertDomainAvailable($domain, $this->apps, $this->store);
            }
            if ($payload['mode'] === self::MODE_HTML) {
                $this->content->ensureWritable();
            }
        } catch (Throwable $e) {
            return $this->createFailure($e->getMessage());
        }

        $webroot = $this->webroot();
        /** @var array<int,array<string,mixed>> $created */
        $created = [];
        /** @var array<int,string> $contentIds */
        $contentIds = [];
        /** @var array<int,string> $configIds */
        $configIds = [];
        $stage = 'menyimpan entri domain parkir';

        try {
            foreach ($domains as $domain) {
                $created[] = $this->store->create([
                    'domain' => $domain,
                    'mode' => $payload['mode'],
                    'redirect_to' => $payload['redirect_to'],
                    'redirect_code' => $payload['redirect_code'],
                    'server_id' => self::LOCAL_SERVER,
                    'created_by' => trim($actorId),
                    // State SSL selalu ada sejak entri dibuat: satu bentuk data
                    // untuk UI/worker, tanpa perlu menebak kunci yang hilang.
                    'ssl_status' => 'disabled',
                    'ssl_stage' => null,
                    'ssl_message' => null,
                    'ssl_error' => null,
                    'ssl_expires_at' => null,
                ]);
            }

            if ($payload['mode'] === self::MODE_HTML) {
                $stage = 'menulis konten HTML';
                foreach ($created as $entry) {
                    $id = (string) ($entry['id'] ?? '');
                    $contentIds[] = $id;
                    $this->content->write($id, (string) $payload['html']);
                }
            }

            $stage = 'menulis config Nginx';
            foreach ($created as $entry) {
                $id = (string) ($entry['id'] ?? '');
                $configIds[] = $id;
                $this->config->write($entry, $this->content->dirFor($id), $webroot, $this->letsencryptPath());
            }

            $stage = 'reload Nginx';
            $reload = $this->runReload();
            if (!$reload['ok']) {
                $rollback = $this->rollbackCreate($created, $configIds, $contentIds);

                return [
                    'ok' => false,
                    'error' => $this->withRollbackWarning(
                        'Config Nginx ditolak: ' . $reload['error'] . ' — domain parkir tidak dibuat.',
                        $rollback
                    ),
                    'created' => [],
                    'reloaded' => false,
                    'rolled_back' => $rollback['rolled_back'],
                ];
            }
        } catch (Throwable $e) {
            $rollback = $this->rollbackCreate($created, $configIds, $contentIds);

            return [
                'ok' => false,
                'error' => $this->withRollbackWarning(
                    'Gagal ' . $stage . ': ' . $e->getMessage(),
                    $rollback
                ),
                'created' => [],
                'reloaded' => false,
                'rolled_back' => $rollback['rolled_back'],
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'created' => $created,
            'reloaded' => true,
            'rolled_back' => false,
        ];
    }

    /**
     * Ubah satu entri (domain/mode/isi). `add_www` **diabaikan** di sini —
     * menambah/menghapus varian www dilakukan lewat create/delete terpisah
     * agar semantik "entri kedua" tidak berubah diam-diam.
     *
     * Urutan: snapshot entri + konten lama → persist → tulis konten baru
     * (mode html) → tulis config → reload. Gagal ⇒ pulihkan entri, pulihkan
     * konten lama (atau buang konten baru), tulis ulang config lama, JANGAN
     * reload. Bila mode berubah html → redirect, konten lama dihapus hanya
     * SETELAH reload sukses.
     *
     * Rename domain (dibandingkan case-insensitive) me-reset state `ssl_*`
     * karena sertifikat terikat domain lama, lalu me-revoke sertifikat lama
     * best-effort setelah sukses (butuh `$issuer`); kegagalan revoke hanya
     * menambah `PERINGATAN` di `error` dengan `ok=true`. Rollback memulihkan
     * seluruh snapshot termasuk field `ssl_*`.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,error:string,domain:?array<string,mixed>,reloaded:bool,rolled_back:bool}
     */
    public function update(string $id, array $input): array
    {
        try {
            $entry = $this->store->find($id);
        } catch (Throwable $e) {
            return $this->updateFailure('Gagal membaca entri domain parkir: ' . $e->getMessage());
        }
        if ($entry === null) {
            return $this->updateFailure(
                'Entri domain parkir tidak ditemukan: ' . $id . '. Muat ulang halaman lalu coba lagi.'
            );
        }
        if ($this->isNonLocal($entry, $input)) {
            return $this->updateFailure(self::LOCAL_ONLY_MESSAGE);
        }

        try {
            $payload = $this->normalizeInput($input);
            if ($payload['mode'] === self::MODE_HTML) {
                $this->content->ensureWritable();
            }
            ParkedDomainValidator::assertDomainAvailable($payload['domain'], $this->apps, $this->store, $id);
        } catch (Throwable $e) {
            return $this->updateFailure($e->getMessage());
        }

        $oldMode = strtolower(trim(is_scalar($entry['mode'] ?? null) ? (string) $entry['mode'] : ''));
        $oldDomain = strtolower(trim(is_scalar($entry['domain'] ?? null) ? (string) $entry['domain'] : ''));
        // Sertifikat terikat ke nama domain: rename wajib membuang state SSL
        // lama, sedangkan perubahan lain (mode/HTML/target) mempertahankannya.
        $domainChanged = $oldDomain !== $payload['domain'];
        $oldHtml = null;
        try {
            if ($oldMode === self::MODE_HTML) {
                $oldHtml = $this->content->read($id);
            }
        } catch (Throwable $e) {
            return $this->updateFailure(
                'Gagal membaca konten HTML lama: ' . $e->getMessage() . ' — perubahan dibatalkan.'
            );
        }

        try {
            $updated = $this->store->update($id, static function (array &$row) use ($payload, $domainChanged): void {
                $row['domain'] = $payload['domain'];
                $row['mode'] = $payload['mode'];
                $row['redirect_to'] = $payload['redirect_to'];
                $row['redirect_code'] = $payload['redirect_code'];
                if ($domainChanged) {
                    // Sertifikat domain lama tidak berlaku untuk domain baru.
                    $row['ssl_status'] = 'disabled';
                    $row['ssl_stage'] = null;
                    $row['ssl_message'] = null;
                    $row['ssl_error'] = null;
                    $row['ssl_expires_at'] = null;
                }
            });
        } catch (Throwable $e) {
            return $this->updateFailure('Gagal menyimpan perubahan domain parkir: ' . $e->getMessage());
        }

        $contentWritten = false;
        $stage = 'menulis konten HTML';
        try {
            if ($payload['mode'] === self::MODE_HTML) {
                $this->content->write($id, (string) $payload['html']);
                $contentWritten = true;
            }

            $stage = 'menulis config Nginx';
            $this->config->write($updated, $this->content->dirFor($id), $this->webroot(), $this->letsencryptPath());

            $stage = 'reload Nginx';
            $reload = $this->runReload();
            if (!$reload['ok']) {
                return $this->rollbackUpdate(
                    $id,
                    $entry,
                    $oldMode,
                    $oldHtml,
                    $payload['mode'],
                    $contentWritten,
                    'Config Nginx ditolak: ' . $reload['error'] . ' — perubahan dibatalkan.'
                );
            }
        } catch (Throwable $e) {
            return $this->rollbackUpdate(
                $id,
                $entry,
                $oldMode,
                $oldHtml,
                $payload['mode'],
                $contentWritten,
                'Gagal ' . $stage . ': ' . $e->getMessage() . ' — perubahan dibatalkan.'
            );
        }

        $notes = [];
        if ($payload['mode'] === self::MODE_REDIRECT && $oldMode === self::MODE_HTML) {
            try {
                $this->content->remove($id);
            } catch (Throwable $e) {
                $notes[] = 'Catatan: perubahan tersimpan, tetapi konten HTML lama gagal dihapus: ' . $e->getMessage();
            }
        }

        if ($domainChanged) {
            // Revoke best-effort: sertifikat domain lama tidak boleh tetap aktif
            // untuk domain yang tidak lagi dikelola entri ini. Kegagalan revoke
            // hanya jadi peringatan — perubahan domain tetap sukses.
            $warning = $this->revokeCertificate($entry);
            if ($warning !== '') {
                $notes[] = $warning;
            }
        }

        return [
            'ok' => true,
            'error' => implode(' — ', $notes),
            'domain' => $updated,
            'reloaded' => true,
            'rolled_back' => false,
        ];
    }

    /**
     * Hapus satu entri: hapus metadata → hapus config → reload. Gagal ⇒
     * pulihkan entri + tulis ulang config lama (tanpa reload). Sukses ⇒ hapus
     * direktori konten best-effort (kegagalannya hanya catatan di `error`,
     * tidak membatalkan).
     *
     * @return array{ok:bool,error:string,reloaded:bool,rolled_back:bool}
     */
    public function delete(string $id): array
    {
        try {
            $entry = $this->store->find($id);
        } catch (Throwable $e) {
            return $this->deleteFailure('Gagal membaca entri domain parkir: ' . $e->getMessage());
        }
        if ($entry === null) {
            return $this->deleteFailure(
                'Entri domain parkir tidak ditemukan: ' . $id . '. Mungkin sudah dihapus — muat ulang halaman.'
            );
        }
        if ($this->isNonLocal($entry, [])) {
            return $this->deleteFailure(self::LOCAL_ONLY_MESSAGE);
        }

        $webroot = $this->webroot();

        try {
            $this->store->delete($id);
            $this->config->remove($id);
            $reload = $this->runReload();
            if (!$reload['ok']) {
                throw new RuntimeException('Config Nginx ditolak: ' . $reload['error']);
            }
        } catch (Throwable $e) {
            $warnings = [];

            try {
                $this->store->create($entry);
            } catch (Throwable $restoreError) {
                $warnings[] = 'PERINGATAN: rollback gagal: ' . $restoreError->getMessage();
            }

            try {
                // Selalu tulis ulang config lama: config mungkin sudah dihapus
                // sebelum reload ditolak.
                $this->config->write($entry, $this->content->dirFor($id), $webroot, $this->letsencryptPath());
            } catch (Throwable $writeError) {
                $warnings[] = 'config lama gagal ditulis ulang: ' . $writeError->getMessage()
                    . ' (periksa/terapkan ulang secara manual)';
            }

            return [
                'ok' => false,
                'error' => $this->withWarnings(
                    'Gagal menghapus domain parkir: ' . $e->getMessage() . ' — entri dipulihkan.',
                    $warnings
                ),
                'reloaded' => false,
                'rolled_back' => !$this->hasRollbackFailure($warnings),
            ];
        }

        $notes = [];
        try {
            $this->content->remove($id);
        } catch (Throwable $e) {
            $notes[] = 'Catatan: entri & config terhapus, tetapi konten HTML gagal dibersihkan: ' . $e->getMessage();
        }

        // Revoke best-effort SETELAH penghapusan sukses (termasuk reload):
        // kegagalan revoke tidak boleh mengembalikan entri/config yang sudah
        // bersih — cukup peringatan di `error` dengan `ok=true`.
        $warning = $this->revokeCertificate($entry);
        if ($warning !== '') {
            $notes[] = $warning;
        }

        return [
            'ok' => true,
            'error' => implode(' — ', $notes),
            'reloaded' => true,
            'rolled_back' => false,
        ];
    }

    // ==================================================================
    // Validasi input
    // ==================================================================

    /**
     * Normalisasi + validasi input mentah (dipakai create & update).
     *
     * @param array<string,mixed> $input
     * @return array{domain:string,mode:string,html:?string,redirect_to:?string,redirect_code:int}
     *
     * @throws RuntimeException pesan Indonesia (penyebab + tindakan)
     */
    private function normalizeInput(array $input): array
    {
        $domain = ParkedDomainValidator::normalizeDomain(
            is_scalar($input['domain'] ?? null) ? (string) $input['domain'] : ''
        );
        if (!ParkedDomainValidator::isValidDomain($domain)) {
            throw new RuntimeException(
                'Domain tidak valid: "' . $domain . '". Gunakan FQDN publik (mis. contoh.com) tanpa http://, '
                . 'path, wildcard, atau underscore.'
            );
        }

        $mode = is_scalar($input['mode'] ?? null) ? strtolower(trim((string) $input['mode'])) : '';
        if ($mode !== self::MODE_HTML && $mode !== self::MODE_REDIRECT) {
            throw new RuntimeException(
                'Mode tidak valid: "' . $mode . '". Pilih "html" (halaman statis) atau "redirect" (alihkan ke URL lain).'
            );
        }

        if ($mode === self::MODE_HTML) {
            $html = is_string($input['html'] ?? null) ? $input['html'] : '';
            if (trim($html) === '') {
                throw new RuntimeException(
                    'Isi HTML wajib diisi untuk mode HTML. Tempel kode HTML halaman parkir, atau pilih mode redirect.'
                );
            }
            ParkedDomainValidator::assertHtmlSize($html);

            return [
                'domain' => $domain,
                'mode' => self::MODE_HTML,
                'html' => $html,
                'redirect_to' => null,
                'redirect_code' => ParkedDomainValidator::DEFAULT_REDIRECT_CODE,
            ];
        }

        $target = ParkedDomainValidator::normalizeRedirectTarget(
            is_scalar($input['redirect_to'] ?? null) ? (string) $input['redirect_to'] : ''
        );
        if (!ParkedDomainValidator::isValidRedirectTarget($target)) {
            throw new RuntimeException(
                'Target redirect tidak valid: "' . $target . '". Gunakan URL http:// atau https:// tanpa userinfo, '
                . 'query, atau fragmen (mis. https://contoh.com).'
            );
        }

        return [
            'domain' => $domain,
            'mode' => self::MODE_REDIRECT,
            'html' => null,
            'redirect_to' => $target,
            'redirect_code' => ParkedDomainValidator::normalizeRedirectCode($input['redirect_code'] ?? null),
        ];
    }

    // ==================================================================
    // Rollback
    // ==================================================================

    /**
     * Batalkan efek create: config → konten → entri (urutan terbalik dari
     * penulisan). Semua langkah best-effort; kegagalan dikumpulkan.
     *
     * @param array<int,array<string,mixed>> $created
     * @param array<int,string>              $configIds
     * @param array<int,string>              $contentIds
     * @return array{rolled_back:bool,error:string}
     */
    private function rollbackCreate(array $created, array $configIds, array $contentIds): array
    {
        $errors = [];

        foreach (array_reverse($configIds) as $id) {
            try {
                $this->config->remove($id);
            } catch (Throwable $e) {
                $errors[] = 'hapus config ' . $id . ': ' . $e->getMessage();
            }
        }

        foreach (array_reverse($contentIds) as $id) {
            try {
                $this->content->remove($id);
            } catch (Throwable $e) {
                $errors[] = 'hapus konten ' . $id . ': ' . $e->getMessage();
            }
        }

        foreach (array_reverse($created) as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }
            try {
                $this->store->delete($id);
            } catch (Throwable $e) {
                $errors[] = 'hapus entri ' . $id . ': ' . $e->getMessage();
            }
        }

        if ($errors === []) {
            return ['rolled_back' => true, 'error' => ''];
        }

        return ['rolled_back' => false, 'error' => 'PERINGATAN: rollback gagal: ' . implode('; ', $errors)];
    }

    /**
     * Pulihkan state setelah update gagal: entri lama → konten lama (atau buang
     * konten baru) → config lama. Reload TIDAK dipanggil.
     *
     * @param array<string,mixed> $old
     * @return array{ok:bool,error:string,domain:?array<string,mixed>,reloaded:bool,rolled_back:bool}
     */
    private function rollbackUpdate(
        string $id,
        array $old,
        string $oldMode,
        ?string $oldHtml,
        string $newMode,
        bool $contentWritten,
        string $originalError,
    ): array {
        $warnings = [];
        $rolledBack = true;

        try {
            $this->store->update($id, static function (array &$row) use ($old): void {
                // Pulihkan SELURUH field snapshot lama (domain/mode/redirect_* +
                // ssl_*) supaya reset state SSL saat rename ikut dibatalkan.
                // `id` tetap dikunci oleh store setelah mutator.
                $row = $old;
            });
        } catch (Throwable $e) {
            $rolledBack = false;
            $warnings[] = 'PERINGATAN: rollback gagal: ' . $e->getMessage();
        }

        try {
            if ($oldMode === self::MODE_HTML) {
                if ($oldHtml !== null) {
                    $this->content->write($id, $oldHtml);
                } elseif ($contentWritten) {
                    $this->content->remove($id);
                }
            } elseif ($newMode === self::MODE_HTML && $contentWritten) {
                $this->content->remove($id);
            }
        } catch (Throwable $e) {
            $warnings[] = 'konten lama gagal dipulihkan: ' . $e->getMessage();
        }

        try {
            $this->config->write($old, $this->content->dirFor($id), $this->webroot(), $this->letsencryptPath());
        } catch (Throwable $e) {
            $warnings[] = 'config lama gagal ditulis ulang: ' . $e->getMessage()
                . ' (periksa/terapkan ulang secara manual)';
        }

        return [
            'ok' => false,
            'error' => $this->withWarnings($originalError, $warnings),
            'domain' => null,
            'reloaded' => false,
            'rolled_back' => $rolledBack,
        ];
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Jalankan `reload()` (berisi `nginx -t` + reload) tanpa exception bocor.
     *
     * @return array{ok:bool,error:string}
     */
    private function runReload(): array
    {
        try {
            $result = $this->reloader->reload();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (($result['ok'] ?? false) === true) {
            return ['ok' => true, 'error' => ''];
        }

        $error = trim((string) ($result['error'] ?? ''));

        return ['ok' => false, 'error' => $error !== '' ? $error : 'nginx menolak config baru'];
    }

    /**
     * Webroot HTTP-01 — sama dengan yang dipakai config app. Tidak pernah
     * melempar (manager tidak boleh membocorkan exception), termasuk saat
     * helper runtime (`config()`/`base_path()`) belum tersedia.
     */
    private function webroot(): string
    {
        try {
            $value = config('deploy.ssl_webroot', base_path() . '/webroot');
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        } catch (Throwable) {
            // jatuh ke fallback di bawah
        }

        $base = defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 3);

        return $base . '/webroot';
    }

    /**
     * Direktori Let's Encrypt untuk config SSL domain parkir. Tidak pernah
     * melempar (manager tidak boleh membocorkan exception), termasuk saat
     * helper runtime (`config()`) belum tersedia.
     */
    private function letsencryptPath(): string
    {
        try {
            $value = config('deploy.letsencrypt_path', '/etc/letsencrypt');
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        } catch (Throwable) {
            // jatuh ke fallback di bawah
        }

        return '/etc/letsencrypt';
    }

    /**
     * Entri milik server non-lokal (fase 1 ditolak) — dicek dari metadata
     * tersimpan maupun input yang mencoba memindahkannya.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $input
     */
    private function isNonLocal(array $entry, array $input): bool
    {
        $stored = trim(is_scalar($entry['server_id'] ?? null) ? (string) $entry['server_id'] : '');
        if ($stored !== '' && $stored !== self::LOCAL_SERVER) {
            return true;
        }

        $requested = trim(is_scalar($input['server_id'] ?? null) ? (string) $input['server_id'] : '');

        return $requested !== '' && $requested !== self::LOCAL_SERVER;
    }

    /**
     * Revoke sertifikat domain dari snapshot entri — **best-effort**: tidak
     * pernah melempar. Dipanggil setelah operasi sukses (rename/delete) dan
     * hanya bila issuer disuntikkan.
     *
     * Revoke dijalankan saat entri berstatus `active` **atau** sertifikat
     * domain masih ada di disk (status bisa saja belum tersinkron, mis. entri
     * lama sebelum state SSL ditambahkan).
     *
     * @param array<string,mixed> $entry snapshot entri (domain lama untuk rename)
     * @return string peringatan berawalan `PERINGATAN:`, atau '' bila sukses/tidak perlu
     */
    private function revokeCertificate(array $entry): string
    {
        if ($this->issuer === null) {
            return '';
        }

        $domain = strtolower(trim(is_scalar($entry['domain'] ?? null) ? (string) $entry['domain'] : ''));
        if ($domain === '') {
            return '';
        }

        try {
            $status = strtolower(trim(is_scalar($entry['ssl_status'] ?? null) ? (string) $entry['ssl_status'] : ''));
            if ($status !== 'active' && !$this->issuer->certExists($domain)) {
                return '';
            }
            $this->issuer->revoke($domain);
        } catch (Throwable $e) {
            return 'PERINGATAN: revoke sertifikat lama ' . $domain . ' gagal: ' . $e->getMessage();
        }

        return '';
    }

    /**
     * Gabungkan error asli dengan peringatan rollback (bila ada).
     *
     * @param array{rolled_back:bool,error:string} $rollback
     */
    private function withRollbackWarning(string $error, array $rollback): string
    {
        if ($rollback['rolled_back']) {
            return $error;
        }

        return $error . ' — ' . $rollback['error'];
    }

    /**
     * @param array<int,string> $warnings
     */
    private function withWarnings(string $error, array $warnings): string
    {
        return $warnings === [] ? $error : $error . ' — ' . implode('; ', $warnings);
    }

    /**
     * @param array<int,string> $warnings
     */
    private function hasRollbackFailure(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if (str_contains($warning, 'PERINGATAN: rollback gagal')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nilai "truthy" dari form/JSON (`1`, `true`, `on`, `yes`, `true` bool).
     */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    /**
     * @return array{ok:bool,error:string,created:array<int,array<string,mixed>>,reloaded:bool,rolled_back:bool}
     */
    private function createFailure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'created' => [], 'reloaded' => false, 'rolled_back' => false];
    }

    /**
     * @return array{ok:bool,error:string,domain:?array<string,mixed>,reloaded:bool,rolled_back:bool}
     */
    private function updateFailure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'domain' => null, 'reloaded' => false, 'rolled_back' => false];
    }

    /**
     * @return array{ok:bool,error:string,reloaded:bool,rolled_back:bool}
     */
    private function deleteFailure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'reloaded' => false, 'rolled_back' => false];
    }
}
