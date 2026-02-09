<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

require_login();

$q      = trim((string)($_GET['q'] ?? ''));
$level  = trim((string)($_GET['level'] ?? ''));
$school = trim((string)($_GET['school'] ?? ''));
$sort   = trim((string)($_GET['sort'] ?? 'name')); // name|level|school
$dir    = strtolower(trim((string)($_GET['dir'] ?? 'asc'))); // asc|desc
$changedOnly = isset($_GET['changed']) && $_GET['changed'] === '1';

// валідація
$allowedSort = ['name', 'level', 'school'];
if (!in_array($sort, $allowedSort, true)) $sort = 'name';
if (!in_array($dir, ['asc','desc'], true)) $dir = 'asc';

// WHERE + params
$where  = "WHERE is_published=1";
$params = [];

if ($q !== '') {
  $where .= " AND name LIKE ?";
  $params[] = "%{$q}%";
}
if ($level !== '' && ctype_digit($level)) {
  $where .= " AND level = ?";
  $params[] = (int)$level;
}
if ($school !== '') {
  $where .= " AND school = ?";
  $params[] = $school;
}
if ($changedOnly) {
  $where .= " AND TRIM(COALESCE(override_text, '')) <> ''";
}

// колейшн для нормального алфавіту (укр)
$collate = " COLLATE utf8mb4_unicode_ci ";

// ORDER BY
if ($sort === 'name') {
  $order = "ORDER BY name{$collate} {$dir}";
} elseif ($sort === 'school') {
  $order = "ORDER BY school{$collate} {$dir}, name{$collate} asc";
} else { // level
  $order = "ORDER BY level {$dir}, name{$collate} asc";
}

// SELECT (беремо override_text і рахуємо has_override як в адмінці)
$sql = "SELECT
          slug,
          name,
          level,
          school,
          override_text
        FROM spells
        {$where}
        {$order}";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// список шкіл (тільки опубліковані)
$schools = db()->query("
  SELECT DISTINCT school
  FROM spells
  WHERE is_published=1 AND school <> ''
  ORDER BY school COLLATE utf8mb4_unicode_ci
")->fetchAll();
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <title>Заклинання</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:1000px;margin:20px auto;padding:0 12px}
    input,select,button{padding:6px 8px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:12px 0}
    .muted{opacity:.75}
    .badge{display:inline-block;border:1px solid #ddd;border-radius:999px;padding:1px 7px;font-size:12px;opacity:.85;margin-left:6px}
    .warn{border-color:#f0c36d;background:#fff9e6}
    label.chk{display:flex;gap:6px;align-items:center}
  </style>
</head>
<body>

<h1>Заклинання</h1>

<form method="get" class="row">
  <input name="q" placeholder="пошук..." value="<?= htmlspecialchars($q) ?>">

  <select name="level">
    <option value="">будь-який рівень</option>
    <?php for ($i=0; $i<=9; $i++): ?>
      <option value="<?= $i ?>" <?= ($level===(string)$i ? 'selected' : '') ?>><?= $i ?></option>
    <?php endfor; ?>
  </select>

  <select name="school">
    <option value="">будь-яка школа</option>
    <?php foreach ($schools as $s): ?>
      <option value="<?= htmlspecialchars((string)$s['school']) ?>" <?= ($school===(string)$s['school'] ? 'selected' : '') ?>>
        <?= htmlspecialchars((string)$s['school']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <label class="chk">
    <input type="checkbox" name="changed" value="1" <?= $changedOnly ? 'checked' : '' ?>>
    тільки змінені
  </label>

  <select name="sort">
    <option value="name"   <?= ($sort==='name'?'selected':'') ?>>сортувати: назва</option>
    <option value="level"  <?= ($sort==='level'?'selected':'') ?>>сортувати: рівень</option>
    <option value="school" <?= ($sort==='school'?'selected':'') ?>>сортувати: школа</option>
  </select>

  <select name="dir">
    <option value="asc"  <?= ($dir==='asc'?'selected':'') ?>>↑</option>
    <option value="desc" <?= ($dir==='desc'?'selected':'') ?>>↓</option>
  </select>

  <button type="submit">Знайти</button>

  <?php if ($q!=='' || $level!=='' || $school!=='' || $changedOnly || $sort!=='name' || $dir!=='asc'): ?>
    <a class="muted" href="/spells.php">скинути</a>
  <?php endif; ?>
</form>

<p class="muted">Знайдено: <?= count($rows) ?></p>

<ul>
<?php foreach ($rows as $r): ?>
  <?php $modified = trim((string)($r['override_text'] ?? '')) !== ''; ?>
  <li>
    <a href="/spell.php?slug=<?= urlencode((string)$r['slug']) ?>">
      <?= htmlspecialchars((string)$r['name']) ?>
    </a>

    <?php if ($modified): ?>
      <span class="badge warn">changed</span>
    <?php endif; ?>

    <span class="muted">
      — lvl <?= (int)$r['level'] ?>, <?= htmlspecialchars((string)$r['school']) ?>
    </span>
  </li>
<?php endforeach; ?>
</ul>

<p><a href="/dashboard.php">← в кабінет</a></p>

</body>
</html>
