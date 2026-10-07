<?php
declare(strict_types=1);
session_start();
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/database.php';
$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
require_once __DIR__ . '/inc/qkl_report.php';
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
    qta_download_status('error', 'Dokumenti nuk u krijua. Provo sërish; nëse përsëritet, njofto administratorin.');
  }
});

$autoloadCandidates = [
  __DIR__ . '/vendor/autoload.php',
  __DIR__ . '/../vendor/autoload.php',
  $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
];
$autoloadLoaded = false;
foreach ($autoloadCandidates as $path) {
  if (is_file($path)) {
    require_once $path;
    $autoloadLoaded = true;
    break;
  }
}
if (!$autoloadLoaded) {
  error_log('[QTA eksport] groups_export: mungon vendor/autoload.php (composer install)');
  qta_fail(500, 'Dokumenti nuk mund të krijohet tani, sepse në server mungojnë programet e dokumenteve. Njofto administratorin.');
}

if (!isset($_SESSION['user_id'])) {
  qta_download_status('error', 'Sesioni ka mbaruar. Hyr sërish në llogari dhe provo përsëri.');
  header('Location: selectProfile.php');
  exit;
}

$userStmt = $pdo->prepare("
  SELECT u.id, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :id
  LIMIT 1
");
$userStmt->execute([':id' => $_SESSION['user_id']]);
$me = $userStmt->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($me['role_name'] ?? ''));
if (!$me || !in_array($role, ['administrator', 'editor'], true)) {
  qta_download_status('error', 'Nuk ke leje për këtë dokument.');
  header('Location: selectProfile.php');
  exit;
}

/* POST (i preferuar: tokeni nuk del në URL) ose GET për lidhjet e vjetra */
$request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$csrfSession = $_SESSION['csrf_token'] ?? '';
$csrfQuery = (string)($request['csrf'] ?? '');
if (!$csrfSession || !hash_equals($csrfSession, $csrfQuery)) {
  qta_fail(403, 'Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.');
}

$type = strtolower(trim((string)($request['type'] ?? '')));
$format = strtolower(trim((string)($request['f'] ?? 'xlsx')));

if ($type !== 'qkl') {
  qta_fail(400, 'Ky raport nuk njihet. Rifresko faqen dhe provo sërish.');
}
if (!in_array($format, ['xlsx', 'pdf'], true)) {
  qta_fail(400, 'Zgjidh formatin e raportit: Excel ose PDF.');
}

$amzeStart = filter_var($request['amze_start'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$amzeEnd = filter_var($request['amze_end'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$amzeStart || !$amzeEnd) {
  qta_fail(400, 'Shkruaj numrat e amzës si numra të plotë, p.sh. nga 3400 deri te 3499.');
}
if ($amzeStart > $amzeEnd) {
  qta_fail(400, 'Numri i parë i amzës duhet të jetë më i vogël ose i njëjtë me të fundit.');
}

@ini_set('memory_limit', '512M');
@set_time_limit(120);

function qkl_first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
  $stmt = $pdo->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = :table
  ");
  $stmt->execute([':table' => $table]);
  $columns = array_flip(array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
  foreach ($candidates as $candidate) {
    if (isset($columns[strtolower($candidate)])) return $candidate;
  }
  return null;
}

function qkl_prepare_output(): void {
  if (function_exists('ini_get') && ini_get('zlib.output_compression')) {
    @ini_set('zlib.output_compression', 'Off');
  }
  while (ob_get_level() > 0) {
    @ob_end_clean();
  }
}

function qkl_stream_file(string $tmpPath, string $mime, string $downloadName): never {
  qkl_prepare_output();
  qta_download_status('ok', 'Dokumenti u gjenerua me sukses.');
  header('X-File-Download: 1');
  header('Content-Type: ' . $mime);
  header('Content-Disposition: attachment; filename="' . $downloadName . '"');
  header('Content-Transfer-Encoding: binary');
  header('Cache-Control: private, max-age=0, must-revalidate');
  header('Pragma: public');
  header('X-Accel-Buffering: no');
  $size = @filesize($tmpPath);
  if ($size !== false) header('Content-Length: ' . $size);
  $fh = fopen($tmpPath, 'rb');
  if ($fh === false) qta_fail(500, 'Skedari i raportit nuk mund të lexohet.');
  fpassthru($fh);
  fclose($fh);
  @unlink($tmpPath);
  exit;
}

function qkl_output_xlsx(array $rows, string $filenameBase): void {
  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('Raporti QKL');

  foreach (qkl_report_subject() as $index => $item) {
    $row = $index + 2;
    $sheet->setCellValue('A' . $row, $item['label']);
    $sheet->setCellValue('B' . $row, $item['value']);
  }

  $headers = qkl_report_headers();
  foreach ($headers as $index => $header) {
    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . '7';
    $sheet->setCellValue($cell, $header);
  }

  $rowNumber = 8;
  foreach ($rows as $row) {
    foreach ($row as $index => $value) {
      $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . $rowNumber;
      $sheet->setCellValueExplicit($cell, (string)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $rowNumber++;
  }

  $lastRow = max(7, $rowNumber - 1);
  $sheet->getStyle('A2:A5')->getFont()->setBold(true);
  $sheet->getStyle('A7:N7')->getFont()->setBold(true);
  $sheet->getStyle('A7:N' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
  $sheet->getStyle('A7:N7')->getFill()
    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFEFEFEF');

  for ($col = 1; $col <= 14; $col++) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
    $sheet->getColumnDimension($colLetter)->setAutoSize(true);
  }

  $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
  $tmp = tempnam(sys_get_temp_dir(), 'qkl_xlsx_');
  $writer->save($tmp);
  qkl_stream_file($tmp, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filenameBase . '.xlsx');
}

function qkl_output_pdf(array $rows, string $filenameBase): void {
  $html = qkl_render_pdf_html($rows);

  @ini_set('memory_limit', '1024M');
  $options = new \Dompdf\Options();
  $options->set('isRemoteEnabled', true);
  $options->set('defaultFont', 'DejaVu Sans');
  $dompdf = new \Dompdf\Dompdf($options);
  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4', 'landscape');
  $dompdf->render();

  $tmp = tempnam(sys_get_temp_dir(), 'qkl_pdf_');
  file_put_contents($tmp, $dompdf->output());
  qkl_stream_file($tmp, 'application/pdf', $filenameBase . '.pdf');
}

$citizenshipColumn = qkl_first_existing_column($pdo, 'persons', ['citizenship', 'nationality', 'shtetesia', 'shtetesi']);
$citizenshipSelect = $citizenshipColumn
  ? 'p.`' . str_replace('`', '``', $citizenshipColumn) . '` AS citizenship_value'
  : "'Shqiptare' AS citizenship_value";

$records = qkl_fetch_records($pdo, (int)$amzeStart, (int)$amzeEnd, $citizenshipSelect);
$rows = qkl_build_rows(qkl_normalize_records($records));
$filenameBase = 'raporti_qkl_' . date('Ymd_His');

if ($format === 'xlsx') {
  qkl_output_xlsx($rows, $filenameBase);
}

qkl_output_pdf($rows, $filenameBase);
