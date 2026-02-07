<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

$u = require_login();
?>
<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><title>Кабінет</title></head>
<body>
<h1>Кабінет</h1>
<p>Ви увійшли як: <b><?= htmlspecialchars($u['email']) ?></b> (роль: <?= htmlspecialchars($u['role']) ?>)</p>

<ul>
  <li><a href="/logout.php">Вийти</a></li>
</ul>
</body>
</html>
