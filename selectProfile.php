<?php
declare(strict_types=1);
session_start();

// (Optional) prefokusimi i tab-it me ?role=administrator|agjencia|student
$activeRole = $_GET['role'] ?? 'administrator';
$validRoles = ['administrator','agjencia','student'];
if (!in_array($activeRole, $validRoles, true)) $activeRole = 'administrator';

// Merr mesazhin e gabimit (nëse ka) nga login_handler.php
$loginError = null;
if (!empty($_SESSION['login_error'])) {
  $loginError = $_SESSION['login_error'];
  unset($_SESSION['login_error']);
}

// Helper i vogël
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Zgjidh profilin – QTA</title>

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>

  <style>
    :root{
      --grad-1:#0ea5e9; --grad-2:#2563eb; --grad-3:#4f46e5;
      --glass-bg: rgba(255,255,255,.72);
      --glass-bd: rgba(255,255,255,.65);
      --shadow: 0 20px 45px rgba(2,6,23,.15);
    }
    body{
      min-height:100vh; background:#0b1220; color:#0f172a;
      background:
        radial-gradient(1200px 420px at 10% -10%, rgba(37,99,235,.25), rgba(37,99,235,0) 60%),
        radial-gradient(900px 320px at 90% -5%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, #0ea5e9 0%, #2563eb 55%, #4f46e5 100%);
    }
    .navbar { backdrop-filter: blur(6px); background: rgba(0,0,0,.35)!important; }
    .navbar .nav-link, .navbar-brand { color:#fff!important; }
    .btn-primary { background:#2563eb; border-color:#2563eb; }
    .btn-success { background:#16a34a; border-color:#16a34a; }
    .btn-info { background:#0ea5e9; border-color:#0ea5e9; }

    .hero{
      color:#fff; padding:56px 0 24px;
    }
    .hero h1{ font-weight:800; letter-spacing:.2px; }
    .chip{
      background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.35);
      color:#fff; display:inline-block; padding:.35rem .65rem; border-radius:9999px;
    }

    .glass{
      background:var(--glass-bg); border:1px solid var(--glass-bd);
      border-radius:1.25rem; box-shadow:var(--shadow); backdrop-filter: blur(12px);
    }
    .glass .card-header{ background:transparent; border:none; }
    .tab-icon{ width:38px; height:38px; border-radius:.75rem; display:inline-flex; align-items:center; justify-content:center; background:#eef2ff; }
    .nav-pills .nav-link{ border-radius:.85rem; padding:.55rem .9rem; }
    .nav-pills .nav-link.active{ background:#111827; color:#fff; }

    .form-control, .input-group-text{
      border:1px solid rgba(15,23,42,.1); background:#fff; border-radius:.8rem;
    }
    .input-group-text{ background:#f8fafc; }
    .form-control:focus{ border-color:#2563eb; box-shadow: 0 0 0 .25rem rgba(37,99,235,.15); }

    .hint{ color:#334155; font-size:.92rem; }
    .footer{
      color:#e5e7eb; background: rgba(0,0,0,.35); backdrop-filter: blur(8px);
    }

    /* micro-anim */
    .lift:hover{ transform: translateY(-4px); transition:.2s ease; }
  </style>
</head>
<body>

<!-- Navbar (si index.php – look & feel i errët mbi gradient) -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <img src="image/logoPNG2.png" alt="Logo" height="30" class="me-2"> Qendra e Trajnimeve të Avancuara (QTA)
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nv">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nv" class="collapse navbar-collapse">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="index.php">Kryefaqja</a></li>
        <li class="nav-item"><a class="nav-link" href="#">Rreth nesh</a></li>
        <li class="nav-item"><a class="nav-link" href="contact.html">Kontakt</a></li>
        <li class="nav-item ms-lg-2"><a class="btn btn-primary" href="selectProfile.php">Hyr</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- HERO -->
<section class="hero text-center">
  <div class="container">
    <span class="chip mb-2">Hyrje në portal</span>
    <h1 class="display-5 mb-2">Zgjidh rolin dhe hyr në QTA</h1>
    <p class="lead" style="opacity:.95">
      Qasja & funksionalitetet përcaktohen nga roli juaj: Administrator, Agjenci ose Student.
    </p>
  </div>
</section>

<!-- CARD: Tabs + Forms -->
<section class="pb-5">
  <div class="container">
    <div class="glass p-3 p-md-4 lift">
      <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between">
        <h5 class="mb-2 mb-md-0"><i class="bi bi-door-open me-2"></i> Zgjidh profilin</h5>
        <ul class="nav nav-pills" id="roleTabs" role="tablist">
          <li class="nav-item me-1">
            <button class="nav-link <?= $activeRole==='administrator'?'active':'' ?>" id="tab-admin" data-bs-toggle="pill" data-bs-target="#pane-admin" type="button" role="tab">
              <span class="tab-icon me-2"><i class="bi bi-person-gear"></i></span> Administrator
            </button>
          </li>
          <li class="nav-item me-1">
            <button class="nav-link <?= $activeRole==='agjencia'?'active':'' ?>" id="tab-agency" data-bs-toggle="pill" data-bs-target="#pane-agency" type="button" role="tab">
              <span class="tab-icon me-2" style="background:#ecfdf5"><i class="bi bi-building text-success"></i></span> Agjenci
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link <?= $activeRole==='student'?'active':'' ?>" id="tab-student" data-bs-toggle="pill" data-bs-target="#pane-student" type="button" role="tab">
              <span class="tab-icon me-2" style="background:#e0f2fe"><i class="bi bi-mortarboard text-info"></i></span> Student
            </button>
          </li>
        </ul>
      </div>

      <div class="card-body pt-3">
        <?php if ($loginError): ?>
          <div class="alert alert-danger mb-4"><i class="bi bi-exclamation-triangle me-1"></i><?= h($loginError) ?></div>
        <?php endif; ?>

        <div class="tab-content">
          <!-- ADMIN -->
          <div class="tab-pane fade <?= $activeRole==='administrator'?'show active':'' ?>" id="pane-admin" role="tabpanel" aria-labelledby="tab-admin">
            <div class="row g-4 align-items-center">
              <div class="col-lg-6">
                <form action="login_handler.php" method="post" autocomplete="off" class="needs-validation" novalidate>
                  <input type="hidden" name="role" value="administrator">
                  <div class="mb-3">
                    <label class="form-label">Email (admin)</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                      <input type="email" name="identifier" class="form-control" placeholder="shembull@email.com" required>
                      <div class="invalid-feedback">Shkruani email të vlefshëm.</div>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Fjalëkalimi</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="bi bi-lock"></i></span>
                      <input type="password" id="adminPass" name="password" class="form-control" placeholder="********" required>
                      <button class="btn btn-outline-secondary" type="button" onclick="togglePwd('adminPass', this)"><i class="bi bi-eye"></i></button>
                      <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
                    </div>
                  </div>
                  <div class="d-grid">
                    <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Hyr si Administrator</button>
                  </div>
                  <div class="d-flex justify-content-between mt-2">
                    <a href="#" class="link-dark small">Keni harruar fjalëkalimin?</a>
                    <span class="small text-muted">Qasje e plotë në sistem</span>
                  </div>
                </form>
              </div>
              <div class="col-lg-6">
                <div class="p-3 p-md-4 rounded-4" style="background:#f8fafc;border:1px dashed #e5e7eb;">
                  <div class="h6 mb-2"><i class="bi bi-stars me-1 text-primary"></i> Çfarë mund të bëni?</div>
                  <ul class="mb-0 text-muted">
                    <li>Menaxhimi i të gjithë përdoruesve dhe roleve</li>
                    <li>Krijim/ndryshim modulësh dhe grupeve</li>
                    <li>Raporte & statistika të avancuara</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <!-- AGENCY -->
          <div class="tab-pane fade <?= $activeRole==='agjencia'?'show active':'' ?>" id="pane-agency" role="tabpanel" aria-labelledby="tab-agency">
            <div class="row g-4 align-items-center">
              <div class="col-lg-6">
                <form action="login_handler.php" method="post" autocomplete="off" class="needs-validation" novalidate>
                  <input type="hidden" name="role" value="agjencia">
                  <div class="mb-3">
                    <label class="form-label">NIPT</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="bi bi-building"></i></span>
                      <input type="text" name="identifier" class="form-control" placeholder="p.sh. L12345678Q" required>
                      <div class="invalid-feedback">Shkruani NIPT-in.</div>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Fjalëkalimi</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="bi bi-lock"></i></span>
                      <input type="password" id="agencyPass" name="password" class="form-control" placeholder="********" required>
                      <button class="btn btn-outline-secondary" type="button" onclick="togglePwd('agencyPass', this)"><i class="bi bi-eye"></i></button>
                      <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
                    </div>
                  </div>
                  <div class="d-grid">
                    <button class="btn btn-success btn-lg" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Hyr si Agjenci</button>
                  </div>
                  <div class="d-flex justify-content-between mt-2">
                    <a href="#" class="link-dark small">S’mbani mend fjalëkalimin?</a>
                    <span class="small text-muted">Menaxho studentët tuaj</span>
                  </div>
                </form>
              </div>
              <div class="col-lg-6">
                <div class="p-3 p-md-4 rounded-4" style="background:#ecfeff;border:1px dashed #99f6e4;">
                  <div class="h6 mb-2"><i class="bi bi-clipboard2-check me-1 text-success"></i> Për agjencitë</div>
                  <ul class="mb-0 text-muted">
                    <li>Shto/hiq studentë nga agjencia jote</li>
                    <li>Shiko grupet, testet & kapacitetet</li>
                    <li>Analiza performance për studentët tuaj</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <!-- STUDENT -->
          <div class="tab-pane fade <?= $activeRole==='student'?'show active':'' ?>" id="pane-student" role="tabpanel" aria-labelledby="tab-student">
            <div class="row g-4 align-items-center">
              <div class="col-lg-6">
                <form action="login_handler.php" method="post" autocomplete="off" class="needs-validation" novalidate>
                  <input type="hidden" name="role" value="student">
                  <div class="mb-3">
                    <label class="form-label">Numri personal</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                      <input type="text" name="identifier" class="form-control" placeholder="ID personale" required>
                      <div class="invalid-feedback">Shkruani numrin personal.</div>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Fjalëkalimi</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="bi bi-lock"></i></span>
                      <input type="password" id="studentPass" name="password" class="form-control" placeholder="********" required>
                      <button class="btn btn-outline-secondary" type="button" onclick="togglePwd('studentPass', this)"><i class="bi bi-eye"></i></button>
                      <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
                    </div>
                  </div>
                  <div class="d-grid">
                    <button class="btn btn-info text-white btn-lg" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Hyr si Student</button>
                  </div>
                  <div class="d-flex justify-content-between mt-2">
                    <a href="#" class="link-dark small">Harruat fjalëkalimin?</a>
                    <a href="#" class="link-dark small">Regjistrohu si student i ri</a>
                  </div>
                </form>
              </div>
              <div class="col-lg-6">
                <div class="p-3 p-md-4 rounded-4" style="background:#eef2ff;border:1px dashed #c7d2fe;">
                  <div class="h6 mb-2"><i class="bi bi-mortarboard me-1 text-primary"></i> Për studentët</div>
                  <ul class="mb-0 text-muted">
                    <li>Shiko notat, kurset dhe provimet</li>
                    <li>Shkarko kartelën dhe QR personal</li>
                    <li>Qasje e shpejtë nga celulari</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
          <!-- /tab content -->
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer class="footer py-4 mt-auto">
  <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
    <div>&copy; <?= date('Y') ?> Qendra e Trajnimeve të Avancuara. Të gjitha të drejtat e rezervuara.</div>
    <div class="small">Rr. Bilal Konxholli, Tiranë · <a class="text-white text-decoration-none" href="mailto:officialqta@gmail.com">officialqta@gmail.com</a></div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Aktivizo tab-in nga ?role=
  (function(){
    const params = new URLSearchParams(location.search);
    const role = params.get('role');
    if(!role) return;
    const map = {administrator:'tab-admin', agjencia:'tab-agency', student:'tab-student'};
    const id = map[role];
    if(id){ document.getElementById(id)?.click(); }
  })();

  // Toggle password eye
  function togglePwd(id, btn){
    const i = document.getElementById(id);
    if(!i) return;
    const is = i.getAttribute('type')==='password';
    i.setAttribute('type', is ? 'text' : 'password');
    btn.querySelector('i').className = is ? 'bi bi-eye-slash' : 'bi bi-eye';
  }

  // Bootstrap validation
  (() => {
    'use strict';
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (!form.checkValidity()) {
          event.preventDefault(); event.stopPropagation();
        }
        form.classList.add('was-validated');
      }, false);
    });
  })();
</script>
</body>
</html>
