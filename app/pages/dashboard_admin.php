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
   Fleta e punës — vetëm çfarë duhet për të nisur punën.
   Asnjë shifër dekorative: kërkim, veprime, ajo që pret, dhe ku e le.
   ========================================================================= */

$one = static function (PDO $pdo, string $sql, array $fallback = []) {
  try { $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r !== false ? $r : $fallback; }
  catch (Throwable $e) { return $fallback; }
};
$many = static function (PDO $pdo, string $sql): array {
  try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
  catch (Throwable $e) { return []; }
};

/* Çfarë pret dorën e dikujt. Vetëm zërat me numër > 0 shfaqen. */
$checks = [
  ['Kursantë pa grup', 'Presin të caktohen në një grup.', 'students_without_groups.php', 'Cakto tani',
   "SELECT COUNT(*) n FROM students s WHERE NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.student_id = s.id)"],
  ['Kartela pa numër personal', 'Identifikuesi bazë mungon.', 'students.php', 'Plotëso',
   "SELECT COUNT(*) n FROM persons WHERE personal_number IS NULL OR personal_number = ''"],
  ['Grupe bosh', 'Grupe pa asnjë kursant.', 'groups.php', 'Shiko grupet',
   "SELECT COUNT(*) n FROM course_groups cg WHERE NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.group_id = cg.id)"],
  ['Datëlindje të pamundura', 'Mosha del nën 15 ose mbi 90 vjeç.', 'students.php', 'Ndreq',
   "SELECT COUNT(*) n FROM persons WHERE birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) NOT BETWEEN 15 AND 90"],
];
$waiting = [];
foreach ($checks as $c) {
  $n = (int)($one($pdo, $c[4], ['n' => 0])['n']);
  if ($n > 0) { $waiting[] = ['title' => $c[0], 'note' => $c[1], 'href' => $c[2], 'cta' => $c[3], 'n' => $n]; }
}

/* Të fundit që hynë në regjistër — për t'u kthyer shpejt te dikush. */
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
$whoShort  = trim((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? 'Administrator')));

$agoText = static function (?string $ts): string {
  if (!$ts) return '';
  $d = time() - (strtotime($ts) ?: time());
  if ($d < 3600)   return max(1, (int)($d / 60)) . ' min';
  if ($d < 86400)  return (int)($d / 3600) . ' orë';
  if ($d < 2592000) return (int)($d / 86400) . ' ditë';
  return date('d.m.Y', strtotime($ts));
};

$NAV_ACTIVE = 'dashboard';
$pageTitle  = 'Fleta e punës – Regjistri QTA';

require __DIR__ . '/../shared/app_head.php';
require __DIR__ . '/inc/navbar.php';
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

  <!-- ====================================================== KËRKIMI I SHPEJTË -->
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

  <!-- ============================================================ VEPRIMET -->
  <section class="mb-5" aria-labelledby="actTitle">
    <div class="plate-head">
      <h2 id="actTitle">Nis një punë</h2>
    </div>

    <div class="actions">
      <?php
      $acts = [
        ['register.php',                 'bi-person-plus',   'Regjistro kursant', 'Shto një person të ri në regjistër', true],
        ['groups.php',                   'bi-collection',    'Grupet',            'Krijo grup, cakto data, mbyll',      true],
        ['students_without_groups.php',  'bi-people',        'Cakto në grup',     'Kush pret dhe ku mund të shkojë',    false],
        ['students.php',                 'bi-table',         'Kursantët',         'Të gjithë, me kërkim dhe filtra',    false],
        ['courses.php',                  'bi-journal-text',  'Modulet',           'Zanatet dhe orët',                   false],
        ['verify.php',                   'bi-patch-check',   'Verifiko',          'Kontrollo një certifikatë',          false],
      ];
      foreach ($acts as $a): ?>
        <a class="act<?= $a[4] ? ' act-primary' : '' ?>" href="<?= h($a[0]) ?>">
          <i class="bi <?= h($a[1]) ?>" aria-hidden="true"></i>
          <b><?= h($a[2]) ?></b>
          <span><?= h($a[3]) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ========================================================= PRET PËR TY -->
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
            <span class="wait-main">
              <b><?= h($w['title']) ?></b>
              <span><?= h($w['note']) ?></span>
            </span>
            <span class="wait-go"><?= h($w['cta']) ?> →</span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="row g-4 g-xl-5">

    <!-- ======================================================== TË FUNDIT -->
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

    <!-- ======================================================= DOKUMENTET -->
    <div class="col-12 col-xl-5">
      <section aria-labelledby="docTitle">
        <div class="plate-head">
          <h2 id="docTitle">Dokumentet</h2>
          <span class="label">Gjenerohen nga grupi</span>
        </div>
        <p class="muted mb-3" style="font-size:var(--fs-sm)">
          Këto akte lëshohen për një grup të caktuar. Hap grupin dhe zgjidh dokumentin që të duhet.
        </p>
        <div class="docs">
          <?php
          $docs = [
            'Lista emërore',
            'Proces verbal',
            'Praktika profesionale',
            'Rregullat e sigurimit teknik',
          ];
          foreach ($docs as $d): ?>
            <a class="doc" href="groups.php">
              <i class="bi bi-file-earmark-text" aria-hidden="true"></i><?= h($d) ?>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="plate-head mt-5">
          <h2>Kontrolli</h2>
        </div>
        <div class="docs">
          <a class="doc" href="logs.php"><i class="bi bi-clock-history" aria-hidden="true"></i>Kush ndryshoi çfarë</a>
          <a class="doc" href="users.php"><i class="bi bi-shield-lock" aria-hidden="true"></i>Aksesi dhe rolet</a>
          <a class="doc" href="agencies.php"><i class="bi bi-building" aria-hidden="true"></i>Agjencitë</a>
        </div>
      </section>
    </div>
  </div>

</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
