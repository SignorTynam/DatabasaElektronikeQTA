<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* ===== Vetëm kursanti ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || ($currentUser['role_name']??'')!=='student') { header('Location: selectProfile.php'); exit; }

require_once __DIR__ . '/../shared/themeli.php';
$today = date('Y-m-d');

/* ===== Profili bazë i kursantit ===== */
$baseQ = $pdo->prepare("
  SELECT s.id AS sid, s.person_id, p.personal_number
  FROM students s JOIN persons p ON p.id=s.person_id
  WHERE s.user_id=:uid LIMIT 1
");
$baseQ->execute([':uid'=>$_SESSION['user_id']]);
$base = $baseQ->fetch(PDO::FETCH_ASSOC);
if (!$base) { exit('Profili i kursantit nuk u gjet.'); }

$baseSid        = (int)$base['sid'];
$basePersonId   = (int)$base['person_id'];
$personalNumber = (string)($base['personal_number'] ?? '');

/* Të gjitha regjistrimet (AMZË) të të njëjtit person */
$amzeRows = $pdo->prepare("
  SELECT id, nr_amze FROM students
  WHERE person_id=:pid
  ORDER BY CAST(nr_amze AS UNSIGNED) ASC, nr_amze ASC
");
$amzeRows->execute([':pid'=>$basePersonId]);
$amzeRows = $amzeRows->fetchAll(PDO::FETCH_ASSOC);

$studentIds = $amzeRows ? array_values(array_unique(array_map(fn($r)=>(int)$r['id'], $amzeRows))) : [$baseSid];
$amzeList   = array_values(array_filter(array_map(fn($r)=>$r['nr_amze'] ?? null, $amzeRows)));

/* Të dhënat personale + arsimi */
$S = $pdo->prepare("
  SELECT
    p.first_name, p.father_name, p.last_name, p.birth_date, p.birth_place,
    s.nr_amze, p.personal_number, p.phone,
    el.code AS edu_code, el.label AS edu_label
  FROM students s
  JOIN persons p ON p.id=s.person_id
  LEFT JOIN education_levels el ON el.id=s.education_level_id
  WHERE s.id=:sid LIMIT 1
");
$S->execute([':sid'=>$baseSid]);
$stud = $S->fetch(PDO::FETCH_ASSOC) ?: [];

/* Agjencia (maksimumi 1 sipas skemës) */
$company = null;
if ($studentIds) {
  $ph = implode(',', array_fill(0, count($studentIds), '?'));
  $C = $pdo->prepare("
    SELECT a.company_name, a.nip_t, a.phone
    FROM agency_students s
    JOIN agencies a ON a.id=s.agency_id
    WHERE s.student_id IN ($ph)
    LIMIT 1
  ");
  $C->execute($studentIds);
  $company = $C->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Grupet për të gjitha regjistrimet */
$groups = [];
if ($studentIds) {
  $ph = implode(',', array_fill(0, count($studentIds), '?'));
  $G = $pdo->prepare("
    SELECT cg.id AS group_id, cg.start_date, cg.end_date,
           c.code AS course_code, c.name AS course_name, c.hours,
           cgs.final_score, cgs.exam_date AS my_exam,
           cgs.student_id, s.nr_amze
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id=cgs.group_id
    JOIN courses c ON c.id=cg.course_id
    JOIN students s ON s.id=cgs.student_id
    WHERE cgs.student_id IN ($ph)
    ORDER BY cg.start_date DESC, cg.id DESC
  ");
  $G->execute($studentIds);
  $groups = $G->fetchAll(PDO::FETCH_ASSOC);
}

/* Kurset e planifikuara që ende s'kanë grup */
$planned = [];
if ($studentIds) {
  $ph = implode(',', array_fill(0, count($studentIds), '?'));
  $P = $pdo->prepare("
    SELECT c.code AS course_code, c.name AS course_name, s.nr_amze
    FROM student_course_plans scp
    JOIN courses c ON c.id = scp.course_id
    JOIN students s ON s.id = scp.student_id
    WHERE scp.student_id IN ($ph) AND scp.group_id IS NULL AND scp.status = 'planned'
    ORDER BY c.name ASC
  ");
  $P->execute($studentIds);
  $planned = $P->fetchAll(PDO::FETCH_ASSOC);
}

/* Kodi QR i verifikimit: tokeni i personit (ose i regjistrimit si rezervë) */
$verifyUrl = null;
$verifyToken = null;
try {
  $q = $pdo->prepare("SELECT token FROM person_qr_tokens WHERE person_id = :pid LIMIT 1");
  $q->execute([':pid' => $basePersonId]);
  $verifyToken = $q->fetchColumn() ?: null;
  if ($verifyToken) {
    $verifyUrl = qta_absolute_url('verify.php') . '?pid=' . $basePersonId . '&t=' . rawurlencode((string)$verifyToken);
  } else {
    $q = $pdo->prepare("SELECT token FROM student_qr_tokens WHERE student_id = :sid LIMIT 1");
    $q->execute([':sid' => $baseSid]);
    $verifyToken = $q->fetchColumn() ?: null;
    if ($verifyToken) {
      $verifyUrl = qta_absolute_url('verify.php') . '?sid=' . $baseSid . '&t=' . rawurlencode((string)$verifyToken);
    }
  }
} catch (Throwable $e) {
  $verifyUrl = null;
}

/* Provimi i radhës: provim i caktuar, pa notë, sot ose më vonë */
$nextExam = null;
foreach ($groups as $g) {
  $hasScore = $g['final_score'] !== null && $g['final_score'] !== '';
  if (!$hasScore && !empty($g['my_exam']) && $g['my_exam'] >= $today) {
    if ($nextExam === null || strcmp((string)$g['my_exam'], (string)$nextExam['my_exam']) < 0) {
      $nextExam = $g;
    }
  }
}
$passedCount = count(array_filter($groups, static fn($g) => $g['final_score'] !== null && $g['final_score'] !== ''));

$fullName  = qta_full_name($stud['first_name'] ?? '', $stud['father_name'] ?? '', $stud['last_name'] ?? '');
$firstName = trim((string)($stud['first_name'] ?? '')) ?: trim((string)strtok((string)($currentUser['full_name'] ?? ''), ' '));

$NAV_ACTIVE  = 'dashboard';
$HELP_TOPIC  = 'dashboard_student';
$pageTitle   = 'Faqja ime';
$pageScripts = $verifyUrl ? ['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'] : [];

require __DIR__ . '/../shared/app_head.php';
require __DIR__ . '/inc/navbar3.php';
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <span class="eyebrow"><?= h(ucfirst(qta_today_label())) ?></span>
      <h1 class="page-title"><?= h(qta_greeting()) ?><?= $firstName !== '' ? ', ' . h($firstName) : '' ?></h1>
      <p class="page-lead">Këtu sheh kurset ku je regjistruar, provimet dhe pikët e tua.</p>
    </div>
    <div class="page-actions">
      <?= qta_help_button() ?>
    </div>
  </header>

  <?php if ($nextExam): ?>
    <section class="section" aria-label="Provimi yt i radhës">
      <div class="callout">
        <span class="callout-icon"><i class="bi bi-calendar-event" aria-hidden="true"></i></span>
        <div class="callout-body">
          <span class="callout-label">Provimi yt i radhës · <?= h(qta_when_label((string)$nextExam['my_exam'])) ?></span>
          <span class="callout-title"><?= h((string)$nextExam['course_name']) ?></span>
          <span class="callout-text">
            <?= h(ucfirst(qta_weekday((int)date('N', strtotime((string)$nextExam['my_exam']))))) ?>,
            <?= h(qta_date((string)$nextExam['my_exam'])) ?>. Merr me vete një dokument identifikimi.
          </span>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <div class="row g-4 g-xl-5">
    <div class="col-12 col-lg-7">
      <section class="section" aria-labelledby="modTitle">
        <div class="section-head">
          <h2 class="section-title" id="modTitle">Kurset e mia</h2>
          <?php if ($groups): ?>
            <span class="section-meta"><?= h($passedCount . ' nga ' . count($groups)) ?> me pikë</span>
          <?php endif; ?>
        </div>

        <?php if ($groups || $planned): ?>
          <ul class="enroll-list">
            <?php foreach ($groups as $g): ?>
              <li class="enroll">
                <div class="enroll-main">
                  <span class="enroll-code"><?= h((string)$g['course_code']) ?></span>
                  <h3 class="enroll-title"><?= h((string)$g['course_name']) ?></h3>
                  <dl class="enroll-meta">
                    <div><dt>Trajnimi</dt><dd><?= h(qta_date((string)$g['start_date'])) ?> – <?= h(qta_date((string)$g['end_date'])) ?></dd></div>
                    <div><dt>Provimi</dt><dd><?= h(qta_date((string)($g['my_exam'] ?? ''), 'pa caktuar')) ?></dd></div>
                    <div><dt>Nr. i amzës</dt><dd class="code"><?= h((string)$g['nr_amze']) ?></dd></div>
                  </dl>
                </div>
                <div><?= qta_enrollment_status($g) ?></div>
              </li>
            <?php endforeach; ?>
            <?php foreach ($planned as $p): ?>
              <li class="enroll">
                <div class="enroll-main">
                  <span class="enroll-code"><?= h((string)$p['course_code']) ?></span>
                  <h3 class="enroll-title"><?= h((string)$p['course_name']) ?></h3>
                  <dl class="enroll-meta">
                    <div><dt>Nr. i amzës</dt><dd class="code"><?= h((string)$p['nr_amze']) ?></dd></div>
                  </dl>
                </div>
                <div><?= qta_status('Pret caktimin në grup', 'neutral', 'bi-hourglass-split') ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <?= qta_empty('Ende pa kurse', 'Sapo QTA të të caktojë në një grup, kursi dhe datat shfaqen këtu.', 'bi-mortarboard') ?>
        <?php endif; ?>
      </section>
    </div>

    <div class="col-12 col-lg-5">
      <?php if ($verifyUrl): ?>
        <section class="section" aria-labelledby="qrTitle">
          <div class="section-head">
            <h2 class="section-title" id="qrTitle">Kodi im QR</h2>
          </div>
          <div class="panel text-center">
            <div class="qr-frame mb-3" data-qr="<?= h($verifyUrl) ?>" data-qr-size="184" data-qr-alt="Kodi QR i verifikimit të certifikatave të mia">
              <span class="text-muted small">Po përgatitet kodi…</span>
            </div>
            <p class="mb-3 text-muted">Tregoja këtë kod inspektorit. Ai e skanon me telefon dhe sheh që certifikatat e tua janë të vërteta.</p>
            <div class="d-flex flex-wrap justify-content-center gap-2">
              <button class="btn btn-secondary" type="button" data-bs-toggle="modal" data-bs-target="#qrModal">
                <i class="bi bi-arrows-fullscreen" aria-hidden="true"></i>Shfaq më të madh
              </button>
              <a class="btn btn-ghost" href="<?= h($verifyUrl) ?>">
                <i class="bi bi-patch-check" aria-hidden="true"></i>Shiko verifikimin
              </a>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <section class="section" aria-labelledby="meTitle">
        <div class="section-head">
          <h2 class="section-title" id="meTitle">Të dhënat e mia</h2>
        </div>
        <div class="panel">
          <dl class="kv">
            <dt>Emri i plotë</dt><dd><?= h($fullName !== '' ? $fullName : '—') ?></dd>
            <dt>Numri personal</dt><dd class="code"><?= h($personalNumber !== '' ? $personalNumber : ($stud['personal_number'] ?? '—')) ?></dd>
            <dt>Datëlindja</dt><dd><?= h(qta_date($stud['birth_date'] ?? null)) ?></dd>
            <dt>Vendlindja</dt><dd><?= h((string)(($stud['birth_place'] ?? '') ?: '—')) ?></dd>
            <dt>Telefoni</dt><dd><?= h((string)(($stud['phone'] ?? '') ?: '—')) ?></dd>
            <dt>Arsimi</dt><dd><?= h((string)(($stud['edu_label'] ?? '') ?: '—')) ?></dd>
            <dt>Agjencia</dt><dd><?= h((string)($company['company_name'] ?? 'Pa agjenci')) ?></dd>
            <dt><?= count($amzeList) > 1 ? 'Nr. e amzës' : 'Nr. i amzës' ?></dt>
            <dd class="code"><?= h($amzeList ? implode(', ', $amzeList) : '—') ?></dd>
          </dl>
        </div>
        <div class="notice mt-3">
          <i class="bi bi-info-circle" aria-hidden="true"></i>
          <span>Diçka nuk është e saktë? <a href="contact.php">Na shkruaj</a> dhe e ndreqim ne. Ti nuk mund t'i ndryshosh vetë këto të dhëna.</span>
        </div>
      </section>
    </div>
  </div>

</main>

<?php if ($verifyUrl): ?>
<div class="modal fade" id="qrModal" tabindex="-1" aria-labelledby="qrModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="qrModalTitle">Kodi im QR</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body text-center">
        <div class="qr-frame" data-qr="<?= h($verifyUrl) ?>" data-qr-size="280" data-qr-alt="Kodi QR i verifikimit, i zmadhuar"></div>
        <p class="mt-3 mb-0 text-muted"><?= h($fullName) ?></p>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
</body>
</html>
