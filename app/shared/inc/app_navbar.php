<?php
declare(strict_types=1);

/**
 * app_navbar.php — Kokëfleta e panelit (PROTOKOLL).
 *
 * Nuk është një "navbar": është koka e një formulari zyrtar — marka, roli i
 * nënshkruesit, seksionet e regjistrit dhe mjetet. Mbyllet me vijë boje 2px.
 *
 * Variabla opsionale nga faqja prind:
 *   $NAV_ACTIVE   string  çelësi i faqes aktive
 *   $NAV_ROLE     string  roli që përcakton menunë
 *   $NAV_SUBTITLE string  rresht i dytë nën markë
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
$navHome   = qta_app_home($navRole);
$navWho    = (string)(($currentUser['full_name'] ?? '') ?: ($currentUser['email'] ?? 'Përdorues'));
$navEmail  = (string)($currentUser['email'] ?? '');
$navInit   = qta_app_initials($navWho);
$navLabel  = qta_app_role_label($navRole);
$navSub    = (string)($NAV_SUBTITLE ?? '');
$navSearch = qta_app_can_search($navRole);

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
<header class="app-bar no-print">
  <div class="app-bar-inner">

    <a class="app-mark" href="<?= h($navHome) ?>">
      <img src="image/logoPNG2.png" alt="QTA">
      <span class="app-mark-name">
        <b>Regjistri QTA</b>
        <span><?= h($navSub !== '' ? $navSub : 'Qendra e Trajnimeve të Avancuara') ?></span>
      </span>
    </a>

    <span class="app-role d-none d-md-inline-flex">
      Nënshkrues: <b><?= h($navLabel) ?></b>
    </span>

    <button class="app-burger ms-auto" type="button" data-nav-toggle
            aria-expanded="false" aria-controls="appNav" aria-label="Hap seksionet">
      <i class="bi bi-list"></i>
    </button>

    <nav class="app-nav" id="appNav" aria-label="Seksionet e regjistrit">
      <ul class="app-nav-list">
        <?php foreach ($navMenu as $i => $item): ?>
          <?php $isActive = $navIsActive($item); ?>
          <?php if (empty($item['children'])): ?>
            <li>
              <a class="app-nav-link<?= $isActive ? ' is-active' : '' ?>"
                 <?= $isActive ? 'aria-current="page"' : '' ?>
                 href="<?= h((string)$item['href']) ?>"><?= h((string)$item['label']) ?></a>
            </li>
          <?php else: ?>
            <li class="app-item">
              <button class="app-nav-link<?= $isActive ? ' is-active' : '' ?>" type="button"
                      data-sub-toggle aria-expanded="false" aria-controls="appSub<?= (int)$i ?>">
                <?= h((string)$item['label']) ?><span class="caret" aria-hidden="true">▾</span>
              </button>
              <ul class="app-sub" id="appSub<?= (int)$i ?>">
                <?php foreach ($item['children'] as $child): ?>
                  <?php $childActive = ($child['key'] ?? null) === $navActive; ?>
                  <li>
                    <a class="app-nav-link<?= $childActive ? ' is-active' : '' ?>"
                       <?= $childActive ? 'aria-current="page"' : '' ?>
                       href="<?= h((string)$child['href']) ?>"><?= h((string)$child['label']) ?></a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </nav>

    <div class="app-tools">
      <?php if ($navSearch): ?>
        <form class="app-find" role="search" method="get" action="students.php" data-app-search>
          <input name="q" type="search" placeholder="Kërko kursant…" aria-label="Kërko kursant"
                 value="<?= h((string)($_GET['q'] ?? '')) ?>">
          <button type="submit" aria-label="Kërko"><i class="bi bi-search"></i></button>
        </form>
      <?php endif; ?>

      <button class="app-tool" type="button" data-theme-toggle aria-label="Ndërro pamjen">
        <i class="bi bi-circle-half"></i>
      </button>

      <button class="app-tool d-none d-lg-inline-flex" type="button" data-density-toggle
              aria-pressed="false" aria-label="Ndërro densitetin e tabelave" title="Densiteti">
        <i class="bi bi-list-nested"></i>
      </button>

      <a class="app-tool" href="verify.php" title="Verifiko certifikatë" aria-label="Verifiko certifikatë">
        <i class="bi bi-patch-check"></i>
      </a>

      <div class="dropdown">
        <button class="app-who" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <span class="initials"><?= h($navInit) ?></span>
          <span class="app-who-name d-none d-sm-inline"><?= h($navWho) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" style="min-width:246px">
          <li class="px-2 py-2 d-flex align-items-center gap-2">
            <span class="initials initials-lg"><?= h($navInit) ?></span>
            <span class="min-w-0">
              <span class="d-block text-truncate" style="font-family:var(--font-record);font-weight:600"><?= h($navWho) ?></span>
              <?php if ($navEmail !== ''): ?>
                <span class="d-block text-truncate muted" style="font-size:var(--fs-xs)"><?= h($navEmail) ?></span>
              <?php endif; ?>
            </span>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item<?= $navActive === 'profile' ? ' active' : '' ?>" href="profile.php"><i class="bi bi-person"></i>Profili</a></li>
          <li><a class="dropdown-item" href="index.php"><i class="bi bi-box-arrow-up-right"></i>Faqja publike</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i>Dil</a></li>
        </ul>
      </div>
    </div>

  </div>
</header>
