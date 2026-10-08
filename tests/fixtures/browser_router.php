<?php
declare(strict_types=1);
// Test server only; never a deployed route or authentication bypass.
if (PHP_SAPI !== 'cli-server' || getenv('QTA_TEST_DB') !== '1') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (str_contains($path, '..')) { http_response_code(404); exit; }
if ($path === '/') $path = '/index.php';
if ($path === '/_test/uncaught-db') {
    require_once $root . '/app/shared/session.php'; qta_session_boot();
    throw new PDOException('rr_uncaught_sensitive_payload');
}
if ($path === '/_test/slow-live') {
    require_once $root . '/app/shared/session.php'; qta_session_boot();
    require_once $root . '/app/shared/database.php';
    require_once $root . '/app/shared/staff_guard.php';
    $pdo = getPDO(); qta_json_require_staff($pdo);
    touch(session_save_path() . '/slow_ready');
    $pdo->query('SELECT SLEEP(1.5)');
    qta_json_out(['ok' => true]);
}
if (preg_match('#^/(app/assets/|image/)[a-zA-Z0-9_./-]+$#', $path)) {
    $file = $root . $path;
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
    if (is_file($file)) { header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream')); readfile($file); exit; }
}
if (preg_match('#^/([a-zA-Z0-9_-]+\.php)$#', $path, $m)) {
    foreach (['pages', 'actions', 'exports'] as $dir) {
        $file = $root . '/app/' . $dir . '/' . $m[1];
        if (is_file($file)) { $_SERVER['SCRIPT_FILENAME'] = $file; require $file; exit; }
    }
}
if (preg_match('#^/app/(pages|actions|exports)/[a-zA-Z0-9_-]+\.php$#', $path)) {
    $file = $root . $path;
    if (is_file($file)) { $_SERVER['SCRIPT_FILENAME'] = $file; require $file; exit; }
}
http_response_code(404);
