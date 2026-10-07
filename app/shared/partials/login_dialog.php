<?php
declare(strict_types=1);

/**
 * login_dialog.php — Hyrja si dialog mbi faqen publike: vetëm formulari.
 *
 * navbarMain.php e vizaton në çdo faqe publike me qta_login_dialog($currentUser).
 * Çdo lidhje me data-login (vlera: student | agjencia | staff, ose bosh) e hap pa
 * ndërruar faqe (login-ui.js). href-ja e saj, selectProfile.php, mbetet rruga pa
 * JavaScript: ajo çon te kryefaqja me dialogun të hapur (?hyr=<roli>).
 *
 * Formulari dërgohet me fetch (Accept: application/json): gabimi del brenda
 * dialogut, hyrja e suksesshme hap panelin e rolit. Pa JavaScript dërgohet si
 * formular i zakonshëm; login_handler.php e kthen këtu me mesazhin në seancë.
 */

require_once __DIR__ . '/../public_ui.php';

if (!function_exists('qta_login_roles')) {
  /** Llojet e llogarisë, sipas numrit të përdoruesve: kursantët janë më të shumtët. */
  function qta_login_roles(): array {
    return [
      'student' => [
        'title'       => 'Kursant',
        'label'       => 'Numri personal',
        'type'        => 'text',
        'placeholder' => 'p.sh. J75010110A',
        'help'        => '10 shenja, siç janë në kartën e identitetit.',
        'missing'     => 'Shkruaj numrin personal.',
        'forgot'      => 'Për siguri, fjalëkalimin e rivendos vetëm QTA. Na telefono ose eja në zyrë me kartën e identitetit.',
      ],
      'agjencia' => [
        'title'       => 'Agjenci',
        'label'       => 'NIPT-i i kompanisë',
        'type'        => 'text',
        'placeholder' => 'p.sh. L12345678Q',
        'help'        => '10 shenja: një shkronjë, 8 shifra dhe një shkronjë.',
        'missing'     => 'Shkruaj NIPT-in e kompanisë.',
        'forgot'      => 'Për siguri, fjalëkalimin e rivendos vetëm QTA. Na telefono ose na shkruaj nga email-i i kompanisë.',
      ],
      'staff' => [
        'title'       => 'Staf i QTA',
        'label'       => 'Email-i i punës',
        'type'        => 'email',
        'placeholder' => 'emri@qta.al',
        'help'        => 'Email-i me të cilin të regjistroi administratori.',
        'missing'     => 'Shkruaj email-in e punës.',
        'forgot'      => 'Kërkoji një administratori të QTA-së të të vendosë një fjalëkalim të ri.',
      ],
    ];
  }
}

if (!function_exists('qta_login_dialog')) {
  function qta_login_dialog(?array $currentUser = null): void {
    $roles = qta_login_roles();

    /* Mesazhi i një hyrjeje të dështuar pa JavaScript (login_handler.php). */
    $error = null;
    if (!empty($_SESSION['login_error'])) {
      $error = (string)$_SESSION['login_error'];
      unset($_SESSION['login_error']);
    }
    $remembered = '';
    if (!empty($_SESSION['login_identifier'])) {
      $remembered = (string)$_SESSION['login_identifier'];
      unset($_SESSION['login_identifier']);
    }

    if (empty($_SESSION['csrf_login'])) {
      $_SESSION['csrf_login'] = bin2hex(random_bytes(24));
    }

    /* ?hyr hap dialogun sapo ngarkohet faqja; ?hyr=student zgjedh edhe llojin. */
    $asked = $_GET['hyr'] ?? null;
    $open = $asked !== null || $error !== null;
    $fixed = is_string($asked) && isset($roles[$asked]);
    $role = $fixed ? $asked : 'staff';
    $active = $roles[$role];
    $isEmail = $active['type'] === 'email';

    $meRole = qta_public_role($currentUser);
    $meName = (string)(($currentUser['full_name'] ?? '') ?: ($currentUser['email'] ?? ''));
    ?>
<div class="modal fade login-modal<?= $open ? ' is-requested' : '' ?>" id="loginModal" tabindex="-1"
     aria-labelledby="loginTitle"<?= $open ? ' data-open-on-load="hyr"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="login-top">
        <img class="login-logo" src="<?= h(qta_asset('image/logoPNG2.png')) ?>" alt="QTA" width="69" height="22">
        <button class="btn btn-ghost btn-icon login-close" type="button" data-bs-dismiss="modal" aria-label="Mbyll">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>

      <h2 class="login-title" id="loginTitle">Hyr në llogari</h2>

      <?php if ($currentUser): ?>
        <p class="notice login-notice">
          <i class="bi bi-person-check" aria-hidden="true"></i>
          <span>
            Je brenda si <b><?= h($meName !== '' ? $meName : 'përdorues') ?></b>.
            <a href="<?= h(qta_public_panel_href($meRole)) ?>">Vazhdo te <?= h(mb_strtolower(qta_public_panel_label($meRole))) ?></a>
            ose hyr me një llogari tjetër.
          </span>
        </p>
      <?php endif; ?>

      <div class="login-error-slot" role="alert" data-login-error><?php if ($error !== null): ?>
        <div class="alert alert-danger login-error">
          <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
          <span><?= h($error) ?></span>
        </div>
      <?php endif; ?></div>

      <form class="login-form" method="post" action="<?= h(qta_url('login_handler.php')) ?>"
            data-login-form<?= $fixed ? ' data-role-fixed' : '' ?> novalidate>
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_login']) ?>">

        <fieldset class="segmented login-roles">
          <legend class="visually-hidden">Hyr si</legend>
          <?php foreach ($roles as $key => $r): ?>
            <label>
              <input type="radio" name="role" value="<?= h($key) ?>"<?= $key === $role ? ' checked' : '' ?>
                     data-label="<?= h($r['label']) ?>" data-type="<?= h($r['type']) ?>"
                     data-placeholder="<?= h($r['placeholder']) ?>" data-help="<?= h($r['help']) ?>"
                     data-missing="<?= h($r['missing']) ?>" data-forgot="<?= h($r['forgot']) ?>">
              <span><?= h($r['title']) ?></span>
            </label>
          <?php endforeach; ?>
        </fieldset>

        <div class="login-field">
          <label class="form-label" for="loginId" data-login-label><?= h($active['label']) ?></label>
          <input class="form-control form-control-lg<?= $isEmail ? '' : ' input-code' ?>" id="loginId" name="identifier"
                 type="<?= h($active['type']) ?>" inputmode="<?= $isEmail ? 'email' : 'text' ?>"
                 placeholder="<?= h($active['placeholder']) ?>" value="<?= h($remembered) ?>"
                 autocomplete="username" autocapitalize="<?= $isEmail ? 'off' : 'characters' ?>"
                 spellcheck="false" required aria-describedby="loginIdHelp">
          <p class="form-text" id="loginIdHelp" data-login-help><?= h($active['help']) ?></p>
          <p class="invalid-feedback" id="loginIdError" data-login-missing><?= h($active['missing']) ?></p>
        </div>

        <div class="login-field">
          <label class="form-label" for="loginPassword">Fjalëkalimi</label>
          <div class="password-field">
            <input class="form-control form-control-lg" id="loginPassword" name="password" type="password"
                   autocomplete="current-password" required>
            <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#loginPassword"
                    aria-label="Shfaq fjalëkalimin" aria-pressed="false">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
          <p class="invalid-feedback" id="loginPasswordError">Shkruaj fjalëkalimin.</p>
          <p class="caps-note" id="loginCaps" data-caps-warning hidden>
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Caps Lock është i ndezur: shkronjat dalin të mëdha.
          </p>
        </div>

        <button class="btn btn-primary btn-lg w-100 login-submit" type="submit">Hyr</button>
        <p class="visually-hidden" role="status" data-login-status></p>

        <details class="login-forgot">
          <summary>Harrove fjalëkalimin?</summary>
          <div class="login-forgot-body">
            <p data-login-forgot><?= h($active['forgot']) ?></p>
            <p>
              <a href="tel:+355698778837"><i class="bi bi-telephone" aria-hidden="true"></i>+355 69 877 8837</a>
              <span aria-hidden="true">·</span>
              <a href="contact.php">Kontakt</a>
            </p>
          </div>
        </details>
      </form>
    </div>
  </div>
</div>
    <?php
  }
}
