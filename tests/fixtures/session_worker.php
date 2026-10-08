<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$script, $mode, $dir, $id] = $argv;
ini_set('session.save_path', $dir);
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_id($id);
require_once __DIR__ . '/../../app/shared/session.php';
$start = microtime(true);
if ($mode === 'hold') {
    session_start();
    $_SESSION['user_id'] = 1;
    touch($dir . '/ready');
    usleep(1500000);
    session_write_close();
} elseif ($mode === 'release' || $mode === 'flash') {
    qta_session_boot();
    touch($dir . '/ready');
    usleep(1500000);
    if ($mode === 'flash') qta_session_put(['flash', 'ok'], 'Ruajtur');
} elseif ($mode === 'toggle') {
    qta_session_boot();
    qta_session_put(['edit_mode'], true);
} elseif ($mode === 'audit') {
    qta_session_boot();
    require __DIR__ . '/../../app/shared/inc/audit_bootstrap.php';
    echo json_encode(['active' => session_status() === PHP_SESSION_ACTIVE]);
} else {
    qta_session_boot();
    echo json_encode(['elapsed_ms' => round((microtime(true) - $start) * 1000), 'session' => $_SESSION]);
}
