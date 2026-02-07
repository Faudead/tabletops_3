<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

try {
  $pdo = db();
  $v = $pdo->query("SELECT VERSION() AS v")->fetch();
  echo "DB OK. Version: " . htmlspecialchars((string)($v['v'] ?? 'unknown'));
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB ERROR: " . htmlspecialchars($e->getMessage());
}
