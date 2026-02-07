<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

require_admin();

function slugify(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  // транслітерація укр → лат (простий варіант)
  $map = [
    'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ie','ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'i','й'=>'i',
    'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch',
    'ш'=>'sh','щ'=>'shch','ь'=>'','ю'=>'iu','я'=>'ia','\''=>'','’'=>'','"'=>'',
  ];
  $out = '';
  $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
  foreach ($chars as $ch) {
    $out .= $map[$ch] ?? $ch;
  }
  $out = preg_replace('~[^a-z0-9]+~', '-', $out) ?? '';
  $out = trim($out, '-');
  if ($out === '') $out = 'spell';
  return substr($out, 0, 190);
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = db();
$mode = (string)($_POST['mode'] ?? '');
$msg = '';
$err = '';
$preview = [];
$stats = ['total'=>0,'ok'=>0,'skipped'=>0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_FILES['json']) || $_FILES['json']['error'] !== UPLOAD_ERR_OK) {
    $err = 'Файл не завантажився. Спробуй ще раз.';
  } else {
    $raw = file_get_contents($_FILES['json']['tmp_name']);
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
      $err = 'JSON не схожий на масив.';
    } else {
      // preview беремо перші 20
      $preview = array_slice($data, 0, 20);
      $stats['total'] = count($data);

      if ($mode === 'import') {
        $pdo->beginTransaction();
        try {
          $ins = $pdo->prepare("
            INSERT INTO spells
              (slug, name, level, school, casting_time, range_txt, duration, components, base_ref, base_summary, override_text, is_published)
            VALUES
              (:slug, :name, :level, :school, :casting_time, :range_txt, :duration, :components, :base_ref, :base_summary, :override_text, :is_published)
            ON DUPLICATE KEY UPDATE
              name=VALUES(name),
              level=VALUES(level),
              school=VALUES(school),
              casting_time=VALUES(casting_time),
              range_txt=VALUES(range_txt),
              duration=VALUES(duration),
              components=VALUES(components),
              base_ref=VALUES(base_ref),
              base_summary=VALUES(base_summary),
              is_published=VALUES(is_published)
          ");

          foreach ($data as $row) {
            if (!is_array($row)) { $stats['skipped']++; continue; }

            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') { $stats['skipped']++; continue; }

            // slug: або з JSON, або генеруємо з name
            $slug = trim((string)($row['slug'] ?? ''));
            if ($slug === '') $slug = slugify($name);

            $level = (int)($row['level'] ?? 0);
            $school = trim((string)($row['school'] ?? ''));
            $casting = trim((string)($row['casting_time'] ?? ''));
            $range = trim((string)($row['range'] ?? ''));
            $duration = trim((string)($row['duration'] ?? ''));
            $components = trim((string)($row['components'] ?? ''));

            // твоє “base vs changes”:
            $base_ref = 'PHB';
            if (!empty($row['source'])) {
              $base_ref = 'PHB (' . trim((string)$row['source']) . ')';
            }
            // description кладемо в base_summary (потім ти можеш скорочувати вручну)
            $base_summary = (string)($row['description'] ?? '');
            $override_text = ''; // ребаланс заповниш в адмінці
            $is_published = 1;

            $ins->execute([
              ':slug' => $slug,
              ':name' => $name,
              ':level' => max(0, min(9, $level)),
              ':school' => $school,
              ':casting_time' => $casting,
              ':range_txt' => $range,
              ':duration' => $duration,
              ':components' => $components,
              ':base_ref' => $base_ref,
              ':base_summary' => $base_summary,
              ':override_text' => $override_text,
              ':is_published' => $is_published,
            ]);

            $stats['ok']++;
          }

          $pdo->commit();
          $msg = "Імпорт завершено: {$stats['ok']} / {$stats['total']} (skipped {$stats['skipped']}).";
        } catch (Throwable $e) {
          $pdo->rollBack();
          $err = "Помилка імпорту: " . $e->getMessage();
        }
      } else {
        $msg = "Preview: показано перші " . count($preview) . " з {$stats['total']}. Натисни Import, щоб залити/оновити.";
      }
    }
  }
}
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <title>Адмін: Імпорт заклинань (JSON)</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 12px}
    input,button{padding:8px 10px}
    .ok{color:#0a7a0a}
    .err{color:#b00020}
    table{border-collapse:collapse;width:100%;margin-top:12px}
    th,td{border:1px solid #ddd;padding:6px;vertical-align:top}
    th{background:#f6f6f6;text-align:left}
    .muted{opacity:.75}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  </style>
</head>
<body>
<h1>Адмін: Імпорт заклинань (JSON)</h1>
<p class="muted">Доступ тільки адміну. Рекомендовано видалити цей файл після імпорту.</p>

<?php if ($msg): ?><p class="ok"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="row">
  <input type="file" name="json" accept=".json,application/json" required>
  <button type="submit" name="mode" value="preview">Preview</button>
  <button type="submit" name="mode" value="import" onclick="return confirm('Імпортувати/оновити всі записи з цього JSON?')">Import/Update</button>
  <a href="/dashboard.php">Dashboard</a>
</form>

<?php if ($preview): ?>
  <h2>Preview (перші <?= (int)count($preview) ?>)</h2>
  <table>
    <tr>
      <th>Name</th><th>Level</th><th>School</th><th>Casting</th><th>Range</th><th>Duration</th><th>Components</th>
      <th class="muted">Desc (початок)</th>
    </tr>
    <?php foreach ($preview as $r): ?>
      <?php
        $name = (string)($r['name'] ?? '');
        $desc = (string)($r['description'] ?? '');
      ?>
      <tr>
        <td><?= h($name) ?></td>
        <td><?= (int)($r['level'] ?? 0) ?></td>
        <td><?= h((string)($r['school'] ?? '')) ?></td>
        <td><?= h((string)($r['casting_time'] ?? '')) ?></td>
        <td><?= h((string)($r['range'] ?? '')) ?></td>
        <td><?= h((string)($r['duration'] ?? '')) ?></td>
        <td><?= h((string)($r['components'] ?? '')) ?></td>
        <td class="muted"><?= h(mb_substr($desc, 0, 120, 'UTF-8')) ?><?= mb_strlen($desc, 'UTF-8')>120 ? '…' : '' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

</body>
</html>
