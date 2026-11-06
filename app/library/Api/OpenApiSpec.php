<?php
declare(strict_types=1);

namespace app\library\Api;

/**
 * Dokumen OpenAPI 3.1 untuk API eksternal dashboard — **satu sumber** untuk
 * dua konsumen:
 *
 *  - `GET /api-docs/openapi.json` (JSON mentah untuk tooling/klien — di balik
 *    login dashboard);
 *  - halaman `/api-docs` (dirender server-side dari array yang sama).
 *
 * Jangan menulis daftar endpoint/field di view: tambah/ubah di sini.
 *
 * Cakupan fase awal (docs/SPECS.md §7.19): build ulang app berbasis **nama
 * (slug)** + pembacaan status app untuk polling build. Endpoint lain menyusul;
 * setiap penambahan wajib memperbarui `paths` dan (bila perlu) `components`.
 */
final class OpenApiSpec
{
    public const VERSION = '1.0.0';

    /**
     * Tool yang disediakan server MCP (`tools/mcp/rames_mcp.py`) — satu sumber
     * untuk dokumentasi di halaman `/api-docs` dan untuk uji konsistensi
     * (`tests/OpenApiSpecTest.php` membandingkan nama tool dengan berkas Python).
     *
     * @var array<int,array{name:string,title:string,method:string,path:string,description:string}>
     */
    public const MCP_TOOLS = [
        [
            'name' => 'rames_app_status',
            'title' => 'Rames: status app',
            'method' => 'GET',
            'path' => '/api/v1/apps/{slug}',
            'description' => 'Status app berdasarkan nama (slug) — dipakai untuk polling setelah build '
                . 'sampai `status` bukan `deploying`.',
        ],
        [
            'name' => 'rames_build_app',
            'title' => 'Rames: build ulang app',
            'method' => 'POST',
            'path' => '/api/v1/apps/{slug}/build',
            'description' => 'Build ulang app (asinkron, 202): sama dengan tombol Rebuild di dashboard '
                . '(gerbang kredit & guard app impor berlaku).',
        ],
    ];

    /** Nama server MCP (dipakai klien untuk mengidentifikasi). */
    public const MCP_SERVER_NAME = 'rames';

    /** Versi server MCP — samakan dengan `SERVER_VERSION` di `tools/mcp/rames_mcp.py`. */
    public const MCP_SERVER_VERSION = '1.0.0';

    /** Skrip server MCP relatif akar repo. */
    public const MCP_SCRIPT = 'tools/mcp/rames_mcp.py';

    /**
     * Path dokumen ini di dashboard — **sesi login wajib** (bukan permukaan
     * `/api/v1/**`): dokumentasi API tidak boleh diambil anonim.
     */
    public const DOCS_JSON_PATH = '/api-docs/openapi.json';

    /**
     * Dokumen OpenAPI lengkap.
     *
     * @return array<string,mixed>
     */
    public static function build(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Rames API',
                'version' => self::VERSION,
                'description' => implode("\n\n", [
                    'API eksternal dashboard Rames untuk otomatisasi CI/CD dan tooling internal.',
                    'Autentikasi memakai **API key per user**: setiap user membuat/mencabut key-nya '
                    . 'sendiri di halaman Profil (`/profile`); key hanya ditampilkan sekali dan yang '
                    . 'disimpan server hanyalah hash-nya. Key bertindak sebagai user pemiliknya, '
                    . 'sehingga pemeriksaan hak app (`AppAccess`) dan gerbang kredit berjalan sama '
                    . 'seperti aksi di UI.',
                    'Build berjalan **asinkron** di worker deploy: respons `202` hanya berarti worker '
                    . 'sudah di-spawn. Pantau `GET /api/v1/apps/{slug}` sampai `status` bukan '
                    . '`deploying`.',
                    'Dokumentasi ini (halaman `/api-docs` dan berkas OpenAPI JSON) hanya dapat '
                    . 'diakses setelah login dashboard.',
                ]),
            ],
            'servers' => [
                ['url' => '/', 'description' => 'Dashboard Rames ini'],
            ],
            'tags' => [
                ['name' => 'Apps', 'description' => 'Status & aksi siklus hidup app'],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
            'paths' => self::paths(),
            // Ekstensi OpenAPI (prefix `x-`) — dibaca halaman /api-docs untuk
            // kartu "MCP" (server stdio + dua tool di atas).
            'x-rames-mcp' => self::mcp(),
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'API key per user (dibuat di `/profile`, tampil sekali) pada '
                            . 'header `Authorization: Bearer <api-key>`. Key yang tidak dikenal/dicabut '
                            . '⇒ 401.',
                    ],
                    'sessionLogin' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => 'PHPSID',
                        'description' => 'Sesi login dashboard (cookie). Hanya untuk halaman '
                            . '`/api-docs` & dokumen JSON-nya — endpoint `/api/v1/**` memakai bearer token.',
                    ],
                ],
                'schemas' => self::schemas(),
            ],
        ];
    }

    /**
     * Metadata server MCP untuk dokumentasi (bukan bagian protokol OpenAPI —
     * dikirim sebagai extension `x-rames-mcp`).
     *
     * @return array<string,mixed>
     */
    public static function mcp(): array
    {
        return [
            'name' => self::MCP_SERVER_NAME,
            'version' => self::MCP_SERVER_VERSION,
            'transport' => 'stdio',
            'protocol' => '2025-06-18',
            'command' => 'python3',
            'args' => [self::MCP_SCRIPT],
            'script' => self::MCP_SCRIPT,
            'docs' => 'tools/mcp/README.md',
            'requires' => 'Python >= 3.9 (pustaka standar saja, tanpa pip/composer/npm)',
            'env' => [
                'RAMES_URL' => 'Alamat dashboard TANPA garis miring di akhir, mis. http://localhost:8123.',
                'RAMES_API_KEY' => 'API key per user (Profil → API key). Tanpa ini tool menolak jalan.',
                'RAMES_TIMEOUT' => 'Timeout HTTP detik (opsional; default & maksimum 30).',
            ],
            'tools' => self::MCP_TOOLS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function paths(): array
    {
        return [
            '/api/v1/apps/{slug}/build' => [
                'post' => [
                    'tags' => ['Apps'],
                    'operationId' => 'buildApp',
                    'summary' => 'Build ulang app berdasarkan nama (slug)',
                    'description' => implode(' ', [
                        'Menjalankan `docker compose build` + recreate untuk app (mode `rebuild`,',
                        'tanpa `git pull`). Sama persis dengan tombol **Rebuild** di dashboard:',
                        'gerbang kredit, guard app impor, dan penolakan saat app sedang deploy',
                        'berlaku. Worker berjalan detached — respons `202` tidak menunggu build',
                        'selesai.',
                    ]),
                    'parameters' => [self::slugParameter()],
                    'responses' => [
                        '202' => [
                            'description' => 'Worker build sudah di-spawn; build berjalan asinkron.',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/BuildAccepted'],
                                    'example' => [
                                        'code' => 0,
                                        'data' => [
                                            'slug' => 'contoh-app',
                                            'id' => 'aff2e53c04e6a74c',
                                            'status' => 'deploying',
                                            'stage' => 'queued',
                                            'message' => 'Rebuild dimulai.',
                                            'poll_url' => '/api/v1/apps/contoh-app',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '401' => self::errorResponse('Token tidak ada / salah, atau user aktor tidak ditemukan.'),
                        '402' => self::errorResponse('Kredit tidak cukup untuk memulai build (user member).'),
                        '404' => self::errorResponse('App tidak ada **atau** tidak boleh diakses aktor (sengaja tidak dibedakan).'),
                        '409' => self::errorResponse('App sedang `deploying`, **atau** nama app ambigu di beberapa server.'),
                        '422' => self::errorResponse('Build ditolak: app impor project compose eksternal, atau direktori app tidak ada.'),
                        '500' => self::errorResponse('Kesalahan tak terduga saat menyiapkan worker.'),
                    ],
                ],
            ],
            '/api/v1/apps/{slug}' => [
                'get' => [
                    'tags' => ['Apps'],
                    'operationId' => 'getApp',
                    'summary' => 'Status app berdasarkan nama (slug)',
                    'description' => 'Ringkasan status app untuk polling hasil build. '
                        . 'Tidak memuat secret (environment, kunci repo/agent) — hanya field operasional.',
                    'parameters' => [self::slugParameter()],
                    'responses' => [
                        '200' => [
                            'description' => 'Status app.',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/AppEnvelope'],
                                    'example' => [
                                        'code' => 0,
                                        'data' => [
                                            'slug' => 'contoh-app',
                                            'id' => 'aff2e53c04e6a74c',
                                            'server_id' => 'local',
                                            'status' => 'running',
                                            'stage' => null,
                                            'message' => 'Running',
                                            'domain' => 'contoh-app.example.com',
                                            'created_at' => '2026-09-16T14:43:53+07:00',
                                            'updated_at' => '2026-11-06T07:30:00+07:00',
                                            'containers' => [
                                                [
                                                    'service' => 'web',
                                                    'name' => 'contoh-app',
                                                    'status' => 'running',
                                                    'ports' => [['host' => 30000, 'container' => 8787]],
                                                ],
                                            ],
                                            'last_deploy' => [
                                                'sha' => 'c49156078a6f9ec7624d07a14a546e438b0e8450',
                                                'short' => 'c491560',
                                                'action' => 'rebuild',
                                                'status' => 'success',
                                                'message' => '',
                                                'created_at' => '2026-09-16T14:44:33+07:00',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '401' => self::errorResponse('Token tidak ada / salah, atau user aktor tidak ditemukan.'),
                        '404' => self::errorResponse('App tidak ada **atau** tidak boleh diakses aktor.'),
                        '409' => self::errorResponse('Nama app ambigu di beberapa server.'),
                    ],
                ],
            ],
            self::DOCS_JSON_PATH => [
                'get' => [
                    'tags' => ['Apps'],
                    'operationId' => 'getOpenApiSpec',
                    'summary' => 'Dokumen OpenAPI ini (butuh login dashboard)',
                    'description' => 'Dokumen OpenAPI 3.1 dalam JSON, disajikan halaman dashboard '
                        . '`/api-docs` — bukan permukaan `/api/v1/**`, jadi autentikasinya sesi '
                        . 'login (bukan bearer token API).',
                    'security' => [['sessionLogin' => []]],
                    'responses' => [
                        '200' => [
                            'description' => 'Dokumen OpenAPI.',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object', 'additionalProperties' => true],
                                ],
                            ],
                        ],
                        '302' => ['description' => 'Belum login — dialihkan ke `/login`.'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function slugParameter(): array
    {
        return [
            'name' => 'slug',
            'in' => 'path',
            'required' => true,
            'description' => 'Nama app (`apps.name`) — trim + lowercase otomatis.',
            'schema' => [
                'type' => 'string',
                'maxLength' => ApiAppLocator::SLUG_MAX_LENGTH,
                'pattern' => '^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$',
                'example' => 'contoh-app',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Error'],
                    'example' => ['code' => 401, 'error' => 'Unauthorized', 'reason' => 'unauthorized'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function schemas(): array
    {
        return [
            'BuildAccepted' => [
                'type' => 'object',
                'required' => ['code', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'const' => 0],
                    'data' => [
                        'type' => 'object',
                        'required' => ['slug', 'id', 'status', 'stage', 'message', 'poll_url'],
                        'properties' => [
                            'slug' => ['type' => 'string'],
                            'id' => ['type' => 'string', 'description' => 'Id internal app (dashboard).'],
                            'status' => ['type' => 'string', 'const' => 'deploying'],
                            'stage' => ['type' => 'string', 'const' => 'queued'],
                            'message' => ['type' => 'string'],
                            'poll_url' => ['type' => 'string', 'description' => 'URL status app untuk polling.'],
                        ],
                    ],
                ],
            ],
            'AppEnvelope' => [
                'type' => 'object',
                'required' => ['code', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'const' => 0],
                    'data' => ['$ref' => '#/components/schemas/App'],
                ],
            ],
            'App' => [
                'type' => 'object',
                'required' => ['slug', 'id', 'status'],
                'properties' => [
                    'slug' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'server_id' => ['type' => 'string', 'description' => '`local` atau id server agent.'],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Status app: `running`, `stopped`, `deploying`, `error`, dst.',
                    ],
                    'stage' => [
                        'type' => ['string', 'null'],
                        'description' => 'Tahap worker deploy saat `status=deploying`.',
                    ],
                    'message' => ['type' => 'string'],
                    'domain' => ['type' => 'string', 'description' => 'FQDN efektif app.'],
                    'created_at' => ['type' => 'string'],
                    'updated_at' => ['type' => 'string'],
                    'containers' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/Container'],
                    ],
                    'last_deploy' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/DeployEntry'],
                            ['type' => 'null'],
                        ],
                    ],
                ],
            ],
            'Container' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'ports' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'host' => ['type' => 'integer'],
                                'container' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            'DeployEntry' => [
                'type' => 'object',
                'properties' => [
                    'sha' => ['type' => 'string'],
                    'short' => ['type' => 'string'],
                    'action' => ['type' => 'string', 'description' => '`deploy`, `rebuild`, `rollback`, …'],
                    'status' => ['type' => 'string', 'description' => '`success`, `error`, `restored`, …'],
                    'message' => ['type' => 'string'],
                    'created_at' => ['type' => 'string'],
                ],
            ],
            'Error' => [
                'type' => 'object',
                'required' => ['code', 'error'],
                'properties' => [
                    'code' => ['type' => 'integer', 'description' => 'Sama dengan status HTTP.'],
                    'error' => ['type' => 'string', 'description' => 'Pesan siap tampil (bahasa Indonesia).'],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Alasan mesin: `unauthorized`, `not_found`, `app_busy`, '
                            . '`ambiguous_app`, `build_rejected`, `insufficient_credits`.',
                    ],
                ],
            ],
        ];
    }
}
