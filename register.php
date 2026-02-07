<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

start_session();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Невірний email.';
  } elseif (mb_strlen($pass) < 8) {
    $err = 'Пароль має бути мінімум 8 символів.';
  } else {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
      $err = 'Такий email вже зареєстрований.';
    } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'user')");
      $stmt->execute([$email, $hash]);
      $id = (int)$pdo->lastInsertId();
      login_user($id, $email, 'user');
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
  <label>Email<br><input name="email" type="email" required></label><br><br>
  <label>Пароль (мін 8)<br><input name="password" type="password" required></label><br><br>
  <button type="submit">Створити акаунт</button>
</form>

<p><a href="/login.php">Вхід</a></p>
</body>
</html>
