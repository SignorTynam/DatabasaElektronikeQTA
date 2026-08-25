<?php
declare(strict_types=1);

/**
 * app_ui.php — Ndihmësit e përbashkët të panelit (faqet e brendshme).
 * Vetëm shtresa e prezantimit: nuk prek skemën apo query-t e databazës.
 */

if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('qta_app_initials')) {
  function qta_app_initials(?string $name): string {
    $name = trim((string)$name);
    if ($name === '') {
      return 'Q';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach ($parts as $part) {
      if ($part === '') {
        continue;
      }
      $letter = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
      $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($letter, 'UTF-8') : strtoupper($letter);
      $length = function_exists('mb_strlen') ? mb_strlen($initials, 'UTF-8') : strlen($initials);
      if ($length >= 2) {
        break;
      }
    }

    return $initials !== '' ? $initials : 'Q';
  }
}

if (!function_exists('qta_app_role_label')) {
  function qta_app_role_label(?string $role): string {
    return match (strtolower((string)$role)) {
      'administrator' => 'Administrator',
      'editor' => 'Editor',
      'agjencia', 'agency' => 'Agjenci',
      'student' => 'Student',
      default => 'Përdorues',
    };
  }
}

if (!function_exists('qta_app_role_icon')) {
  function qta_app_role_icon(?string $role): string {
    return match (strtolower((string)$role)) {
      'administrator' => 'bi-shield-lock',
      'editor' => 'bi-pencil-square',
      'agjencia', 'agency' => 'bi-building',
      'student' => 'bi-mortarboard',
      default => 'bi-person',
    };
  }
}

if (!function_exists('qta_app_home')) {
  function qta_app_home(?string $role): string {
    return match (strtolower((string)$role)) {
      'administrator' => 'dashboard_admin.php',
      'editor' => 'dashboard_editor.php',
      'agjencia', 'agency' => 'dashboard_agjencia.php',
      'student' => 'dashboard_student.php',
      default => 'selectProfile.php',
    };
  }
}

/**
 * Harta faqe → çelës aktiv, e përdorur kur $NAV_ACTIVE nuk jepet.
 */
if (!function_exists('qta_app_active_key')) {
  function qta_app_active_key(): string {
    $script = strtolower(basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''));

    $map = [
      'dashboard_admin.php'         => 'dashboard',
      'dashboard_editor.php'        => 'dashboard',
      'dashboard_agjencia.php'      => 'dashboard',
      'dashboard_student.php'       => 'dashboard',
      'users.php'                   => 'users_admins',
      'editors.php'                 => 'users_editors',
      'agencies.php'                => 'users_agencies',
      'students.php'                => 'users_students',
      'student_card.php'            => 'student_card',
      'register.php'                => 'register_full',
      'groups.php'                  => 'register_groups',
      'students_without_groups.php' => 'students_without_groups',
      'courses.php'                 => 'courses',
      'logs.php'                    => 'logs',
      'logs_editor.php'             => 'logs',
      'register_agjencia.php'       => 'agency_students',
      'groups_agjencia.php'         => 'agency_groups',
      'groups_student.php'          => 'student_groups',
      'profile.php'                 => 'profile',
    ];

    return $map[$script] ?? '';
  }
}

/**
 * Struktura e menusë sipas rolit.
 * Çdo zë: ['key'|'keys', 'label', 'href', 'icon'] ose një dropdown me 'children'.
 */
if (!function_exists('qta_app_menu')) {
  function qta_app_menu(string $role): array {
    $role = strtolower($role);

    $studentsItem = ['key' => 'users_students', 'label' => 'Studentët',       'href' => 'students.php',     'icon' => 'bi-mortarboard'];
    $cardItem     = ['key' => 'student_card',   'label' => 'Kartela e studentit', 'href' => 'student_card.php', 'icon' => 'bi-credit-card-2-front'];
    $agenciesItem = ['key' => 'users_agencies', 'label' => 'Agjencitë',       'href' => 'agencies.php',     'icon' => 'bi-building'];

    $registerGroup = [
      'label' => 'Regjistri',
      'icon' => 'bi-journal-text',
      'children' => [
        ['key' => 'register_full',            'label' => 'Regjistri i plotë',   'href' => 'register.php',                'icon' => 'bi-journal-bookmark'],
        ['key' => 'register_groups',          'label' => 'Regjistri me grupe',  'href' => 'groups.php',                  'icon' => 'bi-people-fill'],
        ['key' => 'students_without_groups',  'label' => 'Studentët pa grupe',  'href' => 'students_without_groups.php', 'icon' => 'bi-person-x'],
      ],
    ];

    return match ($role) {
      'administrator' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard_admin.php', 'icon' => 'bi-speedometer2'],
        [
          'label' => 'Përdorues',
          'icon' => 'bi-people',
          'children' => [
            ['key' => 'users_admins',  'label' => 'Administratorët', 'href' => 'users.php',   'icon' => 'bi-shield-lock'],
            ['key' => 'users_editors', 'label' => 'Editorët',        'href' => 'editors.php', 'icon' => 'bi-pencil-square'],
            $agenciesItem,
            $studentsItem,
            $cardItem,
          ],
        ],
        $registerGroup,
        ['key' => 'courses', 'label' => 'Modulet', 'href' => 'courses.php', 'icon' => 'bi-book'],
        ['key' => 'logs',    'label' => 'Logs',    'href' => 'logs.php',    'icon' => 'bi-clipboard-data'],
      ],

      'editor' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard_editor.php', 'icon' => 'bi-speedometer2'],
        [
          'label' => 'Përdorues',
          'icon' => 'bi-people',
          'children' => [$agenciesItem, $studentsItem, $cardItem],
        ],
        $registerGroup,
        ['key' => 'courses', 'label' => 'Modulet', 'href' => 'courses.php',     'icon' => 'bi-book'],
        ['key' => 'logs',    'label' => 'Logs',    'href' => 'logs_editor.php', 'icon' => 'bi-clipboard-data'],
      ],

      'agjencia', 'agency' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard_agjencia.php', 'icon' => 'bi-speedometer2'],
        [
          'label' => 'Regjistrimet',
          'icon' => 'bi-journal-text',
          'children' => [
            ['key' => 'agency_students', 'label' => 'Regjistro kursantë', 'href' => 'register_agjencia.php', 'icon' => 'bi-person-plus'],
            ['key' => 'agency_groups',   'label' => 'Grupet e mia',       'href' => 'groups_agjencia.php',   'icon' => 'bi-people-fill'],
          ],
        ],
      ],

      'student' => [
        ['key' => 'dashboard',      'label' => 'Dashboard',    'href' => 'dashboard_student.php', 'icon' => 'bi-speedometer2'],
        ['key' => 'student_groups', 'label' => 'Certifikimet', 'href' => 'groups_student.php',    'icon' => 'bi-patch-check'],
      ],

      default => [],
    };
  }
}

/**
 * A ka roli të drejtë për kërkim global të studentëve në navbar?
 */
if (!function_exists('qta_app_can_search')) {
  function qta_app_can_search(string $role): bool {
    return in_array(strtolower($role), ['administrator', 'editor', 'agjencia'], true);
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
