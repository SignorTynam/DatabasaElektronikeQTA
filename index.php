<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/navbarMain.php';

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
    <title>QTA – Databaza e Regjistrave të Studentëve</title>
    <link rel="icon" type="image/svg+xml" href="image/logoPNG - Copy.png">
    <!-- Bootstrap CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <!-- Styles -->
    <style>
        :root{
            --g1:#0ea5e9; --g2:#2563eb; --g3:#4f46e5;
            --bg:#f5f7fb; --muted:#667085; --glass:rgba(255,255,255,.85); --glass-b:rgba(255,255,255,.55);
            --shadow:0 18px 40px rgba(2,6,23,.12); --ring:#e6efff;
        }
        html,body{height:100%;}
        body { background:var(--bg); }

        /* Navbar */
        .navbar-brand img{height:30px;}
        .navbar-dark .nav-link.active { font-weight:600; }

        /* Hero */
        .hero {
            position: relative;
            background:
                radial-gradient(1200px 420px at 10% -20%, rgba(37,99,235,.25), rgba(37,99,235,0) 60%),
                radial-gradient(900px 320px at 90% -10%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
                linear-gradient(135deg, var(--g1) 0%, var(--g2) 55%, var(--g3) 100%);
            color:#fff; padding:72px 0 68px;
            overflow:hidden;
        }
        .hero .chip { background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.28); }
        .hero-blob{
            position:absolute; inset:auto -120px -160px auto;
            width:440px; height:440px; border-radius:50%;
            background:radial-gradient(circle at 40% 40%, rgba(255,255,255,.32), transparent 60%);
            filter:blur(30px); opacity:.8;
        }
        .hero-card{
            background:var(black); border:0.5px solid var(--glass-b);
            border-radius:.5rem; box-shadow:var(--shadow);
        }

        /* Cards & sections */
        .card { border:none; border-radius:1rem; box-shadow:var(--shadow); }
        .feature-card{ transition:transform .2s ease; height:100%; }
        .feature-card:hover{ transform:translateY(-6px); }
        .kpi-number{ font-size:2.35rem; font-weight:800; letter-spacing:.5px; color:#1d4ed8; }
        .kpi-tile{ background:#fff; border:1px solid #eef2ff; border-radius:1rem; padding:18px; }
        .kpi-icon{ width:46px; height:46px; border-radius:.75rem; display:flex; align-items:center; justify-content:center; background:#eef2ff; }

        /* Login */
        .role-card .btn{ padding:.65rem 1rem; }
        .role-card .icon{
            width:56px;height:56px;border-radius:1rem;display:flex;align-items:center;justify-content:center;
            background:#f1f5ff;color:#3355ff;
        }

        /* Admin shortcuts */
        .admin-quick .mini-card { transition:.2s ease; border:1px solid #eef2ff; }
        .admin-quick .mini-card:hover{ transform:translateY(-4px); }

        /* Footer */
        footer{ background:#0b1220; color:#e5e7eb; }
        footer a{ color:#e5e7eb; }

        /* Utilities */
        .small-muted{ color:var(--muted); }
        .ring { box-shadow:0 0 0 8px var(--ring); border-radius:12px; }
        @media (max-width: 991px){
            .hero{ padding:56px 0 54px; }
        }
    </style>
</head>
<body>

    <!-- Hero -->
    <section class="hero">
        <div class="hero-blob"></div>
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-lg-6">
                    <span class="badge chip rounded-pill mb-2">Databaza elektronike e kurseve profesionale</span>
                    <h1 class="display-5 fw-bold mb-3">Menaxhim i centralizuar i studentëve dhe certifikatave</h1>
                    <p class="lead mb-4">QTA ofron një platformë moderne për regjistrim, ndjekje, raporte — dhe verifikim publik të certifikatave me QR.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="selectProfile.php" class="btn btn-light text-primary fw-semibold">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Hyr në sistem
                        </a>
                        <a href="verify.php" class="btn btn-outline-light">
                            <i class="bi bi-qr-code-scan me-1"></i> Verifiko certifikatën
                        </a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="hero-card p-3 p-md-4 ring">
                        <div class="d-flex align-items-center mb-3">
                            <div class="kpi-icon me-3"><i class="bi bi-shield-check text-primary fs-5"></i></div>
                            <div>
                                <div class="fw-semibold">Verifikim i shpejtë dhe i sigurt</div>
                                <div class="small text-white-50">Skanoni QR dhe shihni të dhënat bazike të kursantit në sekonda.</div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="kpi-tile">
                                    <div class="small text-muted">Studentë</div>
                                    <div class="kpi-number" data-kpi="<?= (int)$counts['students'] ?>">0</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-tile">
                                    <div class="small text-muted">Agjenci</div>
                                    <div class="kpi-number" data-kpi="<?= (int)$counts['agencies'] ?>">0</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-tile">
                                    <div class="small text-muted">Administratorë</div>
                                    <div class="kpi-number" data-kpi="<?= (int)$counts['admins'] ?>">0</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-tile">
                                    <div class="small text-muted">Përdorues</div>
                                    <div class="kpi-number" data-kpi="<?= (int)$counts['users'] ?>">0</div>
                                </div>
                            </div>
                        </div>
                        <div class="small text-white-50 mt-3"><i class="bi bi-info-circle me-1"></i>Numrat përditësohen automatikisht nga databaza.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center fw-bold mb-4">Çfarë përfitoni me QTA</h2>
            <p class="text-center small-muted mb-5">Proces i thjeshtuar, transparencë dhe siguri për të gjithë aktorët.</p>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card feature-card h-100">
                        <div class="card-body text-center p-4">
                            <div class="kpi-icon mx-auto mb-3"><i class="bi bi-people fs-4 text-primary"></i></div>
                            <h5 class="card-title">Menaxhim i studentëve</h5>
                            <p class="card-text small-muted">Regjistrim i saktë, azhornime të shpejta dhe historik i plotë akademik.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card feature-card h-100">
                        <div class="card-body text-center p-4">
                            <div class="kpi-icon mx-auto mb-3" style="background:#ecfdf5;"><i class="bi bi-graph-up-arrow fs-4 text-success"></i></div>
                            <h5 class="card-title">Raporte & statistika</h5>
                            <p class="card-text small-muted">Analiza të qarta mbi ecurinë dhe performancën, gati për eksportim.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card feature-card h-100">
                        <div class="card-body text-center p-4">
                            <div class="kpi-icon mx-auto mb-3" style="background:#fff1f2;"><i class="bi bi-shield-lock fs-4 text-danger"></i></div>
                            <h5 class="card-title">Verifikim me QR</h5>
                            <p class="card-text small-muted">Çdo certifikatë lidhet me një token unik — identifikon shpejt çdo abuzim.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Steps -->
            <div class="row align-items-center g-4 mt-5">
                <div class="col-lg-6">
                    <img src="image/logoPNG2.png" class="img-fluid" alt="QTA" style="max-width:360px; opacity:.9;">
                </div>
                <div class="col-lg-6">
                    <h4 class="fw-bold mb-3">Si funksionon verifikimi?</h4>
                    <ol class="small-muted ps-3">
                        <li>Skanoni kodin QR në certifikatë ose ngarkoni një foto të tij.</li>
                        <li>Sistemi krahason <em>tokenin unik</em> me databazën.</li>
                        <li>Shfaqen të dhënat bazike: emër, mbiemër, modulet kryesore…</li>
                        <li>Nëse të dhënat <u>nuk</u> përputhen me certifikatën fizike, lajmëroni menjëherë QTA.</li>
                    </ol>
                    <a href="verify.php" class="btn btn-primary mt-2"><i class="bi bi-qr-code-scan me-1"></i> Provo verifikimin</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Login options -->
    <section class="py-5 bg-white">
        <div class="container">
            <h2 class="text-center fw-bold mb-4">Hyr në sistem</h2>
            <p class="text-center small-muted mb-5">Zgjidhni profilin sipas rolit tuaj.</p>
            <div class="row justify-content-center g-4">
                <div class="col-md-4">
                    <div class="card role-card text-center h-100">
                        <div class="card-body p-4">
                            <div class="icon mx-auto mb-3"><i class="bi bi-person-gear fs-4"></i></div>
                            <h5 class="card-title">Administrator</h5>
                            <p class="card-text small-muted">Qasje e plotë në të gjitha funksionet e sistemit.</p>
                            <a href="selectProfile.php" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr si administrator</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card role-card text-center h-100">
                        <div class="card-body p-4">
                            <div class="icon mx-auto mb-3" style="background:#ecfdf5;color:#12b981;"><i class="bi bi-building fs-4"></i></div>
                            <h5 class="card-title">Agjencia</h5>
                            <p class="card-text small-muted">Menaxhoni studentët që dërgohen nga ju.</p>
                            <a href="selectProfile.php" class="btn btn-success w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr si agjenci</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card role-card text-center h-100">
                        <div class="card-body p-4">
                            <div class="icon mx-auto mb-3" style="background:#f0f9ff;color:#0ea5e9;"><i class="bi bi-mortarboard fs-4"></i></div>
                            <h5 class="card-title">Student</h5>
                            <p class="card-text small-muted">Qasje në të dhënat personale dhe performancën.</p>
                            <a href="selectProfile.php" class="btn btn-info w-100 text-white"><i class="bi bi-box-arrow-in-right me-1"></i> Hyr si student</a>
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
                                            <div class="card text-center mini-card">
                                                <div class="card-body">
                                                    <i class="bi bi-speedometer2 fs-2 text-primary"></i>
                                                    <div class="mt-2">Dashboard</div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a class="text-decoration-none" href="users.php">
                                            <div class="card text-center mini-card">
                                                <div class="card-body">
                                                    <i class="bi bi-people fs-2 text-primary"></i>
                                                    <div class="mt-2">Administratorët</div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a class="text-decoration-none" href="agencies.php">
                                            <div class="card text-center mini-card">
                                                <div class="card-body">
                                                    <i class="bi bi-building fs-2 text-primary"></i>
                                                    <div class="mt-2">Agjencitë</div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a class="text-decoration-none" href="students.php">
                                            <div class="card text-center mini-card">
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

    <!-- JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // KPI animation using IntersectionObserver for smoothness
        const els = document.querySelectorAll('[data-kpi]');
        const animate = (el) => {
            const end = parseInt(el.getAttribute('data-kpi')||'0',10);
            const dur = 900, start = 0;
            const t0 = performance.now();
            const step = (t) => {
                const p = Math.min(1, (t - t0) / dur);
                const val = Math.floor(start + (end - start) * (p * (2 - p))); // easeOutQuad
                el.textContent = val.toLocaleString('sq-AL');
                if (p < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        };
        const io = new IntersectionObserver((entries, obs)=>{
            entries.forEach(e=>{
                if(e.isIntersecting){ animate(e.target); obs.unobserve(e.target); }
            });
        }, {threshold:.4});
        els.forEach(el=>io.observe(el));
    </script>

    <?php require_once __DIR__ . '/footer.php'; ?>

</body>
</html>
