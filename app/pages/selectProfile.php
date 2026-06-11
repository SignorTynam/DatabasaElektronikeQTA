<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);

$validRoles = ['administrator', 'editor', 'agjencia', 'student'];
$activeRole = (string)($_GET['role'] ?? 'administrator');
if (!in_array($activeRole, $validRoles, true)) {
  $activeRole = 'administrator';
}

$loginError = null;
if (!empty($_SESSION['login_error'])) {
  $loginError = (string)$_SESSION['login_error'];
  unset($_SESSION['login_error']);
}

if (empty($_SESSION['csrf_login'])) {
  $_SESSION['csrf_login'] = bin2hex(random_bytes(24));
}
$CSRF = $_SESSION['csrf_login'];

$NAV_ACTIVE = 'login';
$pageTitle = 'Hyr në sistem - QTA';
$pageDescription = 'Zgjidhni rolin dhe hyni në portalin QTA.';
$publicPlugins = ['aos'];
$pageScripts = ['app/assets/js/login-ui.js'];

$roles = [
  'administrator' => [
    'label' => 'Administrator',
    'short' => 'Admin',
    'icon' => 'bi-person-gear',
    'field_label' => 'Email i administratorit',
    'placeholder' => 'admin@qta.al',
    'type' => 'email',
    'button' => 'Hyr si administrator',
  ],
  'editor' => [
    'label' => 'Editor',
    'short' => 'Editor',
    'icon' => 'bi-pencil-square',
    'field_label' => 'Email i editorit',
    'placeholder' => 'editor@qta.al',
    'type' => 'email',
    'button' => 'Hyr si editor',
  ],
  'agjencia' => [
    'label' => 'Agjenci',
    'short' => 'Agjenci',
    'icon' => 'bi-building',
    'field_label' => 'NIPT',
    'placeholder' => 'p.sh. L12345678Q',
    'type' => 'text',
    'button' => 'Hyr si agjenci',
  ],
  'student' => [
    'label' => 'Student',
    'short' => 'Student',
    'icon' => 'bi-mortarboard',
    'field_label' => 'Numri personal',
    'placeholder' => 'Vendos numrin personal',
    'type' => 'text',
    'button' => 'Hyr si student',
  ],
];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main>
  <section class="container-public login-shell">
    <aside class="login-brand-panel" data-aos="fade-right">
      <div class="d-flex align-items-center gap-3 mb-4">
        <img src="image/logoPNG2.png" alt="Logo QTA" style="width: 54px; height: 54px; object-fit: contain;">
        <div>
          <div class="fw-bold fs-4">QTA</div>
          <div class="opacity-75">Qendra e Trajnimeve të Avancuara</div>
        </div>
      </div>
      <h1 class="display-6 fw-bold mb-3">Mirë se vini në portalin QTA</h1>
      <p class="lead opacity-75">Zgjidhni rolin dhe hyni në panelin tuaj.</p>
      <div class="d-grid gap-3 mt-4">
        <div><i class="bi bi-person-lock me-2"></i>Akses sipas rolit</div>
        <div><i class="bi bi-shield-check me-2"></i>Menaxhim i sigurt</div>
        <div><i class="bi bi-qr-code me-2"></i>Certifikata të verifikueshme</div>
        <div><i class="bi bi-phone me-2"></i>Portal i optimizuar për mobile</div>
      </div>
    </aside>

    <section class="qta-card login-card" data-aos="fade-left" aria-labelledby="loginTitle">
      <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
          <h2 class="h3 fw-bold mb-1" id="loginTitle">Hyr në sistem</h2>
          <p class="text-muted-public mb-0" data-role-hint>Zgjidh rolin për të parë fushat e sakta.</p>
        </div>
        <span class="badge text-bg-primary align-self-start" data-role-badge><?= h($roles[$activeRole]['label']) ?></span>
      </div>

      <div class="role-switcher mb-4" role="group" aria-label="Zgjidh rolin">
        <?php foreach ($roles as $key => $roleInfo): ?>
          <button class="role-option <?= $key === $activeRole ? 'active' : '' ?>" type="button"
                  data-role-option="<?= h($key) ?>" aria-pressed="<?= $key === $activeRole ? 'true' : 'false' ?>">
            <i class="bi <?= h($roleInfo['icon']) ?> me-1"></i><?= h($roleInfo['short']) ?>
            <span><?= h($roleInfo['label']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <?php if ($loginError): ?>
        <div class="alert alert-danger d-flex gap-2" role="alert" aria-live="assertive">
          <i class="bi bi-exclamation-triangle mt-1"></i>
          <div>
            <div class="fw-bold">Nuk u krye hyrja</div>
            <div><?= h($loginError) ?></div>
          </div>
        </div>
      <?php endif; ?>

      <?php foreach ($roles as $key => $roleInfo): ?>
        <form class="login-form <?= $key === $activeRole ? '' : 'd-none' ?>" data-role="<?= h($key) ?>"
              action="login_handler.php" method="post" autocomplete="off" novalidate>
          <input type="hidden" name="role" value="<?= h($key) ?>">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

          <div class="mb-3">
            <label class="form-label" for="identifier_<?= h($key) ?>"><?= h($roleInfo['field_label']) ?></label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi <?= $roleInfo['type'] === 'email' ? 'bi-envelope' : h($roleInfo['icon']) ?>"></i></span>
              <input class="form-control" id="identifier_<?= h($key) ?>" name="identifier" type="<?= h($roleInfo['type']) ?>"
                     placeholder="<?= h($roleInfo['placeholder']) ?>" required>
              <div class="invalid-feedback">Plotësoni këtë fushë.</div>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label" for="password_<?= h($key) ?>">Fjalëkalimi</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input class="form-control login-password" id="password_<?= h($key) ?>" name="password" type="password"
                     placeholder="Shkruani fjalëkalimin" required>
              <button class="btn btn-outline-secondary" type="button" data-password-toggle="#password_<?= h($key) ?>"
                      aria-label="Shfaq ose fsheh fjalëkalimin">
                <i class="bi bi-eye"></i>
              </button>
              <div class="invalid-feedback">Shkruani fjalëkalimin.</div>
            </div>
            <div class="caps-warning d-none" data-caps-warning>
              <i class="bi bi-exclamation-triangle me-1"></i>Caps Lock është aktivizuar.
            </div>
          </div>

          <div class="alert alert-info mt-3">
            Nëse nuk keni akses ose keni harruar të dhënat, kontaktoni administratën e QTA-së.
          </div>

          <button class="btn btn-primary btn-lg qta-btn w-100 mt-2" type="submit">
            <i class="bi bi-box-arrow-in-right"></i><?= h($roleInfo['button']) ?>
          </button>
        </form>
      <?php endforeach; ?>

      <div class="d-flex flex-wrap gap-3 mt-4 pt-4 border-top" style="border-color: var(--qta-border) !important;">
        <a href="verify.php"><i class="bi bi-qr-code-scan me-1"></i>Verifiko certifikatën pa hyrë</a>
        <a href="contact.php"><i class="bi bi-headset me-1"></i>Kontakt për ndihmë</a>
        <a href="index.php"><i class="bi bi-house me-1"></i>Kthehu në kryefaqe</a>
      </div>
    </section>
  </section>
</main>
<?php if ($loginError): ?>
  <script>
    window.QTA_LOGIN_ERROR = <?= json_encode($loginError, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  </script>
<?php endif; ?>
<?php
require_once __DIR__ . '/footer.php';
require_once __DIR__ . '/../shared/public_scripts.php';
?>
</body>
</html>
