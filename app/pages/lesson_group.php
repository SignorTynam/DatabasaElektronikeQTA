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

if (isset($_GET['edit'])) {
  $_SESSION['edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
  $qs = $_GET; unset($qs['edit']);
  header('Location: lesson_group.php' . ($qs ? '?' . http_build_query($qs) : ''));
  exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

require_once __DIR__ . '/../shared/themeli.php';
require_once __DIR__ . '/../shared/lesson_groups.php';
require_once __DIR__ . '/../shared/partials/timetable.php';
require_once __DIR__ . '/../shared/partials/group_documents.php';

$gid = (int)($_GET['id'] ?? 0);
$g = $gid > 0 ? qta_lg_find($pdo, $gid) : null;
if ($g && $g['model'] !== 'scheduled') {
  /* Një grup i mëparshëm hapet te faqja e vet, pa orar. */
  header('Location: groups.php?group=' . $gid);
  exit;
}

$today = date('Y-m-d');
if ($g) {
  $topics = qta_lg_topics($pdo, $gid);
  $rules = qta_lg_rules($pdo, $gid);
  $days = qta_sched_annotate($topics, qta_lg_days($pdo, $gid));
  $windows = qta_sched_module_windows($topics, $days);
  $liveCourse = qta_course_find($pdo, (int)$g['course_id']);
  $liveModules = qta_course_modules($pdo, (int)$g['course_id']);
  $courseChanged = qta_lg_curriculum_changed($topics, $liveModules);
  $liveReady = $liveCourse ? qta_course_check($liveCourse, $liveModules)['ready'] : false;
  $started = (string)$g['start_date'] <= $today;
  $closed = (int)$g['is_completed'] === 1;
  $totalHours = array_sum(array_column($days, 'hours'));

  $ms = $pdo->prepare("
    SELECT s.id AS student_id, s.nr_amze, p.first_name, p.father_name, p.last_name, p.personal_number,
           TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age, el.label AS edu_label,
           cgs.exam_date, cgs.final_score
    FROM course_group_students cgs
    JOIN students s ON s.id = cgs.student_id
    LEFT JOIN persons p ON p.id = s.person_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    WHERE cgs.group_id = ?
    ORDER BY CAST(s.nr_amze AS UNSIGNED), s.nr_amze
  ");
  $ms->execute([$gid]);
  $members = $ms->fetchAll(PDO::FETCH_ASSOC);
  $scored = count(array_filter($members, static fn($m) => $m['final_score'] !== null));
  $amzeList = implode(', ', array_map(static fn($m) => (string)(int)$m['nr_amze'], $members));

  /* Sot */
  $todayDay = null;
  $nextDay = null;
  foreach ($days as $d) {
    if ($d['date'] === $today) $todayDay = $d;
    if ($nextDay === null && $d['date'] > $today) $nextDay = $d;
  }
}

$flash_ok = $_SESSION['flash_ok'] ?? null; unset($_SESSION['flash_ok']);

$NAV_ACTIVE = 'lesson_groups';
$HELP_TOPIC = 'lesson_group';
if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

$pageTitle = $g ? 'Grupi #' . $gid . ' · ' . (string)$g['course_name'] : 'Grupi nuk u gjet';
$pageScripts = [qta_asset('app/assets/js/lesson-group.js')];
require __DIR__ . '/../shared/app_head.php';

$statusHtml = static function (array $g) use ($today): string {
  if ((int)$g['is_completed'] === 1) return qta_status('I mbyllur', 'success', 'bi-lock-fill');
  if ((string)$g['start_date'] > $today) return qta_status('Nis ' . qta_when_label((string)$g['start_date']), 'info', 'bi-calendar-event');
  if ((string)$g['end_date'] >= $today) return qta_status('Në mësim', 'accent', 'bi-easel');
  return qta_status('Pret mbylljen', 'warning', 'bi-hourglass-split');
};
?>

<main class="app-main" id="main" tabindex="-1">
<?php if (!$g): ?>
  <header class="page-head">
    <div class="page-head-main">
      <ol class="crumbs"><li><a href="lesson_groups.php">Grupet</a></li></ol>
      <h1 class="page-title">Grupi nuk u gjet</h1>
      <p class="page-lead">Ky grup nuk ekziston më ose adresa është e gabuar.</p>
    </div>
  </header>
  <?= qta_empty('Grupi nuk u gjet', 'Ndoshta u fshi. Kthehu te lista e grupeve.', 'bi-calendar-x', '<a class="btn btn-secondary" href="lesson_groups.php">Te grupet</a>') ?>
<?php else: ?>
  <header class="page-head">
    <div class="page-head-main">
      <ol class="crumbs"><li><a href="lesson_groups.php">Grupet</a></li><li aria-current="page">Grupi #<?= $gid ?></li></ol>
      <h1 class="page-title"><?= h((string)$g['course_name']) ?></h1>
      <p class="page-lead lg-lead">
        <span>Grupi #<?= $gid ?></span>
        <span><?= h(qta_date((string)$g['start_date'])) ?> – <?= h(qta_date((string)$g['end_date'])) ?></span>
        <span><?= h(qta_hours_label((int)$totalHours)) ?>, <?= (int)$g['daily_hours'] ?> në ditë</span>
        <span><?= count($members) ?>/10 kursantë</span>
        <?= $statusHtml($g) ?>
      </p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <button class="btn btn-secondary no-print" type="button" data-lg-print><i class="bi bi-printer" aria-hidden="true"></i>Printo orarin</button>
    </div>
  </header>

  <?php /* Mbyllja/rihapja është veprim: shfaqet vetëm kur ndryshimet janë të lejuara.
           Në lexim, gjendja ("I mbyllur", "Në mësim" …) është te rreshti nën titull. */ ?>
  <?php if ($EDIT_MODE): ?>
    <div class="notice has-action mb-3" id="lgState">
      <i class="bi <?= $closed ? 'bi-lock' : 'bi-unlock' ?>" aria-hidden="true"></i>
      <span><?= $closed
        ? '<b>Grupi është i mbyllur.</b> Çdo ndryshim kërkon konfirmim, sepse dokumentet mund të jenë lëshuar.'
        : '<b>Grupi është i hapur.</b> Kur provimet dhe pikët të jenë të plota, mbylle grupin.' ?></span>
      <button class="btn btn-secondary btn-sm notice-action" type="button" data-lg-complete="<?= $closed ? '0' : '1' ?>">
        <i class="bi <?= $closed ? 'bi-unlock' : 'bi-lock' ?>" aria-hidden="true"></i><?= $closed ? 'Rihape grupin' : 'Mbylle grupin' ?>
      </button>
    </div>
  <?php endif; ?>

  <?php if ($courseChanged): ?>
    <div class="notice has-action is-sunken mb-3">
      <i class="bi bi-journal-arrow-down" aria-hidden="true"></i>
      <span>
        <b>Kursi është ndryshuar pas krijimit të grupit.</b>
        Ky grup ndjek modulet dhe temat siç ishin më <?= h(qta_datetime((string)$g['curriculum_taken_at'])) ?>.
        <?php if ($started || $closed): ?>
          Grupi ka nisur, prandaj temat e tij nuk ndryshojnë — ndryshimet e kursit vlejnë për grupet e reja.
        <?php elseif (!$liveReady): ?>
          Kursi nuk është gati tani; kur të jetë, mund t'i marrësh temat e reja para se të nisë grupi.
        <?php endif; ?>
      </span>
      <?php if ($EDIT_MODE && !$started && !$closed && $liveReady): ?>
        <button class="btn btn-secondary btn-sm notice-action" type="button" data-lg-refresh><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Merr temat e reja</button>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php
    $editModeBannerText = 'Për të ndryshuar orarin, ditët e veçanta, kursantët ose pikët, shtyp "Lejo ndryshimet" lart djathtas.';
    require __DIR__ . '/../shared/partials/edit_mode_off_banner.php';
  ?>

  <ul class="nav nav-tabs lg-tabs no-print" role="tablist">
    <li role="presentation">
      <button class="nav-link active" id="tabOrari" data-bs-toggle="tab" data-bs-target="#orari" type="button" role="tab" aria-controls="orari" aria-selected="true">
        <i class="bi bi-calendar-week" aria-hidden="true"></i>Orari i mësimit
      </button>
    </li>
    <li role="presentation">
      <button class="nav-link" id="tabKursantet" data-bs-toggle="tab" data-bs-target="#kursantet" type="button" role="tab" aria-controls="kursantet" aria-selected="false">
        <i class="bi bi-people" aria-hidden="true"></i>Kursantët dhe provimet <span class="count"><?= count($members) ?></span>
      </button>
    </li>
    <li role="presentation">
      <button class="nav-link" id="tabDokumentet" data-bs-toggle="tab" data-bs-target="#dokumentet" type="button" role="tab" aria-controls="dokumentet" aria-selected="false">
        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Dokumentet
      </button>
    </li>
  </ul>

  <div class="tab-content lg-panes">
    <!-- ======================================================= Orari -->
    <div class="tab-pane fade show active" id="orari" role="tabpanel" aria-labelledby="tabOrari" tabindex="0">

      <?php if ($today < (string)$g['start_date']): ?>
        <div class="notice mb-4"><i class="bi bi-calendar-event" aria-hidden="true"></i>
          <span><b>Mësimi nis <?= h(qta_when_label((string)$g['start_date'])) ?></b>, <?= h(qta_sched_day_label((string)$g['start_date'])) ?>.</span></div>
      <?php elseif ($today > (string)$g['end_date']): ?>
        <div class="notice mb-4"><i class="bi bi-flag" aria-hidden="true"></i>
          <span><b>Mësimi mbaroi</b> <?= h(qta_sched_day_label((string)$g['end_date'])) ?>.<?= !$closed ? ' Cakto provimet dhe pikët te "Kursantët dhe provimet", pastaj mbyll grupin.' : '' ?></span></div>
      <?php elseif ($todayDay): ?>
        <div class="callout lg-today mb-4">
          <span class="callout-icon"><i class="bi bi-geo-alt" aria-hidden="true"></i></span>
          <div class="callout-body">
            <span class="callout-label">Sot · Dita <?= (int)$todayDay['seq'] ?> nga <?= count($days) ?> · <?= h(qta_hours_label((int)$todayDay['hours'])) ?></span>
            <span class="callout-title"><?= h(implode(' · ', array_unique(array_map(static fn($s) => (string)$s['module_title'], $todayDay['slots'])))) ?></span>
            <span class="callout-text"><?= h(implode('; ', array_map(static fn($s) => $s['topic_seq'] . '. ' . $s['topic_title'] . ' (' . qta_hours_label((int)$s['hours']) . ')', $todayDay['slots']))) ?></span>
          </div>
          <a class="btn btn-secondary btn-sm no-print" href="#dita-<?= h($today) ?>">Shiko në orar</a>
        </div>
      <?php else: ?>
        <div class="notice mb-4"><i class="bi bi-cup-hot" aria-hidden="true"></i>
          <span><b>Sot nuk ka mësim.</b><?= $nextDay ? ' Dita tjetër e mësimit: ' . h(qta_sched_day_label((string)$nextDay['date'])) . '.' : '' ?></span></div>
      <?php endif; ?>

      <section class="section" aria-labelledby="lgSummaryTitle">
        <div class="section-head">
          <h2 class="section-title" id="lgSummaryTitle">Orari në shkurt</h2>
          <?php if ($EDIT_MODE): ?>
            <button class="btn btn-secondary btn-sm no-print" type="button" data-lg-settings><i class="bi bi-sliders" aria-hidden="true"></i>Ndrysho fillimin ose orët në ditë</button>
          <?php endif; ?>
        </div>
        <div class="stats">
          <div class="stat">
            <span class="stat-label">Fillon</span>
            <span class="stat-value lg-date"><?= h(qta_date((string)$g['start_date'])) ?></span>
            <span class="stat-note"><?= h(qta_weekday(qta_sched_weekday((string)$g['start_date']))) ?></span>
          </div>
          <div class="stat">
            <span class="stat-label">Mbaron</span>
            <span class="stat-value lg-date"><?= h(qta_date((string)$g['end_date'])) ?></span>
            <span class="stat-note"><?= h(qta_weekday(qta_sched_weekday((string)$g['end_date']))) ?> · e llogarit orari</span>
          </div>
          <div class="stat">
            <span class="stat-label">Ditë mësimi</span>
            <span class="stat-value"><?= count($days) ?></span>
            <span class="stat-note">të dielat pa mësim, përveç ditëve të veçanta</span>
          </div>
          <div class="stat">
            <span class="stat-label">Orë mësimi</span>
            <span class="stat-value"><?= (int)$totalHours ?></span>
            <span class="stat-note"><?= (int)$g['daily_hours'] ?> në një ditë të zakonshme</span>
          </div>
        </div>
      </section>

      <section class="section" aria-labelledby="lgRulesTitle">
        <div class="section-head">
          <h2 class="section-title" id="lgRulesTitle">Ditët e veçanta <span class="count"><?= count($rules) ?></span></h2>
          <?php if ($EDIT_MODE): ?>
            <button class="btn btn-secondary btn-sm no-print" type="button" data-lg-day=""><i class="bi bi-calendar-plus" aria-hidden="true"></i>Shto një ditë të veçantë</button>
          <?php endif; ?>
        </div>
        <?php if ($rules): ?>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead>
                <tr>
                  <th scope="col" class="nowrap">Data</th>
                  <th scope="col">Si është dita</th>
                  <th scope="col">Shënim</th>
                  <?php if ($EDIT_MODE): ?><th scope="col" class="col-actions no-print"><span class="visually-hidden">Veprime</span></th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rules as $rd => $r):
                  $outside = $rd < (string)$g['start_date'] || $rd > (string)$g['end_date'];
                  $label = qta_sched_day_label($rd); ?>
                  <tr>
                    <td class="nowrap"><?= h(ucfirst($label)) ?></td>
                    <td>
                      <?= h(qta_rule_label($r['hours'], (int)$g['daily_hours'])) ?>
                      <?php if ($outside): ?><span class="cell-sub">Jashtë orarit tani — përdoret nëse orari zgjatet deri këtu.</span><?php endif; ?>
                    </td>
                    <td><?= $r['note'] !== null && $r['note'] !== '' ? h((string)$r['note']) : '<span class="text-subtle">—</span>' ?></td>
                    <?php if ($EDIT_MODE): ?>
                      <td class="col-actions no-print">
                        <span class="row-actions">
                          <button class="btn btn-ghost btn-sm" type="button" data-lg-day="<?= h($rd) ?>" aria-label="Ndrysho ditën <?= h($label) ?>"><i class="bi bi-pencil" aria-hidden="true"></i>Ndrysho</button>
                          <button class="btn btn-ghost btn-sm btn-ghost-danger" type="button" data-lg-rule-remove="<?= h($rd) ?>" data-label="<?= h($label) ?>" aria-label="Hiq ditën e veçantë <?= h($label) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i>Hiq</button>
                        </span>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">Asnjë ditë e veçantë. Çdo ditë nga e hëna në të shtunë ka <?= h(qta_hours_label((int)$g['daily_hours'])) ?>; të dielat nuk kanë mësim.
            <?= $EDIT_MODE ? 'Shto një të diel me mësim, një festë pa mësim ose një ditë me orë të tjera.' : '' ?></p>
        <?php endif; ?>
      </section>

      <section class="section" aria-labelledby="lgModulesTitle">
        <div class="section-head">
          <h2 class="section-title" id="lgModulesTitle">Modulet në orar <span class="count"><?= count($windows) ?></span></h2>
          <span class="section-meta">Në radhën e kursit; një modul mund të mbarojë dhe tjetri të fillojë në të njëjtën ditë.</span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th scope="col" class="num-col">Nr.</th>
                <th scope="col" class="col-medium">Moduli</th>
                <th scope="col" class="nowrap num-col">Orë</th>
                <th scope="col" class="nowrap num-col">Tema</th>
                <th scope="col" class="nowrap">Fillon</th>
                <th scope="col" class="nowrap">Mbaron</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($windows as $w): ?>
                <tr>
                  <td class="num-col"><?= (int)$w['module_seq'] ?></td>
                  <td class="col-medium"><span class="person-name"><?= h((string)$w['title']) ?></span></td>
                  <td class="nowrap num-col"><?= (int)$w['hours'] ?></td>
                  <td class="nowrap num-col"><?= (int)$w['topics'] ?></td>
                  <td class="nowrap"><?= $w['first_date'] ? '<a href="#dita-' . h((string)$w['first_date']) . '">' . h(qta_date((string)$w['first_date'])) . '</a>' : '—' ?></td>
                  <td class="nowrap"><?= $w['last_date'] ? h(qta_date((string)$w['last_date'])) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="section" aria-labelledby="lgDaysTitle">
        <div class="section-head">
          <h2 class="section-title" id="lgDaysTitle">Ditë pas dite <span class="count"><?= count($days) ?></span></h2>
          <?php if ($todayDay || ($today >= (string)$g['start_date'] && $today <= (string)$g['end_date'])): ?>
            <a class="section-link no-print" href="#dita-<?= h($today) ?>">Kalo te sot <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
          <?php endif; ?>
        </div>
        <?= qta_render_timetable($days, $rules, $g, $EDIT_MODE, $today) ?>
      </section>
    </div>

    <!-- ============================================ Kursantët dhe provimet -->
    <div class="tab-pane fade" id="kursantet" role="tabpanel" aria-labelledby="tabKursantet" tabindex="0">
      <section class="section" aria-labelledby="lgMembersTitle">
        <div class="section-head">
          <h2 class="section-title" id="lgMembersTitle">Kursantët <span class="count"><?= count($members) ?>/10</span></h2>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($members): ?>
              <span class="section-meta" data-scored-label><?= $scored === 0 ? 'ende pa pikë' : ($scored === count($members) ? 'të gjithë me pikë' : $scored . ' me pikë') ?></span>
            <?php endif; ?>
            <?php if ($EDIT_MODE): ?>
              <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#lgMembers"><i class="bi bi-people" aria-hidden="true"></i>Ndrysho kursantët</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($members): ?>
          <div class="table-responsive">
            <table class="table" id="lgMembersTable">
              <thead>
                <tr>
                  <th scope="col" class="nowrap">Nr. i amzës</th>
                  <th scope="col">Kursanti</th>
                  <th scope="col" class="nowrap">Data e provimit</th>
                  <th scope="col" class="nowrap num-col">Pikët</th>
                  <th scope="col">Gjendja</th>
                  <th scope="col" class="nowrap num-col">Mosha</th>
                  <th scope="col" class="nowrap">Arsimi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($members as $m):
                  $full = qta_full_name($m['first_name'] ?? '', $m['father_name'] ?? '', $m['last_name'] ?? ''); ?>
                  <tr data-student-row>
                    <td class="nowrap"><span class="id-code"><?= h((string)$m['nr_amze']) ?></span></td>
                    <td>
                      <a class="person-name" href="student_card.php?sid=<?= (int)$m['student_id'] ?>"><?= h($full !== '' ? $full : 'Pa emër ende') ?></a>
                      <?php if (!empty($m['personal_number'])): ?><span class="cell-sub code"><?= h((string)$m['personal_number']) ?></span><?php endif; ?>
                    </td>
                    <td class="cell nowrap" data-student="<?= (int)$m['student_id'] ?>" data-field="exam_date" title="Jo para mbarimit të grupit (dd.mm.vvvv)">
                      <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"<?= $EDIT_MODE ? ' role="textbox" aria-label="Data e provimit për ' . h($full ?: (string)$m['nr_amze']) . '"' : '' ?>><?= h(qta_date($m['exam_date'])) ?></span>
                    </td>
                    <td class="cell nowrap num-col" data-student="<?= (int)$m['student_id'] ?>" data-field="final_score" title="Pikët, nga 0 deri në 100">
                      <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"<?= $EDIT_MODE ? ' role="textbox" inputmode="decimal" aria-label="Pikët për ' . h($full ?: (string)$m['nr_amze']) . '"' : '' ?>><?= $m['final_score'] !== null ? h(rtrim(rtrim((string)$m['final_score'], '0'), '.')) : '—' ?></span>
                    </td>
                    <td data-status><?= qta_enrollment_status(['start_date' => $g['start_date'], 'end_date' => $g['end_date'], 'exam_date' => $m['exam_date'], 'final_score' => $m['final_score']], false) ?></td>
                    <td class="nowrap num-col"><?= $m['age'] !== null ? (int)$m['age'] : '—' ?></td>
                    <td class="nowrap"><?= h((string)(($m['edu_label'] ?? '') ?: '—')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($EDIT_MODE): ?>
            <p class="form-text mt-2 mb-0">Kliko datën e provimit ose pikët për t'i ndryshuar. <kbd>Enter</kbd> ruan, <kbd>Esc</kbd> anulon. Provimi nuk mund të jetë para <?= h(qta_date((string)$g['end_date'])) ?>.</p>
          <?php endif; ?>
        <?php else: ?>
          <?= qta_empty('Grupi nuk ka ende kursantë', $EDIT_MODE ? 'Shto kursantët me numrat e amzës te "Ndrysho kursantët", ose caktoji te "Kursantët pa grup".' : 'Për të shtuar kursantë, shtyp "Lejo ndryshimet".', 'bi-people', '', 'is-compact') ?>
        <?php endif; ?>
      </section>
    </div>

    <!-- ========================================================= Dokumentet -->
    <div class="tab-pane fade" id="dokumentet" role="tabpanel" aria-labelledby="tabDokumentet" tabindex="0">
      <section class="section" aria-labelledby="lgDocsTitle">
        <div class="section-head">
          <h2 class="section-title" id="lgDocsTitle">Dokumentet e grupit</h2>
          <span class="section-meta">Zgjidh formatin — dokumenti hapet në një skedë të re.</span>
        </div>
        <?= $members
          ? qta_group_documents($gid, $CSRF)
          : qta_empty('Ende pa dokumente', 'Dokumentet e grupit (procesverbali, lista emërore…) krijohen kur grupi ka kursantë.', 'bi-file-earmark-text', '', 'is-compact') ?>
      </section>
    </div>
  </div>

  <?php if ($EDIT_MODE): ?>
    <section class="section lg-danger no-print" aria-labelledby="lgDangerTitle">
      <h2 class="section-title visually-hidden" id="lgDangerTitle">Fshirja e grupit</h2>
      <button class="btn btn-ghost btn-ghost-danger" type="button" data-lg-delete><i class="bi bi-trash" aria-hidden="true"></i>Fshi grupin</button>
      <span class="text-muted small">Fshihen orari, ditët e veçanta, datat e provimeve dhe pikët e këtij grupi. Kursantët mbeten në regjistër.</span>
    </section>
  <?php endif; ?>
<?php endif; ?>
</main>

<?php if ($g && $EDIT_MODE): ?>
<!-- Dialog: fillimi dhe orët në ditë -->
<div class="modal fade" id="lgSettings" tabindex="-1" aria-labelledby="lgSettingsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" data-lg-form="settings" novalidate>
      <div class="modal-header">
        <div>
          <span class="eyebrow mb-0">Grupi #<?= $gid ?> · <?= h((string)$g['course_name']) ?></span>
          <h2 class="modal-title" id="lgSettingsTitle"><i class="bi bi-sliders" aria-hidden="true"></i>Fillimi dhe orët në ditë</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger py-2" data-form-error role="alert" hidden></div>
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label" for="lgsStart">Data e fillimit <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="lgsStart" name="start_date" type="text" inputmode="numeric" autocomplete="off" data-dmy required value="<?= h(qta_date((string)$g['start_date'])) ?>" placeholder="dd.mm.vvvv">
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="lgsDaily">Orë mësimi në ditë <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="lgsDaily" name="daily_hours" type="number" min="1" max="<?= QTA_DAY_MAX_HOURS ?>" step="1" inputmode="numeric" required value="<?= (int)$g['daily_hours'] ?>">
          </div>
          <div class="col-12">
            <div class="plan-preview" data-lg-impact role="status" aria-live="polite">
              <i class="bi bi-calendar-range" aria-hidden="true"></i>
              <span data-lg-impact-text>Mbaron <?= h(qta_sched_day_label((string)$g['end_date'])) ?>.</span>
            </div>
          </div>
        </div>
        <p class="form-text mb-0 mt-3">Data e mbarimit nuk shkruhet me dorë: llogaritet nga orët e kursit, orët në ditë, të dielat dhe ditët e veçanta.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>Ruaj dhe rillogarit</button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: një ditë e veçantë -->
<div class="modal fade" id="lgDay" tabindex="-1" aria-labelledby="lgDayTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" data-lg-form="rule" novalidate>
      <div class="modal-header">
        <div>
          <span class="eyebrow mb-0">Grupi #<?= $gid ?> · <?= h((string)$g['course_name']) ?></span>
          <h2 class="modal-title" id="lgDayTitle"><i class="bi bi-calendar-event" aria-hidden="true"></i><span data-lg-day-title>Një ditë e veçantë</span></h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger py-2" data-form-error role="alert" hidden></div>
        <div class="mb-3">
          <label class="form-label" for="lgdDate">Data <span class="req" aria-hidden="true">*</span></label>
          <input class="form-control" id="lgdDate" name="date" type="text" inputmode="numeric" autocomplete="off" data-dmy required placeholder="dd.mm.vvvv" aria-describedby="lgdDateHelp">
          <div class="form-text" id="lgdDateHelp" data-lg-weekday>Dita e javës llogaritet vetë nga data.</div>
        </div>
        <fieldset class="mb-3">
          <legend class="form-label">Si do të jetë kjo ditë?</legend>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="mode" id="lgdNormal" value="normal" checked>
            <label class="form-check-label" for="lgdNormal" data-lg-normal-label>Orari i zakonshëm: <?= (int)$g['daily_hours'] ?> orë</label>
          </div>
          <div class="form-check" data-lg-sunday-only hidden>
            <input class="form-check-input" type="radio" name="mode" id="lgdDefault" value="default">
            <label class="form-check-label" for="lgdDefault">Mësim me orarin e zakonshëm: <?= (int)$g['daily_hours'] ?> orë</label>
          </div>
          <div class="form-check lg-hours-choice">
            <input class="form-check-input" type="radio" name="mode" id="lgdHours" value="hours">
            <label class="form-check-label" for="lgdHours">Mësim me orë të tjera:</label>
            <label class="visually-hidden" for="lgdHoursValue">Orët e mësimit në këtë ditë</label>
            <input class="form-control form-control-sm" id="lgdHoursValue" name="hours" type="number" min="1" max="<?= QTA_DAY_MAX_HOURS ?>" step="1" inputmode="numeric" placeholder="orë">
          </div>
          <div class="form-check" data-lg-weekday-only>
            <input class="form-check-input" type="radio" name="mode" id="lgdOff" value="off">
            <label class="form-check-label" for="lgdOff">Pa mësim këtë ditë (p.sh. festë)</label>
          </div>
        </fieldset>
        <div class="mb-3">
          <label class="form-label" for="lgdNote">Shënim <span class="optional">(opsional)</span></label>
          <input class="form-control" id="lgdNote" name="note" type="text" maxlength="160" placeholder="p.sh. Festë kombëtare, ose mësim zëvendësues">
        </div>
        <div class="plan-preview" data-lg-impact role="status" aria-live="polite">
          <i class="bi bi-calendar-range" aria-hidden="true"></i>
          <span data-lg-impact-text>Zgjidh datën dhe si do të jetë dita: këtu del ndikimi në orar.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>Ruaj ditën</button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: kursantët e grupit -->
<div class="modal fade" id="lgMembers" tabindex="-1" aria-labelledby="lgMembersDialogTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" data-lg-form="members" novalidate>
      <div class="modal-header">
        <div>
          <span class="eyebrow mb-0">Grupi #<?= $gid ?> · <?= h((string)$g['course_name']) ?></span>
          <h2 class="modal-title" id="lgMembersDialogTitle"><i class="bi bi-people" aria-hidden="true"></i>Kursantët e grupit</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger py-2" data-form-error role="alert" hidden></div>
        <label class="form-label" for="lgmAmze">Numrat e amzës në këtë grup</label>
        <textarea class="form-control input-code" id="lgmAmze" name="amze_spec" rows="3" placeholder="p.sh. 3400-3403, 3409" aria-describedby="lgmHelp"><?= h($amzeList) ?></textarea>
        <div class="form-text" id="lgmHelp">Numra të ndarë me presje ose intervale me vizë, p.sh. <span class="code">3400-3403, 3409</span>. Shto ose hiq numra për të shtuar ose hequr kursantë.</div>
        <ul class="text-muted small mt-3 mb-0 ps-3">
          <li>Një grup mban deri në 10 kursantë. Për të tjerët krijo një grup tjetër me të njëjtin kurs.</li>
          <li>Një person nuk mund ta ndjekë dy herë të njëjtin kurs.</li>
          <li>Një numër amze që nuk ekziston krijon një kursant të ri pa të dhëna, për t'u plotësuar më vonë.</li>
        </ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>Ruaj kursantët</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php require __DIR__ . '/../shared/partials/download_generation_toast.php'; ?>
<?php if ($g): ?>
<script type="application/json" id="lgConfig"><?= json_encode([
  'csrf' => $CSRF, 'group' => $gid, 'revision' => (int)$g['revision'], 'edit' => $EDIT_MODE,
  'daily' => (int)$g['daily_hours'], 'end' => (string)$g['end_date'], 'start' => (string)$g['start_date'],
  'closed' => $closed, 'today' => $today,
  'endpoint' => 'lesson_group_update.php', 'cellEndpoint' => 'groups_inline_update.php',
  'rules' => array_map(static fn($r) => ['hours' => $r['hours'], 'note' => $r['note']], $rules),
  'flash' => $flash_ok,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
</body>
</html>
