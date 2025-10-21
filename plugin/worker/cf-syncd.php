<?php
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/../api/CloudflareClient.php';
require_once __DIR__ . '/../api/Mapper.php';
require_once __DIR__ . '/../api/Auth.php';

while (true) {
  $evt = Queue::getNext();
  if (!$evt) { sleep(2); continue; }

  try {
    $cfg = Auth::loadConfigForDomain($evt['domain']);
    if (empty($cfg['token']) || empty($cfg['zone_id']) || empty($cfg['enabled'])) {
      throw new \RuntimeException('Domain not configured or disabled');
    }
    $cf = new CloudflareClient($cfg['token']);
    $zid = $cfg['zone_id'];

    if ($evt['action'] === 'UPSERT') {
      $cfRec = Mapper::toCloudflareRecord($evt['record'], $cfg);
      $cf->upsertRecord($zid, $cfRec);
    } elseif ($evt['action'] === 'DELETE') {
      $cf->deleteRecordByKey($zid, $evt['record']);
    }

    Queue::ack((int)$evt['id']);
  } catch (\Throwable $e) {
    $code = 0;
    $msg = $e->getMessage();
    if (preg_match('/429|5\\d{2}/', $msg)) {
      // Backoff handled by queue
    }
    error_log('[cf-sync] worker error evt=' . ($evt['id'] ?? 'n/a') . ' ' . $msg);
    Queue::retryOrDeadLetter($evt, $msg);
  }
}
