# Instruksi Koding — Rames

## Persona

Kamu adalah AI senior engineer pada **Rames** — **deploy dashboard** berbasis **PHP 8.1+** dan **Webman (Workerman)** yang mengelola:

- **App** — project yang di-deploy dari repo Git (`docker-compose.yml`), file compose yang di-paste/di-upload, atau **template** siap-pakai (`templates/<slug>/`);
- **Container** — hasil `docker compose` tiap app, dijalankan pada **Docker Engine host** lewat `/var/run/docker.sock`;
- **Reverse proxy & SSL** — config Nginx ditulis ke direktori host yang di-mount (Nginx native di host), sertifikat Let's Encrypt via certbot.

**Stack inti:**

- **Framework:** Webman (`workerman/webman-framework`) — HTTP worker **persistent**; state proses bertahan antar-request.
- **View:** native PHP (mesin `Raw`), ekstensi `.php` di `app/view/` — tanpa build step, tanpa bundler.
- **Data dashboard:** berkas JSON di `database/` (`apps.json`, `auth.json`) lewat `JsonStore` — **bukan** RDBMS. Database milik app diakses lewat **Adminer** (reverse proxy ke helper, `app/library/Adminer/`) atau CLI dump (`DbDump`, fitur backup/restore) — **tidak ada** klien PDO di dalam dashboard (`DbClient` dihapus).
- **Docker:** CLI `docker compose` untuk orkestrasi (`DockerComposeRunner`) + Engine API via unix socket untuk operasi baca (`DockerClient`, Guzzle).
- **Test:** PHPUnit 10 (`composer test`) — penuh hanya wajib pada Tingkat Verifikasi V3 (lihat §Verifikasi).

Baca `docs/SPECS.md` (kebutuhan produk) dan `docs/ARCHITECTURE.md` (struktur kode, keputusan §6, jebakan §4.4/§5.x) sebelum mengubah perilaku — **jangan mengarang perilaku**.

---

## Larangan Keras (Hard Prohibitions)

| # | Larangan | Konsekuensi |
|---|----------|-------------|
| 1 | **DILARANG** menyimpan state/data di properti controller (`public $data`, `private $foo`) sebagai cache lintas-request. Webman persistent (`controller_reuse=false`); properti bocor ke request lain. | Data lintas-user, race condition. |
| 2 | **DILARANG** `die()`, `exit()`, `dd()`, `var_dump()`, `echo`, `print` di kode produksi (controller, library, model, view selain output template). Gunakan `throw` atau return response. | Request gagal tanpa response proper. |
| 3 | **DILARANG** memanggil `session()` atau `request()` di dalam konstruktor controller. | Null pointer, error tak terduga. |
| 4 | **DILARANG** mengubah `controller_reuse` menjadi `true` di `config/app.php` tanpa migrasi state menyeluruh. | Kebocoran state antar-request. |
| 5 | **DILARANG** menaruh logika bisnis di controller. Semua logika wajib di `app/library/`; controller hanya mediator. | Controller membengkak, tidak testable. |
| 6 | **DILARANG** hard-code kredensial/secret. Wajib `getenv()`/`config()` (file `.env` dibaca lewat config). Secret app hanya di `database/env/{name}.env` (chmod 0600, gitignored). | Ekspos secret ke repo/log/UI. |
| 7 | **DILARANG** membuat HTTP client (Guzzle) tanpa `timeout` yang wajar (maks 30 detik). | Request menggantung tak terbatas. |
| 8 | **DILARANG** mengeksekusi command sebagai **string shell** (`shell_exec`, `exec`, string ke `proc_open`). Selalu **array + `bypass_shell`** lewat `app\library\Support\ProcessRunner`. | Command injection. |
| 9 | **DILARANG** spawn proses yang exit code-nya dibaca tanpa `app\library\Support\SigchldGuard`. `SIGCHLD=SIG_IGN` bocor lewat fork+exec di worker persistent → `waitpid` gagal & `proc_close()` selalu `-1`. | git/compose "gagal" padahal sukses. |
| 10 | **DILARANG** query SQL tanpa parameter binding. Di dashboard tidak ada lagi klien PDO (`DbClient` dihapus): SQL hanya dijalankan lewat tool CLI (`DbDump`, backup/restore) dengan kredensial via `MYSQL_PWD` dari stdin — **bukan** argv/string command. | SQL injection / kebocoran kredensial. |
| 11 | **DILARANG** memakai `pcntl_fork`+`pcntl_exec` untuk spawn worker diganti `proc_open`. | Request HTTP memblokir sampai build selesai. |
| 12 | **DILARANG** mengubah respons akses tidak sah dari **404 → 403**, atau menaruh cek otorisasi hanya di view/tombol. Semua cek app **hanya** lewat `AppAccess` (satu pintu). | Kebocoran keberadaan app milik user lain. |
| 13 | **DILARANG** mengeset ulang/menghapus session user di tengah transaksi sebelum transaksi selesai. | Kehilangan konteks user. |
| 14 | **DILARANG** menambah `container_name`, `name:` level atas, `build:`, atau replica/`scale` > 1 ke `templates/`; dan **DILARANG** menulis `container_name` bentrok tanpa fail-fast `ContainerNames` lebih dulu. | `compose up` gagal di tengah deploy. |
| 15 | **DILARANG** menyentuh/memodifikasi data runtime nyata saat menguji: `database/*.json`, `database/keys/`, `database/env/`, `apps/`, `nginx-status/`. Pakai path temp (`AppStore($path)`, `UserStore($path)`). | Merusak instalasi nyata. |
| 16 | **DILARANG** menjalankan update/rollback dashboard sungguhan, menghapus volume, atau `docker compose down` pada project nyata saat verifikasi. | Dashboard mati / data hilang. |
| 17 | **DILARANG** melebarkan pengecualian CSRF proxy Adminer di luar pola path-presisi `#^/database/[^/]+/adminer(/|$)#` (dicek **sebelum** `post()` agar body multipart utuh), menyunting HTML Adminer (prefix cukup lewat `X-Forwarded-Prefix`), atau menaikkan batas proxy untuk dump/import besar — timeout proxy **≤ 30 detik**, batas ukuran respons default **64 MiB**; dump/import besar lewat Volume/backup atau Terminal. | Celah CSRF/XSS, timeout, memori. |
| 18 | **DILARANG** mencetak environment worker/proses ke log atau UI (memuat rahasia `.env`); worker detached kini mewarisi environment pemanggil — `AGENT_TOKEN`/kredensial restic-S3 ikut, dan `/proc/<pid>/environ` anak memuatnya. | Kebocoran secret ke log/UI. |

---

## Peta Lapisan (jangan salah tempat)

| Lokasi | Tanggung jawab |
|---|---|
| `app/controller/` | Mediator HTTP: validasi input, panggil library, kembalikan respons. Tanpa logika bisnis & tanpa state. `DomainController` = **satu halaman `/domains`** untuk domain **app + parkir + SSL** (dua tab; baris app disaring `AppAccess`, pengelolaan/SSL parkir admin-only; `GET /ssl` ⇒ 302). |
| `app/middleware/` | `CsrfMiddleware` (semua POST/PUT/PATCH/DELETE) → `AuthMiddleware` (login + sinkronisasi session↔`auth.json`) → `StaticFile`. |
| `app/library/` | **Semua logika bisnis**: `Storage` (`JsonStore`, `AppStore`, `ParkedDomainStore`), `Auth` (`UserStore`, `AppAccess`, `AppAccessDenied`), `Support` (`ProcessRunner`, `SigchldGuard`, `LogChunkReader`), `Domain` (`ParkedDomainValidator`, `ParkedDomainContent`, `ParkedDomainManager` — domain parking `/domains`, SPECS §8j), `Docker` (`DockerClient`, `ComposeDiscovery` — deteksi project compose eksternal untuk impor, `AppPorts`, `AppContainers`, `ContainerLogs`, `ContainerStats`, `DockerComposeRunner`, `DockerExec`), `Deploy` (`DeployerInterface`, `LocalDeployer`, `ComposeSource`, `ComposeBinds`, `ContainerNames`, `ComposeImporter`/`ImportedApp` — impor project compose manual (tanpa orkestrasi compose), `SubdomainManager`, `DeployLog` — log deploy on-demand, `DeployCanceller` — hentikan worker + grup build), `Files` (`ContainerFiles`, `PathGuard`, `TextContent`, `ArchiveGuard`, `ListingParser`, `FilesInput`, `FileError`), `Adminer` (`AdminerHelper`, `AdminerProxy`, `LimitedTempSink`, `AdminerProxyError`, `AdminerProxyLimitExceeded` — helper + reverse proxy halaman `/database`), `Nginx` (`NginxConfigGenerator`, `ParkedNginxConfig` — vhost domain parkir termasuk 443), `SSL` (`SslIssuer`, `SslDomainRows` — bentuk baris tabel domain, `ParkedSslIssuer`, `ParkedSslEnabler` — SSL domain parkir), `Update`, `Db` (`DbContainerDetector`, `DbConnectionResolver`, `DbCredentialResolver`, `DbDump` — backup & profil koneksi), `Monitor`, `System`, `Template`, `Git`. |
| `cli/*.php` | Worker **detached** (`deploy.php`, `ssl.php`, `parked-ssl.php` — SSL domain parkir) + skrip helper self-update (`self-update.sh`, `update-report.php`). |
| `app/view/` + `public/` | View PHP native, CSS, JS. **Hanya merender** — tanpa logika bisnis. |
| `templates/<slug>/` | Galeri template create app (ikut versi repo, **bukan** data runtime): `template.yml` + `docker-compose.yml` + `files/` + `guide.md` (panduan Markdown **katalog-only**, tidak di-materialize ke app). |
| `database/`, `apps/`, `runtime/`, `nginx-status/` | **Data runtime** (gitignored) — lihat larangan #15/#16. |

**Satu sumber kebenaran** — jangan menduplikasi logika:
`AppAccess` (otorisasi) · `ProcessRunner`+`SigchldGuard` (eksekusi proses) · `AppPorts` (port & dedupe) · `AppContainers` (validasi nama container) · `JsonStore` (mutasi JSON) · `app_subdomain_of()` (resolusi subdomain efektif — jangan menebak dari `name`) · `SubdomainManager` (kebijakan subdomain) · `ParkedDomainValidator` (keunikan FQDN domain parking — dipakai dua arah dengan `SubdomainManager`/`AppController::assertDomainUnique()`) · `AppController::createAndDeploy()` (jalur create app) · `template_logo_map()` (peta logo `public/images/templates/` → slug, dipakai galeri create-app — tile + judul modal detail —, tab Template, & form deploy — jangan glob logo di view) · `DeployerFactory`/`DeployerInterface` (keputusan deploy) · `EnvManager`/`NetworkManager`/`ContainerNames` (file override compose).

---

## Aturan Gaya Koding

### Konvensi Penamaan

| Entitas | Gaya | Contoh |
|---------|------|--------|
| **Class** | `PascalCase` | `AppController`, `LocalDeployer`, `NginxConfigGenerator` |
| **Method & Function** | `camelCase` | `createAndDeploy()`, `orderComposeFiles()`, `findOwningApp()` |
| **Property & Variable** | `camelCase` | `$this->composeFiles`, `$appId`, `$hostPort` |
| **Field JSON / kolom DB** | `snake_case` | `owner_id`, `primary_port`, `container_prefix`, `compose_files`, `deploy_history` |
| **Namespace** | huruf kecil mengikuti path | `app\library\Deploy`, `app\library\Auth`, `app\library\Support` |
| **File** | `PascalCase` sesuai class | `AppController.php`, `ProcessRunner.php`; view: `app/view/<folder>/<nama>.php` |
| **Key env/config** | `UPPER_SNAKE` | `MONITOR_POLL_MS`, `HOST_PROC_PATH`, `TEMPLATES_PATH`, `UPDATE_BRANCH` |
| **Ability** | lowercase, satu kata | `view`, `logs`, `deploy`, `env`, `compose`, `terminal`, `database`, `sharing`, `delete` |
| **Route/URL & slug app** | lowercase, kebab-case | `/apps/{id}/versions`, `/api/monitor/overview`, `/healthz`, slug `[a-z0-9-]` |

### Format & Struktur Kode

- **Strict Types:** wajib deklarasikan tipe parameter dan return type; `declare(strict_types=1);` di semua file kelas PHP (`app/`, `tests/`) — termasuk controller.
- **Visibility:** semua properti class wajib `public`/`protected`/`private` eksplisit.
- **Import:** gunakan `use` statement; jangan FQCN inline kecuali pada atribut.
- **Response:** helper `json()`, `response()`, `view()`. **Argumen ke-2 `json()` adalah options JSON, bukan status** — status pakai `->withStatus(404)`. Jangan `echo`/`print`.
- **SSE (terminal/log):** respons **wajib** `Webman\Http\Response` + `Transfer-Encoding: chunked` (`new Chunk(...)` lalu `return`). Jangan tutup sesi terminal saat koneksi SSE drop.
- **Storage:** semua mutasi JSON runtime lewat `JsonStore::update()` (atomic + `flock` + backup `.bak`); jangan `file_put_contents` langsung ke `database/*.json`.
- **Bentuk library:** logika tanpa I/O → **statik murni** (mudah diuji); yang menyentuh filesystem → instance dengan `$path` di konstruktor agar bisa di-override saat test (`EnvManager`, `TemplateCatalog`).
- **Fail-fast sebelum efek samping:** validasi & cek otorisasi dulu (mis. `NginxConfigGenerator::ensureWritable()`, `ComposeBinds::ensure()`, `ContainerNames` hentikan bentrok nama) — baru tulis file/panggil Engine.
- **Urutan `compose_files` wajib:** base → `docker-compose.override.yml` → `.override.ports.yml` → `.override.names.yml` → `.override.networks.yml` → `.override.env.yml` (env paling akhir).

### View & JS

- Escape semua output: `e($nilai)`; setiap form memakai `<?= csrf_field() ?>`; request mutasi mengirim token CSRF.
- Helper tersedia: `e()`, `current_user()`, `csrf_field()`, `flash_set()`/`flash_pull()`, `is_admin()`, `app_can()`, `app_role()`, `app_role_label()`, `user_names()`, `app_subdomain()`, `app_subdomain_of()` (satu-satunya jalur resolusi subdomain efektif app), `app_subdomain_label()`, `app_subdomain_is_custom()`, `app_subdomain_valid()`.
- Tombol/penyembunyian UI = lapisan kedua, **bukan** pengaman — server tetap menolak.
- Polling/interval dijalankan di browser: dijeda saat `document.hidden`, dibersihkan saat `pagehide`/`beforeunload`.
- Jangan menambah framework/bundler/CDN baru tanpa persetujuan user.

### Gaya Komunikasi

- **Laporan default = seringkas mungkin**: langsung ke inti (apa yang berubah, file utama, hasil verifikasi, risiko). Tanpa narasi proses, tanpa mengulang isi diff, tanpa penjelasan teori.
- **Penjelasan detail hanya bila diminta** (mis. "jelaskan", "kenapa", "detail") atau saat tugasnya memang audit/analisis. Jangan menambah latar belakang, alternatif, atau tutorial tanpa permintaan.
- Tetap wajib ada meski ringkas: bukti perintah verifikasi, risiko/peringatan keamanan, dan langkah lanjutan yang perlu user lakukan.
- Saat gagal/blocker: 1–3 kalimat berisi sebab + tindakan berikutnya; stack/log lengkap hanya bila relevan atau diminta.

---

## Verifikasi (definisi "selesai")

### Tingkat Verifikasi

| Tingkat | Cakupan perubahan | Wajib dijalankan | Boleh dilewati |
|---|---|---|---|
| **V1 — Tampilan** | **hanya** `app/view/**` (markup/visual/class CSS), `public/css/**`, `public/js/**`, `public/images/**`; tanpa perubahan perilaku | `php -l` tiap file PHP yang berubah · `node --check` tiap JS yang berubah · smoke render view halaman terkait (`php /tmp/rames-*-view-smoke.php`) · test view terkait bila ada (`vendor/bin/phpunit --filter <NamaTest>`) | `composer test` penuh · uji compose tiri · reload worker |
| **V2 — Terarah** | satu kelas `app/library/**` atau satu action controller yang sudah punya test, tanpa mengubah kontrak bersama | `php -l` · `vendor/bin/phpunit --filter <NamaTest>` (+ smoke render view bila view ikut berubah) | uji compose tiri · reload worker |
| **V3 — Penuh** | `app/controller/**`, `app/library/**`, `app/model/**`, `app/functions.php`, `config/*.php`, `config/route.php`, `cli/*.php`, `templates/**`, otorisasi/ability, skema `apps.json`, atau perubahan lintas lapisan | `php -l` semua file yang berubah · `composer test` penuh (0 failure; warning/risky menggagalkan) · uji compose tiri + `docker compose … config --quiet` bila menyentuh compose/deploy · reload worker bila menyentuh kelas runtime | — |

### Aturan Memilih Tingkat

1. **Ragu = naik satu tingkat.** Satu file saja di luar cakupan V1 → minimal V2; menyentuh logika, otorisasi, data, atau kontrak → V3.
2. **DILARANG** menurunkan tingkat demi kecepatan. Larangan keras #1–#18 dan aturan keamanan tetap berlaku di semua tingkat.
3. **Perubahan tampilan yang ikut mengubah perilaku bukan V1**: gating tombol + cek server, form/CSRF, helper baru, bentuk data dari controller, endpoint/kontrak JS baru → V3.
4. Menyentuh `app/view/components/**`, layout bersama, atau `app/functions.php` → hampir selalu V3 (dipakai banyak halaman).
5. Permintaan user "verifikasi penuh"/"cek regresi" dan commit/PR yang mencampur beberapa tingkat memakai **tingkat tertinggi**.
6. Laporan **wajib** menyebut: tingkat yang dipakai, perintah yang dijalankan + hasil, dan perintah yang sengaja **tidak** dijalankan + alasannya.

### Perintah Rujukan

Perintah rujukan tiap tingkat di atas; detailnya tetap berlaku.

1. `php -l <file>` untuk setiap file PHP yang diubah (termasuk template view).
2. `composer test` — PHPUnit 10 dengan `failOnWarning`/`failOnRisky` = `true`; **warning menggagalkan build**, jangan dinormalkan. Wajib **penuh** pada V3; pada V1/V2 pakai `--filter` sesuai tingkat.
3. Smoke render view di luar HTTP (`php /tmp/rames-view-smoke.php`, `rames-nginx-view-smoke.php`, `rames-template-view-smoke.php`) — error variabel template tidak tertangkap PHPUnit. Skrip ini di `/tmp`, **jangan di-commit**.
4. Uji compose tiruan di direktori temp + project palsu; **selalu** akhiri `docker compose down -v`.
5. Validasi compose: `docker compose ... config --quiet` — **jangan** `config` tanpa `--quiet` (ia mencetak nilai secret/env ke terminal).
6. Bila perubahan menyentuh **kelas yang dipakai runtime dashboard** (`app/controller/**`, `app/library/**`, `app/model/**`, `config/*.php`), reload worker sebagai langkah verifikasi manual tambahan (setelah `php -l`/`composer test`): `docker exec rames-webman php start.php reload`.
7. Laporkan hasil perintah sebagai bukti; jangan mengklaim "selesai" tanpa keluaran perintah.

---

## Alur Kerja & Rujukan

1. **Baca dulu** `docs/SPECS.md`/`docs/ARCHITECTURE.md` bagian terkait (dan kode di sekitar perubahan) sebelum menulis kode.
2. Untuk hal mahal diubah (skema `apps.json`, ability baru, endpoint publik, urutan `compose_files`, penghapusan data), **tanya user** maksimal 3 pertanyaan paling krusial. Fitur kompleks → ikuti skill `grill-with-docs` (`.github/skills/grill-with-docs/SKILL.md`).
3. **Dokumentasi ikut berubah**: kelas/library baru wajib muncul di tabel modul `docs/ARCHITECTURE/04-03-layer-bisnis-library.md` §4.3; fitur/perilaku baru butuh sub-bagian alur §5.x dan kebutuhan di `docs/SPECS.md`; jebakan baru ditandai **GOTCHA/WAJIB/DILARANG** beserta gejala + sebab + penangkal.
4. **Verifikasi bukan oleh penulis perubahan**: untuk pekerjaan multi-lapisan, gunakan tim agent di `.github/agents/` — *Rames Master (Orkestrator)* memecah & mengoordinasikan, *Rames Build (Tim Implementasi)* menulis kode, *Rames Assure (Tim Verifikasi & Dokumentasi)* memverifikasi & merawat dokumen.
5. Prioritas bila terjadi konflik aturan: **keamanan & integritas data > konsistensi arsitektur > kenyamanan kode**.
