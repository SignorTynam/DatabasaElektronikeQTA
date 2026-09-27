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
$roleFromUrl = isset($_GET['role']);

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

/* Renditja sipas numrit të përdoruesve: kursantët janë më të shumtët. */
$roles = [
  'student' => [
    'title' => 'Kursant',
    'icon'  => 'bi-person-badge',
    'hint'  => 'Hyn me numrin personal të kartës së identitetit.',
    'label' => 'Numri personal',
    'type'  => 'text',
    'placeholder' => 'p.sh. J75010110A',
    'help'  => '10 shenja, siç janë në kartën e identitetit.',
    'forgot' => 'Për siguri, fjalëkalimin e rivendos vetëm QTA. Na telefono ose eja në zyrë me kartën e identitetit.',
  ],
  'agjencia' => [
    'title' => 'Agjenci',
    'icon'  => 'bi-buildings',
    'hint'  => 'Hyn me NIPT-in e kompanisë.',
    'label' => 'NIPT-i i kompanisë',
    'type'  => 'text',
    'placeholder' => 'p.sh. L12345678Q',
    'help'  => '10 shenja: një shkronjë, 8 shifra dhe një shkronjë.',
    'forgot' => 'Për siguri, fjalëkalimin e rivendos vetëm QTA. Na telefono ose na shkruaj nga email-i i kompanisë.',
  ],
  'staff' => [
    'title' => 'Staf i QTA',
    'icon'  => 'bi-person-workspace',
    'hint'  => 'Hyn me email-in e punës.',
    'label' => 'Email-i i punës',
    'type'  => 'email',
    'placeholder' => 'emri@qta.al',
    'help'  => 'Email-i me të cilin të regjistroi administratori.',
    'forgot' => 'Kërkoji një administratori të QTA-së të të vendosë një fjalëkalim të ri.',
  ],
];
$active = $roles[$activeRole];

$NAV_ACTIVE = 'login';
$pageTitle = 'Hyr në sistem — Regjistri QTA';
$pageDescription = 'Hyr në Regjistrin QTA si kursant, agjenci ose staf.';
$pageBodyClass = 'page-signin';
$pageScripts = [qta_asset('app/assets/js/login-ui.js')];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main id="main" tabindex="-1" class="signin">

  <!-- Modulli është i pari: kjo është arsyeja pse ke ardhur këtu. -->
  <section class="signin-main" aria-labelledby="loginTitle">
    <div class="signin-form">
      <h1 class="signin-title" id="loginTitle">Hyr në llogari</h1>
      <p class="signin-sub">Zgjidh llojin e llogarisë, pastaj shkruaj të dhënat.</p>

      <?php if ($currentUser):
        /* Dikush që është tashmë brenda arrin këtu kur hap një faqe që nuk i takon
           rolit të tij. I themi qartë që nuk ka dalë nga llogaria. */
        $meRole  = qta_public_role($currentUser);
        $meName  = (string)(($currentUser['full_name'] ?? '') ?: ($currentUser['email'] ?? 'llogarinë tënde')); ?>
        <div class="notice mb-4" role="status">
          <i class="bi bi-person-check" aria-hidden="true"></i>
          <span>
            <b>Je tashmë brenda si <?= h($meName) ?>.</b>
            Faqja që hape nuk është për këtë llogari.
            <a href="<?= h(qta_public_panel_href($meRole)) ?>">Vazhdo te <?= h(mb_strtolower(qta_public_panel_label($meRole))) ?></a>
            ose hyr më poshtë me një llogari tjetër.
          </span>
        </div>
      <?php endif; ?>

      <?php if ($loginError): ?>
        <div class="alert alert-danger" role="alert" tabindex="-1" data-login-error>
          <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
          <div><span class="alert-title">Hyrja nuk u krye</span><?= h($loginError) ?></div>
        </div>
      <?php endif; ?>

      <form method="post" action="login_handler.php" data-login-form <?= $roleFromUrl ? 'data-role-fixed' : '' ?> novalidate>
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

        <fieldset class="role-switch">
          <legend class="visually-hidden">Lloji i llogarisë</legend>
          <?php foreach ($roles as $key => $r): ?>
            <label class="role-tab">
              <input type="radio" name="role" value="<?= h($key) ?>" <?= $key === $activeRole ? 'checked' : '' ?>
                     data-label="<?= h($r['label']) ?>" data-type="<?= h($r['type']) ?>"
                     data-placeholder="<?= h($r['placeholder']) ?>" data-help="<?= h($r['help']) ?>"
                     data-hint="<?= h($r['hint']) ?>" data-forgot="<?= h($r['forgot']) ?>">
              <i class="bi <?= h($r['icon']) ?>" aria-hidden="true"></i>
              <span><?= h($r['title']) ?></span>
            </label>
          <?php endforeach; ?>
        </fieldset>
        <p class="role-hint" data-role-hint aria-live="polite"><?= h($active['hint']) ?></p>

        <div class="mb-3">
          <label class="form-label" for="identifier" data-id-label><?= h($active['label']) ?></label>
          <input class="form-control form-control-lg<?= $active['type'] === 'email' ? '' : ' input-code' ?>"
                 id="identifier" name="identifier" type="<?= h($active['type']) ?>"
                 placeholder="<?= h($active['placeholder']) ?>" value="<?= h($rememberedId) ?>"
                 autocomplete="username" autocapitalize="<?= $active['type'] === 'email' ? 'off' : 'characters' ?>"
                 spellcheck="false" required aria-describedby="idHelp idError">
          <p class="form-text" id="idHelp" data-id-help><?= h($active['help']) ?></p>
          <p class="invalid-feedback" id="idError">Shkruaj këtë fushë për të hyrë.</p>
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

        <details class="signin-help">
          <summary>Harrove fjalëkalimin?</summary>
          <div class="signin-help-body">
            <p class="mb-2" data-forgot-text><?= h($active['forgot']) ?></p>
            <p class="mb-0">
              <a href="tel:+355698778837"><i class="bi bi-telephone" aria-hidden="true"></i>+355 69 877 8837</a>
              <span class="text-subtle" aria-hidden="true">·</span>
              <a href="contact.php">Kontakt</a>
            </p>
          </div>
        </details>
      </form>

      <p class="signin-safe">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        QTA nuk ta kërkon kurrë fjalëkalimin me telefon ose email. Mos e ndaj me askënd.
      </p>
    </div>
  </section>

  <!-- Pse ekziston regjistri — në të majtë në kompjuter, poshtë modulit në telefon. -->
  <aside class="signin-brand" aria-label="Rreth Regjistrit QTA">
    <div class="signin-brand-body">
      <p class="signin-brand-eyebrow">Qendra e Trajnimeve të Avancuara</p>
      <p class="signin-brand-title">Kualifikimet profesionale, të regjistruara dhe të verifikueshme.</p>
      <ul class="signin-points">
        <li>
          <span class="signin-point-icon"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></span>
          <span><b>Verifikohen me një skanim</b>Kushdo e kontrollon një certifikatë me kamerën e telefonit, pa llogari.</span>
        </li>
        <li>
          <span class="signin-point-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
          <span><b>Asgjë nuk humbet</b>Çdo ndryshim ruhet: kush e bëri, kur dhe çfarë ishte më parë.</span>
        </li>
        <li>
          <span class="signin-point-icon"><i class="bi bi-person-lock" aria-hidden="true"></i></span>
          <span><b>Secili sheh të vetat</b>Agjencia sheh punonjësit e saj, kursanti vetëm veten.</span>
        </li>
      </ul>
    </div>

    <div class="signin-verify">
      <div>
        <b>Do vetëm të kontrollosh një certifikatë?</b>
        <span>Nuk të duhet llogari.</span>
      </div>
      <a class="btn signin-verify-btn" href="verify.php">
        Verifiko certifikatë<i class="bi bi-arrow-right" aria-hidden="true"></i>
      </a>
    </div>
  </aside>

</main>
<?php
require_once __DIR__ . '/../shared/footer.php';
require_once __DIR__ . '/../shared/public_scripts.php';
?>
</body>
</html>
