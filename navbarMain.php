<?php
// navbarMain.php
// ----------------------------------------------
// Përdorim:
//   $NAV_ACTIVE = 'home' | 'about' | 'contact' | 'verify'; // (opsionale)
//   $currentUser = [...]; // (opsionale: ['full_name','email','role_name'])
//   require __DIR__ . '/navbarMain.php';
//
// Kërkon që faqja prind të ngarkojë Bootstrap CSS & Icons.

// Helper i vogël për sigurinë e output-it
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

// Zbulo automatikisht "active" nëse s’është vendosur manualisht
if (empty($NAV_ACTIVE)) {
  $path = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
  $NAV_ACTIVE = match ($path) {
    'index.php', ''      => 'home',
    'aboutus.php'        => 'about',
    'contact.php', 'contact.html' => 'contact',
    'verify.php'         => 'verify',
    default              => ''
  };
}
$roleName    = $currentUser['role_name'] ?? null;
$displayName = $currentUser['full_name'] ?? ($currentUser['email'] ?? 'Përdorues');

function nav_is_active(string $slug, string $active): string {
  return $slug === $active ? 'active' : '';
}
?>
<!-- Navbar i përbashkët -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <img src="image/logoPNG2.png" alt="Logo" class="me-2" style="height:30px;">
      Qendra e Trajnimeve të Avancuara (QTA)
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNavMain">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        <li class="nav-item">
          <a class="nav-link <?= nav_is_active('home', $NAV_ACTIVE) ?>" href="index.php">
            <i class="bi bi-house-door me-1"></i>Kryefaqja
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= nav_is_active('about', $NAV_ACTIVE) ?>" href="aboutus.php">
            <i class="bi bi-info-circle me-1"></i>Rreth nesh
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= nav_is_active('contact', $NAV_ACTIVE) ?>" href="contact.php">
            <i class="bi bi-envelope me-1"></i>Kontakt
          </a>
        </li>
        <li class="nav-item d-none d-lg-block">
          <a class="nav-link <?= nav_is_active('verify', $NAV_ACTIVE) ?>" href="verify.php">
            <i class="bi bi-qr-code-scan me-1"></i>Verifiko
          </a>
        </li>

        <?php if (!empty($currentUser)): ?>
          <?php if (($roleName ?? '') === 'administrator'): ?>
            <li class="nav-item me-lg-2 my-2 my-lg-0">
              <a class="btn btn-outline-light btn-sm" href="dashboard_admin.php">
          <i class="bi bi-speedometer2 me-1"></i>Paneli
              </a>
            </li>
          <?php endif; ?>
          <li class="nav-item my-2 my-lg-0 d-flex align-items-center">
            <span class="text-white-50 small me-2 d-none d-sm-inline">
              <i class="bi bi-person-circle me-1"></i><?= h($displayName) ?>
            </span>
            <a class="btn btn-primary ms-0 ms-sm-2" href="logout.php">
              <i class="bi bi-box-arrow-right me-1"></i>Dil
            </a>
          </li>
        <?php else: ?>
          <li class="nav-item my-2 my-lg-0">
            <a class="btn btn-primary ms-0 ms-lg-2" href="selectProfile.php">
              <i class="bi bi-box-arrow-in-right me-1"></i>Hyr
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
