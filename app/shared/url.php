<?php
declare(strict_types=1);

if (!function_exists('qta_base_url')) {
  function qta_base_url(): string {
    $configured = getenv('QTA_BASE_URL');
    if ($configured !== false && trim($configured) !== '') {
      $configured = trim(str_replace('\\', '/', $configured), '/');
      return $configured === '' ? '' : '/' . $configured;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $appPos = strpos($script, '/app/');
    $base = $appPos !== false ? substr($script, 0, $appPos) : dirname($script);
    $base = rtrim(str_replace('\\', '/', $base), '/.');
    return $base === '' || $base === '/' ? '' : '/' . ltrim($base, '/');
  }
}

if (!function_exists('qta_url')) {
  function qta_url(string $path = ''): string {
    $path = ltrim($path, '/');
    return qta_base_url() . ($path !== '' ? '/' . $path : '/');
  }
}

if (!function_exists('qta_asset')) {
  function qta_asset(string $path): string {
    $path = ltrim($path, '/');
    $full = dirname(__DIR__, 2) . '/' . $path;
    $stamp = is_file($full) ? (string)filemtime($full) : '1';
    return qta_url($path) . '?v=' . $stamp;
  }
}
