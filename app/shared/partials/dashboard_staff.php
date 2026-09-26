<?php
declare(strict_types=1);

/**
 * dashboard_staff.php — "Kreu" për stafin (administrator dhe editor).
 *
 * Rendi i informacionit ndjek punën e ditës:
 *   1. çfarë pret për ty (raste që kërkojnë veprim)
 *   2. veprimet e shpeshta
 *   3. kjo javë (grupe që nisin/mbarojnë, provime)
 *   4. të fundit në regjistër
 *   5. shifrat e regjistrit
 *
 * Pritet nga faqja prind: $pdo (PDO), $currentUser (array), $DASH_ROLE ('administrator'|'editor').
 * Vetëm lexim: asnjë query këtu nuk ndryshon të dhëna.
 */

require_once __DIR__ . '/../themeli.php';

$dashRole  = $DASH_ROLE ?? 'administrator';
$isAdmin   = $dashRole === 'administrator';
$logsHref  = $isAdmin ? 'logs.php' : 'logs_editor.php';

$one = static function (PDO $pdo, string $sql, array $fallback = []) {
  try { $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r !== false ? $r : $fallback; }
  catch (Throwable $e) { return $fallback; }
};
$many = static function (PDO $pdo, string $sql): array {
  try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
  catch (Throwable $e) { return []; }
};

/* 1. Çfarë pret dorën e dikujt — shfaqen vetëm rastet me numër > 0. */
$checks = [
  ['Kursantë pa grup', 'Janë regjistruar, por ende nuk janë caktuar në një grup.', 'students_without_groups.php', 'Cakto në grup', 'warning',
   "SELECT COUNT(*) n FROM students s WHERE NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.student_id = s.id)"],
  ['Grupe që kanë mbaruar, por s\'janë mbyllur', 'Mësimi ka mbaruar. Kontrollo provimet dhe pikët, pastaj mbyll grupin.', 'lesson_groups.php', 'Shiko grupet', 'warning',
   "SELECT COUNT(*) n FROM course_groups WHERE model = 'scheduled' AND end_date < CURDATE() AND (is_completed = 0 OR is_completed IS NULL)"],
  ['Grupe të mëparshme që kanë mbaruar, por s\'janë mbyllur', 'Data e mbarimit ka kaluar. Kontrollo provimet dhe mbyll grupin.', 'groups.php', 'Shiko grupet e mëparshme', 'warning',
   "SELECT COUNT(*) n FROM course_groups WHERE model = 'legacy' AND end_date < CURDATE() AND (is_completed = 0 OR is_completed IS NULL)"],
  ['Kurse që kursantët i presin, por s\'janë gati', 'Kursantët e kanë zgjedhur kursin, por kursi nuk ka ende module dhe tema me orët e plota, prandaj grupi me orar nuk krijohet.', 'courses.php', 'Plotëso kurset', 'info',
   "SELECT COUNT(*) n FROM courses c
     WHERE EXISTS (SELECT 1 FROM student_course_plans p WHERE p.course_id = c.id AND p.status = 'planned')
       AND (NOT EXISTS (SELECT 1 FROM course_modules m WHERE m.course_id = c.id)
            OR c.hours <> (SELECT COALESCE(SUM(m.hours),0) FROM course_modules m WHERE m.course_id = c.id)
            OR EXISTS (SELECT 1 FROM course_modules m
                       WHERE m.course_id = c.id
                         AND m.hours <> (SELECT COALESCE(SUM(t.hours),0) FROM course_topics t WHERE t.module_id = m.id)))"],
  ['Kursantë pa datë provimi', 'Grupi ka mbaruar, por kursantit nuk i është caktuar data e provimit.', 'register.php', 'Cakto provimet', 'danger',
   "SELECT COUNT(*) n FROM course_group_students cgs JOIN course_groups cg ON cg.id = cgs.group_id WHERE cgs.exam_date IS NULL AND cg.end_date < CURDATE()"],
  ['Kartela pa numër personal', 'Pa numrin personal kursanti nuk mund të hyjë në llogarinë e vet.', 'students.php', 'Plotëso', 'info',
   "SELECT COUNT(*) n FROM persons WHERE personal_number IS NULL OR personal_number = ''"],
  ['Datëlindje për t\'u kontrolluar', 'Mosha del nën 15 ose mbi 90 vjeç — ndoshta data është shkruar gabim.', 'students.php', 'Kontrollo', 'info',
   "SELECT COUNT(*) n FROM persons WHERE birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) NOT BETWEEN 15 AND 90"],
  ['Grupe bosh', 'Grupe pa asnjë kursant brenda.', 'lesson_groups.php', 'Shiko grupet', 'neutral',
   "SELECT COUNT(*) n FROM course_groups cg WHERE cg.model = 'scheduled' AND NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.group_id = cg.id)"],
  ['Grupe të mëparshme bosh', 'Grupe pa asnjë kursant brenda.', 'groups.php', 'Shiko grupet e mëparshme', 'neutral',
   "SELECT COUNT(*) n FROM course_groups cg WHERE cg.model = 'legacy' AND NOT EXISTS (SELECT 1 FROM course_group_students x WHERE x.group_id = cg.id)"],
];
$waiting = [];
foreach ($checks as $c) {
  $n = (int)($one($pdo, $c[5], ['n' => 0])['n'] ?? 0);
  if ($n > 0) {
    $waiting[] = ['title' => $c[0], 'note' => $c[1], 'href' => $c[2], 'cta' => $c[3], 'tone' => $c[4], 'n' => $n];
  }
}

/* 3. Kjo javë: grupe që nisin, mbarojnë dhe provime në 7 ditët e ardhshme. */
$agenda = $many($pdo, "
  SELECT * FROM (
    SELECT 'start' AS kind, cg.start_date AS d, cg.id AS gid, c.code, c.name,
           (SELECT COUNT(*) FROM course_group_students x WHERE x.group_id = cg.id) AS members
    FROM course_groups cg JOIN courses c ON c.id = cg.course_id
    WHERE cg.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    UNION ALL
    SELECT 'end' AS kind, cg.end_date AS d, cg.id AS gid, c.code, c.name,
           (SELECT COUNT(*) FROM course_group_students x WHERE x.group_id = cg.id) AS members
    FROM course_groups cg JOIN courses c ON c.id = cg.course_id
    WHERE cg.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    UNION ALL
    SELECT 'exam' AS kind, cgs.exam_date AS d, cg.id AS gid, c.code, c.name, COUNT(*) AS members
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE cgs.exam_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    GROUP BY cg.id, cgs.exam_date, c.code, c.name
  ) t
  ORDER BY d ASC, kind ASC
  LIMIT 10
");

/* 4. Të fundit që hynë në regjistër. */
$recent = $many($pdo, "
  SELECT s.id, s.nr_amze, s.created_at,
         TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) AS full_name
  FROM students s
  LEFT JOIN persons p ON p.id = s.person_id
  ORDER BY s.created_at DESC, s.id DESC
  LIMIT 6
");

/* 5. Shifrat. */
$figures = $one($pdo, "
  SELECT
    (SELECT COUNT(*) FROM students) AS students_total,
    (SELECT COUNT(*) FROM course_groups WHERE CURDATE() BETWEEN start_date AND end_date) AS active_groups,
    (SELECT COUNT(*) FROM course_group_students cgs JOIN course_groups cg ON cg.id = cgs.group_id
      WHERE CURDATE() BETWEEN cg.start_date AND cg.end_date) AS active_enrollments,
    (SELECT COUNT(*) FROM course_group_students WHERE final_score IS NOT NULL) AS passed
", ['students_total' => 0, 'active_groups' => 0, 'active_enrollments' => 0, 'passed' => 0]);

$firstName = trim((string)strtok((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? '')), ' '));
$agendaKinds = [
  'start' => ['Nis grupi', 'info', 'bi-play-circle'],
  'end'   => ['Mbaron grupi', 'warning', 'bi-flag'],
  'exam'  => ['Provim', 'accent', 'bi-pencil-square'],
];
?>
<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <span class="eyebrow"><?= h(ucfirst(qta_today_label())) ?></span>
      <h1 class="page-title"><?= h(qta_greeting()) ?><?= $firstName !== '' ? ', ' . h($firstName) : '' ?></h1>
      <p class="page-lead">Ja çfarë ka sot në regjistër. Nis nga rastet që presin veprimin tënd.</p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
      <a class="btn btn-primary" href="students.php?edit=1&amp;add=1">
        <i class="bi bi-person-plus" aria-hidden="true"></i>Regjistro kursant
      </a>
    </div>
  </header>

  <!-- Kërkimi: veprimi më i shpeshtë i ditës -->
  <form class="section d-flex flex-column flex-sm-row gap-2" method="get" action="students.php" role="search" aria-label="Gjej një kursant">
    <label class="visually-hidden" for="dashSearch">Gjej një kursant</label>
    <div class="search-field is-lg flex-grow-1">
      <i class="bi bi-search" aria-hidden="true"></i>
      <input class="form-control" id="dashSearch" type="search" name="q"
             placeholder="Gjej një kursant — emri, numri personal ose numri i amzës" autocomplete="off">
    </div>
    <button class="btn btn-secondary btn-lg" type="submit">Kërko</button>
  </form>

  <!-- 1. Çfarë pret për ty -->
  <section class="section" aria-labelledby="waitTitle">
    <div class="section-head">
      <h2 class="section-title" id="waitTitle">Çfarë pret për ty</h2>
      <?php if ($waiting): ?>
        <span class="section-meta"><?= h(qta_plural(count($waiting), 'çështje', 'çështje')) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($waiting): ?>
      <div class="tasks">
        <?php foreach ($waiting as $w): ?>
          <a class="task is-<?= h($w['tone']) ?>" href="<?= h($w['href']) ?>">
            <span class="task-count"><?= number_format($w['n'], 0, ',', '.') ?></span>
            <span class="task-body">
              <span class="task-title"><?= h($w['title']) ?></span>
              <span class="task-text"><?= h($w['note']) ?></span>
            </span>
            <span class="task-cta"><?= h($w['cta']) ?><i class="bi bi-arrow-right" aria-hidden="true"></i></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <?= qta_empty('Gjithçka është në rregull', 'Nuk ka asnjë rast që pret veprimin tënd. Mund të regjistrosh kursantë të rinj ose të kontrollosh grupet.', 'bi-check2-circle', '', 'is-success is-compact') ?>
    <?php endif; ?>
  </section>

  <div class="row g-4 g-xl-5">
    <div class="col-12 col-xl-7">

      <!-- 2. Veprimet e shpeshta -->
      <section class="section" aria-labelledby="quickTitle">
        <div class="section-head">
          <h2 class="section-title" id="quickTitle">Nis një punë</h2>
        </div>
        <div class="quick-grid">
          <a class="quick is-primary" href="students.php?edit=1&amp;add=1">
            <span class="quick-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
            <span><span class="quick-title">Regjistro kursant</span><span class="quick-text">Shto një person të ri në regjistër.</span></span>
          </a>
          <a class="quick is-primary" href="students_without_groups.php">
            <span class="quick-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
            <span><span class="quick-title">Cakto në grup</span><span class="quick-text">Kursantët që presin një grup.</span></span>
          </a>
          <a class="quick" href="lesson_groups.php">
            <span class="quick-icon"><i class="bi bi-collection" aria-hidden="true"></i></span>
            <span><span class="quick-title">Grupet</span><span class="quick-text">Orari i mësimit, provimet, dokumentet.</span></span>
          </a>
          <a class="quick" href="register.php">
            <span class="quick-icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span>
            <span><span class="quick-title">Regjistri i plotë</span><span class="quick-text">Provimet dhe pikët e çdo kursanti.</span></span>
          </a>
          <a class="quick" href="student_card.php">
            <span class="quick-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
            <span><span class="quick-title">Kartela e kursantit</span><span class="quick-text">Gjithçka për një person, me QR.</span></span>
          </a>
          <a class="quick" href="verify.php">
            <span class="quick-icon"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></span>
            <span><span class="quick-title">Verifiko certifikatë</span><span class="quick-text">Kontrollo një kod ose QR.</span></span>
          </a>
        </div>
      </section>

      <!-- 4. Të fundit në regjistër -->
      <section class="section" aria-labelledby="recentTitle">
        <div class="section-head">
          <h2 class="section-title" id="recentTitle">Të fundit në regjistër</h2>
          <a class="section-link" href="students.php">Të gjithë kursantët <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <?php if ($recent): ?>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr><th scope="col">Nr. i amzës</th><th scope="col">Kursanti</th><th scope="col" class="text-end">Regjistruar</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recent as $r): ?>
                  <tr>
                    <td><span class="id-code"><?= h((string)$r['nr_amze']) ?></span></td>
                    <td><a class="person-name" href="student_card.php?q=<?= urlencode((string)$r['nr_amze']) ?>"><?= h((string)($r['full_name'] ?: '—')) ?></a></td>
                    <td class="text-end text-muted nowrap"><?= h(qta_ago($r['created_at'] ?? null)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <?= qta_empty('Regjistri është bosh', 'Kursantët e parë që regjistron do të shfaqen këtu.', 'bi-journal', '<a class="btn btn-primary" href="students.php?edit=1&amp;add=1">Regjistro kursant</a>') ?>
        <?php endif; ?>
      </section>
    </div>

    <div class="col-12 col-xl-5">

      <!-- 3. Kjo javë -->
      <section class="section" aria-labelledby="weekTitle">
        <div class="section-head">
          <h2 class="section-title" id="weekTitle">Kjo javë</h2>
          <a class="section-link" href="lesson_groups.php">Të gjitha grupet <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <?php if ($agenda): ?>
          <ul class="agenda">
            <?php foreach ($agenda as $a):
              [$kindLabel, $kindTone, $kindIcon] = $agendaKinds[$a['kind']] ?? ['Ngjarje', 'neutral', 'bi-dot'];
              $ts = strtotime((string)$a['d']); ?>
              <li class="agenda-item">
                <span class="agenda-date" aria-hidden="true">
                  <b><?= h(date('j', $ts)) ?></b>
                  <span><?= h(qta_month_short((int)date('n', $ts))) ?></span>
                </span>
                <span class="agenda-main">
                  <span class="agenda-title"><?= h((string)$a['name']) ?></span>
                  <span class="agenda-meta">
                    <span class="visually-hidden"><?= h(qta_date((string)$a['d'])) ?> · </span>
                    <?= h((string)$a['code']) ?> · <?= h(qta_plural((int)$a['members'], 'kursant', 'kursantë')) ?> · <?= h(qta_when_label((string)$a['d'])) ?>
                  </span>
                </span>
                <?= qta_status($kindLabel, $kindTone, $kindIcon) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <?= qta_empty('Java është e qetë', 'Asnjë grup nuk nis apo mbaron dhe nuk ka provime në 7 ditët e ardhshme.', 'bi-calendar-check', '', 'is-compact') ?>
        <?php endif; ?>
      </section>

      <!-- 5. Shifrat -->
      <section class="section" aria-labelledby="figTitle">
        <div class="section-head">
          <h2 class="section-title" id="figTitle">Regjistri në shifra</h2>
        </div>
        <div class="stats">
          <a class="stat" href="students.php">
            <span class="stat-label">Kursantë të regjistruar</span>
            <span class="stat-value"><?= number_format((int)$figures['students_total'], 0, ',', '.') ?></span>
          </a>
          <a class="stat" href="lesson_groups.php">
            <span class="stat-label">Grupe në vazhdim</span>
            <span class="stat-value"><?= number_format((int)$figures['active_groups'], 0, ',', '.') ?></span>
            <span class="stat-note"><?= h(qta_plural((int)$figures['active_enrollments'], 'kursant', 'kursantë')) ?> në mësim</span>
          </a>
          <div class="stat">
            <span class="stat-label">Provime me pikë</span>
            <span class="stat-value"><?= number_format((int)$figures['passed'], 0, ',', '.') ?></span>
            <span class="stat-note">me 50 pikë e lart</span>
          </div>
        </div>
      </section>

      <section class="section" aria-labelledby="ctlTitle">
        <div class="section-head">
          <h2 class="section-title" id="ctlTitle">Kontrolli</h2>
        </div>
        <div class="d-grid gap-2">
          <a class="btn btn-secondary justify-content-start" href="<?= h($logsHref) ?>">
            <i class="bi bi-clock-history" aria-hidden="true"></i><?= $isAdmin ? 'Historiku i ndryshimeve' : 'Historiku im' ?>
          </a>
          <a class="btn btn-secondary justify-content-start" href="agencies.php">
            <i class="bi bi-building" aria-hidden="true"></i>Agjencitë
          </a>
          <?php if ($isAdmin): ?>
            <a class="btn btn-secondary justify-content-start" href="users.php">
              <i class="bi bi-shield-lock" aria-hidden="true"></i>Llogaritë e stafit
            </a>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>

</main>
