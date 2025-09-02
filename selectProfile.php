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
  $loginError = $_SESSION['login_error'];
  unset($_SESSION['login_error']);
}

/* CSRF i thjeshtë (opsional) – nëse login_handler nuk e përdor, thjesht injorohet */
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
  <title>Zgjidh profilin – QTA</title>

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>

  <style>
    :root{
      --qta-primary:#2563eb; --qta-sec:#4f46e5; --qta-accent:#0ea5e9;
      --card-glass-bg: rgba(255,255,255,.80);
      --card-glass-bd: rgba(255,255,255,.65);
      --shadow-xl: 0 25px 60px rgba(2,6,23,.18);
    }
    body{
      min-height:100vh; background:#0b1220; color:#0f172a;
      background:
        radial-gradient(1400px 480px at 12% -12%, rgba(37,99,235,.28), rgba(37,99,235,0) 60%),
        radial-gradient(1000px 360px at 88% -6%, rgba(99,102,241,.24), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, #0ea5e9 0%, #2563eb 55%, #4f46e5 100%);
    }
    .hero{
      color:#fff; padding:56px 0 18px;
    }
    .chip{
      background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.35);
      color:#fff; display:inline-block; padding:.35rem .65rem; border-radius:9999px;
    }
    .glass{
      background:var(--card-glass-bg); border:1px solid var(--card-glass-bd);
      border-radius:1.25rem; box-shadow:var(--shadow-xl); backdrop-filter: blur(12px);
    }
    .role-pill{
      border:1px solid #e5e7eb; background:#fff; color:#0f172a;
      border-radius:.85rem; padding:.6rem .85rem; transition:.18s ease;
    }
    .role-pill.active, .role-pill:hover{ background:#111827; color:#fff; border-color:#111827; }
    .role-icon{
      width:38px; height:38px; border-radius:.75rem; display:inline-flex;
      align-items:center; justify-content:center; background:#eef2ff;
    }
    .role-icon.editor{ background:#fff7ed; }
    .role-icon.agency{ background:#ecfdf5; }
    .role-icon.student{ background:#e0f2fe; }
    .role-help{ color:#475569; font-size:.92rem; }
    .form-control, .input-group-text{ border-radius:.8rem; }
    .form-control{ border:1px solid rgba(15,23,42,.12); background:#fff; }
    .input-group-text{ background:#f8fafc; border:1px solid rgba(15,23,42,.12); }
    .form-control:focus{ border-color:var(--qta-primary); box-shadow:0 0 0 .25rem rgba(37,99,235,.12); }
    .btn-primary{ background:var(--qta-primary); border-color:var(--qta-primary); }
    .btn-warning{ background:#f59e0b; border-color:#f59e0b; color:#111827; }
    .btn-success{ background:#16a34a; border-color:#16a34a; }
    .btn-info{ background:#0ea5e9; border-color:#0ea5e9; }
    .lift:hover{ transform: translateY(-4px); transition:transform .18s ease; }
    .caps-hint{ display:none; color:#b91c1c; font-size:.85rem; margin-top:.25rem; }
    .toast-container{ position:fixed; top:84px; right:16px; z-index:1080; }
  </style>
</head>
<body>

<!-- HERO -->
<section class="hero text-center">
  <div class="container">
    <span class="chip mb-2">QTA • Porta e përdoruesit</span>
    <h1 class="display-6 fw-bold mb-2">Zgjidh rolin dhe hyr në sistem</h1>
    <p class="lead" style="opacity:.95">Qasja dhe veprimet varen nga roli juaj: Administrator, Editor, Agjenci ose Student.</p>
  </div>
</section>

<!-- CARD: Role chooser + Dynamic forms -->
<section class="pb-5">
  <div class="container">
    <div class="glass p-3 p-md-4 lift">
      <div class="row g-4 align-items-stretch">
        <!-- Left: Role selector -->
        <div class="col-lg-5">
          <div class="h5 mb-3"><i class="bi bi-door-open me-2"></i>Zgjidh rolin</div>

          <div class="d-grid gap-2">
            <button class="role-pill d-flex align-items-center justify-content-between <?= $activeRole==='administrator'?'active':'' ?>" data-role="administrator" type="button">
              <span class="d-flex align-items-center gap-2">
                <span class="role-icon"><i class="bi bi-person-gear"></i></span>
                <strong>Administrator</strong>
              </span>
              <i class="bi bi-chevron-right"></i>
            </button>
            <button class="role-pill d-flex align-items-center justify-content-between <?= $activeRole==='editor'?'active':'' ?>" data-role="editor" type="button">
              <span class="d-flex align-items-center gap-2">
                <span class="role-icon editor"><i class="bi bi-pencil-square text-warning"></i></span>
                <strong>Editor</strong>
              </span>
              <i class="bi bi-chevron-right"></i>
            </button>
            <button class="role-pill d-flex align-items-center justify-content-between <?= $activeRole==='agjencia'?'active':'' ?>" data-role="agjencia" type="button">
              <span class="d-flex align-items-center gap-2">
                <span class="role-icon agency"><i class="bi bi-building text-success"></i></span>
                <strong>Agjenci</strong>
              </span>
              <i class="bi bi-chevron-right"></i>
            </button>
            <button class="role-pill d-flex align-items-center justify-content-between <?= $activeRole==='student'?'active':'' ?>" data-role="student" type="button">
              <span class="d-flex align-items-center gap-2">
                <span class="role-icon student"><i class="bi bi-mortarboard text-info"></i></span>
                <strong>Student</strong>
              </span>
              <i class="bi bi-chevron-right"></i>
            </button>
          </div>

          <hr class="my-4">
          <div class="role-help">
            <div class="d-flex align-items-start gap-2 mb-2">
              <i class="bi bi-info-circle mt-1"></i>
              <div>
                <strong>Si funksionon?</strong><br/>
                Zgjidh rolin në të majtë – formulari në të djathtë përshtatet automatikisht (Email për admin/editor, NIPT për agjenci, Numër personal për student).
              </div>
            </div>
            <div class="d-flex align-items-start gap-2">
              <i class="bi bi-shield-lock mt-1"></i>
              <div>
                <strong>Siguri:</strong> Fjalëkalimi mund të shihet përkohësisht me ikonën “sy”. Aktivizohet paralajmërimi kur keni <em>Caps Lock</em>.
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Forms -->
        <div class="col-lg-7">
          <div class="card border-0 h-100">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="mb-0"><i class="bi bi-box-arrow-in-right me-2"></i>Hyr në QTA</h5>
                <div class="btn-group btn-group-sm" role="group" aria-label="Shkurtore">
                  <a class="btn btn-outline-dark" href="?role=administrator">Admin</a>
                  <a class="btn btn-outline-dark" href="?role=editor">Editor</a>
                  <a class="btn btn-outline-dark" href="?role=agjencia">Agjenci</a>
                  <a class="btn btn-outline-dark" href="?role=student">Student</a>
                </div>
              </div>

              <!-- ADMIN FORM -->
              <form class="login-form <?= $activeRole==='administrator'?'':'d-none' ?>" data-role="administrator" action="login_handler.php" method="post" autocomplete="off" novalidate>
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
                    <button class="btn btn-outline-secondary" type="button" data-toggle="pw" data-target="#pwd-admin"><i class="bi bi-eye"></i></button>
                    <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
                  </div>
                  <div class="caps-hint" id="caps-admin"><i class="bi bi-exclamation-triangle me-1"></i>Caps Lock i aktivizuar</div>
                </div>
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember-admin" name="remember">
                    <label class="form-check-label" for="remember-admin">Më mbaj mend</label>
                  </div>
                  <a href="#" class="small link-dark">Keni harruar fjalëkalimin?</a>
                </div>
                <div class="d-grid">
                  <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-person-check me-1"></i> Hyr si Administrator</button>
                </div>
              </form>

              <!-- EDITOR FORM -->
              <form class="login-form <?= $activeRole==='editor'?'':'d-none' ?>" data-role="editor" action="login_handler.php" method="post" autocomplete="off" novalidate>
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
                    <button class="btn btn-outline-secondary" type="button" data-toggle="pw" data-target="#pwd-editor"><i class="bi bi-eye"></i></button>
                    <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
                  </div>
                  <div class="caps-hint" id="caps-editor"><i class="bi bi-exclamation-triangle me-1"></i>Caps Lock i aktivizuar</div>
                </div>
                <div class="d-grid">
                  <button class="btn btn-warning btn-lg" type="submit"><i class="bi bi-pencil-square me-1"></i> Hyr si Editor</button>
                </div>
              </form>

              <!-- AGENCY FORM -->
              <form class="login-form <?= $activeRole==='agjencia'?'':'d-none' ?>" data-role="agjencia" action="login_handler.php" method="post" autocomplete="off" novalidate>
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
                    <button class="btn btn-outline-secondary" type="button" data-toggle="pw" data-target="#pwd-agency"><i class="bi bi-eye"></i></button>
                    <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
                  </div>
                  <div class="caps-hint" id="caps-agency"><i class="bi bi-exclamation-triangle me-1"></i>Caps Lock i aktivizuar</div>
                </div>
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <a href="#" class="small link-dark">S’mbani mend fjalëkalimin?</a>
                  <span class="small text-muted">Menaxho studentët e agjencisë</span>
                </div>
                <div class="d-grid">
                  <button class="btn btn-success btn-lg" type="submit"><i class="bi bi-building-check me-1"></i> Hyr si Agjenci</button>
                </div>
              </form>

              <!-- STUDENT FORM -->
              <form class="login-form <?= $activeRole==='student'?'':'d-none' ?>" data-role="student" action="login_handler.php" method="post" autocomplete="off" novalidate>
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
                    <button class="btn btn-outline-secondary" type="button" data-toggle="pw" data-target="#pwd-student"><i class="bi bi-eye"></i></button>
                    <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
                  </div>
                  <div class="caps-hint" id="caps-student"><i class="bi bi-exclamation-triangle me-1"></i>Caps Lock i aktivizuar</div>
                </div>
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <a href="#" class="small link-dark">Harruat fjalëkalimin?</a>
                  <a href="#" class="small link-dark">Regjistrohu si student i ri</a>
                </div>
                <div class="d-grid">
                  <button class="btn btn-info text-white btn-lg" type="submit"><i class="bi bi-mortarboard me-1"></i> Hyr si Student</button>
                </div>
              </form>

            </div>
          </div>
        </div>
      </div> <!-- /row -->
    </div>
  </div>
</section>

<!-- Toast gabimi (nëse ka) -->
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
  // Aktivizo rolin nga ?role=
  (function(){
    const params = new URLSearchParams(location.search);
    const role = params.get('role');
    if (!role) return;
    const btn = document.querySelector(`.role-pill[data-role="${role}"]`);
    if (btn) btn.click();
  })();

  // Ndrysho formën kur klikohen rolet në të majtë
  document.querySelectorAll('.role-pill').forEach(btn => {
    btn.addEventListener('click', () => {
      const role = btn.getAttribute('data-role');
      // vizual: active state
      document.querySelectorAll('.role-pill').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      // shfaq formën korresponduese
      document.querySelectorAll('.login-form').forEach(f => {
        f.classList.toggle('d-none', f.getAttribute('data-role') !== role);
      });
      // update url (pa reload)
      const url = new URL(window.location);
      url.searchParams.set('role', role);
      window.history.replaceState({}, '', url);
    });
  });

  // Toggle password eye
  document.querySelectorAll('[data-toggle="pw"]').forEach(t => {
    t.addEventListener('click', ()=>{
      const target = document.querySelector(t.getAttribute('data-target'));
      if (!target) return;
      const isPwd = target.getAttribute('type')==='password';
      target.setAttribute('type', isPwd ? 'text' : 'password');
      const i = t.querySelector('i');
      if (i) i.className = isPwd ? 'bi bi-eye-slash' : 'bi bi-eye';
      target.focus();
    });
  });

  // Caps Lock detection per role-pwd
  function bindCaps(el, hintId){
    const hint = document.getElementById(hintId);
    if(!hint) return;
    el.addEventListener('keyup', (e)=>{ if (e.getModifierState && e.getModifierState('CapsLock')) hint.style.display='block'; else hint.style.display='none'; });
    el.addEventListener('keydown', (e)=>{ if (e.getModifierState && e.getModifierState('CapsLock')) hint.style.display='block'; else hint.style.display='none'; });
    el.addEventListener('blur', ()=> hint.style.display='none');
  }
  bindCaps(document.getElementById('pwd-admin')  || {addEventListener:()=>{}}, 'caps-admin');
  bindCaps(document.getElementById('pwd-editor') || {addEventListener:()=>{}}, 'caps-editor');
  bindCaps(document.getElementById('pwd-agency') || {addEventListener:()=>{}}, 'caps-agency');
  bindCaps(document.getElementById('pwd-student')|| {addEventListener:()=>{}}, 'caps-student');

  // Bootstrap validation
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
