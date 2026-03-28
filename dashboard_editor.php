<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ===== Guard: editor ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }

$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id
  LIMIT 1
");
$u->execute([':id' => $_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || ($currentUser['role_name'] ?? '') !== 'editor') {
  header('Location: selectProfile.php'); exit;
}

/* ===== Helpers ===== */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ===== Routes (rregullo sipas faqeve reale) ===== */
$ROUTES = [
  'students'       => 'students.php',
  'groups'         => 'groups.php',
  'courses'        => 'courses.php',
  'agencies'       => 'agencies.php',
  'plans'          => 'plans.php',
  'logs'           => 'logs.php',
  'settings'       => 'settings.php',  // nëse s’e ke, hiqe nga UI
  'profile_select' => 'selectProfile.php',
];

/* ===== KPIs (editor) ===== */
$k = [
  'students_total' => 0,
  'audit_24h'      => 0,
  'active_groups'  => 0,
  'active_enrollments' => 0,
  'students_no_group' => 0,
  'groups_ended_not_completed' => 0,
  'pending_exams'  => 0,
  'scp_planned'    => 0,
  'scp_assigned'   => 0,
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
      ) AS pending_exams,

      (SELECT COUNT(*) FROM student_course_plans WHERE status='planned')  AS scp_planned,
      (SELECT COUNT(*) FROM student_course_plans WHERE status='assigned') AS scp_assigned
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

/* Navbar */
$NAV_ACTIVE = 'dashboard';
require __DIR__ . '/inc/navbar4.php';
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8" />
  <title>Editor Dashboard – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>

  <style>
    /* ===== Scoped: vetëm për këtë dashboard (Editor) ===== */
    .qta-edm { background:#f6f8fc; min-height:100vh; padding-top:72px; }

    .qta-edm .card{
      border:1px solid #e9eef6;
      border-radius:16px;
      box-shadow:0 12px 30px rgba(2,6,23,.06);
    }

    .qta-edm .hero{
      border-radius:20px;
      background:
        radial-gradient(900px 300px at 90% -20%, rgba(34,197,94,.14), rgba(34,197,94,0) 60%),
        radial-gradient(900px 300px at 10% -30%, rgba(59,130,246,.18), rgba(59,130,246,0) 55%),
        linear-gradient(135deg, #eef2ff 0%, #f8fafc 100%);
      border:1px solid #e9eef6;
    }

    .qta-edm .muted{ color:#64748b; }

    .qta-edm .pill{
      display:inline-flex; align-items:center; gap:.5rem;
      border:1px solid #e9eef6; background:#fff;
      padding:.35rem .65rem; border-radius:999px;
      font-size:.875rem;
    }

    .qta-edm .qa{
      display:flex; gap:12px; align-items:flex-start;
      padding:12px; border-radius:14px;
      border:1px solid #eef2f7; background:#fff;
      text-decoration:none; color:inherit;
      transition:transform .08s ease, box-shadow .08s ease;
    }
    .qta-edm .qa:hover{
      transform:translateY(-1px);
      box-shadow:0 10px 22px rgba(2,6,23,.08);
    }

    .qta-edm .qa .ico{
      width:44px; height:44px; border-radius:12px;
      display:flex; align-items:center; justify-content:center;
      background:#f1f5f9;
    }

    .qta-edm .kpi{
      display:flex; align-items:center; justify-content:space-between; gap:12px;
      padding:14px 16px;
      border:1px solid #eef2f7; background:#fff;
      border-radius:14px;
    }
    .qta-edm .kpi .val{ font-weight:800; font-size:1.15rem; color:#0f172a; }
    .qta-edm .kpi .lbl{ font-size:.875rem; color:#64748b; }

    .qta-edm .list-tight .list-group-item{ padding:.75rem .9rem; }
    .qta-edm .soft-warn{ background:#fff7ed; border:1px solid #ffedd5; }
    .qta-edm .soft-info{ background:#eff6ff; border:1px solid #dbeafe; }
  </style>
</head>

<body class="qta-edm">
<main class="container-fluid px-3 px-md-4 pb-5">

  <!-- HERO -->
  <section class="hero p-4 p-md-5 mb-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
      <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <span class="pill"><i class="bi bi-sliders"></i> Editor Panel</span>
          <span class="pill"><i class="bi bi-calendar3"></i> <?= date('Y-m-d') ?></span>
          <span class="pill"><i class="bi bi-activity"></i> Audit 24h: <strong><?= (int)$k['audit_24h'] ?></strong></span>
        </div>
        <h1 class="fw-bold mb-1">Sistemi i certifikimeve</h1>
        <div class="muted">Përmbledhje operative: grupe aktive, backlog (planned/assigned) dhe punë për t’u mbyllur.</div>
        <div class="small muted mt-2">
          Mirë se erdhe: <strong><?= h($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Editor')) ?></strong>
        </div>
      </div>
    </div>
  </section>

  <!-- KPI GRID -->
  <section class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="kpi">
        <div>
          <div class="lbl">Total studentë</div>
          <div class="val"><?= (int)$k['students_total'] ?></div>
        </div>
        <div><i class="bi bi-mortarboard fs-3 text-primary"></i></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="kpi">
        <div>
          <div class="lbl">Grupe aktive</div>
          <div class="val"><?= (int)$k['active_groups'] ?></div>
        </div>
        <div><i class="bi bi-collection fs-3 text-success"></i></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="kpi">
        <div>
          <div class="lbl">Regjistrime aktive</div>
          <div class="val"><?= (int)$k['active_enrollments'] ?></div>
        </div>
        <div><i class="bi bi-person-check fs-3 text-info"></i></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="kpi">
        <div>
          <div class="lbl">Backlog plane</div>
          <div class="val"><?= (int)$k['scp_planned'] ?> <span class="text-muted fw-normal" style="font-size:.95rem;">planned</span></div>
        </div>
        <div><i class="bi bi-list-check fs-3 text-dark"></i></div>
      </div>
    </div>
  </section>

  <!-- QUICK ACTIONS -->
  <section class="row g-4 mb-4">
    <div class="col-12">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h5 class="mb-0 fw-semibold"><i class="bi bi-lightning me-2"></i>Vepro shpejt</h5>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a class="qa" href="<?= h($ROUTES['students']) ?>">
        <div class="ico"><i class="bi bi-mortarboard fs-4 text-primary"></i></div>
        <div>
          <div class="fw-semibold">Studentë</div>
          <div class="small muted">Kërko dhe menaxho regjistrime.</div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a class="qa" href="<?= h($ROUTES['groups']) ?>">
        <div class="ico"><i class="bi bi-collection fs-4 text-success"></i></div>
        <div>
          <div class="fw-semibold">Grupe</div>
          <div class="small muted">Shto studentë, mbyll grupe, data testesh.</div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a class="qa" href="<?= h($ROUTES['plans']) ?>">
        <div class="ico"><i class="bi bi-list-check fs-4 text-dark"></i></div>
        <div>
          <div class="fw-semibold">Plane (SCP)</div>
          <div class="small muted">Planned → Assigned → Completed.</div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a class="qa" href="<?= h($ROUTES['courses']) ?>">
        <div class="ico"><i class="bi bi-journal-text fs-4 text-info"></i></div>
        <div>
          <div class="fw-semibold">Module</div>
          <div class="small muted">Shiko listën e moduleve/kursit.</div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a class="qa" href="<?= h($ROUTES['agencies']) ?>">
        <div class="ico"><i class="bi bi-building fs-4 text-secondary"></i></div>
        <div>
          <div class="fw-semibold">Agjenci</div>
          <div class="small muted">NIPT dhe studentët e lidhur.</div>
        </div>
      </a>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <a class="qa" href="<?= h($ROUTES['logs']) ?>">
        <div class="ico"><i class="bi bi-shield-check fs-4 text-danger"></i></div>
        <div>
          <div class="fw-semibold">Audit / Log</div>
          <div class="small muted">Kontrollo veprimet e fundit.</div>
        </div>
      </a>
    </div>

    <?php if (!empty($ROUTES['settings'])): ?>
      <div class="col-12 col-md-6 col-xl-3">
        <a class="qa" href="<?= h($ROUTES['settings']) ?>">
          <div class="ico"><i class="bi bi-gear fs-4 text-primary"></i></div>
          <div>
            <div class="fw-semibold">Settings</div>
            <div class="small muted">Parametra (opsionale).</div>
          </div>
        </a>
      </div>
    <?php endif; ?>

    <div class="col-12 col-md-6 col-xl-3">
      <a class="qa" href="<?= h($ROUTES['profile_select']) ?>">
        <div class="ico"><i class="bi bi-person-badge fs-4 text-success"></i></div>
        <div>
          <div class="fw-semibold">Ndrysho profil</div>
          <div class="small muted">Kthehu te zgjedhja e profilit.</div>
        </div>
      </a>
    </div>
  </section>

  <!-- WORK QUEUE + THIS WEEK -->
  <section class="row g-4 mb-4">
    <div class="col-12 col-xl-5">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-inbox me-2"></i>Work Queue (Action needed)</h6>
          <span class="small muted">Puna që s’duhet lënë pas</span>
        </div>
        <div class="card-body p-0">
          <div class="list-group list-group-flush list-tight">
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
               href="<?= h($ROUTES['students']) ?>">
              <div>
                <div class="fw-semibold">Studentë pa grup</div>
                <div class="small muted">Duhet caktim në grup / plan.</div>
              </div>
              <span class="badge text-bg-secondary rounded-pill"><?= (int)$k['students_no_group'] ?></span>
            </a>

            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
               href="<?= h($ROUTES['groups']) ?>">
              <div>
                <div class="fw-semibold">Grupe të mbyllura, jo “completed”</div>
                <div class="small muted">Duhet mbyllje operative.</div>
              </div>
              <span class="badge text-bg-warning rounded-pill"><?= (int)$k['groups_ended_not_completed'] ?></span>
            </a>

            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
               href="<?= h($ROUTES['groups']) ?>">
              <div>
                <div class="fw-semibold">Studentë pa test (grupe të mbyllura)</div>
                <div class="small muted">Cakto exam_date ose procedo mbylljen.</div>
              </div>
              <span class="badge text-bg-danger rounded-pill"><?= (int)$k['pending_exams'] ?></span>
            </a>

            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
               href="<?= h($ROUTES['plans']) ?>">
              <div>
                <div class="fw-semibold">Backlog plane “planned”</div>
                <div class="small muted">Ktheji në “assigned” (kur të krijohet grupi).</div>
              </div>
              <span class="badge text-bg-primary rounded-pill"><?= (int)$k['scp_planned'] ?></span>
            </a>

          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-7">
      <div class="card h-100">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-calendar-week me-2"></i>Kjo javë</h6>
          <span class="small muted">Grupet që nisin/mbarojnë së shpejti</span>
        </div>

        <div class="card-body">
          <div class="row g-4">
            <div class="col-12 col-lg-6">
              <div class="soft-info p-3 rounded-4">
                <div class="fw-semibold mb-2"><i class="bi bi-play-circle me-2"></i>Nisin (7 ditë)</div>

                <?php if ($startingSoon): ?>
                  <div class="list-group list-tight">
                    <?php foreach ($startingSoon as $g): ?>
                      <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                         href="<?= h($ROUTES['groups']) ?>">
                        <div>
                          <div class="fw-semibold"><?= h(($g['code'] ?? '').' · '.($g['name'] ?? '')) ?></div>
                          <div class="small muted">Start: <?= h($g['start_date'] ?? '') ?> • End: <?= h($g['end_date'] ?? '') ?></div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="text-muted">Asnjë grup që nis në 7 ditë.</div>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-12 col-lg-6">
              <div class="soft-warn p-3 rounded-4">
                <div class="fw-semibold mb-2"><i class="bi bi-flag me-2"></i>Mbarojnë (7 ditë)</div>

                <?php if ($endingSoon): ?>
                  <div class="list-group list-tight">
                    <?php foreach ($endingSoon as $g): ?>
                      <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                         href="<?= h($ROUTES['groups']) ?>">
                        <div>
                          <div class="fw-semibold"><?= h(($g['code'] ?? '').' · '.($g['name'] ?? '')) ?></div>
                          <div class="small muted">End: <?= h($g['end_date'] ?? '') ?> • Start: <?= h($g['start_date'] ?? '') ?></div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="text-muted">Asnjë grup që mbaron në 7 ditë.</div>
                <?php endif; ?>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </section>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Editor Dashboard
  </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
