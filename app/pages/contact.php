<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* Helper për escape */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* Përdoruesi aktual (opsional, vetëm për UX) */
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Kontakt – QTA</title>
  <link rel="icon" type="image/svg+xml" href="image/logoPNG - Copy.png">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
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
    html[data-theme="dark"] .badge-chip{ background: rgba(15,23,42,.6); }

    .text-muted2{ color: var(--muted); }

    /* HERO */
    .hero{
      position:relative;
      overflow:hidden;
      padding: 76px 0 52px;
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

    .hero-panel{
      border-radius: calc(var(--radius) + 6px);
      background: linear-gradient(135deg, rgba(37,99,235,.12), rgba(14,165,233,.08));
      border: 1px solid rgba(148,163,184,.22);
      box-shadow: var(--shadow);
      overflow:hidden;
      position:relative;
    }
    .hero-panel .grid{
      position:absolute; inset:0;
      background-image:
        linear-gradient(to right, rgba(148,163,184,.14) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(148,163,184,.14) 1px, transparent 1px);
      background-size: 46px 46px;
      mask-image: radial-gradient(circle at 30% 25%, black 0 55%, transparent 80%);
      opacity:.65;
    }

    .iconbox{
      width:52px;height:52px;
      border-radius: 16px;
      display:flex;align-items:center;justify-content:center;
      background: rgba(37,99,235,.10);
      border: 1px solid rgba(37,99,235,.18);
      color: var(--primary);
      flex:0 0 auto;
    }

    /* Tiles */
    .tile{
      transition: .15s ease;
      border: 1px solid rgba(148,163,184,.18);
      border-radius: var(--radius);
      background: rgba(255,255,255,.55);
    }
    html[data-theme="dark"] .tile{ background: rgba(15,23,42,.65); }
    .tile:hover{ transform: translateY(-2px); box-shadow: var(--shadow2); }

    .copy-btn{
      border-radius: 12px;
    }

    /* Form */
    .form-floating>.form-control, .form-floating>.form-select{
      border-radius: 14px;
      border:1px solid rgba(148,163,184,.25);
      background: rgba(255,255,255,.70);
      color: var(--text);
    }
    html[data-theme="dark"] .form-floating>.form-control,
    html[data-theme="dark"] .form-floating>.form-select{
      background: rgba(15,23,42,.75);
    }
    .form-control:focus, .form-select:focus{
      border-color: rgba(37,99,235,.55);
      box-shadow: 0 0 0 .25rem rgba(37,99,235,.14);
    }
    .form-floating>label{ color: var(--muted2); }

    /* Tabs card */
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

    /* Map */
    .map-wrap{
      border-radius: var(--radius);
      overflow:hidden;
      border: 1px solid rgba(148,163,184,.18);
      box-shadow: var(--shadow2);
    }
    .map-wrap iframe{ display:block; }

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
      .hero{ padding: 56px 0 40px; }
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
          <i class="bi bi-headset me-1"></i> Kontakt & mbështetje
        </span>

        <h1 class="display-5 fw-bold headline mb-3">Na kontaktoni shpejt dhe thjesht</h1>
        <p class="lead mb-4 text-muted2">
          Për pyetje rreth kurseve, regjistrimeve, aksesit në portal dhe verifikimit të certifikatave.
          Zgjidhni kanalin që preferoni: formular, email, telefon ose vizitë.
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

          <a href="#form" class="btn btn-outline-primary">
            <i class="bi bi-send me-1"></i> Dërgo mesazh
          </a>

          <a href="verify.php" class="btn btn-outline-secondary">
            <i class="bi bi-qr-code-scan me-1"></i> Verifiko certifikatën
          </a>

          <button type="button" class="btn btn-light" id="themeToggle" aria-label="Ndrysho temën">
            <i class="bi bi-moon-stars me-1"></i> Dark mode
          </button>
        </div>

        <div class="mt-4 small text-muted2">
          <i class="bi bi-clock-history me-1"></i>
          Orari: E Hënë–E Premte 08:00–18:00 · E Shtunë 09:00–14:00 · E Diel mbyllur
        </div>
      </div>

      <div class="col-lg-6">
        <div class="hero-panel p-3">
          <div class="grid"></div>
          <div class="soft-card p-4 position-relative" style="z-index:1;">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <div class="fw-semibold">Kanale kontakti</div>
                <div class="small text-muted2">Kopjo kontaktet me një klikim.</div>
              </div>
              <span class="badge text-bg-success"><i class="bi bi-check2-circle me-1"></i> QTA</span>
            </div>

            <div class="row g-3">
              <div class="col-12">
                <div class="tile p-3">
                  <div class="d-flex gap-3 align-items-start">
                    <div class="iconbox"><i class="bi bi-telephone"></i></div>
                    <div class="w-100">
                      <div class="d-flex justify-content-between align-items-center">
                        <div class="fw-semibold">Telefon</div>
                        <button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="+355698778837" type="button" title="Kopjo">
                          <i class="bi bi-clipboard"></i>
                        </button>
                      </div>
                      <div class="small text-muted2 mb-2">Për përgjigje të shpejta gjatë orarit.</div>
                      <a class="btn btn-sm btn-primary" href="tel:+355698778837"><i class="bi bi-telephone me-1"></i> Thirr tani</a>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-md-6">
                <div class="tile p-3 h-100">
                  <div class="d-flex gap-3 align-items-start">
                    <div class="iconbox" style="background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.18);color:var(--danger);">
                      <i class="bi bi-envelope"></i>
                    </div>
                    <div class="w-100">
                      <div class="d-flex justify-content-between align-items-center">
                        <div class="fw-semibold">Email</div>
                        <button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="officialqta@gmail.com" type="button" title="Kopjo">
                          <i class="bi bi-clipboard"></i>
                        </button>
                      </div>
                      <div class="small text-muted2 mb-2">Për kërkesa të detajuara.</div>
                      <a class="btn btn-sm btn-outline-primary" href="mailto:officialqta@gmail.com">
                        <i class="bi bi-envelope-open me-1"></i> Shkruaj
                      </a>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-md-6">
                <div class="tile p-3 h-100">
                  <div class="d-flex gap-3 align-items-start">
                    <div class="iconbox" style="background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.22);color:var(--success);">
                      <i class="bi bi-geo-alt"></i>
                    </div>
                    <div class="w-100">
                      <div class="fw-semibold">Adresa</div>
                      <div class="small text-muted2 mb-2">Rruga Bilal Konxholli, Tiranë</div>
                      <a class="btn btn-sm btn-outline-secondary" href="#map">
                        <i class="bi bi-map me-1"></i> Hap hartën
                      </a>
                    </div>
                  </div>
                </div>
              </div>

            </div><!-- row -->
          </div><!-- inner -->
        </div><!-- hero panel -->
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
          <a class="btn btn-outline-primary btn-sm" href="#form"><i class="bi bi-send me-1"></i> Formular</a>
          <a class="btn btn-outline-primary btn-sm" href="#info"><i class="bi bi-info-circle me-1"></i> Info</a>
          <a class="btn btn-outline-primary btn-sm" href="#map"><i class="bi bi-geo me-1"></i> Harta</a>
          <a class="btn btn-outline-primary btn-sm" href="#faq"><i class="bi bi-question-circle me-1"></i> FAQ</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- MAIN -->
<section class="py-5">
  <div class="container container-max">
    <div class="row g-4 g-lg-5 align-items-start">

      <!-- FORM -->
      <div class="col-lg-7" id="form">
        <div class="soft-card p-4 p-md-5">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <h2 class="fw-bold mb-1">Dërgo një mesazh</h2>
              <div class="text-muted2">Plotëso fushat dhe dërgo. Ke edhe opsion “mailto” si alternativë.</div>
            </div>
            <span class="badge text-bg-primary"><i class="bi bi-shield-check me-1"></i> Privatësi</span>
          </div>

          <div class="progress mb-4" style="height:10px;border-radius:999px;">
            <div class="progress-bar" id="formProgress" style="width: 20%"></div>
          </div>

          <form id="contactForm" class="needs-validation" novalidate>
            <div class="row g-3">
              <div class="col-md-6">
                <div class="form-floating">
                  <input type="text" class="form-control" id="firstName" placeholder="Emri" required>
                  <label for="firstName">Emri</label>
                  <div class="invalid-feedback">Ju lutem shkruani emrin.</div>
                </div>
              </div>

              <div class="col-md-6">
                <div class="form-floating">
                  <input type="text" class="form-control" id="lastName" placeholder="Mbiemri" required>
                  <label for="lastName">Mbiemri</label>
                  <div class="invalid-feedback">Ju lutem shkruani mbiemrin.</div>
                </div>
              </div>

              <div class="col-12">
                <div class="form-floating">
                  <input type="email" class="form-control" id="email" placeholder="email@shembull.com" required>
                  <label for="email">Email</label>
                  <div class="invalid-feedback">Ju lutem shkruani një email të vlefshëm.</div>
                </div>
              </div>

              <div class="col-12">
                <div class="form-floating">
                  <select class="form-select" id="subject" required>
                    <option value="" selected disabled>Zgjidhni një opsion</option>
                    <option value="Informacione për regjistrim">Informacione për regjistrim</option>
                    <option value="Informacione për kurse">Informacione për kurse</option>
                    <option value="Problem teknik">Problem teknik</option>
                    <option value="Verifikim certifikate">Verifikim certifikate</option>
                    <option value="Tjetër">Tjetër</option>
                  </select>
                  <label for="subject">Subjekti</label>
                  <div class="invalid-feedback">Ju lutem zgjidhni një subjekt.</div>
                </div>
              </div>

              <div class="col-12">
                <div class="form-floating">
                  <textarea class="form-control" id="message" placeholder="Mesazhi" style="height: 160px" required></textarea>
                  <label for="message">Mesazhi</label>
                  <div class="invalid-feedback">Ju lutem shkruani mesazhin.</div>
                </div>
              </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mt-4">
              <button class="btn btn-primary px-4" type="submit">
                <i class="bi bi-send me-1"></i> Dërgo mesazhin
              </button>
              <a id="mailtoLink" class="btn btn-outline-secondary" href="#">
                <i class="bi bi-envelope-open me-1"></i> Dërgo me email
              </a>
              <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#hoursModal">
                <i class="bi bi-clock-history me-1"></i> Orari
              </button>
            </div>

            <div class="small text-muted2 mt-3">
              <i class="bi bi-info-circle me-1"></i>
              Këshillë: sa më i qartë subjekti dhe përshkrimi, aq më shpejt e zgjidhim.
            </div>
          </form>
        </div>
      </div>

      <!-- RIGHT: TABS -->
      <div class="col-lg-5" id="info">
        <div class="soft-card p-4 tabs-card">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="fw-semibold"><i class="bi bi-ui-checks-grid me-1"></i> Informacion</div>
            <a class="btn btn-sm btn-outline-primary" href="verify.php"><i class="bi bi-qr-code-scan me-1"></i> Verifiko</a>
          </div>

          <ul class="nav nav-pills flex-wrap mb-3" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#t1" type="button" role="tab">Kontakt</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="pill" data-bs-target="#t2" type="button" role="tab">Harta</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="pill" data-bs-target="#t3" type="button" role="tab">FAQ</button>
            </li>
          </ul>

          <div class="tab-content">
            <!-- Tab 1: Contact info -->
            <div class="tab-pane fade show active" id="t1" role="tabpanel">
              <div class="tile p-3 mb-3">
                <div class="d-flex gap-3 align-items-start">
                  <div class="iconbox"><i class="bi bi-geo-alt"></i></div>
                  <div class="w-100">
                    <div class="fw-semibold">Adresa</div>
                    <div class="small text-muted2">Rruga Bilal Konxholli, Tiranë</div>
                  </div>
                </div>
              </div>

              <div class="tile p-3 mb-3">
                <div class="d-flex gap-3 align-items-start">
                  <div class="iconbox" style="background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.22);color:var(--success);">
                    <i class="bi bi-telephone"></i>
                  </div>
                  <div class="w-100">
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="fw-semibold">Telefoni</div>
                      <button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="+355698778837" type="button" title="Kopjo">
                        <i class="bi bi-clipboard"></i>
                      </button>
                    </div>
                    <a class="text-decoration-none" href="tel:+355698778837">+355 69 877 8837</a>
                  </div>
                </div>
              </div>

              <div class="tile p-3">
                <div class="d-flex gap-3 align-items-start">
                  <div class="iconbox" style="background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.18);color:var(--danger);">
                    <i class="bi bi-envelope"></i>
                  </div>
                  <div class="w-100">
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="fw-semibold">Email</div>
                      <button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="officialqta@gmail.com" type="button" title="Kopjo">
                        <i class="bi bi-clipboard"></i>
                      </button>
                    </div>
                    <a class="text-decoration-none" href="mailto:officialqta@gmail.com">officialqta@gmail.com</a>
                  </div>
                </div>
              </div>

              <div class="small text-muted2 mt-3">
                <i class="bi bi-shield-check me-1"></i> Të dhënat përdoren vetëm për komunikim dhe përgjigje.
              </div>
            </div>

            <!-- Tab 2: Map -->
            <div class="tab-pane fade" id="t2" role="tabpanel">
              <div class="map-wrap" id="map">
                <iframe
                  src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d11975.486952909885!2d19.818733!3d41.327546!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zNDHCsDE5JzM5LjIiTiAxOcKwNDknMDcuNCJF!5e0!3m2!1sen!2s!4v1644269999999!5m2!1sen!2s"
                  width="100%" height="360" style="border:0;" loading="lazy" allowfullscreen
                  referrerpolicy="no-referrer-when-downgrade"></iframe>
              </div>
              <div class="small text-muted2 mt-3">
                <i class="bi bi-info-circle me-1"></i> Nëse do, e zëvendësojmë me pin-in e saktë të zyrës suaj.
              </div>
            </div>

            <!-- Tab 3: FAQ -->
            <div class="tab-pane fade" id="t3" role="tabpanel">
              <div class="faq accordion" id="faqAcc">
                <div class="accordion-item">
                  <h2 class="accordion-header" id="q1">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a1">
                      Si mund të regjistrohem si student?
                    </button>
                  </h2>
                  <div id="a1" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
                    <div class="accordion-body">
                      Na shkruani për programet aktive. Më pas, merrni udhëzimet për dokumentet dhe procesin e regjistrimit.
                    </div>
                  </div>
                </div>

                <div class="accordion-item">
                  <h2 class="accordion-header" id="q2">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2">
                      A ofroni kurse online?
                    </button>
                  </h2>
                  <div id="a2" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
                    <div class="accordion-body">
                      Po. Disa module ofrohen online, me materiale, ushtrime dhe mbështetje sipas programit.
                    </div>
                  </div>
                </div>

                <div class="accordion-item">
                  <h2 class="accordion-header" id="q3">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3">
                      Si verifikohet një certifikatë?
                    </button>
                  </h2>
                  <div id="a3" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
                    <div class="accordion-body">
                      Çdo certifikatë ka QR me token unik. Skanoni në <a href="verify.php">verify.php</a> dhe krahasoni të dhënat.
                    </div>
                  </div>
                </div>
              </div>

              <div class="mt-3">
                <a href="#form" class="btn btn-outline-primary w-100"><i class="bi bi-send me-1"></i> Kthehu te formulari</a>
              </div>
            </div>

          </div><!-- tab-content -->
        </div>
      </div>

    </div>
  </div>
</section>

<!-- CTA BAND -->
<section class="py-5">
  <div class="container container-max">
    <div class="cta-band p-4 p-lg-5">
      <div class="position-relative" style="z-index:1;">
        <h3 class="fw-bold mb-2">Verifikoni certifikatat në sekonda</h3>
        <p class="mb-4" style="opacity:.92;">
          Skanoni QR dhe kontrolloni statusin. Transparencë për kursantin dhe punëdhënësin.
        </p>
        <div class="d-flex flex-wrap gap-2">
          <a href="verify.php" class="btn btn-light fw-semibold"><i class="bi bi-qr-code-scan me-1"></i> Verifiko tani</a>
          <a href="#form" class="btn btn-outline-light"><i class="bi bi-send me-1"></i> Dërgo pyetje</a>
          <a href="selectProfile.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr</a>
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

<!-- Toasts -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="toastOK" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body"><i class="bi bi-check2-circle me-1"></i> Mesazhi u dërgua! Do t’ju përgjigjemi së shpejti.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Mbyll"></button>
    </div>
  </div>

  <div id="toastCopy" class="toast align-items-center text-bg-primary border-0 mt-2" role="alert" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body"><i class="bi bi-clipboard-check me-1"></i> U kopjua në clipboard.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Mbyll"></button>
    </div>
  </div>
</div>

<!-- Modal: hours -->
<div class="modal fade" id="hoursModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content soft-card">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-clock-history me-1"></i> Orari i punës</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="soft-card p-3">
          <div class="d-flex justify-content-between"><span>E Hënë – E Premte</span><b>08:00 – 18:00</b></div>
          <hr class="my-2">
          <div class="d-flex justify-content-between"><span>E Shtunë</span><b>09:00 – 14:00</b></div>
          <hr class="my-2">
          <div class="d-flex justify-content-between"><span>E Diel</span><b>Mbyllur</b></div>
        </div>
        <div class="small text-muted2 mt-3">
          <i class="bi bi-info-circle me-1"></i> Për kërkesa urgjente, dërgoni email me subjekt të qartë.
        </div>
      </div>
      <div class="modal-footer border-0">
        <a class="btn btn-primary" href="tel:+355698778837"><i class="bi bi-telephone me-1"></i> Thirr</a>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </div>
  </div>
</div>

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

  // Theme toggle with localStorage
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

  // Form progress + mailto builder + submit toast (front-end)
  (function(){
    const form = document.getElementById('contactForm');
    const mailto = document.getElementById('mailtoLink');
    const toastEl = document.getElementById('toastOK');
    const toastCopyEl = document.getElementById('toastCopy');
    const toast = toastEl ? new bootstrap.Toast(toastEl, { delay: 3200 }) : null;
    const toastCopy = toastCopyEl ? new bootstrap.Toast(toastCopyEl, { delay: 1600 }) : null;

    const progress = document.getElementById('formProgress');
    const ids = ['firstName','lastName','email','subject','message'];

    function updateProgress(){
      let filled = 0;
      ids.forEach(id=>{
        const el = document.getElementById(id);
        if (!el) return;
        const v = (el.value || '').trim();
        if (v.length) filled++;
      });
      const pct = Math.max(20, Math.round((filled / ids.length) * 100));
      if (progress) progress.style.width = pct + '%';
    }

    function updateMailto(){
      const fn = document.getElementById('firstName')?.value.trim() || '';
      const ln = document.getElementById('lastName')?.value.trim() || '';
      const email = document.getElementById('email')?.value.trim() || '';
      const subj = document.getElementById('subject')?.value || 'Tjetër';
      const msg  = document.getElementById('message')?.value.trim() || '';

      const subject = encodeURIComponent(`[Kontakt] ${subj} – ${fn} ${ln}`.trim());
      const body = encodeURIComponent(
        `Emri: ${fn} ${ln}\nEmail: ${email}\nSubjekti: ${subj}\n\nMesazhi:\n${msg}\n`
      );

      if (mailto) mailto.href = `mailto:officialqta@gmail.com?subject=${subject}&body=${body}`;
    }

    ids.forEach(id=>{
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', ()=>{ updateMailto(); updateProgress(); });
      el.addEventListener('change', ()=>{ updateMailto(); updateProgress(); });
    });

    form?.addEventListener('submit', function (e) {
      e.preventDefault();
      form.classList.add('was-validated');
      if (!form.checkValidity()) { updateProgress(); return; }

      // Këtu mund ta lidhësh me backend (fetch/POST). Për momentin: toast + reset
      toast?.show();
      form.reset();
      form.classList.remove('was-validated');
      updateMailto();
      updateProgress();
    });

    updateMailto();
    updateProgress();

    // Copy buttons
    document.querySelectorAll('[data-copy]').forEach(btn=>{
      btn.addEventListener('click', async ()=>{
        try{
          await navigator.clipboard.writeText(btn.getAttribute('data-copy') || '');
          btn.innerHTML = '<i class="bi bi-clipboard-check"></i>';
          toastCopy?.show();
          setTimeout(()=> btn.innerHTML = '<i class="bi bi-clipboard"></i>', 1200);
        }catch(e){
          // ignore
        }
      });
    });
  })();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>
