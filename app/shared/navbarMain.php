<?php
declare(strict_types=1);

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
$roleName = qta_public_role($currentUser);
$displayName = (string)($currentUser['full_name'] ?? $currentUser['email'] ?? 'Përdorues');
$panelHref = qta_public_panel_href($roleName);
$panelLabel = qta_public_panel_label($roleName);
$avatar = qta_public_initials($displayName);
?>
<nav class="navbar navbar-expand-lg sticky-top qta-navbar" aria-label="Navigimi publik QTA">
  <div class="container-public">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <img src="image/logoPNG2.png" alt="Logo QTA">
      <span class="lh-sm">
        <span class="d-block">QTA</span>
        <span class="brand-subtitle d-none d-md-block">Qendra e Trajnimeve të Avancuara</span>
      </span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#qtaPublicNav"
            aria-controls="qtaPublicNav" aria-expanded="false" aria-label="Hap menunë">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="qtaPublicNav">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
        <li class="nav-item">
          <a class="nav-link <?= h(qta_public_active('home', $NAV_ACTIVE)) ?>" href="index.php" <?= $NAV_ACTIVE === 'home' ? 'aria-current="page"' : '' ?>>
            Kryefaqja
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= h(qta_public_active('about', $NAV_ACTIVE)) ?>" href="aboutus.php" <?= $NAV_ACTIVE === 'about' ? 'aria-current="page"' : '' ?>>
            Rreth nesh
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= h(qta_public_active('contact', $NAV_ACTIVE)) ?>" href="contact.php" <?= $NAV_ACTIVE === 'contact' ? 'aria-current="page"' : '' ?>>
            Kontakt
          </a>
        </li>
        <li class="nav-item">
          <a class="btn btn-outline-primary qta-btn ms-lg-2 <?= h(qta_public_active('verify', $NAV_ACTIVE)) ?>" href="verify.php" <?= $NAV_ACTIVE === 'verify' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-qr-code-scan"></i>Verifiko
          </a>
        </li>

        <?php if (!empty($currentUser)): ?>
          <li class="nav-item dropdown ms-lg-2">
            <button class="btn qta-theme-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <span class="qta-avatar me-1"><?= h($avatar) ?></span>
              <span><?= h($displayName) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end qta-dropdown p-2">
              <li class="px-2 py-2">
                <div class="d-flex align-items-center gap-2">
                  <span class="qta-avatar"><?= h($avatar) ?></span>
                  <div class="min-w-0">
                    <div class="fw-bold text-truncate"><?= h($displayName) ?></div>
                    <?php if (!empty($currentUser['email'])): ?>
                      <div class="small text-muted text-truncate"><?= h((string)$currentUser['email']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2" href="<?= h($panelHref) ?>">
                  <i class="bi bi-speedometer2"></i><?= h($panelLabel) ?>
                </a>
              </li>
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2" href="profile.php">
                  <i class="bi bi-person"></i>Profili
                </a>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="logout.php">
                  <i class="bi bi-box-arrow-right"></i>Dil
                </a>
              </li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item ms-lg-2">
            <a class="btn btn-primary qta-btn <?= h(qta_public_active('login', $NAV_ACTIVE)) ?>" href="selectProfile.php">
              <i class="bi bi-box-arrow-in-right"></i>Hyr
            </a>
          </li>
        <?php endif; ?>

        <li class="nav-item ms-lg-2">
          <button class="qta-theme-btn" type="button" data-theme-toggle>
            <i class="bi bi-moon-stars"></i><span>Modalitet i errët</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</nav>
