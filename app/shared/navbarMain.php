<?php
declare(strict_types=1);

/**
 * navbarMain.php — Koka e faqeve publike (Themeli).
 * Marka majtas; Kreu · Verifiko · Rreth nesh · Kontakt; pamja dhe hyrja djathtas.
 * Në celular lidhjet hapen nga butoni "Menuja".
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
$displayName = (string)(($currentUser['full_name'] ?? '') ?: ($currentUser['email'] ?? 'Përdorues'));
$panelHref   = qta_public_panel_href($roleName);
$panelLabel  = qta_public_panel_label($roleName);

$links = [
  ['key' => 'home',    'href' => 'index.php',   'label' => 'Kreu',                 'icon' => null],
  ['key' => 'verify',  'href' => 'verify.php',  'label' => 'Verifiko certifikatë', 'icon' => 'bi-qr-code-scan'],
  ['key' => 'about',   'href' => 'aboutus.php', 'label' => 'Rreth nesh',           'icon' => null],
  ['key' => 'contact', 'href' => 'contact.php', 'label' => 'Kontakt',              'icon' => null],
];
?>
<header class="masthead no-print">
  <div class="wrap masthead-inner">

    <a class="masthead-brand" href="index.php" aria-label="Regjistri QTA — kreu">
      <img src="<?= h(qta_asset('image/logoPNG2.png')) ?>" alt="">
      <span class="masthead-brand-text">
        <b>Regjistri QTA</b>
        <span>Qendra e Trajnimeve të Avancuara</span>
      </span>
    </a>

    <button class="btn btn-secondary masthead-menu-btn" type="button" data-mast-toggle
            aria-expanded="false" aria-controls="mastNav">
      <i class="bi bi-list" aria-hidden="true"></i><span class="masthead-menu-label">Menuja</span>
    </button>

    <nav class="masthead-nav" id="mastNav" aria-label="Navigimi kryesor">
      <?php foreach ($links as $l):
        $isActive = $NAV_ACTIVE === $l['key']; ?>
        <a class="masthead-link<?= $isActive ? ' is-active' : '' ?><?= $l['key'] === 'verify' ? ' is-verify' : '' ?>"
           href="<?= h($l['href']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
          <?php if ($l['icon']): ?><i class="bi <?= h($l['icon']) ?>" aria-hidden="true"></i><?php endif; ?>
          <?= h($l['label']) ?>
        </a>
      <?php endforeach; ?>

      <div class="masthead-actions">
        <button class="btn btn-ghost btn-icon" type="button" data-theme-toggle aria-label="Ndrysho pamjen">
          <i class="bi bi-moon-stars" aria-hidden="true"></i>
        </button>

        <?php if (!empty($currentUser)): ?>
          <div class="dropdown">
            <button class="btn btn-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <span class="avatar" aria-hidden="true"><?= h(qta_initials($displayName)) ?></span>
              <span class="d-none d-sm-inline text-truncate masthead-user"><?= h($displayName) ?></span>
              <span class="visually-hidden">— menuja e llogarisë</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="<?= h($panelHref) ?>"><i class="bi bi-house-door" aria-hidden="true"></i><?= h($panelLabel) ?></a></li>
              <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person" aria-hidden="true"></i>Profili im</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Dil nga llogaria</a></li>
            </ul>
          </div>
        <?php else: ?>
          <a class="btn btn-primary" href="selectProfile.php"<?= $NAV_ACTIVE === 'login' ? ' aria-current="page"' : '' ?>>
            <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Hyr
          </a>
        <?php endif; ?>
      </div>
    </nav>

  </div>
</header>
