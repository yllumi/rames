<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentAppProjection;
use app\library\Deploy\AgentDeployer;
use app\library\Docker\AppContainers;
use app\library\Files\ContainerFiles;
use app\library\Files\FileError;
use app\library\Files\FilesInput;
use app\library\Files\PathGuard;
use app\library\Storage\AppStore;
use InvalidArgumentException;
use support\Log;
use support\Request;
use Throwable;
use Webman\Http\Response;

/**
 * File manager container **sisi agent** (F3b multi-server, plan §4.1/§5):
 *
 * ```
 * GET  /files/list?app_id=&container=&path=            → {code:0,data:{path,parent,truncated,entries}}
 * GET  /files/read?app_id=&container=&path=            → {code:0,data:{path,size,text,truncated}}
 * GET  /files/download?app_id=&container=&path=        → byte berkas (streaming, ≤ AgentDeployer::MAX_TRANSFER_BYTES;
 *                                                        galat → HTTP 4xx/5xx + `{code,msg}`)
 * POST /files/write    {app_id,container,path,text}    → {code:0}
 * POST /files/mkdir    {app_id,container,target}       → {code:0}
 * POST /files/rename   {app_id,container,from,to}      → {code:0}
 * POST /files/delete   {app_id,container,path}         → {code:0}
 * POST /files/move     {app_id,container,path,dest,name?} → {code:0,data:{from,to}}
 * POST /files/upload   {app_id,container,dir,name} + body biner → {code:0,data:{name,size}}
 * POST /files/extract  {app_id,container,dir,name,dest?} → {code:0,data:{extracted}}
 * ```
 *
 * Dipanggil control plane (`FileController` ⇒ `AgentDeployer`), bukan UI: route
 * ini hanya terdaftar di mode `agent` dan otomatis tertutup
 * `AgentAuthMiddleware` (token + timestamp + nonce + signature).
 *
 * **Logika path/arsip TIDAK disalin** ke sini: seluruh operasi memakai library
 * yang sama dengan jalur lokal (`ContainerFiles`, `PathGuard`, `FilesInput`,
 * `TextContent`, `ArchiveGuard`, `ListingParser`). Yang berbeda hanya tiga hal:
 *
 *  1. **sumber app** = proyeksi agent (`AppStore` agent), tanpa `AppAccess`
 *     (otorisasi ability `files` sudah dilakukan control plane sebelum
 *     memanggil; identitas user tidak ada di sini);
 *  2. **bentuk galat domain** = envelope dashboard (`{code:<status>,msg}`) supaya
 *     control plane meneruskannya **apa adanya** ke UI — container berhenti
 *     tetap 409, terlalu besar 413, biner 415, tidak ada 404 (sama seperti jalur
 *     lokal). Envelope itu memakai HTTP 200 di semua endpoint **kecuali
 *     unduhan**, yang memakai status HTTP nyata (klien harus bisa membedakan
 *     "ini JSON galat" dari "ini isi berkas"). Kegagalan mesin (app tidak ada)
 *     memakai HTTP 404 + `{code:1,error,reason}` agar `AgentClient` memetakannya
 *     menjadi `AgentError('not_found')`;
 *  3. **batas transfer** (`AgentDeployer::MAX_TRANSFER_BYTES`) ditegakkan di
 *     sini sebelum byte dialirkan/diterima, dan berkas temp unggahan agent
 *     selalu dibersihkan.
 *
 * Nama container dari request **selalu** divalidasi milik app ini
 * (`AppContainers::resolve()` terhadap Engine **server ini**) dan operasi hanya
 * dijalankan untuk container **berjalan** (`ContainerFiles::assertRunning()`
 * ⇒ 409) — tidak pernah diteruskan mentah ke Engine.
 *
 * Stateless: properti hanya seam uji (nullable); tanpa `session()`/`request()`
 * di konstruktor, tanpa state lintas-request.
 */
class AgentFilesController
{
    /**
     * Plafon transfer berkas jalur agent (byte). **Bukan** daftar kedua: nilainya
     * milik kontrak `AgentDeployer` (sisi yang menyusun permintaan), dan
     * controller ini (plus jalur proxy unduhan di control plane) yang menegakkannya.
     */
    public const MAX_TRANSFER_BYTES = AgentDeployer::MAX_TRANSFER_BYTES;

    /** Bentuk app id yang boleh dipakai (pola sama registry/app store). */
    private const APP_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    private ?AppStore $apps;

    private ?ContainerFiles $files;

    /**
     * @param AppStore|null       $apps  proyeksi app agent (seam uji; null ⇒ bawaan)
     * @param ContainerFiles|null $files operasi berkas container (seam uji; null ⇒ bawaan)
     */
    public function __construct(?AppStore $apps = null, ?ContainerFiles $files = null)
    {
        $this->apps = $apps;
        $this->files = $files;
    }

    /** `GET /files/list` */
    public function list(Request $request): Response
    {
        $target = $this->target($request);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handle(function () use ($request, $target): Response {
            $path = PathGuard::normalize((string) $request->input('path', '/'));
            $files = $this->runningFiles($target['container']);

            return json(['code' => 0, 'data' => $files->list($target['container'], $path)]);
        });
    }

    /** `GET /files/read` */
    public function read(Request $request): Response
    {
        $target = $this->target($request);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handle(function () use ($request, $target): Response {
            $path = PathGuard::normalize((string) $request->input('path', ''));
            $files = $this->runningFiles($target['container']);

            return json(['code' => 0, 'data' => $files->read($target['container'], $path)]);
        });
    }

    /**
     * `GET /files/download` — byte berkas apa adanya (`response()->download()`),
     * dengan plafon ukuran diperiksa **sebelum** respons dikembalikan.
     *
     * Berkas disalin dulu ke direktori temp agent (library yang sama dengan jalur
     * lokal), lalu pembersihannya dijadwalkan **setelah** respons selesai dikirim
     * (`ContainerFiles::scheduleCleanup()`) — Workerman membaca berkas itu setelah
     * handler kembali, jadi menghapusnya sinkron akan memotong unduhan.
     *
     * Berbeda dari endpoint berkas lain, galat domain di sini memakai **status
     * HTTP nyata** (`409`/`404`/`413`/`400`) dengan body envelope `{code,msg}`:
     * pemanggil (control plane) harus bisa membedakan "respons ini JSON galat"
     * dari "respons ini isi berkas" lewat status — bukan lewat menebak isi body.
     * Control plane meneruskan `code`-nya ke UI sehingga hasilnya sama dengan
     * jalur lokal, dan tidak pernah ada byte galat yang mengalir sebagai berkas.
     */
    public function download(Request $request): Response
    {
        $target = $this->target($request);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handleDownload(function () use ($request, $target): Response {
            $path = PathGuard::normalize((string) $request->input('path', ''));
            $files = $this->runningFiles($target['container']);

            $transfer = $files->downloadToTemp($target['container'], $path);
            if ((int) @filesize($transfer['path']) > $this->transferLimit()) {
                // Berkas terlalu besar: jangan tinggalkan temp menumpuk & jangan
                // alirkan byte apa pun (pesan jelas, bukan unduhan terpotong).
                $files->cleanup($transfer['dir']);
                throw new FileError(
                    'Berkas melebihi batas ' . self::limitLabel($this->transferLimit()) . ' untuk diunduh.',
                    413
                );
            }
            $files->scheduleCleanup($transfer['dir']);

            return response()->download($transfer['path'], $transfer['name']);
        });
    }

    /** `POST /files/write` */
    public function write(Request $request): Response
    {
        $target = $this->target($request);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handle(function () use ($request, $target): Response {
            $path = PathGuard::normalize((string) $request->input('path', ''));
            $text = FilesInput::text($request->input('text'), $request->input('content'));
            $files = $this->runningFiles($target['container']);
            $files->write($target['container'], $path, $text);

            return json(['code' => 0]);
        });
    }

    /** `POST /files/mkdir` — `target` sudah berupa path absolut hasil control plane. */
    public function mkdir(Request $request): Response
    {
        $target = $this->target($request);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handle(function () use ($request, $target): Response {
            $path = PathGuard::normalize((string) $request->input('target', ''));
            $files = $this->runningFiles($target['container']);
            $files->mkdir($target['container'], $path);

            return json(['code' => 0]);
        });
    }

    /** `POST /files/rename` */
    public function rename(Request $request): Response
    {
        $target = $this->target($request);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handle(function () use ($request, $target): Response {
            $from = PathGuard::normalize((string) $request->input('from', ''));
            $to = PathGuard::normalize((string) $request->input('to', ''));
            $files = $this->runningFiles($target['container']);
            $files->rename($target['container'], $from, $to);

            return json(['code' => 0]);
        });
    }

    /** `POST /files/delete` */
    public function delete(Request $request): Response
    {
        $target = $this->target($request);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handle(function () use ($request, $target): Response {
            $path = PathGuard::assertDeletable((string) $request->input('path', ''));
            $files = $this->runningFiles($target['container']);
            $files->delete($target['container'], $path);

            return json(['code' => 0]);
        });
    }

    /** `POST /files/move` */
    public function move(Request $request): Response
    {
        $target = $this->target($request);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handle(function () use ($request, $target): Response {
            $name = $request->input('name');
            $files = $this->runningFiles($target['container']);
            $moved = $files->move(
                $target['container'],
                (string) $request->input('path', ''),
                (string) $request->input('dest', ''),
                $name !== null && trim((string) $name) !== '' ? (string) $name : null
            );

            return json(['code' => 0, 'data' => $moved]);
        });
    }

    /** `POST /files/extract` */
    public function extract(Request $request): Response
    {
        $target = $this->target($request);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handle(function () use ($request, $target): Response {
            $dir = PathGuard::normalize((string) $request->input('dir', ''));
            $name = PathGuard::assertName((string) $request->input('name', ''));
            $dest = trim((string) $request->input('dest', ''));
            $files = $this->runningFiles($target['container']);
            $count = $files->extract($target['container'], $dir, $name, $dest !== '' ? $dest : null);

            return json(['code' => 0, 'data' => ['extracted' => $count]]);
        });
    }

    /**
     * `POST /files/upload` — satu berkas per permintaan; isinya adalah **body
     * mentah** permintaan (control plane mengalirkannya dari disk, tidak
     * di-buffer di memori), field kecil (`app_id`/`container`/`dir`/`name`) di
     * query string.
     */
    public function upload(Request $request): Response
    {
        // Query-only: body permintaan ini adalah byte berkas (application/
        // octet-stream) — jangan biarkan isi berkas ikut terbaca sebagai field
        // (`parse_str` Workerman memproses body non-JSON).
        $target = $this->target($request, true);
        if ($target === null) {
            return $this->notFound();
        }

        return $this->handle(function () use ($request, $target): Response {
            $dir = PathGuard::normalize((string) $request->get('dir', '/'));
            $name = PathGuard::assertName(trim((string) $request->get('name', '')));
            $body = $request->rawBody();
            if (strlen($body) > $this->transferLimit()) {
                throw new FileError(
                    'Berkas unggahan melebihi batas ' . self::limitLabel($this->transferLimit()) . '.',
                    413
                );
            }

            $files = $this->runningFiles($target['container']);
            $tmp = $this->writeTemp($body);
            try {
                $result = $files->uploadOne($target['container'], $dir, $tmp, $name);
            } finally {
                @unlink($tmp);
            }

            return json(['code' => 0, 'data' => $result]);
        });
    }

    /**
     * Plafon transfer berkas (byte) — seam uji supaya batas bisa diuji tanpa
     * menulis berkas 64 MiB.
     */
    protected function transferLimit(): int
    {
        return self::MAX_TRANSFER_BYTES;
    }

    /** Direktori temp unggahan di server agent (seam uji; dibersihkan per berkas). */
    protected function uploadDir(): string
    {
        return runtime_path() . '/files-upload';
    }

    /**
     * App + container dari request; `null` ⇒ app tidak ada di proyeksi agent
     * atau container tidak dikenali (keduanya 404 — bukan 403, sama invariannya
     * dengan dashboard).
     *
     * `$queryOnly` dipakai jalur unggah (body = byte berkas mentah): hanya query
     * string yang boleh menentukan app/container, sehingga isi berkas tidak bisa
     * menyamar sebagai field (`parse_str` Workerman membaca body non-JSON).
     *
     * @return array{app:array<string,mixed>,container:string}|null
     */
    private function target(Request $request, bool $queryOnly = false): ?array
    {
        $appId = $this->appId($request, $queryOnly);
        if ($appId === '') {
            return null;
        }
        try {
            $app = $this->appStore()->find($appId);
        } catch (Throwable) {
            // Basis data agent tidak terbaca: API mesin tetap menjawab JSON.
            return null;
        }
        if ($app === null) {
            return null;
        }

        $raw = $queryOnly ? $request->get('container', '') : $request->input('container', '');
        $requested = is_scalar($raw) ? trim((string) $raw) : '';
        $container = $requested === ''
            ? AppContainers::defaultContainer($app)
            : AppContainers::resolve($app, $requested);
        if ($container === null || $container === '') {
            return null;
        }

        return ['app' => $app, 'container' => $container];
    }

    /**
     * Operasi berkas yang sudah dipastikan container-nya berjalan (keputusan
     * user: operasi hanya untuk container berjalan ⇒ 409).
     *
     * @throws FileError 409
     */
    private function runningFiles(string $container): ContainerFiles
    {
        $files = $this->files();
        $files->assertRunning($container);

        return $files;
    }

    /**
     * Simpan body unggahan ke berkas temp server agent (dibersihkan pemanggil).
     *
     * @throws FileError
     */
    private function writeTemp(string $body): string
    {
        $dir = $this->uploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new FileError('Gagal menyiapkan direktori unggahan di server agent.', 500);
        }
        $path = $dir . '/' . bin2hex(random_bytes(8));
        if (@file_put_contents($path, $body) === false) {
            throw new FileError('Gagal menyimpan berkas unggahan sementara.', 500);
        }

        return $path;
    }

    /**
     * Jalankan operasi berkas & petakan galat domain ke envelope dashboard
     * (HTTP 200 + `code` status) — **bukan** status HTTP, supaya control plane
     * meneruskan kode yang sama dengan jalur lokal.
     */
    private function handle(callable $callback): Response
    {
        try {
            return $callback();
        } catch (FileError $e) {
            return $this->denied($e->getMessage(), $e->status);
        } catch (InvalidArgumentException $e) {
            return $this->denied($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->denied($this->failureMessage($e), 500);
        }
    }

    /**
     * Jalur **unduhan**: galat domain memakai status HTTP nyata + envelope
     * `{code,msg}`. Control plane (`FileController::download()` lewat
     * `AgentClient::openStreamEnvelope()`) membedakan aliran berkas dari galat
     * lewat status HTTP, lalu meneruskan `code`-nya ke UI — jadi pengguna melihat
     * 409/413/404 yang sama seperti jalur lokal dan tidak menerima berkas kosong.
     */
    private function handleDownload(callable $callback): Response
    {
        try {
            return $callback();
        } catch (FileError $e) {
            return $this->deniedWithStatus($e->getMessage(), $e->status);
        } catch (InvalidArgumentException $e) {
            return $this->deniedWithStatus($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->deniedWithStatus($this->failureMessage($e), 500);
        }
    }

    /** Pesan kegagalan tak terduga (detail internal hanya masuk log agent). */
    private function failureMessage(Throwable $e): string
    {
        // Exception tak terduga: jangan bocorkan detail internal (filesystem,
        // docker, dsb.) ke control plane — catat di agent, balas generik.
        try {
            Log::error('AgentFilesController: operasi berkas gagal', ['exception' => $e]);
        } catch (Throwable) {
            // kegagalan logging tidak boleh menutupi respons.
        }

        return 'Gagal memproses operasi berkas di server agent.';
    }

    /** Envelope galat domain (HTTP 200 — kontrak UI jalur lokal). */
    private function denied(string $message, int $status): Response
    {
        return json(['code' => $status, 'msg' => $message]);
    }

    /** Envelope galat domain dengan status HTTP nyata (jalur unduhan). */
    private function deniedWithStatus(string $message, int $status): Response
    {
        $http = $status >= 400 && $status <= 599 ? $status : 500;

        return json(['code' => $status, 'msg' => $message])->withStatus($http);
    }

    /**
     * App/container tidak ada **di server ini**: HTTP 404 JSON eksplisit supaya
     * `AgentClient` memetakannya menjadi `AgentError('not_found')` (dan UI tetap
     * 404, bukan 403).
     */
    private function notFound(): Response
    {
        return json([
            'code' => 1,
            'error' => 'App/container tidak ditemukan di proyeksi server agent.',
            'reason' => 'not_found',
        ])->withStatus(404);
    }

    /** Id app dari body (POST JSON/form) atau query (GET) — `''` bila tidak ada. */
    private function appId(Request $request, bool $queryOnly = false): string
    {
        $value = $queryOnly ? $request->get('app_id', '') : $request->input('app_id');
        if (!is_scalar($value) || trim((string) $value) === '') {
            $value = $request->get('app_id', '');
        }
        $id = is_scalar($value) ? trim((string) $value) : '';

        return preg_match(self::APP_ID_PATTERN, $id) === 1 ? $id : '';
    }

    /** Proyeksi app agent (path mengikuti seam yang disuntikkan). */
    private function appStore(): AppStore
    {
        return $this->apps ?? new AppStore((new AgentAppProjection())->path());
    }

    /** Operasi berkas container (Engine server ini) — seam uji. */
    private function files(): ContainerFiles
    {
        return $this->files ?? new ContainerFiles();
    }

    /** Label plafon dalam bentuk yang bisa dibaca operator (mis. "64 MiB"). */
    private static function limitLabel(int $bytes): string
    {
        return $bytes > 0 && $bytes % 1048576 === 0
            ? ($bytes / 1048576) . ' MiB'
            : $bytes . ' byte';
    }
}
