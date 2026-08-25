<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/database.php';

/**
 * Kërkimi i avancuar i regjistrit.
 *
 * GET:
 *   q       string (min. 2 shenja)
 *   type    student|group|course|agency|user|audit
 *   sort    relevance|az|za|newest|oldest
 *   status  any|active|closed|ungrouped
 *   period  any|30|90|365
 *   match   contains|prefix|exact
 *   limit   1..15 (për çdo lloj)
 */

function search_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function search_error(string $message, int $status = 400): void {
    search_out(['ok' => false, 'error' => $message], $status);
}

/** @param array<string,mixed> $params */
function search_execute(PDOStatement $statement, array $params, int $limit): void {
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':lim', $limit, PDO::PARAM_INT);
    $statement->execute();
}

function search_date(?string $value): string {
    if (!$value) return '';
    $time = strtotime($value);
    return $time ? date('d.m.Y', $time) : '';
}

$started = microtime(true);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        search_error('Metodë e palejuar.', 405);
    }
    if (empty($_SESSION['user_id'])) {
        search_error('Seanca ka skaduar. Hyni sërish.', 401);
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $q = preg_replace('/\s+/u', ' ', $q) ?? $q;

    $sort = (string)($_GET['sort'] ?? 'relevance');
    if (!in_array($sort, ['relevance', 'az', 'za', 'newest', 'oldest'], true)) $sort = 'relevance';

    $status = (string)($_GET['status'] ?? 'any');
    if (!in_array($status, ['any', 'active', 'closed', 'ungrouped'], true)) $status = 'any';

    $match = (string)($_GET['match'] ?? 'contains');
    if (!in_array($match, ['contains', 'prefix', 'exact'], true)) $match = 'contains';

    $period = (string)($_GET['period'] ?? 'any');
    $periodDays = in_array($period, ['30', '90', '365'], true) ? (int)$period : 0;
    $period = $periodDays > 0 ? (string)$periodDays : 'any';

    $limit = max(1, min(15, (int)($_GET['limit'] ?? 6)));
    $only = (string)($_GET['type'] ?? '');

    $pdo = getPDO();

    $userStatement = $pdo->prepare("
        SELECT u.id, u.person_id, r.name AS role
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = :id
        LIMIT 1
    ");
    $userStatement->execute([':id' => (int)$_SESSION['user_id']]);
    $me = $userStatement->fetch(PDO::FETCH_ASSOC);
    if (!$me) search_error('Përdoruesi nuk u gjet.', 403);

    $role = strtolower((string)$me['role']);
    $capabilities = match ($role) {
        'administrator' => ['student', 'group', 'course', 'agency', 'user', 'audit'],
        'editor'        => ['student', 'group', 'course', 'agency', 'audit'],
        'agjencia'      => ['student', 'group'],
        'student'       => ['student'],
        default         => [],
    };

    if ($only !== '' && !in_array($only, $capabilities, true)) {
        $only = '';
    }

    $basePayload = [
        'ok' => true,
        'q' => $q,
        'role' => $role,
        'capabilities' => $capabilities,
        'filters' => compact('only', 'sort', 'status', 'period', 'match', 'limit'),
    ];

    if (mb_strlen($q) < 2) {
        search_out($basePayload + ['groups' => [], 'counts' => [], 'total' => 0, 'took_ms' => 0]);
    }

    $pattern = match ($match) {
        'exact'  => $q,
        'prefix' => $q . '%',
        default  => '%' . $q . '%',
    };
    $idPattern = match ($match) {
        'exact'  => ltrim($q, "# \t"),
        'prefix' => ltrim($q, "# \t") . '%',
        default  => '%' . ltrim($q, "# \t") . '%',
    };
    $prefixPattern = $q . '%';

    $agencyId = null;
    if ($role === 'agjencia') {
        $agencyStatement = $pdo->prepare('SELECT id FROM agencies WHERE user_id = :user LIMIT 1');
        $agencyStatement->execute([':user' => (int)$me['id']]);
        $agencyId = $agencyStatement->fetchColumn();
        if ($agencyId === false) {
            search_out($basePayload + ['groups' => [], 'counts' => [], 'total' => 0, 'took_ms' => 0]);
        }
        $agencyId = (int)$agencyId;
    }

    $myStudentIds = [];
    if ($role === 'student') {
        $studentStatement = $pdo->prepare('SELECT id FROM students WHERE person_id = :person');
        $studentStatement->execute([':person' => (int)$me['person_id']]);
        $myStudentIds = array_map('intval', $studentStatement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    $groups = [];
    $counts = [];
    $total = 0;
    $want = static fn(string $type): bool => in_array($type, $capabilities, true)
        && ($only === '' || $only === $type);
    $addGroup = static function (string $type, string $label, array $items) use (&$groups, &$counts, &$total): void {
        $counts[$type] = count($items);
        if (!$items) return;
        $groups[] = ['type' => $type, 'label' => $label, 'items' => $items];
        $total += count($items);
    };

    /* Kursantët --------------------------------------------------------- */
    if ($want('student') && ($role !== 'student' || $myStudentIds)) {
        $scope = '';
        $params = [
            ':s_amze' => $pattern,
            ':s_personal' => $pattern,
            ':s_full' => $pattern,
            ':s_first' => $pattern,
            ':s_father' => $pattern,
            ':s_last' => $pattern,
            ':s_phone' => $pattern,
            ':s_place' => $pattern,
        ];

        if ($role === 'agjencia') {
            $scope .= " AND EXISTS (
                SELECT 1 FROM agency_students ags
                WHERE ags.student_id = s.id AND ags.agency_id = :student_agency
            )";
            $params[':student_agency'] = $agencyId;
        } elseif ($role === 'student') {
            $ids = [];
            foreach ($myStudentIds as $index => $studentId) {
                $key = ':student_id_' . $index;
                $ids[] = $key;
                $params[$key] = $studentId;
            }
            $scope .= ' AND s.id IN (' . implode(',', $ids) . ')';
        }

        if ($periodDays) {
            $scope .= " AND s.created_at >= DATE_SUB(NOW(), INTERVAL {$periodDays} DAY)";
        }
        $scope .= match ($status) {
            'active' => " AND EXISTS (
                SELECT 1 FROM course_group_students cgs
                JOIN course_groups cg ON cg.id = cgs.group_id
                WHERE cgs.student_id = s.id AND cg.is_completed = 0
            )",
            'closed' => " AND EXISTS (
                SELECT 1 FROM course_group_students cgs
                JOIN course_groups cg ON cg.id = cgs.group_id
                WHERE cgs.student_id = s.id AND cg.is_completed = 1
            )",
            'ungrouped' => ' AND NOT EXISTS (SELECT 1 FROM course_group_students cgs WHERE cgs.student_id = s.id)',
            default => '',
        };

        $studentOrder = match ($sort) {
            'az' => 'full_name ASC, CAST(s.nr_amze AS UNSIGNED) ASC',
            'za' => 'full_name DESC, CAST(s.nr_amze AS UNSIGNED) DESC',
            'newest' => 's.created_at DESC, s.id DESC',
            'oldest' => 's.created_at ASC, s.id ASC',
            default => "CASE
                WHEN s.nr_amze = :student_rank_exact_amze OR p.personal_number = :student_rank_exact_personal THEN 0
                WHEN s.nr_amze LIKE :student_rank_prefix_amze OR p.personal_number LIKE :student_rank_prefix_personal THEN 1
                ELSE 2 END, full_name ASC",
        };
        if ($sort === 'relevance') {
            $params[':student_rank_exact_amze'] = $q;
            $params[':student_rank_exact_personal'] = $q;
            $params[':student_rank_prefix_amze'] = $prefixPattern;
            $params[':student_rank_prefix_personal'] = $prefixPattern;
        }

        $statement = $pdo->prepare("
            SELECT s.id, s.nr_amze, s.created_at,
                   TRIM(CONCAT_WS(' ', p.first_name, NULLIF(p.father_name, ''), p.last_name)) AS full_name,
                   p.personal_number, p.phone, p.birth_place,
                   (SELECT COUNT(*) FROM course_group_students x WHERE x.student_id = s.id) AS group_count,
                   (SELECT COUNT(*) FROM course_group_students x
                    JOIN course_groups gx ON gx.id = x.group_id
                    WHERE x.student_id = s.id AND gx.is_completed = 0) AS open_groups
            FROM students s
            LEFT JOIN persons p ON p.id = s.person_id
            WHERE (
                s.nr_amze LIKE :s_amze OR p.personal_number LIKE :s_personal
                OR TRIM(CONCAT_WS(' ', p.first_name, NULLIF(p.father_name, ''), p.last_name)) LIKE :s_full
                OR p.first_name LIKE :s_first OR p.father_name LIKE :s_father OR p.last_name LIKE :s_last
                OR p.phone LIKE :s_phone OR p.birth_place LIKE :s_place
            )
            {$scope}
            ORDER BY {$studentOrder}
            LIMIT :lim
        ");
        search_execute($statement, $params, $limit);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupCount = (int)$row['group_count'];
            $openGroups = (int)$row['open_groups'];
            if ($openGroups > 0) {
                $state = 'active'; $stateLabel = 'Aktiv';
            } elseif ($groupCount > 0) {
                $state = 'closed'; $stateLabel = 'I mbyllur';
            } else {
                $state = 'ungrouped'; $stateLabel = 'Pa grup';
            }
            $meta = array_values(array_filter([
                (string)($row['personal_number'] ?? ''),
                (string)($row['phone'] ?? ''),
                (string)($row['birth_place'] ?? ''),
            ]));
            $items[] = [
                'title' => trim((string)$row['full_name']) ?: '—',
                'code' => (string)$row['nr_amze'],
                'meta' => implode(' · ', $meta),
                'href' => 'student_card.php?q=' . urlencode((string)$row['nr_amze']),
                'icon' => 'bi-person',
                'status' => $state,
                'status_label' => $stateLabel,
            ];
        }
        $addGroup('student', 'Kursantë', $items);
    }

    /* Grupet ------------------------------------------------------------ */
    if ($want('group') && $role !== 'student') {
        $scope = '';
        $params = [
            ':g_name' => $pattern, ':g_code' => $pattern, ':g_id' => $idPattern,
            ':g_member_amze' => $pattern, ':g_member_personal' => $pattern,
            ':g_member_name' => $pattern,
        ];
        if ($role === 'agjencia') {
            $scope .= " AND EXISTS (
                SELECT 1 FROM course_group_students cgs_scope
                JOIN agency_students ags_scope ON ags_scope.student_id = cgs_scope.student_id
                WHERE cgs_scope.group_id = cg.id AND ags_scope.agency_id = :group_agency
            )";
            $params[':group_agency'] = $agencyId;
        }
        if ($periodDays) {
            $scope .= " AND cg.created_at >= DATE_SUB(NOW(), INTERVAL {$periodDays} DAY)";
        }
        $scope .= match ($status) {
            'active' => ' AND cg.is_completed = 0',
            'closed' => ' AND cg.is_completed = 1',
            'ungrouped' => ' AND NOT EXISTS (SELECT 1 FROM course_group_students cgs_empty WHERE cgs_empty.group_id = cg.id)',
            default => '',
        };

        $groupOrder = match ($sort) {
            'az' => 'c.name ASC, cg.start_date DESC',
            'za' => 'c.name DESC, cg.start_date DESC',
            'newest' => 'cg.created_at DESC, cg.id DESC',
            'oldest' => 'cg.created_at ASC, cg.id ASC',
            default => "CASE WHEN CAST(cg.id AS CHAR) = :group_rank_id OR c.code = :group_rank_code THEN 0
                            WHEN c.code LIKE :group_rank_prefix THEN 1 ELSE 2 END,
                        cg.start_date DESC, cg.id DESC",
        };
        if ($sort === 'relevance') {
            $params[':group_rank_id'] = ltrim($q, "# \t");
            $params[':group_rank_code'] = $q;
            $params[':group_rank_prefix'] = $prefixPattern;
        }

        $statement = $pdo->prepare("
            SELECT cg.id, cg.start_date, cg.end_date, cg.is_completed, cg.created_at,
                   c.code, c.name,
                   (SELECT COUNT(*) FROM course_group_students x WHERE x.group_id = cg.id) AS members
            FROM course_groups cg
            JOIN courses c ON c.id = cg.course_id
            WHERE (
                c.name LIKE :g_name OR c.code LIKE :g_code OR CAST(cg.id AS CHAR) LIKE :g_id
                OR EXISTS (
                    SELECT 1
                    FROM course_group_students cgs_member
                    JOIN students s_member ON s_member.id = cgs_member.student_id
                    JOIN persons p_member ON p_member.id = s_member.person_id
                    WHERE cgs_member.group_id = cg.id
                      AND (s_member.nr_amze LIKE :g_member_amze
                           OR p_member.personal_number LIKE :g_member_personal
                           OR TRIM(CONCAT_WS(' ', p_member.first_name, NULLIF(p_member.father_name, ''), p_member.last_name)) LIKE :g_member_name)
                )
            )
            {$scope}
            ORDER BY {$groupOrder}
            LIMIT :lim
        ");
        search_execute($statement, $params, $limit);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isClosed = (int)$row['is_completed'] === 1;
            $members = (int)$row['members'];
            $items[] = [
                'title' => (string)$row['name'] . ' · #' . (int)$row['id'],
                'code' => (string)$row['code'],
                'meta' => trim(search_date((string)$row['start_date']) . ' → ' . search_date((string)$row['end_date'])
                    . ' · ' . $members . ' kursantë'),
                'href' => ($role === 'agjencia' ? 'groups_agjencia.php' : 'groups.php')
                    . '?q=' . urlencode((string)$row['code']),
                'icon' => 'bi-collection',
                'status' => $members === 0 ? 'ungrouped' : ($isClosed ? 'closed' : 'active'),
                'status_label' => $members === 0 ? 'Bosh' : ($isClosed ? 'I mbyllur' : 'Aktiv'),
            ];
        }
        $addGroup('group', 'Grupe', $items);
    }

    /* Modulet ----------------------------------------------------------- */
    if ($want('course')) {
        $scope = '';
        $params = [':c_name' => $pattern, ':c_code' => $pattern];
        if ($periodDays) $scope .= " AND c.created_at >= DATE_SUB(NOW(), INTERVAL {$periodDays} DAY)";
        $scope .= match ($status) {
            'active' => ' AND EXISTS (SELECT 1 FROM course_groups cg_active WHERE cg_active.course_id = c.id AND cg_active.is_completed = 0)',
            'closed' => ' AND EXISTS (SELECT 1 FROM course_groups cg_closed WHERE cg_closed.course_id = c.id AND cg_closed.is_completed = 1)',
            'ungrouped' => ' AND NOT EXISTS (SELECT 1 FROM course_groups cg_none WHERE cg_none.course_id = c.id)',
            default => '',
        };
        $courseOrder = match ($sort) {
            'az' => 'c.name ASC',
            'za' => 'c.name DESC',
            'newest' => 'c.created_at DESC, c.id DESC',
            'oldest' => 'c.created_at ASC, c.id ASC',
            default => "CASE WHEN c.code = :course_rank_exact THEN 0
                            WHEN c.code LIKE :course_rank_prefix THEN 1 ELSE 2 END, c.name ASC",
        };
        if ($sort === 'relevance') {
            $params[':course_rank_exact'] = $q;
            $params[':course_rank_prefix'] = $prefixPattern;
        }

        $statement = $pdo->prepare("
            SELECT c.id, c.code, c.name, c.hours, c.created_at,
                   (SELECT COUNT(*) FROM course_groups g WHERE g.course_id = c.id) AS group_count,
                   (SELECT COUNT(*) FROM course_groups g WHERE g.course_id = c.id AND g.is_completed = 0) AS open_groups
            FROM courses c
            WHERE (c.name LIKE :c_name OR c.code LIKE :c_code)
            {$scope}
            ORDER BY {$courseOrder}
            LIMIT :lim
        ");
        search_execute($statement, $params, $limit);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupCount = (int)$row['group_count'];
            $openGroups = (int)$row['open_groups'];
            $items[] = [
                'title' => (string)$row['name'],
                'code' => (string)$row['code'],
                'meta' => (int)$row['hours'] . ' orë · ' . $groupCount . ' grupe',
                'href' => 'courses.php?q=' . urlencode((string)$row['code']),
                'icon' => 'bi-journal-text',
                'status' => $groupCount === 0 ? 'ungrouped' : ($openGroups > 0 ? 'active' : 'closed'),
                'status_label' => $groupCount === 0 ? 'Pa grup' : ($openGroups > 0 ? 'Aktiv' : 'I mbyllur'),
            ];
        }
        $addGroup('course', 'Module', $items);
    }

    /* Agjencitë --------------------------------------------------------- */
    if ($want('agency')) {
        $scope = '';
        $params = [
            ':a_name' => $pattern, ':a_nipt' => $pattern,
            ':a_phone' => $pattern, ':a_address' => $pattern,
        ];
        if ($periodDays) $scope .= " AND u.created_at >= DATE_SUB(NOW(), INTERVAL {$periodDays} DAY)";
        $scope .= match ($status) {
            'active' => " AND EXISTS (
                SELECT 1 FROM agency_students ags_active
                JOIN course_group_students cgs_active ON cgs_active.student_id = ags_active.student_id
                JOIN course_groups cg_active ON cg_active.id = cgs_active.group_id
                WHERE ags_active.agency_id = a.id AND cg_active.is_completed = 0
            )",
            'closed' => " AND EXISTS (
                SELECT 1 FROM agency_students ags_closed
                JOIN course_group_students cgs_closed ON cgs_closed.student_id = ags_closed.student_id
                JOIN course_groups cg_closed ON cg_closed.id = cgs_closed.group_id
                WHERE ags_closed.agency_id = a.id AND cg_closed.is_completed = 1
            )",
            'ungrouped' => " AND EXISTS (
                SELECT 1 FROM agency_students ags_none
                WHERE ags_none.agency_id = a.id
                  AND NOT EXISTS (SELECT 1 FROM course_group_students cgs_none WHERE cgs_none.student_id = ags_none.student_id)
            )",
            default => '',
        };
        $agencyOrder = match ($sort) {
            'za' => 'a.company_name DESC',
            'newest' => 'u.created_at DESC, a.id DESC',
            'oldest' => 'u.created_at ASC, a.id ASC',
            default => 'a.company_name ASC',
        };

        $statement = $pdo->prepare("
            SELECT a.id, a.company_name, a.nip_t, a.phone, a.address, u.created_at,
                   (SELECT COUNT(*) FROM agency_students x WHERE x.agency_id = a.id) AS student_count,
                   (SELECT COUNT(DISTINCT cg_open.id)
                    FROM agency_students ags_open
                    JOIN course_group_students cgs_open ON cgs_open.student_id = ags_open.student_id
                    JOIN course_groups cg_open ON cg_open.id = cgs_open.group_id
                    WHERE ags_open.agency_id = a.id AND cg_open.is_completed = 0) AS open_groups
            FROM agencies a
            JOIN users u ON u.id = a.user_id
            WHERE (a.company_name LIKE :a_name OR a.nip_t LIKE :a_nipt
                   OR a.phone LIKE :a_phone OR a.address LIKE :a_address)
            {$scope}
            ORDER BY {$agencyOrder}
            LIMIT :lim
        ");
        search_execute($statement, $params, $limit);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $students = (int)$row['student_count'];
            $openGroups = (int)$row['open_groups'];
            $items[] = [
                'title' => (string)($row['company_name'] ?: 'Agjenci #' . (int)$row['id']),
                'code' => (string)($row['nip_t'] ?? ''),
                'meta' => $students . ' punonjës' . ($row['phone'] ? ' · ' . $row['phone'] : ''),
                'href' => 'agencies.php?q=' . urlencode((string)($row['nip_t'] ?: $row['company_name'])),
                'icon' => 'bi-building',
                'status' => $students === 0 ? 'ungrouped' : ($openGroups > 0 ? 'active' : 'closed'),
                'status_label' => $students === 0 ? 'Pa punonjës' : ($openGroups > 0 ? 'Aktive' : 'Pa grup aktiv'),
            ];
        }
        $addGroup('agency', 'Agjenci', $items);
    }

    /* Llogaritë e stafit (vetëm administrator) ------------------------- */
    if ($want('user') && $status === 'any') {
        $scope = $periodDays ? " AND u.created_at >= DATE_SUB(NOW(), INTERVAL {$periodDays} DAY)" : '';
        $params = [':u_name' => $pattern, ':u_email' => $pattern, ':u_role' => $pattern];
        $userOrder = match ($sort) {
            'za' => 'u.full_name DESC, u.email DESC',
            'newest' => 'u.created_at DESC, u.id DESC',
            'oldest' => 'u.created_at ASC, u.id ASC',
            default => 'u.full_name ASC, u.email ASC',
        };
        $statement = $pdo->prepare("
            SELECT u.id, u.full_name, u.email, u.created_at, LOWER(r.name) AS role_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE LOWER(r.name) IN ('administrator', 'editor')
              AND (u.full_name LIKE :u_name OR u.email LIKE :u_email OR r.name LIKE :u_role)
              {$scope}
            ORDER BY {$userOrder}
            LIMIT :lim
        ");
        search_execute($statement, $params, $limit);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isAdmin = $row['role_name'] === 'administrator';
            $items[] = [
                'title' => (string)($row['full_name'] ?: $row['email'] ?: 'Përdorues #' . (int)$row['id']),
                'code' => (string)($row['email'] ?? ''),
                'meta' => ($isAdmin ? 'Administrator' : 'Editor') . ' · regjistruar ' . search_date((string)$row['created_at']),
                'href' => ($isAdmin ? 'users.php' : 'editors.php') . '?q=' . urlencode((string)($row['email'] ?? '')),
                'icon' => $isAdmin ? 'bi-shield-lock' : 'bi-pencil-square',
                'status' => 'neutral',
                'status_label' => $isAdmin ? 'Admin' : 'Editor',
            ];
        }
        $addGroup('user', 'Llogari stafi', $items);
    }

    /* Auditimi ---------------------------------------------------------- */
    if ($want('audit') && $status === 'any') {
        $scope = $periodDays ? " AND ae.happened_at >= DATE_SUB(NOW(), INTERVAL {$periodDays} DAY)" : '';
        $params = [
            ':l_table' => $pattern, ':l_action' => $pattern, ':l_pk' => $pattern,
            ':l_old' => $pattern, ':l_new' => $pattern, ':l_user' => $pattern,
            ':l_email' => $pattern, ':l_field' => $pattern,
        ];
        $auditOrder = match ($sort) {
            'az' => 'ae.table_name ASC, ae.happened_at DESC',
            'za' => 'ae.table_name DESC, ae.happened_at DESC',
            'oldest' => 'ae.happened_at ASC, ae.id ASC',
            default => 'ae.happened_at DESC, ae.id DESC',
        };
        $statement = $pdo->prepare("
            SELECT ae.id, ae.happened_at, ae.action, ae.table_name, ae.row_pk,
                   u.full_name, u.email
            FROM audit_events ae
            LEFT JOIN users u ON u.id = ae.user_id
            WHERE (
                ae.table_name LIKE :l_table OR ae.action LIKE :l_action OR ae.row_pk LIKE :l_pk
                OR ae.old_data LIKE :l_old OR ae.new_data LIKE :l_new
                OR u.full_name LIKE :l_user OR u.email LIKE :l_email
                OR EXISTS (
                    SELECT 1 FROM audit_event_fields aef
                    WHERE aef.event_id = ae.id
                      AND (aef.column_name LIKE :l_field OR aef.old_value LIKE :l_old_value OR aef.new_value LIKE :l_new_value)
                )
            )
            {$scope}
            ORDER BY {$auditOrder}
            LIMIT :lim
        ");
        $params[':l_old_value'] = $pattern;
        $params[':l_new_value'] = $pattern;
        search_execute($statement, $params, $limit);

        $tableLabels = [
            'students' => 'Kursantë', 'persons' => 'Persona', 'course_groups' => 'Grupe',
            'course_group_students' => 'Anëtarësi grupi', 'courses' => 'Module',
            'agencies' => 'Agjenci', 'agency_students' => 'Punonjës agjencie', 'users' => 'Përdorues',
        ];
        $actionLabels = ['INSERT' => 'Shtim', 'UPDATE' => 'Ndryshim', 'DELETE' => 'Fshirje'];
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $action = (string)$row['action'];
            $actor = (string)($row['full_name'] ?: $row['email'] ?: 'Sistemi');
            $items[] = [
                'title' => ($actionLabels[$action] ?? $action) . ' · ' . ($tableLabels[$row['table_name']] ?? $row['table_name']),
                'code' => '#' . (int)$row['id'],
                'meta' => search_date((string)$row['happened_at']) . ' · ' . $actor,
                'href' => ($role === 'editor' ? 'logs_editor.php' : 'logs.php') . '?q=' . urlencode($q),
                'icon' => $action === 'DELETE' ? 'bi-trash' : ($action === 'INSERT' ? 'bi-plus-circle' : 'bi-pencil'),
                'status' => strtolower($action),
                'status_label' => $actionLabels[$action] ?? $action,
            ];
        }
        $addGroup('audit', 'Auditim', $items);
    }

    $elapsed = (int)round((microtime(true) - $started) * 1000);
    search_out($basePayload + [
        'groups' => $groups,
        'counts' => $counts,
        'total' => $total,
        'took_ms' => $elapsed,
    ]);
} catch (Throwable $exception) {
    error_log('Advanced search failed: ' . $exception->getMessage());
    search_error('Kërkimi dështoi. Provo sërish.', 500);
}
