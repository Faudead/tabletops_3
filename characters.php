<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

require_login();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? 'user');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // create new character
  $name = trim((string)($_POST['name'] ?? ''));
  if ($name === '') $name = 'New Character';

  $pdo = db();
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("INSERT INTO characters (owner_user_id, name, class_name, level, race, alignment, background, xp, player_name)
                           VALUES (?, ?, '', 1, '', '', '', '', '')");
    $stmt->execute([$uid, $name]);
    $charId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO character_stats (character_id) VALUES (?)")->execute([$charId]);
    $pdo->prepare("INSERT INTO character_resources (character_id) VALUES (?)")->execute([$charId]);
    $pdo->prepare("INSERT INTO character_coins (character_id) VALUES (?)")->execute([$charId]);

    $pdo->commit();
    header("Location: /character.php?id=" . $charId);
    exit;
  } catch (Throwable $e) {
    $pdo->rollBack();
    $err = $e->getMessage();
  }
}

if ($role === 'admin') {
  $rows = db()->query("SELECT c.*, u.username
                       FROM characters c
                       JOIN users u ON u.id = c.owner_user_id
                       ORDER BY c.updated_at DESC")->fetchAll();
} else {
  $stmt = db()->prepare("SELECT c.*
                         FROM characters c
                         WHERE c.owner_user_id=?
                         ORDER BY c.updated_at DESC");
  $stmt->execute([$uid]);
  $rows = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Characters</title>
</head>
<body>
<?php require_once __DIR__ . '/inc/nav.php'; ?>

  <h1>Characters</h1>
  
  <form method="post" style="margin-bottom:16px;">
    <input name="name" placeholder="Character name">
    <button type="submit">Create</button>
    <?php if (!empty($err)) echo "<div style='color:red'>".htmlspecialchars($err)."</div>"; ?>
  </form>

  <ul>
    <?php foreach ($rows as $c): ?>
      <li>
        <a href="/character.php?id=<?= (int)$c['id'] ?>">
          <?= htmlspecialchars((string)$c['name']) ?>
        </a>
        — lvl <?= (int)$c['level'] ?> <?= htmlspecialchars((string)$c['class_name']) ?>
        <?php if ($role === 'admin'): ?>
          <small>(owner: <?= htmlspecialchars((string)($c['username'] ?? '')) ?>)</small>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>


</body>
</html>
