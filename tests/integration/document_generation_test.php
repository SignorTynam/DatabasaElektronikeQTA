<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/document_generation.php';

$dgPdo = getPDO();
$dgDb = (string)$dgPdo->query('SELECT DATABASE()')->fetchColumn();
if (getenv('QTA_TEST_DB') !== '1' || !str_contains($dgDb, 'test') || $dgDb === 'qta_db') {
    throw new RuntimeException('Document tests require an isolated test database.');
}
$dgDir = getenv('QTA_DOCUMENT_TEST_SESSION_DIR') ?: sys_get_temp_dir() . '/qta-doc-test-' . bin2hex(random_bytes(5));
if (!is_dir($dgDir)) mkdir($dgDir, 0700, true);
$dgBase = getenv('QTA_DOCUMENT_TEST_URL');
if (!$dgBase) {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1); fclose($probe);
    $dgBase = 'http://127.0.0.1:' . $port;
    $php = [PHP_BINARY, '-d', 'session.save_path=' . $dgDir, '-d', 'display_errors=0'];
    if (getenv('QTA_DOCUMENT_TEST_ZIP') === '1') array_push($php, '-d', 'extension=zip');
    $proc = proc_open(array_merge($php, [
        '-S', '127.0.0.1:' . $port, '-t', realpath(__DIR__ . '/../..'), realpath(__DIR__ . '/../fixtures/browser_router.php')],
        ), [0 => ['pipe', 'r'], 1 => ['file', $dgDir . '/server.log', 'a'], 2 => ['file', $dgDir . '/server.log', 'a']], $pipes);
    fclose($pipes[0]);
    register_shutdown_function(static function () use ($proc): void { if (is_resource($proc)) proc_terminate($proc); });
    for ($i = 0; $i < 100; $i++) {
        try { $connection = fsockopen('127.0.0.1', $port, $errno, $error, .1); fclose($connection); break; }
        catch (Throwable) {}
        usleep(50000);
    }
}
function dg_http(string $route, ?string $sid = null, ?array $form = null): array
{
    $curl = curl_init($GLOBALS['dgBase'] . $route);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIE => $sid ? 'PHPSESSID=' . $sid : '', CURLOPT_FOLLOWLOCATION => false]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl); curl_close($curl);
    if ($body === false) throw new RuntimeException($error);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}
function dg_job(string $sid, array $fields): array
{
    $start = dg_http('/document_generation.php', $sid, $fields + ['csrf' => 'document-test-csrf']);
    if ($start['status'] !== 200 || empty($start['json']['id'])) throw new RuntimeException('Job did not start: ' . $start['body']);
    $id = $start['json']['id'];
    for ($i = 0; $i < 600; $i++) {
        $result = dg_http('/document_generation.php?action=status&id=' . $id, $sid);
        if (($result['json']['status'] ?? '') !== 'working') return $result['json'] + ['id' => $id];
        usleep(100000);
    }
    throw new RuntimeException('Test did not complete');
}
$dgSessions = [];
foreach (['administrator', 'editor', 'agjencia', 'student'] as $role) {
    $query = $dgPdo->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name=? ORDER BY u.id LIMIT 1');
    $query->execute([$role]); $uid = (int)$query->fetchColumn();
    if (!$uid) throw new RuntimeException('Seed synthetic identities before running document integration tests');
    $sid = 'doc' . bin2hex(random_bytes(12));
    file_put_contents($dgDir . '/sess_' . $sid, 'user_id|' . serialize($uid) . 'csrf_token|' . serialize('document-test-csrf'));
    $dgSessions[$role] = $sid;
}
$dgGroup = (int)$dgPdo->query("SELECT id FROM course_groups WHERE model='legacy' ORDER BY id LIMIT 1")->fetchColumn();
$dgScheduled = (int)$dgPdo->query('SELECT group_id FROM group_schedules ORDER BY group_id LIMIT 1')->fetchColumn();

t_case('document HTTP: all existing renderers produce complete private downloads', static function () use ($dgSessions, $dgGroup, $dgScheduled): void {
    $cases = [
        ['students_export.php', 'f', ['pdf', 'xlsx', 'docx'], [], 'administrator'],
        ['groups_export.php', 'f', ['pdf', 'xlsx'], ['type' => 'qkl', 'amze_start' => 7977, 'amze_end' => 7982], 'administrator'],
        ['download_proces_verbal.php', 'f', ['pdf', 'xlsx', 'docx'], ['group_id' => $dgGroup], 'editor'],
        ['download_lista_emerore.php', 'format', ['pdf', 'doc'], ['group_id' => $dgGroup], 'administrator'],
        ['download_praktika_profesionale.php', 'format', ['pdf', 'doc'], ['group_id' => $dgGroup], 'administrator'],
        ['download_rregullat_sigurimi_teknik.php', 'format', ['pdf', 'doc'], ['group_id' => $dgGroup], 'administrator'],
        ['download_regjistri_mesimit.php', 'format', ['pdf', 'docx'], ['group_id' => $dgScheduled], 'editor'],
        ['register_export_agency.php', 'f', ['pdf', 'xlsx', 'docx'], [], 'agjencia'],
        ['activity_log_export.php', 'f', ['csv'], [], 'administrator'],
        ['activity_log_export.php', 'f', ['csv'], [], 'editor'],
    ];
    foreach ($cases as [$endpoint, $field, $formats, $params, $role]) foreach ($formats as $format) {
        $state = dg_job($dgSessions[$role], $params + ['_document_endpoint' => $endpoint, $field => $format]);
        $label = $endpoint . ':' . $format;
        t_eq('ready', $state['status'], $label . ' ready: ' . $state['message']);
        if ($state['status'] !== 'ready') continue;
        t_eq(100, $state['percent'], $label . ' completed progress');
        $file = dg_http('/document_generation.php?action=file&id=' . $state['id'], $dgSessions[$role]);
        t_eq(200, $file['status'], $label . ' downloads');
        t_eq($state['size'], strlen($file['body']), $label . ' exact file size');
        t_ok(str_starts_with(ltrim($file['body'], "\xEF\xBB\xBF \t\r\n"), $format === 'pdf' ? '%PDF-' : ($format === 'doc' ? '<' : ($format === 'csv' ? 'Data;' : 'PK'))), $label . ' correct file signature');
        $other = $role === 'student' ? 'administrator' : 'student';
        t_eq(404, dg_http('/document_generation.php?action=file&id=' . $state['id'], $dgSessions[$other])['status'], $label . ' other session denied');
        t_eq(404, dg_http('/document_generation.php?action=status&id=' . $state['id'], $dgSessions[$other])['status'], $label . ' metadata private');
    }
});
t_case('document HTTP: CSRF, permission, invalid routes and failed generation', static function () use ($dgSessions, $dgGroup): void {
    t_eq(401, dg_http('/document_generation.php')['status'], 'anonymous denied');
    t_eq(403, dg_http('/document_generation.php', $dgSessions['administrator'], ['csrf' => 'invalid'])['status'], 'invalid CSRF denied before generation');
    t_eq(400, dg_http('/document_generation.php', $dgSessions['administrator'], ['csrf' => 'document-test-csrf', '_document_endpoint' => '../database.php'])['status'], 'route traversal denied');
    foreach (['student', 'agjencia'] as $role) {
        $state = dg_job($dgSessions[$role], ['_document_endpoint' => 'download_lista_emerore.php', 'group_id' => $dgGroup, 'format' => 'pdf']);
        t_eq('error', $state['status'], $role . ': original export permission preserved');
        t_eq(409, dg_http('/document_generation.php?action=file&id=' . $state['id'], $dgSessions[$role])['status'], 'failed job never downloaded');
    }
    $state = dg_job($dgSessions['administrator'], ['_document_endpoint' => 'download_regjistri_mesimit.php', 'group_id' => $dgGroup, 'format' => 'pdf']);
    t_eq('error', $state['status'], 'legacy group has no lesson register');
});

if (getenv('QTA_DOCUMENT_TEST_URL')) t_case('document HTTP: Apache returns the job before rendering finishes', static function () use ($dgSessions): void {
    $start = dg_http('/document_generation.php', $dgSessions['administrator'],
        ['csrf' => 'document-test-csrf', '_document_endpoint' => 'students_export.php', 'f' => 'pdf']);
    $id = $start['json']['id'];
    $initial = qta_document_read($id);
    t_eq('working', $initial['status'], 'start response is released while generation is still running');
    $state = dg_http('/document_generation.php?action=status&id=' . $id, $dgSessions['administrator']);
    t_eq(200, $state['status'], 'progress can be requested concurrently');
    t_ok($state['json']['percent'] > 0 && $state['json']['percent'] < 100, 'server reports an unfinished real stage');
    for ($i = 0; $i < 600 && $state['json']['status'] === 'working'; $i++) {
        usleep(100000);
        $state = dg_http('/document_generation.php?action=status&id=' . $id, $dgSessions['administrator']);
    }
    t_eq('ready', $state['json']['status'], 'detached HTTP response does not cancel generation');
});
