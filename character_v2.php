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
$ch['avatar_url'] = $ch['avatar_url'] ?? null;
$ch['avatar_data'] = $ch['avatar_data'] ?? null;

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

// Skills (unused in this v2 block, kept for future)
$skillsSt = db()->prepare("SELECT skill_code, proficiency_level, bonus_override
                           FROM character_skills
                           WHERE character_id=?");
$skillsSt->execute([$charId]);
$skillsRows = $skillsSt->fetchAll() ?: [];
$skillsMap = [];
foreach ($skillsRows as $r) {
  $skillsMap[(string)$r['skill_code']] = [
    'proficiency_level' => (int)($r['proficiency_level'] ?? 0),
    'bonus_override' => ($r['bonus_override'] === null ? null : (int)$r['bonus_override']),
  ];
}

// Proficiencies
$profsSt = db()->prepare("SELECT prof_type, name
                          FROM character_proficiencies
                          WHERE character_id=?
                          ORDER BY prof_type, name");
$profsSt->execute([$charId]);
$profsRows = $profsSt->fetchAll() ?: [];
$profs = [
  'weapons' => [],
  'armor' => [],
  'tools' => [],
  'languages' => [],
  'vehicles' => [],
];
foreach ($profsRows as $r) {
  $type = (string)($r['prof_type'] ?? '');
  $name = trim((string)($r['name'] ?? ''));
  if ($name === '' || !isset($profs[$type])) continue;
  $profs[$type][] = $name;
}

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
$spells = $sp->fetchAll() ?: [];

$ab = db()->prepare("SELECT * FROM character_abilities WHERE character_id=? ORDER BY sort_order,id");
$ab->execute([$charId]);
$abilities = $ab->fetchAll() ?: [];

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
  // table may not exist yet
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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
  <title><?= h($ch['name'] ?? 'Character') ?> (v2)</title>

  <link rel="stylesheet" href="/inc/char.css?v=1">
  <link rel="stylesheet" href="/inc/vendor/gridstack/gridstack.min.css">

  <style>
    body{margin:0}
    .topbar{
      position: sticky; top: 0; z-index: 50;
      display:flex; justify-content:space-between; align-items:center; gap:10px;
      padding: 10px 12px;
      background: rgba(18,18,22,.92);
      border-bottom: 1px solid rgba(255,255,255,.10);
      backdrop-filter: blur(8px);
    }
    .topbar .left{display:flex; align-items:center; gap:10px}
    .topbar .title{font-weight:700}
    .gridWrap{padding: 12px}

    .grid-stack-item-content{
      background: var(--panel, #171822);
      border: 1px solid var(--border, rgba(255,255,255,.10));
      border-radius: 14px;
      overflow: hidden;
      display:flex;
      flex-direction:column;
    }
    .blockHead{
      padding: 10px 12px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      border-bottom: 1px solid rgba(255,255,255,.10);
      cursor: default;
      user-select:none;
    }
    body.customize .blockHead{ cursor: move; }
    .blockBody{ padding: 12px; overflow:auto; }

    .btnRow{display:flex; gap:8px; flex-wrap:wrap}
    .smallBtn{
      padding: 6px 10px; border-radius: 10px; border: 1px solid rgba(255,255,255,.12);
      background: #2b2c36; color: #fff; cursor:pointer; font-size: 13px;
    }
    .smallBtn[disabled]{opacity:.6; cursor:not-allowed}
    .muted{opacity:.75}

    /* Modal */
    .modalBack{
      position: fixed; inset: 0;
      background: rgba(0,0,0,.55);
      display:flex; align-items:center; justify-content:center;
      z-index: 200;
    }
    .modalBack[hidden]{ display:none !important; }
    body.modal-open{ overflow:hidden; }
    .modalWin{
      width: min(760px, 92vw);
      max-height: min(80vh, 720px);
      background: #171822;
      border: 1px solid rgba(255,255,255,.12);
      border-radius: 16px;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }
    .modalHead, .modalFoot{
      padding: 10px 12px;
      border-bottom: 1px solid rgba(255,255,255,.10);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .modalFoot{
      border-bottom: 0;
      border-top: 1px solid rgba(255,255,255,.10);
      justify-content:flex-end;
    }
    .modalTitle{font-weight:800}
    .modalBody{ padding: 12px; overflow:auto; }
    .blockList{
      display:grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap:10px;
    }
    .blockRow{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding: 10px 12px;
      border: 1px solid rgba(255,255,255,.10);
      border-radius: 12px;
      background: rgba(255,255,255,.03);
    }
    .blockRow .name{font-weight:700}
    .toggle{ display:flex; align-items:center; gap:8px; }
    .toggle input{ width: 18px; height: 18px; }
    .grid-stack-item-content.is-collapsed .blockBody{ display:none; }

    /* inner layout */
    .twoColGrid{display:grid; grid-template-columns: 1fr 1fr; gap:10px;}
    @media (max-width: 980px){ .twoColGrid{grid-template-columns: 1fr;} }
    .card{
      border: 1px solid rgba(255,255,255,.10);
      border-radius: 14px;
      background: rgba(255,255,255,.03);
      overflow:hidden;
    }
    .compactCard h2{
      margin:0;
      padding: 10px 12px;
      border-bottom: 1px solid rgba(255,255,255,.10);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      font-size: 14px;
    }
    .cardBody{ padding: 12px; }
    .hint{ opacity:.75; font-size: 12px; }
    .segToggle{
      display:inline-flex;
      gap:6px;
      padding: 2px;
      border: 1px solid rgba(255,255,255,.12);
      border-radius: 12px;
      background: rgba(0,0,0,.15);
    }
    .segBtn{
      padding: 6px 10px;
      border-radius: 10px;
      border: 0;
      background: transparent;
      color: rgba(255,255,255,.85);
      cursor:pointer;
      font-size: 12px;
    }
    .segBtn.isActive{ background: rgba(255,255,255,.10); color:#fff; }
    .isHidden{ display:none !important; }

    /* inputs in editor */
    #spellsEditor input, #spellsEditor textarea{
      width:100%;
      box-sizing:border-box;
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,.12);
      background:#12131a;
      color:#fff;
      outline:none;
    }
    #spellsEditor textarea{ resize: vertical; }
    #spellsEditor label{ display:block; font-size:12px; opacity:.9; }
  </style>
</head>

<body>

<header class="topbar">
  <div class="left">
    <a class="smallBtn" href="/characters.php">← Назад</a>
    <div class="title"><?= h($ch['name'] ?? '') ?> <span class="muted">v2</span></div>
  </div>

  <div class="btnRow">
    <button class="smallBtn" type="button" id="btnBlocks">Blocks</button>
    <button class="smallBtn" type="button" id="btnCustomize">Customize</button>
    <button class="smallBtn" type="button" id="btnReset">Reset layout</button>
    <button class="smallBtn" type="button" id="btnSave" <?= $canEdit ? '' : 'disabled' ?>>Save</button>
  </div>
</header>

<form id="charForm" method="post" action="/api/character_save.php">
  <input type="hidden" name="id" value="<?= (int)$charId ?>">
  <input type="hidden" name="spells_json" id="spells_json" value="">

  <div class="gridWrap">
    <div class="grid-stack" id="grid"></div>
  </div>
</form>

<!-- Blocks modal -->
<div class="modalBack" id="blocksModal" hidden>
  <div class="modalWin" role="dialog" aria-modal="true" aria-labelledby="blocksTitle">
    <div class="modalHead">
      <div class="modalTitle" id="blocksTitle">Blocks</div>
      <button class="smallBtn" type="button" id="btnBlocksClose">✕</button>
    </div>

    <div class="modalBody">
      <div class="muted" style="margin-bottom:10px">
        Вмикай/вимикай блоки. Розкладка збережеться.
      </div>
      <div class="blockList" id="blocksList"></div>
    </div>

    <div class="modalFoot">
      <button class="smallBtn" type="button" id="btnBlocksShowAll">Show all</button>
      <button class="smallBtn" type="button" id="btnBlocksHideAll">Hide all</button>
      <button class="smallBtn" type="button" id="btnBlocksDone">Done</button>
    </div>
  </div>
</div>

<script src="/inc/vendor/gridstack/gridstack-all.min.js"></script>
<script>
(() => {
  const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
  const charId  = <?= (int)$charId ?>;

  const gridEl = document.getElementById('grid');
  if (!gridEl || !window.GridStack) {
    console.error('GridStack not loaded or grid element missing');
    return;
  }

  const storageKey = `charLayout:${charId}`;
  const hiddenKey  = `charHidden:${charId}`;

  const loadLayout = () => {
    try { return JSON.parse(localStorage.getItem(storageKey) || 'null'); }
    catch { return null; }
  };
  const saveLayout = (grid) => {
    const layout = grid.save(false);
    localStorage.setItem(storageKey, JSON.stringify(layout));
  };

  const loadHidden = () => {
    try {
      const arr = JSON.parse(localStorage.getItem(hiddenKey) || '[]');
      return new Set(Array.isArray(arr) ? arr : []);
    } catch { return new Set(); }
  };
  const saveHidden = (set) => {
    localStorage.setItem(hiddenKey, JSON.stringify([...set]));
  };

  const hiddenSet = loadHidden();

  // Grid init
  const grid = GridStack.init({
    float: true,
    margin: 8,
    cellHeight: 90,
    draggable: { handle: '.blockHead' },
    disableDrag: true,
    disableResize: true
  }, gridEl);

  // Create widget
  const makeBlock = (id, title, contentHtml, x, y, w, h) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'grid-stack-item';

    wrapper.setAttribute('gs-id', id);
    wrapper.setAttribute('gs-x', String(x));
    wrapper.setAttribute('gs-y', String(y));
    wrapper.setAttribute('gs-w', String(w));
    wrapper.setAttribute('gs-h', String(h));

    wrapper.innerHTML = `
      <div class="grid-stack-item-content" data-block="${id}">
        <div class="blockHead">
          <div><b>${title}</b></div>
          <div class="btnRow">
            <button class="smallBtn" type="button" data-act="collapse">▾</button>
            <button class="smallBtn" type="button" data-act="hide">✕</button>
          </div>
        </div>
        <div class="blockBody">${contentHtml}</div>
      </div>
    `;

    gridEl.appendChild(wrapper);
    grid.makeWidget(wrapper);
    return wrapper;
  };

  // Blocks
  const BLOCKS = {
    identity: {
      title: 'Identity',
      html: () => `
        <div class="grid2">
          <label>Імʼя персонажа
            <input name="name" placeholder="напр. Флор" value="<?= h($ch['name'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
          <label>Імʼя гравця
            <input name="player_name" value="<?= h($ch['player_name'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>

        <div class="grid2">
          <label>Клас
            <input name="class_name" value="<?= h($ch['class_name'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
          <label>Рівень
            <input name="level" placeholder="1" value="<?= (int)($ch['level'] ?? 1) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>

        <div class="grid2">
          <label>Раса
            <input name="race" value="<?= h($ch['race'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
          <label>Світогляд
            <input name="alignment" placeholder="напр. хаотично-добрий" value="<?= h($ch['alignment'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>

        <div class="grid2">
          <label>Передісторія
            <input name="background" value="<?= h($ch['background'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
          <label>Очки досвіду
            <input name="xp" value="<?= h($ch['xp'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>

        <div class="grid2">
          <label>Риси характеру
            <textarea name="traits" rows="4" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes['traits'] ?? '') ?></textarea>
          </label>
          <label>Ідеали
            <textarea name="ideals" rows="4" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes['ideals'] ?? '') ?></textarea>
          </label>
          <label>Прив'язаності
            <textarea name="bonds" rows="4" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes['bonds'] ?? '') ?></textarea>
          </label>
          <label>Слабкість
            <textarea name="flaws" rows="4" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes['flaws'] ?? '') ?></textarea>
          </label>
        </div>

        <label>Backstory
          <textarea name="backstory" rows="10" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes['backstory'] ?? '') ?></textarea>
        </label>
      `,
      def: { x:0, y:0, w:6, h:5 },
    },

    profs: {
      title: 'Proficiencies',
      html: () => `
        <?php
          $extra = 6;
          $profTypes = [
            'weapons'   => 'Weapons',
            'armor'     => 'Armor',
            'tools'     => 'Tools',
            'languages' => 'Languages',
            'vehicles'  => 'Vehicles / Transport',
          ];
        ?>

        <?php foreach ($profTypes as $t => $label): ?>
          <?php
            $list = $profs[$t] ?? [];
            $total = max(count($list) + $extra, $extra);
          ?>
          <div class="profBlock">
            <div class="profHead">
              <span><?= h($label) ?></span>
              <span class="muted" style="font-size:12px">вписуй будь-що, порожні ігноруються</span>
            </div>

            <div class="profList">
              <?php for ($i=0; $i<$total; $i++): ?>
                <?php $val = $list[$i] ?? ''; ?>
                <div class="profRow">
                  <input
                    name="prof[<?= h($t) ?>][]"
                    value="<?= h($val) ?>"
                    placeholder="—"
                    <?= $canEdit ? '' : 'disabled' ?>
                  >
                </div>
              <?php endfor; ?>
            </div>
          </div>
        <?php endforeach; ?>
      `,
      def: { x:0, y:6, w:6, h:5 },
    },

    skills_spells: {
      title: 'Skills / Spells',
      html: () => `
        <section class="twoColGrid">

          <section class="card compactCard" data-collapse="features">
            <h2 class="featuresHead">
              <span>Features</span>
              <span class="segToggle" id="featuresModeToggle">
                <button type="button" class="segBtn" data-mode="passive">Пасивні</button>
                <button type="button" class="segBtn" data-mode="active">Активні</button>
              </span>
            </h2>

            <div class="cardBody">
              <div id="featuresPassivePane">
                <div class="row">
                  <button type="button" class="smallBtn" id="addFeaturePassive">+ Add passive</button>
                </div>
                <div class="hint" style="margin-top:10px;">Пасивні особливості (клас/раса/фіти/інше).</div>
                <div id="featuresPassiveEditor"></div>
              </div>

              <div id="featuresActivePane" class="isHidden">
                <div class="row">
                  <button type="button" class="smallBtn" id="addFeatureActive">+ Add active</button>
                </div>
                <div class="hint" style="margin-top:10px;">Активні дії/уміння (потребують активації).</div>
                <div id="featuresActiveEditor"></div>
              </div>
            </div>
          </section>

          <section class="card compactCard" data-collapse="spells">
            <h2 class="spellsHead">
              <span>Spells</span>
            </h2>

            <div class="cardBody">
              <div class="row" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <button type="button" class="smallBtn" id="addSpell">+ Add spell</button>

                <div style="display:flex; gap:6px; align-items:center;">
                  <input id="spellSlug" placeholder="slug (напр. magic-missile)" style="height:30px; padding:0 10px; border-radius:10px; border:1px solid rgba(255,255,255,.12); background:#12131a; color:#fff; width:min(320px, 60vw);">
                  <button type="button" class="smallBtn" id="loadSpell">Load from DB</button>
                </div>

                <span class="muted" style="font-size:12px;">Підтягує з таблиці spells по slug.</span>
              </div>

              <div id="spellsHud" style="margin-top:10px;"></div>

              <div class="hint" style="margin-top:10px;">Редактор заклинань (один блок).</div>
              <div id="spellsEditor"></div>
            </div>
          </section>

        </section>
      `,
      def: { x:0, y:12, w:8, h:5 },
    },

    notes: {
      title: 'Notes',
      html: () => `
        <label>Session notes
          <textarea name="session_notes" rows="8" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes["session_notes"] ?? "") ?></textarea>
        </label>
        <label>Quick notes
          <textarea name="quick_notes" rows="6" <?= $canEdit ? '' : 'disabled' ?>><?= h($notes["quick_notes"] ?? "") ?></textarea>
        </label>
      `,
      def: { x:4, y:0, w:4, h:3 },
    },
  };

  // Helpers
  const findWidgetById = (id) => gridEl.querySelector(`.grid-stack-item[gs-id="${CSS.escape(id)}"]`);

  const hideBlock = (id) => {
    const el = findWidgetById(id);
    if (el) grid.removeWidget(el, true);
    hiddenSet.add(id);
    saveHidden(hiddenSet);
    saveLayout(grid);
  };

  // ===== Features toggle =====
  const featuresModeKey = `charFeaturesMode:${charId}`;

  const setupFeaturesToggle = () => {
    const toggle = document.getElementById('featuresModeToggle');
    const passivePane = document.getElementById('featuresPassivePane');
    const activePane  = document.getElementById('featuresActivePane');
    if (!toggle || !passivePane || !activePane) return;

    const setMode = (mode) => {
      const btns = toggle.querySelectorAll('.segBtn');
      btns.forEach(b => b.classList.toggle('isActive', b.dataset.mode === mode));

      if (mode === 'active') {
        passivePane.classList.add('isHidden');
        activePane.classList.remove('isHidden');
      } else {
        activePane.classList.add('isHidden');
        passivePane.classList.remove('isHidden');
      }
      localStorage.setItem(featuresModeKey, mode);
    };

    if (!toggle.dataset.bound) {
      toggle.addEventListener('click', (e) => {
        const btn = e.target.closest('.segBtn');
        if (!btn) return;
        setMode(btn.dataset.mode || 'passive');
      });
      toggle.dataset.bound = '1';
    }

    const savedMode = localStorage.getItem(featuresModeKey) || 'passive';
    setMode(savedMode);
  };

  // ===== Spells editor (NEW) =====
  const spellsKey = `charSpellsData:${charId}`;

  const INITIAL_SPELLS = <?= json_encode(array_map(function($r){
    return [
      'id' => (int)($r['id'] ?? 0),
      'slug' => (string)($r['slug'] ?? ''),
      'name' => (string)($r['name'] ?? ''),
      'level' => (int)($r['level'] ?? 0),
      'school' => (string)($r['school'] ?? ''),
      'casting_time' => (string)($r['casting_time'] ?? ''),
      'range_txt' => (string)($r['range_txt'] ?? ''),
      'duration' => (string)($r['duration'] ?? ''),
      'components' => (string)($r['components'] ?? ''),
      'base_ref' => (string)($r['base_ref'] ?? ''),
      'base_summary' => (string)($r['base_summary'] ?? ''),
      'override_text' => (string)($r['override_text'] ?? ''),
      'notes' => (string)($r['notes'] ?? ''),
      'sort_order' => (int)($r['sort_order'] ?? 0),
    ];
  }, $spells), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const loadSpellsData = () => {
    try {
      const v = JSON.parse(localStorage.getItem(spellsKey) || 'null');
      if (v && Array.isArray(v)) return v;
    } catch {}
    return Array.isArray(INITIAL_SPELLS) ? INITIAL_SPELLS : [];
  };

  const saveSpellsData = (arr) => {
    localStorage.setItem(spellsKey, JSON.stringify(arr));
    const hid = document.getElementById('spells_json');
    if (hid) hid.value = JSON.stringify(arr);
  };

  let spellsData = loadSpellsData();
  let activeSpellId = null;

  const uidGen = () => Math.floor(Date.now() + Math.random() * 10000);
  const findSpell = (id) => spellsData.find(s => String(s.id) === String(id));

  function escapeHtml(str){
    return String(str ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  const renderSpellsHud = () => {
    const hud = document.getElementById('spellsHud');
    if (!hud) return;

    if (!spellsData.length) {
      hud.innerHTML = `<div class="muted">Нема заклинань. Додай своє або завантаж з бази.</div>`;
      return;
    }

    const rows = spellsData
      .slice()
      .sort((a,b) => (a.sort_order||0) - (b.sort_order||0) || String(a.name).localeCompare(String(b.name)))
      .map(s => {
        const isAct = (String(s.id) === String(activeSpellId));
        const lvl = (s.level ?? 0);
        const title = (s.name || '(no name)');
        return `
          <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; border:1px solid rgba(255,255,255,.10); border-radius:12px; background:${isAct ? 'rgba(255,255,255,.06)' : 'rgba(255,255,255,.03)'}; margin-bottom:8px;">
            <button type="button" class="smallBtn" data-spell-open="${s.id}" style="flex:1; text-align:left; background:transparent;">
              <b>${escapeHtml(title)}</b> <span class="muted" style="font-size:12px">lvl ${lvl}${s.slug ? ` · ${escapeHtml(s.slug)}` : ''}</span>
            </button>
            <button type="button" class="smallBtn" data-spell-del="${s.id}">✕</button>
          </div>
        `;
      })
      .join('');

    hud.innerHTML = rows;
  };

  const renderSpellEditor = () => {
    const ed = document.getElementById('spellsEditor');
    if (!ed) return;

    const s = activeSpellId ? findSpell(activeSpellId) : null;
    if (!s) {
      ed.innerHTML = `<div class="muted">Обери заклинання зі списку вище.</div>`;
      return;
    }

    ed.innerHTML = `
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
        <label>Назва
          <input data-f="name" value="${escapeHtml(s.name||'')}">
        </label>
        <label>Slug (опц.)
          <input data-f="slug" value="${escapeHtml(s.slug||'')}">
        </label>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;">
        <label>Рівень
          <input data-f="level" type="number" min="0" max="9" value="${Number(s.level||0)}">
        </label>
        <label>Школа
          <input data-f="school" value="${escapeHtml(s.school||'')}">
        </label>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;">
        <label>Casting time
          <input data-f="casting_time" value="${escapeHtml(s.casting_time||'')}">
        </label>
        <label>Range
          <input data-f="range_txt" value="${escapeHtml(s.range_txt||'')}">
        </label>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;">
        <label>Duration
          <input data-f="duration" value="${escapeHtml(s.duration||'')}">
        </label>
        <label>Components
          <input data-f="components" value="${escapeHtml(s.components||'')}">
        </label>
      </div>

      <div style="margin-top:10px;">
        <label>Base ref
          <input data-f="base_ref" value="${escapeHtml(s.base_ref||'')}">
        </label>
      </div>

      <div style="margin-top:10px;">
        <label>Base summary
          <textarea data-f="base_summary" rows="6">${escapeHtml(s.base_summary||'')}</textarea>
        </label>
      </div>

      <div style="margin-top:10px;">
        <label>Campaign changes (override)
          <textarea data-f="override_text" rows="6">${escapeHtml(s.override_text||'')}</textarea>
        </label>
      </div>

      <div style="margin-top:10px;">
        <label>Notes (опц.)
          <textarea data-f="notes" rows="4">${escapeHtml(s.notes||'')}</textarea>
        </label>
      </div>
    `;
  };

  const ensureSpellsHiddenSynced = () => saveSpellsData(spellsData);

  const setupSpellsEditor = () => {
    const addBtn = document.getElementById('addSpell');
    const loadBtn = document.getElementById('loadSpell');
    const slugInp = document.getElementById('spellSlug');
    const hud = document.getElementById('spellsHud');
    const ed = document.getElementById('spellsEditor');

    if (!hud || !ed) return;

    if (!hud.dataset.bound) {
      hud.addEventListener('click', (e) => {
        const open = e.target.closest('[data-spell-open]');
        const del  = e.target.closest('[data-spell-del]');

        if (open) {
          activeSpellId = open.getAttribute('data-spell-open');
          renderSpellsHud();
          renderSpellEditor();
        }

        if (del) {
          const id = del.getAttribute('data-spell-del');
          spellsData = spellsData.filter(s => String(s.id) !== String(id));
          if (String(activeSpellId) === String(id)) activeSpellId = null;
          ensureSpellsHiddenSynced();
          renderSpellsHud();
          renderSpellEditor();
        }
      });
      hud.dataset.bound = '1';
    }

    if (!ed.dataset.bound) {
      ed.addEventListener('input', (e) => {
        const inp = e.target.closest('[data-f]');
        if (!inp || !activeSpellId) return;

        const s = findSpell(activeSpellId);
        if (!s) return;

        const f = inp.getAttribute('data-f');
        let v = inp.value;

        if (f === 'level' || f === 'sort_order') v = parseInt(v || '0', 10) || 0;
        s[f] = v;

        ensureSpellsHiddenSynced();
        renderSpellsHud();
      });
      ed.dataset.bound = '1';
    }

    if (addBtn && !addBtn.dataset.bound) {
      addBtn.addEventListener('click', () => {
        const id = uidGen();
        const nextOrder = (spellsData.reduce((m,s)=>Math.max(m, (s.sort_order||0)), 0) || 0) + 10;

        spellsData.push({
          id,
          slug: '',
          name: '',
          level: 0,
          school: '',
          casting_time: '',
          range_txt: '',
          duration: '',
          components: '',
          base_ref: '',
          base_summary: '',
          override_text: '',
          notes: '',
          sort_order: nextOrder,
        });

        activeSpellId = id;
        ensureSpellsHiddenSynced();
        renderSpellsHud();
        renderSpellEditor();
      });
      addBtn.dataset.bound = '1';
    }

    if (loadBtn && !loadBtn.dataset.bound) {
      loadBtn.addEventListener('click', async () => {
        const slug = (slugInp?.value || '').trim();
        if (!slug) { alert('Вкажи slug'); return; }

        const r = await fetch(`/api/spell_lookup.php?slug=${encodeURIComponent(slug)}`);
        if (!r.ok) { alert('Не знайдено (або помилка): ' + r.status); return; }

        const js = await r.json();
        if (!js.ok || !js.spell) { alert(js.error || 'Помилка'); return; }

        const spell = js.spell;

        const id = uidGen();
        const nextOrder = (spellsData.reduce((m,s)=>Math.max(m, (s.sort_order||0)), 0) || 0) + 10;

        spellsData.push({
          id,
          slug: spell.slug || slug,
          name: spell.name || '',
          level: Number(spell.level || 0),
          school: spell.school || '',
          casting_time: spell.casting_time || '',
          range_txt: spell.range_txt || '',
          duration: spell.duration || '',
          components: spell.components || '',
          base_ref: spell.base_ref || '',
          base_summary: spell.base_summary || '',
          override_text: spell.override_text || '',
          notes: '',
          sort_order: nextOrder,
        });

        activeSpellId = id;
        ensureSpellsHiddenSynced();
        renderSpellsHud();
        renderSpellEditor();
      });
      loadBtn.dataset.bound = '1';
    }

    if (!activeSpellId && spellsData.length) activeSpellId = spellsData[0].id;
    ensureSpellsHiddenSynced();
    renderSpellsHud();
    renderSpellEditor();
  };

  // Show block
  const showBlock = (id) => {
    const cfg = BLOCKS[id];
    if (!cfg) return;

    if (findWidgetById(id)) {
      hiddenSet.delete(id);
      saveHidden(hiddenSet);
      setupFeaturesToggle();
      setupSpellsEditor();
      return;
    }

    const d = cfg.def || {x:0,y:0,w:4,h:2};
    makeBlock(id, cfg.title, cfg.html(), d.x, d.y, d.w, d.h);

    hiddenSet.delete(id);
    saveHidden(hiddenSet);
    saveLayout(grid);

    setupFeaturesToggle();
    setupSpellsEditor();
  };

  const spawnFromLayout = (layoutArr) => {
    grid.removeAll(true);
    for (const it of layoutArr) {
      const id = it.id || it['gs-id'];
      if (!id || hiddenSet.has(id)) continue;
      const cfg = BLOCKS[id];
      if (!cfg) continue;
      makeBlock(id, cfg.title, cfg.html(), it.x ?? 0, it.y ?? 0, it.w ?? 4, it.h ?? 2);
    }
    grid.load(layoutArr);
  };

  const spawnDefault = () => {
    for (const [id, cfg] of Object.entries(BLOCKS)) {
      if (hiddenSet.has(id)) continue;
      const d = cfg.def || {x:0,y:0,w:4,h:2};
      makeBlock(id, cfg.title, cfg.html(), d.x, d.y, d.w, d.h);
    }
  };

  // Start
  const saved = loadLayout();
  if (saved && Array.isArray(saved) && saved.length) spawnFromLayout(saved);
  else spawnDefault();

  // init after blocks exist
  setupFeaturesToggle();
  setupSpellsEditor();

  grid.on('change', () => saveLayout(grid));

  // Customize toggle
  const btnCustomize = document.getElementById('btnCustomize');
  let customizing = false;
  btnCustomize?.addEventListener('click', () => {
    customizing = !customizing;
    document.body.classList.toggle('customize', customizing);
    grid.enableMove(customizing);
    grid.enableResize(customizing);
    btnCustomize.textContent = customizing ? 'Done' : 'Customize';
  });

  // Reset layout (also clears hidden)
  document.getElementById('btnReset')?.addEventListener('click', () => {
    localStorage.removeItem(storageKey);
    localStorage.removeItem(hiddenKey);
    location.reload();
  });

  // Collapse/Hide buttons inside blocks
  gridEl.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;

    const act = btn.getAttribute('data-act');
    const item = btn.closest('.grid-stack-item');
    const content = btn.closest('.grid-stack-item-content');
    if (!item || !content) return;

    if (act === 'hide') {
      const id = item.getAttribute('gs-id') || '';
      grid.removeWidget(item, true);
      if (id) {
        hiddenSet.add(id);
        saveHidden(hiddenSet);
      }
      saveLayout(grid);
    }

    if (act === 'collapse') {
      const body = content.querySelector('.blockBody');
      if (!body) return;

      const gsItem = item;
      const node = gsItem.gridstackNode;
      if (!node) return;

      const collapsed = content.classList.contains('is-collapsed');

      if (!collapsed) {
        if (!gsItem.dataset.prevH) gsItem.dataset.prevH = String(node.h || 2);
        content.classList.add('is-collapsed');
        btn.textContent = '▸';
        grid.update(gsItem, { h: 1 });
      } else {
        const prevH = parseInt(gsItem.dataset.prevH || '2', 10) || 2;
        content.classList.remove('is-collapsed');
        btn.textContent = '▾';
        grid.update(gsItem, { h: prevH });
      }

      saveLayout(grid);
    }
  });

  // Save character
  document.getElementById('btnSave')?.addEventListener('click', async () => {
    if (!canEdit) return;
    const form = document.getElementById('charForm');
    const fd = new FormData(form);
    const res = await fetch(form.action, { method:'POST', body: fd });
    if (!res.ok) alert('Save failed: ' + res.status);
    else alert('Saved');
  });

  // Blocks modal
  const modal  = document.getElementById('blocksModal');
  const listEl = document.getElementById('blocksList');

  const renderBlocksList = () => {
    listEl.innerHTML = '';
    for (const id of Object.keys(BLOCKS)) {
      const row = document.createElement('div');
      row.className = 'blockRow';
      const shown = !hiddenSet.has(id);
      row.innerHTML = `
        <div class="name">${BLOCKS[id].title}</div>
        <label class="toggle">
          <input type="checkbox" ${shown ? 'checked' : ''} data-block-toggle="${id}">
          <span class="muted">${shown ? 'shown' : 'hidden'}</span>
        </label>
      `;
      listEl.appendChild(row);
    }
  };

  const openModal = () => { modal.hidden = false; renderBlocksList(); };
  const closeModal = () => { modal.hidden = true; };

  document.getElementById('btnBlocks')?.addEventListener('click', openModal);
  document.getElementById('btnBlocksClose')?.addEventListener('click', closeModal);
  document.getElementById('btnBlocksDone')?.addEventListener('click', closeModal);

  modal?.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', (e) => { if (!modal.hidden && e.key === 'Escape') closeModal(); });

  listEl?.addEventListener('change', (e) => {
    const cb = e.target.closest('input[data-block-toggle]');
    if (!cb) return;
    const id = cb.getAttribute('data-block-toggle');
    if (!id) return;
    if (cb.checked) showBlock(id);
    else hideBlock(id);
    renderBlocksList();
  });

  document.getElementById('btnBlocksShowAll')?.addEventListener('click', () => {
    Object.keys(BLOCKS).forEach(id => showBlock(id));
    renderBlocksList();
  });
  document.getElementById('btnBlocksHideAll')?.addEventListener('click', () => {
    Object.keys(BLOCKS).forEach(id => hideBlock(id));
    renderBlocksList();
  });

})();
</script>
</body>
</html>
