<?php
declare(strict_types=1);

namespace app\library\Nginx;

use app\library\Support\ProcessRunner;

/**
 * Reload Nginx HOST dari dalam dashboard container (tanpa setup di host).
 *
 * Dashboard tidak punya shell ke host, tetapi punya akses ke Docker socket
 * host (/var/run/docker.sock). Reload dilakukan lewat helper container yang
 * berbagi PID namespace host (`--pid host`) dan **me-chroot ke root host**
 * (volume `-v /:/host`), sehingga memakai binary, config, module, dan user
 * nginx HOST yang persis (menghindari mismatch binary Alpine vs host):
 *
 *   Test  : docker run --rm --pid host --privileged \
 *             -v /:/host:rw --tmpfs /host/run \
 *             {image} sh -c 'chroot /host "$@"' sh {nginx_bin} -t -c {nginx_http_conf}
 *   Reload: docker run --rm --pid host --privileged \
 *             -v /:/host:ro \
 *             {image} sh -c 'chroot /host "$@"' sh {nginx_bin} -s reload -c {nginx_http_conf}
 *
 * Detail penting:
 * - `--pid host` → pid di /run/nginx.pid (host) merujuk namespace host; SIGHUP
 *   sampai ke master nginx HOST (zero-downtime reload).
 * - `--privileged` → beberapa host membatasi capability/seccomp sehingga
 *   sinyal ke proses root host ditolak (EPERM); `--privileged` menembusnya.
 *   Konsisten dengan threat model project (docker.sock sudah di-mount).
 * - Tahap TEST memakai mount rw + tmpfs `/host/run`: nginx -t perlu menulis
 *   error log (append ke host, sama seperti `sudo nginx -t` manual) dan pid
 *   file; tmpfs melindungi pid asli host agar tidak tertimpa pid test.
 * - Tahap RELOAD memakai mount ro — hanya membaca pid lalu mengirim sinyal.
 *
 * Hasil ditulis ke file status (last-reload.json) yang sama dengan watcher host
 * agar UI (NginxStatusReader) menampilkan konsisten.
 */
class NginxReloader
{
    private ProcessRunner $runner;
    private string $httpConf;
    private string $nginxBin;
    private string $image;
    private string $statusFile;
    private int $timeout;

    public function __construct(?ProcessRunner $runner = null)
    {
        $this->runner = $runner ?? new ProcessRunner();
        $this->httpConf = (string) config('deploy.nginx_http_conf', '/etc/nginx/nginx.conf');
        $this->nginxBin = (string) config('deploy.nginx_bin', '/usr/sbin/nginx');
        $this->image = (string) config('deploy.nginx_reload_image', 'alpine');
        $this->statusFile = (string) config('deploy.nginx_reload_status_file', base_path() . '/nginx-status/last-reload.json');
        $this->timeout = 120;
    }

    /**
     * Validasi config Nginx host (nginx -t) lalu reload (nginx -s reload).
     * Hasil juga ditulis ke file status untuk dibaca UI.
     *
     * @return array{ok:bool, message:string, error:?string, output:string}
     */
    public function reload(): array
    {
        $test = $this->runInHelper('rw', ['-t', '-c', $this->httpConf]);
        if ($test['code'] !== 0) {
            $error = $this->trimOutput($test);
            $this->writeStatus(false, $error);
            return ['ok' => false, 'message' => 'Config Nginx tidak valid.', 'error' => $error, 'output' => $error];
        }

        $reload = $this->runInHelper('ro', ['-s', 'reload', '-c', $this->httpConf]);
        if ($reload['code'] !== 0) {
            $error = $this->trimOutput($reload);
            $this->writeStatus(false, $error);
            return ['ok' => false, 'message' => 'Reload Nginx gagal.', 'error' => $error, 'output' => $error];
        }

        $this->writeStatus(true);
        return ['ok' => true, 'message' => 'Reload Nginx berhasil.', 'error' => null, 'output' => ''];
    }

    /**
     * Jalankan command nginx di dalam helper container (chroot ke root host).
     *
     * @param 'rw'|'ro'            $mode mode mount host root (ro untuk reload)
     * @param array<int,string>    $nginxArgs argumen nginx (tanpa nama binary)
     * @return array{code:int, stdout:string, stderr:string, timedOut:bool}
     */
    private function runInHelper(string $mode, array $nginxArgs): array
    {
        $command = [
            'docker', 'run', '--rm',
            '--pid', 'host',
            '--privileged',
        ];
        if ($mode === 'rw') {
            // nginx -t butuh menulis log & pid; rw utk log, tmpfs /run lindungi pid asli.
            $command[] = '-v';
            $command[] = '/:/host:rw';
            $command[] = '--tmpfs';
            $command[] = '/host/run';
        } else {
            // reload hanya membaca pid lalu SIGHUP — cukup ro.
            $command[] = '-v';
            $command[] = '/:/host:ro';
        }

        $command[] = $this->image;
        $command[] = 'sh';
        $command[] = '-c';
        $command[] = 'chroot /host "$@"'; // argumen diteruskan via "$@" → tanpa string concat
        $command[] = 'sh';
        $command[] = $this->nginxBin;
        foreach ($nginxArgs as $arg) {
            $command[] = $arg;
        }

        return $this->runner->run($command, null, $this->timeout);
    }

    /**
     * @param array{code:int, stdout:string, stderr:string, timedOut:bool} $result
     */
    private function trimOutput(array $result): string
    {
        $out = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
        return $out !== '' ? $out : 'Exit code ' . $result['code'];
    }

    private function writeStatus(bool $ok, string $error = ''): void
    {
        $data = ['ok' => $ok, 'updated_at' => date('c')];
        if (!$ok && $error !== '') {
            $data['error'] = $error;
        }
        $dir = dirname($this->statusFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->statusFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

