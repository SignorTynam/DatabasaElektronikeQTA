<?php
declare(strict_types=1);

require_once __DIR__ . '/themeli.php';

/**
 * app_ui.php — Ndihmësit e shell-it të panelit (faqet pas hyrjes).
 * Vetëm shtresa e prezantimit: nuk prek skemën apo query-t e databazës.
 * Menuja përcaktohet VETËM këtu; desktopi dhe celulari vizatojnë të njëjtën.
 */

if (!function_exists('qta_app_initials')) {
  function qta_app_initials(?string $name): string {
    return qta_initials($name);
  }
}

if (!function_exists('qta_app_role_label')) {
  function qta_app_role_label(?string $role): string {
    return match (strtolower((string)$role)) {
      'administrator' => 'Administrator',
      'editor' => 'Editor',
      'agjencia', 'agency' => 'Agjenci',
      'student' => 'Kursant',
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
      'lesson_groups.php'           => 'lesson_groups',
      'lesson_group.php'            => 'lesson_groups',
      'course.php'                  => 'courses',
      'students_without_groups.php' => 'students_without_groups',
      'courses.php'                 => 'courses',
      'logs.php'                    => 'logs',
      'logs_editor.php'             => 'logs',
      'register_agjencia.php'       => 'agency_students',
      'groups_agjencia.php'         => 'agency_groups',
      'groups_student.php'          => 'student_groups',
      'profile.php'                 => 'profile',
      'ndihme.php'                  => 'help',
    ];

    return $map[$script] ?? '';
  }
}

/**
 * Struktura e menusë sipas rolit.
 * Çdo zë: ['key', 'label', 'href', 'icon']. Seksionet: ['label', 'children' => [...]].
 * Seksionet janë tituj të dukshëm, jo nënmenu të fshehura: përdoruesi i sheh
 * gjithnjë të gjitha vendet ku mund të shkojë.
 */
if (!function_exists('qta_app_menu')) {
  function qta_app_menu(string $role): array {
    $role = strtolower($role);

    $learners = [
      'label' => 'Kursantët',
      'children' => [
        ['key' => 'users_students',          'label' => 'Të gjithë kursantët',  'href' => 'students.php',                'icon' => 'bi-people'],
        ['key' => 'student_card',            'label' => 'Kartela e kursantit',  'href' => 'student_card.php',            'icon' => 'bi-person-vcard'],
        ['key' => 'students_without_groups', 'label' => 'Kursantët pa grup',    'href' => 'students_without_groups.php', 'icon' => 'bi-person-exclamation'],
      ],
    ];

    $training = [
      'label' => 'Grupet dhe provimet',
      'children' => [
        ['key' => 'lesson_groups',   'label' => 'Grupet',             'href' => 'lesson_groups.php', 'icon' => 'bi-calendar-week'],
        ['key' => 'register_groups', 'label' => 'Grupet e mëparshme', 'href' => 'groups.php',        'icon' => 'bi-archive'],
        ['key' => 'register_full',   'label' => 'Regjistri i plotë',  'href' => 'register.php',      'icon' => 'bi-journal-text'],
        ['key' => 'courses',         'label' => 'Kurset',             'href' => 'courses.php',       'icon' => 'bi-book'],
      ],
    ];

    return match ($role) {
      'administrator' => [
        ['key' => 'dashboard', 'label' => 'Kreu', 'href' => 'dashboard_admin.php', 'icon' => 'bi-house-door'],
        $learners,
        $training,
        [
          'label' => 'Administrimi',
          'children' => [
            ['key' => 'users_agencies', 'label' => 'Agjencitë',               'href' => 'agencies.php', 'icon' => 'bi-building'],
            ['key' => 'users_admins',   'label' => 'Administratorët',         'href' => 'users.php',    'icon' => 'bi-shield-lock'],
            ['key' => 'users_editors',  'label' => 'Editorët',                'href' => 'editors.php',  'icon' => 'bi-pencil-square'],
            ['key' => 'logs',           'label' => 'Historiku i ndryshimeve', 'href' => 'logs.php',     'icon' => 'bi-clock-history'],
          ],
        ],
      ],

      'editor' => [
        ['key' => 'dashboard', 'label' => 'Kreu', 'href' => 'dashboard_editor.php', 'icon' => 'bi-house-door'],
        $learners,
        $training,
        [
          'label' => 'Tjetër',
          'children' => [
            ['key' => 'users_agencies', 'label' => 'Agjencitë',   'href' => 'agencies.php',    'icon' => 'bi-building'],
            ['key' => 'logs',           'label' => 'Historiku im', 'href' => 'logs_editor.php', 'icon' => 'bi-clock-history'],
          ],
        ],
      ],

      'agjencia', 'agency' => [
        ['key' => 'dashboard',       'label' => 'Kreu',            'href' => 'dashboard_agjencia.php', 'icon' => 'bi-house-door'],
        ['key' => 'agency_students', 'label' => 'Punonjësit tanë', 'href' => 'register_agjencia.php',  'icon' => 'bi-people'],
        ['key' => 'agency_groups',   'label' => 'Grupet',          'href' => 'groups_agjencia.php',    'icon' => 'bi-collection'],
      ],

      'student' => [
        ['key' => 'dashboard',      'label' => 'Kreu',          'href' => 'dashboard_student.php', 'icon' => 'bi-house-door'],
        ['key' => 'student_groups', 'label' => 'Kurset e mia',  'href' => 'groups_student.php',    'icon' => 'bi-patch-check'],
      ],

      default => [],
    };
  }
}

/**
 * Lidhjet dytësore, të njëjta për të gjitha rolet.
 */
if (!function_exists('qta_app_secondary_menu')) {
  function qta_app_secondary_menu(): array {
    return [
      ['key' => 'verify', 'label' => 'Verifiko certifikatë', 'href' => 'verify.php', 'icon' => 'bi-qr-code-scan'],
      ['key' => 'help',   'label' => 'Ndihmë',               'href' => 'ndihme.php', 'icon' => 'bi-question-circle'],
    ];
  }
}

/**
 * A ka roli të drejtë për kërkim global në regjistër?
 */
if (!function_exists('qta_app_can_search')) {
  function qta_app_can_search(string $role): bool {
    return in_array(strtolower($role), ['administrator', 'editor', 'agjencia'], true);
  }
}
