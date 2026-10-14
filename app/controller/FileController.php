<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
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
use Webman\Http\UploadFile;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Timer;

/**
 * File manager container — controller hanya mediator.
 *
 * Semua logika bisnis (validasi path, baca/tulis, transfer byte, ekstraksi
 * arsip) ada di `app\library\Files\*`. Otorisasi HANYA lewat
 * `AppAccess::require('files', ...)` (operator+); app milik user lain →
 * `AppAccessDenied` → 404 (bukan 403), sama seperti endpoint app lain.
 *
 * Kontrak envelope: sukses `{code:0,data:{...}}`; gagal `{code:<status>,msg}`.
 *
 * **Multi-server (F3b)**: app yang dilayani server `kind=agent` dijalankan
 * **di agent** (`AgentDeployer::file*()` → `/files/*` agent) — Docker Engine
 * host control plane tidak pernah disentuh untuk app remote (plan §3 butir 4),
 * dan agen TLS/token tidak pernah bocor ke UI. Bentuk respons UI **tidak
 * berubah** (JS lama tetap jalan): envelope agent diteruskan apa adanya,
 * termasuk kode domain (409 container berhenti, 413 terlalu besar, 415 biner).
 * Penyelidikan/unduhan byte memakai **streaming** (bukan buffer) dengan plafon
 * `AgentDeployer::MAX_TRANSFER_BYTES`.
 */
class FileController
{
    /**
     * Interval memompa aliran berkas **dari agent** (detik) — sama pola proxy
     * terminal: pembacaan non-blocking supaya worker Webman tetap melayani
     * request lain di antara tick.
     */
    private const PROXY_TICK = 0.05;

    // ==================================================================
    // GET (read-only)
    // ==================================================================

    public function list(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            $path = PathGuard::normalize((string) $request->input('path', '/'));

            return $this->agentData(
                $agent->fileList($app, $container, $path),
                static fn (array $data): array => self::capListing($data)
            );
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            $path = PathGuard::normalize((string) $request->input('path', '/'));

            return json(['code' => 0, 'data' => $files->list($container, $path)]);
        });
    }

    public function read(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            $path = PathGuard::normalize((string) $request->input('path', ''));

            return $this->agentData($agent->fileRead($app, $container, $path));
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            $path = PathGuard::normalize((string) $request->input('path', ''));

            return json(['code' => 0, 'data' => $files->read($container, $path)]);
        });
    }

    /**
     * Unduh berkas.
     *
     * Jalur server agent memakai **streaming passthrough** (byte agent → klien
     * apa adanya lewat `Timer`, tanpa menahan berkas di memori worker) dengan
     * plafon {@see self::transferLimit()}; jalur server lokal tetap
     * `response()->download()` seperti sebelumnya.
     */
    public function download(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            $path = PathGuard::normalize((string) $request->input('path', ''));

            $stream = $agent->fileDownload($app, $container, $path);
            $error = $stream['error'] ?? null;
            if (is_array($error)) {
                // Agent menolak (container berhenti 409, berkas hilang 404, terlalu
                // besar 413): envelope diteruskan apa adanya — tidak ada byte
                // berkas yang mengalir dan tidak ada unduhan kosong.
                return $this->agentData($error);
            }

            $name = basename($path);
            if ($name === '' || $name === '.' || $name === '..') {
                ($stream['close'])();
                throw new FileError('Nama berkas tidak valid.', 400);
            }

            $this->audit($app, $container, 'download ' . $path . ' di server agent');

            return $this->streamDownload($request, $stream, $name);
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            $path = PathGuard::normalize((string) $request->input('path', ''));

            $transfer = $files->downloadToTemp($container, $path);
            $this->audit($app, $container, 'download ' . $path);
            // Berkas temp dibaca Workerman SETELAH handler kembali → jadwalkan
            // pembersihan, jangan hapus sinkron.
            $files->scheduleCleanup($transfer['dir']);

            return response()->download($transfer['path'], $transfer['name']);
        });
    }

    // ==================================================================
    // POST (mutasi — CSRF otomatis oleh CsrfMiddleware)
    // ==================================================================

    public function write(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            $path = PathGuard::normalize((string) $request->input('path', ''));
            $text = FilesInput::text($request->input('text'), $request->input('content'));

            return $this->agentAck(
                $agent->fileWrite($app, $container, $path, $text),
                [$app, $container, 'write ' . $path . ' di server agent']
            );
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            $path = PathGuard::normalize((string) $request->input('path', ''));
            $text = FilesInput::text($request->input('text'), $request->input('content'));
            $files->write($container, $path, $text);
            $this->audit($app, $container, 'write ' . $path);

            return json(['code' => 0]);
        });
    }

    public function mkdir(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            // Kontrak UI sama seperti jalur lokal: `path` = direktori induk,
            // `name` = nama folder baru (FilesInput::mkdirTarget()).
            $target = FilesInput::mkdirTarget(
                (string) $request->input('path', '/'),
                $request->input('name')
            );

            return $this->agentAck(
                $agent->fileMkdir($app, $container, $target),
                [$app, $container, 'mkdir ' . $target . ' di server agent']
            );
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            // Kontrak UI: `path` = direktori induk, `name` = nama folder baru →
            // target = "<path>/<name>" (lihat FilesInput::mkdirTarget()).
            $target = FilesInput::mkdirTarget(
                (string) $request->input('path', '/'),
                $request->input('name')
            );
            $files->mkdir($container, $target);
            $this->audit($app, $container, 'mkdir ' . $target);

            return json(['code' => 0]);
        });
    }

    public function rename(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            $from = PathGuard::normalize((string) $request->input('path', ''));
            $to = FilesInput::renameTarget($from, $request->input('to'), $request->input('name'));

            return $this->agentAck(
                $agent->fileRename($app, $container, $from, $to),
                [$app, $container, 'rename ' . $from . ' -> ' . $to . ' di server agent']
            );
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            $from = PathGuard::normalize((string) $request->input('path', ''));
            $to = FilesInput::renameTarget($from, $request->input('to'), $request->input('name'));
            $files->rename($container, $from, $to);
            $this->audit($app, $container, 'rename ' . $from . ' -> ' . $to);

            return json(['code' => 0]);
        });
    }

    public function delete(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            $path = PathGuard::assertDeletable((string) $request->input('path', ''));

            return $this->agentAck(
                $agent->fileDelete($app, $container, $path),
                [$app, $container, 'delete ' . $path . ' di server agent']
            );
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            $path = PathGuard::assertDeletable((string) $request->input('path', ''));
            $files->delete($container, $path);
            $this->audit($app, $container, 'delete ' . $path);

            return json(['code' => 0]);
        });
    }

    /**
     * Pindahkan berkas/folder ke direktori lain.
     *
     * Kontrak field: `path` (sumber, absolut), `dest` (direktori tujuan, absolut),
     * `name` (opsional — nama entri di tujuan; default = nama sumber).
     * Kontrak respons: sukses `{code:0,data:{from,to}}`; error → `FileError`
     * dengan status aslinya (400 validasi, 404 sumber hilang, 409 bentrok nama).
     */
    public function move(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            // Validasi & normalisasi rencana pindah di control plane (sumber
            // aturan yang sama dengan jalur lokal: `FilesInput::moveTarget()`),
            // supaya penolakan (tujuan = diri sendiri, nama tak sah) terjadi
            // SEBELUM efek samping & tanpa HTTP.
            $plan = FilesInput::moveTarget(
                (string) $request->input('path', ''),
                (string) $request->input('dest', ''),
                $request->input('name') !== null ? (string) $request->input('name') : null
            );
            $envelope = $agent->fileMove(
                $app,
                $container,
                $plan['from'],
                $plan['dir'],
                (string) basename($plan['target'])
            );
            $moved = $envelope['data'] ?? [];
            $target = is_array($moved) ? (string) ($moved['to'] ?? $plan['target']) : $plan['target'];

            return $this->agentData(
                $envelope,
                null,
                [$app, $container, 'move ' . $plan['from'] . ' -> ' . $target . ' di server agent']
            );
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            $moved = $files->move(
                $container,
                (string) $request->input('path', ''),
                (string) $request->input('dest', ''),
                $request->input('name') !== null ? (string) $request->input('name') : null
            );
            $this->audit($app, $container, 'move ' . $moved['from'] . ' -> ' . $moved['to']);

            return json(['code' => 0, 'data' => $moved]);
        });
    }

    public function upload(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            $dir = PathGuard::normalize((string) $request->input('path', '/'));

            $uploaded = [];
            foreach ($this->uploadedFiles($request) as $entry) {
                // Entri `files[]` kosong dikirim browser dengan nama kosong → abaikan.
                if ($entry['name'] === '') {
                    continue;
                }
                $name = PathGuard::assertName($entry['name']);
                $result = $agent->fileUpload($app, $container, $dir, $entry['tmp'], $name);
                $code = (int) ($result['code'] ?? 0);
                if ($code !== 0) {
                    // Kegagalan domain satu berkas → respons sama bentuknya dengan
                    // jalur lokal (berkas sebelumnya sudah tersalin, seperti lokal).
                    return $this->agentFailureEnvelope($result);
                }
                $uploaded[] = $result['data'] ?? ['name' => $name];
            }
            if ($uploaded === []) {
                throw new FileError('Tidak ada berkas yang diunggah.', 400);
            }

            $this->audit($app, $container, 'upload ' . count($uploaded) . ' berkas ke ' . $dir . ' di server agent');

            return json(['code' => 0, 'data' => ['uploaded' => $uploaded]]);
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            $dir = PathGuard::normalize((string) $request->input('path', '/'));

            $uploaded = [];
            foreach ($this->uploadedFiles($request) as $entry) {
                // Entri `files[]` kosong dikirim browser dengan nama kosong → abaikan.
                if ($entry['name'] === '') {
                    continue;
                }
                $uploaded[] = $files->uploadOne($container, $dir, $entry['tmp'], $entry['name']);
            }
            if ($uploaded === []) {
                throw new FileError('Tidak ada berkas yang diunggah.', 400);
            }

            $this->audit($app, $container, 'upload ' . count($uploaded) . ' berkas ke ' . $dir);

            return json(['code' => 0, 'data' => ['uploaded' => $uploaded]]);
        });
    }

    public function extract(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $proxied = $this->withAgent($app, function (AgentDeployer $agent) use ($request, $app) {
            $container = $this->agentContainer($request, $app);
            $dir = PathGuard::normalize((string) $request->input('path', ''));
            $name = PathGuard::assertName((string) $request->input('name', ''));
            $dest = trim((string) $request->input('dest', ''));

            return $this->agentData(
                $agent->fileExtract($app, $container, $dir, $name, $dest !== '' ? $dest : null),
                null,
                [$app, $container, 'extract ' . $dir . '/' . $name . ' di server agent']
            );
        });
        if ($proxied !== null) {
            return $proxied;
        }

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = $this->containerFiles();
            $files->assertRunning($container);
            $dir = PathGuard::normalize((string) $request->input('path', ''));
            $name = PathGuard::assertName((string) $request->input('name', ''));
            $dest = trim((string) $request->input('dest', ''));

            $count = $files->extract($container, $dir, $name, $dest !== '' ? $dest : null);
            $this->audit($app, $container, 'extract ' . $dir . '/' . $name);

            return json(['code' => 0, 'data' => ['extracted' => $count]]);
        });
    }

    // ==================================================================
    // Jalur server agent (F3b) — eksekusi DI AGENT, control plane mem-proxy
    // ==================================================================

    /**
     * Jalankan jalur agent untuk app, atau `null` bila app dilayani server lokal
     * (satu pintu: `AgentDeployer::forApp()` — resolusi `server_id` app).
     *
     * `null` **selalu** berarti "lanjutkan jalur lokal": server `kind=agent`
     * yang konfigurasinya cacat melempar (`AgentError`) dan diterjemahkan di sini
     * menjadi respons galat — **tidak ada** jalur mundur ke server lokal
     * (plan §3 butir 4).
     *
     * @param array<string,mixed>     $app
     * @param callable(AgentDeployer):Response $callback
     */
    private function withAgent(array $app, callable $callback): ?Response
    {
        try {
            $deployer = $this->agentDeployer($app);
            if ($deployer === null) {
                return null;
            }

            return $callback($deployer);
        } catch (AgentError $e) {
            return $this->agentFailure($e);
        } catch (FileError $e) {
            return json(['code' => $e->status, 'msg' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            try {
                Log::error('FileController: operasi berkas di server agent gagal', ['exception' => $e]);
            } catch (Throwable) {
                // kegagalan logging tidak boleh menutupi respons.
            }

            return json(['code' => 500, 'msg' => 'Gagal memproses operasi berkas di server agent.']);
        }
    }

    /**
     * Nama container milik app untuk jalur agent — validasi control plane
     * **tanpa I/O** (bentuk nama + keanggotaan data proyeksi); daftar container
     * hidup tetap milik agent (`AppContainers::resolve()` di sana).
     *
     * @param array<string,mixed> $app
     * @throws FileError 400 container tidak dikenali (pesan sama dengan jalur lokal)
     */
    private function agentContainer(Request $request, array $app): string
    {
        $container = AgentDeployer::containerRef($app, trim((string) $request->input('container', '')));
        if ($container === null) {
            throw new FileError('Container tidak dikenali atau bukan milik app ini.', 400);
        }

        return $container;
    }

    /**
     * Envelope berkas agent → respons UI. `code != 0` diteruskan **apa adanya**
     * (`{code:<status>,msg}`) supaya kode domain agent (409/413/415/404) sampai
     * ke UI seperti jalur lokal; sukses `{code:0,data:…}` dengan penyaringan
     * bentuk opsional (`$shape`).
     *
     * `$shape` menyaring bentuk data (mis. batas listing) dan `$audit`
     * (`[app, container, action]`) dicatat **hanya** saat operasi sukses —
     * identitas pelaku hanya ada di control plane.
     *
     * @param array<string,mixed>          $envelope
     * @param callable(array):array|null   $shape
     * @param array{0:array,1:string,2:string}|null $audit
     */
    private function agentData(array $envelope, ?callable $shape = null, ?array $audit = null)
    {
        $code = (int) ($envelope['code'] ?? 0);
        if ($code !== 0) {
            return $this->agentFailureEnvelope($envelope);
        }

        $data = $envelope['data'] ?? [];
        if (is_array($data) && $shape !== null) {
            $data = $shape($data);
        }
        $this->agentAudit($audit);

        return json(['code' => 0, 'data' => $data]);
    }

    /**
     * Envelope berkas agent tanpa data (`write`/`mkdir`/`rename`/`delete` →
     * `{code:0}` seperti jalur lokal).
     *
     * @param array<string,mixed> $envelope
     * @param array{0:array,1:string,2:string}|null $audit
     */
    private function agentAck(array $envelope, ?array $audit = null)
    {
        $code = (int) ($envelope['code'] ?? 0);
        if ($code !== 0) {
            return $this->agentFailureEnvelope($envelope);
        }
        $this->agentAudit($audit);

        return json(['code' => 0]);
    }

    /**
     * Catat audit trail operasi berkas jalur agent (hanya saat sukses, sama
     * dengan jalur lokal).
     *
     * @param array{0:array,1:string,2:string}|null $audit
     */
    private function agentAudit(?array $audit): void
    {
        if ($audit === null) {
            return;
        }

        $this->audit($audit[0], $audit[1], $audit[2]);
    }

    /**
     * Galat **domain** yang dilaporkan agent (`{code:<status>,msg}`) → respons UI
     * dengan kode yang sama. Pesannya disanitasi supaya token/Authorization tidak
     * pernah tampil walau agent memantulkannya.
     *
     * @param array<string,mixed> $envelope
     */
    private function agentFailureEnvelope(array $envelope)
    {
        $code = (int) ($envelope['code'] ?? 0);
        if ($code <= 0) {
            $code = 502;
        }
        $message = trim((string) ($envelope['msg'] ?? $envelope['error'] ?? ''));
        if ($message === '') {
            $message = 'Operasi berkas ditolak server agent.';
        }

        return json(['code' => $code, 'msg' => AgentError::sanitize($message)]);
    }

    /**
     * Respons galat jalur agent (transport/konfigurasi server) — kode mengikuti
     * `AgentDeployer::failureCode()` (404 tetap 404, sisanya 502) dan pesan
     * disanitasi.
     */
    private function agentFailure(Throwable $e)
    {
        $detail = AgentError::sanitize($e->getMessage());

        return json([
            'code' => AgentDeployer::failureCode($e),
            'msg' => $detail === '' ? 'Operasi berkas di server agent gagal.' : $detail,
        ]);
    }

    /**
     * Alirkan berkas dari agent ke klien sebagai potongan chunked (tanpa buffer
     * penuh di memori worker).
     *
     * Plafon {@see self::transferLimit()} ditegakkan **juga** di sini sebagai
     * jaring pengaman kedua (agent sudah menolak sebelum streaming); melewatinya
     * berarti respons dipotong dan koneksi ditutup, bukan mengalirkan data tanpa
     * batas.
     *
     * @param array{read:callable():?string,close:callable():void} $stream
     */
    private function streamDownload(Request $request, array $stream, string $name)
    {
        $connection = $request->connection;
        if (!$connection instanceof TcpConnection) {
            ($stream['close'])();

            return json(['code' => 500, 'msg' => 'Koneksi streaming tidak tersedia.']);
        }

        // Header respons streaming (Transfer-Encoding chunked) — sama alasan
        // dengan proxy terminal: kelas `Webman\Http\Response` wajib agar Webman
        // memakai jalur streaming yang tidak menutup koneksi lebih awal.
        $response = new Response(200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . self::safeFilename($name) . '"',
            'Cache-Control' => 'no-store',
            'Transfer-Encoding' => 'chunked',
            'X-Accel-Buffering' => 'no',
        ], '');

        $timerId = null;
        $read = $stream['read'];
        $closeUpstream = $stream['close'];
        $limit = $this->transferLimit();
        $sent = 0;
        $cleanup = function () use (&$timerId, $closeUpstream): void {
            if ($timerId !== null) {
                Timer::del($timerId);
                $timerId = null;
            }
            // Idempoten: boleh dipanggil dari EOF, drop klien, atau galat.
            $closeUpstream();
        };

        $timerId = Timer::add(self::PROXY_TICK, function () use ($connection, $read, $cleanup, $limit, &$sent): void {
            if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
                $cleanup();

                return;
            }
            try {
                $chunk = $read();
            } catch (Throwable) {
                $cleanup();
                $connection->close();

                return;
            }
            if ($chunk === null) {
                $connection->send(new Chunk(''));
                $cleanup();
                $connection->close();

                return;
            }
            if ($chunk === '') {
                return;
            }
            $sent += strlen($chunk);
            if ($sent > $limit) {
                // Di luar kontrak agent (ia sudah menolak sebelum streaming):
                // potong agar satu respons tidak mengalirkan data tanpa batas.
                $connection->send(new Chunk(''));
                $cleanup();
                $connection->close();

                return;
            }
            $connection->send(new Chunk($chunk));
        });

        $connection->onClose = $cleanup;

        return $response;
    }

    /** Nama berkas yang aman untuk header `Content-Disposition`. */
    private static function safeFilename(string $name): string
    {
        return str_replace(['"', '\\', "\r", "\n", "\0"], '', $name);
    }

    /**
     * Batas transfer unduhan jalur agent (byte) — seam uji supaya plafon bisa
     * diuji tanpa berkas 64 MiB. Nilainya milik kontrak `AgentDeployer`.
     */
    protected function transferLimit(): int
    {
        return AgentDeployer::MAX_TRANSFER_BYTES;
    }

    /**
     * Batas jumlah entri listing yang diteruskan ke UI (jaring pengaman kedua;
     * pemotongan utama ada di `ContainerFiles::list()` sisi agent).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function capListing(array $data): array
    {
        $entries = $data['entries'] ?? null;
        if (is_array($entries) && count($entries) > ContainerFiles::MAX_LIST_ENTRIES) {
            $data['entries'] = array_slice($entries, 0, ContainerFiles::MAX_LIST_ENTRIES);
            $data['truncated'] = true;
        }

        return $data;
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Ambil app + pastikan user berhak memakai file manager-nya.
     *
     * `protected` (bukan private) semata agar unit test bisa men-bypass
     * `AppStore`/`AppAccess` (data runtime nyata) — produksi tidak berubah.
     *
     * @throws AppAccessDenied dirender 404 oleh webman (JSON untuk /api/*).
     */
    protected function requireApp(string $id): array
    {
        $app = (new AppStore())->find($id);
        if ($app === null) {
            throw new AppAccessDenied('files', $id);
        }
        AppAccess::require('files', $app, current_user());

        return $app;
    }

    /**
     * Deployer **agent** untuk app, atau `null` bila app dilayani server lokal
     * (seam uji: subclass menyuntikkan deployer dengan klien agent tiruan supaya
     * jalur proxy teruji tanpa jaringan & tanpa Docker).
     *
     * @param array<string,mixed> $app
     * @throws Throwable server `kind=agent` yang konfigurasinya cacat — fail-fast,
     *                   TIDAK ada jalur mundur ke server lokal
     */
    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return AgentDeployer::forApp($app);
    }

    /**
     * Operasi berkas container server lokal (seam uji) — jalur lokal memanggil
     * ini supaya uji dapat membuktikan daemon lokal **tidak** tersentuh untuk app
     * server agent.
     */
    protected function containerFiles(): ContainerFiles
    {
        return new ContainerFiles();
    }

    /**
     * Validasi nama container milik app (satu jalur dengan TerminalController —
     * nama dari request tidak pernah dipercaya).
     */
    private function resolveContainer(Request $request, array $app): string
    {
        $container = AppContainers::resolve($app, trim((string) $request->input('container', '')));
        if ($container === null) {
            throw new FileError('Container tidak dikenali atau bukan milik app ini.', 400);
        }

        return $container;
    }

    /**
     * Normalisasi file unggahan Webman → daftar {name, tmp}.
     *
     * @return array<int,array{name:string,tmp:string}>
     */
    private function uploadedFiles(Request $request): array
    {
        $raw = $request->file('files');
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach (is_array($raw) ? array_values($raw) : [$raw] as $file) {
            if (!$file instanceof UploadFile) {
                continue;
            }
            $out[] = [
                'name' => trim((string) $file->getUploadName()),
                'tmp' => $file->isValid() ? (string) $file->getPathname() : '',
            ];
        }

        return $out;
    }

    /**
     * Petakan exception domain → envelope JSON. AppAccessDenied sengaja TIDAK
     * ditangkap di sini (callback dijalankan setelah requireApp).
     */
    private function run(callable $callback)
    {
        try {
            return $callback();
        } catch (FileError $e) {
            return json(['code' => $e->status, 'msg' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Exception tak terduga: JANGAN bocorkan detail internal (filesystem,
            // Guzzle, dsb.) ke klien — catat lengkap ke log, balas pesan generik.
            try {
                Log::error('FileController: operasi file gagal', ['exception' => $e]);
            } catch (Throwable $logFailure) {
                // kegagalan logging tidak boleh menutupi respons.
            }

            return json(['code' => 500, 'msg' => 'Gagal memproses operasi berkas.']);
        }
    }

    /**
     * Audit trail operasi berkas (satu jalur untuk lokal & server agent).
     *
     * `protected` (bukan private) semata agar unit test bisa men-bypass
     * penulisan ke `runtime/logs` nyata — pola yang sama dengan
     * `TerminalController::audit()`; produksi tidak berubah.
     */
    protected function audit(array $app, string $container, string $action): void
    {
        $user = (string) (current_user()['username'] ?? '?');
        $dir = runtime_path() . '/logs/files';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $line = json_encode([
            'ts' => date('c'),
            'user' => $user,
            'app' => (string) ($app['name'] ?? ''),
            'app_id' => (string) ($app['id'] ?? ''),
            'container' => $container,
            'action' => $action,
        ], JSON_UNESCAPED_UNICODE);
        @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
