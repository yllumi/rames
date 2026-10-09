<?php
declare(strict_types=1);

namespace Tests\Support;

use app\library\Storage\SchemaMigrations;
use app\library\Storage\SqliteDatabase;
use app\library\Storage\SqliteStore;

/**
 * Helper penulisan data SQLite untuk tes (misi store SQLite & berikutnya).
 *
 * Statik, tanpa I/O di luar berkas DB yang diberikan, dan selalu lewat
 * {@see SqliteStore} (bukan menulis JSON).
 */
final class SqliteFixture
{
    /**
     * Tulis store `apps` (list).
     *
     * @param array<int,array> $apps
     */
    public static function apps(string $dbFile, array $apps): void
    {
        self::store('apps', $dbFile)->write(array_values($apps));
    }

    /**
     * Tulis store `auth` (list user).
     *
     * @param array<int,array> $users
     */
    public static function users(string $dbFile, array $users): void
    {
        self::store('auth', $dbFile)->write(array_values($users));
    }

    /**
     * Tulis store `billing` (map users/usage/orders).
     *
     * @param array{users?:array,usage?:array,orders?:array} $struct
     */
    public static function billing(string $dbFile, array $struct): void
    {
        self::store('billing', $dbFile)->write([
            'users' => $struct['users'] ?? [],
            'usage' => $struct['usage'] ?? [],
            'orders' => $struct['orders'] ?? [],
        ]);
    }

    /**
     * Tulis store `backup` (map volumes/registry).
     *
     * @param array{volumes?:array,registry?:array} $struct
     */
    public static function backup(string $dbFile, array $struct): void
    {
        self::store('backup', $dbFile)->write([
            'volumes' => $struct['volumes'] ?? [],
            'registry' => $struct['registry'] ?? [],
        ]);
    }

    /**
     * Baca seluruh isi sebuah store (bentuknya seperti `JsonStore::read()`).
     *
     * @return array
     */
    public static function readAll(string $dbFile, string $store): array
    {
        return self::store($store, $dbFile)->read();
    }

    /**
     * Jumlah baris sebuah tabel store (mis. untuk membuktikan "tanpa tulis").
     */
    public static function count(string $dbFile, string $store, string $table): int
    {
        $pdo = (new SqliteDatabase($dbFile))->pdo();
        $name = SqliteStore::tableName($store, $table);

        return (int) $pdo->query('SELECT COUNT(*) FROM "' . $name . '"')->fetchColumn();
    }

    private static function store(string $store, string $dbFile): SqliteStore
    {
        return new SqliteStore($store, SchemaMigrations::storeDefinitions()[$store], $dbFile);
    }
}
