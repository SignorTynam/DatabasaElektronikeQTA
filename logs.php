<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

/* ===== Guard admin ===== */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$u = $pdo->prepare("
  SELECT u.id, u.full_name, u.email, r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.id=:id LIMIT 1
");
$u->execute([':id'=>$_SESSION['user_id']]);
$currentUser = $u->fetch();
if (!$currentUser || $currentUser['role_name']!=='administrator') { header('Location: selectProfile.php'); exit; }

/* Helper */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* Attach audit context */
qta_audit_attach($pdo, $currentUser);

/* Filters */
$action  = isset($_GET['action']) && in_array($_GET['action'], ['INSERT','UPDATE','DELETE'], true) ? $_GET['action'] : null;
$table   = isset($_GET['table']) && $_GET['table'] !== '' ? $_GET['table'] : null;
$q       = isset($_GET['q']) && $_GET['q'] !== '' ? trim($_GET['q']) : null;
$from    = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : null; // YYYY-MM-DD
$to      = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : null;

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per'] ?? 25)));
$offset  = ($page - 1) * $perPage;

/* Build WHERE */
$where = [];
$params = [];

if ($action) { $where[] = "ae.action = :action"; $params[':action'] = $action; }
if ($table)  { $where[] = "ae.table_name = :table"; $params[':table'] = $table; }
if ($from)   { $where[] = "ae.happened_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
if ($to)     { $where[] = "ae.happened_at <= :to";   $params[':to']   = $to   . ' 23:59:59'; }

if ($q) {
  $where[] = "(u.full_name LIKE :q OR u.email LIKE :q OR INSTR(ae.row_pk, :q2) > 0)";
  $params[':q']  = '%'.$q.'%';
  $params[':q2'] = $q;
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* Tablat për dropdown */
$tables = $pdo->query("SELECT DISTINCT table_name FROM audit_events ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

/* Count total */
$cntSt = $pdo->prepare("SELECT COUNT(*) FROM audit_events ae LEFT JOIN users u ON u.id=ae.user_id $whereSql");
$cntSt->execute($params);
$total = (int)$cntSt->fetchColumn();

/* Fetch events page */
$sql = "
  SELECT ae.id, ae.happened_at, ae.action, ae.table_name, ae.row_pk, ae.user_id, ae.ip_address, ae.user_agent
       , u.full_name, u.email, r.name AS role_name
  FROM audit_events ae
  LEFT JOIN users u ON u.id=ae.user_id
  LEFT JOIN roles r ON r.id=u.role_id
  $whereSql
  ORDER BY ae.id DESC
  LIMIT :limit OFFSET :offset
";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v){ $st->bindValue($k,$v); }
$st->bindValue(':limit',$perPage,PDO::PARAM_INT);
$st->bindValue(':offset',$offset,PDO::PARAM_INT);
$st->execute();
$events = $st->fetchAll(PDO::FETCH_ASSOC);

/* Load fields for listed events (to show diff) */
$byId = array_column($events, 'id');
$fields = [];
if ($byId) {
  $in = implode(',', array_fill(0, count($byId), '?'));
  $fs = $pdo->prepare("SELECT event_id, column_name, old_value, new_value FROM audit_event_fields WHERE event_id IN ($in) ORDER BY column_name ASC");
  $fs->execute($byId);
  while ($row = $fs->fetch(PDO::FETCH_ASSOC)) {
    $fields[(int)$row['event_id']][] = $row;
  }
}

$NAV_ACTIVE = 'logs';
require __DIR__ . '/inc/navbar.php';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <title>Logs – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background:#f6f8fb; }
    .card { border:none; border-radius:1rem; box-shadow:0 10px 25px rgba(2,6,23,.06); }
    .badge-action { text-transform:uppercase; letter-spacing:.02em; }
    .json { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size:.8rem; white-space:pre-wrap; word-break:break-word; }
    .diff-old { background:#fff1f2; }
    .diff-new { background:#ecfdf5; }
    .nowrap { white-space:nowrap; }
  </style>
</head>
<body class="pt-4">

<main class="container-fluid px-3 px-md-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>Audit Logs</h3>
    <span class="text-muted">Gjithsej: <?= number_format($total) ?></span>
  </div>

  <!-- Filters -->
  <div class="card mb-4">
    <div class="card-body">
      <form class="row g-3 align-items-end">
        <div class="col-12 col-md-2">
          <label class="form-label">Aksioni</label>
          <select class="form-select" name="action">
            <option value="">—</option>
            <?php foreach (['INSERT','UPDATE','DELETE'] as $a): ?>
              <option value="<?= $a ?>"<?= $action===$a?' selected':'' ?>><?= $a ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Tabela</label>
          <select class="form-select" name="table">
            <option value="">—</option>
            <?php foreach ($tables as $t): ?>
              <option value="<?= h($t) ?>"<?= $table===$t?' selected':'' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Nga data</label>
          <input type="date" class="form-control" name="from" value="<?= h($from) ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Deri më</label>
          <input type="date" class="form-control" name="to" value="<?= h($to) ?>">
        </div>
        <div class="col-12 col-md-2">
          <label class="form-label">Kërko</label>
          <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="emër/email/PK">
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label">/faqe</label>
          <input type="number" class="form-control" name="per" min="10" max="100" value="<?= (int)$perPage ?>">
        </div>
        <div class="col-12 col-md-12 d-flex gap-2">
          <button class="btn btn-primary"><i class="bi bi-filter me-1"></i>Filtro</button>
          <a class="btn btn-outline-secondary" href="logs.php"><i class="bi bi-x-circle me-1"></i>Fshij filtrat</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Koha</th>
              <th>Tabela</th>
              <th>Aksioni</th>
              <th>PK</th>
              <th>Përdoruesi</th>
              <th>IP</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$events): ?>
              <tr><td colspan="8" class="text-center text-muted">S’ka evente për filtrat e zgjedhur.</td></tr>
            <?php else: foreach ($events as $ev): ?>
              <?php
                $eid = (int)$ev['id'];
                $pk  = json_decode($ev['row_pk'] ?? 'null', true);
                $pkText = $pk ? implode(', ', array_map(fn($k)=>$k.'='.$pk[$k], array_keys($pk))) : '—';
                $badge = $ev['action']==='INSERT' ? 'success' : ($ev['action']==='UPDATE' ? 'warning' : 'danger');
              ?>
              <tr>
                <td class="nowrap">#<?= $eid ?></td>
                <td class="small text-muted"><?= h($ev['happened_at']) ?></td>
                <td><?= h($ev['table_name']) ?></td>
                <td><span class="badge badge-action text-bg-<?= $badge ?>"><?= h($ev['action']) ?></span></td>
                <td class="small"><?= h($pkText) ?></td>
                <td>
                  <div class="fw-semibold"><?= h($ev['full_name'] ?: ($ev['email'] ?? '—')) ?></div>
                  <div class="small text-muted"><?= h(($ev['role_name'] ?? '') . ($ev['email'] ? ' • '.$ev['email'] : '')) ?></div>
                </td>
                <td class="small text-muted"><?= h($ev['ip_address'] ?? '—') ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#ev<?= $eid ?>">
                    <i class="bi bi-eye"></i>
                  </button>
                </td>
              </tr>
              <tr class="collapse" id="ev<?= $eid ?>">
                <td colspan="8">
                  <div class="row g-3">
                    <div class="col-lg-6">
                      <div class="border rounded p-2">
                        <div class="small text-muted mb-1">Ndryshimet kolonë për kolonë</div>
                        <div class="table-responsive">
                          <table class="table table-sm">
                            <thead class="table-light">
                              <tr><th>Kolona</th><th class="w-50">Vlera e vjetër</th><th class="w-50">Vlera e re</th></tr>
                            </thead>
                            <tbody>
                              <?php if (!empty($fields[$eid])): foreach ($fields[$eid] as $f): ?>
                                <tr>
                                  <td class="small"><?= h($f['column_name']) ?></td>
                                  <td class="small diff-old"><?= h($f['old_value']) ?></td>
                                  <td class="small diff-new"><?= h($f['new_value']) ?></td>
                                </tr>
                              <?php endforeach; else: ?>
                                <tr><td colspan="3" class="text-muted">S’ka diferenca të regjistruara.</td></tr>
                              <?php endif; ?>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                    <div class="col-lg-6">
                      <div class="border rounded p-2">
                        <div class="small text-muted mb-1">Raw JSON</div>
                        <?php
                          // Merr old/new JSON për të njëjtin event
                          $j = $pdo->prepare("SELECT old_data, new_data, user_agent FROM audit_events WHERE id = ?");
                          $j->execute([$eid]);
                          $jd = $j->fetch(PDO::FETCH_ASSOC) ?: [];
                        ?>
                        <div class="row g-2">
                          <div class="col-12"><span class="badge text-bg-secondary">UA</span> <span class="small"><?= h($jd['user_agent'] ?? '') ?></span></div>
                          <div class="col-md-6">
                            <div class="small text-muted mb-1">old_data</div>
                            <pre class="json mb-0"><?= h($jd['old_data'] ?: 'null') ?></pre>
                          </div>
                          <div class="col-md-6">
                            <div class="small text-muted mb-1">new_data</div>
                            <pre class="json mb-0"><?= h($jd['new_data'] ?: 'null') ?></pre>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php
        $pages = max(1, (int)ceil($total / $perPage));
        $qs = $_GET; unset($qs['page']);
        $base = 'logs.php?'.http_build_query($qs);
      ?>
      <nav class="mt-3" aria-label="Faqet">
        <ul class="pagination pagination-sm">
          <?php for ($i=1; $i<=$pages; $i++): ?>
            <li class="page-item<?= $i===$page ? ' active' : '' ?>">
              <a class="page-link" href="<?= $base . '&page=' . $i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>

  <div class="text-center text-muted small mt-4">
    &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
