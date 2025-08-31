<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* ------------------------------
   Guard: vetëm student i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch();
if (!$currentUser || $currentUser['role_name']!=='student') { header('Location: selectProfile.php'); exit; }

/* Helper HTML safe */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* ------------------------------
   Profili bazë i studentit
------------------------------- */
$S0 = $pdo->prepare("
  SELECT id, first_name, father_name, last_name, personal_number, phone,
         birth_date, birth_place, education_level_id
  FROM students
  WHERE user_id = :uid
  LIMIT 1
");
$S0->execute([':uid'=>$_SESSION['user_id']]);
$meStud = $S0->fetch();

if (!$meStud) { exit('Profili i studentit nuk u gjet.'); }

$personalNumber = (string)($meStud['personal_number'] ?? '');

/* Etiketat e arsimit (opsionale) */
$edu = ['code'=>null,'label'=>null];
if (!empty($meStud['education_level_id'])) {
  $E = $pdo->prepare("SELECT code,label FROM education_levels WHERE id=:id");
  $E->execute([':id'=>(int)$meStud['education_level_id']]);
  $er = $E->fetch();
  if ($er) $edu = ['code'=>$er['code'],'label'=>$er['label']];
}

/* ------------------------------
   AMZË-t për këtë Numër Personal
------------------------------- */
$myAmze = [];
if ($personalNumber !== '') {
  $A = $pdo->prepare("
    SELECT DISTINCT s.nr_amze
    FROM students s
    WHERE s.personal_number = :pn AND s.nr_amze IS NOT NULL AND s.nr_amze <> ''
    ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  ");
  $A->execute([':pn'=>$personalNumber]);
  $myAmze = array_map(fn($r)=> (string)$r['nr_amze'], $A->fetchAll(PDO::FETCH_ASSOC));
}

/* ------------------------------
   Grupet e studentit (për të gjitha AMZË-t e tij)
   – lidhje përmes personal_number
------------------------------- */
$groups = [];
if ($personalNumber !== '') {
  $G = $pdo->prepare("
    SELECT
      cg.id AS group_id, cg.start_date, cg.end_date,
      c.code AS course_code, c.name AS course_name, c.hours,
      cgs.final_score, cgs.exam_date AS my_exam,
      s.nr_amze
    FROM students s
    JOIN course_group_students cgs ON cgs.student_id = s.id
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    WHERE s.personal_number = :pn
    ORDER BY cg.start_date DESC, cg.id DESC, CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  ");
  $G->execute([':pn'=>$personalNumber]);
  $groups = $G->fetchAll(PDO::FETCH_ASSOC);
} else {
  /* Fallback i rrallë: nëse s’ka personal_number, shfaq vetëm grupet e rreshtit aktual */
  $G = $pdo->prepare("
    SELECT
      cg.id AS group_id, cg.start_date, cg.end_date,
      c.code AS course_code, c.name AS course_name, c.hours,
      cgs.final_score, cgs.exam_date AS my_exam,
      s.nr_amze
    FROM course_group_students cgs
    JOIN course_groups cg ON cg.id = cgs.group_id
    JOIN courses c ON c.id = cg.course_id
    JOIN students s ON s.id = cgs.student_id
    WHERE s.id = :sid
    ORDER BY cg.start_date DESC, cg.id DESC
  ");
  $G->execute([':sid'=>(int)$meStud['id']]);
  $groups = $G->fetchAll(PDO::FETCH_ASSOC);
}

/* KPI të thjeshta */
$k_total_groups = count($groups);
$allScores = array_values(array_filter(array_map(
  fn($r)=> $r['final_score']!==null ? (float)$r['final_score'] : null, $groups
), fn($v)=> $v!==null));
$k_avg_score = $allScores ? round(array_sum($allScores)/count($allScores), 2) : null;
$k_pass_rate = null;
if ($allScores) {
  $pass = 0; foreach ($allScores as $sc) if ($sc >= 50) $pass++;
  $k_pass_rate = round(($pass / count($allScores)) * 100, 1);
}

/* Navbar studenti */
$NAV_ACTIVE = 'groups'; // (navbar3 ka vetëm Dashboard/Profili; kjo thjesht mban stilin)
require __DIR__ . '/inc/navbar3.php';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Grupet e mia – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .navbar-brand img { height:28px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .mini-table thead { background:#f1f5f9; }
    .badge-soft { background:#f1f5f9; color:#475569; }
    .nowrap { white-space:nowrap; }
    @media (max-width: 575.98px) { .navbar-text { display:none; } }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">

  <!-- Tabela: grupet (read-only) -->
  <section class="card">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
      <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Grupet e mia</h5>
      <span class="text-muted small"><?= number_format($k_total_groups) ?> grup(e)</span>
    </div>
    <div class="card-body">
      <div class="table-responsive mini-table">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="nowrap">Grupi</th>
              <th>Moduli</th>
              <th class="nowrap">AMZË</th>
              <th class="nowrap">Fillimi</th>
              <th class="nowrap">Mbarimi</th>
              <th class="nowrap">Data e testit</th>
              <th class="nowrap">Pikët</th>
              <th class="nowrap">Statusi</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($groups): foreach ($groups as $g):
            $today = date('Y-m-d');
            $status = 'Aktiv';
            if ($g['final_score'] !== null) {
              $status = ((float)$g['final_score'] >= 50) ? 'Përfunduar (kaloi)' : 'Përfunduar (jo-kalues)';
            } elseif (!empty($g['my_exam'])) {
              $status = ($g['my_exam'] >= $today) ? ('Test më '.$g['my_exam']) : 'Test i kaluar';
            } elseif (!empty($g['end_date']) && $g['end_date'] < $today) {
              $status = 'Mbyllur';
            }
          ?>
            <tr>
              <td class="nowrap">#<?= (int)$g['group_id'] ?></td>
              <td><?= h(($g['course_code'] ?? '').' · '.($g['course_name'] ?? '')) ?></td>
              <td class="nowrap"><?= h($g['nr_amze'] ?? '—') ?></td>
              <td class="nowrap"><?= h($g['start_date'] ?? '') ?></td>
              <td class="nowrap"><?= h($g['end_date'] ?? '') ?></td>
              <td class="nowrap"><?= h($g['my_exam'] ?? '—') ?></td>
              <td class="nowrap"><?= $g['final_score']!==null ? rtrim(rtrim((string)$g['final_score'],'0'),'.') : '—' ?></td>
              <td class="nowrap"><span class="badge badge-soft rounded-pill"><?= h($status) ?></span></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="8" class="text-center text-muted">Aktualisht s’je i regjistruar në asnjë grup.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
