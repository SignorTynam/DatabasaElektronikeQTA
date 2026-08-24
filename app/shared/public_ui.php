<?php
declare(strict_types=1);

if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('qta_public_current_user')) {
  function qta_public_current_user(PDO $pdo): ?array {
    if (empty($_SESSION['user_id'])) {
      return null;
    }

    $stmt = $pdo->prepare("
      SELECT u.id, u.full_name, u.email, r.name AS role_name
      FROM users u
      JOIN roles r ON r.id = u.role_id
      WHERE u.id = :uid
      LIMIT 1
    ");
    $stmt->execute([':uid' => $_SESSION['user_id']]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('qta_public_role')) {
  function qta_public_role(?array $currentUser): string {
    return strtolower((string)($currentUser['role_name'] ?? ''));
  }
}

if (!function_exists('qta_public_panel_href')) {
  function qta_public_panel_href(?string $role): string {
    return match (strtolower((string)$role)) {
      'administrator' => 'dashboard_admin.php',
      'editor' => 'dashboard_editor.php',
      'agjencia', 'agency' => 'dashboard_agjencia.php',
      'student' => 'dashboard_student.php',
      default => 'selectProfile.php',
    };
  }
}

if (!function_exists('qta_public_panel_label')) {
  function qta_public_panel_label(?string $role): string {
    return match (strtolower((string)$role)) {
      'administrator' => 'Paneli i administrimit',
      'editor' => 'Paneli i editorit',
      'agjencia', 'agency' => 'Paneli i agjencisë',
      'student' => 'Paneli i studentit',
      default => 'Zgjidh profilin',
    };
  }
}

if (!function_exists('qta_public_initials')) {
  function qta_public_initials(?string $name): string {
    $name = trim((string)$name);
    if ($name === '') {
      return 'Q';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach ($parts as $part) {
      $letter = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
      $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($letter, 'UTF-8') : strtoupper($letter);
      $length = function_exists('mb_strlen') ? mb_strlen($initials, 'UTF-8') : strlen($initials);
      if ($length >= 2) {
        break;
      }
    }

    return $initials ?: 'Q';
  }
}

if (!function_exists('qta_public_active')) {
  function qta_public_active(string $slug, ?string $active): string {
    return $slug === $active ? 'active' : '';
  }
}

if (!function_exists('qta_plugin_enabled')) {
  function qta_plugin_enabled(string $plugin): bool {
    $plugins = $GLOBALS['publicPlugins'] ?? [];
    return in_array($plugin, $plugins, true) || !empty($plugins[$plugin]);
  }
}

/**
 * Shton një "gishtërinj" versioni te asetet lokale, që shfletuesi të mos
 * shërbejë CSS/JS të vjetruar pas një përditësimi.
 */
if (!function_exists('qta_asset')) {
  function qta_asset(string $path): string {
    $full = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    $stamp = is_file($full) ? (string)filemtime($full) : '1';
    return $path . '?v=' . $stamp;
  }
}
