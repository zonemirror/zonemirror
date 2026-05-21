<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;

/**
 * Loads (or lazily provisions) the 32-byte master encryption key used to
 * encrypt user API tokens at rest. The key file is created with 0600
 * permissions and is never logged.
 */
final class KeyStore
{
    public function __construct(private readonly string $keyPath)
    {
    }

    public function load(): string
    {
        if (!is_file($this->keyPath)) {
            $this->provision();
        }

        $raw = @file_get_contents($this->keyPath);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('Invalid or unreadable master key.');
        }

        return $raw;
    }

    private function provision(): void
    {
        $dir = dirname($this->keyPath);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create key directory: ' . $dir);
        }
        $key = random_bytes(32);
        $tmp = $this->keyPath . '.tmp';
        if (@file_put_contents($tmp, $key, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write master key.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->keyPath)) {
            @unlink($tmp);

            throw new RuntimeException('Unable to install master key.');
        }
    }
}
