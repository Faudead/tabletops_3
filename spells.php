<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

require_login();

$q = trim((string)($_GET['q'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$school = trim((string)($_GET['school'] ?? ''));

$sql = "SELECT slug, name, level, school
        FROM spells
        WHERE is_published=1";
$params = [];

if ($q !== '') {
  $sql .= " AND name LIKE ?";
  $params[] = "%{$q}%";
}
if ($level !== '' && ctype_digit($level)) {
  $sql .= " AND level = ?";
  $params[] = (int)$level;
}
if ($school !== '') {
  $sql .= " AND school = ?";
  $params[] = $school;
}

$sql .= " ORDER BY level, name";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$schools = db()->query("SELECT DISTINCT school FROM spells WHERE school <> '' ORDER BY school")->fetchAll();
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <title>Заклинання</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:980px;margin:20px auto;padding:0 12px}
    input,select,button{padding:6px 8px}
    .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0}
    .muted{opacity:.75}
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
  <button type="submit">Знайти</button>
  <?php if ($q !== '' || $level !== '' || $school !== ''): ?>
    <a class="muted" href="/spells.php">скинути</a>
  <?php endif; ?>
</form>

<ul>
<?php foreach ($rows as $r): ?>
  <li>
    <a href="/spell.php?slug=<?= urlencode((string)$r['slug']) ?>">
      <?= htmlspecialchars((string)$r['name']) ?>
    </a>
    <span class="muted">— lvl <?= (int)$r['level'] ?>, <?= htmlspecialchars((string)$r['school']) ?></span>
  </li>
<?php endforeach; ?>
</ul>

<p><a href="/dashboard.php">← в кабінет</a></p>
</body>
</html>
