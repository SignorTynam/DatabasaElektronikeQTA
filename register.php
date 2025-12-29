<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

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

/* ------------------------------
   EDIT MODE toggle (persistohet në session)
------------------------------- */
if (isset($_GET['edit'])) {
  $e = strtolower((string)$_GET['edit']);
  $_SESSION['edit_mode'] = ($e === 'on');
  // hiq param 'edit' nga URL
  $qs = $_GET; unset($qs['edit']);
  $url = 'register.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
  header("Location: $url"); exit;
}
$EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

/* Nav identifikim (renderohet brenda <body>) */
$NAV_ACTIVE = 'register';

/* CSRF */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* Flash mesazhe për toast pas redirect/operacioneve tjera */
$flash_ok  = $_SESSION['flash_ok']  ?? null; unset($_SESSION['flash_ok']);
$flash_err = $_SESSION['flash_err'] ?? null; unset($_SESSION['flash_err']);

/* Helper: formatim datash për shfaqje (dd-mm-yyyy) */
function fmt_dMY(?string $iso): string {
  if (!$iso) return '—';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');
  $ts = strtotime($iso);
  return $ts ? date('d-m-Y', $ts) : '—';
}

/* Kërkim dhe filtrat */
$q          = trim($_GET['q'] ?? '');
$from_amze  = trim($_GET['from_amze'] ?? ''); // Fillo nga ky nr AMZË
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 20;
$offset     = ($page - 1) * $limit;

$where  = ["1=1"];
$params = [];

if ($q !== '') {
  $where[] = "(s.nr_amze LIKE :kw OR p.personal_number LIKE :kw2 OR p.first_name LIKE :kw3 OR p.father_name LIKE :kw4 OR p.last_name LIKE :kw5)";
  $params[':kw']  = '%'.$q.'%';
  $params[':kw2'] = '%'.$q.'%';
  $params[':kw3'] = '%'.$q.'%';
  $params[':kw4'] = '%'.$q.'%';
  $params[':kw5'] = '%'.$q.'%';
}

/* Filtro nga nr_amze (nga ai e deri në fund) */
if ($from_amze !== '') {
  if (ctype_digit($from_amze)) {
    $where[] = "CAST(s.nr_amze AS UNSIGNED) >= :from_num";
    $params[':from_num'] = (int)$from_amze;
  } else {
    // fallback leksikografik nëse ka shkronja
    $where[] = "s.nr_amze >= :from_str";
    $params[':from_str'] = $from_amze;
  }
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
  LEFT JOIN courses c ON c.id = cg.course_id
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

    /* ===== MODULI =====
       - nese ka grup (lastg.group_id) → course_name (nga courses)
       - nese s'ka grup → planned_course_name (nga student_course_plans)
    */
    c.name AS course_name,
    (
      SELECT c2.name
      FROM student_course_plans scp
      JOIN courses c2 ON c2.id = scp.course_id
      WHERE scp.student_id = s.id
      ORDER BY scp.id DESC
      LIMIT 1
    ) AS planned_course_name,

    /* grupi i fundit */
    lastg.group_id,
    cg.start_date, cg.end_date,
    cgs.exam_date AS exam_date,        -- EXAM PER-STUDENT
    cgs.final_score

  ".$sqlBase."
  ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  LIMIT :lim OFFSET :off
");
foreach ($params as $k=>$v) {
  $list->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$list->bindValue(':lim',$limit,PDO::PARAM_INT);
$list->bindValue(':off',$offset,PDO::PARAM_INT);
$list->execute();
$rows = $list->fetchAll(PDO::FETCH_ASSOC);

/* Build toggle URL që ruan parametrat aktualë */
$toggleUrl = 'register.php?' . http_build_query(array_filter([
  'q' => ($q !== '' ? $q : null),
  'from_amze' => ($from_amze !== '' ? $from_amze : null),
  'page' => ($page > 1 ? $page : null),
  'edit' => ($EDIT_MODE ? 'off' : 'on'),
]));
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Regjistri – QTA <?= $role==='editor' ? 'Editor' : 'Admin' ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    /* ===== Base layout ===== */
    body { background:#f5f7fb; padding-top:72px; }
    .navbar-brand img { height:28px; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .mini-table thead { background:#f1f5f9; }
    .form-control::placeholder { color:#9ca3af; }
    .pagination .page-link { border-radius:.5rem; }
    .nowrap { white-space:nowrap; }
    @media (max-width: 575.98px) { .navbar-text { display:none; } }

    /* --- Inline editable cells --- */
    .editable { display:inline-block; min-width:72px; padding:.35rem .5rem; border-radius:.5rem; transition:box-shadow .2s, background-color .2s; }
    .editable[contenteditable="true"]:hover { background:#f8fafc; box-shadow:inset 0 0 0 1px #e5e7eb; cursor:text; }
    .editable[contenteditable="true"]:focus { outline:0; background:#eef2ff; box-shadow:inset 0 0 0 2px #4f46e5; }
    .editable[contenteditable="false"] { opacity:.7; cursor:default; }

    .cell-saving { position:relative; }
    .cell-saving::after { content:''; position:absolute; right:.25rem; top:50%; width:.55rem; height:.55rem; border:.15rem solid rgba(0,0,0,.2); border-top-color:rgba(0,0,0,.55); border-radius:50%; animation:spin .6s linear infinite; transform:translateY(-50%); }
    @keyframes spin { to { transform:translateY(-50%) rotate(360deg); } }
    .cell-ok { animation: flashOk 1.2s ease; } @keyframes flashOk { 0%{background:#ecfdf5;} 100%{background:transparent;} }
    .cell-err { animation: flashErr 1.2s ease; } @keyframes flashErr { 0%{background:#fef2f2;} 100%{background:transparent;} }

    /* --- Edit Mode OFF visuals --- */
    .editing-off .editable { color:#6b7280; cursor:not-allowed; }
    .editing-off .btn[disabled], .editing-off input[disabled], .editing-off select[disabled], .editing-off textarea[disabled] { cursor:not-allowed; }

    /* --- Soft buttons (shared) --- */
    .btn-pill { border-radius:999px !important; }
    .btn-soft-primary   { background:#eef2ff; color:#1d4ed8; border:1px solid #e0e7ff; }
    .btn-soft-primary:hover { background:#e0e7ff; color:#1d4ed8; }
    .btn-soft-success   { background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; }
    .btn-soft-success:hover { background:#bbf7d0; color:#14532d; }
    .btn-soft-danger    { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .btn-soft-danger:hover { background:#fecaca; color:#7f1d1d; }
    .btn-soft-secondary { background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; }
    .btn-soft-secondary:hover { background:#e2e8f0; color:#0f172a; }

    /* ===== Floating action buttons (stacked) — identik me faqet e tjera ===== */
    .fab-stack{
      position: fixed;
      right: 24px;
      bottom: 24px;
      display: flex;
      flex-direction: column-reverse;
      gap: 12px;
      z-index: 1040;
    }
    .fab-stack .fab-btn{
      align-self: flex-end;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      min-height: 52px;
      height: 52px;
      width: 52px;                 /* icon-only by default */
      padding: 0 14px;             /* room for label when expanded */
      border-radius: 999px;
      box-shadow: 0 12px 20px rgba(2,6,23,.15);
      transition: width .2s ease, box-shadow .2s ease, transform .06s ease;
      overflow: hidden;
    }
    .fab-stack .fab-btn .fab-text{
      white-space: nowrap;
      max-width: 0;
      opacity: 0;
      transition: max-width .2s ease, opacity .15s ease, margin-left .2s ease;
      margin-left: 0;
    }
    .fab-stack .fab-btn:hover,
    .fab-stack .fab-btn:focus{
      width: auto;                 /* pill with label */
      box-shadow: 0 16px 28px rgba(2,6,23,.22);
    }
    .fab-stack .fab-btn:hover .fab-text,
    .fab-stack .fab-btn:focus .fab-text{
      max-width: 180px;
      opacity: 1;
      margin-left: 4px;
    }
    .fab-stack .fab-btn:active{ transform: translateY(1px); }
    @media (max-width: 575.98px){
      .fab-stack{ right:16px; bottom:16px; gap:10px; }
      .fab-stack .fab-btn{ min-height:48px; height:48px; width:48px; padding:0 12px; }
    }

    /* Toasts poshtë MAJTAS */
    .toast.qta-toast{ border:0; border-radius:.75rem; box-shadow:0 12px 20px rgba(2,6,23,.12); }
    .toast.qta-toast .toast-header{ border-bottom:0; }
    .toast-success .toast-header{ background:#ecfdf5; color:#065f46; }
    .toast-danger  .toast-header{ background:#fef2f2; color:#991b1b; }
    .toast-info    .toast-header{ background:#eff6ff; color:#1e40af; }
    .toast-warning .toast-header{ background:#fff7ed; color:#9a3412; }

    /* Compact paddings */
    .compact .mini-table table.table > :not(caption) > * > * { padding: .35rem .5rem; }
  </style>
</head>
<body class="<?= $EDIT_MODE ? '' : 'editing-off' ?>">

<?php
  // Render navbar brenda body për të shmangur “headers already sent”
  if ($role === 'editor') require __DIR__ . '/inc/navbar4.php';
  else require __DIR__ . '/inc/navbar.php';
?>

<!-- Toasts: poshtë MAJTAS -->
<div id="toastZone" class="toast-container position-fixed start-0 bottom-0 p-3" style="z-index:1080;"></div>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <h2 class="mb-0">Regjistri i studentëve</h2>
  </div>

  <!-- Kërkim & Filtrim -->
  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="register.php">
        <div class="col-lg-9">
          <div class="d-flex align-items-center">
            <label class="form-label mb-0 me-2" style="min-width:70px;">Kërko</label>
            <div class="input-group flex-grow-1">
              <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
              <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control border-0" placeholder="Kërko sipas AMZËS/ID personale/Emrit...">
              <span class="input-group-text bg-light border-0">Fillo nga AMZË</span>
              <input type="text" name="from_amze" value="<?= htmlspecialchars($from_amze) ?>" class="form-control border-0" placeholder="p.sh. 1050">
            </div>
          </div>
        </div>
        <div class="col-lg-3 text-end">
          <button class="btn btn-soft-secondary btn-pill me-1" type="button" onclick="window.location='register.php'">
            <i class="bi bi-x-circle me-1"></i>Pastro
          </button>
          <button class="btn btn-primary btn-pill" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
      <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Regjistri</h5>
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted small me-2"><?= number_format($total) ?> rezultat(e)</span>
        <div class="btn-group" role="group">
          <a class="btn btn-soft-success btn-pill"
             href="register_export.php?f=xlsx&q=<?= urlencode($q) ?>&from_amze=<?= urlencode($from_amze) ?>&csrf=<?= urlencode($CSRF) ?>">
            <i class="bi bi-file-earmark-excel me-1"></i> Excel
          </a>
          <a class="btn btn-soft-danger btn-pill"
             href="register_export.php?f=pdf&q=<?= urlencode($q) ?>&from_amze=<?= urlencode($from_amze) ?>&csrf=<?= urlencode($CSRF) ?>">
            <i class="bi bi-file-earmark-pdf me-1"></i> PDF
          </a>
          <a class="btn btn-soft-primary btn-pill"
             href="register_export.php?f=docx&q=<?= urlencode($q) ?>&from_amze=<?= urlencode($from_amze) ?>&csrf=<?= urlencode($CSRF) ?>">
            <i class="bi bi-file-earmark-word me-1"></i> Word
          </a>
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
            <th>Moduli</th>
            <th class="nowrap">Datë fillimi</th>
            <th class="nowrap">Datë mbarimi</th>
            <th class="nowrap">Datë testimi</th>
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

              <td class="nowrap">
                <?php
                  $moduleLabel = '—';

                  // Nëse ka grup të fundit, shfaq kursin + (opsional) grupin
                  if (!empty($r['group_id'])) {
                    $course = $r['course_name'] ?? '';
                    $gname  = $r['group_name'] ?? '';

                    if ($course !== '' && $gname !== '') $moduleLabel = $course.' — '.$gname;
                    elseif ($course !== '')              $moduleLabel = $course;
                    elseif ($gname !== '')               $moduleLabel = $gname;
                    else                                 $moduleLabel = '—';
                  } else {
                    // Nëse s’ka grup, shfaq planin (nëse ekziston)
                    $moduleLabel = $r['planned_course_name'] ?? '—';
                    if (trim((string)$moduleLabel) === '') $moduleLabel = '—';
                  }

                  echo htmlspecialchars($moduleLabel, ENT_QUOTES, 'UTF-8');
                ?>
              </td>

              <!-- start_date (inline grup) -->
              <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="start_date"
                  title="DD-MM-YYYY">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars(fmt_dMY($r['start_date'])) ?></span>
              </td>

              <!-- end_date (inline grup) -->
              <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="end_date"
                  title="DD-MM-YYYY (≥ data e fillimit)">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars(fmt_dMY($r['end_date'])) ?></span>
              </td>

              <!-- exam_date (inline student) -->
              <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="exam_date"
                  title="DD-MM-YYYY (≥ data e mbarimit të grupit)">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= htmlspecialchars(fmt_dMY($r['exam_date'])) ?></span>
              </td>

              <!-- final_score (inline student) -->
              <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="final_score"
                  title="0–100 (me presje ose pikë)">
                <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>">
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
              $base='register.php?'.http_build_query(array_filter([
                'q'=>$q!==''?$q:null,
                'from_amze'=>$from_amze!==''?$from_amze:null,
              ]));
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

<!-- FAB Stack: vetëm Edit Mode -->
<div class="fab-stack" role="group" aria-label="Veprime shpejta">
  <a id="editModeFab"
     class="fab-btn btn <?= $EDIT_MODE ? 'btn-success' : 'btn-soft-secondary' ?>"
     href="<?= htmlspecialchars($toggleUrl) ?>"
     title="Ndrysho gjendjen e Edit Mode">
    <i class="bi <?= $EDIT_MODE ? 'bi-unlock' : 'bi-lock' ?>"></i>
    <span class="fab-text">Edit Mode: <?= $EDIT_MODE ? 'ON' : 'OFF' ?></span>
  </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'register_inline_update.php';
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;

/* Compact mode si te faqet e tjera */
document.addEventListener('DOMContentLoaded', ()=>document.body.classList.add('compact'));

/* Toast helper — identik me students_without_groups.php */
function notify(type, text, opts={}){
  const zone = document.getElementById('toastZone');
  const id = 't' + Date.now() + Math.random().toString(16).slice(2);
  const icons = { success:'check-circle', danger:'exclamation-triangle', warning:'exclamation-circle', info:'info-circle' };
  const icon = icons[type] || 'bell';
  const title = opts.title ?? (
    type==='success' ? 'Sukses' :
    type==='danger'  ? 'Gabim'  :
    type==='warning' ? 'Kujdes' : 'Njoftim'
  );
  const autohide = opts.autohide ?? true;
  const delay = opts.delay ?? 4500;

  const html = `
    <div id="${id}" class="toast qta-toast toast-${type}" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-header">
        <i class="bi bi-${icon} me-2"></i>
        <strong class="me-auto">${title}</strong>
        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll"></button>
      </div>
      <div class="toast-body">${text}</div>
    </div>`;
  zone.insertAdjacentHTML('beforeend', html);

  const el = document.getElementById(id);
  const t = new bootstrap.Toast(el, { autohide, delay });
  el.addEventListener('hidden.bs.toast', ()=> el.remove());
  t.show();
}

/* Për kompatibilitet me kodin ekzistues */
function showMsg(type, text){ notify(type, text); }

function clean(s){ return (s||'').replace(/\s+/g,' ').trim(); }

/* DD-MM-YYYY -> YYYY-MM-DD për server */
function normalizeDateForServer(v){
  const s = clean(v);
  if (s === '' || s === '—') return '';
  const m = s.match(/^(\d{2})-(\d{2})-(\d{4})$/);
  if (!m) throw new Error('Formati i datës duhet të jetë DD-MM-YYYY.');
  return `${m[3]}-${m[2]}-${m[1]}`;
}

async function saveInline(payload, cell, displayEl, oldVal){
  if (!EDIT_MODE) { notify('warning','Edit Mode është OFF.'); return; }
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
      notify('danger', json.error || 'Gabim i panjohur.');
      return;
    }

    if(displayEl){
      displayEl.textContent = json.display ?? displayEl.textContent;
    }
    cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800);
    notify('success','U ruajt me sukses.');
  }catch(e){
    console.error(e);
    cell.classList.remove('cell-saving');
    if(displayEl) displayEl.textContent = oldVal;
    cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
    notify('danger', 'Nuk u krye veprimi. Kontrollo lidhjen ose provo sërish.');
  }
}

/* inline handlers */
document.querySelectorAll('td.cell .editable').forEach(el=>{
  let oldVal = el.textContent;

  if (!EDIT_MODE) el.setAttribute('contenteditable','false');

  el.addEventListener('focus', ()=>{ oldVal = el.textContent; });
  el.addEventListener('keydown', ev=>{ if(ev.key==='Enter'){ ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', ()=>{
    if (!EDIT_MODE) return;

    const cell = el.closest('td.cell');
    const field = cell.dataset.field;
    const studentId = parseInt(cell.dataset.student,10);
    const groupId = parseInt(cell.dataset.group,10) || null;
    let newVal = clean(el.textContent);

    if(newVal===clean(oldVal)) return;

    if(['start_date','end_date','exam_date'].includes(field)){
      try {
        newVal = normalizeDateForServer(newVal); // -> yyyy-mm-dd ose '' (=> null)
      } catch(err){
        el.textContent = oldVal;
        cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200);
        notify('danger', err.message || err);
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
        notify('danger','Nota duhet të jetë numër.');
        return;
      }
      saveInline({action:'update_final_score', student_id:studentId, group_id:groupId, final_score:n}, cell, el, oldVal);
      return;
    }

    if(field==='start_date'){
      if(!groupId){ el.textContent = oldVal; notify('danger','Ky student s’ka grup.'); return; }
      saveInline({action:'update_group_start', student_id:studentId, group_id:groupId, start_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }
    if(field==='end_date'){
      if(!groupId){ el.textContent = oldVal; notify('danger','Ky student s’ka grup.'); return; }
      saveInline({action:'update_group_end', student_id:studentId, group_id:groupId, end_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }
    if(field==='exam_date'){
      if(!groupId){ el.textContent = oldVal; notify('danger','Ky student s’ka grup.'); return; }
      saveInline({action:'update_student_exam_date', student_id:studentId, group_id:groupId, exam_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }
  });
});
</script>
<script>
/* === Auto-viza për datat (DD-MM-YYYY) në contenteditable === */
function maskToDDMMYYYY(input) {
  const digits = String(input || '').replace(/\D/g, '').slice(0, 8); // max 8 shifra
  const d = digits.slice(0, 2);
  const m = digits.slice(2, 4);
  const y = digits.slice(4, 8);
  let out = d;
  if (digits.length > 2) out += '-' + m;
  if (digits.length > 4) out += '-' + y;
  return out;
}
function placeCaretAtEnd(el) {
  const range = document.createRange();
  range.selectNodeContents(el);
  range.collapse(false);
  const sel = window.getSelection();
  sel.removeAllRanges();
  sel.addRange(range);
}
function attachDateMask(el) {
  el.addEventListener('input', () => {
    const masked = maskToDDMMYYYY(el.textContent);
    if (el.textContent !== masked) {
      el.textContent = masked;
      placeCaretAtEnd(el);
    }
  });
  el.addEventListener('paste', (e) => {
    e.preventDefault();
    const txt = (e.clipboardData || window.clipboardData).getData('text');
    el.textContent = maskToDDMMYYYY(txt);
    placeCaretAtEnd(el);
  });
}

/* === Maskë e thjeshtë për notën (lejo vetëm shifra dhe një presje/pikë) === */
function attachScoreMask(el){
  el.addEventListener('input', ()=>{
    let t = el.textContent;
    // Hiq çdo karakter që s’është shifër, presje apo pikë
    t = t.replace(/[^0-9,\.]/g,'');
    // Lejo vetëm një presje/pikë
    const firstSep = t.search(/[,.]/);
    if (firstSep !== -1){
      const head = t.slice(0, firstSep + 1);
      const tail = t.slice(firstSep + 1).replace(/[,.]/g,'');
      t = head + tail;
    }
    if (el.textContent !== t){
      el.textContent = t;
      placeCaretAtEnd(el);
    }
  });
}

document.querySelectorAll(
  'td.cell[data-field="start_date"] .editable,' +
  'td.cell[data-field="end_date"] .editable,'  +
  'td.cell[data-field="exam_date"] .editable'
).forEach(attachDateMask);

document.querySelectorAll('td.cell[data-field="final_score"] .editable').forEach(attachScoreMask);

/* Shfaq toaste nga sesi (flash) ose gjendja e Edit Mode */
<?php if ($flash_ok): ?>
document.addEventListener('DOMContentLoaded', ()=> notify('success', <?= json_encode($flash_ok) ?>));
<?php endif; ?>
<?php if ($flash_err): ?>
document.addEventListener('DOMContentLoaded', ()=> notify('danger', <?= json_encode($flash_err) ?>));
<?php endif; ?>

// Opsionale: informo për Edit Mode në hyrje (komentoje nëse s’e do)
document.addEventListener('DOMContentLoaded', ()=>{
  notify('info', 'Edit Mode: <?= $EDIT_MODE ? 'ON' : 'OFF' ?>', { delay: 2500 });
});
</script>

</body>
</html>
