<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
$u = require_login();
$isAdmin = (($u['role'] ?? '') === 'admin');

$who = $u['email'] ?? $u['username'] ?? 'user';
?>
<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><title>Кабінет</title></head>
<body>
<h1>Кабінет</h1>
<p>Ви увійшли як: <b><?= htmlspecialchars($who) ?></b> (роль: <?= htmlspecialchars($u['role'] ?? '') ?>)</p>

<h2>Гравцю</h2>
<ul>
  <li><a href="/spells.php">Заклинання</a></li>
  <!-- пізніше: <li><a href="/articles.php">Статті/правила</a></li> -->
  <!-- пізніше: <li><a href="/my_characters.php">Мої персонажі</a></li> -->
</ul>

<?php if ($isAdmin): ?>
<h2>Адміну</h2>
<ul>
  <li><a href="/admin_spells.php">Адмінка: Заклинання</a></li>
  <!-- пізніше: <li><a href="/admin.php">Адмінка: Користувачі/чарники</a></li> -->
  <!-- пізніше: <li><a href="/admin_pages.php">Адмінка: Статті</a></li> -->
</ul>
<?php endif; ?>

<p><a href="/logout.php">Вийти</a></p>
</body>
</html>
