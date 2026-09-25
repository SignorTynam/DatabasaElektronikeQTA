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

/* CSRF për eksportet */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   Kërkimi dhe faqet
--------------------------------*/
$q      = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

$params = [':agid' => (int)$AGENCY['id']];
$whereQ = '';
if ($q !== '') {
  $whereQ = " AND (
                 s.nr_amze LIKE :kw
              OR p.personal_number LIKE :kw2
              OR p.first_name LIKE :kw3
              OR p.father_name LIKE :kw4
              OR p.last_name LIKE :kw5
            )";
  foreach ([':kw', ':kw2', ':kw3', ':kw4', ':kw5'] as $k) { $params[$k] = '%' . $q . '%'; }
}

/* Vetëm punonjësit e kësaj agjencie; për secilin, grupi i fundit (sipas datës së fillimit).
   Data e provimit është e çdo kursanti (cgs); ajo e grupit është kolonë e vjetër. */
$sqlBase = "
  FROM agency_students asg
  JOIN students s ON s.id = asg.student_id
  LEFT JOIN persons p ON p.id = s.person_id
  JOIN users u ON u.id = s.user_id
  LEFT JOIN education_levels el ON el.id = s.education_level_id
  LEFT JOIN (
      SELECT t.student_id, t.group_id
      FROM (
        SELECT cgs.student_id, cgs.group_id,
               ROW_NUMBER() OVER (PARTITION BY cgs.student_id ORDER BY cg.start_date DESC, cg.id DESC) AS rn
        FROM course_group_students cgs
        JOIN course_groups cg ON cg.id = cgs.group_id
      ) t
      WHERE t.rn = 1
  ) lastg ON lastg.student_id = s.id
  LEFT JOIN course_group_students cgs ON cgs.group_id = lastg.group_id AND cgs.student_id = s.id
  LEFT JOIN course_groups cg ON cg.id = lastg.group_id
  LEFT JOIN courses c ON c.id = cg.course_id
  WHERE asg.agency_id = :agid
  $whereQ
";

$count = $pdo->prepare("SELECT COUNT(*) " . $sqlBase);
$count->execute($params);
$total = (int)$count->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

$list = $pdo->prepare("
  SELECT
    s.id AS student_id,
    s.nr_amze,
    p.first_name, p.father_name, p.last_name,
    p.personal_number,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,
    el.label AS edu_label,
    lastg.group_id, c.name AS course_name, cg.start_date, cg.end_date,
    COALESCE(cgs.exam_date, cg.exam_date) AS exam_date,
    cgs.final_score
  " . $sqlBase . "
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
  $list->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':lim', $limit, PDO::PARAM_INT);
$list->bindValue(':off', $offset, PDO::PARAM_INT);
$list->execute();
$rows = $list->fetchAll(PDO::FETCH_ASSOC);

$NAV_ACTIVE = 'agency_students';
$HELP_TOPIC = 'agency_students';
require __DIR__ . '/inc/navbar2.php';

$pageTitle = 'Punonjësit tanë';
require __DIR__ . '/../shared/app_head.php';
$company = (string)($AGENCY['company_name'] ?: 'Agjencia');
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <span class="eyebrow"><?= h($company) ?></span>
      <h1 class="page-title">Punonjësit tanë</h1>
      <p class="page-lead">Punonjësit tuaj që janë regjistruar në QTA, me modulin e fundit, provimin dhe rezultatin.</p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
      <?php if ($total > 0):
        $exportAction = 'register_export_agency.php';
        $exportFields = ['q' => $q];
        $exportTitle  = 'Shkarko listën e punonjësve si';
        require __DIR__ . '/../shared/partials/export_menu.php';
      endif; ?>
    </div>
  </header>

  <form class="filters filters-compact" method="get" action="register_agjencia.php" role="search" aria-label="Kërko punonjës">
    <div class="filter-field is-grow">
      <label class="visually-hidden" for="raQ">Kërko një punonjës</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="raQ" type="search" name="q" value="<?= h($q) ?>" placeholder="Emri, numri personal ose nr. i amzës">
      </div>
    </div>
    <div class="filter-actions">
      <?php if ($q !== ''): ?><a class="btn btn-ghost" href="register_agjencia.php">Pastro</a><?php endif; ?>
      <button class="btn btn-secondary" type="submit">Kërko</button>
    </div>
  </form>

  <section class="section" aria-labelledby="raTitle">
    <div class="section-head">
      <h2 class="section-title" id="raTitle">
        <?= $q !== '' ? 'Punonjësit që përputhen' : 'Të gjithë punonjësit' ?>
        <span class="count"><?= number_format($total, 0, ',', '.') ?></span>
      </h2>
      <a class="section-link" href="groups_agjencia.php">Shiko sipas grupeve</a>
    </div>

    <?php if ($rows): ?>
      <div class="table-responsive">
        <table class="table" id="agencyStudentsTable" data-sortable>
          <thead>
            <tr>
              <th scope="col" class="nowrap" data-sort="num">Nr. i amzës</th>
              <th scope="col" data-sort="text">Punonjësi</th>
              <th scope="col" class="col-wide" data-sort="text">Moduli i fundit</th>
              <th scope="col" class="nowrap" data-sort="date">Provimi</th>
              <th scope="col" data-sort="text">Gjendja</th>
              <th scope="col" class="nowrap num-col" data-sort="num">Mosha</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $full = qta_full_name($r['first_name'] ?? '', $r['father_name'] ?? '', $r['last_name'] ?? ''); ?>
              <tr>
                <td class="nowrap" data-sort-value="<?= (int)$r['nr_amze'] ?>"><span class="id-code"><?= h((string)$r['nr_amze']) ?></span></td>
                <td>
                  <a class="person-name" href="student_card.php?sid=<?= (int)$r['student_id'] ?>"><?= h($full !== '' ? $full : 'Pa emër ende') ?></a>
                  <?php if (!empty($r['personal_number'])): ?><span class="cell-sub code"><?= h((string)$r['personal_number']) ?></span><?php endif; ?>
                </td>
                <td class="col-wide">
                  <?php if (!empty($r['group_id'])): ?>
                    <?= h((string)$r['course_name']) ?>
                    <span class="cell-sub"><?= h(qta_date($r['start_date'])) ?> – <?= h(qta_date($r['end_date'])) ?></span>
                  <?php else: ?>
                    <span class="text-muted">Ende pa grup</span>
                  <?php endif; ?>
                </td>
                <td class="nowrap" data-sort-value="<?= h((string)($r['exam_date'] ?? '')) ?>"><?= h(qta_date($r['exam_date'] ?? null)) ?></td>
                <td><?= qta_enrollment_status($r) ?></td>
                <td class="nowrap num-col"><?= $r['age'] !== null ? (int)$r['age'] : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1):
        $pBase = 'register_agjencia.php?' . http_build_query(array_filter(['q' => $q !== '' ? $q : null]));
        $pLink = static fn(int $p) => $pBase . (str_ends_with($pBase, '?') ? '' : '&') . 'page=' . $p; ?>
        <nav class="pager mt-3" aria-label="Faqet e listës">
          <a class="btn btn-secondary btn-sm<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= h($pLink(max(1, $page - 1))) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>><i class="bi bi-chevron-left" aria-hidden="true"></i>Më parë</a>
          <span class="text-muted small">Faqja <?= $page ?> nga <?= $totalPages ?></span>
          <a class="btn btn-secondary btn-sm<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= h($pLink(min($totalPages, $page + 1))) ?>" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Më pas<i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </nav>
      <?php endif; ?>

    <?php else: ?>
      <?= $q !== ''
        ? qta_empty('Asnjë punonjës nuk përputhet', 'Provo një pjesë tjetër të emrit ose numrin e amzës.', 'bi-search', '<a class="btn btn-secondary" href="register_agjencia.php">Pastro kërkimin</a>')
        : qta_empty('Ende pa punonjës në regjistër', 'Kur QTA regjistron punonjësit tuaj në trajnim, ata shfaqen këtu.', 'bi-people', '<a class="btn btn-secondary" href="contact.php">Na kontaktoni</a>') ?>
    <?php endif; ?>
  </section>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php require __DIR__ . '/../shared/partials/download_generation_toast.php'; ?>
</body>
</html>
