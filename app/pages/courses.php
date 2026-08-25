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
    $_SESSION['courses_edit_mode'] = filter_var($_GET['edit'], FILTER_VALIDATE_BOOLEAN);
    // redirect pa param 'edit' (ruaj pjesën tjetër të query-it)
    $qs = $_GET; unset($qs['edit']);
    $redir = 'courses.php' . ($qs ? ('?' . http_build_query($qs)) : '');
    header('Location: ' . $redir);
    exit;
}
$EDIT_MODE = !empty($_SESSION['courses_edit_mode']);

/* ------------------------------
   Guard: admin OSE editor i loguar
------------------------------- */
if (!isset($_SESSION['user_id'])) { header('Location: selectProfile.php'); exit; }
$userStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid
    LIMIT 1
");
$userStmt->execute([':uid' => $_SESSION['user_id']]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

$roleName  = strtolower((string)($currentUser['role_name'] ?? ''));
$isAdmin   = ($roleName === 'administrator');
$isEditor  = ($roleName === 'editor');

if (!$currentUser || (!$isAdmin && !$isEditor)) {
    header('Location: selectProfile.php'); exit;
}

/* ------------------------------
   CSRF & Flash helpers
------------------------------- */
function flash(string $key, ?string $msg=null) {
    if ($msg === null) {
        if (!empty($_SESSION['flash'][$key])) { $m = $_SESSION['flash'][$key]; unset($_SESSION['flash'][$key]); return $m; }
        return null;
    }
    $_SESSION['flash'][$key] = $msg;
}
function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(400); exit('CSRF token mismatch.');
        }
    }
}
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------
   POST: Shto / Fshi kurs
------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_course') {
        try {
            if (!$EDIT_MODE) { throw new RuntimeException('Aktivizo mënyrën e redaktimit për të shtuar module.'); }

            $code  = trim($_POST['code'] ?? '');
            $name  = trim($_POST['name'] ?? '');
            $hours = trim($_POST['hours'] ?? '');

            if ($code === '' || $name === '' || $hours === '') {
                throw new RuntimeException('Plotësoni fushat: Kod, Emër, Orë.');
            }
            if (!ctype_digit($hours) || (int)$hours < 1 || (int)$hours > 65535) {
                throw new RuntimeException('“Orë” duhet të jetë numër i plotë ≥ 1.');
            }

            $q = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE code = :c");
            $q->execute([':c'=>$code]);
            if ((int)$q->fetchColumn() > 0) {
                throw new RuntimeException('Ky kod kursi ekziston tashmë.');
            }

            $st = $pdo->prepare("INSERT INTO courses (code, name, hours) VALUES (:c, :n, :h)");
            $st->execute([':c'=>$code, ':n'=>$name, ':h'=>(int)$hours]);

            flash('ok', 'Moduli u shtua me sukses.');
        } catch (Throwable $e) {
            flash('err', $e->getMessage());
        }
        header('Location: courses.php'); exit;
    }

    if ($action === 'delete_course') {
        try {
            if (!$EDIT_MODE) { throw new RuntimeException('Aktivizo mënyrën e redaktimit për të fshirë module.'); }

            $course_id = (int)($_POST['course_id'] ?? 0);
            if ($course_id <= 0) throw new RuntimeException('Kurs i pavlefshëm.');

            $cinfo = $pdo->prepare("SELECT code, name FROM courses WHERE id=:id LIMIT 1");
            $cinfo->execute([':id'=>$course_id]);
            $ci = $cinfo->fetch(PDO::FETCH_ASSOC);
            if (!$ci) throw new RuntimeException('Moduli nuk u gjet.');

            $del = $pdo->prepare("DELETE FROM courses WHERE id=:id");
            $del->execute([':id'=>$course_id]);

            flash('ok', 'Moduli "'.$ci['code'].' — '.$ci['name'].'" u fshi.');
        } catch (Throwable $e) {
            flash('err', $e->getMessage());
        }
        header('Location: courses.php'); exit;
    }
}

/* ------------------------------
   Helpers
------------------------------- */
function fmt_dmy(?string $iso): string {
    if (!$iso) return '—';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return htmlspecialchars((string)$iso, ENT_QUOTES, 'UTF-8');
    $ts = strtotime($iso);
    return $ts ? date('d-m-Y', $ts) : '—';
}

/* ------------------------------
   Kërkim + Paginim
------------------------------- */
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = ["1=1"];
$params = [];

if ($q !== '') {
    $where[] = "(c.code LIKE :kw OR c.name LIKE :kw2)";
    $params[':kw']  = '%'.$q.'%';
    $params[':kw2'] = '%'.$q.'%';
}
$whereSql = 'WHERE '.implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM courses c $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

$listStmt = $pdo->prepare("
    SELECT c.id AS course_id, c.code, c.name, c.hours, c.created_at
    FROM courses c
    $whereSql
    ORDER BY c.code ASC, c.id ASC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k=>$v) { $listStmt->bindValue($k, $v, PDO::PARAM_STR); }
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$courses = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* Kurse për target (select në modalin “Zhvendos grupin”) */
$allCourses = $pdo->query("SELECT id, code, name FROM courses ORDER BY code ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$allCoursesMap = [];
foreach ($allCourses as $ac) { $allCoursesMap[(int)$ac['id']] = $ac; }

/* Grupe për kurset e faqes (summary + numer i anëtarëve) */
$groupsByCourse = [];
if ($courses) {
    $ids = array_column($courses, 'course_id');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $gq = $pdo->prepare("
            SELECT
              cg.id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed,
              COUNT(cgs.student_id) AS members
            FROM course_groups cg
            LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
            WHERE cg.course_id IN ($ph)
            GROUP BY cg.id
            ORDER BY cg.start_date DESC, cg.id DESC
        ");
        $gq->execute($ids);
        $grs = $gq->fetchAll(PDO::FETCH_ASSOC);
        foreach ($grs as $g) {
            $cid = (int)$g['course_id'];
            if (!isset($groupsByCourse[$cid])) $groupsByCourse[$cid] = [];
            $groupsByCourse[$cid][] = $g;
        }
    }
}

$ok  = flash('ok');
$err = flash('err');

$NAV_ACTIVE = 'courses';

$pageTitle = 'Modulet – QTA ' . ($isAdmin ? 'Admin' : 'Editor');
$bodyClass = $EDIT_MODE ? '' : 'editing-off';
require __DIR__ . '/../shared/app_head.php';
?>


<?php
    // Navbar sipas rolit
    if ($isAdmin) {
        require __DIR__ . '/inc/navbar.php';
    } elseif ($roleName === 'editor') {
        require __DIR__ . '/inc/navbar4.php';
    }
?>

<!-- Toast container -->
<div id="toastZone" class="toast-container position-fixed start-0 bottom-0 p-3" style="z-index:1080;"></div>

<main class="app-main">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
        <div class="title-block-main">
          <div class="title-block-eyebrow">Katalogu</div>
          <h1>Modulet</h1>
        </div>
        <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>

        <div class="d-flex align-items-center page-toolbar">
            <?php
                // Build toggle URL (preserve query params except 'edit')
                $qs = $_GET;
                $qs['edit'] = $EDIT_MODE ? '0' : '1';
                $toggleUrl = 'courses.php' . ($qs ? ('?' . http_build_query($qs)) : '');
            ?>
</div>
    </div>

    <?php if (!$EDIT_MODE): ?>
        <div class="alert alert-secondary py-2">
            <i class="bi bi-info-circle me-1"></i>
            Aktivizo <strong>Mënyrën e redaktimit</strong> për të ndryshuar qelizat, për të shtuar ose fshirë module dhe për të zhvendosur grupe.
        </div>
    <?php endif; ?>

    <!-- Kërkim -->
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get" action="courses.php">
                <div class="col-md-9">
                    <div class="d-flex align-items-center">
                        <label class="form-label mb-0 me-2" style="min-width:70px;">Kërko</label>
                        <div class="input-group flex-grow-1">
                            <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                            <input type="text" name="q" class="form-control border-0"
                                   placeholder="Kërko sipas Kodit ose Emrit..."
                                   value="<?= htmlspecialchars($q) ?>">
                        </div>
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-soft-secondary btn-pill me-1" type="button"
                            onclick="window.location='courses.php'"><i class="bi bi-x-circle me-1"></i>Pastro</button>
                    <button class="btn btn-primary btn-pill" type="submit"><i class="bi bi-funnel me-1"></i>Apliko</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela -->
    <div class="card">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><i class="bi bi-book me-2"></i>Lista e moduleve</h5>
            <span class="text-muted small"><?= number_format($total) ?> rezultat(e)</span>
        </div>
        <div class="card-body">
            <?php
              $tfTarget = '';
              $tfPlaceholder = 'Ngushto listën — kod ose emër moduli';
              $tfChips = [];
              require __DIR__ . '/../shared/partials/table_filter.php';
            ?>
            <div class="table-responsive mini-table">
                <table class="table align-middle mb-0" data-sortable>
                    <thead class="table-light">
                        <tr>
                            <th data-sort="text">Kod</th>
                            <th data-sort="text">Emër</th>
                            <th class="nowrap" data-sort="num">Orë</th>
                            <th class="nowrap" data-sort="date">Krijuar më</th>
                            <th class="nowrap text-end" data-sort="none">Veprime</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($courses): ?>
                        <?php foreach ($courses as $c): $cid=(int)$c['course_id']; ?>
                            <?php
                              $grList = $groupsByCourse[$cid] ?? [];
                              $grCount = count($grList);
                            ?>
                            <tr>
                                <td class="cell" data-id="<?= $cid ?>" data-field="code">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE?'true':'false' ?>" tabindex="<?= $EDIT_MODE?0:-1 ?>"><?= htmlspecialchars($c['code']) ?></span>
                                </td>
                                <td class="cell" data-id="<?= $cid ?>" data-field="name">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE?'true':'false' ?>" tabindex="<?= $EDIT_MODE?0:-1 ?>"><?= htmlspecialchars($c['name']) ?></span>
                                </td>
                                <td class="cell nowrap" data-id="<?= $cid ?>" data-field="hours" title="Numër i plotë ≥ 1">
                                    <span class="editable" contenteditable="<?= $EDIT_MODE?'true':'false' ?>" tabindex="<?= $EDIT_MODE?0:-1 ?>"><?= (int)$c['hours'] ?></span>
                                </td>
                                <td class="text-muted small nowrap"><?= htmlspecialchars($c['created_at']) ?></td>
                                <td class="text-end">
                                    <div class="btn-group">
                                      <button
                                        class="btn btn-soft-secondary btn-pill btn-toggle-groups"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#courseGroups_<?= $cid ?>"
                                        aria-expanded="false"
                                        aria-controls="courseGroups_<?= $cid ?>"
                                      >
                                        <i class="bi bi-chevron-down me-1"></i>
                                        Grupe <span class="badge bg-secondary align-text-bottom"><?= $grCount ?></span>
                                      </button>

                                      <button
                                        type="button"
                                        class="btn btn-outline-danger btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#deleteCourseModal"
                                        data-course-id="<?= $cid ?>"
                                        data-course-code="<?= htmlspecialchars($c['code'], ENT_QUOTES) ?>"
                                        data-course-name="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>"
                                        <?= $EDIT_MODE?'':'disabled' ?>
                                        title="<?= $EDIT_MODE?'Fshi këtë modul':'Aktivizo Edit Mode për të fshirë' ?>"
                                      >
                                        <i class="bi bi-trash me-1"></i> Fshi
                                      </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Row i grupeve (collapse) -->
                            <tr class="collapse" id="courseGroups_<?= $cid ?>">
                              <td colspan="5" class="bg-light">
                                <div class="p-3">
                                  <?php if ($grList): ?>
                                    <div class="table-responsive">
                                      <table class="table table-sm align-middle mb-0">
                                        <thead>
                                          <tr>
                                            <th class="nowrap">Grup</th>
                                            <th>Datat</th>
                                            <th class="nowrap">Anëtarë</th>
                                            <th class="nowrap">Status</th>
                                            <th class="text-end nowrap">Veprime</th>
                                          </tr>
                                        </thead>
                                        <tbody id="groupsBody_<?= $cid ?>">
                                          <?php foreach ($grList as $g): ?>
                                            <?php
                                              $gid = (int)$g['id'];
                                              $completed = (int)$g['is_completed'] === 1;
                                              $dates = fmt_dmy($g['start_date']).' → '.fmt_dmy($g['end_date']);
                                            ?>
                                            <tr id="groupRow_<?= $gid ?>">
                                              <td class="nowrap">
                                                <i class="bi bi-collection me-1"></i>
                                                Grup #<?= $gid ?>
                                              </td>
                                              <td><?= htmlspecialchars($dates) ?></td>
                                              <td class="nowrap"><?= (int)$g['members'] ?></td>
                                              <td>
                                                <span class="badge <?= $completed?'text-bg-success':'text-bg-danger' ?>">
                                                  <?= $completed?'I përfunduar':'Jo i përfunduar' ?>
                                                </span>
                                              </td>
                                              <td class="text-end">
                                                <button
                                                  class="btn btn-soft-primary btn-sm btn-pill"
                                                  type="button"
                                                  data-bs-toggle="modal"
                                                  data-bs-target="#moveGroupModal"
                                                  data-group-id="<?= $gid ?>"
                                                  data-current-course="<?= $cid ?>"
                                                  <?= $EDIT_MODE ? '' : 'disabled' ?>
                                                  title="Zhvendos këtë grup te modul tjetër"
                                                >
                                                  <i class="bi bi-arrows-move me-1"></i> Zhvendos grupin
                                                </button>
                                                <a class="btn btn-outline-secondary btn-sm" href="groups.php?q=&course_id=<?= $cid ?>#gBody_<?= $gid ?>" target="_blank">
                                                  <i class="bi bi-box-arrow-up-right me-1"></i> Hap te “Grupe”
                                                </a>
                                              </td>
                                            </tr>
                                          <?php endforeach; ?>
                                        </tbody>
                                      </table>
                                    </div>
                                  <?php else: ?>
                                    <div class="text-muted small">Nuk ka grupe për këtë modul.</div>
                                  <?php endif; ?>
                                </div>
                              </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">Nuk u gjet asnjë modul.</td></tr>
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
                    $base = 'courses.php?'.http_build_query(array_filter(['q' => $q !== '' ? $q : null]));
                    $prev = max(1, $page-1);
                    $next = min($totalPages, $page+1);
                    ?>
                    <li class="page-item <?= $page<=1?'disabled':'' ?>">
                        <a class="page-link" href="<?= $base.(strpos($base,'?')!==false?'&':'?') ?>page=1">«</a>
                    </li>
                    <li class="page-item <?= $page<=1?'disabled':'' ?>">
                        <a class="page-link" href="<?= $base.(strpos($base,'?')!==false?'&':'?') ?>page=<?= $prev ?>">‹</a>
                    </li>
                    <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $totalPages ?></span></li>
                    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                        <a class="page-link" href="<?= $base.(strpos($base,'?')!==false?'&':'?') ?>page=<?= $next ?>">›</a>
                    </li>
                    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                        <a class="page-link" href="<?= $base.(strpos($base,'?')!==false?'&':'?') ?>page=<?= $totalPages ?>">»</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

    <div class="text-center text-muted small mt-4">
        &copy; <?= date('Y') ?> QTA • Të gjitha të drejtat e rezervuara.
    </div>
</main>

<!-- FAB: Shto modul (poshtë djathtas) -->
<?php if ($EDIT_MODE): ?>
<button class="btn btn-primary btn-fab" type="button"
        data-bs-toggle="modal" data-bs-target="#addCourseModal"
        aria-label="Shto modul">
  <i class="bi bi-plus-lg"></i>
</button>
<?php else: ?>
<button class="btn btn-soft-secondary btn-fab" type="button" disabled
        title="Aktivizo Edit Mode për të shtuar modul">
  <i class="bi bi-plus-lg"></i>
</button>
<?php endif; ?>

<!-- MODAL: Shto Modul -->
<div class="modal fade" id="addCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="create_course">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-bookmark-plus me-1"></i> Shto modul</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Kod *</label>
                <input type="text" name="code" class="form-control" placeholder="p.sh. QTA-ALGO" <?= $EDIT_MODE?'required':'disabled' ?>>
            </div>
            <div class="col-md-5">
                <label class="form-label">Emër *</label>
                <input type="text" name="name" class="form-control" placeholder="p.sh. Algoritme" <?= $EDIT_MODE?'required':'disabled' ?>>
            </div>
            <div class="col-md-3">
                <label class="form-label">Orë *</label>
                <input type="number" name="hours" class="form-control" min="1" step="1" placeholder="p.sh. 30" <?= $EDIT_MODE?'required':'disabled' ?>>
            </div>
        </div>
        <div class="form-text mt-2">
            Krijon një rresht në <code>courses</code> (kolonat: <code>code, name, hours</code>).
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary btn-pill" type="submit" <?= $EDIT_MODE?'':'disabled' ?>>Shto modul</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Fshi modul -->
<div class="modal fade" id="deleteCourseModal" tabindex="-1" aria-labelledby="deleteCourseLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="courses.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="action" value="delete_course">
      <input type="hidden" name="course_id" id="deleteCourseId" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteCourseLabel"><i class="bi bi-trash me-1"></i> Fshi modul</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p>Jeni i sigurt që dëshironi të fshini modulin:</p>
        <ul class="mb-2">
          <li><strong>Kod:</strong> <span id="delCode"></span></li>
          <li><strong>Emër:</strong> <span id="delName"></span></li>
        </ul>
        <div class="alert alert-warning small mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i>
          <strong>Kujdes:</strong> Fshirja mund të <u>shkaktojë fshirje kaskadë</u> të grupeve dhe pjesëmarrjeve të lidhura me këtë modul (nëse FK janë ON).
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-soft-secondary btn-pill" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-danger btn-pill" type="submit" <?= $EDIT_MODE?'':'disabled' ?>>Po, fshije</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Zhvendos grupin te modul tjetër -->
<div class="modal fade" id="moveGroupModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="moveGroupForm" onsubmit="return false;">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="group_id" id="mv_group_id" value="">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-arrows-move me-1"></i> Zhvendos grupin</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Moduli i ri</label>
          <select id="mv_target_course" class="form-select" required <?= $EDIT_MODE?'':'disabled' ?>>
            <option value="">— Zgjidh modul —</option>
            <?php foreach ($allCourses as $ac): ?>
              <option value="<?= (int)$ac['id'] ?>">
                <?= htmlspecialchars($ac['code'].' — '.$ac['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Grupi do të lidhet me modulin e zgjedhur.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-soft-secondary btn-pill" type="button" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary btn-pill" type="submit" id="mv_submit" <?= $EDIT_MODE?'':'disabled' ?>>Zhvendos</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
const ENDPOINT = 'courses_inline_update.php';
const EDIT_ENABLED = <?= $EDIT_MODE ? 'true' : 'false' ?>;

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

function cleanText(s) { return (s || '').replace(/\s+/g,' ').trim(); }

/* ===== Inline save për fushat e modulit ===== */
async function saveInline(courseId, field, value, cell, displayEl) {
  try {
    cell.classList.add('cell-saving');
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'Accept':'application/json'},
      body: JSON.stringify({ action: 'update_field', csrf: CSRF, course_id: courseId, field, value })
    });
    const json = await res.json();
    cell.classList.remove('cell-saving');
    if (!json.ok) throw new Error(json.error || 'Gabim i panjohur.');
    if (displayEl) { displayEl.textContent = json.display ?? (value || ''); }
    cell.classList.add('cell-ok'); setTimeout(()=>cell.classList.remove('cell-ok'), 800);
    notify('success','U ruajt me sukses.');
  } catch (e) {
    console.error(e);
    cell.classList.remove('cell-saving'); cell.classList.add('cell-err');
    setTimeout(()=>cell.classList.remove('cell-err'), 1200);
    notify('danger', e.message || 'Nuk u krye veprimi. Kontrollo lidhjen ose provo sërish.');
  }
}

/* ===== Navigim me TAB ndër qeliza (spreadsheet-like) ===== */
function getEditableList(){
  return Array.from(document.querySelectorAll('td.cell .editable[contenteditable="true"]'));
}
function focusNeighbor(current, backwards=false){
  const list = getEditableList();
  const idx = list.indexOf(current);
  if (idx === -1) return;
  const nextIdx = backwards ? Math.max(0, idx-1) : Math.min(list.length-1, idx+1);
  const target = list[nextIdx];
  if (target && target !== current){
    target.focus();
    // Vendos caret në fund
    const r = document.createRange(); const s = window.getSelection();
    r.selectNodeContents(target); r.collapse(false); s.removeAllRanges(); s.addRange(r);
  }
}

/* Inline editing: vetëm kur Edit Mode është ON */
if (EDIT_ENABLED) {
  document.querySelectorAll('td.cell .editable[contenteditable="true"]').forEach(el => {
    let oldVal = el.textContent;

    el.addEventListener('focus', () => { oldVal = el.textContent; el.dataset.prev = oldVal; });

    el.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); el.blur(); }
      if (ev.key === 'Tab') {
        ev.preventDefault();
        const cell = el.closest('td.cell');
        const field = cell.dataset.field;
        const cid = parseInt(cell.dataset.id, 10);
        const newVal = cleanText(el.textContent);
        if (newVal !== cleanText(oldVal)) {
          // ruaj para se të lëvizë fokusi
          saveInline(cid, field, newVal, cell, el).finally(()=>{
            setTimeout(()=> focusNeighbor(el, ev.shiftKey), 0);
          });
        } else {
          setTimeout(()=> focusNeighbor(el, ev.shiftKey), 0);
        }
      }
      if (ev.key === 'Escape') {
        ev.preventDefault();
        el.textContent = el.dataset.prev || oldVal;
        el.blur();
      }
    });

    el.addEventListener('blur', () => {
      const cell = el.closest('td.cell');
      const field = cell.dataset.field;
      const cid = parseInt(cell.dataset.id, 10);
      const newVal = cleanText(el.textContent);
      if (newVal === cleanText(oldVal)) return;

      if (field === 'hours') {
        if (newVal === '' || isNaN(newVal) || parseInt(newVal,10) < 1) {
          el.textContent = oldVal; cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
          notify('warning','Fusha “Orë” duhet të jetë numër i plotë ≥ 1.');
          return;
        }
      }
      if (field === 'code' && newVal === '') {
        el.textContent = oldVal; cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
        notify('warning','Kodi nuk mund të jetë bosh.');
        return;
      }
      if (field === 'name' && newVal === '') {
        el.textContent = oldVal; cell.classList.add('cell-err'); setTimeout(()=>cell.classList.remove('cell-err'), 1200);
        notify('warning','Emri nuk mund të jetë bosh.');
        return;
      }

      saveInline(cid, field, newVal, cell, el);
    });
  });
}

/* Modal fshirjeje i ripërdorshëm */
const deleteModal = document.getElementById('deleteCourseModal');
if (deleteModal) {
  deleteModal.addEventListener('show.bs.modal', event => {
    const button = event.relatedTarget;
    if (!button || button.hasAttribute('disabled')) { event.preventDefault(); return; }
    const id   = button.getAttribute('data-course-id');
    const code = button.getAttribute('data-course-code') || '';
    const name = button.getAttribute('data-course-name') || '';

    document.getElementById('deleteCourseId').value = id;
    document.getElementById('delCode').textContent  = code;
    document.getElementById('delName').textContent  = name;
  });
}

/* Modal: Zhvendos grupin */
const moveModal = document.getElementById('moveGroupModal');
let MV_groupId = null;
let MV_currentCourse = null;
if (moveModal){
  moveModal.addEventListener('show.bs.modal', (ev)=>{
    const btn = ev.relatedTarget;
    if (!btn || btn.hasAttribute('disabled')) { ev.preventDefault(); return; }
    MV_groupId = parseInt(btn.getAttribute('data-group-id')||'0',10)||0;
    MV_currentCourse = parseInt(btn.getAttribute('data-current-course')||'0',10)||0;

    document.getElementById('mv_group_id').value = MV_groupId;
    const sel = document.getElementById('mv_target_course');
    if (sel) {
      Array.from(sel.options).forEach(opt=>{
        if (parseInt(opt.value,10) === MV_currentCourse) opt.disabled = true;
        else opt.disabled = false;
      });
      sel.value = '';
    }
  });

  document.getElementById('mv_submit')?.addEventListener('click', async ()=>{
    const sel = document.getElementById('mv_target_course');
    const newCourse = parseInt(sel.value||'0',10)||0;
    if (!MV_groupId || !newCourse || newCourse === MV_currentCourse){
      notify('warning','Zgjidh një modul të vlefshëm.'); return;
    }
    try{
      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({
          action: 'move_group_course',
          csrf: CSRF,
          group_id: MV_groupId,
          new_course_id: newCourse
        })
      });
      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'Nuk u zhvendos.');
      // Hiq rreshtin e grupit nga lista aktuale
      const row = document.getElementById('groupRow_'+MV_groupId);
      row?.parentElement?.removeChild(row);

      // Përditëso badge e numrit të grupeve në buton
      const wrap = document.getElementById('groupsBody_'+MV_currentCourse);
      const btn = document.querySelector(`[data-bs-target="#courseGroups_${MV_currentCourse}"] .badge`);
      if (wrap && btn){
        const left = wrap.querySelectorAll('tr').length;
        btn.textContent = left;
      }

      bootstrap.Modal.getInstance(moveModal)?.hide();
      notify('success','Grupi u zhvendos me sukses.');
    }catch(err){
      notify('danger', err.message || 'Zhvendosja dështoi.');
    }
  });
}

/* Flash -> Toast sapo ngarkohet faqja */
<?php if ($ok): ?>
document.addEventListener('DOMContentLoaded',()=>notify('success', <?= json_encode($ok) ?>));
<?php endif; ?>
<?php if ($err): ?>
document.addEventListener('DOMContentLoaded',()=>notify('danger', <?= json_encode($err) ?>));
<?php endif; ?>
</script>
</body>
</html>
