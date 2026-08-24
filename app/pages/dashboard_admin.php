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


/* ============================================================================
   Fleta e punës — të dhënat
   ----------------------------------------------------------------------------
   Çdo pyetje këtu mbështetet te fusha që regjistri i mban vërtet. Asgjë nuk
   llogaritet nga fusha që fluksi nuk i plotëson.
   ========================================================================= */

$one = static function (PDO $pdo, string $sql, array $fallback = []) {
  try {
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : $fallback;
  } catch (Throwable $e) {
    return $fallback;
  }
};
$many = static function (PDO $pdo, string $sql): array {
  try {
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
};

/* ----- Shifrat kryesore ----- */
$k = $one($pdo, "
  SELECT
    (SELECT COUNT(*) FROM students)                                        AS students,
    (SELECT COUNT(*) FROM course_groups)                                   AS groups,
    (SELECT COUNT(DISTINCT course_id) FROM course_groups)                  AS modules_used,
    (SELECT COUNT(*) FROM courses)                                         AS modules_all,
    (SELECT COUNT(*) FROM course_group_students WHERE final_score IS NOT NULL) AS scored,
    (SELECT ROUND(AVG(final_score),1) FROM course_group_students WHERE final_score IS NOT NULL) AS avg_score
", ['students'=>0,'groups'=>0,'modules_used'=>0,'modules_all'=>0,'scored'=>0,'avg_score'=>null]);

$enrolled = (int)($one($pdo, "SELECT COUNT(*) n FROM course_group_students", ['n'=>0])['n']);

/* ----- Ritmi: grupe e kursantë sipas muajit ----- */
$rhythm = $many($pdo, "
  SELECT DATE_FORMAT(cg.start_date, '%Y-%m') AS ym,
         COUNT(DISTINCT cg.id)              AS groups,
         COUNT(cgs.student_id)              AS students
  FROM course_groups cg
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  WHERE cg.start_date IS NOT NULL
  GROUP BY ym
  ORDER BY ym
");
$rhythmMax = 0;
foreach ($rhythm as $r) { $rhythmMax = max($rhythmMax, (int)$r['students']); }

/* ----- Modulet më të kërkuara ----- */
$topModules = $many($pdo, "
  SELECT c.id, c.code, c.name, COUNT(cgs.student_id) AS n
  FROM courses c
  JOIN course_groups cg           ON cg.course_id = c.id
  JOIN course_group_students cgs  ON cgs.group_id = cg.id
  GROUP BY c.id
  HAVING n > 0
  ORDER BY n DESC
  LIMIT 8
");
$topMax = $topModules ? (int)$topModules[0]['n'] : 1;

/* ----- Shpërndarja e notave ----- */
$bands = $many($pdo, "
  SELECT CASE
           WHEN final_score >= 90 THEN '90–100'
           WHEN final_score >= 80 THEN '80–89'
           WHEN final_score >= 70 THEN '70–79'
           ELSE 'nën 70'
         END AS band,
         COUNT(*) AS n
  FROM course_group_students
  WHERE final_score IS NOT NULL
  GROUP BY band
  ORDER BY MIN(final_score) DESC
");
$bandMax = 0;
foreach ($bands as $b) { $bandMax = max($bandMax, (int)$b['n']); }

/* ----- Profili i kursantit ----- */
$profile = $one($pdo, "
  SELECT
    (SELECT ROUND(AVG(TIMESTAMPDIFF(YEAR, birth_date, CURDATE())))
       FROM persons WHERE birth_date IS NOT NULL
        AND TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 15 AND 90)     AS avg_age,
    (SELECT ROUND(AVG(n),1) FROM (SELECT COUNT(*) n FROM course_group_students GROUP BY group_id) t) AS avg_group
", ['avg_age'=>null,'avg_group'=>null]);

$schooling = $many($pdo, "
  SELECT el.label, COUNT(s.id) AS n
  FROM education_levels el
  LEFT JOIN students s ON s.education_level_id = el.id
  GROUP BY el.id
  HAVING n > 0
  ORDER BY n DESC
");
$schoolTotal = 0;
foreach ($schooling as $s) { $schoolTotal += (int)$s['n']; }

$genders = $many($pdo, "
  SELECT g.label, COUNT(p.id) AS n
  FROM genders g
  JOIN persons p ON p.gender_id = g.id
  GROUP BY g.id
  ORDER BY n DESC
");
$genderTotal = 0;
foreach ($genders as $g) { $genderTotal += (int)$g['n']; }

/* ----- Origjina ----- */
$origins = $many($pdo, "
  SELECT birth_place, COUNT(*) AS n
  FROM persons
  WHERE birth_place IS NOT NULL AND birth_place <> ''
  GROUP BY birth_place
  ORDER BY n DESC
  LIMIT 8
");
$originMax = $origins ? (int)$origins[0]['n'] : 1;

/* ----- Aktiviteti i regjistrit ----- */
$actions = $many($pdo, "SELECT action, COUNT(*) AS n FROM audit_events GROUP BY action ORDER BY n DESC");
$actionTotal = 0;
foreach ($actions as $a) { $actionTotal += (int)$a['n']; }

/* ----- Të dhëna që duhen parë (higjienë reale, jo fusha të pambajtura) ----- */
$hygiene = [];
$hygiene[] = [
  'title' => 'Kursantë pa grup',
  'note'  => 'Të regjistruar, por të pacaktuar në asnjë grup.',
  'href'  => $ROUTES['students'],
  'n'     => (int)($one($pdo, "
      SELECT COUNT(*) n FROM students s
      WHERE NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.student_id = s.id)
    ", ['n'=>0])['n']),
];
$hygiene[] = [
  'title' => 'Datëlindje jashtë kufirit',
  'note'  => 'Mosha del nën 15 ose mbi 90 vjeç — ka gabim shtypi.',
  'href'  => $ROUTES['students'],
  'n'     => (int)($one($pdo, "
      SELECT COUNT(*) n FROM persons
      WHERE birth_date IS NOT NULL
        AND TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) NOT BETWEEN 15 AND 90
    ", ['n'=>0])['n']),
];
$hygiene[] = [
  'title' => 'Pa numër personal',
  'note'  => 'Identifikuesi bazë mungon në kartelë.',
  'href'  => $ROUTES['students'],
  'n'     => (int)($one($pdo, "
      SELECT COUNT(*) n FROM persons
      WHERE personal_number IS NULL OR personal_number = ''
    ", ['n'=>0])['n']),
];
$hygiene[] = [
  'title' => 'Grupe bosh',
  'note'  => 'Grupe pa asnjë kursant të caktuar.',
  'href'  => $ROUTES['groups'],
  'n'     => (int)($one($pdo, "
      SELECT COUNT(*) n FROM course_groups cg
      WHERE NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.group_id = cg.id)
    ", ['n'=>0])['n']),
];

/* ----- Prezantimi ----- */
$greetHour = (int)date('G');
$greeting  = $greetHour < 12 ? 'Mirëmëngjes' : ($greetHour < 18 ? 'Mirëdita' : 'Mirëmbrëma');
$whoShort  = trim((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Administrator')));

$months = ['01'=>'Jan','02'=>'Shk','03'=>'Mar','04'=>'Pri','05'=>'Maj','06'=>'Qer',
           '07'=>'Kor','08'=>'Gsh','09'=>'Sht','10'=>'Tet','11'=>'Nën','12'=>'Dhj'];

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
      <p class="title-block-note">Gjendja e regjistrit dhe çfarë tregojnë të dhënat.</p>
    </div>
    <div class="title-block-fields">
      <div class="title-block-field">
        <span class="label">Data</span>
        <span class="value"><?= h(date('d.m.Y')) ?></span>
      </div>
    </div>
  </div>

  <!-- ============================================================== SHIFRAT -->
  <section class="tally" aria-label="Gjendja e regjistrit">
    <div class="tally-cell">
      <span class="label">Në regjistër</span>
      <span class="tally-value"><?= number_format((int)$k['students']) ?></span>
      <span class="tally-foot">kursantë</span>
    </div>
    <div class="tally-cell">
      <span class="label">Grupe</span>
      <span class="tally-value"><?= number_format((int)$k['groups']) ?></span>
      <span class="tally-foot">mes. <?= h((string)($profile['avg_group'] ?? '—')) ?> kursantë</span>
    </div>
    <div class="tally-cell">
      <span class="label">Module në përdorim</span>
      <span class="tally-value"><?= number_format((int)$k['modules_used']) ?></span>
      <span class="tally-foot">nga <?= number_format((int)$k['modules_all']) ?> gjithsej</span>
    </div>
    <div class="tally-cell">
      <span class="label">Nota mesatare</span>
      <span class="tally-value"><?= $k['avg_score'] !== null ? h((string)$k['avg_score']) : '—' ?></span>
      <span class="tally-foot"><?= number_format((int)$k['scored']) ?> të vlerësuar</span>
    </div>
  </section>

  <!-- ================================================================ RITMI -->
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
        <?php foreach ($rhythm as $r):
          $mm = substr((string)$r['ym'], 5, 2); ?>
          <span><?= h($months[$mm] ?? $mm) ?></span>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="row g-4 g-xl-5">

    <!-- ============ KOLONA E MAJTË: listat e gjata ============================ -->
    <div class="col-12 col-xl-7">

      <!-- ------------------------------------------ Zanatet më të kërkuara -->
      <section class="mb-5" aria-labelledby="modTitle">
        <div class="plate-head">
          <h2 id="modTitle">Zanatet më të kërkuara</h2>
          <a class="label" href="<?= h($ROUTES['courses']) ?>">Të gjitha →</a>
        </div>

        <?php if ($topModules): ?>
          <div class="rank">
            <?php foreach ($topModules as $m):
              $n = (int)$m['n'];
              $pct = $topMax > 0 ? round($n / $topMax * 100) : 0;
              $share = $enrolled > 0 ? round($n / $enrolled * 100) : 0; ?>
              <a class="rank-row" href="<?= h($ROUTES['courses']) ?>"
                 title="<?= h((string)$m['name']) ?> — <?= $n ?> kursantë (<?= $share ?>% e regjistrimeve)">
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

      <!-- ------------------------------------------------------- Origjina -->
      <section aria-labelledby="originTitle">
        <div class="plate-head">
          <h2 id="originTitle">Nga vijnë</h2>
          <span class="label">Vendlindja</span>
        </div>

        <?php if ($origins): ?>
          <div class="rank">
            <?php foreach ($origins as $o):
              $n = (int)$o['n'];
              $pct = $originMax > 0 ? round($n / $originMax * 100) : 0; ?>
              <div class="rank-row rank-row-2col">
                <span class="rank-name"><?= h((string)$o['birth_place']) ?></span>
                <span class="rank-n"><?= $n ?></span>
                <span class="rank-track"><span class="rank-bar" style="width:<?= $pct ?>%"></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="blank"><span class="blank-note">Pa të dhëna vendlindjeje.</span></div>
        <?php endif; ?>
      </section>
    </div>

    <!-- ============ KOLONA E DJATHTË: blloqe kompakte ======================== -->
    <div class="col-12 col-xl-5">

      <!-- ------------------------------------------------------ Vlerësimi -->
      <section class="mb-5" aria-labelledby="scoreTitle">
        <div class="plate-head">
          <h2 id="scoreTitle">Vlerësimi</h2>
          <span class="label"><?= number_format((int)$k['scored']) ?> nga <?= number_format($enrolled) ?></span>
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
              <dt>Nota mesatare</dt>
              <dd><?= h((string)($k['avg_score'] ?? '—')) ?></dd>
            </div>
            <div class="datarow">
              <dt>Ende pa notë</dt>
              <dd><?= number_format(max(0, $enrolled - (int)$k['scored'])) ?></dd>
            </div>
          </dl>
        <?php else: ?>
          <div class="blank"><span class="blank-note">Ende asnjë provim i vlerësuar.</span></div>
        <?php endif; ?>
      </section>

      <!-- --------------------------------------------------------- Profili -->
      <section class="mb-5" aria-labelledby="profileTitle">
        <div class="plate-head">
          <h2 id="profileTitle">Profili</h2>
          <span class="label">Kursanti tipik</span>
        </div>

        <dl style="margin:0 0 1.25rem">
          <div class="datarow">
            <dt>Mosha mesatare</dt>
            <dd><?= $profile['avg_age'] !== null ? h((string)$profile['avg_age']) . ' vjeç' : '—' ?></dd>
          </div>
          <div class="datarow">
            <dt>Kursantë për grup</dt>
            <dd><?= h((string)($profile['avg_group'] ?? '—')) ?></dd>
          </div>
        </dl>

        <?php if ($schooling && $schoolTotal > 0): ?>
          <span class="label" style="margin-bottom:.5rem">Arsimi</span>
          <div class="split">
            <?php foreach ($schooling as $s): ?>
              <span class="split-part" style="width:<?= round((int)$s['n'] / $schoolTotal * 100, 2) ?>%"></span>
            <?php endforeach; ?>
          </div>
          <div class="split-key">
            <?php foreach ($schooling as $s): ?>
              <span class="split-key-item"><?= h((string)$s['label']) ?> <b><?= number_format((int)$s['n']) ?></b></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($genders && $genderTotal > 0): ?>
          <span class="label" style="display:block;margin:1.25rem 0 .5rem">Gjinia</span>
          <div class="split">
            <?php foreach ($genders as $g): ?>
              <span class="split-part" style="width:<?= round((int)$g['n'] / $genderTotal * 100, 2) ?>%"></span>
            <?php endforeach; ?>
          </div>
          <div class="split-key">
            <?php foreach ($genders as $g): ?>
              <span class="split-key-item"><?= h((string)$g['label']) ?> <b><?= number_format((int)$g['n']) ?></b></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- ------------------------------------------------------ Aktiviteti -->
      <section aria-labelledby="auditTitle">
        <div class="plate-head">
          <h2 id="auditTitle">Aktiviteti</h2>
          <a class="label" href="<?= h($ROUTES['logs']) ?>">Auditimi →</a>
        </div>

        <?php if ($actions && $actionTotal > 0): ?>
          <div class="split">
            <?php foreach ($actions as $a): ?>
              <span class="split-part" style="width:<?= round((int)$a['n'] / $actionTotal * 100, 2) ?>%"></span>
            <?php endforeach; ?>
          </div>
          <div class="split-key">
            <?php
            $actionWords = ['INSERT' => 'Shtim', 'UPDATE' => 'Ndryshim', 'DELETE' => 'Fshirje'];
            foreach ($actions as $a):
              $w = $actionWords[strtoupper((string)$a['action'])] ?? (string)$a['action']; ?>
              <span class="split-key-item"><?= h($w) ?> <b><?= number_format((int)$a['n']) ?></b></span>
            <?php endforeach; ?>
          </div>

          <dl style="margin:1.25rem 0 0">
            <div class="datarow">
              <dt>Veprime gjithsej</dt>
              <dd><?= number_format($actionTotal) ?></dd>
            </div>
          </dl>
        <?php else: ?>
          <div class="blank"><span class="blank-note">Ende asnjë veprim i audituar.</span></div>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <!-- ================================================ TË DHËNA QË DUHEN PARË -->
  <?php $hygieneOpen = array_filter($hygiene, static fn($r) => $r['n'] > 0); ?>
  <section class="mt-5" aria-labelledby="hygieneTitle">
    <div class="plate-head">
      <h2 id="hygieneTitle">Të dhëna që duhen parë</h2>
      <span class="label"><?= count($hygieneOpen) ?> nga <?= count($hygiene) ?> kontrolle</span>
    </div>

    <?php if ($hygieneOpen): ?>
      <div class="action-list">
        <?php $i = 0; foreach ($hygieneOpen as $row): $i++; ?>
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

  <!-- ============================================================ SHKURTORET -->
  <section class="mt-5" aria-labelledby="shortcutsTitle">
    <div class="plate-head">
      <h2 id="shortcutsTitle">Shko te</h2>
    </div>

    <div class="row g-2">
      <?php
      $shortcuts = [
        ['href' => $ROUTES['students'], 'title' => 'Kursantët',   'note' => 'Regjistrimi dhe të dhënat'],
        ['href' => $ROUTES['groups'],   'title' => 'Grupet',      'note' => 'Caktimi, provimet, mbyllja'],
        ['href' => $ROUTES['courses'],  'title' => 'Modulet',     'note' => 'Zanatet dhe orët'],
        ['href' => $ROUTES['agencies'], 'title' => 'Agjencitë',   'note' => 'Kompanitë dhe punonjësit'],
        ['href' => $ROUTES['logs'],     'title' => 'Auditimi',    'note' => 'Kush ndryshoi çfarë'],
        ['href' => 'users.php',         'title' => 'Përdoruesit', 'note' => 'Aksesi dhe rolet'],
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
