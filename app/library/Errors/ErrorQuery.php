<?php
declare(strict_types=1);

namespace app\library\Errors;

use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use Throwable;

/**
 * Query siap-render untuk halaman Error Log (daftar + detail).
 *
 * Controller **tidak** merakit data sendiri: semua pembacaan/filter/pagination
 * ada di sini. Nama app & server di-resolve **sekali** dari `AppStore::all()`
 * / `ServerStore::all()` (peta id → nama), bukan per baris (hindari N+1).
 *
 * **Satu sumber DB**: `AppStore`/`ServerStore` selalu dibangun dari
 * `ErrorStore::path()` (bukan `config('deploy.sqlite_file')` langsung), sehingga
 * store yang di-inject dengan DB lain saat uji/di caller lain tetap menampilkan
 * nama yang benar. Keduanya bisa di-override lewat konstruktor (seam uji).
 */
final class ErrorQuery
{
    private readonly ?ErrorStore $store;
    private readonly ?AppStore $apps;
    private readonly ?ServerStore $servers;

    /**
     * @param ErrorStore|null    $store   seam uji; null = DB dari config
     * @param AppStore|null      $apps    seam uji; null = DB dari `ErrorStore::path()`
     * @param ServerStore|null   $servers seam uji; null = DB dari `ErrorStore::path()`
     */
    public function __construct(?ErrorStore $store = null, ?AppStore $apps = null, ?ServerStore $servers = null)
    {
        $this->store = $store;
        $this->apps = $apps;
        $this->servers = $servers;
    }

    /**
     * Daftar grup + total + halaman + hitungan status.
     *
     * @param array<string,mixed> $filters
     * @return array{
     *     groups:list<array<string,mixed>>,
     *     total:int,
     *     pages:int,
     *     counts:array{open:int,resolved:int,ignored:int}
     * }
     */
    public function index(array $filters, int $page = 1, int $perPage = 50): array
    {
        $result = $this->store()->listGroups($filters, $page, $perPage);
        $maps = $this->names();

        $groups = [];
        foreach ($result['groups'] as $group) {
            $groups[] = self::decorate($group, $maps);
        }

        return [
            'groups' => $groups,
            'total' => $result['total'],
            'pages' => $result['pages'],
            'counts' => $this->store()->counts(),
        ];
    }

    /**
     * Detail satu grup + sampel event, atau `null` bila tidak ada.
     *
     * @return array{group:array<string,mixed>,events:list<array<string,mixed>>}|null
     */
    public function detail(string $id): ?array
    {
        $group = $this->store()->findGroup($id);
        if ($group === null) {
            return null;
        }

        return [
            'group' => self::decorate($group, $this->names()),
            'events' => $this->store()->eventsForGroup($id),
        ];
    }

    public function openCount(): int
    {
        return $this->store()->openCount();
    }

    /** Delegasi mutasi status agar controller tetap hanya bicara ke satu seam. */
    public function setStatus(string $id, string $status, ?string $actor): bool
    {
        return $this->store()->setStatus($id, $status, $actor);
    }

    /** Delegasi hapus grup (beserta sampel) ke store. */
    public function deleteGroup(string $id): bool
    {
        return $this->store()->deleteGroup($id);
    }

    // ==================================================================
    // Internal
    // ==================================================================

    private function store(): ErrorStore
    {
        return $this->store ?? new ErrorStore();
    }

    /**
     * Peta nama app & server (satu kali per panggilan).
     *
     * Kegagalan store tidak boleh menggagalkan halaman error log: nama bersifat
     * pelengkap, id tetap ditampilkan oleh view.
     *
     * @return array{apps:array<string,string>,servers:array<string,string>}
     */
    private function names(): array
    {
        $apps = [];
        $servers = [];

        // Satu sumber DB: pakai path milik ErrorStore (bukan config langsung).
        try {
            $path = $this->store()->path();
        } catch (Throwable) {
            $path = null;
        }

        try {
            $appStore = $this->apps ?? ($path !== null ? new AppStore($path) : new AppStore());
            foreach ($appStore->all() as $app) {
                if (!is_array($app)) {
                    continue;
                }
                $id = (string) ($app['id'] ?? '');
                if ($id !== '') {
                    $apps[$id] = (string) ($app['name'] ?? '');
                }
            }
        } catch (Throwable) {
            $apps = [];
        }

        try {
            $serverStore = $this->servers ?? ($path !== null ? new ServerStore($path) : new ServerStore());
            foreach ($serverStore->all() as $server) {
                if (!is_array($server)) {
                    continue;
                }
                $id = (string) ($server['id'] ?? '');
                if ($id !== '') {
                    $servers[$id] = (string) ($server['name'] ?? '');
                }
            }
        } catch (Throwable) {
            $servers = [];
        }

        return ['apps' => $apps, 'servers' => $servers];
    }

    /**
     * @param array<string,mixed> $group
     * @param array{apps:array<string,string>,servers:array<string,string>} $maps
     * @return array<string,mixed>
     */
    private static function decorate(array $group, array $maps): array
    {
        $appId = (string) ($group['app_id'] ?? '');
        $serverId = (string) ($group['server_id'] ?? '');

        $group['app_name'] = $appId !== '' ? ($maps['apps'][$appId] ?? '') : '';
        $group['server_name'] = $serverId !== '' ? ($maps['servers'][$serverId] ?? '') : '';

        return $group;
    }
}
