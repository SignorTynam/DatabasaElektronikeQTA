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
  $_SESSION['edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
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
    p.phone,
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

$pageTitle  = 'Regjistri i plotë';
$NAV_ACTIVE = 'register_full';
$HELP_TOPIC = 'register';
$hasFilters = ($q !== '' || $from_amze !== '');
$exportAction = 'register_export.php';
$exportFields = ['q' => $q, 'from_amze' => $from_amze];
require __DIR__ . '/../shared/app_head.php';

if ($role === 'editor') require __DIR__ . '/inc/navbar4.php';
else require __DIR__ . '/inc/navbar.php';
?>

<main class="app-main is-wide" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Regjistri i plotë</h1>
      <p class="page-lead">Çdo rresht është një regjistrim: kursanti, moduli, datat e grupit, provimi dhe pikët. Me ndryshimet e hapura, datat dhe pikët ndryshohen direkt në tabelë.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
    </div>
  </header>

  <form class="filters" method="get" action="register.php" role="search" aria-label="Kërko në regjistër">
    <div class="filter-field is-grow">
      <label class="form-label" for="fQ">Kërko</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="fQ" type="search" name="q" value="<?= h($q) ?>"
               placeholder="Emri, numri personal ose nr. i amzës">
      </div>
    </div>
    <div class="filter-field">
      <label class="form-label" for="fFrom">Nis nga nr. i amzës</label>
      <input class="form-control input-code" id="fFrom" type="text" name="from_amze" value="<?= h($from_amze) ?>"
             placeholder="p.sh. 1050" inputmode="numeric">
    </div>
    <div class="filter-actions">
      <?php if ($hasFilters): ?>
        <a class="btn btn-ghost" href="register.php"><i class="bi bi-x-lg" aria-hidden="true"></i>Pastro kërkimin</a>
      <?php endif; ?>
      <button class="btn btn-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Kërko</button>
    </div>
  </form>

  <?php require __DIR__ . '/../shared/partials/edit_mode_off_banner.php'; ?>

  <section class="section" aria-labelledby="listTitle">
    <div class="section-head">
      <h2 class="section-title" id="listTitle">
        <?= $hasFilters ? 'Rezultatet' : 'Të gjitha regjistrimet' ?>
        <span class="count"><?= number_format($total, 0, ',', '.') ?></span>
      </h2>
      <?php require __DIR__ . '/../shared/partials/export_menu.php'; ?>
    </div>

    <?php
      $tfTarget = '#registerTable';
      $tfPlaceholder = 'Filtro këtë faqe — emër, amzë, modul…';
      $tfChips = [['label' => 'Kaloi', 'match' => 'kaloi'], ['label' => 'Pa provim', 'match' => 'pret']];
      $tfNoun = 'regjistrime';
      require __DIR__ . '/../shared/partials/table_filter.php';
    ?>

    <div class="table-responsive">
      <table class="table table-freeze" id="registerTable" data-sortable>
        <thead>
          <tr>
            <th scope="col" class="nowrap" data-sort="num">Nr. i amzës</th>
            <th scope="col" class="col-medium" data-sort="text">Kursanti</th>
            <th scope="col" class="col-wide" data-sort="text">Moduli</th>
            <th scope="col" class="nowrap" data-sort="date">Fillimi</th>
            <th scope="col" class="nowrap" data-sort="date">Mbarimi</th>
            <th scope="col" class="nowrap" data-sort="date">Provimi</th>
            <th scope="col" class="nowrap num-col" data-sort="num">Pikët</th>
            <th scope="col" data-sort="text">Gjendja</th>
            <th scope="col" class="nowrap" data-sort="text">Telefoni</th>
            <th scope="col" class="nowrap num-col" data-sort="num">Mosha</th>
            <th scope="col" data-sort="text">Arsimi</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($rows): foreach ($rows as $r):
          $sid = (int)$r['student_id'];
          $gid = $r['group_id'] !== null ? (int)$r['group_id'] : 0;
          $full = qta_full_name($r['first_name'] ?? '', $r['father_name'] ?? '', $r['last_name'] ?? '');
          if (!empty($r['group_id'])) {
            $moduleLabel = trim((string)($r['course_name'] ?? '')) ?: '—';
          } else {
            $moduleLabel = trim((string)($r['planned_course_name'] ?? '')) ?: '—';
          }
          $ph = trim((string)($r['phone'] ?? ''));
          if ($ph === '—' || $ph === '-') { $ph = ''; }
          $scoreText = $r['final_score'] !== null ? rtrim(rtrim((string)$r['final_score'], '0'), '.') : '—';
        ?>
          <tr data-row data-start="<?= h((string)($r['start_date'] ?? '')) ?>" data-end="<?= h((string)($r['end_date'] ?? '')) ?>" data-has-group="<?= $gid ? '1' : '0' ?>">
            <td class="nowrap"><span class="id-code"><?= h((string)$r['nr_amze']) ?></span></td>
            <td>
              <a class="person-name" href="student_card.php?sid=<?= $sid ?>"><?= h($full !== '' ? $full : '—') ?></a>
              <span class="cell-sub code"><?= h((string)($r['personal_number'] ?? '—')) ?></span>
            </td>
            <td class="col-wide"><?= h($moduleLabel) ?><?= empty($r['group_id']) && $moduleLabel !== '—' ? ' <span class="cell-sub">i planifikuar, pa grup</span>' : '' ?></td>
            <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="start_date" title="Data e fillimit të grupit (dd.mm.vvvv)">
              <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h(qta_date($r['start_date'])) ?></span>
            </td>
            <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="end_date" title="Data e mbarimit, jo para fillimit (dd.mm.vvvv)">
              <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h(qta_date($r['end_date'])) ?></span>
            </td>
            <td class="cell nowrap" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="exam_date" title="Data e provimit, jo para mbarimit të grupit (dd.mm.vvvv)">
              <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h(qta_date($r['exam_date'])) ?></span>
            </td>
            <td class="cell nowrap num-col" data-student="<?= $sid ?>" data-group="<?= $gid ?>" data-field="final_score" title="Pikët 0–100. Kalon me 50 e lart.">
              <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>"><?= h($scoreText) ?></span>
            </td>
            <td data-status>
              <?= $gid ? qta_enrollment_status(['start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'exam_date' => $r['exam_date'], 'final_score' => $r['final_score']])
                       : qta_status('Pret caktimin në grup', 'neutral', 'bi-hourglass-split') ?>
            </td>
            <td class="nowrap">
              <?php if ($ph !== ''): ?>
                <a class="code" href="tel:<?= h(preg_replace('/[^0-9+]/', '', $ph) ?? '') ?>"><?= h($ph) ?></a>
              <?php else: ?>
                <span class="text-subtle">—</span>
              <?php endif; ?>
            </td>
            <td class="nowrap num-col"><?= $r['age'] !== null ? (int)$r['age'] : '—' ?></td>
            <td><?= h((string)(($r['edu_label'] ?? '') ?: '—')) ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="11" class="table-empty">
            <?= $hasFilters ? 'Asnjë regjistrim nuk përputhet me kërkimin. Provo tjetër emër ose shtyp "Pastro kërkimin".' : 'Regjistri është bosh. Regjistro kursantë te "Të gjithë kursantët".' ?>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1):
      $base = 'register.php?' . http_build_query(array_filter([
        'q'         => $q !== '' ? $q : null,
        'from_amze' => $from_amze !== '' ? $from_amze : null,
      ]));
      $sep = str_contains($base, '=') ? '&' : '';
    ?>
      <nav class="pager" aria-label="Faqet e regjistrit">
        <span>Faqja <?= $page ?> nga <?= $totalPages ?> · <?= h(qta_plural($total, 'regjistrim', 'regjistrime')) ?></span>
        <ul class="pagination">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($base . $sep . 'page=' . max(1, $page - 1)) ?>" aria-label="Faqja e mëparshme"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
          </li>
          <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= h($base . $sep . 'page=' . $p) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($base . $sep . 'page=' . min($totalPages, $page + 1)) ?>" aria-label="Faqja tjetër"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  </section>
</main>

<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php require __DIR__ . '/../shared/partials/download_generation_toast.php'; ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'register_inline_update.php';
const EDIT_MODE = <?= $EDIT_MODE ? 'true' : 'false' ?>;

/* Njoftimet: sistemi i përbashkët (app.js) */
function notify(type, text, opts={}){
  return window.qtaToast ? window.qtaToast(text, type, opts.title, opts) : null;
}
function showMsg(type, text){ notify(type, text); }

function clean(s){ return (s||'').replace(/\s+/g,' ').trim(); }

/* dd.mm.vvvv (ose dd-mm-vvvv) -> vvvv-mm-dd për serverin */
function normalizeDateForServer(v){
  const s = clean(v);
  if (s === '' || s === '—') return '';
  const m = s.match(/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/);
  if (!m) throw new Error('Shkruaje datën si dd.mm.vvvv, p.sh. 05.03.2026.');
  return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
}
function toIso(v){ try { return normalizeDateForServer(v); } catch(e){ return ''; } }

/* Gjendja e rreshtit — e njëjta logjikë si në server (Kalon me ≥ 50) */
function statusHtml(label, variant, icon){
  const span = document.createElement('span');
  span.className = 'status status-' + variant;
  span.innerHTML = '<i class="bi ' + icon + '" aria-hidden="true"></i>';
  span.appendChild(document.createTextNode(label));
  return span.outerHTML;
}
function refreshStatus(row){
  const cell = row.querySelector('[data-status]');
  if (!cell || row.dataset.hasGroup !== '1') return;
  const scoreTxt = clean(row.querySelector('td[data-field="final_score"] .editable')?.textContent);
  const exam = toIso(row.querySelector('td[data-field="exam_date"] .editable')?.textContent);
  const start = toIso(row.querySelector('td[data-field="start_date"] .editable')?.textContent);
  const end = toIso(row.querySelector('td[data-field="end_date"] .editable')?.textContent);
  const today = new Date().toISOString().slice(0,10);
  const days = d => Math.round((new Date(d+'T00:00:00') - new Date(today+'T00:00:00')) / 86400000);
  const when = d => { const n = days(d); return n===0?'sot':n===1?'nesër':n>1?('pas '+n+' ditësh'):(Math.abs(n)+' ditë më parë'); };
  let html;
  if (scoreTxt !== '' && scoreTxt !== '—' && !isNaN(scoreTxt.replace(',','.'))) {
    const n = parseFloat(scoreTxt.replace(',','.'));
    html = n >= 50 ? statusHtml('Kaloi · '+scoreTxt, 'success', 'bi-check-circle-fill') : statusHtml('Nuk kaloi · '+scoreTxt, 'danger', 'bi-x-circle-fill');
  } else if (exam) {
    html = days(exam) >= 0 ? statusHtml('Provimi '+when(exam), 'info', 'bi-calendar-event') : statusHtml('Pret rezultatin', 'warning', 'bi-clock-fill');
  } else if (start && start > today) {
    html = statusHtml('Nis '+when(start), 'info', 'bi-calendar-event');
  } else if (start && end && today >= start && today <= end) {
    html = statusHtml('Në mësim', 'accent', 'bi-easel');
  } else if (end && end < today) {
    html = statusHtml('Pret datën e provimit', 'warning', 'bi-clock-fill');
  } else {
    html = statusHtml('Pa provim ende', 'neutral', 'bi-dash-circle');
  }
  cell.innerHTML = html;
}

async function saveInline(payload, cell, displayEl, oldVal){
  if (!EDIT_MODE) { return; }
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
      notify('danger', json.error || 'Ndryshimi nuk u ruajt.');
      return;
    }

    if(displayEl){
      displayEl.textContent = json.display ?? displayEl.textContent;
    }
    refreshStatus(cell.closest('tr'));
    cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800);
    notify('success','Ndryshimi u ruajt.');
  }catch(e){
    console.error(e);
    cell.classList.remove('cell-saving');
    if(displayEl) displayEl.textContent = oldVal;
    cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
    notify('danger', 'Ndryshimi nuk u ruajt. Kontrollo lidhjen dhe provo sërish.');
  }
}

/* Redaktimi në tabelë */
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
        newVal = normalizeDateForServer(newVal);
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
        notify('danger','Pikët duhet të jenë numër nga 0 deri në 100.');
        return;
      }
      saveInline({action:'update_final_score', student_id:studentId, group_id:groupId, final_score:n}, cell, el, oldVal);
      return;
    }

    const noGroup = 'Ky kursant nuk është ende në një grup. Caktoje te "Kursantët pa grup".';
    if(field==='start_date'){
      if(!groupId){ el.textContent = oldVal; notify('warning', noGroup); return; }
      saveInline({action:'update_group_start', student_id:studentId, group_id:groupId, start_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }
    if(field==='end_date'){
      if(!groupId){ el.textContent = oldVal; notify('warning', noGroup); return; }
      saveInline({action:'update_group_end', student_id:studentId, group_id:groupId, end_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }
    if(field==='exam_date'){
      if(!groupId){ el.textContent = oldVal; notify('warning', noGroup); return; }
      saveInline({action:'update_student_exam_date', student_id:studentId, group_id:groupId, exam_date:(newVal===''?null:newVal)}, cell, el, oldVal);
      return;
    }
  });
});

/* Maska e datës dd.mm.vvvv */
function maskToDDMMYYYY(input) {
  const digits = String(input || '').replace(/\D/g, '').slice(0, 8);
  const d = digits.slice(0, 2), m = digits.slice(2, 4), y = digits.slice(4, 8);
  let out = d;
  if (digits.length > 2) out += '.' + m;
  if (digits.length > 4) out += '.' + y;
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
    if (el.textContent !== masked) { el.textContent = masked; placeCaretAtEnd(el); }
  });
  el.addEventListener('paste', (e) => {
    e.preventDefault();
    const txt = (e.clipboardData || window.clipboardData).getData('text');
    el.textContent = maskToDDMMYYYY(txt);
    placeCaretAtEnd(el);
  });
}

/* Pikët: vetëm shifra dhe një presje/pikë */
function attachScoreMask(el){
  el.addEventListener('input', ()=>{
    let t = el.textContent.replace(/[^0-9,\.]/g,'');
    const firstSep = t.search(/[,.]/);
    if (firstSep !== -1){
      t = t.slice(0, firstSep + 1) + t.slice(firstSep + 1).replace(/[,.]/g,'');
    }
    if (el.textContent !== t){ el.textContent = t; placeCaretAtEnd(el); }
  });
}

document.querySelectorAll(
  'td.cell[data-field="start_date"] .editable,' +
  'td.cell[data-field="end_date"] .editable,'  +
  'td.cell[data-field="exam_date"] .editable'
).forEach(attachDateMask);
document.querySelectorAll('td.cell[data-field="final_score"] .editable').forEach(attachScoreMask);

<?php if ($flash_ok): ?>
document.addEventListener('DOMContentLoaded', ()=> notify('success', <?= json_encode($flash_ok) ?>));
<?php endif; ?>
<?php if ($flash_err): ?>
document.addEventListener('DOMContentLoaded', ()=> notify('danger', <?= json_encode($flash_err) ?>));
<?php endif; ?>
</script>

</body>
</html>
