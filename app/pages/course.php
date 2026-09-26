<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ------------------------------
   Guard: admin OSE editor i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$userStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, r.name AS role_name
    FROM users u JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid LIMIT 1
");
$userStmt->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);
$roleName = strtolower((string)($currentUser['role_name'] ?? ''));
if (!$currentUser || !in_array($roleName, ['administrator', 'editor'], true)) {
    header('Location: selectProfile.php'); exit;
}

/* Kyçi i ndryshimeve (i njëjtë për të gjitha faqet) */
if (isset($_GET['edit'])) {
    $_SESSION['edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
    $qs = $_GET; unset($qs['edit']);
    header('Location: course.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}
$EDIT_MODE = !empty($_SESSION['edit_mode']);

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

require_once __DIR__ . '/../shared/themeli.php';
require_once __DIR__ . '/../shared/curriculum.php';
require_once __DIR__ . '/../shared/partials/course_structure.php';

$courseId = (int)($_GET['id'] ?? 0);
$course = $courseId > 0 ? qta_course_find($pdo, $courseId) : null;
$modules = $course ? qta_course_modules($pdo, $courseId) : [];
$check = $course ? qta_course_check($course, $modules) : null;
$usage = $course ? qta_course_usage($pdo, $courseId) : ['scheduled' => 0, 'legacy' => 0];

$flashOk = $_SESSION['flash']['ok'] ?? null;
unset($_SESSION['flash']['ok']);

$NAV_ACTIVE = 'courses';
$HELP_TOPIC = 'course';
if ($roleName === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

$pageTitle = $course ? (string)$course['name'] . ' · Kursi' : 'Kursi nuk u gjet';
$pageScripts = [qta_asset('app/assets/js/curriculum.js')];
$editHref = 'course.php?' . http_build_query(['id' => $courseId, 'edit' => '1']);
$addHref = 'course.php?' . http_build_query(['id' => $courseId, 'edit' => '1', 'add' => '1']);
require __DIR__ . '/../shared/app_head.php';
?>

<main class="app-main" id="main" tabindex="-1">
<?php if (!$course): ?>
  <header class="page-head">
    <div class="page-head-main">
      <ol class="crumbs"><li><a href="courses.php">Kurset</a></li></ol>
      <h1 class="page-title">Kursi nuk u gjet</h1>
      <p class="page-lead">Ky kurs nuk ekziston më ose adresa është e gabuar.</p>
    </div>
  </header>
  <?= qta_empty('Kursi nuk u gjet', 'Kthehu te lista e kurseve dhe zgjidhe sërish.', 'bi-journal-x', '<a class="btn btn-secondary" href="courses.php">Te kurset</a>') ?>
<?php else: ?>
  <header class="page-head">
    <div class="page-head-main">
      <ol class="crumbs"><li><a href="courses.php">Kurset</a></li><li aria-current="page"><span class="code"><?= h((string)$course['code']) ?></span></li></ol>
      <h1 class="page-title" id="courseTitle"><?= h((string)$course['name']) ?></h1>
      <p class="page-lead">Kursi ndahet në module dhe çdo modul në tema, me radhë. Grupet me orar i zhvillojnë pikërisht në këtë radhë, ditë pas dite.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <?php if ($EDIT_MODE): ?>
        <button class="btn btn-secondary" type="button" data-bs-toggle="modal" data-bs-target="#courseDialog">
          <i class="bi bi-pencil" aria-hidden="true"></i>Ndrysho kursin
        </button>
        <button class="btn btn-primary" type="button" data-cur-open="module-add">
          <i class="bi bi-plus-lg" aria-hidden="true"></i>Shto modul
        </button>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= h($addHref) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i>Shto modul</a>
      <?php endif; ?>
    </div>
  </header>

  <dl class="kv kv-2 mb-4 cur-facts">
    <dt>Orët e kursit</dt><dd data-course-hours><?= h(qta_hours_label((int)$course['hours'])) ?></dd>
    <dt>Kodi</dt><dd><span class="code" data-course-code><?= h((string)$course['code']) ?></span></dd>
  </dl>

  <?php
    $editModeBannerText = 'Për të shtuar, renditur ose ndryshuar modulet dhe temat, shtyp "Lejo ndryshimet" lart djathtas.';
    require __DIR__ . '/../shared/partials/edit_mode_off_banner.php';
  ?>

  <div id="courseStructure" data-course="<?= (int)$course['id'] ?>" aria-live="off">
    <?= qta_render_course_structure($course, $modules, $check, $EDIT_MODE, $usage) ?>
  </div>
  <p class="visually-hidden" id="curAnnounce" role="status" aria-live="polite"></p>

  <?php if ($check['ready']): ?>
    <div class="notice is-sunken mt-4">
      <i class="bi bi-calendar-week" aria-hidden="true"></i>
      <span><b>Gati për grup.</b> Krijo një grup me këtë kurs te <a href="lesson_groups.php?<?= h(http_build_query(['edit' => '1', 'create' => '1', 'course_id' => $courseId])) ?>">Grupet</a>: zgjedh datën e fillimit dhe orët në ditë, orari ndërtohet vetë.</span>
    </div>
  <?php endif; ?>
<?php endif; ?>
</main>

<?php if ($course && $EDIT_MODE): ?>
<!-- Dialog: shto / ndrysho modul -->
<div class="modal fade" id="moduleDialog" tabindex="-1" aria-labelledby="moduleDialogTitle" aria-hidden="true"<?= isset($_GET['add']) ? ' data-open-on-load="add"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" data-cur-form="module" novalidate>
      <input type="hidden" name="module_id" value="">
      <div class="modal-header">
        <h2 class="modal-title" id="moduleDialogTitle"><i class="bi bi-collection" aria-hidden="true"></i><span data-dialog-title>Shto një modul</span></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger py-2" data-form-error role="alert" hidden></div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="mdTitle">Emri i modulit <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="mdTitle" name="title" type="text" maxlength="<?= QTA_MODULE_TITLE_MAX ?>" required placeholder="p.sh. Word" autocomplete="off">
          </div>
          <div class="col-6">
            <label class="form-label" for="mdHours">Orë <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="mdHours" name="hours" type="number" min="1" max="<?= QTA_HOURS_MAX ?>" step="1" inputmode="numeric" required placeholder="p.sh. 10" aria-describedby="mdHoursHelp">
            <div class="form-text" id="mdHoursHelp">Orë të plota. Temat e modulit duhet të mblidhen në këtë numër.</div>
          </div>
          <div class="col-6">
            <label class="form-label" for="mdPosition">Vendi në radhë</label>
            <select class="form-select" id="mdPosition" name="position" aria-describedby="mdPositionHelp"></select>
            <div class="form-text" id="mdPositionHelp">1 = moduli i parë që zhvillohet.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-primary" data-submit-label><i class="bi bi-check-lg" aria-hidden="true"></i><span>Shto modulin</span></button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: ndrysho temë -->
<div class="modal fade" id="topicDialog" tabindex="-1" aria-labelledby="topicDialogTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" data-cur-form="topic" novalidate>
      <input type="hidden" name="topic_id" value="">
      <input type="hidden" name="module_id" value="">
      <div class="modal-header">
        <div>
          <span class="eyebrow mb-0" data-dialog-eyebrow>Moduli</span>
          <h2 class="modal-title" id="topicDialogTitle"><i class="bi bi-list-ol" aria-hidden="true"></i>Ndrysho temën</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger py-2" data-form-error role="alert" hidden></div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="tdTitle">Emri i temës <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="tdTitle" name="title" type="text" maxlength="<?= QTA_TOPIC_TITLE_MAX ?>" required autocomplete="off">
          </div>
          <div class="col-6">
            <label class="form-label" for="tdHours">Orë <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="tdHours" name="hours" type="number" min="1" max="<?= QTA_HOURS_MAX ?>" step="1" inputmode="numeric" required>
          </div>
          <div class="col-6">
            <label class="form-label" for="tdPosition">Vendi në modul</label>
            <select class="form-select" id="tdPosition" name="position"></select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>Ruaj ndryshimet</button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: të dhënat e kursit -->
<div class="modal fade" id="courseDialog" tabindex="-1" aria-labelledby="courseDialogTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" data-cur-form="course" novalidate>
      <div class="modal-header">
        <h2 class="modal-title" id="courseDialogTitle"><i class="bi bi-book" aria-hidden="true"></i>Ndrysho kursin</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger py-2" data-form-error role="alert" hidden></div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="cdName">Emri i kursit <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="cdName" name="name" type="text" maxlength="200" required value="<?= h((string)$course['name']) ?>">
          </div>
          <div class="col-7">
            <label class="form-label" for="cdCode">Kodi <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control input-code" id="cdCode" name="code" type="text" maxlength="50" required value="<?= h((string)$course['code']) ?>">
          </div>
          <div class="col-5">
            <label class="form-label" for="cdHours">Orë mësimi <span class="req" aria-hidden="true">*</span></label>
            <input class="form-control" id="cdHours" name="hours" type="number" min="1" max="<?= QTA_HOURS_MAX ?>" step="1" inputmode="numeric" required value="<?= (int)$course['hours'] ?>">
          </div>
        </div>
        <p class="form-text mb-0 mt-3">Grupet me orar që ekzistojnë nuk ndryshojnë: ata kanë kopjen e tyre të temave dhe orëve.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>Ruaj ndryshimet</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php if ($course): ?>
<script type="application/json" id="curConfig"><?= json_encode([
  'csrf' => $CSRF, 'course' => (int)$course['id'], 'edit' => $EDIT_MODE,
  'endpoint' => 'course_structure_update.php', 'flash' => $flashOk,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
</body>
</html>
