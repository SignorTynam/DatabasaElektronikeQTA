<?php
declare(strict_types=1);
session_start();
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/database.php';
$pdo = getPDO();

require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

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
  http_response_code(500);
  echo "Composer autoload nuk u gjet. Ekzekuto:\ncomposer require phpoffice/phpspreadsheet phpoffice/phpword dompdf/dompdf";
  exit;
}

/* ===== Guard: admin/editor + CSRF ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }

$u = $pdo->prepare("SELECT u.id, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id LIMIT 1");
$u->execute([':id'=>$_SESSION['user_id']]);
$me = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($me['role_name'] ?? ''));
if (!$me || !in_array($role, ['administrator','editor'], true)) { header('Location: selectProfile.php'); exit; }

$csrfSession = $_SESSION['csrf_token'] ?? '';
$csrfQuery   = $_GET['csrf'] ?? '';
if (!$csrfSession || !hash_equals($csrfSession, $csrfQuery)) { http_response_code(403); echo 'CSRF gabim ose mungon.'; exit; }

/* ===== Parametra ===== */
$groupId = (int)($_GET['group_id'] ?? 0);
$fmt     = strtolower(trim((string)($_GET['f'] ?? 'pdf'))); // pdf|docx|xlsx

if ($groupId <= 0) { http_response_code(400); echo 'group_id i pavlefshëm.'; exit; }
if (!in_array($fmt, ['xlsx','pdf','docx'], true)) { http_response_code(400); echo 'Format i pavlefshëm.'; exit; }

@ini_set('memory_limit','512M');
@set_time_limit(120);

/* ===== Konstantat “si në foto” ===== */
const DIDACTIC_TITLE = 'Drejtuesi didaktik';
const DIDACTIC_NAME  = 'Ing. Silvana Pavaci';
const ADMIN_TITLE    = 'Administratori';
const ADMIN_NAME     = 'Msc. Mira Kovaçi';

/* ===== Helpers ===== */
function iso_to_dmy(?string $iso): string {
  if (!$iso || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$iso)) return (string)$iso;
  $ts = strtotime((string)$iso);
  return $ts ? date('d/m/Y', $ts) : (string)$iso;
}
function iso_to_dmy_dash(?string $iso): string {
  if (!$iso || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$iso)) return (string)$iso;
  $ts = strtotime((string)$iso);
  return $ts ? date('d-m-Y', $ts) : (string)$iso;
}
function clean(?string $s): string { return trim((string)$s); }

function signal_download_ready(): void {
  header('X-File-Download: 1');
  setcookie('qta_file_ready', 'ok', [
    'expires'  => time()+60,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => false,
    'samesite' => 'Lax',
  ]);
}
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

/* ===== Lexo “orë mësimore” nga courses (nëse ekziston kolonë) ===== */
function getCourseHours(PDO $pdo, int $courseId): string {
  try {
    $cols = $pdo->query("DESCRIBE courses")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($r)=> strtolower((string)$r['Field']), $cols);

    $candidates = ['hours','ore','num_hours','total_hours','duration_hours','course_hours','hours_total','ora_mesimore'];
    $found = null;
    foreach ($candidates as $cand) {
      if (in_array($cand, $names, true)) { $found = $cand; break; }
    }
    if (!$found) return '';

    $st = $pdo->prepare("SELECT `$found` AS h FROM courses WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$courseId]);
    $h = $st->fetchColumn();
    if ($h === false || $h === null) return '';
    $hs = trim((string)$h);
    return $hs;
  } catch (Throwable $e) {
    return '';
  }
}

/* ===== Merr të dhënat e grupit ===== */
$g = $pdo->prepare("
  SELECT cg.id, cg.course_id, cg.start_date, cg.end_date, c.name AS course_name
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  WHERE cg.id = :gid
  LIMIT 1
");
$g->execute([':gid'=>$groupId]);
$group = $g->fetch(PDO::FETCH_ASSOC);
if (!$group) { http_response_code(404); echo 'Grupi nuk u gjet.'; exit; }

$courseId   = (int)($group['course_id'] ?? 0);
$courseName = (string)($group['course_name'] ?? '');
$startIso   = (string)($group['start_date'] ?? '');
$endIso     = (string)($group['end_date'] ?? '');

$hours = ($courseId > 0) ? getCourseHours($pdo, $courseId) : '';

/* ===== Studentët e grupit (për tabelën) ===== */
$st = $pdo->prepare("
  SELECT
    s.id AS student_id,
    s.nr_amze,
    p.first_name, p.father_name, p.last_name,
    p.birth_date,
    p.birth_place,
    cgs.exam_date,
    cgs.final_score
  FROM course_group_students cgs
  JOIN students s ON s.id = cgs.student_id
  LEFT JOIN persons p ON p.id = s.person_id
  WHERE cgs.group_id = :gid
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
");
$st->execute([':gid'=>$groupId]);
$members = $st->fetchAll(PDO::FETCH_ASSOC);

/* ===== Data e provimit (për tekstin sipër) =====
   - nëse ka një exam_date, përdorim MAX (zakonisht e njëjta për të gjithë)
*/
$examIso = '';
foreach ($members as $m) {
  $d = (string)($m['exam_date'] ?? '');
  if ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
    if ($examIso === '' || $d > $examIso) $examIso = $d;
  }
}

/* ===== Përgatit rreshtat e tabelës ===== */
$rows = [];
$nr = 1;
foreach ($members as $m) {
  $fullName = trim(
    (string)($m['first_name'] ?? '') . ' ' .
    (string)($m['father_name'] ?? '') . ' ' .
    (string)($m['last_name'] ?? '')
  );
  $fullName = preg_replace('/\s+/', ' ', $fullName ?? '') ?: '';

  $score = $m['final_score'];
  $scoreStr = ($score === null) ? '' : rtrim(rtrim((string)$score, '0'), '.');

  $examIsoRow = (string)($m['exam_date'] ?? '');
    $examIsoRow = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $examIsoRow)) ? $examIsoRow : '';

    $rows[] = [
    'nr'         => (string)$nr++,
    'amza'       => (string)($m['nr_amze'] ?? ''),
    'fullname'   => $fullName,
    'birth'      => iso_to_dmy_dash((string)($m['birth_date'] ?? '')),
    'birthplace' => (string)($m['birth_place'] ?? ''),
    'start'      => iso_to_dmy_dash($startIso),
    'end'        => iso_to_dmy_dash($endIso),

    // përdoret për shfaqje në tabelë
    'exam'       => iso_to_dmy_dash($examIsoRow),

    // përdoret për grupim (1 faqe për çdo date testimi)
    'exam_iso'   => $examIsoRow,

    'final'      => $scoreStr,
    ];

}
$totalCertified = count($rows);

/* ===== Logo ===== */
$logoPath = __DIR__ . '/image/logoPNG.png';
$logoDataUri = '';
if (is_file($logoPath)) {
  $bin = file_get_contents($logoPath);
  if ($bin !== false) {
    $logoDataUri = 'data:image/png;base64,' . base64_encode($bin);
  }
}

/* =========================
   EXPORT: PDF (format si foto)
========================= */
function exportPdfProcesVerbal(
  string $logoDataUri,
  string $examIso_IGNORED,
  string $courseName,
  string $hours,
  array $rows,
  int $totalCertified_IGNORED,
  int $groupId
): void {
  signal_download_ready();
  $e = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

  // --- Grupo sipas exam_iso (YYYY-MM-DD). Çdo grup => 1 faqe.
  $byExam = [];
  foreach ($rows as $r) {
    $k = (string)($r['exam_iso'] ?? '');
    $k = preg_match('/^\d{4}-\d{2}-\d{2}$/', $k) ? $k : ''; // bosh nëse mungon/jo valide
    $byExam[$k][] = $r;
  }

  // Rendit faqet: datat valide në rritje, bosh në fund
  uksort($byExam, function($a, $b){
    if ($a === '' && $b === '') return 0;
    if ($a === '') return 1;
    if ($b === '') return -1;
    return strcmp($a, $b);
  });

  // --- Dinamikë “fit on one page”
  $maxRows = 0;
  foreach ($byExam as $list) $maxRows = max($maxRows, count($list));

  // pragje praktike (rregulloji sipas dëshirës)
  if ($maxRows <= 10) { $baseFont=13; $cellPad=6; $titleBig=18; $titleMid=16; $lineGapTop=22; $signTop=40; }
  elseif ($maxRows <= 14) { $baseFont=12; $cellPad=5; $titleBig=16; $titleMid=14; $lineGapTop=18; $signTop=34; }
  elseif ($maxRows <= 18) { $baseFont=11; $cellPad=4; $titleBig=15; $titleMid=13; $lineGapTop=14; $signTop=28; }
  elseif ($maxRows <= 24) { $baseFont=10; $cellPad=3; $titleBig=14; $titleMid=12; $lineGapTop=12; $signTop=22; }
  else { $baseFont=9; $cellPad=2; $titleBig=13; $titleMid=11; $lineGapTop=10; $signTop=18; }

  $hoursTxt = ($hours !== '') ? $hours : '';

  ob_start(); ?>
  <html>
  <head>
    <meta charset="UTF-8" />
    <style>
      /* Narrow margins për të fituar hapësirë */
      @page { size: A4 landscape; margin: 10mm 10mm; }

      * { font-family: DejaVu Sans, sans-serif; }
      body { font-size: <?= (int)$baseFont ?>px; color:#000; margin:0; padding:0; }

      .pv-page { page-break-after: always; }
      .pv-page.last { page-break-after: auto; }

      .toplogo { text-align:center; margin-top: 0; }
      .toplogo img { height: 46px; }

      .title { text-align:center; font-weight:700; letter-spacing:.4px; margin-top: 8px; }
      .title.big { font-size: <?= (int)$titleBig ?>px; }
      .title.mid { font-size: <?= (int)$titleMid ?>px; margin-top: 3px; }

      .line-text { text-align:center; margin: <?= (int)$lineGapTop ?>px 0 12px; }
      .u { display:inline-block; min-width: 110px; border-bottom: 1px solid #000; text-align:center; padding: 0 6px; }
      .u.long { min-width: 360px; }
      .u.short { min-width: 90px; }
      .u.hours { min-width: 70px; }

      table.pv { width:100%; border-collapse: collapse; table-layout: fixed; }
      table.pv th, table.pv td {
        border: 1px solid #000;
        padding: <?= (int)$cellPad ?>px <?= (int)$cellPad ?>px;
        vertical-align: middle;
        line-height: 1.1;
      }
      table.pv th { font-weight:700; text-align:center; }

      .col-nr { width: 4.5%; text-align:center; }
      .col-amza { width: 6.5%; text-align:center; }
      .col-name { width: 25%; }
      .col-birth { width: 10%; text-align:center; }
      .col-place { width: 11.5%; }
      .col-s { width: 10.5%; text-align:center; }
      .col-e { width: 12.5%; text-align:center; }
      .col-ex { width: 12.5%; text-align:center; }
      .col-final { width: 7%; text-align:center; }

      .cert { margin-top: 12px; }
      .cert .u { min-width: 80px; }

      .sign { width:100%; margin-top: <?= (int)$signTop ?>px; table-layout: fixed; }
      .sign td { vertical-align: top; width: 33.33%; text-align:center; }
      .sign .lbl { font-weight:700; text-decoration: underline; font-size: <?= max(11, (int)$baseFont+2) ?>px; }
      .sign .name { font-weight:700; text-decoration: underline; font-size: <?= max(11, (int)$baseFont+2) ?>px; margin-top: 4px; }
      .sign .blank { height: 34px; }
    </style>
  </head>
  <body>
  <?php
    $keys = array_keys($byExam);
    $lastKey = end($keys);

    foreach ($byExam as $examIso => $pageRows):
      $isLast = ($examIso === $lastKey);
      $examDmy = $examIso ? iso_to_dmy($examIso) : ''; // dd/mm/YYYY për tekstin sipër
      $pageTotal = count($pageRows);
  ?>
    <div class="pv-page <?= $isLast ? 'last' : '' ?>">
      <div class="toplogo">
        <?php
          // Opsionale: nëse s’ka GD, mos e vendos logon që të mos bjerë
          if ($logoDataUri && function_exists('imagecreatefrompng')): ?>
          <img src="<?= $e($logoDataUri) ?>" alt="QTA Logo">
        <?php endif; ?>
      </div>

      <div class="title big">QENDRA E TRAJNIMEVE TË AVANCUARA</div>
      <div class="title mid">PROCES VERBAL VLERËSIMI PËRFUNDIMTAR</div>

      <div class="line-text">
        Është mbajtur sot më datë
        <span class="u short"><?= $examDmy ? $e($examDmy) : '&nbsp;' ?></span>
        provimi i programit të kursit të unifikuar
        <span class="u long"><?= $courseName ? $e($courseName) : '&nbsp;' ?></span>
        me orë mësimore
        <span class="u hours"><?= $hoursTxt ? $e($hoursTxt) : '&nbsp;' ?></span>
        orë.
      </div>

      <table class="pv">
        <thead>
          <tr>
            <th class="col-nr">Nr</th>
            <th class="col-amza">Amza</th>
            <th class="col-name">Emër Atësi Mbiemër</th>
            <th class="col-birth">Datëlindja</th>
            <th class="col-place">Vendlindja</th>
            <th class="col-s">Datë fillimi</th>
            <th class="col-e">Datë mbarimi</th>
            <th class="col-ex">Datë testimi</th>
            <th class="col-final">Vlerësimi</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $nrLocal = 1;
            foreach ($pageRows as $r):
          ?>
            <tr>
              <td class="col-nr"><?= $e((string)$nrLocal++) ?></td>
              <td class="col-amza"><?= $e($r['amza']) ?></td>
              <td class="col-name"><?= $e($r['fullname']) ?></td>
              <td class="col-birth"><?= $e($r['birth']) ?></td>
              <td class="col-place"><?= $e($r['birthplace']) ?></td>
              <td class="col-s"><?= $e($r['start']) ?></td>
              <td class="col-e"><?= $e($r['end']) ?></td>
              <td class="col-ex"><?= $e($r['exam']) ?></td>
              <td class="col-final"><?= $e($r['final']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="cert">
        Kursantë të certifikuar:
        <span class="u"><?= $e((string)$pageTotal) ?></span>
      </div>

      <table class="sign">
        <tr>
          <td>
            <div class="lbl">Instruktori i kursit</div>
            <div class="blank"></div>
          </td>
          <td>
            <div class="lbl"><?= $e(DIDACTIC_TITLE) ?></div>
            <div class="name"><?= $e(DIDACTIC_NAME) ?></div>
          </td>
          <td>
            <div class="lbl"><?= $e(ADMIN_TITLE) ?></div>
            <div class="name"><?= $e(ADMIN_NAME) ?></div>
          </td>
        </tr>
      </table>
    </div>
  <?php endforeach; ?>
  </body>
  </html>
  <?php
  $html = ob_get_clean();

  $opt = new \Dompdf\Options();
  $opt->set('isRemoteEnabled', true);
  $opt->set('defaultFont', 'DejaVu Sans');

  $dompdf = new \Dompdf\Dompdf($opt);
  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4','landscape');
  $dompdf->render();

  $tmp = tempnam(sys_get_temp_dir(), 'qta_pv_pdf_');
  file_put_contents($tmp, $dompdf->output());

  $filenameBase = 'proces_verbal_g'.$groupId.'_'.date('Ymd_His');
  qta_stream_file($tmp, 'application/pdf', $filenameBase.'.pdf');
}

/* =========================
   EXPORT: DOCX (format i afërt si foto)
========================= */
function exportDocxProcesVerbal(
  string $logoPath,
  string $examIso,
  string $courseName,
  string $hours,
  array $rows,
  int $totalCertified,
  int $groupId
): void {
  signal_download_ready();

  $examDmy = $examIso ? iso_to_dmy($examIso) : '';
  $hoursTxt = $hours !== '' ? $hours : '';

  $phpWord = new \PhpOffice\PhpWord\PhpWord();
  $phpWord->setDefaultFontName('DejaVu Sans');
  $phpWord->setDefaultFontSize(11);

  $section = $phpWord->addSection([
    'orientation' => 'landscape',
    'marginLeft'  => 850,
    'marginRight' => 850,
    'marginTop'   => 850,
    'marginBottom'=> 850
  ]);

  // Logo
  if (is_file($logoPath)) {
    $section->addImage($logoPath, [
      'height' => 48,
      'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER
    ]);
  }

  // Titles
  $section->addText('QENDRA E TRAJNIMEVE TË AVANCUARA', ['bold'=>true,'size'=>16], ['alignment'=>'center','spaceBefore'=>120]);
  $section->addText('PROCES VERBAL VLERËSIMI PËRFUNDIMTAR', ['bold'=>true,'size'=>14], ['alignment'=>'center','spaceAfter'=>380]);

  // Line with underlined fields
  $run = $section->addTextRun(['alignment'=>'center']);
  $run->addText('Është mbajtur sot më datë ');
  $run->addText($examDmy ?: '     ', ['underline'=>'single']);
  $run->addText('  provimi i programit të kursit të unifikuar ');
  $run->addText($courseName ?: '                              ', ['underline'=>'single']);
  $run->addText('  me orë mësimore ');
  $run->addText($hoursTxt ?: '   ', ['underline'=>'single']);
  $run->addText(' orë.');

  $section->addText('', [], ['spaceAfter'=>220]);

  // Table
  $phpWord->addTableStyle('pv', ['borderSize'=>8,'borderColor'=>'000000','cellMargin'=>80], []);
  $table = $section->addTable('pv');

  $headers = ['Nr','Amza','Emër Atësi Mbiemër','Datëlindja','Vendlindja','Datë fillimi','Datë mbarimi','Datë testimi','Vlerësimi'];

  $table->addRow();
  foreach ($headers as $h) {
    $table->addCell()->addText($h, ['bold'=>true]);
  }

  foreach ($rows as $r) {
    $table->addRow();
    $table->addCell()->addText($r['nr']);
    $table->addCell()->addText($r['amza']);
    $table->addCell()->addText($r['fullname']);
    $table->addCell()->addText($r['birth']);
    $table->addCell()->addText($r['birthplace']);
    $table->addCell()->addText($r['start']);
    $table->addCell()->addText($r['end']);
    $table->addCell()->addText($r['exam']);
    $table->addCell()->addText($r['final']);
  }

  $section->addText('', [], ['spaceAfter'=>160]);

  // Certified count
  $run2 = $section->addTextRun();
  $run2->addText('Kursantë të certifikuar: ');
  $run2->addText((string)$totalCertified, ['underline'=>'single']);

  // Signatures (3 columns)
  $section->addText('', [], ['spaceAfter'=>260]);

  $sig = $section->addTable();
  $sig->addRow();

  $cell1 = $sig->addCell(4500);
  $cell2 = $sig->addCell(4500);
  $cell3 = $sig->addCell(4500);

  $cell1->addText('Instruktori i kursit', ['bold'=>true,'underline'=>'single'], ['alignment'=>'center']);
  $cell1->addText(' ', [], ['spaceAfter'=>380]); // bosh

  $cell2->addText(DIDACTIC_TITLE, ['bold'=>true,'underline'=>'single'], ['alignment'=>'center']);
  $cell2->addText(DIDACTIC_NAME,  ['bold'=>true,'underline'=>'single'], ['alignment'=>'center']);

  $cell3->addText(ADMIN_TITLE, ['bold'=>true,'underline'=>'single'], ['alignment'=>'center']);
  $cell3->addText(ADMIN_NAME,  ['bold'=>true,'underline'=>'single'], ['alignment'=>'center']);

  $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
  $tmp = tempnam(sys_get_temp_dir(), 'qta_pv_docx_');
  $writer->save($tmp);

  $filenameBase = 'proces_verbal_g'.$groupId.'_'.date('Ymd_His');
  qta_stream_file($tmp, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $filenameBase.'.docx');
}

/* =========================
   EXPORT: XLSX (tabelë e pastër)
========================= */
function exportXlsxProcesVerbal(array $rows, int $groupId): void {
  signal_download_ready();

  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('Proces Verbal');

  $headers = ['Nr','Amza','Emër Atësi Mbiemër','Datëlindja','Vendlindja','Datë fillimi','Datë mbarimi','Datë testimi','Vlerësimi'];

  $r = 1;
  $c = 1;
  foreach ($headers as $h) {
    $sheet->setCellValueByColumnAndRow($c++, $r, $h);
  }
  $sheet->getStyle('A1:I1')->getFont()->setBold(true);

  $r = 2;
  foreach ($rows as $row) {
    $sheet->setCellValueByColumnAndRow(1, $r, $row['nr']);
    $sheet->setCellValueByColumnAndRow(2, $r, $row['amza']);
    $sheet->setCellValueByColumnAndRow(3, $r, $row['fullname']);
    $sheet->setCellValueByColumnAndRow(4, $r, $row['birth']);
    $sheet->setCellValueByColumnAndRow(5, $r, $row['birthplace']);
    $sheet->setCellValueByColumnAndRow(6, $r, $row['start']);
    $sheet->setCellValueByColumnAndRow(7, $r, $row['end']);
    $sheet->setCellValueByColumnAndRow(8, $r, $row['exam']);
    $sheet->setCellValueByColumnAndRow(9, $r, $row['final']);
    $r++;
  }

  foreach (range('A','I') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

  $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet,'Xlsx');
  $tmp = tempnam(sys_get_temp_dir(), 'qta_pv_xlsx_');
  $writer->save($tmp);

  $filenameBase = 'proces_verbal_g'.$groupId.'_'.date('Ymd_His');
  qta_stream_file($tmp, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filenameBase.'.xlsx');
}

/* ===== Dispatch sipas formatit ===== */
if ($fmt === 'pdf') {
  exportPdfProcesVerbal($logoDataUri, $examIso, $courseName, $hours, $rows, $totalCertified, $groupId);
}
if ($fmt === 'docx') {
  exportDocxProcesVerbal($logoPath, $examIso, $courseName, $hours, $rows, $totalCertified, $groupId);
}
exportXlsxProcesVerbal($rows, $groupId);
