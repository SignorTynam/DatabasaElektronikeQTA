<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

function qta_audit_event(string $type, array $payload): void {
  try {
    if (function_exists('qta_audit_log')) {
      qta_audit_log($GLOBALS['pdo'] ?? null, $type, $payload);
    }
  } catch (Throwable $e) {}
}

function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function imgDataUriIfExists(string $path): ?string {
  if (!is_file($path)) return null;
  $bin = file_get_contents($path);
  if ($bin === false) return null;

  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $mime = match ($ext) {
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    default => 'application/octet-stream',
  };

  return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/* Guard */
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('Unauthorized'); }

$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($currentUser['role_name'] ?? ''));

if (!$currentUser || !in_array($role, ['administrator','editor'], true)) {
  http_response_code(403); exit('Forbidden');
}

/* POST only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

/* CSRF */
if (empty($_POST['csrf']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf'])) {
  http_response_code(400);
  exit('CSRF token mismatch.');
}

/* Input */
$groupId = (int)($_POST['group_id'] ?? 0);
if ($groupId <= 0) { http_response_code(400); exit('group_id i pavlefshëm.'); }

$format = strtolower(trim((string)($_POST['format'] ?? 'doc')));
if (!in_array($format, ['doc','pdf'], true)) $format = 'doc';

/* Fetch group + course */
$g = $pdo->prepare("
  SELECT cg.id, c.name AS course_name
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  WHERE cg.id = :gid
  LIMIT 1
");
$g->execute([':gid'=>$groupId]);
$group = $g->fetch(PDO::FETCH_ASSOC);
if (!$group) { http_response_code(404); exit('Grupi nuk u gjet.'); }

$courseName = (string)($group['course_name'] ?? '');

/* Students */
$st = $pdo->prepare("
  SELECT
    s.nr_amze,
    p.first_name,
    p.father_name,
    p.last_name
  FROM course_group_students cgs
  JOIN students s ON s.id = cgs.student_id
  LEFT JOIN persons p ON p.id = s.person_id
  WHERE cgs.group_id = :gid
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
");
$st->execute([':gid'=>$groupId]);
$students = $st->fetchAll(PDO::FETCH_ASSOC);

/* Logos */
$coatPath = __DIR__ . '/image/logoRepAlb.png';
$qtaPath  = __DIR__ . '/image/logoPNG.png';
$coat = imgDataUriIfExists($coatPath);
$qta  = imgDataUriIfExists($qtaPath);

$headerLeft  = $coat ? '<img src="'.$coat.'" style="height:68px;">' : '';
$headerRight = $qta  ? '<img src="'.$qta.'" style="height:46px;">' : 'QTA';

/* Signature fixed */
$signLine1 = 'ADMINISTRATOR';
$signLine2 = 'Msc. Mira KOVAÇI';

/* Rreshta sa pjesëmarrës ka grupi */
$rowsCount = count($students);

$rowsHtml = '';
for ($i = 1; $i <= $rowsCount; $i++) {
  $name = ' ';
  $fn = trim((string)($students[$i-1]['first_name'] ?? ''));
  $fa = trim((string)($students[$i-1]['father_name'] ?? ''));
  $ln = trim((string)($students[$i-1]['last_name'] ?? ''));
  $name = trim($fn . ' ' . ($fa !== '' ? ($fa . ' ') : '') . $ln);
  if ($name === '') $name = ' ';

  $rowsHtml .= '
    <tr>
      <td class="tdNr">'. $i .'</td>
      <td class="tdName">'. e($name) .'</td>
    </tr>
  ';
}

/* DATA BOSh (si në foto) */
$dateBlank = '____ / ____ / ______';

$html = '<!doctype html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Sigurimi Teknik - Grup '.$groupId.'</title>
  <style>
    @page { size: A4; margin: 1.35cm; }
    body { font-family: "Times New Roman", serif; font-size: 12pt; color:#111; }

    .top { width:100%; border-collapse:collapse; margin-bottom: 6px; }
    .top td { vertical-align:top; }
    .centerHead { text-align:center; color:#666; font-weight:bold; line-height:1.2; font-size: 12pt; }
    .addr { font-weight:normal; color:#666; }

    .dateRight { text-align:right; margin-top: 8px; font-size: 12pt; font-weight: bold; }
    .dateRight .blank { font-weight: normal; letter-spacing: 1px; }

    .subject { margin-top: 14px; font-size: 12.5pt; }
    .subject b { font-weight:bold; }

    .course { margin-top: 10px; font-size: 12.5pt; }
    .line {
      display:inline-block;
      width: 82%;
      border-bottom: 1px solid #000;
      height: 18px;
      vertical-align: bottom;
      padding-left: 6px;
      font-weight: normal;
    }

    .tbl { margin: 18px auto 0 auto; width: 72%; border-collapse:collapse; }
    .tbl th, .tbl td { border:1px solid #000; padding:7px 10px; }
    .tbl thead th {
      font-style: normal;
      font-weight: bold;
    }
    .tdNr { width:55px; text-align:center; font-weight:bold; }
    .tdName { text-transform: uppercase; }

    .sign { margin-top: 65px; text-align:right; font-weight:bold; }
    .sign .name { font-weight:bold; margin-top:6px; display:inline-block; }
  </style>
</head>
<body>

  <table class="top">
    <tr>
      <td style="width:18%;">'.$headerLeft.'</td>
      <td style="width:64%;">
        <div class="centerHead">
          REPUBLIKA E SHQIPËRISË<br>
          MINISTRIA E EKONOMISË, KULTURËS DHE INOVACIONIT<br>
          QENDRA E TRAJNIMEVE TË AVANCUARA<br>
          <span class="addr">Rruga “Bilal Konxholli”, Pallati “DR &amp;. DI”, Njësia Administrative Nr. 8, Tiranë</span>
        </div>
      </td>
      <td style="width:18%; text-align:right;">'.$headerRight.'</td>
    </tr>
  </table>

  <div class="dateRight">Tiranë, më <span class="blank">'.$dateBlank.'</span></div>

  <div class="subject"><b>Lënda:</b> Formular për njohjen me Rregullat Sigurimit Teknik dhe Mbrojtjes së Mjedisit</div>

  <div class="course"><b>Kursi:</b> <span class="line">'.e($courseName).'</span></div>

  <table class="tbl">
    <thead>
      <tr>
        <th style="width:55px;">NR.</th>
        <th>EMRI ATËSIA MBIEMËR</th>
      </tr>
    </thead>
    <tbody>
      '.$rowsHtml.'
    </tbody>
  </table>

  <div class="sign">
    '.$signLine1.'<br>
    <span class="name">'.e($signLine2).'</span>
  </div>

</body>
</html>';

/* Audit */
qta_audit_event('sigurimi_teknik.download', [
  'group_id'      => $groupId,
  'course_name'   => $courseName,
  'format'        => $format,
  'actor_user_id' => $_SESSION['user_id'] ?? null
]);

/* Output */
if (ob_get_length()) { ob_end_clean(); }

$baseName = 'Rregullat_Sigurimi_Teknik_Grupi_'.$groupId;
header('X-File-Download: 1');
setcookie('qta_file_ready', 'ok', [
  'expires'  => time() + 60,
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']),
  'httponly' => false,
  'samesite' => 'Lax',
]);

if ($format === 'pdf') {
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (!is_file($autoload)) {
    http_response_code(500);
    exit('PDF kërkon dompdf. Instalo: composer require dompdf/dompdf');
  }
  require_once $autoload;

  $options = new Dompdf\Options();
  $options->set('isRemoteEnabled', true);

  $dompdf = new Dompdf\Dompdf($options);
  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="'.$baseName.'.pdf"');
  header('Cache-Control: private, max-age=0, must-revalidate');
  header('Pragma: public');

  echo $dompdf->output();
  exit;
}

/* DOC */
header('Content-Type: application/msword; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$baseName.'.doc"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo "\xEF\xBB\xBF";
echo $html;
exit;
