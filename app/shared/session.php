<?php
declare(strict_types=1);

require_once __DIR__ . '/request.php';

/** A request reads a snapshot. Only small, explicit state updates reacquire the lock. */
function qta_session_boot(): void
{
    if (!empty($GLOBALS['qta_session_booted'])) return;
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    foreach (['csrf_token', 'csrf_login'] as $key) {
        if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(24));
    }
    $GLOBALS['qta_session_actor'] = $_SESSION['user_id'] ?? null;
    $GLOBALS['qta_session_booted'] = true;
    session_write_close();
}

/** Re-read fresh state: never write the request's old snapshot over another request. */
function qta_session_update(callable $change): void
{
    $active = session_status() === PHP_SESSION_ACTIVE;
    if (!$active) {
        // Reuse the ID without issuing cookies/cache headers; update before rendering.
        if (!session_start(['use_cookies' => false, 'cache_limiter' => ''])) {
            throw new RuntimeException('Session updates must happen before rendering.');
        }
    }
    try {
        // A request finishing after logout/login must not write into a different account.
        if (array_key_exists('qta_session_actor', $GLOBALS)
            && ($_SESSION['user_id'] ?? null) !== $GLOBALS['qta_session_actor']) return;
        $change();
    } finally {
        if (!$active) session_write_close();
    }
}

function qta_session_put(array $path, $value): void
{
    qta_session_update(static function () use ($path, $value): void {
        $slot =& $_SESSION;
        foreach ($path as $key) $slot =& $slot[$key];
        $slot = $value;
    });
}

function qta_session_push(array $path, $value): void
{
    qta_session_update(static function () use ($path, $value): void {
        $slot =& $_SESSION;
        foreach ($path as $key) $slot =& $slot[$key];
        if (!is_array($slot)) $slot = [];
        $slot[] = $value;
    });
}

function qta_session_take(array $path, $default = null)
{
    $value = $default;
    qta_session_update(static function () use ($path, $default, &$value): void {
        $key = array_pop($path);
        $slot =& $_SESSION;
        foreach ($path as $part) {
            if (!isset($slot[$part]) || !is_array($slot[$part])) return;
            $slot =& $slot[$part];
        }
        $value = $slot[$key] ?? $default;
        unset($slot[$key]);
    });
    return $value;
}
