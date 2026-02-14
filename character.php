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
// access: admin OR owner OR shared
$canEdit = false;

if ($role === 'admin' || (int)$ch['owner_user_id'] === $uid) {
  $canEdit = true;
} else {
  $accSt = db()->prepare("
    SELECT can_edit
    FROM character_access
    WHERE character_id=? AND user_id=?
    LIMIT 1
  ");
  $accSt->execute([$charId, $uid]);
  $acc = $accSt->fetch();

  if (!$acc) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }

  $canEdit = ((int)$acc['can_edit'] === 1);
}

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

$shared = [];
if ($role === 'admin') {
  $st2 = db()->prepare("
    SELECT a.user_id, a.can_edit, u.username
    FROM character_access a
    JOIN users u ON u.id = a.user_id
    WHERE a.character_id=?
    ORDER BY u.username
  ");
  $st2->execute([$charId]);
  $shared = $st2->fetchAll();
}



function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/inc/char.css">
  <title><?= h($ch['name']) ?></title>
</head>
<body>
<?php require_once __DIR__ . '/inc/nav.php'; ?>

<div class="topbar">
  <div class="brand"><?= h($ch['name']) ?></div>
  <div class="actions">
    <a href="/characters.php"><button type="button" class="secondary">← back</button></a>

    <?php if ($canEdit): ?>
      <button type="submit" form="charForm">Save</button>
    <?php else: ?>
      <span class="badge">read-only</span>
    <?php endif; ?>
  </div>
</div>

<?php if ($role === 'admin'): ?>
  <section class="card" style="margin-top:12px;">
    <h2>Доступ до чарника</h2>

    <form method="post" action="/api/character_share.php" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
      <input type="hidden" name="character_id" value="<?= (int)$charId ?>">

      <label>
        Username кому видати
        <input name="username" placeholder="наприклад: nika" required>
      </label>

      <label style="display:flex; gap:6px; align-items:center; margin-bottom:6px;">
        <input type="checkbox" name="can_edit" value="1">
        може редагувати
      </label>

      <button type="submit">Видати/оновити доступ</button>
    </form>

    <?php if (!empty($shared)): ?>
      <h3 style="margin-top:12px;">Вже мають доступ</h3>
      <ul>
        <?php foreach ($shared as $s): ?>
          <li style="margin:6px 0;">
            <b><?= h($s['username']) ?></b>
            — <?= ((int)$s['can_edit']===1) ? 'edit' : 'view' ?>

            <form method="post" action="/api/character_unshare.php" style="display:inline;">
              <input type="hidden" name="character_id" value="<?= (int)$charId ?>">
              <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
              <button type="submit" onclick="return confirm('Забрати доступ у <?= h($s['username']) ?>?')">забрати</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p style="opacity:.75; margin-top:10px;">Поки нікому не видано доступ.</p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<form id="charForm" method="post" action="/api/character_save.php?id=<?= (int)$charId ?>">

  <div class="shell">
    <!-- LEFT -->
    <aside class="sidebar-left">

      <section class="card">
        <h2>Identity</h2>

        <label>Name <input name="name" value="<?= h($ch['name']) ?>"></label>
        <label>Class <input name="class_name" value="<?= h($ch['class_name']) ?>"></label>

        <div class="grid2">
          <label>Level <input class="numSmall" name="level" type="number" min="1" max="20" value="<?= (int)$ch['level'] ?>"></label>
          <label>Race <input name="race" value="<?= h($ch['race']) ?>"></label>
        </div>

        <label>Alignment <input name="alignment" value="<?= h($ch['alignment']) ?>"></label>
        <label>Background <input name="background" value="<?= h($ch['background']) ?>"></label>
        <label>XP <input name="xp" value="<?= h($ch['xp']) ?>"></label>
        <label>Player name <input name="player_name" value="<?= h($ch['player_name']) ?>"></label>

        <div class="grid2">
          <label>Avatar URL <input name="avatar_url" value="<?= h($ch['avatar_url']) ?>"></label>
          <label>Avatar data <input name="avatar_data" value="<?= h($ch['avatar_data']) ?>"></label>
        </div>
      </section>

      <section class="card vitalsCard">
        <h2>Resources</h2>

        <div class="row">
          <label>HP <input class="numSmall" name="hp_current" type="number" value="<?= (int)($res['hp_current'] ?? 10) ?>"></label>
          <label>Max <input class="numSmall" name="hp_max" type="number" value="<?= (int)($res['hp_max'] ?? 10) ?>"></label>
          <label>Temp <input class="numSmall" name="hp_temp" type="number" value="<?= (int)($res['hp_temp'] ?? 0) ?>"></label>
        </div>

        <div class="row">
          <label>PB <input class="numSmall" name="proficiency_bonus" type="number" value="<?= (int)($res['proficiency_bonus'] ?? 2) ?>"></label>
          <label>AC <input class="numSmall" name="ac" type="number" value="<?= (int)($res['ac'] ?? 10) ?>"></label>
          <label>Speed <input class="numSmall" name="speed" type="number" value="<?= (int)($res['speed'] ?? 30) ?>"></label>
        </div>

        <label>Passive perception override
          <input name="passive_perception_override" value="<?= h($res['passive_perception_override']) ?>">
        </label>

        <label class="row-inline">
          <input name="inspiration" type="checkbox" value="1" <?= !empty($res['inspiration']) ? 'checked' : '' ?>>
          Inspiration
        </label>
      </section>

      <section class="card">
        <h2>Coins</h2>
        <div class="coinsPanel">
          <div class="coinsGrid">
            <label>GP <input class="numSmall" name="gp" type="number" value="<?= (int)($coins['gp'] ?? 0) ?>"></label>
            <label>SP <input class="numSmall" name="sp" type="number" value="<?= (int)($coins['sp'] ?? 0) ?>"></label>
            <label>CP <input class="numSmall" name="cp" type="number" value="<?= (int)($coins['cp'] ?? 0) ?>"></label>
          </div>
        </div>
      </section>

    </aside>

    <!-- MAIN -->
    <main class="main">

      <section class="card" id="statsCard">
        <h2>Stats</h2>

        <div class="statsGridCompact">
          <?php
            $map = ['str'=>'STR','dex'=>'DEX','con'=>'CON','int'=>'INT','wis'=>'WIS','cha'=>'CHA'];
            foreach ($map as $k=>$label):
          ?>
            <div class="statCell">
              <span class="statKey"><?= $label ?></span>
              <input name="<?= $k ?>_score" type="number" value="<?= (int)($stats["{$k}_score"] ?? 10) ?>">
              <span class="statMod">save</span>
              <input type="checkbox" name="save_<?= $k ?>" value="1" <?= !empty($stats["save_{$k}"]) ? 'checked' : '' ?>>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="card collapsible" id="invCard">
        <h2 onclick="this.parentElement.classList.toggle('collapsed')">Inventory</h2>
        <div class="cardBody">
          <?php foreach ($inventory as $i => $it): ?>
            <div class="card" style="box-shadow:none; margin:10px 0;">
              <input type="hidden" name="inv_id[]" value="<?= (int)$it['id'] ?>">

              <div class="grid2">
                <label>Name <input name="inv_name[]" value="<?= h($it['name']) ?>"></label>
                <label>Icon <input name="inv_icon[]" value="<?= h($it['icon']) ?>"></label>
              </div>

              <div class="row">
                <label>Qty <input class="numSmall" name="inv_qty[]" type="number" value="<?= (int)$it['qty'] ?>"></label>
                <label>Charges <input class="numSmall" name="inv_charges[]" type="number" value="<?= (int)$it['charges'] ?>"></label>

                <label class="row-inline">
                  <input type="checkbox" name="inv_equipped[<?= $i ?>]" value="1" <?= !empty($it['equipped'])?'checked':'' ?>>
                  Equipped
                </label>

                <label class="row-inline">
                  <input type="checkbox" name="inv_consumable[<?= $i ?>]" value="1" <?= !empty($it['consumable'])?'checked':'' ?>>
                  Consumable
                </label>
              </div>

              <label>Description
                <textarea name="inv_desc[]" rows="2"><?= h($it['description']) ?></textarea>
              </label>

              <label class="row-inline">
                <input type="checkbox" name="inv_delete[]" value="<?= (int)$it['id'] ?>">
                Delete
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if (!$canEdit): ?>
        <p style="color:#b00020;">У вас є доступ лише на перегляд. Зверніться до адміна, щоб отримати право редагування.</p>
      <?php endif; ?>

    </main>

    <!-- RIGHT -->
    <aside class="sidebar-right">

      <section class="card collapsible">
        <h2 onclick="this.parentElement.classList.toggle('collapsed')">Weapons</h2>
        <div class="cardBody">
          <?php foreach ($weapons as $w): ?>
            <div class="card" style="box-shadow:none; margin:10px 0;">
              <input type="hidden" name="wp_id[]" value="<?= (int)$w['id'] ?>">
              <label>Name <input name="wp_name[]" value="<?= h($w['name']) ?>"></label>
              <div class="grid2">
                <label>ATK <input name="wp_atk[]" value="<?= h($w['atk']) ?>"></label>
                <label>DMG <input name="wp_dmg[]" value="<?= h($w['dmg']) ?>"></label>
              </div>
              <label class="row-inline">
                <input type="checkbox" name="wp_delete[]" value="<?= (int)$w['id'] ?>">
                Delete
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="card collapsible">
        <h2 onclick="this.parentElement.classList.toggle('collapsed')">Spells</h2>
        <div class="cardBody">
          <?php foreach ($spells as $s): ?>
            <div class="card" style="box-shadow:none; margin:10px 0;">
              <input type="hidden" name="sp_id[]" value="<?= (int)$s['id'] ?>">

              <div class="grid2">
                <label>UID <input name="sp_uid[]" value="<?= h($s['spell_uid']) ?>"></label>
                <label>Name <input name="sp_name[]" value="<?= h($s['name']) ?>"></label>
              </div>

              <div class="row">
                <label>Kind
                  <select name="sp_kind[]">
                    <option value="spell" <?= (($s['kind'] ?? 'spell')==='spell')?'selected':'' ?>>spell</option>
                    <option value="cantrip" <?= (($s['kind'] ?? '')==='cantrip')?'selected':'' ?>>cantrip</option>
                  </select>
                </label>
                <label>Level <input class="numSmall" name="sp_level[]" type="number" min="0" max="9" value="<?= (int)$s['level'] ?>"></label>
                <label>Charges <input class="numSmall" name="sp_charges[]" type="number" value="<?= (int)$s['charges'] ?>"></label>

                <label class="row-inline">
                  <input name="sp_used[]" type="checkbox" value="<?= (int)$s['id'] ?>" <?= !empty($s['used'])?'checked':'' ?>>
                  Used
                </label>
              </div>

              <label>Description
                <textarea name="sp_desc[]" rows="2"><?= h($s['description']) ?></textarea>
              </label>

              <label class="row-inline">
                <input type="checkbox" name="sp_delete[]" value="<?= (int)$s['id'] ?>">
                Delete
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="card collapsible">
        <h2 onclick="this.parentElement.classList.toggle('collapsed')">Abilities</h2>
        <div class="cardBody">
          <?php foreach ($abilities as $a): ?>
            <div class="card" style="box-shadow:none; margin:10px 0;">
              <input type="hidden" name="ab_id[]" value="<?= (int)$a['id'] ?>">
              <label>Name <input name="ab_name[]" value="<?= h($a['name']) ?>"></label>
              <div class="grid2">
                <label>Kind <input name="ab_kind[]" value="<?= h($a['kind']) ?>"></label>
                <label>Source <input name="ab_source[]" value="<?= h($a['source']) ?>"></label>
              </div>
              <label>Description
                <textarea name="ab_desc[]" rows="2"><?= h($a['description']) ?></textarea>
              </label>
              <label class="row-inline">
                <input type="checkbox" name="ab_delete[]" value="<?= (int)$a['id'] ?>">
                Delete
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

    </aside>
  </div>
</form>

</body>
</html>