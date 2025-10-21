<?php
class Mapper {
  public static function toCloudflareRecord(array $cp, array $cfg): array {
    $type = strtoupper($cp['type'] ?? '');
    $name = rtrim($cp['name'] ?? ($cp['meta']['name'] ?? ''), '.');
    $ttl  = (int)($cp['ttl'] ?? 300);
    $base = ['type' => $type, 'name' => $name, 'ttl' => $ttl];

    switch ($type) {
      case 'A':
      case 'AAAA':
      case 'CNAME':
        $base['content'] = $cp['content'] ?? '';
        $base['proxied'] = self::shouldProxy($type, $cp, $cfg);
        break;
      case 'TXT':
        $base['content'] = $cp['content'] ?? '';
        break;
      case 'MX':
        $base['content']  = $cp['content'] ?? '';
        $base['priority'] = (int)($cp['meta']['priority'] ?? 10);
        break;
      case 'SRV':
        $base['data'] = [
          'service'  => $cp['meta']['service'] ?? null,
          'proto'    => $cp['meta']['proto'] ?? null,
          'name'     => $name,
          'priority' => (int)($cp['meta']['priority'] ?? 0),
          'weight'   => (int)($cp['meta']['weight'] ?? 0),
          'port'     => (int)($cp['meta']['port'] ?? 0),
          'target'   => $cp['meta']['target'] ?? null,
        ];
        break;
      case 'CAA':
        $base['data'] = [
          'flags' => (int)($cp['meta']['flags'] ?? 0),
          'tag'   => $cp['meta']['tag'] ?? 'issue',
          'value' => $cp['meta']['value'] ?? ''
        ];
        break;
      case 'NS':
        $base['content'] = $cp['content'] ?? '';
        break;
      default:
        // unsupported type, return base
    }
    return $base;
  }

  private static function shouldProxy(string $type, array $cp, array $cfg): bool {
    if (!in_array($type, ['A','AAAA','CNAME'], true)) return false;
    $name = strtolower($cp['name'] ?? ($cp['meta']['name'] ?? ''));
    if (strpos($name, '_acme-challenge') === 0 || strpos($name, '_dmarc') === 0) return false;
    return (bool)($cfg['defaults']['proxied'] ?? false);
  }
}
