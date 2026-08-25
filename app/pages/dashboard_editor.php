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
   Fleta e punës e editorit — vegël pune, jo raport.
   ========================================================================= */

$one = static function (PDO $pdo, string $sql, array $fallback = []) {
  try { $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r !== false ? $r : $fallback; }
  catch (Throwable $e) { return $fallback; }
};
$many = static function (PDO $pdo, string $sql): array {
  try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
  catch (Throwable $e) { return []; }
};

$checks = [
  ['Kursantë pa grup', 'Presin të caktohen në një grup.', 'students_without_groups.php', 'Cakto tani',
   "SELECT COUNT(*) n FROM students s WHERE NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.student_id = s.id)"],
  ['Kartela pa numër personal', 'Identifikuesi bazë mungon.', 'students.php', 'Plotëso',
   "SELECT COUNT(*) n FROM persons WHERE personal_number IS NULL OR personal_number = ''"],
  ['Grupe bosh', 'Grupe pa asnjë kursant.', 'groups.php', 'Shiko grupet',
   "SELECT COUNT(*) n FROM course_groups cg WHERE NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.group_id = cg.id)"],
];
$waiting = [];
foreach ($checks as $c) {
  $n = (int)($one($pdo, $c[4], ['n' => 0])['n']);
  if ($n > 0) { $waiting[] = ['title' => $c[0], 'note' => $c[1], 'href' => $c[2], 'cta' => $c[3], 'n' => $n]; }
}

$recent = $many($pdo, "
  SELECT s.id, s.nr_amze, s.created_at,
         TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) AS full_name
  FROM students s
  LEFT JOIN persons p ON p.id = s.person_id
  ORDER BY s.created_at DESC, s.id DESC
  LIMIT 8
");

$greetHour = (int)date('G');
$greeting  = $greetHour < 12 ? 'Mirëmëngjes' : ($greetHour < 18 ? 'Mirëdita' : 'Mirëmbrëma');
$whoShort  = trim((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Editor')));

$agoText = static function (?string $ts): string {
  if (!$ts) return '';
  $d = time() - (strtotime($ts) ?: time());
  if ($d < 3600)    return max(1, (int)($d / 60)) . ' min';
  if ($d < 86400)   return (int)($d / 3600) . ' orë';
  if ($d < 2592000) return (int)($d / 86400) . ' ditë';
  return date('d.m.Y', strtotime($ts));
};

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
    </div>
    <div class="title-block-fields">
      <div class="title-block-field">
        <span class="label">Sot</span>
        <span class="value"><?= h(date('d.m.Y')) ?></span>
      </div>
    </div>
  </div>

  <section class="jump" aria-labelledby="jumpTitle">
    <h2 id="jumpTitle" class="visually-hidden">Gjej një kursant</h2>
    <form class="jump-form" method="get" action="students.php">
      <div class="jump-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="text" name="q" autofocus
               placeholder="Gjej kursant — emër, numër amze ose numër personal"
               aria-label="Gjej kursant">
      </div>
      <button class="btn btn-ink" type="submit">Kërko</button>
    </form>
    <p class="jump-hint">Shtyp <kbd>/</kbd> kudo në sistem për t'u kthyer te kërkimi.</p>
  </section>

  <section class="mb-5" aria-labelledby="actTitle">
    <div class="plate-head"><h2 id="actTitle">Nis një punë</h2></div>
    <div class="actions">
      <?php
      $acts = [
        ['register.php',                'bi-person-plus',  'Regjistro kursant', 'Shto një person të ri në regjistër', true],
        ['groups.php',                  'bi-collection',   'Grupet',            'Data, provime, mbyllje',             true],
        ['students_without_groups.php', 'bi-people',       'Cakto në grup',     'Kush pret dhe ku mund të shkojë',    false],
        ['students.php',                'bi-table',        'Kursantët',         'Të gjithë, me kërkim dhe filtra',    false],
        ['courses.php',                 'bi-journal-text', 'Modulet',           'Zanatet dhe orët',                   false],
        ['verify.php',                  'bi-patch-check',  'Verifiko',          'Kontrollo një certifikatë',          false],
      ];
      foreach ($acts as $a): ?>
        <a class="act<?= $a[4] ? ' act-primary' : '' ?>" href="<?= h($a[0]) ?>">
          <i class="bi <?= h($a[1]) ?>" aria-hidden="true"></i>
          <b><?= h($a[2]) ?></b><span><?= h($a[3]) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($waiting): ?>
    <section class="mb-5" aria-labelledby="waitTitle">
      <div class="plate-head">
        <h2 id="waitTitle">Pret për ty</h2>
        <span class="label"><?= count($waiting) ?> gjëra</span>
      </div>
      <div class="waiting">
        <?php foreach ($waiting as $w): ?>
          <a class="wait-row" href="<?= h($w['href']) ?>">
            <span class="wait-n"><?= number_format($w['n']) ?></span>
            <span class="wait-main"><b><?= h($w['title']) ?></b><span><?= h($w['note']) ?></span></span>
            <span class="wait-go"><?= h($w['cta']) ?> →</span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="row g-4 g-xl-5">
    <div class="col-12 col-xl-7">
      <?php if ($recent): ?>
        <section aria-labelledby="recTitle">
          <div class="plate-head">
            <h2 id="recTitle">Të fundit në regjistër</h2>
            <a class="label" href="students.php">Të gjithë →</a>
          </div>
          <div class="recent">
            <?php foreach ($recent as $r): ?>
              <a class="recent-row" href="student_card.php?q=<?= urlencode((string)$r['nr_amze']) ?>">
                <span class="code"><?= h((string)$r['nr_amze']) ?></span>
                <span class="person"><?= h((string)($r['full_name'] ?: '—')) ?></span>
                <span class="when"><?= h($agoText($r['created_at'] ?? null)) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <div class="col-12 col-xl-5">
      <section aria-labelledby="docTitle">
        <div class="plate-head">
          <h2 id="docTitle">Dokumentet</h2>
          <span class="label">Gjenerohen nga grupi</span>
        </div>
        <p class="muted mb-3" style="font-size:var(--fs-sm)">
          Këto akte lëshohen për një grup të caktuar. Hap grupin dhe zgjidh dokumentin.
        </p>
        <div class="docs">
          <?php foreach (['Lista emërore','Proces verbal','Praktika profesionale','Rregullat e sigurimit teknik'] as $d): ?>
            <a class="doc" href="groups.php"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><?= h($d) ?></a>
          <?php endforeach; ?>
        </div>

        <div class="plate-head mt-5"><h2>E imja</h2></div>
        <div class="docs">
          <a class="doc" href="logs_editor.php"><i class="bi bi-clock-history" aria-hidden="true"></i>Çfarë kam ndryshuar</a>
          <a class="doc" href="profile.php"><i class="bi bi-person" aria-hidden="true"></i>Profili im</a>
        </div>
      </section>
    </div>
  </div>

</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
