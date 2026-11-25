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
    public const VERSION = '1.2.0';

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
                ['name' => 'Domains', 'description' => 'Custom domain, domain tambahan (alias), dan status SSL-nya'],
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
            '/api/v1/apps/{slug}/domains' => [
                'get' => [
                    'tags' => ['Domains'],
                    'operationId' => 'listAppDomains',
                    'summary' => 'Status domain app yang sudah diset',
                    'description' => 'Subdomain efektif, custom domain utama, dan domain tambahan '
                        . '(alias) beserta status SSL per slot. Field `served=false` berarti app tidak '
                        . 'mem-publish port host sehingga Nginx tidak melayani domain mana pun. '
                        . 'Tidak memuat secret maupun keluaran certbot mentah (`error_summary` sudah diringkas).',
                    'parameters' => [self::slugParameter()],
                    'responses' => [
                        '200' => [
                            'description' => 'Status domain app.',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/DomainStateEnvelope'],
                                    'example' => [
                                        'code' => 0,
                                        'data' => [
                                            'slug' => 'contoh-app',
                                            'served' => true,
                                            'subdomain' => [
                                                'domain' => 'contoh-app.example.org',
                                                'role' => 'redirect',
                                                'ssl' => [
                                                    'status' => 'active',
                                                    'stage' => null,
                                                    'message' => null,
                                                    'expires_at' => '2027-02-01T00:00:00+00:00',
                                                    'error_summary' => '',
                                                ],
                                            ],
                                            'primary' => [
                                                'domain' => 'contoh.example.com',
                                                'role' => 'serve',
                                                'ssl' => [
                                                    'status' => 'disabled',
                                                    'stage' => null,
                                                    'message' => null,
                                                    'expires_at' => null,
                                                    'error_summary' => '',
                                                ],
                                            ],
                                            'aliases' => [
                                                [
                                                    'domain' => 'alias.example.com',
                                                    'target_host_port' => 30002,
                                                    'role' => 'serve',
                                                    'ssl' => [
                                                        'status' => 'pending',
                                                        'stage' => 'queued',
                                                        'message' => 'Menunggu antrean SSL.',
                                                        'expires_at' => null,
                                                        'error_summary' => '',
                                                    ],
                                                ],
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
                'post' => [
                    'tags' => ['Domains'],
                    'operationId' => 'addAppDomainAlias',
                    'summary' => 'Tambah domain tambahan (alias) app',
                    'description' => 'Alias melayani app **langsung** (tanpa redirect 301) sehingga app '
                        . 'multi-site dapat merutekan berdasarkan header `Host`. Butuh ability `domain` '
                        . '(operator+). Validasi sama dengan dashboard: FQDN publik, bukan subdomain/'
                        . 'domain utama app, unik per server terhadap app lain & domain parkir, maksimal '
                        . '20 alias, dan `target_host_port` wajib port yang dipublikasikan app '
                        . '(`0`/kosong = primary service). Config Nginx ditulis ulang + reload; '
                        . 'kegagalannya dilaporkan di `warnings` tanpa membatalkan data.',
                    'parameters' => [self::slugParameter()],
                    'requestBody' => self::domainAliasBody(),
                    'responses' => [
                        '200' => self::domainResultResponse('Alias ditambahkan.'),
                        '401' => self::errorResponse('Token tidak ada / salah.'),
                        '404' => self::errorResponse('App tidak ada **atau** tidak boleh diakses aktor.'),
                        '409' => self::errorResponse('Nama app ambigu di beberapa server.'),
                        '422' => self::errorResponse('Validasi gagal (FQDN tidak sah, duplikat, batas alias, port tujuan tidak dipublikasikan, app tanpa host port).'),
                    ],
                ],
            ],
            '/api/v1/apps/{slug}/domain' => [
                'put' => [
                    'tags' => ['Domains'],
                    'operationId' => 'setAppPrimaryDomain',
                    'summary' => 'Set / ganti custom domain utama app',
                    'description' => 'Perilaku identik form dashboard: domain disimpan sebagai '
                        . '`custom_domain`, sertifikat domain lama di-revoke, config Nginx ditulis ulang '
                        . 'di server pemilik app, dan subdomain bawaan dialihkan (301) ke domain ini. '
                        . 'Mengirim domain yang sama ⇒ `changed=false` tanpa efek samping. Butuh '
                        . 'ability `domain` (operator+). DNS A/AAAA record domain tetap tanggung jawab '
                        . 'user; aktifkan SSL lewat halaman `domains`/detail app.',
                    'parameters' => [self::slugParameter()],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['domain'],
                                    'properties' => ['domain' => ['type' => 'string', 'example' => 'contoh.example.com']],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => self::domainResultResponse('Custom domain utama tersimpan.'),
                        '401' => self::errorResponse('Token tidak ada / salah.'),
                        '404' => self::errorResponse('App tidak ada **atau** tidak boleh diakses aktor.'),
                        '409' => self::errorResponse('Nama app ambigu di beberapa server.'),
                        '422' => self::errorResponse('Validasi gagal (FQDN tidak sah, sama dengan subdomain/alias app, dipakai app lain atau domain parkir, app tanpa host port).'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Domains'],
                    'operationId' => 'removeAppPrimaryDomain',
                    'summary' => 'Hapus custom domain utama app',
                    'description' => 'Sertifikat SSL domain di-revoke best-effort; subdomain kembali '
                        . 'melayani app. Idempoten: app tanpa custom domain ⇒ `200` `changed=false`. '
                        . 'Butuh ability `domain` (operator+).',
                    'parameters' => [self::slugParameter()],
                    'responses' => [
                        '200' => self::domainResultResponse('Custom domain utama dihapus (bila ada).'),
                        '401' => self::errorResponse('Token tidak ada / salah.'),
                        '404' => self::errorResponse('App tidak ada **atau** tidak boleh diakses aktor.'),
                        '409' => self::errorResponse('Nama app ambigu di beberapa server.'),
                    ],
                ],
            ],
            '/api/v1/apps/{slug}/domains/{domain}' => [
                'patch' => [
                    'tags' => ['Domains'],
                    'operationId' => 'updateAppDomainAlias',
                    'summary' => 'Ubah tujuan proxy satu domain tambahan',
                    'description' => 'Field `target_host_port` = host port app tujuan `proxy_pass`; '
                        . '`0`/kosong = kembali ke primary service. Berguna untuk app multi-service '
                        . '(satu domain ke web utama, domain lain ke service port lain). Nama domain '
                        . 'adalah identitas entri dan tidak bisa diubah lewat PATCH. Butuh ability '
                        . '`domain` (operator+); alias tak dikenal ⇒ 404.',
                    'parameters' => [self::slugParameter(), self::domainParameter()],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => ['target_host_port' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535, 'example' => 30002]],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => self::domainResultResponse('Tujuan proxy alias diperbarui.'),
                        '401' => self::errorResponse('Token tidak ada / salah.'),
                        '404' => self::errorResponse('App tidak ada / tidak boleh diakses aktor, atau alias tidak terdaftar.'),
                        '409' => self::errorResponse('Nama app ambigu di beberapa server.'),
                        '422' => self::errorResponse('Port tujuan tidak sah / tidak dipublikasikan app.'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Domains'],
                    'operationId' => 'removeAppDomainAlias',
                    'summary' => 'Hapus satu domain tambahan (alias)',
                    'description' => 'Hanya entri alias yang diminta yang dibuang (alias lain tetap). '
                        . 'Sertifikat SSL alias di-revoke best-effort. Alias tak dikenal ⇒ 404 '
                        . '`alias_not_found`. Butuh ability `domain` (operator+).',
                    'parameters' => [self::slugParameter(), self::domainParameter()],
                    'responses' => [
                        '200' => self::domainResultResponse('Alias dihapus.'),
                        '401' => self::errorResponse('Token tidak ada / salah.'),
                        '404' => self::errorResponse('App tidak ada / tidak boleh diakses aktor, atau alias tidak terdaftar.'),
                        '409' => self::errorResponse('Nama app ambigu di beberapa server.'),
                    ],
                ],
            ],
            '/api/v1/apps/{slug}/domains/{domain}/ssl' => [
                'post' => [
                    'tags' => ['Domains'],
                    'operationId' => 'enableAppDomainSsl',
                    'summary' => 'Ajukan aktivasi SSL satu domain app',
                    'description' => 'Meminta sertifikat Let\'s Encrypt untuk satu domain milik app '
                        . '(subdomain efektif, custom domain utama, atau alias). Permintaan **masuk antrean '
                        . 'global** `ssl_jobs` — respons `202` hanya berarti job sudah diantrekan, bukan '
                        . 'sertifikat terbit. Worker `cli/ssl.php` menulis ulang config Nginx (blok ACME/443), '
                        . 'me-reload Nginx di server pemilik app, lalu menjalankan certbot (app di server '
                        . 'agent ⇒ job `ssl_issue` di sana). Butuh ability `ssl` (operator+). Idempoten: '
                        . 'slot yang sudah `active`/`pending` ⇒ `200` dengan `already:true` tanpa job baru. '
                        . 'Pantau hasil lewat `GET /api/v1/apps/{slug}/domains`.',
                    'parameters' => [self::slugParameter(), self::domainParameter()],
                    'responses' => [
                        '202' => [
                            'description' => 'Job SSL masuk antrean; penerbitan berjalan asinkron.',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SslAccepted'],
                                    'example' => [
                                        'code' => 0,
                                        'data' => [
                                            'slug' => 'contoh-app',
                                            'domain' => 'contoh.example.com',
                                            'slot' => 'custom_ssl',
                                            'status' => 'pending',
                                            'message' => 'SSL untuk contoh.example.com masuk antrean (posisi 1 dari 1).',
                                            'queued' => true,
                                            'queue_position' => 1,
                                            'queue_size' => 1,
                                            'poll_url' => '/api/v1/apps/contoh-app/domains',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '200' => [
                            'description' => 'Slot sudah `active`/`pending` — tidak ada job baru (idempoten).',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SslAccepted'],
                                    'example' => [
                                        'code' => 0,
                                        'data' => [
                                            'slug' => 'contoh-app',
                                            'domain' => 'contoh.example.com',
                                            'slot' => 'custom_ssl',
                                            'status' => 'pending',
                                            'message' => 'SSL untuk contoh.example.com sudah pending.',
                                            'already' => true,
                                            'poll_url' => '/api/v1/apps/contoh-app/domains',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '401' => self::errorResponse('Token tidak ada / salah.'),
                        '404' => self::errorResponse('App tidak ada / tidak boleh diakses aktor, atau domain bukan milik app (`domain_not_found`).'),
                        '409' => self::errorResponse('Nama app ambigu di beberapa server.'),
                        '422' => self::errorResponse('Validasi gagal (app tanpa host port, server agent tanpa APP_DOMAIN, domain bukan publik).'),
                        '503' => self::errorResponse('Antrean SSL tidak dapat ditulis (`ssl_queue_failed`); status slot ditandai `failed`.'),
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
    private static function domainParameter(): array
    {
        return [
            'name' => 'domain',
            'in' => 'path',
            'required' => true,
            'description' => 'Domain tambahan (alias) yang sudah terdaftar pada app — lowercase, tanpa titik akhir.',
            'schema' => ['type' => 'string', 'example' => 'alias.example.com'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function domainAliasBody(): array
    {
        return [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'required' => ['domain'],
                        'properties' => [
                            'domain' => ['type' => 'string', 'example' => 'alias.example.com'],
                            'target_host_port' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => 65535,
                                'description' => 'Host port tujuan `proxy_pass`; `0`/kosong = primary service app.',
                                'example' => 30002,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function domainResultResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/DomainResultEnvelope'],
                ],
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
            'DomainStateEnvelope' => [
                'type' => 'object',
                'required' => ['code', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'const' => 0],
                    'data' => ['$ref' => '#/components/schemas/DomainState'],
                ],
            ],
            'DomainResultEnvelope' => [
                'type' => 'object',
                'required' => ['code', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'const' => 0],
                    'data' => ['$ref' => '#/components/schemas/DomainResult'],
                ],
            ],
            'DomainResult' => [
                'type' => 'object',
                'required' => ['changed', 'applied', 'reloaded', 'domain', 'warnings', 'domains'],
                'properties' => [
                    'changed' => ['type' => 'boolean', 'description' => '`false` = permintaan idempoten tanpa perubahan.'],
                    'applied' => [
                        'type' => 'boolean',
                        'description' => '`true` = config Nginx berhasil ditulis (belum tentu reload sukses).',
                    ],
                    'reloaded' => [
                        'type' => 'boolean',
                        'description' => '`true` = reload Nginx di server pemilik app berhasil (bagian dari proses API; berlaku untuk app lokal maupun server agent).',
                    ],
                    'domain' => ['type' => 'string', 'description' => 'Domain yang disentuh operasi ini.'],
                    'target_host_port' => [
                        'type' => 'integer',
                        'description' => 'Host port tujuan alias (`0` = primary service); operasi domain utama = 0.',
                    ],
                    'warnings' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Kegagalan tulis/reload Nginx atau revoke sertifikat yang tidak membatalkan data.',
                    ],
                    'domains' => ['$ref' => '#/components/schemas/DomainState'],
                ],
            ],
            'DomainState' => [
                'type' => 'object',
                'required' => ['slug', 'served', 'primary', 'aliases'],
                'properties' => [
                    'slug' => ['type' => 'string'],
                    'served' => [
                        'type' => 'boolean',
                        'description' => '`false` = app tanpa host port terpublish; Nginx tidak melayani domain mana pun.',
                    ],
                    'subdomain' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/DomainEntry'],
                            ['type' => 'null'],
                        ],
                    ],
                    'primary' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/DomainEntry'],
                            ['type' => 'null'],
                        ],
                    ],
                    'aliases' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/DomainEntry'],
                    ],
                ],
            ],
            'DomainEntry' => [
                'type' => 'object',
                'required' => ['domain', 'role', 'ssl'],
                'properties' => [
                    'domain' => ['type' => 'string'],
                    'role' => [
                        'type' => 'string',
                        'enum' => ['serve', 'redirect'],
                        'description' => '`serve` = melayani app; `redirect` = subdomain dialihkan 301 ke custom domain utama.',
                    ],
                    'target_host_port' => [
                        'type' => 'integer',
                        'description' => 'Hanya alias: host port tujuan `proxy_pass` (`0` = primary service).',
                    ],
                    'ssl' => ['$ref' => '#/components/schemas/DomainSsl'],
                ],
            ],
            'DomainSsl' => [
                'type' => 'object',
                'required' => ['status', 'stage', 'message', 'expires_at', 'error_summary'],
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'Status SSL slot: `disabled`, `pending`, `active`, `error`.',
                    ],
                    'stage' => ['type' => ['string', 'null'], 'description' => 'Tahap job SSL saat `pending`.'],
                    'message' => ['type' => ['string', 'null']],
                    'expires_at' => ['type' => ['string', 'null'], 'description' => 'Masa berlaku sertifikat (ISO 8601).'],
                    'error_summary' => [
                        'type' => 'string',
                        'description' => 'Ringkasan satu baris galat SSL (bukan keluaran certbot mentah); kosong = tidak ada galat.',
                    ],
                ],
            ],
            'SslAccepted' => [
                'type' => 'object',
                'required' => ['code', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'const' => 0],
                    'data' => [
                        'type' => 'object',
                        'required' => ['domain', 'status', 'message', 'poll_url'],
                        'properties' => [
                            'slug' => ['type' => 'string'],
                            'domain' => ['type' => 'string'],
                            'slot' => [
                                'type' => ['string', 'null'],
                                'description' => 'Slot SSL: `ssl` (subdomain), `custom_ssl` (domain utama), `alias:<domain>`.',
                            ],
                            'status' => ['type' => 'string', 'description' => '`pending` saat diantrekan; status slot saat `already`.'],
                            'message' => ['type' => 'string'],
                            'queued' => ['type' => 'boolean', 'description' => '`true` = job baru dibuat (202).'],
                            'queue_position' => ['type' => 'integer', 'description' => 'Posisi antrean (1 = berikutnya).'],
                            'queue_size' => ['type' => 'integer'],
                            'already' => ['type' => 'boolean', 'description' => '`true` = slot sudah active/pending (200, tanpa job baru).'],
                            'poll_url' => ['type' => 'string', 'description' => 'URL status domain untuk polling.'],
                        ],
                    ],
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
                            . '`ambiguous_app`, `build_rejected`, `insufficient_credits`, '
                            . '`validation_failed`, `invalid_json`, `invalid_field`, `alias_not_found`, '
                            . '`domain_not_found`, `ssl_queue_failed`.',
                    ],
                ],
            ],
        ];
    }
}
