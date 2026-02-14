<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

$user = require_admin();


$q      = trim((string)($_GET['q'] ?? ''));
$level  = trim((string)($_GET['level'] ?? ''));
$school = trim((string)($_GET['school'] ?? ''));
$pub    = trim((string)($_GET['pub'] ?? 'all')); // all|pub|hidden
$sort   = trim((string)($_GET['sort'] ?? 'name'));
$dir    = strtolower(trim((string)($_GET['dir'] ?? 'asc')));

$allowedSort = ['name', 'level', 'school', 'updated_at'];
if (!in_array($sort, $allowedSort, true)) $sort = 'name';
if (!in_array($dir, ['asc','desc'], true)) $dir = 'asc';
if (!in_array($pub, ['all','pub','hidden'], true)) $pub = 'all';

$where = "WHERE 1=1";
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
if ($pub === 'pub') {
  $where .= " AND is_published=1";
} elseif ($pub === 'hidden') {
  $where .= " AND is_published=0";
}

$collate = " COLLATE utf8mb4_unicode_ci ";

if ($sort === 'name') {
  $order = "ORDER BY name{$collate} {$dir}";
} elseif ($sort === 'school') {
  $order = "ORDER BY school{$collate} {$dir}, name{$collate} asc";
} elseif ($sort === 'level') {
  $order = "ORDER BY level {$dir}, name{$collate} asc";
} else { // updated_at
  $order = "ORDER BY updated_at {$dir}";
}

$sql = "SELECT id, slug, name, level, school, is_published, override_text, updated_at
        FROM spells {$where} {$order}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$schools = db()->query("SELECT DISTINCT school FROM spells WHERE school<>'' ORDER BY school COLLATE utf8mb4_unicode_ci")->fetchAll();
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <title>Адмін: Заклинання</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 12px}
    input,select,button{padding:6px 8px}
    table{border-collapse:collapse;width:100%;margin-top:12px}
    th,td{border:1px solid #ddd;padding:6px;vertical-align:top}
    th{background:#f6f6f6;text-align:left}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:12px 0}
    .muted{opacity:.75}
    .badge{display:inline-block;border:1px solid #ddd;border-radius:999px;padding:1px 7px;font-size:12px;opacity:.85}
    .warn{border-color:#f0c36d;background:#fff9e6}
  </style>
</head>
<body>
<?php require_once __DIR__ . '/inc/nav.php'; ?>

<h1>Адмін: Заклинання</h1>

<p>
  <a href="/admin_spell_edit.php">+ Додати</a> ·
  <a href="/admin_spell_import.php">Імпорт JSON</a> ·
  <a href="/dashboard.php">Кабінет</a>
</p>

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

  <select name="pub">
    <option value="all"    <?= ($pub==='all'?'selected':'') ?>>всі</option>
    <option value="pub"    <?= ($pub==='pub'?'selected':'') ?>>тільки published</option>
    <option value="hidden" <?= ($pub==='hidden'?'selected':'') ?>>тільки hidden</option>
  </select>

  <select name="sort">
    <option value="name"       <?= ($sort==='name'?'selected':'') ?>>сортувати: назва</option>
    <option value="level"      <?= ($sort==='level'?'selected':'') ?>>сортувати: рівень</option>
    <option value="school"     <?= ($sort==='school'?'selected':'') ?>>сортувати: школа</option>
    <option value="updated_at" <?= ($sort==='updated_at'?'selected':'') ?>>сортувати: оновлено</option>
  </select>

  <select name="dir">
    <option value="asc"  <?= ($dir==='asc'?'selected':'') ?>>↑</option>
    <option value="desc" <?= ($dir==='desc'?'selected':'') ?>>↓</option>
  </select>

  <button type="submit">Застосувати</button>

  <?php if ($q!=='' || $level!=='' || $school!=='' || $pub!=='all' || $sort!=='name' || $dir!=='asc'): ?>
    <a class="muted" href="/admin_spells.php">скинути</a>
  <?php endif; ?>
</form>

<p class="muted">Знайдено: <?= count($rows) ?></p>

<table>
  <tr>
    <th>Name</th><th>Lvl</th><th>School</th><th>Slug</th><th>Status</th><th>Updated</th><th>Actions</th>
  </tr>
  <?php foreach ($rows as $r): ?>
    <?php $modified = trim((string)$r['override_text']) !== ''; ?>
    <tr>
      <td>
        <?= htmlspecialchars((string)$r['name']) ?>
        <?php if ($modified): ?><span class="badge warn">modified</span><?php endif; ?>
      </td>
      <td><?= (int)$r['level'] ?></td>
      <td><?= htmlspecialchars((string)$r['school']) ?></td>
      <td><?= htmlspecialchars((string)$r['slug']) ?></td>
      <td><?= ((int)$r['is_published']===1) ? 'published' : 'hidden' ?></td>
      <td class="muted"><?= htmlspecialchars((string)$r['updated_at']) ?></td>
      <td><a href="/admin_spell_edit.php?id=<?= (int)$r['id'] ?>">edit</a></td>
    </tr>
  <?php endforeach; ?>
</table>

</body>
</html>
