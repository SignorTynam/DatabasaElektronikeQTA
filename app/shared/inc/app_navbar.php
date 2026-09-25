<?php
declare(strict_types=1);

/**
 * app_navbar.php — Shell-i i panelit (Themeli).
 *
 * Desktop: menu anësore e përhershme me seksione të emërtuara.
 * Celular: shirit i sipërm + e njëjta menu si sirtar (drawer) i aksesueshëm.
 *
 * Variabla opsionale nga faqja prind:
 *   $NAV_ACTIVE   string  çelësi i faqes aktive
 *   $NAV_ROLE     string  roli që përcakton menunë
 *   $currentUser  array   id, full_name, email, role_name
 */

require_once __DIR__ . '/../app_ui.php';

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

$navRole   = strtolower((string)($NAV_ROLE ?? $currentUser['role_name'] ?? ''));
$navActive = $NAV_ACTIVE ?? qta_app_active_key();
$navMenu   = qta_app_menu($navRole);
$navExtra  = qta_app_secondary_menu();
$navHome   = qta_app_home($navRole);
$navWho    = (string)(($currentUser['full_name'] ?? '') ?: ($currentUser['email'] ?? 'Përdorues'));
$navEmail  = (string)($currentUser['email'] ?? '');
$navInit   = qta_initials($navWho);
$navLabel  = qta_app_role_label($navRole);
$navSearch = qta_app_can_search($navRole);
$navSearchTypes = match ($navRole) {
  'administrator' => 'student,group,course,agency,user,audit',
  'editor'        => 'student,group,course,agency,audit',
  'agjencia'      => 'student,group',
  'student'       => 'student',
  default         => '',
};

$navLink = static function (array $item) use ($navActive): string {
  $active = ($item['key'] ?? null) === $navActive;
  return '<a class="nav-item' . ($active ? ' is-active' : '') . '"'
    . ($active ? ' aria-current="page"' : '')
    . ' href="' . h((string)$item['href']) . '" title="' . h((string)$item['label']) . '">'
    . '<i class="bi ' . h((string)($item['icon'] ?? 'bi-circle')) . '" aria-hidden="true"></i>'
    . '<span class="nav-label">' . h((string)$item['label']) . '</span></a>';
};
?>
<header class="topbar no-print">
  <button class="btn btn-ghost btn-icon" type="button" data-drawer-open
          aria-controls="appSidebar" aria-expanded="false" aria-label="Hap menunë">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>
  <a class="topbar-brand" href="<?= h($navHome) ?>">
    <img src="<?= h(qta_asset('image/logoPNG2.png')) ?>" alt="">
    <span>Regjistri QTA</span>
  </a>
  <div class="topbar-actions">
    <?php if ($navSearch): ?>
      <button class="btn btn-ghost btn-icon" type="button" data-open-palette aria-label="Kërko në regjistër">
        <i class="bi bi-search" aria-hidden="true"></i>
      </button>
    <?php endif; ?>
    <a class="btn btn-ghost btn-icon" href="ndihme.php" aria-label="Ndihmë">
      <i class="bi bi-question-circle" aria-hidden="true"></i>
    </a>
  </div>
</header>

<aside class="sidebar no-print" id="appSidebar" data-sidebar data-search-types="<?= h($navSearchTypes) ?>" aria-label="Menuja kryesore">
  <div class="sidebar-head">
    <a class="brand" href="<?= h($navHome) ?>" title="Regjistri QTA — kreu">
      <img class="brand-logo" src="<?= h(qta_asset('image/logoPNG2.png')) ?>" alt="QTA">
      <span class="brand-text"><b>Regjistri</b></span>
    </a>
    <button class="btn btn-ghost btn-icon btn-sm sidebar-rail-toggle" type="button" data-sidebar-toggle
            aria-pressed="false" aria-label="Ngushto menunë" title="Ngushto menunë">
      <i class="bi bi-chevron-bar-left" aria-hidden="true"></i>
    </button>
    <button class="btn btn-ghost btn-icon sidebar-close" type="button" data-drawer-close aria-label="Mbyll menunë">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
  </div>

  <?php if ($navSearch): ?>
    <button class="sidebar-search" type="button" data-open-palette title="Kërko në regjistër (Ctrl K)">
      <i class="bi bi-search" aria-hidden="true"></i>
      <span>Kërko…</span>
      <kbd>Ctrl K</kbd>
    </button>
  <?php endif; ?>

  <nav class="sidebar-nav" aria-label="Seksionet">
    <?php
    $loose = [];
    $flushLoose = static function () use (&$loose, $navLink): void {
      if (!$loose) {
        return;
      }
      echo '<ul class="nav-list">';
      foreach ($loose as $item) {
        echo '<li>' . $navLink($item) . '</li>';
      }
      echo '</ul>';
      $loose = [];
    };

    foreach ($navMenu as $i => $item) {
      if (empty($item['children'])) {
        $loose[] = $item;
        continue;
      }
      $flushLoose();
      $headingId = 'navSection' . (int)$i;
      echo '<div class="nav-section">';
      echo '<p class="nav-heading" id="' . h($headingId) . '">' . h((string)$item['label']) . '</p>';
      echo '<ul class="nav-list" aria-labelledby="' . h($headingId) . '">';
      foreach ($item['children'] as $child) {
        echo '<li>' . $navLink($child) . '</li>';
      }
      echo '</ul></div>';
    }
    $flushLoose();
    ?>
  </nav>

  <div class="sidebar-foot">
    <?php foreach ($navExtra as $item): ?>
      <?= $navLink($item) ?>
    <?php endforeach; ?>

    <div class="dropup">
      <button class="account-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"
              title="<?= h($navWho) ?>">
        <span class="avatar" aria-hidden="true"><?= h($navInit) ?></span>
        <span class="account-text">
          <b><?= h($navWho) ?></b>
          <span><?= h($navLabel) ?></span>
        </span>
        <i class="bi bi-three-dots" aria-hidden="true"></i>
        <span class="visually-hidden">Hap menunë e llogarisë</span>
      </button>
      <ul class="dropdown-menu account-menu">
        <?php if ($navEmail !== ''): ?>
          <li><span class="dropdown-header text-truncate"><?= h($navEmail) ?></span></li>
        <?php endif; ?>
        <li>
          <a class="dropdown-item<?= $navActive === 'profile' ? ' active' : '' ?>" href="profile.php">
            <i class="bi bi-person" aria-hidden="true"></i>Profili im
          </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li><span class="dropdown-header">Pamja</span></li>
        <li><button class="dropdown-item theme-choice" type="button" role="menuitemradio" aria-checked="false" data-theme-set="light"><i class="bi bi-sun" aria-hidden="true"></i>E çelët</button></li>
        <li><button class="dropdown-item theme-choice" type="button" role="menuitemradio" aria-checked="false" data-theme-set="system"><i class="bi bi-circle-half" aria-hidden="true"></i>Sipas pajisjes</button></li>
        <li><button class="dropdown-item theme-choice" type="button" role="menuitemradio" aria-checked="false" data-theme-set="dark"><i class="bi bi-moon-stars" aria-hidden="true"></i>E errët</button></li>
        <li><hr class="dropdown-divider"></li>
        <li>
          <a class="dropdown-item" href="index.php">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Faqja publike
          </a>
        </li>
        <li>
          <a class="dropdown-item text-danger" href="logout.php">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>Dil nga llogaria
          </a>
        </li>
      </ul>
    </div>
  </div>
</aside>
<div class="sidebar-backdrop no-print" data-drawer-close hidden></div>
