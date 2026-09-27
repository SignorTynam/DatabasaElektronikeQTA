<?php
declare(strict_types=1);

require_once __DIR__ . '/themeli.php';

/**
 * public_ui.php — Ndihmësit e faqeve publike.
 */

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
      'administrator', 'editor' => 'Paneli i punës',
      'agjencia', 'agency' => 'Paneli i agjencisë',
      'student' => 'Faqja ime',
      default => 'Hyr në sistem',
    };
  }
}

if (!function_exists('qta_public_initials')) {
  function qta_public_initials(?string $name): string {
    return qta_initials($name);
  }
}

if (!function_exists('qta_public_active')) {
  function qta_public_active(string $slug, ?string $active): string {
    return $slug === $active ? 'is-active' : '';
  }
}

if (!function_exists('qta_plugin_enabled')) {
  /** $plugins: vargu i faqes ($publicPlugins). Pa të, lexohet vargu global —
   *  por ai mungon kur faqja përfshihet brenda një funksioni, prandaj jepe. */
  function qta_plugin_enabled(string $plugin, ?array $plugins = null): bool {
    $plugins = $plugins ?? ($GLOBALS['publicPlugins'] ?? []);
    return in_array($plugin, $plugins, true) || !empty($plugins[$plugin]);
  }
}
