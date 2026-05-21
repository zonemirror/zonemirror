<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\KeyStore;

final class ConfigCryptoTest extends TestCase
{
    private string $keyPath;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/zonemirror-key-' . bin2hex(random_bytes(4));
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
        // XOR (not overwrite): guarantees a bit flip, avoids 1/256 flake when the random byte matched.
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
        $tampered = base64_encode($raw);

        $this->expectException(RuntimeException::class);
        $c->decrypt($tampered);
    }
}
