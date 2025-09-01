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
        jerr('Metodë e palejuar.', 405);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) jerr('Kërkesë e pavlefshme.');

    // Guard: admin OSE editor i loguar
    if (empty($_SESSION['user_id'])) jerr('Seanca ka skaduar. Hyni sërish.', 401);

    $pdo = getPDO();

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
        jerr('Leje e pamjaftueshme.', 403);
    }

    // CSRF
    $csrf = (string)($data['csrf'] ?? '');
    if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        jerr('CSRF token i pavlefshëm.', 400);
    }

    // Parametra
    $agency_id = (int)($data['agency_id'] ?? 0);
    $field     = (string)($data['field'] ?? '');
    $value     = isset($data['value']) ? (string)$data['value'] : '';

    if ($agency_id <= 0) jerr('ID agjencie e pavlefshme.');
    $allowed = ['company_name', 'nip_t', 'phone', 'address'];
    if (!in_array($field, $allowed, true)) jerr('Fushë e palejuar për modifikim.');

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
            if ($value === '') jerr('Emri i agjencisë s’mund të jetë bosh.');
            if (mb_strlen($value) > 150) jerr('Emri është shumë i gjatë (≤150).');
            break;

        case 'nip_t':
            // Hiq çdo shenjë jo-alfanumerike dhe ktheje në UPPER
            $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$value));
            if ($value === '') jerr('NIPT s’mund të jetë bosh.');

            // Format i zakonshëm shqiptar: L + 8 shifra + (shkronjë ose shifër) = 10 shenja
            if (!preg_match('/^[A-Z]\d{8}[A-Z0-9]$/', $value)) {
                jerr('Format NIPT i pavlefshëm (p.sh. L42202012A).');
            }

            // Unik për agjenci të tjera
            $q = $pdo->prepare("SELECT COUNT(*) FROM agencies WHERE nip_t = :n AND id <> :aid");
            $q->execute([':n' => $value, ':aid' => $agency_id]);
            if ((int)$q->fetchColumn() > 0) jerr('Ky NIPT përdoret nga një agjenci tjetër.');
            break;

        case 'phone':
            $value = trim($value);
            if ($value !== '' && mb_strlen($value) > 40) jerr('Telefoni është shumë i gjatë (≤40).');
            // (opsionale) normalizim i thjeshtë hapësirash
            $value = preg_replace('/\s+/u', ' ', $value);
            break;

        case 'address':
            $value = trim($value);
            if ($value !== '' && mb_strlen($value) > 500) jerr('Adresa është shumë e gjatë (≤500).');
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
        jerr('Ndodhi një gabim gjatë ruajtjes.');
    }

    // Përgjigja
    $display = ($value === '' ? '—' : $value);
    echo json_encode(['ok' => true, 'display' => $display], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    jerr('Gabim i papritur.');
}
