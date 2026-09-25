<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* -------------------------------------------------
   Guard: admin/editor/agjencia (jo studentë)
-------------------------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.role_id, u.full_name, u.email, r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = :uid
  LIMIT 1
");
$u->execute([':uid'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) { header('Location: selectProfile.php'); exit; }

$ROLE = strtolower((string)$currentUser['role_name']);
if (!in_array($ROLE, ['administrator','editor','agjencia'], true)) { http_response_code(403); exit('Akses i ndaluar.'); }
$CAN_EDIT = in_array($ROLE, ['administrator','editor'], true);

/* -------------------------------------------------
   EDIT MODE toggle (persistohet në session)
-------------------------------------------------- */
if (isset($_GET['edit'])) {
  $_SESSION['edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
  $qs = $_GET; unset($qs['edit']);
  $url = 'student_card.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
  header("Location: $url"); exit;
}
$EDIT_MODE = $CAN_EDIT ? (bool)($_SESSION['edit_mode'] ?? false) : false;

/* -------------------------------------------------
   CSRF & flash
-------------------------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

function flash(string $k, ?string $m=null){
  if ($m===null){ if(!empty($_SESSION['flash'][$k])){ $x=$_SESSION['flash'][$k]; unset($_SESSION['flash'][$k]); return $x; } return null; }
  $_SESSION['flash'][$k]=$m;
}
require_once __DIR__ . '/../shared/themeli.php';

/* -------------------------------------------------
   Lexo agency_id (nëse jemi agjenci) për filtrime
-------------------------------------------------- */
$MY_AGENCY_ID = null;
if ($ROLE === 'agjencia') {
  $a = $pdo->prepare("SELECT id FROM agencies WHERE user_id=:u LIMIT 1");
  $a->execute([':u'=>$currentUser['id']]);
  $MY_AGENCY_ID = (int)($a->fetchColumn() ?: 0);
  if ($MY_AGENCY_ID <= 0) { http_response_code(403); exit('Agjencia nuk u gjet.'); }
}

/* -------------------------------------------------
   Kërkim & zgjedhje PERSONI
-------------------------------------------------- */
$q   = trim($_GET['q']   ?? '');
$pid = (int)($_GET['pid'] ?? 0);
$sid = (int)($_GET['sid'] ?? 0);
$person = null;
$studentsOfPerson = [];
$eduLevels = [];
$genders = [];
$stats = $groups = $planned = $upcoming = $series = [];

/* Edu levels & genders për dropdown */
try {
  $eduLevels = $pdo->query("SELECT id, code, label FROM education_levels ORDER BY COALESCE(code, label) ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $eduLevels = []; }
try {
  $genders = $pdo->query("SELECT id, code, label FROM genders ORDER BY FIELD(code,'M','F') DESC, label ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $genders = []; }

/* Merr pid nga sid nëse s'është dhënë */
if ($pid<=0 && $sid>0) {
  $p = $pdo->prepare("SELECT person_id FROM students WHERE id=:sid");
  $p->execute([':sid'=>$sid]);
  $pid = (int)($p->fetchColumn() ?: 0);
}

/* ====== Kur kemi person të zgjedhur ====== */
$personQR = ['token'=>null,'created_at'=>null];
if ($pid > 0) {
  // Lejo akses për agjenci vetëm nëse të paktën një nga studentët e personit i përket asaj agjencie
  $person = $pdo->prepare("
    SELECT p.id, p.first_name, p.father_name, p.last_name, p.birth_date, p.birth_place,
           p.personal_number, p.phone, p.gender_id,
           g.code AS gender_code, g.label AS gender_label
    FROM persons p
    LEFT JOIN genders g ON g.id = p.gender_id
    WHERE p.id = :pid
  ");
  $person->execute([':pid'=>$pid]);
  $person = $person->fetch(PDO::FETCH_ASSOC);
  if (!$person) { flash('err','Ky person nuk u gjet. Mund të jetë fshirë — kërkoje sërish.'); $pid = 0; }

  if ($person) {
    // Të gjitha students (regjistrimet) të këtij personi – përdoren për statistika, nuk shfaqim listë AMZË-sh
    $sqlS = "
      SELECT
        s.id, s.nr_amze, s.education_level_id, el.code AS edu_code, el.label AS edu_label,
        s.created_at, u.email,
        ajs.agency_id, ag.company_name AS agency_name
      FROM students s
      JOIN users u ON u.id = s.user_id
      LEFT JOIN education_levels el ON el.id = s.education_level_id
      LEFT JOIN agency_students ajs ON ajs.student_id = s.id
      LEFT JOIN agencies ag ON ag.id = ajs.agency_id
      WHERE s.person_id = :pid
      ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
    ";
    $st = $pdo->prepare($sqlS); $st->execute([':pid'=>$pid]);
    $studentsOfPerson = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($ROLE === 'agjencia') {
      $haveAny = false;
      foreach ($studentsOfPerson as $row) {
        if ((int)($row['agency_id'] ?? 0) === $MY_AGENCY_ID) { $haveAny = true; break; }
      }
      if (!$haveAny) { $person = null; $studentsOfPerson = []; flash('err','Ky person nuk është te punonjësit e agjencisë suaj.'); }
    }

    if ($person) {
      // Token & QR per person
      $qrP = $pdo->prepare("SELECT token, created_at FROM person_qr_tokens WHERE person_id = :pid");
      $qrP->execute([':pid'=>$pid]);
      if ($row = $qrP->fetch(PDO::FETCH_ASSOC)) {
        $personQR['token'] = $row['token'];
        $personQR['created_at'] = $row['created_at'];
      }

      // Statistika & të dhënat e kombinuara
      $ids = array_map(fn($r)=> (int)$r['id'], $studentsOfPerson);
      if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));

        // KPI: nr grupesh, kurse, orë
        $q1 = $pdo->prepare("
          SELECT COUNT(DISTINCT cgs.group_id) AS groups_cnt,
                 COUNT(DISTINCT cg.course_id) AS courses_cnt,
                 COALESCE(SUM(c.hours),0) AS total_hours
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c ON c.id = cg.course_id
          WHERE cgs.student_id IN ($ph)
        ");
        $q1->execute($ids);
        $row = $q1->fetch(PDO::FETCH_ASSOC);
        $stats['groups']  = (int)($row['groups_cnt'] ?? 0);
        $stats['courses'] = (int)($row['courses_cnt'] ?? 0);
        $stats['hours']   = (int)($row['total_hours'] ?? 0);

        // Mesatare / kalueshmëri / best / last
        $q2 = $pdo->prepare("
          SELECT
            AVG(final_score) AS avg_score,
            SUM(CASE WHEN final_score>=50 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0) * 100 AS pass_rate,
            MAX(final_score) AS best_score
          FROM course_group_students
          WHERE student_id IN ($ph) AND final_score IS NOT NULL
        ");
        $q2->execute($ids); $row = $q2->fetch(PDO::FETCH_ASSOC);
        $stats['avg_score'] = $row['avg_score']!==null ? round((float)$row['avg_score'],2) : null;
        $stats['pass_rate'] = $row['pass_rate']!==null ? round((float)$row['pass_rate'],1) : null;
        $stats['best']      = $row['best_score']!==null ? round((float)$row['best_score'],1) : null;

        // Nota e fundit
        $q3 = $pdo->prepare("
          SELECT cgs.final_score
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          WHERE cgs.student_id IN ($ph) AND cgs.final_score IS NOT NULL
          ORDER BY COALESCE(cgs.exam_date, cg.end_date) DESC, cgs.group_id DESC
          LIMIT 1
        ");
        $q3->execute($ids);
        $stats['last'] = $q3->fetchColumn();

        // Të gjitha grupet
        $q4 = $pdo->prepare("
          SELECT
            cgs.student_id,
            s.nr_amze,
            cg.id AS group_id,
            c.code,
            c.name,
            cg.start_date,
            cg.end_date,
            cgs.exam_date,
            cgs.final_score
          FROM course_group_students cgs
          JOIN students s ON s.id = cgs.student_id
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c ON c.id = cg.course_id
          WHERE cgs.student_id IN ($ph)
          ORDER BY cg.start_date DESC, cg.id DESC
        ");
        $q4->execute($ids);
        $groups = $q4->fetchAll(PDO::FETCH_ASSOC);

        // Modulet e planifikuara (pa grup)
        $q5 = $pdo->prepare("
          SELECT
            scp.id AS scp_id,
            scp.student_id,
            s.nr_amze,
            c.id AS course_id,
            c.code,
            c.name
          FROM student_course_plans scp
          JOIN students s ON s.id = scp.student_id
          JOIN courses  c ON c.id = scp.course_id
          WHERE scp.student_id IN ($ph)
            AND scp.status = 'planned'
            AND scp.group_id IS NULL
          ORDER BY c.name ASC
        ");
        $q5->execute($ids);
        $planned = $q5->fetchAll(PDO::FETCH_ASSOC);

        // Provime të afërta (≥ sot)
        $q6 = $pdo->prepare("
          SELECT DISTINCT c.code, c.name, cgs.exam_date
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          JOIN courses c ON c.id = cg.course_id
          WHERE cgs.student_id IN ($ph)
            AND cgs.exam_date IS NOT NULL
            AND cgs.exam_date >= CURDATE()
          ORDER BY cgs.exam_date ASC
          LIMIT 8
        ");
        $q6->execute($ids);
        $upcoming = $q6->fetchAll(PDO::FETCH_ASSOC);

        // Seri për grafik
        $q7 = $pdo->prepare("
          SELECT DATE_FORMAT(COALESCE(cgs.exam_date, cg.end_date), '%Y-%m-%d') AS d, cgs.final_score AS s
          FROM course_group_students cgs
          JOIN course_groups cg ON cg.id = cgs.group_id
          WHERE cgs.student_id IN ($ph) AND cgs.final_score IS NOT NULL
          ORDER BY COALESCE(cgs.exam_date, cg.end_date) ASC, cgs.group_id ASC
          LIMIT 80
        ");
        $q7->execute($ids);
        $series = $q7->fetchAll(PDO::FETCH_ASSOC);
      }
    }
  }
}

/* Rezultatet e kërkimit kur s’është zgjedhur personi */
$results = [];
if ($pid<=0 && $q!=='') {
$sql = "
    SELECT
      p.id AS person_id,
      p.first_name, p.father_name, p.last_name, p.personal_number,
      COUNT(DISTINCT s.id) AS registrations,
      GROUP_CONCAT(DISTINCT s.nr_amze ORDER BY CAST(s.nr_amze AS UNSIGNED), s.nr_amze SEPARATOR ', ') AS amze_list
    FROM persons p
    LEFT JOIN students s ON s.person_id = p.id
    LEFT JOIN users u ON u.id = s.user_id
    WHERE (
       p.first_name      LIKE :kw1
    OR p.last_name       LIKE :kw2
    OR p.personal_number LIKE :kw3
    OR s.nr_amze         LIKE :kw4
    OR s.id              = :idExact
    OR u.email           LIKE :kw5
  )
  ";
  $params = [
    ':kw1' => '%'.$q.'%',
    ':kw2' => '%'.$q.'%',
    ':kw3' => '%'.$q.'%',
    ':kw4' => '%'.$q.'%',
    ':kw5' => '%'.$q.'%',
    ':idExact' => ctype_digit($q) ? (int)$q : -1,
  ];
  if ($ROLE === 'agjencia') {
    $sql .= " AND EXISTS (SELECT 1 FROM agency_students ajs WHERE ajs.student_id = s.id AND ajs.agency_id = :aid)";
    $params[':aid'] = $MY_AGENCY_ID;
  }
  $sql .= " GROUP BY p.id ORDER BY p.last_name, p.first_name LIMIT 40";
  $st = $pdo->prepare($sql); $st->execute($params);
  $results = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* Navbar */
$IS_AGENCY  = ($ROLE === 'agjencia');
$NAV_ACTIVE = $IS_AGENCY ? 'agency_students' : 'student_card';
$HELP_TOPIC = 'student_card';
if     ($ROLE === 'administrator') require __DIR__ . '/inc/navbar.php';
elseif ($ROLE === 'editor')       require __DIR__ . '/inc/navbar4.php';
elseif ($ROLE === 'agjencia')     require __DIR__ . '/inc/navbar2.php';

$fullName = $person ? qta_full_name($person['first_name'] ?? '', $person['father_name'] ?? '', $person['last_name'] ?? '') : '';
$verifyBase = qta_absolute_url('verify.php');
$verifyURL  = ($person && !empty($personQR['token'])) ? ($verifyBase . '?pid=' . (int)$pid . '&t=' . rawurlencode((string)$personQR['token'])) : '';

$pageTitle   = $person ? ('Kartela · ' . ($fullName !== '' ? $fullName : 'Person #' . (int)$pid)) : 'Kartela e kursantit';
$pageScripts = $person ? ['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'] : [];
require __DIR__ . '/../shared/app_head.php';

$listHref  = $IS_AGENCY ? 'register_agjencia.php' : 'students.php';
$listLabel = $IS_AGENCY ? 'Punonjësit tanë' : 'Kursantët';
$canInline = ($EDIT_MODE && $CAN_EDIT);
$flashOk   = flash('ok');
$flashErr  = flash('err');
?>

<main class="app-main" id="main" tabindex="-1">

  <?php if ($flashOk): ?>
    <div class="alert alert-success" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><?= h($flashOk) ?></div></div>
  <?php endif; ?>
  <?php if ($flashErr): ?>
    <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><?= h($flashErr) ?></div></div>
  <?php endif; ?>

<?php if (!$person): ?>
  <!-- ================================================ Kërkimi i personit -->
  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Kartela e kursantit</h1>
      <p class="page-lead">Gjej një person dhe shiko gjithçka për të në një vend: të dhënat, modulet, provimet dhe kodin QR.</p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
    </div>
  </header>

  <form class="panel mb-4" method="get" action="student_card.php" role="search" aria-label="Kërko një person">
    <label class="form-label" for="cardQ">Kë po kërkon?</label>
    <div class="d-flex flex-wrap gap-2">
      <div class="search-field is-lg flex-grow-1">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control form-control-lg" id="cardQ" type="search" name="q" value="<?= h($q) ?>"
               placeholder="Emri, numri personal ose nr. i amzës" autocomplete="off" <?= $q === '' ? 'autofocus' : '' ?>>
      </div>
      <button class="btn btn-primary btn-lg" type="submit">Kërko</button>
    </div>
    <p class="form-text mb-0">Mund të shkruash vetëm një pjesë të emrit, p.sh. "Kola", ose numrin e amzës, p.sh. "1003".</p>
  </form>

  <?php if ($q !== ''): ?>
    <section class="section" aria-labelledby="resTitle">
      <div class="section-head">
        <h2 class="section-title" id="resTitle">Rezultatet për "<?= h($q) ?>" <span class="count"><?= count($results) ?></span></h2>
        <a class="section-link" href="student_card.php">Pastro kërkimin</a>
      </div>
      <?php if ($results): ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th scope="col">Personi</th>
                <th scope="col" class="nowrap">Nr. i amzës</th>
                <th scope="col" class="nowrap num-col">Regjistrime</th>
                <th scope="col" class="col-actions"><span class="visually-hidden">Veprime</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($results as $r):
                $rName = qta_full_name($r['first_name'] ?? '', $r['father_name'] ?? '', $r['last_name'] ?? ''); ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center gap-3">
                      <span class="avatar" aria-hidden="true"><?= h(qta_initials($rName)) ?></span>
                      <span>
                        <a class="person-name" href="student_card.php?pid=<?= (int)$r['person_id'] ?>"><?= h($rName !== '' ? $rName : 'Pa emër ende') ?></a>
                        <?php if (!empty($r['personal_number'])): ?><span class="cell-sub code"><?= h((string)$r['personal_number']) ?></span><?php endif; ?>
                      </span>
                    </div>
                  </td>
                  <td class="nowrap"><span class="id-code"><?= h((string)($r['amze_list'] ?: '—')) ?></span></td>
                  <td class="nowrap num-col"><?= (int)$r['registrations'] ?></td>
                  <td class="col-actions">
                    <a class="btn btn-secondary btn-sm" href="student_card.php?pid=<?= (int)$r['person_id'] ?>">Hap kartelën<i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (count($results) >= 40): ?>
          <p class="text-muted small mt-2 mb-0">Po shfaqen 40 të parët. Shkruaj më shumë nga emri për ta ngushtuar kërkimin.</p>
        <?php endif; ?>
      <?php else: ?>
        <?= qta_empty('Nuk gjeta asnjë person', 'Kontrollo shkronjat ose provo me numrin e amzës ose numrin personal.', 'bi-person-x', '<a class="btn btn-secondary" href="' . h($listHref) . '">Shiko ' . h(mb_strtolower($listLabel)) . '</a>') ?>
      <?php endif; ?>
    </section>
  <?php else: ?>
    <?= qta_empty('Kërko një person për të hapur kartelën', 'Kartela bashkon të gjitha regjistrimet e një personi, edhe kur ka disa numra amze.', 'bi-person-vcard', '<a class="btn btn-secondary" href="' . h($listHref) . '">Ose shfleto ' . h(mb_strtolower($listLabel)) . '</a>') ?>
  <?php endif; ?>

<?php else: ?>
  <?php
    $amzeList = array_values(array_unique(array_filter(array_map(static fn($r) => (string)$r['nr_amze'], $studentsOfPerson))));
    $passed = 0; $scored = 0;
    foreach ($groups as $gr) {
      if ($gr['final_score'] !== null && $gr['final_score'] !== '') { $scored++; if ((float)$gr['final_score'] >= 50) $passed++; }
    }
    $genderLabel = (string)($person['gender_label'] ?? '');
    $agencyNames = array_values(array_unique(array_filter(array_map(static fn($r) => (string)($r['agency_name'] ?? ''), $studentsOfPerson))));
  ?>
  <!-- ================================================ Kartela e personit -->
  <header class="page-head">
    <div class="page-head-main">
      <nav aria-label="Vendndodhja">
        <ol class="crumbs">
          <li><a href="<?= h($listHref) ?>"><?= h($listLabel) ?></a></li>
          <li aria-current="page">Kartela</li>
        </ol>
      </nav>
      <div class="person-head">
        <span class="avatar avatar-xl" aria-hidden="true" data-person-initials><?= h(qta_initials($fullName)) ?></span>
        <div class="min-w-0">
          <h1 class="page-title" data-person-name><?= h($fullName !== '' ? $fullName : 'Pa emër ende') ?></h1>
          <p class="page-lead mb-0">
            <?php if ($amzeList): ?>Nr. i amzës: <span class="id-code"><?= h(implode(', ', $amzeList)) ?></span><?php endif; ?>
            <?php if ($agencyNames): ?><span class="text-subtle" aria-hidden="true"> · </span>Punonjës i <?= h(implode(', ', $agencyNames)) ?><?php endif; ?>
          </p>
        </div>
      </div>
    </div>
    <div class="page-actions">
      <?php if ($CAN_EDIT) require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
    </div>
  </header>

  <form class="filters filters-compact" method="get" action="student_card.php" role="search" aria-label="Kërko një person tjetër">
    <div class="filter-field is-grow">
      <label class="visually-hidden" for="cardQ2">Kërko një person tjetër</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="cardQ2" type="search" name="q" placeholder="Kërko një person tjetër — emër, numër personal ose amzë" autocomplete="off">
      </div>
    </div>
    <div class="filter-actions">
      <button class="btn btn-secondary" type="submit">Kërko</button>
    </div>
  </form>

  <?php if ($CAN_EDIT) require __DIR__ . '/../shared/partials/edit_mode_off_banner.php'; ?>

  <div class="row g-4">
    <div class="col-12 col-xl-8">

      <div class="stats mb-4" aria-label="Përmbledhje">
        <div class="stat">
          <span class="stat-label">Module</span>
          <span class="stat-value"><?= (int)($stats['courses'] ?? 0) + count($planned) ?></span>
          <span class="stat-note"><?= count($planned) ? h(qta_plural(count($planned), 'pret grup', 'presin grup')) : 'të gjitha me grup' ?></span>
        </div>
        <div class="stat">
          <span class="stat-label">Të kaluara</span>
          <span class="stat-value"><?= $passed ?><span class="text-subtle fs-6"> / <?= $scored ?></span></span>
          <span class="stat-note"><?= $scored ? 'nga provimet me pikë' : 'ende pa pikë' ?></span>
        </div>
        <div class="stat">
          <span class="stat-label">Mesatarja</span>
          <span class="stat-value"><?= ($stats['avg_score'] ?? null) !== null ? h(rtrim(rtrim(number_format((float)$stats['avg_score'], 1, ',', ''), '0'), ',')) : '—' ?></span>
          <span class="stat-note">pikë (kalon me 50)</span>
        </div>
        <div class="stat">
          <span class="stat-label">Orë mësimi</span>
          <span class="stat-value"><?= number_format((int)($stats['hours'] ?? 0), 0, ',', '.') ?></span>
          <span class="stat-note">në modulet me grup</span>
        </div>
      </div>

      <!-- Modulet -->
      <section class="section" aria-labelledby="modTitle">
        <div class="section-head">
          <h2 class="section-title" id="modTitle">Modulet dhe provimet</h2>
        </div>
        <?php if ($groups || $planned): ?>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th scope="col" class="col-wide">Moduli</th>
                  <th scope="col" class="nowrap">Nr. i amzës</th>
                  <th scope="col" class="nowrap">Datat e grupit</th>
                  <th scope="col" class="nowrap">Provimi</th>
                  <th scope="col">Gjendja</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($groups as $gr): ?>
                  <tr>
                    <td class="col-wide">
                      <span class="person-name"><?= h((string)$gr['name']) ?></span>
                      <span class="cell-sub">
                        <?php if (!$IS_AGENCY): ?>
                          <a href="groups.php?q=<?= rawurlencode((string)$gr['nr_amze']) ?>">Grupi #<?= (int)$gr['group_id'] ?></a>
                        <?php else: ?>Grupi #<?= (int)$gr['group_id'] ?><?php endif; ?>
                        <?php if (!empty($gr['code'])): ?> · <span class="code"><?= h((string)$gr['code']) ?></span><?php endif; ?>
                      </span>
                    </td>
                    <td class="nowrap"><span class="id-code"><?= h((string)$gr['nr_amze']) ?></span></td>
                    <td class="nowrap"><?= h(qta_date($gr['start_date'])) ?> – <?= h(qta_date($gr['end_date'])) ?></td>
                    <td class="nowrap"><?= h(qta_date($gr['exam_date'] ?? null)) ?></td>
                    <td><?= qta_enrollment_status($gr) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php foreach ($planned as $pl): ?>
                  <tr>
                    <td class="col-wide">
                      <span class="person-name"><?= h((string)$pl['name']) ?></span>
                      <span class="cell-sub">Moduli është zgjedhur<?php if (!empty($pl['code'])): ?> · <span class="code"><?= h((string)$pl['code']) ?></span><?php endif; ?></span>
                    </td>
                    <td class="nowrap"><span class="id-code"><?= h((string)$pl['nr_amze']) ?></span></td>
                    <td class="nowrap text-muted">—</td>
                    <td class="nowrap text-muted">—</td>
                    <td>
                      <?= qta_status('Pret grup', 'warning', 'bi-hourglass-split') ?>
                      <?php if ($CAN_EDIT): ?>
                        <a class="small ms-1" href="students_without_groups.php?q=<?= rawurlencode((string)$pl['nr_amze']) ?>">Cakto në grup</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <?= qta_empty('Ende pa module', 'Ky person nuk ka asnjë modul të zgjedhur dhe nuk është në asnjë grup.', 'bi-journal', $CAN_EDIT ? '<a class="btn btn-secondary" href="students_without_groups.php">Te kursantët pa grup</a>' : '', 'is-compact') ?>
        <?php endif; ?>
      </section>

      <!-- Të dhënat personale -->
      <section class="section" aria-labelledby="pdTitle">
        <div class="section-head">
          <h2 class="section-title" id="pdTitle">Të dhënat personale</h2>
          <span class="section-meta"><?= $canInline ? 'Kliko një vlerë për ta ndryshuar. Ruhet kur del nga fusha.' : '' ?></span>
        </div>
        <div class="panel">
          <dl class="kv kv-2">
            <?php foreach ([
              'first_name'  => 'Emri',
              'father_name' => 'Atësia',
              'last_name'   => 'Mbiemri',
            ] as $field => $label): ?>
              <dt><?= h($label) ?></dt>
              <dd>
                <?php if ($canInline): ?>
                  <span class="editable" contenteditable="true" role="textbox" aria-label="<?= h($label) ?>"
                        data-type="person" data-id="<?= (int)$pid ?>" data-field="<?= h($field) ?>"><?= h((string)($person[$field] ?? '')) ?></span>
                <?php else: ?>
                  <?= h((string)(($person[$field] ?? '') ?: '—')) ?>
                <?php endif; ?>
              </dd>
            <?php endforeach; ?>

            <dt>Numri personal</dt>
            <dd class="code">
              <?php if ($canInline): ?>
                <span class="editable" contenteditable="true" role="textbox" aria-label="Numri personal"
                      data-type="person" data-id="<?= (int)$pid ?>" data-field="personal_number"><?= h((string)($person['personal_number'] ?? '')) ?></span>
              <?php else: ?>
                <?= h((string)(($person['personal_number'] ?? '') ?: '—')) ?>
              <?php endif; ?>
            </dd>

            <dt><label for="pdBirth" class="m-0">Datëlindja</label></dt>
            <dd>
              <?php if ($canInline): ?>
                <input id="pdBirth" type="text" class="form-control form-control-sm dmy-input w-auto" inputmode="numeric" autocomplete="off"
                       placeholder="dd.mm.vvvv" data-type="person" data-id="<?= (int)$pid ?>" data-field="birth_date"
                       value="<?= h(qta_date($person['birth_date'] ?? null, '')) ?>">
              <?php else: ?>
                <?= h(qta_date($person['birth_date'] ?? null)) ?>
              <?php endif; ?>
            </dd>

            <dt>Vendlindja</dt>
            <dd>
              <?php if ($canInline): ?>
                <span class="editable" contenteditable="true" role="textbox" aria-label="Vendlindja"
                      data-type="person" data-id="<?= (int)$pid ?>" data-field="birth_place"><?= h((string)($person['birth_place'] ?? '')) ?></span>
              <?php else: ?>
                <?= h((string)(($person['birth_place'] ?? '') ?: '—')) ?>
              <?php endif; ?>
            </dd>

            <dt><label for="pdGender" class="m-0">Gjinia</label></dt>
            <dd>
              <?php if ($canInline): ?>
                <select id="pdGender" class="form-select form-select-sm w-auto inline-select" data-type="person" data-id="<?= (int)$pid ?>" data-field="gender_id">
                  <?php foreach ($genders as $gd): ?>
                    <option value="<?= (int)$gd['id'] ?>" <?= ((int)($person['gender_id'] ?? 0) === (int)$gd['id']) ? 'selected' : '' ?>><?= h((string)$gd['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <?= h($genderLabel !== '' ? $genderLabel : '—') ?>
              <?php endif; ?>
            </dd>

            <dt>Telefoni</dt>
            <dd>
              <?php if ($canInline): ?>
                <span class="editable" contenteditable="true" role="textbox" aria-label="Telefoni" inputmode="tel"
                      data-type="person" data-id="<?= (int)$pid ?>" data-field="phone"><?= h((string)($person['phone'] ?? '')) ?></span>
              <?php elseif (!empty($person['phone'])): ?>
                <a href="tel:<?= h(preg_replace('/[^\d+]/', '', (string)$person['phone'])) ?>"><?= h((string)$person['phone']) ?></a>
              <?php else: ?>
                —
              <?php endif; ?>
            </dd>
          </dl>
        </div>
      </section>
    </div>

    <aside class="col-12 col-xl-4" aria-label="Kodi QR dhe provimet">
      <!-- Kodi QR -->
      <section class="panel mb-4" aria-labelledby="qrTitle">
        <h2 class="section-title mb-1" id="qrTitle">Kodi QR i verifikimit</h2>
        <p class="text-muted small">Kushdo që e skanon me kamerën e telefonit sheh modulet që ky person ka kaluar.</p>

        <div data-qr-panel <?= $verifyURL ? '' : 'hidden' ?>>
          <div class="text-center">
            <div class="qr-frame" id="personQr" data-qr="<?= h($verifyURL) ?>" data-qr-size="176"
                 data-qr-alt="Kodi QR i verifikimit për <?= h($fullName) ?>"></div>
          </div>
          <div class="d-grid gap-2 mt-3">
            <a class="btn btn-secondary" id="qrOpen" href="<?= h($verifyURL ?: '#') ?>" target="_blank" rel="noopener">
              <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Hap faqen e verifikimit
            </a>
            <button class="btn btn-secondary" type="button" id="qrDownload">
              <i class="bi bi-download" aria-hidden="true"></i>Shkarko kodin QR (PNG)
            </button>
            <button class="btn btn-ghost" type="button" id="qrCopy" data-copy="<?= h($verifyURL) ?>" data-copy-message="Lidhja e verifikimit u kopjua.">
              <i class="bi bi-link-45deg" aria-hidden="true"></i>Kopjo lidhjen
            </button>
          </div>
        </div>

        <div data-qr-missing <?= $verifyURL ? 'hidden' : '' ?>>
          <div class="notice is-sunken mb-3">
            <i class="bi bi-qr-code" aria-hidden="true"></i>
            <span>Ky person nuk ka ende kod QR.</span>
          </div>
          <?php if ($canInline): ?>
            <button class="btn btn-primary w-100" type="button" id="qrGenerate">
              <i class="bi bi-qr-code" aria-hidden="true"></i>Krijo kodin QR
            </button>
          <?php elseif ($CAN_EDIT): ?>
            <p class="text-muted small mb-0">Për ta krijuar, shtyp "Lejo ndryshimet" lart.</p>
          <?php else: ?>
            <p class="text-muted small mb-0">Kodin e krijon QTA. Na kontaktoni nëse ju duhet.</p>
          <?php endif; ?>
        </div>
      </section>

      <!-- Provimet e ardhshme -->
      <section class="section" aria-labelledby="upTitle">
        <div class="section-head">
          <h2 class="section-title" id="upTitle">Provimet e ardhshme</h2>
        </div>
        <?php if ($upcoming): ?>
          <ul class="agenda">
            <?php foreach ($upcoming as $e):
              $ts = strtotime((string)$e['exam_date']); ?>
              <li class="agenda-item">
                <span class="agenda-date"><b><?= date('d', $ts) ?></b><span><?= h(qta_month_short((int)date('n', $ts))) ?></span></span>
                <span class="agenda-main">
                  <span class="agenda-title"><?= h((string)$e['name']) ?></span>
                  <span class="agenda-meta"><?= h(ucfirst(qta_weekday((int)date('N', $ts)))) ?>, <?= h(qta_date((string)$e['exam_date'])) ?></span>
                </span>
                <?= qta_status(ucfirst(qta_when_label((string)$e['exam_date'])), 'info') ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <?= qta_empty('Asnjë provim i caktuar', 'Datat e provimit vendosen te "Grupet" ose "Regjistri i plotë".', 'bi-calendar', '', 'is-compact') ?>
        <?php endif; ?>
      </section>
    </aside>
  </div>
<?php endif; ?>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php if ($person): ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const INLINE = 'student_card_inline.php';
const CAN_EDIT = <?= $CAN_EDIT ? 'true' : 'false' ?>;
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;
const PERSON_ID = <?= (int)$pid ?>;
const VERIFY_BASE = <?= json_encode($verifyBase) ?>;

function clean(s){ const v = (s||'').replace(/\s+/g,' ').trim(); return v === '—' ? '' : v; }
function notify(type, text, opts={}){ return window.qtaToast ? window.qtaToast(text, type, opts.title, opts) : null; }

async function postJSON(payload){
  const r = await fetch(INLINE, {
    method: 'POST',
    headers: {'Content-Type':'application/json','Accept':'application/json'},
    body: JSON.stringify(Object.assign({csrf: CSRF}, payload))
  });
  const data = await r.json().catch(()=> ({}));
  if (!r.ok || data.ok === false) throw new Error(data.error || 'Ndryshimi nuk u ruajt. Provo sërish.');
  return data;
}

function flash(el, ok){
  el.classList.remove('is-saved','is-failed');
  el.classList.add(ok ? 'is-saved' : 'is-failed');
  setTimeout(()=> el.classList.remove('is-saved','is-failed'), 1200);
}

/* Emri në krye rifreskohet kur ndryshon emri ose mbiemri */
function refreshHeading(){
  const val = f => clean(document.querySelector(`.editable[data-field="${f}"]`)?.textContent);
  const full = [val('first_name'), val('father_name'), val('last_name')].filter(Boolean).join(' ');
  const h1 = document.querySelector('[data-person-name]');
  if (h1 && full) h1.textContent = full;
  const ini = document.querySelector('[data-person-initials]');
  if (ini && full) { const p = full.split(' ').filter(Boolean); ini.textContent = ((p[0]||'')[0] + (p.length > 1 ? p[p.length-1][0] : '')).toUpperCase(); }
}

/* Tekst i ndryshueshëm: ruhet kur del nga fusha ose shtyp Enter; Esc e kthen */
document.querySelectorAll('.editable[contenteditable="true"]').forEach(el=>{
  el.addEventListener('focus', ()=>{ el.dataset.prev = clean(el.textContent); });
  el.addEventListener('keydown', e=>{
    if (e.key === 'Enter'){ e.preventDefault(); el.blur(); }
    if (e.key === 'Escape'){ e.preventDefault(); el.textContent = el.dataset.prev || ''; el.blur(); }
  });
  el.addEventListener('paste', e=>{
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
    document.execCommand('insertText', false, text.replace(/\s+/g,' ').trim());
  });
  el.addEventListener('blur', async ()=>{
    if (!CAN_EDIT || !EDIT_MODE) return;
    const value = clean(el.textContent);
    el.textContent = value;
    if (value === (el.dataset.prev || '')) return;
    try{
      const res = await postJSON({action:'set_person_field', person_id: PERSON_ID, field: el.dataset.field, value});
      el.textContent = clean(res.display ?? value);
      el.dataset.prev = clean(el.textContent);
      flash(el, true);
      refreshHeading();
      notify('success', 'Ndryshimi u ruajt.');
    }catch(e){
      el.textContent = el.dataset.prev || '';
      flash(el, false);
      notify('danger', e.message);
    }
  });
});

/* Gjinia */
document.querySelectorAll('select.inline-select[data-field]').forEach(sel=>{
  sel.dataset.prev = sel.value;
  sel.addEventListener('change', async ()=>{
    if (!CAN_EDIT || !EDIT_MODE) return;
    try{
      await postJSON({action:'set_person_field', person_id: PERSON_ID, field: sel.dataset.field, value: sel.value});
      sel.dataset.prev = sel.value;
      flash(sel, true);
      notify('success', 'Ndryshimi u ruajt.');
    }catch(e){
      sel.value = sel.dataset.prev;
      flash(sel, false);
      notify('danger', e.message);
    }
  });
});

/* Datëlindja: dd.mm.vvvv (pranohen edhe viza ose pjerrëta) */
document.querySelectorAll('.dmy-input').forEach(inp=>{
  inp.dataset.prev = inp.value.trim();
  inp.addEventListener('input', ()=>{
    const d = inp.value.replace(/\D/g,'').slice(0,8);
    if (/^\d*$/.test(inp.value.replace(/[.\-\/]/g,''))) {
      let out = d.slice(0,2);
      if (d.length > 2) out += '.' + d.slice(2,4);
      if (d.length > 4) out += '.' + d.slice(4,8);
      inp.value = out;
    }
  });
  inp.addEventListener('keydown', e=>{
    if (e.key === 'Enter'){ e.preventDefault(); inp.blur(); }
    if (e.key === 'Escape'){ e.preventDefault(); inp.value = inp.dataset.prev; inp.blur(); }
  });
  inp.addEventListener('blur', async ()=>{
    if (!CAN_EDIT || !EDIT_MODE) return;
    const value = inp.value.trim();
    if (value === inp.dataset.prev) return;
    if (value !== '' && !/^\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{4}$/.test(value)){
      inp.classList.add('is-invalid');
      notify('danger', 'Shkruaje datëlindjen si dd.mm.vvvv, p.sh. 05.03.1990.');
      return;
    }
    try{
      const res = await postJSON({action:'set_person_field', person_id: PERSON_ID, field:'birth_date', value});
      inp.value = (res.display && res.display !== '—') ? res.display : '';
      inp.dataset.prev = inp.value;
      inp.classList.remove('is-invalid');
      flash(inp, true);
      notify('success', 'Ndryshimi u ruajt.');
    }catch(e){
      inp.classList.add('is-invalid');
      notify('danger', e.message);
    }
  });
});

/* ===== Kodi QR ===== */
function showQr(url){
  const panel = document.querySelector('[data-qr-panel]');
  const missing = document.querySelector('[data-qr-missing]');
  const box = document.getElementById('personQr');
  box.setAttribute('data-qr', url);
  window.qtaRenderQr && window.qtaRenderQr(box);
  document.getElementById('qrOpen').href = url;
  document.getElementById('qrCopy').setAttribute('data-copy', url);
  panel.hidden = false;
  missing.hidden = true;
}

document.getElementById('qrGenerate')?.addEventListener('click', async (ev)=>{
  const btn = ev.currentTarget;
  btn.disabled = true; btn.classList.add('is-loading');
  try{
    const res = await postJSON({action:'generate_qr_person', person_id: PERSON_ID});
    showQr(VERIFY_BASE + '?pid=' + PERSON_ID + '&t=' + encodeURIComponent(res.token));
    notify('success', 'Kodi QR u krijua.');
  }catch(e){
    notify('danger', e.message);
  }finally{
    btn.disabled = false; btn.classList.remove('is-loading');
  }
});

document.getElementById('qrDownload')?.addEventListener('click', ()=>{
  const png = window.qtaQrPng ? window.qtaQrPng(document.getElementById('personQr')) : '';
  if (!png){ notify('danger', 'Kodi QR nuk u përgatit dot. Rifresko faqen dhe provo sërish.'); return; }
  const a = document.createElement('a');
  a.href = png;
  a.download = 'kodi-qr-' + (document.querySelector('[data-person-name]')?.textContent.trim().replace(/\s+/g,'-').toLowerCase() || PERSON_ID) + '.png';
  document.body.appendChild(a); a.click(); a.remove();
});
</script>
<?php endif; ?>
</body>
</html>
