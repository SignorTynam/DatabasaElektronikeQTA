<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();

/* Guard admin */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch();
if (!$currentUser || $currentUser['role_name']!=='administrator') { header('Location: selectProfile.php'); exit; }

/* CSRF */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* Flash helpers */
function flash(string $k, ?string $m=null){ if($m===null){ if(!empty($_SESSION['flash'][$k])){ $x=$_SESSION['flash'][$k]; unset($_SESSION['flash'][$k]); return $x; } return null; } $_SESSION['flash'][$k]=$m; }

/* Role agency id */
$roles = $pdo->query("SELECT id,name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$agencyRoleId=null; foreach($roles as $r) if($r['name']==='agjencia'){ $agencyRoleId=(int)$r['id']; break; }
if($agencyRoleId===null) exit('Mungon roli "agjencia".');

/* POST: create agency (si më parë) */
if($_SERVER['REQUEST_METHOD']==='POST'){
  $token=$_POST['csrf']??''; if(empty($token)||!hash_equals($_SESSION['csrf_token'],$token)){ http_response_code(400); exit('CSRF token mismatch.'); }
  $action=$_POST['action']??'';
  try{
    if($action==='create_agency'){
      $company_name=trim($_POST['company_name']??'');
      $nip_t=trim($_POST['nip_t']??'');
      $phone=trim($_POST['phone']??'');
      $address=trim($_POST['address']??'');
      $p1=$_POST['password']??''; $p2=$_POST['password2']??'';
      if($company_name===''||$nip_t===''||$p1===''||$p2==='') throw new RuntimeException('Plotëso: Emër, NIPT, Fjalëkalim.');
      if($p1!==$p2) throw new RuntimeException('Fjalëkalimet nuk përputhen.');
      $ex=$pdo->prepare("SELECT COUNT(*) FROM agencies WHERE nip_t=:n"); $ex->execute([':n'=>$nip_t]);
      if((int)$ex->fetchColumn()>0) throw new RuntimeException('Ky NIPT ekziston.');
      $pdo->beginTransaction();
      $insU=$pdo->prepare("INSERT INTO users(role_id,full_name,email) VALUES(:r,:n,NULL)");
      $insU->execute([':r'=>$agencyRoleId, ':n'=>$company_name]);
      $uid=(int)$pdo->lastInsertId();
      $hash=password_hash($p1,PASSWORD_BCRYPT);
      $pdo->prepare("INSERT INTO credentials(user_id,password_hash,last_password_change) VALUES(:u,:h,NOW())")->execute([':u'=>$uid,':h'=>$hash]);
      $pdo->prepare("INSERT INTO agencies(user_id,nip_t,company_name,address,phone) VALUES(:u,:nip,:cn,:ad,:ph)")
          ->execute([':u'=>$uid,':nip'=>$nip_t,':cn'=>$company_name,':ad'=>$address,':ph'=>$phone]);
      $pdo->commit(); flash('ok','Agjencia u shtua.');
    }
  }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); flash('err',$e->getMessage()); }
  header('Location: agencies.php'); exit;
}

/* Kërkim + listim me numër studentësh */
$q=trim($_GET['q']??''); $page=max(1,(int)($_GET['page']??1)); $limit=20; $offset=($page-1)*$limit;

$where=["r.id=:rid"]; $params=[':rid'=>$agencyRoleId];
if($q!==''){
  $where[]="(a.company_name LIKE :k OR a.nip_t LIKE :k OR a.phone LIKE :k OR a.address LIKE :k)";
  $params[':k']="%$q%";
}
$whereSql='WHERE '.implode(' AND ',$where);

/* total */
$cnt=$pdo->prepare("SELECT COUNT(*) FROM agencies a JOIN users u ON u.id=a.user_id JOIN roles r ON r.id=u.role_id $whereSql");
$cnt->execute($params); $total=(int)$cnt->fetchColumn(); $totalPages=max(1,(int)ceil($total/$limit));

/* list */
$sql="
SELECT
  a.id AS agency_id, a.company_name, a.nip_t, a.phone, a.address,
  u.id AS user_id, u.created_at,
  (SELECT COUNT(*) FROM agency_students aj WHERE aj.agency_id=a.id) AS students_count
FROM agencies a
JOIN users u ON u.id=a.user_id
JOIN roles r ON r.id=u.role_id
$whereSql
ORDER BY u.created_at DESC
LIMIT :lim OFFSET :off
";
$st=$pdo->prepare($sql);
foreach($params as $k=>$v){ $st->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR); }
$st->bindValue(':lim',$limit,PDO::PARAM_INT);
$st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute(); $agencies=$st->fetchAll(PDO::FETCH_ASSOC);

/* Active nav */
$NAV_ACTIVE='agencies';
require __DIR__ . '/inc/navbar.php';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Agjencitë – QTA Admin</title>
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
    @media (max-width: 575.98px) { .navbar-text { display:none; } }
    .editable{ display:inline-block; min-width:90px; padding:.35rem .5rem; border-radius:.5rem; transition:box-shadow .2s, background-color .2s; }
    .editable:hover{ background:#f8fafc; box-shadow:inset 0 0 0 1px #e5e7eb; }
    .editable:focus{ outline:0; background:#eef2ff; box-shadow:inset 0 0 0 2px #4f46e5; }
    .cell-saving{ position:relative; }
    .cell-saving::after{ content:''; position:absolute; right:.25rem; top:50%; width:.55rem; height:.55rem; border:.15rem solid rgba(0,0,0,.2); border-top-color:rgba(0,0,0,.55); border-radius:50%; animation:spin .6s linear infinite; transform:translateY(-50%); }
    @keyframes spin { to { transform:translateY(-50%) rotate(360deg);} }
    .cell-ok{ animation: flashOk 1.2s ease; } @keyframes flashOk { 0%{background:#ecfdf5;} 100%{background:transparent;} }
    .cell-err{ animation: flashErr 1.2s ease; } @keyframes flashErr { 0%{background:#fef2f2;} 100%{background:transparent;} }
    .nowrap{ white-space:nowrap; }
    .badge-soft{ background:#eef2ff; color:#4338ca; }
  </style>
</head>
<body>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <h2 class="mb-0">Agjencitë & lidhja me studentët</h2>
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAgencyModal">
        <i class="bi bi-building-add me-1"></i> Shto Agjenci
      </button>
    </div>
  </div>

  <?php if ($m = flash('ok')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($m) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if ($m = flash('err')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($m) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div id="msgBox" class="mb-3" style="display:none;"></div>

  <!-- Kërkim -->
  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="agencies.php">
        <div class="col-md-9">
          <label class="form-label">Kërko</label>
          <div class="input-group">
            <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control border-0" placeholder="Emër, NIPT, telefon, adresë..." value="<?= htmlspecialchars($q) ?>">
          </div>
        </div>
        <div class="col-md-3 text-end">
          <button class="btn btn-outline-secondary me-1" type="button" onclick="window.location='agencies.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
          <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Tabela -->
  <div class="card">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
      <h5 class="mb-0"><i class="bi bi-building me-2"></i>Lista e agjencive</h5>
      <span class="text-muted small"><?= number_format($total) ?> rezultat(e)</span>
    </div>
    <div class="card-body">
      <div class="table-responsive mini-table">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:80px">ID</th>
              <th>Emri i agjencisë</th>
              <th>NIPT</th>
              <th>Telefon</th>
              <th>Adresë</th>
              <th class="text-center">Studentë</th>
              <th>Regjistruar</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($agencies): foreach ($agencies as $a): $aid=(int)$a['agency_id']; ?>
            <tr>
              <td class="text-muted">#<?= $aid ?></td>

              <td class="cell" data-id="<?= $aid ?>" data-field="company_name">
                <span class="editable" contenteditable="true"><?= htmlspecialchars($a['company_name'] ?: '—') ?></span>
              </td>

              <td class="cell" data-id="<?= $aid ?>" data-field="nip_t">
                <span class="editable" contenteditable="true"><?= htmlspecialchars($a['nip_t']) ?></span>
              </td>

              <td class="cell nowrap" data-id="<?= $aid ?>" data-field="phone">
                <span class="editable" contenteditable="true"><?= htmlspecialchars($a['phone'] ?: '—') ?></span>
              </td>

              <td class="cell" data-id="<?= $aid ?>" data-field="address" title="Kliko për të modifikuar">
                <span class="editable" contenteditable="true"><?= htmlspecialchars($a['address'] ?: '—') ?></span>
              </td>

              <td class="text-center">
                <span class="badge rounded-pill badge-soft me-1"><i class="bi bi-people-fill me-1"></i><?= (int)$a['students_count'] ?></span>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#manageStudentsModal"
                        data-agency="<?= $aid ?>" data-agency-name="<?= htmlspecialchars($a['company_name'] ?: ('#'.$aid)) ?>">
                  Menaxho
                </button>
              </td>

              <td class="text-muted"><?= htmlspecialchars($a['created_at']) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center text-muted">Nuk u gjet asnjë agjenci.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white">
      <nav aria-label="Page navigation">
        <ul class="pagination mb-0 justify-content-end">
          <?php
            $base='agencies.php?'.http_build_query(array_filter(['q'=>$q!==''?$q:null]));
            $prev=max(1,$page-1); $next=min($totalPages,$page+1);
          ?>
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base.(str_contains($base,'?')?'&':'?') ?>page=1">«</a></li>
          <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base.(str_contains($base,'?')?'&':'?') ?>page=<?= $prev ?>">‹</a></li>
          <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $totalPages ?></span></li>
          <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= $base.(str_contains($base,'?')?'&':'?') ?>page=<?= $next ?>">›</a></li>
          <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= $base.(str_contains($base,'?')?'&':'?') ?>page=<?= $totalPages ?>">»</a></li>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
  </div>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<!-- Modal: Shto Agjenci (si më parë) -->
<div class="modal fade" id="addAgencyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="create_agency">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-building-add me-1"></i> Shto agjenci</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Emri i agjencisë</label>
            <input type="text" name="company_name" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">NIPT</label>
            <input type="text" name="nip_t" class="form-control" required>
            <div class="form-text">Duhet të jetë unik.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Fjalëkalimi</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Përsërit fjalëkalimin</label>
            <input type="password" name="password2" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Telefon</label>
            <input type="text" name="phone" class="form-control" placeholder="+355 67 ...">
          </div>
          <div class="col-12">
            <label class="form-label">Adresë</label>
            <textarea name="address" class="form-control" rows="3" placeholder="Rr. ... , Qyteti"></textarea>
          </div>
        </div>
        <div class="form-text mt-2">
          Krijon rreshta në <code>users</code>, <code>credentials</code> dhe <code>agencies</code>.
          Agjencitë hyjnë me <strong>NIPT + fjalëkalim</strong>.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit">Shto agjenci</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Menaxho studentët e agjencisë -->
<div class="modal fade" id="manageStudentsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-people me-1"></i> Studentët e agjencisë: <span id="msAgencyName">—</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="msAgencyId" value="">
        <div id="msMsg" class="mb-3" style="display:none;"></div>

        <div class="row g-3 align-items-end mb-3">
          <div class="col-md-8">
            <label class="form-label">Shto nga AMZË (intervale ose vlera të ndara me presje)</label>
            <input type="text" class="form-control" id="msAmzeInput" placeholder="p.sh. 3400-3403, 3409">
            <div class="form-text">Shembull: <code>1201-1205, 1210</code>. Nëse disa AMZË nuk ekzistojnë si studentë, do të shfaqet gabim.</div>
          </div>
          <div class="col-md-4">
            <button class="btn btn-primary w-100" id="msAddBtn"><i class="bi bi-plus-circle me-1"></i>Shto në këtë agjenci</button>
          </div>
        </div>

        <div class="table-responsive mini-table">
          <table class="table align-middle mb-0" id="msTable">
            <thead class="table-light">
              <tr>
                <th class="nowrap">AMZË</th>
                <th>Emër Atësi Mbiemër<br><small class="text-muted">ID Personal</small></th>
                <th class="text-end">Veprime</th>
              </tr>
            </thead>
            <tbody>
              <tr><td colspan="3" class="text-center text-muted">Ngarkim...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const INLINE_ENDPOINT = 'agencies_inline_update.php';
const LINK_ENDPOINT   = 'agencies_students_update.php';

function showMsg(container, type, text){
  const el = typeof container==='string' ? document.querySelector(container) : container;
  el.innerHTML = `
    <div class="alert alert-${type} alert-dismissible fade show" role="alert">
      ${text}
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>`;
  el.style.display='';
}

function cleanText(s){ const v=(s||'').replace(/\s+/g,' ').trim(); return v==='—'?'':v; }

/* Inline update për fushat e agjencisë */
document.querySelectorAll('td.cell .editable').forEach(el=>{
  let oldVal=el.textContent;
  el.addEventListener('focus', ()=>{ oldVal=el.textContent; });
  el.addEventListener('keydown', ev=>{ if(ev.key==='Enter'){ ev.preventDefault(); el.blur(); }});
  el.addEventListener('blur', async ()=>{
    const cell=el.closest('td.cell');
    const field=cell.dataset.field;
    const id=parseInt(cell.dataset.id,10);
    const val=cleanText(el.textContent);
    if(val===cleanText(oldVal)) return;
    try{
      cell.classList.add('cell-saving');
      const res=await fetch(INLINE_ENDPOINT,{ method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({csrf:CSRF, agency_id:id, field, value:val})});
      const json=await res.json();
      cell.classList.remove('cell-saving');
      if(!json.ok) throw new Error(json.error||'Gabim.');
      el.textContent = json.display ?? (val||'—');
      cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'),800);
    }catch(e){
      el.textContent=oldVal;
      cell.classList.remove('cell-saving');
      cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200);
      showMsg('#msgBox','danger', e.message);
    }
  });
});

/* Modal Menaxho studentët */
const msModal=document.getElementById('manageStudentsModal');
msModal.addEventListener('show.bs.modal', (ev)=>{
  const btn = ev.relatedTarget;
  const agencyId = btn.getAttribute('data-agency');
  const agencyName = btn.getAttribute('data-agency-name');
  document.getElementById('msAgencyId').value = agencyId;
  document.getElementById('msAgencyName').textContent = agencyName || ('#'+agencyId);
  document.getElementById('msAmzeInput').value='';
  loadAgencyStudents(agencyId);
});

async function loadAgencyStudents(agencyId){
  const tbody = document.querySelector('#msTable tbody');
  tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted">Ngarkim...</td></tr>`;
  const res = await fetch(LINK_ENDPOINT, {
    method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
    body: JSON.stringify({csrf:CSRF, action:'list_assigned', agency_id:parseInt(agencyId,10)})
  });
  const json = await res.json();
  if(!json.ok){
    tbody.innerHTML = `<tr><td colspan="3" class="text-center text-danger">${json.error||'Gabim'}</td></tr>`;
    return;
  }
  if(!json.students || json.students.length===0){
    tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted">Nuk ka studentë në këtë agjenci.</td></tr>`;
    return;
  }
  tbody.innerHTML='';
  json.students.forEach(s=>{
    const full = [s.first_name||'', s.father_name? (s.father_name+' ') : '', s.last_name||''].join('').trim();
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="nowrap">${escapeHtml(s.nr_amze||'')}</td>
      <td>
        <div class="fw-semibold">${escapeHtml(full||'—')}</div>
        <div class="text-muted small">${escapeHtml(s.personal_number||'')}</div>
      </td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-danger" data-unlink="${s.id}">
          <i class="bi bi-x-circle me-1"></i>Hiq
        </button>
      </td>`;
    tbody.appendChild(tr);
  });
}

/* Shto nga AMZË */
document.getElementById('msAddBtn')?.addEventListener('click', async ()=>{
  const agencyId = parseInt(document.getElementById('msAgencyId').value,10);
  const spec = document.getElementById('msAmzeInput').value.trim();
  if(!spec){ showMsg('#msMsg','warning','Shkruaj AMZË.'); return; }
  try{
    const res = await fetch(LINK_ENDPOINT, {
      method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({csrf:CSRF, action:'assign_by_amze', agency_id:agencyId, amze_spec:spec})
    });
    const json = await res.json();
    if(!json.ok) throw new Error(json.error||'Gabim.');
    showMsg('#msMsg','success', `U shtuan ${json.added} student(ë).`);
    loadAgencyStudents(agencyId);
  }catch(e){ showMsg('#msMsg','danger', e.message); }
});

/* Hiq student nga agjencia (delegim) */
document.querySelector('#msTable tbody')?.addEventListener('click', async (ev)=>{
  const btn = ev.target.closest('button[data-unlink]');
  if(!btn) return;
  const sid = parseInt(btn.getAttribute('data-unlink'),10);
  const agencyId = parseInt(document.getElementById('msAgencyId').value,10);
  if(!confirm('Të hiqet ky student nga agjencia?')) return;
  try{
    const res = await fetch(LINK_ENDPOINT, {
      method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({csrf:CSRF, action:'unlink', agency_id:agencyId, student_id:sid})
    });
    const json = await res.json();
    if(!json.ok) throw new Error(json.error||'Gabim.');
    loadAgencyStudents(agencyId);
  }catch(e){ showMsg('#msMsg','danger', e.message); }
});

/* Helpers */
function escapeHtml(s){ const d=document.createElement('div'); d.innerText=s||''; return d.innerHTML; }
</script>
</body>
</html>
