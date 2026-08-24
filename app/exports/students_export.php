<?php
declare(strict_types=1);

/*
 * SHËNIM I RËNDËSISHËM:
 * - Sigurohu që NUK ka asnjë karakter para këtij <?php (pa boshllëk, pa rresht bosh, pa BOM).
 */

if (ob_get_level() === 0) {
    ob_start(); // kap çdo output të mundshëm (warnings, etj.)
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

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
        qta_download_status('error', 'Dokumenti nuk u gjenerua. Ju lutem provo perseri.');
    }
});

/* Composer autoload */
$autoloadCandidates = array(
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php' : null,
);
$autoloadLoaded = false;
foreach ($autoloadCandidates as $p) {
    if ($p && is_file($p)) {
        require_once $p;
        $autoloadLoaded = true;
        break;
    }
}
if (!$autoloadLoaded) {
    qta_fail(500, 'Composer autoload nuk u gjet.');
}

/* Guard: admin OSE editor */
if (!isset($_SESSION['user_id'])) {
    qta_download_status('error', 'Sesioni ka skaduar. Ju lutem kycuni perseri.');
    header('Location: selectProfile.php');
    exit;
}
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :uid
  LIMIT 1
");
$u->execute(array(':uid' => $_SESSION['user_id']));
$currentUser = $u->fetch(PDO::FETCH_ASSOC);

$role = strtolower((string)($currentUser['role_name'] ?? ''));
if (!$currentUser || !in_array($role, array('administrator','editor'), true)) {
    qta_download_status('error', 'Nuk jeni i autorizuar per kete veprim.');
    header('Location: selectProfile.php');
    exit;
}

/* CSRF (GET) */
$csrfSession = $_SESSION['csrf_token'] ?? '';
$csrfQuery   = $_GET['csrf'] ?? '';
if (!$csrfSession || !hash_equals($csrfSession, $csrfQuery)) {
    qta_fail(403, 'CSRF gabim ose mungon.');
}

/* Parametra */
$f          = strtolower(trim($_GET['f'] ?? 'xlsx'));  // xlsx|pdf|docx
$q          = trim($_GET['q'] ?? '');
$edu        = trim($_GET['edu'] ?? '');
$incomplete = isset($_GET['incomplete']) && $_GET['incomplete'] === '1';

/* ===============================
   Filtrat – njësoj si students.php
   =============================== */
$where  = array();
$params = array();

/* Kërkimi i përgjithshëm (q) */
if ($q !== '') {
    $where[] = "(
        p.first_name      LIKE :kw1 OR
        p.father_name     LIKE :kw2 OR
        p.last_name       LIKE :kw3 OR
        s.nr_amze         LIKE :kw4 OR
        p.personal_number LIKE :kw5 OR
        p.phone           LIKE :kw6 OR
        p.birth_place     LIKE :kw7
    )";
    $kw = '%'.$q.'%';
    $params[':kw1'] = $kw;
    $params[':kw2'] = $kw;
    $params[':kw3'] = $kw;
    $params[':kw4'] = $kw;
    $params[':kw5'] = $kw;
    $params[':kw6'] = $kw;
    $params[':kw7'] = $kw;
}

/* Filtër arsimi (edu) */
if ($edu !== '') {
    if (ctype_digit($edu)) {
        $where[] = "s.education_level_id = :eduid";
        $params[':eduid'] = (int)$edu;
    } else {
        $where[] = "el.code = :educode";
        $params[':educode'] = $edu;
    }
}

/*
 * Filtro vetëm studentët që kanë TË PAKTËN një fushë bosh
 * (TEL injorohet qëllimisht) – si në students.php
 */
if ($incomplete) {
    $where[] = "(
        p.personal_number IS NULL OR p.personal_number = '' OR
        p.first_name      IS NULL OR p.first_name      = '' OR
        p.father_name     IS NULL OR p.father_name     = '' OR
        p.last_name       IS NULL OR p.last_name       = '' OR
        p.birth_date      IS NULL OR p.birth_date      = '0000-00-00' OR
        p.birth_place     IS NULL OR p.birth_place     = '' OR
        s.education_level_id IS NULL OR
        p.gender_id       IS NULL
    )";
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE '.implode(' AND ', $where);
}

/* Helper për datën DD-MM-YYYY */
function fmtDate_dmy(?string $iso): string {
    if (!$iso) return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return (string)$iso;
    $ts = strtotime($iso);
    return $ts ? date('d-m-Y', $ts) : (string)$iso;
}

/* ===============================
   SELECT – njësoj me students.php
   (vetëm roli 'student')
   =============================== */
$sql = "
  SELECT
    s.id              AS student_id,
    s.nr_amze,

    p.first_name,
    p.father_name,
    p.last_name,
    p.personal_number,
    p.birth_date,
    p.birth_place,
    p.phone,

    g.code  AS gender_code,
    g.label AS gender_label,

    el.code  AS edu_code,
    el.label AS edu_label,

    /* Grupi (nëse ekziston) – si në students.php */
    (
      SELECT c.name
      FROM course_group_students cgs
      JOIN course_groups cg ON cg.id = cgs.group_id
      JOIN courses c        ON c.id = cg.course_id
      WHERE cgs.student_id = s.id
      ORDER BY cg.start_date DESC, cg.id DESC
      LIMIT 1
    ) AS group_name,
    (
      SELECT cg.start_date
      FROM course_group_students cgs
      JOIN course_groups cg ON cg.id = cgs.group_id
      WHERE cgs.student_id = s.id
      ORDER BY cg.start_date DESC, cg.id DESC
      LIMIT 1
    ) AS group_start_date,
    (
      SELECT cg.end_date
      FROM course_group_students cgs
      JOIN course_groups cg ON cg.id = cgs.group_id
      WHERE cgs.student_id = s.id
      ORDER BY cg.start_date DESC, cg.id DESC
      LIMIT 1
    ) AS group_end_date,

    /* Moduli i planifikuar (nëse nuk ka grup) */
    (
      SELECT c.name
      FROM student_course_plans scp
      JOIN courses c ON c.id = scp.course_id
      WHERE scp.student_id = s.id
      ORDER BY scp.id DESC
      LIMIT 1
    ) AS planned_course_name

  FROM students s
  JOIN users  u ON u.id = s.user_id
  JOIN roles  r ON r.id = u.role_id AND r.name = 'student'
  JOIN persons p ON p.id = s.person_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  LEFT JOIN genders g           ON g.id = p.gender_id
  $whereSql
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   Dataset për eksport
   =============================== */
$headers = array(
    'Nr. Amzës',
    'Emër',
    'Atësi',
    'Mbiemër',
    'Nr. Personal',
    'Datëlindja',
    'Vendlindja',
    'Arsimi',
    'Moduli',
    'Datat e modulit',
    'Gjinia',
    'Tel.',
);

$data = array();
foreach ($rows as $r) {
    $eduLabel = trim(
        (!empty($r['edu_code']) ? ($r['edu_code'].' — ') : '') .
        ($r['edu_label'] ?? '')
    );

    // Moduli
    $moduleName = $r['group_name'] ?: $r['planned_course_name'] ?: '';

    // Datat e modulit
    $datesLabel = '';
    $from = fmtDate_dmy($r['group_start_date'] ?? null);
    $to   = fmtDate_dmy($r['group_end_date'] ?? null);
    if ($from !== '' && $to !== '') {
        $datesLabel = $from . ' - ' . $to;
    } elseif ($from !== '') {
        $datesLabel = $from;
    } elseif ($to !== '') {
        $datesLabel = $to;
    }

    $data[] = array(
        $r['nr_amze'] ?? '',
        $r['first_name']      ?? '',
        $r['father_name']     ?? '',
        $r['last_name']       ?? '',
        $r['personal_number'] ?? '',
        fmtDate_dmy($r['birth_date'] ?? null),
        $r['birth_place']     ?? '',
        $eduLabel,
        $moduleName,
        $datesLabel,
        $r['gender_label']    ?? '',
        $r['phone']           ?? '',
    );
}

$filename = 'studentet_'.date('Ymd_His');

/* ===============================
   Funksionet e eksportit
   =============================== */
function signal_download_ready(): void {
    header('X-File-Download: 1');
    qta_download_status('ok', 'Dokumenti u gjenerua me sukses.');
}

function cleanOutputBuffer(): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function exportXlsx(array $headers, array $data, string $filename): void {
    signal_download_ready();
    cleanOutputBuffer();

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Studentët');

    // Header row
    $col = 1;
    foreach ($headers as $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1';
        $sheet->setCellValue($cell, $h);
        $col++;
    }

    // Data rows
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

    // Auto-size columns
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

function exportPdf(array $headers, array $data, string $filename): void {
    signal_download_ready();
    cleanOutputBuffer();

    $escape = function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    ob_start();
    ?>
    <html>
    <head>
      <meta charset="UTF-8" />
      <style>
        * { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        h3 { margin: 0 0 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bdb5a4; padding: 4px 6px; }
        th { background: #ebe8df; }
      </style>
    </head>
    <body>
      <h3>Regjistri i studentëve (students.php)</h3>
      <table>
        <thead>
          <tr>
            <?php foreach ($headers as $h): ?>
              <th><?= $escape($h) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data as $row): ?>
            <tr>
              <?php foreach ($row as $cell): ?>
                <td><?= $escape($cell) ?></td>
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
    $dompdf->setPaper('A3', 'landscape');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'.pdf"');
    header('Cache-Control: max-age=0');

    echo $dompdf->output();
    exit;
}

function exportDocx(array $headers, array $data, string $filename): void {
    signal_download_ready();
    cleanOutputBuffer();

    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $section = $phpWord->addSection(array(
        'orientation' => 'landscape',
        'marginLeft'  => 600,
        'marginRight' => 600,
        'marginTop'   => 600,
        'marginBottom'=> 600,
    ));
    $section->addText('Regjistri i studentëve', array('bold'=>true, 'size'=>14), array('spaceAfter'=>200));

    $styleTable    = array('borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80);
    $styleFirstRow = array('bgColor' => 'F1F3F5');
    $phpWord->addTableStyle('StudTbl', $styleTable, $styleFirstRow);
    $table = $section->addTable('StudTbl');

    // Header row
    $table->addRow();
    foreach ($headers as $h) {
        $table->addCell()->addText($h, array('bold'=>true));
    }

    // Data rows
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

/* ===============================
   Zgjidh formatin
   =============================== */
switch ($f) {
    case 'xlsx':
        exportXlsx($headers, $data, $filename);
        break;
    case 'pdf':
        exportPdf($headers, $data, $filename);
        break;
    case 'docx':
        exportDocx($headers, $data, $filename);
        break;
    default:
        cleanOutputBuffer();
        qta_fail(400, 'Format i panjohur. Perdor f=xlsx|pdf|docx');
}
