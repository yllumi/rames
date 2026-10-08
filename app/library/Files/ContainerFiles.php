<?php
declare(strict_types=1);

namespace app\library\Files;

use app\library\Docker\DockerClient;
use app\library\Docker\DockerExec;
use app\library\Support\ProcessRunner;
use Throwable;

/**
 * File manager container — semua logika bisnis di sini (controller hanya mediator).
 *
 * Transport byte (unduh/unggah/ekstrak) memakai `docker cp` + direktori temp di
 * `runtime/` agar berkas besar TIDAK dimuat ke memori PHP. Perintah di dalam
 * container dijalankan lewat `DockerExec` (array args + `sh -c` di container,
 * tiap argumen di-`escapeshellarg`); perintah di host (docker/unzip/tar) lewat
 * `ProcessRunner` (array + `bypass_shell`).
 *
 * Container WAJIB berjalan — operasi ditolak fail-fast 409 bila tidak (tidak
 * membuat container sementara / menyentuh volume langsung).
 */
class ContainerFiles
{
    /** Batas jumlah entri yang dikembalikan `list` (sisanya → `truncated`). */
    public const MAX_LIST_ENTRIES = 5000;

    /**
     * User `docker exec` untuk SEMUA operasi file manager (§7.9).
     *
     * Keputusan user: operator+ (`ability files`) sudah memegang shell penuh,
     * sehingga operasi berkas berjalan sebagai **root** agar bisa mengubah/menghapus
     * berkas milik siapa pun di dalam container — tanpa ini `rm`/`cat >` gagal
     * `permission denied` pada berkas milik root atau user image lain.
     *
     * Dampak kepemilikan entri BARU dikompensasi `restoreOwner()` (lihat di bawah):
     * entri yang dulu dibuat user image tetap milik user image.
     */
    private const ROOT_USER = '0';

    private ?DockerClient $docker;
    private ?DockerExec $exec;
    private ?string $transferRoot;
    private ?string $dockerBinary;

    /** Cache `Config.User` container untuk request ini ('' = root, null = belum dibaca). */
    private ?string $ownerSpec = null;

    public function __construct(
        ?DockerClient $docker = null,
        ?DockerExec $exec = null,
        ?string $transferRoot = null,
        ?string $dockerBinary = null,
    ) {
        $this->docker = $docker;
        $this->exec = $exec;
        $this->transferRoot = $transferRoot;
        $this->dockerBinary = $dockerBinary;
    }

    // ==================================================================
    // Guard
    // ==================================================================

    /**
     * Pastikan container berjalan (keputusan user: tolak bila tidak berjalan).
     *
     * @throws FileError 409
     */
    public function assertRunning(string $container): void
    {
        try {
            $info = $this->docker()->inspectContainer($container);
        } catch (Throwable $e) {
            throw new FileError('Container sedang tidak berjalan — jalankan app dulu.', 409);
        }
        if (!(bool) ($info['State']['Running'] ?? false)) {
            throw new FileError('Container sedang tidak berjalan — jalankan app dulu.', 409);
        }
    }

    // ==================================================================
    // Baca
    // ==================================================================

    /**
     * @return array{path:string,parent:?string,truncated:bool,entries:array<int,array>}
     * @throws FileError
     */
    public function list(string $container, string $path): array
    {
        $path = PathGuard::normalize($path);
        $type = $this->containerType($container, $path);
        if ($type === 'missing') {
            throw new FileError('Direktori tidak ditemukan.', 404);
        }
        if ($type !== 'dir') {
            throw new FileError('Bukan direktori.', 400);
        }

        $result = $this->exec()->runCommand($container, $this->listingCommand($path), $this->timeout(), self::ROOT_USER);
        if ($result['code'] !== 0) {
            throw new FileError('Gagal membaca direktori: ' . $this->errorText($result), 400);
        }

        $entries = ListingParser::parse($result['stdout'], $path);
        $truncated = count($entries) > self::MAX_LIST_ENTRIES;
        if ($truncated) {
            $entries = array_slice($entries, 0, self::MAX_LIST_ENTRIES);
        }

        return [
            'path' => $path,
            'parent' => PathGuard::parentOf($path),
            'truncated' => $truncated,
            'entries' => $entries,
        ];
    }

    /**
     * @return array{path:string,size:int,text:string,truncated:bool}
     * @throws FileError
     */
    public function read(string $container, string $path): array
    {
        $path = PathGuard::normalize($path);
        $type = $this->containerType($container, $path);
        if ($type === 'missing') {
            throw new FileError('Berkas tidak ditemukan.', 404);
        }
        if ($type === 'dir') {
            throw new FileError('Berkas adalah direktori.', 400);
        }
        if ($type !== 'file' && $type !== 'link') {
            throw new FileError('Berkas bukan berkas reguler.', 400);
        }

        $sizeResult = $this->exec()->runCommand($container, 'wc -c < ' . escapeshellarg($path), $this->timeout(), self::ROOT_USER);
        if ($sizeResult['code'] !== 0) {
            throw new FileError('Gagal membaca ukuran berkas: ' . $this->errorText($sizeResult), 400);
        }
        $size = (int) trim($sizeResult['stdout']);
        if ($size < 0) {
            $size = 0;
        }
        if (TextContent::isTooLarge($size)) {
            throw new FileError('Berkas melebihi batas 2 MiB untuk diedit sebagai teks.', 413);
        }

        $contentResult = $this->exec()->runCommand($container, 'cat ' . escapeshellarg($path), $this->timeout(), self::ROOT_USER);
        if ($contentResult['code'] !== 0) {
            throw new FileError('Gagal membaca berkas: ' . $this->errorText($contentResult), 400);
        }
        $text = $contentResult['stdout'];
        if (TextContent::isBinary($text)) {
            throw new FileError('Berkas biner tidak dapat diedit sebagai teks.', 415);
        }

        return ['path' => $path, 'size' => strlen($text), 'text' => $text, 'truncated' => false];
    }

    // ==================================================================
    // Mutasi
    // ==================================================================

    /**
     * @throws FileError
     */
    public function write(string $container, string $path, string $content): void
    {
        $path = PathGuard::normalize($path);
        if ($path === '/') {
            throw new FileError('Path bukan berkas yang valid.', 400);
        }
        if (TextContent::isTooLarge(strlen($content))) {
            throw new FileError('Isi melebihi batas 2 MiB.', 413);
        }
        $type = $this->containerType($container, $path);
        if ($type === 'dir') {
            throw new FileError('Tujuan adalah direktori.', 400);
        }
        // Kepemilikan hanya dipulihkan untuk berkas yang BARU dibuat: penulisan
        // ulang berkas yang sudah ada (mis. milik root) tidak boleh mengubah
        // pemiliknya — perilaku lama pun mempertahankan pemilik berkas lama.
        $isNew = $type === 'missing';

        $result = $this->exec()->runCommandWithInput($container, 'cat > ' . escapeshellarg($path), $content, $this->timeout(), self::ROOT_USER);
        if ($result['code'] !== 0) {
            throw new FileError('Gagal menyimpan berkas: ' . $this->errorText($result), 400);
        }
        if ($isNew) {
            $this->restoreOwner($container, $path);
        }
    }

    /**
     * @throws FileError
     */
    public function mkdir(string $container, string $path): void
    {
        $path = PathGuard::normalize($path);
        if ($path === '/') {
            throw new FileError('Path direktori tidak valid.', 400);
        }
        $result = $this->exec()->runCommand($container, 'mkdir ' . escapeshellarg($path), $this->timeout(), self::ROOT_USER);
        if ($result['code'] !== 0) {
            throw new FileError('Gagal membuat direktori: ' . $this->errorText($result), 400);
        }
        // Direktori baru dulu dibuat user image (mkdir sebagai user container) →
        // samakan hasilnya walau sekarang dijalankan sebagai root.
        $this->restoreOwner($container, $path);
    }

    /**
     * @throws FileError
     *
     * PENTING: pemanggil WAJIB sudah memastikan container berjalan
     * ({@see assertRunning()} — seperti yang dilakukan `FileController`).
     * Bila container berhenti, `containerType()` gagal dan kegagalan itu
     * muncul sebagai `404` "Berkas sumber tidak ditemukan" — bukan `409`
     * container tidak berjalan. Metode ini TIDAK memanggil `assertRunning()`.
     */
    public function rename(string $container, string $from, string $to): void
    {
        $from = PathGuard::normalize($from);
        $to = PathGuard::normalize($to);
        if ($from === '/' || $to === '/') {
            throw new FileError('Path tidak valid.', 400);
        }
        if ($this->containerType($container, $from) === 'missing') {
            throw new FileError('Berkas sumber tidak ditemukan.', 404);
        }
        $result = $this->exec()->runCommand($container, 'mv ' . escapeshellarg($from) . ' ' . escapeshellarg($to), $this->timeout(), self::ROOT_USER);
        if ($result['code'] !== 0) {
            throw new FileError('Gagal memindahkan: ' . $this->errorText($result), 400);
        }
    }

    /**
     * Pindahkan berkas/folder ke direktori lain (§7.9).
     *
     * Validasi (semua SEBELUM efek samping):
     *  - sumber wajib ada (`404`);
     *  - tujuan wajib direktori yang sudah ada (`400`);
     *  - tujuan tidak boleh berada di dalam sumber (diri sendiri/descendant) —
     *    dijaga `FilesInput::moveTarget()` (`400`), juga menolak sumber `/`;
     *  - nama entri di tujuan tidak boleh memuat `/`/diawali `-` (PathGuard);
     *  - TIDAK menimpa entri yang sudah ada di tujuan (`409`) — tidak ada
     *    overwrite diam-diam; pemanggil harus memilih nama lain.
     *
     * PENTING: pemanggil WAJIB sudah memastikan container berjalan
     * ({@see assertRunning()} — seperti yang dilakukan `FileController::move()`
     * yang memanggilnya lebih dulu). Bila container berhenti, `containerType()`
     * gagal dan kegagalan itu muncul sebagai `404` "Berkas sumber tidak
     * ditemukan" — bukan `409` container tidak berjalan; kontrak `409` di sini
     * hanya untuk "entri sudah ada di tujuan". Metode ini TIDAK memanggil
     * `assertRunning()` sendiri.
     *
     * @return array{from:string,to:string}
     * @throws FileError
     */
    public function move(string $container, string $from, string $toDir, ?string $name = null): array
    {
        $plan = FilesInput::moveTarget($from, $toDir, $name);
        $from = $plan['from'];
        $dir = $plan['dir'];
        $target = $plan['target'];

        if ($this->containerType($container, $from) === 'missing') {
            throw new FileError('Berkas sumber tidak ditemukan.', 404);
        }
        if ($this->containerType($container, $dir) !== 'dir') {
            throw new FileError('Tujuan bukan direktori yang ada.', 400);
        }
        if ($this->containerType($container, $target) !== 'missing') {
            throw new FileError('Di tujuan sudah ada entri dengan nama tersebut.', 409);
        }

        $result = $this->exec()->runCommand(
            $container,
            'mv ' . escapeshellarg($from) . ' ' . escapeshellarg($target),
            $this->timeout(),
            self::ROOT_USER
        );
        if ($result['code'] !== 0) {
            throw new FileError('Gagal memindahkan: ' . $this->errorText($result), 400);
        }

        return ['from' => $from, 'to' => $target];
    }

    /**
     * @throws FileError
     *
     * PENTING: pemanggil WAJIB sudah memastikan container berjalan
     * ({@see assertRunning()} — seperti yang dilakukan `FileController`).
     * Bila container berhenti, `containerType()` gagal dan kegagalan itu
     * muncul sebagai `404` "Berkas tidak ditemukan" — bukan `409` container
     * tidak berjalan. Metode ini TIDAK memanggil `assertRunning()`.
     */
    public function delete(string $container, string $path): void
    {
        $path = PathGuard::assertDeletable($path);
        if ($this->containerType($container, $path) === 'missing') {
            throw new FileError('Berkas tidak ditemukan.', 404);
        }
        $result = $this->exec()->runCommand($container, 'rm -rf ' . escapeshellarg($path), $this->timeout(), self::ROOT_USER);
        if ($result['code'] !== 0) {
            throw new FileError('Gagal menghapus: ' . $this->errorText($result), 400);
        }
    }

    /**
     * Salin satu berkas unggahan (sudah tersimpan sebagai temp di host dashboard)
     * ke dalam container.
     *
     * @return array{name:string,size:int}
     * @throws FileError
     */
    public function uploadOne(string $container, string $dir, string $tmpPath, string $name): array
    {
        $dir = PathGuard::normalize($dir);
        $name = PathGuard::assertName(basename($name));
        if (!is_file($tmpPath)) {
            throw new FileError('Berkas unggahan tidak ditemukan.', 400);
        }
        if ($this->containerType($container, $dir) !== 'dir') {
            throw new FileError('Direktori tujuan tidak ditemukan.', 400);
        }

        $dest = PathGuard::resolveChild($dir, $name);
        $result = $this->runHost([$this->dockerBinary(), 'cp', $tmpPath, $container . ':' . $dest], $this->transferTimeout());
        if ($result['code'] !== 0) {
            throw new FileError('Gagal mengunggah ' . $name . ': ' . $this->errorText($result), 400);
        }

        return ['name' => $name, 'size' => (int) @filesize($tmpPath)];
    }

    // ==================================================================
    // Unduh
    // ==================================================================

    /**
     * Salin berkas dari container ke direktori temp host. Pemanggil bertanggung
     * jawab atas pembersihan (`cleanup()` / `scheduleCleanup()`) karena respons
     * `response()->download()` membaca berkas SETELAH handler kembali.
     *
     * @return array{dir:string,path:string,name:string}
     * @throws FileError
     */
    public function downloadToTemp(string $container, string $path): array
    {
        $path = PathGuard::normalize($path);
        $type = $this->containerType($container, $path);
        if ($type === 'missing') {
            throw new FileError('Berkas tidak ditemukan.', 404);
        }
        if ($type === 'dir') {
            throw new FileError('Tidak dapat mengunduh direktori.', 400);
        }
        if ($type !== 'file' && $type !== 'link') {
            throw new FileError('Bukan berkas reguler.', 400);
        }

        $name = basename($path);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new FileError('Nama berkas tidak valid.', 400);
        }

        $dir = $this->transferDir();
        $dest = $dir . '/' . $name;
        $result = $this->runHost([$this->dockerBinary(), 'cp', $container . ':' . $path, $dest], $this->transferTimeout());
        if ($result['code'] !== 0) {
            $this->cleanup($dir);
            throw new FileError('Gagal menyalin berkas dari container: ' . $this->errorText($result), 400);
        }
        if (!is_file($dest)) {
            $this->cleanup($dir);
            throw new FileError('Bukan berkas reguler.', 400);
        }

        return ['dir' => $dir, 'path' => $dest, 'name' => $name];
    }

    // ==================================================================
    // Ekstrak arsip
    // ==================================================================

    /**
     * Ekstrak `.zip`/`.tar.gz` di host dashboard lalu salin isinya ke container.
     *
     * @return int jumlah entri hasil ekstraksi
     * @throws FileError
     */
    public function extract(string $container, string $dir, string $name, ?string $dest = null): int
    {
        $kind = ArchiveGuard::kind($name);
        if ($kind === null) {
            throw new FileError('Hanya arsip .zip atau .tar.gz/.tgz yang didukung.', 400);
        }

        $dir = PathGuard::normalize($dir);
        $name = PathGuard::assertName($name);
        $destPath = ($dest !== null && $dest !== '') ? PathGuard::normalize($dest) : $dir;

        $destType = $this->containerType($container, $destPath);
        if ($destType === 'missing') {
            // Direktori yang dibuat `mkdir -p` dicatat dulu supaya pemiliknya bisa
            // dikembalikan ke user container (perilaku lama) walau exec kini root.
            $created = $this->missingAncestors($container, $destPath);
            $mkdir = $this->exec()->runCommand($container, 'mkdir -p ' . escapeshellarg($destPath), $this->timeout(), self::ROOT_USER);
            if ($mkdir['code'] !== 0) {
                throw new FileError('Gagal membuat direktori tujuan: ' . $this->errorText($mkdir), 400);
            }
            foreach ($created as $createdPath) {
                $this->restoreOwner($container, $createdPath);
            }
        } elseif ($destType !== 'dir') {
            throw new FileError('Tujuan ekstraksi bukan direktori.', 400);
        }

        $archivePath = PathGuard::resolveChild($dir, $name);
        if ($this->containerType($container, $archivePath) !== 'file') {
            throw new FileError('Arsip tidak ditemukan.', 404);
        }

        $work = $this->transferDir();
        try {
            $localArchive = $work . '/archive';
            $copy = $this->runHost(
                [$this->dockerBinary(), 'cp', $container . ':' . $archivePath, $localArchive],
                $this->transferTimeout()
            );
            if ($copy['code'] !== 0 || !is_file($localArchive)) {
                throw new FileError('Gagal menyalin arsip dari container: ' . $this->errorText($copy), 400);
            }

            $outDir = $work . '/x';
            if (!@mkdir($outDir, 0700)) {
                throw new FileError('Gagal menyiapkan direktori ekstraksi.', 500);
            }

            $result = $kind === 'zip'
                ? $this->extractZip($localArchive, $outDir)
                : $this->extractTar($localArchive, $outDir);
            if ($result['code'] !== 0) {
                throw new FileError('Ekstraksi gagal: ' . $this->errorText($result), 400);
            }

            $escape = ArchiveGuard::escapingSymlink($outDir);
            if ($escape !== null) {
                throw new FileError('Arsip memuat symlink yang keluar dari direktori ekstraksi.', 400);
            }

            $measured = ArchiveGuard::measure($outDir);
            if ($measured['entries'] > ArchiveGuard::MAX_ENTRIES) {
                throw new FileError('Arsip memuat terlalu banyak entri (maks ' . ArchiveGuard::MAX_ENTRIES . ').', 413);
            }
            if ($measured['bytes'] > ArchiveGuard::MAX_EXTRACT_BYTES) {
                throw new FileError('Hasil ekstraksi melebihi batas ukuran.', 413);
            }

            $copy = $this->runHost(
                [$this->dockerBinary(), 'cp', $outDir . '/.', $container . ':' . $destPath],
                $this->transferTimeout()
            );
            if ($copy['code'] !== 0) {
                throw new FileError('Gagal menyalin hasil ekstraksi ke container: ' . $this->errorText($copy), 400);
            }

            return $measured['entries'];
        } finally {
            $this->cleanup($work);
        }
    }

    // ==================================================================
    // Temp transfer
    // ==================================================================

    /**
     * Hapus direktori temp transfer (rekursif, best-effort).
     */
    public function cleanup(string $dir): void
    {
        $dir = rtrim($dir, '/');
        if ($dir === '' || !is_dir($dir) || !PathGuard::contains($this->transferRoot(), $dir)) {
            return;
        }
        $this->removeTree($dir);
    }

    /**
     * Jadwalkan pembersihan temp SETELAH respons unduhan selesai dikirim.
     * Respons file di-stream Workerman setelah handler kembali, sehingga temp
     * tidak boleh dihapus sinkron. Bila Timer tak tersedia, `prune()` pada
     * request berikutnya akan membersihkannya.
     */
    public function scheduleCleanup(string $dir, int $delaySeconds = 300): void
    {
        try {
            \Workerman\Timer::add($delaySeconds, function () use ($dir): void {
                $this->cleanup($dir);
            }, [], false);
        } catch (Throwable $e) {
            // tanpa event loop — andalkan prune()
        }
    }

    // ==================================================================
    // Helper internal
    // ==================================================================

    /**
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private function runHost(array $args, int $timeout): array
    {
        return (new ProcessRunner())->run($args, null, $timeout);
    }

    /**
     * Kembalikan kepemilikan entri yang BARU dibuat ke user default container —
     * best-effort, kegagalan diabaikan.
     *
     * Latar: seluruh operasi berkas kini dijalankan sebagai root (agar bisa
     * mengubah berkas milik siapa pun). Tanpa langkah ini, entri yang dulu dibuat
     * user image (`mkdir`, `cat >` berkas baru, `mkdir -p` tujuan ekstrak) akan
     * berpindah pemilik ke root. `chown` memakai spesifikasi dari `Config.User`
     * container (`www-data` → `chown www-data:`, `1000:1000` tetap apa adanya);
     * container ber-user root (Config.User kosong) tidak perlu chown sama sekali.
     */
    private function restoreOwner(string $container, string $path): void
    {
        $owner = $this->ownerSpec($container);
        if ($owner === '') {
            return;
        }
        // Non-rekursif dengan sengaja: hanya entri ini yang kita buat; isinya
        // (mis. berkas hasil `docker cp` pada ekstrak) tetap seperti perilaku lama.
        $this->exec()->runCommand(
            $container,
            'chown ' . escapeshellarg($owner) . ' ' . escapeshellarg($path) . ' 2>/dev/null || true',
            $this->timeout(),
            self::ROOT_USER
        );
    }

    /**
     * Spesifikasi `chown` dari user default container ('' = root → tanpa chown).
     *
     * `Config.User` bisa berbentuk `www-data`, `1000`, atau `1000:1000`. Bila grup
     * tidak disebut, `chown user:` mengeset grup ke login group user tersebut —
     * meniru hasil "dibuat oleh user itu" (grup = primary group).
     */
    private function ownerSpec(string $container): string
    {
        if ($this->ownerSpec !== null) {
            return $this->ownerSpec;
        }
        try {
            $info = $this->docker()->inspectContainer($container);
        } catch (Throwable $e) {
            return $this->ownerSpec = '';
        }
        $user = trim((string) ($info['Config']['User'] ?? ''));
        // '' / '0' / 'root' = container berjalan sebagai root → chown tak berguna.
        if ($user === '' || $user === '0' || $user === 'root' || $user === '0:0') {
            return $this->ownerSpec = '';
        }

        return $this->ownerSpec = (str_contains($user, ':') ? $user : $user . ':');
    }

    /**
     * Daftar path yang BELUM ada mulai dari `$path` naik ke atas (untuk
     * mengembalikan pemilik direktori hasil `mkdir -p`).
     *
     * @return array<int,string> terdalam → terluar
     */
    private function missingAncestors(string $container, string $path): array
    {
        // Pemanggil sudah tahu `$path` sendiri belum ada.
        $missing = [$path];
        $current = PathGuard::parentOf($path);
        while ($current !== null && $current !== '/') {
            if ($this->containerType($container, $current) !== 'missing') {
                break;
            }
            $missing[] = $current;
            $current = PathGuard::parentOf($current);
        }

        return $missing;
    }

    /**
     * Jalankan `unzip` pada arsip di host, setelah validasi entri.
     *
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private function extractZip(string $archive, string $outDir): array
    {
        $list = $this->runHost(['unzip', '-Z1', $archive], $this->timeout());
        if ($list['code'] !== 0) {
            throw new FileError('Arsip zip tidak dapat dibaca.', 400);
        }
        $this->assertArchiveEntries(explode("\n", $list['stdout']));

        $verbose = $this->runHost(['unzip', '-l', $archive], $this->timeout());
        $total = ArchiveGuard::parseZipTotal($verbose['stdout']);
        if ($total !== null && $total['bytes'] > ArchiveGuard::MAX_EXTRACT_BYTES) {
            throw new FileError('Arsip berpotensi terlalu besar setelah diekstrak.', 413);
        }

        return $this->runHost(['unzip', '-q', '-o', $archive, '-d', $outDir], $this->transferTimeout());
    }

    /**
     * Jalankan `tar` pada arsip di host, setelah validasi entri.
     *
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private function extractTar(string $archive, string $outDir): array
    {
        $list = $this->runHost(['tar', '-tzf', $archive], $this->timeout());
        if ($list['code'] !== 0) {
            throw new FileError('Arsip tar.gz tidak dapat dibaca.', 400);
        }
        $this->assertArchiveEntries(explode("\n", $list['stdout']));

        return $this->runHost(['tar', '-xzf', $archive, '-C', $outDir], $this->transferTimeout());
    }

    /**
     * @param array<int,string> $lines
     * @throws FileError
     */
    private function assertArchiveEntries(array $lines): void
    {
        $names = [];
        foreach ($lines as $line) {
            if ($line !== '') {
                $names[] = $line;
            }
        }
        if (count($names) > ArchiveGuard::MAX_ENTRIES) {
            throw new FileError('Arsip memuat terlalu banyak entri (maks ' . ArchiveGuard::MAX_ENTRIES . ').', 413);
        }
        $unsafe = ArchiveGuard::unsafeEntry($names);
        if ($unsafe !== null) {
            throw new FileError('Arsip memuat entri tidak aman: ' . $unsafe, 400);
        }
    }

    /**
     * Perintah daftar direktori yang portabel (BusyBox/Alpine & GNU/Debian):
     * `stat -c` per entri, field dipisah US dan record dipisah RS.
     */
    private function listingCommand(string $path): string
    {
        return 'US=$(printf "\037"); RS=$(printf "\036"); d=' . escapeshellarg($path) . ';'
            . ' for f in "$d"/* "$d"/.[!.]* "$d"/..?*; do'
            . ' { [ -e "$f" ] || [ -L "$f" ]; } || continue;'
            . ' t=$(stat -c "%F${US}%s${US}%Y${US}%A${US}%n" "$f" 2>/dev/null) || continue;'
            . ' l=$(readlink "$f" 2>/dev/null);'
            . ' printf "%s%s%s" "$t" "${US}${l}${RS}";'
            . ' done';
    }

    /**
     * Tipe entri di dalam container: dir|file|link|other|missing.
     */
    private function containerType(string $container, string $path): string
    {
        $p = escapeshellarg($path);
        $command = 'if [ -d ' . $p . ' ]; then echo dir;'
            . ' elif [ -f ' . $p . ' ]; then echo file;'
            . ' elif [ -L ' . $p . ' ]; then echo link;'
            . ' elif [ -e ' . $p . ' ]; then echo other;'
            . ' else echo missing; fi';
        $result = $this->exec()->runCommand($container, $command, $this->timeout(), self::ROOT_USER);
        $type = trim($result['stdout']);

        return in_array($type, ['dir', 'file', 'link', 'other'], true) ? $type : 'missing';
    }

    private function transferDir(): string
    {
        $root = $this->transferRoot();
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new FileError('Gagal membuat direktori transfer.', 500);
        }
        $this->prune();

        $dir = $root . '/' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700)) {
            throw new FileError('Gagal membuat direktori transfer.', 500);
        }

        return $dir;
    }

    private function transferRoot(): string
    {
        if ($this->transferRoot === null) {
            $this->transferRoot = runtime_path() . '/files-transfer';
        }

        return $this->transferRoot;
    }

    /**
     * Buang direktori transfer yatim (worker mati sebelum Timer jalan).
     */
    private function prune(int $olderThanSeconds = 3600): void
    {
        $root = $this->transferRoot();
        if (!is_dir($root)) {
            return;
        }
        $threshold = time() - $olderThanSeconds;
        foreach ((array) @scandir($root) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === false) {
                continue;
            }
            $path = $root . '/' . $entry;
            if (is_dir($path) && (int) @filemtime($path) < $threshold) {
                $this->removeTree($path);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path => $info) {
            $path = (string) $path;
            if ($info->isDir() && !is_link($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function docker(): DockerClient
    {
        if ($this->docker === null) {
            $this->docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
        }

        return $this->docker;
    }

    private function exec(): DockerExec
    {
        if ($this->exec === null) {
            $this->exec = new DockerExec();
        }

        return $this->exec;
    }

    private function dockerBinary(): string
    {
        if ($this->dockerBinary === null) {
            $this->dockerBinary = (string) config('deploy.docker_binary', 'docker');
        }

        return $this->dockerBinary;
    }

    private function timeout(): int
    {
        return (int) config('deploy.files_timeout', 120);
    }

    private function transferTimeout(): int
    {
        return (int) config('deploy.files_transfer_timeout', 600);
    }

    /**
     * @param array{code:int,stdout:string,stderr:string,timedOut:bool} $result
     */
    private function errorText(array $result): string
    {
        $text = trim($result['stderr']);
        if ($text === '') {
            $text = trim($result['stdout']);
        }
        if ($text === '') {
            $text = 'exit ' . $result['code'] . ($result['timedOut'] ? ' (timeout)' : '');
        }

        return $text;
    }
}
