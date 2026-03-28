<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

function qta_download_status(string $status, string $message): void {
  setcookie('qta_file_ready', $status, [
    'expires'  => time() + 60,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => false,
    'samesite' => 'Lax',
  ]);
  setcookie('qta_file_msg', $message, [
    'expires'  => time() + 60,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => false,
    'samesite' => 'Lax',
  ]);
}

function qta_fail(int $code, string $message): never {
  qta_download_status('error', $message);
  http_response_code($code);
  exit($message);
}

register_shutdown_function(function () {
  $err = error_get_last();
  if (!$err) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (in_array($err['type'] ?? 0, $fatalTypes, true) && !headers_sent()) {
    qta_download_status('error', 'Dokumenti nuk u gjenerua. Ju lutem provo perseri.');
  }
});

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function qta_audit_event(string $type, array $payload): void {
  try {
    if (function_exists('qta_audit_log')) {
      qta_audit_log($GLOBALS['pdo'] ?? null, $type, $payload);
    }
  } catch (Throwable $e) {}
}

/* Guard */
if (!isset($_SESSION['user_id'])) { qta_fail(401, 'Unauthorized'); }

$u = $pdo->prepare("
  SELECT u.id, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($currentUser['role_name'] ?? ''));

if (!$currentUser || !in_array($role, ['administrator','editor'], true)) {
  qta_fail(403, 'Forbidden');
}

/* POST only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  qta_fail(405, 'Method Not Allowed');
}

/* CSRF */
if (empty($_POST['csrf']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf'])) {
  qta_fail(400, 'CSRF token mismatch.');
}

/* Input */
$groupId = (int)($_POST['group_id'] ?? 0);
if ($groupId <= 0) { qta_fail(400, 'group_id i pavlefshem.'); }

$format = strtolower(trim((string)($_POST['format'] ?? 'doc')));
if (!in_array($format, ['doc','pdf'], true)) $format = 'doc';

/* Students (vetëm emri-atësia-mbiemri) */
$st = $pdo->prepare("
  SELECT
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

/* Build rows */
$rowsHtml = '';
$i = 0;
foreach ($students as $row) {
  $i++;
  $fn = trim((string)($row['first_name'] ?? ''));
  $fa = trim((string)($row['father_name'] ?? ''));
  $ln = trim((string)($row['last_name'] ?? ''));
  $name = trim($fn . ' ' . ($fa !== '' ? ($fa . ' ') : '') . $ln);
  if ($name === '') $name = ' ';

  $rowsHtml .= '
    <tr>
      <td class="nr">'. $i .'</td>
      <td class="name">'. e($name) .'</td>
    </tr>
  ';
}

/* HTML = vetëm tabela (asgjë tjetër) */
$html = '<!doctype html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Lista emerore - Grup '.$groupId.'</title>
<style>
  @page { size: A4; margin: 1.35cm; }
  body { font-family: "Times New Roman", serif; font-size: 12pt; color:#111; }

  /* Content width + e centruar */
  .wrap { text-align: left; }
  table{
    display: inline-table;   /* content width */
    width: auto;             /* jo 100% */
    border-collapse: collapse;
  }

  th, td { border: 1px solid #000; padding: 8px 10px; }
  th { font-weight: bold; text-align: left; }
  .nr { width: 60px; text-align: center; font-weight: bold; }
</style>
</head>
<body>
  <div class="wrap">
    <table>
        <thead>
        <tr>
            <th style="width:60px;">NR.</th>
            <th>EMRI ATËSIA MBIEMËR</th>
        </tr>
        </thead>
        <tbody>
        '.($rowsHtml !== '' ? $rowsHtml : '<tr><td class="nr">—</td><td class="name">—</td></tr>').'
        </tbody>
    </table>
  </div>
</body>
</html>';

/* Audit */
qta_audit_event('lista_emerore.download', [
  'group_id'      => $groupId,
  'format'        => $format,
  'actor_user_id' => $_SESSION['user_id'] ?? null
]);

if (ob_get_length()) { ob_end_clean(); }

$baseName = 'Lista_Emerore_Grupi_'.$groupId;
header('X-File-Download: 1');
qta_download_status('ok', 'Dokumenti u gjenerua me sukses.');

if ($format === 'pdf') {
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (!is_file($autoload)) {
    qta_fail(500, 'PDF kerkon dompdf. Instalo: composer require dompdf/dompdf');
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
