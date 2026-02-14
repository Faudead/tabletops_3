<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

require_login();
$user = current_user();
$uid  = (int)($user['id'] ?? 0);
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

// notes / personality (optional table)
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
  $nt = db()->prepare("
    SELECT session_notes, quick_notes, traits, ideals, bonds, flaws, backstory
    FROM character_notes
    WHERE character_id=?
    LIMIT 1
  ");
  $nt->execute([$charId]);
  $row = $nt->fetch();
  if ($row) {
    foreach ($notes as $k => $_) {
      if (array_key_exists($k, $row) && $row[$k] !== null) $notes[$k] = (string)$row[$k];
    }
  }
} catch (Throwable $e) {
  // Якщо таблиці/колонок ще нема — просто не роняємо сторінку.
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function stat_mod(int $score): int { return (int)floor(($score - 10) / 2); }
function fmt_mod(int $m): string { return ($m >= 0 ? '+' : '') . (string)$m; }

$pb = (int)($res['proficiency_bonus'] ?? 2);

// avatar src
$avatarSrc = '';
if (!empty($ch['avatar_data'])) {
  $avatarSrc = (string)$ch['avatar_data'];
} elseif (!empty($ch['avatar_url'])) {
  $avatarSrc = (string)$ch['avatar_url'];
}

?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($ch['name'] ?? 'Character') ?></title>
  <link rel="stylesheet" href="/inc/char.css?v=1">
  <style>
    /* Extra styles for character.php on top of /inc/char.css */
    /* Keep 3 columns; DO NOT HIDE the right column (char.css stacks at <1100px; that's fine). */
    .shell{ grid-template-columns: 280px 1fr 320px; }

    /* Legacy .btn support (file uses .btn classes) */
    .btn{
      background: var(--accent);
      color: #fff;
      border: none;
      padding: 8px 14px;
      border-radius: 10px;
      cursor: pointer;
      font-size: 14px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .btn.secondary{ background: #2b2c36; }
    .btn.danger{ background: var(--danger); }
    .btn[disabled]{ opacity:.65; cursor:not-allowed; }

    /* Avatar preview */
    .avatarPreview{
      width: 96px;
      height: 96px;
      border-radius: 14px;
      border: 1px solid var(--border);
      overflow: hidden;
      background: var(--panel-soft);
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .avatarPreview img{ width:100%; height:100%; object-fit:cover; display:block; }
    .avatarBox{ display:flex; gap: 12px; align-items:flex-start; }

    /* Tabs */
    .tabs{ display:flex; gap:10px; flex-wrap:wrap; margin-bottom: 12px; }
    .tabs button{
      background: #2b2c36;
      color: var(--text);
      border: 1px solid var(--border);
      padding: 8px 12px;
      border-radius: 10px;
      cursor: pointer;
      font-size: 14px;
    }
    .tabs button.active{
      background: rgba(124, 92, 255, .25);
      border-color: rgba(124, 92, 255, .45);
    }
/* Small UI helpers used by the file */
    .muted{ color: var(--muted); }
    .hint{ font-size: 12px; color: var(--muted); margin-top: 8px; }
    .pill{ background: #2b2c36; padding: 6px 10px; border-radius: 999px; font-size: 12px; color: var(--muted); display:inline-block; }

    .list{ display:flex; flex-direction:column; gap:10px; }
    .item{ background: var(--panel-soft); border:1px solid var(--border); border-radius: 12px; padding: 10px; }
    .itemHead{ display:flex; justify-content:space-between; align-items:center; gap:10px; }

    .toast{
      position: fixed;
      left: 50%;
      bottom: 18px;
      transform: translateX(-50%);
      background: rgba(20,20,24,.92);
      border: 1px solid var(--border);
      padding: 10px 12px;
      border-radius: 12px;
      color: var(--text);
      z-index: 9999;
      display:none;
    }
    .toast.show{ display:block; }
  </style>
</head>
<body>

<header class="topbar">
<div class="brand">
      <a class="btn secondary" href="/characters.php">← Назад</a>
      <div>
        <div style="font-size:18px; font-weight:700; line-height:1.1;"><?= h($ch['name']) ?></div>
        <div class="muted small">
          <span class="pill"><?= h($ch['race'] ?? '') ?></span>
          <span class="pill"><?= h($ch['class_name'] ?? '') ?> <?= (int)($ch['level'] ?? 1) ?></span>
          <?php if ($canEdit): ?>
            <span class="pill">Можна редагувати</span>
          <?php else: ?>
            <span class="pill">Лише перегляд</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="actions">
      <button class="btn primary" id="btnSave" <?= $canEdit ? '' : 'disabled' ?>>Save</button>
      <button class="btn secondary" id="btnExport">Export JSON</button>
    </div>
</header>

<form id="charForm" method="post" action="/api/character_save.php">
  <input type="hidden" name="id" value="<?= (int)$charId ?>">

  <main class="shell">

    <aside class="sidebar-left">
      <section class="card">
  <h2>Avatar</h2>
  <div class="avatarBox" style="margin-bottom:10px;">
          <div class="avatarPreview" id="avatarPreview">
            <?php if ($avatarSrc): ?>
              <img src="<?= h($avatarSrc) ?>" alt="avatar">
            <?php else: ?>
              <span class="muted">No avatar</span>
            <?php endif; ?>
          </div>

          <div style="flex:1">
            <label>Avatar URL
              <input type="url" name="avatar_url" id="avatarUrl" value="<?= h($ch['avatar_url']) ?>" placeholder="https://..." <?= $canEdit ? '' : 'disabled' ?>>
            </label>

            <label>Avatar file (save into DB as base64)
              <input type="file" id="avatarFile" accept="image/*" <?= $canEdit ? '' : 'disabled' ?>>
            </label>

            <input type="hidden" name="avatar_data" id="avatarData" value="<?= h($ch['avatar_data']) ?>">
            <div class="row">
              <button class="btn secondary" type="button" id="btnClearAvatar" <?= $canEdit ? '' : 'disabled' ?>>Clear</button>
            </div>
          </div>
        </div>
  <div class="hint" style="font-size:12px; color:var(--muted); margin-top:10px;">
          Зображення можна зберігати всередині БД як data (base64). Не вантаж гігантські файли 🙂
        </div>
</section>

      <section class="card" style="margin-top: 12px;">
            <h2>Notes</h2>
            <label>Session notes
              <textarea id="sessionNotes" name="session_notes" rows="12" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes["session_notes"] ?? "") ?></textarea>
            </label>
            <label>Quick notes
              <textarea id="quickNotes" name="quick_notes" placeholder="Коротко: що зараз відбувається, цілі, ініціатива..." rows="8" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes["quick_notes"] ?? "") ?></textarea>
            </label>
          </section>

      <section class="card" hidden id="historyPanel">
            <h2>History</h2>
            <div class="hint" style="font-size:12px; color:var(--muted);">Поки що плейсхолдер під історію збережень.</div>
            <button class="secondary" type="button" id="btnRefreshHistory" disabled>Refresh</button>
            <div id="historyList"></div>
          </section>
    </aside>

    <section class="main">
      <section class="card">
<div class="tabs">
          <button type="button" class="active" data-tab="sheet">Sheet</button>
          <button type="button" data-tab="combat">Combat</button>
          <button type="button" data-tab="inventory">Inventory</button>
          <button type="button" data-tab="spells">Spells</button>
          <button type="button" data-tab="abilities">Abilities</button>
        </div>

        <!-- TAB: SHEET -->
        <section data-panel="sheet">

          <div class="grid2">

            <section class="card" style="padding:12px;">
              <h2>Stats</h2>
              <div class="grid2">
                <?php
                  $statKeys = ['str'=>'STR','dex'=>'DEX','con'=>'CON','int'=>'INT','wis'=>'WIS','cha'=>'CHA'];
                  foreach ($statKeys as $k => $label):
                    $score = (int)($stats[$k] ?? 10);
                    $mod = stat_mod($score);
                ?>
                  <div class="item">
                    <div class="itemHead">
                      <div><b><?= $label ?></b></div>
                      <div class="pill"><?= fmt_mod($mod) ?></div>
                    </div>
                    <label>Score
                      <input type="number" min="1" max="30" name="stat_<?= $k ?>" value="<?= $score ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>

            <section class="card" style="padding:12px;">
              <h2>Resources</h2>

              <div class="grid2">
                <label>HP max
                  <input type="number" min="0" name="hp_max" value="<?= (int)($res['hp_max'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </label>
                <label>HP current
                  <input type="number" min="0" name="hp_current" value="<?= (int)($res['hp_current'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </label>

                <label>Temp HP
                  <input type="number" min="0" name="temp_hp" value="<?= (int)($res['temp_hp'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </label>
                <label>AC
                  <input type="number" min="0" name="ac" value="<?= (int)($res['ac'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </label>

                <label>Speed
                  <input type="number" min="0" name="speed" value="<?= (int)($res['speed'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </label>
                <label>Initiative
                  <input type="number" name="initiative" value="<?= (int)($res['initiative'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </label>

                <label>Passive Perception (override)
                  <input type="number" id="passivePerception" value="<?= (int)($res['passive_perception'] ?? 10) ?>" disabled>
                  <input type="hidden" name="passive_perception" value="<?= (int)($res['passive_perception'] ?? 10) ?>">
                </label>

                <label>Auto passive perception
                  <input type="checkbox" id="autoPassivePerception" checked disabled>
                </label>
              </div>

              <hr style="border:0;border-top:1px solid rgba(255,255,255,.10); margin: 12px 0;">

              <h2>Coins</h2>
              <div class="grid2">
                <label>CP <input type="number" min="0" name="coin_cp" value="<?= (int)($coins['cp'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                <label>SP <input type="number" min="0" name="coin_sp" value="<?= (int)($coins['sp'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                <label>EP <input type="number" min="0" name="coin_ep" value="<?= (int)($coins['ep'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                <label>GP <input type="number" min="0" name="coin_gp" value="<?= (int)($coins['gp'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                <label>PP <input type="number" min="0" name="coin_pp" value="<?= (int)($coins['pp'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
              </div>

            </section>

          </div>

          

<section class="card collapsible open" data-collapse="identity" style="margin-top: 12px;">
            <div class="collapseToggle">
              <h2 style="margin:0;">Identity</h2>
              <span class="muted">toggle</span>
            </div>
            <div class="cardBody">

              <div class="grid2">
                <div>
                  <label>Ім'я гравця
                    <input id="playerName" name="player_name" value="<?= h($ch['player_name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>
                  <label>Раса
                    <input id="race" name="race" value="<?= h($ch['race']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>
                  <label>Клас
                    <input id="className" name="class_name" value="<?= h($ch['class_name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>
                  <label>Рівень
                    <input id="level" type="number" min="1" max="20" name="level" value="<?= (int)($ch['level'] ?? 1) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>
                </div>

                <div>
                  <label>Світогляд
                    <input id="alignment" name="alignment" value="<?= h($ch['alignment']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                  </label>
                  <div class="grid2">
                    <label>Передісторія
                      <input id="background" name="background" value="<?= h($ch['background']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    </label>
                    <label>Очки досвіду
                      <input id="xp" name="xp" value="<?= h($ch['xp']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    </label>
                  </div>

                  <div class="grid2">
                    <label>Риси характеру
                      <textarea id="traits" name="traits" rows="4" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes["traits"] ?? "") ?></textarea>
                    </label>
                    <label>Ідеали
                      <textarea id="ideals" name="ideals" rows="4" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes["ideals"] ?? "") ?></textarea>
                    </label>

                    <label>Прив'язаності
                      <textarea id="bonds" name="bonds" rows="4" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes["bonds"] ?? "") ?></textarea>
                    </label>
                    <label>Слабкість
                      <textarea id="flaws" name="flaws" rows="4" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes["flaws"] ?? "") ?></textarea>
                    </label>
                  </div>

                  <label>Backstory
                    <textarea id="backstory" name="backstory" rows="10" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes["backstory"] ?? "") ?></textarea>
                  </label>

                </div>
              </div>

            </div>
          </section>

          <section class="card collapsible" data-collapse="proficiencies">
            <div class="collapseToggle">
              <h2 style="margin:0;">Proficiencies</h2>
              <span class="muted">toggle</span>
            </div>
            <div class="cardBody">
              <div class="hint">Плейсхолдер — якщо захочеш, додамо окремі таблиці/поля.</div>
            </div>
          </section>

        </section>

        <!-- TAB: COMBAT -->
        <section data-panel="combat" hidden>
          <div class="grid2">
            <section class="card">
              <h2>Combat notes</h2>
              <div class="hint">Поки що — базові поля в Resources.</div>
            </section>

            <section class="card">
              <h2>Weapons</h2>
              <div class="sectionTitle">
                <div class="muted small"><?= count($weapons) ?> items</div>
                <button class="btn secondary" type="button" id="btnAddWeapon" <?= $canEdit ? '' : 'disabled' ?>>+ Add</button>
              </div>
              <div id="weaponList" class="list">
                <?php foreach ($weapons as $w): ?>
                  <div class="item weaponItem" data-id="<?= (int)$w['id'] ?>">
                    <div class="itemHead">
                      <div><b><?= h($w['name'] ?? 'Weapon') ?></b></div>
                      <button type="button" class="btn danger btnRemoveWeapon" <?= $canEdit ? '' : 'disabled' ?>>Remove</button>
                    </div>
                    <div class="grid2">
                      <label>Name <input name="weapon_name[]" value="<?= h($w['name'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                      <label>Attack bonus <input name="weapon_attack_bonus[]" value="<?= h($w['attack_bonus'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                      <label>Damage <input name="weapon_damage[]" value="<?= h($w['damage'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                      <label>Type <input name="weapon_type[]" value="<?= h($w['type'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                      <label>Notes <input name="weapon_notes[]" value="<?= h($w['notes'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                      <input type="hidden" name="weapon_id[]" value="<?= (int)$w['id'] ?>">
                      <input type="hidden" name="weapon_sort[]" value="<?= (int)$w['sort_order'] ?>">
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          </div>
        </section>

        <!-- TAB: INVENTORY -->
        <section data-panel="inventory" hidden>
          <section class="card">
            <div class="sectionTitle">
              <h2 style="margin:0;">Inventory</h2>
              <button class="btn secondary" type="button" id="btnAddItem" <?= $canEdit ? '' : 'disabled' ?>>+ Add</button>
            </div>
            <div id="invList" class="list">
              <?php foreach ($inventory as $it): ?>
                <div class="item invItem" data-id="<?= (int)$it['id'] ?>">
                  <div class="itemHead">
                    <div><b><?= h($it['name'] ?? 'Item') ?></b></div>
                    <button type="button" class="btn danger btnRemoveItem" <?= $canEdit ? '' : 'disabled' ?>>Remove</button>
                  </div>
                  <div class="grid2">
                    <label>Name <input name="item_name[]" value="<?= h($it['name'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>Qty <input type="number" min="0" name="item_qty[]" value="<?= (int)($it['qty'] ?? 1) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>Weight <input name="item_weight[]" value="<?= h($it['weight'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>Notes <input name="item_notes[]" value="<?= h($it['notes'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>

                    <input type="hidden" name="item_id[]" value="<?= (int)$it['id'] ?>">
                    <input type="hidden" name="item_sort[]" value="<?= (int)$it['sort_order'] ?>">
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        </section>

        <!-- TAB: SPELLS -->
        <section data-panel="spells" hidden>
          <section class="card">
            <div class="sectionTitle">
              <h2 style="margin:0;">Spells</h2>
              <button class="btn secondary" type="button" id="btnAddSpell" <?= $canEdit ? '' : 'disabled' ?>>+ Add</button>
            </div>
            <div id="spellList" class="list">
              <?php foreach ($spells as $s): ?>
                <div class="item spellItem" data-id="<?= (int)$s['id'] ?>">
                  <div class="itemHead">
                    <div><b><?= h($s['name'] ?? 'Spell') ?></b></div>
                    <button type="button" class="btn danger btnRemoveSpell" <?= $canEdit ? '' : 'disabled' ?>>Remove</button>
                  </div>
                  <div class="grid2">
                    <label>Name <input name="spell_name[]" value="<?= h($s['name'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>Level <input name="spell_level[]" value="<?= h($s['level'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>School <input name="spell_school[]" value="<?= h($s['school'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>Prepared <input type="checkbox" name="spell_prepared[]" value="1" <?= ((int)($s['is_prepared'] ?? 0) === 1 ? 'checked' : '') ?> <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>Notes <input name="spell_notes[]" value="<?= h($s['notes'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>

                    <input type="hidden" name="spell_id[]" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="spell_sort[]" value="<?= (int)$s['sort_order'] ?>">
                    <input type="hidden" name="spell_prepared_present[]" value="1">
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        </section>

        <!-- TAB: ABILITIES -->
        <section data-panel="abilities" hidden>
          <section class="card">
            <div class="sectionTitle">
              <h2 style="margin:0;">Abilities</h2>
              <button class="btn secondary" type="button" id="btnAddAbility" <?= $canEdit ? '' : 'disabled' ?>>+ Add</button>
            </div>
            <div id="abilityList" class="list">
              <?php foreach ($abilities as $a): ?>
                <div class="item abilityItem" data-id="<?= (int)$a['id'] ?>">
                  <div class="itemHead">
                    <div><b><?= h($a['name'] ?? 'Ability') ?></b></div>
                    <button type="button" class="btn danger btnRemoveAbility" <?= $canEdit ? '' : 'disabled' ?>>Remove</button>
                  </div>
                  <div class="grid2">
                    <label>Name <input name="ability_name[]" value="<?= h($a['name'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>Kind <input name="ability_kind[]" value="<?= h($a['kind'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>Source <input name="ability_source[]" value="<?= h($a['source'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
                    <label>Description <input name="ability_description[]" value="<?= h($a['description'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>

                    <input type="hidden" name="ability_id[]" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="ability_sort[]" value="<?= (int)$a['sort_order'] ?>">
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        </section>
</section>
    </section>

    <aside class="sidebar-right">
      <section class="card">
<h2>Basics</h2>

        

        <div class="row">
          <label>Name
            <input name="name" value="<?= h($ch['name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
          <label>Player
            <input name="player_name" value="<?= h($ch['player_name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>

        <div class="row">
          <label>Race
            <input name="race" value="<?= h($ch['race']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
          <label>Class
            <input name="class_name" value="<?= h($ch['class_name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>

        <div class="row">
          <label>Level
            <input type="number" min="1" max="20" name="level" value="<?= (int)($ch['level'] ?? 1) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
          <label>Alignment
            <input name="alignment" value="<?= h($ch['alignment']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>

        <div class="row">
          <label>Background
            <input name="background" value="<?= h($ch['background']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
          <label>XP
            <input name="xp" value="<?= h($ch['xp']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>

        <div class="row">
          <label>Proficiency Bonus
            <input type="number" min="0" max="20" name="proficiency_bonus" value="<?= (int)($res['proficiency_bonus'] ?? 2) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>

        <?php if ($role === 'admin'): ?>
        <hr style="border:0;border-top:1px solid rgba(255,255,255,.10); margin: 12px 0;">
        <h2>Shared access</h2>
        <div class="hint" style="margin-bottom:8px;">Показує, кому виданий доступ через character_access.</div>
        <div class="list">
          <?php if (!$shared): ?>
            <div class="muted small">Немає share-записів.</div>
          <?php else: ?>
            <?php foreach ($shared as $s): ?>
              <div class="item">
                <div class="itemHead">
                  <div><b><?= h($s['username'] ?? ('#'.$s['user_id'])) ?></b></div>
                  <div class="pill"><?= ((int)$s['can_edit'] === 1) ? 'edit' : 'view' ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>
</section>


      <section class="card collapsible" data-collapse="skills">
        <div class="collapseToggle">
          <h2 style="margin:0;">Skills</h2>
          <span class="muted">toggle</span>
        </div>
        <div class="cardBody">
          <div class="hint">Total = stat mod + PB (якщо proficient).</div>
          <div id="skillsList"></div>
        </div>
      </section>


      <section class="card collapsible" data-collapse="proficiencies">
        <div class="collapseToggle">
          <h2 style="margin:0;">Proficiencies</h2>
          <span class="muted">toggle</span>
        </div>
        <div class="cardBody">
          <div class="profBlock">
            <div class="profHead">
              <span>Weapons</span>
              <button type="button" class="smallBtn" id="addProfWeapon" <?= $canEdit ? '' : 'disabled' ?>>+ Add</button>
            </div>
            <div id="profWeapons"></div>
          </div>

          <div class="profBlock">
            <div class="profHead">
              <span>Armor</span>
              <button type="button" class="smallBtn" id="addProfArmor" <?= $canEdit ? '' : 'disabled' ?>>+ Add</button>
            </div>
            <div id="profArmor"></div>
          </div>

          <div class="profBlock">
            <div class="profHead">
              <span>Tools</span>
              <button type="button" class="smallBtn" id="addProfTools" <?= $canEdit ? '' : 'disabled' ?>>+ Add</button>
            </div>
            <div id="profTools"></div>
          </div>

          <div class="profBlock">
            <div class="profHead">
              <span>Languages</span>
              <button type="button" class="smallBtn" id="addProfLang" <?= $canEdit ? '' : 'disabled' ?>>+ Add</button>
            </div>
            <div id="profLang"></div>
          </div>

          <div class="profBlock">
            <div class="profHead">
              <span>Vehicles / Transport</span>
              <button type="button" class="smallBtn" id="addProfVehicle" <?= $canEdit ? '' : 'disabled' ?>>+ Add</button>
            </div>
            <div id="profVehicle"></div>
          </div>
        </div>
      </section>

    </aside>

  </main>
</form>

<div class="toast" id="toast"></div>

<script>
(() => {
  const canEdit = <?= $canEdit ? 'true' : 'false' ?>;

  const toast = (msg) => {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2200);
  };

  // Tabs
  const tabBtns = Array.from(document.querySelectorAll('[data-tab]'));
  const panels = Array.from(document.querySelectorAll('[data-panel]'));
  tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      tabBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const key = btn.getAttribute('data-tab');
      panels.forEach(p => p.hidden = (p.getAttribute('data-panel') !== key));
    });
  });

  // Collapsibles
  document.querySelectorAll('.collapsible').forEach(card => {
    const t = card.querySelector('.collapseToggle');
    if (!t) return;
    t.addEventListener('click', () => card.classList.toggle('open'));
  });

  // Avatar file -> base64
  const avatarFile = document.getElementById('avatarFile');
  const avatarData = document.getElementById('avatarData');
  const avatarUrl  = document.getElementById('avatarUrl');
  const preview    = document.getElementById('avatarPreview');
  const btnClear   = document.getElementById('btnClearAvatar');

  const setPreview = (src) => {
    preview.innerHTML = src ? `<img src="${src}" alt="avatar">` : `<span class="muted">No avatar</span>`;
  };

  if (avatarFile && canEdit) {
    avatarFile.addEventListener('change', async (e) => {
      const f = e.target.files && e.target.files[0];
      if (!f) return;
      const reader = new FileReader();
      reader.onload = () => {
        const src = String(reader.result || '');
        avatarData.value = src;
        if (avatarUrl) avatarUrl.value = '';
        setPreview(src);
      };
      reader.readAsDataURL(f);
    });
  }

  if (avatarUrl && canEdit) {
    avatarUrl.addEventListener('input', () => {
      const v = avatarUrl.value.trim();
      if (v) avatarData.value = '';
      setPreview(v || avatarData.value);
    });
  }

  if (btnClear && canEdit) {
    btnClear.addEventListener('click', () => {
      if (avatarFile) avatarFile.value = '';
      if (avatarUrl) avatarUrl.value = '';
      if (avatarData) avatarData.value = '';
      setPreview('');
    });
  }

  // Dynamic lists helpers
  const q = (sel) => document.querySelector(sel);

  // Inventory add/remove
  const invList = q('#invList');
  const btnAddItem = q('#btnAddItem');
  const addInvItem = () => {
    const el = document.createElement('div');
    el.className = 'item invItem';
    el.innerHTML = `
      <div class="itemHead">
        <div><b>New Item</b></div>
        <button type="button" class="btn danger btnRemoveItem">Remove</button>
      </div>
      <div class="grid2">
        <label>Name <input name="item_name[]" value=""></label>
        <label>Qty <input type="number" min="0" name="item_qty[]" value="1"></label>
        <label>Weight <input name="item_weight[]" value=""></label>
        <label>Notes <input name="item_notes[]" value=""></label>

        <input type="hidden" name="item_id[]" value="0">
        <input type="hidden" name="item_sort[]" value="0">
      </div>
    `;
    invList.appendChild(el);
  };
  invList?.addEventListener('click', (e) => {
    const btn = e.target.closest('.btnRemoveItem');
    if (!btn) return;
    btn.closest('.invItem')?.remove();
  });
  if (btnAddItem && canEdit) btnAddItem.addEventListener('click', addInvItem);

  // Weapons add/remove
  const weaponList = q('#weaponList');
  const btnAddWeapon = q('#btnAddWeapon');
  const addWeaponItem = () => {
    const el = document.createElement('div');
    el.className = 'item weaponItem';
    el.innerHTML = `
      <div class="itemHead">
        <div><b>New Weapon</b></div>
        <button type="button" class="btn danger btnRemoveWeapon">Remove</button>
      </div>
      <div class="grid2">
        <label>Name <input name="weapon_name[]" value=""></label>
        <label>Attack bonus <input name="weapon_attack_bonus[]" value=""></label>
        <label>Damage <input name="weapon_damage[]" value=""></label>
        <label>Type <input name="weapon_type[]" value=""></label>
        <label>Notes <input name="weapon_notes[]" value=""></label>

        <input type="hidden" name="weapon_id[]" value="0">
        <input type="hidden" name="weapon_sort[]" value="0">
      </div>
    `;
    weaponList.appendChild(el);
  };
  weaponList?.addEventListener('click', (e) => {
    const btn = e.target.closest('.btnRemoveWeapon');
    if (!btn) return;
    btn.closest('.weaponItem')?.remove();
  });
  if (btnAddWeapon && canEdit) btnAddWeapon.addEventListener('click', addWeaponItem);

  // Spells add/remove
  const spellList = q('#spellList');
  const btnAddSpell = q('#btnAddSpell');
  const addSpellCard = () => {
    const el = document.createElement('div');
    el.className = 'item spellItem';
    el.innerHTML = `
      <div class="itemHead">
        <div><b>New Spell</b></div>
        <button type="button" class="btn danger btnRemoveSpell">Remove</button>
      </div>
      <div class="grid2">
        <label>Name <input name="spell_name[]" value=""></label>
        <label>Level <input name="spell_level[]" value=""></label>
        <label>School <input name="spell_school[]" value=""></label>
        <label>Prepared <input type="checkbox" name="spell_prepared[]" value="1"></label>
        <label>Notes <input name="spell_notes[]" value=""></label>

        <input type="hidden" name="spell_id[]" value="0">
        <input type="hidden" name="spell_sort[]" value="0">
        <input type="hidden" name="spell_prepared_present[]" value="1">
      </div>
    `;
    spellList.appendChild(el);
  };
  spellList?.addEventListener('click', (e) => {
    const btn = e.target.closest('.btnRemoveSpell');
    if (!btn) return;
    btn.closest('.spellItem')?.remove();
  });
  if (btnAddSpell && canEdit) btnAddSpell.addEventListener('click', addSpellCard);

  // Abilities add/remove
  const abilityList = q('#abilityList');
  const btnAddAbility = q('#btnAddAbility');
  const addAbilityCard = () => {
    const el = document.createElement('div');
    el.className = 'item abilityItem';
    el.innerHTML = `
      <div class="itemHead">
        <div><b>New Ability</b></div>
        <button type="button" class="btn danger btnRemoveAbility">Remove</button>
      </div>
      <div class="grid2">
        <label>Name <input name="ability_name[]" value=""></label>
        <label>Kind <input name="ability_kind[]" value=""></label>
        <label>Source <input name="ability_source[]" value=""></label>
        <label>Description <input name="ability_description[]" value=""></label>

        <input type="hidden" name="ability_id[]" value="0">
        <input type="hidden" name="ability_sort[]" value="0">
      </div>
    `;
    abilityList.appendChild(el);
  };
  abilityList?.addEventListener('click', (e) => {
    const btn = e.target.closest('.btnRemoveAbility');
    if (!btn) return;
    btn.closest('.abilityItem')?.remove();
  });
  if (btnAddAbility && canEdit) btnAddAbility.addEventListener('click', addAbilityCard);

  // Save
  const btnSave = q('#btnSave');
  const form = q('#charForm');

  if (btnSave && form) {
    btnSave.addEventListener('click', async () => {
      if (!canEdit) return;
      btnSave.disabled = true;

      try {
        const fd = new FormData(form);
        const res = await fetch(form.action, { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) {
          toast(data.error || 'Save failed');
        } else {
          toast('Saved');
        }
      } catch (e) {
        toast('Network error');
      } finally {
        btnSave.disabled = false;
      }
    });
  }

  // Export
  const btnExport = q('#btnExport');
  if (btnExport) {
    btnExport.addEventListener('click', () => {
      const obj = {};
      const fd = new FormData(document.getElementById('charForm'));
      fd.forEach((v,k) => {
        if (obj[k] === undefined) obj[k] = v;
        else if (Array.isArray(obj[k])) obj[k].push(v);
        else obj[k] = [obj[k], v];
      });
      const blob = new Blob([JSON.stringify(obj, null, 2)], { type:'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'character_export.json';
      a.click();
      URL.revokeObjectURL(a.href);
    });
  }

})();
</script>

</body>
</html>
