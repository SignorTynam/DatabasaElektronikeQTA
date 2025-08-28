<?php
declare(strict_types=1);

/**
 * Përfshije këtë skedar PAS autentikimit dhe pasi të kesh $currentUser.
 * Nëse nuk e ke të inicializuar $currentUser, ky skedar do të provojë ta lexojë vetë.
 */

// Helper i sigurt për HTML (toleron NULL)
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// Nëse nuk ke $currentUser nga faqja thirrëse, provo ta ngarkosh (opsionale)
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

// Cakto faqen aktive: ose merre nga $NAV_ACTIVE, ose auto nga emri i skriptit
$active = $NAV_ACTIVE ?? null;
if ($active === null) {
    $script = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
    $map = [
        'dashboard_admin.php' => 'dashboard',
        'users.php'           => 'users_admins',
        'agencies.php'        => 'users_agencies',
        'students.php'        => 'users_students',
        'register.php'        => 'register_full',
        'groups.php'          => 'register_groups',
        'courses.php'         => 'courses',
    ];
    $active = $map[$script] ?? '';
}

$usersActive    = in_array($active, ['users_admins','users_agencies','users_students'], true);
$registerActive = in_array($active, ['register_full','register_groups'], true);

$dashIsActive    = $active === 'dashboard';
$usersAdminsAct  = $active === 'users_admins';
$usersAgenciesAct= $active === 'users_agencies';
$usersStudentsAct= $active === 'users_students';
$regFullAct      = $active === 'register_full';
$regGroupsAct    = $active === 'register_groups';
$coursesAct      = $active === 'courses';

// Emri në navbar
$who = $currentUser['full_name'] ?: ($currentUser['email'] ?? 'Administrator');
?>
<!-- NAVBAR (pa sidebar, me dropdown "Përdorues") -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="dashboard_admin.php">
            <img src="image/logoPNG2.png" class="me-2" alt="QTA"> QTA – Paneli i Administratorit
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav" aria-controls="topNav" aria-expanded="false" aria-label="Shfaq navigimin">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="topNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link<?= $dashIsActive ? ' active' : '' ?>" <?= $dashIsActive ? 'aria-current="page"' : '' ?> href="dashboard_admin.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboardi
                    </a>
                </li>

                <!-- Dropdown: Përdorues -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= $usersActive ? ' active' : '' ?>" href="#" id="usersDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-people me-1"></i>Përdorues
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="usersDropdown">
                        <li><a class="dropdown-item<?= $usersAdminsAct ? ' active' : '' ?>" <?= $usersAdminsAct ? 'aria-current="page"' : '' ?> href="users.php">
                            <i class="bi bi-shield-lock me-2"></i>Administratorët</a></li>
                        <li><a class="dropdown-item<?= $usersAgenciesAct ? ' active' : '' ?>" <?= $usersAgenciesAct ? 'aria-current="page"' : '' ?> href="agencies.php">
                            <i class="bi bi-building me-2"></i>Agjencitë</a></li>
                        <li><a class="dropdown-item<?= $usersStudentsAct ? ' active' : '' ?>" <?= $usersStudentsAct ? 'aria-current="page"' : '' ?> href="students.php">
                            <i class="bi bi-mortarboard me-2"></i>Studentët</a></li>
                    </ul>
                </li>

                <!-- Dropdown: Regjistri -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= $registerActive ? ' active' : '' ?>" href="#" id="registerDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-journal-text me-1"></i>Regjistri
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="registerDropdown">
                        <li><a class="dropdown-item<?= $regFullAct ? ' active' : '' ?>" <?= $regFullAct ? 'aria-current="page"' : '' ?> href="register.php">
                            <i class="bi bi-journal-bookmark me-2"></i>Regjistri i plotë</a></li>
                        <li><a class="dropdown-item<?= $regGroupsAct ? ' active' : '' ?>" <?= $regGroupsAct ? 'aria-current="page"' : '' ?> href="groups.php">
                            <i class="bi bi-people-fill me-2"></i>Regjistri me grupe</a></li>
                    </ul>
                </li>

                <!-- Modulet -->
                <li class="nav-item">
                    <a class="nav-link<?= $coursesAct ? ' active' : '' ?>" <?= $coursesAct ? 'aria-current="page"' : '' ?> href="courses.php">
                        <i class="bi bi-book me-1"></i>Modulet
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <span class="text-white-50 small navbar-text">Mirësevjen,</span>
                <span class="text-white fw-semibold navbar-text">
                    <i class="bi bi-person-circle me-1"></i><?= h($who) ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm ms-1">
                    <i class="bi bi-box-arrow-right me-1"></i>Dil
                </a>
            </div>
        </div>
    </div>
</nav>
