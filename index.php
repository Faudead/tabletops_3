<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

$u = current_user();

if ($u) {
  header('Location: /dashboard.php');
} else {
  header('Location: /login.php');
}
exit;
