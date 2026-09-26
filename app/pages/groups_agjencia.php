<?php
declare(strict_types=1);
session_start();
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
   Filtrat
--------------------------------*/
$q            = trim((string)($_GET['q'] ?? ''));
$courseFilter = trim((string)($_GET['course_id'] ?? ''));

$courses = $pdo->query("SELECT id, code, name FROM courses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$params = [':agid' => (int)$AGENCY['id']];
$w = ["asg.agency_id = :agid"];
if ($q !== '') {
  $w[] = "(s.nr_amze LIKE :kw OR p.personal_number LIKE :kw2 OR p.first_name LIKE :kw3 OR p.father_name LIKE :kw4 OR p.last_name LIKE :kw5)";
  foreach ([':kw', ':kw2', ':kw3', ':kw4', ':kw5'] as $k) { $params[$k] = '%' . $q . '%'; }
}
if ($courseFilter !== '' && ctype_digit($courseFilter)) {
  $w[] = "cg.course_id = :cf";
  $params[':cf'] = (int)$courseFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $w);

/* Grupet ku ka punonjës të kësaj agjencie (vetëm ata punonjës shfaqen) */
$sql = "
  SELECT
    cg.id AS group_id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed,
    c.code AS course_code, c.name AS course_name, c.hours,
    s.id AS student_id, s.nr_amze,
    p.first_name, p.father_name, p.last_name, p.personal_number,
    COALESCE(cgs.exam_date, cg.exam_date) AS exam_date,
    cgs.final_score
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  JOIN course_group_students cgs ON cgs.group_id = cg.id
  JOIN students s ON s.id = cgs.student_id
  LEFT JOIN persons p ON p.id = s.person_id
  JOIN agency_students asg ON asg.student_id = s.id
  $whereSql
  ORDER BY cg.start_date DESC, cg.id DESC, CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$st = $pdo->prepare($sql);
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

/* Punonjës pa grup */
$params2 = [':agid' => (int)$AGENCY['id']];
$w2 = ["asg.agency_id = :agid"];
if ($q !== '') {
  $w2[] = "(s.nr_amze LIKE :kw OR p.personal_number LIKE :kw2 OR p.first_name LIKE :kw3 OR p.father_name LIKE :kw4 OR p.last_name LIKE :kw5)";
  foreach ([':kw', ':kw2', ':kw3', ':kw4', ':kw5'] as $k) { $params2[$k] = '%' . $q . '%'; }
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
$noGroup = ($courseFilter === '') ? $ng->fetchAll(PDO::FETCH_ASSOC) : [];

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
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <span class="eyebrow"><?= h((string)($AGENCY['company_name'] ?: 'Agjencia')) ?></span>
      <h1 class="page-title">Grupet</h1>
      <p class="page-lead">Grupet ku janë punonjësit tuaj: moduli, datat dhe pikët e secilit. Hap një grup për të parë punonjësit.</p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
    </div>
  </header>

  <form class="filters" method="get" action="groups_agjencia.php" role="search" aria-label="Kërko grupe">
    <div class="filter-field is-grow">
      <label class="form-label" for="gaQ">Kërko një punonjës</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="gaQ" type="search" name="q" value="<?= h($q) ?>" placeholder="Emri, numri personal ose nr. i amzës">
      </div>
    </div>
    <div class="filter-field">
      <label class="form-label" for="gaC">Moduli</label>
      <select class="form-select" id="gaC" name="course_id">
        <option value="">Të gjitha modulet</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ($courseFilter !== '' && (int)$courseFilter === (int)$c['id']) ? 'selected' : '' ?>><?= h((string)$c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-actions">
      <?php if ($hasFilters): ?><a class="btn btn-ghost" href="groups_agjencia.php">Pastro</a><?php endif; ?>
      <button class="btn btn-secondary" type="submit">Kërko</button>
    </div>
  </form>

  <section class="section" aria-labelledby="gaTitle">
    <div class="section-head">
      <h2 class="section-title" id="gaTitle"><?= $hasFilters ? 'Grupet që përputhen' : 'Grupet' ?> <span class="count"><?= count($groups) ?></span></h2>
    </div>

    <?php if ($groups): ?>
      <div class="table-responsive">
        <table class="table" id="agencyGroupsTable">
          <thead>
            <tr>
              <th scope="col" class="col-wide">Moduli</th>
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
    <?php else: ?>
      <?= $hasFilters
        ? qta_empty('Asnjë grup nuk përputhet', 'Provo një modul tjetër ose pastro kërkimin.', 'bi-search', '<a class="btn btn-secondary" href="groups_agjencia.php">Pastro kërkimin</a>')
        : qta_empty('Punonjësit tuaj nuk janë ende në grupe', 'Kur QTA i cakton në një grup, grupi shfaqet këtu me datat e mësimit dhe provimit.', 'bi-collection') ?>
    <?php endif; ?>
  </section>

  <?php if ($noGroup): ?>
    <section class="section" aria-labelledby="gaNoGroup">
      <div class="section-head">
        <h2 class="section-title" id="gaNoGroup">Presin një grup <span class="count"><?= count($noGroup) ?></span></h2>
        <span class="section-meta">QTA i cakton në grupin e radhës të modulit të tyre.</span>
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
</main>

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

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
