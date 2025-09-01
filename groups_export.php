<?php
declare(strict_types=1);
session_start();
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/database.php';
$pdo = getPDO();

/* Autoload i Composer (si te register_export.php) */
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
  echo "Composer autoload nuk u gjet. Ekzekuto 'composer require phpoffice/phpspreadsheet phpoffice/phpword dompdf/dompdf'.";
  exit;
}

/* Guard admin + CSRF */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$me = $u->fetch();
if (!$me || $me['role_name']!=='administrator') { header('Location: selectProfile.php'); exit; }

$csrfSession = $_SESSION['csrf_token'] ?? '';
$csrfQuery   = $_GET['csrf'] ?? '';
if (!$csrfSession || !hash_equals($csrfSession, $csrfQuery)) {
  http_response_code(403); echo 'CSRF gabim ose mungon.'; exit;
}

/* Parametra */
$type = strtolower(trim((string)($_GET['type'] ?? '')));
$fmt  = strtolower(trim((string)($_GET['f'] ?? 'xlsx')));  // xlsx|pdf|docx

/* Helpers export */
function outXlsx(array $headers, array $rows, string $title, string $filename): void {
  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle(mb_substr($title,0,31));

  $col = 1;
  foreach ($headers as $h) {
    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).'1';
    $sheet->setCellValue($cell, $h);
    $col++;
  }
  $r=2;
  foreach ($rows as $row) {
    $c=1;
    foreach ($row as $val) {
      $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).$r;
      $sheet->setCellValue($cell, $val);
      $c++;
    }
    $r++;
  }
  $highestCol = $sheet->getHighestColumn();
  foreach (range('A',$highestCol) as $L) $sheet->getColumnDimension($L)->setAutoSize(true);

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
  header('Cache-Control: max-age=0');
  ( \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet,'Xlsx') )->save('php://output');
  exit;
}

function outPdf(array $headers, array $rows, string $title, string $filename): void {
  $e = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
  ob_start(); ?>
  <html><head><meta charset="UTF-8" />
  <style>
    * { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
    h3 { margin: 0 0 10px 0; }
    table { width:100%; border-collapse:collapse; }
    th,td { border:1px solid #999; padding:4px 6px; }
    th { background:#f1f3f5; }
  </style>
  </head><body>
    <h3><?= $e($title) ?></h3>
    <table>
      <thead><tr>
        <?php foreach($headers as $h): ?><th><?= $e($h) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?><tr>
          <?php foreach($r as $v): ?><td><?= $e($v) ?></td><?php endforeach; ?>
        </tr><?php endforeach; ?>
      </tbody>
    </table>
  </body></html>
  <?php
  $html = ob_get_clean();
  $dompdf = new \Dompdf\Dompdf((new \Dompdf\Options())->set('isRemoteEnabled', true));
  $dompdf->loadHtml($html,'UTF-8');
  $dompdf->setPaper('A4','landscape');
  $dompdf->render();
  $dompdf->stream($filename.'.pdf', ['Attachment'=>true]);
  exit;
}

function outDocx(array $headers, array $rows, string $title, string $filename): void {
  $phpWord = new \PhpOffice\PhpWord\PhpWord();
  $section = $phpWord->addSection(['orientation'=>'landscape','marginLeft'=>600,'marginRight'=>600,'marginTop'=>600,'marginBottom'=>600]);
  $section->addText($title, ['bold'=>true,'size'=>14], ['spaceAfter'=>200]);
  $styleTable = ['borderSize'=>6,'borderColor'=>'999999','cellMargin'=>80];
  $styleFirst = ['bgColor'=>'F1F3F5'];
  $phpWord->addTableStyle('tbl', $styleTable, $styleFirst);
  $t = $section->addTable('tbl');
  $t->addRow();
  foreach($headers as $h) { $t->addCell()->addText($h, ['bold'=>true]); }
  foreach($rows as $r) {
    $t->addRow();
    foreach($r as $v) $t->addCell()->addText((string)$v);
  }
  header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  header('Content-Disposition: attachment; filename="'.$filename.'.docx"');
  header('Cache-Control: max-age=0');
  (\PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007'))->save('php://output');
  exit;
}

function exportAny(array $headers, array $rows, string $title, string $filename, string $fmt): void {
  switch ($fmt) {
    case 'xlsx': outXlsx($headers,$rows,$title,$filename); break;
    case 'pdf' : outPdf($headers,$rows,$title,$filename); break;
    case 'docx': outDocx($headers,$rows,$title,$filename); break;
    default: http_response_code(400); echo 'Format i panjohur.'; exit;
  }
}

/* ------------------------------
   FORM 1: grupi fillim…mbarim
------------------------------- */
if ($type === 'form1') {
  $gstart = (int)($_GET['gstart'] ?? 0);
  $gend   = (int)($_GET['gend'] ?? 0);
  if ($gstart<=0 || $gend<=0 || $gstart>$gend) { http_response_code(400); echo 'Interval grupe i pavlefshëm.'; exit; }

  $sql = "
    SELECT
      cg.id AS group_id,
      c.code AS course_code, c.name AS course_name,
      cg.start_date, cg.end_date,
      COUNT(s.id) AS total,

      /* Femra nga persons.gender_id -> genders.code = 'F' */
      SUM(CASE WHEN g.code='F' THEN 1 ELSE 0 END) AS females,

      /* Grupmoshat nga persons.birth_date */
      SUM(
        CASE WHEN p.birth_date IS NOT NULL
          AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) BETWEEN 16 AND 24
        THEN 1 ELSE 0 END
      ) AS age_16_24,
      SUM(
        CASE WHEN p.birth_date IS NOT NULL
          AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) BETWEEN 25 AND 34
        THEN 1 ELSE 0 END
      ) AS age_25_34,
      SUM(
        CASE WHEN p.birth_date IS NOT NULL
          AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) >= 35
        THEN 1 ELSE 0 END
      ) AS age_35_plus,

      /* Arsimi nga students.education_level_id */
      SUM(CASE WHEN el.code='AU' THEN 1 ELSE 0 END) AS cnt_AU,
      SUM(CASE WHEN el.code='AM' THEN 1 ELSE 0 END) AS cnt_AM,
      SUM(CASE WHEN el.code='AL' THEN 1 ELSE 0 END) AS cnt_AL,

      MIN(CAST(s.nr_amze AS UNSIGNED)) AS amze_min,
      MAX(CAST(s.nr_amze AS UNSIGNED)) AS amze_max
    FROM course_groups cg
    JOIN courses c ON c.id = cg.course_id
    LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
    LEFT JOIN students s ON s.id = cgs.student_id
    LEFT JOIN persons  p ON p.id = s.person_id
    LEFT JOIN genders  g ON g.id = p.gender_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    WHERE cg.id BETWEEN :gs AND :ge
    GROUP BY cg.id
    ORDER BY cg.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':gs'=>$gstart, ':ge'=>$gend]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $headers = [
    'Grup ID','Kursi','Fillimi','Mbarimi','Totale','Femra',
    '16–24','25–34','35+','AU','AM','AL','AMZË (min–max)'
  ];
  $data = [];
  foreach ($rows as $r) {
    $labelCourse = ($r['course_code'] ? ($r['course_code'].' · ') : '') . ($r['course_name'] ?? '');
    $amzeSpan = ($r['amze_min']===null || $r['amze_max']===null) ? '' : ($r['amze_min'].'–'.$r['amze_max']);
    $data[] = [
      (int)$r['group_id'],
      $labelCourse,
      $r['start_date'] ?? '',
      $r['end_date'] ?? '',
      (int)$r['total'],
      (int)$r['females'],
      (int)$r['age_16_24'],
      (int)$r['age_25_34'],
      (int)$r['age_35_plus'],
      (int)$r['cnt_AU'],
      (int)$r['cnt_AM'],
      (int)$r['cnt_AL'],
      $amzeSpan
    ];
  }

  exportAny($headers, $data, 'Formulari nr. 1 — Grupe', 'form1_grupe_'.date('Ymd_His'), $fmt);
  exit;
}

/* ------------------------------
   FORM 2: AMZË fillim…mbarim
------------------------------- */
if ($type === 'form2') {
  $a1 = (int)($_GET['amze_start'] ?? 0);
  $a2 = (int)($_GET['amze_end'] ?? 0);
  if ($a1<=0 || $a2<=0 || $a1>$a2) { http_response_code(400); echo 'Interval AMZË i pavlefshëm.'; exit; }

  $sql = "
    SELECT
      s.nr_amze,
      p.first_name, p.father_name, p.last_name,
      p.birth_place,
      c.name AS course_name
    FROM students s
    JOIN persons p ON p.id = s.person_id

    /* Grupi i fundit i studentit sipas start_date DESC (MySQL 8+) */
    LEFT JOIN (
      SELECT t.student_id, t.course_id
      FROM (
        SELECT cgs.student_id, cg.course_id,
               ROW_NUMBER() OVER (PARTITION BY cgs.student_id ORDER BY cg.start_date DESC, cg.id DESC) AS rn
        FROM course_group_students cgs
        JOIN course_groups cg ON cg.id = cgs.group_id
      ) t
      WHERE t.rn = 1
    ) lastg ON lastg.student_id = s.id

    LEFT JOIN courses c ON c.id = lastg.course_id

    WHERE CAST(s.nr_amze AS UNSIGNED) BETWEEN :a1 AND :a2
    ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':a1'=>$a1, ':a2'=>$a2]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $headers = ['AMZË','Emër','Atësi','Mbiemër','Vendlindja','Emri i kursit'];
  $data = [];
  foreach ($rows as $r) {
    $data[] = [
      $r['nr_amze'] ?? '',
      $r['first_name'] ?? '',
      $r['father_name'] ?? '',
      $r['last_name'] ?? '',
      $r['birth_place'] ?? '',
      $r['course_name'] ?? ''
    ];
  }

  exportAny($headers, $data, 'Formulari nr. 2 — AMZË', 'form2_amze_'.date('Ymd_His'), $fmt);
  exit;
}

http_response_code(400);
echo 'Parametri type i panjohur. Përdor type=form1 ose type=form2.';
