<?php
declare(strict_types=1);

/**
 * Integrimi përmes HTTP: hyrja si dialog mbi faqet publike (shared/partials/login_dialog.php,
 * actions/login_handler.php, pages/selectProfile.php), ashtu siç e thërret shfletuesi.
 *
 * Kontrollohen: selectProfile.php çon te kryefaqja me dialogun (?hyr, me rolin); çdo faqe
 * publike e vizaton dialogun me shenjën CSRF dhe e hap vetë me ?hyr; dërgimi me
 * Accept: application/json kthen JSON (gabimet me kod dhe shenjën e seancës, hyrja me panelin
 * e rolit dhe seancë të re) pa lënë mesazhe në seancë; pa JavaScript formulari ridrejton si
 * më parë dhe mesazhi del te dialogu një herë.
 *
 * Vetëm në një databazë testimi (QTA_TEST_DB=1). Testi krijon tri llogari (staf, agjenci,
 * kursant) dhe i fshin në fund.
 */

require_once __DIR__ . '/../../app/shared/database.php';

$pdo = getPDO();
$loghDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($loghDb === 'qta_db' && getenv('QTA_ALLOW_MAIN_DB') !== '1') {
  fwrite(STDERR, "Refuzohet: testet e integrimit nuk punojnë mbi qta_db.\n");
  exit(2);
}

/* ------------------------------------------------------------ Ndihmës */

/** Nis `php -S` mbi app/ dhe pret derisa të pranojë lidhje. */
function logh_server_start(string $appDir, string $dir, string $dbName): array
{
  $probe = stream_socket_server('tcp://127.0.0.1:0');
  $port = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
  fclose($probe);
  $log = $dir . DIRECTORY_SEPARATOR . 'server.log';
  $env = getenv();
  $env['QTA_DB_NAME'] = $dbName;
  $proc = proc_open([
    PHP_BINARY,
    '-d', 'session.save_path=' . $dir, '-d', 'session.name=PHPSESSID', '-d', 'session.serialize_handler=php',
    '-d', 'session.use_strict_mode=1', '-d', 'session.gc_probability=0',
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $log,
    '-S', '127.0.0.1:' . $port, '-t', $appDir,
  ], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $appDir, $env);
  if (!is_resource($proc)) {
    throw new RuntimeException('Serveri PHP i testit nuk u nis.');
  }
  for ($i = 0; $i < 100; $i++) {
    try {
      fclose(fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2));
      return ['proc' => $proc, 'base' => 'http://127.0.0.1:' . $port, 'log' => $log];
    } catch (Throwable) {
      usleep(100000);
    }
  }
  proc_terminate($proc);
  throw new RuntimeException('Serveri PHP i testit nuk u përgjigj.');
}

/**
 * Kërkesë HTTP pa ndjekur ridrejtimet. $json = true dërgon Accept: application/json, si dialogu.
 * Kthen statusin, Location, trupin, JSON-in dhe seancën (e re nëse serveri dha një cookie).
 */
function logh_http(string $path, ?string $sid = null, ?array $post = null, bool $json = false): array
{
  $headers = ['Accept: ' . ($json ? 'application/json' : 'text/html')];
  if ($sid !== null) $headers[] = 'Cookie: PHPSESSID=' . $sid;
  $opts = ['method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60];
  if ($post !== null) {
    $opts['method'] = 'POST';
    $opts['content'] = http_build_query($post);
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
  }
  $opts['header'] = implode("\r\n", $headers);
  $raw = (string)file_get_contents($GLOBALS['LOGH']['base'] . $path, false, stream_context_create(['http' => $opts]));
  $head = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
  $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($head[0] ?? ''), $m) ? (int)$m[1] : 0;
  $location = '';
  $newSid = $sid;
  foreach ($head as $h) {
    if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
    if (preg_match('/^Set-Cookie:\s*PHPSESSID=([^;]+)/i', $h, $c)) $newSid = $c[1];
  }
  return ['status' => $status, 'location' => $location, 'body' => $raw, 'json' => json_decode($raw, true),
          'sid' => $newSid, 'headers' => implode("\n", $head)];
}

/** Hap një seancë si shfletuesi: kryefaqja me dialogun, cookie dhe shenja CSRF e formularit. */
function logh_visit(): array
{
  $r = logh_http('/pages/index.php');
  $csrf = preg_match('#name="csrf" value="([a-f0-9]+)"#', $r['body'], $m) ? $m[1] : '';
  return ['sid' => (string)$r['sid'], 'csrf' => $csrf];
}

/* ------------------------------------------------------------ Përgatitja */

$loghAdmin = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'administrator' ORDER BY u.id LIMIT 1")->fetchColumn();
$pdo->exec('SET @audit_user_id = ' . $loghAdmin . ", @audit_ip = '127.0.0.1', @audit_ua = 'tests'");
$loghTag = substr(md5((string)microtime(true)), 0, 6);
$loghPass = 'Prove-' . bin2hex(random_bytes(6));
$loghHash = password_hash($loghPass, PASSWORD_DEFAULT);
$roleId = static fn(string $name): int => (int)$pdo->query("SELECT id FROM roles WHERE name = " . $pdo->quote($name))->fetchColumn();

/* Staf (editor): hyn me email. */
$loghEmail = 'login-' . $loghTag . '@test.invalid';
$pdo->prepare('INSERT INTO users (role_id, full_name, email) VALUES (?, ?, ?)')->execute([$roleId('editor'), 'Editori i hyrjes ' . $loghTag, $loghEmail]);
$loghEditor = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (?, ?, NOW())')->execute([$loghEditor, $loghHash]);

/* Agjenci: hyn me NIPT. */
$loghNipt = 'T' . strtoupper($loghTag) . '009';
$pdo->prepare('INSERT INTO users (role_id, full_name, email) VALUES (?, ?, ?)')->execute([$roleId('agjencia'), 'Agjencia e hyrjes ' . $loghTag, 'login-ag-' . $loghTag . '@test.invalid']);
$loghAgency = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO agencies (user_id, nip_t, company_name) VALUES (?, ?, ?)')->execute([$loghAgency, $loghNipt, 'Agjencia e hyrjes ' . $loghTag]);
$pdo->prepare('INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (?, ?, NOW())')->execute([$loghAgency, $loghHash]);

/* Kursant: hyn me numrin personal. */
$loghPn = 'J' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT) . 'Z';
$gender = (int)$pdo->query('SELECT gender_id FROM persons ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO persons (personal_number, first_name, last_name, gender_id) VALUES (?, ?, ?, ?)')->execute([$loghPn, 'Testi', 'Hyrja', $gender]);
$loghPerson = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (role_id, person_id, full_name) VALUES (?, ?, ?)')->execute([$roleId('student'), $loghPerson, 'Testi Hyrja']);
$loghStudentUser = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO students (person_id, user_id, nr_amze) VALUES (?, ?, ?)')->execute([$loghPerson, $loghStudentUser, '97' . random_int(100000, 999999)]);
$loghStudent = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO credentials (user_id, password_hash, last_password_change) VALUES (?, ?, NOW())')->execute([$loghStudentUser, $loghHash]);

$loghDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qta_login_http_' . bin2hex(random_bytes(4));
mkdir($loghDir);
$loghServer = logh_server_start(realpath(__DIR__ . '/../../app'), $loghDir, $loghDb);
register_shutdown_function(static function () use ($loghServer): void {
  if (is_resource($loghServer['proc'])) proc_terminate($loghServer['proc']);
});
$GLOBALS['LOGH'] = ['base' => $loghServer['base']];

/* ------------------------------------------------------------ Rastet */

t_case('Hyrja përmes HTTP — selectProfile.php hap kryefaqen me dialogun', function () {
  foreach (['' => 'index.php?hyr', '?role=student' => 'index.php?hyr=student', '?role=agjencia' => 'index.php?hyr=agjencia',
            '?role=staff' => 'index.php?hyr=staff', '?role=administrator' => 'index.php?hyr=staff',
            '?role=tjeter' => 'index.php?hyr', '?role[]=student' => 'index.php?hyr'] as $query => $target) {
    $r = logh_http('/pages/selectProfile.php' . $query);
    t_eq([302, $target], [$r['status'], $r['location']], 'selectProfile.php' . ($query ?: '') . ' → ' . $target);
  }
});

t_case('Hyrja përmes HTTP — çdo faqe publike vizaton dialogun', function () {
  $home = logh_http('/pages/index.php');
  t_eq(200, $home['status'], 'kryefaqja: 200');
  t_ok(str_contains($home['body'], 'id="loginModal"') && str_contains($home['body'], '<h2 class="login-title" id="loginTitle">Hyr në llogari</h2>'), 'dialogi me titullin');
  t_ok((bool)preg_match('#name="csrf" value="[a-f0-9]{48}"#', $home['body']), 'shenja CSRF në formular');
  t_eq(3, preg_match_all('#<input type="radio" name="role"#', $home['body']), 'tri llojet e llogarisë');
  t_ok((bool)preg_match('#<a class="btn btn-primary" href="selectProfile\.php" data-login>#', $home['body']), '"Hyr" në kokë hap dialogun');
  t_ok(str_contains($home['body'], 'href="selectProfile.php?role=student" data-login="student"'), '"Hyr si kursant" hap dialogun me llojin e vet');
  t_ok(str_contains($home['body'], 'login-ui.js'), 'skripti i dialogut ngarkohet');
  t_eq([false, false], [str_contains($home['body'], 'data-open-on-load'), str_contains($home['body'], 'is-requested')], 'pa ?hyr dialogu nuk hapet vetë');
  t_eq(false, str_contains($home['body'], 'signin-'), 'asnjë gjurmë e faqes së vjetër të hyrjes');

  $asked = logh_http('/pages/index.php?hyr=agjencia')['body'];
  t_ok(str_contains($asked, 'data-open-on-load="hyr"') && str_contains($asked, 'data-role-fixed'), '?hyr=agjencia: hapet vetë, me llojin të fiksuar');
  t_ok((bool)preg_match('#value="agjencia" checked#', $asked) && str_contains($asked, '>NIPT-i i kompanisë</label>'), '?hyr=agjencia: agjencia e zgjedhur, fusha e NIPT-it');

  foreach (['verify.php', 'aboutus.php', 'contact.php', 'ndihme.php'] as $page) {
    $r = logh_http('/pages/' . $page . '?hyr');
    t_ok($r['status'] === 200 && str_contains($r['body'], 'id="loginModal"') && str_contains($r['body'], 'data-open-on-load="hyr"'), $page . ': dialogu hapet me ?hyr');
  }
});

t_case('Hyrja përmes HTTP — JSON: gabimet mbeten në dialog', function () use ($loghEmail, $loghNipt, $loghPn) {
  $s = logh_visit();
  t_ok($s['sid'] !== '' && $s['csrf'] !== '', 'seanca dhe shenja nga kryefaqja');
  $send = static fn(array $fields, ?string $csrf = null) => logh_http('/actions/login_handler.php', $s['sid'],
    $fields + ['csrf' => $csrf ?? $s['csrf']], true);

  $r = $send(['role' => 'staff', 'identifier' => $loghEmail, 'password' => 'x'], 'shenje-e-vjeter');
  t_eq([200, false, 'csrf', $s['csrf']], [$r['status'], $r['json']['ok'] ?? null, $r['json']['code'] ?? null, $r['json']['csrf'] ?? null], 'shenjë e vjetër: kodi csrf dhe shenja e seancës për provën tjetër');
  t_ok(str_contains($r['headers'], 'application/json') && str_contains($r['headers'], 'no-store'), 'JSON pa ruajtje në cache');

  $r = $send(['role' => 'staff', 'identifier' => $loghEmail, 'password' => '']);
  t_eq(['missing', 'Plotëso të dyja fushat: identifikimin dhe fjalëkalimin.'], [$r['json']['code'] ?? null, $r['json']['error'] ?? null], 'fusha bosh: missing');
  $r = $send(['role' => 'rektor', 'identifier' => $loghEmail, 'password' => 'x']);
  t_eq('role', $r['json']['code'] ?? null, 'lloj i panjohur: role');
  $r = $send(['role' => ['staff'], 'identifier' => $loghEmail, 'password' => 'x']);
  t_eq('role', $r['json']['code'] ?? null, 'lloj si varg: role, pa gabim PHP');

  foreach ([['staff', $loghEmail, 'Email-i'], ['agjencia', $loghNipt, 'NIPT-i'], ['student', $loghPn, 'Numri personal']] as [$role, $id, $name]) {
    $r = $send(['role' => $role, 'identifier' => $id, 'password' => 'fjalekalim-i-gabuar']);
    t_eq([false, 'credentials', $name . ' ose fjalëkalimi nuk është i saktë. Kontrollo dhe provo sërish.'],
      [$r['json']['ok'] ?? null, $r['json']['code'] ?? null, $r['json']['error'] ?? null], $role . ': fjalëkalim i gabuar, mesazhi emërton identifikimin');
  }
  $r = $send(['role' => 'agjencia', 'identifier' => $loghEmail, 'password' => 'x']);
  t_eq('credentials', $r['json']['code'] ?? null, 'email-i te agjencia: nuk gjendet');

  $after = logh_http('/pages/index.php', $s['sid'])['body'];
  t_eq([false, false], [str_contains($after, 'alert alert-danger login-error'), str_contains($after, 'is-requested')], 'JSON nuk lë mesazh në seancë: faqja tjetër hapet e pastër');
});

t_case('Hyrja përmes HTTP — JSON: hyrja hap panelin e rolit me seancë të re', function () use ($loghEmail, $loghNipt, $loghPn, $loghPass) {
  foreach ([['staff', $loghEmail, 'dashboard_editor.php'], ['agjencia', ' ' . substr($loghNipt, 0, 5) . ' ' . substr($loghNipt, 5) . ' ', 'dashboard_agjencia.php'],
            ['student', $loghPn, 'dashboard_student.php']] as [$role, $id, $panel]) {
    $s = logh_visit();
    $r = logh_http('/actions/login_handler.php', $s['sid'], ['csrf' => $s['csrf'], 'role' => $role, 'identifier' => $id, 'password' => $loghPass], true);
    t_eq([200, true, $panel], [$r['status'], $r['json']['ok'] ?? null, $r['json']['redirect'] ?? null], $role . ': ok dhe paneli i rolit');
    t_ok($r['sid'] !== $s['sid'], $role . ': seanca ndërrohet pas hyrjes');
    $p = logh_http('/pages/' . $panel, $r['sid']);
    t_eq(200, $p['status'], $role . ': paneli hapet me seancën e re');
    t_eq(302, logh_http('/pages/' . $panel, $s['sid'])['status'], $role . ': seanca e vjetër nuk vlen më');
  }

  /* Faqja e hapur gjatë: shenja e kthyer nga gabimi lejon një provë tjetër pa rifreskim. */
  $s = logh_visit();
  $stale = logh_http('/actions/login_handler.php', $s['sid'], ['csrf' => 'e-vjeter', 'role' => 'staff', 'identifier' => $loghEmail, 'password' => $loghPass], true);
  $retry = logh_http('/actions/login_handler.php', $s['sid'], ['csrf' => (string)($stale['json']['csrf'] ?? ''), 'role' => 'staff', 'identifier' => $loghEmail, 'password' => $loghPass], true);
  t_eq(['csrf', true], [$stale['json']['code'] ?? null, $retry['json']['ok'] ?? null], 'shenja e re nga gabimi csrf lejon hyrjen');
});

t_case('Hyrja përmes HTTP — pa JavaScript: ridrejtimi dhe mesazhi te dialogu', function () use ($loghEmail, $loghPass) {
  $s = logh_visit();
  $r = logh_http('/actions/login_handler.php', $s['sid'], ['csrf' => $s['csrf'], 'role' => 'staff', 'identifier' => $loghEmail, 'password' => 'gabim']);
  t_eq([302, 'selectProfile.php?role=staff'], [$r['status'], $r['location']], 'gabimi: ridrejtim si më parë');
  $page = logh_http('/pages/index.php?hyr=staff', $s['sid'])['body'];
  t_ok(str_contains($page, 'Email-i ose fjalëkalimi nuk është i saktë.') && str_contains($page, 'is-requested'), 'mesazhi del te dialogu i hapur');
  t_ok(str_contains($page, 'value="' . $loghEmail . '"'), 'email-i i shkruar mbetet në fushë');
  t_eq(false, str_contains(logh_http('/pages/index.php', $s['sid'])['body'], 'nuk është i saktë'), 'mesazhi shfaqet vetëm një herë');

  $ok = logh_http('/actions/login_handler.php', $s['sid'], ['csrf' => $s['csrf'], 'role' => 'staff', 'identifier' => $loghEmail, 'password' => $loghPass]);
  t_eq([302, 'dashboard_editor.php'], [$ok['status'], $ok['location']], 'hyrja: ridrejtim te paneli');
  t_eq([302, 'selectProfile.php'], [logh_http('/actions/login_handler.php')['status'], logh_http('/actions/login_handler.php')['location']], 'GET te hyrja: te selectProfile.php');
});

t_case('Hyrja përmes HTTP — serveri pa gabime PHP', function () use ($loghServer) {
  $log = is_file($loghServer['log']) ? (string)file_get_contents($loghServer['log']) : '';
  t_ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/i', $log), 'regjistri i serverit pa gabime' . (preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse).*/i', $log, $m) ? ' | ' . $m[0] : ''));
});

/* ------------------------------------------------------------ Pastrimi */

proc_terminate($loghServer['proc']);
proc_close($loghServer['proc']);
foreach (glob($loghDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) unlink($f);
rmdir($loghDir);
$pdo->prepare('DELETE FROM students WHERE id = ?')->execute([$loghStudent]);
$pdo->prepare('DELETE FROM agencies WHERE user_id = ?')->execute([$loghAgency]);
foreach ([$loghEditor, $loghAgency, $loghStudentUser] as $uid) {
  $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
}
$pdo->prepare('DELETE FROM persons WHERE id = ?')->execute([$loghPerson]);
