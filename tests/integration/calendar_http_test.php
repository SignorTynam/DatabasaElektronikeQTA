<?php
declare(strict_types=1);

/**
 * Integrimi përmes HTTP: faqja e kalendarit dhe pika e të dhënave të tij, ashtu siç i
 * thërret shfletuesi. Një server PHP i përkohshëm (php -S) shërben app/ mbi të njëjtën
 * databazë testimi, me seanca të shkruara nga testi.
 *
 * Kontrollohen: vetëm administratori dhe editori e hapin faqen dhe marrin të dhënat (asnjë
 * rrjedhje te kursanti, agjencia apo pa seancë), vetëm GET, intervali i kontrolluar,
 * grupi që mungon (404), menuja me "Kalendari", lidhja ?group= dhe mungesa e të dhënave
 * personale te lista e grupeve.
 *
 * Vetëm në një databazë testimi (QTA_TEST_DB=1). Testi krijon grupin dhe agjencinë e vet.
 */

require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/group_calendar.php';

$pdo = getPDO();
$calhDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($calhDb === 'qta_db' && getenv('QTA_ALLOW_MAIN_DB') !== '1') {
  fwrite(STDERR, "Refuzohet: testet e integrimit nuk punojnë mbi qta_db.\n");
  exit(2);
}

/* ------------------------------------------------------------ Ndihmës */

/** Nis `php -S` mbi app/ dhe pret derisa të pranojë lidhje. */
function calh_server_start(string $appDir, string $dir, string $dbName): array
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
function calh_session(string $dir, array $vars): string
{
  $id = bin2hex(random_bytes(16));
  $data = '';
  foreach ($vars as $k => $v) $data .= $k . '|' . serialize($v);
  file_put_contents($dir . DIRECTORY_SEPARATOR . 'sess_' . $id, $data);
  return $id;
}

/** Kërkesë HTTP pa ndjekur ridrejtimet: statusi, JSON-i, Location, koka dhe trupi. */
function calh_http(string $url, string $method = 'GET', ?string $sid = null): array
{
  $headers = ['Accept: application/json'];
  if ($sid !== null) $headers[] = 'Cookie: PHPSESSID=' . $sid;
  $ctx = stream_context_create(['http' => [
    'method' => $method, 'header' => implode("\r\n", $headers), 'content' => '',
    'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
  ]]);
  $raw = (string)file_get_contents($url, false, $ctx);
  $head = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
  $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($head[0] ?? ''), $m) ? (int)$m[1] : 0;
  $location = '';
  foreach ($head as $h) {
    if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
  }
  return ['status' => $status, 'json' => json_decode($raw, true), 'location' => $location, 'headers' => implode("\n", $head), 'body' => $raw];
}

/** Konfigurimi i faqes (JSON-i që lexon calendar.js). */
function calh_config(string $html): ?array
{
  return preg_match('#<script type="application/json" id="calConfig">(.*?)</script>#s', $html, $m) ? json_decode($m[1], true) : null;
}

/* ------------------------------------------------------------ Përgatitja */

$calhAdmin = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'administrator' ORDER BY u.id LIMIT 1")->fetchColumn();
$calhEditor = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'editor' ORDER BY u.id LIMIT 1")->fetchColumn();
$calhStudent = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'student' ORDER BY u.id LIMIT 1")->fetchColumn();
$pdo->exec('SET @audit_user_id = ' . $calhAdmin . ", @audit_ip = '127.0.0.1', @audit_ua = 'tests'");
$calhTag = substr(md5((string)microtime(true)), 0, 5);
$calhAmze = 830000 + random_int(0, 9000) * 10;

/* Një agjenci e përkohshme: sheh grupet e punonjësve të vet, por jo kalendarin e stafit. */
$pdo->prepare("INSERT INTO users (role_id, full_name, email) SELECT id, ?, ? FROM roles WHERE name = 'agjencia'")
    ->execute(['Agjencia e testit ' . $calhTag, 'cal-' . $calhTag . '@test.invalid']);
$calhAgencyUser = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO agencies (user_id, nip_t, company_name) VALUES (?, ?, ?)')->execute([$calhAgencyUser, 'T' . strtoupper($calhTag) . '0001', 'Agjencia e testit ' . $calhTag]);

/* Kurs gati dhe një grup me orar në vitin 2092, me dy kursantë me emër. */
$pdo->prepare('INSERT INTO courses (code, name, hours) VALUES (?, ?, ?)')->execute(['CALH-' . $calhTag, 'Kurs HTTP kalendari ' . $calhTag, 16]);
$calhCourse = (int)$pdo->lastInsertId();
$mid = qta_curriculum_add_module($pdo, $calhCourse, 'Moduli i vetëm', 16);
qta_curriculum_add_topic($pdo, $mid, 'Tema e parë', 8);
qta_curriculum_add_topic($pdo, $mid, 'Tema e dytë', 8);
$start = '2092-03-03';
while (qta_sched_is_sunday($start)) $start = qta_sched_next_day($start);
$calhGroup = (int)qta_lg_create($pdo, ['course_id' => $calhCourse, 'start_date' => $start, 'daily_hours' => 8,
  'exam_date' => '2199-12-31', 'amze_spec' => $calhAmze . '-' . ($calhAmze + 1)])['groups'][0]['group_id'];
$pdo->prepare('UPDATE persons p JOIN students s ON s.person_id = p.id SET p.first_name = ?, p.last_name = ?, p.personal_number = ? WHERE CAST(s.nr_amze AS UNSIGNED) = ?')
    ->execute(['Arta', 'Kalendari', 'J' . substr((string)$calhAmze, 0, 8) . 'X', $calhAmze]);
/* Grup i regjistrit të vjetër në të njëjtin muaj. */
$pdo->prepare('INSERT INTO course_groups (course_id, start_date, end_date, is_completed) VALUES (?, ?, ?, 0)')->execute([$calhCourse, '2092-03-10', '2092-03-12']);
$calhLegacy = (int)$pdo->lastInsertId();

$calhDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qta_cal_http_' . bin2hex(random_bytes(4));
mkdir($calhDir);
$calhServer = calh_server_start(realpath(__DIR__ . '/../../app'), $calhDir, $calhDb);
register_shutdown_function(static function () use ($calhServer): void {
  if (is_resource($calhServer['proc'])) proc_terminate($calhServer['proc']);
});

$calhSid = [
  'admin'   => calh_session($calhDir, ['user_id' => $calhAdmin, 'csrf_token' => 'x']),
  'editor'  => calh_session($calhDir, ['user_id' => $calhEditor, 'csrf_token' => 'x']),
  'student' => calh_session($calhDir, ['user_id' => $calhStudent, 'csrf_token' => 'x']),
  'agency'  => calh_session($calhDir, ['user_id' => $calhAgencyUser, 'csrf_token' => 'x']),
];
$GLOBALS['CALH'] = ['base' => $calhServer['base'], 'sid' => $calhSid];

function calh_data(?string $who, string $query, string $method = 'GET'): array
{
  $c = $GLOBALS['CALH'];
  return calh_http($c['base'] . '/actions/calendar_data.php?' . $query, $method, $who === null ? null : $c['sid'][$who]);
}

/* ------------------------------------------------------------ Rastet */

t_case('Kalendari përmes HTTP — faqja: vetëm administratori dhe editori', function () use ($calhGroup, $start) {
  $c = $GLOBALS['CALH'];
  $page = $c['base'] . '/pages/calendar.php';
  $anon = calh_http($page);
  t_eq([302, 'selectProfile.php'], [$anon['status'], $anon['location']], 'pa seancë: te zgjedhja e profilit');
  t_eq(302, calh_http($page, 'GET', $c['sid']['student'])['status'], 'kursanti: i ridrejtuar');
  t_eq(302, calh_http($page, 'GET', $c['sid']['agency'])['status'], 'agjencia: e ridrejtuar');
  foreach (['admin', 'editor'] as $who) {
    $r = calh_http($page . '?month=2092-03', 'GET', $c['sid'][$who]);
    t_eq(200, $r['status'], $who . ': 200');
    t_ok(str_contains($r['body'], '<h1 class="page-title">Kalendari</h1>'), $who . ': titulli i faqes');
    t_ok((bool)preg_match('#<a class="nav-item is-active" aria-current="page" href="calendar\.php"#', $r['body']), $who . ': "Kalendari" është aktiv në menu');
    $cfg = calh_config($r['body']);
    t_eq('2092-03', $cfg['month'] ?? null, $who . ': muaji nga adresa');
    t_ok(in_array($calhGroup, array_column($cfg['feed']['events'] ?? [], 'id'), true), $who . ': grupi i muajit është në faqe, pa kërkesë të dytë');
  }
  $deep = calh_config(calh_http($page . '?group=' . $calhGroup, 'GET', $c['sid']['admin'])['body']);
  t_eq([substr($start, 0, 7), $calhGroup, false], [$deep['month'] ?? null, $deep['group'] ?? null, $deep['legacy'] ?? null], '?group=: hapet muaji i fillimit të grupit');
});

t_case('Kalendari përmes HTTP — menuja: "Kalendari" vetëm për stafin', function () {
  $c = $GLOBALS['CALH'];
  $reg = calh_http($c['base'] . '/pages/lesson_groups.php', 'GET', $c['sid']['editor']);
  t_ok(str_contains($reg['body'], 'href="calendar.php"'), 'editori: "Kalendari" në menunë e regjistrit');
  t_ok(strpos($reg['body'], 'href="lesson_groups.php"') < strpos($reg['body'], 'href="calendar.php"')
    && strpos($reg['body'], 'href="calendar.php"') < strpos($reg['body'], 'href="groups.php"'), 'pas regjistrit, para regjistrit të vjetër');
  t_eq(false, str_contains(calh_http($c['base'] . '/pages/dashboard_student.php', 'GET', $c['sid']['student'])['body'], 'calendar.php'), 'kursanti nuk e sheh');
  t_eq(false, str_contains(calh_http($c['base'] . '/pages/dashboard_agjencia.php', 'GET', $c['sid']['agency'])['body'], 'calendar.php'), 'agjencia nuk e sheh');
});

t_case('Kalendari përmes HTTP — të dhënat: seanca, roli dhe vetëm GET', function () use ($calhGroup) {
  $month = 'from=2092-03-01&to=2092-03-31';
  t_eq(401, calh_data(null, $month)['status'], 'pa seancë: 401');
  t_eq(403, calh_data('student', $month)['status'], 'kursanti: 403');
  t_eq(403, calh_data('agency', $month)['status'], 'agjencia: 403');
  t_eq(403, calh_data('student', 'group=' . $calhGroup)['status'], 'kursanti nuk merr detajet e grupit: 403');
  t_eq(403, calh_data('agency', 'group=' . $calhGroup)['status'], 'agjencia nuk merr detajet e grupit: 403');
  foreach (['student', 'agency'] as $who) {
    $body = calh_data($who, 'group=' . $calhGroup)['body'];
    t_eq([false, false], [str_contains($body, 'Arta'), str_contains($body, '"members"')], $who . ': asnjë kursant në përgjigje');
  }
  $post = calh_data('admin', $month, 'POST');
  t_eq(405, $post['status'], 'POST: 405');
  t_ok(str_contains($post['headers'], 'Allow: GET'), 'POST: tregon që lejohet vetëm GET');
  $ok = calh_data('editor', $month);
  t_eq(200, $ok['status'], 'editori: 200');
  t_ok(str_contains($ok['headers'], 'nosniff') && str_contains($ok['headers'], 'no-store'), 'pa ruajtje në cache dhe pa nuhatje tipi');
});

t_case('Kalendari përmes HTTP — intervali i kontrolluar dhe grupi që mungon', function () use ($calhGroup, $calhLegacy) {
  foreach (['from=2092-03-31&to=2092-03-01' => 'fillimi pas mbarimit', 'from=2092-01-01&to=2092-12-31' => 'një vit njëherësh',
            'from=03.03.2092&to=31.03.2092' => 'jo ISO', 'from=2092-02-30&to=2092-03-10' => 'datë që s\'ekziston',
            'to=2092-03-31' => 'pa fillim', 'from[]=2092-03-01&to=2092-03-31' => 'varg', '' => 'pa asnjë parametër'] as $q => $what) {
    $r = calh_data('admin', $q);
    t_eq([400, 'bad_range'], [$r['status'], $r['json']['code'] ?? null], 'refuzohet: ' . $what);
  }
  t_eq([404, 'not_found'], [calh_data('admin', 'group=2147480000')['status'], calh_data('admin', 'group=2147480000')['json']['code'] ?? null], 'grupi që mungon: 404');
  t_eq(404, calh_data('admin', 'group=abc')['status'], 'numër grupi i pavlefshëm: 404');

  $feed = calh_data('admin', 'from=2092-03-01&to=2092-03-31')['json'];
  $ids = array_column($feed['events'] ?? [], 'id');
  t_ok(in_array($calhGroup, $ids, true) && !in_array($calhLegacy, $ids, true), 'grupi me orar po, ai i regjistrit të vjetër jo');
  t_eq(1, $feed['legacy_hidden'] ?? null, 'grupi i vjetër numërohet');
  t_ok(in_array($calhLegacy, array_column(calh_data('admin', 'from=2092-03-01&to=2092-03-31&legacy=1')['json']['events'] ?? [], 'id'), true), 'me legacy=1 shfaqet');
});

t_case('Kalendari përmes HTTP — lista pa të dhëna personale, detajet për stafin', function () use ($calhGroup, $calhAmze) {
  $list = calh_data('admin', 'from=2092-03-01&to=2092-03-31')['body'];
  foreach (['Arta', (string)$calhAmze, 'personal_number', 'birth', 'phone', 'email'] as $needle) {
    t_eq(false, str_contains($list, $needle), 'lista nuk mban: ' . $needle);
  }
  $d = calh_data('editor', 'group=' . $calhGroup)['json']['group'] ?? [];
  t_eq(['Arta Kalendari', (string)$calhAmze, 'student_card.php?sid='], [$d['members'][0]['name'] ?? null, $d['members'][0]['amze'] ?? null, substr((string)($d['members'][0]['href'] ?? ''), 0, strlen('student_card.php?sid='))],
    'detajet: emri, amza dhe lidhja te kartela');
  t_eq(false, str_contains(json_encode($d, JSON_UNESCAPED_UNICODE), 'J' . substr((string)$calhAmze, 0, 8) . 'X'), 'detajet pa numrin personal');
  t_eq(['lesson_group.php?id=' . $calhGroup, 'Moduli i vetëm', ['Tema e parë', 'Tema e dytë']],
    [$d['href'] ?? null, $d['curriculum']['modules'][0]['title'] ?? null, array_column($d['curriculum']['modules'][0]['topics'] ?? [], 'title')], 'lidhja e grupit dhe kopja e temave');
});

t_case('Kalendari përmes HTTP — serveri pa gabime PHP', function () use ($calhServer) {
  $log = is_file($calhServer['log']) ? (string)file_get_contents($calhServer['log']) : '';
  t_ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/i', $log), 'regjistri i serverit pa gabime' . (preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse).*/i', $log, $m) ? ' | ' . $m[0] : ''));
});

/* ------------------------------------------------------------ Pastrimi */

proc_terminate($calhServer['proc']);
proc_close($calhServer['proc']);
foreach (glob($calhDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) unlink($f);
rmdir($calhDir);
qta_lg_delete($pdo, $calhGroup, ['force' => true]);
$pdo->prepare('DELETE FROM course_groups WHERE id = ?')->execute([$calhLegacy]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$calhAgencyUser]);
