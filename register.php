<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* Guard: admin OSE editor */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch(PDO::FETCH_ASSOC);

$role = strtolower((string)($currentUser['role_name'] ?? ''));
if (!$currentUser || !in_array($role, ['administrator','editor'], true)) {
  header('Location: selectProfile.php'); exit;
}

/* Navbar sipas rolit (opsionale) */
$NAV_ACTIVE = 'register';
if ($role === 'editor') {
  require __DIR__ . '/inc/navbar4.php';   // navbar i editorit
} else {
  require __DIR__ . '/inc/navbar.php';    // navbar i administratorit
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* Kërkim */
$q = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where = ["1=1"];
$params = [];
if ($q !== '') {
  $where[] = "(s.nr_amze LIKE :kw OR p.personal_number LIKE :kw2 OR p.first_name LIKE :kw3 OR p.father_name LIKE :kw4 OR p.last_name LIKE :kw5)";
  $params[':kw']  = '%'.$q.'%';
  $params[':kw2'] = '%'.$q.'%';
  $params[':kw3'] = '%'.$q.'%';
  $params[':kw4'] = '%'.$q.'%';
  $params[':kw5'] = '%'.$q.'%';
}
$whereSql = 'WHERE '.implode(' AND ', $where);

/* Subquery: grupi i fundit për çdo student */
$sqlBase = "
  FROM students s
  JOIN users u   ON u.id = s.user_id
  JOIN persons p ON p.id = s.person_id
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
  $whereSql
";

/* total */
$count = $pdo->prepare("SELECT COUNT(*) ".$sqlBase);
$count->execute($params);
$total = (int)$count->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

/* list */
$list = $pdo->prepare("
  SELECT
    s.id AS student_id,
    s.nr_amze,

    /* nga persons */
    p.first_name, p.father_name, p.last_name,
    p.personal_number,
    p.birth_date, p.birth_place,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) AS age,

    /* arsimi */
    el.code AS edu_code, el.label AS edu_label,

    /* grupi i fundit */
    lastg.group_id,
    cg.start_date, cg.end_date,
    cgs.exam_date AS exam_date,        -- EXAM PER-STUDENT
    cgs.final_score
  ".$sqlBase."
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  LIMIT :lim OFFSET :off
");
foreach ($params as $k=>$v) $list->bindValue($k,$v,PDO::PARAM_STR);
$list->bindValue(':lim',$limit,PDO::PARAM_INT);
$list->bindValue(':off',$offset,PDO::PARAM_INT);
$list->execute();
$rows = $list->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Regjistri – QTA Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f5f7fb; padding-top:72px; }
    .navbar-brand img { height:28px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .mini-table thead { background:#f1f5f9; }
    .form-control::placeholder { color:#9ca3af; }
    .pagination .page-link { border-radius:.5rem; }
    .nowrap { white-space:nowrap; }
    @media (max-width: 575.98px) { .navbar-text { display:none; } }

    .editable { display:inline-block; min-width:72px; padding:.35rem .5rem; border-radius:.5rem; transition:box-shadow .2s, background-color .2s; }
    .editable:hover { background:#f8fafc; box-shadow:inset 0 0 0 1px #e5e7eb; }
    .editable:focus { outline:0; background:#eef2ff; box-shadow:inset 0 0 0 2px #4f46e5; }
    .cell-saving { position:relative; }
    .cell-saving::after { content:''; position:absolute; right:.25rem; top:50%; width:.55rem; height:.55rem; border:.15rem solid rgba(0,0,0,.2); border-top-color:rgba(0,0,0,.55); border-radius:50%; animation:spin .6s linear infinite; transform:translateY(-50%); }
    @keyframes spin { to { transform:translateY(-50%) rotate(360deg); } }
    .cell-ok { animation: flashOk 1.2s ease; } @keyframes flashOk { 0%{background:#ecfdf5;} 100%{background:transparent;} }
    .cell-err { animation: flashErr 1.2s ease; } @keyframes flashErr { 0%{background:#fef2f2;} 100%{background:transparent;} }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <h2 class="mb-0">Regjistri i studentëve</h2>
    <form class="d-flex" method="get" action="register.php">
      <div class="input-group">
        <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control border-0" placeholder="Kërko sipas AMZËS/ID/Emrit...">
        <button class="btn btn-outline-secondary" type="button" onclick="window.location='register.php'">
          <i class="bi bi-x-circle me-1"></i>Pastro
        </button>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
      </div>
    </form>
  </div>

  <div id="msgBox" class="mb-3" style="display:none;"></div>

  <div class="card">
    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
      <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Regjistri</h5>
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted small me-2"><?= number_format($total) ?> rezultat(e)</span>
        <div class="btn-group" role="group">
          <a class="btn btn-outline-success" href="register_export.php?f=xlsx&q=<?= urlencode($q) ?>&csrf=<?= urlencode($CSRF) ?>"><i class="bi bi-file-earmark-excel me-1"></i> Excel</a>
          <a class="btn btn-outline-danger" href="register_export.php?f=pdf&q=<?= urlencode($q) ?>&csrf=<?= urlencode($CSRF) ?>"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</a>
          <a class="btn btn-outline-primary" href="register_export.php?f=docx&q=<?= urlencode($q) ?>&csrf=<?= urlencode($CSRF) ?>"><i class="bi bi-file-earmark-word me-1"></i> Word</a>
        </div>
      </div>
    </div>

    <div class="card-body">
      <div class="table-responsive mini-table">
        <table class="table align-middle mb-0">
          <thead class="table-light">
          <tr>
            <th class="nowrap">AMZË</th>
            <th>Emër Atësi Mbiemër<br><small class="text-muted">ID Personal</small></th>
            <th class="nowrap">Datë fillimi (grup)</th>
            <th class="nowrap">Datë mbarimi (grup)</th>
            <th class="nowrap">Datë testimi (student)</th>
            <th class="nowrap">Pikët përfundimtare</th>
            <th class="nowrap">Mosha</th>
            <th class="nowrap">Arsimi</th>
          </tr>
          </thead>
          <tbody>
          <?php if ($rows): foreach ($rows as $r):
            $sid = (int)$r['student_id'];
            $gid = $r['group_id'] !== null ? (int)$r['group_id'] : 0;
            $full = trim(($r['first_name']??'').' '.(($r['father_name']??'')?($r['father_name'].' '):'').($r['last_name']??''));
            ?>
            <tr>
              <td class="nowrap"><?= htmlspecialchars($r['nr_amze']) ?></td>

              <td>
                <div class="fw-semibold"><?= htmlspecialchars($full) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($r['personal_number'] ?? '—') ?></div>
              </td>

              <!-- start_date (inline grup) -->
              <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="start_date"
                  title="YYYY-MM-DD">
                <span class="editable" contenteditable="true"><?= htmlspecialchars($r['start_date'] ?: '—') ?></span>
              </td>

              <!-- end_date (inline grup) -->
              <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="end_date"
                  title="YYYY-MM-DD (≥ data e fillimit)">
                <span class="editable" contenteditable="true"><?= htmlspecialchars($r['end_date'] ?: '—') ?></span>
              </td>

              <!-- exam_date (inline student) -->
              <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="exam_date"
                  title="YYYY-MM-DD (≥ data e mbarimit të grupit)">
                <span class="editable" contenteditable="true"><?= htmlspecialchars($r['exam_date'] ?: '—') ?></span>
              </td>

              <!-- final_score (inline student) -->
              <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="final_score"
                  title="0–100 (me presje ose pikë)">
                <span class="editable" contenteditable="true">
                  <?= $r['final_score'] !== null ? rtrim(rtrim((string)$r['final_score'],'0'),'.') : '—' ?>
                </span>
              </td>

              <td class="nowrap"><?= $r['age'] !== null ? (int)$r['age'] : '—' ?></td>
              <td><?= htmlspecialchars(($r['edu_code']? $r['edu_code'].' — ' : '').($r['edu_label'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="8" class="text-center text-muted">Nuk u gjetën studentë.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($totalPages>1): ?>
      <div class="card-footer bg-white">
        <nav aria-label="Page navigation">
          <ul class="pagination mb-0 justify-content-end">
            <?php
              $base='register.php?'.http_build_query(array_filter(['q'=>$q!==''?$q:null]));
              $prev=max(1,$page-1); $next=min($totalPages,$page+1);
              $sep = (str_contains($base,'?')?'&':'?');
            ?>
            <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base.$sep ?>page=1">«</a></li>
            <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base.$sep ?>page=<?= $prev ?>">‹</a></li>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $totalPages ?></span></li>
            <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= $base.$sep ?>page=<?= $next ?>">›</a></li>
            <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= $base.$sep ?>page=<?= $totalPages ?>">»</a></li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'register_inline_update.php';

function clean(s){ return (s||'').replace(/\s+/g,' ').trim(); }
function showMsg(type, text){
  const box = document.getElementById('msgBox');
  box.innerHTML = `
    <div class="alert alert-${type} alert-dismissible fade show" role="alert">
      ${text}
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>`;
  box.style.display = '';
}

async function saveInline(payload, cell, displayEl, oldVal){
  try{
    cell.classList.add('cell-saving');
    const res = await fetch(ENDPOINT, {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({...payload, csrf: CSRF})
    });
    const json = await res.json();
    cell.classList.remove('cell-saving');

    if(!json.ok){
      if(displayEl) displayEl.textContent = oldVal;
      cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
      showMsg('danger', json.error || 'Gabim i panjohur.');
      return;
    }

    if(displayEl){
      displayEl.textContent = json.display ?? displayEl.textContent;
    }
    cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800);
  }catch(e){
    console.error(e);
    cell.classList.remove('cell-saving');
    if(displayEl) displayEl.textContent = oldVal;
    cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
    showMsg('danger', 'Nuk u krye veprimi. Kontrollo lidhjen ose provo sërish.');
  }
}

/* inline handlers */
document.querySelectorAll('td.cell .editable').forEach(el=>{
  let oldVal = el.textContent;
  el.addEventListener('focus', ()=>{ oldVal = el.textContent; });
  el.addEventListener('keydown', ev=>{ if(ev.key==='Enter'){ ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', ()=>{
    const cell = el.closest('td.cell');
    const field = cell.dataset.field;
    const studentId = parseInt(cell.dataset.student,10);
    const groupId = parseInt(cell.dataset.group,10) || null;
    const newVal = clean(el.textContent);

    if(newVal===clean(oldVal)) return;

    if(['start_date','end_date','exam_date'].includes(field)){
      if(newVal!=='' && !/^\d{4}-\d{2}-\d{2}$/.test(newVal)){
        el.textContent = oldVal;
        cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200);
        showMsg('danger','Data duhet në formatin YYYY-MM-DD.');
        return;
      }
    }

    if(field==='final_score'){
      if(newVal===''){
        saveInline({action:'update_final_score', student_id:studentId, group_id:groupId, final_score:null}, cell, el, oldVal);
        return;
      }
      const n = newVal.replace(',','.');
      if(isNaN(n)){
        el.textContent = oldVal;
        cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200);
        showMsg('danger','Nota duhet të jetë numër.');
        return;
      }
      saveInline({action:'update_final_score', student_id:studentId, group_id:groupId, final_score:n}, cell, el, oldVal);
      return;
    }

    if(field==='start_date'){
      if(!groupId){ el.textContent = oldVal; showMsg('danger','Ky student s’ka grup.'); return; }
      saveInline({action:'update_group_start', student_id:studentId, group_id:groupId, start_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }
    if(field==='end_date'){
      if(!groupId){ el.textContent = oldVal; showMsg('danger','Ky student s’ka grup.'); return; }
      saveInline({action:'update_group_end', student_id:studentId, group_id:groupId, end_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }
    if(field==='exam_date'){
      if(!groupId){ el.textContent = oldVal; showMsg('danger','Ky student s’ka grup.'); return; }
      saveInline({action:'update_student_exam_date', student_id:studentId, group_id:groupId, exam_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }
  });
});
</script>
</body>
</html>
