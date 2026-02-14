<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

require_login();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? 'user');

$charId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($charId <= 0) { http_response_code(400); echo "Missing id"; exit; }

$stmt = db()->prepare("SELECT * FROM characters WHERE id=?");
$stmt->execute([$charId]);
$ch = $stmt->fetch();
if (!$ch) { http_response_code(404); echo "Not found"; exit; }
if ($role !== 'admin' && (int)$ch['owner_user_id'] !== $uid) { http_response_code(403); echo "Forbidden"; exit; }

$st = db()->prepare("SELECT * FROM character_stats WHERE character_id=?");
$st->execute([$charId]);
$stats = $st->fetch() ?: [];

$rs = db()->prepare("SELECT * FROM character_resources WHERE character_id=?");
$rs->execute([$charId]);
$res = $rs->fetch() ?: [];

$cc = db()->prepare("SELECT * FROM character_coins WHERE character_id=?");
$cc->execute([$charId]);
$coins = $cc->fetch() ?: [];

$inv = db()->prepare("SELECT * FROM character_inventory WHERE character_id=? ORDER BY sort_order,id");
$inv->execute([$charId]);
$inventory = $inv->fetchAll();

$wp = db()->prepare("SELECT * FROM character_weapons WHERE character_id=? ORDER BY sort_order,id");
$wp->execute([$charId]);
$weapons = $wp->fetchAll();

$sp = db()->prepare("SELECT * FROM character_spells WHERE character_id=? ORDER BY sort_order,id");
$sp->execute([$charId]);
$spells = $sp->fetchAll();

$ab = db()->prepare("SELECT * FROM character_abilities WHERE character_id=? ORDER BY sort_order,id");
$ab->execute([$charId]);
$abilities = $ab->fetchAll();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($ch['name']) ?></title>
</head>
<body>
<?php require_once __DIR__ . '/inc/nav.php'; ?>

<p><a href="/characters.php">← back</a></p>

<h1><?= h($ch['name']) ?></h1>

<form method="post" action="/api/character_save.php?id=<?= (int)$charId ?>">

  <h2>Identity</h2>
  <label>Name <input name="name" value="<?= h($ch['name']) ?>"></label><br>
  <label>Class <input name="class_name" value="<?= h($ch['class_name']) ?>"></label><br>
  <label>Level <input name="level" type="number" min="1" max="20" value="<?= (int)$ch['level'] ?>"></label><br>
  <label>Race <input name="race" value="<?= h($ch['race']) ?>"></label><br>
  <label>Alignment <input name="alignment" value="<?= h($ch['alignment']) ?>"></label><br>
  <label>Background <input name="background" value="<?= h($ch['background']) ?>"></label><br>
  <label>XP <input name="xp" value="<?= h($ch['xp']) ?>"></label><br>
  <label>Player name <input name="player_name" value="<?= h($ch['player_name']) ?>"></label><br>

  <label>Avatar URL <input name="avatar_url" value="<?= h($ch['avatar_url']) ?>"></label><br>
  <label>Avatar data (dataURL) <textarea name="avatar_data" rows="2"><?= h($ch['avatar_data']) ?></textarea></label>

  <h2>Resources</h2>
  <label>HP current <input name="hp_current" type="number" value="<?= (int)($res['hp_current'] ?? 10) ?>"></label><br>
  <label>HP max <input name="hp_max" type="number" value="<?= (int)($res['hp_max'] ?? 10) ?>"></label><br>
  <label>HP temp <input name="hp_temp" type="number" value="<?= (int)($res['hp_temp'] ?? 0) ?>"></label><br>
  <label>PB <input name="proficiency_bonus" type="number" value="<?= (int)($res['proficiency_bonus'] ?? 2) ?>"></label><br>
  <label>AC <input name="ac" type="number" value="<?= (int)($res['ac'] ?? 10) ?>"></label><br>
  <label>Passive perception override (empty = auto)
    <input name="passive_perception_override" value="<?= h($res['passive_perception_override']) ?>">
  </label><br>
  <label>Inspiration <input name="inspiration" type="checkbox" value="1" <?= !empty($res['inspiration']) ? 'checked' : '' ?>></label><br>
  <label>Speed <input name="speed" type="number" value="<?= (int)($res['speed'] ?? 30) ?>"></label><br>

  <h2>Stats</h2>
  <?php
    $map = ['str'=>'STR','dex'=>'DEX','con'=>'CON','int'=>'INT','wis'=>'WIS','cha'=>'CHA'];
    foreach ($map as $k=>$label):
  ?>
    <label><?= $label ?>
      <input name="<?= $k ?>_score" type="number" value="<?= (int)($stats["{$k}_score"] ?? 10) ?>">
    </label>
    <label>Save prof
      <input name="save_<?= $k ?>" type="checkbox" value="1" <?= !empty($stats["save_{$k}"]) ? 'checked' : '' ?>>
    </label>
    <br>
  <?php endforeach; ?>

  <h2>Coins</h2>
  <label>GP <input name="gp" type="number" value="<?= (int)($coins['gp'] ?? 0) ?>"></label>
  <label>SP <input name="sp" type="number" value="<?= (int)($coins['sp'] ?? 0) ?>"></label>
  <label>CP <input name="cp" type="number" value="<?= (int)($coins['cp'] ?? 0) ?>"></label>

  <h2>Inventory</h2>
  <div id="inv">
    <?php foreach ($inventory as $i => $it): ?>
      <div style="border:1px solid #ccc; padding:8px; margin:6px 0;">
        <input type="hidden" name="inv_id[]" value="<?= (int)$it['id'] ?>">
        <label>Name <input name="inv_name[]" value="<?= h($it['name']) ?>"></label>
        <label>Eq <input type="checkbox" name="inv_equipped[<?= $i ?>]" value="1" <?= !empty($it['equipped'])?'checked':'' ?>></label>
        <label>Cons <input type="checkbox" name="inv_consumable[<?= $i ?>]" value="1" <?= !empty($it['consumable'])?'checked':'' ?>></label>
        <label>Qty <input name="inv_qty[]" type="number" value="<?= (int)$it['qty'] ?>"></label>
        <label>Charges <input name="inv_charges[]" type="number" value="<?= (int)$it['charges'] ?>"></label>
        <label>Icon <input name="inv_icon[]" value="<?= h($it['icon']) ?>"></label><br>
        <label>Description<br><textarea name="inv_desc[]" rows="2"><?= h($it['description']) ?></textarea></label><br>
        <label>Delete <input type="checkbox" name="inv_delete[]" value="<?= (int)$it['id'] ?>"></label>
      </div>
    <?php endforeach; ?>
  </div>

  <h2>Weapons</h2>
  <?php foreach ($weapons as $w): ?>
    <div style="border:1px solid #ccc; padding:8px; margin:6px 0;">
      <input type="hidden" name="wp_id[]" value="<?= (int)$w['id'] ?>">
      <label>Name <input name="wp_name[]" value="<?= h($w['name']) ?>"></label>
      <label>ATK <input name="wp_atk[]" value="<?= h($w['atk']) ?>"></label>
      <label>DMG <input name="wp_dmg[]" value="<?= h($w['dmg']) ?>"></label>
      <label>Delete <input type="checkbox" name="wp_delete[]" value="<?= (int)$w['id'] ?>"></label>
    </div>
  <?php endforeach; ?>

  <h2>Spells</h2>
  <?php foreach ($spells as $s): ?>
    <div style="border:1px solid #ccc; padding:8px; margin:6px 0;">
      <input type="hidden" name="sp_id[]" value="<?= (int)$s['id'] ?>">
      <label>UID <input name="sp_uid[]" value="<?= h($s['spell_uid']) ?>"></label>
      <label>Name <input name="sp_name[]" value="<?= h($s['name']) ?>"></label>
      <label>Kind
        <select name="sp_kind[]">
          <option value="spell" <?= (($s['kind'] ?? 'spell')==='spell')?'selected':'' ?>>spell</option>
          <option value="cantrip" <?= (($s['kind'] ?? '')==='cantrip')?'selected':'' ?>>cantrip</option>
        </select>
      </label>
      <label>Level <input name="sp_level[]" type="number" min="0" max="9" value="<?= (int)$s['level'] ?>"></label>
      <label>Charges <input name="sp_charges[]" type="number" value="<?= (int)$s['charges'] ?>"></label>
      <label>Used <input name="sp_used[]" type="checkbox" value="<?= (int)$s['id'] ?>" <?= !empty($s['used'])?'checked':'' ?>></label><br>
      <label>Description<br><textarea name="sp_desc[]" rows="2"><?= h($s['description']) ?></textarea></label><br>
      <label>Delete <input type="checkbox" name="sp_delete[]" value="<?= (int)$s['id'] ?>"></label>
    </div>
  <?php endforeach; ?>

  <h2>Abilities</h2>
  <?php foreach ($abilities as $a): ?>
    <div style="border:1px solid #ccc; padding:8px; margin:6px 0;">
      <input type="hidden" name="ab_id[]" value="<?= (int)$a['id'] ?>">
      <label>Name <input name="ab_name[]" value="<?= h($a['name']) ?>"></label>
      <label>Kind <input name="ab_kind[]" value="<?= h($a['kind']) ?>"></label>
      <label>Source <input name="ab_source[]" value="<?= h($a['source']) ?>"></label><br>
      <label>Description<br><textarea name="ab_desc[]" rows="2"><?= h($a['description']) ?></textarea></label><br>
      <label>Delete <input type="checkbox" name="ab_delete[]" value="<?= (int)$a['id'] ?>"></label>
    </div>
  <?php endforeach; ?>

  <p>
    <button type="submit">Save</button>
  </p>
</form>

</body>
</html>
