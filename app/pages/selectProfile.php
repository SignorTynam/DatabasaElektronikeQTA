<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);

/* Roli i parazgjedhur (?role=…). Administratori dhe editori hyjnë si "staf". */
$requested = (string)($_GET['role'] ?? 'staff');
$activeRole = match ($requested) {
  'agjencia' => 'agjencia',
  'student'  => 'student',
  default    => 'staff',
};

$loginError = null;
if (!empty($_SESSION['login_error'])) {
  $loginError = (string)$_SESSION['login_error'];
  unset($_SESSION['login_error']);
}
$rememberedId = '';
if (!empty($_SESSION['login_identifier'])) {
  $rememberedId = (string)$_SESSION['login_identifier'];
  unset($_SESSION['login_identifier']);
}

if (empty($_SESSION['csrf_login'])) {
  $_SESSION['csrf_login'] = bin2hex(random_bytes(24));
}
$CSRF = $_SESSION['csrf_login'];

$roles = [
  'staff' => [
    'title' => 'Staf i QTA-së',
    'text'  => 'Hyj me email-in e punës',
    'icon'  => 'bi-person-workspace',
    'label' => 'Email-i i punës',
    'type'  => 'email',
    'placeholder' => 'emri@qta.al',
    'help'  => 'Email-i me të cilin të ka regjistruar administratori i QTA-së.',
    'forgot' => 'Kërkoji administratorit të QTA-së të ta rivendosë fjalëkalimin.',
  ],
  'agjencia' => [
    'title' => 'Agjenci',
    'text'  => 'Hyj me NIPT-in e kompanisë',
    'icon'  => 'bi-building',
    'label' => 'NIPT-i i agjencisë',
    'type'  => 'text',
    'placeholder' => 'p.sh. L12345678Q',
    'help'  => 'NIPT-i ka 10 shenja: një shkronjë, tetë shifra dhe një shkronjë.',
    'forgot' => 'Për siguri, fjalëkalimin e rivendos vetëm QTA. Na telefono ose na shkruaj nga email-i i kompanisë.',
  ],
  'student' => [
    'title' => 'Kursant',
    'text'  => 'Hyj me numrin personal',
    'icon'  => 'bi-person-badge',
    'label' => 'Numri personal',
    'type'  => 'text',
    'placeholder' => 'p.sh. J75010110A',
    'help'  => 'Numri personal nga karta e identitetit (10 shenja).',
    'forgot' => 'Për siguri, fjalëkalimin e rivendos vetëm QTA. Na telefono ose eja në zyrë me kartën e identitetit.',
  ],
];
$active = $roles[$activeRole];

$NAV_ACTIVE = 'login';
$pageTitle = 'Hyr në sistem — Regjistri QTA';
$pageDescription = 'Hyr në Regjistrin QTA si staf, agjenci ose kursant.';
$pageScripts = [qta_asset('app/assets/js/login-ui.js')];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main id="main" tabindex="-1">
  <div class="wrap login-layout">

    <aside class="login-aside">
      <span class="hero-eyebrow"><i class="bi bi-lock" aria-hidden="true"></i>Hyrje e sigurt</span>
      <h2>Mirë se erdhe në Regjistrin QTA</h2>
      <p class="lead-text mb-4">Me llogarinë tënde ndjek regjistrimet, grupet, provimet dhe certifikatat. Secili sheh vetëm atë që i përket.</p>
      <dl class="kv mb-4">
        <dt>Stafi</dt><dd>Regjistron kursantët, cakton grupet, shënon provimet.</dd>
        <dt>Agjencia</dt><dd>Ndjek punonjësit e vet dhe dokumentet e tyre.</dd>
        <dt>Kursanti</dt><dd>Sheh modulet, provimet dhe kodin QR të certifikatave.</dd>
      </dl>
      <div class="notice">
        <i class="bi bi-qr-code-scan" aria-hidden="true"></i>
        <span>Do vetëm të kontrollosh një certifikatë? Nuk duhet llogari —
          <a href="verify.php">hap verifikimin</a>.</span>
      </div>
    </aside>

    <section class="login-card" aria-labelledby="loginTitle">
      <h1 class="login-title" id="loginTitle">Hyr në llogari</h1>
      <p class="text-muted mb-4">Zgjidh kush je, pastaj shkruaj të dhënat.</p>

      <?php if ($loginError): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-login-error>
          <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
          <div><span class="alert-title">Hyrja nuk u krye</span><?= h($loginError) ?></div>
        </div>
      <?php endif; ?>

      <form method="post" action="login_handler.php" data-login-form novalidate>
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

        <fieldset class="role-options">
          <legend class="form-label mb-2">Kush je?</legend>
          <?php foreach ($roles as $key => $r): ?>
            <label class="role-option">
              <input type="radio" name="role" value="<?= h($key) ?>" <?= $key === $activeRole ? 'checked' : '' ?>
                     data-label="<?= h($r['label']) ?>" data-type="<?= h($r['type']) ?>"
                     data-placeholder="<?= h($r['placeholder']) ?>" data-help="<?= h($r['help']) ?>"
                     data-forgot="<?= h($r['forgot']) ?>">
              <span class="role-option-icon"><i class="bi <?= h($r['icon']) ?>" aria-hidden="true"></i></span>
              <span class="role-option-text"><b><?= h($r['title']) ?></b><span><?= h($r['text']) ?></span></span>
              <span class="role-option-check" aria-hidden="true"></span>
            </label>
          <?php endforeach; ?>
        </fieldset>

        <div class="mb-3">
          <label class="form-label" for="identifier" data-id-label><?= h($active['label']) ?></label>
          <input class="form-control form-control-lg<?= $active['type'] === 'email' ? '' : ' input-code' ?>"
                 id="identifier" name="identifier" type="<?= h($active['type']) ?>"
                 placeholder="<?= h($active['placeholder']) ?>" value="<?= h($rememberedId) ?>"
                 autocomplete="username" autocapitalize="off" spellcheck="false" required
                 aria-describedby="idHelp idError">
          <p class="form-text" id="idHelp" data-id-help><?= h($active['help']) ?></p>
          <p class="invalid-feedback" id="idError">Shkruaj identifikimin tënd.</p>
        </div>

        <div class="mb-2">
          <label class="form-label" for="password">Fjalëkalimi</label>
          <div class="password-field">
            <input class="form-control form-control-lg" id="password" name="password" type="password"
                   autocomplete="current-password" required aria-describedby="pwError capsNote">
            <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#password"
                    aria-label="Shfaq fjalëkalimin" aria-pressed="false">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
          <p class="invalid-feedback" id="pwError">Shkruaj fjalëkalimin.</p>
          <p class="caps-note" id="capsNote" data-caps-warning hidden>
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Caps Lock është i ndezur — shkronjat po dalin të mëdha.
          </p>
        </div>

        <button class="btn btn-primary btn-lg w-100 mt-3" type="submit">
          <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Hyr
        </button>

        <p class="mt-3 mb-0">
          <button class="btn btn-link px-0" type="button" data-bs-toggle="collapse" data-bs-target="#forgotHelp"
                  aria-expanded="false" aria-controls="forgotHelp">Harrove fjalëkalimin?</button>
        </p>
        <div class="collapse" id="forgotHelp">
          <div class="notice mt-1">
            <i class="bi bi-key" aria-hidden="true"></i>
            <span><span data-forgot-text><?= h($active['forgot']) ?></span>
              Tel. <a href="tel:+355698778837">+355 69 877 8837</a> · <a href="contact.php">Kontakt</a></span>
          </div>
        </div>
      </form>
    </section>

  </div>
</main>
<?php
require_once __DIR__ . '/../shared/footer.php';
require_once __DIR__ . '/../shared/public_scripts.php';
?>
</body>
</html>
