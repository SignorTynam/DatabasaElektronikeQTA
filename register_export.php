<?php
declare(strict_types=1);
session_start();
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/database.php';
$pdo = getPDO();

/* Composer autoload */
$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
];
$autoloadLoaded = false;
foreach ($autoloadCandidates as $p) {
    if (is_file($p)) { require_once $p; $autoloadLoaded = true; break; }
}
if (!$autoloadLoaded) {
    http_response_code(500);
    echo "Composer autoload nuk u gjet. Ekzekuto 'composer require phpoffice/phpspreadsheet phpoffice/phpword dompdf/dompdf' në "
        . __DIR__ . " ose korrigjo shtegun e vendor/autoload.php.";
    exit;
}

/* Guard admin */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :uid
  LIMIT 1
");
$u->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $u->fetch();
if (!$currentUser || $currentUser['role_name'] !== 'administrator') {
    header('Location: selectProfile.php'); exit;
}

/* CSRF */
$csrfSession = $_SESSION['csrf_token'] ?? '';
$csrfQuery   = $_GET['csrf'] ?? '';
if (!$csrfSession || !hash_equals($csrfSession, $csrfQuery)) {
    http_response_code(403);
    echo 'CSRF gabim ose mungon.'; exit;
}

/* Parametra */
$f = strtolower(trim($_GET['f'] ?? 'xlsx'));  // xlsx|pdf|docx
$q = trim($_GET['q'] ?? '');

/* Filtri */
$where = ["1=1"];
$params = [];
if ($q !== '') {
  $where[] = "(s.nr_amze LIKE :kw
           OR p.personal_number LIKE :kw2
           OR p.first_name LIKE :kw3
           OR p.father_name LIKE :kw4
           OR p.last_name LIKE :kw5)";
  $params[':kw']  = '%'.$q.'%';
  $params[':kw2'] = '%'.$q.'%';
  $params[':kw3'] = '%'.$q.'%';
  $params[':kw4'] = '%'.$q.'%';
  $params[':kw5'] = '%'.$q.'%';
}
$whereSql = 'WHERE '.implode(' AND ', $where);

/* Subquery: grupi më i fundit për çdo student */
$sqlBase = "
  FROM students s
  JOIN users u   ON u.id = s.user_id
  JOIN persons p ON p.id = s.person_id
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
  $whereSql
";

/* Merr të gjitha rreshtat */
$sql = "
  SELECT
    s.id AS student_id,
    s.nr_amze,

    p.first_name, p.father_name, p.last_name,
    p.personal_number,
    p.birth_date, p.birth_place,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,

    el.code AS edu_code, el.label AS edu_label,

    lastg.group_id,
    cg.start_date, cg.end_date,
    cgs.exam_date,                 -- EXAM PER-STUDENT
    cgs.final_score
  $sqlBase
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Dataset për eksport */
$headers = [
  'AMZË','Emër','Atësi','Mbiemër','ID Personal',
  'Datëlindje','Vendilindje','Mosha','Arsimi',
  'Datë fillimi (grup)','Datë mbarimi (grup)','Datë testimi (student)','Pikët përfundimtare'
];

$data = [];
foreach ($rows as $r) {
  $edu = trim(($r['edu_code'] ? ($r['edu_code'].' — ') : '').($r['edu_label'] ?? ''));
  $score = ($r['final_score'] !== null) ? rtrim(rtrim((string)$r['final_score'],'0'),'.') : '';
  $data[] = [
    $r['nr_amze'] ?? '',
    $r['first_name'] ?? '',
    $r['father_name'] ?? '',
    $r['last_name'] ?? '',
    $r['personal_number'] ?? '',
    $r['birth_date'] ?? '',
    $r['birth_place'] ?? '',
    ($r['age'] !== null ? (int)$r['age'] : ''),
    $edu,
    $r['start_date'] ?? '',
    $r['end_date'] ?? '',
    $r['exam_date'] ?? '',
    $score
  ];
}

$filename = 'regjistri_'.date('Ymd_His');

/* ===== Eksportues ===== */
function outputXlsx(array $headers, array $data, string $filename): void {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Regjistri');

    $col = 1;
    foreach ($headers as $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1';
        $sheet->setCellValue($cell, $h);
        $col++;
    }
    $r = 2;
    foreach ($data as $row) {
        $c = 1;
        foreach ($row as $val) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
            $sheet->setCellValue($cell, $val);
            $c++;
        }
        $r++;
    }
    $highestCol = $sheet->getHighestColumn();
    foreach (range('A', $highestCol) as $colLetter) {
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

function outputPdf(array $headers, array $data, string $filename): void {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    ob_start(); ?>
    <html>
    <head>
      <meta charset="UTF-8" />
      <style>
        * { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        h3 { margin: 0 0 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 6px; }
        th { background: #f1f3f5; }
      </style>
    </head>
    <body>
      <h3>Regjistri i studentëve</h3>
      <table>
        <thead>
          <tr>
            <?php foreach ($headers as $h): ?>
              <th><?= $e($h) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data as $row): ?>
            <tr>
              <?php foreach ($row as $cell): ?>
                <td><?= $e($cell) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream($filename.'.pdf', ['Attachment' => true]);
    exit;
}

function outputDocx(array $headers, array $data, string $filename): void {
    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $section = $phpWord->addSection(['orientation' => 'landscape', 'marginLeft'=>600, 'marginRight'=>600, 'marginTop'=>600, 'marginBottom'=>600]);
    $section->addText('Regjistri i studentëve', ['bold'=>true, 'size'=>14], ['spaceAfter'=>200]);

    $styleTable = ['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80];
    $styleFirstRow = ['bgColor' => 'F1F3F5'];
    $phpWord->addTableStyle('RegTbl', $styleTable, $styleFirstRow);
    $table = $section->addTable('RegTbl');

    $table->addRow();
    foreach ($headers as $h) {
        $table->addCell()->addText($h, ['bold'=>true]);
    }
    foreach ($data as $row) {
        $table->addRow();
        foreach ($row as $cell) {
            $table->addCell()->addText((string)$cell);
        }
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="'.$filename.'.docx"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save('php://output');
    exit;
}

/* Zgjidh formatin */
switch ($f) {
  case 'xlsx': outputXlsx($headers, $data, $filename); break;
  case 'pdf' : outputPdf($headers, $data, $filename); break;
  case 'docx': outputDocx($headers, $data, $filename); break;
  default:
    http_response_code(400);
    echo 'Format i panjohur. Përdor f=xlsx|pdf|docx'; exit;
}
