<?php
declare(strict_types=1);

/**
 * Editor Navbar
 * -------------------------------------------------
 * Përdoret vetëm nga përdorues me rol "editor".
 * Kërkon Bootstrap 5.3+ (CSS & JS) dhe Bootstrap Icons.
 */

/* Helper HTML-safe */
if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* Lexo $currentUser nëse s’është i kaluar nga jashtë */
if (!isset($currentUser)) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    require_once __DIR__ . '/../database.php';
    $pdo = getPDO();
    $currentUser = ['full_name'=>null,'email'=>null,'role_name'=>null];
    if (!empty($_SESSION['user_id'])) {
        $st = $pdo->prepare("
          SELECT u.id, u.full_name, u.email, r.name AS role_name
          FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.id = :id LIMIT 1
        ");
        $st->execute([':id'=>$_SESSION['user_id']]);
        $currentUser = $st->fetch() ?: $currentUser;
    }
}

/* Sigurohu që është EDITOR (navbar vetëm për editorët) */
$role = strtolower((string)($currentUser['role_name'] ?? ''));
if ($role !== 'editor') {
    // Për çdo rol tjetër, thjesht mos shfaq navin.
    return;
}

/* Cakto faqen aktive (nga $NAV_ACTIVE ose auto) */
$active = $NAV_ACTIVE ?? null;
if ($active === null) {
    $script = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
    $map = [
        'dashboard_editor.php'       => 'dashboard',
        'agencies.php'               => 'users_agencies',
        'students.php'               => 'users_students',
        'student_card.php'           => 'student_card',
        'register.php'               => 'register_full',
        'groups.php'                 => 'register_groups',
        'courses.php'                => 'courses',
        'profile.php'                => 'profile',
        'students_without_groups.php'=> 'students_without_groups',
        'logs_editor.php'            => 'logs_editor',
    ];
    $active = $map[$script] ?? '';
}

/* Flage për active states */
$usersActive            = in_array($active, ['users_agencies','users_students','student_card'], true);
$registerActive         = in_array($active, ['register_full','register_groups','students_without_groups'], true);
$isDash                 = $active === 'dashboard';
$isUsersAg              = $active === 'users_agencies';
$isUsersSt              = $active === 'users_students';
$isStudentCard          = $active === 'student_card';
$isRegFull              = $active === 'register_full';
$isRegGroups            = $active === 'register_groups';
$isCourses              = $active === 'courses';
$isProfile              = $active === 'profile';
$isRegStudentsNoGroups  = $active === 'students_without_groups';
$isLogsEditor           = $active === 'logs_editor';

$who = $currentUser['full_name'] ?: ($currentUser['email'] ?? 'Editor');
?>
<style>
  :root { --admin-nav-height: 56px; }
  body { padding-top: var(--admin-nav-height); }
  @media (min-width: 992px){
    :root { --admin-nav-height: 64px; }
  }
  .navbar-brand img{
    height:28px;
    width:auto;
  }
  .nav-link.active, .dropdown-item.active {
    font-weight: 600;
  }
  .nav-role-badge {
    font-size:.75rem;
    padding:.2rem .5rem;
    border:1px solid rgba(255,255,255,.25);
    border-radius:.5rem;
    color:#e5e7eb;
  }
  @media (min-width: 992px){
    .navbar .vr { opacity:.25; }
  }
</style>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top" data-bs-theme="dark" role="navigation" aria-label="Navbar editor">
  <div class="container-fluid">

    <!-- Brand -->
    <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_editor.php">
      <img src="image/logoPNG2.png" alt="QTA">
      <span class="nav-role-badge ms-1 d-none d-md-inline">
        <i class="bi bi-pencil-square me-1"></i>Editor
      </span>
    </a>

    <!-- Quick (mobile) -->
    <div class="d-flex d-lg-none align-items-center gap-2">
      <a href="verify.php" class="btn btn-outline-light btn-sm" title="Verifiko certifikatë">
        <i class="bi bi-qr-code-scan"></i>
      </a>
      <a href="index.php" class="btn btn-outline-light btn-sm" title="Faqja publike">
        <i class="bi bi-globe2"></i>
      </a>
      <button class="navbar-toggler ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#topNav" aria-controls="topNav" aria-expanded="false" aria-label="Shfaq/Fsheh menunë">
        <span class="navbar-toggler-icon"></span>
      </button>
    </div>

    <div class="collapse navbar-collapse" id="topNav">
      <!-- Left menu -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <!-- Dashboard -->
        <li class="nav-item">
          <a class="nav-link<?= $isDash ? ' active' : '' ?>" <?= $isDash ? 'aria-current="page"' : '' ?> href="dashboard_editor.php">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
          </a>
        </li>

        <!-- Përdorues (agjenci + studentë + kartela) -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $usersActive ? ' active' : '' ?>" href="#" id="usersDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-people me-1"></i>Përdorues
          </a>
          <ul class="dropdown-menu" aria-labelledby="usersDropdown">
            <li>
              <a class="dropdown-item<?= $isUsersAg ? ' active' : '' ?>" <?= $isUsersAg ? 'aria-current="page"' : '' ?> href="agencies.php">
                <i class="bi bi-building me-2"></i>Agjencitë
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $isUsersSt ? ' active' : '' ?>" <?= $isUsersSt ? 'aria-current="page"' : '' ?> href="students.php">
                <i class="bi bi-mortarboard me-2"></i>Studentët
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $isStudentCard ? ' active' : '' ?>" <?= $isStudentCard ? 'aria-current="page"' : '' ?> href="student_card.php">
                <i class="bi bi-credit-card-2-front me-2"></i>Kartela studentit
              </a>
            </li>
          </ul>
        </li>

        <!-- Regjistri -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $registerActive ? ' active' : '' ?>" href="#" id="registerDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-journal-text me-1"></i>Regjistri
          </a>
          <ul class="dropdown-menu" aria-labelledby="registerDropdown">
            <li>
              <a class="dropdown-item<?= $isRegFull ? ' active' : '' ?>" <?= $isRegFull ? 'aria-current="page"' : '' ?> href="register.php">
                <i class="bi bi-journal-bookmark me-2"></i>Regjistri i plotë
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $isRegGroups ? ' active' : '' ?>" <?= $isRegGroups ? 'aria-current="page"' : '' ?> href="groups.php">
                <i class="bi bi-people-fill me-2"></i>Regjistri me grupe
              </a>
            </li>
            <li>
              <a class="dropdown-item<?= $isRegStudentsNoGroups ? ' active' : '' ?>" <?= $isRegStudentsNoGroups ? 'aria-current="page"' : '' ?> href="students_without_groups.php">
                <i class="bi bi-person-x me-2"></i>Studentët pa grupe
              </a>
            </li>
          </ul>
        </li>

        <!-- Modulet -->
        <li class="nav-item">
          <a class="nav-link<?= $isCourses ? ' active' : '' ?>" <?= $isCourses ? 'aria-current="page"' : '' ?> href="courses.php">
            <i class="bi bi-book me-1"></i>Modulet
          </a>
        </li>

        <!-- Regjistri i ndryshimeve (vetëm për editorin vetë – faqja logs_editor.php) -->
        <li class="nav-item">
          <a class="nav-link<?= $isLogsEditor ? ' active' : '' ?>" <?= $isLogsEditor ? 'aria-current="page"' : '' ?> href="logs_editor.php">
            <i class="bi bi-journal-text me-1"></i>Regjistri i ndryshimeve
          </a>
        </li>

      </ul>

      <!-- Right side: kërkim + shkurtores + profili -->
      <div class="d-flex align-items-center gap-3">

        <!-- Kërkim i shpejtë për studentë -->
        <form class="d-none d-lg-flex" role="search" method="get" action="students.php">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-transparent text-white-50 border-secondary-subtle">
              <i class="bi bi-search"></i>
            </span>
            <input
              name="q"
              class="form-control bg-dark text-white border-secondary-subtle"
              type="search"
              placeholder="Kërko studentë…"
              aria-label="Kërkim"
            >
          </div>
        </form>

        <div class="vr d-none d-lg-block"></div>

        <!-- Shkurtore: verifikim + faqja publike -->
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

      </div><!-- /Right side -->

    </div><!-- /collapse -->
  </div><!-- /container-fluid -->
</nav>
