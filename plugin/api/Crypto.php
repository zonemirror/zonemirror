<?php
class Crypto {
  private static function keyPath(): string {
    $home = getenv('HOME') ?: '/root';
    $dir = $home . '/.cf-sync';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $path = $dir . '/.key';
    if (!file_exists($path)) {
      $key = random_bytes(32);
      file_put_contents($path, $key);
      chmod($path, 0600);
    }
    return $path;
  }

  private static function loadKey(): string {
    return file_get_contents(self::keyPath());
  }

  public static function encrypt(string $plaintext): string {
    $key = self::loadKey();
    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
      $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
      $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $key);
      return base64_encode($nonce . $cipher);
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
  }

  public static function decrypt(string $ciphertextB64): string {
    $key = self::loadKey();
    $raw = base64_decode($ciphertextB64);
    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
      $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
      $nonce = substr($raw, 0, $nonceLen);
      $cipher = substr($raw, $nonceLen);
      $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, '', $nonce, $key);
      if ($plain === false) throw new \RuntimeException('Decryption failed');
      return $plain;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new \RuntimeException('Decryption failed');
    return $plain;
  }
}
