<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* 1) Verifiko login & rolin administrator */
if (!isset($_SESSION['user_id'])) {
    header('Location: selectProfile.php'); exit;
}
$userStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid
    LIMIT 1
");
$userStmt->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $userStmt->fetch();
if (!$currentUser || $currentUser['role_name'] !== 'administrator') {
    header('Location: selectProfile.php'); exit;
}

/* 2) Statistikat kryesore */
function tableCount(PDO $pdo, string $table): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}
$stats = [
    'students' => tableCount($pdo, 'students'),
    'agencies' => tableCount($pdo, 'agencies'),
    'admins'   => tableCount($pdo, 'admins'),
    'users'    => tableCount($pdo, 'users'),
];

/* 3) Aktivitetet e fundit */
$recentStmt = $pdo->query("
    SELECT u.full_name, u.email, u.created_at, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    ORDER BY u.created_at DESC
    LIMIT 8
");
$recentUsers = $recentStmt->fetchAll();

/* 4) Agjencitë e fundit */
$agenciesStmt = $pdo->query("
    SELECT a.company_name, a.nip_t, a.phone
    FROM agencies a
    JOIN users u ON u.id = a.user_id
    ORDER BY u.created_at DESC
    LIMIT 5
");
$recentAgencies = $agenciesStmt->fetchAll();

/* 5) Studentët e fundit */
$studentsStmt = $pdo->query("
    SELECT s.personal_number, s.phone, u.full_name
    FROM students s
    JOIN users u ON u.id = s.user_id
    ORDER BY u.created_at DESC
    LIMIT 5
");
$recentStudents = $studentsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8" />
    <title>Dashboard Administrator - QTA</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
    <style>
        body { background:#f5f7fb; padding-top:72px; } /* hapësirë për navbar-in fixed-top */
        .navbar-brand img { height:28px; }
        .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
        .stat-hero {
            background: radial-gradient(1200px 400px at 10% -20%, rgba(37,99,235,.25), rgba(37,99,235,0) 60%),
                        radial-gradient(800px 300px at 90% -10%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
                        linear-gradient(135deg, #0ea5e9 0%, #2563eb 55%, #4f46e5 100%);
            color:#fff; border-radius:1.25rem; overflow:hidden;
        }
        .stat-hero .badge { background:rgba(255,255,255,.2); }
        .mini-table thead { background:#f1f5f9; }
        .kpi-icon {
            width:46px; height:46px; border-radius:.75rem; display:flex; align-items:center; justify-content:center;
            background:#eef2ff;
        }
        @media (max-width: 575.98px) {
            .navbar-text { display:none; }
        }
    </style>
</head>
<body>

<!-- NAVBAR (pa sidebar, me dropdown "Përdorues") -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="dashboard_admin.php">
            <img src="image/logoPNG2.png" class="me-2" alt="QTA"> QTA – Paneli i Administratorit
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="topNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <!-- Dashboard (aktiv) -->
                <li class="nav-item">
                    <a class="nav-link active" aria-current="page" href="dashboard_admin.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboardi
                    </a>
                </li>

                <!-- Dropdown: Përdorues -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="usersDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-people me-1"></i>Përdorues
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="usersDropdown">
                        <li><a class="dropdown-item" href="users.php"><i class="bi bi-shield-lock me-2"></i>Administratorët</a></li>
                        <li><a class="dropdown-item" href="agencies.php"><i class="bi bi-building me-2"></i>Agjencitë</a></li>
                        <li><a class="dropdown-item" href="students.php"><i class="bi bi-mortarboard me-2"></i>Studentët</a></li>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="registerDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-file-earmark-text me-1"></i>Regjistri
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="registerDropdown">
                        <li><a class="dropdown-item" href="register.php"><i class="bi bi-file-earmark-text me-2"></i>Regjistri i plotë</a></li>
                    </ul>
                </li>


                <li class="nav-item">
                    <a class="nav-link" aria-current="page" href="courses.php">
                        <i class="bi bi-book me-1"></i>Modulet
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <span class="text-white-50 small navbar-text">Mirësevjen,</span>
                <span class="text-white fw-semibold navbar-text">
                    <i class="bi bi-person-circle me-1"></i>
                    <?= htmlspecialchars($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Administrator')) ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm ms-1">
                    <i class="bi bi-box-arrow-right me-1"></i>Dil
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4">

    <!-- Hero / Overview -->
    <div class="stat-hero p-4 p-md-5 mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge rounded-pill">Administrator</span>
                    <span class="small opacity-75">QTA • Qendra e Trajnimeve të Avancuara</span>
                </div>
                <h1 class="display-6 fw-bold mb-2">Përmbledhje e sistemit</h1>
                <p class="mb-0">Monitoroni në kohë reale përdoruesit, agjencitë dhe studentët. Shfletoni aktivitetet e fundit dhe mbani kontrollin e platformës.</p>
            </div>
            <div class="col-lg-4 mt-4 mt-lg-0">
                <div class="card text-dark">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="kpi-icon me-3"><i class="bi bi-people-fill fs-4"></i></div>
                            <div>
                                <div class="text-uppercase small text-muted">Total Përdorues</div>
                                <div class="h3 mb-0"><?= number_format($stats['users']) ?></div>
                            </div>
                        </div>
                        <div class="progress mt-3" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $stats['users'] ? 100 : 0 ?>">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <div class="small text-muted mt-2">Aktiv deri tani</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card p-3 h-100">
                <div class="d-flex align-items-center">
                    <div class="kpi-icon me-3" style="background:#eff6ff;"><i class="bi bi-mortarboard fs-4 text-primary"></i></div>
                    <div>
                        <div class="small text-muted text-uppercase">Studentë</div>
                        <div class="h3 mb-0"><?= number_format($stats['students']) ?></div>
                    </div>
                </div>
                <div class="small mt-2 text-muted">Të regjistruar në sistem</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card p-3 h-100">
                <div class="d-flex align-items-center">
                    <div class="kpi-icon me-3" style="background:#ecfdf5;"><i class="bi bi-building fs-4 text-success"></i></div>
                    <div>
                        <div class="small text-muted text-uppercase">Agjenci</div>
                        <div class="h3 mb-0"><?= number_format($stats['agencies']) ?></div>
                    </div>
                </div>
                <div class="small mt-2 text-muted">Partnerë aktivë</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card p-3 h-100">
                <div class="d-flex align-items-center">
                    <div class="kpi-icon me-3" style="background:#fff1f2;"><i class="bi bi-shield-lock fs-4 text-danger"></i></div>
                    <div>
                        <div class="small text-muted text-uppercase">Administratorë</div>
                        <div class="h3 mb-0"><?= number_format($stats['admins']) ?></div>
                    </div>
                </div>
                <div class="small mt-2 text-muted">Staf administrativ</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card p-3 h-100">
                <div class="d-flex align-items-center">
                    <div class="kpi-icon me-3" style="background:#eef2ff;"><i class="bi bi-people fs-4 text-primary"></i></div>
                    <div>
                        <div class="small text-muted text-uppercase">Përdorues</div>
                        <div class="h3 mb-0"><?= number_format($stats['users']) ?></div>
                    </div>
                </div>
                <div class="small mt-2 text-muted">Totali në platformë</div>
            </div>
        </div>
    </div>

    <!-- Activity & Lists -->
    <div class="row g-4">
        <!-- Aktivitetet e fundit -->
        <div class="col-12 col-xl-7">
            <div class="card">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Aktivitetet e fundit</h5>
                    <div class="input-group input-group-sm" style="max-width: 240px;">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                        <input type="text" id="filterActivities" class="form-control border-0" placeholder="Kërko...">
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive mini-table">
                        <table class="table align-middle" id="activitiesTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Emri / Email</th>
                                    <th>Roli</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($recentUsers): ?>
                                <?php foreach ($recentUsers as $ru): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($ru['created_at']) ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($ru['full_name'] ?: '-') ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars($ru['email'] ?: '-') ?></div>
                                        </td>
                                        <td>
                                            <span class="badge rounded-pill text-bg-<?= 
                                                $ru['role_name']==='administrator' ? 'danger' :
                                                ($ru['role_name']==='agjencia' ? 'success' : 'primary') ?>">
                                                <?= htmlspecialchars(ucfirst($ru['role_name'])) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center text-muted">Nuk ka të dhëna.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white text-end">
                    <a href="#" class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1"></i>Eksporto CSV</a>
                </div>
            </div>
        </div>

        <!-- Agjencitë & Studentët e fundit -->
        <div class="col-12 col-xl-5">
            <div class="card mb-4">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-building me-2"></i>Agjencitë e fundit</h6>
                </div>
                <div class="card-body">
                    <?php if ($recentAgencies): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($recentAgencies as $a): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($a['company_name'] ?: '—') ?></div>
                                        <div class="small text-muted">NIPT: <?= htmlspecialchars($a['nip_t']) ?></div>
                                    </div>
                                    <span class="small text-muted"><?= htmlspecialchars($a['phone'] ?: '') ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted mb-0">Nuk ka të dhëna.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Studentët e fundit</h6>
                </div>
                <div class="card-body">
                    <?php if ($recentStudents): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($recentStudents as $s): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($s['full_name'] ?: '—') ?></div>
                                        <div class="small text-muted">Numri personal: <?= htmlspecialchars($s['personal_number']) ?></div>
                                    </div>
                                    <span class="small text-muted"><?= htmlspecialchars($s['phone'] ?: '') ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted mb-0">Nuk ka të dhëna.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer mini -->
    <div class="text-center text-muted small mt-4">
        &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
    </div>
</main>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Kërkim i shpejtë në “Aktivitetet e fundit”
    const filterInput = document.getElementById('filterActivities');
    const table = document.getElementById('activitiesTable');
    filterInput?.addEventListener('input', () => {
        const q = filterInput.value.toLowerCase();
        for (const row of table.tBodies[0].rows) {
            row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
        }
    });
</script>
</body>
</html>
