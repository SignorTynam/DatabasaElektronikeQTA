<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* ------------------------------
   Vetëm agjencia e loguar
------------------------------- */
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

/* Lejo 'kompani' (emri i ri) dhe 'agjencia' (për kompatibilitet) */
if (!$currentUser || !in_array($currentUser['role_name'] ?? '', ['kompani','agjencia'], true)) {
    header('Location: selectProfile.php'); exit;
}

/* Agjencia e lidhur me përdoruesin */
$co = $pdo->prepare("
  SELECT id, user_id, company_name, nip_t, address, phone
  FROM agencies
  WHERE user_id = :uid
  LIMIT 1
");
$co->execute([':uid' => (int)$currentUser['id']]);
$COMPANY = $co->fetch(PDO::FETCH_ASSOC);
if (!$COMPANY) { header('Location: selectProfile.php'); exit; }

require_once __DIR__ . '/../shared/themeli.php';
$cid = (int)$COMPANY['id'];

/* ====== Shifrat e agjencisë ====== */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM agency_students WHERE agency_id=:cid");
$stmt->execute([':cid'=>$cid]);
$studentsTotal = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM agency_students a
  LEFT JOIN course_group_students cgs ON cgs.student_id = a.student_id
  WHERE a.agency_id = :cid AND cgs.group_id IS NULL
");
$stmt->execute([':cid'=>$cid]);
$noGroupCnt = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
  SELECT COUNT(DISTINCT cgs.student_id)
  FROM course_group_students cgs
  JOIN agency_students a ON a.student_id = cgs.student_id
  JOIN course_groups cg ON cg.id=cgs.group_id
  WHERE a.agency_id=:cid
    AND CURDATE() BETWEEN cg.start_date AND cg.end_date
");
$stmt->execute([':cid'=>$cid]);
$activeStudentsToday = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM course_group_students cgs
  JOIN agency_students a ON a.student_id = cgs.student_id
  WHERE a.agency_id = :cid AND cgs.final_score IS NOT NULL
");
$stmt->execute([':cid'=>$cid]);
$passedCnt = (int)$stmt->fetchColumn();

/* Ngjarjet e 30 ditëve të ardhshme: grupe që nisin dhe provime të punonjësve */
$stmt = $pdo->prepare("
  SELECT * FROM (
    SELECT 'start' AS kind, cg.start_date AS d, cg.id AS gid, c.code, c.name, COUNT(*) AS members
    FROM course_group_students cgs
    JOIN agency_students a ON a.student_id = cgs.student_id
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE a.agency_id = :cid1
      AND cg.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    GROUP BY cg.id, cg.start_date, c.code, c.name
    UNION ALL
    SELECT 'exam' AS kind, cgs.exam_date AS d, cg.id AS gid, c.code, c.name, COUNT(*) AS members
    FROM course_group_students cgs
    JOIN agency_students a ON a.student_id = cgs.student_id
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE a.agency_id = :cid2
      AND cgs.exam_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    GROUP BY cg.id, cgs.exam_date, c.code, c.name
  ) t
  ORDER BY d ASC
  LIMIT 8
");
$stmt->execute([':cid1'=>$cid, ':cid2'=>$cid]);
$upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Grupet ku agjencia ka punonjës (6 më të fundit) */
$stmt = $pdo->prepare("
  SELECT cg.id, c.code AS course_code, c.name AS course_name,
         cg.start_date, cg.end_date, cg.is_completed,
         COUNT(cgs.student_id) AS cnt_company
  FROM course_group_students cgs
  JOIN agency_students a ON a.student_id = cgs.student_id
  JOIN course_groups cg ON cg.id = cgs.group_id
  JOIN courses c ON c.id = cg.course_id
  WHERE a.agency_id = :cid
  GROUP BY cg.id
  ORDER BY cg.start_date DESC, cg.id DESC
  LIMIT 6
");
$stmt->execute([':cid'=>$cid]);
$groupsCap = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Punonjësit pa grup — listë e shkurtër */
$stmt = $pdo->prepare("
  SELECT s.id, s.nr_amze, p.first_name, p.father_name, p.last_name, p.personal_number
  FROM agency_students a
  JOIN students s ON s.id = a.student_id
  JOIN persons p ON p.id = s.person_id
  WHERE a.agency_id = :cid
    AND NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.student_id = s.id)
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  LIMIT 6
");
$stmt->execute([':cid'=>$cid]);
$noGroupList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$agencyName = trim((string)($COMPANY['company_name'] ?? ''));
$agencyNipt = trim((string)($COMPANY['nip_t'] ?? ''));
$today = date('Y-m-d');

$NAV_ACTIVE = 'dashboard';
$HELP_TOPIC = 'dashboard_agency';
$pageTitle  = 'Kreu i agjencisë';

require __DIR__ . '/../shared/app_head.php';
require __DIR__ . '/inc/navbar2.php';
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <span class="eyebrow"><?= h(ucfirst(qta_today_label())) ?><?= $agencyNipt !== '' ? ' · NIPT ' . h($agencyNipt) : '' ?></span>
      <h1 class="page-title"><?= h(qta_greeting()) ?><?= $agencyName !== '' ? ', ' . h($agencyName) : '' ?></h1>
      <p class="page-lead">Këtu shihni ku janë punonjësit tuaj: kush pret grupin, kush është në mësim dhe provimet e ardhshme.</p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
      <a class="btn btn-primary" href="register_agjencia.php">
        <i class="bi bi-people" aria-hidden="true"></i>Punonjësit tanë
      </a>
    </div>
  </header>

  <form class="section d-flex flex-column flex-sm-row gap-2" method="get" action="register_agjencia.php" role="search" aria-label="Gjej një punonjës">
    <label class="visually-hidden" for="agSearch">Gjej një punonjës</label>
    <div class="search-field is-lg flex-grow-1">
      <i class="bi bi-search" aria-hidden="true"></i>
      <input class="form-control" id="agSearch" type="search" name="q"
             placeholder="Gjej një punonjës — emri, numri personal ose numri i amzës" autocomplete="off">
    </div>
    <button class="btn btn-secondary btn-lg" type="submit">Kërko</button>
  </form>

  <section class="section" aria-label="Punonjësit në shifra">
    <div class="stats">
      <a class="stat" href="register_agjencia.php">
        <span class="stat-label">Punonjës të regjistruar</span>
        <span class="stat-value"><?= number_format($studentsTotal, 0, ',', '.') ?></span>
      </a>
      <div class="stat">
        <span class="stat-label">Në mësim sot</span>
        <span class="stat-value"><?= number_format($activeStudentsToday, 0, ',', '.') ?></span>
      </div>
      <div class="stat">
        <span class="stat-label">Presin grupin</span>
        <span class="stat-value"><?= number_format($noGroupCnt, 0, ',', '.') ?></span>
      </div>
      <div class="stat">
        <span class="stat-label">Provime me pikë</span>
        <span class="stat-value"><?= number_format($passedCnt, 0, ',', '.') ?></span>
      </div>
    </div>
  </section>

  <div class="row g-4 g-xl-5">
    <div class="col-12 col-xl-7">
      <section class="section" aria-labelledby="agGroups">
        <div class="section-head">
          <h2 class="section-title" id="agGroups">Grupet ku keni punonjës</h2>
          <a class="section-link" href="groups_agjencia.php">Të gjitha grupet <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>

        <?php if ($groupsCap): ?>
          <div class="table-responsive">
            <table class="table" data-sortable>
              <thead>
                <tr>
                  <th scope="col" data-sort="text">Moduli</th>
                  <th scope="col" data-sort="date">Trajnimi</th>
                  <th scope="col" class="num-col" data-sort="num">Punonjës</th>
                  <th scope="col" data-sort="none">Gjendja</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($groupsCap as $g):
                  $start = (string)$g['start_date']; $end = (string)$g['end_date'];
                  if ((int)$g['is_completed'] === 1)      { $st = qta_status('Përfunduar', 'success'); }
                  elseif ($start > $today)               { $st = qta_status('Nis ' . qta_when_label($start), 'info', 'bi-calendar-event'); }
                  elseif ($end >= $today)                { $st = qta_status('Në mësim', 'accent', 'bi-easel'); }
                  else                                   { $st = qta_status('Pret provimet', 'warning'); }
                ?>
                  <tr>
                    <td>
                      <span class="person-name"><?= h((string)$g['course_name']) ?></span>
                      <span class="cell-sub"><?= h((string)$g['course_code']) ?></span>
                    </td>
                    <td class="nowrap" data-sort-value="<?= h(qta_date($start)) ?>"><?= h(qta_date($start)) ?> – <?= h(qta_date($end)) ?></td>
                    <td class="num-col"><?= (int)$g['cnt_company'] ?></td>
                    <td><?= $st ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <?= qta_empty('Ende pa grupe', 'Kur QTA të caktojë punonjësit tuaj në grupe, ato shfaqen këtu.', 'bi-collection') ?>
        <?php endif; ?>
      </section>
    </div>

    <div class="col-12 col-xl-5">
      <section class="section" aria-labelledby="upTitle">
        <div class="section-head">
          <h2 class="section-title" id="upTitle">30 ditët e ardhshme</h2>
        </div>
        <?php if ($upcoming): ?>
          <ul class="agenda">
            <?php foreach ($upcoming as $e):
              $ts = strtotime((string)$e['d']);
              $isExam = $e['kind'] === 'exam'; ?>
              <li class="agenda-item">
                <span class="agenda-date" aria-hidden="true">
                  <b><?= h(date('j', $ts)) ?></b>
                  <span><?= h(qta_month_short((int)date('n', $ts))) ?></span>
                </span>
                <span class="agenda-main">
                  <span class="agenda-title"><?= h((string)$e['name']) ?></span>
                  <span class="agenda-meta">
                    <span class="visually-hidden"><?= h(qta_date((string)$e['d'])) ?> · </span>
                    <?= h(qta_plural((int)$e['members'], 'punonjës', 'punonjës')) ?> · <?= h(qta_when_label((string)$e['d'])) ?>
                  </span>
                </span>
                <?= $isExam ? qta_status('Provim', 'accent', 'bi-pencil-square') : qta_status('Nis grupi', 'info', 'bi-play-circle') ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <?= qta_empty('Asgjë e planifikuar', 'Nuk ka grupe që nisin apo provime në 30 ditët e ardhshme.', 'bi-calendar-check', '', 'is-compact') ?>
        <?php endif; ?>
      </section>

      <section class="section" aria-labelledby="waitTitle">
        <div class="section-head">
          <h2 class="section-title" id="waitTitle">Presin caktimin në grup</h2>
          <?php if ($noGroupCnt > 0): ?><span class="section-meta"><?= h(qta_plural($noGroupCnt, 'punonjës', 'punonjës')) ?></span><?php endif; ?>
        </div>
        <?php if ($noGroupList): ?>
          <div class="tasks">
            <?php foreach ($noGroupList as $s): ?>
              <a class="task is-neutral" href="register_agjencia.php?q=<?= urlencode((string)$s['nr_amze']) ?>">
                <span class="task-body">
                  <span class="task-title"><?= h(qta_full_name($s['first_name'] ?? '', null, $s['last_name'] ?? '') ?: '—') ?></span>
                  <span class="task-text">Nr. i amzës <span class="code"><?= h((string)$s['nr_amze']) ?></span> · ende pa grup</span>
                </span>
                <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
              </a>
            <?php endforeach; ?>
          </div>
          <p class="text-muted small mt-2 mb-0">Stafi i QTA-së i cakton punonjësit në grupin e radhës të modulit.</p>
        <?php else: ?>
          <?= qta_empty('Të gjithë janë në grupe', 'Asnjë punonjës nuk pret caktimin.', 'bi-check2-circle', '', 'is-success is-compact') ?>
        <?php endif; ?>
      </section>
    </div>
  </div>

</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
