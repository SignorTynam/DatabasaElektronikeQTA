<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* Nëse je i loguar, lexo përdoruesin aktual */
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email, r.name AS role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    $currentUser = $stmt->fetch() ?: null;
}

/* Statistika dinamike */
$counts = [
    'students' => 0,
    'agencies' => 0,
    'admins'   => 0,
    'users'    => 0,
];

$counts['students'] = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$counts['agencies'] = (int)$pdo->query("SELECT COUNT(*) FROM agencies")->fetchColumn();
$counts['users']    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

$adminCountStmt = $pdo->query("
    SELECT COUNT(*) 
    FROM users u 
    JOIN roles r ON r.id = u.role_id 
    WHERE r.name = 'administrator'
");
$counts['admins'] = (int)$adminCountStmt->fetchColumn();

/* (Opsionale) lista e fundit – shfaqet vetëm për admin që është loguar */
$latestStudents = [];
$latestAgencies = [];
if ($currentUser && $currentUser['role_name'] === 'administrator') {
    $q = $pdo->query("
        SELECT s.nr_amze, s.first_name, s.last_name, u.created_at
        FROM students s
        JOIN users u ON u.id = s.user_id
        ORDER BY u.created_at DESC
        LIMIT 5
    ");
    $latestStudents = $q->fetchAll();

    $q = $pdo->query("
        SELECT a.company_name, a.nip_t, u.created_at
        FROM agencies a
        JOIN users u ON u.id = a.user_id
        ORDER BY u.created_at DESC
        LIMIT 5
    ");
    $latestAgencies = $q->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Databaza e Regjistrave të Studentëve</title>
    <link rel="icon" type="image/svg+xml" href="image/logoPNG - Copy.png">
    <!-- Bootstrap CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css">
    <!-- Custom CSS -->
    <style>
        .hero-section { background-color: #f8f9fa; padding: 60px 0; }
        .feature-card { transition: transform 0.3s; height: 100%; }
        .feature-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .stat-number { font-size: 2.5rem; font-weight: bold; color: #0d6efd; }
        .admin-quick .card { transition: .2s ease; }
        .admin-quick .card:hover { transform: translateY(-4px); }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <img src="image/logoPNG2.png" alt="Logo" height="30" class="d-inline-block align-text-top me-2">
                Qendra e Trajnimeve të Avancuara (QTA)
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item"><a class="nav-link active" href="index.php">Kryefaqja</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">Rreth nesh</a></li>
                    <li class="nav-item"><a class="nav-link" href="contact.html">Kontakt</a></li>
                    <?php if ($currentUser): ?>
                        <?php if ($currentUser['role_name'] === 'administrator'): ?>
                            <li class="nav-item me-2"><a class="btn btn-outline-light btn-sm" href="dashboard_admin.php"><i class="bi bi-speedometer2 me-1"></i>Paneli</a></li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <span class="text-white-50 small me-2 d-none d-sm-inline">
                                <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Përdorues')) ?>
                            </span>
                            <a class="btn btn-primary ms-2" href="logout.php" role="button">Dil</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="btn btn-primary ms-2" href="selectProfile.php" role="button">Hyr</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-md-6">
                    <h1 class="display-4 fw-bold">Databaza elektronike e kurseve profesionale</h1>
                    <p class="lead">Sistemi i centralizuar për menaxhimin e të dhënave të studentëve në Qendrën e Trajnimeve të Avancuara.</p>
                    <a href="selectProfile.php" class="btn btn-primary btn-lg me-2">Hyr në sistem</a>
                    <a href="#" class="btn btn-outline-secondary btn-lg">Mëso më shumë</a>
                </div>
                <div class="col-md-6">
                    <!-- Ilustrim i thjeshtë SVG inline -->
                    <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI1MDAiIGhlaWdodD0iNTAwIiB2aWV3Qm94PSIwIDAgNTAwIDUwMCI+PHJlY3QgeD0iMTAwIiB5PSIxMDAiIHdpZHRoPSIzMDAiIGhlaWdodD0iMzAwIiBmaWxsPSIjZTdlZmYzIiBzdHJva2U9IiMwZDZlZmQiIHN0cm9rZS13aWR0aD0iMiIvPjxjaXJjbGUgY3g9IjE1MCIgY3k9IjE1MCIgcj0iMjAiIGZpbGw9IiMwZDZlZmQiLz48Y2lyY2xlIGN4PSIyNTAiIGN5PSIxNTAiIHI9IjIwIiBmaWxsPSIjNmZjYWU2Ii8+PGNpcmNsZSBjeD0iMzUwIiBjeT0iMTUwIiByPSIyMCIgZmlsbD0iI2Y0NzQ1NiIvPjxsaW5lIHgxPSIxNTAiIHkxPSIyMDAiIHgyPSIzNTAiIHkyPSIyMDAiIHN0cm9rZT0iIzZkN2U4ZCIgc3Ryb2tlLXdpZHRoPSIyIi8+PGxpbmUgeDE9IjE1MCIgeTE9IjI1MCIgeDI9IjM1MCIgeTI9IjI1MCIgc3Ryb2tlPSIjNmQ3ZThkIiBzdHJva2Utd2lkdGg9IjIiLz48bGluZSB4MT0iMTUwIiB5MT0iMzAwIiB4Mj0iMzUwIiB5Mj0iMzAwIiBzdHJva2U9IiM2ZDdlOGQiIHN0cm9rZS13aWR0aD0iMiIvPjxsaW5lIHgxPSIxNTAiIHkxPSIzNTAiIHgyPSIzNTAiIHkyPSIzNTAiIHN0cm9rZT0iIzZkN2U4ZCIgc3Ryb2tlLXdpZHRoPSIyIi8+PC9zdmc+" class="img-fluid" alt="Student Database Illustration">
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center mb-5">Karakteristikat kryesore</h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card feature-card">
                        <div class="card-body text-center">
                            <i class="bi bi-people display-6 text-primary mb-3"></i>
                            <h5 class="card-title">Menaxhimi i studentëve</h5>
                            <p class="card-text">Regjistroni, modifikoni dhe menaxhoni të dhënat e studentëve në mënyrë efikase.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card feature-card">
                        <div class="card-body text-center">
                            <i class="bi bi-graph-up display-6 text-primary mb-3"></i>
                            <h5 class="card-title">Raporte dhe statistika</h5>
                            <p class="card-text">Gjeneroni raporte të detajuara dhe statistika për performancën e studentëve.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card feature-card">
                        <div class="card-body text-center">
                            <i class="bi bi-chat-dots display-6 text-primary mb-3"></i>
                            <h5 class="card-title">Komunikim i lehtë</h5>
                            <p class="card-text">Komunikoni me studentët dhe stafin nëpërmjet sistemit të integruar.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section (dinamike) -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row text-center g-4">
                <div class="col-md-3">
                    <p class="stat-number" id="studentCount" data-target="<?= (int)$counts['students'] ?>">0</p>
                    <p>Studentë të regjistruar</p>
                </div>
                <div class="col-md-3">
                    <p class="stat-number" id="agencyCount" data-target="<?= (int)$counts['agencies'] ?>">0</p>
                    <p>Agjenci</p>
                </div>
                <div class="col-md-3">
                    <p class="stat-number" id="adminCount" data-target="<?= (int)$counts['admins'] ?>">0</p>
                    <p>Administratorë</p>
                </div>
                <div class="col-md-3">
                    <p class="stat-number" id="usersCount" data-target="<?= (int)$counts['users'] ?>">0</p>
                    <p>Përdorues gjithsej</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Login Options -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center mb-5">Hyr në sistem</h2>
            <div class="row justify-content-center g-4">
                <div class="col-md-4">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="bi bi-person-gear display-6 text-primary mb-3"></i>
                            <h5 class="card-title">Administrator</h5>
                            <p class="card-text">Qasje e plotë në të gjitha funksionet e sistemit.</p>
                            <a href="selectProfile.php" class="btn btn-primary">Hyr si administrator</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="bi bi-building display-6 text-primary mb-3"></i>
                            <h5 class="card-title">Agjenci</h5>
                            <p class="card-text">Menaxhoni studentët që dërgohen nga ju.</p>
                            <a href="selectProfile.php" class="btn btn-primary">Hyr si agjenci</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="bi bi-mortarboard display-6 text-primary mb-3"></i>
                            <h5 class="card-title">Student</h5>
                            <p class="card-text">Qasje në të dhënat personale dhe performancën.</p>
                            <a href="selectProfile.php" class="btn btn-primary">Hyr si student</a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($currentUser && $currentUser['role_name'] === 'administrator'): ?>
            <!-- Shkurtore Admin + Kërkim i shpejtë -->
            <div class="mt-5 admin-quick">
                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="card h-100">
                            <div class="card-header bg-white">
                                <strong><i class="bi bi-lightning-charge me-1"></i>Shkurtore administratori</strong>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <a class="text-decoration-none" href="dashboard_admin.php">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <i class="bi bi-speedometer2 fs-2 text-primary"></i>
                                                    <div class="mt-2">Dashboard</div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a class="text-decoration-none" href="users.php">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <i class="bi bi-people fs-2 text-primary"></i>
                                                    <div class="mt-2">Administratorët</div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a class="text-decoration-none" href="agencies.php">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <i class="bi bi-building fs-2 text-primary"></i>
                                                    <div class="mt-2">Agjencitë</div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a class="text-decoration-none" href="students.php">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <i class="bi bi-mortarboard fs-2 text-primary"></i>
                                                    <div class="mt-2">Studentët</div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                </div>

                                <hr class="my-4">
                                <form class="row g-2" method="get" action="students.php">
                                    <div class="col-12 col-md-8">
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                                            <input type="text" name="q" class="form-control border-0" placeholder="Kërko student (emër, nr. amze, nr. personal, vendlindje...)">
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4 d-grid">
                                        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Kërko në studentë</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Të fundit (vetëm për admin që janë loguar) -->
                    <div class="col-lg-4">
                        <div class="card mb-4">
                            <div class="card-header bg-white">
                                <strong><i class="bi bi-clock-history me-1"></i>Studentët e fundit</strong>
                            </div>
                            <ul class="list-group list-group-flush">
                                <?php if ($latestStudents): foreach ($latestStudents as $s): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></span>
                                        <span class="badge text-bg-primary">Amza: <?= htmlspecialchars($s['nr_amze']) ?></span>
                                    </li>
                                <?php endforeach; else: ?>
                                    <li class="list-group-item text-muted">S’ka të dhëna.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="card">
                            <div class="card-header bg-white">
                                <strong><i class="bi bi-clock-history me-1"></i>Agjencitë e fundit</strong>
                            </div>
                            <ul class="list-group list-group-flush">
                                <?php if ($latestAgencies): foreach ($latestAgencies as $a): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span><?= htmlspecialchars($a['company_name'] ?: '—') ?></span>
                                        <span class="badge text-bg-success"><?= htmlspecialchars($a['nip_t']) ?></span>
                                    </li>
                                <?php endforeach; else: ?>
                                    <li class="list-group-item text-muted">S’ka të dhëna.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-white py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>Qendra e Trajnimeve të Avancuara</h5>
                    <p>Ofrimi i edukimit me cilësi të lartë për profesionistët e të nesërmes.</p>
                    <div class="d-flex">
                        <a href="#" class="text-white me-3"><i class="bi bi-facebook"></i></a>
                        <a href="#" class="text-white me-3"><i class="bi bi-twitter"></i></a>
                        <a href="#" class="text-white me-3"><i class="bi bi-linkedin"></i></a>
                        <a href="#" class="text-white"><i class="bi bi-instagram"></i></a>
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5>Lidhje të shpejta</h5>
                    <ul class="list-unstyled">
                        <li><a href="index.php" class="text-white text-decoration-none">Kryefaqja</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Rreth Nesh</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Kurset</a></li>
                        <li><a href="contact.html" class="text-white text-decoration-none">Kontakt</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5>Na gjeni këtu</h5>
                    <p><i class="bi bi-geo-alt me-2"></i> Rruga Bilal Konxholli, Tiranë</p>
                    <p><i class="bi bi-telephone me-2"></i> +355 69 877 8837</p>
                    <p><i class="bi bi-envelope me-2"></i> officialqta@gmail.com</p>
                </div>
            </div>
            <hr class="my-4 bg-light">
            <p class="text-center mb-0">&copy; <?= date('Y') ?> Qendra e Trajnimeve të Avancuara. Të gjitha të drejtat e rezervuara.</p>
        </div>
    </footer>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <script>
        // Animim i numrave në statistikat – lexon target nga data-target
        function animateValue(el, end, duration) {
            let start = 0;
            const range = end - start;
            const stepTime = range > 0 ? Math.max(Math.floor(duration / range), 10) : 10;
            const timer = setInterval(function() {
                start += 1;
                el.textContent = start.toLocaleString('sq-AL');
                if (start >= end) clearInterval(timer);
            }, stepTime);
        }

        function isInViewport(element) {
            const rect = element.getBoundingClientRect();
            return rect.top < (window.innerHeight || document.documentElement.clientHeight) && rect.bottom >= 0;
        }

        let statsAnimated = false;
        function tryAnimateStats() {
            const statsSection = document.querySelector('.bg-light');
            if (!statsAnimated && statsSection && isInViewport(statsSection)) {
                document.querySelectorAll('.stat-number').forEach(el => {
                    const target = parseInt(el.getAttribute('data-target') || '0', 10);
                    animateValue(el, target, 1200);
                });
                statsAnimated = true;
            }
        }
        window.addEventListener('scroll', tryAnimateStats);
        window.addEventListener('load', tryAnimateStats);
    </script>
</body>
</html>
