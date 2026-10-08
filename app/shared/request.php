<?php
declare(strict_types=1);

/** Payload-free request diagnostics, shared by pages, actions and exports. */
function qta_request_id(): string
{
    if (!isset($GLOBALS['qta_request_id'])) {
        $GLOBALS['qta_request_id'] = bin2hex(random_bytes(6));
        $GLOBALS['qta_request_started'] = microtime(true);
        if (!headers_sent()) header('X-QTA-Request-ID: ' . $GLOBALS['qta_request_id']);
        register_shutdown_function(static function (): void {
            if (PHP_SAPI === 'cli') return;
            $ms = (microtime(true) - $GLOBALS['qta_request_started']) * 1000;
            $last = error_get_last();
            $fatal = $last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
            if ($ms < 2000 && !$fatal && empty($GLOBALS['qta_request_exception'])) return;
            error_log('[QTA ' . ($ms >= 2000 ? 'SLOW REQUEST' : 'REQUEST ERROR') . '] ' . json_encode([
                'request_id' => $GLOBALS['qta_request_id'],
                'endpoint' => basename($_SERVER['SCRIPT_FILENAME'] ?? ''),
                'action' => $GLOBALS['qta_request_action'] ?? null,
                'actor_user_id' => $GLOBALS['qta_session_actor'] ?? null,
                'elapsed_ms' => round($ms),
                'transaction_ms' => round($GLOBALS['qta_transaction_ms'] ?? 0),
                'status' => http_response_code() ?: 200,
                'exception_class' => $GLOBALS['qta_request_exception'] ?? ($fatal ? 'PHPFatalError' : null),
            ], JSON_UNESCAPED_SLASHES));
        });
    }
    return $GLOBALS['qta_request_id'];
}

function qta_request_action($value): void
{
    // Only action identifiers, never free text or other submitted fields.
    if (is_string($value) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $value)) {
        $GLOBALS['qta_request_action'] = $value;
    }
}

function qta_request_exception(Throwable $e): string
{
    $GLOBALS['qta_request_exception'] = get_class($e);
    $ref = qta_request_id();
    // SQL and bound values can contain personal information: log codes and source only.
    error_log('[QTA ' . $ref . '] ' . get_class($e) . ' code=' . $e->getCode()
        . ' driver=' . ($e instanceof PDOException ? ($e->errorInfo[1] ?? '') : '')
        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    return $ref;
}

function qta_database_busy(Throwable $e): bool
{
    return $e instanceof PDOException && (in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213, 3572], true)
        || (string)$e->getCode() === '40001');
}

function qta_error_status(Throwable $e): int
{
    if (qta_database_busy($e)) return 409;
    if ($e instanceof PDOException) return ($e->errorInfo[0] ?? '') === '45000' ? 400 : 500;
    return $e instanceof RuntimeException ? 400 : 500;
}

/** Legacy domain exceptions keep their messages; infrastructure errors stay private. */
function qta_error_message(Throwable $e): string
{
    if ($e instanceof PDOException && ($e->errorInfo[0] ?? '') === '45000' && !empty($e->errorInfo[2])) {
        return (string)$e->errorInfo[2];
    }
    if ($e instanceof RuntimeException && !$e instanceof PDOException) return $e->getMessage();
    $ref = qta_request_exception($e);
    $lead = qta_database_busy($e)
        ? 'Të dhënat po përpunohen nga një veprim tjetër. Kontrollo gjendjen dhe provo sërish.'
        : 'Veprimi nuk përfundoi siç pritej. Kontrollo nëse ndryshimi është ruajtur para se ta provosh përsëri.';
    return $lead . ' Referenca: ' . $ref;
}

qta_request_id();
qta_request_action($_POST['action'] ?? $_GET['action'] ?? null);

// Also cover failures before a legacy endpoint reaches its local try/catch
// (for example the role lookup). Never render/log an uncaught PDO payload.
if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $e): void {
        $ref = qta_request_exception($e);
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $rollbackError) { qta_request_exception($rollbackError); }
        }
        http_response_code(qta_database_busy($e) ? 409 : 500);
        $message = 'Shërbimi nuk e përfundoi kërkesën. Kontrollo gjendjen para se ta provosh përsëri. Referenca: ' . $ref;
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => $message, 'request_id' => $ref], JSON_UNESCAPED_UNICODE);
        } else {
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        }
    });
}
