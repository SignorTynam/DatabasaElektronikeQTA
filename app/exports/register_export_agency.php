<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

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

/* ------------------------------
   Guard: vetëm "agjencia"
--------------------------------*/
if (!isset($_SESSION['user_id'])) {
  qta_download_status('error', 'Sesioni ka skaduar. Ju lutem kycuni perseri.');
  header('Location: selectProfile.php');
  exit;
}
$usr = $pdo->prepare("
  SELECT u.id, r.name as role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:uid LIMIT 1
");
$usr->execute([':uid'=>$_SESSION['user_id']]);
$me = $usr->fetch(PDO::FETCH_ASSOC);
if (!$me || $me['role_name']!=='agjencia') {
  qta_download_status('error', 'Nuk jeni i autorizuar per kete veprim.');
  header('Location: selectProfile.php');
  exit;
}

/* Agjencia e këtij user-i */
$ast = $pdo->prepare("SELECT id, company_name FROM agencies WHERE user_id=:uid LIMIT 1");
$ast->execute([':uid'=>$me['id']]);
$AGENCY = $ast->fetch(PDO::FETCH_ASSOC);
if (!$AGENCY) { qta_fail(403, 'No agency bound to this user.'); }
// CSRF token për eksport
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];


/* ------------------------------
   CSRF
--------------------------------*/
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_GET['csrf'] ?? ''))) {
  qta_fail(400, 'Invalid CSRF token.');
}

/* ------------------------------
   Parametrat
--------------------------------*/
$f = strtolower((string)($_GET['f'] ?? 'xlsx'));   // xlsx|pdf|docx
$q = trim((string)($_GET['q'] ?? ''));

/* Auto-load librarish (nëse ekziston vendor/) */
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) { require_once $autoload; }

/* ------------------------------
   Nxjerrja e të dhënave (vetëm për këtë agjenci)
--------------------------------*/
$params = [':agid' => (int)$AGENCY['id']];
$whereQ = '';
if ($q !== '') {
  $whereQ = " AND (s.nr_amze LIKE :kw OR s.personal_number LIKE :kw2
                   OR s.first_name LIKE :kw3 OR s.father_name LIKE :kw4 OR s.last_name LIKE :kw5)";
  $params[':kw']  = '%'.$q.'%';
  $params[':kw2'] = '%'.$q.'%';
  $params[':kw3'] = '%'.$q.'%';
  $params[':kw4'] = '%'.$q.'%';
  $params[':kw5'] = '%'.$q.'%';
}

$sql = "
  SELECT
    s.id AS student_id,
    s.nr_amze,
    s.first_name, s.father_name, s.last_name,
    s.personal_number,
    s.birth_date, s.birth_place,
    TIMESTAMPDIFF(YEAR, s.birth_date, CURDATE()) AS age,
    el.code AS edu_code, el.label AS edu_label,
    lastg.group_id, cg.start_date, cg.end_date, cg.exam_date,
    cgs.final_score
  FROM agency_students asg
  JOIN students s ON s.id = asg.student_id
  JOIN users u ON u.id = s.user_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  LEFT JOIN (
      SELECT t.student_id, t.group_id
      FROM (
        SELECT cgs.student_id, cgs.group_id,
               ROW_NUMBER() OVER (PARTITION BY cgs.student_id ORDER BY cg.start_date DESC, cg.id DESC) AS rn
        FROM course_group_students cgs
        JOIN course_groups cg ON cg.id = cgs.group_id
      ) t
      WHERE t.rn = 1
  ) lastg ON lastg.student_id = s.id
  LEFT JOIN course_group_students cgs ON cgs.group_id = lastg.group_id AND cgs.student_id = s.id
  LEFT JOIN course_groups cg ON cg.id = lastg.group_id
  WHERE asg.agency_id = :agid
  $whereQ
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) {
  $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------
   Përgatitje dataset-i
--------------------------------*/
$headers = [
  'AMZË', 'Emër', 'Atësi', 'Mbiemër', 'ID Personal',
  'Datë fillimi', 'Datë mbarimi', 'Datë testimi',
  'Pikët përfundimtare', 'Mosha', 'Arsimi'
];

$data = [];
foreach ($rows as $r) {
  $edu = '';
  if (!empty($r['edu_code']) || !empty($r['edu_label'])) {
    $edu = trim(($r['edu_code'] ? $r['edu_code'].' — ' : '').($r['edu_label'] ?? ''));
  }
  $data[] = [
    $r['nr_amze'] ?? '',
    $r['first_name'] ?? '',
    $r['father_name'] ?? '',
    $r['last_name'] ?? '',
    $r['personal_number'] ?? '',
    $r['start_date'] ?: '',
    $r['end_date'] ?: '',
    $r['exam_date'] ?: '',
    ($r['final_score'] !== null ? (string)$r['final_score'] : ''),
    ($r['age'] !== null ? (string)(int)$r['age'] : ''),
    $edu
  ];
}

/* ------------------------------
   Emri i file-it
--------------------------------*/
function slugify(string $s): string {
  $s = iconv('UTF-8','ASCII//TRANSLIT', $s);
  $s = preg_replace('~[^A-Za-z0-9]+~','-',$s);
  $s = trim($s,'-');
  return $s !== '' ? $s : 'Agjencia';
}
$agencySlug = slugify((string)($AGENCY['company_name'] ?? 'Agjencia'));
$now = date('Y-m-d');

/* ------------------------------
   Helper HTML table (Word/PDF fallback)
--------------------------------*/
function htmlTable(array $headers, array $data, string $title): string {
  $esc = fn($x)=>htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
  ob_start(); ?>
  <!doctype html>
  <html lang="sq"><head>
    <meta charset="utf-8">
    <title><?= $esc($title) ?></title>
    <style>
      body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color:#111; }
      h2 { margin:0 0 10px 0; }
      .meta { color:#555; margin-bottom:12px; }
      table { border-collapse: collapse; width: 100%; }
      th, td { border:1px solid #ddd; padding:6px 8px; vertical-align: top; }
      th { background:#f1f5f9; text-align:left; }
      tr:nth-child(even) td { background:#fafafa; }
    </style>
  </head><body>
    <h2><?= $esc($title) ?></h2>
    <div class="meta">Gjeneruar më: <?= date('Y-m-d H:i') ?></div>
    <table>
      <thead><tr>
        <?php foreach($headers as $h): ?><th><?= $esc($h) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php if (!$data): ?>
          <tr><td colspan="<?= count($headers) ?>">Nuk ka të dhëna.</td></tr>
        <?php else: foreach($data as $row): ?>
          <tr><?php foreach($row as $cell): ?><td><?= $esc($cell) ?></td><?php endforeach; ?></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </body></html>
  <?php
  return (string)ob_get_clean();
}

/* ------------------------------
   Eksporti sipas formatit
--------------------------------*/
$title = "Regjistri i studentëve – {$AGENCY['company_name']}";

function signal_download_ready(): void {
  header('X-File-Download: 1');
  qta_download_status('ok', 'Dokumenti u gjenerua me sukses.');
}

signal_download_ready();

switch ($f) {
  case 'xlsx':
    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
      $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
      $sheet = $spreadsheet->getActiveSheet();
      $sheet->setTitle('Regjistri');

      // Header
      $col = 1;
      foreach ($headers as $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1';
        $sheet->setCellValue($cell, $h);
        $col++;
      }
      // Bold header
      $headerRange = 'A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1';
      $sheet->getStyle($headerRange)->getFont()->setBold(true);

      // Data
      $rowIdx = 2;
      foreach ($data as $row) {
        $col = 1;
        foreach ($row as $cell) {
          // Final score & age si numra nëse janë numerikë
          if (($col===9 || $col===10) && $cell !== '' && is_numeric($cell)) {
            $sheet->setCellValueExplicit(
              \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $rowIdx,
              (float)$cell,
              \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
            );
          } else {
            $cellCoordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $rowIdx;
            $sheet->setCellValue($cellCoordinate, $cell);
          }
          $col++;
        }
        $rowIdx++;
      }

      // Auto-size columns
      for ($c = 1; $c <= count($headers); $c++) {
        $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
      }

      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment; filename="QTA-Regjistri-'.$agencySlug.'-'.$now.'.xlsx"');
      header('Cache-Control: max-age=0');
      if (ob_get_length()) { ob_end_clean(); }
      $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
      $writer->save('php://output');
      exit;
    } else {
      // Fallback: CSV kompatibël me Excel
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="QTA-Regjistri-'.$agencySlug.'-'.$now.'.csv"');
      if (ob_get_length()) { ob_end_clean(); }
      $out = fopen('php://output', 'w');
      // UTF-8 BOM që Excel t’i lexojë shkronjat shqip
      fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
      fputcsv($out, $headers);
      foreach ($data as $row) { fputcsv($out, $row); }
      fclose($out);
      exit;
    }

  case 'pdf':
    $html = htmlTable($headers, $data, $title);
    if (class_exists('\Dompdf\Dompdf')) {
      $dompdf = new \Dompdf\Dompdf(['isHtml5ParserEnabled'=>true, 'isRemoteEnabled'=>true]);
      $dompdf->loadHtml($html, 'UTF-8');
      $dompdf->setPaper('A4', 'landscape');
      $dompdf->render();
      if (ob_get_length()) { ob_end_clean(); }
      $dompdf->stream('QTA-Regjistri-'.$agencySlug.'-'.$now.'.pdf', ['Attachment'=>true]);
      exit;
    } else {
      // Fallback: jep HTML për “Print to PDF”
      header('Content-Type: text/html; charset=utf-8');
      echo $html;
      exit;
    }

  case 'docx':
    if (class_exists('\PhpOffice\PhpWord\PhpWord')) {
      $phpWord = new \PhpOffice\PhpWord\PhpWord();
      $section = $phpWord->addSection(['orientation'=>'landscape']);
      $section->addText($title, ['bold'=>true, 'size'=>14]);
      $section->addText('Gjeneruar më: '.date('Y-m-d H:i'), ['size'=>10], ['spaceAfter'=>200]);

      $styleName = 'QTA-Table';
      $phpWord->addTableStyle($styleName, [
        'borderSize' => 6,
        'borderColor'=> 'DDDDDD',
        'cellMargin' => 80
      ], [
        'bgColor'    => 'F1F5F9'
      ]);

      $table = $section->addTable($styleName);
      // Header
      $headerRow = $table->addRow();
      foreach ($headers as $h) {
        $headerRow->addCell(1750)->addText($h, ['bold'=>true]);
      }
      // Data
      foreach ($data as $row) {
        $tr = $table->addRow();
        foreach ($row as $cell) {
          $tr->addCell(1750)->addText((string)$cell);
        }
      }

      header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
      header('Content-Disposition: attachment; filename="QTA-Regjistri-'.$agencySlug.'-'.$now.'.docx"');
      if (ob_get_length()) { ob_end_clean(); }
      $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
      $writer->save('php://output');
      exit;
    } else {
      // Fallback: HTML i hapshëm me Word
      $html = htmlTable($headers, $data, $title);
      header('Content-Type: application/msword; charset=utf-8');
      header('Content-Disposition: attachment; filename="QTA-Regjistri-'.$agencySlug.'-'.$now.'.doc"');
      echo $html;
      exit;
    }

  default:
    qta_fail(400, 'Format i panjohur.');
}
