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

function fmt_dMY(?string $iso): string {
  if (!$iso) return '—';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return $iso;
  $ts = strtotime($iso);
  return $ts ? date('d-m-Y', $ts) : '—';
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
    'png'  => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    default => 'application/octet-stream'
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
  SELECT cg.id, cg.start_date, cg.end_date, c.name AS course_name
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  WHERE cg.id = :gid
  LIMIT 1
");
$g->execute([':gid'=>$groupId]);
$group = $g->fetch(PDO::FETCH_ASSOC);
if (!$group) { http_response_code(404); exit('Grupi nuk u gjet.'); }

/* Periudha (pa modifikim nga user) */
$period = '____ / ____ / ____  -  ____ / ____ / ____';

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

/* Logos nga folderi image */
$coatPath = __DIR__ . '/image/logoRepAlb.png';
$qtaPath  = __DIR__ . '/image/logoPNG.png';

$coat = imgDataUriIfExists($coatPath);
$qta  = imgDataUriIfExists($qtaPath);

/* Administratori FIKS */
$signLine1 = 'ADMINISTRATORI';
$signLine2 = 'Msc. Mira Kovaçi';

$courseName = (string)($group['course_name'] ?? '');

/* 10 rreshta */
$rowsCount = max(10, count($students));
$rowsHtml = '';
for ($i=1; $i <= $rowsCount; $i++) {
  $name = ' ';
  if (isset($students[$i-1])) {
    $fn = trim((string)($students[$i-1]['first_name'] ?? ''));
    $fa = trim((string)($students[$i-1]['father_name'] ?? ''));
    $ln = trim((string)($students[$i-1]['last_name'] ?? ''));
    $name = trim($fn . ' ' . ($fa !== '' ? ($fa . ' ') : '') . $ln);
    if ($name === '') $name = ' ';
  }
  $rowsHtml .= '
    <tr>
      <td class="tdNr">'. $i .'.</td>
      <td class="tdName">'. e($name) .'</td>
    </tr>
  ';
}

$headerLeft  = $coat ? '<img src="'.$coat.'" style="height:70px;">' : '';
$headerRight = $qta  ? '<img src="'.$qta.'" style="height:48px;">' : 'QTA';

/* HTML (margin NARROW) */
$html = '<!doctype html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Praktika Profesionale - Grup '.$groupId.'</title>
  <style>
    @page { size: A4; margin: 1.27cm; } /* Narrow */
    body { font-family: "Times New Roman", serif; font-size: 12pt; color:#111; }
    .top { width:100%; border-collapse:collapse; }
    .top td { vertical-align:top; }
    .centerHead { text-align:center; color:#111; font-weight:bold; line-height:1.25; }
    .addr { font-weight:normal; }
    .dateRight { text-align:right; margin-top:16px; font-size:12pt; }
    .subject { margin-top:16px; font-size:12.5pt; }
    .subject b { text-decoration: underline; }
    .p { margin-top:10px; line-height:1.35; }
    .tbl { margin: 18px auto 0 auto; width: 70%; border-collapse:collapse; }
    .tbl th, .tbl td { border:1px solid #000; padding:6px 8px; }
    .tbl th { text-align:left; font-weight:bold; }
    .tdNr { width:55px; text-align:center; }
    .tdName { width:auto; }
    .sign { margin-top:70px; text-align:right; font-weight:bold; }
    .sign .name { font-weight:bold; margin-top:6px; }
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

  <div class="dateRight"><b>Tiranë më</b> ____ / ____ / ____</div>

  <div class="subject"><b>Lënda:</b> Për kryerjen e praktikës profesionale mësimore të orientuar</div>

  <div class="p">
    Lista emërore e detajuar e kursantëve për kryerjen e praktikës mësimore në kurrikulën
    <b>“'.e($courseName).'”</b>, për periudhën <u>'.e($period).'</u>
  </div>

  <table class="tbl">
    <tr>
      <th style="width:55px;">Nr.</th>
      <th>EMËR ATËSI MBIEMËR</th>
    </tr>
    '.$rowsHtml.'
  </table>

  <div class="sign">
    '.$signLine1.'<br>
    <span class="name">'.e($signLine2).'</span>
  </div>

</body>
</html>';

/* Audit */
qta_audit_event('praktika_profesionale.download', [
  'group_id'      => $groupId,
  'course_name'   => $courseName,
  'format'        => $format,
  'actor_user_id' => $_SESSION['user_id'] ?? null
]);

/* Output */
if (ob_get_length()) { ob_end_clean(); }

$baseName = 'Praktika_Profesionale_Grupi_'.$groupId;
header('X-File-Download: 1');
setcookie('qta_file_ready', 'ok', [
  'expires'  => time() + 60,
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']),
  'httponly' => false,
  'samesite' => 'Lax',
]);

if ($format === 'pdf') {
  // Kërkon Dompdf. Nëse e ke, vazhdon direkt.
  // composer require dompdf/dompdf
  // dhe sigurohu që ekziston vendor/autoload.php
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
