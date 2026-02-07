<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function start_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;

  $cfg = require __DIR__ . '/config.php';
  session_name($cfg['app']['session_name'] ?? 'APPSESS');

  // на більшості хостингів HTTPS уже є — але хай буде без фанатизму
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();
}

function current_user(): ?array {
  start_session();
  return $_SESSION['user'] ?? null;
}

function require_login(): array {
  $u = current_user();
  if (!$u) {
    header('Location: /login.php');
    exit;
  }
  return $u;
}

function require_admin(): array {
  $u = require_login();
  if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
  }
  return $u;
}

function login_user(int $id, string $email, string $role): void {
  start_session();
  session_regenerate_id(true);
  $_SESSION['user'] = ['id' => $id, 'email' => $email, 'role' => $role];
}

function logout_user(): void {
  start_session();
  $_SESSION = [];
  session_destroy();
}
