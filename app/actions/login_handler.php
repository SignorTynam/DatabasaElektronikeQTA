<?php
// login_handler.php — hyrja sipas rolit.
session_start();
require __DIR__ . '/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: selectProfile.php');
    exit;
}

$role = (string)($_POST['role'] ?? '');
$identifier = trim((string)($_POST['identifier'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$validRoles = ['staff', 'administrator', 'editor', 'agjencia', 'student'];
$backRole = in_array($role, $validRoles, true) ? $role : 'staff';

$fail = static function (string $message) use ($backRole, $identifier): void {
    $_SESSION['login_error'] = $message;
    $_SESSION['login_identifier'] = mb_substr($identifier, 0, 120);
    header('Location: selectProfile.php?role=' . urlencode($backRole));
    exit;
};

/* Mbrojtja CSRF: formulari duhet të vijë nga faqja jonë e hyrjes. */
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_login']) || !hash_equals((string)$_SESSION['csrf_login'], $csrf)) {
    $fail('Faqja e hyrjes kishte qëndruar e hapur shumë gjatë. Provo sërish.');
}

if ($identifier === '' || $password === '') {
    $fail('Plotëso të dyja fushat: identifikimin dhe fjalëkalimin.');
}

/* NIPT-i dhe numri personal shkruhen shpesh me hapësira — i heqim. */
if ($role === 'agjencia' || $role === 'student') {
    $identifier = preg_replace('/\s+/', '', $identifier) ?? $identifier;
}

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

try {
    if ($role === 'staff' || $role === 'administrator' || $role === 'editor') {
        /* Stafi hyn me email. Email-i është unik, prandaj roli gjendet vetë. */
        $roles = $role === 'staff' ? ['administrator', 'editor'] : [$role];
        $ph = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $pdo->prepare("SELECT u.id AS user_id, r.name AS role_name, c.password_hash, u.full_name
                FROM users u
                JOIN roles r ON u.role_id = r.id
                JOIN credentials c ON c.user_id = u.id
                WHERE u.email = ? AND r.name IN ($ph)
                LIMIT 1");
        $stmt->execute(array_merge([$identifier], $roles));

    } elseif ($role === 'agjencia') {
        $stmt = $pdo->prepare("SELECT u.id AS user_id, r.name AS role_name, c.password_hash, u.full_name, a.nip_t
                FROM users u
                JOIN roles r ON u.role_id = r.id
                JOIN credentials c ON c.user_id = u.id
                JOIN agencies a ON a.user_id = u.id
                WHERE a.nip_t = :identifier AND r.name = 'agjencia'
                LIMIT 1");
        $stmt->execute([':identifier' => $identifier]);

    } elseif ($role === 'student') {
        /* users -> students (user_id) -> persons (personal_number) */
        $stmt = $pdo->prepare("SELECT u.id AS user_id, r.name AS role_name, c.password_hash, u.full_name, p.personal_number
                FROM users u
                JOIN roles r      ON u.role_id = r.id
                JOIN credentials c ON c.user_id = u.id
                JOIN students s    ON s.user_id = u.id
                JOIN persons  p    ON p.id = s.person_id
                WHERE p.personal_number = :identifier AND r.name = 'student'
                LIMIT 1");
        $stmt->execute([':identifier' => $identifier]);

    } else {
        $fail('Zgjidh si do të hysh: staf, agjenci apo kursant.');
    }

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $fail('Identifikimi ose fjalëkalimi nuk është i saktë. Kontrollo dhe provo sërish.');
    }

    // Hyrja u krye
    session_regenerate_id(true);
    unset($_SESSION['csrf_login'], $_SESSION['login_identifier'], $_SESSION['login_error']);
    $_SESSION['user_id']   = (int)$user['user_id'];
    $_SESSION['role']      = $user['role_name'];
    $_SESSION['full_name'] = $user['full_name'] ?? '';

    switch ($user['role_name']) {
        case 'administrator':
            header('Location: dashboard_admin.php'); exit;
        case 'editor':
            header('Location: dashboard_editor.php'); exit;
        case 'agjencia':
            header('Location: dashboard_agjencia.php'); exit;
        case 'student':
            header('Location: dashboard_student.php'); exit;
        default:
            header('Location: selectProfile.php'); exit;
    }

} catch (Exception $e) {
    error_log('[QTA login] ' . $e->getMessage());
    $fail('Hyrja nuk u krye për shkak të një problemi teknik. Provo sërish pas pak.');
}
