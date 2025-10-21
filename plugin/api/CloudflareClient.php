<?php
class CloudflareClient {
  private string $token;
  public function __construct(string $token) { $this->token = $token; }

  private function request(string $method, string $path, array $query = [], ?array $body = null): array {
    $url = 'https://api.cloudflare.com/client/v4' . $path;
    if (!empty($query)) { $url .= '?' . http_build_query($query); }
    $ch = curl_init($url);
    $headers = [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $this->token,
      'User-Agent: cf-sync/1.0'
    ];
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_TIMEOUT => 20
    ]);
    if ($body !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
      return [$code ?: 0, ['success' => false, 'errors' => [['message' => $err]]]];
    }
    return [$code, json_decode($resp, true) ?: []];
  }

  public function findZoneId(string $zoneName): ?string {
    [$code, $json] = $this->request('GET', '/zones', ['name' => $zoneName]);
    if ($code === 200 && !empty($json['result'][0]['id'])) return $json['result'][0]['id'];
    return null;
  }

  public function listRecords(string $zoneId, array $query = []): array {
    [$code, $json] = $this->request('GET', "/zones/$zoneId/dns_records", array_merge(['per_page' => 500], $query));
    return $code === 200 ? ($json['result'] ?? []) : [];
  }

  public function upsertRecord(string $zoneId, array $rec): void {
    $existing = $this->findEquivalent($zoneId, $rec);
    if ($existing) {
      $this->request('PUT', "/zones/$zoneId/dns_records/{$existing['id']}", [], $rec);
    } else {
      $this->request('POST', "/zones/$zoneId/dns_records", [], $rec);
    }
  }

  private function findEquivalent(string $zoneId, array $rec): ?array {
    $list = $this->listRecords($zoneId, ['type' => $rec['type'] ?? null, 'name' => $rec['name'] ?? null]);
    foreach ($list as $r) {
      if ($this->equiv($r, $rec)) return $r;
    }
    return null;
  }

  private function equiv(array $a, array $b): bool {
    $keyA = strtoupper($a['type'] ?? '') . '|' . strtolower($a['name'] ?? '');
    $keyB = strtoupper($b['type'] ?? '') . '|' . strtolower($b['name'] ?? '');
    if ($keyA !== $keyB) return false;
    if (isset($b['content']) && (($a['content'] ?? null) !== $b['content'])) return false;
    if (isset($b['priority']) && (($a['priority'] ?? null) !== $b['priority'])) return false;
    return true;
  }

  public function deleteRecordByKey(string $zoneId, array $key): void {
    $list = $this->listRecords($zoneId, ['type' => $key['type'] ?? null, 'name' => $key['name'] ?? null]);
    foreach ($list as $r) {
      if ($this->equiv($r, $key)) {
        $this->request('DELETE', "/zones/$zoneId/dns_records/{$r['id']}");
      }
    }
  }
}
