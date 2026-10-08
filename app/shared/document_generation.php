<?php
declare(strict_types=1);

/** Private, session-bound files. Generation has no time limit; only finished jobs are swept. */
function qta_document_directory(): string
{
    $dir = sys_get_temp_dir() . '/qta-documents-' . substr(hash('sha256', __DIR__), 0, 16);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Dosja e dokumenteve nuk mund të krijohet.');
    }
    return $dir;
}

function qta_document_owner(): string
{
    return hash('sha256', session_id() . ':' . (string)($_SESSION['user_id'] ?? ''));
}

function qta_document_read(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/D', $id)) return null;
    $path = qta_document_directory() . '/' . $id . '.json';
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    try {
        if (!flock($fh, LOCK_SH)) return null;
        $state = json_decode(stream_get_contents($fh), true);
        return is_array($state) ? $state : null;
    } finally { fclose($fh); }
}

function qta_document_write(string $id, array $state): void
{
    $fh = fopen(qta_document_directory() . '/' . $id . '.json', 'c+b');
    if (!$fh) throw new RuntimeException('Gjendja e dokumentit nuk mund të ruhet.');
    try {
        if (!flock($fh, LOCK_EX)) throw new RuntimeException('Gjendja e dokumentit nuk mund të ruhet.');
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        fflush($fh);
    } finally { fclose($fh); }
}

function qta_export_progress(int $percent, string $message): void
{
    if (empty($GLOBALS['qta_document_job'])) return;
    $state =& $GLOBALS['qta_document_state'];
    if ($state['status'] !== 'working') return;
    $state['percent'] = max($state['percent'], min(95, $percent));
    $state['message'] = $message;
    qta_document_write($GLOBALS['qta_document_job'], $state);
}

/** Preserve ordinary direct exports; the job runner captures attachment metadata. */
function qta_export_header(string $header): void
{
    if (empty($GLOBALS['qta_document_job'])) { header($header); return; }
    [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
    $GLOBALS['qta_document_headers'][strtolower(trim($name))] = trim($value);
}

function qta_download_status(string $status, string $message): void
{
    if (!empty($GLOBALS['qta_document_job'])) {
        // Legacy exporters signal "ok" before rendering. Only shutdown can mark ready.
        if (!in_array($status, ['ok', 'success'], true)) {
            $GLOBALS['qta_document_state']['status'] = 'error';
            $GLOBALS['qta_document_state']['message'] = $message;
            qta_document_write($GLOBALS['qta_document_job'], $GLOBALS['qta_document_state']);
        } else {
            qta_export_progress(45, 'Po krijohet skedari i dokumentit.');
        }
        return;
    }
    foreach (['qta_file_ready' => $status, 'qta_file_msg' => $message] as $name => $value) {
        setcookie($name, $value, ['expires' => time() + 60, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']), 'httponly' => false, 'samesite' => 'Lax']);
    }
}

/** Never remove the runner's output capture when an exporter clears stray output. */
function qta_export_clean_output(): void
{
    $floor = $GLOBALS['qta_document_buffer_level'] ?? 0;
    while (ob_get_level() > $floor) ob_end_clean();
    if ($floor && ob_get_level() === $floor) ob_clean();
}

function qta_document_capture(string $id): void
{
    $GLOBALS['qta_document_job'] = $id;
    $GLOBALS['qta_document_state'] = qta_document_read($id);
    $GLOBALS['qta_document_headers'] = [];
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ignore_user_abort(true);
    set_time_limit(0);
    $path = qta_document_directory() . '/' . $id . '.bin';
    $out = fopen($path, 'wb');
    if (!$out) throw new RuntimeException('Skedari i dokumentit nuk mund të krijohet.');
    ob_start(static function (string $bytes, int $phase) use ($out): string {
        if (!($phase & PHP_OUTPUT_HANDLER_CLEAN) && $GLOBALS['qta_document_state']['status'] === 'working') {
            if ($bytes !== '' && fwrite($out, $bytes) !== strlen($bytes)) {
                $GLOBALS['qta_document_state']['status'] = 'error';
                $GLOBALS['qta_document_state']['message'] = 'Dokumenti nuk mund të ruhet plotësisht. Provo përsëri.';
            }
        }
        return '';
    }, 65536);
    $GLOBALS['qta_document_buffer_level'] = ob_get_level();
    $GLOBALS['qta_document_shutdown_reserve'] = str_repeat(' ', 262144);
    register_shutdown_function(static function () use ($id, $path, $out): void {
        unset($GLOBALS['qta_document_shutdown_reserve']);
        $last = error_get_last();
        $fatal = $last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        while (ob_get_level() > 0) ob_end_flush();
        fflush($out);
        fclose($out);
        clearstatcache(true, $path);
        $state = $GLOBALS['qta_document_state'];
        $headers = $GLOBALS['qta_document_headers'];
        $size = filesize($path);
        $disposition = $headers['content-disposition'] ?? '';
        $complete = !$fatal && (http_response_code() ?: 200) < 400 && $size > 0
            && preg_match('/filename="([^"]+)"/', $disposition, $match)
            && (!isset($headers['content-length']) || (int)$headers['content-length'] === $size);
        if ($state['status'] === 'working' && $complete) {
            $state = array_merge($state, ['status' => 'ready', 'percent' => 100,
                'message' => 'Dokumenti është gati.', 'filename' => basename($match[1]),
                'mime' => $headers['content-type'] ?? 'application/octet-stream', 'size' => $size]);
        } else {
            if ($state['status'] !== 'error') $state['message'] = 'Dokumenti nuk u krijua. Provo përsëri; nëse përsëritet, njofto administratorin.';
            $state['status'] = 'error';
            @unlink($path);
        }
        $state['finished_at'] = time();
        qta_document_write($id, $state);
    });
    qta_export_progress(10, 'Po kontrollohen të dhënat dhe lejet.');
}
