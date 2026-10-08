<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/../shared/document_generation.php';

function qta_document_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE));
}

if (empty($_SESSION['user_id'])) qta_document_json(['error' => 'Sesioni ka mbaruar. Hyr sërish në llogari.'], 401);
$action = $_GET['action'] ?? 'start';
if ($action === 'status' || $action === 'file') {
    $id = (string)($_GET['id'] ?? '');
    $state = qta_document_read($id);
    if (!$state || !hash_equals($state['owner'], qta_document_owner())) {
        qta_document_json(['error' => 'Dokumenti nuk u gjet për këtë sesion.'], 404);
    }
    if ($action === 'status') {
        unset($state['owner']);
        qta_document_json($state);
    }
    if ($state['status'] !== 'ready') qta_document_json(['error' => 'Dokumenti po përgatitet ende.'], 409);
    $path = qta_document_directory() . '/' . $id . '.bin';
    $fh = @fopen($path, 'rb');
    if (!$fh) qta_document_json(['error' => 'Skedari nuk mund të lexohet. Provo përsëri.'], 404);
    header('Content-Type: ' . $state['mime']);
    header('Content-Disposition: attachment; filename="' . str_replace(["\r", "\n", '"'], '', $state['filename']) . '"');
    header('Content-Length: ' . $state['size']);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    fpassthru($fh);
    fclose($fh);
    exit;
}
if ($action !== 'start' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    qta_document_json(['error' => 'Kërkesë e pavlefshme.'], 405);
}
$csrf = $_POST['csrf'] ?? '';
if (!is_string($csrf) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf) || $csrf === '') {
    qta_document_json(['error' => 'Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.'], 403);
}
$endpoint = $_POST['_document_endpoint'] ?? '';
$allowed = ['students_export.php', 'groups_export.php', 'register_export_agency.php',
    'download_proces_verbal.php', 'download_lista_emerore.php', 'download_regjistri_mesimit.php',
    'download_praktika_profesionale.php', 'download_rregullat_sigurimi_teknik.php', 'activity_log_export.php'];
if (!is_string($endpoint) || !in_array($endpoint, $allowed, true)) {
    qta_document_json(['error' => 'Ky dokument nuk njihet.'], 400);
}

// An active job is never expired or restarted by polling. Retain completed files for one day.
foreach (glob(qta_document_directory() . '/*.json') ?: [] as $old) {
    if (filemtime($old) >= time() - 86400) continue;
    $oldId = basename($old, '.json');
    $oldState = qta_document_read($oldId);
    if (!empty($oldState['finished_at']) && $oldState['finished_at'] < time() - 86400) {
        @unlink(qta_document_directory() . '/' . $oldId . '.bin');
        @unlink($old);
    }
}
$id = bin2hex(random_bytes(24));
qta_document_write($id, ['owner' => qta_document_owner(), 'status' => 'working',
    'percent' => 0, 'message' => 'Po nis përgatitja e dokumentit.']);

// Finish the small HTTP response before generation. Apache consumes Content-Length;
// FPM also releases its response explicitly. The same PHP request starts work immediately.
$reply = json_encode(['id' => $id]);
while (ob_get_level() > 0) ob_end_clean();
ini_set('zlib.output_compression', '0');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($reply));
header('Connection: close');
ignore_user_abort(true);
set_time_limit(0);
echo $reply;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else flush();

try {
    qta_document_capture($id);
    unset($_POST['_document_endpoint']);
    $_GET = [];
    $_REQUEST = $_POST;
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../exports/' . $endpoint;
    require $_SERVER['SCRIPT_FILENAME'];
} catch (Throwable $e) {
    $reference = qta_request_exception($e);
    $state = $GLOBALS['qta_document_state'] ?? qta_document_read($id);
    $state['status'] = 'error';
    $state['message'] = 'Dokumenti nuk u krijua. Provo përsëri. Referenca: ' . $reference;
    $state['finished_at'] = time();
    $GLOBALS['qta_document_state'] = $state;
    qta_document_write($id, $state);
}
