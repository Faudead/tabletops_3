<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

require_login();
$user = current_user();
$uid  = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? 'user');

header('Content-Type: application/json; charset=utf-8');

function json_fail(string $msg, int $code = 400): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function json_ok(array $payload): void {
  echo json_encode(array_merge(['ok' => true], $payload), JSON_UNESCAPED_UNICODE);
  exit;
}

$pdo = db();

$charId = 0;
if (isset($_GET['id']) && $_GET['id'] !== '') $charId = (int)$_GET['id'];
elseif (isset($_POST['id']) && $_POST['id'] !== '') $charId = (int)$_POST['id'];

if ($charId <= 0) json_fail('Missing id', 400);

// --- load character ---
$st = $pdo->prepare("SELECT * FROM characters WHERE id=?");
$st->execute([$charId]);
$ch = $st->fetch();
if (!$ch) json_fail('Not found', 404);

// --- access check ---
$canView = false;
$canEdit = false;

if ($role === 'admin') {
  $canView = true;
  $canEdit = true;
} elseif ((int)$ch['owner_user_id'] === $uid) {
  $canView = true;
  $canEdit = true;
} else {
  $accSt = $pdo->prepare("
    SELECT can_edit
    FROM character_access
    WHERE character_id=? AND user_id=?
    LIMIT 1
  ");
  $accSt->execute([$charId, $uid]);
  $acc = $accSt->fetch();
  if (!$acc) json_fail('Forbidden', 403);
  $canView = true;
  $canEdit = ((int)$acc['can_edit'] === 1);
}

// --- stats/resources/coins ---
$st2 = $pdo->prepare("SELECT * FROM character_stats WHERE character_id=?");
$st2->execute([$charId]);
$stats = $st2->fetch() ?: [];

$st3 = $pdo->prepare("SELECT * FROM character_resources WHERE character_id=?");
$st3->execute([$charId]);
$res = $st3->fetch() ?: [];

$st4 = $pdo->prepare("SELECT * FROM character_coins WHERE character_id=?");
$st4->execute([$charId]);
$coins = $st4->fetch() ?: [];

// --- lists ---
$invSt = $pdo->prepare("SELECT * FROM character_inventory WHERE character_id=? ORDER BY sort_order,id");
$invSt->execute([$charId]);
$inventory = $invSt->fetchAll();

$wpSt = $pdo->prepare("SELECT * FROM character_weapons WHERE character_id=? ORDER BY sort_order,id");
$wpSt->execute([$charId]);
$weapons = $wpSt->fetchAll();

$spSt = $pdo->prepare("SELECT * FROM character_spells WHERE character_id=? ORDER BY sort_order,id");
$spSt->execute([$charId]);
$spells = $spSt->fetchAll();

$abSt = $pdo->prepare("SELECT * FROM character_abilities WHERE character_id=? ORDER BY sort_order,id");
$abSt->execute([$charId]);
$abilities = $abSt->fetchAll();

// --- notes (optional table) ---
$notes = [
  'session_notes' => '',
  'quick_notes'   => '',
  'traits'        => '',
  'ideals'        => '',
  'bonds'         => '',
  'flaws'         => '',
  'backstory'     => '',
];

try {
  $ntSt = $pdo->prepare("
    SELECT session_notes, quick_notes, traits, ideals, bonds, flaws, backstory
    FROM character_notes
    WHERE character_id=?
    LIMIT 1
  ");
  $ntSt->execute([$charId]);
  $row = $ntSt->fetch();
  if ($row) {
    foreach ($notes as $k => $_) {
      if (array_key_exists($k, $row) && $row[$k] !== null) {
        $notes[$k] = (string)$row[$k];
      }
    }
  }
} catch (Throwable $e) {
  // якщо таблиці ще нема — ок
}

// --- shared access list for admin ---
$shared = [];
if ($role === 'admin') {
  $sSt = $pdo->prepare("
    SELECT a.user_id, a.can_edit, u.username
    FROM character_access a
    LEFT JOIN users u ON u.id=a.user_id
    WHERE a.character_id=?
    ORDER BY u.username
  ");
  $sSt->execute([$charId]);
  $shared = $sSt->fetchAll();
}

// --- normalize a few fields for front-end convenience ---
$out = [
  'id' => $charId,
  'access' => [
    'can_view' => $canView,
    'can_edit' => $canEdit,
    'role'     => $role,
  ],
  'character' => [
    'id'            => (int)($ch['id'] ?? 0),
    'owner_user_id' => (int)($ch['owner_user_id'] ?? 0),
    'name'          => (string)($ch['name'] ?? ''),
    'class_name'    => (string)($ch['class_name'] ?? ''),
    'level'         => (int)($ch['level'] ?? 1),
    'race'          => (string)($ch['race'] ?? ''),
    'alignment'     => (string)($ch['alignment'] ?? ''),
    'background'    => (string)($ch['background'] ?? ''),
    'xp'            => (string)($ch['xp'] ?? ''),
    'player_name'   => (string)($ch['player_name'] ?? ''),
    'avatar_url'    => $ch['avatar_url'],
    'avatar_data'   => $ch['avatar_data'],
    'created_at'    => $ch['created_at'] ?? null,
    'updated_at'    => $ch['updated_at'] ?? null,
  ],
  'resources' => $res,
  'stats'     => $stats,
  'coins'     => $coins,
  'inventory' => $inventory,
  'weapons'   => $weapons,
  'spells'    => $spells,
  'abilities' => $abilities,
  'notes'     => $notes,
  'shared'    => $shared,
];

json_ok($out);
