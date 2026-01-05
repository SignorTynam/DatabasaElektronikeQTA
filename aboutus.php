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
  <title>Rreth Nesh – QTA</title>
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
    .container-max{ max-width: 1140px; }

    .soft-card{
      background: var(--card);
      border: 1px solid rgba(148,163,184,.18);
      border-radius: var(--radius);
      box-shadow: var(--shadow2);
    }

    .badge-chip{
      border: 1px solid rgba(148,163,184,.25);
      background: rgba(255,255,255,.55);
      color: var(--text);
    }
    html[data-theme="dark"] .badge-chip{
      background: rgba(15,23,42,.6);
    }

    /* HERO */
    .hero{
      position:relative;
      overflow:hidden;
      padding: 76px 0 56px;
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
    }
    .hero-inner{ position:relative; z-index:1; }
    .headline{ letter-spacing: -0.02em; }

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
    .hero-visual .panel{ position:relative; padding: 18px; }

    .iconbox{
      width:54px;height:54px;
      border-radius: 16px;
      display:flex;align-items:center;justify-content:center;
      background: rgba(37,99,235,.10);
      border: 1px solid rgba(37,99,235,.18);
      color: var(--primary);
      flex:0 0 auto;
    }

    .text-muted2{ color: var(--muted); }
    .section-title{ letter-spacing: -0.01em; }

    /* Tabs */
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

    /* Stepper */
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

    /* Team */
    .avatar{
      width:54px;height:54px;border-radius:16px;
      display:flex;align-items:center;justify-content:center;
      background: rgba(37,99,235,.10);
      border:1px solid rgba(37,99,235,.18);
      color: var(--primary);
      font-weight: 800;
      letter-spacing:.5px;
    }
    .tag{
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      padding:.3rem .55rem;
      border-radius:999px;
      border:1px solid rgba(148,163,184,.20);
      background: rgba(255,255,255,.55);
      color: var(--text);
      font-size:.85rem;
    }
    html[data-theme="dark"] .tag{ background: rgba(15,23,42,.65); }

    /* FAQ */
    .faq .accordion-button{
      border-radius: 14px !important;
      box-shadow:none !important;
      background: rgba(255,255,255,.55);
      border: 1px solid rgba(148,163,184,.18);
      color: var(--text);
    }
    html[data-theme="dark"] .faq .accordion-button{ background: rgba(15,23,42,.65); }
    .faq .accordion-item{ background: transparent; border:0; margin-bottom: 12px; }
    .faq .accordion-body{
      background: var(--card);
      border: 1px solid rgba(148,163,184,.18);
      border-radius: 14px;
      margin-top: 8px;
      color: var(--muted);
    }

    /* CTA band */
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

    /* Floating actions */
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
      text-decoration:none;
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
          <i class="bi bi-info-circle me-1"></i> Rreth QTA – kush jemi dhe si punojmë
        </span>

        <h1 class="display-5 fw-bold headline mb-3">
          Ndërtojmë aftësi praktike dhe certifikim të besueshëm për tregun e punës
        </h1>

        <p class="lead mb-4 text-muted2">
          QTA është një qendër trajnimi e orientuar te praktika: programe të strukturuara, role të qarta, procese të standardizuara
          dhe certifikata të verifikueshme publikisht me QR.
        </p>

        <div class="d-flex flex-wrap gap-2 hero-cta">
          <?php if ($currentUser): ?>
            <a href="<?= $isAdmin ? 'dashboard_admin.php' : ($isAgency ? 'dashboard_agency.php' : 'dashboard_student.php') ?>"
               class="btn btn-primary fw-semibold">
              <i class="bi bi-arrow-right-circle me-1"></i>
              Vazhdoni si <?= h($currentUser['full_name'] ?? 'përdorues') ?>
            </a>
          <?php else: ?>
            <a href="selectProfile.php" class="btn btn-primary fw-semibold">
              <i class="bi bi-box-arrow-in-right me-1"></i> Hyr në sistem
            </a>
          <?php endif; ?>

          <a href="verify.php" class="btn btn-outline-primary">
            <i class="bi bi-qr-code-scan me-1"></i> Verifiko certifikatën
          </a>

          <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#cultureModal">
            <i class="bi bi-play-circle me-1"></i> Si punojmë (demo)
          </button>

          <button type="button" class="btn btn-light" id="themeToggle" aria-label="Ndrysho temën">
            <i class="bi bi-moon-stars me-1"></i> Dark mode
          </button>
        </div>

        <div class="mt-4 d-flex flex-wrap gap-2">
          <span class="tag"><i class="bi bi-people"></i> Praktikë & mentoring</span>
          <span class="tag"><i class="bi bi-shield-check"></i> Standard & integritet</span>
          <span class="tag"><i class="bi bi-qr-code"></i> Verifikim publik</span>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="hero-visual p-3">
          <div class="grid"></div>
          <div class="panel soft-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <div class="fw-semibold">Çfarë mund të presësh nga ne</div>
                <div class="small text-muted2">Qartësi në proces + transparencë në certifikim.</div>
              </div>
              <span class="badge text-bg-success"><i class="bi bi-check2-circle me-1"></i> QTA</span>
            </div>

            <div class="row g-3">
              <div class="col-12">
                <div class="soft-card p-3">
                  <div class="d-flex gap-3 align-items-start">
                    <div class="iconbox"><i class="bi bi-bullseye"></i></div>
                    <div>
                      <div class="fw-semibold mb-1">Mision i qartë</div>
                      <div class="small text-muted2">Të japim kompetenca reale dhe të matshme, me proces të standardizuar nga regjistrimi te certifikimi.</div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-6">
                <div class="soft-card p-3">
                  <div class="d-flex gap-2 align-items-center">
                    <div class="iconbox" style="width:44px;height:44px;border-radius:14px;background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.22);color:var(--success);">
                      <i class="bi bi-diagram-3"></i>
                    </div>
                    <div>
                      <div class="fw-semibold">Programe</div>
                      <div class="small text-muted2">modulare</div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-6">
                <div class="soft-card p-3">
                  <div class="d-flex gap-2 align-items-center">
                    <div class="iconbox" style="width:44px;height:44px;border-radius:14px;background:rgba(14,165,233,.12);border-color:rgba(14,165,233,.20);color:var(--accent);">
                      <i class="bi bi-clipboard2-check"></i>
                    </div>
                    <div>
                      <div class="fw-semibold">Proces</div>
                      <div class="small text-muted2">i kontrolluar</div>
                    </div>
                  </div>
                </div>
              </div>

            </div>

            <div class="mt-3 small text-muted2">
              <i class="bi bi-info-circle me-1"></i> Kjo faqe është “about” — fokus te historia, vlerat dhe mënyra si punojmë (jo statistika).
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- QUICK NAV -->
<section class="py-4">
  <div class="container container-max">
    <div class="soft-card p-3">
      <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="fw-semibold"><i class="bi bi-compass me-1"></i> Navigim i shpejtë</div>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-outline-primary btn-sm" href="#story"><i class="bi bi-clock-history me-1"></i> Historia</a>
          <a class="btn btn-outline-primary btn-sm" href="#values"><i class="bi bi-stars me-1"></i> Vlerat</a>
          <a class="btn btn-outline-primary btn-sm" href="#team"><i class="bi bi-people me-1"></i> Ekipi</a>
          <a class="btn btn-outline-primary btn-sm" href="#security"><i class="bi bi-shield-lock me-1"></i> Siguria</a>
          <a class="btn btn-outline-primary btn-sm" href="#faq"><i class="bi bi-question-circle me-1"></i> FAQ</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- STORY (Interactive Stepper) -->
<section id="story" class="py-5">
  <div class="container container-max">
    <div class="row g-4 align-items-start">
      <div class="col-lg-5">
        <h2 class="fw-bold section-title mb-2">Historia jonë</h2>
        <p class="text-muted2">
          Zgjidh një etapë dhe shih përshkrimin. Kjo e bën “about” më të gjallë dhe më pak tekst-efik.
        </p>

        <div class="stepper d-grid gap-2 mt-3">
          <button class="step-btn active" type="button" data-step="1">
            <span class="step-index">1</span>
            <span>
              <span class="fw-semibold d-block">Fillimi</span>
              <span class="small text-muted2">Trajnime praktike, fokus te zanatet e kërkuara.</span>
            </span>
          </button>

          <button class="step-btn" type="button" data-step="2">
            <span class="step-index">2</span>
            <span>
              <span class="fw-semibold d-block">Standardizimi</span>
              <span class="small text-muted2">Programe të strukturuara dhe kurrikula modulare.</span>
            </span>
          </button>

          <button class="step-btn" type="button" data-step="3">
            <span class="step-index">3</span>
            <span>
              <span class="fw-semibold d-block">Partneritete</span>
              <span class="small text-muted2">Bashkëpunime për praktikë & punësim.</span>
            </span>
          </button>

          <button class="step-btn" type="button" data-step="4">
            <span class="step-index">4</span>
            <span>
              <span class="fw-semibold d-block">Platforma digjitale</span>
              <span class="small text-muted2">Menaxhim i centralizuar i kurseve dhe certifikatave.</span>
            </span>
          </button>

          <button class="step-btn" type="button" data-step="5">
            <span class="step-index">5</span>
            <span>
              <span class="fw-semibold d-block">Verifikimi me QR</span>
              <span class="small text-muted2">Transparencë publike dhe anti-abuzim.</span>
            </span>
          </button>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="soft-card p-4">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold"><i class="bi bi-journal-text me-1"></i> Përmbledhje e etapës</div>
            <span class="badge text-bg-primary" id="stepBadge">Etapa 1</span>
          </div>

          <div class="progress mb-3" style="height:10px;border-radius:999px;">
            <div class="progress-bar" id="stepProgress" role="progressbar" style="width: 20%"></div>
          </div>

          <div id="stepContent" class="text-muted2">
            <h5 class="fw-bold text-body">Fillimi: praktikë mbi teori</h5>
            <p class="mb-0">
              QTA lindi me një ide të thjeshtë: trajnimet duhet të japin aftësi që përdoren realisht. Që në fillim, prioriteti ishte praktika,
              struktura dhe orientimi te punësimi.
            </p>
          </div>

          <hr class="my-4">

          <div class="row g-3">
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-hand-index-thumb me-1"></i> Parimi ynë</div>
                <div class="small text-muted2">“Rezultat i matshëm” – çdo modul përfundon me output të qartë (aftësi/kompetencë).</div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-check2-square me-1"></i> Standard</div>
                <div class="small text-muted2">Proces i unifikuar nga regjistrimi deri te certifikimi dhe verifikimi publik.</div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</section>

<!-- VALUES (Interactive Tabs) -->
<section id="values" class="py-5">
  <div class="container container-max">
    <div class="soft-card p-4 tabs-card">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div>
          <h2 class="fw-bold section-title mb-1">Misioni, vizioni dhe vlerat</h2>
          <div class="text-muted2">Për t’i dhënë përdoruesit “arsyen” pse QTA është e besueshme.</div>
        </div>
        <a href="#security" class="btn btn-outline-primary">
          <i class="bi bi-shield-lock me-1"></i> Shih sigurinë
        </a>
      </div>

      <ul class="nav nav-pills flex-wrap mb-3" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#v1" type="button" role="tab">Misioni</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#v2" type="button" role="tab">Vizioni</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#v3" type="button" role="tab">Vlerat</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#v4" type="button" role="tab">Metodologjia</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#v5" type="button" role="tab">Për punëdhënësit</button>
        </li>
      </ul>

      <div class="tab-content">
        <div class="tab-pane fade show active" id="v1" role="tabpanel">
          <div class="row g-4 align-items-center">
            <div class="col-lg-7">
              <h5 class="fw-bold">Të japim kompetenca reale, jo vetëm teori</h5>
              <p class="text-muted2 mb-3">
                Misioni ynë është të përgatisim kursantë që dinë të punojnë: me praktikë, ushtrime të strukturuara dhe standarde të qarta vlerësimi.
              </p>
              <div class="soft-card p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-bullseye me-1"></i> Fokus</div>
                <div class="small text-muted2">Aftësi, rezultat, certifikim i besueshëm.</div>
              </div>
            </div>
            <div class="col-lg-5">
              <div class="soft-card p-4">
                <div class="fw-semibold mb-2"><i class="bi bi-lightning-charge me-1"></i> Përvojë e thjeshtë</div>
                <div class="small text-muted2">Përdoruesi hyn dhe kupton menjëherë çfarë të bëjë: regjistro, ndjek, certifiko, verifiko.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="v2" role="tabpanel">
          <div class="row g-4 align-items-center">
            <div class="col-lg-7">
              <h5 class="fw-bold">Të jemi referencë për trajnime dhe certifikime të sigurta</h5>
              <p class="text-muted2 mb-3">
                Vizioni ynë është të ndërtojmë një standard trajnimi ku cilësia dhe transparenca janë pjesë e produktit, jo “opsion”.
              </p>
              <ul class="text-muted2 mb-0">
                <li>Programe të përditësuara sipas tregut</li>
                <li>Proces i standardizuar</li>
                <li>Verifikim publik i certifikatave</li>
              </ul>
            </div>
            <div class="col-lg-5">
              <div class="soft-card p-4">
                <div class="fw-semibold mb-2">Shkallëzim i qëndrueshëm</div>
                <div class="small text-muted2">Rritja nuk duhet të ulë cilësinë. Platforma ndihmon të ruhet standardi.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="v3" role="tabpanel">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex gap-3">
                  <div class="iconbox"><i class="bi bi-shield-check"></i></div>
                  <div>
                    <div class="fw-semibold">Integritet</div>
                    <div class="small text-muted2">Proces i pastër, certifikim i kontrolluar, transparencë.</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex gap-3">
                  <div class="iconbox" style="background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.22);color:var(--success);">
                    <i class="bi bi-mortarboard"></i>
                  </div>
                  <div>
                    <div class="fw-semibold">Cilësi mësimore</div>
                    <div class="small text-muted2">Praktikë e strukturuar, feedback dhe vlerësim i qartë.</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex gap-3">
                  <div class="iconbox" style="background:rgba(14,165,233,.12);border-color:rgba(14,165,233,.20);color:var(--accent);">
                    <i class="bi bi-people"></i>
                  </div>
                  <div>
                    <div class="fw-semibold">Respekt për kursantin</div>
                    <div class="small text-muted2">Qartësi, mbështetje dhe ritëm i përshtatshëm.</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="soft-card p-3">
                <div class="d-flex gap-3">
                  <div class="iconbox" style="background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.20);color:#f59e0b;">
                    <i class="bi bi-ui-checks-grid"></i>
                  </div>
                  <div>
                    <div class="fw-semibold">Qartësi në UX</div>
                    <div class="small text-muted2">Më pak klikime, më shumë veprime të drejtpërdrejta.</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="v4" role="tabpanel">
          <div class="row g-4 align-items-center">
            <div class="col-lg-7">
              <h5 class="fw-bold">Metodologji e orientuar te puna</h5>
              <ol class="text-muted2 mb-3">
                <li><b>Teori minimale</b> vetëm sa për të kuptuar bazat.</li>
                <li><b>Praktikë intensive</b> me ushtrime dhe raste reale.</li>
                <li><b>Vlerësim i strukturuar</b> dhe feedback.</li>
                <li><b>Certifikim</b> me standard dhe verifikim publik.</li>
              </ol>
              <div class="soft-card p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-check2-circle me-1"></i> Output i qartë</div>
                <div class="small text-muted2">Në fund, kursanti duhet të ketë aftësi konkrete që mund t’i demonstrojë.</div>
              </div>
            </div>
            <div class="col-lg-5">
              <div class="soft-card p-4">
                <div class="fw-semibold mb-2"><i class="bi bi-clipboard-data me-1"></i> Standardizim</div>
                <div class="small text-muted2">Procese të përsëritshme = cilësi e qëndrueshme.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="v5" role="tabpanel">
          <div class="row g-4 align-items-center">
            <div class="col-lg-7">
              <h5 class="fw-bold">Për punëdhënësit: verifikim i thjeshtë</h5>
              <p class="text-muted2 mb-3">
                Punëdhënësit mund të verifikojnë një certifikatë në sekonda, pa llogari. Qëllimi është besueshmëria dhe ulja e abuzimeve.
              </p>
              <a href="verify.php" class="btn btn-primary"><i class="bi bi-qr-code-scan me-1"></i> Hap verifikimin</a>
            </div>
            <div class="col-lg-5">
              <div class="soft-card p-4">
                <div class="fw-semibold mb-2">Praktikë UX</div>
                <div class="small text-muted2">Shfaqje minimale e të dhënave (vetëm çfarë duhet) + status i qartë (Valid/Invalid/Not found).</div>
              </div>
            </div>
          </div>
        </div>

      </div><!-- tab-content -->
    </div>
  </div>
</section>

<!-- TEAM -->
<section id="team" class="py-5">
  <div class="container container-max">
    <div class="text-center mb-4">
      <h2 class="fw-bold section-title">Ekipi</h2>
      <p class="text-muted2 mb-0">Vendmbajtës të bukur që i zëvendëson me emrat realë kur të jesh gati.</p>
    </div>

    <div class="row g-4">
      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 h-100">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="avatar">AD</div>
            <div>
              <div class="fw-semibold">Emër Mbiemër</div>
              <div class="small text-muted2">Drejtues Akademik</div>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="tag"><i class="bi bi-journal-check"></i> Kurrikula</span>
            <span class="tag"><i class="bi bi-mortarboard"></i> Trajnim</span>
          </div>
          <p class="small text-muted2 mb-0">
            Përgjegjës për standardin mësimor, strukturën e programeve dhe cilësinë e vlerësimit.
          </p>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 h-100">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="avatar" style="background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.22);color:var(--success);">OP</div>
            <div>
              <div class="fw-semibold">Emër Mbiemër</div>
              <div class="small text-muted2">Operacione & Partneritete</div>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="tag"><i class="bi bi-people"></i> Partnerë</span>
            <span class="tag"><i class="bi bi-briefcase"></i> Punësim</span>
          </div>
          <p class="small text-muted2 mb-0">
            Koordinon proceset, bashkëpunimet dhe zbatimin e standardeve me palët e treta.
          </p>
        </div>
      </div>

      <div class="col-md-6 col-lg-4">
        <div class="soft-card p-4 h-100">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="avatar" style="background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.18);color:var(--danger);">IT</div>
            <div>
              <div class="fw-semibold">Emër Mbiemër</div>
              <div class="small text-muted2">Platformë & Siguri</div>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="tag"><i class="bi bi-shield-lock"></i> Siguri</span>
            <span class="tag"><i class="bi bi-ui-checks-grid"></i> UX</span>
          </div>
          <p class="small text-muted2 mb-0">
            Përgjegjës për stabilitetin e portalit, aksesin sipas roleve dhe funksionet e verifikimit publik.
          </p>
        </div>
      </div>
    </div>

    <div class="soft-card p-3 mt-4">
      <div class="small text-muted2">
        <i class="bi bi-info-circle me-1"></i> Shënim: këtu mund të shtosh edhe foto reale, LinkedIn, ose një “team modal” për secilin.
      </div>
    </div>
  </div>
</section>

<!-- SECURITY / TRUST -->
<section id="security" class="py-5">
  <div class="container container-max">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <h2 class="fw-bold section-title mb-2">Besueshmëri & siguri</h2>
        <p class="text-muted2">
          Qëllimi: përdoruesi ta kuptojë pse certifikata është e verifikueshme dhe pse sistemi është i kontrolluar.
        </p>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="soft-card p-3">
              <div class="fw-semibold mb-1"><i class="bi bi-qr-code me-1"></i> Token unik</div>
              <div class="small text-muted2">Çdo certifikatë lidhet me identifikues unik për verifikim publik.</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="soft-card p-3">
              <div class="fw-semibold mb-1"><i class="bi bi-person-lock me-1"></i> Role & leje</div>
              <div class="small text-muted2">Parimi “least privilege”: secili sheh vetëm çfarë i duhet.</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="soft-card p-3">
              <div class="fw-semibold mb-1"><i class="bi bi-shield-check me-1"></i> Verifikim i qartë</div>
              <div class="small text-muted2">Rezultat i thjeshtë: e vlefshme / e pavlefshme / nuk u gjet.</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="soft-card p-3">
              <div class="fw-semibold mb-1"><i class="bi bi-clipboard2-check me-1"></i> Proces i standardizuar</div>
              <div class="small text-muted2">Nga regjistrimi te certifikimi – logjikë e unifikuar dhe e parashikueshme.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="cta-band p-4 p-lg-5">
          <div class="position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-2">Dëshiron të shohësh verifikimin?</h3>
            <p class="mb-4" style="opacity:.92;">
              Për punëdhënësit dhe publikun: verifikimi është i thjeshtë dhe i shpejtë.
            </p>
            <div class="d-flex flex-wrap gap-2">
              <a href="verify.php" class="btn btn-light fw-semibold"><i class="bi bi-qr-code-scan me-1"></i> Verifiko certifikatën</a>
              <a href="selectProfile.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr në sistem</a>
              <a href="#faq" class="btn btn-outline-light"><i class="bi bi-question-circle me-1"></i> Pyetje</a>
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
      <p class="text-muted2 mb-0">E heqim “pasigurinë” para se përdoruesi të bëjë veprim.</p>
    </div>

    <div class="faq accordion" id="faqAcc">
      <div class="accordion-item">
        <h2 class="accordion-header" id="q1">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a1">
            A është verifikimi publik?
          </button>
        </h2>
        <div id="a1" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
          <div class="accordion-body">
            Po. Verifikimi synon të jetë i aksesueshëm pa llogari, në mënyrë që certifikatat të kontrollohen lehtësisht nga kushdo.
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header" id="q2">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2">
            Çfarë të dhënash shfaqen në verifikim?
          </button>
        </h2>
        <div id="a2" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
          <div class="accordion-body">
            Vetëm të dhëna minimale dhe të kontrolluara, të mjaftueshme për konfirmim. Qëllimi është privatësia dhe besueshmëria.
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
          <div class="accordion-body">
            Rolet e bëjnë sistemin më të sigurt dhe UI-n më të pastër: secili sheh vetëm funksionet që i duhen.
          </div>
        </div>
      </div>

      <div class="accordion-item">
        <h2 class="accordion-header" id="q4">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a4">
            Si mund të bashkëpunojmë?
          </button>
        </h2>
        <div id="a4" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
          <div class="accordion-body">
            Mund të krijojmë programe të dedikuara, trajnime “in-house”, ose partneritete për praktikë dhe punësim.
          </div>
        </div>
      </div>
    </div>

    <div class="soft-card p-4 mt-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
          <div class="fw-semibold">Gati për hapin tjetër?</div>
          <div class="small text-muted2">Hyr në sistem ose verifiko një certifikatë me QR.</div>
        </div>
        <div class="d-flex gap-2">
          <a href="selectProfile.php" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr</a>
          <a href="verify.php" class="btn btn-outline-primary"><i class="bi bi-qr-code-scan me-1"></i> Verifiko</a>
          <a href="contact.html" class="btn btn-outline-secondary"><i class="bi bi-envelope me-1"></i> Kontakt</a>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- Floating actions -->
<div class="floating-actions">
  <button class="fab" id="goTop" title="Shko lart" aria-label="Shko lart">
    <i class="bi bi-arrow-up"></i>
  </button>
  <a class="fab" href="verify.php" title="Verifiko certifikatën" aria-label="Verifiko certifikatën">
    <i class="bi bi-qr-code-scan"></i>
  </a>
</div>

<!-- Culture modal -->
<div class="modal fade" id="cultureModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content soft-card">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-play-circle me-1"></i> Si punojmë (demo e shkurtër)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="soft-card p-4">
          <div class="fw-semibold mb-2">3 parime që na udhëheqin</div>
          <div class="row g-3">
            <div class="col-md-4">
              <div class="soft-card p-3 text-center">
                <i class="bi bi-hand-thumbs-up fs-1"></i>
                <div class="fw-semibold mt-2">Praktikë</div>
                <div class="small text-muted2">Më shumë ushtrime</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="soft-card p-3 text-center">
                <i class="bi bi-diagram-3 fs-1"></i>
                <div class="fw-semibold mt-2">Proces</div>
                <div class="small text-muted2">Standard & i përsëritshëm</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="soft-card p-3 text-center">
                <i class="bi bi-shield-check fs-1"></i>
                <div class="fw-semibold mt-2">Besim</div>
                <div class="small text-muted2">Verifikim publik</div>
              </div>
            </div>
          </div>

          <hr class="my-4">

          <div class="fw-semibold mb-1">Ku ta vendosësh videon?</div>
          <div class="small text-muted2">
            Këtu mund të futësh një video (YouTube/MP4) ose screenshot-e me carousel. Për momentin është placeholder “i bukur”.
          </div>
        </div>
      </div>
      <div class="modal-footer border-0">
        <a href="verify.php" class="btn btn-primary"><i class="bi bi-qr-code-scan me-1"></i> Verifiko</a>
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
      title: 'Fillimi: praktikë mbi teori',
      body: 'QTA u ndërtua si përgjigje ndaj nevojës për aftësi reale. Fokus te praktika, strukturimi i moduleve dhe qartësia në proces.'
    },
    2: {
      title: 'Standardizimi: cilësi e përsëritshme',
      body: 'Për të ruajtur cilësinë, programet u modularizuan dhe u standardizuan: kush bën çfarë, kur, dhe me çfarë kriteresh.'
    },
    3: {
      title: 'Partneritete: lidhje me tregun',
      body: 'Bashkëpunime me agjenci dhe kompani për të afruar kursantët me praktika, projekte dhe mundësi punësimi.'
    },
    4: {
      title: 'Platforma digjitale: menaxhim i centralizuar',
      body: 'Një portal i vetëm për regjistrime, ndjekje, rezultate dhe certifikim, që e bën procesin të shpejtë dhe të kontrolluar.'
    },
    5: {
      title: 'Verifikimi me QR: transparencë publike',
      body: 'Çdo certifikatë lidhet me token unik dhe QR. Kjo ul abuzimet dhe rrit besueshmërinë për punëdhënësit dhe publikun.'
    }
  };

  const selectStep = (n) => {
    stepBtns.forEach(b => b.classList.toggle('active', b.getAttribute('data-step') === String(n)));
    if (stepBadge) stepBadge.textContent = 'Etapa ' + n;
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
