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

/* ============================================================================
   Fleta e punës e editorit — të dhëna nga fushat që regjistri i mban vërtet.
   ========================================================================= */

$one = static function (PDO $pdo, string $sql, array $fallback = []) {
  try { $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r !== false ? $r : $fallback; }
  catch (Throwable $e) { return $fallback; }
};
$many = static function (PDO $pdo, string $sql): array {
  try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
  catch (Throwable $e) { return []; }
};

$f = $one($pdo, "
  SELECT
    (SELECT COUNT(*) FROM students)                                        AS students,
    (SELECT COUNT(*) FROM course_groups)                                   AS groups,
    (SELECT COUNT(*) FROM course_group_students)                           AS enrolled,
    (SELECT COUNT(*) FROM course_group_students WHERE final_score IS NOT NULL) AS scored,
    (SELECT ROUND(AVG(final_score),1) FROM course_group_students WHERE final_score IS NOT NULL) AS avg_score,
    (SELECT ROUND(AVG(n),1) FROM (SELECT COUNT(*) n FROM course_group_students GROUP BY group_id) t) AS avg_group
", ['students'=>0,'groups'=>0,'enrolled'=>0,'scored'=>0,'avg_score'=>null,'avg_group'=>null]);

$rhythm = $many($pdo, "
  SELECT DATE_FORMAT(cg.start_date, '%Y-%m') AS ym,
         COUNT(DISTINCT cg.id) AS groups,
         COUNT(cgs.student_id) AS students
  FROM course_groups cg
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  WHERE cg.start_date IS NOT NULL
  GROUP BY ym ORDER BY ym
");
$rhythmMax = 0;
foreach ($rhythm as $r) { $rhythmMax = max($rhythmMax, (int)$r['students']); }

$topModules = $many($pdo, "
  SELECT c.code, c.name, COUNT(cgs.student_id) AS n
  FROM courses c
  JOIN course_groups cg          ON cg.course_id = c.id
  JOIN course_group_students cgs ON cgs.group_id = cg.id
  GROUP BY c.id HAVING n > 0 ORDER BY n DESC LIMIT 8
");
$topMax = $topModules ? (int)$topModules[0]['n'] : 1;

$bands = $many($pdo, "
  SELECT CASE
           WHEN final_score >= 90 THEN '90–100'
           WHEN final_score >= 80 THEN '80–89'
           WHEN final_score >= 70 THEN '70–79'
           ELSE 'nën 70' END AS band,
         COUNT(*) AS n
  FROM course_group_students
  WHERE final_score IS NOT NULL
  GROUP BY band ORDER BY MIN(final_score) DESC
");
$bandMax = 0;
foreach ($bands as $b) { $bandMax = max($bandMax, (int)$b['n']); }

/* Veprimet e mia — çfarë ka bërë vetë ky editor. */
$mine = ['n' => 0];
try {
  $st = $pdo->prepare("SELECT COUNT(*) n FROM audit_events WHERE user_id = :id");
  $st->execute([':id' => (int)$currentUser['id']]);
  $mine = $st->fetch(PDO::FETCH_ASSOC) ?: $mine;
} catch (Throwable $e) { /* pa numër */ }

/* Kontrolle higjiene — probleme të vërteta, jo fusha të pambajtura. */
$checks = [
  ['Kursantë pa grup', 'Të regjistruar, por të pacaktuar në asnjë grup.', 'students.php',
   "SELECT COUNT(*) n FROM students s WHERE NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.student_id = s.id)"],
  ['Pa numër personal', 'Identifikuesi bazë mungon në kartelë.', 'students.php',
   "SELECT COUNT(*) n FROM persons WHERE personal_number IS NULL OR personal_number = ''"],
  ['Datëlindje jashtë kufirit', 'Mosha del nën 15 ose mbi 90 vjeç — ka gabim shtypi.', 'students.php',
   "SELECT COUNT(*) n FROM persons WHERE birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) NOT BETWEEN 15 AND 90"],
  ['Grupe bosh', 'Grupe pa asnjë kursant të caktuar.', 'groups.php',
   "SELECT COUNT(*) n FROM course_groups cg WHERE NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.group_id = cg.id)"],
];
$hygiene = [];
foreach ($checks as $c) {
  $n = (int)($one($pdo, $c[3], ['n' => 0])['n']);
  if ($n > 0) { $hygiene[] = ['title' => $c[0], 'note' => $c[1], 'href' => $c[2], 'n' => $n]; }
}

$greetHour = (int)date('G');
$greeting  = $greetHour < 12 ? 'Mirëmëngjes' : ($greetHour < 18 ? 'Mirëdita' : 'Mirëmbrëma');
$whoShort  = trim((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Editor')));

$months = ['01'=>'Jan','02'=>'Shk','03'=>'Mar','04'=>'Pri','05'=>'Maj','06'=>'Qer',
           '07'=>'Kor','08'=>'Gsh','09'=>'Sht','10'=>'Tet','11'=>'Nën','12'=>'Dhj'];

$NAV_ACTIVE = 'dashboard';
require __DIR__ . '/inc/navbar4.php';

$pageTitle = 'Fleta e punës – Regjistri QTA';
require __DIR__ . '/../shared/app_head.php';
?>

<main class="app-main">

  <div class="title-block">
    <div class="title-block-main">
      <div class="title-block-eyebrow">Fleta e punës</div>
      <h1><?= h($greeting) ?>, <?= h($whoShort) ?></h1>
      <p class="title-block-note">Gjendja e regjistrit dhe zërat që presin dorën tënde.</p>
    </div>
    <div class="title-block-fields">
      <div class="title-block-field">
        <span class="label">Data</span>
        <span class="value"><?= h(date('d.m.Y')) ?></span>
      </div>
      <div class="title-block-field">
        <span class="label">Veprimet e mia</span>
        <span class="value"><?= number_format((int)$mine['n']) ?></span>
      </div>
    </div>
  </div>

  <section class="tally" aria-label="Gjendja e regjistrit">
    <div class="tally-cell">
      <span class="label">Në regjistër</span>
      <span class="tally-value"><?= number_format((int)$f['students']) ?></span>
      <span class="tally-foot">kursantë</span>
    </div>
    <div class="tally-cell">
      <span class="label">Grupe</span>
      <span class="tally-value"><?= number_format((int)$f['groups']) ?></span>
      <span class="tally-foot">mes. <?= h((string)($f['avg_group'] ?? '—')) ?> kursantë</span>
    </div>
    <div class="tally-cell">
      <span class="label">Regjistrime</span>
      <span class="tally-value"><?= number_format((int)$f['enrolled']) ?></span>
      <span class="tally-foot">caktime në grupe</span>
    </div>
    <div class="tally-cell">
      <span class="label">Nota mesatare</span>
      <span class="tally-value"><?= $f['avg_score'] !== null ? h((string)$f['avg_score']) : '—' ?></span>
      <span class="tally-foot"><?= number_format((int)$f['scored']) ?> të vlerësuar</span>
    </div>
  </section>

  <?php if ($rhythm): ?>
    <section class="mb-5" aria-labelledby="rhythmTitle">
      <div class="plate-head">
        <h2 id="rhythmTitle">Ritmi i trajnimeve</h2>
        <span class="label">Kursantë për muaj nisjeje</span>
      </div>
      <div class="bars">
        <?php foreach ($rhythm as $r):
          $n = (int)$r['students'];
          $pct = $rhythmMax > 0 ? max(2, round($n / $rhythmMax * 100)) : 2; ?>
          <div class="bar<?= $n === $rhythmMax ? ' is-peak' : '' ?>"
               title="<?= h($r['ym']) ?>: <?= $n ?> kursantë në <?= (int)$r['groups'] ?> grupe">
            <span class="bar-value"><?= $n ?></span>
            <span class="bar-fill" style="height:<?= $pct ?>%"></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="bars-axis">
        <?php foreach ($rhythm as $r): $mm = substr((string)$r['ym'], 5, 2); ?>
          <span><?= h($months[$mm] ?? $mm) ?></span>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="row g-4 g-xl-5">
    <div class="col-12 col-xl-7">
      <section aria-labelledby="modTitle">
        <div class="plate-head">
          <h2 id="modTitle">Zanatet më të kërkuara</h2>
          <a class="label" href="courses.php">Të gjitha →</a>
        </div>
        <?php if ($topModules): ?>
          <div class="rank">
            <?php foreach ($topModules as $m):
              $n = (int)$m['n'];
              $pct = $topMax > 0 ? round($n / $topMax * 100) : 0; ?>
              <a class="rank-row" href="courses.php" title="<?= h((string)$m['name']) ?> — <?= $n ?> kursantë">
                <span class="code"><?= h((string)$m['code']) ?></span>
                <span class="rank-name"><?= h((string)$m['name']) ?></span>
                <span class="rank-n"><?= $n ?></span>
                <span class="rank-track"><span class="rank-bar" style="width:<?= $pct ?>%"></span></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="blank"><span class="blank-note">Ende asnjë kursant i caktuar në modul.</span></div>
        <?php endif; ?>
      </section>
    </div>

    <div class="col-12 col-xl-5">
      <section aria-labelledby="scoreTitle">
        <div class="plate-head">
          <h2 id="scoreTitle">Vlerësimi</h2>
          <span class="label"><?= number_format((int)$f['scored']) ?> nga <?= number_format((int)$f['enrolled']) ?></span>
        </div>
        <?php if ($bands): ?>
          <div class="spread mb-3">
            <?php foreach ($bands as $b):
              $n = (int)$b['n'];
              $pct = $bandMax > 0 ? max(1, round($n / $bandMax * 100)) : 1; ?>
              <div class="spread-row<?= $n === $bandMax ? ' is-top' : '' ?>">
                <span class="spread-band"><?= h((string)$b['band']) ?></span>
                <span class="spread-track"><span class="spread-bar" style="width:<?= $pct ?>%"></span></span>
                <span class="spread-n"><?= $n ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <dl style="margin:0">
            <div class="datarow">
              <dt>Nota mesatare</dt><dd><?= h((string)($f['avg_score'] ?? '—')) ?></dd>
            </div>
            <div class="datarow">
              <dt>Ende pa notë</dt>
              <dd><?= number_format(max(0, (int)$f['enrolled'] - (int)$f['scored'])) ?></dd>
            </div>
          </dl>
        <?php else: ?>
          <div class="blank"><span class="blank-note">Ende asnjë provim i vlerësuar.</span></div>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <section class="mt-5" aria-labelledby="hygTitle">
    <div class="plate-head">
      <h2 id="hygTitle">Të dhëna që duhen parë</h2>
      <span class="label"><?= count($hygiene) ?> nga <?= count($checks) ?> kontrolle</span>
    </div>
    <?php if ($hygiene): ?>
      <div class="action-list">
        <?php $i = 0; foreach ($hygiene as $row): $i++; ?>
          <a class="action-row" href="<?= h($row['href']) ?>">
            <span class="no"><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?></span>
            <span class="action-main">
              <b><?= h($row['title']) ?></b>
              <span><?= h($row['note']) ?></span>
            </span>
            <span class="action-count"><?= number_format($row['n']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="blank">
        <span class="blank-title">Regjistri është i pastër</span>
        <span class="blank-note">Asnjë nga kontrollet nuk gjeti të dhëna që kërkojnë ndreqje.</span>
      </div>
    <?php endif; ?>
  </section>

  <section class="mt-5" aria-labelledby="goTitle">
    <div class="plate-head"><h2 id="goTitle">Shko te</h2></div>
    <div class="row g-2">
      <?php
      $shortcuts = [
        ['students.php',  'Kursantët', 'Regjistrimi dhe të dhënat'],
        ['groups.php',    'Grupet',    'Caktimi, provimet, mbyllja'],
        ['courses.php',   'Modulet',   'Zanatet dhe orët'],
        ['agencies.php',  'Agjencitë', 'Kompanitë dhe punonjësit'],
        ['logs_editor.php','Veprimet e mia', 'Çfarë kam ndryshuar'],
        ['profile.php',   'Profili',   'Të dhënat e mia'],
      ];
      foreach ($shortcuts as $sc): ?>
        <div class="col-12 col-sm-6 col-lg-4">
          <a class="shortcut" href="<?= h($sc[0]) ?>">
            <span><b><?= h($sc[1]) ?></b><span><?= h($sc[2]) ?></span></span>
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
