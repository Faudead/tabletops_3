<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$uid = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? 'user');

$charId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($charId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing id']);
  exit;
}

$stmt = db()->prepare("SELECT * FROM characters WHERE id=?");
$stmt->execute([$charId]);
$ch = $stmt->fetch();
if (!$ch) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Not found']);
  exit;
}

// access: admin OR owner OR shared
$canEdit = false;

if ($role !== 'admin' && (int)$ch['owner_user_id'] !== $uid) {
    $accSt = $pdo->prepare("SELECT can_edit FROM character_access WHERE character_id=? AND user_id=? LIMIT 1");
    $accSt->execute([$charId, $uid]);
    $acc = $accSt->fetch();
  
    if (!$acc || (int)$acc['can_edit'] !== 1) {
      http_response_code(403);
      echo "Forbidden";
      exit;
    }
  }
  


// 1) characters -> identity частково
// 2) підтаблиці -> state.*
$state = [];

$state['identity'] = [
  'shortName' => (string)$ch['name'],
  'className' => (string)$ch['class_name'],
  'level' => (int)$ch['level'],
  'playerName' => (string)$ch['player_name'],
  'race' => (string)$ch['race'],
  'alignment' => (string)$ch['alignment'],
  'background' => (string)$ch['background'],
  'xp' => (string)$ch['xp'],
  'avatar' => (string)($ch['avatar_data'] ?: $ch['avatar_url'] ?: ''),
];

$st = db()->prepare("SELECT * FROM character_stats WHERE character_id=?");
$st->execute([$charId]);
$row = $st->fetch() ?: [];

$state['stats'] = [
  'STR' => (int)($row['str_score'] ?? 10),
  'DEX' => (int)($row['dex_score'] ?? 10),
  'CON' => (int)($row['con_score'] ?? 10),
  'INT' => (int)($row['int_score'] ?? 10),
  'WIS' => (int)($row['wis_score'] ?? 10),
  'CHA' => (int)($row['cha_score'] ?? 10),
];

$state['saves'] = [
  'STR' => !empty($row['save_str']),
  'DEX' => !empty($row['save_dex']),
  'CON' => !empty($row['save_con']),
  'INT' => !empty($row['save_int']),
  'WIS' => !empty($row['save_wis']),
  'CHA' => !empty($row['save_cha']),
];

$rs = db()->prepare("SELECT * FROM character_resources WHERE character_id=?");
$rs->execute([$charId]);
$r = $rs->fetch() ?: [];

$state['resources'] = [
  'hp' => [
    'current' => (int)($r['hp_current'] ?? 10),
    'max' => (int)($r['hp_max'] ?? 10),
    'temp' => (int)($r['hp_temp'] ?? 0),
  ],
  'proficiencyBonus' => (int)($r['proficiency_bonus'] ?? 2),
  'defense' => [
    'ac' => (int)($r['ac'] ?? 10),
    'passivePerceptionOverride' => isset($r['passive_perception_override'])
      ? (int)$r['passive_perception_override']
      : null,
  ],
  // нове:
  'inspiration' => !empty($r['inspiration']),
  'speed' => (int)($r['speed'] ?? 30),
  // custom пропускаємо як ти сказала
  'custom' => [],
];

$nt = db()->prepare("SELECT * FROM character_notes WHERE character_id=?");
$nt->execute([$charId]);
$n = $nt->fetch() ?: [];

$state['notes'] = [
  'session' => (string)($n['session_notes'] ?? ''),
  'quick' => (string)($n['quick_notes'] ?? ''),
  'backstory' => (string)($n['backstory'] ?? ''),
];

$cc = db()->prepare("SELECT * FROM character_coins WHERE character_id=?");
$cc->execute([$charId]);
$c = $cc->fetch() ?: [];
$state['coins'] = [
  'gp' => (int)($c['gp'] ?? 0),
  'sp' => (int)($c['sp'] ?? 0),
  'cp' => (int)($c['cp'] ?? 0),
];

// skills profs (тільки true)
$state['skillProfs'] = [];
$sk = db()->prepare("SELECT skill_key FROM character_skill_profs WHERE character_id=?");
$sk->execute([$charId]);
foreach ($sk->fetchAll() as $srow) {
  $state['skillProfs'][(string)$srow['skill_key']] = true;
}

// proficiencies
$state['proficiencies'] = ['weapons'=>[], 'tools'=>[], 'armor'=>[], 'languages'=>[], 'transport'=>[]];
$pf = db()->prepare("SELECT prof_type, value FROM character_proficiencies WHERE character_id=? ORDER BY prof_type, sort_order, id");
$pf->execute([$charId]);
foreach ($pf->fetchAll() as $prow) {
  $t = (string)$prow['prof_type'];
  $v = (string)$prow['value'];
  if ($t === 'weapon') $state['proficiencies']['weapons'][] = $v;
  if ($t === 'tool') $state['proficiencies']['tools'][] = $v;
  if ($t === 'armor') $state['proficiencies']['armor'][] = $v;
  if ($t === 'language') $state['proficiencies']['languages'][] = $v;
  if ($t === 'transport') $state['proficiencies']['transport'][] = $v;
}

// inventory
$state['inventory'] = [];
$inv = db()->prepare("SELECT name,equipped,consumable,qty,charges,icon,description FROM character_inventory WHERE character_id=? ORDER BY sort_order, id");
$inv->execute([$charId]);
foreach ($inv->fetchAll() as $irow) {
  $state['inventory'][] = [
    'name' => (string)$irow['name'],
    'equipped' => !empty($irow['equipped']),
    'consumable' => !empty($irow['consumable']),
    'qty' => (int)$irow['qty'],
    'charges' => (int)$irow['charges'],
    'icon' => (string)$irow['icon'],
    'description' => (string)$irow['description'],
  ];
}

// weapons
$state['weapons'] = [];
$wp = db()->prepare("SELECT name,atk,dmg FROM character_weapons WHERE character_id=? ORDER BY sort_order, id");
$wp->execute([$charId]);
foreach ($wp->fetchAll() as $wrow) {
  $state['weapons'][] = [
    'name' => (string)$wrow['name'],
    'atk' => (string)$wrow['atk'],
    'dmg' => (string)$wrow['dmg'],
  ];
}

// spells
$state['spells'] = [];
$sp = db()->prepare("SELECT spell_uid,name,kind,level,charges,used,description FROM character_spells WHERE character_id=? ORDER BY sort_order, id");
$sp->execute([$charId]);
foreach ($sp->fetchAll() as $sprow) {
  $state['spells'][] = [
    'id' => (string)$sprow['spell_uid'],
    'name' => (string)$sprow['name'],
    'kind' => (string)$sprow['kind'],
    'level' => (int)$sprow['level'],
    'charges' => (int)$sprow['charges'],
    'used' => !empty($sprow['used']),
    'description' => (string)$sprow['description'],
  ];
}

// abilities
$state['abilities'] = [];
$ab = db()->prepare("SELECT name,kind,source,description FROM character_abilities WHERE character_id=? ORDER BY sort_order, id");
$ab->execute([$charId]);
foreach ($ab->fetchAll() as $arow) {
  $state['abilities'][] = [
    'name' => (string)$arow['name'],
    'kind' => (string)$arow['kind'],
    'source' => (string)$arow['source'],
    'description' => (string)$arow['description'],
  ];
}

// death saves
$ds = db()->prepare("SELECT fails,successes FROM character_death_saves WHERE character_id=?");
$ds->execute([$charId]);
$d = $ds->fetch() ?: [];
$state['deathSaves'] = [
  'fails' => (int)($d['fails'] ?? 0),
  'successes' => (int)($d['successes'] ?? 0),
];

// UI/meta можна не віддавати з БД — твій ensureDefaults() їх підставить
echo json_encode(['ok' => true, 'state' => $state], JSON_UNESCAPED_UNICODE);
