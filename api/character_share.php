<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

$user = require_admin();
$pdo = db();

$charId = (int)($_POST['character_id'] ?? 0);
$username = trim((string)($_POST['username'] ?? ''));
$canEdit = isset($_POST['can_edit']) ? 1 : 0;

if ($charId <= 0 || $username === '') {
  http_response_code(400);
  echo "Bad request";
  exit;
}

$st = $pdo->prepare("SELECT id FROM characters WHERE id=? LIMIT 1");
$st->execute([$charId]);
if (!$st->fetch()) {
  http_response_code(404);
  echo "Character not found";
  exit;
}

$st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
$st->execute([$username]);
$u = $st->fetch();
if (!$u) {
  http_response_code(404);
  echo "User not found";
  exit;
}
$targetUserId = (int)$u['id'];

// upsert доступ
$st = $pdo->prepare("
  INSERT INTO character_access (character_id, user_id, can_edit)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE can_edit=VALUES(can_edit)
");
$st->execute([$charId, $targetUserId, $canEdit]);

header("Location: /character.php?id=" . $charId);
exit;
