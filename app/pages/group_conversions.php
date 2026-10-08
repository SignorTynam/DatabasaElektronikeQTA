<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

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
  qta_session_put(['edit_mode'], filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN));
  $qs = $_GET; unset($qs['edit']);
  header('Location: group_conversions.php' . ($qs ? '?' . http_build_query($qs) : ''));
  exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

require_once __DIR__ . '/../shared/themeli.php';
require_once __DIR__ . '/../shared/legacy_conversion.php';

/* ------------------------------
   Lista: kërkimi dhe kursi në SQL; gjendja e konvertimit llogaritet për të
   gjitha grupet që përputhen, pastaj numërohet (çipat) dhe filtrohet.
------------------------------- */
$F = qta_group_filters($_GET, []);
$state = qta_list_choice($_GET['status'] ?? '', array_keys(QTA_CONV_STATES));
$list = qta_conv_list($pdo, $F, $state);
$rows = $list['rows'];
$counts = $list['counts'];
$hasFilters = ($F['q'] !== '' || $F['course_id'] !== '');
$legacyTotal = (int)$pdo->query("SELECT COUNT(*) FROM course_groups WHERE model = 'legacy'")->fetchColumn();
$converted = (int)$pdo->query("SELECT COUNT(*) FROM group_conversions WHERE status = 'completed'")->fetchColumn();

$NAV_ACTIVE = 'register_groups';
$HELP_TOPIC = 'conversions';
if ($role === 'administrator') require __DIR__ . '/inc/navbar.php';
else require __DIR__ . '/inc/navbar4.php';

$pageTitle = 'Konvertimi i grupeve';
require __DIR__ . '/../shared/app_head.php';

$stateTitles = ['' => 'Grupet e regjistrit të vjetër'] + array_map(static fn($s) => $s['label'], QTA_CONV_STATES);
$chips = [['value' => '', 'label' => 'Të gjitha', 'count' => $counts[''] ?? 0]];
foreach (['ready', 'draft', 'review', 'problems', 'impossible'] as $k) {
  $chips[] = ['value' => $k, 'label' => QTA_CONV_STATES[$k]['label'], 'count' => $counts[$k] ?? 0, 'optional' => ($counts[$k] ?? 0) === 0 && $state !== $k];
}
$total = $state === '' ? (int)($counts[''] ?? 0) : (int)($counts[$state] ?? 0);
$LF = [
  'action'      => 'group_conversions.php',
  'label'       => 'Kërko në grupet për konvertim',
  'placeholder' => 'Kurs, nr. i grupit, kursant, nr. i amzës ose datë',
  'q'           => $F['q'],
  'target'      => 'convResults',
  'status'      => $state,
  'chips'       => $chips,
  'chips_label' => 'Gjendja e konvertimit',
  'more'        => [
    ['name' => 'course_id', 'label' => 'Kursi', 'value' => $F['course_id'], 'options' => qta_course_options($pdo), 'empty' => 'Çdo kurs'],
  ],
];
$actionLabel = [
  'ready' => 'Përgatit konvertimin', 'draft' => 'Vazhdo', 'review' => 'Kontrollo',
  'problems' => 'Shiko problemet', 'impossible' => 'Shiko arsyen',
];
?>

<main class="app-main is-wide" id="main" tabindex="-1">
  <header class="page-head">
    <div class="page-head-main">
      <ol class="crumbs">
        <li><a href="groups.php">Regjistri i vjetër i kurseve profesionale</a></li>
        <li aria-current="page">Konvertimi i grupeve</li>
      </ol>
      <h1 class="page-title">Konvertimi i grupeve</h1>
      <p class="page-lead">Grupet e regjistrit të vjetër kalojnë një nga një te Regjistri i kurseve profesionale, me datat e tyre historike.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
    </div>
  </header>

  <?php if ($converted > 0): ?>
    <div class="notice is-sunken mb-4" role="note">
      <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
      <span><b><?= h(qta_plural($converted, 'grup është konvertuar', 'grupe janë konvertuar')) ?></b>, <?= h(qta_plural($legacyTotal, 'mbetet', 'mbeten')) ?> në regjistrin e vjetër.
        Grupet e konvertuara janë te <a href="lesson_groups.php">Regjistri i kurseve profesionale</a>.</span>
    </div>
  <?php endif; ?>

  <section class="section" aria-labelledby="convTitle">
    <div class="list-head" data-live-region="list-head">
      <h2 class="section-title" id="convTitle" tabindex="-1" data-live-focus>
        <?= h($stateTitles[$state] ?? 'Grupet e regjistrit të vjetër') ?>
        <span class="count"><?= number_format($total, 0, ',', '.') ?></span>
      </h2>
    </div>

    <?php require __DIR__ . '/../shared/partials/list_toolbar.php'; ?>

    <div id="convResults" data-live-region="results" data-live-announce="<?= h(qta_plural($total, 'grup', 'grupe')) ?>">
    <?php if ($rows): ?>
      <div class="table-responsive">
        <table class="table" id="conversionsTable" data-sortable>
          <thead>
            <tr>
              <th scope="col" class="col-wide" data-sort="text">Kursi</th>
              <th scope="col" class="nowrap" data-sort="date">Periudha</th>
              <th scope="col" class="nowrap num-col" data-sort="num">Orët</th>
              <th scope="col" class="nowrap num-col" data-sort="num">Kursantë</th>
              <th scope="col" data-sort="text">Gjendja</th>
              <th scope="col" class="col-actions" data-sort="none"><span class="visually-hidden">Veprimi</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $gid = (int)$r['id'];
              $s = $r['status'];
              $amze = $r['amze_min'] === null ? '' : ((string)$r['amze_min'] . ((string)$r['amze_max'] !== (string)$r['amze_min'] ? '–' . $r['amze_max'] : ''));
            ?>
              <tr>
                <td class="col-wide">
                  <a class="row-open" href="group_conversion.php?id=<?= $gid ?>">
                    <span>
                      <span class="person-name"><?= h((string)$r['course_name']) ?></span>
                      <span class="cell-sub">Grupi #<?= $gid ?><?= $amze !== '' ? ' · amza ' . h($amze) : '' ?></span>
                    </span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                  </a>
                </td>
                <td class="nowrap" data-sort-value="<?= h((string)$r['start_date']) ?>">
                  <?= h(qta_date((string)$r['start_date'])) ?> – <?= h(qta_date((string)$r['end_date'])) ?>
                  <span class="cell-sub"><?= h(qta_plural((int)$r['calendar_days'], 'ditë', 'ditë')) ?></span>
                </td>
                <td class="nowrap num-col"><?= (int)$r['course_hours'] ?></td>
                <td class="nowrap num-col" data-sort-value="<?= (int)$r['members'] ?>"><?= (int)$r['members'] ?></td>
                <td>
                  <?= qta_status($s['label'], $s['variant'], $s['icon']) ?>
                  <?php if ($s['hint'] !== ''): ?><span class="cell-sub"><?= h($s['hint']) ?></span><?php endif; ?>
                </td>
                <td class="col-actions">
                  <a class="btn btn-ghost btn-sm" href="group_conversion.php?id=<?= $gid ?>"
                     aria-label="<?= h($actionLabel[$s['key']] . ': Grupi #' . $gid . ', ' . $r['course_name']) ?>"><?= h($actionLabel[$s['key']]) ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($hasFilters): ?>
      <?= qta_empty('Asnjë grup nuk përputhet', 'Provo një kurs tjetër, një numër amze ose hiq një filtër.', 'bi-search') ?>
    <?php elseif ($state !== ''): ?>
      <?= qta_empty('Asnjë grup me këtë gjendje', 'Zgjidh "Të gjitha" për të parë çdo grup të regjistrit të vjetër.', 'bi-arrow-left-right', '', 'is-compact') ?>
    <?php else: ?>
      <?= qta_empty('Regjistri i vjetër është bosh', 'Çdo grup i mëparshëm është konvertuar. Grupet janë te "Regjistri i kurseve profesionale".', 'bi-check2-circle',
            '<a class="btn btn-primary" href="lesson_groups.php">Te regjistri i kurseve profesionale</a>', 'is-success') ?>
    <?php endif; ?>
    </div>
  </section>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
