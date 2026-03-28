<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* Helper për escape */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* Nëse je i loguar, lexo përdoruesin aktual (vetëm për personalizim UX) */
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
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$role = strtolower((string)($currentUser['role_name'] ?? ''));
$isAdmin   = ($role === 'administrator');
$isAgency  = ($role === 'agency');
$isStudent = ($role === 'student');

require_once __DIR__ . '/navbarMain.php';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QTA – Portali i Kurseve Profesionale</title>
  <link rel="icon" type="image/svg+xml" href="image/logoPNG - Copy.png">

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <style>
    :root{
      --bg: #f6f8ff;
      --bg2:#eef2ff;
      --card:#ffffff;
      --text:#0b1220;
      --muted:#667085;
      --muted2:#94a3b8;
      --primary:#2563eb;
      --primary2:#4f46e5;
      --accent:#0ea5e9;
      --success:#10b981;
      --danger:#ef4444;
      --ring: rgba(37,99,235,.16);
      --shadow: 0 18px 44px rgba(2,6,23,.12);
      --shadow2: 0 10px 26px rgba(2,6,23,.10);
      --radius: 18px;
    }

    html[data-theme="dark"]{
      --bg:#070b14;
      --bg2:#0b1220;
      --card:#0f172a;
      --text:#e5e7eb;
      --muted:#a7b0c0;
      --muted2:#7c879b;
      --ring: rgba(99,102,241,.18);
      --shadow: 0 20px 54px rgba(0,0,0,.45);
      --shadow2: 0 12px 32px rgba(0,0,0,.35);
    }

    body{
      background: radial-gradient(1200px 520px at 15% -12%, rgba(37,99,235,.18), transparent 60%),
                  radial-gradient(900px 420px at 90% -10%, rgba(99,102,241,.16), transparent 55%),
                  linear-gradient(180deg, var(--bg) 0%, var(--bg2) 60%, var(--bg) 100%);
      color: var(--text);
    }

    .container-max { max-width: 1140px; }

    .soft-card{
      background: var(--card);
      border: 1px solid rgba(148,163,184,.18);
      border-radius: var(--radius);
      box-shadow: var(--shadow2);
    }

    .hero{
      position:relative;
      overflow:hidden;
      padding: 76px 0 58px;
    }
    .hero::before{
      content:"";
      position:absolute;
      inset:-2px;
      background:
        radial-gradient(900px 360px at 15% 0%, rgba(14,165,233,.25), transparent 60%),
        radial-gradient(800px 300px at 85% 10%, rgba(99,102,241,.24), transparent 60%),
        linear-gradient(135deg, rgba(37,99,235,.08), rgba(79,70,229,.06));
      pointer-events:none;
      filter: blur(0px);
    }
    .hero-inner{
      position:relative;
      z-index:1;
    }

    .badge-chip{
      border: 1px solid rgba(148,163,184,.25);
      background: rgba(255,255,255,.55);
      color: var(--text);
    }
    html[data-theme="dark"] .badge-chip{
      background: rgba(15,23,42,.6);
    }

    .headline{
      letter-spacing: -0.02em;
    }

    .hero-cta .btn{
      padding: .75rem 1rem;
      border-radius: 14px;
    }

    .hero-visual{
      border-radius: calc(var(--radius) + 6px);
      background: linear-gradient(135deg, rgba(37,99,235,.12), rgba(14,165,233,.08));
      border: 1px solid rgba(148,163,184,.22);
      box-shadow: var(--shadow);
      overflow:hidden;
      position:relative;
    }
    .hero-visual .grid{
      position:absolute;
      inset:0;
      background-image:
        linear-gradient(to right, rgba(148,163,184,.14) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(148,163,184,.14) 1px, transparent 1px);
      background-size: 46px 46px;
      mask-image: radial-gradient(circle at 30% 25%, black 0 55%, transparent 80%);
      opacity:.65;
    }
    .hero-visual .panel{
      position:relative;
      padding: 18px;
    }
    .mini-pill{
      display:inline-flex;
      gap:.5rem;
      align-items:center;
      padding:.35rem .6rem;
      border-radius: 999px;
      border:1px solid rgba(148,163,184,.22);
      background: rgba(255,255,255,.6);
      font-size: .85rem;
      color: var(--text);
    }
    html[data-theme="dark"] .mini-pill{ background: rgba(15,23,42,.65); }

    .feature{
      transition: transform .18s ease, box-shadow .18s ease;
      height:100%;
    }
    .feature:hover{
      transform: translateY(-6px);
      box-shadow: var(--shadow);
    }
    .iconbox{
      width:54px;height:54px;
      border-radius: 16px;
      display:flex;align-items:center;justify-content:center;
      background: rgba(37,99,235,.10);
      border: 1px solid rgba(37,99,235,.18);
      color: var(--primary);
      flex:0 0 auto;
    }

    .section-title{
      letter-spacing:-0.01em;
    }
    .text-muted2{ color: var(--muted); }

    .tabs-card .nav-pills .nav-link{
      border-radius: 12px;
      color: var(--text);
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.55);
      margin-right: .5rem;
    }
    html[data-theme="dark"] .tabs-card .nav-pills .nav-link{ background: rgba(15,23,42,.65); }
    .tabs-card .nav-pills .nav-link.active{
      background: linear-gradient(135deg, rgba(37,99,235,.95), rgba(79,70,229,.95));
      border-color: rgba(37,99,235,.35);
      color:#fff;
    }

    .stepper .step-btn{
      width:100%;
      text-align:left;
      border-radius: 14px;
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.55);
      padding: 12px 12px;
      display:flex;
      gap:.75rem;
      align-items:flex-start;
      transition: .15s ease;
    }
    html[data-theme="dark"] .stepper .step-btn{ background: rgba(15,23,42,.65); }
    .stepper .step-btn:hover{ transform: translateY(-2px); box-shadow: var(--shadow2); }
    .stepper .step-btn.active{
      border-color: rgba(37,99,235,.35);
      background: rgba(37,99,235,.08);
      box-shadow: 0 0 0 10px var(--ring);
    }
    .step-index{
      width:32px;height:32px;border-radius: 12px;
      display:flex;align-items:center;justify-content:center;
      background: rgba(37,99,235,.10);
      border:1px solid rgba(37,99,235,.18);
      color: var(--primary);
      font-weight: 700;
      flex: 0 0 auto;
    }

    .faq .accordion-button{
      border-radius: 14px !important;
      box-shadow:none !important;
      background: rgba(255,255,255,.55);
      border: 1px solid rgba(148,163,184,.18);
      color: var(--text);
    }
    html[data-theme="dark"] .faq .accordion-button{ background: rgba(15,23,42,.65); }
    .faq .accordion-item{
      background: transparent;
      border:0;
      margin-bottom: 12px;
    }
    .faq .accordion-body{
      background: var(--card);
      border: 1px solid rgba(148,163,184,.18);
      border-radius: 14px;
      margin-top: 8px;
    }

    .cta-band{
      background: linear-gradient(135deg, rgba(37,99,235,.92), rgba(79,70,229,.92));
      border-radius: calc(var(--radius) + 6px);
      color:#fff;
      overflow:hidden;
      position:relative;
      box-shadow: var(--shadow);
    }
    .cta-band::after{
      content:"";
      position:absolute;
      inset:-80px -120px auto auto;
      width:320px;height:320px;border-radius:50%;
      background: radial-gradient(circle at 30% 30%, rgba(255,255,255,.35), transparent 65%);
      filter: blur(18px);
      opacity:.9;
    }

    .floating-actions{
      position: fixed;
      right: 18px;
      bottom: 18px;
      z-index: 1040;
      display:flex;
      flex-direction:column;
      gap:10px;
    }
    .fab{
      width:48px;height:48px;
      border-radius: 16px;
      border: 1px solid rgba(148,163,184,.20);
      background: var(--card);
      color: var(--text);
      box-shadow: var(--shadow2);
      display:flex;align-items:center;justify-content:center;
      cursor:pointer;
      transition: .15s ease;
    }
    .fab:hover{ transform: translateY(-2px); }

    @media (max-width: 991px){
      .hero{ padding: 56px 0 44px; }
    }
  </style>
</head>

<body>

<!-- HERO -->
<section class="hero">
  <div class="container container-max hero-inner">
    <div class="row align-items-center g-4">
      <div class="col-lg-6">
        <span class="badge badge-chip rounded-pill mb-3">
          <i class="bi bi-shield-check me-1"></i> Portali QTA për menaxhim kursesh & certifikatash
        </span>

        <h1 class="display-5 fw-bold headline mb-3">
          Një përvojë moderne për regjistrim, ndjekje dhe verifikim të certifikatave
        </h1>

        <p class="lead mb-4 text-muted2">
          Fokus te qartësia, siguria dhe proceset: nga regjistrimi i kursantëve deri te certifikata me QR dhe verifikimi publik.
        </p>

        <div class="d-flex flex-wrap gap-2 hero-cta">
          <?php if ($currentUser): ?>
            <a href="<?= $isAdmin ? 'dashboard_admin.php' : ($isAgency ? 'dashboard_agency.php' : 'dashboard_student.php') ?>"
               class="btn btn-primary fw-semibold">
              <i class="bi bi-arrow-right-circle me-1"></i>
              Vazhdoni si <?= h($currentUser['full_name'] ?? 'përdorues') ?>
            </a>
            <a href="#explore" class="btn btn-outline-primary">
              <i class="bi bi-compass me-1"></i> Eksploro funksionet
            </a>
          <?php else: ?>
            <a href="selectProfile.php" class="btn btn-primary fw-semibold">
              <i class="bi bi-box-arrow-in-right me-1"></i> Hyr në sistem
            </a>
            <a href="verify.php" class="btn btn-outline-primary">
              <i class="bi bi-qr-code-scan me-1"></i> Verifiko certifikatën
            </a>
          <?php endif; ?>

          <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#demoModal">
            <i class="bi bi-play-circle me-1"></i> Shiko demo
          </button>

          <button type="button" class="btn btn-light" id="themeToggle" aria-label="Ndrysho temën">
            <i class="bi bi-moon-stars me-1"></i> Dark mode
          </button>
        </div>

        <div class="mt-4 d-flex flex-wrap gap-2">
          <span class="mini-pill"><i class="bi bi-lock"></i> Role & leje</span>
          <span class="mini-pill"><i class="bi bi-qr-code"></i> QR / Token unik</span>
          <span class="mini-pill"><i class="bi bi-journal-check"></i> Proces i standardizuar</span>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="hero-visual p-3">
          <div class="grid"></div>
          <div class="panel soft-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <div class="fw-semibold">Pamje “si produkt” (UX i orientuar te përdoruesi)</div>
                <div class="small text-muted2">Qartësi në hapëzimin e proceseve + akses i shpejtë në veprimet kryesore.</div>
              </div>
              <span class="badge text-bg-success"><i class="bi bi-check2-circle me-1"></i>Ready</span>
            </div>

            <div class="row g-3">
              <div class="col-6">
                <div class="soft-card p-3">
                  <div class="d-flex align-items-center gap-2">
                    <div class="iconbox" style="width:44px;height:44px;border-radius:14px;">
                      <i class="bi bi-person-plus"></i>
                    </div>
                    <div>
                      <div class="fw-semibold">Regjistrim</div>
                      <div class="small text-muted2">i strukturuar</div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-6">
                <div class="soft-card p-3">
                  <div class="d-flex align-items-center gap-2">
                    <div class="iconbox" style="width:44px;height:44px;border-radius:14px;background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.22);color:var(--success);">
                      <i class="bi bi-award"></i>
                    </div>
                    <div>
                      <div class="fw-semibold">Certifikata</div>
                      <div class="small text-muted2">me QR</div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-12">
                <div class="soft-card p-3">
                  <div class="d-flex align-items-start gap-3">
                    <div class="iconbox" style="background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.18);color:var(--danger);">
                      <i class="bi bi-shield-lock"></i>
                    </div>
                    <div>
                      <div class="fw-semibold mb-1">Siguri & verifikim publik</div>
                      <div class="small text-muted2">
                        Verifikimi nuk kërkon llogari: publik, i shpejtë, me të dhëna minimale dhe të kontrolluara.
                      </div>
                      <div class="mt-2 small">
                        <a href="#security" class="text-decoration-none">Shih standardet <i class="bi bi-arrow-right"></i></a>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- EXPLORE -->
<section id="explore" class="py-5">
  <div class="container container-max">
    <div class="text-center mb-4">
      <h2 class="fw-bold section-title">Çfarë e bën QTA të “lehtë për t’u përdorur”</h2>
      <p class="text-muted2 mb-0">Strukturë e qartë, ndërveprim i thjeshtë, dhe përqendrim te veprimet kryesore.</p>
    </div>

    <div class="row g-4">
      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 feature">
          <div class="d-flex gap-3">
            <div class="iconbox"><i class="bi bi-diagram-3"></i></div>
            <div>
              <div class="fw-semibold mb-1">Proces i standardizuar</div>
              <div class="text-muted2 small">Regjistrim → ndjekje → certifikim → verifikim. Çdo hap i qartë, pa konfuzion.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 feature">
          <div class="d-flex gap-3">
            <div class="iconbox" style="background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.22);color:var(--success);">
              <i class="bi bi-check2-square"></i>
            </div>
            <div>
              <div class="fw-semibold mb-1">Kontroll & auditim</div>
              <div class="text-muted2 small">Veprimet kritike janë të menduara për gjurmueshmëri dhe përgjegjësi.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 feature">
          <div class="d-flex gap-3">
            <div class="iconbox" style="background:rgba(14,165,233,.12);border-color:rgba(14,165,233,.20);color:var(--accent);">
              <i class="bi bi-lightning-charge"></i>
            </div>
            <div>
              <div class="fw-semibold mb-1">UX i shpejtë</div>
              <div class="text-muted2 small">Më pak klikime, më shumë “quick actions”, dhe navigim i parashikueshëm.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 feature">
          <div class="d-flex gap-3">
            <div class="iconbox" style="background:rgba(99,102,241,.12);border-color:rgba(99,102,241,.20);color:var(--primary2);">
              <i class="bi bi-person-badge"></i>
            </div>
            <div>
              <div class="fw-semibold mb-1">Role të qarta</div>
              <div class="text-muted2 small">Administrator / Agjenci / Student – çdo rol sheh vetëm atë që i duhet.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 feature">
          <div class="d-flex gap-3">
            <div class="iconbox" style="background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.18);color:var(--danger);">
              <i class="bi bi-shield-exclamation"></i>
            </div>
            <div>
              <div class="fw-semibold mb-1">Parandalim abuzimi</div>
              <div class="text-muted2 small">Token unik + verifikim publik ndihmon të zbulohen certifikatat e rreme.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 feature">
          <div class="d-flex gap-3">
            <div class="iconbox" style="background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.20);color:#f59e0b;">
              <i class="bi bi-ui-checks-grid"></i>
            </div>
            <div>
              <div class="fw-semibold mb-1">Komponentë modernë UI</div>
              <div class="text-muted2 small">Tabs, modal, stepper, FAQ accordion, dark mode — për një ndjesi moderne.</div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- HOW IT WORKS (Interactive Stepper) -->
<section class="py-5">
  <div class="container container-max">
    <div class="row g-4 align-items-start">
      <div class="col-lg-5">
        <h2 class="fw-bold section-title mb-2">Si funksionon në praktikë</h2>
        <p class="text-muted2">Zgjidh një hap më poshtë dhe shih shpjegimin. Kjo pjesë është “interaktive” për përdoruesin.</p>

        <div class="stepper d-grid gap-2 mt-3">
          <button class="step-btn active" type="button" data-step="1">
            <span class="step-index">1</span>
            <span>
              <span class="fw-semibold d-block">Krijo profilin & rolin</span>
              <span class="small text-muted2">Përcakto lejet dhe qasjen.</span>
            </span>
          </button>

          <button class="step-btn" type="button" data-step="2">
            <span class="step-index">2</span>
            <span>
              <span class="fw-semibold d-block">Regjistro kursantin</span>
              <span class="small text-muted2">Të dhëna të sakta dhe të verifikueshme.</span>
            </span>
          </button>

          <button class="step-btn" type="button" data-step="3">
            <span class="step-index">3</span>
            <span>
              <span class="fw-semibold d-block">Ndjek modulët</span>
              <span class="small text-muted2">Shënime, rezultate, historik.</span>
            </span>
          </button>

          <button class="step-btn" type="button" data-step="4">
            <span class="step-index">4</span>
            <span>
              <span class="fw-semibold d-block">Gjenero certifikatën</span>
              <span class="small text-muted2">Token unik + QR.</span>
            </span>
          </button>

          <button class="step-btn" type="button" data-step="5">
            <span class="step-index">5</span>
            <span>
              <span class="fw-semibold d-block">Verifikim publik</span>
              <span class="small text-muted2">Pa llogari, pa barriera.</span>
            </span>
          </button>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="soft-card p-4">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold"><i class="bi bi-compass me-1"></i> Shpjegim hap-pas-hapi</div>
            <span class="badge text-bg-primary" id="stepBadge">Hapi 1</span>
          </div>

          <div class="progress mb-3" style="height:10px;border-radius:999px;">
            <div class="progress-bar" id="stepProgress" role="progressbar" style="width: 20%"></div>
          </div>

          <div id="stepContent" class="text-muted2">
            <h5 class="fw-bold text-body">Roli përcakton përvojën</h5>
            <p class="mb-0">
              QTA ndërton UX sipas rolit. Administratori sheh menaxhimin e plotë, agjencia menaxhon kursantët e vet,
              studenti sheh të dhënat personale dhe progresin.
            </p>
          </div>

          <hr class="my-4">

          <div class="row g-3">
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-hand-index-thumb me-1"></i> Parim UX</div>
                <div class="small text-muted2">“Një ekran = një vendim.” Minimalizojmë konfuzionin dhe shpërndarjen e vëmendjes.</div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-clipboard2-check me-1"></i> Parim Procesi</div>
                <div class="small text-muted2">Çdo certifikatë ka një identifikues unik, i verifikueshëm publikisht.</div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</section>

<!-- MODULES (Interactive Tabs) -->
<section class="py-5">
  <div class="container container-max">
    <div class="soft-card p-4 tabs-card">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div>
          <h2 class="fw-bold section-title mb-1">Modulet kryesore</h2>
          <div class="text-muted2">Këto janë “pikat e forta” që i shpjegojnë përdoruesit çfarë bën sistemi.</div>
        </div>
        <a href="verify.php" class="btn btn-outline-primary">
          <i class="bi bi-qr-code-scan me-1"></i> Verifikim publik
        </a>
      </div>

      <ul class="nav nav-pills flex-wrap mb-3" id="modTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="t1-tab" data-bs-toggle="pill" data-bs-target="#t1" type="button" role="tab">
            Regjistrim
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="t2-tab" data-bs-toggle="pill" data-bs-target="#t2" type="button" role="tab">
            Ndjekje & progres
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="t3-tab" data-bs-toggle="pill" data-bs-target="#t3" type="button" role="tab">
            Certifikata + QR
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="t4-tab" data-bs-toggle="pill" data-bs-target="#t4" type="button" role="tab">
            Raporte
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="t5-tab" data-bs-toggle="pill" data-bs-target="#t5" type="button" role="tab">
            Role & leje
          </button>
        </li>
      </ul>

      <div class="tab-content">
        <div class="tab-pane fade show active" id="t1" role="tabpanel">
          <div class="row g-4 align-items-center">
            <div class="col-lg-7">
              <h5 class="fw-bold">Regjistrim i strukturuar dhe i verifikueshëm</h5>
              <ul class="text-muted2 mb-3">
                <li>Format të qarta me validime (për minimizim gabimesh).</li>
                <li>Identifikues të qëndrueshëm (p.sh. nr. personal / amzë) për kërkim të shpejtë.</li>
                <li>Historik ndryshimesh (kurdoherë e di “çfarë ndryshoi dhe kush e bëri”).</li>
              </ul>
              <div class="soft-card p-3">
                <div class="fw-semibold"><i class="bi bi-lightbulb me-1"></i> Ide UI</div>
                <div class="small text-muted2">“Quick add” për student/agjenci + “wizard” me 3 hapa për regjistrim më të lehtë.</div>
              </div>
            </div>
            <div class="col-lg-5">
              <div class="soft-card p-4">
                <div class="fw-semibold mb-2"><i class="bi bi-window-sidebar me-1"></i> Pamje e rekomanduar</div>
                <div class="small text-muted2 mb-3">Sidebar → lista → detajet. Kjo e bën sistemin të ndihet si aplikacion.</div>
                <div class="d-grid gap-2">
                  <span class="mini-pill"><i class="bi bi-search"></i> Kërkim në një fushë</span>
                  <span class="mini-pill"><i class="bi bi-funnel"></i> Filtra inteligjentë</span>
                  <span class="mini-pill"><i class="bi bi-pencil-square"></i> Edit inline</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="t2" role="tabpanel">
          <div class="row g-4 align-items-center">
            <div class="col-lg-7">
              <h5 class="fw-bold">Ndjekje progresi me sinjale të thjeshta</h5>
              <p class="text-muted2 mb-3">
                Në vend të “tabelave të gjata”, UX fokusohet te sinjale: status, përfundime, module kryesore, dhe njoftime.
              </p>
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="soft-card p-3">
                    <div class="fw-semibold mb-1">Status</div>
                    <div class="small text-muted2">Aktiv / Në pritje / Përfunduar</div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="soft-card p-3">
                    <div class="fw-semibold mb-1">Timeline</div>
                    <div class="small text-muted2">Hapat e kryer sipas datave</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-lg-5">
              <div class="soft-card p-4">
                <div class="fw-semibold mb-2"><i class="bi bi-bell me-1"></i> Njoftime të dobishme</div>
                <ul class="small text-muted2 mb-0">
                  <li>“Ky student ka plotësuar modulin X”</li>
                  <li>“Mungon dokumenti Y”</li>
                  <li>“Gati për certifikim”</li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="t3" role="tabpanel">
          <div class="row g-4 align-items-center">
            <div class="col-lg-7">
              <h5 class="fw-bold">Certifikata me QR: e thjeshtë për t’u kontrolluar</h5>
              <ul class="text-muted2 mb-3">
                <li>Token unik për çdo certifikatë.</li>
                <li>Verifikim publik me të dhëna minimale (p.sh. emër, mbiemër, modul).</li>
                <li>Shënim i qartë “E vlefshme / E pavlefshme / Nuk u gjet”.</li>
              </ul>
              <a href="verify.php" class="btn btn-primary"><i class="bi bi-qr-code-scan me-1"></i> Hap verifikimin</a>
            </div>
            <div class="col-lg-5">
              <div class="soft-card p-4">
                <div class="fw-semibold mb-2">Praktikë UX</div>
                <div class="small text-muted2">
                  Në verifikim, shfaq vetëm çfarë duhet. Mos e “derdh” databazën. Përdor “cards” me etiketa të qarta.
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="t4" role="tabpanel">
          <div class="row g-4 align-items-center">
            <div class="col-lg-7">
              <h5 class="fw-bold">Raporte që lexohen shpejt</h5>
              <p class="text-muted2 mb-3">
                Në vend të shumë numrave, raporte “të përdorshme”: lista të filtrueshme, eksport, dhe përmbledhje me KPI të brendshme.
                (Këtu në homepage nuk shfaqim statistika nga DB – vetëm shpjegojmë vlerën.)
              </p>
              <div class="soft-card p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-file-earmark-arrow-down me-1"></i> Eksport</div>
                <div class="small text-muted2">PDF / CSV – sipas rolit dhe nevojës.</div>
              </div>
            </div>
            <div class="col-lg-5">
              <div class="soft-card p-4">
                <div class="fw-semibold mb-2">UX rekomandim</div>
                <div class="small text-muted2">
                  Një panel “filters first” (filtrat sipër) + tabela poshtë + veprimet “Export / Print” gjithmonë në të njëjtin vend.
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="t5" role="tabpanel">
          <div class="row g-4 align-items-center">
            <div class="col-lg-7">
              <h5 class="fw-bold">Role & leje: më pak rrezik, më shumë qartësi</h5>
              <ul class="text-muted2 mb-3">
                <li>Administrator: menaxhim i plotë + kontrolle.</li>
                <li>Agjenci: vetëm studentët e vet, pa akses në të tjerë.</li>
                <li>Student: vetëm të dhënat personale dhe rezultati.</li>
              </ul>
              <div class="soft-card p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-shield-lock me-1"></i> Praktikë sigurie</div>
                <div class="small text-muted2">Parimi “least privilege”: çdo rol merr minimumin e nevojshëm.</div>
              </div>
            </div>
            <div class="col-lg-5">
              <div class="soft-card p-4">
                <div class="fw-semibold mb-2">Për përdoruesin</div>
                <div class="small text-muted2">Roli i tij e bën UI-n “më të pastër” – më pak menu, më pak gabime.</div>
              </div>
            </div>
          </div>
        </div>

      </div><!-- tab-content -->
    </div>
  </div>
</section>

<!-- LOGIN / ROLE CHOOSER -->
<section class="py-5">
  <div class="container container-max">
    <div class="text-center mb-4">
      <h2 class="fw-bold section-title">Fillo shpejt</h2>
      <p class="text-muted2 mb-0">Zgjidh rolin dhe futu direkt te veprimet kryesore.</p>
    </div>

    <div class="row g-4 justify-content-center">
      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 h-100">
          <div class="d-flex align-items-center gap-3 mb-2">
            <div class="iconbox"><i class="bi bi-person-gear fs-5"></i></div>
            <div>
              <div class="fw-semibold">Administrator</div>
              <div class="small text-muted2">Menaxhim i plotë i sistemit</div>
            </div>
          </div>
          <ul class="small text-muted2 mb-4">
            <li>Studentë, agjenci, përdorues</li>
            <li>Certifikata, verifikim, auditim</li>
            <li>Raporte, eksport, kontroll</li>
          </ul>
          <a href="selectProfile.php" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr si administrator</a>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 h-100">
          <div class="d-flex align-items-center gap-3 mb-2">
            <div class="iconbox" style="background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.22);color:var(--success);">
              <i class="bi bi-building fs-5"></i>
            </div>
            <div>
              <div class="fw-semibold">Agjencia</div>
              <div class="small text-muted2">Menaxho kursantët e tu</div>
            </div>
          </div>
          <ul class="small text-muted2 mb-4">
            <li>Regjistrim dhe ndjekje e kursantëve</li>
            <li>Dokumente dhe status</li>
            <li>Raporte për grupin tënd</li>
          </ul>
          <a href="selectProfile.php" class="btn btn-success w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr si agjenci</a>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 h-100">
          <div class="d-flex align-items-center gap-3 mb-2">
            <div class="iconbox" style="background:rgba(14,165,233,.12);border-color:rgba(14,165,233,.20);color:var(--accent);">
              <i class="bi bi-mortarboard fs-5"></i>
            </div>
            <div>
              <div class="fw-semibold">Student</div>
              <div class="small text-muted2">Qasje te progresi & certifikata</div>
            </div>
          </div>
          <ul class="small text-muted2 mb-4">
            <li>Të dhëna personale</li>
            <li>Modulet dhe rezultatet</li>
            <li>Certifikata & verifikim</li>
          </ul>
          <a href="selectProfile.php" class="btn btn-info w-100 text-white"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr si student</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- SECURITY -->
<section id="security" class="py-5">
  <div class="container container-max">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <h2 class="fw-bold section-title mb-2">Siguri, privatësi, besueshmëri</h2>
        <p class="text-muted2">
          Homepage duhet t’i japë përdoruesit besim. Kjo pjesë e bën të qartë “si e mbron sistemi integritetin”.
        </p>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="soft-card p-3">
              <div class="fw-semibold mb-1"><i class="bi bi-key me-1"></i> Token unik</div>
              <div class="small text-muted2">Çdo certifikatë lidhet me identifikues unik, i verifikueshëm publikisht.</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="soft-card p-3">
              <div class="fw-semibold mb-1"><i class="bi bi-person-lock me-1"></i> Least privilege</div>
              <div class="small text-muted2">Roli sheh vetëm çfarë i duhet, duke ulur rrezikun operacional.</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="soft-card p-3">
              <div class="fw-semibold mb-1"><i class="bi bi-shield-check me-1"></i> Validime</div>
              <div class="small text-muted2">Kontrolle të dhënash për të shmangur gabime dhe duplikime.</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="soft-card p-3">
              <div class="fw-semibold mb-1"><i class="bi bi-clipboard-data me-1"></i> Gjurmueshmëri</div>
              <div class="small text-muted2">Veprimet kyçe mund të auditohen (kur është e aktivizuar).</div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="cta-band p-4 p-lg-5">
          <div class="position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-2">Gati për ta përdorur?</h3>
            <p class="mb-4" style="opacity:.92;">
              Hyr në sistem ose provo verifikimin publik të certifikatës. Homepage duhet të shtyjë përdoruesin te veprimi.
            </p>
            <div class="d-flex flex-wrap gap-2">
              <a href="selectProfile.php" class="btn btn-light fw-semibold"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr në sistem</a>
              <a href="verify.php" class="btn btn-outline-light"><i class="bi bi-qr-code-scan me-1"></i> Verifiko certifikatën</a>
              <a href="#faq" class="btn btn-outline-light"><i class="bi bi-question-circle me-1"></i> Pyetje të shpeshta</a>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- FAQ -->
<section id="faq" class="py-5">
  <div class="container container-max">
    <div class="text-center mb-4">
      <h2 class="fw-bold section-title">Pyetje të shpeshta</h2>
      <p class="text-muted2 mb-0">Për t’i hequr “frenat” e përdoruesit para se të hyjë në sistem.</p>
    </div>

    <div class="faq accordion" id="faqAcc">
      <div class="accordion-item">
        <h2 class="accordion-header" id="q1">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a1">
            A mund të verifikoj një certifikatë pa llogari?
          </button>
        </h2>
        <div id="a1" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
          <div class="accordion-body text-muted2">
            Po. Verifikimi publik synon të jetë i shpejtë dhe i thjeshtë: skanon QR ose fut tokenin dhe merr rezultatin bazik.
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header" id="q2">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2">
            Çfarë shfaqet gjatë verifikimit?
          </button>
        </h2>
        <div id="a2" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
          <div class="accordion-body text-muted2">
            Vetëm të dhëna minimale dhe të kontrolluara (p.sh. emër, mbiemër, module kryesore, status). Qëllimi është privatësia + besueshmëria.
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header" id="q3">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3">
            Pse rolet janë të rëndësishme?
          </button>
        </h2>
        <div id="a3" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
          <div class="accordion-body text-muted2">
            Rolet reduktojnë rrezikun dhe thjeshtojnë UI-n. Secili sheh vetëm çfarë i duhet, duke ulur gabimet dhe konfuzionin.
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- Floating actions -->
<div class="floating-actions" aria-hidden="false">
  <button class="fab" id="goTop" title="Shko lart" aria-label="Shko lart">
    <i class="bi bi-arrow-up"></i>
  </button>
  <a class="fab text-decoration-none" href="verify.php" title="Verifiko certifikatën" aria-label="Verifiko certifikatën">
    <i class="bi bi-qr-code-scan"></i>
  </a>
</div>

<!-- Demo modal -->
<div class="modal fade" id="demoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content soft-card">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-play-circle me-1"></i> Demo e shkurtër</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="soft-card p-4">
          <div class="fw-semibold mb-2">Ide praktike</div>
          <p class="text-muted2 mb-3">
            Këtu mund të vendosësh një video (YouTube/MP4) ose 3–4 screenshot-e me “carousel”.
            Për momentin është placeholder për të mos u varur nga asset-e të jashtme.
          </p>
          <div class="row g-3">
            <div class="col-md-4">
              <div class="soft-card p-3 text-center">
                <i class="bi bi-ui-checks-grid fs-1"></i>
                <div class="fw-semibold mt-2">UI i pastër</div>
                <div class="small text-muted2">Komponentë modernë</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="soft-card p-3 text-center">
                <i class="bi bi-qr-code fs-1"></i>
                <div class="fw-semibold mt-2">QR Verifikim</div>
                <div class="small text-muted2">Publik & i shpejtë</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="soft-card p-3 text-center">
                <i class="bi bi-shield-lock fs-1"></i>
                <div class="fw-semibold mt-2">Siguri</div>
                <div class="small text-muted2">Role + audit</div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0">
        <a href="selectProfile.php" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr në sistem</a>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Smooth scroll for internal anchors
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', (e) => {
      const id = a.getAttribute('href');
      if (!id || id === '#') return;
      const el = document.querySelector(id);
      if (!el) return;
      e.preventDefault();
      el.scrollIntoView({behavior:'smooth', block:'start'});
    });
  });

  // Back to top
  const goTop = document.getElementById('goTop');
  goTop?.addEventListener('click', ()=> window.scrollTo({top:0, behavior:'smooth'}));

  // Theme toggle (dark/light) with localStorage
  const root = document.documentElement;
  const themeBtn = document.getElementById('themeToggle');
  const setTheme = (t) => {
    root.setAttribute('data-theme', t);
    localStorage.setItem('qta_theme', t);
    if (themeBtn){
      const isDark = (t === 'dark');
      themeBtn.innerHTML = isDark
        ? '<i class="bi bi-sun me-1"></i> Light mode'
        : '<i class="bi bi-moon-stars me-1"></i> Dark mode';
    }
  };
  const saved = localStorage.getItem('qta_theme');
  if (saved === 'dark' || saved === 'light') setTheme(saved);
  else setTheme('light');

  themeBtn?.addEventListener('click', () => {
    const cur = root.getAttribute('data-theme') || 'light';
    setTheme(cur === 'dark' ? 'light' : 'dark');
  });

  // Stepper interaction
  const stepBtns = document.querySelectorAll('.stepper .step-btn');
  const stepBadge = document.getElementById('stepBadge');
  const stepProgress = document.getElementById('stepProgress');
  const stepContent = document.getElementById('stepContent');

  const steps = {
    1: {
      title: 'Roli përcakton përvojën',
      body: 'QTA ndërton UX sipas rolit. Administratori sheh menaxhimin e plotë, agjencia menaxhon kursantët e vet, studenti sheh të dhënat personale dhe progresin.'
    },
    2: {
      title: 'Regjistrim pa konfuzion',
      body: 'Regjistrimi është i ndarë në fushat thelbësore me validime. Qëllimi: të shmangen gabimet dhe duplikimet, dhe të jetë kërkimi i menjëhershëm.'
    },
    3: {
      title: 'Ndjekje me sinjale të qarta',
      body: 'Në vend të shumë tabelave, UX përdor status, timeline dhe njoftime. Përdoruesi kupton menjëherë çfarë mungon dhe çfarë është gati.'
    },
    4: {
      title: 'Certifikim i besueshëm',
      body: 'Gjenerimi i certifikatës lidhet me token unik dhe QR. Kjo e bën verifikimin të thjeshtë për publikun dhe të fortë kundër abuzimeve.'
    },
    5: {
      title: 'Verifikim publik në sekonda',
      body: 'Pa llogari, pa barriera. Fut tokenin ose skano QR dhe sistemi kthen përgjigje të qartë: e vlefshme / e pavlefshme / nuk u gjet.'
    }
  };

  const selectStep = (n) => {
    stepBtns.forEach(b => b.classList.toggle('active', b.getAttribute('data-step') === String(n)));
    if (stepBadge) stepBadge.textContent = 'Hapi ' + n;
    if (stepProgress) stepProgress.style.width = (n * 20) + '%';
    if (stepContent){
      const s = steps[n];
      stepContent.innerHTML = '<h5 class="fw-bold text-body">'+ s.title +'</h5><p class="mb-0">'+ s.body +'</p>';
    }
  };

  stepBtns.forEach(btn => {
    btn.addEventListener('click', () => selectStep(parseInt(btn.getAttribute('data-step') || '1', 10)));
  });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>
