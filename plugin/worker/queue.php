<?php
class Queue {
  private static function dbPath(string $user = null): string {
    $home = getenv('HOME') ?: '/root';
    if ($user && $user !== 'root') {
      $home = "/home/$user";
    }
    $dir = $home . '/.cf-sync';
    if (!is_dir($dir)) {
      mkdir($dir, 0700, true);
    }
    $db = $dir . '/events.sqlite';
    if (!file_exists($db)) {
      touch($db);
      chmod($db, 0600);
    }
    return $db;
  }

  private static function pdo(): \PDO {
    $db = self::dbPath();
    $pdo = new \PDO('sqlite:' . $db);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    self::migrate($pdo);
    return $pdo;
  }

  private static function migrate(\PDO $pdo): void {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS events(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL,
        action TEXT NOT NULL,
        record TEXT NOT NULL,
        attempts INTEGER DEFAULT 0,
        next_run_at INTEGER DEFAULT (strftime('%s','now'))
      );"
    );
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_ready ON events(next_run_at);");
  }

  public static function enqueue(string $domain, string $action, array $record): void {
    $pdo = self::pdo();
    $stmt = $pdo->prepare('INSERT INTO events(domain, action, record) VALUES(?,?,?)');
    $stmt->execute([$domain, $action, json_encode($record)]);
  }

  public static function getNext(): ?array {
    $pdo = self::pdo();
    $pdo->beginTransaction();
    try {
      $now = time();
      $row = $pdo->query("SELECT * FROM events WHERE next_run_at <= $now ORDER BY id LIMIT 1 FOR UPDATE")->fetch(\PDO::FETCH_ASSOC);
      if (!$row) { $pdo->commit(); return null; }
      // Lock by updating next_run_at slightly in the future
      $stmt = $pdo->prepare('UPDATE events SET next_run_at = ? WHERE id = ?');
      $stmt->execute([$now + 60, $row['id']]);
      $pdo->commit();
      $row['record'] = json_decode($row['record'], true);
      return $row;
    } catch (\Throwable $e) {
      $pdo->rollBack();
      return null;
    }
  }

  public static function ack(int $id): void {
    $pdo = self::pdo();
    $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
    $stmt->execute([$id]);
  }

  public static function retryOrDeadLetter(array $evt, string $reason): void {
    $pdo = self::pdo();
    $attempts = (int)$evt['attempts'] + 1;
    $backoffs = [1, 3, 9, 27, 81];
    $delay = $backoffs[min($attempts - 1, count($backoffs) - 1)];
    $next = time() + $delay;
    $stmt = $pdo->prepare('UPDATE events SET attempts = ?, next_run_at = ? WHERE id = ?');
    $stmt->execute([$attempts, $next, $evt['id']]);
    error_log('[cf-sync] retry ' . $evt['id'] . " attempts=$attempts delay=$delay reason=$reason");
  }
}
