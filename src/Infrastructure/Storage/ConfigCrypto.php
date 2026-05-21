<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;

/**
 * Authenticated symmetric encryption for at-rest secrets (Cloudflare API
 * tokens). Prefers XChaCha20-Poly1305 from libsodium and falls back to
 * AES-256-GCM via OpenSSL. Ciphertext is base64 with a 1-byte version prefix
 * inside the binary blob so the algorithm can be rotated later without
 * breaking existing configs.
 */
final class ConfigCrypto
{
    private const VERSION_SODIUM = 1;
    private const VERSION_OPENSSL = 2;

    public function __construct(private readonly KeyStore $keyStore)
    {
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->keyStore->load();
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $key);

            return base64_encode(chr(self::VERSION_SODIUM) . $nonce . $cipher);
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('OpenSSL encryption failed.');
        }

        return base64_encode(chr(self::VERSION_OPENSSL) . $iv . $tag . $cipher);
    }

    public function decrypt(string $ciphertextB64): string
    {
        $raw = base64_decode($ciphertextB64, true);
        if ($raw === false || strlen($raw) < 2) {
            throw new RuntimeException('Malformed ciphertext.');
        }

        $version = ord($raw[0]);
        $payload = substr($raw, 1);
        $key = $this->keyStore->load();

        if ($version === self::VERSION_SODIUM) {
            $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $nonce = substr($payload, 0, $nonceLen);
            $cipher = substr($payload, $nonceLen);
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, '', $nonce, $key);
            if ($plain === false) {
                throw new RuntimeException('Decryption failed (sodium).');
            }

            return $plain;
        }

        if ($version === self::VERSION_OPENSSL) {
            $iv = substr($payload, 0, 12);
            $tag = substr($payload, 12, 16);
            $cipher = substr($payload, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain === false) {
                throw new RuntimeException('Decryption failed (openssl).');
            }

            return $plain;
        }

        throw new RuntimeException('Unsupported ciphertext version: ' . $version);
    }
}
