<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

start_session();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string)($_POST['username'] ?? ''));
  $pass     = (string)($_POST['password'] ?? '');

  if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
    $err = 'Username: 3–32 символи, латиниця, цифри, _.';
  } elseif (mb_strlen($pass) < 8) {
    $err = 'Пароль має бути мінімум 8 символів.';
  } else {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
      $err = 'Такий username вже існує.';
    } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare(
        "INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'user')"
      );
      $stmt->execute([$username, $hash]);
      $id = (int)$pdo->lastInsertId();
      login_user($id, $username, 'user');
      header('Location: /dashboard.php');
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><title>Реєстрація</title></head>
<body>
<h1>Реєстрація</h1>
<?php if ($err): ?><p style="color:red"><?= htmlspecialchars($err) ?></p><?php endif; ?>

<form method="post">
  <label>Username<br><input name="username" required></label><br><br>
  <label>Пароль<br><input name="password" type="password" required></label><br><br>
  <button type="submit">Створити акаунт</button>
</form>

<p><a href="/login.php">Вхід</a></p>
</body>
</html>
