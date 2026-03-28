<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';
$pdo = getPDO();

/* ============== Helpers & Output ============== */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function json_out(array $x){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($x); exit; }

/* Datë DD-MM-YYYY nga ISO YYYY-MM-DD */
function fmt_dMY(?string $iso): string {
  if (!$iso) return '—';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return h($iso);
  $ts = strtotime($iso);
  return $ts ? date('d-m-Y', $ts) : '—';
}

/* Masko ID personale (p.sh. ****123) */
function mask_id(?string $s): string {
  if(!$s) return '—';
  $len = strlen($s);
  if ($len <= 3) return str_repeat('*', max(0,$len-1)).substr($s, -1);
  return str_repeat('*', $len-3).substr($s, -3);
}

/* ============== Lexo përdoruesin e loguar (për navbar) ============== */
$currentUser = null;
$roleName = '';

if (!empty($_SESSION['user_id'])) {
  $stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = :uid
    LIMIT 1
  ");
  $stmt->execute([':uid' => $_SESSION['user_id']]);
  $currentUser = $stmt->fetch() ?: null;
  $roleName = strtolower((string)($currentUser['role_name'] ?? ''));
}

/* --------------- Parser i payload-it --------------- */
function parse_payload(string $raw): array {
  $raw = trim($raw);
  $sid = 0; $pid = 0; $token = '';

  // 1) URL me sid/pid & t/token
  if (filter_var($raw, FILTER_VALIDATE_URL)) {
    $q = parse_url($raw, PHP_URL_QUERY);
    parse_str($q ?? '', $arr);
    if (!empty($arr['sid']) && ctype_digit((string)$arr['sid'])) $sid = (int)$arr['sid'];
    if (!empty($arr['pid']) && ctype_digit((string)$arr['pid'])) $pid = (int)$arr['pid'];
    $token = (string)($arr['t'] ?? $arr['token'] ?? '');
    return ['sid'=>$sid, 'pid'=>$pid, 'token'=>trim($token)];
  }

  // 2) QTA|SID:...|TOKEN:... ose QTA|PID:...|TOKEN:...
  if (stripos($raw, 'QTA|') === 0 || str_contains($raw, 'SID:') || str_contains($raw, 'PID:') || str_contains($raw, 'TOKEN:')) {
    if (preg_match('/SID\s*:\s*(\d+)/i', $raw, $m)) $sid = (int)$m[1];
    if (preg_match('/PID\s*:\s*(\d+)/i', $raw, $m)) $pid = (int)$m[1];
    if (preg_match('/TOKEN\s*:\s*([a-f0-9]{16,})/i', $raw, $m)) $token = strtolower($m[1]);
    return ['sid'=>$sid, 'pid'=>$pid, 'token'=>$token];
  }

  // 3) JSON {sid|pid, token}
  if (($raw[0]??'') === '{') {
    $j = json_decode($raw, true);
    if (is_array($j)) {
      if (isset($j['sid']) && ctype_digit((string)$j['sid'])) $sid=(int)$j['sid'];
      if (isset($j['pid']) && ctype_digit((string)$j['pid'])) $pid=(int)$j['pid'];
      if (isset($j['token']) && is_string($j['token'])) $token=trim($j['token']);
      return ['sid'=>$sid, 'pid'=>$pid, 'token'=>$token];
    }
  }

  // 4) “PID|TOKEN” ose “SID|TOKEN”
  if (str_contains($raw,'|')) {
    [$a,$b] = array_map('trim', explode('|',$raw,2));
    if (strcasecmp($a,'PID')===0){ $pid = (int)preg_replace('/\D/','', $b); return ['sid'=>0,'pid'=>$pid,'token'=>$b]; }
    if (strcasecmp($a,'SID')===0){ $sid = (int)preg_replace('/\D/','', $b); return ['sid'=>$sid,'pid'=>0,'token'=>$b]; }
    if (ctype_digit($a)) { $sid = (int)$a; $token=$b; return ['sid'=>$sid,'pid'=>0,'token'=>$token]; }
  }

  return ['sid'=>0, 'pid'=>0, 'token'=>''];
}

/* --------------- Verifikimi: STUDENT --------------- */
function verify_student(PDO $pdo, int $sid, string $token): array {
  if ($sid<=0 || $token==='') return ['valid'=>false, 'reason'=>'Mungon SID ose token.'];

  $chk = $pdo->prepare("SELECT 1 FROM student_qr_tokens WHERE student_id=:sid AND token=:t LIMIT 1");
  $chk->execute([':sid'=>$sid, ':t'=>$token]);
  if (!$chk->fetchColumn()) return ['valid'=>false, 'reason'=>'Token i pavlefshëm ose nuk përputhet me këtë student.'];

  $sql = "
    SELECT
      s.id, s.nr_amze, u.created_at,
      el.label AS edu_label,
      ajs.agency_id, ag.company_name AS agency_name,
      p.first_name, p.father_name, p.last_name, p.personal_number
    FROM students s
    JOIN users u    ON u.id = s.user_id
    JOIN persons p  ON p.id = s.person_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    LEFT JOIN agency_students ajs  ON ajs.student_id = s.id
    LEFT JOIN agencies ag          ON ag.id = ajs.agency_id
    WHERE s.id = :sid
    LIMIT 1
  ";
  $st = $pdo->prepare($sql); $st->execute([':sid'=>$sid]);
  $S = $st->fetch(PDO::FETCH_ASSOC);
  if (!$S) return ['valid'=>false, 'reason'=>'Studenti nuk u gjet.'];

  // # Modulet (UNION: cgs + scp)
  $qCourses = $pdo->prepare("
    SELECT COUNT(*) FROM (
      SELECT DISTINCT c.id
      FROM course_group_students cgs
      JOIN course_groups cg ON cg.id = cgs.group_id
      JOIN courses c        ON c.id  = cg.course_id
      WHERE cgs.student_id = :sid1
      UNION
      SELECT DISTINCT c.id
      FROM student_course_plans scp
      JOIN courses c ON c.id = scp.course_id
      WHERE scp.student_id = :sid2
    ) x
  ");
  $qCourses->execute([':sid1'=>$sid, ':sid2'=>$sid]);
  $coursesCnt = (int)$qCourses->fetchColumn();

  // # Grupe
  $qGroups = $pdo->prepare("SELECT COUNT(*) FROM course_group_students WHERE student_id=:sid");
  $qGroups->execute([':sid'=>$sid]); $groupsCnt = (int)$qGroups->fetchColumn();

  // Mesatare / kalueshmëri
  $qAvg = $pdo->prepare("SELECT AVG(final_score) FROM course_group_students WHERE student_id=:sid AND final_score IS NOT NULL");
  $qAvg->execute([':sid'=>$sid]); $avg = $qAvg->fetchColumn();
  $avgScore = $avg!==null ? round((float)$avg,1) : null;

  $qPR = $pdo->prepare("
    SELECT SUM(CASE WHEN final_score>=50 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0) * 100
    FROM course_group_students WHERE student_id=:sid AND final_score IS NOT NULL
  ");
  $qPR->execute([':sid'=>$sid]); $pr = $qPR->fetchColumn();
  $passRate = $pr!==null ? round((float)$pr,1) : null;

  // Lista kurseve (max 5)
  $qList = $pdo->prepare("
    SELECT id, code, name FROM (
      SELECT DISTINCT c.id, c.code, c.name
      FROM course_group_students cgs
      JOIN course_groups cg ON cg.id = cgs.group_id
      JOIN courses c        ON c.id  = cg.course_id
      WHERE cgs.student_id = :sid1
      UNION
      SELECT DISTINCT c.id, c.code, c.name
      FROM student_course_plans scp
      JOIN courses c ON c.id = scp.course_id
      WHERE scp.student_id = :sid2
    ) xx
    ORDER BY name ASC
    LIMIT 5
  ");
  $qList->execute([':sid1'=>$sid, ':sid2'=>$sid]);
  $coursesList = $qList->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Modulet & grupet (vetëm për listim, do të shfaqim vetëm AMZË + Moduli)
  $qEnr = $pdo->prepare("
    SELECT * FROM (
      SELECT
        cg.id     AS group_id,
        c.code    AS course_code,
        c.name    AS course_name,
        s.nr_amze AS amze,
        'group'   AS row_kind
      FROM course_group_students cgs
      JOIN course_groups cg ON cg.id = cgs.group_id
      JOIN courses c        ON c.id  = cg.course_id
      JOIN students s       ON s.id  = cgs.student_id
      WHERE cgs.student_id = :sid1

      UNION ALL

      SELECT
        NULL        AS group_id,
        c.code      AS course_code,
        c.name      AS course_name,
        s.nr_amze   AS amze,
        'planned'   AS row_kind
      FROM student_course_plans scp
      JOIN students s ON s.id = scp.student_id
      JOIN courses  c ON c.id = scp.course_id
      WHERE scp.student_id = :sid2
        AND scp.group_id IS NULL
        AND scp.status = 'planned'
    ) t
    ORDER BY t.group_id DESC, CAST(t.amze AS UNSIGNED) ASC, t.amze ASC
    LIMIT 160
  ");
  $qEnr->execute([':sid1'=>$sid, ':sid2'=>$sid]);
  $groups = $qEnr->fetchAll(PDO::FETCH_ASSOC) ?: [];

  return [
    'valid'=>true,
    'kind'=>'student',
    'student'=>[
      'id'=>(int)$S['id'],
      'amze'=>$S['nr_amze'],
      'first_name'=>$S['first_name'],
      'father_name'=>$S['father_name'],
      'last_name'=>$S['last_name'],
      'personal_number_masked'=>mask_id($S['personal_number'] ?? null),
      'edu_label'=>$S['edu_label'] ?? null,
      'agency'=>$S['agency_name'] ?? null,
      'created_at'=>$S['created_at'] ?? null,
      'stats'=>[
        'courses'=>$coursesCnt,
        'groups'=>$groupsCnt,
        'avg_score'=>$avgScore,
        'pass_rate'=>$passRate
      ],
      'courses_list'=>$coursesList,
      'groups'=>$groups
    ]
  ];
}

/* --------------- Verifikimi: PERSON --------------- */
function verify_person(PDO $pdo, int $pid, string $token): array {
  if ($pid<=0 || $token==='') return ['valid'=>false, 'reason'=>'Mungon PID ose token.'];

  $chk = $pdo->prepare("SELECT 1 FROM person_qr_tokens WHERE person_id=:pid AND token=:t LIMIT 1");
  $chk->execute([':pid'=>$pid, ':t'=>$token]);
  if (!$chk->fetchColumn()) return ['valid'=>false, 'reason'=>'Token i pavlefshëm ose nuk përputhet me këtë person.'];

  // Person + lista e AMZË-ve
  $p = $pdo->prepare("
    SELECT p.id, p.first_name, p.father_name, p.last_name, p.personal_number
    FROM persons p WHERE p.id = :pid LIMIT 1
  ");
  $p->execute([':pid'=>$pid]);
  $P = $p->fetch(PDO::FETCH_ASSOC);
  if (!$P) return ['valid'=>false, 'reason'=>'Personi nuk u gjet.'];

  $st = $pdo->prepare("
    SELECT s.id, s.nr_amze, el.label AS edu_label,
           ag.company_name AS agency_name
    FROM students s
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    LEFT JOIN agency_students ajs  ON ajs.student_id = s.id
    LEFT JOIN agencies ag          ON ag.id = ajs.agency_id
    WHERE s.person_id = :pid
    ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  ");
  $st->execute([':pid'=>$pid]);
  $students = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $ids = array_map(fn($r)=> (int)$r['id'], $students);
  $stats = ['courses'=>0,'groups'=>0,'avg_score'=>null,'pass_rate'=>null];
  $coursesList = [];
  $groups = [];

  if ($ids){
    $ph = implode(',', array_fill(0,count($ids),'?'));

    // # Modulet (UNION: cgs + scp)
    $q1 = $pdo->prepare("
      SELECT COUNT(*) FROM (
        SELECT DISTINCT c.id
        FROM course_group_students cgs
        JOIN course_groups cg ON cg.id = cgs.group_id
        JOIN courses c        ON c.id  = cg.course_id
        WHERE cgs.student_id IN ($ph)
        UNION
        SELECT DISTINCT c.id
        FROM student_course_plans scp
        JOIN courses c ON c.id = scp.course_id
        WHERE scp.student_id IN ($ph)
      ) xx
    ");
    $q1->execute(array_merge($ids,$ids));
    $stats['courses'] = (int)$q1->fetchColumn();

    // # Grupe
    $q2 = $pdo->prepare("SELECT COUNT(*) FROM course_group_students WHERE student_id IN ($ph)");
    $q2->execute($ids);
    $stats['groups'] = (int)$q2->fetchColumn();

    // Mesatare / kalueshmëri
    $q3 = $pdo->prepare("
      SELECT AVG(final_score)
      FROM course_group_students
      WHERE student_id IN ($ph) AND final_score IS NOT NULL
    ");
    $q3->execute($ids);
    $avg = $q3->fetchColumn();
    $stats['avg_score'] = $avg!==null ? round((float)$avg,1) : null;

    $q4 = $pdo->prepare("
      SELECT SUM(CASE WHEN final_score>=50 THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0) * 100
      FROM course_group_students
      WHERE student_id IN ($ph) AND final_score IS NOT NULL
    ");
    $q4->execute($ids);
    $pr = $q4->fetchColumn();
    $stats['pass_rate'] = $pr!==null ? round((float)$pr,1) : null;

    // Lista kurseve (max 6)
    $q5 = $pdo->prepare("
      SELECT id, code, name FROM (
        SELECT DISTINCT c.id, c.code, c.name
        FROM course_group_students cgs
        JOIN course_groups cg ON cg.id = cgs.group_id
        JOIN courses c        ON c.id  = cg.course_id
        WHERE cgs.student_id IN ($ph)
        UNION
        SELECT DISTINCT c.id, c.code, c.name
        FROM student_course_plans scp
        JOIN courses c ON c.id = scp.course_id
        WHERE scp.student_id IN ($ph)
      ) xxx
      ORDER BY name ASC
      LIMIT 6
    ");
    $q5->execute(array_merge($ids,$ids));
    $coursesList = $q5->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Modulet & grupet (vetëm për listim, do të shfaqim vetëm AMZË + Moduli)
    $q6 = $pdo->prepare("
      SELECT * FROM (
        SELECT
          cg.id     AS group_id,
          c.code    AS course_code,
          c.name    AS course_name,
          s.nr_amze AS amze,
          'group'   AS row_kind
        FROM course_group_students cgs
        JOIN course_groups cg ON cg.id = cgs.group_id
        JOIN courses c        ON c.id  = cg.course_id
        JOIN students s       ON s.id  = cgs.student_id
        WHERE cgs.student_id IN ($ph)

        UNION ALL

        SELECT
          NULL        AS group_id,
          c.code      AS course_code,
          c.name      AS course_name,
          s.nr_amze   AS amze,
          'planned'   AS row_kind
        FROM student_course_plans scp
        JOIN students s ON s.id = scp.student_id
        JOIN courses  c ON c.id = scp.course_id
        WHERE scp.student_id IN ($ph)
          AND scp.group_id IS NULL
          AND scp.status = 'planned'
      ) t
      ORDER BY t.group_id DESC, CAST(t.amze AS UNSIGNED) ASC, t.amze ASC
      LIMIT 220
    ");
    $q6->execute(array_merge($ids, $ids));
    $groups = $q6->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  return [
    'valid'=>true,
    'kind'=>'person',
    'person'=>[
      'id'=>(int)$P['id'],
      'first_name'=>$P['first_name'],
      'father_name'=>$P['father_name'],
      'last_name'=>$P['last_name'],
      'personal_number_masked'=>mask_id($P['personal_number'] ?? null),
      'students'=>array_map(fn($r)=>[
        'id'=>(int)$r['id'], 'amze'=>$r['nr_amze'],
        'edu_label'=>$r['edu_label'] ?? null,
        'agency'=>$r['agency_name'] ?? null
      ], $students),
      'stats'=>$stats,
      'courses_list'=>$coursesList,
      'groups'=>$groups
    ]
  ];
}

/* ==================== API JSON (POST) ==================== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  $raw = file_get_contents('php://input');
  $json = (stripos($ct,'application/json')!==false) ? json_decode($raw,true) : $_POST;

  $action = $json['action'] ?? '';
  if ($action==='verify') {
    // Prefero parametrat direkt nga query nëse ekzistojnë
    $sid = isset($_GET['sid']) && ctype_digit((string)$_GET['sid']) ? (int)$_GET['sid'] : 0;
    $pid = isset($_GET['pid']) && ctype_digit((string)$_GET['pid']) ? (int)$_GET['pid'] : 0;
    $token = trim((string)($_GET['t'] ?? ($json['token'] ?? '')));
    $payload = trim((string)($json['payload'] ?? ''));

    if ((!$sid && !$pid) || !$token) {
      $p = parse_payload($payload);
      $sid = $sid ?: (int)$p['sid'];
      $pid = $pid ?: (int)$p['pid'];
      $token = $token ?: (string)$p['token'];
    }

    if ($pid>0 && $token!=='') {
      $res = verify_person($pdo, $pid, $token);
      json_out(['ok'=>true] + $res);
    } elseif ($sid>0 && $token!=='') {
      $res = verify_student($pdo, $sid, $token);
      json_out(['ok'=>true] + $res);
    } else {
      json_out(['ok'=>true, 'valid'=>false, 'reason'=>'Nuk u gjet as PID as SID ose mungon token.']);
    }
  }
  json_out(['ok'=>false, 'error'=>'Veprim i panjohur.']);
}

/* ==================== GET: Prefill (opsionale) ==================== */
$prefillResult = null;
if (isset($_GET['pid'], $_GET['t']) || isset($_GET['sid'], $_GET['t'])) {
  $pid = isset($_GET['pid']) && ctype_digit((string)$_GET['pid']) ? (int)$_GET['pid'] : 0;
  $sid = isset($_GET['sid']) && ctype_digit((string)$_GET['sid']) ? (int)$_GET['sid'] : 0;
  $token = trim((string)$_GET['t']);
  if ($pid>0)      $prefillResult = verify_person($pdo, $pid, $token);
  elseif ($sid>0)  $prefillResult = verify_student($pdo, $sid, $token);
}

$NAV_ACTIVE = 'verify';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <title>Verifikim – QTA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>

  <!-- QR lib -->
  <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

  <style>
    :root{
      --bg:#f6f8ff;
      --bg2:#eef2ff;
      --card:#ffffff;
      --text:#0b1220;
      --muted:#667085;
      --muted2:#94a3b8;

      --primary:#2563eb;
      --primary2:#4f46e5;
      --accent:#0ea5e9;

      --success:#10b981;
      --danger:#ef4444;
      --warning:#f59e0b;

      --ring: rgba(37,99,235,.16);
      --shadow: 0 18px 44px rgba(2,6,23,.12);
      --shadow2: 0 10px 26px rgba(2,6,23,.10);
      --radius: 18px;
    }

    html[data-theme="dark"]{
      --bg:#070b14;
      --bg2:#0b1220;
      --card:#0f172a;
      --text:#e5e7eb;
      --muted:#a7b0c0;
      --muted2:#7c879b;
      --ring: rgba(99,102,241,.18);
      --shadow: 0 20px 54px rgba(0,0,0,.45);
      --shadow2: 0 12px 32px rgba(0,0,0,.35);
    }

    body{
      background: radial-gradient(1200px 520px at 15% -12%, rgba(37,99,235,.18), transparent 60%),
                  radial-gradient(900px 420px at 90% -10%, rgba(99,102,241,.16), transparent 55%),
                  linear-gradient(180deg, var(--bg) 0%, var(--bg2) 60%, var(--bg) 100%);
      color: var(--text);
    }

    .container-max{ max-width: 1180px; }

    .soft-card{
      background: var(--card);
      border: 1px solid rgba(148,163,184,.18);
      border-radius: var(--radius);
      box-shadow: var(--shadow2);
    }

    .hero{
      position:relative;
      overflow:hidden;
      padding: 34px 0 18px;
    }
    .hero::before{
      content:"";
      position:absolute;
      inset:-2px;
      background:
        radial-gradient(900px 360px at 15% 0%, rgba(14,165,233,.25), transparent 60%),
        radial-gradient(800px 300px at 85% 10%, rgba(99,102,241,.24), transparent 60%),
        linear-gradient(135deg, rgba(37,99,235,.08), rgba(79,70,229,.06));
      pointer-events:none;
    }
    .hero-inner{ position:relative; z-index:1; }

    .badge-chip{
      border: 1px solid rgba(148,163,184,.25);
      background: rgba(255,255,255,.55);
      color: var(--text);
    }
    html[data-theme="dark"] .badge-chip{ background: rgba(15,23,42,.6); }

    .text-muted2{ color: var(--muted); }

    .mini-pill{
      display:inline-flex;
      gap:.5rem;
      align-items:center;
      padding:.35rem .6rem;
      border-radius: 999px;
      border:1px solid rgba(148,163,184,.22);
      background: rgba(255,255,255,.6);
      font-size: .85rem;
      color: var(--text);
    }
    html[data-theme="dark"] .mini-pill{ background: rgba(15,23,42,.65); }

    .btn, .form-control, .input-group-text, .nav-pills .nav-link{ border-radius: 14px; }

    .input-group-text{
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.55);
      color: var(--text);
    }
    html[data-theme="dark"] .input-group-text{ background: rgba(15,23,42,.65); }

    .form-control{
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.55);
      color: var(--text);
    }
    html[data-theme="dark"] .form-control{ background: rgba(15,23,42,.65); }

    .form-control:focus{
      border-color: rgba(37,99,235,.35);
      box-shadow: 0 0 0 10px var(--ring);
      background: rgba(255,255,255,.75);
    }
    html[data-theme="dark"] .form-control:focus{ background: rgba(15,23,42,.75); }

    .nav-pills .nav-link{
      background: rgba(255,255,255,.55);
      border: 1px solid rgba(148,163,184,.18);
      color: var(--text);
    }
    html[data-theme="dark"] .nav-pills .nav-link{ background: rgba(15,23,42,.65); }

    .nav-pills .nav-link.active{
      background: rgba(37,99,235,.10);
      border-color: rgba(37,99,235,.28);
      box-shadow: 0 0 0 10px var(--ring);
      color: var(--text);
    }

    .btn-soft{
      background: rgba(255,255,255,.55);
      border: 1px solid rgba(148,163,184,.18);
      color: var(--text);
    }
    html[data-theme="dark"] .btn-soft{ background: rgba(15,23,42,.65); }

    .btn-accent{
      background: var(--primary2);
      border: 1px solid rgba(79,70,229,.35);
      color:#fff;
    }
    .btn-accent:hover{ filter: brightness(.97); }

    /* Reader */
    #reader{ width:100%; height:340px; }
    #reader > div{ border-radius: 16px !important; overflow:hidden; }
    #reader video{ width:100% !important; height:100% !important; object-fit:cover; border-radius: 16px; }

    /* Status banner */
    .status-banner{
      display:flex;
      align-items:center;
      gap:.7rem;
      padding: 12px 14px;
      border-radius: 16px;
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.55);
      box-shadow: var(--shadow2);
    }
    html[data-theme="dark"] .status-banner{ background: rgba(15,23,42,.65); }

    .status-banner.status-valid{ border-left: 6px solid var(--success); }
    .status-banner.status-invalid{ border-left: 6px solid var(--danger); }
    .status-banner.status-pending{ border-left: 6px solid rgba(148,163,184,.7); }

    .fact{
      background: rgba(255,255,255,.55);
      border: 1px solid rgba(148,163,184,.18);
      border-radius: 16px;
      padding: .85rem;
    }
    html[data-theme="dark"] .fact{ background: rgba(15,23,42,.65); }

    .table-min th, .table-min td { padding:.55rem .75rem; }

    .tab-content{ min-height: 420px; }

    @media (max-width: 991px){
      #reader{ height: 300px; }
    }

    @media print {
      .navbar, .hero, #controlsBar, .nav, .btn, footer, #topActions { display:none !important; }
      .soft-card, .status-banner { box-shadow:none !important; }
      body { background:#fff !important; }
    }
  </style>
</head>
<body>

<?php
/* Navbar kryesor */
require_once __DIR__ . '/navbarMain.php';
?>

<!-- HERO -->
<section class="hero">
  <div class="container container-max hero-inner">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div>
        <span class="badge badge-chip rounded-pill mb-2">
          <i class="bi bi-upc-scan me-1"></i> Verifikim publik • QTA
        </span>
        <h1 class="h3 fw-bold mb-1">Verifikim i certifikatave</h1>
        <div class="text-muted2">
          Mbështet QR të <strong>studentit (SID)</strong> dhe <strong>personit (PID)</strong>. Rezultati shfaqet në kohë reale.
        </div>

        <div class="mt-2 d-flex flex-wrap gap-2">
          <span class="mini-pill"><i class="bi bi-shield-check"></i> Anti-abuzim</span>
          <span class="mini-pill"><i class="bi bi-camera-video"></i> Kamera / Foto</span>
          <span class="mini-pill"><i class="bi bi-link-45deg"></i> Link i ndashëm</span>
        </div>
      </div>

      <div class="d-flex gap-2 align-items-center" id="topActions">
        <a href="index.php" class="btn btn-soft">
          <i class="bi bi-house me-1"></i> Home
        </a>
        <button type="button" class="btn btn-soft" id="themeToggle" aria-label="Ndrysho temën">
          <i class="bi bi-moon-stars me-1"></i> Dark mode
        </button>
      </div>
    </div>
  </div>
</section>

<br>

<div class="container container-max pb-5">
  <div class="row g-4">

    <!-- LEFT: Tools -->
    <div class="col-lg-5">
      <div class="soft-card p-4 h-100">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div class="fw-semibold"><i class="bi bi-tools me-1"></i> Mjetet e verifikimit</div>
          <span class="badge text-bg-secondary">Publike</span>
        </div>

        <ul class="nav nav-pills mb-3" role="tablist">
          <li class="nav-item me-1" role="presentation">
            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pane-camera" type="button" role="tab">
              <i class="bi bi-camera-video me-1"></i>Kamera
            </button>
          </li>
          <li class="nav-item me-1" role="presentation">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-upload" type="button" role="tab">
              <i class="bi bi-image me-1"></i>Foto
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-manual" type="button" role="tab">
              <i class="bi bi-clipboard-check me-1"></i>URL / Tekst
            </button>
          </li>
        </ul>

        <div class="tab-content">
          <!-- Kamera -->
          <div class="tab-pane fade show active" id="pane-camera" role="tabpanel">
            <div class="fact p-2 mb-2">
              <div id="reader" aria-live="polite"></div>
            </div>

            <div id="controlsBar" class="d-flex align-items-center gap-2 flex-wrap">
              <div class="input-group input-group-sm" style="max-width:100%;">
                <span class="input-group-text"><i class="bi bi-camera"></i></span>
                <select id="camSelect" class="form-select"></select>
                <button class="btn btn-soft" id="btnFlip" type="button"><i class="bi bi-arrow-left-right me-1"></i>Kalo</button>
                <button class="btn btn-soft" id="btnStopCam" type="button"><i class="bi bi-stop-circle me-1"></i>Ndalo</button>
              </div>
              <div class="small text-muted2 ms-auto">Sugjerim: “Back/Rear” për fokus më të mirë.</div>
            </div>
          </div>

          <!-- Foto -->
          <div class="tab-pane fade" id="pane-upload" role="tabpanel">
            <div id="file-reader" class="d-none"></div>

            <div class="fact text-center mb-2">
              <i class="bi bi-image fs-4 d-block mb-2"></i>
              Ngarko një foto që përmban QR:
              <label class="btn btn-accent ms-2">
                Zgjidh skedar
                <input id="fileInput" type="file" accept="image/*" hidden>
              </label>
              <div class="text-muted2 mt-2">Mbështetur: JPG, PNG, WEBP…</div>
            </div>

            <div class="d-flex gap-2 align-items-center">
              <button id="btnPasteImage" class="btn btn-soft" type="button">
                <i class="bi bi-clipboard2-check me-1"></i>Ngjit nga Clipboard
              </button>
              <div id="uploadMsg" class="small text-muted2 ms-auto"></div>
            </div>
          </div>

          <!-- Manual -->
          <div class="tab-pane fade" id="pane-manual" role="tabpanel">
            <div class="input-group mb-2">
              <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
              <input id="manualPayload" type="text" class="form-control" placeholder="Ngjit këtu stringun ose URL-në e QR">
            </div>

            <div class="d-flex gap-2 mb-2">
              <button id="btnVerify" class="btn btn-accent flex-fill" type="button">
                <i class="bi bi-shield-check me-1"></i>Verifiko
              </button>
              <button id="btnPaste" class="btn btn-soft" type="button" title="Ngjit tekst">
                <i class="bi bi-clipboard2"></i>
              </button>
              <button id="btnClear" class="btn btn-soft" type="button">Pastro</button>
            </div>

            <div class="text-muted2 small">
              Pranohet: URL me <code>?sid=&amp;t=</code> ose <code>?pid=&amp;t=</code>,
              <code>QTA|SID:..|TOKEN:..</code>, <code>QTA|PID:..|TOKEN:..</code>, JSON {"sid|pid","token"},
              ose <code>SID|TOKEN</code>/<code>PID|TOKEN</code>.
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="alert alert-warning mb-0">
          <strong>E rëndësishme:</strong> Nëse emri në ekran <b>NUK</b> përputhet me emrin në certifikatën fizike,
          mund të jetë certifikatë e kopjuar. Njoftoni:
          <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a> · <a href="tel:+355698778837">+355 69 877 8837</a>.
        </div>
      </div>
    </div>

    <!-- RIGHT: Result -->
    <div class="col-lg-7">
      <?php
        $statusClass = 'status-pending';
        $statusLabel = 'Gati për verifikim';
        if ($prefillResult){
          $statusClass = $prefillResult['valid'] ? 'status-valid' : 'status-invalid';
          $statusLabel = $prefillResult['valid'] ? 'VALID' : 'INVALID';
        }
      ?>

      <div class="status-banner mb-3 <?= h($statusClass) ?>">
        <i id="statusIcon" class="bi fs-5 <?= $prefillResult ? ($prefillResult['valid']?'bi-check2-circle text-success':'bi-x-circle text-danger') : 'bi-shield-lock text-secondary' ?>"></i>
        <div class="fw-semibold">Statusi:</div>
        <div id="statusText"><?= h($statusLabel) ?></div>

        <div class="ms-auto d-flex gap-2">
          <button class="btn btn-soft btn-sm" id="btnShare" type="button" title="Krijo link verifikimi">
            <i class="bi bi-link-45deg"></i>
          </button>
          <button class="btn btn-soft btn-sm" id="btnPrint" type="button" title="Printo rezultatin">
            <i class="bi bi-printer"></i>
          </button>
        </div>
      </div>

      <div class="soft-card p-0">
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom" style="border-color: rgba(148,163,184,.18) !important;">
          <div class="fw-semibold"><i class="bi bi-patch-check me-1"></i> Rezultati i verifikimit</div>
          <span id="statusChip" class="badge <?= $prefillResult ? ($prefillResult['valid']?'text-bg-success':'text-bg-danger') : 'text-bg-secondary' ?>">
            <?= $prefillResult ? ($prefillResult['valid']?'VALID':'INVALID') : '—' ?>
          </span>
        </div>

        <div class="p-4" id="resultBody" aria-live="polite">
          <?php if ($prefillResult): ?>
            <?php if ($prefillResult['valid']):
              if (($prefillResult['kind'] ?? '') === 'person'):
                $pr = $prefillResult['person'];
                $full = trim(($pr['first_name']??'').' '.(($pr['father_name']??'')?($pr['father_name'].' '):'').($pr['last_name']??'')); ?>
                <div class="d-flex align-items-center mb-3">
                  <div class="me-3" style="width:52px;height:52px;border-radius:16px;background:rgba(37,99,235,.10);border:1px solid rgba(37,99,235,.18);display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-people fs-4" style="color:var(--primary)"></i>
                  </div>
                  <div>
                    <div class="h5 mb-0"><?= h($full ?: '—') ?></div>
                    <div class="text-muted2 small">ID personale: <?= h($pr['personal_number_masked'] ?? '—') ?></div>
                  </div>
                </div>

                <div class="row g-3 mb-3">
                  <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Modulet</div><div class="h5 mb-0"><?= (int)($pr['stats']['courses'] ?? 0) ?></div></div></div>
                  <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Grupe</div><div class="h5 mb-0"><?= (int)($pr['stats']['groups'] ?? 0) ?></div></div></div>
                  <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Mes. pikë</div><div class="h5 mb-0"><?= $pr['stats']['avg_score']!==null ? $pr['stats']['avg_score'] : '—' ?></div></div></div>
                  <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Kalueshmëria</div><div class="h5 mb-0"><?= $pr['stats']['pass_rate']!==null ? ($pr['stats']['pass_rate'].'%') : '—' ?></div></div></div>
                </div>

                <div class="mt-3">
                  <div class="text-muted2 small mb-1">Modulet & grupet</div>
                  <?php if (!empty($pr['groups'])): ?>
                    <div class="table-responsive">
                      <table class="table table-sm table-min align-middle mb-0">
                        <thead class="table-light">
                          <tr><th>AMZË</th><th>Moduli</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pr['groups'] as $g): ?>
                          <tr>
                            <td class="text-nowrap"><?= h($g['amze'] ?? '—') ?></td>
                            <td><?= h(($g['course_code'] ?? '').($g['course_code']?' · ':'').($g['course_name'] ?? '')) ?></td>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php else: ?>
                    <div class="text-muted2">Nuk ka ende të dhëna.</div>
                  <?php endif; ?>
                </div>

              <?php else:
                $st = $prefillResult['student'];
                $full = trim(($st['first_name']??'').' '.(($st['father_name']??'')?($st['father_name'].' '):'').($st['last_name']??'')); ?>
                <div class="d-flex align-items-center mb-3">
                  <div class="me-3" style="width:52px;height:52px;border-radius:16px;background:rgba(14,165,233,.10);border:1px solid rgba(14,165,233,.18);display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-person-badge fs-4" style="color:var(--accent)"></i>
                  </div>
                  <div>
                    <div class="h5 mb-0"><?= h($full ?: '—') ?></div>
                    <div class="text-muted2 small">
                      AMZË: <strong><?= h($st['amze'] ?? '—') ?></strong> • ID personale: <?= h($st['personal_number_masked'] ?? '—') ?>
                    </div>
                  </div>
                </div>

                <div class="row g-3">
                  <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Modulet</div><div class="h5 mb-0"><?= (int)$st['stats']['courses'] ?></div></div></div>
                  <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Grupe</div><div class="h5 mb-0"><?= (int)$st['stats']['groups'] ?></div></div></div>
                  <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Mes. pikë</div><div class="h5 mb-0"><?= $st['stats']['avg_score']!==null ? $st['stats']['avg_score'] : '—' ?></div></div></div>
                  <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Kalueshmëria</div><div class="h5 mb-0"><?= $st['stats']['pass_rate']!==null ? ($st['stats']['pass_rate'].'%') : '—' ?></div></div></div>
                </div>

                <div class="mt-3">
                  <div class="text-muted2 small mb-1">Modulet & grupet</div>
                  <?php if (!empty($st['groups'])): ?>
                    <div class="table-responsive">
                      <table class="table table-sm table-min align-middle mb-0">
                        <thead class="table-light">
                          <tr><th>AMZË</th><th>Moduli</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($st['groups'] as $g): ?>
                          <tr>
                            <td class="text-nowrap"><?= h($g['amze'] ?? ($st['amze'] ?? '—')) ?></td>
                            <td><?= h(($g['course_code'] ?? '').($g['course_code']?' · ':'').($g['course_name'] ?? '')) ?></td>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php else: ?>
                    <div class="text-muted2">Nuk ka ende të dhëna.</div>
                  <?php endif; ?>
                </div>

                <hr style="border-color: rgba(148,163,184,.18)">

                <div class="row">
                  <div class="col-md-6">
                    <div class="text-muted2 small">Edukimi</div>
                    <div class="fw-semibold mb-3"><?= h($st['edu_label'] ?? '—') ?></div>
                  </div>
                  <div class="col-md-6">
                    <div class="text-muted2 small">Agjencia</div>
                    <div class="fw-semibold mb-3"><?= h($st['agency'] ?? '—') ?></div>
                  </div>
                </div>

                <div class="text-muted2 small">Kurset (max 5):</div>
                <ul class="list-group list-group-flush">
                  <?php if (!empty($st['courses_list'])): foreach ($st['courses_list'] as $c): ?>
                    <li class="list-group-item">
                      <i class="bi bi-mortarboard me-2"></i><?= h(($c['code']??'').' · '.($c['name']??'')) ?>
                    </li>
                  <?php endforeach; else: ?>
                    <li class="list-group-item text-muted2">Nuk u gjet listë kursesh.</li>
                  <?php endif; ?>
                </ul>
              <?php endif; ?>
            <?php else: ?>
              <div class="alert alert-danger mb-0">
                <strong>INVALID:</strong> <?= h($prefillResult['reason'] ?? 'Token i pavlefshëm.') ?><br>
                Kontrolloni që QR të jetë i qartë dhe i plotë. Nëse dyshoni për abuzim, njoftoni QTA menjëherë.
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-muted2">
              Skanoni QR, ngarkoni foto ose ngjisni vlerën për verifikim. Rezultati do të shfaqet këtu.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ===== Theme toggle (si te faqet e reja) ===== */
const root = document.documentElement;
const themeBtn = document.getElementById('themeToggle');
const setTheme = (t) => {
  root.setAttribute('data-theme', t);
  localStorage.setItem('qta_theme', t);
  if (themeBtn){
    const isDark = (t === 'dark');
    themeBtn.innerHTML = isDark
      ? '<i class="bi bi-sun me-1"></i> Light mode'
      : '<i class="bi bi-moon-stars me-1"></i> Dark mode';
  }
};
const saved = localStorage.getItem('qta_theme');
if (saved === 'dark' || saved === 'light') setTheme(saved); else setTheme('light');
themeBtn?.addEventListener('click', () => {
  const cur = root.getAttribute('data-theme') || 'light';
  setTheme(cur === 'dark' ? 'light' : 'dark');
});

/* ========= State ========= */
let camQr = null;
let isCamRunning = false;
let isBusy = false;
let lastPayload = '';
let cameras = [];
let currentCamIndex = -1;

const statusBanner = document.querySelector('.status-banner');
const statusText   = document.getElementById('statusText');
const camSelect    = document.getElementById('camSelect');

function setStatus(label, variant){
  const chip = document.getElementById('statusChip');
  chip.className = 'badge text-bg-'+variant;
  chip.textContent = label;

  if (statusText) statusText.textContent = (label === '—' ? 'Gati për verifikim' : label);

  statusBanner?.classList.remove('status-valid','status-invalid','status-pending');
  statusBanner?.classList.add(
    variant==='success' ? 'status-valid'
    : (variant==='danger' ? 'status-invalid' : 'status-pending')
  );
}

/* ========= Camera enumeration & switching ========= */
async function enumerateCameras(){
  try {
    const list = await Html5Qrcode.getCameras();
    cameras = Array.isArray(list) ? list : [];
    camSelect.innerHTML = '';
    if (!cameras.length){
      camSelect.innerHTML = '<option value="">Kamera nuk u gjet</option>';
      return;
    }
    cameras.sort((a,b)=>{
      const la = (a.label||'').toLowerCase(), lb=(b.label||'').toLowerCase();
      const aBack = la.includes('back') || la.includes('rear');
      const bBack = lb.includes('back') || lb.includes('rear');
      if (aBack && !bBack) return -1;
      if (!aBack && bBack) return 1;
      return la.localeCompare(lb);
    });
    cameras.forEach((c,idx)=>{
      const opt = document.createElement('option');
      opt.value = String(idx);
      opt.textContent = c.label || (idx===0?'Kamera 1':'Kamera '+(idx+1));
      camSelect.appendChild(opt);
    });
    currentCamIndex = 0;
    const firstBackIdx = cameras.findIndex(c => (c.label||'').toLowerCase().includes('back') || (c.label||'').toLowerCase().includes('rear'));
    if (firstBackIdx >= 0) currentCamIndex = firstBackIdx;
    camSelect.value = String(currentCamIndex);
  } catch(e){
    camSelect.innerHTML = '<option value="">Kamera u bllokua ose nuk u gjet</option>';
  }
}

async function startCameraByIndex(idx){
  const readerEl = document.getElementById('reader');
  if (!readerEl) return;
  if (!cameras.length) await enumerateCameras();
  if (idx<0 || idx>=cameras.length) idx = 0;
  currentCamIndex = idx;
  camSelect.value = String(currentCamIndex);

  try {
    if (!camQr) camQr = new Html5Qrcode('reader');
    const deviceId = cameras[currentCamIndex]?.id;
    if (!deviceId) throw new Error('Pa deviceId');

    const config = {
      fps: 10,
      qrbox: function(viewfinderWidth, viewfinderHeight) {
        const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
        const size = Math.max(220, Math.min(340, Math.floor(minEdge * 0.82)));
        return { width: size, height: size };
      },
      rememberLastUsedCamera: false
    };

    isBusy = false;
    await camQr.start(deviceId, config, onDecode, onDecodeError);
    isCamRunning = true;
  } catch (err){
    try { await stopCamera(); } catch(e){}
    readerEl.innerHTML = '<div class="text-muted2 p-3">Kamera nuk është në dispozicion ose nuk u lejua.</div>';
    isCamRunning = false;
  }
}

async function stopCamera(){
  if (camQr && isCamRunning){
    try { await camQr.stop(); } catch(e){}
    try { await camQr.clear(); } catch(e){}
  }
  isCamRunning = false;
}

function onDecode(decodedText){
  if (isBusy) return;
  isBusy = true;
  lastPayload = decodedText;
  stopCamera();
  setStatus('Duke verifikuar...', 'secondary');
  doVerify(decodedText).finally(()=>{ isBusy = false; });
}
function onDecodeError(_err){}

/* ========= Upload / Paste ========= */
let fileQr = null;
function ensureFileReader(){ if (!fileQr){ try{ fileQr = new Html5Qrcode('file-reader'); }catch(e){} } }
function setUploadMsg(text, good=false){
  const m = document.getElementById('uploadMsg'); if (!m) return;
  m.className = good ? 'small text-success ms-auto' : 'small text-danger ms-auto';
  m.textContent = text || '';
}
async function handleFile(file){
  if(!file){ setUploadMsg('Asnjë skedar.'); return; }
  ensureFileReader();
  try{
    setUploadMsg('Duke lexuar QR nga imazhi…', true);
    const decodedText = await fileQr.scanFile(file, true);
    lastPayload = decodedText;
    document.getElementById('manualPayload').value = decodedText;
    setStatus('Duke verifikuar...', 'secondary');
    await doVerify(decodedText);
    setUploadMsg('U lexua me sukses.', true);
  } catch(err){
    setUploadMsg('Nuk u gjet QR në këtë imazh. Provo me foto më të qartë.');
  }
}
document.getElementById('fileInput')?.addEventListener('change', (ev)=>{ handleFile(ev.target.files?.[0]); });
document.getElementById('btnPasteImage')?.addEventListener('click', async ()=>{
  try{
    const items = await navigator.clipboard.read();
    for (const item of items){
      for (const type of item.types){
        if (type.startsWith('image/')){
          const blob = await item.getType(type);
          const file = new File([blob], 'clipboard.'+type.split('/')[1], {type});
          return handleFile(file);
        }
      }
    }
    setUploadMsg('Clipboard nuk përmban imazh.');
  }catch(e){ setUploadMsg('Shfletuesi nuk lejon leximin e imazhit nga clipboard.'); }
});

/* ========= Manual verify ========= */
async function doVerify(payload){
  try{
    const res = await fetch('verify.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({action:'verify', payload})
    });
    const json = await res.json();
    renderResult(json, payload);
  }catch(e){
    setStatus('INVALID', 'danger');
    document.getElementById('resultBody').innerHTML = `<div class="alert alert-danger">Gabim gjatë verifikimit. Provo përsëri.</div>`;
  }
}

function escapeHtml(s){ const d=document.createElement('div'); d.innerText=s||''; return d.innerHTML; }

function renderResult(json, payloadUsed=''){
  const body = document.getElementById('resultBody');
  if(!json || json.ok!==true){
    setStatus('INVALID', 'danger');
    body.innerHTML = `<div class="alert alert-danger">Gabim gjatë verifikimit. Provo përsëri.</div>`;
    return;
  }
  if (payloadUsed) lastPayload = payloadUsed;

  if(json.valid){
    setStatus('VALID', 'success');

    if (json.kind === 'person') {
      const pr = json.person || {};
      const full = [pr.first_name||'', pr.father_name? (pr.father_name+' ') : '', pr.last_name||''].join('').trim();

      let rows = '';
      if (Array.isArray(pr.groups) && pr.groups.length>0) {
        rows = pr.groups.map(g => {
          const amze = g.amze || '—';
          const title = (g.course_code ? g.course_code+' · ' : '') + (g.course_name || '');
          return `<tr><td class="text-nowrap">${escapeHtml(amze)}</td><td>${escapeHtml(title)}</td></tr>`;
        }).join('');
      }

      body.innerHTML = `
        <div class="d-flex align-items-center mb-3">
          <div class="me-3" style="width:52px;height:52px;border-radius:16px;background:rgba(37,99,235,.10);border:1px solid rgba(37,99,235,.18);display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-people fs-4" style="color:var(--primary)"></i>
          </div>
          <div>
            <div class="h5 mb-0">${escapeHtml(full || '—')}</div>
            <div class="text-muted2 small">ID personale: ${escapeHtml(pr.personal_number_masked||'—')}</div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Modulet</div><div class="h5 mb-0">${pr.stats?.courses ?? 0}</div></div></div>
          <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Grupe</div><div class="h5 mb-0">${pr.stats?.groups ?? 0}</div></div></div>
          <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Mes. pikë</div><div class="h5 mb-0">${pr.stats?.avg_score ?? '—'}</div></div></div>
          <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Kalueshmëria</div><div class="h5 mb-0">${(pr.stats?.pass_rate ?? null) !== null ? pr.stats.pass_rate+'%' : '—'}</div></div></div>
        </div>

        <div class="mt-3">
          <div class="text-muted2 small mb-1">Modulet & grupet</div>
          ${rows
            ? `<div class="table-responsive"><table class="table table-sm table-min align-middle mb-0">
                 <thead class="table-light"><tr><th>AMZË</th><th>Moduli</th></tr></thead>
                 <tbody>${rows}</tbody>
               </table></div>`
            : `<div class="text-muted2">Nuk ka ende të dhëna.</div>`}
        </div>
      `;

    } else { // student
      const st = json.student || {};
      const full = [st.first_name||'', st.father_name? (st.father_name+' ') : '', st.last_name||''].join('');

      let rows = '';
      if (Array.isArray(st.groups) && st.groups.length>0) {
        rows = st.groups.map(g => {
          const amze = g.amze || st.amze || '—';
          const title = (g.course_code ? g.course_code+' · ' : '') + (g.course_name || '');
          return `<tr><td class="text-nowrap">${escapeHtml(amze)}</td><td>${escapeHtml(title)}</td></tr>`;
        }).join('');
      }

      body.innerHTML = `
        <div class="d-flex align-items-center mb-3">
          <div class="me-3" style="width:52px;height:52px;border-radius:16px;background:rgba(14,165,233,.10);border:1px solid rgba(14,165,233,.18);display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-person-badge fs-4" style="color:var(--accent)"></i>
          </div>
          <div>
            <div class="h5 mb-0">${escapeHtml(full||'—')}</div>
            <div class="text-muted2 small">AMZË: <strong>${escapeHtml(st.amze||'—')}</strong> • ID personale: ${escapeHtml(st.personal_number_masked||'—')}</div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Modulet</div><div class="h5 mb-0">${st.stats?.courses ?? 0}</div></div></div>
          <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Grupe</div><div class="h5 mb-0">${st.stats?.groups ?? 0}</div></div></div>
          <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Mes. pikë</div><div class="h5 mb-0">${st.stats?.avg_score ?? '—'}</div></div></div>
          <div class="col-6 col-md-3"><div class="fact"><div class="text-muted2 small">Kalueshmëria</div><div class="h5 mb-0">${(st.stats?.pass_rate ?? null) !== null ? st.stats.pass_rate+'%' : '—'}</div></div></div>
        </div>

        <div class="mt-3">
          <div class="text-muted2 small mb-1">Modulet & grupet</div>
          ${rows
            ? `<div class="table-responsive"><table class="table table-sm table-min align-middle mb-0">
                 <thead class="table-light"><tr><th>AMZË</th><th>Moduli</th></tr></thead>
                 <tbody>${rows}</tbody>
               </table></div>`
            : `<div class="text-muted2">Nuk ka ende të dhëna.</div>`}
        </div>

        <hr style="border-color: rgba(148,163,184,.18)">

        <div class="row">
          <div class="col-md-6"><div class="text-muted2 small">Edukimi</div><div class="fw-semibold mb-3">${escapeHtml(st.edu_label||'—')}</div></div>
          <div class="col-md-6"><div class="text-muted2 small">Agjencia</div><div class="fw-semibold mb-3">${escapeHtml(st.agency||'—')}</div></div>
        </div>

        <div class="text-muted2 small">Kurset (max 5):</div>
        <ul class="list-group list-group-flush">${
          (Array.isArray(st.courses_list) && st.courses_list.length>0)
            ? st.courses_list.map(c => {
                const t = (c.code? c.code+' · ' : '') + (c.name||'');
                return `<li class="list-group-item"><i class="bi bi-mortarboard me-2"></i>${escapeHtml(t)}</li>`;
              }).join('')
            : '<li class="list-group-item text-muted2">Nuk u gjet listë kursesh.</li>'
        }</ul>
      `;
    }

  } else {
    setStatus('INVALID', 'danger');
    const reason = json.reason || 'Token i pavlefshëm.';
    body.innerHTML = `
      <div class="alert alert-danger">
        <strong>INVALID:</strong> ${escapeHtml(reason)}<br>
        Kontrolloni QR dhe provoni sërish. Nëse dyshoni për abuzim, njoftoni:
        <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a> · <a href="tel:+355698778837">+355 69 877 8837</a>.
      </div>`;
  }
}

/* ========= Buttons ========= */
document.getElementById('btnVerify')?.addEventListener('click', ()=>{
  const v = document.getElementById('manualPayload').value.trim();
  if (!v){ document.getElementById('manualPayload').focus(); return; }
  lastPayload = v;
  setStatus('Duke verifikuar...', 'secondary');
  doVerify(v);
});
document.getElementById('btnPaste')?.addEventListener('click', async ()=>{
  try{
    const t = await navigator.clipboard.readText();
    if (t){ document.getElementById('manualPayload').value = t; lastPayload = t; }
  }catch(e){}
});
document.getElementById('btnClear')?.addEventListener('click', ()=>{
  document.getElementById('manualPayload').value = '';
  lastPayload = '';
  setStatus('—','secondary');
  document.getElementById('resultBody').innerHTML = '<div class="text-muted2">Skanoni QR, ngarkoni foto ose ngjisni vlerën për verifikim. Rezultati do të shfaqet këtu.</div>';
});

/* Share link (mbështet pid/sid) */
function buildShareFromPayload(raw){
  const s = String(raw||'');
  let sid = 0, pid = 0, token = '';
  if (s.startsWith('http')){
    try{
      const url = new URL(s);
      sid = parseInt(url.searchParams.get('sid')||'0', 10) || 0;
      pid = parseInt(url.searchParams.get('pid')||'0', 10) || 0;
      token = (url.searchParams.get('t') || url.searchParams.get('token') || '').trim();
    }catch(e){}
  }
  if ((!sid && !pid) || !token){
    const mSid = s.match(/SID\s*:\s*(\d+)/i); if (mSid) sid = parseInt(mSid[1],10)||0;
    const mPid = s.match(/PID\s*:\s*(\d+)/i); if (mPid) pid = parseInt(mPid[1],10)||0;
    const mTok = s.match(/TOKEN\s*:\s*([a-f0-9]{16,})/i); if (mTok) token = mTok[1].toLowerCase();
  }
  if ((!sid && !pid) || !token){
    const parts = s.split('|');
    if (parts.length===2 && /^\d+$/.test(parts[0])) { sid = parseInt(parts[0],10); token = parts[1].trim(); }
  }
  if ((pid||sid) && token){
    const base = location.origin + location.pathname;
    const qp = pid ? `pid=${pid}` : `sid=${sid}`;
    return `${base}?${qp}&t=${encodeURIComponent(token)}`;
  }
  return '';
}
document.getElementById('btnShare')?.addEventListener('click', async ()=>{
  const link = buildShareFromPayload(lastPayload);
  if (!link){ alert('S’ka të dhëna të mjaftueshme për link. Skanoni ose ngjisni QR fillimisht.'); return; }
  try{ await navigator.clipboard.writeText(link); }catch(e){}
  const btn = document.getElementById('btnShare');
  const old = btn.innerHTML; btn.innerHTML = '<i class="bi bi-check2"></i>'; setTimeout(()=>btn.innerHTML=old, 900);
});
document.getElementById('btnPrint')?.addEventListener('click', ()=> window.print());

/* ========= Tabs: kamera on/off ========= */
document.addEventListener('shown.bs.tab', async (e)=>{
  const target = e.target?.getAttribute('data-bs-target');
  if (target === '#pane-camera'){
    await stopCamera(); await enumerateCameras(); await startCameraByIndex(currentCamIndex>=0 ? currentCamIndex : 0);
  } else { await stopCamera(); }
});
window.addEventListener('beforeunload', ()=>{ try{ camQr?.stop(); camQr?.clear(); }catch(e){} });
document.addEventListener('visibilitychange', async ()=>{ if (document.hidden) await stopCamera(); });

/* ========= Start ========= */
window.addEventListener('load', async ()=>{
  await enumerateCameras();
  const activePane = document.querySelector('#pane-camera');
  if (activePane && activePane.classList.contains('active')) {
    await startCameraByIndex(currentCamIndex>=0 ? currentCamIndex : 0);
  }
});

/* ========= Controls: flip/stop ========= */
document.getElementById('btnFlip')?.addEventListener('click', async ()=>{
  if (!cameras.length) return;
  currentCamIndex = (currentCamIndex + 1) % cameras.length;
  await stopCamera(); await startCameraByIndex(currentCamIndex);
});
document.getElementById('btnStopCam')?.addEventListener('click', async ()=>{ await stopCamera(); });
</script>

<?php if (file_exists(__DIR__.'/footer.php')) require __DIR__ . '/footer.php'; ?>
</body>
</html>
