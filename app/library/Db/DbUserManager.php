<?php
declare(strict_types=1);

namespace app\library\Db;

use PDO;
use RuntimeException;

/**
 * Kelola user & hak akses MySQL/MariaDB (CREATE/DROP USER, GRANT/REVOKE).
 *
 * User/host divalidasi regex ketat lalu di-quote literal (single-quote, escape '').
 * Password & privilege di-bind / di-whitelist — bebas SQL injection.
 */
class DbUserManager
{
    /** Privilege yang boleh dipilih via UI. */
    public const PRIVILEGES = [
        'ALL PRIVILEGES',
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'DROP',
        'INDEX',
        'ALTER',
        'REFERENCES',
        'LOCK TABLES',
        'EXECUTE',
        'CREATE VIEW',
        'SHOW VIEW',
        'CREATE ROUTINE',
        'ALTER ROUTINE',
        'EVENT',
        'TRIGGER',
    ];

    /**
     * Daftar user (mysql.user).
     *
     * @return array<int,array{user:string, host:string}>
     */
    public function users(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT User, Host FROM mysql.user ORDER BY User, Host')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'user' => (string) ($row['User'] ?? ''),
                'host' => (string) ($row['Host'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Daftar privilege (SHOW GRANTS) untuk sebuah user@host.
     *
     * @return array<int,string>
     */
    public function grants(PDO $pdo, string $user, string $host): array
    {
        $this->assertAccount($user, $host);
        $stmt = $pdo->query('SHOW GRANTS FOR ' . $this->quoteAccount($user, $host));
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[] = (string) reset($row);
        }
        return $result;
    }

    public function createUser(PDO $pdo, string $user, string $host, string $password): void
    {
        $this->assertAccount($user, $host);
        $stmt = $pdo->prepare('CREATE USER ' . $this->quoteAccount($user, $host) . ' IDENTIFIED BY ?');
        $stmt->execute([$password]);
    }

    public function dropUser(PDO $pdo, string $user, string $host): void
    {
        $this->assertAccount($user, $host);
        $pdo->exec('DROP USER ' . $this->quoteAccount($user, $host));
    }

    /**
     * Beri privilege pada user@host untuk sebuah database.
     *
     * @param array<int,string> $privileges token dari DbUserManager::PRIVILEGES
     */
    public function grant(PDO $pdo, string $user, string $host, string $db, array $privileges): void
    {
        $this->assertAccount($user, $host);
        $this->assertDatabase($db);
        $privs = $this->validatedPrivileges($privileges);
        $pdo->exec(
            'GRANT ' . $privs . ' ON `' . $db . '`.* TO ' . $this->quoteAccount($user, $host)
        );
    }

    /**
     * Cabut privilege dari user@host untuk sebuah database.
     *
     * @param array<int,string> $privileges token dari DbUserManager::PRIVILEGES
     */
    public function revoke(PDO $pdo, string $user, string $host, string $db, array $privileges): void
    {
        $this->assertAccount($user, $host);
        $this->assertDatabase($db);
        $privs = $this->validatedPrivileges($privileges);
        $pdo->exec(
            'REVOKE ' . $privs . ' ON `' . $db . '`.* FROM ' . $this->quoteAccount($user, $host)
        );
    }

    /**
     * @param array<int,string> $privileges
     */
    private function validatedPrivileges(array $privileges): string
    {
        $selected = [];
        foreach ($privileges as $p) {
            $p = strtoupper(trim((string) $p));
            if ($p === 'ALL') {
                $p = 'ALL PRIVILEGES';
            }
            if (in_array($p, self::PRIVILEGES, true) && !in_array($p, $selected, true)) {
                $selected[] = $p;
            }
        }
        if ($selected === []) {
            throw new RuntimeException('Pilih minimal satu privilege.');
        }
        return implode(', ', $selected);
    }

    private function assertAccount(string $user, string $host): void
    {
        if (!preg_match('/^[A-Za-z0-9_.$-]+$/', $user) || $user === '') {
            throw new RuntimeException('Username tidak valid.');
        }
        if (!preg_match('/^[A-Za-z0-9_.%-]+$/', $host) || $host === '') {
            throw new RuntimeException('Host tidak valid.');
        }
    }

    private function assertDatabase(string $db): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
            throw new RuntimeException('Nama database tidak valid.');
        }
    }

    /**
     * Literal `'user'@'host'` dengan escaping single-quote.
     */
    private function quoteAccount(string $user, string $host): string
    {
        $user = str_replace("'", "''", $user);
        $host = str_replace("'", "''", $host);
        return "'{$user}'@'{$host}'";
    }
}
