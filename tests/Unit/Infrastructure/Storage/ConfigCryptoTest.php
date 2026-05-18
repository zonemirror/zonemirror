<?php

declare(strict_types=1);

namespace CfSync\Tests\Unit\Infrastructure\Storage;

use CfSync\Infrastructure\Storage\ConfigCrypto;
use CfSync\Infrastructure\Storage\KeyStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigCryptoTest extends TestCase
{
    private string $keyPath;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/cfsync-key-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $c = new ConfigCrypto(new KeyStore($this->keyPath));
        $plain = 'cf-token-' . str_repeat('x', 40);
        $cipher = $c->encrypt($plain);
        self::assertNotSame($plain, $cipher);
        self::assertSame($plain, $c->decrypt($cipher));
    }

    public function testCiphertextDiffersAcrossCalls(): void
    {
        $c = new ConfigCrypto(new KeyStore($this->keyPath));
        $plain = 'secret';
        self::assertNotSame($c->encrypt($plain), $c->encrypt($plain), 'nonce/iv must be random per encryption');
    }

    public function testTamperedCiphertextFails(): void
    {
        $c = new ConfigCrypto(new KeyStore($this->keyPath));
        $cipher = $c->encrypt('hello');
        $raw = base64_decode($cipher, true);
        self::assertNotFalse($raw);
        $raw[strlen($raw) - 1] = "\x00";
        $tampered = base64_encode($raw);

        $this->expectException(RuntimeException::class);
        $c->decrypt($tampered);
    }
}
