<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

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

/* Agjencia e këtij user-i */
$astmt = $pdo->prepare("SELECT * FROM agencies WHERE user_id = :uid LIMIT 1");
$astmt->execute([':uid' => $currentUser['id']]);
$AGENCY = $astmt->fetch(PDO::FETCH_ASSOC);
if (!$AGENCY) { header('Location: selectProfile.php'); exit; }

/* ------------------------------
   Filtra kërkimi
--------------------------------*/
$q            = trim($_GET['q'] ?? '');
$courseFilter = trim($_GET['course_id'] ?? '');  // opsional

/* Merr kurset për dropdown */
$courses = $pdo->query("SELECT id, code, name FROM courses ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------
   Query: rreshta (grup + student i agjencisë)
--------------------------------*/
$params = [':agid' => (int)$AGENCY['id']];
$w = ["asg.agency_id = :agid"];

if ($q !== '') {
$w[] = "(s.nr_amze LIKE :kw
         OR p.personal_number LIKE :kw2
         OR p.first_name LIKE :kw3
         OR p.father_name LIKE :kw4
         OR p.last_name LIKE :kw5)";
  $params[':kw']  = '%'.$q.'%';
  $params[':kw2'] = '%'.$q.'%';
  $params[':kw3'] = '%'.$q.'%';
  $params[':kw4'] = '%'.$q.'%';
  $params[':kw5'] = '%'.$q.'%';
}
if ($courseFilter !== '' && ctype_digit($courseFilter)) {
  $w[] = "cg.course_id = :cf";
  $params[':cf'] = (int)$courseFilter;
}
$whereSql = 'WHERE '.implode(' AND ', $w);

$sql = "
  SELECT
    cg.id AS group_id, cg.course_id, cg.start_date, cg.end_date, cg.exam_date,
    c.code AS course_code, c.name AS course_name,
    s.id AS student_id, s.nr_amze,
    p.first_name, p.father_name, p.last_name, p.personal_number,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,
    el.code AS edu_code, el.label AS edu_label,
    cgs.final_score
  FROM course_groups cg
  JOIN courses c ON c.id = cg.course_id
  LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
  LEFT JOIN students s ON s.id = cgs.student_id
  LEFT JOIN persons p ON p.id = s.person_id
  LEFT JOIN agency_students asg ON asg.student_id = s.id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  $whereSql
  ORDER BY cg.start_date DESC, cg.id DESC, CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) $st->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* Grupim në PHP: group_id => header + students (vetëm të kësaj agjencie) */
$groups = [];
foreach ($rows as $r) {
  $gid = (int)$r['group_id'];
  if (!isset($groups[$gid])) {
    $groups[$gid] = [
      'header' => [
        'group_id'=>$gid,
        'course_id'=>$r['course_id'],
        'course_code'=>$r['course_code'],
        'course_name'=>$r['course_name'],
        'start_date'=>$r['start_date'],
        'end_date'=>$r['end_date'],
        'exam_date'=>$r['exam_date'],
      ],
      'students' => []
    ];
  }
  if ($r['student_id']) $groups[$gid]['students'][] = $r; // vetëm studentët e kësaj agjencie
}

/* Studentë të agjencisë pa grup */
$params2 = [':agid' => (int)$AGENCY['id']];
$w2 = ["asg.agency_id = :agid"];
if ($q !== '') {
$w2[] = "(s.nr_amze LIKE :kw
          OR p.personal_number LIKE :kw2
          OR p.first_name LIKE :kw3
          OR p.father_name LIKE :kw4
          OR p.last_name LIKE :kw5)";
  $params2[':kw']  = '%'.$q.'%';
  $params2[':kw2'] = '%'.$q.'%';
  $params2[':kw3'] = '%'.$q.'%';
  $params2[':kw4'] = '%'.$q.'%';
  $params2[':kw5'] = '%'.$q.'%';
}
$whereNoGroup = 'WHERE '.implode(' AND ', $w2);
$sqlNoGroup = "
  SELECT
    s.id AS student_id, s.nr_amze,
    p.first_name, p.father_name, p.last_name, p.personal_number,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,
    el.code AS edu_code, el.label AS edu_label
  FROM students s
  LEFT JOIN persons p ON p.id = s.person_id
  JOIN agency_students asg ON asg.student_id = s.id
  LEFT JOIN course_group_students cgs ON cgs.student_id = s.id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  $whereNoGroup
  GROUP BY s.id
  HAVING COUNT(cgs.group_id) = 0
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
";
$ng = $pdo->prepare($sqlNoGroup);
foreach ($params2 as $k=>$v) $ng->bindValue($k,$v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
$ng->execute();
$noGroup = $ng->fetchAll(PDO::FETCH_ASSOC);

/* Navbar active key */
$NAV_ACTIVE = 'groups';

$pageTitle = 'Grupe – QTA Agjenci';
require __DIR__ . '/../shared/app_head.php';
?>


<?php require __DIR__ . '/inc/navbar2.php'; ?>

<main class="app-main">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <h2 class="mb-0">Grupe – <?= htmlspecialchars($AGENCY['company_name'] ?? 'Agjencia') ?></h2>
    <form class="d-flex" method="get" action="groups_agency.php">
      <div class="input-group">
        <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control border-0" placeholder="Kërko studentë sipas AMZË/ID/Emri...">
        <select name="course_id" class="form-select">
          <option value="">— Modul —</option>
          <?php foreach($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ($courseFilter!=='' && (int)$courseFilter===(int)$c['id'])?'selected':'' ?>>
              <?= htmlspecialchars($c['code'].' — '.$c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary" type="button" onclick="window.location='groups_agency.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
      </div>
    </form>
  </div>

  <?php if ($groups): foreach ($groups as $gid=>$g): ?>
    <div class="card mb-4">
      <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-3">
          <h5 class="mb-0">
            <i class="bi bi-collection me-2"></i>
            Grup #<?= (int)$g['header']['group_id'] ?> — <?= htmlspecialchars($g['header']['course_code'].' · '.$g['header']['course_name']) ?>
          </h5>
        </div>
        <div class="text-muted small">
          <span class="me-3">Fillimi: <span class="readonly"><?= htmlspecialchars($g['header']['start_date']) ?></span></span>
          <span class="me-3">Mbarimi: <span class="readonly"><?= htmlspecialchars($g['header']['end_date']) ?></span></span>
          <span>Testi: <span class="readonly"><?= htmlspecialchars($g['header']['exam_date'] ?: '—') ?></span></span>
        </div>
      </div>
      <div class="card-body">
        <div class="table-responsive mini-table">
          <table class="table align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="nowrap">AMZË</th>
                <th>Emër Atësi Mbiemër<br><small class="text-muted">ID Personal</small></th>
                <th class="nowrap">Pikët përfundimtare</th>
                <th class="nowrap">Mosha</th>
                <th class="nowrap">Arsimi</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($g['students']): foreach ($g['students'] as $r): ?>
              <tr>
                <td class="nowrap"><?= htmlspecialchars($r['nr_amze']) ?></td>
                <td>
                  <div class="fw-semibold">
                    <?= htmlspecialchars(trim(($r['first_name']??'').' '.(($r['father_name']??'')?($r['father_name'].' '):'').($r['last_name']??''))) ?>
                  </div>
                  <div class="text-muted small"><?= htmlspecialchars($r['personal_number'] ?? '') ?></div>
                </td>
                <td class="nowrap"><span class="readonly">
                  <?= $r['final_score'] !== null ? rtrim(rtrim((string)$r['final_score'],'0'),'.') : '—' ?>
                </span></td>
                <td class="nowrap"><?= $r['age'] !== null ? (int)$r['age'] : '—' ?></td>
                <td><?= htmlspecialchars(($r['edu_code']? $r['edu_code'].' — ' : '').($r['edu_label'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5" class="text-center text-muted">S’ka studentë të kësaj agjencie në këtë grup.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endforeach; else: ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Nuk u gjetën grupe për studentët e kësaj agjencie.</div>
  <?php endif; ?>

  <!-- Studentë të agjencisë pa grup -->
  <div class="card mb-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
      <h5 class="mb-0"><i class="bi bi-person-dash me-2"></i>Studentë pa grup</h5>
      <span class="text-muted small"><?= number_format(count($noGroup)) ?> student(ë)</span>
    </div>
    <div class="card-body">
      <div class="table-responsive mini-table">
        <table class="table align-middle mb-0">
          <thead class="table-light">
          <tr>
            <th class="nowrap">AMZË</th>
            <th>Emër Atësi Mbiemër<br><small class="text-muted">ID Personal</small></th>
            <th class="nowrap">Mosha</th>
            <th class="nowrap">Arsimi</th>
          </tr>
          </thead>
          <tbody>
          <?php if ($noGroup): foreach ($noGroup as $s): ?>
            <tr>
              <td class="nowrap"><?= htmlspecialchars($s['nr_amze']) ?></td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars(trim(($s['first_name']??'').' '.(($s['father_name']??'')?($s['father_name'].' '):'').($s['last_name']??''))) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($s['personal_number'] ?? '') ?></div>
              </td>
              <td class="nowrap"><?= $s['age'] !== null ? (int)$s['age'] : '—' ?></td>
              <td><?= htmlspecialchars(($s['edu_code']? $s['edu_code'].' — ' : '').($s['edu_label'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="4" class="text-center text-muted">Të gjithë studentët e kësaj agjencie janë në grupe.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
