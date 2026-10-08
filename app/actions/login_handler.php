<?php
// login_handler.php — hyrja sipas rolit.
// Dialogu i hyrjes (partials/login_dialog.php) e dërgon me fetch dhe pret JSON:
// {ok: true, redirect} ose {ok: false, code, error, csrf}. Pa JavaScript formulari
// dërgohet si zakonisht dhe përgjigjja është një ridrejtim, si më parë.
require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require __DIR__ . '/database.php';

$wantsJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

$reply = static function (array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: selectProfile.php');
    exit;
}

$text = static fn($value): string => is_string($value) ? $value : '';
$role = $text($_POST['role'] ?? '');
$identifier = trim($text($_POST['identifier'] ?? ''));
$password = $text($_POST['password'] ?? '');

$validRoles = ['staff', 'administrator', 'editor', 'agjencia', 'student'];
$backRole = in_array($role, $validRoles, true) ? $role : 'staff';

$fail = static function (string $message, string $code) use ($backRole, $identifier, $wantsJson, $reply): void {
    if ($wantsJson) {
        /* Dialogu mbetet i hapur: me shenjën e seancës mund të provohet sërish pa rifreskuar faqen. */
        $reply(['ok' => false, 'code' => $code, 'error' => $message, 'csrf' => $_SESSION['csrf_login']]);
    }
    qta_session_put(['login_error'], $message);
    qta_session_put(['login_identifier'], mb_substr($identifier, 0, 120));
    header('Location: selectProfile.php?role=' . urlencode($backRole));
    exit;
};

/* Mbrojtja CSRF: formulari duhet të vijë nga dialogu ynë i hyrjes. */
$csrf = $text($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_login']) || !hash_equals((string)$_SESSION['csrf_login'], $csrf)) {
    $fail('Faqja kishte qëndruar e hapur shumë gjatë. Shtyp sërish "Hyr".', 'csrf');
}

if ($identifier === '' || $password === '') {
    $fail('Plotëso të dyja fushat: identifikimin dhe fjalëkalimin.', 'missing');
}

/* Emri i identifikimit në mesazhin e gabimit, sipas llojit të llogarisë. */
$idName = match ($role) {
    'staff', 'administrator', 'editor' => 'Email-i',
    'agjencia' => 'NIPT-i',
    'student'  => 'Numri personal',
    default    => '',
};
if ($idName === '') {
    $fail('Zgjidh si do të hysh: staf, agjenci apo kursant.', 'role');
}

/* NIPT-i dhe numri personal shkruhen shpesh me hapësira — i heqim. */
if ($role === 'agjencia' || $role === 'student') {
    $identifier = preg_replace('/\s+/', '', $identifier) ?? $identifier;
}

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

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

    } else {
        /* Kursanti: users -> students (user_id) -> persons (personal_number) */
        $stmt = $pdo->prepare("SELECT u.id AS user_id, r.name AS role_name, c.password_hash, u.full_name, p.personal_number
                FROM users u
                JOIN roles r      ON u.role_id = r.id
                JOIN credentials c ON c.user_id = u.id
                JOIN students s    ON s.user_id = u.id
                JOIN persons  p    ON p.id = s.person_id
                WHERE p.personal_number = :identifier AND r.name = 'student'
                LIMIT 1");
        $stmt->execute([':identifier' => $identifier]);
    }

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $fail($idName . ' ose fjalëkalimi nuk është i saktë. Kontrollo dhe provo sërish.', 'credentials');
    }

    // Hyrja u krye
    session_start();
    session_regenerate_id(true);
    unset($_SESSION['csrf_login'], $_SESSION['login_identifier'], $_SESSION['login_error']);
    $_SESSION['user_id']   = (int)$user['user_id'];
    $_SESSION['role']      = $user['role_name'];
    $_SESSION['full_name'] = $user['full_name'] ?? '';

    session_write_close();

    $target = match ($user['role_name']) {
        'administrator' => 'dashboard_admin.php',
        'editor'        => 'dashboard_editor.php',
        'agjencia'      => 'dashboard_agjencia.php',
        'student'       => 'dashboard_student.php',
        default         => 'selectProfile.php',
    };
    if ($wantsJson) {
        $reply(['ok' => true, 'redirect' => $target]);
    }
    header('Location: ' . $target);
    exit;

} catch (Throwable $e) {
    $ref = qta_request_exception($e);
    if ($wantsJson) http_response_code(500);
    $fail('Hyrja nuk u krye për shkak të një problemi teknik. Provo sërish pas pak. Referenca: ' . $ref, 'server');
}
