<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

$user = require_admin();
$pdo = db();

$charId = (int)($_POST['character_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);

if ($charId <= 0 || $userId <= 0) {
  http_response_code(400);
  echo "Bad request";
  exit;
}

$st = $pdo->prepare("DELETE FROM character_access WHERE character_id=? AND user_id=?");
$st->execute([$charId, $userId]);

header("Location: /character.php?id=" . $charId);
exit;
