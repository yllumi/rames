<?php
declare(strict_types=1);

namespace app\library\Provision;

use app\library\Storage\SqliteDatabase;
use RuntimeException;

/**
 * Antrean provisioning server agent (Opsi B: Ansible) — tabel `provision_jobs`
 * (migrasi skema v6).
 *
 * Pola sama dengan {@see \app\library\SSL\SslQueue}: baris job dimutasi per
 * baris lewat prepared statement, **tanpa state lintas-request** (seluruh data
 * ada di SQLite; instance kelas ini hanya memegang seam basis data).
 *
 * Status:
 *   - `queued`    — menunggu worker (`stage` bebas, mis. `preflight`);
 *   - `running`   — playbook sedang dijalankan;
 *   - `done`      — playbook selesai tanpa galat;
 *   - `error`     — gagal/macet (worker mati ditandai {@see self::failStaleRunning()});
 *   - `cancelled` — dibatalkan user sebelum/saat berjalan.
 *
 * Kolom tabel **tidak memuat rahasia** (lihat migrasi 6): token agent, private
 * key SSH, dan passphrase TIDAK pernah ditulis di sini. Kunci SSH hidup di
 * {@see ProvisionAgentKey} (private 0600 di control plane, di luar basis data).
 */
final class ProvisionJobStore
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELLED = 'cancelled';

    /** Seluruh status yang sah (sumber tunggal untuk validasi `update()`). */
    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_DONE,
        self::STATUS_ERROR,
        self::STATUS_CANCELLED,
    ];

    /** Id job: hex/alfanumerik aman untuk URL & query string. */
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Batas `stage` (nilai pendek, mis. `preflight`/`install`/`verify`). */
    private const MAX_STAGE_LENGTH = 64;

    /** Batas `message`/`error` (kolom teks bebas; UI memotong lebih lanjut). */
    private const MAX_TEXT_LENGTH = 2000;

    /**
     * Kolom yang boleh diubah {@see self::update()}. `id`, `created_at`, dan
     * kolom identitas job (host/domain/playbook/…) sengaja tidak bisa diubah
     * setelah dibuat — job adalah catatan historis.
     *
     * @var array<int,string>
     */
    private const UPDATABLE = ['status', 'stage', 'message', 'error', 'started_at', 'finished_at', 'server_id'];

    /** Seam basis data (null ⇒ `config('deploy.sqlite_file')`). */
    private readonly ?SqliteDatabase $database;

    public function __construct(?SqliteDatabase $database = null)
    {
        $this->database = $database;
    }

    // ==================================================================
    // Tulis
    // ==================================================================

    /**
     * Buat job baru (status `queued`) dan kembalikan baris yang tersimpan.
     *
     * Hanya kolom tabel yang disimpan: field asing (termasuk `token`,
     * `private_key`, atau `force`) diabaikan total. Id dibuat di sini
     * (16 karakter hex), `created_at`/`updated_at` = sekarang (UTC ISO8601).
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     * @throws RuntimeException bila field wajib kosong atau port di luar rentang
     */
    public function create(array $job): array
    {
        $now = self::now();
        $id = bin2hex(random_bytes(8));

        $record = [
            'id' => $id,
            'server_id' => self::nullable(self::text($job['server_id'] ?? '')),
            'name' => self::required($job, 'name'),
            'playbook' => self::required($job, 'playbook'),
            'host' => self::required($job, 'host'),
            'ssh_user' => self::required($job, 'ssh_user'),
            'ssh_port' => self::port($job['ssh_port'] ?? 22, 'ssh_port'),
            'domain' => self::required($job, 'domain'),
            'port_range_start' => self::port($job['port_range_start'] ?? 30000, 'port_range_start'),
            'port_range_end' => self::port($job['port_range_end'] ?? 30999, 'port_range_end'),
            'agent_port' => self::port($job['agent_port'] ?? 8443, 'agent_port'),
            'control_plane_ips' => self::ipsText($job['control_plane_ips'] ?? ''),
            'manage_firewall' => self::boolInt($job['manage_firewall'] ?? true),
            // Opsi identitas opsional (bukan rahasia) — kosong berarti installer
            // mempertahankan nilai lama di `secrets/agent.env`.
            'email' => self::truncate(self::text($job['email'] ?? ''), self::MAX_TEXT_LENGTH),
            'bind' => self::truncate(self::text($job['bind'] ?? ''), self::MAX_TEXT_LENGTH),
            'ip' => self::truncate(self::text($job['ip'] ?? ''), self::MAX_TEXT_LENGTH),
            'ssl_challenge' => self::truncate(self::text($job['ssl_challenge'] ?? ''), self::MAX_TEXT_LENGTH),
            'ssl_ca_server' => self::truncate(self::text($job['ssl_ca_server'] ?? ''), self::MAX_TEXT_LENGTH),
            'status' => self::STATUS_QUEUED,
            'stage' => self::STATUS_QUEUED,
            'message' => self::truncate(self::text($job['message'] ?? ''), self::MAX_TEXT_LENGTH),
            'error' => null,
            'created_at' => $now,
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => $now,
        ];

        if ($record['port_range_start'] >= $record['port_range_end']) {
            throw new RuntimeException('Rentang port provisioning harus start < end.');
        }

        $this->db()->pdo()->prepare(
            'INSERT INTO "provision_jobs" ('
            . '"id","server_id","name","playbook","host","ssh_user","ssh_port","domain",'
            . '"port_range_start","port_range_end","agent_port","control_plane_ips",'
            . '"manage_firewall","email","bind","ip","ssl_challenge","ssl_ca_server",'
            . '"status","stage","message","error",'
            . '"created_at","started_at","finished_at","updated_at"'
            . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $record['id'],
            $record['server_id'],
            $record['name'],
            $record['playbook'],
            $record['host'],
            $record['ssh_user'],
            $record['ssh_port'],
            $record['domain'],
            $record['port_range_start'],
            $record['port_range_end'],
            $record['agent_port'],
            $record['control_plane_ips'],
            $record['manage_firewall'],
            $record['email'],
            $record['bind'],
            $record['ip'],
            $record['ssl_challenge'],
            $record['ssl_ca_server'],
            $record['status'],
            $record['stage'],
            $record['message'],
            $record['error'],
            $record['created_at'],
            $record['started_at'],
            $record['finished_at'],
            $record['updated_at'],
        ]);

        return $this->find($id) ?? $record;
    }

    /**
     * Perbarui kolom whitelist. `updated_at` **selalu** diset ulang.
     *
     * Kolom di luar {@see self::UPDATABLE} diabaikan (tidak melempar) supaya
     * pemanggil tidak bisa menyelipkan rahasia/identitas. Status yang tidak
     * dikenal ditolak.
     *
     * @param array<string,mixed> $fields
     * @throws RuntimeException bila id tidak valid atau status tidak dikenal
     */
    public function update(string $id, array $fields): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new RuntimeException('Id job provisioning tidak valid.');
        }

        $sets = [];
        $params = [];
        foreach (self::UPDATABLE as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }

            $value = $fields[$column];
            switch ($column) {
                case 'status':
                    $value = self::text($value);
                    if (!in_array($value, self::STATUSES, true)) {
                        throw new RuntimeException('Status job provisioning tidak dikenal: ' . $value . '.');
                    }
                    break;
                case 'stage':
                    $value = self::truncate(trim(self::text($value)), self::MAX_STAGE_LENGTH);
                    break;
                case 'message':
                case 'error':
                    $value = $value === null ? null : self::truncate(self::text($value), self::MAX_TEXT_LENGTH);
                    break;
                default: // started_at, finished_at, server_id
                    $value = self::nullable(self::text($value));
                    break;
            }

            $sets[] = '"' . $column . '" = ?';
            $params[] = $value;
        }

        $sets[] = '"updated_at" = ?';
        $params[] = self::now();
        $params[] = $id;

        $this->db()->pdo()
            ->prepare('UPDATE "provision_jobs" SET ' . implode(', ', $sets) . ' WHERE "id" = ?')
            ->execute($params);
    }

    /**
     * Tandai job mulai dikerjakan (`running`, `started_at` sekarang).
     */
    public function markRunning(string $id): void
    {
        $this->update($id, [
            'status' => self::STATUS_RUNNING,
            'stage' => self::STATUS_RUNNING,
            'started_at' => self::now(),
            'error' => null,
        ]);
    }

    /**
     * Tandai job selesai (`done`, `finished_at` sekarang).
     */
    public function markDone(string $id, string $message = ''): void
    {
        $this->update($id, [
            'status' => self::STATUS_DONE,
            'stage' => self::STATUS_DONE,
            'message' => $message,
            'finished_at' => self::now(),
            'error' => null,
        ]);
    }

    /**
     * Tandai job gagal (`error`). `error` dipotong ≤ 2000 karakter.
     */
    public function markError(string $id, string $error): void
    {
        $this->update($id, [
            'status' => self::STATUS_ERROR,
            'stage' => self::STATUS_ERROR,
            'error' => $error,
            'finished_at' => self::now(),
        ]);
    }

    /**
     * Tandai job dibatalkan (`cancelled`, `finished_at` sekarang).
     */
    public function markCancelled(string $id): void
    {
        $this->update($id, [
            'status' => self::STATUS_CANCELLED,
            'stage' => self::STATUS_CANCELLED,
            'finished_at' => self::now(),
        ]);
    }

    /**
     * Tandai `running` yang `started_at`-nya lebih tua dari `$maxAgeSeconds`
     * sebagai `error` (worker berhenti di tengah jalan).
     *
     * @return int jumlah baris yang ditandai
     */
    public function failStaleRunning(int $maxAgeSeconds): int
    {
        if ($maxAgeSeconds <= 0) {
            return 0;
        }

        $now = self::now();
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $maxAgeSeconds);

        $stmt = $this->db()->pdo()->prepare(
            'UPDATE "provision_jobs" SET "status" = ?, "stage" = ?, "error" = ?,'
            . ' "finished_at" = ?, "updated_at" = ?'
            . ' WHERE "status" = ? AND COALESCE("started_at", "created_at") <= ?'
        );
        $stmt->execute([
            self::STATUS_ERROR,
            self::STATUS_ERROR,
            'worker berhenti: job macet lebih dari ' . $maxAgeSeconds . ' detik.',
            $now,
            $now,
            self::STATUS_RUNNING,
            $cutoff,
        ]);

        return $stmt->rowCount();
    }

    // ==================================================================
    // Baca
    // ==================================================================

    /**
     * Cari job berdasarkan id. Id berbentuk asing ⇒ null (tanpa menyentuh DB).
     *
     * @return array<string,mixed>|null
     */
    public function find(string $id): ?array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        $stmt = $this->db()->pdo()->prepare('SELECT * FROM "provision_jobs" WHERE "id" = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Job terbaru untuk satu server (status apa pun), atau null.
     *
     * @return array<string,mixed>|null
     */
    public function latestForServer(string $serverId): ?array
    {
        $id = trim($serverId);
        if ($id === '') {
            return null;
        }

        $stmt = $this->db()->pdo()->prepare(
            'SELECT * FROM "provision_jobs" WHERE "server_id" = ?'
            . ' ORDER BY "created_at" DESC, "id" DESC LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Job aktif (`queued`/`running`) untuk satu server, atau null.
     *
     * @return array<string,mixed>|null
     */
    public function activeForServer(string $serverId): ?array
    {
        $id = trim($serverId);
        if ($id === '') {
            return null;
        }

        $stmt = $this->db()->pdo()->prepare(
            'SELECT * FROM "provision_jobs" WHERE "server_id" = ?'
            . ' AND "status" IN (\'queued\',\'running\')'
            . ' ORDER BY "created_at" DESC, "id" DESC LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    // ==================================================================
    // Internal
    // ==================================================================

    private function db(): SqliteDatabase
    {
        return $this->database ?? new SqliteDatabase();
    }

    /**
     * Baris SQLite → bentuk kanonik (kolom INTEGER selalu int).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        foreach (['ssh_port', 'port_range_start', 'port_range_end', 'agent_port', 'manage_firewall'] as $column) {
            $row[$column] = (int) ($row[$column] ?? 0);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $job
     * @throws RuntimeException bila field wajib kosong
     */
    private static function required(array $job, string $field): string
    {
        $value = trim(self::text($job[$field] ?? ''));
        if ($value === '') {
            throw new RuntimeException('Field "' . $field . '" wajib diisi pada job provisioning.');
        }

        return $value;
    }

    /**
     * Port 1–65535 (int atau string digit).
     *
     * @throws RuntimeException
     */
    private static function port(mixed $raw, string $field): int
    {
        $port = null;
        if (is_int($raw)) {
            $port = $raw;
        } elseif (is_string($raw) && preg_match('/^\d{1,5}$/', trim($raw)) === 1) {
            $port = (int) trim($raw);
        }

        if ($port === null || $port < 1 || $port > 65535) {
            throw new RuntimeException('Field "' . $field . '" harus angka port 1–65535.');
        }

        return $port;
    }

    private static function boolInt(mixed $raw): int
    {
        if (is_bool($raw)) {
            return $raw ? 1 : 0;
        }
        if (is_int($raw)) {
            return $raw === 0 ? 0 : 1;
        }
        if (is_string($raw)) {
            return in_array(strtolower(trim($raw)), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
        }

        return 1;
    }

    /**
     * Daftar IP → teks dipisah koma (kolom TEXT, bukan JSON).
     */
    private static function ipsText(mixed $raw): string
    {
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $item) {
                $ip = trim(self::text($item));
                if ($ip !== '') {
                    $parts[] = $ip;
                }
            }

            return implode(',', $parts);
        }

        return trim(self::text($raw));
    }

    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return '';
    }

    private static function nullable(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function truncate(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit);
        }

        return substr($value, 0, $limit);
    }

    /** Timestamp kanonik UTC (`Y-m-d\TH:i:s\Z`) — bisa diurutkan leksikografis. */
    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
