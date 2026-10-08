<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

/* -------------------------------------------------
   Toggle: Edit Mode (ruhet në session)
-------------------------------------------------- */
if (isset($_GET['edit'])) {
    qta_session_put(['edit_mode'], filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN));
    // redirect pa param 'edit' (ruaj pjesën tjetër të query-it)
    $qs = $_GET; unset($qs['edit']);
    $redir = 'agencies.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $redir);
    exit;
}
$EDIT_MODE = !empty($_SESSION['edit_mode']);

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
if (empty($_SESSION['csrf_token'])) { qta_session_put(['csrf_token'], bin2hex(random_bytes(24))); }
$CSRF = $_SESSION['csrf_token'];

/* Flash helpers */
function flash(string $k, ?string $m=null){
  if($m===null){
    if(!empty($_SESSION['flash'][$k])){ return qta_session_take(['flash', $k]); }
    return null;
  }
  qta_session_put(['flash', $k], $m);
}

/* Role agency id */
$roles = $pdo->query("SELECT id,name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$agencyRoleId=null; foreach($roles as $r) if(strtolower($r['name'])==='agjencia'){ $agencyRoleId=(int)$r['id']; break; }
if($agencyRoleId===null) exit('Mungon roli "agjencia".');

/* POST: create/delete agency (lejo vetëm kur Edit Mode = ON) */
if($_SERVER['REQUEST_METHOD']==='POST'){
  $token=$_POST['csrf']??'';
  if(empty($token)||!hash_equals($_SESSION['csrf_token'],$token)){
    http_response_code(400); exit('Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.');
  }
  if(!$EDIT_MODE){
    flash('err','Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.');
    header('Location: agencies.php'); exit;
  }

  $action=$_POST['action']??'';
  try{
    if($action==='create_agency'){
      $company_name=trim($_POST['company_name']??'');
      $nip_t=preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($_POST['nip_t']??''))));
      $phone=trim($_POST['phone']??'');
      $address=trim($_POST['address']??'');
      $p1=$_POST['password']??''; $p2=$_POST['password2']??'';
      if($company_name===''||$nip_t===''||$p1===''||$p2==='') throw new RuntimeException('Plotëso emrin, NIPT-in dhe fjalëkalimin.');
      if(!preg_match('/^[A-Z]\d{8}[A-Z0-9]$/', $nip_t)) throw new RuntimeException('NIPT-i ka 10 shenja: një shkronjë, 8 shifra dhe një shkronjë në fund, p.sh. L42202012A.');
      if(mb_strlen($p1) < 8) throw new RuntimeException('Fjalëkalimi duhet të ketë të paktën 8 shenja.');
      if($p1!==$p2) throw new RuntimeException('Dy fjalëkalimet nuk janë njësoj. Shkruaji sërish.');
      $ex=$pdo->prepare("SELECT COUNT(*) FROM agencies WHERE nip_t=:n"); $ex->execute([':n'=>$nip_t]);
      if((int)$ex->fetchColumn()>0) throw new RuntimeException('Ky NIPT i përket një agjencie tjetër.');

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
      flash('ok','Agjencia "'.$company_name.'" u shtua. Ajo hyn me NIPT-in '.$nip_t.' dhe fjalëkalimin që vendose.');
    }
    elseif($action==='delete_agency'){
      $agency_id = (int)($_POST['agency_id'] ?? 0);
      if($agency_id<=0) throw new RuntimeException('Agjencia nuk u gjet. Rifresko faqen.');

      // gjej user_id e agjencisë
      $st = $pdo->prepare("SELECT user_id FROM agencies WHERE id=:id LIMIT 1");
      $st->execute([':id'=>$agency_id]);
      $uid = (int)$st->fetchColumn();
      if(!$uid) throw new RuntimeException('Agjencia nuk u gjet. Rifresko faqen.');

      /* FSHIRJE E SIGURT (FK ON DELETE CASCADE te users/agencies/credentials/agency_students) */
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM users WHERE id=:uid")->execute([':uid'=>$uid]);
      $pdo->commit();

      flash('ok','Agjencia u fshi. Punonjësit e saj mbeten në regjistër.');
    }
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    flash('err',qta_error_message($e));
  }
  header('Location: agencies.php'); exit;
}

/* Kërkim + listim me numër punonjësish. Kërkimi me fjalë: emri, NIPT-i, telefoni, adresa. */
require_once __DIR__ . '/../shared/list_filter.php';
$q = qta_search_q($_GET['q'] ?? ''); $limit = 20;

$where = ["r.id=:rid"]; $params = [':rid' => $agencyRoleId];
$tokens = qta_search_tokens($q);
if ($tokens) {
  $where[] = qta_search_sql($tokens, ['a.company_name', 'a.nip_t', 'a.phone', 'a.address'], $params, 'aq',
    ['digits' => [qta_phone_digits_sql('a.phone')]]);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

/* total */
$cnt = $pdo->prepare("SELECT COUNT(*) FROM agencies a JOIN users u ON u.id=a.user_id JOIN roles r ON r.id=u.role_id $whereSql");
$cnt->execute($params); $total = (int)$cnt->fetchColumn(); $totalPages = max(1, (int)ceil($total / $limit));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages); $offset = ($page - 1) * $limit;

/* list */
$sql = "
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
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) { $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute(); $agencies = $st->fetchAll(PDO::FETCH_ASSOC);

$NAV_ACTIVE = 'users_agencies';
$HELP_TOPIC = 'agencies';
require __DIR__ . ($isAdmin ? '/inc/navbar.php' : '/inc/navbar4.php');

$openAdd = $EDIT_MODE && isset($_GET['add']);
$addHref = 'agencies.php?' . http_build_query(['edit' => '1', 'add' => '1']);
$flashOk  = flash('ok');
$flashErr = flash('err');
$LF = [
  'action'      => 'agencies.php',
  'label'       => 'Kërko agjenci',
  'placeholder' => 'Emri, NIPT, telefoni ose adresa',
  'q'           => $q,
  'target'      => 'agenciesResults',
];

$pageTitle = 'Agjencitë';
require __DIR__ . '/../shared/app_head.php';
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Agjencitë</h1>
      <p class="page-lead">Kompanitë që dërgojnë punonjës në trajnim.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <?php if ($EDIT_MODE): ?>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addAgencyModal">
          <i class="bi bi-building-add" aria-hidden="true"></i>Shto agjenci
        </button>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= h($addHref) ?>"><i class="bi bi-building-add" aria-hidden="true"></i>Shto agjenci</a>
      <?php endif; ?>
    </div>
  </header>

  <section class="section" aria-labelledby="agTitle">
    <div class="list-head" data-live-region="list-head">
      <h2 class="section-title" id="agTitle" tabindex="-1" data-live-focus>
        <?= $q !== '' ? 'Agjencitë që përputhen' : 'Të gjitha agjencitë' ?>
        <span class="count"><?= number_format($total, 0, ',', '.') ?></span>
      </h2>
    </div>

    <?php require __DIR__ . '/../shared/partials/list_toolbar.php'; ?>

    <div id="agenciesResults" data-live-region="results" data-live-announce="<?= h(qta_plural($total, 'agjenci', 'agjenci')) ?>">
    <?php if ($agencies): ?>
      <div class="table-responsive">
        <table class="table" id="agenciesTable" data-sortable>
          <thead>
            <tr>
              <th scope="col" class="col-wide" data-sort="text">Agjencia</th>
              <th scope="col" class="nowrap" data-sort="text">NIPT</th>
              <th scope="col" class="nowrap" data-sort="text">Telefoni</th>
              <th scope="col" class="col-medium" data-sort="text">Adresa</th>
              <th scope="col" class="nowrap" data-sort="num">Punonjës</th>
              <th scope="col" class="col-actions" data-sort="none"><span class="visually-hidden">Veprime</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agencies as $a):
              $aid = (int)$a['agency_id'];
              $name = (string)($a['company_name'] ?: ('Agjencia #' . $aid));
              $count = (int)$a['students_count']; ?>
              <tr>
                <td class="cell col-wide" data-id="<?= $aid ?>" data-field="company_name">
                  <span class="editable person-name" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>" <?= $EDIT_MODE ? 'role="textbox" aria-label="Emri i agjencisë"' : '' ?>><?= h((string)$a['company_name']) ?></span>
                  <span class="cell-sub">Në portal që nga <?= h(qta_date(substr((string)$a['created_at'], 0, 10))) ?></span>
                </td>
                <td class="cell nowrap" data-id="<?= $aid ?>" data-field="nip_t">
                  <span class="editable id-code" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>" <?= $EDIT_MODE ? 'role="textbox" aria-label="NIPT"' : '' ?>><?= h((string)$a['nip_t']) ?></span>
                </td>
                <td class="cell nowrap" data-id="<?= $aid ?>" data-field="phone">
                  <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>" <?= $EDIT_MODE ? 'role="textbox" aria-label="Telefoni"' : '' ?>><?= h((string)($a['phone'] ?: '')) ?></span>
                </td>
                <td class="cell col-medium" data-id="<?= $aid ?>" data-field="address">
                  <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>" <?= $EDIT_MODE ? 'role="textbox" aria-label="Adresa"' : '' ?>><?= h((string)($a['address'] ?: '')) ?></span>
                </td>
                <td class="nowrap" data-sort-value="<?= $count ?>">
                  <button class="btn btn-ghost btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#manageStudentsModal"
                          data-agency="<?= $aid ?>" data-agency-name="<?= h($name) ?>">
                    <i class="bi bi-people" aria-hidden="true"></i><span data-agency-count="<?= $aid ?>"><?= h(qta_plural($count, 'punonjës', 'punonjës')) ?></span>
                  </button>
                </td>
                <td class="col-actions">
                  <?php if ($EDIT_MODE): ?>
                    <form method="post" action="agencies.php" class="d-inline"
                          data-confirm="<?= h('Agjencia "' . $name . '" dhe llogaria e saj fshihen. ' . ($count ? qta_plural($count, 'punonjës', 'punonjës') . ' mbeten në regjistër, por nuk lidhen më me këtë agjenci. ' : '') . 'Kjo nuk mund të kthehet mbrapsht.') ?>"
                          data-confirm-title="Të fshihet agjencia?" data-confirm-ok="Po, fshije agjencinë">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="action" value="delete_agency">
                      <input type="hidden" name="agency_id" value="<?= $aid ?>">
                      <button class="btn btn-ghost btn-ghost-danger btn-sm btn-icon" type="submit" aria-label="Fshi agjencinë <?= h($name) ?>" data-tip="Fshi agjencinë">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($q !== ''): ?>
      <?= qta_empty('Asnjë agjenci nuk përputhet', 'Provo me NIPT-in ose një pjesë të emrit.', 'bi-search') ?>
    <?php else: ?>
      <?= qta_empty('Ende pa agjenci', 'Shto agjencinë e parë që dërgon punonjës në trajnim.', 'bi-building', '<a class="btn btn-primary" href="' . h($addHref) . '">Shto agjenci</a>') ?>
    <?php endif; ?>

      <?= qta_list_pager('agencies.php', ['q' => $q], $page, $totalPages, qta_plural($total, 'agjenci', 'agjenci')) ?>
    </div>
  </section>
</main>

<!-- Dialog: shto agjenci -->
<div class="modal fade" id="addAgencyModal" tabindex="-1" aria-labelledby="addAgencyTitle" aria-hidden="true"<?= $openAdd ? ' data-open-on-load="add"' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="post" action="agencies.php" data-loading>
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="create_agency">
      <div class="modal-header">
        <h2 class="modal-title" id="addAgencyTitle"><i class="bi bi-building-add" aria-hidden="true"></i>Shto një agjenci</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label" for="aaName">Emri i kompanisë <span class="req" aria-hidden="true">*</span></label>
            <input id="aaName" type="text" name="company_name" class="form-control" maxlength="150" placeholder="p.sh. Beton Invest Sh.p.k." required <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="aaNipt">NIPT <span class="req" aria-hidden="true">*</span></label>
            <input id="aaNipt" type="text" name="nip_t" class="form-control input-code" maxlength="14" autocomplete="off" autocapitalize="characters"
                   placeholder="p.sh. L42202012A" pattern="\s*[A-Za-z]\s*\d{8}\s*[A-Za-z0-9]\s*" aria-describedby="aaNiptHelp" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
            <div class="form-text" id="aaNiptHelp">10 shenja. Agjencia e përdor për të hyrë në portal.</div>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="aaPhone">Telefoni</label>
            <input id="aaPhone" type="tel" name="phone" class="form-control" maxlength="40" placeholder="p.sh. +355 67 123 4567" <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>
          <div class="col-md-7">
            <label class="form-label" for="aaAddress">Adresa</label>
            <input id="aaAddress" type="text" name="address" class="form-control" maxlength="500" placeholder="p.sh. Rr. e Durrësit, Tiranë" <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="aaPass">Fjalëkalimi i agjencisë <span class="req" aria-hidden="true">*</span></label>
            <div class="password-field">
              <input id="aaPass" type="password" name="password" class="form-control" minlength="8" autocomplete="new-password" aria-describedby="aaPassHelp" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#aaPass" aria-label="Shfaq fjalëkalimin" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <div class="form-text" id="aaPassHelp">Të paktën 8 shenja. Agjencia mund ta ndryshojë vetë te "Profili im".</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="aaPass2">Shkruaje sërish <span class="req" aria-hidden="true">*</span></label>
            <div class="password-field">
              <input id="aaPass2" type="password" name="password2" class="form-control" minlength="8" autocomplete="new-password" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#aaPass2" aria-label="Shfaq fjalëkalimin" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <div class="invalid-feedback">Dy fjalëkalimet nuk janë njësoj.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>><i class="bi bi-check-lg" aria-hidden="true"></i>Shto agjencinë</button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: punonjësit e agjencisë -->
<div class="modal fade" id="manageStudentsModal" tabindex="-1" aria-labelledby="msTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <span class="eyebrow mb-0" id="msAgencyName">Agjencia</span>
          <h2 class="modal-title" id="msTitle"><i class="bi bi-people" aria-hidden="true"></i>Punonjësit e agjencisë</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="msAgencyId" value="">
        <?php if ($EDIT_MODE): ?>
          <form class="panel panel-sunken mb-3" id="msAddForm">
            <label class="form-label" for="msAmzeInput">Shto punonjës me numrin e amzës</label>
            <div class="d-flex flex-wrap gap-2">
              <input type="text" class="form-control input-code flex-grow-1 w-auto" id="msAmzeInput" placeholder="p.sh. 3400-3403, 3409" aria-describedby="msAmzeHelp">
              <button class="btn btn-primary" type="submit" id="msAddBtn"><i class="bi bi-plus-lg" aria-hidden="true"></i>Shto</button>
            </div>
            <div class="form-text" id="msAmzeHelp">Numra të ndarë me presje ose intervale me vizë. Kursantët duhet të jenë tashmë në regjistër.</div>
          </form>
        <?php else: ?>
          <p class="text-muted small">Për të shtuar ose hequr punonjës, shtyp "Lejo ndryshimet" në faqe.</p>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table table-sm" id="msTable">
            <thead>
              <tr>
                <th scope="col" class="nowrap">Nr. i amzës</th>
                <th scope="col">Punonjësi</th>
                <th scope="col" class="col-actions"><span class="visually-hidden">Veprime</span></th>
              </tr>
            </thead>
            <tbody aria-live="polite">
              <tr><td colspan="3" class="table-empty">Po ngarkohet…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Mbyll</button>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const INLINE_ENDPOINT = 'agencies_inline_update.php';
const LINK_ENDPOINT   = 'agencies_students_update.php';
const EDIT_ENABLED    = <?= $EDIT_MODE ? 'true' : 'false' ?>;

function notify(type, text, opts={}){ return window.qtaToast ? window.qtaToast(text, type, opts.title, opts) : null; }
function cleanText(s){ const v = (s||'').replace(/\s+/g,' ').trim(); return v === '—' ? '' : v; }
function esc(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

async function postJSON(url, payload){
  const res = await window.qtaFetch.response(url, {
    method: 'POST',
    headers: {'Content-Type':'application/json','Accept':'application/json'},
    body: JSON.stringify(Object.assign({csrf: CSRF}, payload))
  });
  const json = await res.json().catch(()=> null);
  if (!json || !json.ok) throw new Error((json && json.error) || 'Veprimi nuk u krye. Provo sërish.');
  return json;
}

/* ===== Redaktimi në tabelë ===== */
/* Me delegim: rreshtat e rinj pas kërkimit ose faqosjes ndryshohen njësoj.
   app.js (defer) është gati te DOMContentLoaded. */
if (EDIT_ENABLED) document.addEventListener('DOMContentLoaded', () => {
  window.qtaEditable('#agenciesTable td.cell .editable', async (el, prevRaw)=>{
      el.dataset.prev = cleanText(prevRaw);
      const cell = el.closest('td.cell');
      const val = cleanText(el.textContent);
      el.textContent = val;
      if (val === (el.dataset.prev || '')) return;
      if (cell.classList.contains('cell-saving')) return;
      cell.setAttribute('aria-busy', 'true');
      cell.classList.add('cell-saving');
      try{
        const json = await postJSON(INLINE_ENDPOINT, {agency_id: parseInt(cell.dataset.id,10), field: cell.dataset.field, value: val});
        el.textContent = cleanText(json.display ?? val);
        el.dataset.prev = el.textContent;
        cell.classList.remove('cell-saving');
        cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800);
        notify('success', 'Ndryshimi u ruajt.');
      }catch(e){
        el.textContent = el.dataset.prev || '';
        cell.classList.remove('cell-saving');
        cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
        notify('danger', e.message);
      } finally { cell.classList.remove('cell-saving'); cell.removeAttribute('aria-busy'); }
  });
});

/* ===== Shto agjenci: kontrollo që fjalëkalimet përputhen ===== */
(function(){
  const p1 = document.getElementById('aaPass'), p2 = document.getElementById('aaPass2');
  if (!p1 || !p2) return;
  const check = () => {
    const bad = p2.value !== '' && p1.value !== p2.value;
    p2.classList.toggle('is-invalid', bad);
    p2.setCustomValidity(bad ? 'Dy fjalëkalimet nuk janë njësoj.' : '');
  };
  p1.addEventListener('input', check); p2.addEventListener('input', check);
})();

/* ===== Punonjësit e agjencisë ===== */
const msModal = document.getElementById('manageStudentsModal');
let msAgency = 0;

function plural(n){ return n === 1 ? '1 punonjës' : n + ' punonjës'; }

async function loadAgencyStudents(){
  const tbody = document.querySelector('#msTable tbody');
  tbody.innerHTML = '<tr><td colspan="3" class="table-empty">Po ngarkohet…</td></tr>';
  try{
    const json = await postJSON(LINK_ENDPOINT, {action:'list_assigned', agency_id: msAgency});
    const list = json.students || [];
    const countEl = document.querySelector(`[data-agency-count="${msAgency}"]`);
    if (countEl) countEl.textContent = plural(list.length);
    if (!list.length){
      tbody.innerHTML = '<tr><td colspan="3" class="table-empty">Kjo agjenci nuk ka ende punonjës në regjistër.</td></tr>';
      return;
    }
    tbody.innerHTML = list.map(s => {
      const full = [s.first_name, s.father_name, s.last_name].filter(Boolean).join(' ') || 'Pa emër ende';
      return `<tr>
        <td class="nowrap"><span class="id-code">${esc(s.nr_amze || '')}</span></td>
        <td><a class="person-name" href="student_card.php?sid=${parseInt(s.id,10)}">${esc(full)}</a>
            ${s.personal_number ? `<span class="cell-sub code">${esc(s.personal_number)}</span>` : ''}</td>
        <td class="col-actions">${EDIT_ENABLED ? `<button class="btn btn-ghost btn-sm" type="button" data-unlink="${parseInt(s.id,10)}" data-name="${esc(full)}"><i class="bi bi-x-lg" aria-hidden="true"></i>Hiq</button>` : ''}</td>
      </tr>`;
    }).join('');
  }catch(e){
    tbody.innerHTML = `<tr><td colspan="3" class="table-empty text-danger">${esc(e.message)}</td></tr>`;
  }
}

msModal?.addEventListener('show.bs.modal', (ev)=>{
  const btn = ev.relatedTarget;
  if (!btn) { ev.preventDefault(); return; }
  msAgency = parseInt(btn.getAttribute('data-agency'), 10);
  document.getElementById('msAgencyId').value = msAgency;
  document.getElementById('msAgencyName').textContent = btn.getAttribute('data-agency-name') || 'Agjencia';
  const input = document.getElementById('msAmzeInput');
  if (input) input.value = '';
  loadAgencyStudents();
});

document.getElementById('msAddForm')?.addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  const input = document.getElementById('msAmzeInput');
  const spec = input.value.trim();
  if (!spec){ notify('warning', 'Shkruaj numrat e amzës, p.sh. 3400-3403, 3409.'); input.focus(); return; }
  const btn = document.getElementById('msAddBtn');
  if (btn.disabled) return;
  btn.disabled = true; btn.classList.add('is-loading'); btn.setAttribute('aria-busy', 'true');
  try{
    const json = await postJSON(LINK_ENDPOINT, {action:'assign_by_amze', agency_id: msAgency, amze_spec: spec});
    notify('success', json.added ? (json.added === 1 ? '1 punonjës u shtua.' : json.added + ' punonjës u shtuan.') : (json.info || 'Asgjë e re për të shtuar.'));
    input.value = '';
    loadAgencyStudents();
  }catch(e){ notify('danger', e.message, {autohide:false}); }
  finally { btn.disabled = false; btn.classList.remove('is-loading'); btn.removeAttribute('aria-busy'); }
});

document.querySelector('#msTable tbody')?.addEventListener('click', async (ev)=>{
  const btn = ev.target.closest('button[data-unlink]');
  if (!btn || !EDIT_ENABLED) return;
  const ok = await window.qtaConfirm({
    title: 'Të hiqet nga agjencia?',
    message: `${btn.dataset.name || 'Ky punonjës'} mbetet në regjistër, por agjencia nuk do ta shohë më.`,
    confirm: 'Po, hiqe', danger: true
  });
  if (!ok) return;
  try{
    await postJSON(LINK_ENDPOINT, {action:'unlink', agency_id: msAgency, student_id: parseInt(btn.dataset.unlink,10)});
    notify('success', 'U hoq nga agjencia.');
    loadAgencyStudents();
  }catch(e){ notify('danger', e.message); }
});

/* Mesazhet pas ringarkimit */
document.addEventListener('DOMContentLoaded', ()=>{
<?php if ($flashOk): ?>
  notify('success', <?= json_encode($flashOk, JSON_UNESCAPED_UNICODE) ?>, {delay: 7000});
<?php endif; ?>
<?php if ($flashErr): ?>
  notify('danger', <?= json_encode($flashErr, JSON_UNESCAPED_UNICODE) ?>, {autohide: false});
<?php endif; ?>
});
</script>
</body>
</html>
