<?php
declare(strict_types=1);
session_start();
mb_internal_encoding('UTF-8');

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
    qta_download_status('error', 'Dokumenti nuk u gjenerua. Ju lutem provoni përsëri.');
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
  qta_fail(500, 'Composer autoload nuk u gjet.');
}

if (!isset($_SESSION['user_id'])) {
  qta_download_status('error', 'Sesioni ka skaduar. Ju lutem kyçuni përsëri.');
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
  qta_download_status('error', 'Nuk jeni i autorizuar për këtë veprim.');
  header('Location: selectProfile.php');
  exit;
}

$csrfSession = $_SESSION['csrf_token'] ?? '';
$csrfQuery = $_GET['csrf'] ?? '';
if (!$csrfSession || !hash_equals($csrfSession, $csrfQuery)) {
  qta_fail(403, 'CSRF është i pavlefshëm ose mungon.');
}

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$format = strtolower(trim((string)($_GET['f'] ?? 'xlsx')));

if ($type !== 'qkl') {
  qta_fail(400, 'Parametri type i panjohur. Përdor type=qkl.');
}
if (!in_array($format, ['xlsx', 'pdf'], true)) {
  qta_fail(400, 'Format i panjohur. Për Raportin për QKL përdor vetëm f=xlsx ose f=pdf.');
}

$amzeStart = filter_input(INPUT_GET, 'amze_start', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$amzeEnd = filter_input(INPUT_GET, 'amze_end', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$amzeStart || !$amzeEnd) {
  qta_fail(400, 'Intervali AMZË është i pavlefshëm. AMZË fillimi dhe AMZË mbarimi duhet të jenë numra pozitivë.');
}
if ($amzeStart > $amzeEnd) {
  qta_fail(400, 'Intervali AMZË është i pavlefshëm. AMZË fillimi duhet të jetë më i vogël ose i barabartë me AMZË mbarimi.');
}

@ini_set('memory_limit', '512M');
@set_time_limit(120);

function qkl_iso_to_dmy(?string $iso): string {
  if (!$iso || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return '';
  $ts = strtotime($iso);
  return $ts ? date('d-m-Y', $ts) : '';
}

function qkl_clean_key(string $value): string {
  $value = mb_strtolower(trim($value), 'UTF-8');
  return strtr($value, [
    'ë' => 'e',
    'ç' => 'c',
    'Ë' => 'e',
    'Ç' => 'c',
  ]);
}

function qkl_gender_label(?string $code, ?string $label): string {
  $code = strtoupper(trim((string)$code));
  if ($code === 'M') return 'Mashkull';
  if ($code === 'F') return 'Femër';
  return trim((string)$label);
}

function qkl_education_label(?string $code, ?string $label): string {
  $code = strtoupper(trim((string)$code));
  if ($code === 'AU') return 'Arsim 8/9 vjeçar';
  if ($code === 'AM') return 'Arsim i mesëm';
  if ($code === 'AL') return 'Arsim i lartë';

  $normalized = qkl_clean_key((string)$label);
  if ($normalized === '') return '';
  if (str_contains($normalized, '8') || str_contains($normalized, '9') || str_contains($normalized, 'ulet') || str_contains($normalized, 'ulët')) {
    return 'Arsim 8/9 vjeçar';
  }
  if (str_contains($normalized, 'mes')) return 'Arsim i mesëm';
  if (str_contains($normalized, 'lart')) return 'Arsim i lartë';
  return '';
}

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

function qkl_report_headers(): array {
  return [
    'Nr.ID',
    'Emër',
    'Atësi',
    'Mbiemër',
    'Shtetësia',
    'Gjinia',
    'Datëlindje',
    'Arsimi',
    'Nr.Amze',
    'Emërtimi i kursit',
    'Datë fillimi',
    'Datë mbarimi',
    'Datë certifikimi',
    'Datë ndërprerje',
  ];
}

function qkl_build_rows(array $records): array {
  $rows = [];
  foreach ($records as $record) {
    $rows[] = [
      (string)($record['personal_number'] ?? ''),
      (string)($record['first_name'] ?? ''),
      (string)($record['father_name'] ?? ''),
      (string)($record['last_name'] ?? ''),
      trim((string)($record['citizenship_value'] ?? '')) !== '' ? (string)$record['citizenship_value'] : 'Shqiptare',
      qkl_gender_label($record['gender_code'] ?? null, $record['gender_label'] ?? null),
      qkl_iso_to_dmy($record['birth_date'] ?? null),
      qkl_education_label($record['edu_code'] ?? null, $record['edu_label'] ?? null),
      (string)($record['nr_amze'] ?? ''),
      (string)($record['course_name'] ?? ''),
      qkl_iso_to_dmy($record['start_date'] ?? null),
      qkl_iso_to_dmy($record['end_date'] ?? null),
      qkl_iso_to_dmy($record['exam_date'] ?? null),
      '',
    ];
  }
  return $rows;
}

function qkl_output_xlsx(array $rows, string $filenameBase): void {
  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('Raporti QKL');

  $sheet->setCellValue('A2', 'Emërtimi i Subjektit:');
  $sheet->setCellValue('B2', 'Qendra e Trajnimeve të Avancuara');
  $sheet->setCellValue('A3', 'NIPT:');
  $sheet->setCellValue('B3', 'L61325037A');
  $sheet->setCellValue('A4', 'Numër Licence:');
  $sheet->setCellValue('B4', 'LN-2358-11-2016');
  $sheet->setCellValue('A5', 'Adresë/Kontakt:');
  $sheet->setCellValue('B5', 'Rruga Bilal Konxholli');

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
  $escape = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $headers = qkl_report_headers();
  $chunks = $rows ? array_chunk($rows, 20) : [[]];

  ob_start(); ?>
  <!doctype html>
  <html lang="sq">
  <head>
    <meta charset="UTF-8">
    <style>
      @page { margin: 18px; }
      * { font-family: DejaVu Sans, sans-serif; font-size: 7.5px; }
      .page { page-break-after: always; }
      .page:last-child { page-break-after: auto; }
      table { width: 100%; border-collapse: collapse; }
      .subject { margin-bottom: 8px; }
      .subject td { border: 0; padding: 2px 4px; font-size: 8.5px; }
      .subject td:first-child { font-weight: bold; width: 120px; }
      .report { table-layout: fixed; }
      .report th, .report td { border: 1px solid #777; padding: 2px 3px; vertical-align: top; word-break: break-word; }
      .report th { background: #efefef; font-weight: bold; }
      .col-id { width: 8%; }
      .col-name, .col-father, .col-last { width: 7%; }
      .col-citizen, .col-gender { width: 6%; }
      .col-date { width: 7%; }
      .col-edu { width: 8%; }
      .col-amze { width: 6%; }
      .col-course { width: 12%; }
    </style>
  </head>
  <body>
    <?php foreach ($chunks as $pageIndex => $chunk): ?>
      <div class="page">
        <?php if ($pageIndex === 0): ?>
          <table class="subject">
            <tr><td>Emërtimi i Subjektit:</td><td>Qendra e Trajnimeve të Avancuara</td></tr>
            <tr><td>NIPT:</td><td>L61325037A</td></tr>
            <tr><td>Numër Licence:</td><td></td></tr>
            <tr><td>Adresë/Kontakt:</td><td>Rruga Bilal Konxholli</td></tr>
          </table>
        <?php endif; ?>
        <table class="report">
          <thead>
            <tr>
              <?php foreach ($headers as $idx => $header): ?>
                <?php
                  $classes = [
                    0 => 'col-id',
                    1 => 'col-name',
                    2 => 'col-father',
                    3 => 'col-last',
                    4 => 'col-citizen',
                    5 => 'col-gender',
                    6 => 'col-date',
                    7 => 'col-edu',
                    8 => 'col-amze',
                    9 => 'col-course',
                    10 => 'col-date',
                    11 => 'col-date',
                    12 => 'col-date',
                    13 => 'col-date',
                  ];
                ?>
                <th class="<?= $classes[$idx] ?? '' ?>"><?= $escape($header) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($chunk as $row): ?>
          <tr>
            <?php foreach ($row as $cell): ?>
              <td><?= $escape($cell) ?></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  </body>
  </html>
  <?php
  $html = ob_get_clean();

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

$sql = "
  SELECT
    s.nr_amze,
    p.personal_number,
    p.first_name,
    p.father_name,
    p.last_name,
    p.birth_date,
    $citizenshipSelect,
    g.code AS gender_code,
    g.label AS gender_label,
    el.code AS edu_code,
    el.label AS edu_label,
    lastg.course_name,
    lastg.start_date,
    lastg.end_date,
    lastg.exam_date
  FROM students s
  JOIN persons p ON p.id = s.person_id
  LEFT JOIN genders g ON g.id = p.gender_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  LEFT JOIN (
    SELECT t.student_id, t.course_name, t.start_date, t.end_date, t.exam_date
    FROM (
      SELECT
        cgs.student_id,
        c.name AS course_name,
        cg.start_date,
        cg.end_date,
        cgs.exam_date,
        ROW_NUMBER() OVER (PARTITION BY cgs.student_id ORDER BY cg.start_date DESC, cg.id DESC) AS rn
      FROM course_group_students cgs
      JOIN course_groups cg ON cg.id = cgs.group_id
      JOIN courses c ON c.id = cg.course_id
    ) t
    WHERE t.rn = 1
  ) lastg ON lastg.student_id = s.id
  WHERE CAST(s.nr_amze AS UNSIGNED) BETWEEN :amze_start AND :amze_end
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':amze_start' => $amzeStart,
  ':amze_end' => $amzeEnd,
]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$rows = qkl_build_rows($records);
$filenameBase = 'raporti_qkl_' . date('Ymd_His');

if ($format === 'xlsx') {
  qkl_output_xlsx($rows, $filenameBase);
}

qkl_output_pdf($rows, $filenameBase);
