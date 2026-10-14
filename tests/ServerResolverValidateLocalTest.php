<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ServerResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test aturan baris **server lokal** di {@see ServerResolver::validateLocal()}.
 *
 * Aturan ini dipindahkan dari `ServerController` (yang dulu menyalin
 * `normalizeDomain()`/`normalizeNotes()` privat) supaya hanya ada **satu** sumber
 * aturan: `domain`/`notes` boleh berubah, sisanya (`name`, `kind`, `base_url`,
 * `token`, `tls_cert_pem`, `enabled`, `port_range`) dikunci — **no-op** bila
 * nilainya setara, **ditolak** bila berbeda. `port_range` sengaja dikunci karena
 * sumber kebenarannya `.env` (`PORT_RANGE_*`), bukan registry server.
 *
 * Tanpa store & tanpa I/O: `validateLocal()` murni (payload + dokumen → payload).
 * Nilai rahasia (token/PEM) tidak boleh muncul di pesan galat.
 */
class ServerResolverValidateLocalTest extends TestCase
{
    private const SECRET_TOKEN = 'agt_rahasia_lokal_9x7q';
    private const SECRET_PEM = "-----BEGIN CERTIFICATE-----\nSANGAT-RAHASIA-JANGAN-BOCOR\n-----END CERTIFICATE-----";

    /**
     * Dokumen baris lokal seperti hasil seed + rentang port (untuk uji no-op).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function local(array $overrides = []): array
    {
        return array_merge([
            'id' => 'local',
            'name' => 'local',
            'kind' => 'local',
            'enabled' => true,
            'domain' => '',
            'port_range' => ['start' => 30000, 'end' => 30999],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // (a) domain & notes boleh berubah (dinormalkan)
    // ------------------------------------------------------------------

    public function testNormalizesDomainAndNotes(): void
    {
        $out = ServerResolver::validateLocal(
            ['domain' => 'Local.Example.com.', 'notes' => '  server utama  '],
            $this->local()
        );

        self::assertSame(['domain' => 'local.example.com', 'notes' => 'server utama'], $out);
    }

    public function testEmptyDomainFallsBackToAppDomain(): void
    {
        $out = ServerResolver::validateLocal(['domain' => '', 'notes' => ''], $this->local());

        self::assertSame(['domain' => '', 'notes' => ''], $out);
    }

    /** Field yang tidak dikirim tidak ditulis ulang (nilai tersimpan tidak hilang). */
    public function testReturnsOnlyEditableFieldsThatWereSent(): void
    {
        self::assertSame([], ServerResolver::validateLocal([], $this->local()));
        self::assertSame(['notes' => 'catatan'], ServerResolver::validateLocal(['notes' => 'catatan'], $this->local()));
        self::assertSame(['domain' => 'a.test'], ServerResolver::validateLocal(['domain' => 'a.test'], $this->local()));
    }

    public function testInvalidDomainUsesDomainMessage(): void
    {
        foreach (['https://x.test', 'x.test:8443', 'x.test/path', str_repeat('a', 254)] as $domain) {
            try {
                ServerResolver::validateLocal(['domain' => $domain], $this->local());
                self::fail("domain \"{$domain}\" harus ditolak");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('Domain server', $e->getMessage());
                self::assertStringNotContainsString('tidak bisa diubah', $e->getMessage());
            }
        }
    }

    public function testTooLongNotesRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Catatan server maksimal ' . ServerResolver::MAX_NOTES_LENGTH . ' karakter.');

        ServerResolver::validateLocal(['notes' => str_repeat('x', ServerResolver::MAX_NOTES_LENGTH + 1)], $this->local());
    }

    // ------------------------------------------------------------------
    // (b) field terkunci dengan nilai berbeda ⇒ ditolak, pesan menyebut field
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:mixed}>
     */
    public static function lockedFieldProvider(): array
    {
        return [
            'name' => ['name', 'local-baru'],
            'kind' => ['kind', 'agent'],
            'base_url' => ['base_url', 'https://10.0.0.99:8443'],
            'token' => ['token', self::SECRET_TOKEN],
            'tls_cert_pem' => ['tls_cert_pem', self::SECRET_PEM],
            'enabled' => ['enabled', '0'],
            'port_range' => ['port_range', '31000-31999'],
        ];
    }

    /** @dataProvider lockedFieldProvider */
    public function testChangedLockedFieldIsRejected(string $field, mixed $value): void
    {
        try {
            ServerResolver::validateLocal([$field => $value], $this->local());
            self::fail("field \"{$field}\" harus ditolak bila nilainya berbeda");
        } catch (RuntimeException $e) {
            self::assertStringContainsString('field ' . $field, $e->getMessage());
            self::assertStringContainsString('Yang bisa disesuaikan: domain & notes', $e->getMessage());
        }
    }

    public function testKindCanNeverBeWrittenEvenWhenSubmitted(): void
    {
        $out = ServerResolver::validateLocal(['kind' => 'local', 'domain' => 'a.test'], $this->local());

        self::assertArrayNotHasKey('kind', $out, 'kind tidak boleh ikut ditulis oleh pemanggil');
        self::assertSame(['domain' => 'a.test'], $out);
    }

    public function testMultipleChangedFieldsAreAllNamed(): void
    {
        try {
            ServerResolver::validateLocal(['kind' => 'agent', 'token' => self::SECRET_TOKEN, 'enabled' => '0'], $this->local());
            self::fail('perubahan field terkunci harus ditolak');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('kind', $e->getMessage());
            self::assertStringContainsString('token', $e->getMessage());
            self::assertStringContainsString('enabled', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // (c) nilai yang sama (bentuk setara) ⇒ no-op, bukan penolakan
    // ------------------------------------------------------------------

    public function testEqualValuesAreNoOps(): void
    {
        $payload = [
            'name' => 'local',
            'kind' => 'local',
            'base_url' => '',
            'token' => '',
            'tls_cert_pem' => '',
            'enabled' => 'on',                 // boolean setara true
            'port_range' => ['start' => '30000', 'end' => '30999'], // array vs array
            'domain' => 'local.example.com',
            'notes' => 'server utama',
        ];

        $out = ServerResolver::validateLocal($payload, $this->local());

        self::assertSame(['domain' => 'local.example.com', 'notes' => 'server utama'], $out);
    }

    public function testEquivalentPortRangeTextIsNoOp(): void
    {
        foreach (['30000-30999', '30000 : 30999', '30000..30999'] as $range) {
            $out = ServerResolver::validateLocal(['port_range' => $range], $this->local());

            self::assertSame([], $out, "rentang setara \"{$range}\" harus dianggap tidak berubah");
        }
    }

    public function testAbsentStoredValuesAreNoOpsWhenSubmittedEmpty(): void
    {
        // Baris lokal hasil seed tanpa base_url/token/PEM/port_range.
        $seed = ['id' => 'local', 'name' => 'local', 'kind' => 'local', 'enabled' => true, 'domain' => ''];

        $out = ServerResolver::validateLocal([
            'base_url' => '',
            'token' => '',
            'tls_cert_pem' => '',
            'enabled' => '1',
        ], $seed);

        self::assertSame([], $out);
    }

    /**
     * Pasangan rentang port yang **kosong** bukan bentuk setara dari rentang yang
     * absen: ia tetap dianggap "dikirim" dan berbeda.
     *
     * Perilaku ini **sama persis** dengan sebelum refactor (controller lama
     * membandingkan dengan cara yang sama), jadi sengaja dipin di sini. Jalur UI
     * normal tidak terkena: `ServerController::serverInput()` hanya mengirim
     * `port_range` bila salah satu sisi terisi, dan `port_range` milik baris lokal
     * memang terkunci (sumber kebenarannya `.env`/`PORT_RANGE_*`).
     */
    public function testEmptyRangePairIsTreatedAsChangeWhenStoredRangeAbsent(): void
    {
        $seed = ['id' => 'local', 'name' => 'local', 'kind' => 'local', 'enabled' => true, 'domain' => ''];

        try {
            ServerResolver::validateLocal(['port_range' => ['start' => '', 'end' => '']], $seed);
            self::fail('pasangan rentang kosong pada baris lokal harus dianggap perubahan');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('field port_range', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // (d) rahasia tidak pernah bocor ke pesan galat
    // ------------------------------------------------------------------

    public function testSecretsNeverAppearInErrorMessage(): void
    {
        try {
            ServerResolver::validateLocal([
                'name' => 'local-baru',
                'token' => self::SECRET_TOKEN,
                'tls_cert_pem' => self::SECRET_PEM,
            ], $this->local());
            self::fail('perubahan field terkunci harus ditolak');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            self::assertStringNotContainsString(self::SECRET_TOKEN, $message);
            self::assertStringNotContainsString('SANGAT-RAHASIA-JANGAN-BOCOR', $message);
            self::assertStringNotContainsString('BEGIN CERTIFICATE', $message);
            self::assertStringContainsString('field name, token, tls_cert_pem', $message);
        }
    }
}
