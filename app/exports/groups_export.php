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
    'expires'  => time()+60,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => false,
    'samesite' => 'Lax',
  ]);
  setcookie('qta_file_msg', $message, [
    'expires'  => time()+60,
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

/* ===== Composer autoload ===== */
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
  qta_fail(500, "Composer autoload nuk u gjet. Ekzekuto 'composer require phpoffice/phpspreadsheet phpoffice/phpword dompdf/dompdf'.");
}

/* ===== Guard: admin/editor + CSRF ===== */
if (!isset($_SESSION['user_id'])) { qta_download_status('error', 'Sesioni ka skaduar. Ju lutem kycuni perseri.'); header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("SELECT u.id, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id LIMIT 1");
$u->execute([':id'=>$_SESSION['user_id']]);
$me = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($me['role_name'] ?? ''));
if (!$me || !in_array($role, ['administrator','editor'], true)) { qta_download_status('error', 'Nuk jeni i autorizuar per kete veprim.'); header('Location: selectProfile.php'); exit; }

$csrfSession = $_SESSION['csrf_token'] ?? '';
$csrfQuery   = $_GET['csrf'] ?? '';
if (!$csrfSession || !hash_equals($csrfSession, $csrfQuery)) { qta_fail(403, 'CSRF gabim ose mungon.'); }

/* ===== Parametra ===== */
$type = strtolower(trim((string)($_GET['type'] ?? '')));
$fmt  = strtolower(trim((string)($_GET['f'] ?? 'xlsx')));  // xlsx|pdf|docx

/* ===== Opsione ekzekutimi ===== */
@ini_set('memory_limit','512M');
@set_time_limit(120);

/* ===== Helpers tÃ« pÃ«rgjithshÃ«m ===== */
function iso_to_dmy(?string $iso): string {
  if (!$iso || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$iso)) return (string)$iso;
  $ts = strtotime((string)$iso); return $ts ? date('d-m-Y', $ts) : (string)$iso;
}

/* Njoftim opsional pÃ«r UI (cookie â€œfile readyâ€) */
function signal_download_ready(string $token='ok'): void {
  header('X-File-Download: 1');
  if ($token === 'ok') qta_download_status('ok', 'Dokumenti u gjenerua me sukses.');
  else qta_download_status('error', 'Dokumenti nuk u gjenerua. Ju lutem provo perseri.');
}

function get_group_amze_min(PDO $pdo, int $gid): ?int {
  $st = $pdo->prepare("
    SELECT MIN(CAST(s.nr_amze AS UNSIGNED)) AS amze_min
    FROM course_groups cg
    LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
    LEFT JOIN students s ON s.id = cgs.student_id
    WHERE cg.id = :gid
    GROUP BY cg.id
    LIMIT 1
  ");
  $st->execute([':gid' => $gid]);
  $v = $st->fetchColumn();
  if ($v === false || $v === null) return null;
  return (int)$v;
}

/* Pastrim & stream i sigurt */
function qta_prepare_output(): void {
  if (function_exists('ini_get') && ini_get('zlib.output_compression')) {
    @ini_set('zlib.output_compression', 'Off');
  }
  while (ob_get_level() > 0) { @ob_end_clean(); }
}
function qta_stream_file(string $tmpPath, string $mime, string $downloadName): void {
  qta_prepare_output();
  header('Content-Type: '.$mime);
  header('Content-Disposition: attachment; filename="'.$downloadName.'"');
  header('Content-Transfer-Encoding: binary');
  header('Cache-Control: private, max-age=0, must-revalidate');
  header('Pragma: public');
  header('X-Accel-Buffering: no');
  $size = @filesize($tmpPath);
  if ($size !== false) header('Content-Length: '.$size);
  $fh = fopen($tmpPath, 'rb');
  fpassthru($fh);
  fclose($fh);
  @unlink($tmpPath);
  exit;
}

/* ===== Exporters ===== */
function outXlsx(array $headers, array $rows, string $title, string $filenameBase): void {
  signal_download_ready();
  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle(mb_substr($title,0,31));

  // Header
  $i = 1;
  foreach ($headers as $h) {
    $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i).'1';
    $sheet->setCellValue($addr, $h);
    $i++;
  }
  $sheet->getStyle('1:1')->getFont()->setBold(true);

  // Rows
  $r = 2;
  foreach ($rows as $row) {
    $c = 1;
    foreach ($row as $val) {
      $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).$r;
      $sheet->setCellValueExplicit($addr, (string)$val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
      $c++;
    }
    $r++;
  }

  // Autosize
  $colCount = count($headers);
  for ($ci = 1; $ci <= $colCount; $ci++) {
    $colL = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci);
    $sheet->getColumnDimension($colL)->setAutoSize(true);
  }

  $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet,'Xlsx');
  $tmp = tempnam(sys_get_temp_dir(), 'qta_xlsx_');
  $writer->save($tmp);
  qta_stream_file($tmp, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filenameBase.'.xlsx');
}

function outPdf(array $headers, array $rows, string $title, string $filenameBase): void {
  signal_download_ready();
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
  $opt = new \Dompdf\Options();
  $opt->set('isRemoteEnabled', true);
  $opt->set('defaultFont', 'DejaVu Sans');
  $dompdf = new \Dompdf\Dompdf($opt);
  $dompdf->loadHtml($html,'UTF-8');
  $dompdf->setPaper('A4','landscape');
  $dompdf->render();

  $tmp = tempnam(sys_get_temp_dir(), 'qta_pdf_');
  file_put_contents($tmp, $dompdf->output());
  qta_stream_file($tmp, 'application/pdf', $filenameBase.'.pdf');
}

/* Fallback pÃ«r Word: .DOC (HTML) nÃ« LANDSCAPE â€“ nuk kÃ«rkon ext-zip */
function outWordHtml(array $headers, array $rows, string $title, string $filenameBase): void {
  signal_download_ready();
  $e = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
  ob_start(); ?>
  <!DOCTYPE html>
  <html>
  <head>
    <meta charset="UTF-8">
    <title><?= $e($title) ?></title>
    <style>
      /* Word-friendly landscape */
      @page { size: A4 landscape; margin: 1.5cm; }
      @page Section1 { size: 841.9pt 595.3pt; mso-page-orientation: landscape; margin: 1.5cm; }
      div.Section1 { page: Section1; }

      * { font-family: DejaVu Sans, Calibri, Arial, sans-serif; font-size: 11pt; }
      h3 { margin: 0 0 10px 0; }
      table { width: 100%; border-collapse: collapse; table-layout: fixed; }
      th,td { border: 1px solid #999; padding: 4px 6px; word-wrap: break-word; }
      th { background: #f1f3f5; }
    </style>
  </head>
  <body>
    <div class="Section1">
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
    </div>
  </body>
  </html>
  <?php
  $html = ob_get_clean();
  $tmp = tempnam(sys_get_temp_dir(), 'qta_doc_');
  file_put_contents($tmp, $html);
  qta_stream_file($tmp, 'application/msword', $filenameBase.'.doc');
}


function outDocx(array $headers, array $rows, string $title, string $filenameBase): void {
  // NÃ«se sâ€™ka ZipArchive, kalo automatikisht nÃ« .DOC (HTML)
  if (!class_exists('ZipArchive')) { outWordHtml($headers,$rows,$title,$filenameBase); return; }

  signal_download_ready();
  try {
    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('DejaVu Sans');
    $phpWord->setDefaultFontSize(10);

    $section = $phpWord->addSection([
      'orientation'=>'landscape',
      'marginLeft'=>600,'marginRight'=>600,'marginTop'=>600,'marginBottom'=>600
    ]);
    $section->addText($title, ['bold'=>true,'size'=>14], ['spaceAfter'=>200]);

    $phpWord->addTableStyle('tbl', ['borderSize'=>6,'borderColor'=>'999999','cellMargin'=>80], ['bgColor'=>'F1F3F5']);
    $t = $section->addTable('tbl');
    $t->addRow();
    foreach($headers as $h) $t->addCell()->addText((string)$h, ['bold'=>true]);
    foreach($rows as $r) {
      $t->addRow();
      foreach($r as $v) $t->addCell()->addText((string)$v);
    }

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $tmp = tempnam(sys_get_temp_dir(), 'qta_docx_');
    $writer->save($tmp);
    qta_stream_file($tmp, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $filenameBase.'.docx');
  } catch (Throwable $e) {
    // NÃ«se diÃ§ka shkon keq, kalo nÃ« .DOC (HTML)
    outWordHtml($headers,$rows,$title,$filenameBase);
  }
}

function exportAny(array $headers, array $rows, string $title, string $filenameBase, string $fmt): void {
  switch ($fmt) {
    case 'xlsx': outXlsx($headers,$rows,$title,$filenameBase); break;
    case 'pdf' : outPdf($headers,$rows,$title,$filenameBase); break;
    case 'docx': outDocx($headers,$rows,$title,$filenameBase); break; // auto-fallback nÃ« .doc
    default: qta_fail(400, 'Format i panjohur.');
  }
}

/* =========================================================
   FORM 1: grupi fillimâ€¦mbarim (PA kode kursi) â€” datat DD-MM-YYYY
   ========================================================= */
if ($type === 'form1') {
  $gstart = (int)($_GET['gstart'] ?? 0);
  $gend   = (int)($_GET['gend'] ?? 0);

  if ($gstart <= 0 || $gend <= 0) {
    qta_fail(400, 'Interval grupe i pavlefshem.');
  }

  // NEW: validim sipas AMZÃ‹ (jo sipas ID)
  $amzeStart = get_group_amze_min($pdo, $gstart);
  $amzeEnd   = get_group_amze_min($pdo, $gend);

  if ($amzeStart === null) {
    qta_fail(400, 'Grupi i fillimit nuk ka AMZE (nuk ka studente) ose nuk u gjet.');
  }
  if ($amzeEnd === null) {
    qta_fail(400, 'Grupi i mbarimit nuk ka AMZE (nuk ka studente) ose nuk u gjet.');
  }
  if ($amzeEnd < $amzeStart) {
    qta_fail(400, 'Interval grupe i pavlefshem: AMZE e mbarimit duhet te jete >= AMZE e fillimit.');
  }

  $sql = "
    SELECT
      cg.id AS group_id,
      c.name AS course_name,
      cg.start_date, cg.end_date,
      COUNT(s.id) AS total,
      SUM(CASE WHEN g.code='F' THEN 1 ELSE 0 END) AS females,
      SUM(CASE WHEN p.birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) BETWEEN 16 AND 24 THEN 1 ELSE 0 END) AS age_16_24,
      SUM(CASE WHEN p.birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) BETWEEN 25 AND 34 THEN 1 ELSE 0 END) AS age_25_34,
      SUM(CASE WHEN p.birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) >= 35 THEN 1 ELSE 0 END) AS age_35_plus,
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
    GROUP BY cg.id
    HAVING MIN(CAST(s.nr_amze AS UNSIGNED)) BETWEEN :a1 AND :a2
    ORDER BY
      (MIN(CAST(s.nr_amze AS UNSIGNED)) IS NULL) ASC,
      MIN(CAST(s.nr_amze AS UNSIGNED)) ASC,
      cg.id ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([':a1' => $amzeStart, ':a2' => $amzeEnd]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $headers = [
    'Grup ID','Kursi','Fillimi','Mbarimi','Totale','Femra',
    '16â€“24','25â€“34','35+','AU','AM','AL','AMZÃ‹ (minâ€“max)'
  ];

  $data = [];
  foreach ($rows as $r) {
    $amzeSpan = ($r['amze_min']===null || $r['amze_max']===null) ? '' : ($r['amze_min'].'â€“'.$r['amze_max']);
    $data[] = [
      (int)$r['group_id'],
      (string)($r['course_name'] ?? ''),
      iso_to_dmy($r['start_date'] ?? ''),
      iso_to_dmy($r['end_date'] ?? ''),
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

  exportAny($headers, $data, 'Formulari nr. 1 â€” Grupe', 'form1_grupe_'.date('Ymd_His'), $fmt);
  exit;
}


/* =========================================================
   FORM 2: AMZÃ‹ fillimâ€¦mbarim (veÃ§ emrit tÃ« kursit tÃ« fundit)
   ========================================================= */
if ($type === 'form2') {
  $a1 = (int)($_GET['amze_start'] ?? 0);
  $a2 = (int)($_GET['amze_end'] ?? 0);
  if ($a1<=0 || $a2<=0 || $a1>$a2) { qta_fail(400, 'Interval AMZE i pavlefshem.'); }

  $sql = "
    SELECT
      s.nr_amze,
      p.first_name, p.father_name, p.last_name,
      p.birth_place,
      c.name AS course_name
    FROM students s
    JOIN persons p ON p.id = s.person_id
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

  $headers = ['AMZÃ‹','EmÃ«r','AtÃ«si','MbiemÃ«r','Vendlindja','Emri i kursit'];
  $data = [];
  foreach ($rows as $r) {
    $data[] = [
      (string)($r['nr_amze'] ?? ''),
      (string)($r['first_name'] ?? ''),
      (string)($r['father_name'] ?? ''),
      (string)($r['last_name'] ?? ''),
      (string)($r['birth_place'] ?? ''),
      (string)($r['course_name'] ?? '')
    ];
  }

  exportAny($headers, $data, 'Formulari nr. 2 â€” AMZÃ‹', 'form2_amze_'.date('Ymd_His'), $fmt);
  exit;
}

qta_fail(400, 'Parametri type i panjohur. Perdor type=form1 ose type=form2.');

