<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
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
use Webman\Http\UploadFile;

/**
 * File manager container — controller hanya mediator.
 *
 * Semua logika bisnis (validasi path, baca/tulis, transfer byte, ekstraksi
 * arsip) ada di `app\library\Files\*`. Otorisasi HANYA lewat
 * `AppAccess::require('files', ...)` (operator+); app milik user lain →
 * `AppAccessDenied` → 404 (bukan 403), sama seperti endpoint app lain.
 *
 * Kontrak envelope: sukses `{code:0,data:{...}}`; gagal `{code:<status>,msg}`.
 */
class FileController
{
    // ==================================================================
    // GET (read-only)
    // ==================================================================

    public function list(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = new ContainerFiles();
            $files->assertRunning($container);
            $path = PathGuard::normalize((string) $request->input('path', '/'));

            return json(['code' => 0, 'data' => $files->list($container, $path)]);
        });
    }

    public function read(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = new ContainerFiles();
            $files->assertRunning($container);
            $path = PathGuard::normalize((string) $request->input('path', ''));

            return json(['code' => 0, 'data' => $files->read($container, $path)]);
        });
    }

    public function download(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = new ContainerFiles();
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

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = new ContainerFiles();
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

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = new ContainerFiles();
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

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = new ContainerFiles();
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

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = new ContainerFiles();
            $files->assertRunning($container);
            $path = PathGuard::assertDeletable((string) $request->input('path', ''));
            $files->delete($container, $path);
            $this->audit($app, $container, 'delete ' . $path);

            return json(['code' => 0]);
        });
    }

    public function upload(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = new ContainerFiles();
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

        return $this->run(function () use ($request, $app) {
            $container = $this->resolveContainer($request, $app);
            $files = new ContainerFiles();
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
    // Helper
    // ==================================================================

    /**
     * Ambil app + pastikan user berhak memakai file manager-nya.
     *
     * @throws AppAccessDenied dirender 404 oleh webman (JSON untuk /api/*).
     */
    private function requireApp(string $id): array
    {
        $app = (new AppStore())->find($id);
        if ($app === null) {
            throw new AppAccessDenied('files', $id);
        }
        AppAccess::require('files', $app, current_user());

        return $app;
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

    private function audit(array $app, string $container, string $action): void
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
