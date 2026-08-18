<?php
declare(strict_types=1);

namespace app\library\Storage;

use RuntimeException;

/**
 * Penyimpanan app (database/apps.json). Struktur app mengikuti SPECS.md §7.1.
 */
class AppStore
{
    private JsonStore $store;

    public function __construct(?string $filePath = null)
    {
        $this->store = new JsonStore($filePath ?? (config('deploy.database_path') . '/apps.json'));
    }

    /**
     * @return array<int,array>
     */
    public function all(): array
    {
        return $this->store->read();
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $app) {
            if (($app['id'] ?? '') === $id) {
                return $app;
            }
        }
        return null;
    }

    public function findByName(string $name): ?array
    {
        foreach ($this->all() as $app) {
            if (($app['name'] ?? '') === $name) {
                return $app;
            }
        }
        return null;
    }

    public function nameExists(string $name): bool
    {
        return $this->findByName($name) !== null;
    }

    public function create(array $app): array
    {
        $app['id'] = $app['id'] ?? bin2hex(random_bytes(8));
        $app['created_at'] = $app['created_at'] ?? date('c');
        $app['updated_at'] = date('c');

        $this->store->update(function (array &$data) use ($app): void {
            foreach ($data as $existing) {
                if (($existing['name'] ?? '') === $app['name']) {
                    throw new RuntimeException("Nama app \"{$app['name']}\" sudah dipakai.");
                }
            }
            $data[] = $app;
        });

        return $app;
    }

    /**
     * Update app by id; mutator(array &$app): void.
     */
    public function update(string $id, callable $mutator): array
    {
        $updated = null;
        $this->store->update(function (array &$data) use ($id, $mutator, &$updated): void {
            foreach ($data as &$app) {
                if (($app['id'] ?? '') === $id) {
                    $mutator($app);
                    $app['updated_at'] = date('c');
                    $updated = $app;
                    return;
                }
            }
            throw new RuntimeException("App tidak ditemukan: {$id}");
        });

        return $updated ?? [];
    }

    public function delete(string $id): void
    {
        $this->store->update(function (array &$data) use ($id): void {
            $data = array_values(array_filter(
                $data,
                static fn (array $s): bool => ($s['id'] ?? '') !== $id
            ));
        });
    }
}
