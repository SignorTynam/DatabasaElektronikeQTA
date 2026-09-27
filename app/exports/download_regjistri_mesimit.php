<?php
declare(strict_types=1);

/**
 * download_regjistri_mesimit.php — "Regjistri i orëve të mësimit" i një grupi me
 * orar, në PDF ose Word (.docx).
 *
 * POST nga "Dokumentet e grupit" (lesson_group.php): csrf, group_id, format = pdf|docx.
 * Vetëm administratorët dhe redaktorët; vetëm grupet me orar (jo grupet e mëparshme).
 * Faqet tek janë regjistri i prezencës, faqet çift datat dhe temat e modulit —
 * shih app/shared/lesson_register.php.
 */

session_start();
mb_internal_encoding('UTF-8');
ob_start();

require_once __DIR__ . '/database.php';
$pdo = getPDO();

require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

function qta_download_status(string $status, string $message): void {
  foreach (['qta_file_ready' => $status, 'qta_file_msg' => $message] as $name => $value) {
    setcookie($name, $value, [
      'expires'  => time() + 60,
      'path'     => '/',
      'secure'   => !empty($_SERVER['HTTPS']),
      'httponly' => false,
      'samesite' => 'Lax',
    ]);
  }
}

function qta_fail(int $code, string $message): never {
  while (ob_get_level() > 0) { @ob_end_clean(); }
  qta_download_status('error', $message);
  http_response_code($code);
  header('Content-Type: text/plain; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');
  exit($message);
}

register_shutdown_function(function () {
  $err = error_get_last();
  if (!$err) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (in_array($err['type'] ?? 0, $fatalTypes, true) && !headers_sent()) {
    qta_download_status('error', 'Regjistri nuk u krijua. Provo sërish; nëse përsëritet, njofto administratorin.');
  }
});

function qta_audit_event(string $type, array $payload): void {
  try {
    if (function_exists('qta_audit_log')) {
      qta_audit_log($GLOBALS['pdo'] ?? null, $type, $payload);
    }
  } catch (Throwable $e) {}
}

/* ===== Kush e kërkon ===== */
if (!isset($_SESSION['user_id'])) {
  qta_fail(401, 'Sesioni ka mbaruar. Hyr sërish në llogari dhe provo përsëri.');
}
$u = $pdo->prepare('SELECT u.id, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id LIMIT 1');
$u->execute([':id' => $_SESSION['user_id']]);
$me = $u->fetch(PDO::FETCH_ASSOC);
$role = strtolower((string)($me['role_name'] ?? ''));
if (!$me || !in_array($role, ['administrator', 'editor'], true)) {
  qta_fail(403, 'Regjistri i orëve krijohet vetëm nga administratorët dhe redaktorët.');
}

/* ===== Vetëm POST me token (tokeni nuk del kurrë në URL) ===== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  qta_fail(405, 'Regjistri shkarkohet nga butonat PDF ose Word te "Dokumentet e grupit".');
}
$csrfSession = (string)($_SESSION['csrf_token'] ?? '');
$csrfPosted = $_POST['csrf'] ?? '';
if ($csrfSession === '' || !is_string($csrfPosted) || !hash_equals($csrfSession, $csrfPosted)) {
  qta_fail(403, 'Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.');
}

/* ===== Çfarë kërkohet ===== */
$groupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($groupId === false) {
  qta_fail(400, 'Grupi nuk u gjet. Rifresko faqen dhe provo sërish.');
}
$format = strtolower(trim((string)($_POST['format'] ?? '')));
if (!in_array($format, ['pdf', 'docx'], true)) {
  qta_fail(400, 'Zgjidh formatin e regjistrit: PDF ose Word.');
}

$autoload = __DIR__ . '/vendor/autoload.php';
$rootAutoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($rootAutoload)) {
  error_log('[QTA regjistri] mungon vendor/autoload.php (composer install)');
  qta_fail(500, 'Regjistri nuk mund të krijohet tani, sepse në server mungojnë programet e dokumenteve. Njofto administratorin.');
}
require_once $autoload;
require_once __DIR__ . '/inc/lesson_register_documents.php';

@ini_set('memory_limit', '512M');
@set_time_limit(120);

/* ===== Modeli: orari i ruajtur dhe kopja e temave të grupit ===== */
try {
  $model = qta_lesson_register_build($pdo, (int)$groupId);
} catch (QtaUserError $e) {
  $code = (string)($e->data['code'] ?? '');
  if ($code === 'legacy_group') {
    qta_fail(409, 'Regjistri i orëve krijohet vetëm për grupet me orar mësimi. Grupi #' . (int)$groupId . ' është grup i mëparshëm, pa orar.');
  }
  qta_fail($code === 'no_schedule' ? 409 : 404, $e->getMessage());
} catch (Throwable $e) {
  $ref = bin2hex(random_bytes(4));
  error_log('[QTA regjistri ' . $ref . '] grupi ' . (int)$groupId . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
  qta_fail(500, 'Regjistri nuk u krijua. Provo sërish; nëse përsëritet, njofto administratorin. Referenca: ' . $ref);
}

/* ===== Dokumenti ===== */
$tmp = tempnam(sys_get_temp_dir(), 'qta_lr_');
try {
  if ($format === 'pdf') {
    $pdf = qta_lr_render_pdf($model);
    if ($pdf['pages'] !== $model['page_count']) {
      /* Faqet tek/çift do të dilnin të ngatërruara: më mirë asnjë dokument se një regjistër i gabuar. */
      throw new RuntimeException('PDF me ' . $pdf['pages'] . ' faqe, modeli ' . $model['page_count']);
    }
    file_put_contents($tmp, $pdf['bytes']);
    $mime = 'application/pdf';
  } else {
    qta_lr_render_docx($model, $tmp);
    $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
  }
} catch (Throwable $e) {
  @unlink($tmp);
  $ref = bin2hex(random_bytes(4));
  error_log('[QTA regjistri ' . $ref . '] grupi ' . (int)$groupId . ', ' . $format . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
  qta_fail(500, 'Regjistri nuk u krijua. Provo sërish; nëse përsëritet, njofto administratorin. Referenca: ' . $ref);
}

qta_audit_event('lesson_register.download', [
  'group_id'      => (int)$groupId,
  'format'        => $format,
  'actor_user_id' => $_SESSION['user_id'] ?? null,
]);

/* ===== Dërgimi ===== */
if (function_exists('ini_get') && ini_get('zlib.output_compression')) {
  @ini_set('zlib.output_compression', 'Off');
}
while (ob_get_level() > 0) { @ob_end_clean(); }

$filename = qta_lr_filename((int)$groupId, $format, (string)$model['generated_on']);
header('X-File-Download: 1');
qta_download_status('ok', 'Regjistri i orëve të mësimit u krijua.');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');
$size = @filesize($tmp);
if ($size !== false) header('Content-Length: ' . $size);
readfile($tmp);
@unlink($tmp);
exit;
