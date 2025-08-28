<?php
/**
 * inc/navbar2.php — Navbar për rolin "agjencia"
 * Variabla të disponueshme nga faqja prind:
 *   - $NAV_ACTIVE  : string  (p.sh. 'dashboard', 'students', ...)
 *   - $currentUser : array   (id, full_name, email, role_name)
 *   - $AGENCY      : array   (id, company_name, nip_t, ...)
 *   - funksioni h(): escape HTML
 */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}
$active = fn(string $k) => isset($NAV_ACTIVE) && $NAV_ACTIVE === $k ? 'active' : '';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top shadow-sm">
  <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center" href="dashboard_admin.php">
            <img src="image/logoPNG2.png" class="me-2" alt="QTA"> QTA – Paneli i Administratorit
        </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navAgency"
            aria-controls="navAgency" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navAgency">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link <?= $active('dashboard') ?>" href="dashboard_agjencia.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= $active('students') ?>"  href="students.php?scope=mine"><i class="bi bi-people me-1"></i>Studentët e mi</a></li>
        <li class="nav-item"><a class="nav-link <?= $active('groups') ?>"    href="course_groups.php?scope=mine"><i class="bi bi-collection me-1"></i>Grupet</a></li>
        <li class="nav-item"><a class="nav-link <?= $active('courses') ?>"   href="courses.php"><i class="bi bi-journal-text me-1"></i>Modulet</a></li>
        <li class="nav-item"><a class="nav-link <?= $active('exams') ?>"     href="exams.php?scope=mine"><i class="bi bi-calendar2-event me-1"></i>Provimet</a></li>
        <li class="nav-item"><a class="nav-link <?= $active('reports') ?>"   href="reports.php?scope=agency"><i class="bi bi-bar-chart-line me-1"></i>Raporte</a></li>
      </ul>

      <div class="d-flex align-items-center gap-3">
        <div class="navbar-text text-white-50 d-none d-lg-block">
          <small>
            <i class="bi bi-buildings me-1"></i><?= h($AGENCY['company_name'] ?? 'Agjenci') ?>
            <span class="mx-1">•</span>
            <i class="bi bi-envelope-at me-1"></i><?= h($currentUser['email'] ?? '') ?>
          </small>
        </div>

        <div class="dropdown">
          <button class="btn btn-outline-light btn-sm dropdown-toggle rounded-pill" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-badge me-1"></i><?= h($currentUser['full_name'] ?: 'Profili') ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item <?= $active('profile') ?>" href="profile.php"><i class="bi bi-person-gear me-2"></i>Profili</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Dil</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</nav>
