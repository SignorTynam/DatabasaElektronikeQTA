<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* -------------------------------------------------
   Toggle: Edit Mode (ruhet në session)
-------------------------------------------------- */
if (isset($_GET['edit'])) {
    $_SESSION['agencies_edit_mode'] = ($_GET['edit'] === '1');
    // redirect pa param 'edit' (ruaj pjesën tjetër të query-it)
    $qs = $_GET; unset($qs['edit']);
    $redir = 'agencies.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $redir);
    exit;
}
$EDIT_MODE = !empty($_SESSION['agencies_edit_mode']);

/* Guard: admin OSE editor */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch();

$roleName = strtolower((string)($currentUser['role_name'] ?? ''));
$isAdmin  = ($roleName === 'administrator');
$isEditor = ($roleName === 'editor');

if (!$currentUser || (!$isAdmin && !$isEditor)) {
  header('Location: selectProfile.php'); exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* Flash helpers */
function flash(string $k, ?string $m=null){
  if($m===null){
    if(!empty($_SESSION['flash'][$k])){ $x=$_SESSION['flash'][$k]; unset($_SESSION['flash'][$k]); return $x; }
    return null;
  }
  $_SESSION['flash'][$k]=$m;
}

/* Role agency id */
$roles = $pdo->query("SELECT id,name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$agencyRoleId=null; foreach($roles as $r) if(strtolower($r['name'])==='agjencia'){ $agencyRoleId=(int)$r['id']; break; }
if($agencyRoleId===null) exit('Mungon roli "agjencia".');

/* POST: create/delete agency (lejo vetëm kur Edit Mode = ON) */
if($_SERVER['REQUEST_METHOD']==='POST'){
  $token=$_POST['csrf']??'';
  if(empty($token)||!hash_equals($_SESSION['csrf_token'],$token)){
    http_response_code(400); exit('CSRF token mismatch.');
  }
  if(!$EDIT_MODE){
    flash('err','Aktivizo <strong>Mënyrën e redaktimit</strong> për të kryer veprime.');
    header('Location: agencies.php'); exit;
  }

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
      $pdo->prepare("INSERT INTO credentials(user_id,password_hash,last_password_change) VALUES(:u,:h,NOW())")
          ->execute([':u'=>$uid,':h'=>$hash]);

      $pdo->prepare("INSERT INTO agencies(user_id,nip_t,company_name,address,phone) VALUES(:u,:nip,:cn,:ad,:ph)")
          ->execute([':u'=>$uid,':nip'=>$nip_t,':cn'=>$company_name,':ad'=>$address,':ph'=>$phone]);

      $pdo->commit();
      flash('ok','Agjencia u shtua.');
    }
    elseif($action==='delete_agency'){
      $agency_id = (int)($_POST['agency_id'] ?? 0);
      if($agency_id<=0) throw new RuntimeException('ID agjencie i pavlefshëm.');

      // gjej user_id e agjencisë
      $st = $pdo->prepare("SELECT user_id FROM agencies WHERE id=:id LIMIT 1");
      $st->execute([':id'=>$agency_id]);
      $uid = (int)$st->fetchColumn();
      if(!$uid) throw new RuntimeException('Agjencia nuk u gjet.');

      /* FSHIRJE E SIGURT (FK ON DELETE CASCADE te users/agencies/credentials/agency_students) */
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM users WHERE id=:uid")->execute([':uid'=>$uid]);
      $pdo->commit();

      flash('ok','Agjencia u fshi me sukses.');
    }
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    flash('err',$e->getMessage());
  }
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
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Agjencitë – QTA <?= $isAdmin ? 'Admin' : 'Editor' ?></title>
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
    .editable[contenteditable="true"]:hover{ background:#f8fafc; box-shadow:inset 0 0 0 1px #e5e7eb; cursor:text; }
    .editable[contenteditable="true"]:focus{ outline:0; background:#eef2ff; box-shadow:inset 0 0 0 2px #4f46e5; }
    .editable[contenteditable="false"]{ opacity:.7; cursor:not-allowed; }
    .cell-saving{ position:relative; }
    .cell-saving::after{ content:''; position:absolute; right:.25rem; top:50%; width:.55rem; height:.55rem; border:.15rem solid rgba(0,0,0,.2); border-top-color:rgba(0,0,0,.55); border-radius:50%; animation:spin .6s linear infinite; transform:translateY(-50%); }
    @keyframes spin { to { transform:translateY(-50%) rotate(360deg);} }
    .cell-ok{ animation: flashOk 1.2s ease; } @keyframes flashOk { 0%{background:#ecfdf5;} 100%{background:transparent;} }
    .cell-err{ animation: flashErr 1.2s ease; } @keyframes flashErr { 0%{background:#fef2f2;} 100%{background:transparent;} }
    .nowrap{ white-space:nowrap; }
    .badge-soft{ background:#eef2ff; color:#4338ca; }

    /* UI e re – soft & pill */
    .btn-pill { border-radius:999px !important; }
    .btn-soft-secondary { background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; }
    .btn-soft-secondary:hover { background:#e2e8f0; color:#0f172a; }

    /* Edit Mode OFF visuals */
    .editing-off .editable { color:#6b7280; cursor:not-allowed; }
    .editing-off .btn[disabled], .editing-off input[disabled], .editing-off select[disabled], .editing-off textarea[disabled] { cursor:not-allowed; }

    /* FAB (+) poshtë DJATHTAS */
    .btn-fab{
      position: fixed;
      right: 24px;
      bottom: 24px;
      width: 56px; height: 56px; border-radius: 50%;
      display:flex; align-items:center; justify-content:center;
      z-index:1040; box-shadow:0 12px 20px rgba(2,6,23,.15);
    }
    .btn-fab i{ font-size:1.25rem; line-height:1; }
    .btn-fab:focus{ box-shadow:0 0 0 .25rem rgba(13,110,253,.25), 0 12px 20px rgba(2,6,23,.15); }
    @media (max-width:575.98px){ .btn-fab{ right:16px; bottom:16px; width:52px; height:52px; } }

    /* Toasts poshtë MAJTAS */
    .toast.qta-toast{ border:0; border-radius:.75rem; box-shadow:0 12px 20px rgba(2,6,23,.12); }
    .toast.qta-toast .toast-header{ border-bottom:0; }
    .toast-success .toast-header{ background:#ecfdf5; color:#065f46; }
    .toast-danger  .toast-header{ background:#fef2f2; color:#991b1b; }
    .toast-info    .toast-header{ background:#eff6ff; color:#1e40af; }
    .toast-warning .toast-header{ background:#fff7ed; color:#9a3412; }
  </style>
</head>
<body class="<?= $EDIT_MODE ? '' : 'editing-off' ?>">
<?php
  $NAV_ACTIVE='agencies';
  require __DIR__ . ($isAdmin ? '/inc/navbar.php' : '/inc/navbar4.php');
?>

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
    <h2 class="mb-0">Agjencitë & lidhja me studentët</h2>

    <!-- Toolbar: Edit Mode toggle -->
    <div class="d-flex align-items-center">
      <?php
        $qs = $_GET;
        $qs['edit'] = $EDIT_MODE ? '0' : '1';
        $toggleUrl = 'agencies.php' . ($qs ? ('?' . http_build_query($qs)) : '');
      ?>
      <a class="btn btn-pill <?= $EDIT_MODE ? 'btn-success' : 'btn-soft-secondary' ?>" href="<?= htmlspecialchars($toggleUrl) ?>"
         title="Ndrysho gjendjen e Edit Mode">
        <i class="bi <?= $EDIT_MODE ? 'bi-unlock' : 'bi-lock' ?> me-1"></i>
        Edit Mode:
        <span class="badge ms-1 <?= $EDIT_MODE ? 'bg-light text-success' : 'bg-secondary' ?>"><?= $EDIT_MODE ? 'ON' : 'OFF' ?></span>
      </a>
    </div>
  </div>

  <?php if (!$EDIT_MODE): ?>
    <div class="alert alert-secondary py-2">
      <i class="bi bi-info-circle me-1"></i>
      Aktivizo <strong>Mënyrën e redaktimit</strong> për të ndryshuar qelizat, për të shtuar / fshirë agjenci ose për të menaxhuar studentët.
    </div>
  <?php endif; ?>

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
          <button class="btn btn-soft-secondary btn-pill me-1" type="button" onclick="window.location='agencies.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
          <button class="btn btn-primary btn-pill" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
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
              <th class="text-end">Veprime</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($agencies): foreach ($agencies as $a): $aid=(int)$a['agency_id']; ?>
            <tr>
              <td class="text-muted">#<?= $aid ?></td>

              <td class="cell" data-id="<?= $aid ?>" data-field="company_name">
                <span class="editable"
                      contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                      tabindex="<?= $EDIT_MODE ? 0 : -1 ?>"><?= htmlspecialchars($a['company_name'] ?: '—') ?></span>
              </td>

              <td class="cell" data-id="<?= $aid ?>" data-field="nip_t">
                <span class="editable"
                      contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                      tabindex="<?= $EDIT_MODE ? 0 : -1 ?>"><?= htmlspecialchars($a['nip_t']) ?></span>
              </td>

              <td class="cell nowrap" data-id="<?= $aid ?>" data-field="phone">
                <span class="editable"
                      contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                      tabindex="<?= $EDIT_MODE ? 0 : -1 ?>"><?= htmlspecialchars($a['phone'] ?: '—') ?></span>
              </td>

              <td class="cell" data-id="<?= $aid ?>" data-field="address" title="Kliko për të modifikuar">
                <span class="editable"
                      contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"
                      tabindex="<?= $EDIT_MODE ? 0 : -1 ?>"><?= htmlspecialchars($a['address'] ?: '—') ?></span>
              </td>

              <td class="text-center">
                <span class="badge rounded-pill badge-soft me-1"><i class="bi bi-people-fill me-1"></i><?= (int)$a['students_count'] ?></span>
              </td>

              <td class="text-muted"><?= htmlspecialchars($a['created_at']) ?></td>
              <td class="text-end">
                <!-- Menaxho -->
                <button class="btn btn-sm btn-outline-primary me-2"
                        data-bs-toggle="modal" data-bs-target="#manageStudentsModal"
                        data-agency="<?= $aid ?>"
                        data-agency-name="<?= htmlspecialchars($a['company_name'] ?: ('#'.$aid)) ?>"
                        <?= $EDIT_MODE ? '' : 'disabled' ?>
                        title="<?= $EDIT_MODE ? 'Menaxho studentët e kësaj agjencie' : 'Aktivizo Edit Mode për të menaxhuar' ?>">
                  <i class="bi bi-people"></i> Menaxho
                </button>

                <!-- Fshi Agjencinë -->
                <form method="post" class="d-inline js-confirm-delete"
                      action="agencies.php"
                      onsubmit="return <?= $EDIT_MODE ? 'true' : '(notify(\"warning\",\"Aktivizo Edit Mode për të fshirë.\"), false)' ?>;">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                  <input type="hidden" name="action" value="delete_agency">
                  <input type="hidden" name="agency_id" value="<?= $aid ?>">
                  <button class="btn btn-sm btn-outline-danger" <?= $EDIT_MODE ? '' : 'disabled' ?>
                          title="<?= $EDIT_MODE ? 'Fshi këtë agjenci' : 'Aktivizo Edit Mode për të fshirë' ?>">
                    <i class="bi bi-trash"></i> Fshi
                  </button>
                </form>
              </td>

            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="8" class="text-center text-muted">Nuk u gjet asnjë agjenci.</td></tr>
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
            $base='agencies.php?'.http_build_query(array_filter([
              'q'=>$q!==''?$q:null,
              'edit'=>$EDIT_MODE?'1':'0'
            ]));
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

<!-- Floating Action Button (FAB) – poshtë djathtas -->
<?php if ($EDIT_MODE): ?>
<button class="btn btn-primary btn-fab" type="button"
        data-bs-toggle="modal" data-bs-target="#addAgencyModal"
        aria-label="Shto agjenci" title="Shto agjenci">
  <i class="bi bi-plus-lg"></i>
</button>
<?php else: ?>
<button class="btn btn-soft-secondary btn-fab" type="button" disabled
        title="Aktivizo Edit Mode për të shtuar agjenci">
  <i class="bi bi-plus-lg"></i>
</button>
<?php endif; ?>

<!-- Toasts: poshtë MAJTAS -->
<div id="toastZone" class="toast-container position-fixed start-0 bottom-0 p-3" style="z-index:1080;"></div>

<!-- Modal: Shto Agjenci -->
<div class="modal fade" id="addAgencyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" action="agencies.php">
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
            <input type="text" name="company_name" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label">NIPT</label>
            <input type="text" name="nip_t" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?>>
            <div class="form-text">Duhet të jetë unik.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Fjalëkalimi</label>
            <input type="password" name="password" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label">Përsërit fjalëkalimin</label>
            <input type="password" name="password2" class="form-control" <?= $EDIT_MODE ? 'required' : 'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label">Telefon</label>
            <input type="text" name="phone" class="form-control" placeholder="+355 67 ..." <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label">Adresë</label>
            <textarea name="address" class="form-control" rows="3" placeholder="Rr. ... , Qyteti" <?= $EDIT_MODE ? '' : 'disabled' ?>></textarea>
          </div>
        </div>
        <div class="form-text mt-2">
          Krijon rreshta në <code>users</code>, <code>credentials</code> dhe <code>agencies</code>. Agjencitë hyjnë me <strong>NIPT + fjalëkalim</strong>.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary btn-pill" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Shto agjenci</button>
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
        <div class="row g-3 align-items-end mb-3">
          <div class="col-md-8">
            <label class="form-label">Shto nga AMZË (intervale ose vlera të ndara me presje)</label>
            <input type="text" class="form-control" id="msAmzeInput" placeholder="p.sh. 3400-3403, 3409" <?= $EDIT_MODE ? '' : 'disabled' ?>>
            <div class="form-text">Shembull: <code>1201-1205, 1210</code>. Nëse disa AMZË nuk ekzistojnë si studentë, do të shfaqet gabim.</div>
          </div>
          <div class="col-md-4">
            <button class="btn btn-primary w-100" id="msAddBtn" <?= $EDIT_MODE ? '' : 'disabled' ?>><i class="bi bi-plus-circle me-1"></i>Shto në këtë agjenci</button>
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
        <button class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Konfirmim veprimi (universal) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Konfirmim</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body" id="confirmText">A jeni i sigurt?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
        <button type="button" class="btn btn-danger btn-pill" id="confirmYesBtn">Po, vazhdo</button>
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
const EDIT_ENABLED    = <?= $EDIT_MODE ? 'true' : 'false' ?>;

/* Toast helper */
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

/* showMsg alias */
function showMsg(containerOrType, typeOrText, maybeText){
  if (maybeText === undefined) notify(containerOrType, typeOrText);
  else notify(typeOrText, maybeText);
}

function cleanText(s){ const v=(s||'').replace(/\s+/g,' ').trim(); return v==='—'?'':v; }

/* Inline update për fushat e agjencisë — vetëm kur Edit Mode është ON */
if (EDIT_ENABLED) {
  document.querySelectorAll('td.cell .editable[contenteditable="true"]').forEach(el=>{
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
        notify('success','U ruajt me sukses.');
      }catch(e){
        el.textContent=oldVal;
        cell.classList.remove('cell-saving');
        cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'),1200);
        notify('danger', e.message || 'Ndodhi një gabim.');
      }
    });
  });
}

/* Modal Menaxho studentët */
const msModal=document.getElementById('manageStudentsModal');
msModal?.addEventListener('show.bs.modal', (ev)=>{
  const btn = ev.relatedTarget;
  if (!EDIT_ENABLED || !btn || btn.hasAttribute('disabled')) { ev.preventDefault(); return; }
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
        <button class="btn btn-sm btn-outline-danger" data-unlink="${s.id}" ${EDIT_ENABLED ? '' : 'disabled'}>
          <i class="bi bi-x-circle me-1"></i>Hiq
        </button>
      </td>`;
    tbody.appendChild(tr);
  });
}

/* Shto nga AMZË */
document.getElementById('msAddBtn')?.addEventListener('click', async ()=>{
  if (!EDIT_ENABLED) { notify('warning','Aktivizo Edit Mode.'); return; }
  const agencyId = parseInt(document.getElementById('msAgencyId').value,10);
  const spec = document.getElementById('msAmzeInput').value.trim();
  if(!spec){ notify('warning','Shkruaj AMZË.'); return; }
  try{
    const res = await fetch(LINK_ENDPOINT, {
      method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({csrf:CSRF, action:'assign_by_amze', agency_id:agencyId, amze_spec:spec})
    });
    const json = await res.json();
    if(!json.ok) throw new Error(json.error||'Gabim.');
    notify('success', `U shtuan ${json.added} student(ë).`);
    loadAgencyStudents(agencyId);
  }catch(e){ notify('danger', e.message || 'Gabim gjatë shtimit.'); }
});

/* Konfirmim universal me modal */
let __confirmCb = null;
function openConfirm(message, onYes){
  document.getElementById('confirmText').textContent = message || 'A jeni i sigurt?';
  __confirmCb = typeof onYes==='function' ? onYes : null;
  const m = new bootstrap.Modal('#confirmModal');
  m.show();
  const yesBtn = document.getElementById('confirmYesBtn');
  yesBtn.onclick = ()=>{ if(__confirmCb) __confirmCb(); m.hide(); __confirmCb=null; };
}

/* Hiq student nga agjencia (delegim) me modal confirm */
document.querySelector('#msTable tbody')?.addEventListener('click', async (ev)=>{
  const btn = ev.target.closest('button[data-unlink]');
  if(!btn) return;
  if (!EDIT_ENABLED || btn.hasAttribute('disabled')) { notify('warning','Aktivizo Edit Mode.'); return; }
  const sid = parseInt(btn.getAttribute('data-unlink'),10);
  const agencyId = parseInt(document.getElementById('msAgencyId').value,10);
  openConfirm('Të hiqet ky student nga agjencia?', async ()=>{
    try{
      const res = await fetch(LINK_ENDPOINT, {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({csrf:CSRF, action:'unlink', agency_id:agencyId, student_id:sid})
      });
      const json = await res.json();
      if(!json.ok) throw new Error(json.error||'Gabim.');
      notify('success','U hoq me sukses.');
      loadAgencyStudents(agencyId);
    }catch(e){ notify('danger', e.message || 'Gabim gjatë heqjes.'); }
  });
});

/* Konfirmim për fshirjen e agjencisë (forms) */
document.querySelectorAll('form.js-confirm-delete').forEach(form=>{
  form.addEventListener('submit', (e)=>{
    if (!EDIT_ENABLED) { e.preventDefault(); notify('warning','Aktivizo Edit Mode për të fshirë.'); return; }
    e.preventDefault();
    const msg = form.getAttribute('data-confirm') || 'A jeni i sigurt?';
    openConfirm(msg, ()=> form.submit());
  });
});

/* Helpers */
function escapeHtml(s){ const d=document.createElement('div'); d.innerText=s||''; return d.innerHTML; }

/* Flash -> Toast sapo ngarkohet faqja */
<?php if ($m = flash('ok')): ?>
document.addEventListener('DOMContentLoaded',()=>notify('success', <?= json_encode($m) ?>));
<?php endif; ?>
<?php if ($m = flash('err')): ?>
document.addEventListener('DOMContentLoaded',()=>notify('danger', <?= json_encode($m) ?>));
<?php endif; ?>
</script>
</body>
</html>
