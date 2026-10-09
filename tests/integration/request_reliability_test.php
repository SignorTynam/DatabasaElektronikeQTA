<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/group_members.php';

$rrPdo = getPDO();
$rrDb = (string)$rrPdo->query('SELECT DATABASE()')->fetchColumn();
if (getenv('QTA_TEST_DB') !== '1' || !str_contains($rrDb, 'test') || $rrDb === 'qta_db') {
    throw new RuntimeException('Reliability tests require an isolated test database.');
}
function rr_http(string $url, ?string $sid = null, ?array $form = null): array
{
    $headers = ['Accept: text/html'];
    if ($sid) $headers[] = 'Cookie: PHPSESSID=' . $sid;
    if ($form !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $start = microtime(true);
    $body = file_get_contents($url, false, stream_context_create(['http' => [
        'method' => $form === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
        'content' => $form === null ? '' : http_build_query($form), 'ignore_errors' => true,
        'follow_location' => 0, 'timeout' => 25,
    ]]));
    $head = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $head[0] ?? '', $m);
    return ['status' => (int)($m[1] ?? 0), 'body' => $body, 'headers' => $head, 'ms' => (microtime(true) - $start) * 1000];
}

$rrDir = sys_get_temp_dir() . '/qta_reliability_http_' . bin2hex(random_bytes(5));
mkdir($rrDir);
$rrProbe = stream_socket_server('tcp://127.0.0.1:0');
$rrPort = (int)substr(strrchr(stream_socket_get_name($rrProbe, false), ':'), 1); fclose($rrProbe);
$rrLog = $rrDir . '/server.log';
$rrProc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $rrDir, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $rrLog,
    '-S', '127.0.0.1:' . $rrPort, '-t', realpath(__DIR__ . '/../../app')],
    [0 => ['pipe', 'r'], 1 => ['file', $rrLog, 'a'], 2 => ['file', $rrLog, 'a']], $rrPipes);
register_shutdown_function(static function () use ($rrProc): void { if (is_resource($rrProc)) proc_terminate($rrProc); });
for ($i = 0; $i < 100; $i++) {
    try { fclose(fsockopen('127.0.0.1', $rrPort, $errno, $errstr, .1)); break; } catch (Throwable) { usleep(50000); }
}
$rrBase = 'http://127.0.0.1:' . $rrPort;
$rrLiveProbe = stream_socket_server('tcp://127.0.0.1:0');
$rrLivePort = (int)substr(strrchr(stream_socket_get_name($rrLiveProbe, false), ':'), 1); fclose($rrLiveProbe);
$rrLiveProc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $rrDir, '-S', '127.0.0.1:' . $rrLivePort,
    '-t', realpath(__DIR__ . '/../..'), realpath(__DIR__ . '/../fixtures/browser_router.php')],
    [0 => ['pipe', 'r'], 1 => ['file', $rrLog, 'a'], 2 => ['file', $rrLog, 'a']], $rrLivePipes);
register_shutdown_function(static function () use ($rrLiveProc): void { if (is_resource($rrLiveProc)) proc_terminate($rrLiveProc); });
$rrAdmin = (int)$rrPdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='administrator' LIMIT 1")->fetchColumn();
$rrSid = 'rr' . bin2hex(random_bytes(12));
$rrSession = ['user_id' => $rrAdmin, 'csrf_token' => 'rr-csrf', 'edit_mode' => true];
$rrSerial = ''; foreach ($rrSession as $k => $v) $rrSerial .= $k . '|' . serialize($v);
file_put_contents($rrDir . '/sess_' . $rrSid, $rrSerial);
$rrPdo->exec('SET @audit_user_id=' . $rrAdmin);
$rrPdo->prepare('INSERT INTO courses(code,name,hours) VALUES(?,?,10)')->execute(['RR-' . bin2hex(random_bytes(4)), 'Reliability fixture']);
$rrCourse = (int)$rrPdo->lastInsertId();
$rrPdo->prepare("INSERT INTO course_groups(course_id,start_date,end_date,is_completed) VALUES(?,'2095-01-01','2095-01-05',0)")->execute([$rrCourse]);
$rrGroup = (int)$rrPdo->lastInsertId();
$rrExisting = array_map('intval', $rrPdo->query('SELECT id FROM students WHERE CAST(nr_amze AS UNSIGNED) BETWEEN 7977 AND 7982')->fetchAll(PDO::FETCH_COLUMN));
$rrMap = qta_tx($rrPdo, static function () use ($rrPdo, $rrGroup): array {
    $map = qta_amze_ensure_batch($rrPdo, range(7977, 7982));
    foreach ($map as $sid) $rrPdo->prepare('INSERT INTO course_group_students(group_id,student_id) VALUES(?,?)')->execute([$rrGroup, $sid]);
    return $map;
});
function rr_counts(PDO $pdo): array
{
    $out = [];
    foreach (['persons', 'users', 'students', 'course_group_students', 'student_course_plans', 'audit_events'] as $t) $out[$t] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
    return $out;
}
try {
    t_case('Reliability HTTP: 7977–7982 bëhet 7977–7981, shpejt dhe pa orphan', function () use ($rrPdo, $rrBase, $rrSid, $rrGroup, $rrMap) {
        $before = rr_counts($rrPdo);
        $r = rr_http($rrBase . '/pages/groups.php', $rrSid, ['csrf' => 'rr-csrf', 'action' => 'edit_members', 'group_id' => $rrGroup, 'amze_spec_members' => '7977-7981']);
        t_eq(302, $r['status'], 'POST ridrejton');
        t_ok($r['ms'] < 2000, 'mutation përfundon brenda 2s');
        echo '  group edit: ', round($r['ms'], 1), " ms\n";
        $q = $rrPdo->prepare('SELECT student_id FROM course_group_students WHERE group_id=? ORDER BY student_id'); $q->execute([$rrGroup]);
        t_eq(array_slice(array_values($rrMap), 0, 5), array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), 'vetëm 7982 hiqet');
        $after = rr_counts($rrPdo);
        foreach (['persons', 'users', 'students'] as $t) t_eq($before[$t], $after[$t], $t . ': nuk krijohen records');
        t_ok(str_contains(rr_http($rrBase . '/pages/groups.php', $rrSid)['body'], 'Kursantët e grupit u ruajtën.'), 'success flash mbërrin pas redirect');
    });
    t_case('Reliability HTTP: live DB read dhe mutation paralel me të njëjtën seancë', function () use ($rrDir, $rrLivePort, $rrBase, $rrSid, $rrGroup) {
        $worker = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/http_worker.php', 'http://127.0.0.1:' . $rrLivePort . '/_test/slow-live', $rrSid],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            $limit = microtime(true) + 5;
            while (!is_file($rrDir . '/slow_ready') && microtime(true) < $limit) { clearstatcache(); usleep(10000); }
            t_ok(is_file($rrDir . '/slow_ready'), 'live request përfundoi authentication dhe nisi DB work');
            $r = rr_http($rrBase . '/pages/groups.php', $rrSid, ['csrf' => 'rr-csrf', 'action' => 'edit_members', 'group_id' => $rrGroup, 'amze_spec_members' => '7977-7981']);
            t_eq(302, $r['status'], 'mutation përfundon gjatë live request');
            t_ok($r['ms'] < 800, 'nuk pret SELECT SLEEP(1.5) të live request');
            echo '  concurrent live + mutation: ', round($r['ms'], 1), " ms\n";
            t_ok(proc_get_status($worker)['running'], 'live request ende aktiv kur mutation përfundon');
        } finally {
            foreach ($pipes as $pipe) fclose($pipe);
            proc_terminate($worker); proc_close($worker);
        }
    });
    t_case('Reliability HTTP: validim pas auto-krijimit rikthen gjithë DB work', function () use ($rrPdo, $rrBase, $rrSid, $rrGroup, $rrCourse, $rrMap) {
        $rrPdo->prepare("INSERT INTO course_groups(course_id,start_date,end_date,is_completed) VALUES(?,'2095-02-01','2095-02-05',0)")->execute([$rrCourse]);
        $other = (int)$rrPdo->lastInsertId();
        // Same-period group: this case tests occupied membership and rollback,
        // independently of the explicit date-reconciliation policy.
        $rrPdo->prepare("UPDATE course_groups SET start_date='2095-01-01',end_date='2095-01-05' WHERE id=?")->execute([$other]);
        $rrPdo->prepare('INSERT INTO course_group_students(group_id,student_id) VALUES(?,?)')->execute([$other, $rrMap[7982]]);
        $before = rr_counts($rrPdo);
        rr_http($rrBase . '/pages/groups.php', $rrSid, ['csrf' => 'rr-csrf', 'action' => 'edit_members', 'group_id' => $rrGroup, 'amze_spec_members' => '7977-7982,999999998']);
        t_eq($before, rr_counts($rrPdo), 'validimi pas krijimit nuk lë as persons/users/students, as audit parcial');
        rr_http($rrBase . '/pages/groups.php', $rrSid, ['csrf' => 'rr-csrf', 'action' => 'create_group', 'course_id' => $rrCourse,
            'start_date' => '01.03.2095', 'end_date' => '05.03.2095', 'amze_spec' => '7982,999999997']);
        t_eq($before, rr_counts($rrPdo), 'krijimi i grupit gjithashtu është atomik');
    });
    t_case('Reliability HTTP: DB exception, rollback, reference dhe log', function () use ($rrPdo, $rrBase, $rrSid, $rrGroup, $rrLog) {
        $trigger = 'rr_failure_' . bin2hex(random_bytes(4));
        $rrPdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON course_group_students FOR EACH ROW BEGIN IF NEW.group_id=$rrGroup THEN SIGNAL SQLSTATE 'HY000' SET MESSAGE_TEXT='rr_injected_failure'; END IF; END");
        try {
            $before = rr_counts($rrPdo);
            rr_http($rrBase . '/pages/groups.php', $rrSid, ['csrf' => 'rr-csrf', 'action' => 'edit_members', 'group_id' => $rrGroup, 'amze_spec_members' => '7977-7981,999999996']);
            t_eq($before, rr_counts($rrPdo), 'DB exception rikthen gjithë transaksionin');
            $html = rr_http($rrBase . '/pages/groups.php', $rrSid)['body'];
            t_ok(str_contains($html, 'Referenca:') && !str_contains($html, 'rr_injected_failure'), 'përdoruesi merr referencë, pa SQL');
            t_ok(str_contains(file_get_contents($rrLog), 'PDOException'), 'serveri logon klasën e exception');
        } finally { $rrPdo->exec('DROP TRIGGER ' . $trigger); }
    });
    t_case('Reliability: InnoDB lock wait i kufizuar dhe rollback', function () use ($rrPdo, $rrGroup) {
        $second = getPDO(); $second->exec('SET SESSION innodb_lock_wait_timeout=1');
        $rrPdo->beginTransaction();
        try {
            $rrPdo->exec('UPDATE course_groups SET is_completed=is_completed WHERE id=' . $rrGroup);
            $start = microtime(true);
            $e = t_throws(PDOException::class, fn() => qta_tx($second, fn() => $second->exec('UPDATE course_groups SET is_completed=is_completed WHERE id=' . $rrGroup)), 'lock wait skadon');
            t_ok($e !== null && qta_database_busy($e), 'klasifikohet si database busy');
            t_ok(microtime(true) - $start < 3, 'nuk pret minuta');
            t_eq(false, $second->inTransaction(), 'transaction rollback pas timeout');
        } finally { $rrPdo->rollBack(); }
    });
    t_case('Reliability HTTP: add_amze_for_person nuk lë user pas DB exception', function () use ($rrPdo, $rrBase, $rrSid) {
        $rrPdo->exec("INSERT INTO persons(gender_id) SELECT id FROM genders WHERE code='M' LIMIT 1");
        $pid = (int)$rrPdo->lastInsertId();
        $trigger = 'rr_student_failure_' . bin2hex(random_bytes(4));
        $rrPdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON students FOR EACH ROW BEGIN IF NEW.nr_amze='999999995' THEN SIGNAL SQLSTATE 'HY000' SET MESSAGE_TEXT='rr_student_failure'; END IF; END");
        try {
            $before = rr_counts($rrPdo);
            $r = rr_http($rrBase . '/actions/student_card_inline.php', $rrSid, ['csrf' => 'rr-csrf', 'action' => 'add_amze_for_person', 'person_id' => $pid, 'nr_amze' => '999999995']);
            t_eq(500, $r['status'], 'DB exception ka status serveri');
            t_eq($before, rr_counts($rrPdo), 'user/student/audit rikthehen së bashku');
            t_ok(str_contains($r['body'], 'Referenca:') && !str_contains($r['body'], 'rr_student_failure'), 'gabimi nuk ekspozon SQL');
        } finally {
            $rrPdo->exec('DROP TRIGGER ' . $trigger);
            $rrPdo->prepare('DELETE FROM persons WHERE id=?')->execute([$pid]);
        }
    });
    t_case('Reliability: batch AMZË ruan zerot në fillim dhe nuk krijon duplikat', function () use ($rrPdo) {
        $rrPdo->beginTransaction();
        try {
            $sid = qta_amze_ensure_batch($rrPdo, [999999994])[999999994];
            $rrPdo->prepare("UPDATE students SET nr_amze='0999999994' WHERE id=?")->execute([$sid]);
            $before = rr_counts($rrPdo);
            t_eq($sid, qta_amze_ensure_batch($rrPdo, [999999994])[999999994], 'gjen të njëjtin regjistrim numerik');
            t_eq($before, rr_counts($rrPdo), 'nuk krijohen records të rinj');
        } finally { $rrPdo->rollBack(); }
    });
    t_case('Reliability HTTP: uncaught DB error nuk ekspozon payload', function () use ($rrLivePort, $rrLog) {
        $r = rr_http('http://127.0.0.1:' . $rrLivePort . '/_test/uncaught-db');
        t_eq(500, $r['status'], 'uncaught failure ka status serveri');
        t_ok(str_contains($r['body'], 'Referenca:') && !str_contains($r['body'], 'rr_uncaught_sensitive_payload'), 'body me reference pa payload');
        t_ok(!str_contains(file_get_contents($rrLog), 'rr_uncaught_sensitive_payload'), 'private log nuk permban payload exception');
    });
    t_case('Reliability HTTP: server log pa warning/fatal', function () use ($rrLog) {
        t_ok(!preg_match('/PHP (Warning|Fatal|Notice|Deprecated|Parse)/i', file_get_contents($rrLog)), 'PHP runtime i pastër');
    });
} finally {
    proc_terminate($rrLiveProc); proc_close($rrLiveProc);
    proc_terminate($rrProc); proc_close($rrProc);
    foreach (glob($rrDir . '/*') ?: [] as $f) unlink($f);
    rmdir($rrDir);
    $rrPdo->prepare('DELETE FROM course_groups WHERE course_id=?')->execute([$rrCourse]);
    $rrPdo->prepare('DELETE FROM courses WHERE id=?')->execute([$rrCourse]);
    foreach (array_diff(array_values($rrMap), $rrExisting) as $sid) {
        $q = $rrPdo->prepare('SELECT user_id,person_id FROM students WHERE id=?'); $q->execute([$sid]); $s = $q->fetch();
        $rrPdo->prepare('DELETE FROM students WHERE id=?')->execute([$sid]);
        $rrPdo->prepare('DELETE FROM users WHERE id=?')->execute([$s['user_id']]);
        $rrPdo->prepare('DELETE FROM persons WHERE id=?')->execute([$s['person_id']]);
    }
}
