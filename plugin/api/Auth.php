<?php
class Auth {
  private static function configDir(): string {
    $home = getenv('HOME') ?: '/root';
    $dir = $home . '/.cf-sync';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir;
  }

  private static function configPath(): string {
    $path = self::configDir() . '/config.json';
    if (!file_exists($path)) {
      file_put_contents($path, json_encode(new \stdClass()));
      chmod($path, 0600);
    }
    return $path;
  }

  public static function loadConfig(): array {
    $raw = file_get_contents(self::configPath());
    $json = json_decode($raw, true) ?: [];
    return $json;
  }

  public static function loadConfigForDomain(string $domain): array {
    $cfg = self::loadConfig();
    $domainCfg = $cfg[$domain] ?? [];
    if (!empty($domainCfg['token_encrypted'])) {
      try {
        $domainCfg['token'] = Crypto::decrypt($domainCfg['token_encrypted']);
      } catch (\Throwable $e) {
        $domainCfg['token'] = '';
      }
    }
    return $domainCfg;
  }

  public static function saveConfigForDomain(string $domain, array $data): void {
    $cfg = self::loadConfig();
    if (!empty($data['token'])) {
      $data['token_encrypted'] = Crypto::encrypt($data['token']);
      unset($data['token']);
    }
    $cfg[$domain] = $data;
    file_put_contents(self::configPath(), json_encode($cfg, JSON_PRETTY_PRINT));
  }
}
