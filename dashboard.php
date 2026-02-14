<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

$user = require_login();
$isAdmin = (($user['role'] ?? '') === 'admin');
$who = $user['email'] ?? $user['username'] ?? 'user';
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <title>Кабінет</title>
</head>
<body>

<?php require_once __DIR__ . '/inc/nav.php'; ?>

<h1>Кабінет</h1>
<p>Ви увійшли як: <b><?= htmlspecialchars($who) ?></b> (роль: <?= htmlspecialchars($user['role'] ?? '') ?>)</p>

<h2>Гравцю</h2>
<ul>
  <li><a href="/spells.php">Заклинання</a></li>
  <li><a href="/characters.php">Мої персонажі</a></li>
</ul>

<?php if ($isAdmin): ?>
  <h2>Адміну</h2>
  <ul>
    <li><a href="/admin_spells.php">Адмінка: Заклинання</a></li>
    <li><a href="/characters.php">Персонажі гравців</a></li>
  </ul>
<?php endif; ?>

</body>
</html>
