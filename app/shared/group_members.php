<?php
declare(strict_types=1);

/**
 * group_members.php — Rregullat e anëtarësisë në grup, për grupet me orar.
 *
 * Janë të njëjtat rregulla si te grupet e mëparshme (groups.php):
 *   - numrat e amzës shkruhen si "3400-3403, 3409" dhe renditen;
 *   - një numër amze që nuk ekziston krijon një kursant të ri pa të dhëna;
 *   - një regjistrim (nr. i amzës) mund të jetë vetëm në një grup;
 *   - i njëjti person (sipas numrit personal) nuk e ndjek dy herë të njëjtin kurs;
 *   - kur kursanti hyn në grup, zgjedhjet "pret grup" të tij hiqen;
 *   - një grup mban deri në 10 kursantë; mbi 10, grupet e reja ndahen në mënyrë të barabartë.
 * Ndryshe nga groups.php, gjithçka ndodh brenda transaksionit të thirrësit: kur
 * diçka nuk shkon, nuk mbetet asnjë kursant bosh i krijuar më kot.
 */

require_once __DIR__ . '/domain.php';

const QTA_GROUP_MAX_MEMBERS = 10;
const QTA_AMZE_MAX_PER_REQUEST = 200;

if (!function_exists('qta_amze_parse')) {
  /** "3400-3403, 3409" → [3400, 3401, 3402, 3403, 3409] (të renditur, pa përsëritje). */
  function qta_amze_parse(string $spec): array
  {
    $out = [];
    $bad = [];
    foreach (preg_split('/\s*[,;\n]\s*/', trim($spec)) ?: [] as $tok) {
      $tok = trim($tok);
      if ($tok === '') continue;
      if (preg_match('/^(\d{1,9})\s*[-–]\s*(\d{1,9})$/u', $tok, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($a > $b) [$a, $b] = [$b, $a];
        if ($b - $a >= QTA_AMZE_MAX_PER_REQUEST) {
          throw new QtaUserError('Intervali ' . $tok . ' është shumë i gjatë. Shkruaj deri në ' . QTA_AMZE_MAX_PER_REQUEST . ' numra amze njëherësh.');
        }
        for ($i = $a; $i <= $b; $i++) $out[$i] = true;
      } elseif (preg_match('/^\d{1,9}$/', $tok)) {
        $out[(int)$tok] = true;
      } else {
        $bad[] = $tok;
      }
    }
    if ($bad) {
      throw new QtaUserError('Nuk e kuptova: "' . implode('", "', array_slice($bad, 0, 3)) . '". Shkruaj numra amze të ndarë me presje ose intervale me vizë, p.sh. 3400-3403, 3409.');
    }
    if (count($out) > QTA_AMZE_MAX_PER_REQUEST) {
      throw new QtaUserError('Ke shkruar ' . count($out) . ' numra amze. Shkruaj deri në ' . QTA_AMZE_MAX_PER_REQUEST . ' njëherësh.');
    }
    $nums = array_keys($out);
    sort($nums, SORT_NUMERIC);
    return $nums;
  }

  /** Kursanti me këtë numër amze; nëse mungon, krijohet bosh (person + llogari + regjistrim). */
  function qta_amze_ensure_student(PDO $pdo, int $amze): int
  {
    $q = $pdo->prepare('SELECT id FROM students WHERE CAST(nr_amze AS UNSIGNED) = ? LIMIT 1');
    $q->execute([$amze]);
    $sid = $q->fetchColumn();
    if ($sid) return (int)$sid;

    $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'student'")->fetchColumn();
    $genderId = (int)($pdo->query("SELECT id FROM genders WHERE code IN ('M','m') OR LOWER(label) IN ('mashkull','male','m') LIMIT 1")->fetchColumn() ?: 0);
    if (!$roleId || !$genderId) {
      throw new QtaUserError('Kursanti i ri nuk u krijua: mungon roli "kursant" ose gjinia bazë. Njofto administratorin.');
    }
    $pdo->prepare('INSERT INTO persons (first_name, father_name, last_name, birth_date, birth_place, personal_number, phone, gender_id) VALUES (NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?)')
        ->execute([$genderId]);
    $pid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (role_id, person_id, full_name, email) VALUES (?, ?, NULL, NULL)')->execute([$roleId, $pid]);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO students (user_id, person_id, nr_amze, education_level_id) VALUES (?, ?, ?, NULL)')->execute([$uid, $pid, (string)$amze]);
    return (int)$pdo->lastInsertId();
  }

  /**
   * Kontrollet para se kursantët të hyjnë në një grup të kursit $courseId.
   * Hedh QtaUserError me listën e numrave të amzës që nuk lejohen.
   * @param int[] $studentIds
   */
  function qta_members_assert_can_join(PDO $pdo, array $studentIds, int $courseId): void
  {
    if (!$studentIds) return;
    $ph = implode(',', array_fill(0, count($studentIds), '?'));

    $dup = $pdo->prepare("
      SELECT DISTINCT s.nr_amze, cg.id AS group_id, c.name AS course_name
      FROM course_group_students cgs
      JOIN students s ON s.id = cgs.student_id
      JOIN course_groups cg ON cg.id = cgs.group_id
      JOIN courses c ON c.id = cg.course_id
      WHERE cgs.student_id IN ($ph)
      ORDER BY CAST(s.nr_amze AS UNSIGNED)
    ");
    $dup->execute($studentIds);
    if ($rows = $dup->fetchAll(PDO::FETCH_ASSOC)) {
      $items = array_map(static fn($r) => $r['nr_amze'] . ' (Grupi #' . $r['group_id'] . ', ' . $r['course_name'] . ')', $rows);
      throw new QtaUserError('Këta numra amze janë tashmë në një grup: ' . implode(', ', $items) . '. Një regjistrim mund të jetë vetëm në një grup.');
    }

    $pn = $pdo->prepare("SELECT DISTINCT p.personal_number FROM students s JOIN persons p ON p.id = s.person_id WHERE s.id IN ($ph) AND p.personal_number IS NOT NULL AND p.personal_number <> ''");
    $pn->execute($studentIds);
    $numbers = $pn->fetchAll(PDO::FETCH_COLUMN);
    if ($numbers) {
      $ph2 = implode(',', array_fill(0, count($numbers), '?'));
      $hit = $pdo->prepare("
        SELECT DISTINCT s.nr_amze, p.personal_number, cg.id AS group_id, c.name AS course_name
        FROM course_group_students cgs
        JOIN students s ON s.id = cgs.student_id
        JOIN persons p ON p.id = s.person_id
        JOIN course_groups cg ON cg.id = cgs.group_id
        JOIN courses c ON c.id = cg.course_id
        WHERE cg.course_id = ? AND p.personal_number IN ($ph2)
      ");
      $hit->execute(array_merge([$courseId], $numbers));
      if ($rows = $hit->fetchAll(PDO::FETCH_ASSOC)) {
        $items = array_map(static fn($r) => ($r['nr_amze'] ?: $r['personal_number']) . ' (Grupi #' . $r['group_id'] . ')', $rows);
        throw new QtaUserError('Disa persona e kanë ndjekur tashmë këtë kurs me një numër tjetër amze: ' . implode(', ', $items) . '. I njëjti person nuk e ndjek dy herë të njëjtin kurs.');
      }
    }
  }

  /**
   * Ndan kursantët (të renditur sipas amzës) në grupe me ≤ 10, sa më të barabarta,
   * pa ndryshuar radhën — e njëjta ndarje si te grupet e mëparshme.
   * @param array<int,int> $amzeToStudent amzë → student_id (e renditur)
   * @return array<int,array{ids:int[],amze_min:?int,amze_max:?int}>
   */
  function qta_members_split(array $amzeToStudent): array
  {
    $amze = array_keys($amzeToStudent);
    $ids = array_values($amzeToStudent);
    $total = count($ids);
    if ($total === 0) return [];
    $groups = (int)ceil($total / QTA_GROUP_MAX_MEMBERS);
    $base = intdiv($total, $groups);
    $rem = $total % $groups;
    $out = [];
    $cursor = 0;
    for ($g = 0; $g < $groups; $g++) {
      $size = $base + ($g < $rem ? 1 : 0);
      $chunkIds = array_slice($ids, $cursor, $size);
      $chunkAmze = array_slice($amze, $cursor, $size);
      $cursor += $size;
      $out[] = ['ids' => $chunkIds, 'amze_min' => $chunkAmze ? min($chunkAmze) : null, 'amze_max' => $chunkAmze ? max($chunkAmze) : null];
    }
    return $out;
  }
}
