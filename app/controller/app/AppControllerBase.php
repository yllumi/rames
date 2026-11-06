<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\UserStore;
use app\library\Billing\BillingGate;
use app\library\Billing\InsufficientCredits;
use app\library\Billing\Pricing;
use app\library\Billing\ServerRates;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\DeploySpawner;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\ServerAddress;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use RuntimeException;
use support\Request;

/**
 * Basis bersama seluruh controller fitur app (`app\controller\app\*`).
 *
 * Kelas ini **hanya** memuat helper yang dipakai lebih dari satu controller
 * fitur: resolusi app & server, render view, redaksi galat agent, guard
 * billing, orkestrasi daftar file compose, nama container, serta spawn worker
 * deploy. Helper yang hanya dipakai satu controller tinggal di controller itu.
 *
 * Pola lama tidak berubah: controller mediator, tanpa state properti
 * lintas-request (Webman persistent, lihat copilot-instructions) — seluruh
 * logika bisnis tetap di `app/library/**`.
 */
abstract class AppControllerBase
{
    /** Registry server (seam uji: path store temp). */
    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver();
    }

    /**
     * Render view (seam uji — `view()` butuh konteks HTTP).
     *
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): \Webman\Http\Response
    {
        return view($template, $vars);
    }

    /**
     * Alamat publik server pemilik app — target DNS record yang boleh
     * ditampilkan ke user saat custom domain disimpan/dilihat.
     *
     * Satu pintu: {@see ServerAddress} (GOTCHA no. 25 — hanya alamat; token/
     * PEM/`base_url` utuh tidak pernah keluar) dan **tanpa resolusi DNS**
     * (jalur render worker persistent). `protected` = seam uji: tes controller
     * bisa menyuntik nilai tanpa menyentuh registry nyata.
     *
     * @param array<string,mixed> $app
     */
    protected function serverAddressForApp(array $app, ?ServerResolver $resolver = null): string
    {
        return ServerAddress::forApp($app, $resolver);
    }

    /**
     * Server target untuk app **yang sudah ada** (jalur app-scoped). Fail-fast
     * bila `server_id` app menunjuk server yang hilang.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    protected function serverForApp(array $app): array
    {
        return (new ServerResolver())->resolveForApp($app);
    }

    /**
     * Dokumen server app untuk **redaksi** pesan galat (bukan keputusan hak).
     *
     * Server hilang dari registry / dokumen cacat ⇒ `[]`;
     * `AgentError::sanitizeForServer()` dengan dokumen kosong berperilaku seperti
     * `sanitize()` biasa, jadi pemanggil tidak perlu bercabang.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function agentServerDocFor(array $app): array
    {
        try {
            return $this->serverForApp($app);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Pesan galat aksi app yang tampil ke user (flash/JSON/view).
     *
     * Jalur agent menyisipkan `base_url`/host internal pada galat transport
     * (`AgentClient`) dan blok PEM bisa ikut terpantul — redaksi **wajib** lewat
     * satu pintu `AgentError::sanitizeForServer()`. Untuk app lokal hasilnya
     * no-op (server tanpa `base_url`/token), jadi aman dipakai tanpa percabangan.
     *
     * @param array<string,mixed> $app
     */
    protected function appErrorText(array $app, \Throwable|string $error): string
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : $error;

        return AgentError::sanitizeForServer($message, $this->agentServerDocFor($app));
    }

    /**
     * Direktori artefak app **di host ini** untuk jalur yang masih menulis file
     * lokal (network/nama container, dan cabang **lokal** env/limits) — atau
     * `null` + flash error.
     *
     * Multi-server F1/F2: jalur-jalur itu menulis berkas di server lokal, jadi
     * untuk app di server **agent** operasinya ditolak **eksplisit** dengan
     * alasan sesungguhnya ("belum aktif untuk server agent (fase F2/F3)") —
     * bukan pesan menyesatkan "Direktori app tidak ada" yang membuat user
     * mengira app/direktori sudah terhapus. Perilaku server lokal **tidak
     * berubah** (pesan & alur lama).
     *
     * Jalur env & limits kini **server-aware**: pemanggilnya (`saveEnv`,
     * `uploadEnv`, `importEnv`, `saveLimits`) memutuskan lebih dulu berdasarkan
     * `ServerResolver::isLocal()` sehingga app agent tidak lagi sampai ke guard
     * ini (yang berarti menulis artefak lokal untuk server yang salah).
     *
     * @param array<string,mixed> $app
     * @param string              $feature nama fitur untuk pesan galat
     * @return string|null direktori app, atau null bila sudah ditolak (flash error ditulis)
     */
    protected function appArtifactDirOrFail(array $app, string $feature): ?string
    {
        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            // `server_id` app menunjuk server yang hilang dari registry: tolak
            // eksplisit (bukan 500 mentah) & jangan sentuh apa pun.
            flash_set('error', $feature . ' tidak bisa dijalankan: ' . $e->getMessage());

            return null;
        }

        if (!$local) {
            flash_set(
                'error',
                $feature . ' belum aktif untuk server agent (fase F2/F3) — jalur ini masih menulis '
                . 'artefak app di server lokal, jadi tidak dijalankan agar tidak menyentuh server yang salah.'
            );

            return null;
        }

        $dir = (string) config('deploy.apps_path') . '/' . (string) ($app['name'] ?? '');
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');

            return null;
        }

        return $dir;
    }

    /**
     * Data service + limit base compose app dari **server agent** (satu panggilan
     * HTTP `GET /apps/{id}/services`).
     *
     * `protected` = seam uji: subclass dapat menimpanya supaya render detail &
     * simpan batas bisa diuji tanpa jaringan (pola `renderView()`).
     *
     * @param array<string,mixed> $app
     * @return array{services:array<int,string>,detected:array<string,array{cpus:?float,memory_mb:?int}>,error:?string}
     * @throws AgentError|RuntimeException
     */
    protected function agentAppServices(array $app): array
    {
        $deployer = DeployerFactory::createForApp($app);
        if (!$deployer instanceof AgentDeployer) {
            throw new RuntimeException('App ini tidak berjalan di server agent.');
        }

        return $deployer->appServices($app);
    }

    /**
     * Urutkan compose_files: base compose → override reset/ports/names/limits →
     * override lain (network, env). Override env wajib paling akhir agar tetap
     * menang atas file repo.
     *
     * @param array<int,string> $files
     * @return array<int,string>
     */
    protected function orderComposeFiles(array $files): array
    {
        return ComposeSource::orderFiles($files);
    }

    /**
     * Ambil app + pastikan user berhak melakukan `ability`.
     *
     * Melempar AppAccessDenied (dirender sebagai 404 oleh webman) bila app tidak
     * ada ATAU tidak boleh diakses — supaya keberadaan app milik user lain tidak
     * bocor lewat perbedaan pesan 403 vs 404.
     *
     * @throws AppAccessDenied
     */
    protected function findApp(string $id, string $ability): array
    {
        $app = (new AppStore())->find($id);
        if ($app === null) {
            throw new AppAccessDenied($ability, $id);
        }
        AppAccess::require($ability, $app, current_user());

        return $app;
    }

    /**
     * Gerbang kredit untuk jalur **membuat** app (create/confirm & template).
     * `$limits` wajib sudah divalidasi & (untuk member) diberi basis default.
     * Melempar `InsufficientCredits` — blokir **fungsional**, bukan otorisasi
     * (`AppAccess` tetap satu-satunya pintu hak akses); pemanggil menanganinya
     * lewat `billingBlocked()`.
     *
     * `$gate` hanya untuk pengujian (inject); produksi memakai default.
     * `$serverId` = server target create — gate memakai **tarif server itu**
     * (fallback tarif global bila server tak punya tarif).
     *
     * @param array<int|string,mixed> $limits
     * @throws InsufficientCredits
     */
    protected function billingAssertCanCreate(
        array $limits,
        ?array $user,
        ?BillingGate $gate = null,
        ?string $serverId = null
    ): void {
        $gate ??= new BillingGate();

        // Aktor bebas tagihan (admin) tidak pernah diblokir kredit — eksplisit,
        // meski `BillingGate` juga sudah mengecualikannya.
        if ($gate->isExempt($user)) {
            return;
        }

        $gate->assertCanCreate($limits, $user, $serverId);
    }

    /**
     * Gerbang kredit untuk app yang **sudah ada** (rebuild/rollback/start/simpan
     * compose/env/network/nama/limits) — limit dibaca dari field `limits` app.
     *
     * Urutan keputusan (kebijakan produk: admin bebas kredit & app milik admin
     * tidak dibatasi kredit siapa pun):
     *  1. aktor admin ⇒ **tidak pernah** diblokir kredit;
     *  2. app milik user bebas tagihan (admin) ⇒ bebas untuk siapa pun;
     *  3. aktor member wajib punya saldo;
     *  4. penanggung biaya (owner) yang berbeda dari aktor tetap dinilai —
     *     konsisten dengan penilaian ulang di worker `cli/deploy.php`.
     *
     * @throws InsufficientCredits
     */
    protected function billingAssertCanStart(array $app, ?array $user, ?BillingGate $gate = null, ?UserStore $users = null): void
    {
        if ($user === null) {
            // Tanpa konteks user login: penilaian pemilik dilakukan worker
            // `cli/deploy.php` (gerbang kredit bukan lapisan autentikasi).
            return;
        }

        $gate ??= new BillingGate(null, null, $users);

        // (1) Aktor admin bebas kredit — tidak pernah diblokir, bahkan untuk app
        //     milik member yang saldonya kurang.
        if ($gate->isExempt($user)) {
            return;
        }

        $payer = $this->billingPayerFor($app, $user, $users);

        // (2) App milik user bebas tagihan (admin) ⇒ bebas untuk siapa pun,
        //     tanpa memandang saldo aktor.
        if ($payer !== null && $gate->isExempt($payer)) {
            return;
        }

        // (3) Aktor member wajib punya kredit.
        $gate->assertCanStart($app, $user);

        // (4) Penanggung biaya (owner) — app yang dibagikan tetap ditagih ke
        //     ownernya, selaras dengan penilaian ulang di worker.
        if ($payer !== null && (string) ($payer['id'] ?? '') !== (string) ($user['id'] ?? '')) {
            $gate->assertCanStart($app, $payer);
        }
    }

    /**
     * User yang menanggung biaya sebuah app: **owner** bila diketahui, selain itu
     * aktor saat ini (app tanpa owner tetap dinilai atas nama aktor, dan user
     * `null` tetap dilewatkan `BillingGate` — bukan lapisan autentikasi).
     */
    protected function billingPayerFor(array $app, ?array $user, ?UserStore $users = null): ?array
    {
        $ownerId = trim((string) ($app['owner_id'] ?? ''));
        if ($ownerId === '') {
            return $user;
        }
        if ($user !== null && (string) ($user['id'] ?? '') === $ownerId) {
            return $user;
        }

        try {
            return ($users ?? new UserStore())->findPublicById($ownerId) ?? $user;
        } catch (\Throwable) {
            // `auth.json` tak terbaca/korup: jatuh ke aktor, jangan gagalkan aksi
            // (AuthMiddleware tetap penjaga utama lapisan autentikasi).
            return $user;
        }
    }

    /**
     * Bentuk respons blokir kredit: 402 JSON untuk AJAX/`/api/*`, flash pesan +
     * redirect `/credits` untuk form biasa. Pesan (saldo, kebutuhan minimum, cara
     * menambah kredit) sudah disusun `InsufficientCredits`.
     */
    protected function billingBlocked(Request $request, InsufficientCredits $e): \Webman\Http\Response
    {
        if ($request->expectsJson()) {
            return json(['code' => 402, 'error' => $e->getMessage()])->withStatus(402);
        }

        flash_set('error', $e->getMessage());

        return redirect('/credits');
    }

    /**
     * Apakah user tunduk pada plafon & basis limit billing (member login)?
     * Admin gratis dan user null (tanpa konteks login) dikecualikan — resolusi
     * role memakai `BillingGate::roleIsExempt()` (role legacy tanpa field `role`
     * di-resolve lewat `UserStore`), bukan `$user['role']` mentah.
     */
    protected function isBillingMember(?array $user, ?UserStore $users = null): bool
    {
        return $user !== null && !BillingGate::roleIsExempt($user, $users);
    }

    /**
     * Tarif CPU/RAM untuk `$serverId` (server target/penyimpanan app).
     *
     * `null`/kosong ⇒ tarif global tanpa membaca registry (perilaku lama &
     * hemat I/O untuk pemanggil yang belum punya konteks server). Server tak
     * dikenal/tanpa tarif ⇒ fallback global (`Pricing`/`ServerRates`).
     *
     * @return array{cpu:float,ram:float}
     */
    protected function pricingRatesFor(?string $serverId = null): array
    {
        $id = $serverId === null ? '' : trim($serverId);
        if ($id === '') {
            return Pricing::rates();
        }

        return (new ServerRates())->ratesFor($id);
    }

    /**
     * Id user yang sedang login ('' bila tak ada konteks login). Dipakai untuk
     * meneruskan identitas aktor ke worker deploy (argv[4]) supaya controller &
     * worker memakai pengecualian kredit yang sama (admin bebas kredit).
     */
    protected function currentActorId(): string
    {
        $user = current_user();

        return $user === null ? '' : (string) ($user['id'] ?? '');
    }

    /**
     * Spawn background worker deploy (detached, non-blocking).
     *
     * Satu implementasi bersama ada di
     * {@see \app\library\Deploy\DeployWorkerLauncher} (dipakai juga oleh
     * `AgentJobController` di server agent, misi F1) — di sini hanya delegasi,
     * supaya perilaku spawn tidak punya dua salinan yang bisa menyimpang.
     * Mekanisme & alasannya (pcntl_fork + pcntl_exec, SIGCHLD lewat
     * `SigchldGuard`, log ke `runtime/logs/deploy/{appId}.log`) ada di
     * docblock kelas launcher.
     *
     * @param DeployWorkerLauncher|null $launcher seam uji: launcher dengan spawn
     *                                            palsu (null = bawaan). Dipakai
     *                                            jalur create agar tes tidak
     *                                            benar-benar membuat proses.
     */
    protected function spawnWorker(
        string $appId,
        string $mode,
        ?string $arg = null,
        ?string $actorId = null,
        ?DeployWorkerLauncher $launcher = null
    ): bool {
        return (new DeploySpawner($launcher))->spawn($appId, $mode, $arg, (string) $actorId);
    }

    /**
     * SHA versi yang sedang aktif = entri sukses/restored terakhir di history.
     * Dipakai untuk menampilkan "versi aktif" dan mencegah rollback ke versi aktif.
     */
    protected function resolveActiveSha(array $app): string
    {
        foreach (array_reverse($app['deploy_history'] ?? []) as $h) {
            if (in_array(($h['status'] ?? ''), ['success', 'restored'], true)) {
                return (string) ($h['sha'] ?? '');
            }
        }
        return '';
    }

    /**
     * Hapus direktori beserta isinya (hanya dipakai untuk area apps_path).
     */
    protected function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
