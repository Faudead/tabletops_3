<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

require_login();

$q = trim((string)($_GET['q'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$school = trim((string)($_GET['school'] ?? ''));

// ✅ фільтр "тільки змінені"
$changedOnly = isset($_GET['changed']) && (string)$_GET['changed'] === '1';

$sql = "SELECT
          slug,
          name,
          level,
          school,
          (override_text IS NOT NULL AND TRIM(override_text) <> '') AS has_override
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
if ($changedOnly) {
  $sql .= " AND TRIM(override_text) <> ''";
}

// ✅ за замовчуванням — алфавіт (укр сортування)
$sql .= " ORDER BY name COLLATE utf8mb4_unicode_ci";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$schools = db()->query("SELECT DISTINCT school FROM spells WHERE is_published=1 AND school <> '' ORDER BY school COLLATE utf8mb4_unicode_ci")->fetchAll();
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

  <button type="submit">Знайти</button>

  <?php if ($q !== '' || $level !== '' || $school !== '' || $changedOnly): ?>
    <a class="muted" href="/spells.php">скинути</a>
  <?php endif; ?>
</form>

<ul>
<?php foreach ($rows as $r): ?>
  <li>
    <a href="/spell.php?slug=<?= urlencode((string)$r['slug']) ?>">
      <?= htmlspecialchars((string)$r['name']) ?>
    </a>

    <?php if ((int)$r['has_override'] === 1): ?>
      <span class="badge warn">changed</span>
    <?php endif; ?>

    <span class="muted">— lvl <?= (int)$r['level'] ?>, <?= htmlspecialchars((string)$r['school']) ?></span>
  </li>
<?php endforeach; ?>
</ul>

<p><a href="/dashboard.php">← в кабінет</a></p>
</body>
</html>
