<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

$user = require_admin();
$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$spell = [
  'slug' => '',
  'name' => '',
  'level' => 0,
  'school' => '',
  'casting_time' => '',
  'range_txt' => '',
  'duration' => '',
  'components' => '',
  'base_ref' => 'PHB',
  'base_summary' => '',
  'override_text' => '',
  'is_published' => 1,
];

if ($id > 0) {
  $st = $pdo->prepare("SELECT * FROM spells WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) { http_response_code(404); echo "Not found"; exit; }
  $spell = array_merge($spell, $row);
}

$err = '';

function slugify(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
  $s = trim($s, '-');
  return $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'save');

  if ($action === 'delete' && $id > 0) {
    $st = $pdo->prepare("DELETE FROM spells WHERE id=?");
    $st->execute([$id]);
    header('Location: /admin_spells.php');
    exit;
  }

  $name = trim((string)($_POST['name'] ?? ''));
  $slug = trim((string)($_POST['slug'] ?? ''));
  $slug = $slug !== '' ? $slug : slugify($name);

  $level = (int)($_POST['level'] ?? 0);

  if ($slug === '' || !preg_match('/^[a-z0-9-]{2,190}$/', $slug)) {
    $err = 'Slug: тільки a-z, 0-9, дефіс. (мін 2 символи)';
  } elseif ($name === '') {
    $err = 'Name обовʼязкове.';
  } elseif ($level < 0 || $level > 9) {
    $err = 'Level має бути 0..9.';
  } else {
    $data = [
      'slug' => $slug,
      'name' => $name,
      'level' => $level,
      'school' => trim((string)($_POST['school'] ?? '')),
      'casting_time' => trim((string)($_POST['casting_time'] ?? '')),
      'range_txt' => trim((string)($_POST['range_txt'] ?? '')),
      'duration' => trim((string)($_POST['duration'] ?? '')),
      'components' => trim((string)($_POST['components'] ?? '')),
      'base_ref' => trim((string)($_POST['base_ref'] ?? '')),
      'base_summary' => (string)($_POST['base_summary'] ?? ''),
      'override_text' => (string)($_POST['override_text'] ?? ''),
      'is_published' => isset($_POST['is_published']) ? 1 : 0,
    ];

    try {
      if ($id > 0) {
        $st = $pdo->prepare("
          UPDATE spells SET
            slug=:slug, name=:name, level=:level, school=:school,
            casting_time=:casting_time, range_txt=:range_txt, duration=:duration, components=:components,
            base_ref=:base_ref, base_summary=:base_summary, override_text=:override_text,
            is_published=:is_published
          WHERE id=:id
        ");
        $data['id'] = $id;
        $st->execute($data);
      } else {
        $st = $pdo->prepare("
          INSERT INTO spells
            (slug,name,level,school,casting_time,range_txt,duration,components,base_ref,base_summary,override_text,is_published)
          VALUES
            (:slug,:name,:level,:school,:casting_time,:range_txt,:duration,:components,:base_ref,:base_summary,:override_text,:is_published)
        ");
        $st->execute($data);
        $id = (int)$pdo->lastInsertId();
      }

      header('Location: /admin_spells.php');
      exit;

    } catch (PDOException $e) {
      // найчастіше: дубль slug
      $err = 'DB error: ' . $e->getCode();
    }

    $spell = array_merge($spell, $data);
  }
}
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <title>Admin Spell</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 12px}
    input,textarea,select,button{padding:6px 8px;width:100%;box-sizing:border-box}
    textarea{font-family:ui-monospace,Consolas,monospace}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .card{border:1px solid #ddd;border-radius:10px;padding:12px}
    .row{display:grid;grid-template-columns:160px 1fr;gap:10px;align-items:center;margin:8px 0}
    .err{color:#b00020}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
    .actions button{width:auto}
    @media (max-width: 900px){ .grid{grid-template-columns:1fr} .row{grid-template-columns:1fr} }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/inc/nav.php'; ?>

<h1>Admin: Spell <?= $id>0 ? "#$id" : "NEW" ?></h1>
<?php if ($err): ?><p class="err"><?= htmlspecialchars($err) ?></p><?php endif; ?>

<form method="post">
  <div class="card">
    <div class="grid">
      <div>
        <div class="row"><label>Name</label><input name="name" value="<?= htmlspecialchars((string)$spell['name']) ?>" required></div>
        <div class="row"><label>Slug</label><input name="slug" value="<?= htmlspecialchars((string)$spell['slug']) ?>" placeholder="auto from name"></div>
        <div class="row"><label>Level</label><input name="level" type="number" min="0" max="9" value="<?= (int)$spell['level'] ?>"></div>
        <div class="row"><label>School</label><input name="school" value="<?= htmlspecialchars((string)$spell['school']) ?>"></div>
      </div>
      <div>
        <div class="row"><label>Casting</label><input name="casting_time" value="<?= htmlspecialchars((string)$spell['casting_time']) ?>"></div>
        <div class="row"><label>Range</label><input name="range_txt" value="<?= htmlspecialchars((string)$spell['range_txt']) ?>"></div>
        <div class="row"><label>Duration</label><input name="duration" value="<?= htmlspecialchars((string)$spell['duration']) ?>"></div>
        <div class="row"><label>Components</label><input name="components" value="<?= htmlspecialchars((string)$spell['components']) ?>"></div>
      </div>
    </div>

    <div class="row"><label>Base ref</label><input name="base_ref" value="<?= htmlspecialchars((string)$spell['base_ref']) ?>" placeholder="PHB p.241"></div>

    <div class="grid">
      <div class="card">
        <h2>Base summary (your words)</h2>
        <textarea name="base_summary" rows="10"><?= htmlspecialchars((string)$spell['base_summary']) ?></textarea>
      </div>
      <div class="card">
        <h2>Campaign changes</h2>
        <textarea name="override_text" rows="10"><?= htmlspecialchars((string)$spell['override_text']) ?></textarea>
      </div>
    </div>

    <div style="margin-top:10px">
      <label>
        <input type="checkbox" name="is_published" <?= ((int)$spell['is_published'] === 1 ? 'checked' : '') ?>>
        Published (visible for players)
      </label>
    </div>

    <div class="actions">
      <button type="submit" name="action" value="save">Save</button>
      <?php if ($id>0): ?>
        <button type="submit" name="action" value="delete" onclick="return confirm('Delete this spell?')">Delete</button>
      <?php endif; ?>
      <a href="/admin_spells.php">Back to list</a>
      <a href="/dashboard.php">Dashboard</a>
    </div>
  </div>
</form>
</body>
</html>
