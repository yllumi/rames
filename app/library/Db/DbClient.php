<?php
declare(strict_types=1);

namespace app\library\Db;

use PDO;
use RuntimeException;

/**
 * Wrapper tipis PDO untuk mengelola MySQL/MariaDB (browse, struktur, query,
 * dan CRUD baris). Seluruh query STRUKTURAL memakai prepared statement;
 * identifer (nama database/tabel/kolom) divalidasi regex lalu di-quote backtick.
 *
 * Query SQL editor (DbClient::execute) adalah satu-satunya tempat query mentah
 * user dieksekusi — itulah tujuan fitur, dibatasi satu statement + jumlah baris.
 */
class DbClient
{
    /**
     * Buat koneksi PDO dari profile koneksi.
     *
     * @param array{host:string, port:int, username:string, password:string} $profile
     */
    public function connect(array $profile): PDO
    {
        $dsn = 'mysql:host=' . $profile['host'] . ';port=' . (int) $profile['port'] . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => (int) config('deploy.db_connect_timeout', 10),
        ];
        try {
            return new PDO($dsn, (string) $profile['username'], (string) $profile['password'], $options);
        } catch (\PDOException $e) {
            throw new RuntimeException('Gagal konek ke database: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Daftar database (system schema disembunyikan secara default).
     *
     * @return array<int,string>
     */
    public function databases(PDO $pdo, bool $includeSystem = false): array
    {
        $rows = $pdo->query('SHOW DATABASES')->fetchAll();
        $system = ['information_schema', 'mysql', 'performance_schema', 'sys'];
        $names = [];
        foreach ($rows as $row) {
            $name = (string) reset($row);
            if (!$includeSystem && in_array($name, $system, true)) {
                continue;
            }
            $names[] = $name;
        }
        return $names;
    }

    /**
     * Daftar tabel & view dalam sebuah database.
     *
     * @return array<int,array{name:string, type:string}>
     */
    public function tables(PDO $pdo, string $db): array
    {
        $this->assertIdentifier($db);
        $rows = $pdo->query('SHOW FULL TABLES FROM `' . $this->backtick($db) . '`')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $values = array_values($row);
            $name = (string) ($values[0] ?? '');
            if ($name === '') {
                continue;
            }
            $type = strtoupper((string) ($values[1] ?? 'BASE TABLE')) === 'VIEW' ? 'VIEW' : 'TABLE';
            $result[] = ['name' => $name, 'type' => $type];
        }
        usort($result, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        return $result;
    }

    /**
     * Kolom tabel (SHOW FULL COLUMNS).
     *
     * @return array<int,array{field:string, type:string, collation:?string, null:bool,
     *                         key:string, default:?string, extra:string}>
     */
    public function columns(PDO $pdo, string $db, string $table): array
    {
        $this->assertIdentifier($db);
        $this->assertIdentifier($table);
        $stmt = $pdo->prepare('SHOW FULL COLUMNS FROM `' . $this->backtick($db) . '`.`' . $this->backtick($table) . '`');
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[] = [
                'field' => (string) ($row['Field'] ?? ''),
                'type' => (string) ($row['Type'] ?? ''),
                'collation' => $row['Collation'] !== null ? (string) $row['Collation'] : null,
                'null' => strtoupper((string) ($row['Null'] ?? 'NO')) === 'YES',
                'key' => (string) ($row['Key'] ?? ''),
                'default' => $row['Default'] !== null ? (string) $row['Default'] : null,
                'extra' => (string) ($row['Extra'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Index tabel (SHOW INDEX).
     *
     * @return array<int,array{key_name:string, column:string, unique:bool, type:string}>
     */
    public function indexes(PDO $pdo, string $db, string $table): array
    {
        $this->assertIdentifier($db);
        $this->assertIdentifier($table);
        $stmt = $pdo->prepare('SHOW INDEX FROM `' . $this->backtick($db) . '`.`' . $this->backtick($table) . '`');
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[] = [
                'key_name' => (string) ($row['Key_name'] ?? ''),
                'column' => (string) ($row['Column_name'] ?? ''),
                'unique' => (int) ($row['Non_unique'] ?? 1) === 0,
                'type' => (string) ($row['Index_type'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Nama kolom primary key, atau null bila tabel tanpa PK.
     *
     * @param array<int,array> $columns hasil DbClient::columns
     */
    public function primaryKey(array $columns): ?string
    {
        foreach ($columns as $c) {
            if (($c['key'] ?? '') === 'PRI') {
                return (string) $c['field'];
            }
        }
        return null;
    }

    /**
     * Jumlah baris tabel.
     */
    public function countRows(PDO $pdo, string $db, string $table): int
    {
        $this->assertIdentifier($db);
        $this->assertIdentifier($table);
        $stmt = $pdo->query('SELECT COUNT(*) FROM `' . $this->backtick($db) . '`.`' . $this->backtick($table) . '`');
        return (int) $stmt->fetchColumn();
    }

    /**
     * Halaman baris tabel (SELECT * dengan LIMIT/OFFSET).
     *
     * @return array<int,array>
     */
    public function rows(PDO $pdo, string $db, string $table, int $page, int $perPage): array
    {
        $this->assertIdentifier($db);
        $this->assertIdentifier($table);
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 500));
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->query(
            'SELECT * FROM `' . $this->backtick($db) . '`.`' . $this->backtick($table) . '`'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        return $stmt->fetchAll();
    }

    /**
     * Eksekusi SQL editor (satu statement). Mengembalikan hasil grid untuk
     * query bertipe result-set, atau jumlah baris terpengaruh untuk DML/DDL.
     *
     * @return array{isSelect:bool, columns:array<int,string>, rows:array<int,array>,
     *               affected:int, truncated:bool}
     */
    public function execute(PDO $pdo, string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new RuntimeException('Query kosong.');
        }

        // Tolak multi-statement (semicolon di tengah). Semicolon di akhir dibuang.
        $trimmed = rtrim(rtrim($sql), ';');
        $trimmed = trim($trimmed);
        if (str_contains($trimmed, ';')) {
            throw new RuntimeException('Hanya satu statement yang diizinkan per eksekusi.');
        }
        $sql = $trimmed;

        $first = strtoupper((string) preg_replace('/^\(+/', '', ltrim($sql)));
        $firstWord = strtoupper(strtok($first, " \t\n\r(") ?: '');
        $isSelect = in_array($firstWord, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH', 'TABLE', 'VALUES'], true);

        $maxRows = (int) config('deploy.db_max_rows', 500);
        if ($isSelect) {
            $stmt = $pdo->query($sql);
            if (!$stmt instanceof \PDOStatement) {
                return ['isSelect' => true, 'columns' => [], 'rows' => [], 'affected' => 0, 'truncated' => false];
            }
            // Ambil maks. maxRows + 1 baris (incremental — hindari memuat jutaan baris).
            $rows = [];
            $truncated = false;
            foreach ($stmt as $row) {
                if (count($rows) >= $maxRows) {
                    $truncated = true;
                    break;
                }
                $rows[] = $row;
            }
            $columns = $this->resultColumns($stmt, $rows);
            return [
                'isSelect' => true,
                'columns' => $columns,
                'rows' => $rows,
                'affected' => count($rows),
                'truncated' => $truncated,
            ];
        }

        $affected = $pdo->exec($sql);
        return [
            'isSelect' => false,
            'columns' => [],
            'rows' => [],
            'affected' => $affected,
            'truncated' => false,
        ];
    }

    /**
     * Insert baris; mengembalikan id auto-increment (bila ada) atau 0.
     *
     * @param array<string,string> $data kolom => nilai
     */
    public function insert(PDO $pdo, string $db, string $table, array $data): int
    {
        $this->assertIdentifier($db);
        $this->assertIdentifier($table);
        if ($data === []) {
            throw new RuntimeException('Tidak ada kolom untuk diinsert.');
        }
        $cols = [];
        $holders = [];
        $values = [];
        foreach ($data as $col => $value) {
            $this->assertIdentifier((string) $col);
            $cols[] = '`' . $this->backtick((string) $col) . '`';
            $holders[] = '?';
            $values[] = $value;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO `' . $this->backtick($db) . '`.`' . $this->backtick($table) . '`'
            . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $holders) . ')'
        );
        $stmt->execute($values);
        $lastId = (int) $pdo->lastInsertId();
        return $lastId;
    }

    /**
     * Update baris berdasarkan primary key.
     *
     * @param array<string,string> $data kolom => nilai
     */
    public function update(PDO $pdo, string $db, string $table, string $pkCol, string $pkVal, array $data): int
    {
        $this->assertIdentifier($db);
        $this->assertIdentifier($table);
        $this->assertIdentifier($pkCol);
        if ($data === []) {
            throw new RuntimeException('Tidak ada kolom untuk diupdate.');
        }
        $sets = [];
        $values = [];
        foreach ($data as $col => $value) {
            $this->assertIdentifier((string) $col);
            $sets[] = '`' . $this->backtick((string) $col) . '` = ?';
            $values[] = $value;
        }
        $values[] = $pkVal;
        $stmt = $pdo->prepare(
            'UPDATE `' . $this->backtick($db) . '`.`' . $this->backtick($table) . '` SET '
            . implode(', ', $sets) . ' WHERE `' . $this->backtick($pkCol) . '` = ? LIMIT 1'
        );
        $stmt->execute($values);
        return $stmt->rowCount();
    }

    /**
     * Hapus baris berdasarkan primary key.
     */
    public function delete(PDO $pdo, string $db, string $table, string $pkCol, string $pkVal): int
    {
        $this->assertIdentifier($db);
        $this->assertIdentifier($table);
        $this->assertIdentifier($pkCol);
        $stmt = $pdo->prepare(
            'DELETE FROM `' . $this->backtick($db) . '`.`' . $this->backtick($table) . '`'
            . ' WHERE `' . $this->backtick($pkCol) . '` = ? LIMIT 1'
        );
        $stmt->execute([$pkVal]);
        return $stmt->rowCount();
    }

    /**
     * Nama-nama kolom hasil query (dari baris pertama, fallback ke metadata).
     *
     * @param array<int,array> $rows
     * @return array<int,string>
     */
    private function resultColumns(\PDOStatement $stmt, array $rows): array
    {
        if ($rows !== []) {
            return array_keys($rows[0]);
        }
        $cols = [];
        try {
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                $cols[] = (string) ($meta['name'] ?? ('col' . $i));
            }
        } catch (\Throwable $e) {
            // metadata tidak tersedia — biarkan kosong
        }
        return $cols;
    }

    /**
     * Validasi identifier (nama database/tabel/kolom) — tolak karakter di luar
     * [A-Za-z0-9_]. Identifier yang lolos aman di-quote backtick.
     */
    private function assertIdentifier(string $name): void
    {
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException('Identifier database/tabel/kolom tidak valid.');
        }
    }

    /**
     * Escape backtick di dalam identifier (defensif; assertIdentifier sudah membatasi).
     */
    private function backtick(string $name): string
    {
        return str_replace('`', '``', $name);
    }
}
