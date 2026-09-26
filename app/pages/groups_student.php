<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/../shared/themeli.php';

/* ------------------------------
   Guard: vetëm kursant i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id' => $_SESSION['user_id']]);
$currentUser = $u->fetch();
if (!$currentUser || $currentUser['role_name'] !== 'student') { header('Location: selectProfile.php'); exit; }

/* ------------------------------
   Personi i kësaj llogarie
------------------------------- */
$S0 = $pdo->prepare("
  SELECT s.id, s.person_id, p.personal_number
  FROM students s
  JOIN persons p ON p.id = s.person_id
  WHERE s.user_id = :uid
  LIMIT 1
");
$S0->execute([':uid' => $_SESSION['user_id']]);
$meStud = $S0->fetch();
$personId = $meStud ? (int)$meStud['person_id'] : 0;

/* ------------------------------
   Kurset (të gjitha regjistrimet e personit)
------------------------------- */
$groups = [];
$planned = [];
if ($personId > 0) {
  $G = $pdo->prepare("
    SELECT
      cg.id AS group_id, cg.start_date, cg.end_date,
      c.code AS course_code, c.name AS course_name, c.hours,
      cgs.final_score, cgs.exam_date,
      s.nr_amze
    FROM students s
    JOIN course_group_students cgs ON cgs.student_id = s.id
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE s.person_id = :pid
    ORDER BY cg.start_date DESC, cg.id DESC, CAST(s.nr_amze AS UNSIGNED) ASC
  ");
  $G->execute([':pid' => $personId]);
  $groups = $G->fetchAll(PDO::FETCH_ASSOC);

  /* Kurse të zgjedhura që presin grup */
  $P = $pdo->prepare("
    SELECT c.code AS course_code, c.name AS course_name, c.hours, s.nr_amze
    FROM student_course_plans scp
    JOIN students s ON s.id = scp.student_id
    JOIN courses c ON c.id = scp.course_id
    WHERE s.person_id = :pid AND scp.status = 'planned'
    ORDER BY c.name
  ");
  $P->execute([':pid' => $personId]);
  $planned = $P->fetchAll(PDO::FETCH_ASSOC);
}

$scored = array_values(array_filter($groups, static fn($g) => $g['final_score'] !== null));
$passed = count($scored);
$hoursDone = array_sum(array_map(static fn($g) => (int)$g['hours'], $scored));

$NAV_ACTIVE = 'student_groups';
$HELP_TOPIC = 'student_groups';
require __DIR__ . '/inc/navbar3.php';

$pageTitle = 'Kurset e mia';
require __DIR__ . '/../shared/app_head.php';
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Kurset e mia</h1>
      <p class="page-lead">Çdo kurs ku je regjistruar: datat e mësimit, provimi dhe pikët.</p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
    </div>
  </header>

  <?php if ($groups): ?>
    <div class="stats mb-4" aria-label="Përmbledhje">
      <div class="stat">
        <span class="stat-label">Kurse</span>
        <span class="stat-value"><?= count($groups) + count($planned) ?></span>
      </div>
      <div class="stat">
        <span class="stat-label">Me pikë</span>
        <span class="stat-value"><?= $passed ?></span>
      </div>
      <div class="stat">
        <span class="stat-label">Orë të përfunduara</span>
        <span class="stat-value"><?= number_format((int)$hoursDone, 0, ',', '.') ?></span>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($groups || $planned): ?>
    <ul class="enroll-list">
      <?php foreach ($groups as $g): ?>
        <li class="enroll">
          <div class="enroll-main">
            <span class="enroll-code"><?= h((string)$g['course_code']) ?> · Nr. i amzës <?= h((string)$g['nr_amze']) ?></span>
            <h2 class="enroll-title"><?= h((string)$g['course_name']) ?></h2>
            <dl class="enroll-meta">
              <div><dt>Mësimi</dt><dd><?= h(qta_date($g['start_date'])) ?> – <?= h(qta_date($g['end_date'])) ?></dd></div>
              <div><dt>Provimi</dt><dd><?= h(qta_date($g['exam_date'] ?? null, 'pa datë ende')) ?></dd></div>
              <?php if (!empty($g['hours'])): ?><div><dt>Orë</dt><dd><?= (int)$g['hours'] ?></dd></div><?php endif; ?>
            </dl>
          </div>
          <?= qta_enrollment_status($g) ?>
        </li>
      <?php endforeach; ?>
      <?php foreach ($planned as $p): ?>
        <li class="enroll">
          <div class="enroll-main">
            <span class="enroll-code"><?= h((string)$p['course_code']) ?> · Nr. i amzës <?= h((string)$p['nr_amze']) ?></span>
            <h2 class="enroll-title"><?= h((string)$p['course_name']) ?></h2>
            <p class="text-muted small mb-0">Je regjistruar. QTA do të të caktojë në grupin e radhës dhe do të njoftohesh për datat.</p>
          </div>
          <?= qta_status('Pret grupin', 'warning', 'bi-hourglass-split') ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($passed): ?>
      <div class="notice is-sunken mt-4">
        <i class="bi bi-qr-code" aria-hidden="true"></i>
        <span>Kurset e tua mund t'i verifikojë kushdo me kodin tënd QR — e gjen te <a href="dashboard_student.php">Kreu</a>.</span>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <?= qta_empty('Ende pa kurse', 'Kur QTA të regjistrojë në një kurs, ai shfaqet këtu me datat dhe pikët.', 'bi-journal', '<a class="btn btn-secondary" href="contact.php">Na kontaktoni</a>') ?>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
