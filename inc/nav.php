<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// якщо сторінка не передала $user — візьмемо з сесії або проженемо через require_login()
if (!isset($user) || !is_array($user)) {
  $user = current_user();
  if (!$user) $user = require_login();
}

$isAdmin = (($user['role'] ?? '') === 'admin');
?>
<nav style="display:flex; gap:12px; align-items:center; margin:12px 0;">
  <a href="/dashboard.php">Кабінет</a>
  <a href="/characters.php"><?= $isAdmin ? 'Персонажі гравців' : 'Мої персонажі' ?></a>
  <a href="/spells.php">Заклинання</a>

  <?php if ($isAdmin): ?>
    <a href="/admin_spells.php">Адмінка: заклинання</a>
  <?php endif; ?>

  <span style="margin-left:auto; opacity:.75;">
    <?= htmlspecialchars((string)($user['email'] ?? $user['username'] ?? 'user')) ?>
  </span>
  <a href="/logout.php">Вийти</a>
</nav>
<hr>

