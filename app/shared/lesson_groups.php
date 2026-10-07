<?php
declare(strict_types=1);

/**
 * lesson_groups.php — Grupet me orar mësimi (modeli i ri).
 *
 * Një grup me orar ka:
 *   - kursin (i pandryshueshëm) dhe një kopje të ngrirë të moduleve dhe temave;
 *   - orarin e ruajtur: ditët e mësimit dhe ndarjen e orëve të temave;
 *   - një nga dy mënyrat e orarit (group_schedules.schedule_mode):
 *       calculated   datën e fillimit, orët në ditë dhe ditët e veçanta; data e
 *                    mbarimit nuk shkruhet nga askush: del nga orari;
 *       fixed_range  fillimi dhe mbarimi përcaktohen shprehimisht; plani i
 *                    ditëve (group_fixed_days) jep orët e çdo date. Kjo mënyrë
 *                    përdoret si nga grupet e reja, ashtu edhe nga ato të
 *                    konvertuara. Prejardhja e konvertimit ruhet veçmas.
 *

 * Çdo veprim që shkruan:
 *   1. bllokon orarin e grupit (dy persona nuk e ndryshojnë njëkohësisht);
 *   2. kontrollon versionin që pa përdoruesi (revision);
 *   3. rillogarit gjithë orarin me motorin e vetëm (schedule.php) dhe e kontrollon;
 *   4. ruan gjithçka në një transaksion — ose gjithçka, ose asgjë.
 *
 * Politika e historikut
 *   - Kopja e temave merret kur krijohet grupi; ndryshimet e kursit nuk e prekin.
 *   - "Merr temat e reja të kursit" lejohet vetëm para se të nisë grupi.
 *   - Një ndryshim që prek ditë që kanë kaluar, ose një grup të mbyllur,
 *     kërkon konfirmim të qartë (force). Çdo ndryshim shënohet në historik.
 */

require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/schedule_fixed.php';
require_once __DIR__ . '/curriculum.php';
require_once __DIR__ . '/group_members.php';
require_once __DIR__ . '/themeli.php';

/* ============================================================== Leximi */

if (!function_exists('qta_lg_find')) {
  /**
   * Grupi me kursin dhe konfigurimin e orarit. $lock bllokon orarin deri në fund
   * të transaksionit. Për një grup të konvertuar jep edhe kur dhe nga kush u konvertua.
   */
  function qta_lg_find(PDO $pdo, int $groupId, bool $lock = false): ?array
  {
    if ($lock) {
      $l = $pdo->prepare('SELECT group_id FROM group_schedules WHERE group_id = ? FOR UPDATE');
      $l->execute([$groupId]);
    }
    $st = $pdo->prepare('
      SELECT cg.id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed, cg.model, cg.created_at,
             c.code AS course_code, c.name AS course_name, c.hours AS live_course_hours,
             gs.schedule_mode, gs.daily_hours, gs.course_hours, gs.curriculum_taken_at, gs.teaching_days, gs.revision, gs.generated_at,
             gc.source_start_date, gc.source_end_date, gc.converted_at,
             COALESCE(NULLIF(cu.full_name, \'\'), cu.email) AS converted_by_name
      FROM course_groups cg
      JOIN courses c ON c.id = cg.course_id
      LEFT JOIN group_schedules gs ON gs.group_id = cg.id
      LEFT JOIN group_conversions gc ON gc.group_id = cg.id AND gc.status = \'completed\'
      LEFT JOIN users cu ON cu.id = gc.converted_by
      WHERE cg.id = ?
    ');
    $st->execute([$groupId]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) {
      return null;
    }
    foreach (['id', 'course_id', 'is_completed', 'live_course_hours'] as $k) $g[$k] = (int)$g[$k];
    foreach (['daily_hours', 'course_hours', 'teaching_days', 'revision'] as $k) $g[$k] = $g[$k] === null ? null : (int)$g[$k];
    return $g;
  }

  /** A e ka grupi orarin me periudhë të përcaktuar? */
  function qta_lg_is_fixed(array $g): bool
  {
    return ($g['schedule_mode'] ?? null) === 'fixed_range';
  }

  /** A ka ky grup një konvertim të përfunduar nga regjistri i vjetër? */
  function qta_lg_is_converted(array $g): bool
  {
    return !empty($g['converted_at']);
  }

  /** Grupi me orar, ose QtaUserError i qartë (grupi mungon ose është i mëparshëm). */
  function qta_lg_require(PDO $pdo, int $groupId, bool $lock = false): array
  {
    $g = qta_lg_find($pdo, $groupId, $lock);
    if (!$g) {
      throw new QtaUserError('Grupi nuk u gjet. Ndoshta u fshi — rifresko faqen.');
    }
    if ($g['model'] !== 'scheduled' || $g['schedule_mode'] === null) {
      throw new QtaUserError('Grupi #' . $groupId . ' është një grup i mëparshëm, pa orar mësimi. Ai menaxhohet te "Regjistri i vjetër i kurseve profesionale" dhe nuk merr orar.', ['code' => 'legacy_group']);
    }
    return $g;
  }

  /** Kopja e temave të grupit, në radhë, në formatin e motorit të orarit. */
  function qta_lg_topics(PDO $pdo, int $groupId): array
  {
    $st = $pdo->prepare('
      SELECT seq, module_seq, module_title, module_hours, topic_seq, topic_title, topic_hours, source_module_id, source_topic_id
      FROM group_schedule_topics WHERE group_id = ? ORDER BY seq ASC
    ');
    $st->execute([$groupId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $out[] = [
        'seq' => (int)$r['seq'], 'module_seq' => (int)$r['module_seq'], 'module_title' => (string)$r['module_title'],
        'module_hours' => (int)$r['module_hours'], 'topic_seq' => (int)$r['topic_seq'], 'topic_title' => (string)$r['topic_title'],
        'hours' => (int)$r['topic_hours'],
        'source_module_id' => $r['source_module_id'] === null ? null : (int)$r['source_module_id'],
        'source_topic_id' => $r['source_topic_id'] === null ? null : (int)$r['source_topic_id'],
      ];
    }
    return $out;
  }

  /** Ditët e veçanta: data → ['hours' => ?int, 'note' => ?string], të renditura. */
  function qta_lg_rules(PDO $pdo, int $groupId): array
  {
    $st = $pdo->prepare('SELECT rule_date, hours, note FROM group_day_rules WHERE group_id = ? ORDER BY rule_date ASC');
    $st->execute([$groupId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $out[(string)$r['rule_date']] = ['hours' => $r['hours'] === null ? null : (int)$r['hours'], 'note' => $r['note']];
    }
    return $out;
  }

  /** @return array<string,?int> */
  function qta_lg_rule_map(array $rules): array
  {
    $map = [];
    foreach ($rules as $date => $r) $map[$date] = $r['hours'];
    return $map;
  }

  /** Orari i ruajtur: ditët me pjesët e tyre (dy query). */
  function qta_lg_days(PDO $pdo, int $groupId): array
  {
    $ds = $pdo->prepare('SELECT day_seq, lesson_date, hours FROM group_schedule_days WHERE group_id = ? ORDER BY day_seq ASC');
    $ds->execute([$groupId]);
    $days = [];
    foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $d) {
      $days[(int)$d['day_seq']] = ['seq' => (int)$d['day_seq'], 'date' => (string)$d['lesson_date'], 'hours' => (int)$d['hours'], 'slots' => []];
    }
    $ss = $pdo->prepare('SELECT day_seq, slot_seq, topic_seq, hours FROM group_schedule_slots WHERE group_id = ? ORDER BY day_seq ASC, slot_seq ASC');
    $ss->execute([$groupId]);
    foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $s) {
      $k = (int)$s['day_seq'];
      if (isset($days[$k])) {
        $days[$k]['slots'][] = ['seq' => (int)$s['slot_seq'], 'topic' => (int)$s['topic_seq'], 'hours' => (int)$s['hours']];
      }
    }
    return array_values($days);
  }

  /**
   * Plani i ditëve të një grupi me periudhë të përcaktuar: çdo datë e periudhës,
   * në radhë, me orët dhe shënimin e saj.
   * @return array<string,array{hours:int,note:?string}>
   */
  function qta_lg_fixed_days(PDO $pdo, int $groupId): array
  {
    $st = $pdo->prepare('SELECT lesson_date, hours, note FROM group_fixed_days WHERE group_id = ? ORDER BY lesson_date ASC');
    $st->execute([$groupId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $out[(string)$r['lesson_date']] = ['hours' => (int)$r['hours'], 'note' => $r['note'] === null || $r['note'] === '' ? null : (string)$r['note']];
    }
    return $out;
  }

  /** @return array<string,int> */
  function qta_lg_fixed_hours(array $fixedDays): array
  {
    return array_map(static fn($d) => (int)$d['hours'], $fixedDays);
  }

  /** A ka ndryshuar kursi (modulet, temat, orët ose radha) pas kopjes së grupit? */
  function qta_lg_curriculum_changed(array $snapshotTopics, array $liveModules): bool
  {
    $norm = static fn(array $topics): array => array_map(static fn($t) => [
      (int)$t['module_seq'], (string)$t['module_title'], (int)$t['module_hours'],
      (int)$t['topic_seq'], (string)$t['topic_title'], (int)$t['hours'],
    ], array_values($topics));
    return $norm($snapshotTopics) !== $norm(qta_course_schedule_topics($liveModules));
  }

  /** Përmbledhje e shkurtër e një orari për mesazhe dhe parashikim. */
  function qta_lg_plan_summary(array $plan): array
  {
    $last = $plan['days'][count($plan['days']) - 1];
    return [
      'start_date' => $plan['start_date'],
      'end_date' => $plan['end_date'],
      'start_label' => qta_sched_day_label($plan['start_date']),
      'end_label' => qta_sched_day_label($plan['end_date']),
      'start_on' => qta_sched_on_label($plan['start_date']),
      'end_on' => qta_sched_on_label($plan['end_date']),
      'days' => count($plan['days']),
      'total_hours' => (int)$plan['total_hours'],
      'last_day_hours' => (int)$last['hours'],
    ];
  }
}

/* ================================================================ Shkrimi */

if (!function_exists('qta_lg_write_topics')) {
  function qta_lg_write_topics(PDO $pdo, int $groupId, array $topics): void
  {
    $pdo->prepare('DELETE FROM group_schedule_slots WHERE group_id = ?')->execute([$groupId]);
    $pdo->prepare('DELETE FROM group_schedule_days WHERE group_id = ?')->execute([$groupId]);
    $pdo->prepare('DELETE FROM group_schedule_topics WHERE group_id = ?')->execute([$groupId]);
    foreach (array_chunk($topics, 300) as $chunk) {
      $ph = [];
      $vals = [];
      foreach ($chunk as $t) {
        $ph[] = '(?,?,?,?,?,?,?,?,?,?)';
        array_push($vals, $groupId, $t['seq'], $t['module_seq'], $t['module_title'], $t['module_hours'],
          $t['topic_seq'], $t['topic_title'], $t['hours'], $t['source_module_id'] ?? null, $t['source_topic_id'] ?? null);
      }
      $pdo->prepare('INSERT INTO group_schedule_topics (group_id, seq, module_seq, module_title, module_hours, topic_seq, topic_title, topic_hours, source_module_id, source_topic_id) VALUES ' . implode(',', $ph))
          ->execute($vals);
    }
  }

  /** Zëvendëson ditët dhe pjesët e orarit me planin e ri (pa gjurmë të së vjetrës). */
  function qta_lg_write_plan(PDO $pdo, int $groupId, array $plan): void
  {
    $pdo->prepare('DELETE FROM group_schedule_slots WHERE group_id = ?')->execute([$groupId]);
    $pdo->prepare('DELETE FROM group_schedule_days WHERE group_id = ?')->execute([$groupId]);
    foreach (array_chunk($plan['days'], 400) as $chunk) {
      $ph = [];
      $vals = [];
      foreach ($chunk as $d) {
        $ph[] = '(?,?,?,?)';
        array_push($vals, $groupId, $d['seq'], $d['date'], $d['hours']);
      }
      $pdo->prepare('INSERT INTO group_schedule_days (group_id, day_seq, lesson_date, hours) VALUES ' . implode(',', $ph))->execute($vals);
    }
    $rows = [];
    foreach ($plan['days'] as $d) {
      foreach ($d['slots'] as $s) {
        $rows[] = [$groupId, $d['seq'], $s['seq'], $s['topic'], $s['hours']];
      }
    }
    foreach (array_chunk($rows, 400) as $chunk) {
      $ph = [];
      $vals = [];
      foreach ($chunk as $r) {
        $ph[] = '(?,?,?,?,?)';
        array_push($vals, ...$r);
      }
      $pdo->prepare('INSERT INTO group_schedule_slots (group_id, day_seq, slot_seq, topic_seq, hours) VALUES ' . implode(',', $ph))->execute($vals);
    }
  }

  /** Shkruan planin e plotë të ditëve të një grupi me periudhë të përcaktuar. */
  function qta_lg_write_fixed_days(PDO $pdo, int $groupId, array $dayHours, array $notes = []): void
  {
    foreach (array_chunk($dayHours, 400, true) as $chunk) {
      $ph = [];
      $vals = [];
      foreach ($chunk as $date => $hours) {
        $ph[] = '(?,?,?,?)';
        $note = isset($notes[$date]) && $notes[$date] !== '' ? (string)$notes[$date] : null;
        array_push($vals, $groupId, (string)$date, (int)$hours, $note);
      }
      $pdo->prepare('INSERT INTO group_fixed_days (group_id, lesson_date, hours, note) VALUES ' . implode(',', $ph))->execute($vals);
    }
  }

  /**
   * Kontrolli i fundit i një grupi me periudhë të përcaktuar: lexon sërish orarin, planin
   * e ditëve dhe datat e grupit, dhe i verifikon me kontrollin e pavarur.
   */
  function qta_lg_assert_stored_fixed(PDO $pdo, int $groupId, array $topics, string $start, string $end): void
  {
    $stored = qta_lg_days($pdo, $groupId);
    $dayHours = qta_lg_fixed_hours(qta_lg_fixed_days($pdo, $groupId));
    $violations = qta_sched_verify_fixed_range($topics, [
      'start_date' => $stored[0]['date'] ?? null,
      'end_date' => $stored ? $stored[count($stored) - 1]['date'] : null,
      'days' => $stored,
    ], $start, $end, $dayHours);
    $cg = $pdo->prepare('SELECT cg.start_date, cg.end_date, cg.model, gs.schedule_mode, gs.daily_hours, gs.course_hours, gs.teaching_days
                         FROM course_groups cg JOIN group_schedules gs ON gs.group_id = cg.id WHERE cg.id = ?');
    $cg->execute([$groupId]);
    $row = $cg->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['start_date'] !== $start || $row['end_date'] !== $end) {
      $violations[] = 'datat e periudhës së grupit nuk përputhen';
    }
    if (!$row || $row['model'] !== 'scheduled' || $row['schedule_mode'] !== 'fixed_range' || $row['daily_hours'] !== null) {
      $violations[] = 'lloji i grupit ose i orarit nuk është i saktë';
    }
    $total = array_sum(array_map(static fn($t) => (int)$t['hours'], $topics));
    if (!$row || (int)$row['course_hours'] !== $total || (int)$row['teaching_days'] !== count($stored)) {
      $violations[] = 'koka e orarit nuk përputhet me ditët';
    }
    if ($violations) {
      error_log('[QTA schedule] grupi ' . $groupId . ' (periudhë e përcaktuar): ' . implode('; ', $violations));
      throw new QtaUserError('Orari nuk u ruajt, sepse kontrolli i fundit gjeti një mospërputhje. Asgjë nuk ndryshoi. Provo sërish; nëse përsëritet, njofto administratorin.');
    }
  }

  /** Kontrolli i pavarur para ruajtjes së një orari me periudhë të përcaktuar. */
  function qta_lg_verify_fixed_or_fail(array $topics, array $plan, string $start, string $end, array $dayHours): void
  {
    $violations = qta_sched_verify_fixed_range($topics, $plan, $start, $end, $dayHours);
    if ($violations) {
      error_log('[QTA schedule] verifikimi i orarit me periudhë të përcaktuar dështoi: ' . implode('; ', $violations));
      throw new QtaUserError('Orari nuk u ruajt, sepse kontrolli i brendshëm gjeti një mospërputhje. Asgjë nuk ndryshoi. Njofto administratorin.');
    }
  }

  /** Kontrolli i fundit para ruajtjes: lexon sërish orarin e shkruar dhe e verifikon. */
  function qta_lg_assert_stored(PDO $pdo, int $groupId, array $topics, int $daily, array $ruleMap, string $start): void
  {
    $stored = qta_lg_days($pdo, $groupId);
    $end = $stored ? $stored[count($stored) - 1]['date'] : $start;
    $violations = qta_sched_verify($topics, ['start_date' => $stored[0]['date'] ?? null, 'end_date' => $end, 'days' => $stored], $daily, $ruleMap, $start);
    $cg = $pdo->prepare('SELECT start_date, end_date FROM course_groups WHERE id = ?');
    $cg->execute([$groupId]);
    $dates = $cg->fetch(PDO::FETCH_ASSOC);
    if (!$dates || $dates['start_date'] !== $start || $dates['end_date'] !== $end) {
      $violations[] = 'datat e grupit nuk përputhen me orarin';
    }
    if ($violations) {
      error_log('[QTA schedule] grupi ' . $groupId . ': ' . implode('; ', $violations));
      throw new QtaUserError('Orari nuk u ruajt, sepse kontrolli i fundit gjeti një mospërputhje. Asgjë nuk ndryshoi. Provo sërish; nëse përsëritet, njofto administratorin.');
    }
  }

  function qta_lg_verify_or_fail(array $topics, array $plan, int $daily, array $ruleMap): void
  {
    $violations = qta_sched_verify($topics, $plan, $daily, $ruleMap);
    if ($violations) {
      error_log('[QTA schedule] verifikimi dështoi: ' . implode('; ', $violations));
      throw new QtaUserError('Orari nuk u ruajt, sepse kontrolli i brendshëm gjeti një mospërputhje. Asgjë nuk ndryshoi. Njofto administratorin.');
    }
  }
}

/* ============================================================== Krijimi */

if (!function_exists('qta_lg_preview_new')) {
  /**
   * Parashikimi i orarit për një grup të ri (pa ruajtur asgjë).
   * @return array{course:array,plan:array,summary:array}
   */
  function qta_lg_preview_new(PDO $pdo, $courseId, $start, $daily, $mode = 'calculated', $end = null): array
  {
    $in = qta_lg_parse_new($courseId, $start, $daily, $mode, $end);
    $course = qta_course_find($pdo, $in['course_id']);
    if (!$course) throw new QtaUserError('Kursi nuk u gjet. Rifresko faqen.');
    $modules = qta_course_modules($pdo, $course['id']);
    qta_lg_assert_course_ready($course, $modules);
    $topics = qta_course_schedule_topics($modules);
    if ($in['schedule_mode'] === 'fixed_range') {
      $fixed = qta_lg_build_fixed_proposal($topics, $in['start_date'], $in['end_date']);
      return ['course' => $course, 'plan' => $fixed['plan'], 'summary' => qta_lg_plan_summary($fixed['plan']),
              'fixed_summary' => $fixed['summary'], 'schedule_mode' => 'fixed_range'];
    }
    $plan = qta_sched_build($topics, $in['start_date'], $in['daily_hours']);
    qta_lg_verify_or_fail($topics, $plan, $in['daily_hours'], []);
    return ['course' => $course, 'plan' => $plan, 'summary' => qta_lg_plan_summary($plan), 'schedule_mode' => 'calculated'];
  }

  function qta_lg_parse_new($courseId, $start, $daily, $mode = 'calculated', $end = null): array
  {
    $cid = qta_parse_int_input($courseId, 1, PHP_INT_MAX);
    if ($cid === null) throw new QtaUserError('Zgjidh kursin e grupit.');
    $startIso = qta_parse_date_input($start);
    if ($startIso === null) throw new QtaUserError('Shkruaj datën e fillimit si dd.mm.vvvv, p.sh. 01.10.2026.');
    $mode = (string)$mode;
    if (!in_array($mode, ['calculated', 'fixed_range'], true)) {
      throw new QtaUserError('Zgjidh si përcaktohet mbarimi i grupit.');
    }
    if ($mode === 'fixed_range') {
      $endIso = qta_parse_date_input($end);
      if ($endIso === null) throw new QtaUserError('Shkruaj datën e mbarimit si dd.mm.vvvv, p.sh. 31.10.2026.');
      if ($endIso < $startIso) throw new QtaUserError('Data e mbarimit nuk mund të jetë para datës së fillimit.');
      return ['course_id' => $cid, 'schedule_mode' => $mode, 'start_date' => $startIso,
              'end_date' => $endIso, 'daily_hours' => null];
    }
    $h = qta_parse_int_input($daily, 1, QTA_DAY_MAX_HOURS);
    if ($h === null) throw new QtaUserError('Shkruaj sa orë mësim ka në ditë: një numër i plotë nga 1 deri në ' . QTA_DAY_MAX_HOURS . ', p.sh. 5.');
    return ['course_id' => $cid, 'schedule_mode' => $mode, 'start_date' => $startIso,
            'end_date' => null, 'daily_hours' => $h];
  }

  function qta_lg_assert_course_ready(array $course, array $modules): array
  {
    $check = qta_course_check($course, $modules);
    if (!$check['ready']) {
      throw new QtaUserError('Kursi "' . $course['name'] . '" nuk është ende gati për grupe me orar. ' . $check['issues'][0]['text']
        . ($check['issues'] && count($check['issues']) > 1 ? ' (dhe ' . (count($check['issues']) - 1) . ' të tjera)' : '')
        . ' Plotësoje te "Katalogu i kurseve".', ['code' => 'course_not_ready', 'course_id' => $course['id']]);
    }
    return $check;
  }

  /** Ndërton dhe verifikon propozimin automatik për një periudhë të përcaktuar. */
  function qta_lg_build_fixed_proposal(array $topics, string $start, string $end): array
  {
    $hours = qta_sched_validate_topics(array_values($topics));
    $proposal = qta_sched_propose_fixed_range($hours, $start, $end);
    $plan = qta_sched_build_fixed_range($topics, $start, $end, $proposal['days']);
    qta_lg_verify_fixed_or_fail($topics, $plan, $start, $end, $proposal['days']);
    return ['plan' => $plan, 'days' => $proposal['days'],
            'summary' => qta_sched_fixed_summary($proposal['days'], $hours, $start, $end)];
  }

  /**
   * Krijon një ose disa grupe me orar (mbi 10 kursantë → grupe të barabarta,
   * secili me orarin e vet të njëjtë). Gjithçka në një transaksion.
   * @param array{course_id:mixed,start_date:mixed,daily_hours?:mixed,end_date?:mixed,schedule_mode?:mixed,amze_spec?:mixed} $in
   */
  function qta_lg_create(PDO $pdo, array $in): array
  {
    $p = qta_lg_parse_new($in['course_id'] ?? null, $in['start_date'] ?? null, $in['daily_hours'] ?? null,
                          $in['schedule_mode'] ?? 'calculated', $in['end_date'] ?? null);
    $spec = trim((string)($in['amze_spec'] ?? ''));

    return qta_tx($pdo, function () use ($pdo, $p, $spec): array {
      $course = qta_curriculum_lock_course($pdo, $p['course_id']);
      $modules = qta_course_modules($pdo, $course['id']);
      qta_lg_assert_course_ready($course, $modules);
      $topics = qta_course_schedule_topics($modules);
      $fixedDays = null;
      if ($p['schedule_mode'] === 'fixed_range') {
        $fixed = qta_lg_build_fixed_proposal($topics, $p['start_date'], $p['end_date']);
        $plan = $fixed['plan'];
        $fixedDays = $fixed['days'];
      } else {
        $plan = qta_sched_build($topics, $p['start_date'], $p['daily_hours']);
        qta_lg_verify_or_fail($topics, $plan, $p['daily_hours'], []);
      }

      $chunks = [['ids' => [], 'amze_min' => null, 'amze_max' => null]];
      if ($spec !== '') {
        $nums = qta_amze_parse($spec);
        if (!$nums) throw new QtaUserError('Nuk gjeta asnjë numër amze. Shkruaji si 3400-3403, 3409, ose lëre fushën bosh.');
        $map = [];
        foreach ($nums as $n) $map[$n] = qta_amze_ensure_student($pdo, $n);
        qta_members_assert_can_join($pdo, array_values($map), $course['id']);
        $ids = array_values($map);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM student_course_plans WHERE status = 'planned' AND student_id IN ($ph)")->execute($ids);
        $chunks = qta_members_split($map);
      }

      $created = [];
      $insGroup = $pdo->prepare("INSERT INTO course_groups (course_id, start_date, end_date, is_completed, model) VALUES (?, ?, ?, 0, 'scheduled')");
      $insSchedule = $pdo->prepare("INSERT INTO group_schedules (group_id, schedule_mode, daily_hours, course_hours, curriculum_taken_at, teaching_days, revision, generated_at) VALUES (?, ?, ?, ?, NOW(), ?, 1, NOW())");
      $insMember = $pdo->prepare('INSERT INTO course_group_students (group_id, student_id) VALUES (?, ?)');
      foreach ($chunks as $chunk) {
        $insGroup->execute([$course['id'], $plan['start_date'], $plan['end_date']]);
        $gid = (int)$pdo->lastInsertId();
        $insSchedule->execute([$gid, $p['schedule_mode'], $p['daily_hours'], (int)$plan['total_hours'], count($plan['days'])]);
        qta_lg_write_topics($pdo, $gid, $topics);
        if ($fixedDays !== null) qta_lg_write_fixed_days($pdo, $gid, $fixedDays);
        qta_lg_write_plan($pdo, $gid, $plan);
        foreach ($chunk['ids'] as $sid) $insMember->execute([$gid, $sid]);
        if ($fixedDays !== null) qta_lg_assert_stored_fixed($pdo, $gid, $topics, $p['start_date'], $p['end_date']);
        else qta_lg_assert_stored($pdo, $gid, $topics, $p['daily_hours'], [], $p['start_date']);
        $created[] = ['group_id' => $gid, 'count' => count($chunk['ids']), 'amze_min' => $chunk['amze_min'], 'amze_max' => $chunk['amze_max']];
      }
      return ['groups' => $created, 'summary' => qta_lg_plan_summary($plan), 'course' => $course];
    });
  }
}

/* ============================================ Ndryshimet e orarit (një rrugë) */

if (!function_exists('qta_lg_change')) {
  /**
   * Ndryshon orarin e një grupi dhe e rillogarit të tërin.
   *
   * $change për orarin e llogaritur:
   *   ['type' => 'settings', 'start_date' => …, 'daily_hours' => …]
   *   ['type' => 'rule', 'date' => …, 'mode' => 'default'|'hours'|'off'|'remove', 'hours' => …, 'note' => …]
   *   ['type' => 'refresh']   — merr temat e reja të kursit (vetëm para fillimit)
   * $change për orarin me periudhë të përcaktuar:
   *   ['type' => 'fixed_range_settings', 'start_date' => …, 'end_date' => …]
   *   ['type' => 'fixed_days', 'days' => ['2026-10-03' => 0, …], 'notes' => ['2026-10-03' => 'Festë', …]]
   *   — ndryshon periudhën ose korrigjon orët e disa datave brenda saj.
   * $opts: revision (?int), force (bool), dry_run (bool), today ('Y-m-d')
   *
   * Me dry_run nuk ruhet asgjë: kthehet ndikimi (data e re e mbarimit, ditët që
   * ndryshojnë, a duhet konfirmim).
   */
  function qta_lg_change(PDO $pdo, int $groupId, array $change, array $opts = []): array
  {
    $dry = !empty($opts['dry_run']);
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
      $result = qta_lg_change_in_tx($pdo, $groupId, $change, $opts);
      if ($own) {
        if ($dry) $pdo->rollBack(); else $pdo->commit();
      }
      return $result;
    } catch (Throwable $e) {
      if ($own && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }

  function qta_lg_change_in_tx(PDO $pdo, int $groupId, array $change, array $opts): array
  {
    $today = (string)($opts['today'] ?? date('Y-m-d'));
    $force = !empty($opts['force']);
    $dry = !empty($opts['dry_run']);

    $g = qta_lg_require($pdo, $groupId, true);
    if (isset($opts['revision']) && $opts['revision'] !== null && $opts['revision'] !== '' && (int)$opts['revision'] !== (int)$g['revision']) {
      throw new QtaUserError('Orari i këtij grupi u ndryshua nga dikush tjetër ndërkohë. Rifresko faqen që të shohësh orarin e ri, pastaj provo sërish.', ['code' => 'stale']);
    }
    if (qta_lg_is_fixed($g)) {
      return qta_lg_change_fixed_in_tx($pdo, $g, $change, $opts);
    }

    $topics = qta_lg_topics($pdo, $groupId);
    $rules = qta_lg_rules($pdo, $groupId);
    $oldDays = qta_lg_days($pdo, $groupId);
    $start = (string)$g['start_date'];
    $daily = (int)$g['daily_hours'];
    $newTopics = $topics;
    $ruleOp = null;          // ['upsert', date, hours, note] | ['delete', date]
    $what = '';

    $type = (string)($change['type'] ?? '');
    if ($type === 'settings') {
      $s = qta_parse_date_input($change['start_date'] ?? '');
      if ($s === null) throw new QtaUserError('Shkruaj datën e fillimit si dd.mm.vvvv, p.sh. 01.10.2026.');
      $h = qta_parse_int_input($change['daily_hours'] ?? '', 1, QTA_DAY_MAX_HOURS);
      if ($h === null) throw new QtaUserError('Orët në ditë duhet të jenë një numër i plotë nga 1 deri në ' . QTA_DAY_MAX_HOURS . '.');
      if ($s === $start && $h === $daily) throw new QtaUserError('Nuk ndryshove asgjë: data e fillimit dhe orët në ditë janë të njëjta.');
      $start = $s;
      $daily = $h;
      $what = 'settings';
    } elseif ($type === 'rule') {
      $date = qta_parse_date_input($change['date'] ?? '');
      if ($date === null) throw new QtaUserError('Shkruaj datën si dd.mm.vvvv, p.sh. 11.10.2026.');
      if ($date < $start) {
        throw new QtaUserError('Data ' . qta_sched_day_label($date) . ' është para fillimit të grupit (' . qta_sched_day_label($start) . '). Zgjidh një datë nga fillimi e tutje.');
      }
      $mode = (string)($change['mode'] ?? '');
      $note = qta_clean_text($change['note'] ?? '', 160);
      $sunday = qta_sched_is_sunday($date);
      $hours = null;
      if ($mode === 'hours') {
        $hours = qta_parse_int_input($change['hours'] ?? '', 0, QTA_DAY_MAX_HOURS);
        if ($hours === null) throw new QtaUserError('Orët e ditës duhet të jenë një numër i plotë nga 0 deri në ' . QTA_DAY_MAX_HOURS . '. 0 do të thotë pa mësim.');
        if ($hours === 0) $mode = 'off'; /* 0 orë = pa mësim */
      }
      if ($mode === 'hours') {
        $ruleOp = ['upsert', $date, $hours, $note];
      } elseif ($mode === 'off') {
        /* E diela është pa mësim vetvetiu: pa shënim, rregulli nuk nevojitet. */
        $ruleOp = ($sunday && $note === '') ? ['delete', $date] : ['upsert', $date, 0, $note];
      } elseif ($mode === 'default') {
        $ruleOp = $sunday ? ['upsert', $date, null, $note] : ['delete', $date];
      } elseif ($mode === 'remove') {
        $ruleOp = ['delete', $date];
      } else {
        throw new QtaUserError('Zgjidh si do të jetë kjo ditë: orari i zakonshëm, orë të tjera ose pa mësim.');
      }
      if ($ruleOp[0] === 'delete' && !array_key_exists($date, $rules)) {
        throw new QtaUserError('Dita ' . qta_sched_day_label($date) . ' ka tashmë orarin e zakonshëm' . ($sunday ? ' (e diel, pa mësim)' : '') . '.');
      }
      if ($ruleOp[0] === 'upsert' && array_key_exists($date, $rules)
          && $rules[$date]['hours'] === $ruleOp[2] && (string)($rules[$date]['note'] ?? '') === $ruleOp[3]) {
        throw new QtaUserError('Kjo ditë e ka tashmë këtë orar.');
      }
      if ($ruleOp[0] === 'delete') unset($rules[$date]);
      else $rules[$date] = ['hours' => $ruleOp[2], 'note' => $ruleOp[3] === '' ? null : $ruleOp[3]];
      $what = 'rule';
    } elseif ($type === 'refresh') {
      if ((int)$g['is_completed'] === 1) {
        throw new QtaUserError('Grupi është i mbyllur. Temat e një grupi të mbyllur nuk ndryshohen.');
      }
      if ($start <= $today) {
        throw new QtaUserError('Grupi ka nisur ' . qta_sched_on_label($start) . '. Temat e tij nuk ndryshohen më, që të mos ndryshojë mësimi i zhvilluar. Ndryshimet e kursit vlejnë për grupet e reja.');
      }
      $course = qta_curriculum_lock_course($pdo, (int)$g['course_id']);
      $modules = qta_course_modules($pdo, $course['id']);
      qta_lg_assert_course_ready($course, $modules);
      $newTopics = qta_course_schedule_topics($modules);
      if (!qta_lg_curriculum_changed($topics, $modules)) {
        throw new QtaUserError('Temat e grupit janë njësoj si te kursi. Nuk ka asgjë për të marrë.');
      }
      /* Pikët e moduleve i përkasin kopjes aktuale: një kopje e re do t'i linte pa modul. */
      if (qta_results_scored_students($pdo, $groupId)) {
        throw new QtaUserError('Grupi ka pikë sipas moduleve të kopjes së tij, prandaj temat e reja të kursit nuk merren. Ndryshimet e kursit vlejnë për grupet e reja.', ['code' => 'has_scores']);
      }
      $what = 'refresh';
    } elseif ($type === 'fixed_days') {
      throw new QtaUserError('Ky grup e llogarit orarin nga data e fillimit dhe orët në ditë. Ndrysho një ditë me "Ndrysho ditën".');
    } else {
      throw new QtaUserError('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
    }

    $ruleMap = qta_lg_rule_map($rules);
    $plan = qta_sched_build($newTopics, $start, $daily, $ruleMap);
    qta_lg_verify_or_fail($newTopics, $plan, $daily, $ruleMap);

    /* Ndikimi */
    $changed = $what === 'refresh' ? array_column($plan['days'], 'date') : qta_sched_diff($oldDays, $plan['days']);
    $past = array_values(array_filter($changed, static fn($d) => $d < $today));
    $oldEnd = (string)$g['end_date'];
    $summary = qta_lg_plan_summary($plan);

    $exam = $pdo->prepare('SELECT COUNT(*) AS n, MIN(exam_date) AS first_exam FROM course_group_students WHERE group_id = ? AND exam_date IS NOT NULL AND exam_date < ?');
    $exam->execute([$groupId, $plan['end_date']]);
    $conflict = $exam->fetch(PDO::FETCH_ASSOC);
    if ((int)$conflict['n'] > 0) {
      throw new QtaUserError('Me këtë ndryshim orari do të mbaronte ' . qta_sched_on_label($plan['end_date']) . ', pas datës së provimit të '
        . qta_plural_word((int)$conflict['n'], 'kursanti', 'kursantëve') . ' (' . qta_date((string)$conflict['first_exam']) . '). '
        . 'Provimi nuk mund të jetë para mbarimit. Ndrysho së pari datat e provimit te "Kursantët".', ['code' => 'exam_conflict']);
    }

    $confirm = qta_lg_change_confirm((int)$g['is_completed'] === 1, $past);

    $result = [
      'what' => $what,
      'old' => ['start_date' => (string)$g['start_date'], 'end_date' => $oldEnd, 'days' => count($oldDays), 'daily_hours' => (int)$g['daily_hours']],
      'new' => $summary + ['daily_hours' => $daily],
      'changed_dates' => $changed,
      'first_changed' => $changed[0] ?? null,
      'past_changed' => $past,
      'confirm' => $confirm,
      'revision' => (int)$g['revision'],
    ];
    if ($dry) {
      return $result;
    }
    if ($confirm && !$force) {
      throw new QtaConfirmNeeded($confirm['title'], $confirm['message'], $confirm['confirm'], ['impact' => $result]);
    }

    /* Ruajtja */
    if ($ruleOp !== null) {
      if ($ruleOp[0] === 'delete') {
        $pdo->prepare('DELETE FROM group_day_rules WHERE group_id = ? AND rule_date = ?')->execute([$groupId, $ruleOp[1]]);
      } else {
        $pdo->prepare('INSERT INTO group_day_rules (group_id, rule_date, hours, note) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE hours = VALUES(hours), note = VALUES(note)')
            ->execute([$groupId, $ruleOp[1], $ruleOp[2], $ruleOp[3] === '' ? null : $ruleOp[3]]);
      }
    }
    if ($what === 'refresh') {
      qta_lg_write_topics($pdo, $groupId, $newTopics);
    }
    qta_lg_write_plan($pdo, $groupId, $plan);
    if ($plan['start_date'] !== (string)$g['start_date'] || $plan['end_date'] !== $oldEnd) {
      $pdo->prepare('UPDATE course_groups SET start_date = ?, end_date = ? WHERE id = ?')->execute([$plan['start_date'], $plan['end_date'], $groupId]);
    }
    $sets = 'daily_hours = ?, teaching_days = ?, revision = revision + 1, generated_at = NOW()';
    $vals = [$daily, count($plan['days'])];
    if ($what === 'refresh') {
      $sets .= ', course_hours = ?, curriculum_taken_at = NOW()';
      $vals[] = (int)$plan['total_hours'];
    }
    $vals[] = $groupId;
    $pdo->prepare('UPDATE group_schedules SET ' . $sets . ' WHERE group_id = ?')->execute($vals);
    qta_lg_assert_stored($pdo, $groupId, $newTopics, $daily, $ruleMap, $plan['start_date']);

    $result['revision'] = (int)$g['revision'] + 1;
    return $result;
  }

  function qta_plural_word(int $n, string $one, string $many): string
  {
    return $n . ' ' . ($n === 1 ? $one : $many);
  }

  /**
   * Konfirmimi që kërkon një ndryshim orari: grup i mbyllur dhe/ose ditë që kanë
   * kaluar. I njëjtë për të dy mënyrat e orarit. null = pa pyetje.
   * @param string[] $past datat e kaluara që ndryshojnë
   */
  function qta_lg_change_confirm(bool $closed, array $past): ?array
  {
    $reasons = [];
    if ($closed) {
      $reasons[] = 'Grupi është i mbyllur dhe dokumentet e tij mund të jenë lëshuar tashmë.';
    }
    if ($past) {
      $reasons[] = 'Ky ndryshim prek ' . qta_plural_word(count($past), 'ditë mësimi që ka kaluar', 'ditë mësimi që kanë kaluar')
        . ' (' . (count($past) === 1 ? qta_sched_day_label($past[0]) : 'nga ' . qta_date($past[0]) . ' deri ' . qta_date($past[count($past) - 1])) . '). '
        . 'Ato ditë janë zhvilluar sipas orarit të vjetër — vazhdo vetëm nëse po korrigjon një gabim.';
    }
    if ($closed || $past) {
      $reasons[] = 'Ky ndryshim prek orarin e kaluar dhe mund të ndryshojë dokumentet e gjeneruara më parë.';
    }
    if (!$reasons) {
      return null;
    }
    return [
      'title' => $past ? 'Ndryshon edhe ditë që kanë kaluar' : 'Ky grup është i mbyllur',
      'message' => implode(' ', $reasons),
      'confirm' => $past ? 'Po, ndrysho edhe ditët e kaluara' : 'Po, ndrysho orarin',
    ];
  }

  /**
   * Ndryshimi i një orari 'fixed_range'. Periudha mund të zgjerohet ose të
   * ngushtohet; vendimet e ditëve që mbeten brenda saj ruhen sa herë që është e
   * mundur dhe motori rishpërndan vetëm diferencën. Çdo plan rindërtohet dhe
   * verifikohet i plotë para shkrimit atomik.
   */
  function qta_lg_change_fixed_in_tx(PDO $pdo, array $g, array $change, array $opts): array
  {
    $today = (string)($opts['today'] ?? date('Y-m-d'));
    $force = !empty($opts['force']);
    $dry = !empty($opts['dry_run']);
    $groupId = (int)$g['id'];
    $oldStart = (string)$g['start_date'];
    $oldEnd = (string)$g['end_date'];
    $start = $oldStart;
    $end = $oldEnd;
    $range = qta_sched_range_label($oldStart, $oldEnd);

    $type = (string)($change['type'] ?? '');
    if ($type === 'rule') {
      throw new QtaUserError('Ky grup ka plan të plotë ditësh, prandaj nuk ka "ditë të veçanta". Ndrysho orët e datës te plani i ditëve.');
    }
    if ($type === 'refresh') {
      throw new QtaUserError(qta_lg_is_converted($g)
        ? 'Grupi u konvertua nga regjistri i vjetër: modulet dhe temat e tij mbeten ato që u ruajtën në konvertim.'
        : 'Ky grup ka një kopje të ruajtur të moduleve dhe temave; ndryshimet e kursit vlejnë për grupet e reja.');
    }
    if (!in_array($type, ['settings', 'fixed_range_settings', 'fixed_days'], true)) {
      throw new QtaUserError('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
    }

    $current = qta_lg_fixed_days($pdo, $groupId);
    $dayHours = qta_lg_fixed_hours($current);
    $notes = array_map(static fn($d) => (string)($d['note'] ?? ''), $current);
    $topics = qta_lg_topics($pdo, $groupId);
    $oldDays = qta_lg_days($pdo, $groupId);
    $courseHours = array_sum(array_map(static fn($t) => (int)$t['hours'], $topics));
    $rangeChanged = false;

    if ($type === 'settings' || $type === 'fixed_range_settings') {
      $start = qta_parse_date_input($change['start_date'] ?? '') ?? '';
      $end = qta_parse_date_input($change['end_date'] ?? '') ?? '';
      if ($start === '') throw new QtaUserError('Shkruaj datën e fillimit si dd.mm.vvvv, p.sh. 01.10.2026.');
      if ($end === '') throw new QtaUserError('Shkruaj datën e mbarimit si dd.mm.vvvv, p.sh. 31.10.2026.');
      if ($end < $start) throw new QtaUserError('Data e mbarimit nuk mund të jetë para datës së fillimit.');
      if ($start === $oldStart && $end === $oldEnd) {
        throw new QtaUserError('Nuk ndryshove asgjë: fillimi dhe mbarimi janë të njëjtat.');
      }
      $feasible = qta_sched_fixed_feasibility($courseHours, $start, $end);
      if (!$feasible['ok']) throw new QtaUserError((string)$feasible['reason'], ['code' => $feasible['code']]);

      $newHours = array_fill_keys(qta_sched_dates($start, $end), 0);
      $newNotes = array_fill_keys(array_keys($newHours), '');
      $preserved = [];
      foreach ($newHours as $date => $_) {
        if (!array_key_exists($date, $dayHours)) continue;
        $newHours[$date] = $dayHours[$date];
        $newNotes[$date] = $notes[$date];
        $preserved[] = $date;
      }
      $balanced = qta_sched_rebalance_fixed_range($newHours, $courseHours, $start, $end, $preserved);
      $newHours = $balanced['days'];
      $rangeChanged = true;
    } else {
      $newHours = $dayHours;
      $newNotes = $notes;
      $outside = static fn(?string $iso): QtaUserError => new QtaUserError(
        ($iso !== null ? 'Data ' . qta_sched_dmy($iso) . ' është' : 'Një datë e dërguar është') . ' jashtë periudhës ' . $range . '.');
      foreach ((array)($change['days'] ?? []) as $date => $hours) {
        $iso = qta_parse_date_input((string)$date);
        if ($iso === null || !array_key_exists($iso, $current)) throw $outside($iso);
        $h = qta_parse_int_input($hours, 0, QTA_DAY_MAX_HOURS);
        if ($h === null) {
          throw new QtaUserError('Orët e datës ' . qta_sched_dmy($iso) . ' duhet të jenë një numër i plotë nga 0 deri në ' . QTA_DAY_MAX_HOURS . '. 0 do të thotë pa mësim.');
        }
        $newHours[$iso] = $h;
      }
      foreach ((array)($change['notes'] ?? []) as $date => $note) {
        $iso = qta_parse_date_input((string)$date);
        if ($iso === null || !array_key_exists($iso, $current)) throw $outside($iso);
        $newNotes[$iso] = qta_clean_text($note, 160);
      }
    }

    $allDates = array_values(array_unique(array_merge(array_keys($dayHours), array_keys($newHours))));
    sort($allDates);
    $touched = array_values(array_filter($allDates, static fn($d) =>
      ($newHours[$d] ?? null) !== ($dayHours[$d] ?? null) || ($newNotes[$d] ?? null) !== ($notes[$d] ?? null)));
    if (!$touched && !$rangeChanged) throw new QtaUserError('Nuk ndryshove asgjë: orët e ditëve janë të njëjtat.');

    $plan = qta_sched_build_fixed_range($topics, $start, $end, $newHours);
    qta_lg_verify_fixed_or_fail($topics, $plan, $start, $end, $newHours);

    $changed = array_values(array_unique(array_merge(qta_sched_diff($oldDays, $plan['days']), $touched)));
    sort($changed);
    $past = array_values(array_filter($changed, static fn($d) => $d < $today));

    $exam = $pdo->prepare('SELECT COUNT(*) AS n, MIN(exam_date) AS first_exam FROM course_group_students WHERE group_id = ? AND exam_date IS NOT NULL AND exam_date < ?');
    $exam->execute([$groupId, $end]);
    $conflict = $exam->fetch(PDO::FETCH_ASSOC);
    if ((int)$conflict['n'] > 0) {
      throw new QtaUserError('Me këtë ndryshim orari do të mbaronte ' . qta_sched_on_label($end) . ', pas datës së provimit të '
        . qta_plural_word((int)$conflict['n'], 'kursanti', 'kursantëve') . ' (' . qta_date((string)$conflict['first_exam']) . '). '
        . 'Provimi nuk mund të jetë para mbarimit. Ndrysho së pari datat e provimit te "Kursantët".', ['code' => 'exam_conflict']);
    }

    $confirm = qta_lg_change_confirm((int)$g['is_completed'] === 1, $past);
    $summary = qta_lg_plan_summary($plan);
    $result = [
      'what' => $rangeChanged ? 'fixed_range_settings' : 'fixed_days',
      'old' => ['start_date' => $oldStart, 'end_date' => $oldEnd, 'days' => count($oldDays), 'daily_hours' => null],
      'new' => $summary + ['daily_hours' => null, 'fixed' => qta_sched_fixed_summary($newHours, $courseHours, $start, $end)],
      'edited_dates' => $touched,
      'changed_dates' => $changed,
      'first_changed' => $changed[0] ?? null,
      'past_changed' => $past,
      'confirm' => $confirm,
      'revision' => (int)$g['revision'],
    ];
    if ($dry) {
      return $result;
    }
    if ($confirm && !$force) {
      throw new QtaConfirmNeeded($confirm['title'], $confirm['message'], $confirm['confirm'], ['impact' => $result]);
    }

    if ($rangeChanged) {
      $pdo->prepare('UPDATE course_groups SET start_date = ?, end_date = ? WHERE id = ?')->execute([$start, $end, $groupId]);
      $pdo->prepare('DELETE FROM group_fixed_days WHERE group_id = ?')->execute([$groupId]);
      qta_lg_write_fixed_days($pdo, $groupId, $newHours, $newNotes);
    } else {
      $upd = $pdo->prepare('UPDATE group_fixed_days SET hours = ?, note = ? WHERE group_id = ? AND lesson_date = ?');
      foreach ($touched as $d) {
        $upd->execute([$newHours[$d], $newNotes[$d] === '' ? null : $newNotes[$d], $groupId, $d]);
      }
    }
    qta_lg_write_plan($pdo, $groupId, $plan);
    $pdo->prepare('UPDATE group_schedules SET teaching_days = ?, revision = revision + 1, generated_at = NOW() WHERE group_id = ?')
        ->execute([count($plan['days']), $groupId]);
    qta_lg_assert_stored_fixed($pdo, $groupId, $topics, $start, $end);

    $result['revision'] = (int)$g['revision'] + 1;
    return $result;
  }
}

/* ============================================= Kursantët dhe fshirja */

if (!function_exists('qta_lg_set_members')) {
  /**
   * Vendos kursantët e grupit sipas listës së numrave të amzës (deri në 10).
   * @param array{force?:bool} $opts
   */
  function qta_lg_set_members(PDO $pdo, int $groupId, string $spec, array $opts = []): array
  {
    $force = !empty($opts['force']);
    return qta_tx($pdo, function () use ($pdo, $groupId, $spec, $force): array {
      $g = qta_lg_require($pdo, $groupId, true);
      if ((int)$g['is_completed'] === 1 && !$force) {
        throw new QtaConfirmNeeded('Ky grup është i mbyllur', 'Dokumentet e këtij grupi mund të jenë lëshuar tashmë. Je i sigurt që do t\'i ndryshosh kursantët?', 'Po, ndryshoji');
      }
      $ex = $pdo->prepare('
        SELECT cgs.student_id, CAST(s.nr_amze AS UNSIGNED) AS amze, s.nr_amze, cgs.exam_date, cgs.final_score
        FROM course_group_students cgs JOIN students s ON s.id = cgs.student_id
        WHERE cgs.group_id = ? ORDER BY amze
      ');
      $ex->execute([$groupId]);
      $existing = [];
      $existingRows = [];
      foreach ($ex->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[(int)$r['amze']] = (int)$r['student_id'];
        $existingRows[(int)$r['student_id']] = $r;
      }

      $nums = trim($spec) === '' ? [] : qta_amze_parse($spec);
      if (count($nums) > QTA_GROUP_MAX_MEMBERS) {
        throw new QtaUserError('Një grup mban deri në ' . QTA_GROUP_MAX_MEMBERS . ' kursantë, por ke shkruar ' . count($nums)
          . ' numra amze. Për të tjerët krijo një grup tjetër me të njëjtin kurs.');
      }
      $target = [];
      foreach ($nums as $n) {
        $target[$n] = $existing[$n] ?? qta_amze_ensure_student($pdo, $n);
      }
      $toRemove = [];
      foreach ($existing as $amze => $sid) if (!isset($target[$amze])) $toRemove[] = $sid;
      $toAdd = [];
      foreach ($target as $amze => $sid) if (!isset($existing[$amze])) $toAdd[] = $sid;
      if (!$toAdd && !$toRemove) {
        throw new QtaUserError('Nuk ndryshove asgjë: kursantët e grupit janë të njëjtët.');
      }

      $withScores = qta_results_scored_students($pdo, $groupId);
      $withData = array_values(array_filter($toRemove, static fn($sid) =>
        $existingRows[$sid]['exam_date'] !== null || $existingRows[$sid]['final_score'] !== null || isset($withScores[$sid])));
      if ($withData && !$force) {
        $labels = array_map(static fn($sid) => (string)$existingRows[$sid]['nr_amze'], $withData);
        throw new QtaConfirmNeeded('Të hiqen kursantë me provim ose pikë?',
          'Kursantët me numër amze ' . implode(', ', $labels) . ' kanë datë provimi ose pikë në këtë grup. Kur hiqen nga grupi, këto të dhëna fshihen.',
          'Po, hiqi');
      }

      if ($toAdd) {
        qta_members_assert_can_join($pdo, $toAdd, (int)$g['course_id']);
        $ph = implode(',', array_fill(0, count($toAdd), '?'));
        $pdo->prepare("DELETE FROM student_course_plans WHERE status = 'planned' AND student_id IN ($ph)")->execute($toAdd);
      }
      $del = $pdo->prepare('DELETE FROM course_group_students WHERE group_id = ? AND student_id = ?');
      foreach ($toRemove as $sid) $del->execute([$groupId, $sid]);
      $ins = $pdo->prepare('INSERT INTO course_group_students (group_id, student_id) VALUES (?, ?)');
      foreach ($toAdd as $sid) $ins->execute([$groupId, $sid]);

      return ['added' => count($toAdd), 'removed' => count($toRemove), 'total' => count($target)];
    });
  }

  /** Fshin grupin me orar: orarin, ditët e veçanta, kursantët e grupit (jo vetë kursantët) dhe grupin. */
  function qta_lg_delete(PDO $pdo, int $groupId, array $opts = []): array
  {
    $force = !empty($opts['force']);
    return qta_tx($pdo, function () use ($pdo, $groupId, $force): array {
      $g = qta_lg_require($pdo, $groupId, true);
      $mc = $pdo->prepare('SELECT COUNT(*) FROM course_group_students WHERE group_id = ?');
      $mc->execute([$groupId]);
      $members = (int)$mc->fetchColumn();
      if (!$force) {
        throw new QtaConfirmNeeded('Të fshihet Grupi #' . $groupId . '?',
          'Fshihen orari dhe ditët e veçanta të grupit' . ($members ? ', si dhe datat e provimit dhe pikët e ' . qta_plural_word($members, 'kursantit', 'kursantëve') . ' në këtë grup' : '')
          . '. Kursantët nuk fshihen — ata mbeten te "Kursantët", pa grup. Kjo nuk mund të kthehet mbrapsht.',
          'Po, fshije grupin');
      }
      /* Fshirje e shprehur (jo zinxhir), që historiku të shohë çdo pjesë që hiqet. */
      foreach (['group_schedule_slots', 'group_schedule_days', 'group_schedule_topics', 'group_day_rules', 'group_fixed_days', 'group_schedules', 'group_conversions'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE group_id = ?")->execute([$groupId]);
      }
      $pdo->prepare('DELETE FROM course_group_students WHERE group_id = ?')->execute([$groupId]);
      $pdo->prepare('DELETE FROM course_groups WHERE id = ?')->execute([$groupId]);
      return ['group' => $g, 'members' => $members];
    });
  }
}
