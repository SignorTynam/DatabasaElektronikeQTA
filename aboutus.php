<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/navbarMain.php';

$pdo = getPDO();

/* 1) Nëse je i loguar, lexo përdoruesin aktual */
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

/* 2) Statistika për “Impaktin” */
$counts = [
    'students' => 0,
    'agencies' => 0,
    'admins'   => 0,
    'users'    => 0,
];
$counts['students'] = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$counts['agencies'] = (int)$pdo->query("SELECT COUNT(*) FROM agencies")->fetchColumn();
$counts['users']    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

$adminCountStmt = $pdo->query("
    SELECT COUNT(*)
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.name = 'administrator'
");
$counts['admins'] = (int)$adminCountStmt->fetchColumn();

/* Helper i vogël */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Rreth Nesh – Qendra e Trajnimeve të Avancuara (QTA)</title>
  <link rel="icon" type="image/svg+xml" href="image/logoPNG - Copy.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    :root{
      --g1:#0ea5e9; --g2:#2563eb; --g3:#4f46e5;
      --bg:#f5f7fb; --glass:rgba(255,255,255,.85); --glass-b:rgba(255,255,255,.55);
      --muted:#667085; --ring:#e6efff; --shadow:0 18px 40px rgba(2,6,23,.12);
    }
    body{ background:var(--bg); }
    .navbar-brand img{ height:30px; }

    /* HERO */
    .hero{
      color:#fff; overflow:hidden; position:relative;
      background:
        radial-gradient(1200px 420px at 10% -20%, rgba(37,99,235,.25), rgba(37,99,235,0) 60%),
        radial-gradient(900px 320px at 90% -10%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, var(--g1) 0%, var(--g2) 55%, var(--g3) 100%);
      padding:72px 0;
      border-bottom-left-radius:1.25rem; border-bottom-right-radius:1.25rem;
    }
    .hero .chip{
      background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.28);
    }
    .blob{
      position:absolute; right:-140px; bottom:-140px; width:520px; height:520px; border-radius:50%;
      background:radial-gradient(circle at 45% 45%, rgba(255,255,255,.35), transparent 60%);
      filter:blur(28px);
    }

    /* Cards / sections */
    .card{ border:none; border-radius:1rem; box-shadow:var(--shadow); }
    .glass{ background:var(black); border:1px solid var(--glass-b); border-radius:1rem; box-shadow:var(--shadow); }
    .feature .icon, .value .icon{
      width:56px; height:56px; border-radius:14px; display:flex; align-items:center; justify-content:center;
      background:#eef2ff; color:#3557ff;
    }
    .value .icon{ background:#ecfdf5; color:#16a34a; }

    .stat-number{ font-size:2.4rem; font-weight:800; color:#0d6efd; }
    .small-muted{ color:var(--muted); }

    /* Timeline */
    .timeline{ position:relative; padding-left:1.25rem; }
    .timeline::before{
      content:''; position:absolute; left:18px; top:0; bottom:0; width:2px; background:#e5e7eb;
    }
    .timeline-item{ position:relative; padding-left:2.4rem; margin-bottom:1.1rem; }
    .timeline-item::before{
      content:''; position:absolute; left:10px; top:.35rem; width:18px; height:18px; border-radius:50%;
      background:#fff; border:3px solid #2563eb;
    }

    /* Team */
    .avatar{
      width:68px; height:68px; border-radius:20px; background:#eef2ff; display:flex; align-items:center; justify-content:center;
      font-weight:700; color:#2563eb;
    }
    .badge-soft{
      background:#eef2ff; color:#2563eb; border:1px solid #e5e7eb;
    }

    /* CTA stripe */
    .cta{
      background:linear-gradient(90deg, #e0e7ff, #eff6ff);
      border:1px solid #e5e7eb; border-radius:1rem;
    }

    /* Footer */
    footer{ background:#0b1220; color:#e5e7eb; }
    footer a{ color:#e5e7eb; }
  </style>
</head>
<body>

<!-- HERO -->
<section class="hero mb-4">
  <div class="blob"></div>
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <span class="badge chip rounded-pill mb-2">Rreth Qendrës</span>
        <h1 class="display-5 fw-bold mb-2">Qendra e Trajnimeve të Avancuara</h1>
        <p class="lead mb-0">Ndërtojmë aftësi praktike për tregun e punës, me programe fleksibël, instruktorë profesionistë dhe certifikim të verifikueshëm me QR.</p>
      </div>
      <div class="col-lg-5">
        <div class="glass p-3 p-md-4">
          <div class="d-flex align-items-center">
            <div class="feature icon me-3"><i class="bi bi-mortarboard fs-4"></i></div>
            <div>
              <div class="fw-semibold">Misioni ynë</div>
              <div class="small">T’u japim kursantëve kompetenca reale për karrierë – shpejt, qartë dhe me standarde të larta.</div>
            </div>
          </div>
          <div class="d-flex gap-2 mt-3">
            <a href="selectProfile.php" class="btn btn-light text-primary"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr në sistem</a>
            <a href="verify.php" class="btn btn-outline-light"><i class="bi bi-qr-code-scan me-1"></i> Verifiko certifikatën</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- VLERAT / VIZIONI -->
<section class="py-4">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-4">
        <div class="card h-100 value p-3">
          <div class="card-body">
            <div class="icon mb-3"><i class="bi bi-bullseye fs-5"></i></div>
            <h5 class="fw-bold">Vizioni</h5>
            <p class="small-muted mb-0">Të bëhemi referenca për trajnime praktike dhe certifikime të sigurta në Shqipëri dhe rajon.</p>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card h-100 value p-3">
          <div class="card-body">
            <div class="icon mb-3" style="background:#fff1f2;color:#ef4444;"><i class="bi bi-flag fs-5"></i></div>
            <h5 class="fw-bold">Vlerat</h5>
            <p class="small-muted mb-0">Integritet, cilësi mësimore, transparencë me palët dhe respekt për kursantin.</p>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card h-100 value p-3">
          <div class="card-body">
            <div class="icon mb-3" style="background:#ecfeff;color:#0891b2;"><i class="bi bi-shield-check fs-5"></i></div>
            <h5 class="fw-bold">Siguria e certifikatës</h5>
            <p class="small-muted mb-0">Çdo certifikatë ka QR unik. Skanoni dhe krahasoni emrin për të shmangur abuzimet.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- PSE QTA -->
<section class="py-4">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-body p-4">
            <h3 class="fw-bold mb-3"><i class="bi bi-stars me-2 text-primary"></i>Pse të zgjidhni QTA</h3>
            <ul class="list-unstyled mb-0">
              <li class="d-flex align-items-start mb-3">
                <div class="feature icon me-3"><i class="bi bi-person-workspace fs-5"></i></div>
                <div>
                  <div class="fw-semibold">Instruktorë me eksperiencë reale</div>
                  <div class="small-muted">Të gjithë pedagogët tanë vijnë nga industria dhe fokusohen në praktikë.</div>
                </div>
              </li>
              <li class="d-flex align-items-start mb-3">
                <div class="feature icon me-3" style="background:#ecfdf5;color:#16a34a;"><i class="bi bi-diagram-3 fs-5"></i></div>
                <div>
                  <div class="fw-semibold">Kurrikula e modularizuar</div>
                  <div class="small-muted">Modulet përzgjidhen sipas nevojës – nga bazat te specializimet.</div>
                </div>
              </li>
              <li class="d-flex align-items-start">
                <div class="feature icon me-3" style="background:#fff1f2;color:#ef4444;"><i class="bi bi-qr-code fs-5"></i></div>
                <div>
                  <div class="fw-semibold">Certifikim i verifikueshëm</div>
                  <div class="small-muted">Verifikim publik në <a href="verify.php">verify.php</a> për transparencë me punëdhënësit.</div>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Impakti me statistika -->
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-body p-4">
            <h3 class="fw-bold mb-3"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Impakt & shtrirje</h3>
            <div class="row text-center g-3">
              <div class="col-6 col-md-3">
                <div class="stat-number" data-target="<?= (int)$counts['students'] ?>">0</div>
                <div class="small-muted">Studentë</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="stat-number" data-target="<?= (int)$counts['agencies'] ?>">0</div>
                <div class="small-muted">Agjenci</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="stat-number" data-target="<?= (int)$counts['admins'] ?>">0</div>
                <div class="small-muted">Administratorë</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="stat-number" data-target="<?= (int)$counts['users'] ?>">0</div>
                <div class="small-muted">Përdorues</div>
              </div>
            </div>
            <div class="small-muted mt-3"><i class="bi bi-info-circle me-1"></i>Statistikat përditësohen automatikisht nga databaza.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- HISTORIKU -->
<section class="py-4">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-body p-4">
            <h3 class="fw-bold mb-3"><i class="bi bi-clock-history me-2 text-primary"></i>Historiku i shkurtër</h3>
            <div class="timeline">
              <div class="timeline-item">
                <div class="fw-semibold">Themelimi i QTA</div>
                <div class="small-muted">Nisja si një nismë e vogël trajnimesh praktike për profesionet më të kërkuara.</div>
              </div>
              <div class="timeline-item">
                <div class="fw-semibold">Shtrirja me partnerë</div>
                <div class="small-muted">Bashkëpunime me agjenci punësimi dhe kompani për praktikë & punësim.</div>
              </div>
              <div class="timeline-item">
                <div class="fw-semibold">Platforma digjitale</div>
                <div class="small-muted">Menaxhim kurse-sh, grupe-sh dhe rezultate-sh në një sistem të unifikuar.</div>
              </div>
              <div class="timeline-item">
                <div class="fw-semibold">Verifikimi me QR</div>
                <div class="small-muted">Certifikata me token unik; verifikim publik për të parandaluar abuzimet.</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- EKIPI -->
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-body p-4">
            <h3 class="fw-bold mb-3"><i class="bi bi-people me-2 text-primary"></i>Ekipi drejtues</h3>

            <div class="d-flex align-items-center mb-3">
              <div class="avatar me-3">EM</div>
              <div>
                <div class="fw-semibold">Emër Mbiemër</div>
                <div class="small-muted">Drejtues Akademik</div>
              </div>
              <span class="badge ms-auto badge-soft">Akademi</span>
            </div>

            <div class="d-flex align-items-center mb-3">
              <div class="avatar me-3" style="background:#ecfdf5;color:#16a34a;">OP</div>
              <div>
                <div class="fw-semibold">Emër Mbiemër</div>
                <div class="small-muted">Operacione & Partneritete</div>
              </div>
              <span class="badge ms-auto badge-soft">Partneritet</span>
            </div>

            <div class="d-flex align-items-center">
              <div class="avatar me-3" style="background:#fff1f2;color:#ef4444;">IT</div>
              <div>
                <div class="fw-semibold">Emër Mbiemër</div>
                <div class="small-muted">Infrastrukturë & Platformë</div>
              </div>
              <span class="badge ms-auto badge-soft">Teknologji</span>
            </div>

            <div class="small-muted mt-3">*Emrat janë vendmbajtës; zëvendësojini me ekipin tuaj real.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- PARTNERË / AKREDITIME (placeholder) -->
<section class="py-4">
  <div class="container">
    <div class="card">
      <div class="card-body p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
          <div class="small-muted">Partnerë & bashkëpunëtorë:</div>
          <span class="badge rounded-pill text-bg-light border">Agjencia A</span>
          <span class="badge rounded-pill text-bg-light border">Kompania B</span>
          <span class="badge rounded-pill text-bg-light border">Instituti C</span>
          <span class="badge rounded-pill text-bg-light border">Organizata D</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="py-4">
  <div class="container">
    <div class="cta d-flex flex-column flex-md-row align-items-center justify-content-between p-3 p-md-4">
      <div class="d-flex align-items-center">
        <div class="me-3 d-none d-md-block"><i class="bi bi-briefcase fs-2 text-primary"></i></div>
        <div>
          <div class="fw-semibold">Gati për bashkëpunim?</div>
          <div class="small-muted">Na shkruani për programe të dedikuara, trajnime in-house ose partneritete.</div>
        </div>
      </div>
      <div class="mt-3 mt-md-0 d-flex gap-2">
        <a href="contact.html" class="btn btn-primary"><i class="bi bi-envelope me-1"></i> Na kontaktoni</a>
        <a href="verify.php" class="btn btn-outline-primary"><i class="bi bi-qr-code-scan me-1"></i> Verifiko certifikatën</a>
      </div>
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="py-5 mt-4">
  <div class="container">
    <div class="row gy-4">
      <div class="col-lg-4">
        <h5>Qendra e Trajnimeve të Avancuara</h5>
        <p class="small">Edukimi cilësor për profesionistët e së nesërmes.</p>
        <div class="d-flex gap-3">
          <a href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
          <a href="#" aria-label="Twitter"><i class="bi bi-twitter"></i></a>
          <a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
          <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
        </div>
      </div>
      <div class="col-lg-4">
        <h5>Lidhje të shpejta</h5>
        <ul class="list-unstyled small">
          <li><a class="text-decoration-none" href="index.php">Kryefaqja</a></li>
          <li><a class="text-decoration-none" href="aboutus.php">Rreth Nesh</a></li>
          <li><a class="text-decoration-none" href="contact.html">Kontakt</a></li>
          <li><a class="text-decoration-none" href="verify.php">Verifiko Certifikatën</a></li>
          <li><a class="text-decoration-none" href="selectProfile.php">Hyr në sistem</a></li>
        </ul>
      </div>
      <div class="col-lg-4">
        <h5>Na gjeni këtu</h5>
        <p class="small mb-1"><i class="bi bi-geo-alt me-2"></i>Rruga Bilal Konxholli, Tiranë</p>
        <p class="small mb-1"><i class="bi bi-telephone me-2"></i>+355 69 877 8837</p>
        <p class="small mb-0"><i class="bi bi-envelope me-2"></i>officialqta@gmail.com</p>
      </div>
    </div>
    <hr class="my-4" style="opacity:.2;">
    <p class="text-center small mb-0">&copy; <?= date('Y') ?> Qendra e Trajnimeve të Avancuara. Të gjitha të drejtat e rezervuara.</p>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Animimi i numrave (si te index.php)
  function animateValue(el, end, duration){
    let start = 0;
    const range = end - start;
    const stepTime = range > 0 ? Math.max(Math.floor(duration / range), 12) : 12;
    const timer = setInterval(function(){
      start += 1;
      el.textContent = start.toLocaleString('sq-AL');
      if (start >= end) clearInterval(timer);
    }, stepTime);
  }
  function isInViewport(element) {
    const rect = element.getBoundingClientRect();
    return rect.top < (window.innerHeight || document.documentElement.clientHeight) && rect.bottom >= 0;
  }
  let statsAnimated = false;
  function tryAnimateStats(){
    const anyStat = document.querySelector('.stat-number');
    if(!anyStat) return;
    const cont = anyStat.closest('.card') || anyStat.closest('.container') || anyStat;
    if(!statsAnimated && isInViewport(cont)){
      document.querySelectorAll('.stat-number').forEach(el=>{
        const target = parseInt(el.getAttribute('data-target') || el.dataset.target || '0', 10);
        animateValue(el, target, 1200);
      });
      statsAnimated = true;
    }
  }
  window.addEventListener('scroll', tryAnimateStats);
  window.addEventListener('load', tryAnimateStats);
</script>
</body>
</html>
