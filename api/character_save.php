<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

require_login();
$user = current_user();
$uid  = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? 'user');

header('Content-Type: application/json; charset=utf-8');

function wants_json(): bool {
  $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
  $xhr    = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
  return (stripos($accept, 'application/json') !== false) || (strtolower($xhr) === 'xmlhttprequest');
}
function json_ok(array $extra = []): void {
  echo json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}
function json_fail(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

$pdo = db();

$charId = 0;
// id може прийти як POST (новий character.php) або як GET (старі форми)
if (isset($_POST['id']) && $_POST['id'] !== '') $charId = (int)$_POST['id'];
elseif (isset($_GET['id']) && $_GET['id'] !== '') $charId = (int)$_GET['id'];

if ($charId <= 0) json_fail("Missing id", 400);

// --- auth / access ---
$st = $pdo->prepare("SELECT id, owner_user_id, level FROM characters WHERE id=?");
$st->execute([$charId]);
$ch = $st->fetch();
if (!$ch) json_fail("Not found", 404);

if ($role !== 'admin' && (int)$ch['owner_user_id'] !== $uid) {
  $accSt = $pdo->prepare("SELECT can_edit FROM character_access WHERE character_id=? AND user_id=? LIMIT 1");
  $accSt->execute([$charId, $uid]);
  $acc = $accSt->fetch();
  if (!$acc || (int)$acc['can_edit'] !== 1) json_fail("Forbidden", 403);
}

// --- helpers ---
function post_has(string $k): bool { return array_key_exists($k, $_POST); }
function post_str(string $k, string $def=''): string { return trim((string)($_POST[$k] ?? $def)); }
function post_int(string $k, int $def=0): int {
  $v = $_POST[$k] ?? null;
  if ($v === null || $v === '') return $def;
  return (int)$v;
}
function post_null_int(string $k): ?int {
  if (!array_key_exists($k, $_POST)) return null;
  $v = trim((string)($_POST[$k] ?? ''));
  return ($v === '') ? null : (int)$v;
}

/**
 * Дістає масив з POST по одному з можливих ключів.
 * Напр. inv_id[] (старий) або item_id[] (новий).
 */
function post_arr(string ...$keys): array {
  foreach ($keys as $k) {
    if (isset($_POST[$k]) && is_array($_POST[$k])) return $_POST[$k];
  }
  return [];
}

/**
 * Нормалізує checkbox-масиви типу prepared/equipped які приходять як:
 * - або масив значень
 * - або індексний масив (де індекс = позиція рядка)
 */
function has_indexed_checkbox(array $src, int $i): bool {
  if (!$src) return false;
  // якщо прийшло як [0=>1, 2=>1...] — тоді індекси
  if (array_key_exists($i, $src)) return true;
  // якщо прийшло як [id, id, id] — тоді значення
  return false;
}

$pdo->beginTransaction();

try {
  // поточні ресурси (щоб не затерти поля, які не прислали)
  $curResSt = $pdo->prepare("SELECT * FROM character_resources WHERE character_id=?");
  $curResSt->execute([$charId]);
  $curRes = $curResSt->fetch() ?: [];

  // ----------------------------
  // 1) characters
  // ----------------------------
  $pdo->prepare("
    UPDATE characters
      SET name=?, class_name=?, level=?, race=?, alignment=?, background=?, xp=?, player_name=?, avatar_url=?, avatar_data=?
    WHERE id=?
  ")->execute([
    post_str('name'),
    post_str('class_name'),
    post_int('level', (int)($ch['level'] ?? 1)),
    post_str('race'),
    post_str('alignment'),
    post_str('background'),
    post_str('xp'),
    post_str('player_name'),
    ($_POST['avatar_url'] ?? null),
    ($_POST['avatar_data'] ?? null),
    $charId
  ]);

  // ----------------------------
  // 2) resources
  // Підтримуємо і старі ключі (hp_temp), і нові (temp_hp)
  // ----------------------------
  $hpCurrent = post_has('hp_current') ? post_int('hp_current', (int)($curRes['hp_current'] ?? 10)) : (int)($curRes['hp_current'] ?? 10);
  $hpMax     = post_has('hp_max')     ? post_int('hp_max',     (int)($curRes['hp_max'] ?? 10))     : (int)($curRes['hp_max'] ?? 10);

  $hpTemp = null;
  if (post_has('hp_temp'))      $hpTemp = post_int('hp_temp', (int)($curRes['hp_temp'] ?? 0));
  elseif (post_has('temp_hp'))  $hpTemp = post_int('temp_hp', (int)($curRes['hp_temp'] ?? 0));
  else                          $hpTemp = (int)($curRes['hp_temp'] ?? 0);

  $pb = post_has('proficiency_bonus')
    ? post_int('proficiency_bonus', (int)($curRes['proficiency_bonus'] ?? 2))
    : (int)($curRes['proficiency_bonus'] ?? 2);

  $ac = post_has('ac')
    ? post_int('ac', (int)($curRes['ac'] ?? 10))
    : (int)($curRes['ac'] ?? 10);

  // passive perception override: старий ключ passive_perception_override, новий просто passive_perception
  $ppo = null;
  if (post_has('passive_perception_override')) $ppo = post_null_int('passive_perception_override');
  elseif (post_has('passive_perception'))      $ppo = post_null_int('passive_perception');
  // якщо взагалі не прислали — залишаємо як є
  else $ppo = $curRes['passive_perception_override'] ?? null;

  $speed = post_has('speed') ? post_int('speed', 30) : (int)($curRes['speed'] ?? 30);
  $inspiration = post_has('inspiration') ? (isset($_POST['inspiration']) ? 1 : 0) : (int)($curRes['inspiration'] ?? 0);

  $pdo->prepare("
    UPDATE character_resources
      SET hp_current=?, hp_max=?, hp_temp=?, proficiency_bonus=?, ac=?, passive_perception_override=?, inspiration=?, speed=?
    WHERE character_id=?
  ")->execute([$hpCurrent, $hpMax, $hpTemp, $pb, $ac, $ppo, $inspiration, $speed, $charId]);

  // ----------------------------
  // 3) stats
  // Старий варіант: str_score.. cha_score
  // Новий: stat_str.. stat_cha
  // ----------------------------
  $str = post_has('str_score') ? post_int('str_score', 10) : post_int('stat_str', 10);
  $dex = post_has('dex_score') ? post_int('dex_score', 10) : post_int('stat_dex', 10);
  $con = post_has('con_score') ? post_int('con_score', 10) : post_int('stat_con', 10);
  $int = post_has('int_score') ? post_int('int_score', 10) : post_int('stat_int', 10);
  $wis = post_has('wis_score') ? post_int('wis_score', 10) : post_int('stat_wis', 10);
  $cha = post_has('cha_score') ? post_int('cha_score', 10) : post_int('stat_cha', 10);

  // saves: якщо їх немає у формі — не чіпаємо (в старій формі вони були)
  $curStatsSt = $pdo->prepare("SELECT * FROM character_stats WHERE character_id=?");
  $curStatsSt->execute([$charId]);
  $curStats = $curStatsSt->fetch() ?: [];

  $save_str = post_has('save_str') ? (isset($_POST['save_str']) ? 1 : 0) : (int)($curStats['save_str'] ?? 0);
  $save_dex = post_has('save_dex') ? (isset($_POST['save_dex']) ? 1 : 0) : (int)($curStats['save_dex'] ?? 0);
  $save_con = post_has('save_con') ? (isset($_POST['save_con']) ? 1 : 0) : (int)($curStats['save_con'] ?? 0);
  $save_int = post_has('save_int') ? (isset($_POST['save_int']) ? 1 : 0) : (int)($curStats['save_int'] ?? 0);
  $save_wis = post_has('save_wis') ? (isset($_POST['save_wis']) ? 1 : 0) : (int)($curStats['save_wis'] ?? 0);
  $save_cha = post_has('save_cha') ? (isset($_POST['save_cha']) ? 1 : 0) : (int)($curStats['save_cha'] ?? 0);

  $pdo->prepare("
    UPDATE character_stats
      SET str_score=?, dex_score=?, con_score=?, int_score=?, wis_score=?, cha_score=?,
          save_str=?, save_dex=?, save_con=?, save_int=?, save_wis=?, save_cha=?
    WHERE character_id=?
  ")->execute([$str,$dex,$con,$int,$wis,$cha,$save_str,$save_dex,$save_con,$save_int,$save_wis,$save_cha,$charId]);

  // ----------------------------
  // 4) coins
  // character_coins у твоєму бекенді (в поточному save) має gp/sp/cp.
  // Нова форма шле coin_gp, coin_sp, coin_cp — підтримуємо обидва.
  // ----------------------------
  $gp = post_has('gp') ? post_int('gp',0) : post_int('coin_gp', 0);
  $sp = post_has('sp') ? post_int('sp',0) : post_int('coin_sp', 0);
  $cp = post_has('cp') ? post_int('cp',0) : post_int('coin_cp', 0);

  $pdo->prepare("UPDATE character_coins SET gp=?, sp=?, cp=? WHERE character_id=?")
      ->execute([$gp, $sp, $cp, $charId]);

  // ----------------------------
  // 5) character_notes (ОСНОВНЕ!)
  // UPSERT. Якщо таблиці ще нема — не валимо save, але повернемо warning.
  // ----------------------------
  $notesWarn = null;
  try {
    // якщо хоч один з ключів є — пишемо
    $hasAnyNotes =
      post_has('session_notes') || post_has('quick_notes') || post_has('traits') || post_has('ideals') ||
      post_has('bonds') || post_has('flaws') || post_has('backstory');

    if ($hasAnyNotes) {
      $pdo->prepare("
        INSERT INTO character_notes
          (character_id, session_notes, quick_notes, traits, ideals, bonds, flaws, backstory)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          session_notes=VALUES(session_notes),
          quick_notes=VALUES(quick_notes),
          traits=VALUES(traits),
          ideals=VALUES(ideals),
          bonds=VALUES(bonds),
          flaws=VALUES(flaws),
          backstory=VALUES(backstory)
      ")->execute([
        $charId,
        (string)($_POST['session_notes'] ?? ''),
        (string)($_POST['quick_notes'] ?? ''),
        (string)($_POST['traits'] ?? ''),
        (string)($_POST['ideals'] ?? ''),
        (string)($_POST['bonds'] ?? ''),
        (string)($_POST['flaws'] ?? ''),
        (string)($_POST['backstory'] ?? ''),
      ]);
    }
  } catch (Throwable $e) {
    // таблиці може ще не бути — це не повинно ламати збереження листа
    $notesWarn = 'character_notes not saved: ' . $e->getMessage();
  }

  // ----------------------------
  // 6) Inventory sync (підтримка старих inv_* та нових item_*)
  // Важливо: якщо список прийшов — синхронізуємо (видаляємо відсутні)
  // ----------------------------
  $inv_id   = post_arr('inv_id', 'item_id');
  $inv_name = post_arr('inv_name', 'item_name');
  $inv_qty  = post_arr('inv_qty', 'item_qty');
  $inv_desc = post_arr('inv_desc', 'item_notes'); // нове item_notes мапимо в description

  if ($inv_id || $inv_name || $inv_qty || $inv_desc) {
    $postedIds = [];
    foreach ($inv_id as $raw) { $id = (int)$raw; if ($id > 0) $postedIds[] = $id; }

    // delete missing
    if ($postedIds) {
      $in = implode(',', array_fill(0, count($postedIds), '?'));
      $args = $postedIds;
      $args[] = $charId;
      $pdo->prepare("DELETE FROM character_inventory WHERE character_id=? AND id NOT IN ($in)")
          ->execute(array_merge([$charId], $postedIds));
    } else {
      // якщо прислали список, але там все нове/порожнє — чистимо таблицю
      $pdo->prepare("DELETE FROM character_inventory WHERE character_id=?")->execute([$charId]);
    }

    // update/insert
    foreach ($inv_id as $i => $idRaw) {
      $id   = (int)$idRaw;
      $name = (string)($inv_name[$i] ?? '');
      $qty  = (int)($inv_qty[$i] ?? 1);
      $desc = (string)($inv_desc[$i] ?? '');

      // старі чекбокси inv_equipped/inv_consumable могли бути, у новій формі їх нема — лишаємо 0
      $equipped   = (isset($_POST['inv_equipped']) && is_array($_POST['inv_equipped']) && has_indexed_checkbox($_POST['inv_equipped'], $i)) ? 1 : 0;
      $consumable = (isset($_POST['inv_consumable']) && is_array($_POST['inv_consumable']) && has_indexed_checkbox($_POST['inv_consumable'], $i)) ? 1 : 0;

      if ($id > 0) {
        $pdo->prepare("
          UPDATE character_inventory
            SET name=?, equipped=?, consumable=?, qty=?, description=?
          WHERE id=? AND character_id=?
        ")->execute([$name, $equipped, $consumable, $qty, $desc, $id, $charId]);
      } else {
        $hasData = (trim($name) !== '' || trim($desc) !== '' || $qty !== 1);
        if ($hasData) {
          $pdo->prepare("
            INSERT INTO character_inventory (character_id, name, equipped, consumable, qty, description, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, 0)
          ")->execute([$charId, $name, $equipped, $consumable, $qty, $desc]);
        }
      }
    }
  }

  // ----------------------------
  // 7) Weapons sync (старі wp_* та нові weapon_*)
  // ----------------------------
  $wp_id   = post_arr('wp_id', 'weapon_id');
  $wp_name = post_arr('wp_name', 'weapon_name');
  $wp_atk  = post_arr('wp_atk', 'weapon_attack_bonus');
  $wp_dmg  = post_arr('wp_dmg', 'weapon_damage');

  if ($wp_id || $wp_name || $wp_atk || $wp_dmg) {
    $postedIds = [];
    foreach ($wp_id as $raw) { $id = (int)$raw; if ($id > 0) $postedIds[] = $id; }

    if ($postedIds) {
      $in = implode(',', array_fill(0, count($postedIds), '?'));
      $pdo->prepare("DELETE FROM character_weapons WHERE character_id=? AND id NOT IN ($in)")
          ->execute(array_merge([$charId], $postedIds));
    } else {
      $pdo->prepare("DELETE FROM character_weapons WHERE character_id=?")->execute([$charId]);
    }

    foreach ($wp_id as $i => $idRaw) {
      $id   = (int)$idRaw;
      $name = (string)($wp_name[$i] ?? '');
      $atk  = (string)($wp_atk[$i] ?? '');
      $dmg  = (string)($wp_dmg[$i] ?? '');

      if ($id > 0) {
        $pdo->prepare("UPDATE character_weapons SET name=?, atk=?, dmg=? WHERE id=? AND character_id=?")
            ->execute([$name, $atk, $dmg, $id, $charId]);
      } else {
        $hasData = (trim($name) !== '' || trim($atk) !== '' || trim($dmg) !== '');
        if ($hasData) {
          $pdo->prepare("INSERT INTO character_weapons (character_id, name, atk, dmg, sort_order) VALUES (?, ?, ?, ?, 0)")
              ->execute([$charId, $name, $atk, $dmg]);
        }
      }
    }
  }

  // ----------------------------
  // 8) Spells sync (старі sp_* та нові spell_*)
  // Примітка: твій DB-шар зараз про spell_uid/kind/charges/used/description.
  // Нова форма має school/prepared/notes — мапимо у description.
  // ----------------------------
  $sp_id    = post_arr('sp_id', 'spell_id');
  $sp_uid   = post_arr('sp_uid'); // у новій формі цього нема
  $sp_name  = post_arr('sp_name', 'spell_name');
  $sp_kind  = post_arr('sp_kind'); // у новій формі цього нема
  $sp_level = post_arr('sp_level', 'spell_level');
  $sp_desc  = post_arr('sp_desc', 'spell_notes');
  $sp_school= post_arr('spell_school'); // тільки нова

  $sp_used_ids = post_arr('sp_used'); // старий used
  $usedSet = [];
  foreach ($sp_used_ids as $sid) $usedSet[(int)$sid] = true;

  // prepared з нової форми (checkbox) — не те саме що used, але можна хоча б не губити: впишемо в desc
  $sp_prepared = post_arr('spell_prepared');

  if ($sp_id || $sp_name || $sp_level || $sp_desc || $sp_school) {
    $postedIds = [];
    foreach ($sp_id as $raw) { $id = (int)$raw; if ($id > 0) $postedIds[] = $id; }

    if ($postedIds) {
      $in = implode(',', array_fill(0, count($postedIds), '?'));
      $pdo->prepare("DELETE FROM character_spells WHERE character_id=? AND id NOT IN ($in)")
          ->execute(array_merge([$charId], $postedIds));
    } else {
      $pdo->prepare("DELETE FROM character_spells WHERE character_id=?")->execute([$charId]);
    }

    foreach ($sp_id as $i => $idRaw) {
      $id   = (int)$idRaw;
      $uidS = (string)($sp_uid[$i] ?? '');
      $name = (string)($sp_name[$i] ?? '');
      $lvl  = (int)($sp_level[$i] ?? 0);

      $kind = 'spell';
      if (isset($sp_kind[$i]) && (($sp_kind[$i] ?? '') === 'cantrip')) $kind = 'cantrip';

      $school = (string)($sp_school[$i] ?? '');
      $notes  = (string)($sp_desc[$i] ?? '');

      $desc = $notes;
      if ($school !== '') $desc = "School: {$school}\n" . $desc;

      $used = ($id > 0 && isset($usedSet[$id])) ? 1 : 0;

      if ($id > 0) {
        $pdo->prepare("
          UPDATE character_spells
            SET spell_uid=?, name=?, kind=?, level=?, charges=?, used=?, description=?
          WHERE id=? AND character_id=?
        ")->execute([$uidS, $name, $kind, $lvl, 0, $used, $desc, $id, $charId]);
      } else {
        $hasData = (trim($uidS) !== '' || trim($name) !== '' || trim($desc) !== '' || $lvl !== 0);
        if ($hasData) {
          $pdo->prepare("
            INSERT INTO character_spells (character_id, spell_uid, name, kind, level, charges, used, description, sort_order)
            VALUES (?, ?, ?, ?, ?, 0, 0, ?, 0)
          ")->execute([$charId, $uidS, $name, $kind, $lvl, $desc]);
        }
      }
    }
  }

  // ----------------------------
  // 9) Abilities sync (старі ab_* та нові ability_*)
  // ----------------------------
  $ab_id    = post_arr('ab_id', 'ability_id');
  $ab_name  = post_arr('ab_name', 'ability_name');
  $ab_kind  = post_arr('ab_kind', 'ability_kind');
  $ab_src   = post_arr('ab_source', 'ability_source');
  $ab_desc  = post_arr('ab_desc', 'ability_description');

  if ($ab_id || $ab_name || $ab_kind || $ab_src || $ab_desc) {
    $postedIds = [];
    foreach ($ab_id as $raw) { $id = (int)$raw; if ($id > 0) $postedIds[] = $id; }

    if ($postedIds) {
      $in = implode(',', array_fill(0, count($postedIds), '?'));
      $pdo->prepare("DELETE FROM character_abilities WHERE character_id=? AND id NOT IN ($in)")
          ->execute(array_merge([$charId], $postedIds));
    } else {
      $pdo->prepare("DELETE FROM character_abilities WHERE character_id=?")->execute([$charId]);
    }

    foreach ($ab_id as $i => $idRaw) {
      $id   = (int)$idRaw;
      $name = (string)($ab_name[$i] ?? '');
      $kind = (string)($ab_kind[$i] ?? '');
      $src  = (string)($ab_src[$i] ?? '');
      $desc = (string)($ab_desc[$i] ?? '');

      if ($id > 0) {
        $pdo->prepare("
          UPDATE character_abilities
            SET name=?, kind=?, source=?, description=?
          WHERE id=? AND character_id=?
        ")->execute([$name, $kind, $src, $desc, $id, $charId]);
      } else {
        $hasData = (trim($name) !== '' || trim($kind) !== '' || trim($src) !== '' || trim($desc) !== '');
        if ($hasData) {
          $pdo->prepare("
            INSERT INTO character_abilities (character_id, name, kind, source, description, sort_order)
            VALUES (?, ?, ?, ?, ?, 0)
          ")->execute([$charId, $name, $kind, $src, $desc]);
        }
      }
    }
  }

  $pdo->commit();

  // якщо це fetch — віддаємо JSON
  if (wants_json()) {
    $out = ['id' => $charId];
    if ($notesWarn) $out['warning'] = $notesWarn;
    json_ok($out);
  }

  // інакше редірект
  header("Location: /character.php?id=" . $charId);
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  if (wants_json()) json_fail("Save failed: " . $e->getMessage(), 500);
  http_response_code(500);
  echo "Save failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
