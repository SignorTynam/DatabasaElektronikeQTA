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
<main class="wrap">
  <div class="gate">

    <aside class="gate-aside">
      <div class="protocol-line">
        <span>Republika e Shqipërisë</span>
        <span class="sep">·</span>
        <span>Tiranë</span>
      </div>

      <h1 style="margin-bottom:.8rem">Hyrje në regjistër</h1>
      <p class="prose-lead" style="margin-bottom:1.75rem">
        Aksesi jepet sipas rolit. Çdo veprim mbi regjistrin shënohet dhe i atribuohet
        nënshkruesit që e kryen.
      </p>

      <dl style="margin:0;border-top:1px solid var(--rule)">
        <?php
        $gateNotes = [
          ['Administratori', 'Mban regjistrin e plotë: përdoruesit, modulet, grupet dhe auditimin.'],
          ['Editori', 'Regjistron kursantë, cakton grupe dhe shënon provimet.'],
          ['Agjencia', 'Regjistron punonjësit e vet dhe ndjek grupet ku janë caktuar.'],
          ['Kursanti', 'Sheh modulet, grupet dhe certifikatat e veta.'],
        ];
        foreach ($gateNotes as $n): ?>
          <div style="display:grid;grid-template-columns:9rem 1fr;gap:1rem;padding:.7rem 0;border-bottom:1px solid var(--rule-hair)">
            <dt class="label" style="padding-top:.15rem"><?= h($n[0]) ?></dt>
            <dd style="margin:0;font-size:var(--fs-sm);color:var(--pencil)"><?= h($n[1]) ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </aside>

    <section class="gate-form" aria-labelledby="gateTitle">
      <h2 id="gateTitle" style="margin-bottom:.25rem">Identifikohu</h2>
      <p class="muted" style="font-size:var(--fs-sm);margin-bottom:1.4rem" data-role-hint>
        Zgjidh rolin për të parë fushat e sakta.
      </p>

      <div class="role-tabs" role="group" aria-label="Zgjidh rolin">
        <?php foreach ($roles as $key => $roleInfo): ?>
          <button class="role-tab <?= $key === $activeRole ? 'is-active' : '' ?>" type="button"
                  data-role-option="<?= h($key) ?>"
                  aria-pressed="<?= $key === $activeRole ? 'true' : 'false' ?>"><?= h($roleInfo['short']) ?></button>
        <?php endforeach; ?>
      </div>

      <?php if ($loginError): ?>
        <div class="alert alert-danger mb-3" role="alert" aria-live="assertive">
          <strong style="display:block">Hyrja nuk u krye</strong>
          <?= h($loginError) ?>
        </div>
      <?php endif; ?>

      <?php foreach ($roles as $key => $roleInfo): ?>
        <form class="login-form <?= $key === $activeRole ? '' : 'd-none' ?>" data-role="<?= h($key) ?>"
              action="login_handler.php" method="post" autocomplete="off" novalidate>
          <input type="hidden" name="role" value="<?= h($key) ?>">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

          <div class="field">
            <label class="label" for="identifier_<?= h($key) ?>"><?= h($roleInfo['field_label']) ?></label>
            <input class="input<?= $roleInfo['type'] === 'email' ? '' : ' input-code' ?>"
                   id="identifier_<?= h($key) ?>" name="identifier" type="<?= h($roleInfo['type']) ?>"
                   placeholder="<?= h($roleInfo['placeholder']) ?>" required>
          </div>

          <div class="field">
            <label class="label" for="password_<?= h($key) ?>">Fjalëkalimi</label>
            <div style="display:flex;gap:.4rem">
              <input class="input login-password" id="password_<?= h($key) ?>" name="password" type="password"
                     placeholder="Shkruaj fjalëkalimin" required style="flex:1;min-width:0">
              <button class="btn btn-icon" type="button" data-password-toggle="#password_<?= h($key) ?>"
                      aria-label="Shfaq ose fsheh fjalëkalimin">
                <i class="bi bi-eye"></i>
              </button>
            </div>
            <div class="caps-note d-none" data-caps-warning>Caps Lock është i ndezur.</div>
          </div>

          <button class="btn btn-ink btn-lg w-100" type="submit" style="margin-top:.6rem">
            <?= h($roleInfo['button']) ?>
          </button>
        </form>
      <?php endforeach; ?>

      <p class="muted" style="font-size:var(--fs-xs);margin:1.1rem 0 0">
        Nuk ke akses? Shkruaj te <a href="contact.php">administrata e QTA-së</a>.
        Për të kontrolluar një certifikatë nuk duhet llogari —
        <a href="verify.php">hap verifikimin</a>.
      </p>
    </section>

  </div>
</main>

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
require_once __DIR__ . '/../shared/footer.php';
require_once __DIR__ . '/../shared/public_scripts.php';
?>
</body>
</html>
