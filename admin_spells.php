<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_admin();

$rows = db()->query("SELECT id, name, level, school, slug, is_published FROM spells ORDER BY level, name")->fetchAll();
?>
<!doctype html>
<html lang="uk"><head><meta charset="utf-8"><title>Адмін: Заклинання</title></head>
<body>
<h1>Адмін: Заклинання</h1>
<p><a href="/admin_spell_edit.php">+ Додати</a> · <a href="/dashboard.php">в кабінет</a></p>

<table border="1" cellpadding="6" cellspacing="0">
<tr><th>Name</th><th>Lvl</th><th>School</th><th>Slug</th><th>Pub</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= htmlspecialchars((string)$r['name']) ?></td>
  <td><?= (int)$r['level'] ?></td>
  <td><?= htmlspecialchars((string)$r['school']) ?></td>
  <td><?= htmlspecialchars((string)$r['slug']) ?></td>
  <td><?= (int)$r['is_published'] ?></td>
  <td><a href="/admin_spell_edit.php?id=<?= (int)$r['id'] ?>">edit</a></td>
</tr>
<?php endforeach; ?>
</table>
</body></html>
