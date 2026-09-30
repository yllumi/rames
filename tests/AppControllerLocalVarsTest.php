<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Audit statik AppController: variabel lokal wajib didefinisikan sebelum dipakai.
 *
 * Menutup regresi `AppController::rollback()` yang memakai `$store->update(...)`
 * tanpa pernah mendefinisikan `$store` → `Undefined variable $store` lalu
 * `Call to a member function update() on null` (HTTP 500 pada tombol Rollback).
 *
 * Analisis berbasis `token_get_all()` (tanpa HTTP, tanpa Docker, tanpa data
 * runtime) sehingga aman dijalankan di CI.
 */
class AppControllerLocalVarsTest extends TestCase
{
    private const CONTROLLER = '/app/controller/AppController.php';

    /**
     * Daftar method yang memakai `$store` tanpa mendefinisikannya lebih dulu.
     *
     * "Mendefinisikan" = parameter method/closure bersarang, atau penugasan
     * `$store = …` / `$store += …` di dalam badan method.
     *
     * @return list<string>
     */
    private static function methodsUsingStoreWithoutDefinition(string $source): array
    {
        $tokens = token_get_all($source);

        // Token bermakna (tanpa whitespace/komentar), urutan & baris dipertahankan.
        $t = [];
        foreach ($tokens as $tok) {
            if (is_array($tok)) {
                if (in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $t[] = ['id' => $tok[0], 'text' => $tok[1]];
            } else {
                $t[] = ['id' => null, 'text' => $tok];
            }
        }

        $count = count($t);
        $braceDepth = 0;
        $offenders = [];
        $assignOps = ['=', '+=', '-=', '*=', '/=', '.=', '%=', '??=', '|=', '&=', '^=', '<<=', '>>='];

        for ($i = 0; $i < $count; $i++) {
            if ($t[$i]['text'] === '{') {
                $braceDepth++;
                continue;
            }
            if ($t[$i]['text'] === '}') {
                $braceDepth--;
                continue;
            }
            if ($t[$i]['id'] !== T_FUNCTION || $braceDepth !== 1) {
                continue;
            }

            $name = ($t[$i + 1]['id'] ?? null) === T_STRING ? $t[$i + 1]['text'] : '?';

            // Rentang tanda tangan (untuk parameter) & badan method.
            $j = $i + 1;
            while ($j < $count && $t[$j]['text'] !== '(') {
                $j++;
            }
            $sigStart = $j;
            $sigEnd = $j;
            $depth = 0;
            for (; $j < $count; $j++) {
                if ($t[$j]['text'] === '(') {
                    $depth++;
                } elseif ($t[$j]['text'] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $sigEnd = $j;
                        break;
                    }
                }
            }
            while ($j < $count && $t[$j]['text'] !== '{') {
                $j++;
            }
            $start = $j + 1;
            $inner = 1;
            $end = $start;
            for ($k = $start; $k < $count; $k++) {
                if ($t[$k]['text'] === '{') {
                    $inner++;
                } elseif ($t[$k]['text'] === '}') {
                    $inner--;
                    if ($inner === 0) {
                        $end = $k;
                        break;
                    }
                }
            }

            $defined = false;
            $used = false;

            // Parameter method bernama $store = definisi valid (mis. assertDomainUnique).
            for ($k = $sigStart; $k < $sigEnd; $k++) {
                if ($t[$k]['id'] === T_VARIABLE && $t[$k]['text'] === '$store') {
                    $defined = true;
                }
            }

            for ($k = $start; $k < $end; $k++) {
                if ($t[$k]['id'] === T_VARIABLE && $t[$k]['text'] === '$store') {
                    // nama token berikutnya (lewati subscript array bila ada).
                    $n = $k + 1;
                    if (($t[$n]['text'] ?? '') === '[') {
                        $d = 0;
                        for (; $n < $end; $n++) {
                            if ($t[$n]['text'] === '[') {
                                $d++;
                            } elseif ($t[$n]['text'] === ']') {
                                $d--;
                                if ($d === 0) {
                                    $n++;
                                    break;
                                }
                            }
                        }
                    }
                    if (in_array($t[$n]['text'] ?? '', $assignOps, true)) {
                        $defined = true;
                    } else {
                        $used = true;
                    }
                    continue;
                }
                // parameter closure/arrow-fn bersarang bernama $store = definisi valid
                if ($t[$k]['id'] === T_FUNCTION || $t[$k]['id'] === T_FN) {
                    $p = $k + 1;
                    while ($p < $end && $t[$p]['text'] !== '(') {
                        $p++;
                    }
                    $d = 0;
                    for (; $p < $end; $p++) {
                        if ($t[$p]['text'] === '(') {
                            $d++;
                        } elseif ($t[$p]['text'] === ')') {
                            $d--;
                            if ($d === 0) {
                                break;
                            }
                        } elseif ($d > 0 && $t[$p]['id'] === T_VARIABLE && $t[$p]['text'] === '$store') {
                            $defined = true;
                        }
                    }
                }
            }

            if ($used && !$defined) {
                $offenders[] = $name;
            }

            $i = $end;
        }

        return $offenders;
    }

    public function testDetectorFlagsUndefinedStore(): void
    {
        $fixture = <<<'PHP'
        <?php
        class Fixture
        {
            public function broken(): void
            {
                $store->update('id', static function (array &$s): void {});
            }

            public function alsoBroken(): void
            {
                if (true) {
                    $store->delete('id');
                }
            }

            public function fine(): void
            {
                $store = new AppStore();
                $store->update('id', static function (array &$s) use ($store): void {});
            }
        }
        PHP;

        $this->assertSame(['broken', 'alsoBroken'], self::methodsUsingStoreWithoutDefinition($fixture));
    }

    public function testEveryStoreUsageInAppControllerIsDefined(): void
    {
        $path = dirname(__DIR__) . self::CONTROLLER;
        $this->assertFileExists($path, 'AppController.php tidak ditemukan.');

        $source = (string) file_get_contents($path);
        $offenders = self::methodsUsingStoreWithoutDefinition($source);

        $this->assertSame(
            [],
            $offenders,
            'Method berikut memakai $store tanpa mendefinisikannya: ' . implode(', ', $offenders)
        );
    }
}
