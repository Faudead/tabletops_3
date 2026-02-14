<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

require_login();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? 'user');

$charId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($charId <= 0) { http_response_code(400); echo "Missing id"; exit; }

$pdo = db();

$stmt = $pdo->prepare("SELECT id, owner_user_id FROM characters WHERE id=?");
$stmt->execute([$charId]);
$ch = $stmt->fetch();
if (!$ch) { http_response_code(404); echo "Not found"; exit; }
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
  


function post_str(string $k): string { return trim((string)($_POST[$k] ?? '')); }
function post_int(string $k, int $def=0): int {
  $v = $_POST[$k] ?? null;
  if ($v === null || $v === '') return $def;
  return (int)$v;
}
function post_bool(string $k): int { return isset($_POST[$k]) ? 1 : 0; }

$pdo->beginTransaction();
try {
  // characters
  $pdo->prepare("UPDATE characters
                 SET name=?, class_name=?, level=?, race=?, alignment=?, background=?, xp=?, player_name=?, avatar_url=?, avatar_data=?
                 WHERE id=?")
      ->execute([
        post_str('name'),
        post_str('class_name'),
        post_int('level', 1),
        post_str('race'),
        post_str('alignment'),
        post_str('background'),
        post_str('xp'),
        post_str('player_name'),
        ($_POST['avatar_url'] ?? null),
        ($_POST['avatar_data'] ?? null),
        $charId
      ]);

  // resources
  $ppoRaw = ($_POST['passive_perception_override'] ?? '');
  $ppo = (trim((string)$ppoRaw) === '') ? null : (int)$ppoRaw;

  $pdo->prepare("UPDATE character_resources
                 SET hp_current=?, hp_max=?, hp_temp=?, proficiency_bonus=?, ac=?, passive_perception_override=?, inspiration=?, speed=?
                 WHERE character_id=?")
      ->execute([
        post_int('hp_current', 10),
        post_int('hp_max', 10),
        post_int('hp_temp', 0),
        post_int('proficiency_bonus', 2),
        post_int('ac', 10),
        $ppo,
        post_bool('inspiration'),
        post_int('speed', 30),
        $charId
      ]);

  // stats + saves
  $pdo->prepare("UPDATE character_stats
                 SET str_score=?, dex_score=?, con_score=?, int_score=?, wis_score=?, cha_score=?,
                     save_str=?, save_dex=?, save_con=?, save_int=?, save_wis=?, save_cha=?
                 WHERE character_id=?")
      ->execute([
        post_int('str_score',10), post_int('dex_score',10), post_int('con_score',10),
        post_int('int_score',10), post_int('wis_score',10), post_int('cha_score',10),
        post_bool('save_str'), post_bool('save_dex'), post_bool('save_con'),
        post_bool('save_int'), post_bool('save_wis'), post_bool('save_cha'),
        $charId
      ]);

  // coins
  $pdo->prepare("UPDATE character_coins SET gp=?, sp=?, cp=? WHERE character_id=?")
      ->execute([post_int('gp',0), post_int('sp',0), post_int('cp',0), $charId]);

  // deletes for lists (inventory/weapons/spells/abilities)
  $delInv = $_POST['inv_delete'] ?? [];
  if (is_array($delInv) && $delInv) {
    $in = implode(',', array_fill(0, count($delInv), '?'));
    $args = array_map('intval', $delInv);
    $args[] = $charId;
    $pdo->prepare("DELETE FROM character_inventory WHERE id IN ($in) AND character_id=?")->execute($args);
  }

  $delWp = $_POST['wp_delete'] ?? [];
  if (is_array($delWp) && $delWp) {
    $in = implode(',', array_fill(0, count($delWp), '?'));
    $args = array_map('intval', $delWp);
    $args[] = $charId;
    $pdo->prepare("DELETE FROM character_weapons WHERE id IN ($in) AND character_id=?")->execute($args);
  }

  $delSp = $_POST['sp_delete'] ?? [];
  if (is_array($delSp) && $delSp) {
    $in = implode(',', array_fill(0, count($delSp), '?'));
    $args = array_map('intval', $delSp);
    $args[] = $charId;
    $pdo->prepare("DELETE FROM character_spells WHERE id IN ($in) AND character_id=?")->execute($args);
  }

  $delAb = $_POST['ab_delete'] ?? [];
  if (is_array($delAb) && $delAb) {
    $in = implode(',', array_fill(0, count($delAb), '?'));
    $args = array_map('intval', $delAb);
    $args[] = $charId;
    $pdo->prepare("DELETE FROM character_abilities WHERE id IN ($in) AND character_id=?")->execute($args);
  }

  // updates for list rows (only existing)
  // inventory
  $inv_id = $_POST['inv_id'] ?? [];
  $inv_name = $_POST['inv_name'] ?? [];
  $inv_qty = $_POST['inv_qty'] ?? [];
  $inv_ch = $_POST['inv_charges'] ?? [];
  $inv_icon = $_POST['inv_icon'] ?? [];
  $inv_desc = $_POST['inv_desc'] ?? [];
  foreach ($inv_id as $i => $id) {
    $id = (int)$id;
    $equipped = isset($_POST['inv_equipped'][$i]) ? 1 : 0;
    $consumable = isset($_POST['inv_consumable'][$i]) ? 1 : 0;
    $pdo->prepare("UPDATE character_inventory
                   SET name=?, equipped=?, consumable=?, qty=?, charges=?, icon=?, description=?
                   WHERE id=? AND character_id=?")
        ->execute([
          (string)($inv_name[$i] ?? ''),
          $equipped,
          $consumable,
          (int)($inv_qty[$i] ?? 1),
          (int)($inv_ch[$i] ?? 0),
          (string)($inv_icon[$i] ?? ''),
          (string)($inv_desc[$i] ?? ''),
          $id,
          $charId
        ]);
  }

  // weapons
  $wp_id = $_POST['wp_id'] ?? [];
  $wp_name = $_POST['wp_name'] ?? [];
  $wp_atk = $_POST['wp_atk'] ?? [];
  $wp_dmg = $_POST['wp_dmg'] ?? [];
  foreach ($wp_id as $i => $id) {
    $pdo->prepare("UPDATE character_weapons SET name=?, atk=?, dmg=? WHERE id=? AND character_id=?")
        ->execute([
          (string)($wp_name[$i] ?? ''),
          (string)($wp_atk[$i] ?? ''),
          (string)($wp_dmg[$i] ?? ''),
          (int)$id,
          $charId
        ]);
  }

  // spells
  $sp_id = $_POST['sp_id'] ?? [];
  $sp_uid = $_POST['sp_uid'] ?? [];
  $sp_name = $_POST['sp_name'] ?? [];
  $sp_kind = $_POST['sp_kind'] ?? [];
  $sp_level = $_POST['sp_level'] ?? [];
  $sp_charges = $_POST['sp_charges'] ?? [];
  $sp_desc = $_POST['sp_desc'] ?? [];
  $sp_used = $_POST['sp_used'] ?? []; // array of ids
  $usedSet = [];
  if (is_array($sp_used)) foreach ($sp_used as $sid) $usedSet[(int)$sid] = true;

  foreach ($sp_id as $i => $id) {
    $id = (int)$id;
    $pdo->prepare("UPDATE character_spells
                   SET spell_uid=?, name=?, kind=?, level=?, charges=?, used=?, description=?
                   WHERE id=? AND character_id=?")
        ->execute([
          (string)($sp_uid[$i] ?? ''),
          (string)($sp_name[$i] ?? ''),
          (($sp_kind[$i] ?? 'spell') === 'cantrip') ? 'cantrip' : 'spell',
          (int)($sp_level[$i] ?? 1),
          (int)($sp_charges[$i] ?? 0),
          isset($usedSet[$id]) ? 1 : 0,
          (string)($sp_desc[$i] ?? ''),
          $id,
          $charId
        ]);
  }

  // abilities
  $ab_id = $_POST['ab_id'] ?? [];
  $ab_name = $_POST['ab_name'] ?? [];
  $ab_kind = $_POST['ab_kind'] ?? [];
  $ab_source = $_POST['ab_source'] ?? [];
  $ab_desc = $_POST['ab_desc'] ?? [];
  foreach ($ab_id as $i => $id) {
    $pdo->prepare("UPDATE character_abilities
                   SET name=?, kind=?, source=?, description=?
                   WHERE id=? AND character_id=?")
        ->execute([
          (string)($ab_name[$i] ?? ''),
          (string)($ab_kind[$i] ?? ''),
          (string)($ab_source[$i] ?? ''),
          (string)($ab_desc[$i] ?? ''),
          (int)$id,
          $charId
        ]);
  }

  $pdo->commit();
  header("Location: /character.php?id=" . $charId);
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "Save failed: " . htmlspecialchars($e->getMessage());
}
