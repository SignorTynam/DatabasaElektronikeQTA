<?php
declare(strict_types=1);

/**
 * Student Navbar (styled like admin navbar)
 * -------------------------------------------------
 * Përfshije PAS autentikimit dhe pasi të kesh $currentUser.
 * Nëse s’është vendosur, ky skedar përpiqet ta lexojë vetë.
 * Kërkon Bootstrap 5.3+ (CSS & JS) dhe Bootstrap Icons.
 */

/* Helper i sigurt për HTML */
if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* Ngarko $currentUser nëse mungon (opsionale) */
if (!isset($currentUser)) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    require_once __DIR__ . '/../database.php';
    $pdo = getPDO();
    if (!empty($_SESSION['user_id'])) {
        $st = $pdo->prepare("
          SELECT u.id, u.full_name, u.email, r.name AS role_name
          FROM users u JOIN roles r ON r.id=u.role_id
          WHERE u.id=:id LIMIT 1
        ");
        $st->execute([':id'=>$_SESSION['user_id']]);
        $currentUser = $st->fetch() ?: ['full_name'=>null,'email'=>null,'role_name'=>null];
    } else {
        $currentUser = ['full_name'=>null,'email'=>null,'role_name'=>null];
    }
}

/* Cakto faqen aktive (nga $NAV_ACTIVE ose auto) */
$active = $NAV_ACTIVE ?? null;
if ($active === null) {
    $script = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
    $map = [
        'dashboard_student.php' => 'dashboard',
        'profile.php'           => 'profile',
    ];
    $active = $map[$script] ?? '';
}

/* Flage për active states */
$isDash    = $active === 'dashboard';
$isProfile = $active === 'profile';

/* Emri/roli */
$role = strtolower((string)($currentUser['role_name'] ?? 'student')) ?: 'student';
$roleLabel = ucfirst($role); // "Student"
$who = $currentUser['full_name'] ?: ($currentUser['email'] ?? 'Student');
?>
<style>
  /* Kompensim për fixed-top (shmang “overlap” me përmbajtjen) */
  :root { --student-nav-height: 56px; }
  body { padding-top: var(--student-nav-height); }
  @media (min-width: 992px){ :root { --student-nav-height: 64px; } }

  .navbar-brand img{ height:28px; width:auto; }
  .nav-link.active, .dropdown-item.active { font-weight: 600; }
  .nav-role-badge {
    font-size:.75rem; padding:.2rem .5rem; border:1px solid rgba(255,255,255,.25);
    border-radius:.5rem; color:#e5e7eb;
  }
  @media (min-width: 992px){ .navbar .vr { opacity:.25; } }
</style>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top" data-bs-theme="dark" role="navigation" aria-label="Navbar studenti">
  <div class="container-fluid">

    <!-- Brand -->
    <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_student.php">
      <img src="image/logoPNG2.png" alt="QTA">
      <span class="nav-role-badge ms-1 d-none d-md-inline"><i class="bi bi-mortarboard me-1"></i><?= h($roleLabel) ?></span>
    </a>

    <!-- Quick actions (mobile first) -->
    <div class="d-flex d-lg-none align-items-center gap-2">
      <a href="verify.php" class="btn btn-outline-light btn-sm" title="Verifiko certifikatë">
        <i class="bi bi-qr-code-scan"></i>
      </a>
      <a href="index.php" class="btn btn-outline-light btn-sm" title="Shko te faqja publike">
        <i class="bi bi-globe2"></i>
      </a>
      <button class="navbar-toggler ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#topNav3" aria-controls="topNav3" aria-expanded="false" aria-label="Shfaq/FSheh menunë">
        <span class="navbar-toggler-icon"></span>
      </button>
    </div>

    <!-- Menu -->
    <div class="collapse navbar-collapse" id="topNav3">

      <!-- Left: seksionet kryesore -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <!-- Dashboard -->
        <li class="nav-item">
          <a class="nav-link<?= $isDash ? ' active' : '' ?>" <?= $isDash ? 'aria-current="page"' : '' ?> href="dashboard_student.php">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
          </a>
        </li>
        <!-- (Opsionale) Lidhje te tjera të studentit mund të shtohen këtu
             p.sh. "Dokumentet", "Orari", etj. -->
      </ul>

      <!-- Right: aksione + profili -->
      <div class="d-flex align-items-center gap-3">

        <div class="vr d-none d-lg-block"></div>

        <!-- Butona të shpejtë -->
        <div class="btn-group" role="group" aria-label="Shkurtore">
          <a href="verify.php" class="btn btn-outline-light btn-sm" title="Verifiko certifikatë">
            <i class="bi bi-qr-code-scan"></i>
          </a>
          <a href="index.php" class="btn btn-outline-light btn-sm" title="Faqja publike">
            <i class="bi bi-globe2"></i>
          </a>
        </div>

        <!-- Profili -->
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
