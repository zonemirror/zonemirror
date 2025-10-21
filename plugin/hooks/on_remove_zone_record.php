#!/usr/bin/php -d detect_unicode=0
<?php
require_once __DIR__ . '/../worker/queue.php';

$payload = json_decode(stream_get_contents(STDIN), true);
$domain = $payload['data']['args']['domain'] ?? null;
$record = [
  'type'    => $payload['data']['result']['data']['type'] ?? null,
  'name'    => $payload['data']['result']['data']['name'] ?? null,
  'content' => $payload['data']['result']['data']['address'] ??
               ($payload['data']['result']['data']['txtdata'] ??
               ($payload['data']['result']['data']['cname'] ?? null)),
  'ttl'     => $payload['data']['result']['data']['ttl'] ?? 300,
  'meta'    => $payload['data']['result']['data'] ?? []
];

if (!$domain || !$record['type']) {
  error_log('[cf-sync] missing domain/type in remove hook');
  exit(0);
}

Queue::enqueue($domain, 'DELETE', $record);
exit(0);
