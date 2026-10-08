<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

/* -------------------------------------------------
   Toggle: Edit Mode (ruhet në session)
-------------------------------------------------- */
if (isset($_GET['edit'])) {
    qta_session_put(['edit_mode'], filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN));
    // redirect pa param 'edit' (ruaj pjesën tjetër të query-it)
    $qs = $_GET; unset($qs['edit']);
    $redir = 'courses.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $redir);
    exit;
}
$EDIT_MODE = !empty($_SESSION['edit_mode']);

/* ------------------------------
   Guard: admin OSE editor i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$userStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid
    LIMIT 1
");
$userStmt->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

$roleName  = strtolower((string)($currentUser['role_name'] ?? ''));
$isAdmin   = ($roleName === 'administrator');
$isEditor  = ($roleName === 'editor');

if (!$currentUser || (!$isAdmin && !$isEditor)) {
    header('Location: selectProfile.php'); exit;
}

require_once __DIR__ . '/../shared/curriculum.php';

/* ------------------------------
   CSRF & Flash helpers
------------------------------- */
function flash(string $key, ?string $msg=null) {
    if ($msg === null) {
        if (!empty($_SESSION['flash'][$key])) { return qta_session_take(['flash', $key]); }
        return null;
    }
    qta_session_put(['flash', $key], $msg);
}
function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(400); exit('Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.');
        }
    }
}
if (empty($_SESSION['csrf_token'])) { qta_session_put(['csrf_token'], bin2hex(random_bytes(24))); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   POST: Shto / Fshi kurs
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_course') {
        try {
            if (!$EDIT_MODE) { throw new RuntimeException('Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.'); }

            $code  = trim($_POST['code'] ?? '');
            $name  = trim($_POST['name'] ?? '');
            $hours = trim($_POST['hours'] ?? '');

            if ($code === '' || $name === '' || $hours === '') {
                throw new RuntimeException('Plotëso kodin, emrin dhe orët e kursit.');
            }
            if (!ctype_digit($hours) || (int)$hours < 1 || (int)$hours > 65535) {
                throw new RuntimeException('Orët duhet të jenë një numër i plotë, p.sh. 40.');
            }

            $q = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE code = :c");
            $q->execute([':c'=>$code]);
            if ((int)$q->fetchColumn() > 0) {
                throw new RuntimeException('Ky kod i përket një kursi tjetër. Zgjidh një kod tjetër.');
            }

            $st = $pdo->prepare("INSERT INTO courses (code, name, hours) VALUES (:c, :n, :h)");
            $st->execute([':c'=>$code, ':n'=>$name, ':h'=>(int)$hours]);
            $newId = (int)$pdo->lastInsertId();

            /* Kursi i ri hapet menjëherë, që t'i shtohen modulet dhe temat. */
            flash('ok', 'Kursi "' . $name . '" u shtua. Tani ndaje në module dhe tema.');
            header('Location: course.php?id=' . $newId); exit;
        } catch (Throwable $e) {
            flash('err', qta_error_message($e));
        }
        header('Location: courses.php'); exit;
    }

    if ($action === 'delete_course') {
        try {
            if (!$EDIT_MODE) { throw new RuntimeException('Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.'); }

            $course_id = (int)($_POST['course_id'] ?? 0);
            if ($course_id <= 0) throw new RuntimeException('Kursi nuk u gjet. Rifresko faqen.');

            /* Kursi me grupe nuk fshihet (as nga baza: fk_cg_course është RESTRICT).
               Pa grupe: fshihen edhe modulet dhe temat e tij, një nga një (me historik). */
            $r = qta_course_delete($pdo, $course_id);
            $extra = $r['modules'] ? ' bashkë me ' . $r['modules'] . ($r['modules'] === 1 ? ' modul' : ' module') . ' dhe ' . $r['topics'] . ($r['topics'] === 1 ? ' temë' : ' tema') : '';
            flash('ok', 'Kursi "' . $r['course']['code'] . ' — ' . $r['course']['name'] . '" u fshi' . $extra . '.');
        } catch (Throwable $e) {
            flash('err', qta_error_message($e));
        }
        header('Location: courses.php'); exit;
    }
}

require_once __DIR__ . '/../shared/themeli.php';
require_once __DIR__ . '/../shared/list_filter.php';

/* ------------------------------
   Katalogu: kërkimi, gjendja e strukturës (çipat) dhe faqosja.
   Gatishmëria vjen nga qta_course_summaries() — i njëjti rregull që përdor
   krijimi i grupit — prandaj katalogu filtrohet këtu, jo me një kopje në SQL.
------------------------------- */
$courseStates = ['ready', 'not_ready', 'no_modules'];
$q      = qta_search_q($_GET['q'] ?? '');
$status = qta_list_choice($_GET['status'] ?? '', $courseStates);
$limit  = 20;

$allRows = $pdo->query("SELECT c.id AS course_id, c.code, c.name, c.hours, c.created_at FROM courses c ORDER BY c.code ASC, c.id ASC")->fetchAll(PDO::FETCH_ASSOC);
$structureAll = qta_course_summaries($pdo);

/* Emrat e moduleve dhe temave, që kërkimi të gjejë edhe "Excel" te "Microsoft Office". */
$partsText = [];
$pdo->exec('SET SESSION group_concat_max_len = 65535');
foreach ($pdo->query("
    SELECT m.course_id, CONCAT_WS(' ', m.title, GROUP_CONCAT(t.title SEPARATOR ' ')) AS txt
    FROM course_modules m LEFT JOIN course_topics t ON t.module_id = m.id
    GROUP BY m.id, m.course_id, m.title
")->fetchAll(PDO::FETCH_ASSOC) as $pt) {
    $partsText[(int)$pt['course_id']] = ($partsText[(int)$pt['course_id']] ?? '') . ' ' . (string)$pt['txt'];
}

$stateOf = static function (array $st): string {
    return !empty($st['ready']) ? 'ready' : (!empty($st['has_structure']) ? 'not_ready' : 'no_modules');
};
$stateWords = ['ready' => 'gati', 'not_ready' => 'jo gati', 'no_modules' => 'pa module'];
$tokens = qta_search_tokens($q);
$counts = ['' => 0, 'ready' => 0, 'not_ready' => 0, 'no_modules' => 0];
$matched = [];
foreach ($allRows as $c) {
    $cid = (int)$c['course_id'];
    $st = $structureAll[$cid] ?? ['modules' => 0, 'topics' => 0, 'ready' => false, 'has_structure' => false];
    $state = $stateOf($st);
    if ($tokens) {
        $hay = implode(' ', [
            $c['code'], $c['name'], (int)$c['hours'] . ' orë', $stateWords[$state],
            qta_plural((int)$st['modules'], 'modul', 'module'), qta_plural((int)$st['topics'], 'temë', 'tema'),
            $partsText[$cid] ?? '',
        ]);
        if (!qta_search_hit($tokens, $hay)) continue;
    }
    $counts['']++;
    $counts[$state]++;
    if ($status === '' || $status === $state) $matched[] = $c;
}
$total = count($matched);
$totalPages = max(1, (int)ceil($total / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$courses = array_slice($matched, ($page - 1) * $limit, $limit);
$structure = $structureAll;

/* Kurse për target (select në dialogun "Zhvendos grupin") */
$allCourses = $pdo->query("SELECT id, code, name FROM courses ORDER BY code ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

/* Grupe për kurset e faqes (summary + numer i anëtarëve) */
$groupsByCourse = [];
if ($courses) {
    $ids = array_column($courses, 'course_id');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $gq = $pdo->prepare("
            SELECT
              cg.id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed, cg.model,
              COUNT(cgs.student_id) AS members
            FROM course_groups cg
            LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
            WHERE cg.course_id IN ($ph)
            GROUP BY cg.id
            ORDER BY cg.start_date DESC, cg.id DESC
        ");
        $gq->execute($ids);
        $grs = $gq->fetchAll(PDO::FETCH_ASSOC);
        foreach ($grs as $g) {
            $cid = (int)$g['course_id'];
            if (!isset($groupsByCourse[$cid])) $groupsByCourse[$cid] = [];
            $groupsByCourse[$cid][] = $g;
        }
    }
}

$ok  = flash('ok');
$err = flash('err');

$NAV_ACTIVE = 'courses';
$HELP_TOPIC = 'courses';
if ($isAdmin) require __DIR__ . '/inc/navbar.php';
else          require __DIR__ . '/inc/navbar4.php';

/* Sa kursantë e kanë zgjedhur çdo kurs, por nuk janë ende në grup */
$plannedByCourse = [];
foreach ($pdo->query("SELECT course_id, COUNT(*) AS n FROM student_course_plans WHERE status='planned' GROUP BY course_id")->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    $plannedByCourse[(int)$pr['course_id']] = (int)$pr['n'];
}

$openAdd = $EDIT_MODE && isset($_GET['add']);
$addHref = 'courses.php?' . http_build_query(['edit' => '1', 'add' => '1']);
$today   = date('Y-m-d');
$groupHref = static fn(array $g): string => ($g['model'] ?? 'legacy') === 'scheduled'
    ? 'lesson_group.php?id=' . (int)$g['id']
    : 'groups.php?group=' . (int)$g['id'];

$stateTitles = ['' => 'Të gjitha kurset', 'ready' => 'Kurset gati për grup', 'not_ready' => 'Kurset jo gati', 'no_modules' => 'Kurset pa module'];
$chips = [];
foreach (['' => 'Të gjitha', 'ready' => 'Gati', 'not_ready' => 'Jo gati', 'no_modules' => 'Pa module'] as $v => $label) {
    $chips[] = ['value' => (string)$v, 'label' => $label, 'count' => $counts[$v] ?? 0];
}
$LF = [
    'action'      => 'courses.php',
    'label'       => 'Kërko në katalogun e kurseve',
    'placeholder' => 'Kodi, emri, orët, një modul ose një temë',
    'q'           => $q,
    'target'      => 'coursesResults',
    'status'      => $status,
    'chips'       => $chips,
    'chips_label' => 'Gjendja e strukturës',
];

$pageTitle = 'Katalogu i kurseve';
require __DIR__ . '/../shared/app_head.php';
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Katalogu i kurseve</h1>
      <p class="page-lead">Kurset, modulet, temat dhe orët që përdoren në regjistrim dhe certifikim.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <?php if ($EDIT_MODE): ?>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addCourseModal">
          <i class="bi bi-plus-lg" aria-hidden="true"></i>Shto kurs
        </button>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= h($addHref) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i>Shto kurs</a>
      <?php endif; ?>
    </div>
  </header>

  <section class="section" aria-labelledby="coursesTitle">
    <div class="list-head" data-live-region="list-head">
      <h2 class="section-title" id="coursesTitle" tabindex="-1" data-live-focus>
        <?= h($stateTitles[$status] ?? 'Të gjitha kurset') ?>
        <span class="count"><?= number_format($total, 0, ',', '.') ?></span>
      </h2>
    </div>

    <?php require __DIR__ . '/../shared/partials/list_toolbar.php'; ?>

    <div id="coursesResults" data-live-region="results" data-live-announce="<?= h(qta_plural($total, 'kurs', 'kurse')) ?>">
    <?php if ($courses): ?>
      <div class="table-responsive">
        <table class="table" id="coursesTable" data-sortable>
          <thead>
            <tr>
              <th scope="col" class="nowrap" data-sort="text">Kodi</th>
              <th scope="col" class="col-wide" data-sort="text">Kursi</th>
              <th scope="col" class="nowrap num-col" data-sort="num">Orë</th>
              <th scope="col" class="nowrap" data-sort="num">Modulet dhe temat</th>
              <th scope="col" class="nowrap" data-sort="num">Grupe</th>
              <th scope="col" class="nowrap num-col" data-sort="num">Kursantë</th>
              <th scope="col" class="col-actions" data-sort="none"><span class="visually-hidden">Veprime</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($courses as $c):
            $cid = (int)$c['course_id'];
            $grList = $groupsByCourse[$cid] ?? [];
            $grCount = count($grList);
            $members = array_sum(array_map(static fn($g) => (int)$g['members'], $grList));
            $plannedN = $plannedByCourse[$cid] ?? 0;
            $st = $structure[$cid] ?? ['modules' => 0, 'topics' => 0, 'ready' => false, 'has_structure' => false];
          ?>
            <tr data-course="<?= $cid ?>">
              <td class="cell nowrap" data-id="<?= $cid ?>" data-field="code">
                <span class="editable id-code" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>" <?= $EDIT_MODE ? 'role="textbox" aria-label="Kodi i kursit"' : '' ?>><?= h((string)$c['code']) ?></span>
              </td>
              <td class="cell col-wide" data-id="<?= $cid ?>" data-field="name">
                <?php if ($EDIT_MODE): ?>
                  <span class="editable person-name" contenteditable="true" role="textbox" aria-label="Emri i kursit"><?= h((string)$c['name']) ?></span>
                <?php else: ?>
                  <a class="person-name" href="course.php?id=<?= $cid ?>"><?= h((string)$c['name']) ?></a>
                <?php endif; ?>
                <?php if ($plannedN): ?><span class="cell-sub"><?= h(qta_plural($plannedN, 'kursant e ka zgjedhur, pa grup ende', 'kursantë e kanë zgjedhur, pa grup ende')) ?></span><?php endif; ?>
              </td>
              <td class="cell nowrap num-col" data-id="<?= $cid ?>" data-field="hours" title="Numër i plotë, p.sh. 40">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>" inputmode="numeric" <?= $EDIT_MODE ? 'role="textbox" aria-label="Orët e kursit"' : '' ?>><?= (int)$c['hours'] ?></span>
              </td>
              <td class="nowrap" data-sort-value="<?= $st['ready'] ? '2' : ($st['has_structure'] ? '1' : '0') ?>">
                <a class="row-open" href="course.php?id=<?= $cid ?>">
                  <span>
                    <?= $st['ready']
                      ? qta_status('Gati', 'success', 'bi-check-circle-fill')
                      : ($st['has_structure'] ? qta_status('Jo gati', 'warning', 'bi-exclamation-triangle') : qta_status('Pa module', 'neutral', 'bi-dash-circle')) ?>
                    <span class="cell-sub"><?= $st['has_structure'] ? h(qta_plural((int)$st['modules'], 'modul', 'module') . ' · ' . qta_plural((int)$st['topics'], 'temë', 'tema')) : 'Shto modulet' ?></span>
                  </span>
                  <span class="visually-hidden">: modulet dhe temat e kursit <?= h((string)$c['name']) ?></span>
                  <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
              </td>
              <td class="nowrap" data-sort-value="<?= $grCount ?>">
                <?php if ($grCount): ?>
                  <button class="row-open" type="button" data-bs-toggle="modal" data-bs-target="#courseGroupsModal_<?= $cid ?>" aria-haspopup="dialog">
                    <span class="row-open-text"><?= h(qta_plural($grCount, 'grup', 'grupe')) ?></span><i class="bi bi-chevron-right" aria-hidden="true"></i>
                  </button>
                <?php else: ?>
                  <span class="text-muted">Asnjë grup</span>
                <?php endif; ?>
              </td>
              <td class="nowrap num-col" data-count-members><?= $members ?></td>
              <td class="col-actions">
                <?php if ($EDIT_MODE): ?>
                  <button type="button" class="btn btn-ghost btn-ghost-danger btn-sm btn-icon" data-bs-toggle="modal" data-bs-target="#deleteCourseModal"
                          data-course-id="<?= $cid ?>" data-course-code="<?= h((string)$c['code']) ?>" data-course-name="<?= h((string)$c['name']) ?>"
                          data-course-groups="<?= $grCount ?>" data-course-planned="<?= $plannedN ?>"
                          data-course-modules="<?= (int)$st['modules'] ?>" data-course-topics="<?= (int)$st['topics'] ?>"
                          aria-label="Fshi kursin <?= h((string)$c['name']) ?>" data-tip="Fshi kursin">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($q !== ''): ?>
      <?= qta_empty('Asnjë kurs nuk përputhet', 'Provo një fjalë tjetër nga emri, kodi, një modul ose një temë.', 'bi-search') ?>
    <?php elseif ($status !== ''): ?>
      <?= qta_empty('Asnjë kurs me këtë gjendje', 'Zgjidh "Të gjitha" për të parë çdo kurs.', 'bi-journal', '', 'is-compact') ?>
    <?php else: ?>
      <?= qta_empty('Ende pa kurse', 'Shto kursin e parë që ofron QTA, p.sh. "Punime në lartësi".', 'bi-journal-plus', '<a class="btn btn-primary" href="' . h($addHref) . '">Shto kurs</a>') ?>
    <?php endif; ?>

      <?= qta_list_pager('courses.php', ['q' => $q, 'status' => $status], $page, $totalPages, qta_plural($total, 'kurs', 'kurse')) ?>
    </div>
  </section>
</main>

<?php /* Dritaret e grupeve të kurseve i përkasin listës: rifreskohen bashkë me të. */ ?>
<div data-live-region="dialogs">

<?php foreach ($courses as $c):
  $cid = (int)$c['course_id'];
  $grList = $groupsByCourse[$cid] ?? [];
  if (!$grList) continue;
  $members = array_sum(array_map(static fn($g) => (int)$g['members'], $grList));
?>
  <!-- Dritarja: grupet e kursit <?= h((string)$c['code']) ?> -->
  <div class="modal fade modal-record" id="courseGroupsModal_<?= $cid ?>" tabindex="-1" aria-labelledby="cgmTitle_<?= $cid ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="eyebrow mb-0">Kursi · <span class="code"><?= h((string)$c['code']) ?></span></span>
            <h2 class="modal-title" id="cgmTitle_<?= $cid ?>"><?= h((string)$c['name']) ?></h2>
            <div class="modal-meta">
              <span><i class="bi bi-clock" aria-hidden="true"></i><?= h(qta_plural((int)$c['hours'], 'orë', 'orë')) ?></span>
              <span><i class="bi bi-collection" aria-hidden="true"></i><?= h(qta_plural(count($grList), 'grup', 'grupe')) ?></span>
              <span><i class="bi bi-people" aria-hidden="true"></i><?= h(qta_plural($members, 'kursant', 'kursantë')) ?></span>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
        </div>
        <div class="modal-body">
          <div class="table-responsive">
            <table class="table table-sm">
              <thead>
                <tr>
                  <th scope="col" class="nowrap">Grupi</th>
                  <th scope="col" class="nowrap">Datat</th>
                  <th scope="col" class="nowrap num-col">Kursantë</th>
                  <th scope="col">Gjendja</th>
                  <?php if ($EDIT_MODE): ?><th scope="col" class="col-actions"><span class="visually-hidden">Veprime</span></th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($grList as $g): $gid = (int)$g['id']; $isScheduled = ($g['model'] ?? 'legacy') === 'scheduled'; ?>
                  <tr id="groupRow_<?= $gid ?>">
                    <td class="nowrap">
                      <a class="person-name" href="<?= h($groupHref($g)) ?>">Grupi #<?= $gid ?></a>
                      <span class="cell-sub"><?= $isScheduled ? 'me orar mësimi' : 'i mëparshëm, pa orar' ?></span>
                    </td>
                    <td class="nowrap"><?= h(qta_date($g['start_date'])) ?> – <?= h(qta_date($g['end_date'])) ?></td>
                    <td class="nowrap num-col"><?= (int)$g['members'] ?><span class="text-subtle">/10</span></td>
                    <td><?= qta_group_status($g, $today) ?></td>
                    <?php if ($EDIT_MODE): ?>
                      <td class="col-actions">
                        <?php if (!$isScheduled): ?>
                          <button class="btn btn-ghost btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#moveGroupModal"
                                  data-group-id="<?= $gid ?>" data-current-course="<?= $cid ?>" data-completed="<?= (int)$g['is_completed'] ?>"
                                  data-group-label="Grupi #<?= $gid ?> · <?= h(qta_date($g['start_date'])) ?> – <?= h(qta_date($g['end_date'])) ?>"
                                  aria-label="Zhvendos Grupin #<?= $gid ?> te një kurs tjetër">
                            <i class="bi bi-arrow-left-right" aria-hidden="true"></i>Zhvendos grupin
                          </button>
                        <?php else: ?>
                          <span class="text-muted small" title="Orari i grupit është ndërtuar nga temat e këtij kursi">Mbetet te ky kurs</span>
                        <?php endif; ?>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <div class="modal-footer-start">
            <a class="btn btn-ghost" href="lesson_groups.php?course_id=<?= $cid ?>"><i class="bi bi-calendar-week" aria-hidden="true"></i>Te regjistri i kurseve profesionale</a>
            <a class="btn btn-ghost" href="groups.php?course_id=<?= $cid ?>"><i class="bi bi-archive" aria-hidden="true"></i>Te regjistri i vjetër</a>
          </div>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Dialog: shto kurs -->
<div class="modal fade" id="addCourseModal" tabindex="-1" aria-labelledby="addCourseTitle" aria-hidden="true"<?= $openAdd ? ' data-open-on-load="add"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="courses.php" data-loading>
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="create_course">
      <div class="modal-header">
        <h2 class="modal-title" id="addCourseTitle"><i class="bi bi-journal-plus" aria-hidden="true"></i>Shto një kurs</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="acName">Emri i kursit <span class="req" aria-hidden="true">*</span></label>
            <input id="acName" type="text" name="name" class="form-control" placeholder="p.sh. Punime në lartësi dhe përdorimi i rripave" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>
          <div class="col-7">
            <label class="form-label" for="acCode">Kodi <span class="req" aria-hidden="true">*</span></label>
            <input id="acCode" type="text" name="code" class="form-control input-code" placeholder="p.sh. LRT-02" required aria-describedby="acCodeHelp" <?= $EDIT_MODE ? '' : 'disabled' ?>>
            <div class="form-text" id="acCodeHelp">I shkurtër dhe i veçantë për çdo kurs.</div>
          </div>
          <div class="col-5">
            <label class="form-label" for="acHours">Orë mësimi <span class="req" aria-hidden="true">*</span></label>
            <input id="acHours" type="number" name="hours" class="form-control" min="1" max="65535" step="1" inputmode="numeric" placeholder="p.sh. 40" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>
        </div>
        <p class="form-text mb-0 mt-3">Pas ruajtjes hapet kursi, që ta ndash në module dhe tema.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>><i class="bi bi-check-lg" aria-hidden="true"></i>Shto kursin</button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: fshi kurs -->
<div class="modal fade" id="deleteCourseModal" tabindex="-1" aria-labelledby="deleteCourseTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="courses.php" data-loading>
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="delete_course">
      <input type="hidden" name="course_id" id="deleteCourseId" value="">
      <div class="modal-body pt-4">
        <span class="confirm-icon is-danger"><i class="bi bi-trash" aria-hidden="true"></i></span>
        <h2 class="modal-title mb-2" id="deleteCourseTitle">Të fshihet kursi?</h2>
        <p class="mb-2"><b id="delName"></b> <span class="code text-muted" id="delCode"></span></p>
        <div id="delBlocked" class="alert alert-warning mb-0" hidden>
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
          <div><b>Ky kurs ka <span id="delGroups"></span>.</b> Një kurs me grupe nuk fshihet, që të mos humbasin datat e provimeve dhe pikët.</div>
        </div>
        <div id="delAllowed">
          <p class="text-muted mb-0">Kursi hiqet nga katalogu<span id="delStructure"></span>. <span id="delPlanned"></span>Kjo nuk mund të kthehet mbrapsht.</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-danger" type="submit" id="delSubmit" <?= $EDIT_MODE ? '' : 'disabled' ?>><i class="bi bi-trash" aria-hidden="true"></i>Po, fshije kursin</button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: zhvendos grupin e mëparshëm te një kurs tjetër -->
<div class="modal fade" id="moveGroupModal" tabindex="-1" aria-labelledby="moveGroupTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="moveGroupForm">
      <div class="modal-header">
        <div>
          <span class="eyebrow mb-0" id="mvGroupLabel">Grupi</span>
          <h2 class="modal-title" id="moveGroupTitle"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Zhvendos grupin te një kurs tjetër</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="mvTarget">Kursi i ri</label>
        <select id="mvTarget" class="form-select" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
          <option value="">Zgjidh kursin</option>
          <?php foreach ($allCourses as $ac): ?>
            <option value="<?= (int)$ac['id'] ?>"><?= h((string)$ac['name']) ?> (<?= h((string)$ac['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <p class="form-text mb-0">Përdore për të korrigjuar një gabim: të gjithë kursantët e grupit zhvendosen te kursi i ri, me datat dhe pikët e tyre.</p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit" id="mvSubmit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Zhvendos grupin</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'courses_inline_update.php';
const EDIT_ENABLED = <?= $EDIT_MODE ? 'true' : 'false' ?>;

function notify(type, text, opts={}){ return window.qtaToast ? window.qtaToast(text, type, opts.title, opts) : null; }
function cleanText(s){ return (s || '').replace(/\s+/g,' ').trim(); }

async function post(payload){
  const res = await window.qtaFetch.response(ENDPOINT, {
    method: 'POST',
    headers: {'Content-Type':'application/json','Accept':'application/json'},
    body: JSON.stringify(Object.assign({csrf: CSRF}, payload))
  });
  const json = await res.json().catch(()=> null);
  if (!json || !json.ok) {
    const err = new Error((json && json.error) || 'Ndryshimi nuk u ruajt. Provo sërish.');
    err.data = json;
    throw err;
  }
  return json;
}

/* ===== Redaktimi në tabelë (kodi, emri, orët) ===== */
function flashCell(cell, cls){ cell.classList.add(cls); setTimeout(()=>cell.classList.remove(cls), cls === 'cell-err' ? 1200 : 800); }

async function saveInline(el){
  const cell = el.closest('td.cell');
  const field = cell.dataset.field;
  const cid = parseInt(cell.dataset.id, 10);
  const prev = el.dataset.prev ?? '';
  const value = cleanText(el.textContent);
  el.textContent = value;
  if (value === cleanText(prev)) return;

  let problem = '';
  if (field === 'hours' && !/^\d+$/.test(value)) problem = 'Orët duhet të jenë një numër i plotë, p.sh. 40.';
  else if (field === 'hours' && parseInt(value, 10) < 1) problem = 'Orët duhet të jenë të paktën 1.';
  else if (field === 'code' && value === '') problem = 'Kodi nuk mund të mbetet bosh.';
  else if (field === 'name' && value === '') problem = 'Emri nuk mund të mbetet bosh.';
  if (problem){ el.textContent = prev; flashCell(cell, 'cell-err'); notify('warning', problem); return; }

  if (cell.classList.contains('cell-saving')) return;

  cell.setAttribute('aria-busy', 'true');
      cell.classList.add('cell-saving');
  try{
    const json = await post({action:'update_field', course_id: cid, field, value});
    el.textContent = json.display ?? value;
    el.dataset.prev = el.textContent;
    cell.classList.remove('cell-saving');
    flashCell(cell, 'cell-ok');
    notify('success', field === 'hours'
      ? 'Ndryshimi u ruajt. Kontrollo që modulet të kenë po aq orë (kolona "Modulet dhe temat").'
      : 'Ndryshimi u ruajt.');
  }catch(e){
    cell.classList.remove('cell-saving');
    el.textContent = prev;
    flashCell(cell, 'cell-err');
    /* Orët e kursit nën shumën e moduleve: dialog me vlerën më të vogël të lejuar. */
    const d = e.data && e.data.code === 'hours_limit' ? e.data.dialog : null;
    if (d && window.qtaConfirm) {
      el.focus();
      const ok = await window.qtaConfirm({ title: d.title, message: d.message, danger: false, icon: 'bi-exclamation-triangle',
        confirm: d.fix ? d.fix.label : 'Ndrysho orët', cancel: d.fix ? 'Ndrysho orët' : false });
      if (ok && d.fix) { el.textContent = String(d.fix.value); await saveInline(el); }
      else el.focus();
      return;
    }
    notify('danger', e.message);
  } finally { cell.classList.remove('cell-saving'); cell.removeAttribute('aria-busy'); }
}

/* Me delegim: rreshtat e rinj pas kërkimit ose faqosjes ndryshohen njësoj.
   app.js (defer) është gati te DOMContentLoaded. */
if (EDIT_ENABLED) document.addEventListener('DOMContentLoaded', () => {
  window.qtaEditable('#coursesTable td.cell .editable', (el, prevRaw) => {
    el.dataset.prev = cleanText(prevRaw);
    saveInline(el);
  });
});

/* ===== Fshirja e kursit: e bllokuar kur ka grupe ===== */
document.getElementById('deleteCourseModal')?.addEventListener('show.bs.modal', (ev) => {
  const btn = ev.relatedTarget;
  if (!btn) { ev.preventDefault(); return; }
  const groups = parseInt(btn.dataset.courseGroups || '0', 10);
  const planned = parseInt(btn.dataset.coursePlanned || '0', 10);
  const modules = parseInt(btn.dataset.courseModules || '0', 10);
  const topics = parseInt(btn.dataset.courseTopics || '0', 10);
  document.getElementById('deleteCourseId').value = btn.dataset.courseId;
  document.getElementById('delName').textContent = btn.dataset.courseName || '';
  document.getElementById('delCode').textContent = btn.dataset.courseCode ? '· ' + btn.dataset.courseCode : '';
  document.getElementById('delGroups').textContent = groups === 1 ? '1 grup' : groups + ' grupe';
  document.getElementById('delStructure').textContent = modules
    ? ' bashkë me ' + (modules === 1 ? '1 modul' : modules + ' module') + ' dhe ' + (topics === 1 ? '1 temë' : topics + ' tema')
    : '';
  document.getElementById('delPlanned').textContent = planned
    ? (planned === 1 ? '1 kursant e ka zgjedhur këtë kurs dhe do ta humbasë zgjedhjen. ' : planned + ' kursantë e kanë zgjedhur këtë kurs dhe do ta humbasin zgjedhjen. ')
    : '';
  document.getElementById('delBlocked').hidden = groups === 0;
  document.getElementById('delAllowed').hidden = groups > 0;
  document.getElementById('delSubmit').hidden = groups > 0;
});

/* ===== Zhvendosja e një grupi të mëparshëm te një kurs tjetër ===== */
(function(){
  const modal = document.getElementById('moveGroupModal');
  if (!modal) return;
  const sel = document.getElementById('mvTarget');
  let groupId = 0, currentCourse = 0, completed = false;

  modal.addEventListener('show.bs.modal', (ev) => {
    const btn = ev.relatedTarget;
    if (!btn) { ev.preventDefault(); return; }
    groupId = parseInt(btn.dataset.groupId || '0', 10);
    currentCourse = parseInt(btn.dataset.currentCourse || '0', 10);
    completed = btn.dataset.completed === '1';
    document.getElementById('mvGroupLabel').textContent = btn.dataset.groupLabel || 'Grupi';
    Array.from(sel.options).forEach(o => { o.disabled = parseInt(o.value, 10) === currentCourse; });
    sel.value = '';
  });

  document.getElementById('moveGroupForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const target = parseInt(sel.value || '0', 10);
    if (!target) { notify('warning', 'Zgjidh kursin ku do të zhvendoset grupi.'); sel.focus(); return; }
    let force = 0;
    if (completed) {
      const ok = await window.qtaConfirm({
        title: 'Ky grup është i mbyllur',
        message: 'Dokumentet e këtij grupi mund të jenë lëshuar tashmë. Je i sigurt që do ta zhvendosësh te një kurs tjetër?',
        confirm: 'Po, zhvendose', danger: false
      });
      if (!ok) return;
      force = 1;
    }
    const btn = document.getElementById('mvSubmit');
    if (btn.disabled) return;
  btn.disabled = true; btn.classList.add('is-loading'); btn.setAttribute('aria-busy', 'true');
    try {
      await post({action:'move_group_course', group_id: groupId, new_course_id: target, force});
      const targetName = (sel.options[sel.selectedIndex]?.text || 'kursi i ri').replace(/\s*\([^)]*\)\s*$/, '');
      try { sessionStorage.setItem('qtaFlash', 'Grupi #' + groupId + ' u zhvendos te "' + targetName + '".'); } catch (e) { /* pa njoftim */ }
      /* Pas ringarkimit rihapet dritarja e grupeve të kursit, nëse u nis prej saj. */
      if (window.qtaReopenAfterReload) window.qtaReopenAfterReload(modal);
      location.reload();
    } catch (e) {
      notify('danger', e.message);
    } finally { btn.disabled = false; btn.classList.remove('is-loading'); btn.removeAttribute('aria-busy'); }
  });
})();

/* Mesazhet pas ringarkimit */
document.addEventListener('DOMContentLoaded', () => {
  let queued = null;
  try { queued = sessionStorage.getItem('qtaFlash'); sessionStorage.removeItem('qtaFlash'); } catch (e) { queued = null; }
  if (queued) notify('success', queued);
<?php if ($ok): ?>
  notify('success', <?= json_encode($ok, JSON_UNESCAPED_UNICODE) ?>);
<?php endif; ?>
<?php if ($err): ?>
  notify('danger', <?= json_encode($err, JSON_UNESCAPED_UNICODE) ?>, {autohide: false});
<?php endif; ?>
});
</script>
</body>
</html>
