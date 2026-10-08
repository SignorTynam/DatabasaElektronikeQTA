<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/../shared/document_generation.php';
set_time_limit(0);
$csrf = $_POST['csrf'] ?? '';
if (!is_string($csrf) || $csrf === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    qta_download_status('error', 'Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.');
    http_response_code(403); exit;
}
require_once __DIR__ . '/../shared/database.php';
$pdo = getPDO();
$user = $pdo->prepare('SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');
$user->execute([$_SESSION['user_id'] ?? 0]);
$role = strtolower((string)$user->fetchColumn());
if (!in_array($role, ['administrator', 'editor'], true)) {
    qta_download_status('error', 'Nuk ke leje për këtë dokument.');
    http_response_code(403); exit;
}
// Reuse the existing list/export queries and editor's own-history scope.
$_GET = $_POST;
$_GET['export'] = 'csv';
qta_export_progress(30, 'Po përgatitet historiku i ndryshimeve.');
require __DIR__ . '/../pages/' . ($role === 'administrator' ? 'logs.php' : 'logs_editor.php');
