<?php
declare(strict_types=1);
// Synthetic identities only. Run once against an empty, migrated test schema.
if (PHP_SAPI !== 'cli' || getenv('QTA_TEST_DB') !== '1') exit(2);
require_once __DIR__ . '/../../app/shared/database.php';
require_once __DIR__ . '/../../app/shared/group_members.php';
require_once __DIR__ . '/../../app/shared/lesson_groups.php';
$browser = in_array('--browser', $argv, true);
$pdo = getPDO();
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_contains($db, 'test') || $db === 'qta_db'
    || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    throw new RuntimeException('Seed requires an empty isolated test database.');
}
qta_tx($pdo, static function () use ($pdo, $browser): void {
    $hash = password_hash('Qta-Test-2026!', PASSWORD_DEFAULT);
    foreach (['administrator', 'editor', 'agjencia'] as $role) {
        $pdo->prepare('INSERT INTO users(role_id,full_name,email) SELECT id,?,? FROM roles WHERE name=?')
            ->execute(['Test ' . $role, $role . '@test.invalid', $role]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO credentials(user_id,password_hash) VALUES(?,?)')->execute([$uid, $hash]);
        if ($role === 'administrator') $pdo->prepare('INSERT INTO admins(user_id) VALUES(?)')->execute([$uid]);
        if ($role === 'agjencia') $pdo->prepare('INSERT INTO agencies(user_id,nip_t,company_name) VALUES(?,?,?)')->execute([$uid, 'T12345678A', 'AgjenciaTest']);
    }
    $sid = qta_amze_ensure_batch($pdo, [12345680])[12345680];
    $pdo->prepare("UPDATE persons p JOIN students s ON s.person_id=p.id SET p.personal_number='T26010100A',p.first_name='KursantTest' WHERE s.id=?")->execute([$sid]);
    $pdo->prepare('INSERT INTO credentials(user_id,password_hash) SELECT user_id,? FROM students WHERE id=?')->execute([$hash, $sid]);
    $pdo->prepare('INSERT INTO courses(code,name,hours) VALUES(?,?,10)')->execute(['RR-BASE', $browser ? 'Reliability fixture' : 'Kursi test']);
    $course = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO course_groups(course_id,start_date,end_date,is_completed) VALUES(?,'2090-01-01','2090-01-05',0)")->execute([$course]);
    $group = (int)$pdo->lastInsertId();
    if ($browser) {
        foreach (qta_amze_ensure_batch($pdo, range(7977, 7982)) as $student) {
            $pdo->prepare('INSERT INTO course_group_students(group_id,student_id) VALUES(?,?)')->execute([$group, $student]);
        }
        $pdo->exec("INSERT INTO courses(code,name,hours) VALUES('RR-SCHEDULE','Scheduled browser fixture',10)");
        $scheduledCourse = (int)$pdo->lastInsertId();
        $module = qta_curriculum_add_module($pdo, $scheduledCourse, 'Moduli test', 10);
        qta_curriculum_add_topic($pdo, $module, 'Tema test', 10);
        qta_lg_create($pdo, ['course_id' => $scheduledCourse, 'start_date' => '2090-02-01', 'daily_hours' => 5, 'amze_spec' => '']);
    }
});
echo "Synthetic admin/editor/agency/student and one legacy group seeded.\n";
