<?php
declare(strict_types=1);

/**
 * Очікує, що сторінка вже підключила auth.php і є $user (або викличе require_login()).
 * Якщо в тебе в auth.php інакше називаються змінні — просто підправиш 2 рядки нижче.
 */

$isAdmin = (($user['role'] ?? '') === 'admin');

?>
<nav style="display:flex; gap:12px; align-items:center; margin:12px 0;">
  <a href="/dashboard.php">Кабінет</a>
  <a href="/characters.php"><?= $isAdmin ? 'Персонажі гравців' : 'Мої персонажі' ?></a>
  <a href="/spells.php">Заклинання</a>

  <?php if ($isAdmin): ?>
    <a href="/admin_spells.php">Адмінка: заклинання</a>
    <!-- якщо є/буде адмін-дашборд -->
    <!-- <a href="/admin.php">Адмін</a> -->
  <?php endif; ?>

  <span style="margin-left:auto; opacity:.75;">
  <?= htmlspecialchars((string)($user['email'] ?? 'user')) ?>
  </span>
  <a href="/logout.php">Вийти</a>
</nav>
<hr>
