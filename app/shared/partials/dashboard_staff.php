<?php
declare(strict_types=1);

/**
 * dashboard_staff.php — "Kreu" për stafin (administrator dhe editor).
 *
 * Kreu përgjigjet "çfarë mund të bëj nga këtu?", pa shifra dekorative:
 *   1. çfarë pret për ty (raste që kërkojnë veprim) — i pandryshuar
 *   2. nis një punë: vendet kryesore të menusë
 *   3. kjo javë (grupe që nisin/mbarojnë, provime)
 *   4. të fundit në regjistër
 *
 * Pritet nga faqja prind: $pdo (PDO), $currentUser (array).
 * Vetëm lexim: asnjë query këtu nuk ndryshon të dhëna.
 */

require_once __DIR__ . '/../themeli.php';

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
  ['Kursantë pa grup', 'Janë regjistruar, por ende nuk janë caktuar në një grup.', 'students.php?status=no_group', 'Cakto në grup', 'warning',
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
  ['Kursantë pa datë provimi', 'Grupi ka mbaruar, por kursantit nuk i është caktuar data e provimit.', 'students.php?status=no_exam', 'Cakto provimet', 'danger',
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

/* 4. Të fundit që hynë në regjistër (për të vazhduar punën me ta). */
$recent = $many($pdo, "
  SELECT s.id, s.nr_amze, s.created_at,
         TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) AS full_name
  FROM students s
  LEFT JOIN persons p ON p.id = s.person_id
  ORDER BY s.created_at DESC, s.id DESC
  LIMIT 5
");

$firstName = trim((string)strtok((string)($currentUser['full_name'] ?: ($currentUser['email'] ?? '')), ' '));
$agendaKinds = [
  'start' => ['Nis grupi', 'info', 'bi-play-circle'],
  'end'   => ['Mbaron grupi', 'warning', 'bi-flag'],
  'exam'  => ['Provim', 'accent', 'bi-pencil-square'],
];
/* 2. Nis një punë: vendet kryesore, sipas menusë së re. */
$shortcuts = [
  ['students.php?status=no_group',          'bi-people',         'Cakto në grup', true],
  ['lesson_groups.php?edit=1&create=1',     'bi-calendar-plus',  'Krijo grup', true],
  ['lesson_groups.php',                     'bi-calendar-week',  'Regjistri i kurseve profesionale', false],
  ['students.php',                          'bi-person-lines-fill', 'Të gjithë kursantët', false],
  ['student_card.php',                      'bi-person-vcard',   'Kartela e kursantit', false],
  ['courses.php',                           'bi-book',           'Katalogu i kurseve', false],
];
?>
<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <span class="eyebrow"><?= h(ucfirst(qta_today_label())) ?></span>
      <h1 class="page-title"><?= h(qta_greeting()) ?><?= $firstName !== '' ? ', ' . h($firstName) : '' ?></h1>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
      <a class="btn btn-primary" href="students.php?edit=1&amp;add=1">
        <i class="bi bi-person-plus" aria-hidden="true"></i>Regjistro kursant
      </a>
    </div>
  </header>

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

  <!-- 2. Nis një punë -->
  <section class="section" aria-labelledby="quickTitle">
    <div class="section-head">
      <h2 class="section-title" id="quickTitle">Nis një punë</h2>
    </div>
    <nav class="quick-grid is-dense" aria-labelledby="quickTitle">
      <?php foreach ($shortcuts as [$href, $icon, $title, $primary]): ?>
        <a class="quick<?= $primary ? ' is-primary' : '' ?>" href="<?= h($href) ?>">
          <span class="quick-icon"><i class="bi <?= h($icon) ?>" aria-hidden="true"></i></span>
          <span class="quick-title"><?= h($title) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </section>

  <div class="row g-4 g-xl-5">
    <div class="col-12 col-xl-6">
      <!-- 3. Kjo javë -->
      <section class="section" aria-labelledby="weekTitle">
        <div class="section-head">
          <h2 class="section-title" id="weekTitle">Kjo javë</h2>
          <a class="section-link" href="lesson_groups.php">Regjistri i kurseve profesionale <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
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
    </div>

    <div class="col-12 col-xl-6">
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
  </div>

</main>
