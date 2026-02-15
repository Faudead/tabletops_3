<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$user = current_user();
$uid  = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? 'user');

$charId = (int)($_POST['id'] ?? 0);
if ($charId <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Missing id']);
  exit;
}

$st = db()->prepare("SELECT id, owner_user_id FROM characters WHERE id=?");
$st->execute([$charId]);
$ch = $st->fetch();
if (!$ch) {
  http_response_code(404);
  echo json_encode(['ok'=>false,'error'=>'Character not found']);
  exit;
}

$canEdit = false;
if ($role === 'admin' || (int)$ch['owner_user_id'] === $uid) {
  $canEdit = true;
} else {
  $acc = db()->prepare("SELECT can_edit FROM character_access WHERE character_id=? AND user_id=? LIMIT 1");
  $acc->execute([$charId, $uid]);
  $r = $acc->fetch();
  $canEdit = (bool)($r && (int)($r['can_edit'] ?? 0) === 1);
}

if (!$canEdit) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Forbidden']);
  exit;
}

$skillsJson = (string)($_POST['skills_json'] ?? '{}');
$profsJson  = (string)($_POST['profs_json'] ?? '{}');

$skills = json_decode($skillsJson, true);
$profs  = json_decode($profsJson, true);

if (!is_array($skills)) $skills = [];
if (!is_array($profs))  $profs  = [];

$allowedSkillCodes = [
  'acrobatics','animal','arcana','athletics','deception','history','insight','intimidation','investigation',
  'medicine','nature','perception','performance','persuasion','religion','sleight','stealth','survival'
];

$allowedProfTypes = ['weapons','armor','tools','languages','vehicles'];

$db = db();
$db->beginTransaction();

try {
  // Skills: replace-all for character
  $db->prepare("DELETE FROM character_skills WHERE character_id=?")->execute([$charId]);

  $insSkill = $db->prepare("INSERT INTO character_skills (character_id, skill_code, proficiency_level, bonus_override)
                            VALUES (?,?,?,?)");
  foreach ($skills as $code => $row) {
    $code = trim((string)$code);
    if ($code === '' || !in_array($code, $allowedSkillCodes, true)) continue;

    $prof = 0;
    $ov = null;

    if (is_array($row)) {
      $prof = (int)($row['proficiency_level'] ?? 0);
      if ($prof < 0) $prof = 0;
      if ($prof > 2) $prof = 2;

      if (array_key_exists('bonus_override', $row) && $row['bonus_override'] !== null && $row['bonus_override'] !== '') {
        $ov = (int)$row['bonus_override'];
      }
    }

    $insSkill->execute([$charId, $code, $prof, $ov]);
  }

  // Profs: replace-all for character
  $db->prepare("DELETE FROM character_proficiencies WHERE character_id=?")->execute([$charId]);

  $insProf = $db->prepare("INSERT INTO character_proficiencies (character_id, prof_type, name)
                           VALUES (?,?,?)");

  foreach ($allowedProfTypes as $type) {
    if (!isset($profs[$type]) || !is_array($profs[$type])) continue;
    foreach ($profs[$type] as $name) {
      $name = trim((string)$name);
      if ($name === '') continue;
      if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
      $insProf->execute([$charId, $type, $name]);
    }
  }

  $db->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  $db->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB error']);
}
