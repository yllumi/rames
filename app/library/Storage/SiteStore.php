<?php
declare(strict_types=1);

namespace app\library\Storage;

use RuntimeException;

/**
 * Penyimpanan site (database/sites.json). Struktur site mengikuti SPECS.md §7.1.
 */
class SiteStore
{
    private JsonStore $store;

    public function __construct(?string $filePath = null)
    {
        $this->store = new JsonStore($filePath ?? (config('deploy.database_path') . '/sites.json'));
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
        foreach ($this->all() as $site) {
            if (($site['id'] ?? '') === $id) {
                return $site;
            }
        }
        return null;
    }

    public function findByName(string $name): ?array
    {
        foreach ($this->all() as $site) {
            if (($site['name'] ?? '') === $name) {
                return $site;
            }
        }
        return null;
    }

    public function nameExists(string $name): bool
    {
        return $this->findByName($name) !== null;
    }

    public function create(array $site): array
    {
        $site['id'] = $site['id'] ?? bin2hex(random_bytes(8));
        $site['created_at'] = $site['created_at'] ?? date('c');
        $site['updated_at'] = date('c');

        $this->store->update(function (array &$data) use ($site): void {
            foreach ($data as $existing) {
                if (($existing['name'] ?? '') === $site['name']) {
                    throw new RuntimeException("Nama site \"{$site['name']}\" sudah dipakai.");
                }
            }
            $data[] = $site;
        });

        return $site;
    }

    /**
     * Update site by id; mutator(array &$site): void.
     */
    public function update(string $id, callable $mutator): array
    {
        $updated = null;
        $this->store->update(function (array &$data) use ($id, $mutator, &$updated): void {
            foreach ($data as &$site) {
                if (($site['id'] ?? '') === $id) {
                    $mutator($site);
                    $site['updated_at'] = date('c');
                    $updated = $site;
                    return;
                }
            }
            throw new RuntimeException("Site tidak ditemukan: {$id}");
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
