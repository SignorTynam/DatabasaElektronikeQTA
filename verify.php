<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* ========================= Helpers ========================= */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function json_out(array $x){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($x); exit; }

/* Masko ID personale (p.sh. ****123) */
function mask_id(?string $s): string {
  if(!$s) return '—';
  $len = strlen($s);
  if ($len <= 3) return str_repeat('*', max(0,$len-1)).substr($s, -1);
  return str_repeat('*', $len-3).substr($s, -3);
}

/* Parser payload-i nga QR */
function parse_payload(string $raw): array {
  $raw = trim($raw);

  // 1) URL ?sid=...&t=...
  if (filter_var($raw, FILTER_VALIDATE_URL)) {
    $q = parse_url($raw, PHP_URL_QUERY);
    parse_str($q ?? '', $arr);
    $sid = isset($arr['sid']) && ctype_digit((string)$arr['sid']) ? (int)$arr['sid'] : 0;
    $token = $arr['t'] ?? ($arr['token'] ?? '');
    $token = is_string($token) ? trim($token) : '';
    return ['sid'=>$sid, 'token'=>$token];
  }

  // 2) “QTA|SID:...|TOKEN:...”
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

  // 4) “SID|TOKEN”
  if (str_contains($raw,'|')) {
    [$a,$b] = array_map('trim', explode('|',$raw,2));
    $sid = ctype_digit($a) ? (int)$a : 0;
    $token = $b;
    return ['sid'=>$sid, 'token'=>$token];
  }

  return ['sid'=>0, 'token'=>''];
}

/* Verifikimi në DB */
function verify_student(PDO $pdo, int $sid, string $token): array {
  if ($sid<=0 || $token==='') return ['valid'=>false, 'reason'=>'Mungon SID ose token.'];

  $chk = $pdo->prepare("SELECT 1 FROM student_qr_tokens WHERE student_id=:sid AND token=:t LIMIT 1");
  $chk->execute([':sid'=>$sid, ':t'=>$token]);
  if (!$chk->fetchColumn()) return ['valid'=>false, 'reason'=>'Token i pavlefshëm ose nuk përputhet me këtë student.'];

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

  // Stats publike
  $q1 = $pdo->prepare("SELECT COUNT(DISTINCT cg.course_id) FROM course_group_students cgs JOIN course_groups cg ON cg.id=cgs.group_id WHERE cgs.student_id=:sid");
  $q1->execute([':sid'=>$sid]); $courses = (int)$q1->fetchColumn();

  $q2 = $pdo->prepare("SELECT COUNT(*) FROM course_group_students WHERE student_id=:sid");
  $q2->execute([':sid'=>$sid]); $groups = (int)$q2->fetchColumn();

  $q3 = $pdo->prepare("SELECT AVG(final_score) FROM course_group_students WHERE student_id=:sid AND final_score IS NOT NULL");
  $q3->execute([':sid'=>$sid]); $avg = $q3->fetchColumn();
  $avgScore = $avg!==null ? round((float)$avg,1) : null;

  $q4 = $pdo->prepare("SELECT SUM(CASE WHEN final_score>=50 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0) * 100 FROM course_group_students WHERE student_id=:sid AND final_score IS NOT NULL");
  $q4->execute([':sid'=>$sid]); $pr = $q4->fetchColumn();
  $passRate = $pr!==null ? round((float)$pr,1) : null;

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

/* ==================== API JSON (POST) ==================== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  $raw = file_get_contents('php://input');
  $json = (stripos($ct,'application/json')!==false) ? json_decode($raw,true) : $_POST;

  $action = $json['action'] ?? '';
  if ($action==='verify') {
    $payload = trim((string)($json['payload'] ?? ''));
    $sid = (int)($_GET['sid'] ?? 0);
    $token = trim((string)($_GET['t'] ?? ($json['token'] ?? '')));

    if (!$sid || !$token) { $p = parse_payload($payload); $sid = $sid ?: (int)$p['sid']; $token = $token ?: (string)$p['token']; }

    $res = verify_student($pdo, (int)$sid, (string)$token);
    json_out(['ok'=>true] + $res);
  }
  json_out(['ok'=>false, 'error'=>'Veprim i panjohur.']);
}

/* ==================== GET: Render ==================== */
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

$prefillResult = null;
if (isset($_GET['sid'], $_GET['t'])) {
  $sid = ctype_digit((string)$_GET['sid']) ? (int)$_GET['sid'] : 0;
  $token = trim((string)$_GET['t']);
  $prefillResult = verify_student($pdo, $sid, $token);
}

$NAV_ACTIVE = 'verify';
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
      --g1:#0ea5e9; --g2:#2563eb; --g3:#4f46e5;     /* Ocean (default) */
      --bg:#f6f8fc; --text:#0f172a; --muted:#64748b;
      --glass:rgba(255,255,255,.82); --glass-b:rgba(255,255,255,.55);
      --shadow:0 18px 40px rgba(2,6,23,.12);
      --chip:rgba(255,255,255,.17); --chip-b:rgba(255,255,255,.26);
      --accent:#0ea5e9;
    }
    [data-theme="emerald"]{ --g1:#10b981; --g2:#059669; --g3:#047857; --accent:#10b981; }
    [data-theme="sunset"]{ --g1:#f97316; --g2:#ef4444; --g3:#db2777; --accent:#f97316; }

    body { background:var(--bg); color:var(--text); }
    .card { border:none; border-radius:1rem; box-shadow:var(--shadow); }
    .hero {
      background:
        radial-gradient(1200px 420px at 10% -20%, rgba(37,99,235,.25), rgba(37,99,235,0) 60%),
        radial-gradient(900px 320px at 90% -10%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, var(--g1) 0%, var(--g2) 55%, var(--g3) 100%);
      color:#fff; border-radius:0 0 1.25rem 1.25rem; padding:48px 0;
      position:relative; overflow:hidden;
    }
    .hero .chip { background:var(--chip); border:1px solid var(--chip-b); }
    .hero .shape { position:absolute; filter:blur(60px); opacity:.35; }
    .hero .shape.s1{ width:240px; height:240px; background:#fff; top:-40px; left:-40px; border-radius:50%; }
    .hero .shape.s2{ width:300px; height:300px; background:#fff; bottom:-100px; right:-80px; border-radius:40% 60% 50% 50%/50% 40% 60% 50%; }

    .qrbox { background:#fff; border:1px dashed #e5e7eb; border-radius:.75rem; }
    .small-muted { color:var(--muted); font-size:.95rem; }
    .dropzone { border:2px dashed #cbd5e1; border-radius:.75rem; background:#fff; padding:18px; text-align:center; transition:.2s ease; }
    .dropzone.dragover { background:#f8fafc; border-color:#94a3b8; }
    .nav-pills .nav-link { border-radius:.75rem; }
    .btn-soft { background:#f8fafc; border:1px solid #e5e7eb; }
    .btn-accent { background:var(--accent); border:none; }
    .btn-accent:hover { filter:brightness(.95); }
    .status-banner{
      background:var(--glass); border:1px solid var(--glass-b); backdrop-filter:blur(10px);
      border-radius:1rem; padding:10px 14px; display:flex; align-items:center; gap:.5rem;
    }
    .status-icon{ width:34px; height:34px; border-radius:.6rem; display:flex; align-items:center; justify-content:center; }
    .status-valid{ border-left:6px solid #22c55e; }
    .status-invalid{ border-left:6px solid #ef4444; }

    /* --- Stabiletet layout-i (anti-jump) --- */
    #reader{ width:100%; height:320px; }               /* lartësi fikse që të mos "hidhet" layout-i */
    #reader > div{ border-radius:.75rem !important; overflow:hidden; }
    #reader video{ width:100% !important; height:100% !important; object-fit:cover; border-radius:.75rem; }
    .qrbox > *{ border-radius:.75rem; overflow:hidden; }
    .tab-content{ min-height:380px; }                  /* lartësi min që të mos rrëzohet karta */

    /* How it works */
    .how-step { display:flex; gap:.75rem; align-items:flex-start; }
    .how-bullet {
      width:36px; height:36px; border-radius:.8rem; display:flex; align-items:center; justify-content:center;
      background:#fff; box-shadow:var(--shadow); color:var(--g2); flex:0 0 36px;
    }

    /* FAQ */
    .accordion-button:focus { box-shadow:none; }
    .accordion-button:not(.collapsed){ background:#f8fafc; color:var(--text); }

    @media print {
      .navbar, .nav, .btn, .dropzone, .hero, footer, .theme-switch { display:none !important; }
      .card { box-shadow:none !important; border:1px solid #e5e7eb; }
      body { background:white !important; }
    }
  </style>
</head>
<body>

<?php require __DIR__ . '/navbarMain.php'; ?>

<!-- HERO -->
<section class="hero mb-4">
  <div class="shape s1"></div><div class="shape s2"></div>
  <div class="container position-relative">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
      <span class="badge chip rounded-pill">Verifikim publik i certifikatës</span>

      <!-- Theme switch -->
      <div class="theme-switch d-flex align-items-center gap-2">
        <span class="small">Tema:</span>
        <button class="btn btn-sm btn-light border" data-theme="ocean"   title="Ocean"   style="width:28px;height:28px;border-radius:7px;background:linear-gradient(45deg,#0ea5e9,#2563eb,#4f46e5)"></button>
        <button class="btn btn-sm btn-light border" data-theme="emerald" title="Emerald" style="width:28px;height:28px;border-radius:7px;background:linear-gradient(45deg,#10b981,#059669,#047857)"></button>
        <button class="btn btn-sm btn-light border" data-theme="sunset"  title="Sunset"  style="width:28px;height:28px;border-radius:7px;background:linear-gradient(45deg,#f97316,#ef4444,#db2777)"></button>
      </div>
    </div>

    <div class="row align-items-center g-4">
      <div class="col-lg-7 text-white">
        <h1 class="display-6 fw-bold mb-2">Skanoni QR dhe verifikoni certifikatën në QTA</h1>
        <p class="mb-4">Konfirmoni shpejt nëse të dhënat në certifikatën fizike përputhen me regjistrat zyrtarë.</p>

        <div class="row g-3">
          <div class="col-md-4"><div class="how-step"><div class="how-bullet"><i class="bi bi-camera-video"></i></div><div><strong>1. Zgjidhni</strong><br><span class="small">Kamerë, foto ose URL.</span></div></div></div>
          <div class="col-md-4"><div class="how-step"><div class="how-bullet"><i class="bi bi-upc-scan"></i></div><div><strong>2. Skanoni</strong><br><span class="small">Lexoni QR ose ngjisni të dhënat.</span></div></div></div>
          <div class="col-md-4"><div class="how-step"><div class="how-bullet"><i class="bi bi-shield-check"></i></div><div><strong>3. Verifikoni</strong><br><span class="small">Shfaqet statusi dhe detajet.</span></div></div></div>
        </div>
      </div>

      <div class="col-lg-5 text-end">
        <a href="selectProfile.php" class="btn btn-accent btn-lg shadow-sm"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr në sistem</a>
      </div>
    </div>
  </div>
</section>

<div class="container mb-5">
  <div class="row g-4">
    <!-- Tabs & Inputs -->
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <strong><i class="bi bi-shield-check me-1"></i>Metodat e verifikimit</strong>
          <span class="small text-muted">Zgjidhni një mënyrë</span>
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
            <!-- Kamera -->
            <div class="tab-pane fade show active" id="pane-camera" role="tabpanel" aria-labelledby="tab-camera">
              <div id="scanRegion" class="qrbox p-2 mb-3">
                <div id="reader" aria-live="polite"></div>
              </div>
              <div class="d-flex align-items-center justify-content-between">
                <div class="small-muted">Lejo aksesin e kamerës. Përdor kamerën e pasme në telefon (nëse ofrohet).</div>
                <div class="d-flex gap-2">
                  <button class="btn btn-outline-secondary btn-sm" id="btnRestartCam"><i class="bi bi-arrow-clockwise me-1"></i>Rinis</button>
                  <button class="btn btn-outline-secondary btn-sm" id="btnStopCam"><i class="bi bi-stop-circle me-1"></i>Ndalo</button>
                </div>
              </div>
            </div>

            <!-- Upload foto -->
            <div class="tab-pane fade" id="pane-upload" role="tabpanel" aria-labelledby="tab-upload">
              <div id="file-reader" class="d-none"></div>
              <div id="dropzone" class="dropzone mb-2">
                <i class="bi bi-image fs-4 d-block mb-2"></i>
                Zvarrit një foto me QR këtu ose
                <label class="btn btn-sm btn-accent text-white ms-1 mb-1">
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

            <!-- Manual -->
            <div class="tab-pane fade" id="pane-manual" role="tabpanel" aria-labelledby="tab-manual">
              <div class="input-group mb-2">
                <span class="input-group-text bg-light border-0"><i class="bi bi-clipboard-check"></i></span>
                <input id="manualPayload" type="text" class="form-control border-0" placeholder="Ngjit këtu stringun ose URL-në e QR" aria-label="Vlera e QR">
              </div>
              <div class="d-flex gap-2 mb-3">
                <button id="btnVerify" class="btn btn-accent text-white flex-fill"><i class="bi bi-shield-check me-1"></i>Verifiko</button>
                <button id="btnPaste" class="btn btn-soft" title="Ngjit tekst"><i class="bi bi-clipboard2"></i></button>
                <button id="btnClear" class="btn btn-outline-secondary">Pastro</button>
              </div>
              <div class="small-muted">Pranohet: URL me ?sid=&t=, <code>QTA|SID:..|TOKEN:..</code>, JSON {"sid","token"} ose <code>SID|TOKEN</code>.</div>
            </div>
          </div>

          <hr>
          <div class="alert alert-warning mb-0">
            <strong>E rëndësishme:</strong> Nëse <u>emri</u> në ekran <b>NUK</b> përputhet me emrin në certifikatën fizike,
            mund të jetë <b>certifikatë e vjedhur/kopjuar</b>. Njoftoni menjëherë te
            <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a> ose <a href="tel:+355698778837">+355 69 877 8837</a>.
          </div>
        </div>
      </div>
    </div>

    <!-- Rezultati -->
    <div class="col-lg-7">
      <div class="status-banner mb-3 <?= $prefillResult ? ($prefillResult['valid']?'status-valid':'status-invalid') : '' ?>">
        <div class="status-icon <?= $prefillResult ? ($prefillResult['valid']?'bg-success-subtle text-success':'bg-danger-subtle text-danger') : 'bg-secondary-subtle text-secondary' ?>">
          <i id="statusIcon" class="bi <?= $prefillResult ? ($prefillResult['valid']?'bi-check2-circle':'bi-x-circle') : 'bi-shield-lock' ?>"></i>
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

      <!-- FAQ -->
      <div class="card mt-4">
        <div class="card-header bg-white"><strong><i class="bi bi-question-circle me-1"></i>Pyetjet e shpeshta</strong></div>
        <div class="card-body">
          <div class="accordion" id="faqVerify">
            <div class="accordion-item">
              <h2 class="accordion-header" id="fq1">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#fc1">QR nuk lexohet – çfarë të bëj?</button>
              </h2>
              <div id="fc1" class="accordion-collapse collapse show" data-bs-parent="#faqVerify">
                <div class="accordion-body small">
                  Sigurohuni që fotoja të jetë e qartë dhe me kontrast të mirë. Provoni kamerën e pasme ose ngarkoni një foto me rezolucion më të lartë.
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header" id="fq2">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#fc2">A ruhen të dhënat e mia?</button>
              </h2>
              <div id="fc2" class="accordion-collapse collapse" data-bs-parent="#faqVerify">
                <div class="accordion-body small">
                  Verifikimi kryen vetëm një pyetje të sigurt në bazën e të dhënave dhe shfaq rezultatet publike. Nuk ruajmë fotot e ngarkuara.
                </div>
              </div>
            </div>
            <div class="accordion-item">
              <h2 class="accordion-header" id="fq3">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#fc3">Si krijohet linku i verifikimit?</button>
              </h2>
              <div id="fc3" class="accordion-collapse collapse" data-bs-parent="#faqVerify">
                <div class="accordion-body small">
                  Nëse QR përmban <code>sid</code> dhe <code>token</code>, butoni “Link” krijon automatikisht një URL të ndarë që mund ta ndani.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ========= Tema ========= */
(function(){
  const saved = localStorage.getItem('qta-theme') || 'ocean';
  if (saved && saved !== 'ocean') document.body.setAttribute('data-theme', saved);
  document.querySelectorAll('.theme-switch [data-theme]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const t = btn.getAttribute('data-theme');
      if (t==='ocean') document.body.removeAttribute('data-theme');
      else document.body.setAttribute('data-theme', t);
      localStorage.setItem('qta-theme', t);
    });
  });
})();

/* ========= State ========= */
let camQr = null;           // Html5Qrcode instance for camera
let fileQr = null;          // Html5Qrcode instance for images
let isCamRunning = false;
let isBusy = false;         // debounce verification
let lastPayload = '';

const statusBanner = document.querySelector('.status-banner');
const statusText   = document.getElementById('statusText');
const statusIcon   = document.getElementById('statusIcon');

function setStatus(label, variant){
  const chip = document.getElementById('statusChip');
  chip.className = 'badge text-bg-'+variant;
  chip.textContent = label;
  if (statusText) statusText.textContent = (label === '—' ? 'Gati për verifikim' : label);
  statusBanner?.classList.remove('status-valid','status-invalid');
  statusIcon?.classList.remove('bi-check2-circle','bi-x-circle','bi-shield-lock');
  if (variant==='success'){ statusBanner?.classList.add('status-valid');  statusIcon?.classList.add('bi-check2-circle'); }
  else if (variant==='danger'){ statusBanner?.classList.add('status-invalid'); statusIcon?.classList.add('bi-x-circle'); }
  else { statusIcon?.classList.add('bi-shield-lock'); }
}

/* ========= Kamera (Html5Qrcode low-level API) ========= */
async function startCamera(){
  if (isCamRunning) return;
  const readerEl = document.getElementById('reader');
  if (!readerEl) return;

  try {
    if (!camQr) camQr = new Html5Qrcode('reader');

    const config = {
      fps: 10,
      qrbox: function(viewfinderWidth, viewfinderHeight) {
        const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
        const size = Math.max(220, Math.min(320, Math.floor(minEdge * 0.8)));
        return { width: size, height: size };
      },
      aspectRatio: 1.0,
      rememberLastUsedCamera: true
    };

    isBusy = false;
    await camQr.start({ facingMode: "environment" }, config, onDecode, onDecodeError);
    isCamRunning = true;
  } catch (err){
    readerEl.innerHTML = '<div class="text-muted p-3">Kamera nuk është në dispozicion ose nuk u lejua.</div>';
    isCamRunning = false;
  }
}

async function stopCamera(){
  if (camQr && isCamRunning){
    try { await camQr.stop(); } catch(e){}
    try { await camQr.clear(); } catch(e){}
  }
  isCamRunning = false;
}

function onDecode(decodedText){
  if (isBusy) return;              // debounce
  isBusy = true;
  lastPayload = decodedText;
  stopCamera();                    // mbyll kamerën që të mos dërgojë skanime të tjera
  setStatus('Duke verifikuar...', 'secondary');
  doVerify(decodedText).finally(()=>{ isBusy = false; });
}

function onDecodeError(_err){ /* silent */ }

/* ========= Upload / DragDrop / Paste ========= */
function ensureFileReader(){
  if (!fileQr){
    try{ fileQr = new Html5Qrcode('file-reader'); }catch(e){}
  }
}
function setUploadMsg(text, good=false){
  const m = document.getElementById('uploadMsg'); if (!m) return;
  m.className = good ? 'small text-success ms-auto' : 'small text-danger ms-auto';
  m.textContent = text || '';
}
async function handleFile(file){
  if(!file){ setUploadMsg('Asnjë skedar.'); return; }
  ensureFileReader();
  try{
    setUploadMsg('Duke lexuar QR nga imazhi…', true);
    const decodedText = await fileQr.scanFile(file, true);
    lastPayload = decodedText;
    document.getElementById('manualPayload').value = decodedText;
    setStatus('Duke verifikuar...', 'secondary');
    await doVerify(decodedText);
    setUploadMsg('U lexua me sukses.', true);
  } catch(err){
    console.error(err);
    setUploadMsg('Nuk u gjet QR në këtë imazh. Provo me foto më të qartë.');
  }
}

document.getElementById('fileInput')?.addEventListener('change', (ev)=>{ handleFile(ev.target.files?.[0]); });
const dz = document.getElementById('dropzone');
dz?.addEventListener('dragover', (e)=>{ e.preventDefault(); dz.classList.add('dragover'); });
dz?.addEventListener('dragleave', ()=> dz.classList.remove('dragover'));
dz?.addEventListener('drop', (e)=>{ e.preventDefault(); dz.classList.remove('dragover'); handleFile(e.dataTransfer.files?.[0]); });

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

/* ========= Manual verify ========= */
async function doVerify(payload){
  try{
    const res = await fetch('verify.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({action:'verify', payload})
    });
    const json = await res.json();
    renderResult(json, payload);
  }catch(e){
    setStatus('Gabim', 'danger');
    const body = document.getElementById('resultBody');
    body.innerHTML = `<div class="alert alert-danger">Gabim gjatë verifikimit. Provo përsëri.</div>`;
  }
}

function escapeHtml(s){ const d=document.createElement('div'); d.innerText=s||''; return d.innerHTML; }

function renderResult(json, payloadUsed=''){
  const card = document.getElementById('resultCard');
  const body = document.getElementById('resultBody');
  if(!json || json.ok!==true){
    setStatus('Gabim', 'danger');
    card.classList.remove('status-valid','status-invalid');
    body.innerHTML = `<div class="alert alert-danger">Gabim gjatë verifikimit. Provo përsëri.</div>`;
    return;
  }
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

/* ========= Butonat ========= */
document.getElementById('btnVerify')?.addEventListener('click', ()=>{
  const v = document.getElementById('manualPayload').value.trim();
  if (!v){ document.getElementById('manualPayload').focus(); return; }
  lastPayload = v;
  setStatus('Duke verifikuar...', 'secondary');
  doVerify(v);
});

document.getElementById('btnPaste')?.addEventListener('click', async ()=>{
  try{
    const t = await navigator.clipboard.readText();
    if (t){ document.getElementById('manualPayload').value = t; lastPayload = t; }
  }catch(e){}
});

document.getElementById('btnClear')?.addEventListener('click', async ()=>{
  document.getElementById('manualPayload').value = '';
  lastPayload = '';
  setStatus('—','secondary');
  document.getElementById('resultBody').innerHTML = '<div class="text-muted">Skanoni QR, ngarkoni foto ose ngjisni vlerën për verifikim. Rezultati do të shfaqet këtu.</div>';
  document.getElementById('resultCard').classList.remove('status-valid','status-invalid');
  const activePane = document.querySelector('.tab-pane.active')?.id;
  if (activePane === 'pane-camera'){ await stopCamera(); startCamera(); }
});

/* Restart / Stop camera */
document.getElementById('btnRestartCam')?.addEventListener('click', async ()=>{ await stopCamera(); startCamera(); });
document.getElementById('btnStopCam')?.addEventListener('click', async ()=>{ await stopCamera(); });

/* Share link */
function buildShareFromPayload(raw){
  const s = String(raw||'');
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

/* ========= Tab events: start/stop camera sipas tab-it ========= */
/* përdor listener në dokument (ngjarja "shown.bs.tab" bubblohet) që të funksionojë kudo */
document.addEventListener('shown.bs.tab', async (e)=>{
  const target = e.target?.getAttribute('data-bs-target');
  if (target === '#pane-camera'){ await stopCamera(); startCamera(); }
  else { await stopCamera(); }
});

/* Mbyll kamerën kur del nga faqa / ose tab-i bëhet i padukshëm */
window.addEventListener('beforeunload', ()=>{ try{ camQr?.stop(); camQr?.clear(); }catch(e){} });
document.addEventListener('visibilitychange', async ()=>{ if (document.hidden) await stopCamera(); });

/* ========= Start ========= */
window.addEventListener('load', ()=>{
  // nis kamerën vetëm nëse pamja default është “Kamerë”
  const active = document.querySelector('#pane-camera');
  if (active && active.classList.contains('active')) startCamera();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

</body>
</html>
