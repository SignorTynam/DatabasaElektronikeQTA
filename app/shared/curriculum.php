<?php
declare(strict_types=1);

/**
 * curriculum.php — Struktura e kursit: Kursi → Modulet (në radhë) → Temat (në radhë).
 *
 * Rregullat (kontrollohen këtu, në server):
 *   Σ orët e moduleve = orët e kursit
 *   Σ orët e temave të një moduli = orët e modulit
 *   çdo modul ka të paktën një temë; radha është 1, 2, 3 … pa boshllëqe
 *
 * Kursi mund të ruhet edhe "jo gati" (p.sh. kur shtohen modulet një nga një),
 * por vetëm një kurs "gati" mund të përdoret për një grup me orar.
 *
 * Çdo ndryshim bëhet në një transaksion që bllokon rreshtin e kursit, që dy
 * persona që punojnë njëkohësisht të mos e prishin radhën.
 */

require_once __DIR__ . '/domain.php';

const QTA_MODULE_TITLE_MAX = 200;
const QTA_TOPIC_TITLE_MAX = 255;
const QTA_HOURS_MAX = 65535;

/* ============================================================== Leximi */

if (!function_exists('qta_course_find')) {
  function qta_course_find(PDO $pdo, int $courseId, bool $lock = false): ?array
  {
    $st = $pdo->prepare('SELECT id, code, name, hours, created_at FROM courses WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$courseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return null;
    }
    $row['id'] = (int)$row['id'];
    $row['hours'] = (int)$row['hours'];
    return $row;
  }

  /**
   * Modulet e kursit në radhë, secili me temat e veta në radhë. Dy query,
   * sa do module e tema të ketë.
   * @return array<int,array<string,mixed>>
   */
  function qta_course_modules(PDO $pdo, int $courseId): array
  {
    $ms = $pdo->prepare('SELECT id, course_id, position, title, hours FROM course_modules WHERE course_id = ? ORDER BY position ASC, id ASC');
    $ms->execute([$courseId]);
    $modules = [];
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
      $m['id'] = (int)$m['id'];
      $m['course_id'] = (int)$m['course_id'];
      $m['position'] = (int)$m['position'];
      $m['hours'] = (int)$m['hours'];
      $m['topics'] = [];
      $modules[$m['id']] = $m;
    }
    if ($modules) {
      $ts = $pdo->prepare('
        SELECT t.id, t.module_id, t.position, t.title, t.hours
        FROM course_topics t
        JOIN course_modules m ON m.id = t.module_id
        WHERE m.course_id = ?
        ORDER BY t.module_id ASC, t.position ASC, t.id ASC
      ');
      $ts->execute([$courseId]);
      foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $t['id'] = (int)$t['id'];
        $t['module_id'] = (int)$t['module_id'];
        $t['position'] = (int)$t['position'];
        $t['hours'] = (int)$t['hours'];
        if (isset($modules[$t['module_id']])) {
          $modules[$t['module_id']]['topics'][] = $t;
        }
      }
    }
    return array_values($modules);
  }

  function qta_module_find(PDO $pdo, int $moduleId): ?array
  {
    $st = $pdo->prepare('SELECT id, course_id, position, title, hours FROM course_modules WHERE id = ?');
    $st->execute([$moduleId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) return null;
    foreach (['id', 'course_id', 'position', 'hours'] as $k) $m[$k] = (int)$m[$k];
    return $m;
  }

  function qta_topic_find(PDO $pdo, int $topicId): ?array
  {
    $st = $pdo->prepare('
      SELECT t.id, t.module_id, t.position, t.title, t.hours, m.course_id, m.title AS module_title
      FROM course_topics t JOIN course_modules m ON m.id = t.module_id
      WHERE t.id = ?
    ');
    $st->execute([$topicId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    foreach (['id', 'module_id', 'position', 'hours', 'course_id'] as $k) $t[$k] = (int)$t[$k];
    return $t;
  }
}

/* ========================================================= Gatishmëria */

if (!function_exists('qta_course_check')) {
  /**
   * A mund të përdoret kursi për një grup me orar? Kthen gjendjen dhe
   * problemet me fjalë: çfarë nuk shkon dhe çfarë të bësh. Disa probleme kanë
   * një rregullim me një klik ('fix').
   *
   * @param array<string,mixed>            $course
   * @param array<int,array<string,mixed>> $modules  nga qta_course_modules()
   * @return array<string,mixed>
   */
  function qta_course_check(array $course, array $modules): array
  {
    $courseHours = (int)$course['hours'];
    $issues = [];
    $moduleSum = 0;
    $topicCount = 0;
    $perModule = [];

    if (!$modules) {
      $issues[] = ['text' => 'Kursi nuk ka ende module. Shto modulin e parë me butonin "Shto modul".', 'module_id' => null, 'fix' => null];
    }

    $expectedPos = 1;
    $modulesOrdered = true;
    foreach ($modules as $m) {
      if ((int)$m['position'] !== $expectedPos++) $modulesOrdered = false;
      $moduleSum += (int)$m['hours'];
    }

    if ($modules && $moduleSum !== $courseHours) {
      $diff = abs($courseHours - $moduleSum);
      $issues[] = $moduleSum < $courseHours
        ? ['text' => 'Modulet kanë ' . qta_hours_label($moduleSum) . ' nga ' . qta_hours_label($courseHours) . ' të kursit. Shto edhe ' . qta_hours_label($diff) . ' te modulet, ose ul orët e kursit në ' . $moduleSum . '.',
           'module_id' => null, 'fix' => ['action' => 'set_course_hours', 'value' => $moduleSum, 'label' => 'Vendos orët e kursit në ' . $moduleSum]]
        : ['text' => 'Modulet kanë ' . qta_hours_label($moduleSum) . ', por kursi ka vetëm ' . qta_hours_label($courseHours) . '. Hiq ' . qta_hours_label($diff) . ' nga modulet, ose rrit orët e kursit në ' . $moduleSum . '.',
           'module_id' => null, 'over' => true, 'fix' => ['action' => 'set_course_hours', 'value' => $moduleSum, 'label' => 'Vendos orët e kursit në ' . $moduleSum]];
    }
    if (!$modulesOrdered) {
      $issues[] = ['text' => 'Radha e moduleve nuk është e qartë. Shtyp "Rregullo radhën" që modulet të numërohen 1, 2, 3 … siç shfaqen.',
                   'module_id' => null, 'fix' => ['action' => 'normalize', 'value' => null, 'label' => 'Rregullo radhën']];
    }

    foreach ($modules as $m) {
      $mid = (int)$m['id'];
      $topics = $m['topics'] ?? [];
      $sum = 0;
      $ordered = true;
      $expected = 1;
      foreach ($topics as $t) {
        $sum += (int)$t['hours'];
        if ((int)$t['position'] !== $expected++) $ordered = false;
      }
      $topicCount += count($topics);
      $ok = $topics && $sum === (int)$m['hours'] && $ordered;
      $perModule[$mid] = ['topic_hours' => $sum, 'topics' => count($topics), 'ok' => $ok];
      $name = '"' . $m['title'] . '"';
      if (!$topics) {
        $issues[] = ['text' => 'Moduli ' . $name . ' nuk ka ende tema. Shto të paktën një temë.', 'module_id' => $mid, 'fix' => null];
        continue;
      }
      if ($sum !== (int)$m['hours']) {
        $diff = abs((int)$m['hours'] - $sum);
        if ($sum < (int)$m['hours']) {
          $issues[] = ['text' => 'Temat e modulit ' . $name . ' kanë ' . qta_hours_label($sum) . ' nga ' . qta_hours_label((int)$m['hours']) . '. Shto edhe ' . qta_hours_label($diff) . ' te temat, ose ul orët e modulit në ' . $sum . '.',
                       'module_id' => $mid, 'fix' => ['action' => 'set_module_hours', 'value' => $sum, 'label' => 'Vendos orët e modulit në ' . $sum]];
        } else {
          /* Temat kalojnë modulin (vetëm te të dhëna më të vjetra: sot nuk lejohet).
             Moduli rritet me një klik vetëm kur kursi ka ende vend për të. */
          $fits = $moduleSum - (int)$m['hours'] + $sum <= $courseHours;
          $issues[] = ['text' => 'Temat e modulit ' . $name . ' kanë ' . qta_hours_label($sum) . ', por moduli ka vetëm ' . qta_hours_label((int)$m['hours']) . '. Hiq ' . qta_hours_label($diff) . ' nga temat'
                                 . ($fits ? ', ose rrit orët e modulit në ' . $sum . '.' : '.'),
                       'module_id' => $mid, 'over' => true,
                       'fix' => $fits ? ['action' => 'set_module_hours', 'value' => $sum, 'label' => 'Vendos orët e modulit në ' . $sum] : null];
        }
      }
      if (!$ordered) {
        $issues[] = ['text' => 'Radha e temave te moduli ' . $name . ' nuk është e qartë. Shtyp "Rregullo radhën".',
                     'module_id' => $mid, 'fix' => ['action' => 'normalize', 'value' => null, 'label' => 'Rregullo radhën']];
      }
    }

    return [
      'ready'        => !$issues,
      'course_hours' => $courseHours,
      'module_hours' => $moduleSum,
      'module_count' => count($modules),
      'topic_count'  => $topicCount,
      'issues'       => $issues,
      'modules'      => $perModule,
    ];
  }

  /**
   * Përmbledhje për listat (faqja e kurseve, krijimi i grupit): gatishmëria e
   * shumë kurseve me dy query të grupuara. Përputhet me qta_course_check().
   * @param int[]|null $courseIds null = të gjitha
   * @return array<int,array{modules:int,topics:int,module_hours:int,ready:bool,has_structure:bool}>
   */
  function qta_course_summaries(PDO $pdo, ?array $courseIds = null): array
  {
    $where = '';
    $params = [];
    if ($courseIds !== null) {
      $courseIds = array_values(array_unique(array_filter(array_map('intval', $courseIds))));
      if (!$courseIds) return [];
      $where = ' WHERE c.id IN (' . implode(',', array_fill(0, count($courseIds), '?')) . ')';
      $params = $courseIds;
    }
    $st = $pdo->prepare("
      SELECT c.id AS course_id, c.hours AS course_hours,
             COUNT(m.id) AS modules,
             COALESCE(SUM(m.hours), 0) AS module_hours,
             COALESCE(SUM(t.cnt), 0) AS topics,
             COALESCE(SUM(CASE WHEN m.id IS NOT NULL AND (t.cnt IS NULL OR t.hsum <> m.hours OR t.ordered = 0) THEN 1 ELSE 0 END), 0) AS bad_modules,
             COALESCE(MIN(m.position), 0) AS min_pos, COALESCE(MAX(m.position), 0) AS max_pos,
             COUNT(DISTINCT m.position) AS distinct_pos
      FROM courses c
      LEFT JOIN course_modules m ON m.course_id = c.id
      LEFT JOIN (
        SELECT module_id, COUNT(*) AS cnt, SUM(hours) AS hsum,
               (MIN(position) = 1 AND MAX(position) = COUNT(*) AND COUNT(DISTINCT position) = COUNT(*)) AS ordered
        FROM course_topics GROUP BY module_id
      ) t ON t.module_id = m.id
      $where
      GROUP BY c.id, c.hours
    ");
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $modules = (int)$r['modules'];
      $orderedModules = $modules === 0 || ((int)$r['min_pos'] === 1 && (int)$r['max_pos'] === $modules && (int)$r['distinct_pos'] === $modules);
      $out[(int)$r['course_id']] = [
        'modules'       => $modules,
        'topics'        => (int)$r['topics'],
        'module_hours'  => (int)$r['module_hours'],
        'has_structure' => $modules > 0,
        'ready'         => $modules > 0 && (int)$r['module_hours'] === (int)$r['course_hours']
                           && (int)$r['bad_modules'] === 0 && $orderedModules,
      ];
    }
    return $out;
  }

  /**
   * Temat e kursit si listë e vetme në radhë, gati për motorin e orarit dhe për
   * kopjen e grupit.
   * @return array<int,array<string,mixed>>
   */
  function qta_course_schedule_topics(array $modules): array
  {
    $out = [];
    $seq = 0;
    foreach (array_values($modules) as $mi => $m) {
      foreach (array_values($m['topics'] ?? []) as $ti => $t) {
        $out[] = [
          'seq' => ++$seq,
          'module_seq' => $mi + 1,
          'module_title' => (string)$m['title'],
          'module_hours' => (int)$m['hours'],
          'topic_seq' => $ti + 1,
          'topic_title' => (string)$t['title'],
          'hours' => (int)$t['hours'],
          'source_module_id' => (int)$m['id'],
          'source_topic_id' => (int)$t['id'],
        ];
      }
    }
    return $out;
  }
}

/* ========================================================= Ndryshimet */

if (!function_exists('qta_curriculum_lock_course')) {
  function qta_curriculum_lock_course(PDO $pdo, int $courseId): array
  {
    $course = qta_course_find($pdo, $courseId, true);
    if (!$course) {
      throw new QtaUserError('Kursi nuk u gjet. Rifresko faqen.');
    }
    return $course;
  }

  function qta_curriculum_title($value, int $max, string $what): string
  {
    $title = qta_clean_text($value, $max);
    if ($title === '') {
      throw new QtaUserError($what === 'module' ? 'Shkruaj emrin e modulit, p.sh. "Word".' : 'Shkruaj emrin e temës, p.sh. "Formatimi i tekstit".');
    }
    return $title;
  }

  function qta_curriculum_hours($value, string $what): int
  {
    $n = qta_parse_int_input($value, 1, QTA_HOURS_MAX);
    if ($n === null) {
      throw new QtaUserError(($what === 'module' ? 'Orët e modulit' : ($what === 'topic' ? 'Orët e temës' : 'Orët e kursit'))
        . ' duhet të jenë një numër i plotë, të paktën 1, p.sh. ' . ($what === 'topic' ? '2' : '10') . '.');
    }
    return $n;
  }

  /* ------------------------------------------------------------------
     Orët e pjesëve nuk kalojnë kurrë orët e së tërës:
       Σ temat ≤ moduli  dhe  Σ modulet ≤ kursi.
     Më pak lejohet (kursi ndërtohet hap pas hapi); gati për grup është vetëm
     kur shumat janë të barabarta. Një ndryshim që i ul orët lejohet gjithmonë,
     edhe kur të dhëna më të vjetra e kalojnë kufirin: e afron te rregulli.
     ------------------------------------------------------------------ */

  /** Shuma e orëve të temave të një moduli, pa temën $exceptId. */
  function qta_curriculum_topic_hours(PDO $pdo, int $moduleId, int $exceptId = 0): int
  {
    $st = $pdo->prepare('SELECT COALESCE(SUM(hours), 0) FROM course_topics WHERE module_id = ? AND id <> ?');
    $st->execute([$moduleId, $exceptId]);
    return (int)$st->fetchColumn();
  }

  /** Shuma e orëve të moduleve të një kursi, pa modulin $exceptId. */
  function qta_curriculum_module_hours(PDO $pdo, int $courseId, int $exceptId = 0): int
  {
    $st = $pdo->prepare('SELECT COALESCE(SUM(hours), 0) FROM course_modules WHERE course_id = ? AND id <> ?');
    $st->execute([$courseId, $exceptId]);
    return (int)$st->fetchColumn();
  }

  /**
   * Gabimi që ndërfaqja e shfaq si dialog: çfarë nuk shkon dhe, kur ka një vlerë
   * të vlefshme, butoni që e vendos ("Vendos 10 orë").
   */
  function qta_hours_limit_error(string $title, string $message, ?int $fixValue = null): QtaUserError
  {
    $fix = $fixValue !== null && $fixValue >= 1
      ? ['value' => $fixValue, 'label' => 'Vendos ' . qta_hours_label($fixValue)]
      : null;
    return new QtaUserError($title . '. ' . $message, [
      'code' => 'hours_limit',
      'dialog' => ['title' => $title, 'message' => $message, 'fix' => $fix],
    ]);
  }

  /** Tema e re ose tema me më shumë orë nuk i kalon orët e modulit. */
  function qta_curriculum_assert_topic_hours(PDO $pdo, array $module, int $newHours, ?array $topic = null): void
  {
    $oldHours = $topic ? (int)$topic['hours'] : 0;
    if ($topic && $newHours <= $oldHours) return;
    $others = qta_curriculum_topic_hours($pdo, (int)$module['id'], $topic ? (int)$topic['id'] : 0);
    $limit = (int)$module['hours'];
    if ($others + $newHours <= $limit) return;
    $max = max(0, $limit - $others);
    $lead = 'Moduli "' . $module['title'] . '" ka ' . qta_hours_label($limit) . '. '
      . ($topic ? 'Me këtë ndryshim' : 'Me këtë temë') . ', temat e tij do të kishin ' . qta_hours_label($others + $newHours) . '. ';
    if ($max > 0) {
      $next = $topic ? 'Kjo temë mund të ketë deri në ' . qta_hours_label($max) . '.' : 'Për temën e re mbeten ' . qta_hours_label($max) . '.';
    } elseif ($others > $limit) {
      $next = ($topic ? 'Temat e tjera' : 'Temat që ka') . ' i kalojnë tashmë orët e modulit (' . $others . ' nga ' . $limit . '). Ul së pari orët e tyre.';
    } else {
      $next = ($topic ? 'Temat e tjera' : 'Temat që ka') . ' i kanë zënë të gjitha orët e modulit. Ul orët e një teme' . ($topic ? ' tjetër' : '')
        . ', ose rrit orët e modulit me "Ndrysho".';
    }
    throw qta_hours_limit_error('Temat kalojnë orët e modulit', $lead . $next, $max > 0 ? $max : null);
  }

  /**
   * Orët e reja të një moduli: jo më pak se temat e tij dhe, bashkë me modulet
   * e tjera, jo më shumë se kursi. $module = null për modul të ri.
   */
  function qta_curriculum_assert_module_hours(PDO $pdo, array $course, int $newHours, ?array $module = null): void
  {
    $oldHours = $module ? (int)$module['hours'] : 0;
    if ($module && $newHours === $oldHours) return;
    if ($module && $newHours < $oldHours) {
      $topicHours = qta_curriculum_topic_hours($pdo, (int)$module['id']);
      if ($newHours >= $topicHours) return;
      throw qta_hours_limit_error('Temat kanë më shumë orë se moduli',
        'Temat e modulit "' . $module['title'] . '" kanë ' . qta_hours_label($topicHours) . ', prandaj moduli nuk mund të ketë më pak se '
          . qta_hours_label($topicHours) . '. Për ta ulur, ul së pari orët e temave.',
        $topicHours <= $oldHours ? $topicHours : null);
    }
    $others = qta_curriculum_module_hours($pdo, (int)$course['id'], $module ? (int)$module['id'] : 0);
    $limit = (int)$course['hours'];
    if ($others + $newHours <= $limit) return;
    $max = max(0, $limit - $others);
    $topicHours = $module ? qta_curriculum_topic_hours($pdo, (int)$module['id']) : 0;
    /* Vlera e propozuar duhet të respektojë edhe temat e modulit. */
    $fix = $max > 0 && ($max >= $oldHours || $max >= $topicHours) ? $max : null;
    $lead = 'Kursi "' . $course['name'] . '" ka ' . qta_hours_label($limit) . '. '
      . ($module ? 'Me këtë ndryshim' : 'Me këtë modul') . ', modulet e tij do të kishin ' . qta_hours_label($others + $newHours) . '. ';
    if ($max > 0) {
      $next = $module ? 'Ky modul mund të ketë deri në ' . qta_hours_label($max) . '.' : 'Për modulin e ri mbeten ' . qta_hours_label($max) . '.';
    } elseif ($others > $limit) {
      $next = ($module ? 'Modulet e tjera' : 'Modulet që ka') . ' i kalojnë tashmë orët e kursit (' . $others . ' nga ' . $limit . '). Ul së pari orët e tyre, ose rrit orët e kursit me "Ndrysho kursin".';
    } else {
      $next = ($module ? 'Modulet e tjera' : 'Modulet që ka') . ' i kanë zënë të gjitha orët e kursit. Ul orët e një moduli' . ($module ? ' tjetër' : '')
        . ', ose rrit orët e kursit me "Ndrysho kursin".';
    }
    throw qta_hours_limit_error('Modulet kalojnë orët e kursit', $lead . $next, $fix);
  }

  /** Orët e kursit nuk ulen nën shumën e moduleve të tij. */
  function qta_curriculum_assert_course_hours(PDO $pdo, array $course, int $newHours): void
  {
    if ($newHours >= (int)$course['hours']) return;
    $moduleHours = qta_curriculum_module_hours($pdo, (int)$course['id']);
    if ($newHours >= $moduleHours) return;
    throw qta_hours_limit_error('Modulet kanë më shumë orë se kursi',
      'Modulet e kursit "' . $course['name'] . '" kanë ' . qta_hours_label($moduleHours) . ', prandaj kursi nuk mund të ketë më pak se '
        . qta_hours_label($moduleHours) . '. Për ta ulur, ul së pari orët e moduleve.',
      $moduleHours);
  }

  /**
   * Vendos rreshtin $movedId në vendin $target (1 = i pari) dhe rinumëron
   * 1, 2, 3 … Përditëson vetëm rreshtat që ndryshojnë vend (historiku mbetet i pastër).
   */
  function qta_curriculum_reorder(PDO $pdo, string $table, int $parentId, ?int $movedId, ?int $target): void
  {
    $parentCol = ['course_modules' => 'course_id', 'course_topics' => 'module_id'][$table] ?? null;
    if ($parentCol === null) {
      throw new InvalidArgumentException('Tabelë e panjohur për radhën.');
    }
    $st = $pdo->prepare("SELECT id, position FROM $table WHERE $parentCol = ? ORDER BY position ASC, id ASC");
    $st->execute([$parentId]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $ids = array_map('intval', array_keys($rows));
    if ($movedId !== null) {
      $ids = array_values(array_filter($ids, static fn($id) => $id !== $movedId));
      $at = max(0, min(count($ids), ($target ?? (count($ids) + 1)) - 1));
      array_splice($ids, $at, 0, [$movedId]);
    }
    $upd = $pdo->prepare("UPDATE $table SET position = ? WHERE id = ?");
    foreach ($ids as $i => $id) {
      if ((int)($rows[$id] ?? 0) !== $i + 1) {
        $upd->execute([$i + 1, $id]);
      }
    }
  }

  function qta_curriculum_add_module(PDO $pdo, int $courseId, $title, $hours, $position = null): int
  {
    return qta_tx($pdo, function () use ($pdo, $courseId, $title, $hours, $position): int {
      $course = qta_curriculum_lock_course($pdo, $courseId);
      $title = qta_curriculum_title($title, QTA_MODULE_TITLE_MAX, 'module');
      $hours = qta_curriculum_hours($hours, 'module');
      qta_curriculum_assert_module_hours($pdo, $course, $hours);
      $count = (int)$pdo->query('SELECT COUNT(*) FROM course_modules WHERE course_id = ' . $courseId)->fetchColumn();
      $pdo->prepare('INSERT INTO course_modules (course_id, position, title, hours) VALUES (?, ?, ?, ?)')
          ->execute([$courseId, $count + 1, $title, $hours]);
      $id = (int)$pdo->lastInsertId();
      $pos = qta_parse_int_input($position, 1, $count + 1);
      if ($pos !== null && $pos !== $count + 1) {
        qta_curriculum_reorder($pdo, 'course_modules', $courseId, $id, $pos);
      }
      return $id;
    });
  }

  /** @param array{title?:mixed,hours?:mixed,position?:mixed} $fields */
  function qta_curriculum_update_module(PDO $pdo, int $moduleId, array $fields): array
  {
    return qta_tx($pdo, function () use ($pdo, $moduleId, $fields): array {
      $m = qta_module_find($pdo, $moduleId);
      if (!$m) throw new QtaUserError('Moduli nuk u gjet. Rifresko faqen.');
      $course = qta_curriculum_lock_course($pdo, $m['course_id']);
      $m = qta_module_find($pdo, $moduleId) ?? $m;
      $sets = [];
      $vals = [];
      if (array_key_exists('title', $fields)) { $sets[] = 'title = ?'; $vals[] = qta_curriculum_title($fields['title'], QTA_MODULE_TITLE_MAX, 'module'); }
      if (array_key_exists('hours', $fields)) {
        $newHours = qta_curriculum_hours($fields['hours'], 'module');
        qta_curriculum_assert_module_hours($pdo, $course, $newHours, $m);
        $sets[] = 'hours = ?';
        $vals[] = $newHours;
      }
      if ($sets) {
        $vals[] = $moduleId;
        $pdo->prepare('UPDATE course_modules SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
      }
      if (array_key_exists('position', $fields) && $fields['position'] !== null && $fields['position'] !== '') {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM course_modules WHERE course_id = ' . (int)$m['course_id'])->fetchColumn();
        $pos = qta_parse_int_input($fields['position'], 1, max(1, $count));
        if ($pos === null) throw new QtaUserError('Zgjidh një vend nga 1 deri në ' . $count . '.');
        qta_curriculum_reorder($pdo, 'course_modules', (int)$m['course_id'], $moduleId, $pos);
      }
      return qta_module_find($pdo, $moduleId) ?? $m;
    });
  }

  /** Fshin modulin bashkë me temat e tij. Grupet ekzistuese nuk preken (kanë kopjen e tyre). */
  function qta_curriculum_delete_module(PDO $pdo, int $moduleId): array
  {
    return qta_tx($pdo, function () use ($pdo, $moduleId): array {
      $m = qta_module_find($pdo, $moduleId);
      if (!$m) throw new QtaUserError('Moduli nuk u gjet. Ndoshta u fshi tashmë — rifresko faqen.');
      qta_curriculum_lock_course($pdo, $m['course_id']);
      $del = $pdo->prepare('DELETE FROM course_topics WHERE module_id = ?');
      $del->execute([$moduleId]);
      $topics = $del->rowCount();
      $pdo->prepare('DELETE FROM course_modules WHERE id = ?')->execute([$moduleId]);
      qta_curriculum_reorder($pdo, 'course_modules', (int)$m['course_id'], null, null);
      return ['title' => $m['title'], 'topics' => $topics, 'course_id' => $m['course_id']];
    });
  }

  function qta_curriculum_add_topic(PDO $pdo, int $moduleId, $title, $hours, $position = null): int
  {
    return qta_tx($pdo, function () use ($pdo, $moduleId, $title, $hours, $position): int {
      $m = qta_module_find($pdo, $moduleId);
      if (!$m) throw new QtaUserError('Moduli nuk u gjet. Rifresko faqen.');
      qta_curriculum_lock_course($pdo, $m['course_id']);
      $m = qta_module_find($pdo, $moduleId) ?? $m;
      $title = qta_curriculum_title($title, QTA_TOPIC_TITLE_MAX, 'topic');
      $hours = qta_curriculum_hours($hours, 'topic');
      qta_curriculum_assert_topic_hours($pdo, $m, $hours);
      $count = (int)$pdo->query('SELECT COUNT(*) FROM course_topics WHERE module_id = ' . $moduleId)->fetchColumn();
      $pdo->prepare('INSERT INTO course_topics (module_id, position, title, hours) VALUES (?, ?, ?, ?)')
          ->execute([$moduleId, $count + 1, $title, $hours]);
      $id = (int)$pdo->lastInsertId();
      $pos = qta_parse_int_input($position, 1, $count + 1);
      if ($pos !== null && $pos !== $count + 1) {
        qta_curriculum_reorder($pdo, 'course_topics', $moduleId, $id, $pos);
      }
      return $id;
    });
  }

  /** @param array{title?:mixed,hours?:mixed,position?:mixed} $fields */
  function qta_curriculum_update_topic(PDO $pdo, int $topicId, array $fields): array
  {
    return qta_tx($pdo, function () use ($pdo, $topicId, $fields): array {
      $t = qta_topic_find($pdo, $topicId);
      if (!$t) throw new QtaUserError('Tema nuk u gjet. Rifresko faqen.');
      qta_curriculum_lock_course($pdo, $t['course_id']);
      $t = qta_topic_find($pdo, $topicId) ?? $t;
      $sets = [];
      $vals = [];
      if (array_key_exists('title', $fields)) { $sets[] = 'title = ?'; $vals[] = qta_curriculum_title($fields['title'], QTA_TOPIC_TITLE_MAX, 'topic'); }
      if (array_key_exists('hours', $fields)) {
        $newHours = qta_curriculum_hours($fields['hours'], 'topic');
        $module = qta_module_find($pdo, (int)$t['module_id']);
        if ($module) qta_curriculum_assert_topic_hours($pdo, $module, $newHours, $t);
        $sets[] = 'hours = ?';
        $vals[] = $newHours;
      }
      if ($sets) {
        $vals[] = $topicId;
        $pdo->prepare('UPDATE course_topics SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
      }
      if (array_key_exists('position', $fields) && $fields['position'] !== null && $fields['position'] !== '') {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM course_topics WHERE module_id = ' . (int)$t['module_id'])->fetchColumn();
        $pos = qta_parse_int_input($fields['position'], 1, max(1, $count));
        if ($pos === null) throw new QtaUserError('Zgjidh një vend nga 1 deri në ' . $count . '.');
        qta_curriculum_reorder($pdo, 'course_topics', (int)$t['module_id'], $topicId, $pos);
      }
      return qta_topic_find($pdo, $topicId) ?? $t;
    });
  }

  function qta_curriculum_delete_topic(PDO $pdo, int $topicId): array
  {
    return qta_tx($pdo, function () use ($pdo, $topicId): array {
      $t = qta_topic_find($pdo, $topicId);
      if (!$t) throw new QtaUserError('Tema nuk u gjet. Ndoshta u fshi tashmë — rifresko faqen.');
      qta_curriculum_lock_course($pdo, $t['course_id']);
      $pdo->prepare('DELETE FROM course_topics WHERE id = ?')->execute([$topicId]);
      qta_curriculum_reorder($pdo, 'course_topics', (int)$t['module_id'], null, null);
      return $t;
    });
  }

  /** Lëviz një modul ose temë një vend lart (-1) ose poshtë (+1). */
  function qta_curriculum_move(PDO $pdo, string $kind, int $id, int $delta): array
  {
    return qta_tx($pdo, function () use ($pdo, $kind, $id, $delta): array {
      if ($kind === 'module') {
        $row = qta_module_find($pdo, $id);
        if (!$row) throw new QtaUserError('Moduli nuk u gjet. Rifresko faqen.');
        qta_curriculum_lock_course($pdo, $row['course_id']);
        qta_curriculum_reorder($pdo, 'course_modules', $row['course_id'], null, null);
        $row = qta_module_find($pdo, $id);
        $count = (int)$pdo->query('SELECT COUNT(*) FROM course_modules WHERE course_id = ' . $row['course_id'])->fetchColumn();
        $target = max(1, min($count, $row['position'] + $delta));
        qta_curriculum_reorder($pdo, 'course_modules', $row['course_id'], $id, $target);
        return ['position' => $target, 'count' => $count, 'title' => $row['title']];
      }
      $row = qta_topic_find($pdo, $id);
      if (!$row) throw new QtaUserError('Tema nuk u gjet. Rifresko faqen.');
      qta_curriculum_lock_course($pdo, $row['course_id']);
      qta_curriculum_reorder($pdo, 'course_topics', $row['module_id'], null, null);
      $row = qta_topic_find($pdo, $id);
      $count = (int)$pdo->query('SELECT COUNT(*) FROM course_topics WHERE module_id = ' . $row['module_id'])->fetchColumn();
      $target = max(1, min($count, $row['position'] + $delta));
      qta_curriculum_reorder($pdo, 'course_topics', $row['module_id'], $id, $target);
      return ['position' => $target, 'count' => $count, 'title' => $row['title']];
    });
  }

  /** Numëron sërish 1, 2, 3 … modulet dhe temat e kursit, sipas radhës që shfaqet. */
  function qta_curriculum_normalize(PDO $pdo, int $courseId): void
  {
    qta_tx($pdo, function () use ($pdo, $courseId): void {
      qta_curriculum_lock_course($pdo, $courseId);
      qta_curriculum_reorder($pdo, 'course_modules', $courseId, null, null);
      $st = $pdo->prepare('SELECT id FROM course_modules WHERE course_id = ?');
      $st->execute([$courseId]);
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        qta_curriculum_reorder($pdo, 'course_topics', (int)$mid, null, null);
      }
    });
  }

  function qta_curriculum_set_course_hours(PDO $pdo, int $courseId, $hours): void
  {
    qta_tx($pdo, function () use ($pdo, $courseId, $hours): void {
      $course = qta_curriculum_lock_course($pdo, $courseId);
      $h = qta_curriculum_hours($hours, 'course');
      qta_curriculum_assert_course_hours($pdo, $course, $h);
      $pdo->prepare('UPDATE courses SET hours = ? WHERE id = ?')->execute([$h, $courseId]);
    });
  }

  /**
   * Fshin një kurs pa grupe, bashkë me modulet dhe temat e tij. Kurset me grupe
   * (të mëparshme ose me orar) nuk fshihen: grupet ruajnë provimet dhe pikët.
   */
  function qta_course_delete(PDO $pdo, int $courseId): array
  {
    return qta_tx($pdo, function () use ($pdo, $courseId): array {
      $course = qta_curriculum_lock_course($pdo, $courseId);
      $gc = $pdo->prepare('SELECT COUNT(*) FROM course_groups WHERE course_id = ?');
      $gc->execute([$courseId]);
      $groups = (int)$gc->fetchColumn();
      if ($groups > 0) {
        throw new QtaUserError('Kursi "' . $course['name'] . '" ka ' . $groups . ($groups === 1 ? ' grup' : ' grupe')
          . ' dhe nuk u fshi, që të mos humbasin datat e provimeve dhe pikët. Një kurs fshihet vetëm kur nuk ka asnjë grup.');
      }
      $modules = $pdo->prepare('SELECT id FROM course_modules WHERE course_id = ?');
      $modules->execute([$courseId]);
      $ids = array_map('intval', $modules->fetchAll(PDO::FETCH_COLUMN));
      $topics = 0;
      if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $dt = $pdo->prepare("DELETE FROM course_topics WHERE module_id IN ($ph)");
        $dt->execute($ids);
        $topics = $dt->rowCount();
        $pdo->prepare("DELETE FROM course_modules WHERE id IN ($ph)")->execute($ids);
      }
      $pdo->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);
      return ['course' => $course, 'modules' => count($ids), 'topics' => $topics];
    });
  }
}
