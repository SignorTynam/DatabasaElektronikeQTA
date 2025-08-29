<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* =========================================================
   (Si index.php) – nëse është i loguar, lexo përdoruesin
========================================================= */
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email, r.name AS role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    $currentUser = $stmt->fetch() ?: null;
}

/* =========================================================
   Helpers
========================================================= */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function json_out(array $x){ header('Content-Type: application/json'); echo json_encode($x); exit; }

/* Masko ID personale për publikun (p.sh. ****123) */
function mask_id(?string $s): string {
  if(!$s) return '—';
  $len = strlen($s);
  if ($len <= 3) return str_repeat('*', max(0,$len-1)).substr($s, -1);
  return str_repeat('*', $len-3).substr($s, -3);
}

/* Nxirr {sid, token} nga payload i skanuar */
function parse_payload(string $raw): array {
  $raw = trim($raw);

  // 1) URL me query ?sid=...&t=... ose &token=
  if (filter_var($raw, FILTER_VALIDATE_URL)) {
    $q = parse_url($raw, PHP_URL_QUERY);
    parse_str($q ?? '', $arr);
    $sid = isset($arr['sid']) && ctype_digit((string)$arr['sid']) ? (int)$arr['sid'] : 0;
    $token = $arr['t'] ?? ($arr['token'] ?? '');
    $token = is_string($token) ? trim($token) : '';
    return ['sid'=>$sid, 'token'=>$token];
  }

  // 2) Formati “QTA|SID:...|AMZE:...|TOKEN:...”
  if (stripos($raw, 'QTA|') === 0 || str_contains($raw, 'SID:') || str_contains($raw, 'TOKEN:')) {
    $sid = 0; $token = '';
    if (preg_match('/SID\s*:\s*(\d+)/i', $raw, $m)) $sid = (int)$m[1];
    if (preg_match('/TOKEN\s*:\s*([a-f0-9]{32,})/i', $raw, $m)) $token = strtolower($m[1]);
    return ['sid'=>$sid, 'token'=>$token];
  }

  // 3) JSON {sid, token}
  if (($raw[0]??'') === '{') {
    $j = json_decode($raw, true);
    if (is_array($j)) {
      $sid = isset($j['sid']) && ctype_digit((string)$j['sid']) ? (int)$j['sid'] : 0;
      $token = isset($j['token']) && is_string($j['token']) ? trim($j['token']) : '';
      return ['sid'=>$sid, 'token'=>$token];
    }
  }

  // 4) Si fund fare: “SID|TOKEN” i ndarë me |
  if (str_contains($raw,'|')) {
    [$a,$b] = array_map('trim', explode('|',$raw,2));
    $sid = ctype_digit($a) ? (int)$a : 0;
    $token = $b;
    return ['sid'=>$sid, 'token'=>$token];
  }

  return ['sid'=>0, 'token'=>''];
}

/* Kthe info publike të studentit, nëse (sid, token) përputhen */
function verify_student(PDO $pdo, int $sid, string $token): array {
  if ($sid<=0 || $token==='') return ['valid'=>false, 'reason'=>'Mungon SID ose token.'];

  // Kontrollo token
  $chk = $pdo->prepare("
    SELECT 1 FROM student_qr_tokens WHERE student_id=:sid AND token=:t LIMIT 1
  ");
  $chk->execute([':sid'=>$sid, ':t'=>$token]);
  if (!$chk->fetchColumn()) {
    return ['valid'=>false, 'reason'=>'Token i pavlefshëm ose nuk përputhet me këtë student.'];
  }

  // Info bazike publike (pa të dhëna sensitive)
  $sql = "
    SELECT s.id, s.nr_amze, s.first_name, s.father_name, s.last_name, s.personal_number,
           u.created_at, el.label AS edu_label,
           ajs.agency_id, ag.company_name AS agency_name
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    LEFT JOIN agency_students ajs ON ajs.student_id = s.id
    LEFT JOIN agencies ag ON ag.id = ajs.agency_id
    WHERE s.id = :sid
    LIMIT 1
  ";
  $st = $pdo->prepare($sql); $st->execute([':sid'=>$sid]);
  $S = $st->fetch(PDO::FETCH_ASSOC);
  if (!$S) return ['valid'=>false, 'reason'=>'Studenti nuk u gjet.'];

  // Statistika të lehta publike
  $q1 = $pdo->prepare("SELECT COUNT(DISTINCT cg.course_id) FROM course_group_students cgs JOIN course_groups cg ON cg.id=cgs.group_id WHERE cgs.student_id=:sid");
  $q1->execute([':sid'=>$sid]); $courses = (int)$q1->fetchColumn();

  $q2 = $pdo->prepare("SELECT COUNT(*) FROM course_group_students WHERE student_id=:sid");
  $q2->execute([':sid'=>$sid]); $groups = (int)$q2->fetchColumn();

  $q3 = $pdo->prepare("SELECT AVG(final_score) FROM course_group_students WHERE student_id=:sid AND final_score IS NOT NULL");
  $q3->execute([':sid'=>$sid]); $avg = $q3->fetchColumn();
  $avgScore = $avg!==null ? round((float)$avg,1) : null;

  $q4 = $pdo->prepare("
    SELECT SUM(CASE WHEN final_score>=50 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0) * 100
    FROM course_group_students WHERE student_id=:sid AND final_score IS NOT NULL
  ");
  $q4->execute([':sid'=>$sid]); $pr = $q4->fetchColumn();
  $passRate = $pr!==null ? round((float)$pr,1) : null;

  // Listë e shkurtër kursesh (max 5 publikisht)
  $q5 = $pdo->prepare("
    SELECT DISTINCT c.code, c.name
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE cgs.student_id = :sid
    ORDER BY c.name ASC
    LIMIT 5
  ");
  $q5->execute([':sid'=>$sid]);
  $coursesList = $q5->fetchAll(PDO::FETCH_ASSOC);

  return [
    'valid'=>true,
    'student'=>[
      'id'=>(int)$S['id'],
      'amze'=>$S['nr_amze'],
      'first_name'=>$S['first_name'],
      'father_name'=>$S['father_name'],
      'last_name'=>$S['last_name'],
      'personal_number_masked'=>mask_id($S['personal_number'] ?? null),
      'edu_label'=>$S['edu_label'] ?? null,
      'agency'=>$S['agency_name'] ?? null,
      'created_at'=>$S['created_at'] ?? null,
      'stats'=>[
        'courses'=>$courses,
        'groups'=>$groups,
        'avg_score'=>$avgScore,
        'pass_rate'=>$passRate
      ],
      'courses_list'=>$coursesList
    ]
  ];
}

/* =========================================================
   API e vogël AJAX: POST JSON {action:"verify", payload:"..."} 
========================================================= */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  $raw = file_get_contents('php://input');
  $json = (stripos($ct,'application/json')!==false) ? json_decode($raw,true) : $_POST;

  $action = $json['action'] ?? '';
  if ($action==='verify') {
    $payload = trim((string)($json['payload'] ?? ''));
    $sid = (int)($_GET['sid'] ?? 0); // lejo override via URL
    $token = trim((string)($_GET['t'] ?? ($json['token'] ?? '')));

    if (!$sid || !$token) {
      $p = parse_payload($payload);
      $sid = $sid ?: (int)$p['sid'];
      $token = $token ?: (string)$p['token'];
    }

    $res = verify_student($pdo, (int)$sid, (string)$token);
    json_out(['ok'=>true] + $res);
  }
  json_out(['ok'=>false, 'error'=>'Veprim i panjohur.']);
}

/* =========================================================
   Nëse vjen si GET me ?sid=...&t=..., bëj verifikim server-side
========================================================= */
$prefillResult = null;
if (isset($_GET['sid'], $_GET['t'])) {
  $sid = ctype_digit((string)$_GET['sid']) ? (int)$_GET['sid'] : 0;
  $token = trim((string)$_GET['t']);
  $prefillResult = verify_student($pdo, $sid, $token);
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <title>Verifikim certifikate / QR – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
  <style>
    :root{
      --g1:#0ea5e9; --g2:#2563eb; --g3:#4f46e5;
      --bg:#f5f7fb; --glass:rgba(255,255,255,.82); --glass-b:rgba(255,255,255,.55);
      --muted:#64748b; --shadow:0 18px 40px rgba(2,6,23,.12);
    }
    body { background:var(--bg); }
    .card { border:none; border-radius:1rem; box-shadow:var(--shadow); }
    .hero {
      background:
        radial-gradient(1200px 420px at 10% -20%, rgba(37,99,235,.25), rgba(37,99,235,0) 60%),
        radial-gradient(900px 320px at 90% -10%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, var(--g1) 0%, var(--g2) 55%, var(--g3) 100%);
      color:#fff; border-radius:0 0 1.25rem 1.25rem; padding:42px 0;
    }
    .chip { background:rgba(255,255,255,.17); border:1px solid rgba(255,255,255,.26); }
    .qrbox { background:#fff; border:1px dashed #e5e7eb; border-radius:.75rem; }
    .small-muted { color:var(--muted); font-size:.95rem; }
    .dropzone { border:2px dashed #cbd5e1; border-radius:.75rem; background:#fff; padding:18px; text-align:center; transition:.2s ease; }
    .dropzone.dragover { background:#f8fafc; border-color:#94a3b8; }
    .nav-pills .nav-link { border-radius:.75rem; }
    .status-banner{
      background:var(--glass); border:1px solid var(--glass-b); backdrop-filter:blur(10px);
      border-radius:1rem; padding:10px 14px; display:flex; align-items:center; gap:.5rem;
    }
    .status-icon{ width:34px; height:34px; border-radius:.6rem; display:flex; align-items:center; justify-content:center; }
    .status-valid{ border-left:6px solid #22c55e; }
    .status-invalid{ border-left:6px solid #ef4444; }
    .btn-soft { background:#f8fafc; border:1px solid #e5e7eb; }
    @media print {
      .navbar, .nav, .btn, .dropzone, .hero, footer { display:none !important; }
      .card { box-shadow:none !important; border:1px solid #e5e7eb; }
      body { background:white !important; }
    }
  </style>
</head>
<body>

<!-- Navbar identik me index.php -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <img src="image/logoPNG2.png" alt="Logo" height="30" class="d-inline-block align-text-top me-2">
      Qendra e Trajnimeve të Avancuara (QTA)
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="index.php">Kryefaqja</a></li>
        <li class="nav-item"><a class="nav-link" href="#">Rreth nesh</a></li>
        <li class="nav-item"><a class="nav-link" href="contact.html">Kontakt</a></li>
        <?php if ($currentUser): ?>
          <?php if ($currentUser['role_name'] === 'administrator'): ?>
            <li class="nav-item me-2"><a class="btn btn-outline-light btn-sm" href="dashboard_admin.php"><i class="bi bi-speedometer2 me-1"></i>Paneli</a></li>
          <?php endif; ?>
          <li class="nav-item">
            <span class="text-white-50 small me-2 d-none d-sm-inline">
              <i class="bi bi-person-circle me-1"></i><?= h($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Përdorues')) ?>
            </span>
            <a class="btn btn-primary ms-2" href="logout.php" role="button">Dil</a>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="btn btn-primary ms-2" href="selectProfile.php" role="button">Hyr</a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<section class="hero mb-4">
  <div class="container">
    <span class="badge chip rounded-pill mb-2">Verifikim publik i certifikatës</span>
    <h1 class="display-6 fw-bold mb-2">Skanon kodin QR dhe verifiko kursantin</h1>
    <p class="mb-0">Kjo faqe ju ndihmon të kontrolloni nëse një certifikatë përputhet me të dhënat reale të studentit në QTA.</p>
  </div>
</section>

<div class="container mb-5">
  <div class="row g-4">
    <!-- Kolona: mënyrat e verifikimit (me tab-a) -->
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header bg-white">
          <strong><i class="bi bi-shield-check me-1"></i>Zgjidh mënyrën e verifikimit</strong>
        </div>
        <div class="card-body">
          <ul class="nav nav-pills mb-3" id="verifyTabs" role="tablist">
            <li class="nav-item me-1" role="presentation">
              <button class="nav-link active" id="tab-camera" data-bs-toggle="pill" data-bs-target="#pane-camera" type="button" role="tab">
                <i class="bi bi-camera-video me-1"></i>Kamerë
              </button>
            </li>
            <li class="nav-item me-1" role="presentation">
              <button class="nav-link" id="tab-upload" data-bs-toggle="pill" data-bs-target="#pane-upload" type="button" role="tab">
                <i class="bi bi-upload me-1"></i>Ngarko foto
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-manual" data-bs-toggle="pill" data-bs-target="#pane-manual" type="button" role="tab">
                <i class="bi bi-clipboard-check me-1"></i>Ngjit tekst/URL
              </button>
            </li>
          </ul>

          <div class="tab-content">
            <!-- Pane: Kamera -->
            <div class="tab-pane fade show active" id="pane-camera" role="tabpanel" aria-labelledby="tab-camera">
              <div id="scanRegion" class="qrbox p-2 mb-3">
                <div id="reader" style="width:100%;"></div>
              </div>
              <div class="d-flex align-items-center justify-content-between">
                <div class="small-muted">Lejo aksesin e kamerës. Përdor kamerën e pasme në telefon (nëse ofrohet).</div>
                <button class="btn btn-outline-secondary btn-sm" id="btnRestartCam"><i class="bi bi-arrow-clockwise me-1"></i>Rinis</button>
              </div>
            </div>

            <!-- Pane: Upload foto -->
            <div class="tab-pane fade" id="pane-upload" role="tabpanel" aria-labelledby="tab-upload">
              <div id="file-reader" class="d-none"></div>
              <div id="dropzone" class="dropzone mb-2">
                <i class="bi bi-image fs-4 d-block mb-2"></i>
                Zvarrit një foto me QR këtu ose
                <label class="btn btn-sm btn-primary ms-1 mb-1">
                  Zgjidh skedar
                  <input id="fileInput" type="file" accept="image/*" hidden>
                </label>
                <div class="small-muted mt-2">Mbështetur: JPG, PNG, WEBP…</div>
              </div>
              <div class="d-flex gap-2">
                <button id="btnPasteImage" class="btn btn-soft btn-sm"><i class="bi bi-clipboard2-check me-1"></i>Ngjit nga Clipboard</button>
                <div id="uploadMsg" class="small text-muted ms-auto"></div>
              </div>
            </div>

            <!-- Pane: Manual -->
            <div class="tab-pane fade" id="pane-manual" role="tabpanel" aria-labelledby="tab-manual">
              <div class="input-group mb-2">
                <span class="input-group-text bg-light border-0"><i class="bi bi-clipboard-check"></i></span>
                <input id="manualPayload" type="text" class="form-control border-0" placeholder="Ngjit këtu stringun ose URL-në e QR">
              </div>
              <div class="d-flex gap-2 mb-3">
                <button id="btnVerify" class="btn btn-primary flex-fill"><i class="bi bi-shield-check me-1"></i>Verifiko</button>
                <button id="btnPaste" class="btn btn-soft"><i class="bi bi-clipboard2"></i></button>
                <button id="btnClear" class="btn btn-outline-secondary">Pastro</button>
              </div>
              <div class="small-muted">Këshillë: formatet e pranuara janë URL me ?sid=&t=, <code>QTA|SID:..|TOKEN:..</code>, JSON {"sid","token"} ose <code>SID|TOKEN</code>.</div>
            </div>
          </div>

          <hr>
          <div class="alert alert-warning mb-0">
            <strong>E rëndësishme:</strong> Nëse <u>emri</u> në ekran <b>NUK</b> përputhet me emrin në certifikatën fizike,
            mund të jetë <b>certifikatë e vjedhur/kopjuar</b>. Njoftoni menjëherë QTA te
            <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a> ose <a href="tel:+355698778837">+355 69 877 8837</a>.
          </div>
        </div>
      </div>
    </div>

    <!-- Kolona: rezultati -->
    <div class="col-lg-7">
      <div class="status-banner mb-3 <?= $prefillResult ? ($prefillResult['valid']?'status-valid':'status-invalid') : '' ?>">
        <div class="status-icon <?= $prefillResult ? ($prefillResult['valid']?'bg-success-subtle text-success':'bg-danger-subtle text-danger') : 'bg-secondary-subtle text-secondary' ?>">
          <i class="bi <?= $prefillResult ? ($prefillResult['valid']?'bi-check2-circle':'bi-x-circle') : 'bi-shield-lock' ?>"></i>
        </div>
        <div class="fw-semibold">Statusi:</div>
        <div id="statusText"><?= $prefillResult ? ($prefillResult['valid']?'VALID':'INVALID') : 'Gati për verifikim' ?></div>
        <div class="ms-auto d-none d-lg-flex gap-2">
          <button class="btn btn-soft btn-sm" id="btnShare" title="Krijo link verifikimi"><i class="bi bi-link-45deg"></i></button>
          <button class="btn btn-soft btn-sm" id="btnPrint" title="Printo rezultatin"><i class="bi bi-printer"></i></button>
        </div>
      </div>

      <div id="resultCard" class="card <?= $prefillResult ? ($prefillResult['valid']?'status-valid':'status-invalid') : '' ?>">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <strong><i class="bi bi-patch-check me-1"></i>Rezultati i verifikimit</strong>
          <span id="statusChip" class="badge <?= $prefillResult ? ($prefillResult['valid']?'text-bg-success':'text-bg-danger') : 'text-bg-secondary' ?>">
            <?= $prefillResult ? ($prefillResult['valid']?'VALID':'INVALID') : '—' ?>
          </span>
        </div>
        <div class="card-body" id="resultBody" aria-live="polite">
          <?php if ($prefillResult): ?>
            <?php if ($prefillResult['valid']): 
              $st = $prefillResult['student']; 
              $full = trim(($st['first_name']??'').' '.(($st['father_name']??'')?($st['father_name'].' '):'').($st['last_name']??'')); ?>
              <div class="d-flex align-items-center mb-3">
                <div class="me-3" style="width:46px;height:46px;border-radius:.75rem;background:#eef2ff;display:flex;align-items:center;justify-content:center;">
                  <i class="bi bi-person-badge fs-4 text-primary"></i>
                </div>
                <div>
                  <div class="h5 mb-0"><?= h($full ?: '—') ?></div>
                  <div class="small text-muted">AMZË: <strong><?= h($st['amze'] ?? '—') ?></strong> • ID personale: <?= h($st['personal_number_masked'] ?? '—') ?></div>
                </div>
              </div>

              <div class="row g-3">
                <div class="col-6 col-md-3"><div class="p-3 rounded" style="background:#f8fafc;">
                  <div class="small text-muted">Modulet</div><div class="h5 mb-0"><?= (int)$st['stats']['courses'] ?></div></div></div>
                <div class="col-6 col-md-3"><div class="p-3 rounded" style="background:#f8fafc;">
                  <div class="small text-muted">Grupe</div><div class="h5 mb-0"><?= (int)$st['stats']['groups'] ?></div></div></div>
                <div class="col-6 col-md-3"><div class="p-3 rounded" style="background:#f8fafc;">
                  <div class="small text-muted">Mes. pikë</div><div class="h5 mb-0"><?= $st['stats']['avg_score']!==null ? $st['stats']['avg_score'] : '—' ?></div></div></div>
                <div class="col-6 col-md-3"><div class="p-3 rounded" style="background:#f8fafc;">
                  <div class="small text-muted">Kalueshmëria</div><div class="h5 mb-0"><?= $st['stats']['pass_rate']!==null ? ($st['stats']['pass_rate'].'%') : '—' ?></div></div></div>
              </div>

              <hr>
              <div class="row">
                <div class="col-md-6"><div class="small text-muted">Edukimi</div><div class="fw-semibold mb-3"><?= h($st['edu_label'] ?? '—') ?></div></div>
                <div class="col-md-6"><div class="small text-muted">Agjencia</div><div class="fw-semibold mb-3"><?= h($st['agency'] ?? '—') ?></div></div>
              </div>

              <div class="small text-muted">Kurset (max 5):</div>
              <ul class="list-group list-group-flush">
                <?php if (!empty($st['courses_list'])): foreach ($st['courses_list'] as $c): ?>
                  <li class="list-group-item"><i class="bi bi-mortarboard me-2"></i><?= h(($c['code']??'').' · '.($c['name']??'')) ?></li>
                <?php endforeach; else: ?>
                  <li class="list-group-item text-muted">Nuk u gjet listë kursesh.</li>
                <?php endif; ?>
              </ul>

              <div class="alert alert-info mt-3">
                <strong>Kujdes:</strong> Nëse emri në ekran <u>nuk</u> përputhet me emrin në certifikatën fizike,
                raportoni menjëherë te <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a> ose <a href="tel:+355698778837">+355 69 877 8837</a>.
              </div>
            <?php else: ?>
              <div class="alert alert-danger mb-0">
                <strong>INVALID:</strong> Token i pavlefshëm ose nuk përputhet me studentin. Kontrolloni që QR të jetë i qartë
                dhe i plotë. Nëse dyshoni për abuzim, njoftoni QTA menjëherë.
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-muted">Skanoni QR, ngarkoni foto ose ngjisni vlerën për verifikim. Rezultati do të shfaqet këtu.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<footer class="bg-dark text-white py-4">
  <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
    <div>&copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.</div>
    <div class="small">
      Raport abuzimi: <a class="text-white" href="mailto:officialqta@gmail.com">officialqta@gmail.com</a> ·
      <a class="text-white" href="tel:+355698778837">+355 69 877 8837</a>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ====== Global helpers ====== */
let html5QrcodeScanner, fileQr;
let lastPayload = ''; // ruajmë payload për share link
const statusBanner = document.querySelector('.status-banner');
const statusText = document.getElementById('statusText');

function setStatus(label, variant){
  const chip = document.getElementById('statusChip');
  chip.className = 'badge text-bg-'+variant;
  chip.textContent = label;
  if (statusText) statusText.textContent = (label === '—' ? 'Gati për verifikim' : label);
  statusBanner?.classList.remove('status-valid','status-invalid');
  if (variant==='success') statusBanner?.classList.add('status-valid');
  if (variant==='danger')  statusBanner?.classList.add('status-invalid');
}

function escapeHtml(s){ const d=document.createElement('div'); d.innerText=s||''; return d.innerHTML; }

/* ====== Skanimi me kamerë (html5-qrcode) ====== */
function onScanSuccess(decodedText) {
  lastPayload = decodedText;
  document.querySelector('#tab-manual').click();
  const inp = document.getElementById('manualPayload');
  inp.value = decodedText;
  doVerify(decodedText);
}

function startScanner(){
  try{
    const w = Math.min(500, document.getElementById('reader').clientWidth || 500);
    html5QrcodeScanner = new Html5QrcodeScanner(
      "reader",
      { fps: 10, qrbox: Math.floor(w*0.8), aspectRatio: 1.0, rememberLastUsedCamera: true,
        supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA] },
      false
    );
    html5QrcodeScanner.render(onScanSuccess, (err)=>{ /* silent */ });
  }catch(e){
    document.getElementById('reader').innerHTML = '<div class="text-muted p-3">Kamera nuk është në dispozicion ose nuk u lejua.</div>';
  }
}

/* ====== Ngarko foto / Drag & Drop / Paste ====== */
function initFileScanner(){
  try{ fileQr = new Html5Qrcode('file-reader'); }catch(e){}
}
function setUploadMsg(text, good=false){
  const m = document.getElementById('uploadMsg');
  if (!m) return;
  m.className = good ? 'small text-success ms-auto' : 'small text-danger ms-auto';
  m.textContent = text || '';
}
async function handleFile(file){
  if(!file){ setUploadMsg('Asnjë skedar.'); return; }
  if(!fileQr) initFileScanner();
  try{
    setUploadMsg('Duke lexuar QR nga imazhi…', true);
    const decodedText = await fileQr.scanFile(file, true);
    lastPayload = decodedText;
    document.querySelector('#tab-manual').click();
    const inp = document.getElementById('manualPayload');
    inp.value = decodedText;
    doVerify(decodedText);
    setUploadMsg('U lexua me sukses.', true);
  } catch(err){
    console.error(err);
    setUploadMsg('Nuk u gjet QR në këtë imazh. Provo me foto më të qartë.');
  }
}
document.getElementById('fileInput')?.addEventListener('change', (ev)=>{
  const f = ev.target.files?.[0]; handleFile(f);
});
const dz = document.getElementById('dropzone');
dz?.addEventListener('dragover', (e)=>{ e.preventDefault(); dz.classList.add('dragover'); });
dz?.addEventListener('dragleave', ()=> dz.classList.remove('dragover'));
dz?.addEventListener('drop', (e)=>{
  e.preventDefault(); dz.classList.remove('dragover');
  const f = e.dataTransfer.files?.[0]; handleFile(f);
});
document.getElementById('btnPasteImage')?.addEventListener('click', async ()=>{
  try{
    const items = await navigator.clipboard.read();
    for (const item of items){
      for (const type of item.types){
        if (type.startsWith('image/')){
          const blob = await item.getType(type);
          const file = new File([blob], 'clipboard.'+type.split('/')[1], {type});
          return handleFile(file);
        }
      }
    }
    setUploadMsg('Clipboard nuk përmban imazh.');
  }catch(e){ setUploadMsg('Shfletuesi nuk lejon leximin e imazhit nga clipboard.'); }
});

/* ====== Manual verify ====== */
async function doVerify(payload){
  setStatus('Duke verifikuar...', 'secondary');
  const res = await fetch('verify.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','Accept':'application/json'},
    body: JSON.stringify({action:'verify', payload})
  });
  const json = await res.json();
  renderResult(json, payload);
}

function renderResult(json, payloadUsed=''){
  const card = document.getElementById('resultCard');
  const body = document.getElementById('resultBody');

  if(!json || json.ok!==true){
    setStatus('Gabim', 'danger');
    card.classList.remove('status-valid','status-invalid');
    body.innerHTML = `<div class="alert alert-danger">Gabim gjatë verifikimit. Provo përsëri.</div>`;
    return;
  }

  // ruaj payload për share
  if (payloadUsed) lastPayload = payloadUsed;

  if(json.valid){
    setStatus('VALID', 'success');
    card.classList.add('status-valid'); card.classList.remove('status-invalid');

    const st = json.student || {};
    const full = [st.first_name||'', st.father_name? (st.father_name+' ') : '', st.last_name||''].join('').trim();

    let coursesList = '';
    if (Array.isArray(st.courses_list) && st.courses_list.length>0){
      coursesList = st.courses_list.map(c => {
        const t = (c.code? c.code+' · ' : '') + (c.name||'');
        return `<li class="list-group-item"><i class="bi bi-mortarboard me-2"></i>${escapeHtml(t)}</li>`;
      }).join('');
    } else {
      coursesList = `<li class="list-group-item text-muted">Nuk u gjet listë kursesh.</li>`;
    }

    body.innerHTML = `
      <div class="d-flex align-items-center mb-3">
        <div class="me-3" style="width:46px;height:46px;border-radius:.75rem;background:#eef2ff;display:flex;align-items:center;justify-content:center;">
          <i class="bi bi-person-badge fs-4 text-primary"></i>
        </div>
        <div>
          <div class="h5 mb-0">${escapeHtml(full||'—')}</div>
          <div class="small text-muted">AMZË: <strong>${escapeHtml(st.amze||'—')}</strong> • ID personale: ${escapeHtml(st.personal_number_masked||'—')}</div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-6 col-md-3"><div class="p-3 rounded" style="background:#f8fafc;">
          <div class="small text-muted">Modulet</div><div class="h5 mb-0">${st.stats?.courses ?? 0}</div></div></div>
        <div class="col-6 col-md-3"><div class="p-3 rounded" style="background:#f8fafc;">
          <div class="small text-muted">Grupe</div><div class="h5 mb-0">${st.stats?.groups ?? 0}</div></div></div>
        <div class="col-6 col-md-3"><div class="p-3 rounded" style="background:#f8fafc;">
          <div class="small text-muted">Mes. pikë</div><div class="h5 mb-0">${st.stats?.avg_score ?? '—'}</div></div></div>
        <div class="col-6 col-md-3"><div class="p-3 rounded" style="background:#f8fafc;">
          <div class="small text-muted">Kalueshmëria</div><div class="h5 mb-0">${(st.stats?.pass_rate ?? null) !== null ? st.stats.pass_rate+'%' : '—'}</div></div></div>
      </div>

      <hr>
      <div class="row">
        <div class="col-md-6"><div class="small text-muted">Edukimi</div><div class="fw-semibold mb-3">${escapeHtml(st.edu_label||'—')}</div></div>
        <div class="col-md-6"><div class="small text-muted">Agjencia</div><div class="fw-semibold mb-3">${escapeHtml(st.agency||'—')}</div></div>
      </div>

      <div class="small text-muted">Kurset (max 5):</div>
      <ul class="list-group list-group-flush">${coursesList}</ul>

      <div class="alert alert-info mt-3">
        <strong>Kujdes:</strong> Nëse emri këtu <u>nuk</u> përputhet me emrin në certifikatën fizike,
        raportoni menjëherë te <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a> ose
        <a href="tel:+355698778837">+355 69 877 8837</a>.
      </div>
    `;
  } else {
    setStatus('INVALID', 'danger');
    card.classList.add('status-invalid'); card.classList.remove('status-valid');
    const reason = json.reason || 'Token i pavlefshëm.';
    body.innerHTML = `
      <div class="alert alert-danger">
        <strong>INVALID:</strong> ${escapeHtml(reason)}<br>
        Kontrolloni QR dhe provoni sërish. Nëse dyshoni për abuzim, njoftoni QTA menjëherë:
        <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a> · <a href="tel:+355698778837">+355 69 877 8837</a>.
      </div>`;
  }
}

/* ====== Butona & Shërbime ====== */
// Manual buttons
document.getElementById('btnVerify')?.addEventListener('click', ()=>{
  const v = document.getElementById('manualPayload').value.trim();
  if (!v){ document.getElementById('manualPayload').focus(); return; }
  lastPayload = v;
  doVerify(v);
});
document.getElementById('btnPaste')?.addEventListener('click', async ()=>{
  try{
    const t = await navigator.clipboard.readText();
    if (t){ document.getElementById('manualPayload').value = t; lastPayload = t; }
  }catch(e){}
});
document.getElementById('btnClear')?.addEventListener('click', ()=>{
  document.getElementById('manualPayload').value = '';
  setStatus('—','secondary');
  document.getElementById('resultBody').innerHTML = '<div class="text-muted">Skanoni QR, ngarkoni foto ose ngjisni vlerën për verifikim. Rezultati do të shfaqet këtu.</div>';
  document.getElementById('resultCard').classList.remove('status-valid','status-invalid');
});

// Restart camera
document.getElementById('btnRestartCam')?.addEventListener('click', ()=>{
  try{ html5QrcodeScanner?.clear(); }catch(e){}
  document.querySelector('#tab-camera').click();
  startScanner();
});

// Share link (ndërto nga payload nëse ka SID/TOKEN)
function buildShareFromPayload(raw){
  const s = String(raw||'');
  // përpiqu të gjesh sid & token
  let sid = 0, token = '';
  if (s.startsWith('http')){
    try{
      const url = new URL(s);
      sid = parseInt(url.searchParams.get('sid')||'0', 10) || 0;
      token = (url.searchParams.get('t') || url.searchParams.get('token') || '').trim();
    }catch(e){}
  }
  if (!sid || !token){
    const mSid = s.match(/SID\s*:\s*(\d+)/i); if (mSid) sid = parseInt(mSid[1],10)||0;
    const mTok = s.match(/TOKEN\s*:\s*([a-f0-9]{32,})/i); if (mTok) token = mTok[1].toLowerCase();
  }
  if (!sid || !token){
    const m = s.split('|'); if (m.length===2 && /^\d+$/.test(m[0])) { sid = parseInt(m[0],10); token = m[1].trim(); }
  }
  if (sid && token){
    const base = location.origin + location.pathname;
    return `${base}?sid=${sid}&t=${encodeURIComponent(token)}`;
  }
  return '';
}
document.getElementById('btnShare')?.addEventListener('click', async ()=>{
  const link = buildShareFromPayload(lastPayload);
  if (!link){ alert('S’ka të dhëna të mjaftueshme për link. Skanoni ose ngjisni QR fillimisht.'); return; }
  try{ await navigator.clipboard.writeText(link); }catch(e){}
  const btn = document.getElementById('btnShare');
  const old = btn.innerHTML; btn.innerHTML = '<i class="bi bi-check2"></i>'; setTimeout(()=>btn.innerHTML=old, 900);
});
document.getElementById('btnPrint')?.addEventListener('click', ()=> window.print());

/* Start */
window.addEventListener('load', ()=>{
  startScanner();
  initFileScanner();
});
</script>
</body>
</html>
