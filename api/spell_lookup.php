<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing slug'], JSON_UNESCAPED_UNICODE);
  exit;
}

$stmt = db()->prepare("
  SELECT
    slug, name, level, school, casting_time, range_txt, duration, components,
    base_ref, base_summary, override_text
  FROM spells
  WHERE slug=? AND is_published=1
  LIMIT 1
");
$stmt->execute([$slug]);
$s = $stmt->fetch();

if (!$s) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode([
  'ok' => true,
  'spell' => [
    'slug' => (string)$s['slug'],
    'name' => (string)$s['name'],
    'level' => (int)$s['level'],
    'school' => (string)$s['school'],
    'casting_time' => (string)$s['casting_time'],
    'range_txt' => (string)$s['range_txt'],
    'duration' => (string)$s['duration'],
    'components' => (string)$s['components'],
    'base_ref' => (string)($s['base_ref'] ?? ''),
    'base_summary' => (string)($s['base_summary'] ?? ''),
    'override_text' => (string)($s['override_text'] ?? ''),
  ],
], JSON_UNESCAPED_UNICODE);
