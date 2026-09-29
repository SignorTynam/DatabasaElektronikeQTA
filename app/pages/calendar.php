<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* ------------------------------
   Guard: admin/editor i loguar (si regjistri i kurseve profesionale)
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

require_once __DIR__ . '/../shared/themeli.php';
require_once __DIR__ . '/../shared/group_calendar.php';
require_once __DIR__ . '/../shared/group_list.php';

/* ------------------------------
   Gjendja nga adresa: muaji, pamja, filtrat dhe grupi i hapur.
   Kalendari vetëm lexon; datat ndryshohen te faqja e grupit.
------------------------------- */
$today = date('Y-m-d');
$F = qta_group_filters($_GET);
$view = qta_list_choice($_GET['view'] ?? '', ['timeline', 'list']);
$openGroup = is_string($_GET['group'] ?? null) ? qta_parse_int_input($_GET['group'], 1, PHP_INT_MAX) : null;

/* calendar.php?group=12: hapet muaji ku fillon grupi; një grup i regjistrit të vjetër
   shfaqet edhe pa filtrin e tij, sepse u kërkua me emër. */
$brief = $openGroup ? qta_calendar_group_brief($pdo, $openGroup) : null;
$M = qta_calendar_month($_GET['month'] ?? ($brief ? substr($brief['start'], 0, 7) : null), $today);
$legacy = ($_GET['legacy'] ?? '') === '1' || ($brief !== null && $brief['legacy'] && !isset($_GET['legacy']));
$feed = qta_calendar_events($pdo, $M['from'], $M['to'], $legacy, $today);
$courses = qta_course_options($pdo);

$states = ['active' => 'Në mësim', 'upcoming' => 'Nisin së shpejti', 'awaiting_close' => 'Presin mbylljen', 'closed' => 'Të mbyllura'];

$NAV_ACTIVE = 'calendar';
$HELP_TOPIC = 'calendar';
if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

$pageTitle = 'Kalendari · ' . $M['label'];
$pageScripts = [qta_asset('app/assets/js/calendar.js')];
require __DIR__ . '/../shared/app_head.php';
?>

<main class="app-main is-wide" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Kalendari</h1>
      <p class="page-lead">Kur fillon dhe kur mbaron çdo grup i kurseve profesionale.</p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
    </div>
  </header>

  <section class="cal" data-cal aria-labelledby="calTitle">
    <div class="cal-toolbar">
      <div class="cal-nav no-print">
        <button class="btn btn-secondary" type="button" data-cal-today>Sot</button>
        <button class="btn btn-secondary btn-icon" type="button" data-cal-step="-1" aria-label="Muaji i kaluar" data-tip="Muaji i kaluar">
          <i class="bi bi-chevron-left" aria-hidden="true"></i>
        </button>
        <button class="btn btn-secondary btn-icon" type="button" data-cal-step="1" aria-label="Muaji tjetër" data-tip="Muaji tjetër">
          <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </button>
      </div>

      <h2 class="cal-title" id="calTitle">
        <button class="cal-period" type="button" data-cal-jump aria-haspopup="dialog" aria-describedby="calJumpHint">
          <span data-cal-label><?= h($M['label']) ?></span><i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
      </h2>
      <span class="visually-hidden" id="calJumpHint">Zgjidh një datë tjetër në kalendar</span>
      <?php /* Ura te kalendari i përbashkët i datave (date-picker.js): data e zgjedhur hap muajin e saj. */ ?>
      <input class="visually-hidden" type="text" tabindex="-1" aria-hidden="true"
             data-cal-jump-field data-dmy data-dmy-ready data-dmy-required data-dmy-title="Shko te data">

      <div class="cal-tools no-print">
        <div class="segmented" role="radiogroup" aria-label="Pamja e kalendarit">
          <button type="button" role="radio" aria-checked="<?= $view === 'list' ? 'false' : 'true' ?>" data-cal-view="timeline">
            <i class="bi bi-calendar-range" aria-hidden="true"></i>Kalendar
          </button>
          <button type="button" role="radio" aria-checked="<?= $view === 'list' ? 'true' : 'false' ?>" data-cal-view="list">
            <i class="bi bi-list-ul" aria-hidden="true"></i>Listë
          </button>
        </div>

        <details class="lf-more" data-cal-more>
          <summary class="lf-more-btn" title="Filtra të tjerë">
            <i class="bi bi-funnel" aria-hidden="true"></i><span class="lf-more-text">Filtra</span>
            <span class="lf-more-count" data-cal-more-count<?= $F['course_id'] === '' ? ' hidden' : '' ?>>1<span class="visually-hidden"> aktiv</span></span>
          </summary>
          <div class="lf-panel">
            <div class="lf-field">
              <label class="form-label" for="calCourse">Kursi</label>
              <select class="form-select form-select-sm" id="calCourse" data-cal-course>
                <option value="">Çdo kurs</option>
                <?php foreach ($courses as $cid => $cname): ?>
                  <option value="<?= h((string)$cid) ?>"<?= (string)$cid === $F['course_id'] ? ' selected' : '' ?>><?= h($cname) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="lf-panel-foot">
              <button class="btn btn-ghost btn-sm" type="button" data-cal-more-reset>Hiq këta filtra</button>
            </div>
          </div>
        </details>
      </div>
      <span class="cal-progress" aria-hidden="true"></span>
    </div>

    <div class="cal-chips lf-chips no-print">
      <div class="lf-chip-set" role="group" aria-label="Gjendja e grupeve">
        <button class="chip<?= $F['status'] === '' ? ' is-on' : '' ?>" type="button" aria-pressed="<?= $F['status'] === '' ? 'true' : 'false' ?>" data-cal-status="">
          Të gjitha <span class="chip-count" data-cal-count=""></span>
        </button>
        <?php foreach ($states as $key => $label): $on = $F['status'] === $key; ?>
          <button class="chip<?= $on ? ' is-on' : '' ?>" type="button" aria-pressed="<?= $on ? 'true' : 'false' ?>" data-cal-status="<?= h($key) ?>">
            <span class="cal-swatch tone-<?= h(qta_group_state_look($key)['tone']) ?>" aria-hidden="true"></span><?= h($label) ?>
            <span class="chip-count" data-cal-count="<?= h($key) ?>"></span>
          </button>
        <?php endforeach; ?>
      </div>
      <button class="chip cal-legacy-chip" type="button" aria-pressed="<?= $legacy ? 'true' : 'false' ?>" data-cal-legacy hidden>
        <i class="bi bi-archive" aria-hidden="true"></i>Regjistri i vjetër <span class="chip-count" data-cal-legacy-count></span>
      </button>
      <button class="chip is-filter" type="button" data-cal-course-chip hidden>
        <span data-cal-course-chip-text></span><i class="bi bi-x" aria-hidden="true"></i>
      </button>
    </div>

    <div class="cal-body" data-cal-body>
      <div class="cal-skeleton" aria-hidden="true">
        <?php for ($i = 0; $i < 5; $i++): ?><span class="skeleton"></span><?php endfor; ?>
      </div>
      <noscript>
        <?= qta_empty('Kalendari ka nevojë për JavaScript', 'Grupet, datat dhe kursantët i gjen edhe te Regjistri i kurseve profesionale.', 'bi-calendar-x',
              '<a class="btn btn-secondary" href="lesson_groups.php">Regjistri i kurseve profesionale</a>', 'is-compact') ?>
      </noscript>
    </div>
    <p class="visually-hidden" id="calKeys">Shigjetat lart dhe poshtë lëvizin nga një grup te tjetri, Page Up dhe Page Down ndërrojnë muajin, Enter hap grupin.</p>
    <p class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" data-cal-announce></p>
  </section>
</main>

<!-- Dritarja e një grupi: pasqyrë dhe lidhje; ndryshimet bëhen te faqja e grupit. -->
<div class="modal fade modal-record cal-modal" id="calGroup" tabindex="-1" aria-labelledby="calgTitle" aria-describedby="calgMeta" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <div class="calg-head">
          <span class="eyebrow mb-0" data-calg-eyebrow></span>
          <h2 class="modal-title" id="calgTitle" data-calg-title>Grupi</h2>
          <div class="modal-meta" id="calgMeta" data-calg-meta></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body calg-body" data-calg-body></div>
      <div class="modal-footer">
        <div class="modal-footer-start">
          <a class="btn btn-ghost" href="#" data-calg-course hidden><i class="bi bi-book" aria-hidden="true"></i>Hap kursin</a>
        </div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
        <a class="btn btn-primary" href="#" data-calg-open hidden><i class="bi bi-calendar-week" aria-hidden="true"></i>Hap grupin</a>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<script type="application/json" id="calConfig"><?= json_encode([
  'endpoint' => 'calendar_data.php',
  'registry' => 'lesson_groups.php',
  'today' => $today,
  'month' => $M['month'],
  'view' => $view,
  'status' => $F['status'],
  'course' => $F['course_id'],
  'legacy' => $legacy,
  'group' => $openGroup,
  'courses' => (object)$courses,
  'feed' => $feed,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</body>
</html>
