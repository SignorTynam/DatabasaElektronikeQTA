<?php
// navbarMain.php — QTA Light Slim (jo “AI”), CSS i izoluar vetëm për navbar.
// Kërkon Bootstrap 5.3+ CSS & Bootstrap Icons të ngarkuara në prind.

if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

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

$roleName    = isset($currentUser['role_name']) ? strtolower((string)$currentUser['role_name']) : null;
$displayName = $currentUser['full_name'] ?? ($currentUser['email'] ?? 'Përdorues');

function qta_nav_active(string $slug, string $active): string { return $slug === $active ? 'active' : ''; }
function qta_nav_aria(string $slug, string $active): string { return $slug === $active ? 'aria-current="page"' : ''; }

function qta_initials(string $name): string {
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
function qta_role_badge_color(?string $role): string {
  return match ($role) {
    'administrator' => 'danger',
    'agjencia'      => 'success',
    'editor'        => 'warning',
    'student'       => 'info',
    default         => 'secondary'
  };
}
function qta_role_panel_href(?string $role): string {
  return match ($role) {
    'administrator' => 'dashboard_admin.php',
    'agjencia'      => 'dashboard_agjencia.php',
    'editor'        => 'dashboard_editor.php',
    'student'       => 'dashboard_student.php',
    default         => 'selectProfile.php'
  };
}
function qta_role_panel_label(?string $role): string {
  return match ($role) {
    'administrator' => 'Paneli i Administrimit',
    'agjencia'      => 'Paneli i Agjencisë',
    'editor'        => 'Paneli i Editorit',
    'student'       => 'Paneli i Studentit',
    default         => 'Zgjidh rolin'
  };
}

$avatarIni  = qta_initials((string)$displayName);
$badgeColor = qta_role_badge_color($roleName);
$panelHref  = qta_role_panel_href($roleName);
$panelLabel = qta_role_panel_label($roleName);
?>

<style>
/* ==========================================================
   QTA NAV (Slim + light + pak transparente)
   CSS i izoluar: prek vetëm brenda nav.qtaNav
   ========================================================== */
nav.qtaNav{
  --qta-bg: rgba(255,255,255,.86);
  --qta-bd: rgba(15,23,42,.10);
  --qta-text: #0f172a;
  --qta-muted: #64748b;
  --qta-primary: #2563eb;

  background: var(--qta-bg);
  border-bottom: 1px solid var(--qta-bd);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  box-shadow: 0 8px 24px rgba(2,6,23,.06);
}

nav.qtaNav .qtaNav-wrap{ min-height: 56px; } /* slim */

nav.qtaNav .qtaNav-brand{
  display:flex; align-items:center; gap:.6rem;
  text-decoration:none;
  color: var(--qta-text);
}
nav.qtaNav .qtaNav-brand:hover{ color: var(--qta-text); }

nav.qtaNav .qtaNav-brand img{ height: 28px; width:auto; display:block; }
nav.qtaNav .qtaNav-title{ font-weight: 900; letter-spacing:.2px; }
nav.qtaNav .qtaNav-sub{ color: var(--qta-muted); font-weight: 700; font-size: .92rem; }

nav.qtaNav a.qtaNav-link{
  color: var(--qta-muted) !important;
  font-weight: 800;
  font-size: .95rem;
  padding: .42rem .70rem;
  border-radius: 9999px;
  border: 1px solid transparent;
  transition: background .12s ease, color .12s ease, border-color .12s ease;
}
nav.qtaNav a.qtaNav-link:hover{
  color: var(--qta-text) !important;
  background: rgba(15,23,42,.04);
  border-color: rgba(15,23,42,.08);
}

/* Active pill (si “Kryefaqja” në screenshot, por pa u dukur “AI”) */
nav.qtaNav a.qtaNav-link.active{
  color: #fff !important;
  background: var(--qta-primary);
  border-color: rgba(37,99,235,.35);
  box-shadow: 0 8px 18px rgba(37,99,235,.18);
}

nav.qtaNav .navbar-toggler{
  border: 1px solid rgba(15,23,42,.14);
  border-radius: 9999px;
  padding: .34rem .58rem;
}
nav.qtaNav .navbar-toggler:focus{
  box-shadow: 0 0 0 .22rem rgba(37,99,235,.14);
}

/* Avatar */
nav.qtaNav .qtaNav-avatar{
  width: 32px; height: 32px; border-radius: 9999px;
  display:inline-flex; align-items:center; justify-content:center;
  font-weight: 900; font-size: .80rem;
  color: var(--qta-text);
  background: rgba(255,255,255,.95);
  border: 1px solid rgba(15,23,42,.10);
}

/* Dropdown vetëm brenda navbar */
nav.qtaNav .qtaNav-dd{
  min-width: 290px;
  border: 1px solid rgba(15,23,42,.10);
  border-radius: 14px;
  box-shadow: 0 18px 44px rgba(2,6,23,.12);
}
nav.qtaNav .qtaNav-dd .dropdown-item{
  border-radius: 12px;
  font-weight: 750;
}
nav.qtaNav .qtaNav-dd .dropdown-item:hover{
  background: rgba(15,23,42,.04);
}

/* Button Hyr: i thjeshtë, jo “gradient” */
nav.qtaNav .qtaNav-cta{
  border-radius: 9999px;
  font-weight: 900;
  padding: .44rem .90rem;
}

/* Mobile collapse = card e thjeshtë */
@media (max-width: 991.98px){
  nav.qtaNav .navbar-collapse{
    margin-top: .55rem;
    background: rgba(255,255,255,.92);
    border: 1px solid rgba(15,23,42,.10);
    border-radius: 14px;
    padding: .6rem;
  }
  nav.qtaNav .qtaNav-sub{ display:none; }
}
</style>

<nav class="navbar navbar-expand-lg navbar-light sticky-top qtaNav" aria-label="QTA Navigation">
  <div class="container qtaNav-wrap">

    <a class="qtaNav-brand" href="index.php">
      <img src="image/logoPNG2.png" alt="QTA">
      <span class="qtaNav-title">QTA</span>
      <span class="d-none d-md-inline qtaNav-sub">Qendra e Trajnimeve të Avancuara</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#qtaNavMain"
            aria-controls="qtaNavMain" aria-expanded="false" aria-label="Hap menunë">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="qtaNavMain">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">

        <li class="nav-item">
          <a class="nav-link qtaNav-link <?= h(qta_nav_active('home', $NAV_ACTIVE)) ?>"
             href="index.php" <?= qta_nav_aria('home', $NAV_ACTIVE) ?>>
            <i class="bi bi-house-door me-1"></i>Kryefaqja
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link qtaNav-link <?= h(qta_nav_active('about', $NAV_ACTIVE)) ?>"
             href="aboutus.php" <?= qta_nav_aria('about', $NAV_ACTIVE) ?>>
            <i class="bi bi-info-circle me-1"></i>Rreth nesh
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link qtaNav-link <?= h(qta_nav_active('contact', $NAV_ACTIVE)) ?>"
             href="contact.php" <?= qta_nav_aria('contact', $NAV_ACTIVE) ?>>
            <i class="bi bi-envelope me-1"></i>Kontakt
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link qtaNav-link <?= h(qta_nav_active('verify', $NAV_ACTIVE)) ?>"
             href="verify.php" <?= qta_nav_aria('verify', $NAV_ACTIVE) ?>>
            <i class="bi bi-qr-code-scan me-1"></i>Verifiko
          </a>
        </li>

        <?php if (!empty($currentUser)): ?>
          <li class="nav-item dropdown ms-lg-2 my-2 my-lg-0">
            <a class="nav-link dropdown-toggle qtaNav-link d-flex align-items-center" href="#"
               id="qtaNavUserMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <span class="qtaNav-avatar me-2"><?= h($avatarIni) ?></span>
              <span class="d-none d-md-inline"><?= h($displayName) ?></span>
            </a>

            <ul class="dropdown-menu dropdown-menu-end qtaNav-dd" aria-labelledby="qtaNavUserMenu">
              <li class="px-3 pt-3 pb-2">
                <div class="d-flex align-items-center">
                  <span class="qtaNav-avatar me-2"><?= h($avatarIni) ?></span>
                  <div>
                    <div class="fw-semibold"><?= h($displayName) ?></div>
                    <?php if (!empty($currentUser['email'])): ?>
                      <div class="small text-muted"><?= h($currentUser['email']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="mt-2">
                  <span class="badge bg-<?= h($badgeColor) ?> rounded-pill">
                    <?= h($roleName ? ucfirst($roleName) : 'Përdorues') ?>
                  </span>
                </div>
              </li>

              <li><hr class="dropdown-divider my-2"></li>

              <li>
                <a class="dropdown-item d-flex align-items-center" href="<?= h($panelHref) ?>">
                  <i class="bi bi-speedometer2 me-2"></i><?= h($panelLabel) ?>
                </a>
              </li>

              <li><hr class="dropdown-divider my-2"></li>

              <li>
                <a class="dropdown-item d-flex align-items-center text-danger" href="logout.php">
                  <i class="bi bi-box-arrow-right me-2"></i>Dil
                </a>
              </li>
            </ul>
          </li>

        <?php else: ?>
          <li class="nav-item ms-lg-2 my-2 my-lg-0">
            <a class="btn btn-primary qtaNav-cta" href="selectProfile.php">
              <i class="bi bi-box-arrow-in-right me-1"></i>Hyr
            </a>
          </li>
        <?php endif; ?>

      </ul>
    </div>
  </div>
</nav>
