<?php
// navbarMain.php (revamped)
// -----------------------------------------------------------
// Përdorim nga faqet:
//   $NAV_ACTIVE = 'home' | 'about' | 'contact' | 'verify'; // (opsionale)
//   $currentUser = [...]; // (opsionale: ['full_name','email','role_name'])
//   require __DIR__ . '/navbarMain.php';
// Kërkon Bootstrap 5.3+ CSS & Icons të ngarkuara në prind.

// Helper sigurie
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

// Zbulo automatikisht "active" nëse s’është vendosur manualisht
if (empty($NAV_ACTIVE)) {
  $path = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
  $NAV_ACTIVE = match ($path) {
    'index.php', ''                    => 'home',
    'aboutus.php'                      => 'about',
    'contact.php', 'contact.html'      => 'contact',
    'verify.php'                       => 'verify',
    default                            => ''
  };
}

// Të dhënat e përdoruesit (nëse është i loguar)
$roleName    = $currentUser['role_name'] ?? null;                  // p.sh. administrator | agjencia | student
$displayName = $currentUser['full_name'] ?? ($currentUser['email'] ?? 'Përdorues');

// Funksione ndihmëse
function nav_is_active(string $slug, string $active): string {
  return $slug === $active ? 'active" aria-current="page' : '"';
}
function initials_from_name(string $name): string {
  $name = trim($name);
  if ($name === '') return 'U';
  $parts = preg_split('/\s+/', $name);
  $ini = '';
  foreach ($parts as $p) {
    if ($p !== '') { $ini .= strtoupper(substr($p, 0, 1)); }
    if (strlen($ini) >= 2) break;
  }
  return $ini ?: 'U';
}
function role_badge_color(?string $role): string {
  return match ($role) {
    'administrator' => 'danger',
    'agjencia'      => 'success',
    'student'       => 'info',
    default         => 'secondary'
  };
}
function role_panel_href(?string $role): string {
  return match ($role) {
    'administrator' => 'dashboard_admin.php',
    'agjencia'      => 'dashboard_agjencia.php',
    'student'       => 'dashboard_student.php',
    default         => 'selectProfile.php'
  };
}
function role_panel_label(?string $role): string {
  return match ($role) {
    'administrator' => 'Paneli i Administrimit',
    'agjencia'      => 'Paneli i Agjencisë',
    'student'       => 'Paneli i Studentit',
    default         => 'Zgjidh rolin'
  };
}

$avatarIni   = initials_from_name($displayName);
$badgeColor  = role_badge_color($roleName);
$panelHref   = role_panel_href($roleName);
$panelLabel  = role_panel_label($roleName);
?>
<style>
  /* Glass navbar + aksent i lehtë */
  .navbar-glass {
    --nbg: rgba(15, 23, 42, .75);              /* slate-900 me transparencë */
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    background: var(--nbg) !important;
    border-bottom: 1px solid rgba(255,255,255,.08);
  }
  .navbar-glass .nav-link {
    color: rgba(255,255,255,.85);
  }
  .navbar-glass .nav-link:hover { color:#fff; }
  .navbar-glass .nav-link.active {
    color:#fff !important;
    font-weight:600;
    position:relative;
  }
  .navbar-glass .nav-link.active::after {
    content:"";
    position:absolute; left:.75rem; right:.75rem; bottom:-.25rem;
    height:2px; border-radius:2px; background:linear-gradient(90deg,#0ea5e9,#2563eb,#4f46e5);
  }
  .navbar-brand img { height:30px; }
  .avatar-circle {
    width:32px; height:32px; border-radius:9999px;
    display:inline-flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg,#60a5fa,#7c3aed);
    color:#fff; font-weight:700; font-size:.85rem;
  }
  .dropdown-menu { min-width: 260px; }
  @media (max-width: 991.98px) { /* lg breakpoint */
    .navbar-glass .nav-link.active::after { display:none; } /* shmangem vijën poshtë në mobile */
    .navbar-glass { border-bottom-color: rgba(255,255,255,.12); }
  }
</style>

<nav class="navbar navbar-expand-lg navbar-dark navbar-glass sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <img src="image/logoPNG2.png" alt="QTA">
      <span class="ms-2">Qendra e Trajnimeve të Avancuara</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavMain" aria-controls="navbarNavMain" aria-expanded="false" aria-label="Toggle navigation">
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
        <li class="nav-item">
          <a class="nav-link <?= nav_is_active('verify', $NAV_ACTIVE) ?>" href="verify.php">
            <i class="bi bi-qr-code-scan me-1"></i>Verifiko
          </a>
        </li>

        <?php if (!empty($currentUser)): ?>
          <!-- Përdorues i loguar: dropdown -->
          <li class="nav-item dropdown ms-lg-2 my-2 my-lg-0">
            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <span class="avatar-circle me-2"><?= h($avatarIni) ?></span>
              <span class="d-none d-sm-inline text-white-90"><?= h($displayName) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="userMenu">
              <li class="px-3 py-2">
                <div class="d-flex align-items-center">
                  <div class="avatar-circle me-2"><?= h($avatarIni) ?></div>
                  <div>
                    <div class="fw-semibold"><?= h($displayName) ?></div>
                    <?php if (!empty($currentUser['email'])): ?>
                      <div class="small text-muted"><?= h($currentUser['email']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="mt-2">
                  <span class="badge bg-<?= $badgeColor ?> rounded-pill">
                    <?= h(ucfirst((string)$roleName)) ?: 'Përdorues' ?>
                  </span>
                </div>
              </li>
              <li><hr class="dropdown-divider"></li>

              <?php if ($roleName): ?>
                <li>
                  <a class="dropdown-item d-flex align-items-center" href="<?= h($panelHref) ?>">
                    <i class="bi bi-speedometer2 me-2"></i><?= h($panelLabel) ?>
                  </a>
                </li>
              <?php endif; ?>

              <!-- Mund të shtoni 'account.php' kur ta keni gati -->
              <!--
              <li>
                <a class="dropdown-item d-flex align-items-center" href="account.php">
                  <i class="bi bi-person-gear me-2"></i>Profili im
                </a>
              </li>
              -->

              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item d-flex align-items-center text-danger" href="logout.php">
                  <i class="bi bi-box-arrow-right me-2"></i>Dil
                </a>
              </li>
            </ul>
          </li>
        <?php else: ?>
          <!-- I pa-loguar: buton Hyr -->
          <li class="nav-item my-2 my-lg-0 ms-lg-2">
            <a class="btn btn-primary" href="selectProfile.php">
              <i class="bi bi-box-arrow-in-right me-1"></i>Hyr
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
