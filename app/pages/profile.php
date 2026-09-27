<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);
require_once __DIR__ . '/../shared/themeli.php';

/* ---------------------------------
   Guard: përdorues i loguar
---------------------------------- */
if (!isset($_SESSION['user_id'])) {
  header('Location: selectProfile.php'); exit;
}

/* Lexo user-in & rolin */
$st = $pdo->prepare("
  SELECT u.id, u.role_id, u.full_name, u.email, u.created_at, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :uid
  LIMIT 1
");
$st->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $st->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) { header('Location: selectProfile.php'); exit; }

$ROLE = strtolower((string)$currentUser['role_name']); // 'administrator' | 'editor' | 'agjencia' | 'student'
$IS_STAFF = in_array($ROLE, ['administrator', 'editor'], true);

/* Të dhënat sipas rolit (vetëm për lexim për agjencinë dhe kursantin) */
$agency = null; $person = null; $amzeList = []; $staffExtra = null;

if ($ROLE === 'agjencia') {
  $q = $pdo->prepare("SELECT id, user_id, company_name, nip_t, address, phone FROM agencies WHERE user_id=:u LIMIT 1");
  $q->execute([':u' => $currentUser['id']]);
  $agency = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
elseif ($ROLE === 'student') {
  /* Të dhënat personale janë te "persons"; një person mund të ketë disa regjistrime (amza). */
  $q = $pdo->prepare("
    SELECT p.first_name, p.father_name, p.last_name, p.personal_number, p.birth_date, p.birth_place, p.phone,
           s.nr_amze
    FROM students s
    JOIN persons p ON p.id = s.person_id
    WHERE s.user_id = :u
    ORDER BY CAST(s.nr_amze AS UNSIGNED), s.nr_amze
  ");
  $q->execute([':u' => $currentUser['id']]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  if ($rows) {
    $person = $rows[0];
    $amzeList = array_values(array_unique(array_filter(array_map(static fn($r) => (string)$r['nr_amze'], $rows))));
  }
}
elseif ($ROLE === 'administrator') {
  $q = $pdo->prepare("SELECT id, user_id, employee_code FROM admins WHERE user_id=:u LIMIT 1");
  $q->execute([':u' => $currentUser['id']]);
  $staffExtra = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Kur u ndryshua fjalëkalimi së fundi */
$pc = $pdo->prepare("SELECT last_password_change FROM credentials WHERE user_id=:u");
$pc->execute([':u' => $currentUser['id']]);
$lastPasswordChange = $pc->fetchColumn() ?: null;

/* Helpers */
function flash(string $k, ?string $m = null) {
  if ($m === null) { if (!empty($_SESSION['flash'][$k])) { $x = $_SESSION['flash'][$k]; unset($_SESSION['flash'][$k]); return $x; } return null; }
  $_SESSION['flash'][$k] = $m;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ---------------------------------
   POST: veprime sipas rolit
---------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf'] ?? '';
  if (empty($token) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
    flash('err', 'Faqja ka qëndruar e hapur shumë gjatë. Provo sërish.');
    header('Location: profile.php'); exit;
  }

  $action = $_POST['action'] ?? '';
  try {
    /* 1) Stafi: emri dhe email-i */
    if ($action === 'update_user_info') {
      if (!$IS_STAFF) { throw new RuntimeException('Këto të dhëna i ndryshon stafi i QTA.'); }
      $full_name = trim((string)($_POST['full_name'] ?? ''));
      $email     = trim((string)($_POST['email'] ?? ''));
      if ($full_name === '') throw new RuntimeException('Shkruaj emrin tënd.');
      if ($email === '') throw new RuntimeException('Shkruaj email-in — me të hyn në portal.');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email-i nuk duket i saktë. Kontrolloje, p.sh. emri@qta.al.');

      $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=:e AND id<>:id");
      $chk->execute([':e' => $email, ':id' => $currentUser['id']]);
      if ((int)$chk->fetchColumn() > 0) throw new RuntimeException('Ky email përdoret nga një llogari tjetër.');

      $up = $pdo->prepare("UPDATE users SET full_name=:n, email=:e WHERE id=:id");
      $up->execute([':n' => $full_name, ':e' => $email, ':id' => $currentUser['id']]);

      flash('ok', 'Të dhënat e tua u ruajtën.' . ($email !== (string)$currentUser['email'] ? ' Herën tjetër hyr me email-in e ri.' : ''));
      header('Location: profile.php'); exit;
    }

    /* 2) Administratori: kodi i punonjësit */
    if ($action === 'update_staff_extra') {
      if ($ROLE !== 'administrator') { throw new RuntimeException('Kodi i punonjësit vlen vetëm për administratorët.'); }
      $employee_code = trim((string)($_POST['employee_code'] ?? ''));
      if ($staffExtra) {
        $up = $pdo->prepare("UPDATE admins SET employee_code=:c WHERE user_id=:u");
        $up->execute([':c' => ($employee_code !== '' ? $employee_code : null), ':u' => $currentUser['id']]);
      } else {
        $ins = $pdo->prepare("INSERT INTO admins(user_id, employee_code) VALUES(:u, :c)");
        $ins->execute([':u' => $currentUser['id'], ':c' => ($employee_code !== '' ? $employee_code : null)]);
      }
      flash('ok', 'Kodi i punonjësit u ruajt.');
      header('Location: profile.php'); exit;
    }

    /* 3) Fjalëkalimi (të gjitha rolet) */
    if ($action === 'change_password') {
      $old = (string)($_POST['old_password'] ?? '');
      $p1  = (string)($_POST['new_password'] ?? '');
      $p2  = (string)($_POST['new_password2'] ?? '');
      if ($p1 === '' || $p2 === '') throw new RuntimeException('Shkruaj fjalëkalimin e ri dy herë.');
      if ($p1 !== $p2) throw new RuntimeException('Dy fjalëkalimet e reja nuk janë njësoj. Shkruaji sërish.');
      if (mb_strlen($p1) < 8) throw new RuntimeException('Fjalëkalimi i ri duhet të ketë të paktën 8 shenja.');
      if ($old !== '' && hash_equals($old, $p1)) throw new RuntimeException('Fjalëkalimi i ri duhet të jetë i ndryshëm nga ai aktual.');

      $c = $pdo->prepare("SELECT password_hash FROM credentials WHERE user_id=:u");
      $c->execute([':u' => $currentUser['id']]);
      $hashOld = $c->fetchColumn();

      if ($hashOld) {
        if ($old === '' || !password_verify($old, (string)$hashOld)) {
          throw new RuntimeException('Fjalëkalimi aktual nuk është i saktë.');
        }
      } // pa rresht te credentials: lejohet pa fjalëkalimin e vjetër

      $newHash = password_hash($p1, PASSWORD_BCRYPT);
      if ($hashOld) {
        $u = $pdo->prepare("UPDATE credentials SET password_hash=:h, last_password_change=NOW() WHERE user_id=:u");
        $u->execute([':h' => $newHash, ':u' => $currentUser['id']]);
      } else {
        $i = $pdo->prepare("INSERT INTO credentials(user_id, password_hash, last_password_change) VALUES(:u,:h,NOW())");
        $i->execute([':u' => $currentUser['id'], ':h' => $newHash]);
      }

      flash('ok', 'Fjalëkalimi u ndryshua. Herën tjetër hyr me fjalëkalimin e ri.');
      header('Location: profile.php'); exit;
    }

    throw new RuntimeException('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    header('Location: profile.php'); exit;
  }
}

/* ---------------------------------
   Faqja
---------------------------------- */
$NAV_ACTIVE = 'profile';
$HELP_TOPIC = 'profile';
if ($ROLE === 'administrator')  { require __DIR__ . '/inc/navbar.php';  }
elseif ($ROLE === 'editor')     { require __DIR__ . '/inc/navbar4.php'; }
elseif ($ROLE === 'agjencia')   { require __DIR__ . '/inc/navbar2.php'; }
elseif ($ROLE === 'student')    { require __DIR__ . '/inc/navbar3.php'; }

$pageTitle = 'Profili im';
require __DIR__ . '/../shared/app_head.php';

$displayName = $ROLE === 'agjencia'
  ? (string)($agency['company_name'] ?? $currentUser['full_name'] ?? 'Agjencia')
  : ($person ? qta_full_name($person['first_name'] ?? '', $person['father_name'] ?? '', $person['last_name'] ?? '') : (string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Llogaria ime')));
$roleLabel = function_exists('qta_app_role_label') ? qta_app_role_label($ROLE) : ucfirst($ROLE);
$loginHint = [
  'administrator' => 'Hyn me email-in dhe fjalëkalimin.',
  'editor'        => 'Hyn me email-in dhe fjalëkalimin.',
  'agjencia'      => 'Hyn me NIPT-in dhe fjalëkalimin.',
  'student'       => 'Hyn me numrin personal dhe fjalëkalimin.',
][$ROLE] ?? '';
$flashOk  = flash('ok');
$flashErr = flash('err');
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <div class="person-head">
        <span class="avatar avatar-xl" aria-hidden="true"><?= h(qta_initials($displayName)) ?></span>
        <div class="min-w-0">
          <span class="eyebrow mb-0"><?= h($roleLabel) ?></span>
          <h1 class="page-title"><?= h($displayName) ?></h1>
        </div>
      </div>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
    </div>
  </header>

  <?php if ($flashOk): ?>
    <div class="alert alert-success" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><?= h($flashOk) ?></div></div>
  <?php endif; ?>
  <?php if ($flashErr): ?>
    <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><?= h($flashErr) ?></div></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-xl-7">

      <section class="section" aria-labelledby="meTitle">
        <div class="section-head">
          <h2 class="section-title" id="meTitle">Të dhënat e mia</h2>
        </div>

        <?php if ($IS_STAFF): ?>
          <form class="panel" method="post" action="profile.php" data-loading>
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="update_user_info">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="pfName">Emri dhe mbiemri</label>
                <input id="pfName" type="text" name="full_name" class="form-control" value="<?= h((string)$currentUser['full_name']) ?>" autocomplete="name" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="pfEmail">Email-i</label>
                <input id="pfEmail" type="email" name="email" class="form-control" value="<?= h((string)$currentUser['email']) ?>" autocomplete="email" aria-describedby="pfEmailHelp" required>
                <div class="form-text" id="pfEmailHelp">Me këtë email hyn në portal.</div>
              </div>
              <div class="col-12">
                <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i>Ruaj ndryshimet</button>
              </div>
            </div>
          </form>

          <?php if ($ROLE === 'administrator'): ?>
            <form class="panel mt-3" method="post" action="profile.php" data-loading>
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="action" value="update_staff_extra">
              <div class="row g-3 align-items-end">
                <div class="col-sm-7">
                  <label class="form-label" for="pfCode">Kodi i punonjësit <span class="optional">(nëse keni)</span></label>
                  <input id="pfCode" type="text" name="employee_code" class="form-control input-code" value="<?= h((string)($staffExtra['employee_code'] ?? '')) ?>">
                </div>
                <div class="col-sm-5">
                  <button class="btn btn-secondary w-100" type="submit">Ruaj kodin</button>
                </div>
              </div>
            </form>
          <?php endif; ?>

        <?php elseif ($ROLE === 'agjencia' && $agency): ?>
          <div class="panel">
            <dl class="kv">
              <dt>Kompania</dt><dd><?= h((string)($agency['company_name'] ?: '—')) ?></dd>
              <dt>NIPT</dt><dd class="code"><?= h((string)($agency['nip_t'] ?: '—')) ?></dd>
              <dt>Telefoni</dt><dd><?= h((string)($agency['phone'] ?: '—')) ?></dd>
              <dt>Adresa</dt><dd><?= h((string)($agency['address'] ?: '—')) ?></dd>
              <?php if (!empty($currentUser['email'])): ?><dt>Email-i</dt><dd><?= h((string)$currentUser['email']) ?></dd><?php endif; ?>
            </dl>
          </div>
          <div class="notice is-sunken mt-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>Këto të dhëna i ndryshon QTA. Nëse diçka nuk është e saktë, <a href="contact.php">na shkruani</a>.</span>
          </div>

        <?php elseif ($ROLE === 'student' && $person): ?>
          <div class="panel">
            <dl class="kv">
              <dt>Emri i plotë</dt><dd><?= h($displayName !== '' ? $displayName : '—') ?></dd>
              <dt>Numri personal</dt><dd class="code"><?= h((string)($person['personal_number'] ?: '—')) ?></dd>
              <dt>Datëlindja</dt><dd><?= h(qta_date($person['birth_date'] ?? null)) ?></dd>
              <dt>Vendlindja</dt><dd><?= h((string)($person['birth_place'] ?: '—')) ?></dd>
              <?php if (!empty($person['phone'])): ?><dt>Telefoni</dt><dd><?= h((string)$person['phone']) ?></dd><?php endif; ?>
              <dt>Nr. i amzës</dt><dd class="code"><?= h($amzeList ? implode(', ', $amzeList) : '—') ?></dd>
            </dl>
          </div>
          <div class="notice is-sunken mt-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>Të dhënat personale i ndryshon QTA. Nëse diçka nuk është e saktë, <a href="contact.php">na shkruani</a>.</span>
          </div>

        <?php else: ?>
          <?= qta_empty('Nuk ka të dhëna për të shfaqur', 'Nëse mendon se është gabim, na kontakto.', 'bi-person', '<a class="btn btn-secondary" href="contact.php">Kontakt</a>', 'is-compact') ?>
        <?php endif; ?>
      </section>

      <section class="section" aria-labelledby="lookTitle">
        <div class="section-head">
          <h2 class="section-title" id="lookTitle">Pamja</h2>
        </div>
        <div class="panel">
          <p class="text-muted small mb-3">Zgjidh si të duket portali. "Sipas pajisjes" ndjek cilësimin e telefonit ose kompjuterit.</p>
          <div class="segmented" role="radiogroup" aria-label="Pamja e portalit">
            <button type="button" role="radio" aria-checked="false" data-theme-set="light"><i class="bi bi-sun" aria-hidden="true"></i>E çelët</button>
            <button type="button" role="radio" aria-checked="false" data-theme-set="system"><i class="bi bi-circle-half" aria-hidden="true"></i>Sipas pajisjes</button>
            <button type="button" role="radio" aria-checked="false" data-theme-set="dark"><i class="bi bi-moon-stars" aria-hidden="true"></i>E errët</button>
          </div>
        </div>
      </section>
    </div>

    <div class="col-12 col-xl-5">
      <section class="section" aria-labelledby="pwTitle">
        <div class="section-head">
          <h2 class="section-title" id="pwTitle">Fjalëkalimi</h2>
          <?php if ($lastPasswordChange): ?>
            <span class="section-meta">Ndryshuar më <?= h(qta_date(substr((string)$lastPasswordChange, 0, 10))) ?></span>
          <?php endif; ?>
        </div>
        <form class="panel" method="post" action="profile.php" data-loading id="pwForm">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="change_password">
          <?php if ($ROLE === 'student'): ?>
            <div class="callout is-info mb-3">
              <span class="callout-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
              <div class="callout-body">
                <span class="callout-text">Nëse po përdor ende fjalëkalimin që të dha QTA, ndryshoje tani me një që e di vetëm ti.</span>
              </div>
            </div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label" for="pwOld">Fjalëkalimi aktual</label>
            <div class="password-field">
              <input id="pwOld" type="password" name="old_password" class="form-control" autocomplete="current-password" required>
              <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#pwOld" aria-label="Shfaq fjalëkalimin" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="pwNew">Fjalëkalimi i ri</label>
            <div class="password-field">
              <input id="pwNew" type="password" name="new_password" class="form-control" minlength="8" autocomplete="new-password" aria-describedby="pwNewHelp" required>
              <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#pwNew" aria-label="Shfaq fjalëkalimin" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <div class="form-text" id="pwNewHelp">Të paktën 8 shenja. Një fjali e shkurtër mbahet mend më lehtë, p.sh. "Kafe3herenëjavë".</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="pwNew2">Shkruaje sërish fjalëkalimin e ri</label>
            <div class="password-field">
              <input id="pwNew2" type="password" name="new_password2" class="form-control" minlength="8" autocomplete="new-password" required>
              <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#pwNew2" aria-label="Shfaq fjalëkalimin" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <div class="invalid-feedback">Dy fjalëkalimet e reja nuk janë njësoj.</div>
          </div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-key" aria-hidden="true"></i>Ndrysho fjalëkalimin</button>
          <?php if ($loginHint !== ''): ?>
            <p class="form-text mb-0 mt-2"><?= h($loginHint) ?></p>
          <?php endif; ?>
        </form>
      </section>
    </div>
  </div>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<script>
(function(){
  /* Dy fjalëkalimet e reja duhet të jenë njësoj */
  const p1 = document.getElementById('pwNew'), p2 = document.getElementById('pwNew2');
  if (!p1 || !p2) return;
  const check = () => {
    const bad = p2.value !== '' && p1.value !== p2.value;
    p2.classList.toggle('is-invalid', bad);
    p2.setCustomValidity(bad ? 'Dy fjalëkalimet e reja nuk janë njësoj.' : '');
  };
  p1.addEventListener('input', check);
  p2.addEventListener('input', check);
})();
</script>
</body>
</html>
