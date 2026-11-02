<?php
declare(strict_types=1);

namespace Tests;

use app\library\Update\UpdateService;
use PHPUnit\Framework\TestCase;

/**
 * Keputusan perbaikan kepemilikan `vendor/` sebelum `composer install` di helper
 * self-update (docs/SPECS.md §7.8).
 *
 * Bug yang dicegah: bootstrap container dashboard berjalan sebagai **root** dan
 * membuat `vendor/` milik root; helper update berjalan `--user <pemilik repo>`
 * sehingga `composer install --no-dev` gagal "Could not delete …/vendor/…".
 * `needsVendorOwnerRepair()` memakai stat (owner/group/other + bit mode), bukan
 * `is_writable()` yang selalu `true` bagi root.
 */
class UpdateVendorOwnershipTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-vendor-owner-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->tmp);
    }

    public function testVendorMissingMeansNoRepair(): void
    {
        self::assertFalse(UpdateService::needsVendorOwnerRepair($this->tmp, 33, 33));
    }

    public function testRootHelperNeverNeedsRepair(): void
    {
        $this->makeVendor(0700);

        self::assertFalse(UpdateService::needsVendorOwnerRepair($this->tmp, 0, 0));
    }

    public function testVendorWritableByHelperUidNeedsNoRepair(): void
    {
        $this->makeVendor(0777);

        self::assertFalse(UpdateService::needsVendorOwnerRepair($this->tmp, 33, 33));
    }

    public function testVendorNotWritableByHelperUidNeedsRepair(): void
    {
        // 0755: uid/gid 33 bukan owner/group → hanya r-x pada "other" (tanpa w).
        $this->makeVendor(0755);

        self::assertTrue(UpdateService::needsVendorOwnerRepair($this->tmp, 33, 33));
    }

    public function testRootOwnedComposerSubdirInsideWritableVendorNeedsRepair(): void
    {
        $vendor = $this->makeVendor(0777);
        mkdir($vendor . '/composer', 0755, true);

        self::assertTrue(UpdateService::needsVendorOwnerRepair($this->tmp, 33, 33));
    }

    /**
     * Buat direktori `vendor/` dengan mode tertentu; kepemilikan mengikuti user
     * yang menjalankan test (mode bit yang menentukan hasil, bukan owner).
     */
    private function makeVendor(int $mode): string
    {
        $vendor = $this->tmp . '/vendor';
        mkdir($vendor, $mode, true);
        chmod($vendor, $mode);

        return $vendor;
    }
}
