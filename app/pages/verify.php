<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';
$pdo = getPDO();

/* ============== Ndihmës ============== */
function json_out(array $x){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($x); exit; }

/* Masko ID personale (p.sh. *******00A) */
function mask_id(?string $s): string {
  if(!$s) return '—';
  $len = strlen($s);
  if ($len <= 3) return str_repeat('*', max(0,$len-1)).substr($s, -1);
  return str_repeat('*', $len-3).substr($s, -3);
}

/* --------------- Leximi i kodit (URL, QTA|…, JSON, SID|TOKEN, ose vetëm token) --------------- */
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

  // 5) Vetëm kodi (token) — siç shkruhet poshtë QR-së në certifikatë
  $bare = strtolower(preg_replace('/\s+/', '', $raw) ?? '');
  if (preg_match('/^[a-f0-9]{16,64}$/', $bare)) {
    return ['sid'=>0, 'pid'=>0, 'token'=>$bare];
  }

  return ['sid'=>0, 'pid'=>0, 'token'=>''];
}

/* Kodi vetëm (pa SID/PID): tokenët janë unikë në të dyja tabelat, prandaj
   gjejmë pronarin drejtpërdrejt. Njëlloj i sigurt sa QR-ja: kodi është sekreti. */
function resolve_token(PDO $pdo, string $token): array {
  $token = strtolower(trim($token));
  if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) return ['sid'=>0, 'pid'=>0];
  $q = $pdo->prepare("SELECT person_id FROM person_qr_tokens WHERE token = :t LIMIT 1");
  $q->execute([':t'=>$token]);
  $pid = (int)($q->fetchColumn() ?: 0);
  if ($pid > 0) return ['sid'=>0, 'pid'=>$pid];
  $q = $pdo->prepare("SELECT student_id FROM student_qr_tokens WHERE token = :t LIMIT 1");
  $q->execute([':t'=>$token]);
  return ['sid'=>(int)($q->fetchColumn() ?: 0), 'pid'=>0];
}

/* --------------- Verifikimi: STUDENT --------------- */
function verify_student(PDO $pdo, int $sid, string $token): array {
  if ($sid<=0 || $token==='') return ['valid'=>false, 'reason'=>'Mungon SID ose token.'];

  $chk = $pdo->prepare("SELECT 1 FROM student_qr_tokens WHERE student_id=:sid AND token=:t LIMIT 1");
  $chk->execute([':sid'=>$sid, ':t'=>$token]);
  if (!$chk->fetchColumn()) return ['valid'=>false, 'reason'=>'Token i pavlefshëm ose nuk përputhet me këtë student.'];

  /* Verifikimi publik kthen vetëm emrin, numrin personal të maskuar dhe kurset:
     pa agjenci, arsim, pikë apo statistika. */
  $sql = "
    SELECT
      s.id, s.nr_amze,
      p.first_name, p.father_name, p.last_name, p.personal_number
    FROM students s
    JOIN users u    ON u.id = s.user_id
    JOIN persons p  ON p.id = s.person_id
    WHERE s.id = :sid
    LIMIT 1
  ";
  $st = $pdo->prepare($sql); $st->execute([':sid'=>$sid]);
  $S = $st->fetch(PDO::FETCH_ASSOC);
  if (!$S) return ['valid'=>false, 'reason'=>'Studenti nuk u gjet.'];

  // Lista e kurseve (max 5)
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

  // Kurset & grupet (vetëm AMZË + Kursi)
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

  $p = $pdo->prepare("
    SELECT p.id, p.first_name, p.father_name, p.last_name, p.personal_number
    FROM persons p WHERE p.id = :pid LIMIT 1
  ");
  $p->execute([':pid'=>$pid]);
  $P = $p->fetch(PDO::FETCH_ASSOC);
  if (!$P) return ['valid'=>false, 'reason'=>'Personi nuk u gjet.'];

  $st = $pdo->prepare("
    SELECT s.id, s.nr_amze
    FROM students s
    WHERE s.person_id = :pid
    ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  ");
  $st->execute([':pid'=>$pid]);
  $students = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $ids = array_map(fn($r)=> (int)$r['id'], $students);
  $coursesList = [];
  $groups = [];

  if ($ids){
    $ph = implode(',', array_fill(0,count($ids),'?'));

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
      'students'=>array_map(fn($r)=>['id'=>(int)$r['id'], 'amze'=>$r['nr_amze']], $students),
      'courses_list'=>$coursesList,
      'groups'=>$groups
    ]
  ];
}

/* Zgjidh dhe verifiko: pid/sid + token, ose vetëm token. */
function verify_any(PDO $pdo, int $sid, int $pid, string $token): array {
  if ($token !== '' && $sid <= 0 && $pid <= 0) {
    $r = resolve_token($pdo, $token);
    $sid = $r['sid']; $pid = $r['pid'];
    if ($sid <= 0 && $pid <= 0) {
      return ['valid'=>false, 'reason'=>'Ky kod nuk figuron në regjistër.'];
    }
  }
  if ($pid > 0 && $token !== '') return verify_person($pdo, $pid, $token);
  if ($sid > 0 && $token !== '') return verify_student($pdo, $sid, $token);
  return ['valid'=>false, 'reason'=>'Kodi nuk u lexua. Kontrollo që të jetë i plotë.'];
}

/* ==================== API JSON (POST) ==================== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  $raw = file_get_contents('php://input');
  $json = (stripos($ct,'application/json')!==false) ? json_decode($raw,true) : $_POST;
  if (!is_array($json)) $json = [];

  $action = $json['action'] ?? '';
  if ($action==='verify') {
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

    json_out(['ok'=>true] + verify_any($pdo, $sid, $pid, $token));
  }
  json_out(['ok'=>false, 'error'=>'Veprim i panjohur.']);
}

/* ==================== GET: rezultati nga lidhja ose kodi ==================== */
$prefillResult = null;
$prefillCode = '';
if (isset($_GET['t']) && trim((string)$_GET['t']) !== '') {
  $pid = isset($_GET['pid']) && ctype_digit((string)$_GET['pid']) ? (int)$_GET['pid'] : 0;
  $sid = isset($_GET['sid']) && ctype_digit((string)$_GET['sid']) ? (int)$_GET['sid'] : 0;
  $parsed = parse_payload((string)$_GET['t']);
  $token = $parsed['token'] !== '' ? $parsed['token'] : trim((string)$_GET['t']);
  $pid = $pid ?: (int)$parsed['pid'];
  $sid = $sid ?: (int)$parsed['sid'];
  $prefillCode = $token;
  $prefillResult = ['ok'=>true] + verify_any($pdo, $sid, $pid, $token);
}

$currentUser = qta_public_current_user($pdo);

$NAV_ACTIVE = 'verify';
$pageTitle = 'Verifiko një certifikatë — Regjistri QTA';
$pageDescription = 'Kontrollo nëse një certifikatë QTA është e vlefshme: skano kodin QR ose shkruaj kodin. Pa llogari.';
$publicPlugins = ['html5-qrcode'];
$pageScripts = [qta_asset('app/assets/js/verify.js')];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main id="main" tabindex="-1">

  <section class="wrap page-intro">
    <span class="hero-eyebrow"><i class="bi bi-shield-check" aria-hidden="true"></i>Verifikim publik · pa llogari</span>
    <h1 class="page-title">Verifiko një certifikatë</h1>
    <p class="page-lead">Skano kodin QR të certifikatës ose shkruaj kodin që gjendet poshtë tij. Përgjigjja jepet menjëherë.</p>
  </section>

  <div class="wrap verify-layout">

    <!-- Mjetet: skano, ngarko, shkruaj -->
    <div>
      <section class="panel" id="skano" aria-labelledby="toolsTitle">
        <h2 class="section-title mb-3" id="toolsTitle">Si do ta kontrollosh?</h2>

        <button class="btn btn-primary btn-lg w-100" type="button" data-scan-start>
          <i class="bi bi-camera" aria-hidden="true"></i>Skano kodin QR me kamerë
        </button>

        <div class="scan-box mt-3" data-scan-box>
          <div id="reader" aria-label="Pamja e kamerës"></div>
          <div class="scan-tools">
            <label class="visually-hidden" for="camSelect">Zgjidh kamerën</label>
            <select class="form-select form-select-sm flex-grow-1" id="camSelect" data-scan-camera hidden></select>
            <button class="btn btn-secondary btn-sm" type="button" data-scan-stop>
              <i class="bi bi-stop-circle" aria-hidden="true"></i>Ndalo kamerën
            </button>
          </div>
        </div>
        <p class="scan-status" data-scan-status aria-live="polite">Kamera hapet vetëm kur shtyp butonin. Drejtoje te kodi QR dhe mbaje të qetë.</p>

        <label class="btn btn-secondary w-100 mt-2">
          <i class="bi bi-image" aria-hidden="true"></i>Ngarko një foto të kodit
          <input class="visually-hidden" type="file" accept="image/*" data-scan-file>
        </label>
        <div id="file-reader" class="d-none" aria-hidden="true"></div>

        <div class="or-divider">ose shkruaj kodin</div>

        <form data-verify-form novalidate>
          <label class="form-label" for="manualPayload">Kodi i certifikatës</label>
          <div class="d-flex gap-2">
            <input class="form-control form-control-lg input-code" id="manualPayload" name="code" type="text"
                   autocomplete="off" autocapitalize="off" spellcheck="false"
                   aria-describedby="codeHelp" placeholder="p.sh. 77d9f938…" value="<?= h($prefillCode) ?>">
            <button class="btn btn-secondary btn-lg btn-icon" type="button" data-paste aria-label="Ngjit kodin nga kujtesa">
              <i class="bi bi-clipboard" aria-hidden="true"></i>
            </button>
          </div>
          <p class="form-text" id="codeHelp">Kodi gjendet poshtë kodit QR. Mund të ngjisësh edhe linkun e plotë të verifikimit.</p>
          <button class="btn btn-primary w-100 mt-1" type="submit">
            <i class="bi bi-search" aria-hidden="true"></i>Kontrollo kodin
          </button>
        </form>
      </section>

      <section class="notice is-sunken mt-3" aria-labelledby="readTitle">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
          <b id="readTitle">Si ta lexoj rezultatin?</b>
          <ul class="mb-2 mt-1 ps-3">
            <li><b>E gjelbër</b> — certifikata figuron në regjistrin e QTA-së.</li>
            <li><b>E kuqe</b> — kodi nuk u gjet: mund të jetë shkruar gabim ose të jetë i rremë.</li>
            <li>Krahaso gjithnjë <b>emrin në ekran</b> me emrin në certifikatë.</li>
          </ul>
          Dyshon për falsifikim? Njofto QTA-në: <a href="tel:+355698778837">+355 69 877 8837</a> ·
          <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a>
        </div>
      </section>
    </div>

    <!-- Rezultati -->
    <section aria-labelledby="statusText">
      <div class="verdict" data-verdict aria-live="polite">
        <span class="verdict-icon"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></span>
        <div class="min-w-0">
          <h2 class="verdict-title" id="statusText">Gati për kontroll</h2>
          <p class="verdict-text" data-verdict-note>Skano kodin QR ose shkruaj kodin e certifikatës.</p>
        </div>
      </div>
      <div class="result" data-result>
        <div class="result-empty">
          <i class="bi bi-patch-question" aria-hidden="true"></i>
          <span>Rezultati do të shfaqet këtu.</span>
        </div>
      </div>
      <noscript>
        <div class="alert alert-warning mt-3">Për të skanuar kodin duhet JavaScript i aktivizuar në shfletues.</div>
      </noscript>
    </section>

  </div>
</main>

<?php if ($prefillResult !== null): ?>
<script type="application/json" id="verifyPrefill"><?= json_encode($prefillResult, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>

<?php
require_once __DIR__ . '/../shared/footer.php';
require_once __DIR__ . '/../shared/public_scripts.php';
?>
</body>
</html>
