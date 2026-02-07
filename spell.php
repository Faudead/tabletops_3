<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

require_login();

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') { http_response_code(404); echo "Not found"; exit; }

$stmt = db()->prepare("SELECT * FROM spells WHERE slug=? AND is_published=1 LIMIT 1");
$stmt->execute([$slug]);
$s = $stmt->fetch();

if (!$s) { http_response_code(404); echo "Not found"; exit; }

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function br(string $v): string { return nl2br(h($v)); }

$hasOverride = trim((string)$s['override_text']) !== '';
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <title><?= h((string)$s['name']) ?></title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 12px}
    .meta{opacity:.8;margin:6px 0 14px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .card{border:1px solid #ddd;border-radius:10px;padding:12px}
    .card h2{margin:0 0 8px}
    .pill{display:inline-block;padding:2px 8px;border:1px solid #ddd;border-radius:999px;margin-left:6px;font-size:12px;opacity:.8}
    .warn{border-color:#f0c36d;background:#fff9e6}
    ul{margin:8px 0 0}
    @media (max-width: 900px){ .grid{grid-template-columns:1fr} }
  </style>
</head>
<body>
<h1><?= h((string)$s['name']) ?></h1>
<div class="meta">
  <b>Level:</b> <?= (int)$s['level'] ?> |
  <b>School:</b> <?= h((string)$s['school']) ?> |
  <b>Casting:</b> <?= h((string)$s['casting_time']) ?> |
  <b>Range:</b> <?= h((string)$s['range_txt']) ?> |
  <b>Duration:</b> <?= h((string)$s['duration']) ?> |
  <b>Components:</b> <?= h((string)$s['components']) ?>
</div>

<div class="grid">
  <div class="card">
    <h2>Base <span class="pill"><?= h((string)($s['base_ref'] ?? '')) ?></span></h2>
    <div><?= br((string)$s['base_summary']) ?></div>
  </div>

  <div class="card <?= $hasOverride ? 'warn' : '' ?>">
    <h2>Campaign changes <?= $hasOverride ? '<span class="pill">changed</span>' : '<span class="pill">no changes</span>' ?></h2>
    <div><?= $hasOverride ? br((string)$s['override_text']) : '<span style="opacity:.75">Нема змін у цій кампанії.</span>' ?></div>
  </div>
</div>

<p style="margin-top:14px">
  <a href="/spells.php">← назад до списку</a> ·
  <a href="/dashboard.php">в кабінет</a>
</p>
</body>
</html>
