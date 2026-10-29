<?php
declare(strict_types=1);

namespace app\library\Errors;

use app\library\Storage\SqliteDatabase;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Penyimpanan "Rekam Error" — grup per fingerprint + sampel event append-only.
 *
 * **Tidak memakai `SqliteStore`**: store itu menulis ulang seluruh koleksi per
 * mutasi, sedangkan error log bisa menerima banyak event per menit. Di sini
 * semua penulisan memakai `SqliteDatabase::transaction()` (`BEGIN IMMEDIATE`)
 * dan prepared statement langsung ke `error_groups`/`error_events`.
 *
 * Kebijakan retensi (dua lapis, keduanya dipertahankan saat `record()`):
 *  - maksimum `$maxSamples` event per grup — sampel tertua dibuang;
 *  - maksimum `$maxEvents` event total — event tertua dibuang;
 *  - prune berbasis umur (`retentionDays`) **oportunistik** maksimal sekali
 *    per jam lintas worker, diklaim atomic di tabel `kv` (`errors:prune_at`).
 *
 * Semua nilai yang masuk ke SQL adalah parameter terikat. `id`/`status`/`kind`
 * divalidasi/ dinormalisasi lebih dulu; tidak ada string SQL yang dirakit dari
 * input.
 */
final class ErrorStore
{
    /** Status grup yang dikenal. */
    public const STATUSES = ['open', 'resolved', 'ignored'];

    /** Jenis error yang dikenal. */
    public const KINDS = ['client', 'server'];

    /** Pola id grup (16 hex yang dihasilkan `record()`, cukup longgar untuk uji). */
    public const ID_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /** Pola fingerprint (sha256 hex; longgar agar pemanggil lain bisa memakai). */
    public const FINGERPRINT_PATTERN = '/\A[A-Za-z0-9_.:-]{1,128}\z/';

    /** Kunci klaim prune oportunistik di tabel `kv`. */
    public const PRUNE_KEY = 'errors:prune_at';

    /** Jeda minimum antar-prune (detik, lintas worker). */
    public const PRUNE_INTERVAL_SECONDS = 3600;

    /** Batas panjang teks pencarian (`q`). */
    private const MAX_QUERY_CHARS = 100;

    private readonly ?SqliteDatabase $db;

    /**
     * @param SqliteDatabase|null $db seam uji; null = `database/rames.sqlite`
     *                                sesuai `config('deploy.sqlite_file')`
     */
    public function __construct(?SqliteDatabase $db = null)
    {
        $this->db = $db;
    }

    /**
     * Path berkas SQLite yang dipakai store ini.
     *
     * Dipakai {@see ErrorQuery} agar `AppStore`/`ServerStore` membaca **DB yang
     * sama** dengan error log (penting saat store di-inject dengan DB temp),
     * bukan selalu `config('deploy.sqlite_file')`.
     */
    public function path(): string
    {
        return $this->database()->file();
    }

    // ==================================================================
    // Penulisan
    // ==================================================================

    /**
     * Catat satu event: buat/perbarui grup berdasarkan fingerprint, sisipkan
     * event, lalu tegakkan batas sampel + total.
     *
     * @param array<string,mixed> $event kunci: kind,message,stack,location,route,
     *                                    http_status,app_id,server_id,user_id,
     *                                    username,occurred_at,context,user_agent
     * @return array<string,mixed> baris grup sesudah pembaruan
     *
     * @throws InvalidArgumentException fingerprint tidak valid
     */
    public function record(
        array $event,
        string $fingerprint,
        int $maxSamples = 20,
        int $retentionDays = 30,
        int $maxEvents = 5000
    ): array {
        $fingerprint = trim($fingerprint);
        if (preg_match(self::FINGERPRINT_PATTERN, $fingerprint) !== 1) {
            throw new InvalidArgumentException('Fingerprint error tidak valid.');
        }

        $kind = strtolower(self::text($event['kind'] ?? ''));
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'client';
        }

        $occurredAt = self::normalizeTime(self::text($event['occurred_at'] ?? ''));
        $message = self::text($event['message'] ?? '');
        $location = self::text($event['location'] ?? '');
        $route = self::text($event['route'] ?? '');
        $stack = self::text($event['stack'] ?? '');
        $userAgent = self::text($event['user_agent'] ?? '');
        $context = self::encodeContext($event['context'] ?? []);

        $httpStatus = self::nullableInt($event['http_status'] ?? null);
        $appId = self::nullableText($event['app_id'] ?? null);
        $serverId = self::nullableText($event['server_id'] ?? null);
        $userId = self::nullableText($event['user_id'] ?? null);
        $username = self::nullableText($event['username'] ?? null);

        $sampleLimit = max(0, $maxSamples);
        $eventLimit = max(0, $maxEvents);

        $db = $this->database();

        $group = $db->transaction(function (PDO $pdo) use (
            $fingerprint,
            $kind,
            $occurredAt,
            $message,
            $location,
            $route,
            $stack,
            $userAgent,
            $context,
            $httpStatus,
            $appId,
            $serverId,
            $userId,
            $username,
            $sampleLimit,
            $eventLimit
        ): array {
            $find = $pdo->prepare('SELECT * FROM "error_groups" WHERE "fingerprint" = :fingerprint');
            $find->execute([':fingerprint' => $fingerprint]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            $find->closeCursor();

            if (is_array($existing)) {
                $id = (string) $existing['id'];
                $status = strtolower((string) ($existing['status'] ?? 'open'));
                $resolvedAt = $existing['resolved_at'] ?? null;
                $resolvedBy = $existing['resolved_by'] ?? null;

                if ($status === 'resolved') {
                    // Error muncul lagi → buka kembali; jejak penyelesaian lama
                    // tidak berlaku untuk kejadian baru.
                    $status = 'open';
                    $resolvedAt = null;
                    $resolvedBy = null;
                } elseif (!in_array($status, self::STATUSES, true)) {
                    $status = 'open';
                }

                $firstSeen = self::earliest((string) ($existing['first_seen'] ?? ''), $occurredAt);
                $lastSeen = self::latest((string) ($existing['last_seen'] ?? ''), $occurredAt);

                $update = $pdo->prepare(
                    'UPDATE "error_groups" SET'
                    . ' "status" = :status, "message" = :message, "location" = :location,'
                    . ' "route" = :route, "http_status" = :http_status, "app_id" = :app_id,'
                    . ' "server_id" = :server_id, "user_id" = :user_id, "username" = :username,'
                    . ' "first_seen" = :first_seen, "last_seen" = :last_seen,'
                    . ' "count" = "count" + 1,'
                    . ' "resolved_at" = :resolved_at, "resolved_by" = :resolved_by'
                    . ' WHERE "id" = :id'
                );
                $update->bindValue(':status', $status);
                $update->bindValue(':message', $message);
                $update->bindValue(':location', $location);
                $update->bindValue(':route', $route);
                self::bindNullableInt($update, ':http_status', $httpStatus);
                self::bindNullableText($update, ':app_id', $appId);
                self::bindNullableText($update, ':server_id', $serverId);
                self::bindNullableText($update, ':user_id', $userId);
                self::bindNullableText($update, ':username', $username);
                $update->bindValue(':first_seen', $firstSeen);
                $update->bindValue(':last_seen', $lastSeen);
                self::bindNullableText($update, ':resolved_at', $resolvedAt === null ? null : (string) $resolvedAt);
                self::bindNullableText($update, ':resolved_by', $resolvedBy === null ? null : (string) $resolvedBy);
                $update->bindValue(':id', $id);
                $update->execute();
            } else {
                $id = bin2hex(random_bytes(8));
                $insert = $pdo->prepare(
                    'INSERT INTO "error_groups" ('
                    . '"id","fingerprint","kind","status","message","location","route",'
                    . '"http_status","app_id","server_id","user_id","username",'
                    . '"first_seen","last_seen","count","samples"'
                    . ') VALUES ('
                    . ':id,:fingerprint,:kind,\'open\',:message,:location,:route,'
                    . ':http_status,:app_id,:server_id,:user_id,:username,'
                    . ':first_seen,:last_seen,1,0'
                    . ')'
                );
                $insert->bindValue(':id', $id);
                $insert->bindValue(':fingerprint', $fingerprint);
                $insert->bindValue(':kind', $kind);
                $insert->bindValue(':message', $message);
                $insert->bindValue(':location', $location);
                $insert->bindValue(':route', $route);
                self::bindNullableInt($insert, ':http_status', $httpStatus);
                self::bindNullableText($insert, ':app_id', $appId);
                self::bindNullableText($insert, ':server_id', $serverId);
                self::bindNullableText($insert, ':user_id', $userId);
                self::bindNullableText($insert, ':username', $username);
                $insert->bindValue(':first_seen', $occurredAt);
                $insert->bindValue(':last_seen', $occurredAt);
                $insert->execute();
            }

            $eventInsert = $pdo->prepare(
                'INSERT INTO "error_events"'
                . ' ("group_id","occurred_at","kind","message","stack","context","user_agent")'
                . ' VALUES (:group_id,:occurred_at,:kind,:message,:stack,:context,:user_agent)'
            );
            $eventInsert->execute([
                ':group_id' => $id,
                ':occurred_at' => $occurredAt,
                ':kind' => $kind,
                ':message' => $message,
                ':stack' => $stack,
                ':context' => $context,
                ':user_agent' => $userAgent,
            ]);

            // Batas sampel per grup — sisakan yang terbaru.
            if ($sampleLimit === 0) {
                $pdo->prepare('DELETE FROM "error_events" WHERE "group_id" = :group_id')
                    ->execute([':group_id' => $id]);
            } else {
                $trim = $pdo->prepare(
                    'DELETE FROM "error_events"'
                    . ' WHERE "group_id" = :group_id AND "id" NOT IN ('
                    . 'SELECT "id" FROM "error_events" WHERE "group_id" = :group_id_keep'
                    . ' ORDER BY "occurred_at" DESC, "id" DESC LIMIT :keep'
                    . ')'
                );
                $trim->bindValue(':group_id', $id);
                $trim->bindValue(':group_id_keep', $id);
                $trim->bindValue(':keep', $sampleLimit, PDO::PARAM_INT);
                $trim->execute();
            }

            self::enforceTotalCap($pdo, $eventLimit);

            // `samples` selalu jumlah sampel yang benar-benar tersimpan.
            $count = $pdo->prepare('SELECT COUNT(*) FROM "error_events" WHERE "group_id" = :group_id');
            $count->execute([':group_id' => $id]);
            $samples = (int) $count->fetchColumn();
            $count->closeCursor();

            $pdo->prepare('UPDATE "error_groups" SET "samples" = :samples WHERE "id" = :id')
                ->execute([':samples' => $samples, ':id' => $id]);

            $select = $pdo->prepare('SELECT * FROM "error_groups" WHERE "id" = :id');
            $select->execute([':id' => $id]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            $select->closeCursor();

            return is_array($row) ? $row : ['id' => $id, 'fingerprint' => $fingerprint];
        });

        $this->maybePrune($retentionDays, $maxEvents);

        return $group;
    }

    // ==================================================================
    // Pembacaan
    // ==================================================================

    /** @return array<string,mixed>|null */
    public function findGroup(string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }

        $stmt = $this->database()->pdo()
            ->prepare('SELECT * FROM "error_groups" WHERE "id" = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return is_array($row) ? $row : null;
    }

    /**
     * Sampel event terbaru milik satu grup.
     *
     * @return list<array<string,mixed>>
     */
    public function eventsForGroup(string $id, int $limit = 20): array
    {
        if (!self::isValidId($id)) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $stmt = $this->database()->pdo()->prepare(
            'SELECT "id","group_id","occurred_at","kind","message","stack","context","user_agent"'
            . ' FROM "error_events" WHERE "group_id" = :id'
            . ' ORDER BY "occurred_at" DESC, "id" DESC LIMIT :limit'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $events = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['context'] = self::decodeContext($row['context'] ?? '{}');
            $events[] = $row;
        }

        return $events;
    }

    /**
     * Daftar grup terfilter dengan pagination.
     *
     * Filter yang dikenal: `status` (`open|resolved|ignored|all`, default
     * `open`), `kind` (`client|server|all`, default `all`), `app_id` (pola id),
     * `q` (LIKE pada message/location/route/username, maks 100 karakter).
     *
     * @param array<string,mixed> $filters
     * @return array{groups:list<array<string,mixed>>,total:int,pages:int}
     */
    public function listGroups(array $filters, int $page = 1, int $perPage = 50): array
    {
        $normalized = self::normalizeFilters($filters);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        // `app_id` yang tidak cocok pola id tidak boleh "melonggar" menjadi
        // tanpa filter: hasilnya kosong (fail-closed).
        if ($normalized['app_id'] !== ''
            && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $normalized['app_id']) !== 1) {
            return ['groups' => [], 'total' => 0, 'pages' => 1];
        }

        $where = [];
        $params = [];

        if ($normalized['status'] !== 'all') {
            $where[] = '"status" = :status';
            $params[':status'] = $normalized['status'];
        }
        if ($normalized['kind'] !== 'all') {
            $where[] = '"kind" = :kind';
            $params[':kind'] = $normalized['kind'];
        }
        if ($normalized['app_id'] !== '') {
            $where[] = '"app_id" = :app_id';
            $params[':app_id'] = $normalized['app_id'];
        }
        if ($normalized['q'] !== '') {
            $needle = '%' . self::escapeLike($normalized['q']) . '%';
            $where[] = '("message" LIKE :q_message ESCAPE \'\\\''
                . ' OR "location" LIKE :q_location ESCAPE \'\\\''
                . ' OR "route" LIKE :q_route ESCAPE \'\\\''
                . ' OR "username" LIKE :q_username ESCAPE \'\\\')';
            $params[':q_message'] = $needle;
            $params[':q_location'] = $needle;
            $params[':q_route'] = $needle;
            $params[':q_username'] = $needle;
        }

        $pdo = $this->database()->pdo();
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $count = $pdo->prepare('SELECT COUNT(*) FROM "error_groups"' . $whereSql);
        foreach ($params as $name => $value) {
            $count->bindValue($name, $value);
        }
        $count->execute();
        $total = (int) $count->fetchColumn();
        $count->closeCursor();

        $select = $pdo->prepare(
            'SELECT * FROM "error_groups"' . $whereSql
            . ' ORDER BY "last_seen" DESC, "id" DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $name => $value) {
            $select->bindValue($name, $value);
        }
        $select->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $select->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $select->execute();
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        $select->closeCursor();

        return [
            'groups' => is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Jumlah grup per status.
     *
     * @return array{open:int,resolved:int,ignored:int}
     */
    public function counts(): array
    {
        $out = ['open' => 0, 'resolved' => 0, 'ignored' => 0];

        $stmt = $this->database()->pdo()->query(
            'SELECT "status", COUNT(*) AS "total" FROM "error_groups" GROUP BY "status"'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            if (isset($out[$status])) {
                $out[$status] = (int) ($row['total'] ?? 0);
            }
        }
        $stmt->closeCursor();

        return $out;
    }

    /** Jumlah grup berstatus `open`. */
    public function openCount(): int
    {
        return $this->counts()['open'];
    }

    // ==================================================================
    // Mutasi administratif
    // ==================================================================

    /**
     * Ubah status grup. `false` bila id/status tidak sah atau grup tidak ada.
     */
    public function setStatus(string $id, string $status, ?string $actor): bool
    {
        if (!self::isValidId($id) || !in_array($status, self::STATUSES, true)) {
            return false;
        }
        if ($this->findGroup($id) === null) {
            return false;
        }

        $resolvedAt = null;
        $resolvedBy = null;
        if ($status === 'resolved') {
            $resolvedAt = self::now();
            $resolvedBy = self::nullableText($actor);
        } elseif ($status === 'ignored') {
            $resolvedBy = self::nullableText($actor);
        }

        $stmt = $this->database()->pdo()->prepare(
            'UPDATE "error_groups" SET "status" = :status,'
            . ' "resolved_at" = :resolved_at, "resolved_by" = :resolved_by'
            . ' WHERE "id" = :id'
        );
        $stmt->bindValue(':status', $status);
        self::bindNullableText($stmt, ':resolved_at', $resolvedAt);
        self::bindNullableText($stmt, ':resolved_by', $resolvedBy);
        $stmt->bindValue(':id', $id);
        $stmt->execute();

        return $stmt->rowCount() > 0 || $this->findGroup($id) !== null;
    }

    /**
     * Hapus grup beserta seluruh sampelnya dalam satu transaksi.
     */
    public function deleteGroup(string $id): bool
    {
        if (!self::isValidId($id)) {
            return false;
        }

        return (bool) $this->database()->transaction(function (PDO $pdo) use ($id): bool {
            $exists = $pdo->prepare('SELECT 1 FROM "error_groups" WHERE "id" = :id');
            $exists->execute([':id' => $id]);
            $found = $exists->fetchColumn() !== false;
            $exists->closeCursor();
            if (!$found) {
                return false;
            }

            $pdo->prepare('DELETE FROM "error_events" WHERE "group_id" = :id')
                ->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM "error_groups" WHERE "id" = :id')
                ->execute([':id' => $id]);

            return true;
        });
    }

    // ==================================================================
    // Retensi
    // ==================================================================

    /**
     * Buang data kedaluwarsa lalu tegakkan batas total event.
     *
     * `$retentionDays` 0 ⇒ semua data lebih tua dari (atau sama dengan) detik
     * ini ikut dibuang; berguna untuk uji & pembersihan manual.
     */
    public function prune(int $retentionDays, int $maxEvents): void
    {
        $days = max(0, $retentionDays);
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
        $eventLimit = max(0, $maxEvents);

        $this->database()->transaction(function (PDO $pdo) use ($cutoff, $eventLimit): void {
            $pdo->prepare('DELETE FROM "error_events" WHERE "occurred_at" <= :cutoff')
                ->execute([':cutoff' => $cutoff]);

            // Sampel grup basi dihapus lebih dulu (tanpa foreign key cascade).
            $pdo->prepare(
                'DELETE FROM "error_events" WHERE "group_id" IN ('
                . 'SELECT "id" FROM "error_groups" WHERE "last_seen" <= :cutoff'
                . ')'
            )->execute([':cutoff' => $cutoff]);

            $pdo->prepare('DELETE FROM "error_groups" WHERE "last_seen" <= :cutoff')
                ->execute([':cutoff' => $cutoff]);

            self::enforceTotalCap($pdo, $eventLimit);

            $pdo->exec(
                'UPDATE "error_groups" SET "samples" = ('
                . 'SELECT COUNT(*) FROM "error_events"'
                . ' WHERE "error_events"."group_id" = "error_groups"."id"'
                . ')'
            );
        });
    }

    /**
     * Prune oportunistik maksimal sekali per jam, diklaim atomic di `kv`.
     *
     * Kegagalan di sini tidak boleh menggagalkan `record()` — pelaporan error
     * adalah fitur diagnostik, bukan operasi bisnis.
     */
    private function maybePrune(int $retentionDays, int $maxEvents): void
    {
        try {
            $pdo = $this->database()->pdo();
            $now = time();
            $threshold = $now - self::PRUNE_INTERVAL_SECONDS;

            $claim = $pdo->prepare(
                'INSERT INTO "kv" ("key","value") VALUES (:key, :now)'
                . ' ON CONFLICT("key") DO UPDATE SET "value" = excluded."value"'
                . ' WHERE CAST("value" AS INTEGER) <= :threshold'
            );
            $claim->bindValue(':key', self::PRUNE_KEY);
            $claim->bindValue(':now', $now, PDO::PARAM_INT);
            $claim->bindValue(':threshold', $threshold, PDO::PARAM_INT);
            $claim->execute();

            if ($claim->rowCount() !== 1) {
                return; // worker lain baru saja menjalankan prune
            }

            $this->prune($retentionDays, $maxEvents);
        } catch (Throwable) {
            // abaikan: retensi akan dicoba lagi pada kesempatan berikutnya
        }
    }

    // ==================================================================
    // Normalisasi filter (dipakai juga controller untuk payload view)
    // ==================================================================

    /**
     * Normalisasi filter GET menjadi empat key string yang selalu ada.
     *
     * @param array<string,mixed> $filters
     * @return array{status:string,kind:string,app_id:string,q:string}
     */
    public static function normalizeFilters(array $filters): array
    {
        $status = strtolower(self::text($filters['status'] ?? ''));
        if (!in_array($status, [...self::STATUSES, 'all'], true)) {
            $status = 'open';
        }

        $kind = strtolower(self::text($filters['kind'] ?? ''));
        if (!in_array($kind, [...self::KINDS, 'all'], true)) {
            $kind = 'all';
        }

        $appId = trim(self::text($filters['app_id'] ?? ''));
        if (strlen($appId) > 128) {
            $appId = substr($appId, 0, 128);
        }

        $q = trim(self::text($filters['q'] ?? ''));
        if ($q !== '') {
            $q = function_exists('mb_substr')
                ? mb_substr($q, 0, self::MAX_QUERY_CHARS, 'UTF-8')
                : substr($q, 0, self::MAX_QUERY_CHARS);
        }

        return ['status' => $status, 'kind' => $kind, 'app_id' => $appId, 'q' => $q];
    }

    // ==================================================================
    // Internal
    // ==================================================================

    private function database(): SqliteDatabase
    {
        return $this->db ?? new SqliteDatabase();
    }

    /** Cap total event; hanya menghitung saat perlu (biasanya < batas). */
    private static function enforceTotalCap(PDO $pdo, int $maxEvents): void
    {
        $count = $pdo->query('SELECT COUNT(*) FROM "error_events"');
        $total = (int) $count->fetchColumn();
        $count->closeCursor();
        if ($total <= $maxEvents) {
            return;
        }

        $excess = $total - $maxEvents;
        if ($maxEvents === 0) {
            $pdo->exec('DELETE FROM "error_events"');
        } else {
            $delete = $pdo->prepare(
                'DELETE FROM "error_events" WHERE "id" IN ('
                . 'SELECT "id" FROM "error_events"'
                . ' ORDER BY "occurred_at" ASC, "id" ASC LIMIT :excess'
                . ')'
            );
            $delete->bindValue(':excess', $excess, PDO::PARAM_INT);
            $delete->execute();
        }

        // Sampel grup yang kehilangan event harus mencerminkan isi nyata.
        $pdo->exec(
            'UPDATE "error_groups" SET "samples" = ('
            . 'SELECT COUNT(*) FROM "error_events"'
            . ' WHERE "error_events"."group_id" = "error_groups"."id"'
            . ')'
        );
    }

    /** Escape wildcard LIKE dengan `\` (dipakai bersama `ESCAPE '\'`). */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /** ISO/teks waktu → bentuk kanonik UTC yang bisa diurutkan leksikografis. */
    private static function normalizeTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return self::now();
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? self::now() : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /** Waktu paling awal dari dua string ISO (kosong = yang lain). */
    private static function earliest(string $a, string $b): string
    {
        if ($a === '') {
            return $b;
        }
        if ($b === '') {
            return $a;
        }

        return $a <= $b ? $a : $b;
    }

    /** Waktu paling akhir dari dua string ISO (kosong = yang lain). */
    private static function latest(string $a, string $b): string
    {
        if ($a === '') {
            return $b;
        }
        if ($b === '') {
            return $a;
        }

        return $a >= $b ? $a : $b;
    }

    private static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = self::text($value);

        return $text === '' ? null : $text;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d{1,9}\z/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private static function bindNullableText(\PDOStatement $stmt, string $name, ?string $value): void
    {
        $stmt->bindValue($name, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }

    private static function bindNullableInt(\PDOStatement $stmt, string $name, ?int $value): void
    {
        $stmt->bindValue($name, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    }

    private static function encodeContext(mixed $context): string
    {
        if (!is_array($context)) {
            return '{}';
        }

        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($json) ? $json : '{}';
    }

    /** @return array<string,mixed> */
    private static function decodeContext(mixed $context): array
    {
        if (!is_string($context) || $context === '') {
            return [];
        }
        $decoded = json_decode($context, true);

        return is_array($decoded) ? $decoded : [];
    }
}
