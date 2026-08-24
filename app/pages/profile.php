<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ---------------------------------
   Guard: përdorues i loguar
---------------------------------- */
if (!isset($_SESSION['user_id'])) {
  header('Location: selectProfile.php'); exit;
}

/* Lexo user-in & rolin */
$st = $pdo->prepare("
  SELECT u.id, u.role_id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :uid
  LIMIT 1
");
$st->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $st->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) { header('Location: selectProfile.php'); exit; }

$ROLE = (string)$currentUser['role_name']; // 'administrator' | 'editor' | 'agjencia' | 'student'
$IS_STAFF = in_array($ROLE, ['administrator','editor'], true);

/* Ngarko rekorde shtesë sipas rolit (read-only për agency/student) */
$agency = null; $student = null; $staffExtra = null;

if ($ROLE === 'agjencia') {
  $q = $pdo->prepare("SELECT id, user_id, company_name, nip_t, address, phone FROM agencies WHERE user_id=:u LIMIT 1");
  $q->execute([':u' => $currentUser['id']]); $agency = $q->fetch(PDO::FETCH_ASSOC);
}
elseif ($ROLE === 'student') {
  $q = $pdo->prepare("SELECT id, user_id, first_name, father_name, last_name, nr_amze, personal_number, birth_date, birth_place, education_level_id
                      FROM students WHERE user_id=:u LIMIT 1");
  $q->execute([':u' => $currentUser['id']]); $student = $q->fetch(PDO::FETCH_ASSOC);
}
elseif ($IS_STAFF) {
  // Përdorim të njëjtën tabelë 'admins' si storage për info shtesë të stafit (admin + editor)
  $q = $pdo->prepare("SELECT id, user_id, employee_code FROM admins WHERE user_id=:u LIMIT 1");
  $q->execute([':u' => $currentUser['id']]); $staffExtra = $q->fetch(PDO::FETCH_ASSOC);
}

/* Helpers */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function flash(string $k, ?string $m=null){
  if ($m===null){ if(!empty($_SESSION['flash'][$k])){ $x=$_SESSION['flash'][$k]; unset($_SESSION['flash'][$k]); return $x; } return null; }
  $_SESSION['flash'][$k]=$m;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ---------------------------------
   POST: veprime sipas rolit
   (ADMIN dhe EDITOR me të njëjtat të drejta këtu)
---------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf'] ?? '';
  if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(400); exit('CSRF token mismatch.');
  }

  $action = $_POST['action'] ?? '';
  try {
    /* 1) STAF (admin + editor): update info bazë (full_name, email) */
    if ($action === 'update_user_info') {
      if (!$IS_STAFF) { throw new RuntimeException('Nuk lejohet.'); }
      $full_name = trim($_POST['full_name'] ?? '');
      $email     = trim($_POST['email'] ?? '');
      if ($full_name === '') throw new RuntimeException('Emri nuk mund të jetë bosh.');
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email i pavlefshëm.');

      // Unik për email (lejo veten)
      if ($email !== '') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=:e AND id<>:id");
        $chk->execute([':e'=>$email, ':id'=>$currentUser['id']]);
        if ((int)$chk->fetchColumn() > 0) throw new RuntimeException('Ky email është i zënë.');
      }

      $up = $pdo->prepare("UPDATE users SET full_name=:n, email=:e WHERE id=:id");
      $up->execute([':n'=>$full_name, ':e'=>($email!==''?$email:null), ':id'=>$currentUser['id']]);

      flash('ok', 'Profili u përditësua.');
      header('Location: profile.php'); exit;
    }

    /* 2) STAF (admin + editor): update info shtesë (employee_code) */
    if ($action === 'update_staff_extra') {
      if (!$IS_STAFF) { throw new RuntimeException('Nuk lejohet.'); }
      $employee_code = trim($_POST['employee_code'] ?? '');
      // nëse ekziston rresht te admins -> update, ndryshe krijo
      if ($staffExtra) {
        $up = $pdo->prepare("UPDATE admins SET employee_code=:c WHERE user_id=:u");
        $up->execute([':c'=>($employee_code!==''?$employee_code:null), ':u'=>$currentUser['id']]);
      } else {
        $ins = $pdo->prepare("INSERT INTO admins(user_id, employee_code) VALUES(:u, :c)");
        $ins->execute([':u'=>$currentUser['id'], ':c'=>($employee_code!==''?$employee_code:null)]);
      }
      flash('ok', 'Të dhënat e stafit u ruajtën.');
      header('Location: profile.php'); exit;
    }

    /* 3) NDRYSHIMI I FJALËKALIMIT (lejohet për të gjithë rolet) */
    if ($action === 'change_password') {
      $old = $_POST['old_password'] ?? '';
      $p1  = $_POST['new_password'] ?? '';
      $p2  = $_POST['new_password2'] ?? '';
      if ($p1 === '' || $p2 === '') throw new RuntimeException('Plotëso fjalëkalimin e ri.');
      if ($p1 !== $p2) throw new RuntimeException('Fjalëkalimet e reja nuk përputhen.');
      if (mb_strlen($p1) < 6) throw new RuntimeException('Fjalëkalimi duhet të ketë të paktën 6 karaktere.');

      // lexo hash ekzistues
      $c = $pdo->prepare("SELECT password_hash FROM credentials WHERE user_id=:u");
      $c->execute([':u'=>$currentUser['id']]);
      $hashOld = $c->fetchColumn();

      if ($hashOld) {
        if ($old === '' || !password_verify($old, $hashOld)) {
          throw new RuntimeException('Fjalëkalimi aktual është i pasaktë.');
        }
      } // nëse nuk ka rresht credentials: lejo pa verifikim të vjetrës

      $newHash = password_hash($p1, PASSWORD_BCRYPT);
      if ($hashOld) {
        $u = $pdo->prepare("UPDATE credentials SET password_hash=:h, last_password_change=NOW() WHERE user_id=:u");
        $u->execute([':h'=>$newHash, ':u'=>$currentUser['id']]);
      } else {
        $i = $pdo->prepare("INSERT INTO credentials(user_id, password_hash, last_password_change) VALUES(:u,:h,NOW())");
        $i->execute([':u'=>$currentUser['id'], ':h'=>$newHash]);
      }

      flash('ok', 'Fjalëkalimi u ndryshua me sukses.');
      header('Location: profile.php'); exit;
    }

    // Nëse arrihet këtu pa përputhje veprimi:
    throw new RuntimeException('Veprim i panjohur.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    header('Location: profile.php'); exit;
  }
}

/* View helpers */
$NAV_ACTIVE = 'profile';

$pageTitle = 'Profili – QTA';
require __DIR__ . '/../shared/app_head.php';
?>


<?php
// Ngarko navbar sipas rolit
if ($ROLE === 'administrator')      { require __DIR__ . '/inc/navbar.php';   }
elseif ($ROLE === 'editor')         { require __DIR__ . '/inc/navbar4.php';  }
elseif ($ROLE === 'agjencia')       { require __DIR__ . '/inc/navbar2.php';  }
elseif ($ROLE === 'student')        { require __DIR__ . '/inc/navbar3.php';  }
?>

<main class="app-main">

  <!-- HERO -->
  <div class="title-block">
    <div class="title-block-main">
      <div class="title-block-eyebrow">Profili</div>
      <h1>Përshëndetje, <?= h($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Përdorues')) ?></h1>
      <p class="title-block-note">Të dhënat e llogarisë tënde. Çfarë mund të ndryshosh varet nga roli.</p>
    </div>
    <div class="title-block-fields">
      <div class="title-block-field">
        <span class="label">Roli</span>
        <span class="value" style="font-family:var(--font-record)"><?= h(ucfirst((string)$ROLE)) ?></span>
      </div>
    </div>
  </div>

  <?php if ($m = flash('ok')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="bi bi-check-circle me-1"></i><?= h($m) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if ($m = flash('err')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle me-1"></i><?= h($m) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Kolona majtas: Info bazë (STAF editable) / read-only për agjenci & student -->
    <div class="col-12 col-xl-7">
      <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Të dhënat e përdoruesit</h5>
          <span class="text-muted small">Roli: <?= h($ROLE) ?></span>
        </div>
        <div class="card-body">
          <?php if ($IS_STAFF): ?>
            <form method="post" class="row g-3">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="update_user_info">
              <div class="col-md-6">
                <label class="form-label">Emri i plotë</label>
                <input type="text" name="full_name" class="form-control" value="<?= h((string)$currentUser['full_name']) ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= h((string)$currentUser['email']) ?>" placeholder="p.sh. staf@qta.al">
                <div class="form-text">Duhet të jetë unik në sistem.</div>
              </div>
              <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Ruaj ndryshimet</button>
              </div>
            </form>

            <hr class="my-4" />

            <form method="post" class="row g-3">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="update_staff_extra">
              <div class="col-md-6">
                <label class="form-label">Kodi i punonjësit (opsional)</label>
                <input type="text" name="employee_code" class="form-control" value="<?= h($staffExtra['employee_code'] ?? '') ?>">
              </div>
              <div class="col-12">
                <button class="btn btn-outline-secondary"><i class="bi bi-save me-1"></i>Ruaj të dhënat e stafit</button>
              </div>
            </form>

          <?php elseif ($ROLE === 'agjencia' && $agency): ?>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Emri i agjencisë</label>
                <input type="text" class="form-control" value="<?= h($agency['company_name'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">NIPT</label>
                <input type="text" class="form-control" value="<?= h($agency['nip_t'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">Telefon</label>
                <input type="text" class="form-control" value="<?= h($agency['phone'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">Email (nëse ka)</label>
                <input type="text" class="form-control" value="<?= h((string)$currentUser['email']) ?>" disabled>
              </div>
              <div class="col-12">
                <label class="form-label">Adresë</label>
                <textarea class="form-control" rows="2" disabled><?= h($agency['address'] ?? '') ?></textarea>
              </div>
              <div class="col-12">
                <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>
                  Modifikimi i të dhënave të agjencisë bëhet nga administratorët. Ju mund të ndryshoni vetëm fjalëkalimin.
                </div>
              </div>
            </div>

          <?php elseif ($ROLE === 'student' && $student): ?>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Emër</label>
                <input type="text" class="form-control" value="<?= h($student['first_name'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-4">
                <label class="form-label">Atësi</label>
                <input type="text" class="form-control" value="<?= h($student['father_name'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-4">
                <label class="form-label">Mbiemër</label>
                <input type="text" class="form-control" value="<?= h($student['last_name'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">Nr. AMZË</label>
                <input type="text" class="form-control" value="<?= h($student['nr_amze'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">ID personale</label>
                <input type="text" class="form-control" value="<?= h($student['personal_number'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">Datëlindja</label>
                <input type="text" class="form-control" value="<?= h($student['birth_date'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">Vendlindja</label>
                <input type="text" class="form-control" value="<?= h($student['birth_place'] ?? '') ?>" disabled>
              </div>
              <div class="col-12">
                <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>
                  Modifikimi i të dhënave personale bëhet nga administratorët. Ju mund të ndryshoni vetëm fjalëkalimin.
                </div>
              </div>
            </div>

          <?php else: ?>
            <div class="alert alert-secondary mb-0">Nuk ka të dhëna shtesë për t'u shfaqur.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Kolona djathtas: Ndrysho fjalëkalimin (të gjithë rolet) -->
    <div class="col-12 col-xl-5">
      <div class="card">
        <div class="card-header bg-white">
          <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Ndrysho fjalëkalimin</h5>
        </div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="col-12">
              <label class="form-label">Fjalëkalimi aktual</label>
              <input type="password" name="old_password" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fjalëkalimi i ri</label>
              <input type="password" name="new_password" class="form-control" minlength="6" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Përsërit fjalëkalimin e ri</label>
              <input type="password" name="new_password2" class="form-control" minlength="6" required>
            </div>
            <div class="col-12">
              <button class="btn btn-primary"><i class="bi bi-key me-1"></i>Ruaj fjalëkalimin</button>
              <div class="form-text mt-1">Minimum 6 karaktere. Ndryshimi regjistrohet te <code>credentials.last_password_change</code>.</div>
            </div>
          </form>
        </div>
      </div>

      <!-- Kartë e vogël informuese -->
      <div class="card mt-4">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="me-3 text-primary"><i class="bi bi-info-circle fs-4"></i></div>
            <div class="small text-muted">
              Nëse je agjenci ose student dhe do të ndryshosh të dhënat e tjera (p.sh. adresë, emër), kontakto administratorin.
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
