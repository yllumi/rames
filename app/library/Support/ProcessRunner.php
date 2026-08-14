<?php
declare(strict_types=1);

namespace app\library\Support;

use RuntimeException;

/**
 * Menjalankan command eksternal via proc_open dengan timeout.
 *
 * Keamanan: memakai bentuk array + bypass_shell (langsung execve, tanpa shell)
 * sehingga input apapun tidak bisa menjadi command injection — lebih ketat
 * daripada concat string + escapeshellarg (SPECS.md §11).
 *
 * @return array{code:int, stdout:string, stderr:string, timedOut:bool}
 */
class ProcessRunner
{
    /**
     * @param array<int,string> $command list program + argumen (mentah, tanpa escaping)
     * @param string|null       $cwd    working directory
     * @param int               $timeout detik (0 = tanpa batas)
     * @param array<string,string> $env  environment tambahan
     * @return array{code:int, stdout:string, stderr:string, timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $options = ['bypass_shell' => true];
        if ($cwd !== null) {
            $options['cwd'] = $cwd;
        }
        if ($env !== []) {
            $options['env'] = array_merge(getenv(), $env);
        }

        $proc = @proc_open($command, $descriptors, $pipes, $cwd, $env !== [] ? array_merge(getenv(), $env) : null, $options);
        if (!is_resource($proc)) {
            throw new RuntimeException('Gagal menjalankan proses: ' . implode(' ', $command));
        }

        // Tanpa input interaktif — tutup stdin segera
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timedOut = false;

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            // stream_select dengan timeout 1 detik agar loop tidak busy
            $n = @stream_select($read, $write, $except, 1, 0);

            if ($n > 0) {
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk !== false && $chunk !== '') {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }

            if ($timeout > 0 && (microtime(true) - $start) > $timeout) {
                $timedOut = true;
                break;
            }

            $status = @proc_get_status($proc);
            if (!$status || !$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = $status['exitcode'] ?? -1;
                @proc_close($proc);
                return ['code' => (int) $code, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => false];
            }
        }

        // Timeout: terminate paksa
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_terminate($proc, 9);
        @proc_close($proc);

        return [
            'code' => -1,
            'stdout' => $stdout,
            'stderr' => $stderr . "\n[timeout setelah {$timeout} detik]",
            'timedOut' => true,
        ];
    }
}
