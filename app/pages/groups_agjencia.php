<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/../shared/themeli.php';

/* ------------------------------
   Guard: vetëm përdorues i loguar me rol "agjencia"
--------------------------------*/
if (!isset($_SESSION['user_id'])) {
  header('Location: selectProfile.php'); exit;
}

$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :id
  LIMIT 1
");
$u->execute([':id' => $_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || $currentUser['role_name'] !== 'agjencia') {
  header('Location: selectProfile.php'); exit;
}

/* Agjencia e këtij përdoruesi */
$astmt = $pdo->prepare("SELECT * FROM agencies WHERE user_id = :uid LIMIT 1");
$astmt->execute([':uid' => $currentUser['id']]);
$AGENCY = $astmt->fetch(PDO::FETCH_ASSOC);
if (!$AGENCY) { header('Location: selectProfile.php'); exit; }

/* ------------------------------
   Filtrat: kërkimi (kurs, grup, punonjës, data), gjendja (çipat) dhe kursi
--------------------------------*/
require_once __DIR__ . '/../shared/group_list.php';
$gaStates = ['upcoming', 'active', 'awaiting_close', 'closed'];
$F = qta_group_filters($_GET, $gaStates);
$q = $F['q'];
$status = $F['status'];
$courseFilter = $F['course_id'];

/* Kushtet e rreshtave (grup + punonjës i kësaj agjencie). */
$baseParams = [':agid' => (int)$AGENCY['id']];
$w = ["asg.agency_id = :agid"];
$tokens = qta_search_tokens($q);
if ($tokens) {
  $w[] = qta_search_sql($tokens, [
    's.nr_amze', 'p.personal_number', 'p.first_name', 'p.father_name', 'p.last_name',
    "CONCAT_WS(' ', p.first_name, p.father_name, p.last_name)",
    'c.name', 'c.code', "DATE_FORMAT(cg.start_date, '%d.%m.%Y')", "DATE_FORMAT(cg.end_date, '%d.%m.%Y')",
  ], $baseParams, 'gaq', ['ids' => ['cg.id']]);
}
if ($courseFilter !== '') {
  $w[] = "cg.course_id = :cf";
  $baseParams[':cf'] = (int)$courseFilter;
}
$rowsFrom = "
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  JOIN course_group_students cgs ON cgs.group_id = cg.id
  JOIN students s ON s.id = cgs.student_id
  LEFT JOIN persons p ON p.id = s.person_id
  JOIN agency_students asg ON asg.student_id = s.id
";

/* Sa grupe ka çdo çip (grupe të ndryshme, jo rreshta). */
$cSql = 'SELECT COUNT(DISTINCT cg.id) AS all_n';
foreach ($gaStates as $sKey) {
  $cSql .= ', COUNT(DISTINCT CASE WHEN ' . qta_group_state_sql($sKey) . ' THEN cg.id END) AS ' . $sKey;
}
$cst = $pdo->prepare($cSql . $rowsFrom . ' WHERE ' . implode(' AND ', $w));
$cst->execute($baseParams);
$cRow = $cst->fetch(PDO::FETCH_ASSOC) ?: [];
$counts = ['' => (int)($cRow['all_n'] ?? 0)];
foreach ($gaStates as $sKey) $counts[$sKey] = (int)($cRow[$sKey] ?? 0);

/* Grupet ku ka punonjës të kësaj agjencie (vetëm ata punonjës shfaqen) */
$params = $baseParams;
$wList = $w;
if ($status !== '') $wList[] = qta_group_state_sql($status);
$st = $pdo->prepare("
  SELECT
    cg.id AS group_id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed,
    c.code AS course_code, c.name AS course_name, c.hours,
    s.id AS student_id, s.nr_amze,
    p.first_name, p.father_name, p.last_name, p.personal_number,
    COALESCE(cgs.exam_date, cg.exam_date) AS exam_date,
    cgs.final_score
  $rowsFrom
  WHERE " . implode(' AND ', $wList) . "
  ORDER BY cg.start_date DESC, cg.id DESC, CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
");
foreach ($params as $k => $v) $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($rows as $r) {
  $gid = (int)$r['group_id'];
  if (!isset($groups[$gid])) {
    $groups[$gid] = ['header' => $r, 'students' => []];
  }
  $groups[$gid]['students'][] = $r;
}

/* Punonjës pa grup (vetëm te "Të gjitha", pa kurs të zgjedhur) */
$noGroup = [];
if ($status === '' && $courseFilter === '') {
  $params2 = [':agid' => (int)$AGENCY['id']];
  $w2 = ["asg.agency_id = :agid"];
  if ($tokens) {
    $w2[] = qta_search_sql($tokens, ['s.nr_amze', 'p.personal_number', 'p.first_name', 'p.father_name', 'p.last_name',
      "CONCAT_WS(' ', p.first_name, p.father_name, p.last_name)"], $params2, 'gan');
  }
  $ng = $pdo->prepare("
    SELECT s.id AS student_id, s.nr_amze, p.first_name, p.father_name, p.last_name, p.personal_number
    FROM students s
    LEFT JOIN persons p ON p.id = s.person_id
    JOIN agency_students asg ON asg.student_id = s.id
    LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
    WHERE " . implode(' AND ', $w2) . "
    GROUP BY s.id
    HAVING COUNT(cgs.group_id) = 0
    ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  ");
  foreach ($params2 as $k => $v) $ng->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  $ng->execute();
  $noGroup = $ng->fetchAll(PDO::FETCH_ASSOC);
}

$today = date('Y-m-d');
$groupState = static function (array $g) use ($today): string {
  if ((int)$g['is_completed'] === 1) return qta_status('Përfunduar', 'success', 'bi-check-circle-fill');
  if ((string)$g['start_date'] > $today) return qta_status('Nis ' . qta_when_label((string)$g['start_date']), 'info', 'bi-calendar-event');
  if ((string)$g['end_date'] >= $today) return qta_status('Në mësim', 'accent', 'bi-easel');
  return qta_status('Në provime', 'warning', 'bi-hourglass-split');
};

$NAV_ACTIVE = 'agency_groups';
$HELP_TOPIC = 'agency_groups';
require __DIR__ . '/inc/navbar2.php';

$pageTitle = 'Grupet';
require __DIR__ . '/../shared/app_head.php';
$hasFilters = ($q !== '' || $courseFilter !== '');
$total = $counts[$status] ?? 0;
$stateTitles = ['' => 'Të gjitha grupet', 'upcoming' => 'Grupet që nisin së shpejti', 'active' => 'Grupet në mësim',
                'awaiting_close' => 'Grupet në provime', 'closed' => 'Grupet e përfunduara'];
$chips = [];
foreach (['' => 'Të gjitha', 'upcoming' => 'Nisin së shpejti', 'active' => 'Në mësim', 'awaiting_close' => 'Në provime', 'closed' => 'Përfunduar'] as $v => $label) {
  $chips[] = ['value' => (string)$v, 'label' => $label, 'count' => $counts[$v] ?? 0];
}
$LF = [
  'action'      => 'groups_agjencia.php',
  'label'       => 'Kërko grupe',
  'placeholder' => 'Kursi, nr. i grupit, punonjësi ose nr. i amzës',
  'q'           => $q,
  'target'      => 'gaResults',
  'status'      => $status,
  'chips'       => $chips,
  'chips_label' => 'Gjendja e grupeve',
  'more'        => [
    ['name' => 'course_id', 'label' => 'Kursi', 'value' => $courseFilter, 'options' => qta_course_options($pdo), 'empty' => 'Çdo kurs'],
  ],
];
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <span class="eyebrow"><?= h((string)($AGENCY['company_name'] ?: 'Agjencia')) ?></span>
      <h1 class="page-title">Grupet</h1>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
    </div>
  </header>

  <section class="section" aria-labelledby="gaTitle">
    <div class="list-head" data-live-region="list-head">
      <h2 class="section-title" id="gaTitle" tabindex="-1" data-live-focus>
        <?= h($stateTitles[$status] ?? 'Të gjitha grupet') ?> <span class="count"><?= (int)$total ?></span>
      </h2>
    </div>

    <?php require __DIR__ . '/../shared/partials/list_toolbar.php'; ?>

    <div id="gaResults" data-live-region="results" data-live-announce="<?= h(qta_plural((int)$total, 'grup', 'grupe')) ?>">
    <?php if ($groups): ?>
      <div class="table-responsive">
        <table class="table" id="agencyGroupsTable">
          <thead>
            <tr>
              <th scope="col" class="col-wide">Kursi</th>
              <th scope="col" class="nowrap">Datat</th>
              <th scope="col" class="nowrap num-col">Punonjës</th>
              <th scope="col">Gjendja</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($groups as $gid => $g):
            $hd = $g['header'];
            $n = count($g['students']);
            $scored = count(array_filter($g['students'], static fn($s) => $s['final_score'] !== null && $s['final_score'] !== '')); ?>
            <tr>
              <td class="col-wide">
                <button class="row-open" type="button" data-bs-toggle="modal" data-bs-target="#gaGroupModal_<?= (int)$gid ?>" aria-haspopup="dialog">
                  <span>
                    <span class="person-name"><?= h((string)$hd['course_name']) ?></span>
                    <span class="cell-sub">Grupi #<?= (int)$gid ?><?= !empty($hd['hours']) ? ' · ' . (int)$hd['hours'] . ' orë' : '' ?></span>
                  </span>
                  <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
              </td>
              <td class="nowrap"><?= h(qta_date($hd['start_date'])) ?> – <?= h(qta_date($hd['end_date'])) ?></td>
              <td class="nowrap num-col"><?= $n ?><?php if ($scored): ?><span class="cell-sub"><?= $scored ?> me pikë</span><?php endif; ?></td>
              <td><?= $groupState($hd) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($hasFilters): ?>
      <?= qta_empty('Asnjë grup nuk përputhet', 'Provo një kurs tjetër, një emër ose hiq një filtër.', 'bi-search') ?>
    <?php elseif ($status !== ''): ?>
      <?= qta_empty('Asnjë grup me këtë gjendje', 'Zgjidh "Të gjitha" për të parë çdo grup.', 'bi-collection', '', 'is-compact') ?>
    <?php else: ?>
      <?= qta_empty('Punonjësit tuaj nuk janë ende në grupe', 'Kur QTA i cakton në një grup, grupi shfaqet këtu me datat e mësimit dhe provimit.', 'bi-collection') ?>
    <?php endif; ?>

    <?php if ($noGroup): ?>
      <section class="section mt-5" aria-labelledby="gaNoGroup">
        <div class="section-head">
          <h3 class="section-title" id="gaNoGroup">Presin një grup <span class="count"><?= count($noGroup) ?></span></h3>
        </div>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th scope="col" class="nowrap">Nr. i amzës</th>
                <th scope="col">Punonjësi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($noGroup as $s):
                $full = qta_full_name($s['first_name'] ?? '', $s['father_name'] ?? '', $s['last_name'] ?? ''); ?>
                <tr>
                  <td class="nowrap"><span class="id-code"><?= h((string)$s['nr_amze']) ?></span></td>
                  <td>
                    <a class="person-name" href="student_card.php?sid=<?= (int)$s['student_id'] ?>"><?= h($full !== '' ? $full : 'Pa emër ende') ?></a>
                    <?php if (!empty($s['personal_number'])): ?><span class="cell-sub code"><?= h((string)$s['personal_number']) ?></span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
    </div>
  </section>
</main>

<?php /* Dritaret e grupeve i përkasin listës: rifreskohen bashkë me të. */ ?>
<div data-live-region="dialogs">
<?php foreach ($groups as $gid => $g):
  $gid = (int)$gid;
  $hd = $g['header']; ?>
  <!-- Dritarja e grupit #<?= $gid ?>: punonjësit e agjencisë në këtë grup -->
  <div class="modal fade modal-record" id="gaGroupModal_<?= $gid ?>" tabindex="-1" aria-labelledby="gaTitle_<?= $gid ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="eyebrow mb-0">Grupi #<?= $gid ?></span>
            <h2 class="modal-title" id="gaTitle_<?= $gid ?>"><?= h((string)$hd['course_name']) ?></h2>
            <div class="modal-meta">
              <span><i class="bi bi-calendar-range" aria-hidden="true"></i><?= h(qta_date($hd['start_date'])) ?> – <?= h(qta_date($hd['end_date'])) ?></span>
              <?php if (!empty($hd['hours'])): ?><span><i class="bi bi-clock" aria-hidden="true"></i><?= (int)$hd['hours'] ?> orë</span><?php endif; ?>
              <span><?= $groupState($hd) ?></span>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
        </div>
        <div class="modal-body">
          <section class="modal-section" aria-labelledby="gaMembers_<?= $gid ?>">
            <div class="modal-section-head">
              <h3 class="modal-section-title" id="gaMembers_<?= $gid ?>">Punonjësit tuaj në këtë grup <span class="count"><?= count($g['students']) ?></span></h3>
            </div>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead>
                  <tr>
                    <th scope="col" class="nowrap">Nr. i amzës</th>
                    <th scope="col">Punonjësi</th>
                    <th scope="col" class="nowrap">Provimi</th>
                    <th scope="col">Gjendja</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($g['students'] as $s):
                    $full = qta_full_name($s['first_name'] ?? '', $s['father_name'] ?? '', $s['last_name'] ?? ''); ?>
                    <tr>
                      <td class="nowrap"><span class="id-code"><?= h((string)$s['nr_amze']) ?></span></td>
                      <td>
                        <a class="person-name" href="student_card.php?sid=<?= (int)$s['student_id'] ?>"><?= h($full !== '' ? $full : 'Pa emër ende') ?></a>
                        <?php if (!empty($s['personal_number'])): ?><span class="cell-sub code"><?= h((string)$s['personal_number']) ?></span><?php endif; ?>
                      </td>
                      <td class="nowrap"><?= h(qta_date($s['exam_date'] ?? null)) ?></td>
                      <td><?= qta_enrollment_status($s) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
