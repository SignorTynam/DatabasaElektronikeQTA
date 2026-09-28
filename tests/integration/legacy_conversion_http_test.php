<?php
declare(strict_types=1);

/**
 * Integrimi përmes HTTP: konvertimi i grupeve ashtu siç e thërret faqja.
 *
 * Një server PHP i përkohshëm (php -S) shërben app/ mbi të njëjtën databazë
 * testimi, me seanca të shkruara nga testi. Kontrollohen rregullat e hyrjes
 * (POST, seanca, roli, tokeni i faqes, "Lejo ndryshimet") për pikën e
 * konvertimit, faqet e saj dhe korrigjimin e grupit të konvertuar, pastaj
 * rrjedha e plotë: propozim → draft → konvertim → përpjekje e dytë.
 *
 * Vetëm në një databazë testimi (QTA_TEST_DB=1). Testi krijon grupin e vet.
 */

require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/legacy_conversion.php';

$pdo = getPDO();
$cvhDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($cvhDb === 'qta_db' && getenv('QTA_ALLOW_MAIN_DB') !== '1') {
  fwrite(STDERR, "Refuzohet: testet e integrimit nuk punojnë mbi qta_db.\n");
  exit(2);
}

/* ------------------------------------------------------------ Ndihmës */

function cvh_count(PDO $pdo, string $sql, array $p = []): int
{
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return (int)$st->fetchColumn();
}

/** Nis `php -S` mbi app/ dhe pret derisa të pranojë lidhje. */
function cvh_server_start(string $appDir, string $dir, string $dbName): array
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

/** Seancë e gatshme në dosjen e serverit; kthen identifikuesin e saj. */
function cvh_session(string $dir, array $vars): string
{
  $id = bin2hex(random_bytes(16));
  $data = '';
  foreach ($vars as $k => $v) $data .= $k . '|' . serialize($v);
  file_put_contents($dir . DIRECTORY_SEPARATOR . 'sess_' . $id, $data);
  return $id;
}

/** Kërkesë HTTP pa ndjekur ridrejtimet: statusi, JSON-i, Location dhe trupi. */
function cvh_http(string $url, string $method = 'POST', ?string $sid = null, ?array $body = null): array
{
  $headers = ['Accept: application/json'];
  if ($body !== null) $headers[] = 'Content-Type: application/json';
  if ($sid !== null) $headers[] = 'Cookie: PHPSESSID=' . $sid;
  $ctx = stream_context_create(['http' => [
    'method' => $method, 'header' => implode("\r\n", $headers),
    'content' => $body === null ? '' : json_encode($body),
    'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
  ]]);
  $raw = (string)file_get_contents($url, false, $ctx);
  $head = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
  $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($head[0] ?? ''), $m) ? (int)$m[1] : 0;
  $location = '';
  foreach ($head as $h) {
    if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
  }
  return ['status' => $status, 'json' => json_decode($raw, true), 'location' => $location, 'body' => $raw];
}

/* ------------------------------------------------------------ Përgatitja */

$cvhAdmin = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'administrator' ORDER BY u.id LIMIT 1")->fetchColumn();
$cvhStudent = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'student' ORDER BY u.id LIMIT 1")->fetchColumn();
$pdo->exec('SET @audit_user_id = ' . $cvhAdmin . ", @audit_ip = '127.0.0.1', @audit_ua = 'tests'");
$cvhTag = substr(md5((string)microtime(true)), 0, 5);
$cvhAmze = 860000 + random_int(0, 9000) * 10;

/* Kurs gati me 30 orë dhe një grup i vjetër e hënë–e shtunë: 4 ditë mësimi, pa të diela. */
$pdo->prepare('INSERT INTO courses (code, name, hours) VALUES (?, ?, ?)')->execute(['HTTP-' . $cvhTag, 'Kurs HTTP ' . $cvhTag, 30]);
$cvhCourse = (int)$pdo->lastInsertId();
foreach ([['Hyrje', [8, 8]], ['Praktika', [8, 6]]] as [$title, $topics]) {
  $mid = qta_curriculum_add_module($pdo, $cvhCourse, $title, array_sum($topics));
  foreach ($topics as $i => $h) qta_curriculum_add_topic($pdo, $mid, $title . ' — tema ' . ($i + 1), $h);
}
$pdo->prepare('INSERT INTO course_groups (course_id, start_date, end_date, is_completed) VALUES (?, ?, ?, 0)')->execute([$cvhCourse, '2027-05-03', '2027-05-08']);
$cvhGroup = (int)$pdo->lastInsertId();
foreach ([$cvhAmze, $cvhAmze + 1] as $a) {
  $pdo->prepare('INSERT INTO course_group_students (group_id, student_id) VALUES (?, ?)')->execute([$cvhGroup, qta_amze_ensure_student($pdo, $a)]);
}

$cvhDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qta_http_' . bin2hex(random_bytes(4));
mkdir($cvhDir);
$cvhServer = cvh_server_start(realpath(__DIR__ . '/../../app'), $cvhDir, $cvhDb);
register_shutdown_function(static function () use ($cvhServer): void {
  if (is_resource($cvhServer['proc'])) proc_terminate($cvhServer['proc']);
});

$cvhCsrf = bin2hex(random_bytes(16));
$cvhSid = [
  'student' => cvh_session($cvhDir, ['user_id' => $cvhStudent, 'csrf_token' => $cvhCsrf]),
  'locked'  => cvh_session($cvhDir, ['user_id' => $cvhAdmin, 'csrf_token' => $cvhCsrf, 'edit_mode' => false]),
  'open'    => cvh_session($cvhDir, ['user_id' => $cvhAdmin, 'csrf_token' => $cvhCsrf, 'edit_mode' => true]),
];
$GLOBALS['CVH'] = ['base' => $cvhServer['base'], 'sid' => $cvhSid, 'csrf' => $cvhCsrf, 'group' => $cvhGroup];

/** Thirrje e pikës së konvertimit për grupin e testit. */
function cvh_conv(?string $who, string $action, array $extra = [], bool $withCsrf = true): array
{
  $c = $GLOBALS['CVH'];
  $body = ['action' => $action, 'group_id' => $c['group']] + $extra + ($withCsrf ? ['csrf' => $c['csrf']] : []);
  return cvh_http($c['base'] . '/actions/group_conversion_update.php', 'POST', $who === null ? null : $c['sid'][$who], $body);
}

/** Gjendja e grupit që duhet të mbetet e paprekur pas çdo kërkese të refuzuar. */
function cvh_state(PDO $pdo, int $gid): array
{
  return [
    'model' => (string)$pdo->query('SELECT model FROM course_groups WHERE id = ' . $gid)->fetchColumn(),
    'drafts' => cvh_count($pdo, 'SELECT COUNT(*) FROM legacy_conversion_drafts WHERE group_id = ?', [$gid]),
    'conversions' => cvh_count($pdo, 'SELECT COUNT(*) FROM group_conversions WHERE group_id = ?', [$gid]),
    'plan' => cvh_count($pdo, 'SELECT COUNT(*) FROM group_fixed_days WHERE group_id = ?', [$gid]),
  ];
}

/* ------------------------------------------------------------ Rastet */

t_case('Konvertimi përmes HTTP — hyrja: POST, seanca, roli, tokeni, "Lejo ndryshimet"', function () use ($pdo, $cvhGroup) {
  $c = $GLOBALS['CVH'];
  $untouched = ['model' => 'legacy', 'drafts' => 0, 'conversions' => 0, 'plan' => 0];

  t_eq(405, cvh_http($c['base'] . '/actions/group_conversion_update.php', 'GET', $c['sid']['open'])['status'], 'GET refuzohet (405)');
  t_eq(401, cvh_conv(null, 'save')['status'], 'pa seancë: 401');
  t_eq(403, cvh_conv('student', 'convert')['status'], 'kursanti nuk konverton: 403');
  t_eq(400, cvh_conv('open', 'convert', [], false)['status'], 'pa tokenin e faqes: 400');
  t_eq(400, cvh_conv('open', 'convert', ['csrf' => 'x' . $c['csrf']])['status'], 'me token të gabuar: 400');
  foreach (['save', 'refresh', 'convert'] as $action) {
    $r = cvh_conv('locked', $action, ['revision' => 0, 'plan' => []]);
    t_eq([403, false], [$r['status'], $r['json']['ok'] ?? null], $action . ' me ndryshimet të mbyllura: 403');
  }
  t_eq($untouched, cvh_state($pdo, $cvhGroup), 'asnjë kërkesë e refuzuar nuk preku grupin');

  /* Llogaritjet pa ruajtje lejohen edhe me ndryshimet të mbyllura, si te grupet e tjera. */
  $p = cvh_conv('locked', 'propose');
  t_eq(200, $p['status'], 'propozimi: 200 me ndryshimet të mbyllura');
  t_eq([6, 30], [count($p['json']['plan'] ?? []), array_sum(array_column($p['json']['plan'] ?? [], 'h'))], 'propozimi: 6 data, 30 orë');
  t_eq($untouched, cvh_state($pdo, $cvhGroup), 'propozimi nuk ruan asgjë');

  /* Faqet: vetëm stafi; të tjerët dërgohen te zgjedhja e profilit. */
  $page = $c['base'] . '/pages/group_conversion.php?id=' . $cvhGroup;
  $anon = cvh_http($page, 'GET');
  t_eq([302, 'selectProfile.php'], [$anon['status'], $anon['location']], 'faqja pa seancë: te zgjedhja e profilit');
  t_eq(302, cvh_http($page, 'GET', $c['sid']['student'])['status'], 'faqja për kursantin: e mbyllur');
  t_eq(302, cvh_http($c['base'] . '/pages/group_conversions.php', 'GET', $c['sid']['student'])['status'], 'qendra e konvertimit për kursantin: e mbyllur');
  $admin = cvh_http($page, 'GET', $c['sid']['locked']);
  t_eq(200, $admin['status'], 'faqja për administratorin: 200');
  t_ok(str_contains($admin['body'], 'Konvertimi i Grupit #' . $cvhGroup), 'faqja tregon grupin');
});

t_case('Konvertimi përmes HTTP — draft, konvertim, përpjekja e dytë dhe korrigjimi', function () use ($pdo, $cvhGroup) {
  $c = $GLOBALS['CVH'];
  $source = qta_conv_fingerprint(qta_conv_source($pdo, $cvhGroup));
  $plan = cvh_conv('open', 'propose')['json']['plan'] ?? [];

  $saved = cvh_conv('open', 'save', ['plan' => $plan, 'revision' => 0, 'source' => $source]);
  t_eq([200, 1], [$saved['status'], $saved['json']['revision'] ?? null], 'drafti u ruajt: versioni 1');
  t_eq(['model' => 'legacy', 'drafts' => 1, 'conversions' => 0, 'plan' => 0], cvh_state($pdo, $cvhGroup), 'drafti nuk preku grupin');

  $stale = cvh_conv('open', 'convert', ['revision' => 0, 'source' => $source]);
  t_eq([400, 'draft_changed'], [$stale['status'], $stale['json']['code'] ?? null], 'versioni i vjetër i draftit refuzohet');
  $changed = cvh_conv('open', 'convert', ['revision' => 1, 'source' => str_repeat('0', 64)]);
  t_eq([400, 'source_changed'], [$changed['status'], $changed['json']['code'] ?? null], 'gjurma e ndryshme e të dhënave refuzohet');
  t_eq('legacy', cvh_state($pdo, $cvhGroup)['model'], 'pas refuzimeve grupi mbetet i vjetër');

  $done = cvh_conv('open', 'convert', ['revision' => 1, 'source' => $source]);
  t_eq([200, 'lesson_group.php?id=' . $cvhGroup], [$done['status'], $done['json']['redirect'] ?? null], 'konvertimi: 200 dhe te faqja e grupit');
  t_eq(['model' => 'scheduled', 'drafts' => 0, 'conversions' => 1, 'plan' => 6], cvh_state($pdo, $cvhGroup), 'grupi u konvertua, drafti u mbyll');
  t_eq(['fixed_range', null, '2027-05-03', '2027-05-08'],
    array_values($pdo->query('SELECT gs.schedule_mode, gs.daily_hours, cg.start_date, cg.end_date FROM group_schedules gs JOIN course_groups cg ON cg.id = gs.group_id WHERE gs.group_id = ' . $cvhGroup)->fetch(PDO::FETCH_ASSOC)),
    'data historike, pa orë ditore të sajuara');

  $again = cvh_conv('open', 'convert', ['revision' => 1, 'source' => $source]);
  t_eq([400, 'converted'], [$again['status'], $again['json']['code'] ?? null], 'përpjekja e dytë refuzohet');
  t_eq(1, cvh_count($pdo, 'SELECT COUNT(*) FROM group_conversions WHERE group_id = ?', [$cvhGroup]), 'vetëm një shënim konvertimi');

  /* Korrigjimi i grupit të konvertuar ndjek të njëjtat rregulla hyrjeje. */
  $lg = $c['base'] . '/actions/lesson_group_update.php';
  $stored = array_map(static fn($r) => ['d' => $r['lesson_date'], 'h' => (int)$r['hours']],
    $pdo->query('SELECT lesson_date, hours FROM group_fixed_days WHERE group_id = ' . $cvhGroup . ' ORDER BY lesson_date')->fetchAll(PDO::FETCH_ASSOC));
  t_eq(405, cvh_http($lg, 'GET', $c['sid']['open'])['status'], 'korrigjimi: GET refuzohet');
  t_eq(400, cvh_http($lg, 'POST', $c['sid']['open'], ['action' => 'rebalance', 'group_id' => $cvhGroup, 'plan' => $stored])['status'], 'korrigjimi pa token: 400');
  t_eq(403, cvh_http($lg, 'POST', $c['sid']['student'], ['action' => 'rebalance', 'group_id' => $cvhGroup, 'plan' => $stored, 'csrf' => $c['csrf']])['status'], 'korrigjimi për kursantin: 403');
  $locked = cvh_http($lg, 'POST', $c['sid']['locked'], ['action' => 'change', 'group_id' => $cvhGroup, 'csrf' => $c['csrf'],
    'change' => ['type' => 'fixed_days', 'days' => ['2027-05-04' => 0]]]);
  t_eq(403, $locked['status'], 'korrigjimi me ndryshimet të mbyllura: 403');
  $rb = cvh_http($lg, 'POST', $c['sid']['locked'], ['action' => 'rebalance', 'group_id' => $cvhGroup, 'plan' => $stored, 'csrf' => $c['csrf']]);
  t_eq([200, 30], [$rb['status'], array_sum(array_column($rb['json']['plan'] ?? [], 'h'))], 'rishpërndarja (pa ruajtje): 200, 30 orë');
  t_eq($stored, array_map(static fn($r) => ['d' => $r['lesson_date'], 'h' => (int)$r['hours']],
    $pdo->query('SELECT lesson_date, hours FROM group_fixed_days WHERE group_id = ' . $cvhGroup . ' ORDER BY lesson_date')->fetchAll(PDO::FETCH_ASSOC)), 'plani i ruajtur mbeti i njëjtë');
});

t_case('Konvertimi përmes HTTP — serveri pa gabime PHP', function () use ($cvhServer) {
  $log = is_file($cvhServer['log']) ? (string)file_get_contents($cvhServer['log']) : '';
  t_ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/i', $log), 'regjistri i serverit pa gabime' . ($log !== '' && preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse).*/i', $log, $m) ? ' | ' . $m[0] : ''));
});

/* ------------------------------------------------------------ Pastrimi */

proc_terminate($cvhServer['proc']);
proc_close($cvhServer['proc']);
foreach (glob($cvhDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) unlink($f);
rmdir($cvhDir);
