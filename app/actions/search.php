<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/database.php';

/**
 * search.php — Kërkimi i menjëhershëm i regjistrit.
 *
 * Kthen zëra të grupuar sipas llojit. ÇDO pyetje kufizohet sipas rolit:
 *   administrator / editor  → i gjithë regjistri
 *   agjencia                → vetëm punonjësit e vet dhe grupet ku i ka
 *   student                 → vetëm vetja
 *
 * GET  ?q=…  [&type=student|group|course|agency]  [&limit=]
 */

function jout(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function jerr(string $msg, int $code = 400): void {
    http_response_code($code);
    jout(['ok' => false, 'error' => $msg]);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        jerr('Metodë e palejuar.', 405);
    }
    if (empty($_SESSION['user_id'])) {
        jerr('Seanca ka skaduar. Hyni sërish.', 401);
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        jout(['ok' => true, 'q' => $q, 'groups' => [], 'total' => 0]);
    }

    $limit = (int)($_GET['limit'] ?? 6);
    $limit = max(1, min(12, $limit));
    $only  = (string)($_GET['type'] ?? '');

    $pdo = getPDO();

    /* ---- Kush po kërkon ---------------------------------------------- */
    $u = $pdo->prepare("
        SELECT u.id, u.person_id, r.name AS role
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = :id
        LIMIT 1
    ");
    $u->execute([':id' => (int)$_SESSION['user_id']]);
    $me = $u->fetch(PDO::FETCH_ASSOC);
    if (!$me) {
        jerr('Përdoruesi nuk u gjet.', 403);
    }

    $role = strtolower((string)$me['role']);
    $like = '%' . $q . '%';

    /* Agjencia sheh vetëm punonjësit e vet. */
    $agencyId = null;
    if ($role === 'agjencia') {
        $a = $pdo->prepare("SELECT id FROM agencies WHERE user_id = :u LIMIT 1");
        $a->execute([':u' => (int)$me['id']]);
        $agencyId = $a->fetchColumn();
        if ($agencyId === false) {
            jout(['ok' => true, 'q' => $q, 'groups' => [], 'total' => 0]);
        }
        $agencyId = (int)$agencyId;
    }

    /* Studenti sheh vetëm veten. */
    $myStudentIds = [];
    if ($role === 'student') {
        $s = $pdo->prepare("SELECT id FROM students WHERE person_id = :p");
        $s->execute([':p' => (int)$me['person_id']]);
        $myStudentIds = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!$myStudentIds) {
            jout(['ok' => true, 'q' => $q, 'groups' => [], 'total' => 0]);
        }
    }

    $groups = [];
    $total  = 0;

    $want = static fn(string $t): bool => ($only === '' || $only === $t);

    /* ---- Kursantët ---------------------------------------------------- */
    if ($want('student')) {
        $scope  = '';
        $params = [':a' => $like, ':b' => $like, ':c' => $like, ':d' => $like];

        if ($role === 'agjencia') {
            $scope = " AND EXISTS (SELECT 1 FROM agency_students ags
                                   WHERE ags.student_id = s.id AND ags.agency_id = :ag) ";
            $params[':ag'] = $agencyId;
        } elseif ($role === 'student') {
            /* Vetëm emrat e parametrave — asnjë përzierje me pozicione. */
            $names = [];
            foreach ($myStudentIds as $k => $sid) {
                $names[] = ':s' . $k;
                $params[':s' . $k] = $sid;
            }
            $scope = ' AND s.id IN (' . implode(',', $names) . ') ';
        }

        $st = $pdo->prepare("
            SELECT s.id, s.nr_amze,
                   TRIM(CONCAT(COALESCE(p.first_name,''),' ',
                               COALESCE(NULLIF(CONCAT(p.father_name,' '),' '),''),
                               COALESCE(p.last_name,''))) AS title,
                   p.personal_number,
                   p.birth_place
            FROM students s
            LEFT JOIN persons p ON p.id = s.person_id
            WHERE (s.nr_amze LIKE :a
                   OR p.personal_number LIKE :b
                   OR p.first_name LIKE :c
                   OR p.last_name LIKE :d)
                  $scope
            ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
            LIMIT :lim
        ");
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $meta = array_filter([
                (string)($r['personal_number'] ?? ''),
                (string)($r['birth_place'] ?? ''),
            ]);
            $items[] = [
                'title' => trim((string)$r['title']) ?: '—',
                'code'  => (string)$r['nr_amze'],
                'meta'  => implode(' · ', $meta),
                'href'  => 'student_card.php?q=' . urlencode((string)$r['nr_amze']),
                'icon'  => 'bi-person',
            ];
        }
        if ($items) { $groups[] = ['label' => 'Kursantë', 'items' => $items]; $total += count($items); }
    }

    /* ---- Grupet ------------------------------------------------------- */
    if ($want('group') && $role !== 'student') {
        $scope  = '';
        $params = [':a' => $like, ':b' => $like];
        if ($role === 'agjencia') {
            $scope = " AND EXISTS (SELECT 1 FROM course_group_students cgs
                                   JOIN agency_students ags ON ags.student_id = cgs.student_id
                                   WHERE cgs.group_id = cg.id AND ags.agency_id = :ag) ";
            $params[':ag'] = $agencyId;
        }

        $st = $pdo->prepare("
            SELECT cg.id, cg.start_date, cg.end_date, cg.is_completed,
                   c.code, c.name,
                   (SELECT COUNT(*) FROM course_group_students x WHERE x.group_id = cg.id) AS members
            FROM course_groups cg
            JOIN courses c ON c.id = cg.course_id
            WHERE (c.name LIKE :a OR c.code LIKE :b)
                  $scope
            ORDER BY cg.start_date DESC, cg.id DESC
            LIMIT :lim
        ");
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $when = $r['start_date'] ? date('d.m.Y', strtotime((string)$r['start_date'])) : '';
            $items[] = [
                'title' => (string)$r['name'] . ' · #' . (int)$r['id'],
                'code'  => (string)$r['code'],
                'meta'  => trim($when . ' · ' . (int)$r['members'] . ' kursantë'
                           . ((int)$r['is_completed'] === 1 ? ' · i mbyllur' : '')),
                'href'  => ($role === 'agjencia' ? 'groups_agjencia.php' : 'groups.php'),
                'icon'  => 'bi-collection',
            ];
        }
        if ($items) { $groups[] = ['label' => 'Grupe', 'items' => $items]; $total += count($items); }
    }

    /* ---- Modulet ------------------------------------------------------ */
    if ($want('course') && in_array($role, ['administrator', 'editor'], true)) {
        $st = $pdo->prepare("
            SELECT c.id, c.code, c.name, c.hours,
                   (SELECT COUNT(*) FROM course_groups g WHERE g.course_id = c.id) AS ngroups
            FROM courses c
            WHERE c.name LIKE :a OR c.code LIKE :b
            ORDER BY c.code ASC
            LIMIT :lim
        ");
        $st->bindValue(':a', $like);
        $st->bindValue(':b', $like);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'title' => (string)$r['name'],
                'code'  => (string)$r['code'],
                'meta'  => (int)$r['ngroups'] . ' grupe',
                'href'  => 'courses.php',
                'icon'  => 'bi-journal-text',
            ];
        }
        if ($items) { $groups[] = ['label' => 'Module', 'items' => $items]; $total += count($items); }
    }

    /* ---- Agjencitë ---------------------------------------------------- */
    if ($want('agency') && in_array($role, ['administrator', 'editor'], true)) {
        try {
            $st = $pdo->prepare("
                SELECT a.id, a.company_name, a.nip_t,
                       (SELECT COUNT(*) FROM agency_students x WHERE x.agency_id = a.id) AS nstud
                FROM agencies a
                WHERE a.company_name LIKE :a OR a.nip_t LIKE :b
                ORDER BY a.company_name ASC
                LIMIT :lim
            ");
            $st->bindValue(':a', $like);
            $st->bindValue(':b', $like);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();

            $items = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = [
                    'title' => (string)($r['company_name'] ?: 'Agjenci #' . (int)$r['id']),
                    'code'  => (string)($r['nip_t'] ?? ''),
                    'meta'  => (int)$r['nstud'] . ' punonjës',
                    'href'  => 'agencies.php',
                    'icon'  => 'bi-building',
                ];
            }
            if ($items) { $groups[] = ['label' => 'Agjenci', 'items' => $items]; $total += count($items); }
        } catch (Throwable $e) {
            /* tabela mund të mos ekzistojë në çdo instalim */
        }
    }

    jout(['ok' => true, 'q' => $q, 'role' => $role, 'groups' => $groups, 'total' => $total]);

} catch (Throwable $e) {
    jerr('Kërkimi dështoi.', 500);
}
