<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/navbarMain.php';

/* Prefokusim i rolit me ?role=administrator|editor|agjencia|student */
$activeRole = $_GET['role'] ?? 'administrator';
$validRoles = ['administrator','editor','agjencia','student'];
if (!in_array($activeRole, $validRoles, true)) $activeRole = 'administrator';

/* Merr gabimin nga login_handler (nëse ka) */
$loginError = null;
if (!empty($_SESSION['login_error'])) {
  $loginError = (string)$_SESSION['login_error'];
  unset($_SESSION['login_error']);
}

/* CSRF i thjeshtë */
if (empty($_SESSION['csrf_login'])) { $_SESSION['csrf_login'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_login'];

/* Helper */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hyr në QTA</title>
  <link rel="icon" type="image/svg+xml" href="image/logoPNG - Copy.png">

  <!-- Bootstrap & Icons -->
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
      --warning:#f59e0b;
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

    .hero{
      position:relative;
      overflow:hidden;
      padding: 58px 0 34px;
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

    .badge-chip{
      border: 1px solid rgba(148,163,184,.25);
      background: rgba(255,255,255,.55);
      color: var(--text);
    }
    html[data-theme="dark"] .badge-chip{ background: rgba(15,23,42,.6); }

    .headline{ letter-spacing:-0.02em; }
    .text-muted2{ color: var(--muted); }

    .iconbox{
      width:54px;height:54px;
      border-radius: 16px;
      display:flex;align-items:center;justify-content:center;
      background: rgba(37,99,235,.10);
      border: 1px solid rgba(37,99,235,.18);
      color: var(--primary);
      flex:0 0 auto;
    }

    /* Role selector (left) */
    .role-tile{
      width:100%;
      text-align:left;
      border-radius: 16px;
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.55);
      padding: 14px 14px;
      display:flex;
      gap:.9rem;
      align-items:center;
      transition: transform .16s ease, box-shadow .16s ease, background .16s ease, border-color .16s ease;
    }
    html[data-theme="dark"] .role-tile{ background: rgba(15,23,42,.65); }

    .role-tile:hover{
      transform: translateY(-3px);
      box-shadow: var(--shadow2);
    }
    .role-tile.active{
      border-color: rgba(37,99,235,.35);
      background: rgba(37,99,235,.08);
      box-shadow: 0 0 0 10px var(--ring);
    }

    .role-ic{
      width:44px;height:44px;border-radius: 14px;
      display:flex;align-items:center;justify-content:center;
      border:1px solid rgba(148,163,184,.22);
      background: rgba(255,255,255,.65);
      color: var(--text);
      flex:0 0 auto;
    }
    html[data-theme="dark"] .role-ic{ background: rgba(15,23,42,.7); }

    .role-ic.admin{ border-color: rgba(37,99,235,.20); color: var(--primary); background: rgba(37,99,235,.10); }
    .role-ic.editor{ border-color: rgba(245,158,11,.22); color: var(--warning); background: rgba(245,158,11,.10); }
    .role-ic.agency{ border-color: rgba(16,185,129,.22); color: var(--success); background: rgba(16,185,129,.10); }
    .role-ic.student{ border-color: rgba(14,165,233,.20); color: var(--accent); background: rgba(14,165,233,.10); }

    .role-sub{ font-size:.92rem; color: var(--muted); }
    html[data-theme="dark"] .role-sub{ color: var(--muted); }

    /* Form UI */
    .form-control, .input-group-text, .btn{
      border-radius: 14px;
    }
    .input-group-text{
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.55);
      color: var(--text);
    }
    html[data-theme="dark"] .input-group-text{ background: rgba(15,23,42,.65); }

    .form-control{
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.55);
      color: var(--text);
    }
    html[data-theme="dark"] .form-control{ background: rgba(15,23,42,.65); }

    .form-control:focus{
      border-color: rgba(37,99,235,.35);
      box-shadow: 0 0 0 10px var(--ring);
      background: rgba(255,255,255,.75);
    }
    html[data-theme="dark"] .form-control:focus{ background: rgba(15,23,42,.75); }

    .caps-hint{ display:none; color: var(--danger); font-size:.88rem; margin-top:.35rem; }

    .action-bar .btn{
      padding: .75rem 1rem;
      border-radius: 14px;
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

    /* Toast */
    .toast-container{
      position:fixed;
      top:84px;
      right:16px;
      z-index:1080;
    }

    @media (max-width: 991px){
      .hero{ padding: 48px 0 26px; }
    }
  </style>
</head>

<body>

<!-- HERO -->
<section class="hero">
  <div class="container container-max hero-inner">
    <div class="row align-items-center g-3">
      <div class="col-lg-8">
        <span class="badge badge-chip rounded-pill mb-3">
          <i class="bi bi-door-open me-1"></i> QTA • Porta e përdoruesit
        </span>

        <h1 class="display-5 fw-bold headline mb-2">Hyr në sistem sipas rolit</h1>
        <p class="lead mb-0 text-muted2">
          UI përshtatet automatikisht: Email për Administrator/Editor, NIPT për Agjenci, Numër Personal për Student.
        </p>

        <div class="mt-3 d-flex flex-wrap gap-2">
          <span class="mini-pill"><i class="bi bi-shield-lock"></i> Least privilege</span>
          <span class="mini-pill"><i class="bi bi-lightning-charge"></i> Hyrje e shpejtë</span>
          <span class="mini-pill"><i class="bi bi-person-badge"></i> Role të qarta</span>
        </div>
      </div>

      <div class="col-lg-4 d-flex justify-content-lg-end">
        <div class="d-flex gap-2 action-bar">
          <a href="verify.php" class="btn btn-outline-primary">
            <i class="bi bi-qr-code-scan me-1"></i> Verifikim publik
          </a>
          <button type="button" class="btn btn-outline-secondary" id="themeToggle" aria-label="Ndrysho temën">
            <i class="bi bi-moon-stars me-1"></i> Dark mode
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<br>

<!-- CONTENT -->
<section class="pb-5">
  <div class="container container-max">
    <div class="row g-4">

      <!-- LEFT: Role selector -->
      <div class="col-lg-5">
        <div class="soft-card p-4">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <div class="fw-semibold"><i class="bi bi-ui-checks-grid me-1"></i> Zgjidh rolin</div>
            <span class="badge text-bg-secondary" id="roleBadge"><?= h(ucfirst($activeRole)) ?></span>
          </div>

          <div class="d-grid gap-2">
            <button type="button" class="role-tile <?= $activeRole==='administrator'?'active':'' ?>" data-role="administrator">
              <span class="role-ic admin"><i class="bi bi-person-gear"></i></span>
              <span class="flex-grow-1">
                <span class="fw-semibold d-block">Administrator</span>
                <span class="role-sub">Menaxhim i plotë i sistemit</span>
              </span>
              <i class="bi bi-chevron-right opacity-75"></i>
            </button>

            <button type="button" class="role-tile <?= $activeRole==='editor'?'active':'' ?>" data-role="editor">
              <span class="role-ic editor"><i class="bi bi-pencil-square"></i></span>
              <span class="flex-grow-1">
                <span class="fw-semibold d-block">Editor</span>
                <span class="role-sub">Përmbajtje, procese dhe kontrolle</span>
              </span>
              <i class="bi bi-chevron-right opacity-75"></i>
            </button>

            <button type="button" class="role-tile <?= $activeRole==='agjencia'?'active':'' ?>" data-role="agjencia">
              <span class="role-ic agency"><i class="bi bi-building"></i></span>
              <span class="flex-grow-1">
                <span class="fw-semibold d-block">Agjenci</span>
                <span class="role-sub">Menaxho kursantët e tu</span>
              </span>
              <i class="bi bi-chevron-right opacity-75"></i>
            </button>

            <button type="button" class="role-tile <?= $activeRole==='student'?'active':'' ?>" data-role="student">
              <span class="role-ic student"><i class="bi bi-mortarboard"></i></span>
              <span class="flex-grow-1">
                <span class="fw-semibold d-block">Student</span>
                <span class="role-sub">Progresi, modulet, certifikata</span>
              </span>
              <i class="bi bi-chevron-right opacity-75"></i>
            </button>
          </div>

          <hr class="my-4">

          <div class="soft-card p-3">
            <div class="fw-semibold mb-1"><i class="bi bi-shield-check me-1"></i> Siguri & UX</div>
            <div class="small text-muted2">
              Ikona “sy” shfaq përkohësisht fjalëkalimin. Aktivizohet paralajmërimi kur është <em>Caps Lock</em>.
              Nëse gabon rolin, mjafton ta ndërroni majtas—forma përshtatet automatikisht.
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT: Form -->
      <div class="col-lg-7">
        <div class="soft-card p-4">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
              <div class="fw-semibold"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr në QTA</div>
              <div class="small text-muted2" id="roleHint">Zgjidh rolin për të parë fushat e sakta.</div>
            </div>

            <div class="btn-group btn-group-sm" role="group" aria-label="Shkurtore role">
              <a class="btn btn-outline-secondary" href="?role=administrator">Admin</a>
              <a class="btn btn-outline-secondary" href="?role=editor">Editor</a>
              <a class="btn btn-outline-secondary" href="?role=agjencia">Agjenci</a>
              <a class="btn btn-outline-secondary" href="?role=student">Student</a>
            </div>
          </div>

          <?php if ($loginError): ?>
            <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
              <i class="bi bi-exclamation-triangle mt-1"></i>
              <div>
                <div class="fw-semibold">Nuk u logove</div>
                <div><?= h($loginError) ?></div>
              </div>
            </div>
          <?php endif; ?>

          <!-- ADMIN -->
          <form class="login-form <?= $activeRole==='administrator'?'':'d-none' ?>" data-role="administrator"
                action="login_handler.php" method="post" autocomplete="off" novalidate>
            <input type="hidden" name="role" value="administrator">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

            <div class="mb-3">
              <label class="form-label">Email (admin)</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" name="identifier" class="form-control" placeholder="shembull@email.com" required>
                <div class="invalid-feedback">Shkruani email të vlefshëm.</div>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Fjalëkalimi</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="pwd-admin" class="form-control role-pwd" placeholder="********" required>
                <button class="btn btn-outline-secondary" type="button" data-toggle="pw" data-target="#pwd-admin" aria-label="Shfaq/Fsheh fjalëkalimin">
                  <i class="bi bi-eye"></i>
                </button>
                <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
              </div>
              <div class="caps-hint" id="caps-admin"><i class="bi bi-exclamation-triangle me-1"></i>Caps Lock i aktivizuar</div>
            </div>

            <div class="d-flex align-items-center justify-content-between mt-3 mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remember-admin" name="remember">
                <label class="form-check-label" for="remember-admin">Më mbaj mend</label>
              </div>
              <a href="#" class="small text-decoration-none">Harruat fjalëkalimin?</a>
            </div>

            <div class="d-grid">
              <button class="btn btn-primary btn-lg" type="submit">
                <i class="bi bi-person-check me-1"></i> Hyr si Administrator
              </button>
            </div>
          </form>

          <!-- EDITOR -->
          <form class="login-form <?= $activeRole==='editor'?'':'d-none' ?>" data-role="editor"
                action="login_handler.php" method="post" autocomplete="off" novalidate>
            <input type="hidden" name="role" value="editor">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

            <div class="mb-3">
              <label class="form-label">Email (editor)</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" name="identifier" class="form-control" placeholder="editor@email.com" required>
                <div class="invalid-feedback">Shkruani email të vlefshëm.</div>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Fjalëkalimi</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="pwd-editor" class="form-control role-pwd" placeholder="********" required>
                <button class="btn btn-outline-secondary" type="button" data-toggle="pw" data-target="#pwd-editor" aria-label="Shfaq/Fsheh fjalëkalimin">
                  <i class="bi bi-eye"></i>
                </button>
                <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
              </div>
              <div class="caps-hint" id="caps-editor"><i class="bi bi-exclamation-triangle me-1"></i>Caps Lock i aktivizuar</div>
            </div>

            <div class="d-grid mt-3">
              <button class="btn btn-warning btn-lg" type="submit">
                <i class="bi bi-pencil-square me-1"></i> Hyr si Editor
              </button>
            </div>
          </form>

          <!-- AGENCY -->
          <form class="login-form <?= $activeRole==='agjencia'?'':'d-none' ?>" data-role="agjencia"
                action="login_handler.php" method="post" autocomplete="off" novalidate>
            <input type="hidden" name="role" value="agjencia">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

            <div class="mb-3">
              <label class="form-label">NIPT</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-building"></i></span>
                <input type="text" name="identifier" class="form-control" placeholder="p.sh. L12345678Q" required>
                <div class="invalid-feedback">Shkruani NIPT-in.</div>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Fjalëkalimi</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="pwd-agency" class="form-control role-pwd" placeholder="********" required>
                <button class="btn btn-outline-secondary" type="button" data-toggle="pw" data-target="#pwd-agency" aria-label="Shfaq/Fsheh fjalëkalimin">
                  <i class="bi bi-eye"></i>
                </button>
                <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
              </div>
              <div class="caps-hint" id="caps-agency"><i class="bi bi-exclamation-triangle me-1"></i>Caps Lock i aktivizuar</div>
            </div>

            <div class="d-flex align-items-center justify-content-between mt-3 mb-3">
              <a href="#" class="small text-decoration-none">Harruat fjalëkalimin?</a>
              <span class="small text-muted2">Menaxho studentët e agjencisë</span>
            </div>

            <div class="d-grid">
              <button class="btn btn-success btn-lg" type="submit">
                <i class="bi bi-building-check me-1"></i> Hyr si Agjenci
              </button>
            </div>
          </form>

          <!-- STUDENT -->
          <form class="login-form <?= $activeRole==='student'?'':'d-none' ?>" data-role="student"
                action="login_handler.php" method="post" autocomplete="off" novalidate>
            <input type="hidden" name="role" value="student">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

            <div class="mb-3">
              <label class="form-label">Numri personal</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                <input type="text" name="identifier" class="form-control" placeholder="ID personale" required>
                <div class="invalid-feedback">Shkruani numrin personal.</div>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Fjalëkalimi</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="pwd-student" class="form-control role-pwd" placeholder="********" required>
                <button class="btn btn-outline-secondary" type="button" data-toggle="pw" data-target="#pwd-student" aria-label="Shfaq/Fsheh fjalëkalimin">
                  <i class="bi bi-eye"></i>
                </button>
                <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
              </div>
              <div class="caps-hint" id="caps-student"><i class="bi bi-exclamation-triangle me-1"></i>Caps Lock i aktivizuar</div>
            </div>

            <div class="d-flex align-items-center justify-content-between mt-3 mb-3">
              <a href="#" class="small text-decoration-none">Harruat fjalëkalimin?</a>
              <a href="#" class="small text-decoration-none">Regjistrohu si student i ri</a>
            </div>

            <div class="d-grid">
              <button class="btn btn-info btn-lg text-white" type="submit">
                <i class="bi bi-mortarboard me-1"></i> Hyr si Student
              </button>
            </div>
          </form>

          <hr class="my-4">

          <div class="soft-card p-3">
            <div class="fw-semibold mb-1"><i class="bi bi-lightbulb me-1"></i> Këshillë</div>
            <div class="small text-muted2">
              Nëse keni QR në certifikatë, mund ta verifikoni direkt te “Verifikim publik” pa hyrë në sistem.
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- Toast gabimi (opsional, për “feel” modern) -->
<div class="toast-container">
  <?php if ($loginError): ?>
    <div class="toast align-items-center text-bg-danger border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body"><i class="bi bi-exclamation-triangle me-2"></i><?= h($loginError) ?></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Mbyll"></button>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ===== Theme toggle (identik me index.php) ===== */
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

/* ===== Role switching ===== */
const roleBadge = document.getElementById('roleBadge');
const roleHint = document.getElementById('roleHint');

const roleMeta = {
  administrator: { badge:'Administrator', hint:'Hyr me email dhe fjalëkalim (admin).'},
  editor: { badge:'Editor', hint:'Hyr me email dhe fjalëkalim (editor).'},
  agjencia: { badge:'Agjenci', hint:'Hyr me NIPT dhe fjalëkalim.'},
  student: { badge:'Student', hint:'Hyr me numër personal dhe fjalëkalim.'},
};

function selectRole(role){
  document.querySelectorAll('.role-tile').forEach(b => b.classList.toggle('active', b.dataset.role === role));
  document.querySelectorAll('form.login-form').forEach(f => f.classList.toggle('d-none', f.dataset.role !== role));

  const m = roleMeta[role] || {badge: role, hint:''};
  if (roleBadge) roleBadge.textContent = m.badge;
  if (roleHint) roleHint.textContent = m.hint;

  const url = new URL(window.location);
  url.searchParams.set('role', role);
  window.history.replaceState({}, '', url);
}

document.querySelectorAll('.role-tile').forEach(btn => {
  btn.addEventListener('click', () => selectRole(btn.dataset.role));
});

/* Init from ?role */
(() => {
  const params = new URLSearchParams(location.search);
  const role = params.get('role');
  if (role && roleMeta[role]) selectRole(role);
  else {
    // fallback: find current visible form or active tile
    const active = document.querySelector('.role-tile.active')?.dataset.role || 'administrator';
    selectRole(active);
  }
})();

/* ===== Toggle password eye ===== */
document.querySelectorAll('[data-toggle="pw"]').forEach(t => {
  t.addEventListener('click', ()=>{
    const target = document.querySelector(t.getAttribute('data-target'));
    if (!target) return;
    const isPwd = target.getAttribute('type') === 'password';
    target.setAttribute('type', isPwd ? 'text' : 'password');
    const i = t.querySelector('i');
    if (i) i.className = isPwd ? 'bi bi-eye-slash' : 'bi bi-eye';
    target.focus();
  });
});

/* ===== Caps Lock detection ===== */
function bindCaps(el, hintId){
  const hint = document.getElementById(hintId);
  if(!el || !hint) return;
  const fn = (e) => {
    const on = !!(e.getModifierState && e.getModifierState('CapsLock'));
    hint.style.display = on ? 'block' : 'none';
  };
  el.addEventListener('keydown', fn);
  el.addEventListener('keyup', fn);
  el.addEventListener('blur', ()=> hint.style.display='none');
}
bindCaps(document.getElementById('pwd-admin'), 'caps-admin');
bindCaps(document.getElementById('pwd-editor'), 'caps-editor');
bindCaps(document.getElementById('pwd-agency'), 'caps-agency');
bindCaps(document.getElementById('pwd-student'), 'caps-student');

/* ===== Bootstrap validation ===== */
(() => {
  'use strict';
  document.querySelectorAll('form.login-form').forEach(form => {
    form.addEventListener('submit', ev => {
      if (!form.checkValidity()) {
        ev.preventDefault();
        ev.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>
