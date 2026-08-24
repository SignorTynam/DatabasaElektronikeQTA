<?php
declare(strict_types=1);

/**
 * navbarMain.php — Kokëfleta publike (PROTOKOLL).
 * Institucioni majtas, seksionet djathtas, vijë boje 2px poshtë.
 */

require_once __DIR__ . '/public_ui.php';

if (empty($NAV_ACTIVE)) {
  $path = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
  $NAV_ACTIVE = match ($path) {
    'index.php', '' => 'home',
    'aboutus.php' => 'about',
    'contact.php' => 'contact',
    'verify.php' => 'verify',
    'selectProfile.php' => 'login',
    default => '',
  };
}

$currentUser = $currentUser ?? null;
$roleName    = qta_public_role($currentUser);
$displayName = (string)($currentUser['full_name'] ?? $currentUser['email'] ?? 'Përdorues');
$panelHref   = qta_public_panel_href($roleName);
$panelLabel  = qta_public_panel_label($roleName);
$avatar      = qta_public_initials($displayName);

$links = [
  ['key' => 'home',    'href' => 'index.php',   'label' => 'Regjistri'],
  ['key' => 'about',   'href' => 'aboutus.php', 'label' => 'Institucioni'],
  ['key' => 'contact', 'href' => 'contact.php', 'label' => 'Kontakt'],
];
?>
<header class="masthead">
  <div class="wrap masthead-inner">

    <a class="masthead-mark" href="index.php">
      <img src="image/logoPNG2.png" alt="QTA">
      <span>
        <b>Regjistri QTA</b>
        <span>Certifikime profesionale</span>
      </span>
    </a>

    <button class="masthead-burger" type="button" data-mast-toggle
            aria-expanded="false" aria-controls="mastNav" aria-label="Hap menunë">
      <i class="bi bi-list"></i>
    </button>

    <nav class="masthead-nav" id="mastNav" aria-label="Navigimi publik">
      <?php foreach ($links as $l): ?>
        <a class="masthead-link<?= $NAV_ACTIVE === $l['key'] ? ' is-active' : '' ?>"
           href="<?= h($l['href']) ?>"
           <?= $NAV_ACTIVE === $l['key'] ? 'aria-current="page"' : '' ?>><?= h($l['label']) ?></a>
      <?php endforeach; ?>

      <a class="masthead-link<?= $NAV_ACTIVE === 'verify' ? ' is-active' : '' ?>"
         href="verify.php" <?= $NAV_ACTIVE === 'verify' ? 'aria-current="page"' : '' ?>>Verifiko</a>

      <button class="app-tool ms-2" type="button" data-theme-toggle aria-label="Ndërro pamjen">
        <i class="bi bi-circle-half"></i>
      </button>

      <?php if (!empty($currentUser)): ?>
        <div class="dropdown ms-2">
          <button class="app-who" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="initials"><?= h($avatar) ?></span>
            <span class="app-who-name d-none d-sm-inline"><?= h($displayName) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end" style="min-width:238px">
            <li class="px-2 py-2 d-flex align-items-center gap-2">
              <span class="initials initials-lg"><?= h($avatar) ?></span>
              <span class="min-w-0">
                <span class="d-block text-truncate" style="font-family:var(--font-record);font-weight:600"><?= h($displayName) ?></span>
                <?php if (!empty($currentUser['email'])): ?>
                  <span class="d-block text-truncate muted" style="font-size:var(--fs-xs)"><?= h((string)$currentUser['email']) ?></span>
                <?php endif; ?>
              </span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= h($panelHref) ?>"><i class="bi bi-journal-text"></i><?= h($panelLabel) ?></a></li>
            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i>Profili</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i>Dil</a></li>
          </ul>
        </div>
      <?php else: ?>
        <a class="btn btn-ink btn-sm ms-2" href="selectProfile.php">Hyr në sistem</a>
      <?php endif; ?>
    </nav>

  </div>
</header>
