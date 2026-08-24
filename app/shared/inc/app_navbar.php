<?php
declare(strict_types=1);

/**
 * app_navbar.php — Navbar-i i vetëm i panelit, i drejtuar nga roli.
 * Zëvendëson navbar.php / navbar2.php / navbar3.php / navbar4.php,
 * të cilët tani janë vetëm mbështjellës që caktojnë $NAV_ROLE.
 *
 * Variabla opsionale nga faqja prind:
 *   $NAV_ACTIVE   string  çelësi i faqes aktive
 *   $NAV_ROLE     string  roli që përcakton menunë
 *   $NAV_SUBTITLE string  rresht i dytë nën brand (p.sh. emri i agjencisë)
 *   $currentUser  array   id, full_name, email, role_name
 */

require_once __DIR__ . '/../app_ui.php';

/* Ngarko $currentUser nëse faqja nuk e ka dhënë */
if (!isset($currentUser) || !is_array($currentUser)) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  require_once __DIR__ . '/../database.php';

  $currentUser = ['full_name' => null, 'email' => null, 'role_name' => null];

  if (!empty($_SESSION['user_id'])) {
    try {
      $navPdo = getPDO();
      $navStmt = $navPdo->prepare("
        SELECT u.id, u.full_name, u.email, r.name AS role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = :id
        LIMIT 1
      ");
      $navStmt->execute([':id' => $_SESSION['user_id']]);
      $currentUser = $navStmt->fetch(PDO::FETCH_ASSOC) ?: $currentUser;
    } catch (Throwable $e) {
      /* mbaj vlerat bazë */
    }
  }
}

$navRole    = strtolower((string)($NAV_ROLE ?? $currentUser['role_name'] ?? ''));
$navActive  = $NAV_ACTIVE ?? qta_app_active_key();
$navMenu    = qta_app_menu($navRole);
$navHome    = qta_app_home($navRole);
$navWho     = (string)(($currentUser['full_name'] ?? '') ?: ($currentUser['email'] ?? 'Përdorues'));
$navEmail   = (string)($currentUser['email'] ?? '');
$navInit    = qta_app_initials($navWho);
$navLabel   = qta_app_role_label($navRole);
$navIcon    = qta_app_role_icon($navRole);
$navSub     = (string)($NAV_SUBTITLE ?? '');
$navSearch  = qta_app_can_search($navRole);
$navProfile = $navActive === 'profile';

/** A është aktiv një zë (ose ndonjë nga fëmijët e tij)? */
$navIsActive = static function (array $item) use ($navActive): bool {
  if (isset($item['key'])) {
    return $item['key'] === $navActive;
  }
  foreach ($item['children'] ?? [] as $child) {
    if (($child['key'] ?? null) === $navActive) {
      return true;
    }
  }
  return false;
};
?>
<nav class="navbar navbar-expand-lg app-nav no-print" aria-label="Navigimi i panelit">
  <div class="container-fluid">

    <a class="navbar-brand" href="<?= h($navHome) ?>">
      <img src="image/logoPNG2.png" alt="QTA">
      <span class="app-brand-text">
        <span>QTA</span>
        <small><?= h($navSub !== '' ? $navSub : 'Paneli i sistemit') ?></small>
      </span>
      <span class="app-role-chip d-none d-md-inline-flex ms-1">
        <i class="bi <?= h($navIcon) ?>"></i><?= h($navLabel) ?>
      </span>
    </a>

    <div class="d-flex d-lg-none align-items-center gap-2">
      <button class="app-icon-btn" type="button" data-theme-toggle aria-label="Ndrysho temën">
        <i class="bi bi-moon-stars"></i>
      </button>
      <a href="verify.php" class="app-icon-btn" title="Verifiko certifikatë" aria-label="Verifiko certifikatë">
        <i class="bi bi-qr-code-scan"></i>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNavMenu"
              aria-controls="appNavMenu" aria-expanded="false" aria-label="Shfaq ose fshih menunë">
        <span class="navbar-toggler-icon"></span>
      </button>
    </div>

    <div class="collapse navbar-collapse" id="appNavMenu">

      <ul class="navbar-nav me-auto mb-0">
        <?php foreach ($navMenu as $i => $item): ?>
          <?php $isActive = $navIsActive($item); ?>
          <?php if (empty($item['children'])): ?>
            <li class="nav-item">
              <a class="nav-link<?= $isActive ? ' active' : '' ?>"
                 <?= $isActive ? 'aria-current="page"' : '' ?>
                 href="<?= h((string)$item['href']) ?>">
                <i class="bi <?= h((string)$item['icon']) ?>"></i><?= h((string)$item['label']) ?>
              </a>
            </li>
          <?php else: ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle<?= $isActive ? ' active' : '' ?>" href="#"
                 id="appNavDrop<?= (int)$i ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi <?= h((string)$item['icon']) ?>"></i><?= h((string)$item['label']) ?>
              </a>
              <ul class="dropdown-menu" aria-labelledby="appNavDrop<?= (int)$i ?>">
                <?php foreach ($item['children'] as $child): ?>
                  <?php $childActive = ($child['key'] ?? null) === $navActive; ?>
                  <li>
                    <a class="dropdown-item<?= $childActive ? ' active' : '' ?>"
                       <?= $childActive ? 'aria-current="page"' : '' ?>
                       href="<?= h((string)$child['href']) ?>">
                      <i class="bi <?= h((string)$child['icon']) ?>"></i><?= h((string)$child['label']) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>

      <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2">

        <?php if ($navSearch): ?>
          <form class="app-search" role="search" method="get" action="students.php" data-app-search>
            <i class="bi bi-search app-search-icon"></i>
            <input name="q" type="search" placeholder="Kërko studentë…" aria-label="Kërko studentë"
                   value="<?= h((string)($_GET['q'] ?? '')) ?>">
            <kbd class="d-none d-xl-block">/</kbd>
          </form>
        <?php endif; ?>

        <div class="d-flex align-items-center gap-2">
          <button class="app-icon-btn d-none d-lg-inline-flex" type="button" data-theme-toggle aria-label="Ndrysho temën">
            <i class="bi bi-moon-stars"></i>
          </button>

          <button class="app-icon-btn d-none d-lg-inline-flex" type="button" data-density-toggle
                  aria-pressed="false" title="Densitet kompakt" aria-label="Ndrysho densitetin e tabelave">
            <i class="bi bi-arrows-collapse"></i>
          </button>

          <a href="verify.php" class="app-icon-btn d-none d-lg-inline-flex" title="Verifiko certifikatë" aria-label="Verifiko certifikatë">
            <i class="bi bi-qr-code-scan"></i>
          </a>

          <a href="index.php" class="app-icon-btn" title="Faqja publike" aria-label="Faqja publike">
            <i class="bi bi-globe2"></i>
          </a>

          <div class="dropdown flex-grow-1 flex-lg-grow-0">
            <button class="app-user-btn dropdown-toggle w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <span class="app-avatar"><?= h($navInit) ?></span>
              <span class="app-user-name"><?= h($navWho) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width: 260px;">
              <li class="px-2 py-2">
                <div class="d-flex align-items-center gap-2">
                  <span class="app-avatar app-avatar-lg"><?= h($navInit) ?></span>
                  <div class="min-w-0">
                    <div class="fw-bold text-truncate"><?= h($navWho) ?></div>
                    <?php if ($navEmail !== ''): ?>
                      <div class="small text-muted text-truncate"><?= h($navEmail) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item<?= $navProfile ? ' active' : '' ?>"
                   <?= $navProfile ? 'aria-current="page"' : '' ?> href="profile.php">
                  <i class="bi bi-person-gear"></i>Profili
                </a>
              </li>
              <li class="d-lg-none">
                <button class="dropdown-item" type="button" data-theme-toggle>
                  <i class="bi bi-moon-stars"></i><span data-theme-label>Modalitet i errët</span>
                </button>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item text-danger" href="logout.php">
                  <i class="bi bi-box-arrow-right"></i>Dil
                </a>
              </li>
            </ul>
          </div>
        </div>
      </div>

    </div>
  </div>
</nav>
