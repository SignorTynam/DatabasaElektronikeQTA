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
$pageTitle  = 'Fleta e punës – Regjistri QTA';

require __DIR__ . '/../shared/app_head.php';
require __DIR__ . '/inc/navbar.php';
?>

<main class="sheet sheet-wide">

  <!-- ====================================================== BLLOKU I TITULLIT -->
  <div class="title-block">
    <div class="title-block-main">
      <div class="title-block-eyebrow">Fleta e punës</div>
      <h1><?= h($greeting) ?>, <?= h($whoShort) ?></h1>
      <p class="title-block-note">
        Gjendja e regjistrit sot dhe zërat që presin veprim.
      </p>
    </div>

    <div class="title-block-fields">
      <div class="title-block-field">
        <span class="label">Data</span>
        <span class="value"><?= h(date('d.m.Y')) ?></span>
      </div>
      <div class="title-block-field">
        <span class="label">Veprime 24h</span>
        <span class="value"><?= number_format((int)$k['audit_24h']) ?></span>
      </div>
    </div>
  </div>

  <!-- ============================================================== SHIFRAT -->
  <section class="tally" aria-label="Gjendja e regjistrit">
    <div class="tally-cell">
      <span class="label">Të regjistruar</span>
      <span class="tally-value"><?= number_format((int)$k['students_total']) ?></span>
      <span class="tally-foot">gjithsej</span>
    </div>
    <div class="tally-cell">
      <span class="label">Grupe në zhvillim</span>
      <span class="tally-value"><?= number_format((int)$k['active_groups']) ?></span>
      <span class="tally-foot">sot</span>
    </div>
    <div class="tally-cell">
      <span class="label">Regjistrime aktive</span>
      <span class="tally-value"><?= number_format((int)$k['active_enrollments']) ?></span>
      <span class="tally-foot">në grupe të hapura</span>
    </div>
    <div class="tally-cell<?= (int)$k['students_no_group'] > 0 ? ' is-hold' : '' ?>">
      <span class="label">Pa grup</span>
      <span class="tally-value"><?= number_format((int)$k['students_no_group']) ?></span>
      <span class="tally-foot">presin caktim</span>
    </div>
  </section>

  <div class="row g-4">

    <!-- ===================================================== ZËRAT PA MBYLLUR -->
    <div class="col-12 col-xl-5">
      <section aria-labelledby="pendingTitle">
        <div class="band-head" style="margin-bottom:0">
          <h2 id="pendingTitle">Pa mbyllur</h2>
          <span class="label">Kërkon veprim</span>
        </div>

        <div class="action-list">
          <?php
          $pending = [
            ['no' => '01', 'href' => $ROUTES['students'], 'title' => 'Kursantë pa grup',
             'note' => 'Regjistruar, por ende pa u caktuar në asnjë grup.',
             'count' => (int)$k['students_no_group']],
            ['no' => '02', 'href' => $ROUTES['groups'], 'title' => 'Grupe të mbaruara, të pambyllura',
             'note' => 'Data e mbarimit ka kaluar dhe grupi s\'është mbyllur.',
             'count' => (int)$k['groups_ended_not_completed']],
            ['no' => '03', 'href' => $ROUTES['groups'], 'title' => 'Provime pa datë',
             'note' => 'Kursantë në grupe të mbyllura pa datë provimi.',
             'count' => (int)$k['pending_exams']],
          ];
          foreach ($pending as $row): ?>
            <a class="action-row" href="<?= h($row['href']) ?>">
              <span class="no"><?= h($row['no']) ?></span>
              <span class="action-main">
                <b><?= h($row['title']) ?></b>
                <span><?= h($row['note']) ?></span>
              </span>
              <span class="action-count"><?= number_format($row['count']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <!-- ============================================================ KALENDARI -->
    <div class="col-12 col-xl-7">
      <section aria-labelledby="weekTitle">
        <div class="band-head" style="margin-bottom:0">
          <h2 id="weekTitle">Shtatë ditët e ardhshme</h2>
          <span class="label">Grupet</span>
        </div>

        <div class="row g-4 mt-0">
          <?php
          $weeks = [
            ['title' => 'Nisin', 'rows' => $startingSoon, 'field' => 'start_date',
             'empty' => 'Asnjë grup nuk nis brenda shtatë ditëve.'],
            ['title' => 'Mbarojnë', 'rows' => $endingSoon, 'field' => 'end_date',
             'empty' => 'Asnjë grup nuk mbaron brenda shtatë ditëve.'],
          ];
          foreach ($weeks as $w): ?>
            <div class="col-12 col-lg-6">
              <span class="label" style="display:block;padding-bottom:.4rem;border-bottom:1px solid var(--rule)">
                <?= h($w['title']) ?>
              </span>

              <?php if ($w['rows']): ?>
                <div class="action-list" style="border-top:0">
                  <?php foreach ($w['rows'] as $i => $g): ?>
                    <a class="action-row" href="<?= h($ROUTES['groups']) ?>">
                      <span class="no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                      <span class="action-main">
                        <b class="text-truncate d-block"><?= h((string)($g['name'] ?? '')) ?></b>
                        <span class="code"><?= h((string)($g['code'] ?? '')) ?></span>
                      </span>
                      <span class="action-count" style="font-size:var(--fs-sm)">
                        <?= h(date('d.m', strtotime((string)$g[$w['field']]))) ?>
                      </span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="blank" style="padding:1.75rem 1rem;margin-top:.75rem">
                  <span class="blank-note"><?= h($w['empty']) ?></span>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>
  </div>

  <!-- ============================================================ SHKURTORET -->
  <section class="mt-5" aria-labelledby="shortcutsTitle">
    <div class="band-head">
      <h2 id="shortcutsTitle">Shko te</h2>
    </div>

    <div class="row g-2">
      <?php
      $shortcuts = [
        ['href' => $ROUTES['students'], 'title' => 'Kursantët',  'note' => 'Regjistrimi dhe të dhënat'],
        ['href' => $ROUTES['groups'],   'title' => 'Grupet',     'note' => 'Caktimi, provimet, mbyllja'],
        ['href' => $ROUTES['courses'],  'title' => 'Modulet',    'note' => 'Zanatet dhe orët'],
        ['href' => $ROUTES['agencies'], 'title' => 'Agjencitë',  'note' => 'Kompanitë dhe punonjësit'],
        ['href' => $ROUTES['logs'],     'title' => 'Auditimi',   'note' => 'Kush ndryshoi çfarë'],
        ['href' => 'users.php',         'title' => 'Përdoruesit','note' => 'Aksesi dhe rolet'],
      ];
      foreach ($shortcuts as $sc): ?>
        <div class="col-12 col-sm-6 col-lg-4">
          <a class="shortcut" href="<?= h($sc['href']) ?>">
            <span>
              <b><?= h($sc['title']) ?></b>
              <span><?= h($sc['note']) ?></span>
            </span>
            <i class="bi bi-arrow-right arrow"></i>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
