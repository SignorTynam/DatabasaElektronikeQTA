<?php
/**
 * inc/navbar2.php — Navbar për rolin "agjencia"
 * Variabla të disponueshme nga faqja prind:
 *   - $NAV_ACTIVE  : string  (p.sh. 'dashboard', 'profile')
 *   - $currentUser : array   (id, full_name, email, role_name)
 *   - $AGENCY      : array   (id, company_name, nip_t, ...)
 *   - funksioni h(): escape HTML
 */

declare(strict_types=1);

/* Helper sigurie për HTML */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* Zbulo faqen aktive nëse s’është dhënë */
$active = $NAV_ACTIVE ?? null;
if ($active === null) {
  $script = strtolower(basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''));
  $map = [
    'dashboard_agjencia.php' => 'dashboard',
    'profile.php'            => 'profile',
    // shto këtu faqet e tjera të agjencisë sipas nevojës:
    // 'students_agjencia.php'  => 'students',
  ];
  $active = $map[$script] ?? '';
}
$isDashboard = ($active === 'dashboard');
$isProfile   = ($active === 'profile');

/* Label-et e headerit */
$agencyName = $AGENCY['company_name'] ?? 'Agjenci';
$who        = ($currentUser['full_name'] ?? '') ?: ($currentUser['email'] ?? 'Profili');
$role       = strtolower((string)($currentUser['role_name'] ?? 'agjencia'));
$roleLabel  = ($role === 'agjencia' || $role === 'agency') ? 'Agjenci' : ucfirst($role);
?>
<style>
  /* Kompensim për navbar-in fixed-top që të mos mbulohet përmbajtja */
  :root { --agency-nav-height: 56px; }
  @media (min-width: 992px){ :root { --agency-nav-height: 64px; } }
  body { padding-top: var(--agency-nav-height); }

  /* Detaje UI */
  .navbar-brand img{ height:28px; width:auto; }
  .nav-link.active, .dropdown-item.active { font-weight:600; }
  .nav-role-badge{
    font-size:.75rem; padding:.2rem .5rem; border:1px solid rgba(255,255,255,.25);
    border-radius:.5rem; color:#e5e7eb;
  }
  @media (min-width: 992px){ .navbar .vr{ opacity:.25; } }
</style>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top shadow-sm" data-bs-theme="dark" role="navigation" aria-label="Navbar agjencie">
  <div class="container-fluid px-3 px-md-4">

    <!-- Brand + badge roli -->
    <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_agjencia.php">
      <img src="image/logoPNG2.png" alt="QTA">
      <span class="nav-role-badge ms-1 d-none d-md-inline"><i class="bi bi-building me-1"></i><?= h($roleLabel) ?></span>
    </a>

    <!-- Quick actions (mobile) + toggler -->
    <div class="d-flex d-lg-none align-items-center gap-2">
      <a href="verify.php" class="btn btn-outline-light btn-sm" title="Verifiko certifikatë">
        <i class="bi bi-qr-code-scan"></i>
      </a>
      <a href="index.php" class="btn btn-outline-light btn-sm" title="Faqja publike">
        <i class="bi bi-globe2"></i>
      </a>
      <button class="navbar-toggler ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#navAgency"
              aria-controls="navAgency" aria-expanded="false" aria-label="Shfaq/Fsheh menunë">
        <span class="navbar-toggler-icon"></span>
      </button>
    </div>

    <div class="collapse navbar-collapse" id="navAgency">
      <!-- Majtas: seksionet kryesore (mund t’i zgjerohet në të ardhmen) -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link<?= $isDashboard ? ' active' : '' ?>" <?= $isDashboard ? 'aria-current="page"' : '' ?> href="dashboard_agjencia.php">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
          </a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= ($active === 'students' || $active === 'groups') ? ' active' : '' ?>" href="#" id="navUsersDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-people me-1"></i>Përdoruesit
          </a>
          <ul class="dropdown-menu" aria-labelledby="navUsersDropdown">
            <li>
              <a class="dropdown-item<?= $active === 'students' ? ' active' : '' ?>" href="register_agjencia.php">
                <i class="bi bi-person-lines-fill me-2"></i>Të gjithë studentët
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $active === 'groups' ? ' active' : '' ?>" href="groups_agjencia.php">
                <i class="bi bi-people-fill me-2"></i>Grupet studentët
              </a>
            </li>
          </ul>
        </li>

        <?php /* Shembull për zgjerim në të ardhmen:
        <li class="nav-item">
          <a class="nav-link<?= $active==='students' ? ' active' : '' ?>" href="students_agjencia.php">
            <i class="bi bi-mortarboard me-1"></i>Studentët e mi
          </a>
        </li> */ ?>
      </ul>

      <!-- Djathtas: info shpejt + quick actions + profili -->
      <div class="d-flex align-items-center gap-3">

        <div class="vr d-none d-lg-block"></div>

        <!-- Shkurtore -->
        <div class="btn-group" role="group" aria-label="Shkurtore">
          <a href="verify.php" class="btn btn-outline-light btn-sm" title="Verifiko certifikatë"><i class="bi bi-qr-code-scan"></i></a>
          <a href="index.php"  class="btn btn-outline-light btn-sm" title="Faqja publike"><i class="bi bi-globe2"></i></a>
        </div>

        <!-- Dropdown profili -->
        <div class="dropdown">
          <button class="btn btn-light btn-sm dropdown-toggle rounded-pill px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-badge me-1"></i><?= h($who) ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <a class="dropdown-item<?= $isProfile ? ' active' : '' ?>" <?= $isProfile ? 'aria-current="page"' : '' ?> href="profile.php">
                <i class="bi bi-person-gear me-2"></i>Profili
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item text-danger" href="logout.php">
                <i class="bi bi-box-arrow-right me-2"></i>Dil
              </a>
            </li>
          </ul>
        </div>

      </div>
    </div>
  </div>
</nav>
