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
  header('Location: group_conversion.php' . ($qs ? '?' . http_build_query($qs) : ''));
  exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

require_once __DIR__ . '/../shared/themeli.php';
require_once __DIR__ . '/../shared/legacy_conversion.php';
require_once __DIR__ . '/../shared/partials/day_plan.php';

$gid = (int)($_GET['id'] ?? 0);
$V = null;
if ($gid > 0) {
  try {
    $V = qta_conv_view($pdo, $gid);
  } catch (QtaUserError $e) {
    $V = null;
  }
}
if ($V && $V['source']['group']['model'] !== 'legacy') {
  /* Grupi është konvertuar tashmë: hapet te regjistri i ri. */
  header('Location: lesson_group.php?id=' . $gid);
  exit;
}

$flash_ok = $_SESSION['flash_ok'] ?? null; unset($_SESSION['flash_ok']);

$NAV_ACTIVE = 'register_groups';
$HELP_TOPIC = 'conversion';
if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

$pageTitle = $V ? 'Konvertimi i Grupit #' . $gid : 'Grupi nuk u gjet';
$pageScripts = [qta_asset('app/assets/js/day-plan.js'), qta_asset('app/assets/js/group-conversion.js')];
require __DIR__ . '/../shared/app_head.php';

if ($V) {
  $src = $V['source'];
  $g = $src['group'];
  $course = $src['course'];
  $H = (int)$V['hours'];
  $start = (string)$g['start_date'];
  $end = (string)$g['end_date'];
  $members = $src['members'];
  $withExam = count(array_filter($members, static fn($m) => $m['exam_date'] !== null));
  $withScore = count(array_filter($members, static fn($m) => $m['final_score'] !== null));
  $pre = $V['pre'];
  $status = $V['status'];
  $plan = $V['plan'];
  $summary = $V['summary'];
  $draft = $V['draft'];
  $stale = $V['stale'];
  $blockers = $pre['blockers'];
  $memberCodes = ['too_many', 'in_other_group', 'same_course_twice'];
  $examCodes = ['exam_before_end', 'score_without_exam', 'score_out_of_range'];
  $memberIssues = array_values(array_filter($blockers, static fn($b) => in_array($b['code'], $memberCodes, true)));
  $examIssues = array_values(array_filter($blockers, static fn($b) => in_array($b['code'], $examCodes, true)));
  $courseReady = (bool)$src['check']['ready'];
  $canEdit = $EDIT_MODE && $plan !== null && !$stale;
  $convertible = $EDIT_MODE && !$blockers && !$stale && $plan !== null;
  $blockedReason = !$EDIT_MODE ? 'Për të konvertuar, shtyp "Lejo ndryshimet" lart djathtas.'
    : ($blockers ? 'Grupi ka probleme që duhen rregulluar para konvertimit (shih "Kontrollet").' : '');
  $savedLabel = $draft
    ? 'Drafti u ruajt ' . qta_ago((string)($draft['updated_at'] ?? $draft['created_at'])) . (!empty($draft['updated_by_name']) ? ' nga ' . $draft['updated_by_name'] : '')
    : '';
  $days = $summary ? $summary['teaching_days'] : 0;
  $calendarDays = count(qta_sched_dates($start, $end));
}

/** Një rresht i kontrolleve: ikonë + tekst (+ lidhja e rregullimit). */
$check = static function (string $key, string $kind, string $text, ?array $fix = null): string {
  $icons = ['ok' => 'bi-check-circle-fill', 'err' => 'bi-x-circle-fill', 'warn' => 'bi-exclamation-triangle-fill'];
  return '<li class="cv-check is-' . h($kind) . '" data-check="' . h($key) . '">'
    . '<i class="bi ' . $icons[$kind] . '" aria-hidden="true"></i>'
    . '<span><span data-check-text>' . h($text) . '</span>'
    . ($fix ? ' <a class="cv-check-fix" href="' . h((string)$fix['href']) . '">' . h((string)$fix['label']) . '</a>' : '')
    . '</span></li>';
};
?>

<main class="app-main" id="main" tabindex="-1">
<?php if (!$V): ?>
  <header class="page-head">
    <div class="page-head-main">
      <ol class="crumbs">
        <li><a href="groups.php">Regjistri i vjetër i kurseve profesionale</a></li>
        <li><a href="group_conversions.php">Konvertimi i grupeve</a></li>
      </ol>
      <h1 class="page-title">Grupi nuk u gjet</h1>
    </div>
  </header>
  <?= qta_empty('Grupi nuk u gjet', 'Ndoshta u fshi, ose adresa është e gabuar. Kthehu te lista e konvertimit.', 'bi-archive',
        '<a class="btn btn-secondary" href="group_conversions.php">Te konvertimi i grupeve</a>') ?>
<?php else: ?>
  <header class="page-head">
    <div class="page-head-main">
      <ol class="crumbs">
        <li><a href="groups.php">Regjistri i vjetër i kurseve profesionale</a></li>
        <li><a href="group_conversions.php">Konvertimi i grupeve</a></li>
        <li aria-current="page">Grupi #<?= $gid ?></li>
      </ol>
      <h1 class="page-title">Konvertimi i Grupit #<?= $gid ?></h1>
      <p class="page-lead lg-lead">
        <span><?= h($course['name']) ?></span>
        <span><?= h(qta_date($start)) ?> – <?= h(qta_date($end)) ?></span>
        <span><?= h(qta_hours_label($H)) ?> · <?= h(qta_plural(count($members), 'kursant', 'kursantë')) ?></span>
        <?= qta_status($status['label'], $status['variant'], $status['icon']) ?>
      </p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
    </div>
  </header>

  <?php
    $editModeBannerTitle = 'Po shikon propozimin.';
    $editModeBannerText = 'Për ta ndryshuar, ruajtur ose konvertuar grupin, shtyp "Lejo ndryshimet" lart djathtas.';
    require __DIR__ . '/../shared/partials/edit_mode_off_banner.php';
  ?>

  <div class="notice has-action is-warning mb-3" data-cv-stale role="status"<?= $stale ? '' : ' hidden' ?>>
    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
    <span data-cv-stale-text><b>Ky draft u ndryshua ndërkohë.</b> Të dhënat e grupit, të kursantëve ose të kursit ndryshuan pasi u ruajt propozimi. Rifresko të dhënat para se të vazhdosh.</span>
    <?php if ($EDIT_MODE): ?>
      <button class="btn btn-secondary btn-sm notice-action" type="button" data-cv-refresh><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Rifresko të dhënat</button>
    <?php endif; ?>
  </div>

  <?php if ($blockers): ?>
    <div class="alert <?= $pre['impossible'] ? 'alert-danger' : 'alert-warning' ?> cv-blockers" role="note">
      <i class="bi <?= $pre['impossible'] ? 'bi-slash-circle' : 'bi-exclamation-triangle' ?>" aria-hidden="true"></i>
      <div>
        <span class="alert-title"><?= $pre['impossible'] ? 'Ky grup nuk mund të konvertohet me këto data.' : 'Rregulloji këto para konvertimit' ?></span>
        <ul>
          <?php foreach ($blockers as $b): ?>
            <li><?= h($b['text']) ?><?php if (!empty($b['fix'])): ?> <a class="alert-link" href="<?= h($b['fix']['href']) ?>"><?= h($b['fix']['label']) ?></a><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <div class="cv-layout">
    <div class="cv-main">
      <section class="section cv-plan" aria-labelledby="cvPlanTitle">
        <div class="section-head">
          <div>
            <h2 class="section-title" id="cvPlanTitle">Propozim për konvertim</h2>
            <?php if ($plan): ?>
              <p class="section-meta mb-0"><?= $draft
                ? h('Drafti · ruajtur ' . qta_datetime((string)($draft['updated_at'] ?? $draft['created_at'])) . (!empty($draft['updated_by_name']) ? ' nga ' . $draft['updated_by_name'] : ''))
                : 'Propozim automatik — ende pa ruajtur.' ?></p>
            <?php endif; ?>
          </div>
          <?php if ($canEdit): ?>
            <div class="cv-tools" role="toolbar" aria-label="Veprime për planin">
              <button class="btn btn-ghost btn-sm" type="button" data-cv-undo disabled><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Zhbëj</button>
              <button class="btn btn-secondary btn-sm" type="button" data-cv-rebalance disabled><i class="bi bi-magic" aria-hidden="true"></i>Rishpërndaj automatikisht</button>
              <button class="btn btn-ghost btn-sm" type="button" data-cv-reset><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Rikthe propozimin</button>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($plan): ?>
          <p class="cv-intro">
            Regjistri i vjetër nuk tregon në cilat data u zhvillua mësimi. Sistemi i ndau <?= h(qta_hours_label($H)) ?> brenda periudhës historike, me të shumtën <?= QTA_DAY_MAX_HOURS ?> orë në ditë.
            <?= $canEdit ? 'Kontrollo ditët dhe ndrysho ku duhet: kliko një datë, ose shkruaj orët me shifra.' : '' ?>
          </p>
          <?= qta_render_day_plan($plan['days'], $start, $end, [
            'id' => 'cvPlan', 'editable' => $canEdit, 'manual' => $plan['manual'], 'notes' => $plan['notes'],
          ]) ?>
        <?php elseif (!$courseReady): ?>
          <?= qta_empty('Propozimi del kur kursi të jetë gati',
                'Orari ndërtohet nga modulet dhe temat e kursit. Plotësoji te "Katalogu i kurseve", pastaj kthehu këtu.',
                'bi-journal-x', '<a class="btn btn-secondary" href="course.php?id=' . (int)$course['id'] . '">Hape kursin</a>') ?>
        <?php else: ?>
          <?= qta_empty('Periudha nuk i mban orët e kursit',
                'Me të shumtën ' . QTA_DAY_MAX_HOURS . ' orë në ditë, ' . qta_hours_label($H) . ' nuk zënë brenda ' . qta_plural($calendarDays, 'dite', 'ditëve') . ' historike. Arsyeja e plotë është më sipër.',
                'bi-calendar-x') ?>
        <?php endif; ?>
      </section>

      <?php if ($courseReady && $src['topics']): ?>
        <?php
          $mods = [];
          foreach ($src['topics'] as $t) {
            $mods[$t['module_seq']] ??= ['title' => $t['module_title'], 'hours' => $t['module_hours'], 'topics' => []];
            $mods[$t['module_seq']]['topics'][] = $t;
          }
        ?>
        <details class="panel cv-curriculum">
          <summary>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
            <span class="cv-curriculum-title">Modulet dhe temat që do të ruhen</span>
            <span class="section-meta"><?= h(qta_plural(count($mods), 'modul', 'module')) ?> · <?= h(qta_plural(count($src['topics']), 'temë', 'tema')) ?></span>
          </summary>
          <p class="cv-intro">Modulet dhe temat do të ruhen sipas strukturës aktuale të kursit dhe më pas do të mbeten historike për këtë grup: ndryshimet e mëvonshme të kursit nuk e prekin.</p>
          <ol class="cv-modules">
            <?php foreach ($mods as $mseq => $m): ?>
              <li>
                <p class="cv-module-name">Moduli <?= (int)$mseq ?> — <?= h((string)$m['title']) ?> <span class="cv-module-hours"><?= h(qta_hours_label((int)$m['hours'])) ?></span></p>
                <ol class="cv-topics">
                  <?php foreach ($m['topics'] as $t): ?>
                    <li><span class="cv-topic-num"><?= (int)$t['topic_seq'] ?>.</span><span><?= h((string)$t['topic_title']) ?></span><span class="cv-topic-hours"><?= h(qta_hours_label((int)$t['hours'])) ?></span></li>
                  <?php endforeach; ?>
                </ol>
              </li>
            <?php endforeach; ?>
          </ol>
        </details>
      <?php endif; ?>
    </div>

    <aside class="cv-side" aria-label="Gjendja e konvertimit">
      <?php if ($plan): ?>
        <section class="panel cv-summary" aria-labelledby="cvSumTitle">
          <h2 class="cv-panel-title" id="cvSumTitle">Orët e planit</h2>
          <p class="cv-total"><b data-cv-planned><?= (int)$summary['planned'] ?></b><span> / <?= $H ?> orë</span></p>
          <progress class="hours-bar<?= $summary['planned'] === $H ? ' is-full' : ($summary['planned'] > $H ? ' is-over' : '') ?>" max="<?= $H ?>" value="<?= min((int)$summary['planned'], $H) ?>" data-cv-meter aria-hidden="true"></progress>
          <dl class="cv-counts">
            <div><dt>Ditë mësimi</dt><dd data-cv-teach><?= (int)$summary['teaching_days'] ?></dd></div>
            <div><dt>Pa mësim</dt><dd data-cv-off><?= (int)$summary['off_days'] ?></dd></div>
            <div><dt>Në ditë</dt><dd>deri në <?= QTA_DAY_MAX_HOURS ?> orë</dd></div>
          </dl>
          <?php
            $first = $summary['issues'][0]['text'] ?? '';
            $vKind = $first !== '' ? 'err' : (($stale || $blockers || $summary['sundays']) ? 'warn' : 'ok');
            $vText = $first !== '' ? $first : ($stale ? 'Rifresko të dhënat para se të vazhdosh'
              : ($blockers ? 'Orari është i vlefshëm — rregullo problemet e grupit'
              : ($summary['sundays'] ? 'Orari është i vlefshëm — kontrollo të dielat' : 'Orari është gati për konvertim')));
            $vIcon = ['ok' => 'bi-check-circle-fill', 'warn' => 'bi-exclamation-triangle-fill', 'err' => 'bi-x-circle-fill'][$vKind];
          ?>
          <p class="cv-verdict is-<?= $vKind ?>" data-cv-verdict role="status" aria-live="polite" data-text="<?= h($vText) ?>">
            <i class="bi <?= $vIcon ?>" aria-hidden="true"></i><span><?= h($vText) ?></span>
          </p>
        </section>
      <?php endif; ?>

      <section class="panel cv-checks" aria-labelledby="cvChecksTitle">
        <h2 class="cv-panel-title" id="cvChecksTitle">Kontrollet</h2>
        <ul class="cv-check-list">
          <?php if ($plan):
            $hoursIssue = null;
            foreach ($summary['issues'] as $i) if (in_array($i['code'], ['missing_hours', 'extra_hours'], true)) $hoursIssue = $i;
            $gap = abs((int)$summary['planned'] - $H); ?>
            <?= $check('hours', $hoursIssue ? 'err' : 'ok', $hoursIssue
                  ? ($summary['planned'] < $H ? ($gap === 1 ? 'Mungon 1 orë' : 'Mungojnë ' . $gap . ' orë') : ($gap === 1 ? 'Është vendosur 1 orë më shumë' : 'Janë vendosur ' . $gap . ' orë më shumë'))
                  : $summary['planned'] . ' nga ' . $H . ' orë') ?>
            <?= $check('max', 'ok', 'Asnjë ditë mbi ' . QTA_DAY_MAX_HOURS . ' orë') ?>
            <?= $check('start', (int)$plan['days'][$start] > 0 ? 'ok' : 'err', 'Fillimi ' . qta_date($start) . ((int)$plan['days'][$start] > 0 ? ' ka mësim' : ' pa mësim')) ?>
            <?= $check('end', (int)$plan['days'][$end] > 0 ? 'ok' : 'err', 'Mbarimi ' . qta_date($end) . ((int)$plan['days'][$end] > 0 ? ' ka mësim' : ' pa mësim')) ?>
            <?= $check('sundays', $summary['sundays'] ? 'warn' : 'ok', $summary['sundays']
                  ? 'Përdor ' . (count($summary['sundays']) === 1 ? '1 të diel' : count($summary['sundays']) . ' të diela') . ' (' . implode(', ', array_map('qta_date', $summary['sundays'])) . ')'
                  : 'Pa mësim të dielave') ?>
          <?php elseif ($pre['impossible']): ?>
            <?= $check('capacity', 'err', (string)$blockers[0]['short']) ?>
          <?php endif; ?>
          <?php if ($courseReady): ?>
            <?= $check('course', 'ok', 'Struktura e kursit është e plotë') ?>
            <?= $check('structure', 'ok', 'Modulet dhe temat: ' . qta_hours_label($H)) ?>
          <?php else: ?>
            <?= $check('course', 'err', 'Kursi nuk ka ende strukturë të plotë', ['href' => 'course.php?id=' . (int)$course['id'], 'label' => 'Hape kursin']) ?>
          <?php endif; ?>
          <?= $memberIssues
                ? $check('members', 'err', (string)$memberIssues[0]['short'], $memberIssues[0]['fix'])
                : $check('members', 'ok', count($members) ? qta_plural(count($members), 'kursant', 'kursantë') . ', pa probleme' : 'Grupi nuk ka kursantë') ?>
          <?= $examIssues
                ? $check('exams', 'err', (string)$examIssues[0]['short'], $examIssues[0]['fix'])
                : $check('exams', 'ok', $withExam ? 'Provimet dhe pikët janë të vlefshme' : 'Ende pa data provimi') ?>
          <?php if ($stale): ?>
            <?= $check('fresh', 'err', 'Të dhënat ndryshuan pas draftit') ?>
          <?php endif; ?>
        </ul>
      </section>

      <section class="panel cv-facts" aria-labelledby="cvFactsTitle">
        <h2 class="cv-panel-title" id="cvFactsTitle">Të dhënat historike</h2>
        <dl class="kv">
          <dt>Kursi</dt><dd><?= h($course['name']) ?></dd>
          <dt>Fillimi</dt>
          <dd class="cv-locked"><i class="bi bi-lock-fill" aria-hidden="true"></i><span><?= h(qta_date($start)) ?> <span class="cv-muted"><?= h(qta_weekday(qta_sched_weekday($start))) ?></span></span></dd>
          <dt>Mbarimi</dt>
          <dd class="cv-locked"><i class="bi bi-lock-fill" aria-hidden="true"></i><span><?= h(qta_date($end)) ?> <span class="cv-muted"><?= h(qta_weekday(qta_sched_weekday($end))) ?></span></span></dd>
          <dt>Periudha</dt><dd><?= h(qta_plural($calendarDays, 'ditë', 'ditë')) ?> kalendarike</dd>
          <dt>Orët e kursit</dt><dd><?= $H ?></dd>
          <dt>Kursantë</dt>
          <dd><?= count($members) ?><?php if ($members): ?> <span class="cv-muted">· <?= $withExam ?> me provim, <?= $withScore ?> me pikë</span><?php endif; ?></dd>
          <?php if ((int)$g['is_completed'] === 1): ?><dt>Gjendja</dt><dd>I mbyllur</dd><?php endif; ?>
        </dl>
        <p class="cv-note"><i class="bi bi-lock" aria-hidden="true"></i>Këto data ruhen si prejardhja historike e konvertimit dhe nuk ndryshojnë. Pas konvertimit, periudha operative mund të korrigjohet te faqja e grupit.</p>
      </section>
    </aside>
  </div>

  <?php if ($EDIT_MODE && $plan): ?>
    <div class="cv-bar" role="region" aria-label="Ruajtja dhe konvertimi">
      <p class="cv-bar-status">
        <i class="bi bi-check-circle-fill" data-cv-bar-icon aria-hidden="true"></i>
        <span class="cv-bar-text" data-cv-bar-text><b><?= (int)$summary['planned'] ?> / <?= $H ?> orë</b></span>
        <span class="cv-saved" data-cv-saved><?= h($savedLabel ?: 'Propozim automatik, ende pa ruajtur') ?></span>
      </p>
      <div class="cv-bar-actions">
        <button class="btn btn-secondary" type="button" data-cv-save><i class="bi bi-floppy" aria-hidden="true"></i>Ruaj draftin</button>
        <button class="btn btn-primary" type="button" data-cv-convert><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Konverto grupin</button>
      </div>
      <p class="visually-hidden" id="cvConvertWhy"></p>
    </div>
  <?php endif; ?>
<?php endif; ?>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php if ($V): ?>
<script type="application/json" id="cvConfig"><?= json_encode([
  'csrf' => $CSRF, 'endpoint' => 'group_conversion_update.php', 'group' => $gid,
  'revision' => $draft ? (int)$draft['revision'] : 0, 'source' => $V['fingerprint'], 'stale' => $stale,
  'hours' => $H, 'start' => $start, 'end' => $end, 'course' => $course['name'],
  'edit' => $EDIT_MODE, 'convertible' => $convertible, 'blocked' => (bool)$blockers, 'blockedReason' => $blockedReason,
  'savedLabel' => $savedLabel, 'flash' => $flash_ok,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
</body>
</html>
