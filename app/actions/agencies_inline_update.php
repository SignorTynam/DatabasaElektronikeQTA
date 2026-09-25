<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/database.php';

function jerr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jerr('Kjo kërkesë nuk pranohet.', 405);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) jerr('Kërkesa nuk u kuptua. Rifresko faqen dhe provo sërish.');

    // Guard: admin OSE editor i loguar
    if (empty($_SESSION['user_id'])) jerr('Seanca ka mbaruar. Hyr sërish në llogari.', 401);

    $pdo = getPDO();
    require_once __DIR__ . '/inc/audit_bootstrap.php';
    qta_audit_attach($pdo);


    $uStmt = $pdo->prepare("
        SELECT u.id, r.name AS role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = :uid
        LIMIT 1
    ");
    $uStmt->execute([':uid' => $_SESSION['user_id']]);
    $me = $uStmt->fetch();

    $role = strtolower((string)($me['role_name'] ?? ''));
    if (!$me || !in_array($role, ['administrator','editor'], true)) {
        jerr('Nuk ke leje për këtë veprim.', 403);
    }

    // Kyçi i ndryshimeve: njësoj si faqet e tjera
    if (empty($_SESSION['edit_mode'])) jerr('Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.', 403);

    // CSRF
    $csrf = (string)($data['csrf'] ?? '');
    if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        jerr('Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.', 400);
    }

    // Parametra
    $agency_id = (int)($data['agency_id'] ?? 0);
    $field     = (string)($data['field'] ?? '');
    $value     = isset($data['value']) ? (string)$data['value'] : '';

    if ($agency_id <= 0) jerr('Agjencia nuk u gjet. Rifresko faqen.');
    $allowed = ['company_name', 'nip_t', 'phone', 'address'];
    if (!in_array($field, $allowed, true)) jerr('Kjo fushë nuk mund të ndryshohet këtu.');

    // Gjej rolin "agjencia"
    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'agjencia' LIMIT 1");
    $roleStmt->execute();
    $agencyRoleId = (int)$roleStmt->fetchColumn();
    if ($agencyRoleId <= 0) jerr('Konfigurim i mangët: roli agjencia mungon.');

    // Verifiko që agjencia ekziston dhe i përket rolit 'agjencia'
    $check = $pdo->prepare("
        SELECT a.user_id, a.company_name, a.nip_t, a.phone, a.address
        FROM agencies a
        JOIN users u ON u.id = a.user_id
        WHERE a.id = :aid AND u.role_id = :rid
        LIMIT 1
    ");
    $check->execute([':aid' => $agency_id, ':rid' => $agencyRoleId]);
    $ag = $check->fetch();
    if (!$ag) jerr('Agjencia nuk u gjet.');

    // Normalizime & validime për fushat
    $display = '—';
    switch ($field) {
        case 'company_name':
            $value = trim(preg_replace('/\s+/u', ' ', $value));
            if ($value === '') jerr('Shkruaj emrin e agjencisë.');
            if (mb_strlen($value) > 150) jerr('Emri është shumë i gjatë (deri në 150 shkronja).');
            break;

        case 'nip_t':
            // Hiq çdo shenjë jo-alfanumerike dhe ktheje në UPPER
            $value = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$value));
            if ($value === '') jerr('Shkruaj NIPT-in.');

            // Format i zakonshëm shqiptar: L + 8 shifra + (shkronjë ose shifër) = 10 shenja
            if (!preg_match('/^[A-Z]\d{8}[A-Z0-9]$/', $value)) {
                jerr('NIPT-i ka 10 shenja: një shkronjë, 8 shifra dhe një shkronjë në fund, p.sh. L42202012A.');
            }

            // Unik për agjenci të tjera
            $q = $pdo->prepare("SELECT COUNT(*) FROM agencies WHERE nip_t = :n AND id <> :aid");
            $q->execute([':n' => $value, ':aid' => $agency_id]);
            if ((int)$q->fetchColumn() > 0) jerr('Ky NIPT përdoret nga një agjenci tjetër.');
            break;

        case 'phone':
            $value = trim($value);
            if ($value !== '' && mb_strlen($value) > 40) jerr('Telefoni është shumë i gjatë (deri në 40 shenja).');
            // (opsionale) normalizim i thjeshtë hapësirash
            $value = preg_replace('/\s+/u', ' ', $value);
            break;

        case 'address':
            $value = trim($value);
            if ($value !== '' && mb_strlen($value) > 500) jerr('Adresa është shumë e gjatë (deri në 500 shenja).');
            break;
    }

    // Nëse vlera është e njëjtë, kthe ok pa update
    if ((string)$ag[$field] === (string)$value) {
        $display = ($value === '' ? '—' : $value);
        echo json_encode(['ok' => true, 'display' => $display], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Bëj update (dhe sinkronizim users.full_name kur ndryshon company_name)
    $pdo->beginTransaction();
    try {
        if ($field === 'company_name') {
            $updA = $pdo->prepare("UPDATE agencies SET company_name = :v WHERE id = :aid");
            $updA->execute([':v' => $value, ':aid' => $agency_id]);

            $updU = $pdo->prepare("UPDATE users SET full_name = :v WHERE id = :uid");
            $updU->execute([':v' => $value, ':uid' => (int)$ag['user_id']]);
        } else {
            $sql = "UPDATE agencies SET {$field} = :v WHERE id = :aid";
            $upd = $pdo->prepare($sql);
            $upd->execute([':v' => $value, ':aid' => $agency_id]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jerr('Ndryshimi nuk u ruajt. Provo sërish.');
    }

    // Përgjigja
    $display = ($value === '' ? '—' : $value);
    echo json_encode(['ok' => true, 'display' => $display], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    jerr('Diçka nuk shkoi. Provo sërish.');
}
