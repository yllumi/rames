<?php
declare(strict_types=1);

namespace app\library\Agent;

use app\library\Storage\JsonStore;

/**
 * Registry job **di sisi agent** (plan §4.1/§4.2) — fondasi `POST /jobs` (F1).
 *
 * Satu job = satu berkas JSON (`{dir}/{id}.json`) yang ditulis lewat
 * {@see JsonStore} (atomik + `flock` + backup `.bak`) — **bukan**
 * `file_put_contents` manual. Log job ada di berkas terpisah
 * (`{dir}/{id}.log`) supaya penulisan log tidak menulis ulang rekaman.
 *
 * Bentuk rekaman:
 * ```
 * {id, op, app_id, status, stage, message, error, created_at, started_at, finished_at, payload}
 * ```
 * `status` ∈ `pending|running|done|error` (lihat {@see self::STATUSES}).
 *
 * **Higienitas kredensial**: rekaman job hanya menyimpan metadata operasi.
 * `payload` disimpan **setelah diredaksi rekursif** — nilai dengan kunci
 * mencurigakan (`token`, `password`, `secret`, `*key*` termasuk `APP_KEY`,
 * `authorization`, `signature`, `credential`) diganti `[redacted]` sehingga
 * token/kredensial tidak pernah masuk `runtime/agent-jobs/*.json`. Ini metadata,
 * bukan sumber kebenaran app (proyeksi app tetap di `AppStore` agent).
 *
 * Direktori dapat di-override (uji memakai `/tmp`; **jangan** menyentuh
 * `runtime/` nyata dari test). Tanpa state statik ⇒ aman di worker persistent.
 */
final class AgentJobStore
{
    public const STATUSES = ['pending', 'running', 'done', 'error'];

    /** Plafon baca log per panggilan (256 KiB) — log deploy bisa panjang. */
    public const MAX_LOG_READ = 262144;

    /** Batas offset yang diterima (menahan angka absurd dari query string). */
    public const MAX_LOG_OFFSET = 1073741824; // 1 GiB

    private const DIR_MODE = 0700;

    /** Kunci payload yang nilainya tidak boleh masuk rekaman job. */
    private const SENSITIVE_KEY_PATTERN = '/token|secret|password|passwd|key|authorization|signature|credential/i';

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    private readonly string $dir;

    /**
     * @param string|null $dir direktori job; null = `runtime/agent-jobs`
     */
    public function __construct(?string $dir = null)
    {
        $dir = trim((string) $dir);
        $this->dir = rtrim($dir !== '' ? $dir : self::defaultDir(), '/');
    }

    /** Direktori job (untuk uji/diagnostik). */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Buat rekaman job baru berstatus `pending`.
     *
     * @param string              $op      op agent (mis. `deploy`, `nginx_reload`)
     * @param string              $appId   id app terkait; `''` untuk op sistem
     * @param array<string,mixed> $payload metadata operasi (akan diredaksi)
     * @return array<string,mixed> rekaman yang tersimpan
     * @throws AgentError invalid_config (op kosong)
     */
    public function create(string $op, string $appId, array $payload = []): array
    {
        $op = trim($op);
        if ($op === '') {
            throw AgentError::invalidConfig('op job kosong.');
        }

        $this->ensureDir();

        $id = bin2hex(random_bytes(8));
        $now = date('c');
        $record = [
            'id' => $id,
            'op' => $op,
            'app_id' => $appId,
            'status' => 'pending',
            'stage' => '',
            'message' => '',
            'error' => '',
            'created_at' => $now,
            'started_at' => '',
            'finished_at' => '',
            'payload' => self::redact($payload),
        ];

        $this->store($id)->update(function (array &$data) use ($record): void {
            $data = $record;
        });

        return $record;
    }

    /**
     * Rekaman job, atau `null` bila tidak ada / id tidak sah / berkas korup.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $id): ?array
    {
        $path = $this->existingPath($id);
        if ($path === null) {
            return null;
        }

        try {
            $data = (new JsonStore($path))->read();
        } catch (\Throwable) {
            return null; // berkas korup: jangan jatuhkan polling agent
        }

        return $data === [] ? null : $data;
    }

    /**
     * Update rekaman job (mutator menerima rekaman **by reference**).
     *
     * @param callable(array<string,mixed>&):void $mutator
     * @return array<string,mixed> rekaman setelah update
     * @throws AgentError not_found (job tidak ada / id tidak sah)
     */
    public function update(string $id, callable $mutator): array
    {
        if ($this->existingPath($id) === null) {
            throw AgentError::notFound("Job tidak ditemukan: {$id}");
        }

        $store = $this->store($id);
        $store->update(function (array &$data) use ($mutator, $id): void {
            $mutator($data);
            $data['id'] = $id; // id tidak boleh berubah oleh mutator
        });

        return $this->find($id) ?? [];
    }

    /**
     * Semua rekaman job, terbaru lebih dulu (`created_at`, lalu `id`).
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $records = [];
        $files = glob($this->dir . '/*.json') ?: [];
        foreach ($files as $file) {
            try {
                $data = (new JsonStore((string) $file))->read();
            } catch (\Throwable) {
                continue;
            }
            if ($data !== [] && is_string($data['id'] ?? null)) {
                $records[] = $data;
            }
        }

        usort($records, static function (array $a, array $b): int {
            return [$b['created_at'] ?? '', $b['id'] ?? ''] <=> [$a['created_at'] ?? '', $a['id'] ?? ''];
        });

        return $records;
    }

    /**
     * Path berkas log job.
     *
     * @throws AgentError not_found (id tidak sah)
     */
    public function logPath(string $id): string
    {
        return $this->dir . '/' . $this->safeId($id) . '.log';
    }

    /**
     * Tambahkan satu baris log (append atomic + `LOCK_EX`).
     *
     * Baris dinormalkan ke `\n` dan selalu diakhiri newline supaya pembacaan
     * dari offset selalu jatuh pada batas baris.
     *
     * @throws AgentError not_found (job tidak ada / id tidak sah)
     * @throws \RuntimeException gagal menulis
     */
    public function appendLog(string $id, string $line): void
    {
        if ($this->existingPath($id) === null) {
            throw AgentError::notFound("Job tidak ditemukan: {$id}");
        }

        if ($line === '') {
            return;
        }

        $line = str_replace(["\r\n", "\r"], "\n", $line);
        if (!str_ends_with($line, "\n")) {
            $line .= "\n";
        }

        $path = $this->logPath($id);
        $this->ensureDir();
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Gagal menulis log job {$path}");
        }
    }

    /**
     * Baca log mulai `offset` byte — **maksimal 256 KiB per panggilan**.
     *
     * Bila potongan terbatas itu tidak berakhir di batas baris, hasilnya
     * dipangkas ke newline terakhir (log berbasis baris) supaya pemanggil tidak
     * pernah menerima potongan di tengah baris/UTF-8.
     *
     * @throws AgentError not_found (id tidak sah)
     */
    public function readLog(string $id, int $offset = 0): string
    {
        $path = $this->logPath($id);
        if (!is_file($path)) {
            return '';
        }

        $offset = max(0, min($offset, self::MAX_LOG_OFFSET));
        $size = filesize($path);
        if ($size === false || $offset >= $size) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                return '';
            }
            $chunk = fread($handle, self::MAX_LOG_READ);
        } finally {
            fclose($handle);
        }

        if ($chunk === false || $chunk === '') {
            return '';
        }

        // Berakhir persis di EOF ⇒ tidak ada yang dipangkas.
        if ($offset + strlen($chunk) >= $size || str_ends_with($chunk, "\n")) {
            return $chunk;
        }

        $lastNewline = strrpos($chunk, "\n");
        if ($lastNewline === false || $lastNewline === 0) {
            return $chunk; // satu baris raksasa: serahkan apa adanya
        }

        return substr($chunk, 0, $lastNewline + 1);
    }

    /**
     * Hapus job **selesai** (`done`/`error`) terlama, beserta log-nya, sampai
     * hanya `$keep` job selesai terbaru yang tersisa. Job `pending`/`running`
     * tidak pernah dipangkas.
     *
     * @return int jumlah job yang dihapus
     */
    public function prune(int $keep = 200): int
    {
        $keep = max(0, $keep);

        $finished = [];
        foreach ($this->all() as $record) {
            $status = (string) ($record['status'] ?? '');
            if ($status === 'done' || $status === 'error') {
                $finished[] = $record;
            }
        }

        $excess = count($finished) - $keep;
        if ($excess <= 0) {
            return 0;
        }

        // Terlama dulu: `finished_at`, jatuh ke `created_at`, lalu `id`.
        usort($finished, static function (array $a, array $b): int {
            $aKey = [$a['finished_at'] ?? '', $a['created_at'] ?? '', $a['id'] ?? ''];
            $bKey = [$b['finished_at'] ?? '', $b['created_at'] ?? '', $b['id'] ?? ''];

            return $aKey <=> $bKey;
        });

        $removed = 0;
        foreach (array_slice($finished, 0, $excess) as $record) {
            $id = (string) ($record['id'] ?? '');
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                continue;
            }
            $json = $this->dir . '/' . $id . '.json';
            @unlink($json);
            @unlink($json . '.bak');
            @unlink($this->logPath($id));
            $removed++;
        }

        return $removed;
    }

    private function store(string $id): JsonStore
    {
        return new JsonStore($this->dir . '/' . $this->safeId($id) . '.json');
    }

    /** Path rekaman bila (dan hanya bila) berkasnya ada. */
    private function existingPath(string $id): ?string
    {
        $id = trim($id);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null; // id tidak sah: jangan sentuh berkas apa pun
        }

        $path = $this->dir . '/' . $id . '.json';

        return is_file($path) ? $path : null;
    }

    /** @throws AgentError not_found */
    private function safeId(string $id): string
    {
        $id = trim($id);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw AgentError::notFound('job id tidak valid.');
        }

        return $id;
    }

    private function ensureDir(): void
    {
        if (is_dir($this->dir)) {
            return;
        }
        if (!@mkdir($this->dir, self::DIR_MODE, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Tidak bisa membuat direktori job {$this->dir}");
        }
        @chmod($this->dir, self::DIR_MODE);
    }

    /**
     * Redaksi rekursif nilai ber-kunci sensitif (token/kredensial) — rekaman
     * job tidak boleh menjadi tempat kebocoran kredensial.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function redact(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $out;
    }

    /** `runtime/agent-jobs` (fallback path repo bila helper webman tak tersedia). */
    private static function defaultDir(): string
    {
        if (function_exists('runtime_path')) {
            return (string) runtime_path('agent-jobs');
        }

        return dirname(__DIR__, 3) . '/runtime/agent-jobs';
    }
}
