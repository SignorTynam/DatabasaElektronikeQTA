<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ===== Guard admin ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }

$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id
  LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || ($currentUser['role_name'] ?? '') !== 'administrator') {
  header('Location: selectProfile.php'); exit;
}

/* ===== Helpers ===== */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ===== Routes (ADJUST sipas faqeve reale që ke) ===== */
$ROUTES = [
  'students'       => 'students.php',
  'groups'         => 'groups.php',
  'courses'        => 'courses.php',
  'agencies'       => 'agencies.php',
  'plans'          => 'plans.php',     // nëse s’e ke, ndrysho
  'logs'           => 'logs.php',
  'settings'       => 'settings.php',  // opsionale
  'profile_select' => 'selectProfile.php',
];

/* ===== KPIs ===== */
$k = [
  'students_total' => 0,
  'audit_24h'      => 0,
  'active_groups'  => 0,
  'active_enrollments' => 0,
  'students_no_group' => 0,
  'groups_ended_not_completed' => 0,
  'pending_exams'  => 0,
];

try {
  $k = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM students) AS students_total,

      (SELECT COUNT(*)
       FROM audit_events
       WHERE happened_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
      ) AS audit_24h,

      (SELECT COUNT(*) FROM course_groups
       WHERE CURDATE() BETWEEN start_date AND end_date
      ) AS active_groups,

      (SELECT COUNT(*)
       FROM course_group_students cgs
       JOIN course_groups cg ON cg.id=cgs.group_id
       WHERE CURDATE() BETWEEN cg.start_date AND cg.end_date
      ) AS active_enrollments,

      (SELECT COUNT(*)
       FROM students s
       LEFT JOIN course_group_students cgs ON cgs.student_id=s.id
       WHERE cgs.student_id IS NULL
      ) AS students_no_group,

      (SELECT COUNT(*)
       FROM course_groups
       WHERE end_date < CURDATE() AND (is_completed=0 OR is_completed IS NULL)
      ) AS groups_ended_not_completed,

      (SELECT COUNT(*)
       FROM course_group_students cgs
       JOIN course_groups cg ON cg.id=cgs.group_id
       WHERE cgs.exam_date IS NULL AND cg.end_date < CURDATE()
      ) AS pending_exams
  ")->fetch(PDO::FETCH_ASSOC) ?: $k;
} catch (Throwable $e) {
  // keep defaults
}

/* ===== Groups starting/ending soon (7 days) ===== */
$startingSoon = $endingSoon = [];
try {
  $startingSoon = $pdo->query("
    SELECT cg.id, cg.start_date, cg.end_date, cg.is_completed,
           c.code, c.name
    FROM course_groups cg
    JOIN courses c ON c.id=cg.course_id
    WHERE cg.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY cg.start_date ASC
    LIMIT 8
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $endingSoon = $pdo->query("
    SELECT cg.id, cg.start_date, cg.end_date, cg.is_completed,
           c.code, c.name
    FROM course_groups cg
    JOIN courses c ON c.id=cg.course_id
    WHERE cg.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY cg.end_date ASC
    LIMIT 8
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ===== Prezantimi ===== */
$greetHour = (int)date('G');
$greeting  = $greetHour < 12 ? 'Mirëmëngjes' : ($greetHour < 18 ? 'Mirëdita' : 'Mirëmbrëma');
$whoShort  = trim((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Administrator')));

$NAV_ACTIVE = 'dashboard';
$pageTitle  = 'Dashboard – QTA';
require __DIR__ . '/../shared/app_head.php';
require __DIR__ . '/inc/navbar.php';
?>

<main class="app-main">

  <!-- HERO -->
  <section class="app-hero">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
      <div class="min-w-0">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <span class="app-pill"><i class="bi bi-calendar3"></i><?= h(date('d.m.Y')) ?></span>
          <span class="app-pill"><i class="bi bi-activity"></i>Audit 24h: <strong class="text-body"><?= (int)$k['audit_24h'] ?></strong></span>
        </div>
        <h1><?= h($greeting) ?>, <?= h($whoShort) ?></h1>
        <p class="lead">Këtu janë veprimet kryesore dhe puna që ka mbetur pa u mbyllur në sistemin e certifikimeve.</p>
      </div>

      <div class="page-header-actions">
        <a class="btn btn-primary" href="<?= h($ROUTES['students']) ?>">
          <i class="bi bi-person-plus me-1"></i>Regjistro student
        </a>
        <a class="btn btn-outline-primary" href="<?= h($ROUTES['groups']) ?>">
          <i class="bi bi-people me-1"></i>Grupet
        </a>
      </div>
    </div>
  </section>

  <!-- KPI -->
  <section class="stat-grid" aria-label="Treguesit kryesorë">
    <div class="stat">
      <span class="app-icon"><i class="bi bi-mortarboard"></i></span>
      <div class="stat-body">
        <span class="stat-value"><?= (int)$k['students_total'] ?></span>
        <span class="stat-label">Total studentë</span>
      </div>
    </div>

    <div class="stat stat-success">
      <span class="app-icon success"><i class="bi bi-collection"></i></span>
      <div class="stat-body">
        <span class="stat-value"><?= (int)$k['active_groups'] ?></span>
        <span class="stat-label">Grupe aktive</span>
      </div>
    </div>

    <div class="stat stat-accent">
      <span class="app-icon accent"><i class="bi bi-person-check"></i></span>
      <div class="stat-body">
        <span class="stat-value"><?= (int)$k['active_enrollments'] ?></span>
        <span class="stat-label">Regjistrime aktive</span>
      </div>
    </div>

    <div class="stat stat-warning">
      <span class="app-icon warning"><i class="bi bi-exclamation-circle"></i></span>
      <div class="stat-body">
        <span class="stat-value"><?= (int)$k['students_no_group'] ?></span>
        <span class="stat-label">Studentë pa grup</span>
      </div>
    </div>
  </section>

  <!-- VEPRO SHPEJT -->
  <section class="mb-4">
    <div class="page-header">
      <div>
        <div class="page-eyebrow"><i class="bi bi-lightning-charge"></i>Shkurtore</div>
        <h2>Vepro shpejt</h2>
      </div>
    </div>

    <div class="row g-3">
      <?php
      $quickActions = [
        ['href' => $ROUTES['students'],       'icon' => 'bi-mortarboard',   'tone' => '',        'title' => 'Studentë',       'desc' => 'Krijo dhe menaxho regjistrime.'],
        ['href' => $ROUTES['groups'],         'icon' => 'bi-collection',    'tone' => 'success', 'title' => 'Grupe',          'desc' => 'Krijo grup, shto studentë, mbyll grup.'],
        ['href' => $ROUTES['courses'],        'icon' => 'bi-journal-text',  'tone' => 'accent',  'title' => 'Module',         'desc' => 'Shto ose ndrysho kurse dhe orë.'],
        ['href' => $ROUTES['agencies'],       'icon' => 'bi-building',      'tone' => 'info',    'title' => 'Agjenci',        'desc' => 'NIPT, të dhëna dhe studentë të caktuar.'],
        ['href' => $ROUTES['logs'],           'icon' => 'bi-shield-check',  'tone' => 'danger',  'title' => 'Audit / Log',    'desc' => 'Kontrollo ndryshimet dhe përdoruesit.'],
        ['href' => $ROUTES['profile_select'], 'icon' => 'bi-person-badge',  'tone' => 'warning', 'title' => 'Ndrysho profil', 'desc' => 'Kthehu te zgjedhja e profilit.'],
      ];
      foreach ($quickActions as $qa): ?>
        <div class="col-12 col-sm-6 col-xl-4">
          <a class="quick-action h-100" href="<?= h($qa['href']) ?>">
            <span class="app-icon <?= h($qa['tone']) ?>"><i class="bi <?= h($qa['icon']) ?>"></i></span>
            <span class="min-w-0">
              <strong><?= h($qa['title']) ?></strong>
              <span><?= h($qa['desc']) ?></span>
            </span>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- RADHA E PUNËS + KJO JAVË -->
  <section class="row g-3">
    <div class="col-12 col-xl-5">
      <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-inbox me-2"></i>Radha e punës</span>
          <span class="small text-muted fw-normal">Prioritete operative</span>
        </div>
        <div class="card-body p-0">
          <div class="list-group list-group-flush">
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3"
               href="<?= h($ROUTES['students']) ?>">
              <span>
                <strong class="d-block">Studentë pa grup</strong>
                <span class="small text-muted">Regjistrime që s'janë futur askund.</span>
              </span>
              <span class="badge text-bg-secondary"><?= (int)$k['students_no_group'] ?></span>
            </a>

            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3"
               href="<?= h($ROUTES['groups']) ?>">
              <span>
                <strong class="d-block">Grupe të papërfunduara</strong>
                <span class="small text-muted">Mbyllje operative, pastaj testet dhe notat.</span>
              </span>
              <span class="badge text-bg-warning"><?= (int)$k['groups_ended_not_completed'] ?></span>
            </a>

            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3"
               href="<?= h($ROUTES['groups']) ?>">
              <span>
                <strong class="d-block">Studentë pa test</strong>
                <span class="small text-muted">Në grupe të mbyllura pa datë testi.</span>
              </span>
              <span class="badge text-bg-danger"><?= (int)$k['pending_exams'] ?></span>
            </a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-7">
      <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-calendar-week me-2"></i>Kjo javë</span>
          <span class="small text-muted fw-normal">Grupet që nisin ose mbarojnë</span>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <?php
            $weekBlocks = [
              ['title' => 'Nisin brenda 7 ditëve',    'icon' => 'bi-play-circle', 'tone' => 'success', 'rows' => $startingSoon, 'empty' => 'Asnjë grup që nis në 7 ditë.'],
              ['title' => 'Mbarojnë brenda 7 ditëve', 'icon' => 'bi-flag',        'tone' => 'warning', 'rows' => $endingSoon,   'empty' => 'Asnjë grup që mbaron në 7 ditë.'],
            ];
            foreach ($weekBlocks as $block): ?>
              <div class="col-12 col-lg-6">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <span class="app-icon <?= h($block['tone']) ?>" style="width:32px;height:32px;font-size:.95rem;">
                    <i class="bi <?= h($block['icon']) ?>"></i>
                  </span>
                  <strong class="small"><?= h($block['title']) ?></strong>
                </div>

                <?php if ($block['rows']): ?>
                  <div class="list-group">
                    <?php foreach ($block['rows'] as $g): ?>
                      <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2"
                         href="<?= h($ROUTES['groups']) ?>">
                        <span class="min-w-0">
                          <strong class="d-block text-truncate"><?= h(trim(($g['code'] ?? '') . ' · ' . ($g['name'] ?? ''), ' ·')) ?></strong>
                          <span class="small text-muted tabular">
                            <?= h((string)($g['start_date'] ?? '')) ?> → <?= h((string)($g['end_date'] ?? '')) ?>
                          </span>
                        </span>
                        <i class="bi bi-chevron-right text-muted"></i>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="empty-state py-4">
                    <span class="empty-icon" style="width:44px;height:44px;font-size:1.1rem;"><i class="bi bi-calendar-x"></i></span>
                    <span class="small"><?= h($block['empty']) ?></span>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <p class="text-center text-muted small mt-4 mb-0">
    &copy; <?= date('Y') ?> QTA · Paneli i administrimit
  </p>

</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
