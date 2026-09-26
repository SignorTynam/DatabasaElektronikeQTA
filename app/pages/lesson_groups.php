<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ------------------------------
   Guard: admin/editor i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id = u.role_id
  WHERE u.id = :id LIMIT 1
");
$u->execute([':id' => $_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($currentUser['role_name'] ?? ''));
if (!$currentUser || !in_array($role, ['administrator', 'editor'], true)) {
  header('Location: selectProfile.php'); exit;
}

/* Kyçi i ndryshimeve */
if (isset($_GET['edit'])) {
  $_SESSION['edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
  $qs = $_GET; unset($qs['edit']);
  header('Location: lesson_groups.php' . ($qs ? '?' . http_build_query($qs) : ''));
  exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

require_once __DIR__ . '/../shared/themeli.php';
require_once __DIR__ . '/../shared/lesson_groups.php';

/* =========================
   POST: krijo grup(e) me orar
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_group') {
  $form = [
    'course_id' => (string)($_POST['course_id'] ?? ''),
    'start_date' => (string)($_POST['start_date'] ?? ''),
    'daily_hours' => (string)($_POST['daily_hours'] ?? ''),
    'amze_spec' => (string)($_POST['amze_spec'] ?? ''),
  ];
  try {
    if (empty($_POST['csrf']) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf'])) {
      throw new QtaUserError('Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.');
    }
    if (!$EDIT_MODE) {
      throw new QtaUserError('Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.');
    }
    $res = qta_lg_create($pdo, $form);
    $s = $res['summary'];
    $when = qta_sched_on_label($s['end_date']);
    if (count($res['groups']) === 1) {
      $g0 = $res['groups'][0];
      $_SESSION['flash_ok'] = 'Grupi #' . $g0['group_id'] . ' u krijua' . ($g0['count'] ? ' me ' . $g0['count'] . ' kursantë' : '')
        . '. Orari mbaron ' . $when . ', pas ' . qta_plural((int)$s['days'], 'dite', 'ditësh') . ' mësimi.';
      header('Location: lesson_group.php?id=' . (int)$g0['group_id']);
      exit;
    }
    $parts = array_map(static fn($g) => '#' . $g['group_id'] . ' (' . $g['count'] . ', amza ' . $g['amze_min'] . '–' . $g['amze_max'] . ')', $res['groups']);
    $_SESSION['flash_ok'] = 'U krijuan ' . count($res['groups']) . ' grupe me të njëjtin orar: ' . implode(', ', $parts) . '. Mbarojnë ' . $when . '.';
    header('Location: lesson_groups.php');
    exit;
  } catch (QtaUserError $e) {
    $_SESSION['lg_create_form'] = $form + ['error' => $e->getMessage()];
  } catch (Throwable $e) {
    $ref = bin2hex(random_bytes(4));
    error_log('[QTA ' . $ref . '] krijimi i grupit: ' . $e->getMessage());
    $_SESSION['lg_create_form'] = $form + ['error' => 'Grupi nuk u krijua për shkak të një gabimi të papritur. Asgjë nuk u ruajt. Provo sërish (referenca ' . $ref . ').'];
  }
  header('Location: lesson_groups.php?create=1');
  exit;
}

/* ------------------------------
   Filtrat
------------------------------- */
$q = trim((string)($_GET['q'] ?? ''));
$courseFilter = trim((string)($_GET['course_id'] ?? ''));
$courseFilterId = ($courseFilter !== '' && ctype_digit($courseFilter)) ? (int)$courseFilter : 0;

$params = [];
$w = ["cg.model = 'scheduled'"];
if ($q !== '') {
  $w[] = "cg.id IN (
            SELECT cgs2.group_id FROM course_group_students cgs2
            JOIN students s2 ON s2.id = cgs2.student_id
            LEFT JOIN persons p2 ON p2.id = s2.person_id
            WHERE s2.nr_amze LIKE :kw OR p2.personal_number LIKE :kw2 OR p2.first_name LIKE :kw3
               OR p2.father_name LIKE :kw4 OR p2.last_name LIKE :kw5)";
  foreach ([':kw', ':kw2', ':kw3', ':kw4', ':kw5'] as $k) $params[$k] = '%' . $q . '%';
}
if ($courseFilterId > 0) {
  $w[] = 'cg.course_id = :cf';
  $params[':cf'] = $courseFilterId;
}
$st = $pdo->prepare("
  SELECT cg.id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed,
         c.name AS course_name, c.code AS course_code,
         gs.daily_hours, gs.course_hours, gs.teaching_days,
         COUNT(cgs.student_id) AS members,
         COALESCE(SUM(cgs.final_score IS NOT NULL), 0) AS scored,
         MIN(CAST(s.nr_amze AS UNSIGNED)) AS amze_min,
         MAX(CAST(s.nr_amze AS UNSIGNED)) AS amze_max
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  JOIN group_schedules gs ON gs.group_id = cg.id
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  LEFT JOIN students s ON s.id = cgs.student_id
  WHERE " . implode(' AND ', $w) . "
  GROUP BY cg.id
  ORDER BY cg.start_date DESC, cg.id DESC
");
$st->execute($params);
$groups = $st->fetchAll(PDO::FETCH_ASSOC);

/* Mësimi i sotëm për grupet në vazhdim (një query për të gjitha) */
$today = date('Y-m-d');
$todayLessons = [];
if ($groups) {
  $ids = array_map(static fn($g) => (int)$g['id'], $groups);
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $tl = $pdo->prepare("
    SELECT d.group_id, d.hours, t.module_title, t.topic_seq, t.topic_title
    FROM group_schedule_days d
    JOIN group_schedule_slots s ON s.group_id = d.group_id AND s.day_seq = d.day_seq
    JOIN group_schedule_topics t ON t.group_id = s.group_id AND t.seq = s.topic_seq
    WHERE d.lesson_date = ? AND d.group_id IN ($ph)
    ORDER BY d.group_id, s.slot_seq
  ");
  $tl->execute(array_merge([$today], $ids));
  foreach ($tl->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gid = (int)$r['group_id'];
    if (!isset($todayLessons[$gid])) $todayLessons[$gid] = ['hours' => (int)$r['hours'], 'first' => $r];
  }
}

/* Kurset për krijimin: vetëm kurset "gati" mund të zgjidhen */
$courses = $pdo->query('SELECT id, code, name, hours FROM courses ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$summaries = qta_course_summaries($pdo);
$readyCount = 0;
foreach ($courses as $c) if (!empty($summaries[(int)$c['id']]['ready'])) $readyCount++;
$notReadyCount = count($courses) - $readyCount;
$legacyCount = (int)$pdo->query("SELECT COUNT(*) FROM course_groups WHERE model = 'legacy'")->fetchColumn();

/* Formulari i krijimit pas një gabimi */
$createForm = $_SESSION['lg_create_form'] ?? null;
unset($_SESSION['lg_create_form']);
$openCreate = $EDIT_MODE && (isset($_GET['create']) || $createForm !== null);
$prefCourse = (int)($createForm['course_id'] ?? ($openCreate ? $courseFilterId : 0));

$flash_ok = $_SESSION['flash_ok'] ?? null; unset($_SESSION['flash_ok']);
$flash_err = $_SESSION['flash_err'] ?? null; unset($_SESSION['flash_err']);

$NAV_ACTIVE = 'lesson_groups';
$HELP_TOPIC = 'lesson_groups';
if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

$hasFilters = ($q !== '' || $courseFilterId > 0);
$createHref = 'lesson_groups.php?' . http_build_query(array_filter(['edit' => '1', 'create' => '1', 'course_id' => $courseFilterId ?: null]));
$pageTitle = 'Grupet';
$pageScripts = [qta_asset('app/assets/js/lesson-groups.js')];
require __DIR__ . '/../shared/app_head.php';

$groupStatus = static function (array $g) use ($today): string {
  if ((int)$g['is_completed'] === 1) return qta_status('I mbyllur', 'success', 'bi-lock-fill');
  if ((string)$g['start_date'] > $today) return qta_status('Nis ' . qta_when_label((string)$g['start_date']), 'info', 'bi-calendar-event');
  if ((string)$g['end_date'] >= $today) return qta_status('Në mësim', 'accent', 'bi-easel');
  return qta_status('Pret mbylljen', 'warning', 'bi-hourglass-split');
};
$scoredLabel = static function (int $scored, int $total): string {
  if ($scored === 0) return 'ende pa pikë';
  return $scored === $total ? 'të gjithë me pikë' : $scored . ' me pikë';
};
?>

<main class="app-main is-wide" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Grupet</h1>
      <p class="page-lead">Çdo grup ndjek një kurs me orar mësimi ditë pas dite. Data e mbarimit llogaritet vetë nga orët e kursit, orët në ditë dhe ditët pa mësim.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <button class="btn btn-secondary" type="button" data-bs-toggle="modal" data-bs-target="#qklReportModal">
        <i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>Raporti për QKL
      </button>
      <?php if ($EDIT_MODE): ?>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createLessonGroup">
          <i class="bi bi-plus-lg" aria-hidden="true"></i>Krijo grup
        </button>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= h($createHref) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i>Krijo grup</a>
      <?php endif; ?>
    </div>
  </header>

  <form class="filters" method="get" action="lesson_groups.php" role="search" aria-label="Kërko grupe">
    <div class="filter-field is-grow">
      <label class="form-label" for="lgQ">Kërko një kursant</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="lgQ" type="search" name="q" value="<?= h($q) ?>" placeholder="Emri, numri personal ose nr. i amzës">
      </div>
    </div>
    <div class="filter-field">
      <label class="form-label" for="lgCourse">Kursi</label>
      <select class="form-select" id="lgCourse" name="course_id">
        <option value="">Të gjitha kurset</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $courseFilterId === (int)$c['id'] ? 'selected' : '' ?>><?= h((string)$c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-actions">
      <?php if ($hasFilters): ?>
        <a class="btn btn-ghost" href="lesson_groups.php"><i class="bi bi-x-lg" aria-hidden="true"></i>Pastro kërkimin</a>
      <?php endif; ?>
      <button class="btn btn-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Kërko</button>
    </div>
  </form>

  <?php if ($legacyCount > 0): ?>
    <div class="notice mb-3">
      <i class="bi bi-archive" aria-hidden="true"></i>
      <span><b>Grupet e krijuara para orarit të mësimit</b> (<?= h(qta_plural($legacyCount, 'grup', 'grupe')) ?>) janë te
        <a href="groups.php">Grupet e mëparshme</a>. Ato mbeten siç ishin, pa orar ditë pas dite.</span>
    </div>
  <?php endif; ?>
  <?php if ($notReadyCount > 0): ?>
    <div class="notice mb-3">
      <i class="bi bi-diagram-3" aria-hidden="true"></i>
      <span><b><?= h(qta_plural($notReadyCount, 'kurs nuk është', 'kurse nuk janë')) ?> ende gati për grupe me orar</b>, sepse u mungojnë modulet ose temat me orët e plota.
        <a href="courses.php">Plotëso kurset</a>.</span>
    </div>
  <?php endif; ?>

  <?php require __DIR__ . '/../shared/partials/edit_mode_off_banner.php'; ?>

  <section class="section" aria-labelledby="lgTitle">
    <div class="section-head">
      <h2 class="section-title" id="lgTitle">
        <?= $hasFilters ? 'Grupet që përputhen' : 'Të gjitha grupet' ?>
        <span class="count"><?= number_format(count($groups), 0, ',', '.') ?></span>
      </h2>
      <?php if ($groups): ?><span class="section-meta">Hap një grup për orarin, kursantët, provimet dhe dokumentet.</span><?php endif; ?>
    </div>

    <?php if ($groups): ?>
      <?php
        $tfTarget = '#lessonGroupsTable';
        $tfPlaceholder = 'Filtro — kurs, amzë ose datë';
        $tfChips = [['label' => 'Në mësim', 'match' => 'Në mësim'], ['label' => 'Nisin së shpejti', 'match' => 'Nis '], ['label' => 'Presin mbylljen', 'match' => 'Pret mbylljen'], ['label' => 'Të mbyllura', 'match' => 'I mbyllur']];
        $tfNoun = 'grupe';
        require __DIR__ . '/../shared/partials/table_filter.php';
      ?>
      <div class="table-responsive">
        <table class="table" id="lessonGroupsTable" data-sortable>
          <thead>
            <tr>
              <th scope="col" class="col-medium" data-sort="text">Kursi</th>
              <th scope="col" class="nowrap" data-sort="num">Nr. i amzës</th>
              <th scope="col" class="nowrap" data-sort="date">Fillimi</th>
              <th scope="col" class="nowrap" data-sort="date">Mbarimi</th>
              <th scope="col" class="nowrap" data-sort="num">Orari</th>
              <th scope="col" class="nowrap num-col" data-sort="num">Kursantë</th>
              <th scope="col" data-sort="text">Gjendja</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $g):
              $gid = (int)$g['id'];
              $n = (int)$g['members'];
              $amze = $g['amze_min'] === null ? '—' : ((string)$g['amze_min'] . ((string)$g['amze_max'] !== (string)$g['amze_min'] ? '–' . $g['amze_max'] : ''));
              $tl = $todayLessons[$gid] ?? null;
            ?>
              <tr>
                <td class="col-medium">
                  <a class="row-open" href="lesson_group.php?id=<?= $gid ?>">
                    <span>
                      <span class="person-name"><?= h((string)$g['course_name']) ?></span>
                      <span class="cell-sub">Grupi #<?= $gid ?></span>
                    </span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                  </a>
                </td>
                <td class="nowrap" data-sort-value="<?= (int)($g['amze_min'] ?? 0) ?>"><span class="id-code"><?= h($amze) ?></span></td>
                <td class="nowrap"><?= h(qta_date((string)$g['start_date'])) ?></td>
                <td class="nowrap"><?= h(qta_date((string)$g['end_date'])) ?></td>
                <td class="nowrap" data-sort-value="<?= (int)$g['course_hours'] ?>">
                  <?= h(qta_hours_label((int)$g['course_hours'])) ?> · <?= (int)$g['daily_hours'] ?> në ditë
                  <span class="cell-sub"><?= h(qta_plural((int)$g['teaching_days'], 'ditë mësimi', 'ditë mësimi')) ?></span>
                </td>
                <td class="nowrap num-col" data-sort-value="<?= $n ?>">
                  <?= $n ?><span class="text-subtle">/10</span>
                  <?php if ($n > 0): ?><span class="cell-sub<?= (int)$g['scored'] < $n ? ' text-warning' : '' ?>"><?= h($scoredLabel((int)$g['scored'], $n)) ?></span><?php endif; ?>
                </td>
                <td>
                  <?= $groupStatus($g) ?>
                  <?php if ($tl): ?>
                    <span class="cell-sub">Sot: <?= h((string)$tl['first']['module_title']) ?> · <?= h((string)$tl['first']['topic_title']) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <?= $hasFilters
        ? qta_empty('Asnjë grup nuk përputhet', 'Provo një kurs tjetër ose pastro kërkimin.', 'bi-search', '<a class="btn btn-secondary" href="lesson_groups.php">Pastro kërkimin</a>')
        : qta_empty('Ende pa grupe me orar',
            $readyCount > 0
              ? 'Krijo grupin e parë: zgjidh kursin, datën e fillimit dhe orët në ditë — orari ndërtohet vetë.'
              : 'Së pari plotëso një kurs me module dhe tema te "Kurset"; pastaj krijo grupin.',
            'bi-calendar-week',
            $readyCount > 0 ? '<a class="btn btn-primary" href="' . h($createHref) . '">Krijo grup</a>' : '<a class="btn btn-primary" href="courses.php">Te kurset</a>') ?>
    <?php endif; ?>
  </section>
</main>

<?php require __DIR__ . '/../shared/partials/qkl_report_modal.php'; ?>

<!-- Dialog: krijo grup me orar -->
<div class="modal fade" id="createLessonGroup" tabindex="-1" aria-labelledby="createLessonGroupTitle" aria-hidden="true"<?= $openCreate ? ' data-open-on-load="create"' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content" method="post" action="lesson_groups.php" data-lg-create data-loading novalidate>
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="create_group">
      <div class="modal-header">
        <h2 class="modal-title" id="createLessonGroupTitle"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Krijo një grup me orar</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <?php if (!empty($createForm['error'])): ?>
          <div class="alert alert-danger" role="alert"><i class="bi bi-x-circle" aria-hidden="true"></i><div><b>Grupi nuk u krijua.</b> <?= h((string)$createForm['error']) ?></div></div>
        <?php endif; ?>
        <?php if ($readyCount === 0): ?>
          <div class="alert alert-warning" role="note"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div><b>Asnjë kurs nuk është ende gati.</b> Një grup me orar ka nevojë për një kurs me module dhe tema, orët e të cilave mblidhen saktë. <a href="courses.php">Plotëso një kurs</a>.</div>
          </div>
        <?php endif; ?>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="lgcCourse">Kursi <span class="req" aria-hidden="true">*</span></label>
            <select class="form-select" id="lgcCourse" name="course_id" required aria-describedby="lgcCourseHelp" <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <option value="">— Zgjidh kursin —</option>
              <?php foreach ($courses as $c):
                $cid = (int)$c['id'];
                $ready = !empty($summaries[$cid]['ready']); ?>
                <option value="<?= $cid ?>" <?= $ready ? '' : 'disabled' ?> <?= $prefCourse === $cid && $ready ? 'selected' : '' ?>>
                  <?= h((string)$c['name']) ?> · <?= h(qta_hours_label((int)$c['hours'])) ?><?= $ready ? '' : ' — jo gati' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" id="lgcCourseHelp">Kurset "jo gati" nuk kanë ende module dhe tema me orët e plota. <a href="courses.php">Plotësoji te Kurset</a>.</div>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="lgcStart">Data e fillimit <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="lgcStart" name="start_date" type="text" inputmode="numeric" autocomplete="off" placeholder="dd.mm.vvvv"
                   required data-dmy value="<?= h((string)($createForm['start_date'] ?? '')) ?>" aria-describedby="lgcStartHelp" <?= $EDIT_MODE ? '' : 'disabled' ?>>
            <div class="form-text" id="lgcStartHelp">Dita e parë e mësimit, p.sh. 01.10.2026. Nuk mund të jetë e diel.</div>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="lgcDaily">Orë mësimi në ditë <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="lgcDaily" name="daily_hours" type="number" min="1" max="<?= QTA_DAY_MAX_HOURS ?>" step="1" inputmode="numeric"
                   placeholder="p.sh. 5" required value="<?= h((string)($createForm['daily_hours'] ?? '')) ?>" aria-describedby="lgcDailyHelp" <?= $EDIT_MODE ? '' : 'disabled' ?>>
            <div class="form-text" id="lgcDailyHelp">Nga e hëna në të shtunë. Ditë me orë të tjera i shton më pas te grupi.</div>
          </div>
          <div class="col-12">
            <div class="plan-preview" data-lg-preview role="status" aria-live="polite">
              <i class="bi bi-calendar-range" aria-hidden="true"></i>
              <span data-lg-preview-text>Zgjidh kursin, datën e fillimit dhe orët në ditë: këtu del kur mbaron mësimi.</span>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label" for="lgcAmze">Numrat e amzës së kursantëve <span class="optional">(mund t'i shtosh edhe më vonë)</span></label>
            <textarea class="form-control input-code" id="lgcAmze" name="amze_spec" rows="2" placeholder="p.sh. 3400-3403, 3409" aria-describedby="lgcAmzeHelp" <?= $EDIT_MODE ? '' : 'disabled' ?>><?= h((string)($createForm['amze_spec'] ?? '')) ?></textarea>
            <div class="form-text" id="lgcAmzeHelp">Numra të ndarë me presje ose intervale me vizë. Mbi 10 kursantë krijohen disa grupe të barabarta me të njëjtin orar — të tregohet si para se të ruhen.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit" <?= $EDIT_MODE && $readyCount > 0 ? '' : 'disabled' ?>><i class="bi bi-check-lg" aria-hidden="true"></i>Krijo grupin</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php require __DIR__ . '/../shared/partials/download_generation_toast.php'; ?>
<script type="application/json" id="lgConfig"><?= json_encode([
  'csrf' => $CSRF, 'endpoint' => 'lesson_group_update.php', 'edit' => $EDIT_MODE,
  'flash_ok' => $flash_ok, 'flash_err' => $flash_err,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</body>
</html>
