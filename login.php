<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

start_session();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  $pdo = db();
  $stmt = $pdo->prepare("SELECT id, email, password_hash, role FROM users WHERE email=? LIMIT 1");
  $stmt->execute([$email]);
  $u = $stmt->fetch();

  if (!$u || !password_verify($pass, (string)$u['password_hash'])) {
    $err = 'Невірний email або пароль.';
  } else {
    login_user((int)$u['id'], (string)$u['email'], (string)$u['role']);
    header('Location: /dashboard.php');
    exit;
  }
}
?>
<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><title>Вхід</title></head>
<body>
<h1>Вхід</h1>
<?php if ($err): ?><p style="color:red"><?= htmlspecialchars($err) ?></p><?php endif; ?>

<form method="post">
  <label>Email<br><input name="email" type="email" required></label><br><br>
  <label>Пароль<br><input name="password" type="password" required></label><br><br>
  <button type="submit">Увійти</button>
</form>

<p><a href="/register.php">Реєстрація</a></p>
</body>
</html>
