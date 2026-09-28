<?php
declare(strict_types=1);

/**
 * legacy_conversion.php — Konvertimi i grupeve nga "Regjistri i vjetër i kurseve
 * profesionale" te "Regjistri i kurseve profesionale".
 *
 * Rrjedha:
 *   grupi i vjetër → kontrolli paraprak → propozimi automatik → drafti →
 *   shqyrtimi dhe ndryshimet → kontrolli përfundimtar → konfirmimi → konvertimi atomik
 *
 * Parimet
 *  - Konvertimi bëhet në vend: i njëjti numër grupi, të njëjtët kursantë, provime,
 *    pikë, dokumente dhe gjurmë në historik. Asgjë nuk fshihet dhe nuk rikrijohet.
 *  - Fillimi dhe mbarimi janë fakte historike: orari ndërtohet brenda tyre
 *    (schedule_fixed.php). Propozimi automatik nuk është e vërteta historike —
 *    e shqyrton dhe e miraton një administrator ose editor.
 *  - Drafti nuk prek grupin: grupi mbetet 'legacy' deri në commit-in e konvertimit.
 *  - Çdo ndryshim i grupit, i kursantëve (provime, pikë) ose i kursit pasi u hap
 *    faqja ose u ruajt drafti e vjetëron punën (gjurma e burimit). Një draft i
 *    vjetëruar ose i ndryshuar nga dikush tjetër nuk ruhet dhe nuk konvertohet.
 *  - Asgjë nuk korrigjohet vetë: çdo problem i të dhënave të vjetra tregohet me
 *    fjalë dhe me vendin ku rregullohet.
 */

require_once __DIR__ . '/lesson_groups.php';
require_once __DIR__ . '/schedule_fixed.php';
require_once __DIR__ . '/group_list.php';

/** Gjendjet e konvertimit, në radhën e përparësisë (e para që vlen fiton). */
const QTA_CONV_STATES = [
  'impossible' => ['label' => 'Nuk mund të konvertohet', 'variant' => 'danger',  'icon' => 'bi-slash-circle'],
  'problems'   => ['label' => 'Ka probleme',             'variant' => 'warning', 'icon' => 'bi-exclamation-triangle-fill'],
  'review'     => ['label' => 'Kërkon kontroll',         'variant' => 'info',    'icon' => 'bi-eye-fill'],
  'draft'      => ['label' => 'Draft për kontroll',      'variant' => 'accent',  'icon' => 'bi-pencil-square'],
  'ready'      => ['label' => 'Gati për përgatitje',     'variant' => 'success', 'icon' => 'bi-check-circle-fill'],
];

/* ===================================================== Leximi i burimit */

if (!function_exists('qta_conv_source')) {
  /**
   * Grupi i vjetër me kursin, kursantët dhe strukturën AKTUALE të kursit — ajo që
   * do të ngrihet si kopja e grupit kur të konvertohet.
   * @return array{group:array,course:array,members:array,modules:array,check:array,topics:array}
   */
  function qta_conv_source(PDO $pdo, int $groupId): array
  {
    $st = $pdo->prepare('
      SELECT cg.id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed, cg.model,
             c.code AS course_code, c.name AS course_name, c.hours AS course_hours
      FROM course_groups cg JOIN courses c ON c.id = cg.course_id
      WHERE cg.id = ?
    ');
    $st->execute([$groupId]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) {
      throw new QtaUserError('Grupi #' . $groupId . ' nuk u gjet. Ndoshta u fshi — kthehu te lista e konvertimit.', ['code' => 'not_found']);
    }
    $group = [
      'id' => (int)$g['id'], 'course_id' => (int)$g['course_id'],
      'start_date' => (string)$g['start_date'], 'end_date' => (string)$g['end_date'],
      'is_completed' => (int)$g['is_completed'], 'model' => (string)$g['model'],
    ];
    $course = ['id' => (int)$g['course_id'], 'code' => (string)$g['course_code'], 'name' => (string)$g['course_name'], 'hours' => (int)$g['course_hours']];

    $ms = $pdo->prepare('
      SELECT cgs.student_id, s.nr_amze, p.first_name, p.father_name, p.last_name, p.personal_number,
             cgs.exam_date, cgs.final_score
      FROM course_group_students cgs
      JOIN students s ON s.id = cgs.student_id
      LEFT JOIN persons p ON p.id = s.person_id
      WHERE cgs.group_id = ?
      ORDER BY CAST(s.nr_amze AS UNSIGNED), s.nr_amze
    ');
    $ms->execute([$groupId]);
    $members = $ms->fetchAll(PDO::FETCH_ASSOC);

    $modules = qta_course_modules($pdo, $course['id']);
    return [
      'group' => $group,
      'course' => $course,
      'members' => $members,
      'modules' => $modules,
      'check' => qta_course_check($course, $modules),
      'topics' => qta_course_schedule_topics($modules),
    ];
  }

  /**
   * Gjurma e burimit: grupi (kursi, datat, gjendja), kursantët me provimet dhe pikët,
   * orët e kursit dhe struktura e tij. Çdo ndryshim i tyre e ndryshon gjurmën.
   */
  function qta_conv_fingerprint(array $src): string
  {
    $g = $src['group'];
    $members = array_map(static fn($m) => [
      (int)$m['student_id'], $m['exam_date'] === null ? null : (string)$m['exam_date'],
      $m['final_score'] === null ? null : (string)$m['final_score'],
    ], $src['members']);
    usort($members, static fn($a, $b) => $a[0] <=> $b[0]);
    $data = [
      'group' => [$g['id'], $g['course_id'], $g['start_date'], $g['end_date'], $g['is_completed'], $g['model']],
      'members' => $members,
      'course' => [$src['course']['id'], $src['course']['hours']],
      'curriculum' => array_map(static fn($t) => [
        $t['module_seq'], $t['module_title'], $t['module_hours'], $t['topic_seq'], $t['topic_title'], $t['hours'],
        $t['source_module_id'], $t['source_topic_id'],
      ], $src['topics']),
    ];
    return hash('sha256', (string)json_encode($data, JSON_UNESCAPED_UNICODE));
  }

  /** Orët e kursit që do të vendosen: shuma e temave kur kursi është gati, përndryshe orët e kursit. */
  function qta_conv_hours(array $src): int
  {
    return $src['check']['ready'] ? array_sum(array_column($src['topics'], 'hours')) : (int)$src['course']['hours'];
  }
}

/* ====================================================== Kontrolli paraprak */

if (!function_exists('qta_conv_member_issues')) {
  /**
   * Problemet e kursantëve që e pengojnë konvertimin, për një ose shumë grupe.
   * Të njëjtat rregulla si te regjistri i ri (group_members.php): deri në 10
   * kursantë, një regjistrim vetëm në një grup, i njëjti person jo dy herë në të
   * njëjtin kurs, provimi jo para mbarimit, pikë vetëm me datë provimi, 0–100.
   * Nuk korrigjon asgjë: çdo problem thuhet me fjalë dhe me vendin e rregullimit.
   *
   * @param int[] $groupIds
   * @return array<int,array<int,array{code:string,short:string,text:string,fix:?array}>>
   */
  function qta_conv_member_issues(PDO $pdo, array $groupIds): array
  {
    $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $found = [];
    $add = static function (int $gid, string $code, string $item) use (&$found): void {
      $found[$gid][$code][] = $item;
    };

    $st = $pdo->prepare("SELECT group_id, COUNT(*) AS n FROM course_group_students WHERE group_id IN ($ph) GROUP BY group_id HAVING COUNT(*) > ?");
    $st->execute(array_merge($ids, [QTA_GROUP_MAX_MEMBERS]));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add((int)$r['group_id'], 'too_many', (string)(int)$r['n']);

    $st = $pdo->prepare("
      SELECT a.group_id, s.nr_amze, b.group_id AS other_group
      FROM course_group_students a
      JOIN course_group_students b ON b.student_id = a.student_id AND b.group_id <> a.group_id
      JOIN students s ON s.id = a.student_id
      WHERE a.group_id IN ($ph)
      ORDER BY a.group_id, CAST(s.nr_amze AS UNSIGNED), b.group_id
    ");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add((int)$r['group_id'], 'in_other_group', $r['nr_amze'] . ' (Grupi #' . (int)$r['other_group'] . ')');

    $st = $pdo->prepare("
      SELECT DISTINCT a.group_id, sa.nr_amze, gb.id AS other_group, sb.nr_amze AS other_amze
      FROM course_group_students a
      JOIN students sa ON sa.id = a.student_id
      JOIN persons pa ON pa.id = sa.person_id
      JOIN course_groups ga ON ga.id = a.group_id
      JOIN course_groups gb ON gb.course_id = ga.course_id AND gb.id <> ga.id
      JOIN course_group_students b ON b.group_id = gb.id AND b.student_id <> a.student_id
      JOIN students sb ON sb.id = b.student_id
      JOIN persons pb ON pb.id = sb.person_id
      WHERE a.group_id IN ($ph) AND pa.personal_number IS NOT NULL AND pa.personal_number <> '' AND pb.personal_number = pa.personal_number
      ORDER BY a.group_id, sa.nr_amze
    ");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $add((int)$r['group_id'], 'same_course_twice', $r['nr_amze'] . ' (edhe si ' . $r['other_amze'] . ' te Grupi #' . (int)$r['other_group'] . ')');
    }

    $st = $pdo->prepare("
      SELECT cgs.group_id, s.nr_amze, cgs.exam_date, cgs.final_score, g.end_date
      FROM course_group_students cgs
      JOIN course_groups g ON g.id = cgs.group_id
      JOIN students s ON s.id = cgs.student_id
      WHERE cgs.group_id IN ($ph)
        AND ((cgs.exam_date IS NOT NULL AND cgs.exam_date < g.end_date)
          OR (cgs.final_score IS NOT NULL AND cgs.exam_date IS NULL)
          OR cgs.final_score < 0 OR cgs.final_score > 100)
      ORDER BY cgs.group_id, CAST(s.nr_amze AS UNSIGNED)
    ");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $gid = (int)$r['group_id'];
      if ($r['exam_date'] !== null && $r['exam_date'] < $r['end_date']) {
        $add($gid, 'exam_before_end', $r['nr_amze'] . ' (provimi ' . qta_date((string)$r['exam_date']) . ')');
      }
      if ($r['final_score'] !== null && $r['exam_date'] === null) {
        $add($gid, 'score_without_exam', (string)$r['nr_amze']);
      }
      if ($r['final_score'] !== null && ((float)$r['final_score'] < 0 || (float)$r['final_score'] > 100)) {
        $add($gid, 'score_out_of_range', $r['nr_amze'] . ' (' . rtrim(rtrim((string)$r['final_score'], '0'), '.') . ' pikë)');
      }
    }

    $out = [];
    foreach ($found as $gid => $codes) {
      $fix = ['href' => 'groups.php?group=' . $gid, 'label' => 'Hape te regjistri i vjetër'];
      foreach ($codes as $code => $items) {
        $n = count($items);
        $list = implode(', ', array_slice($items, 0, 5)) . ($n > 5 ? ' dhe ' . ($n - 5) . ' të tjerë' : '');
        [$short, $text] = match ($code) {
          'too_many' => ['Mbi 10 kursantë',
            'Grupi ka ' . $items[0] . ' kursantë, por një grup në regjistrin e ri mban deri në ' . QTA_GROUP_MAX_MEMBERS . '. Ndaje te regjistri i vjetër para konvertimit.'],
          'in_other_group' => [$n === 1 ? 'Kursant edhe në një grup tjetër' : $n . ' kursantë edhe në grupe të tjera',
            ($n === 1 ? 'Kursanti me nr. amze ' . $list . ' është edhe në një grup tjetër.' : 'Kursantët me nr. amze ' . $list . ' janë edhe në grupe të tjera.')
            . ' Një regjistrim mund të jetë vetëm në një grup: hiqe nga njëri grup para konvertimit.'],
          'same_course_twice' => ['I njëjti person dy herë',
            'I njëjti person e ka ndjekur këtë kurs dy herë: nr. amze ' . $list . '. Një person nuk e ndjek dy herë të njëjtin kurs — korrigjoje para konvertimit.'],
          'exam_before_end' => ['Provim para mbarimit',
            ($n === 1 ? 'Kursanti me nr. amze ' . $list . ' ka provimin' : 'Kursantët me nr. amze ' . $list . ' kanë provimin')
            . ' para mbarimit të grupit. Provimi nuk mund të jetë para mbarimit: korrigjo datën e provimit para konvertimit.'],
          'score_without_exam' => ['Pikë pa datë provimi',
            ($n === 1 ? 'Kursanti me nr. amze ' . $list . ' ka pikë, por jo datë provimi.' : 'Kursantët me nr. amze ' . $list . ' kanë pikë, por jo datë provimi.')
            . ' Shto datën e provimit para konvertimit.'],
          'score_out_of_range' => ['Pikë jashtë 0–100',
            'Pikët janë nga 0 deri në 100, por: nr. amze ' . $list . '. Korrigjoji para konvertimit.'],
        };
        $out[$gid][] = ['code' => $code, 'short' => $short, 'text' => $text, 'fix' => $fix];
      }
    }
    return $out;
  }

  /**
   * Pengesat e konvertimit të një grupi dhe mundësia e orarit. $courseIssue = null
   * kur kursi është gati; përndryshe problemi i parë me fjalë. Mundësia e orarit
   * vlerësohet vetëm me një kurs gati (përndryshe orët e tij nuk janë ende të sakta).
   *
   * @return array{blockers:array,impossible:bool,feasibility:?array}
   */
  function qta_conv_blockers(array $group, array $course, int $hours, ?string $courseIssue, array $memberIssues): array
  {
    $blockers = [];
    $impossible = false;
    $feasibility = null;
    $gid = (int)$group['id'];
    if (($group['model'] ?? 'legacy') !== 'legacy') {
      $blockers[] = ['code' => 'converted', 'short' => 'I konvertuar', 'text' => 'Grupi #' . $gid . ' është tashmë te "Regjistri i kurseve profesionale".',
                     'fix' => ['href' => 'lesson_group.php?id=' . $gid, 'label' => 'Hape grupin']];
      return ['blockers' => $blockers, 'impossible' => false, 'feasibility' => null];
    }
    if ($courseIssue !== null) {
      $blockers[] = ['code' => 'course_not_ready', 'short' => 'Kursi nuk është gati',
                     'text' => 'Kursi "' . $course['name'] . '" nuk ka ende strukturë të plotë. ' . $courseIssue . ' Plotësoje te "Katalogu i kurseve", pastaj kthehu këtu.',
                     'fix' => ['href' => 'course.php?id=' . (int)$course['id'], 'label' => 'Hape kursin']];
    } else {
      $feasibility = qta_sched_fixed_feasibility($hours, (string)$group['start_date'], (string)$group['end_date']);
      if (!$feasibility['ok']) {
        $impossible = in_array($feasibility['code'], ['capacity', 'boundaries'], true);
        $short = match ($feasibility['code']) {
          'capacity' => $hours . ' orë në ' . $feasibility['calendar_days'] . ' ditë · maks. ' . $feasibility['capacity'],
          'boundaries' => 'Më pak orë se ditët kufitare',
          default => 'Datat e grupit',
        };
        $blockers[] = ['code' => (string)$feasibility['code'], 'short' => $short, 'text' => (string)$feasibility['reason'],
                       'fix' => $impossible ? null : ['href' => 'groups.php?group=' . $gid, 'label' => 'Hape te regjistri i vjetër']];
      }
    }
    foreach ($memberIssues as $issue) $blockers[] = $issue;
    return ['blockers' => $blockers, 'impossible' => $impossible, 'feasibility' => $feasibility];
  }

  /**
   * Gjendja e konvertimit me fjalë, për listën dhe për faqen e grupit.
   * @return array{key:string,label:string,variant:string,icon:string,hint:string}
   */
  function qta_conv_status(array $pre, ?array $draft, bool $stale, ?array $summary): array
  {
    $hint = '';
    if ($pre['impossible']) {
      $key = 'impossible';
      $hint = (string)$pre['blockers'][0]['short'];
    } elseif ($pre['blockers']) {
      $key = 'problems';
      $n = count($pre['blockers']);
      $hint = $pre['blockers'][0]['short'] . ($n > 1 ? ' · +' . ($n - 1) : '');
    } elseif ($stale) {
      $key = 'review';
      $hint = 'Të dhënat ndryshuan pas draftit';
    } elseif ($summary && $summary['issues']) {
      $key = 'review';
      $first = $summary['issues'][0];
      $gap = abs((int)$summary['planned'] - (int)$summary['hours']);
      $hint = match ($first['code']) {
        'missing_hours' => ($gap === 1 ? 'Mungon 1 orë' : 'Mungojnë ' . $gap . ' orë'),
        'extra_hours' => $gap . ' orë më shumë',
        'start_off', 'end_off' => 'Kufiri historik pa mësim',
        default => 'Plani duhet korrigjuar',
      };
    } elseif ($summary && $summary['sundays']) {
      $key = 'review';
      $n = count($summary['sundays']);
      $hint = 'Përdor ' . $n . ($n === 1 ? ' të diel' : ' të diela');
    } elseif ($draft) {
      $key = 'draft';
      $hint = 'Ruajtur ' . qta_ago((string)($draft['updated_at'] ?? $draft['created_at'] ?? ''));
    } else {
      $key = 'ready';
    }
    return ['key' => $key, 'hint' => $hint] + QTA_CONV_STATES[$key];
  }
}

/* ============================================================ Drafti */

if (!function_exists('qta_conv_draft_find')) {
  /** Drafti i grupit (plani i dekoduar), ose null. $lock e bllokon deri në fund të transaksionit. */
  function qta_conv_draft_find(PDO $pdo, int $groupId, bool $lock = false): ?array
  {
    $st = $pdo->prepare('
      SELECT d.group_id, d.revision, d.source_fingerprint, d.algorithm_version, d.plan_json,
             d.created_by, d.updated_by, d.created_at, d.updated_at,
             COALESCE(NULLIF(u.full_name, \'\'), u.email) AS updated_by_name
      FROM legacy_conversion_drafts d LEFT JOIN users u ON u.id = d.updated_by
      WHERE d.group_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$groupId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;
    $d['revision'] = (int)$d['revision'];
    $d['plan'] = qta_conv_plan_decode((string)$d['plan_json']);
    unset($d['plan_json']);
    return $d;
  }

  /**
   * Plani i ruajtur → ['days' => [data => orë], 'manual' => [data => true], 'notes' => [data => tekst]].
   */
  function qta_conv_plan_decode(string $json): array
  {
    $raw = json_decode($json, true);
    $plan = ['days' => [], 'manual' => [], 'notes' => []];
    foreach ((array)($raw['days'] ?? []) as $item) {
      $d = (string)($item['d'] ?? '');
      if (!qta_sched_is_iso_date($d)) continue;
      $plan['days'][$d] = (int)($item['h'] ?? 0);
      if (!empty($item['m'])) $plan['manual'][$d] = true;
      if (isset($item['n']) && $item['n'] !== '') $plan['notes'][$d] = (string)$item['n'];
    }
    ksort($plan['days']);
    return $plan;
  }

  function qta_conv_plan_encode(array $plan): string
  {
    return (string)json_encode(['v' => 1, 'days' => qta_conv_plan_for_client($plan)], JSON_UNESCAPED_UNICODE);
  }

  /** Plani si listë e thjeshtë për shfletuesin: [{d, h, m?, n?}, …] në radhë. */
  function qta_conv_plan_for_client(array $plan): array
  {
    $out = [];
    foreach ($plan['days'] as $d => $h) {
      $item = ['d' => (string)$d, 'h' => (int)$h];
      if (!empty($plan['manual'][$d])) $item['m'] = 1;
      if (isset($plan['notes'][$d]) && $plan['notes'][$d] !== '') $item['n'] = (string)$plan['notes'][$d];
      $out[] = $item;
    }
    return $out;
  }

  /** Gjurma e planit të miratuar (orët dhe shënimet e çdo date), ruhet te konvertimi. */
  function qta_conv_plan_hash(array $plan): string
  {
    $rows = [];
    foreach ($plan['days'] as $d => $h) $rows[] = [(string)$d, (int)$h, (string)($plan['notes'][$d] ?? '')];
    return hash('sha256', (string)json_encode($rows, JSON_UNESCAPED_UNICODE));
  }

  /**
   * Plani i dërguar nga faqja. Kërkon saktësisht çdo datë të [S, E] një herë, me
   * orë të plota 0–8. Nuk kërkon që plani të jetë gati (një draft mund të ruhet
   * i paplotë); gatishmëria kontrollohet te konvertimi.
   */
  function qta_conv_plan_from_input($raw, string $start, string $end): array
  {
    $bad = 'Plani i dërguar nuk është i plotë. Rifresko faqen dhe provo sërish.';
    if (!is_array($raw)) throw new QtaUserError($bad, ['code' => 'bad_plan']);
    $dates = qta_sched_dates($start, $end);
    $inRange = array_flip($dates);
    $plan = ['days' => [], 'manual' => [], 'notes' => []];
    foreach ($raw as $item) {
      $d = is_array($item) ? ($item['d'] ?? null) : null;
      if (!is_string($d) || !isset($inRange[$d]) || isset($plan['days'][$d])) {
        throw new QtaUserError($bad, ['code' => 'bad_plan']);
      }
      $h = qta_parse_int_input($item['h'] ?? null, 0, QTA_DAY_MAX_HOURS);
      if ($h === null) {
        $given = is_numeric($item['h'] ?? null) ? (int)$item['h'] : null;
        throw new QtaUserError($given !== null && $given > QTA_DAY_MAX_HOURS
          ? 'Më ' . qta_sched_dmy($d) . ' janë vendosur ' . $given . ' orë. Një ditë mund të ketë maksimumi ' . QTA_DAY_MAX_HOURS . ' orë.'
          : 'Orët e datës ' . qta_sched_dmy($d) . ' duhet të jenë një numër i plotë nga 0 deri në ' . QTA_DAY_MAX_HOURS . '.', ['code' => 'day_over']);
      }
      $plan['days'][$d] = $h;
      if (!empty($item['m'])) $plan['manual'][$d] = true;
      $note = qta_clean_text($item['n'] ?? '', 160);
      if ($note !== '') $plan['notes'][$d] = $note;
    }
    if (count($plan['days']) !== count($dates)) {
      throw new QtaUserError($bad, ['code' => 'bad_plan']);
    }
    ksort($plan['days']);
    return $plan;
  }
}

/* ================================================================ Pamja */

if (!function_exists('qta_conv_view')) {
  /**
   * Gjithçka që i duhet faqes së konvertimit të një grupi: burimi, pengesat,
   * drafti ose propozimi automatik, përmbledhja e planit dhe gjendja. Nuk shkruan.
   */
  function qta_conv_view(PDO $pdo, int $groupId): array
  {
    $src = qta_conv_source($pdo, $groupId);
    $g = $src['group'];
    $hours = qta_conv_hours($src);
    $issues = qta_conv_member_issues($pdo, [$groupId])[$groupId] ?? [];
    $courseIssue = $src['check']['ready'] ? null : (string)($src['check']['issues'][0]['text'] ?? 'Kursi nuk ka ende module dhe tema.');
    $pre = qta_conv_blockers($g, $src['course'], $hours, $courseIssue, $issues);
    $fp = qta_conv_fingerprint($src);
    $draft = $g['model'] === 'legacy' ? qta_conv_draft_find($pdo, $groupId) : null;
    $stale = $draft !== null && !hash_equals((string)$draft['source_fingerprint'], $fp);

    $proposal = null;
    if ($pre['feasibility'] !== null && $pre['feasibility']['ok']) {
      $proposal = qta_sched_propose_fixed_range($hours, $g['start_date'], $g['end_date']);
    }
    $plan = null;
    if ($draft && array_keys($draft['plan']['days']) === qta_sched_dates($g['start_date'], $g['end_date'])) {
      $plan = $draft['plan'];
    } elseif ($proposal) {
      $plan = ['days' => $proposal['days'], 'manual' => [], 'notes' => []];
    }
    $summary = $plan ? qta_sched_fixed_summary($plan['days'], $hours, $g['start_date'], $g['end_date']) : null;
    return [
      'source' => $src,
      'hours' => $hours,
      'pre' => $pre,
      'fingerprint' => $fp,
      'draft' => $draft,
      'stale' => $stale,
      'proposal' => $proposal,
      'plan' => $plan,
      'summary' => $summary,
      'status' => qta_conv_status($pre, $draft, $stale, $summary),
    ];
  }

  /**
   * "Konvertimi i grupeve": grupet e regjistrit të vjetër me gjendjen e konvertimit.
   * Kërkimi dhe kursi filtrohen në SQL (qta_group_where); gjendja llogaritet këtu
   * për të gjitha grupet që përputhen, pastaj numërohet dhe filtrohet sipas çipit.
   *
   * @return array{rows:array,counts:array<string,int>}
   */
  function qta_conv_list(PDO $pdo, array $F, string $state = ''): array
  {
    $params = [];
    $w = array_merge(["cg.model = 'legacy'"], qta_group_where($F, $params, false));
    $st = $pdo->prepare("
      SELECT cg.id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed, cg.model,
             c.name AS course_name, c.hours AS course_hours,
             COALESCE(m.n, 0) AS members, m.amze_min, m.amze_max,
             d.revision AS draft_revision, d.source_fingerprint AS draft_fingerprint, d.plan_json AS draft_plan,
             d.created_at AS draft_created_at, d.updated_at AS draft_updated_at
      FROM course_groups cg
      JOIN courses c ON c.id = cg.course_id
      LEFT JOIN (
        SELECT x.group_id, COUNT(*) AS n, MIN(CAST(s.nr_amze AS UNSIGNED)) AS amze_min, MAX(CAST(s.nr_amze AS UNSIGNED)) AS amze_max
        FROM course_group_students x JOIN students s ON s.id = x.student_id
        GROUP BY x.group_id
      ) m ON m.group_id = cg.id
      LEFT JOIN legacy_conversion_drafts d ON d.group_id = cg.id
      WHERE " . implode(' AND ', $w) . "
      ORDER BY cg.start_date DESC, cg.id DESC
    ");
    foreach ($params as $k => $v) $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $ready = qta_course_summaries($pdo, array_column($rows, 'course_id'));
    $issues = qta_conv_member_issues($pdo, array_column($rows, 'id'));
    $counts = array_fill_keys(array_merge([''], array_keys(QTA_CONV_STATES)), 0);
    $out = [];
    foreach ($rows as $r) {
      $gid = (int)$r['id'];
      $group = ['id' => $gid, 'start_date' => (string)$r['start_date'], 'end_date' => (string)$r['end_date'], 'model' => 'legacy'];
      $course = ['id' => (int)$r['course_id'], 'name' => (string)$r['course_name'], 'hours' => (int)$r['course_hours']];
      $isReady = !empty($ready[$course['id']]['ready']);
      $pre = qta_conv_blockers($group, $course, $course['hours'], $isReady ? null : 'Modulet dhe temat e tij nuk kanë ende orët e plota.', $issues[$gid] ?? []);

      $draft = null;
      $stale = false;
      $summary = null;
      if ($r['draft_revision'] !== null) {
        $draft = ['revision' => (int)$r['draft_revision'], 'created_at' => $r['draft_created_at'], 'updated_at' => $r['draft_updated_at']];
        $stale = !hash_equals((string)$r['draft_fingerprint'], qta_conv_fingerprint(qta_conv_source($pdo, $gid)));
        $plan = qta_conv_plan_decode((string)$r['draft_plan']);
        if (!$pre['blockers']) $summary = qta_sched_fixed_summary($plan['days'], $course['hours'], $group['start_date'], $group['end_date']);
      } elseif (!$pre['blockers'] && ($pre['feasibility']['sundays_needed'] ?? 0) > 0) {
        /* Pa draft: propozimi do të përdorte të diela — kërkon kontroll që në fillim. */
        $summary = qta_sched_fixed_summary(qta_sched_propose_fixed_range($course['hours'], $group['start_date'], $group['end_date'])['days'],
          $course['hours'], $group['start_date'], $group['end_date']);
      }
      $status = qta_conv_status($pre, $draft, $stale, $summary);
      $counts['']++;
      $counts[$status['key']]++;
      if ($state !== '' && $status['key'] !== $state) continue;
      $out[] = $r + ['status' => $status, 'calendar_days' => count(qta_sched_dates($group['start_date'], $group['end_date']))];
    }
    return ['rows' => $out, 'counts' => $counts];
  }
}

/* ========================================================= Ndryshimet */

if (!function_exists('qta_conv_lock_group')) {
  /** Blokon rreshtin e grupit; refuzon grupin që mungon ose që është konvertuar tashmë. */
  function qta_conv_lock_group(PDO $pdo, int $groupId): array
  {
    $st = $pdo->prepare('SELECT id, course_id, start_date, end_date, is_completed, model FROM course_groups WHERE id = ? FOR UPDATE');
    $st->execute([$groupId]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) {
      throw new QtaUserError('Grupi #' . $groupId . ' nuk u gjet. Ndoshta u fshi — kthehu te lista e konvertimit.', ['code' => 'not_found']);
    }
    if ($g['model'] !== 'legacy') {
      throw new QtaUserError('Grupi #' . $groupId . ' është konvertuar tashmë dhe ndodhet te "Regjistri i kurseve profesionale".',
        ['code' => 'converted', 'redirect' => 'lesson_group.php?id=' . $groupId]);
    }
    return $g;
  }

  /** Versioni i draftit që pa faqja (0 = pa draft) duhet të jetë ai i ruajtur. */
  function qta_conv_assert_revision(?array $draft, $revision): void
  {
    $seen = ($revision === null || $revision === '') ? 0 : (int)$revision;
    $current = $draft ? (int)$draft['revision'] : 0;
    if ($seen === $current) return;
    throw new QtaUserError($current === 0
      ? 'Ky draft u fshi ndërkohë nga dikush tjetër. Rifresko të dhënat para se të vazhdosh.'
      : 'Ky draft u ndryshua ndërkohë. Rifresko të dhënat para se të vazhdosh.', ['code' => 'draft_changed']);
  }

  /** Të dhënat burimore nuk kanë ndryshuar që kur u hap faqja dhe që kur u ruajt drafti. */
  function qta_conv_assert_source(?array $draft, string $fingerprint, $seen): void
  {
    $pageStale = is_string($seen) && $seen !== '' && !hash_equals($fingerprint, $seen);
    $draftStale = $draft !== null && !hash_equals((string)$draft['source_fingerprint'], $fingerprint);
    if ($pageStale || $draftStale) {
      throw new QtaUserError('Të dhënat e grupit, të kursantëve ose të kursit ndryshuan pasi u përgatit ky propozim. Rifresko të dhënat para se të vazhdosh.', ['code' => 'source_changed']);
    }
  }

  /**
   * Ruan draftin (e krijon herën e parë). Nuk prek grupin. Refuzon një skedë të
   * vjetër (versioni) dhe të dhëna burimore që kanë ndryshuar (gjurma).
   * @return array{revision:int,created:bool,unchanged:bool,summary:array}
   */
  function qta_conv_save(PDO $pdo, int $groupId, $rawPlan, $revision, $seenSource, ?int $userId): array
  {
    return qta_tx($pdo, function () use ($pdo, $groupId, $rawPlan, $revision, $seenSource, $userId): array {
      $g = qta_conv_lock_group($pdo, $groupId);
      $draft = qta_conv_draft_find($pdo, $groupId, true);
      qta_conv_assert_revision($draft, $revision);
      $src = qta_conv_source($pdo, $groupId);
      $fp = qta_conv_fingerprint($src);
      qta_conv_assert_source($draft, $fp, $seenSource);
      $plan = qta_conv_plan_from_input($rawPlan, (string)$g['start_date'], (string)$g['end_date']);
      $json = qta_conv_plan_encode($plan);
      $summary = qta_sched_fixed_summary($plan['days'], qta_conv_hours($src), (string)$g['start_date'], (string)$g['end_date']);

      if ($draft) {
        if ($json === qta_conv_plan_encode($draft['plan'])) {
          return ['revision' => (int)$draft['revision'], 'created' => false, 'unchanged' => true, 'summary' => $summary];
        }
        $upd = $pdo->prepare('UPDATE legacy_conversion_drafts SET plan_json = ?, revision = revision + 1, updated_by = ? WHERE group_id = ? AND revision = ?');
        $upd->execute([$json, $userId, $groupId, (int)$draft['revision']]);
        if ($upd->rowCount() !== 1) {
          throw new QtaUserError('Ky draft u ndryshua ndërkohë. Rifresko të dhënat para se të vazhdosh.', ['code' => 'draft_changed']);
        }
        return ['revision' => (int)$draft['revision'] + 1, 'created' => false, 'unchanged' => false, 'summary' => $summary];
      }
      $pdo->prepare('INSERT INTO legacy_conversion_drafts (group_id, revision, source_fingerprint, algorithm_version, plan_json, created_by, updated_by) VALUES (?, 1, ?, ?, ?, ?, ?)')
          ->execute([$groupId, $fp, QTA_FIXED_ALGORITHM_VERSION, $json, $userId, $userId]);
      return ['revision' => 1, 'created' => true, 'unchanged' => false, 'summary' => $summary];
    });
  }

  /**
   * Rifreskon një draft pas ndryshimit të të dhënave burimore: merr gjurmën e re dhe
   * e mban planin kur periudha është e njëjtë; përndryshe nis nga propozimi automatik.
   * Kur periudha e re nuk i mban dot orët, drafti hiqet (dhe thuhet pse).
   * @return array{revision:int,kept:bool,discarded:bool}
   */
  function qta_conv_refresh(PDO $pdo, int $groupId, $revision, ?int $userId): array
  {
    return qta_tx($pdo, function () use ($pdo, $groupId, $revision, $userId): array {
      $g = qta_conv_lock_group($pdo, $groupId);
      $draft = qta_conv_draft_find($pdo, $groupId, true);
      qta_conv_assert_revision($draft, $revision);
      if (!$draft) {
        return ['revision' => 0, 'kept' => false, 'discarded' => false];
      }
      $src = qta_conv_source($pdo, $groupId);
      $start = (string)$g['start_date'];
      $end = (string)$g['end_date'];
      $plan = $draft['plan'];
      $kept = array_keys($plan['days']) === qta_sched_dates($start, $end);
      if (!$kept) {
        try {
          $p = qta_sched_propose_fixed_range(qta_conv_hours($src), $start, $end);
        } catch (QtaUserError) {
          $pdo->prepare('DELETE FROM legacy_conversion_drafts WHERE group_id = ?')->execute([$groupId]);
          return ['revision' => 0, 'kept' => false, 'discarded' => true];
        }
        $plan = ['days' => $p['days'], 'manual' => [], 'notes' => []];
      }
      $pdo->prepare('UPDATE legacy_conversion_drafts SET source_fingerprint = ?, plan_json = ?, algorithm_version = ?, revision = revision + 1, updated_by = ? WHERE group_id = ?')
          ->execute([qta_conv_fingerprint($src), qta_conv_plan_encode($plan), $kept ? $draft['algorithm_version'] : QTA_FIXED_ALGORITHM_VERSION, $userId, $groupId]);
      return ['revision' => (int)$draft['revision'] + 1, 'kept' => $kept, 'discarded' => false];
    });
  }

  /** Propozimi automatik nga e para (për "Rikthe propozimin"). Nuk shkruan. */
  function qta_conv_propose(PDO $pdo, int $groupId): array
  {
    $src = qta_conv_source($pdo, $groupId);
    $p = qta_sched_propose_fixed_range(qta_conv_hours($src), $src['group']['start_date'], $src['group']['end_date']);
    return $p + ['hours' => qta_conv_hours($src)];
  }

  /** Rishpërndan orët e planit të dërguar, pa prekur ditët e caktuara me dorë kur ka rrugë tjetër. Nuk shkruan. */
  function qta_conv_rebalance(PDO $pdo, int $groupId, $rawPlan): array
  {
    $src = qta_conv_source($pdo, $groupId);
    $g = $src['group'];
    $plan = qta_conv_plan_from_input($rawPlan, $g['start_date'], $g['end_date']);
    $r = qta_sched_rebalance_fixed_range($plan['days'], qta_conv_hours($src), $g['start_date'], $g['end_date'], array_keys($plan['manual']));
    return $r + ['hours' => qta_conv_hours($src)];
  }
}

/* ======================================================== Konvertimi */

if (!function_exists('qta_conv_apply')) {
  /**
   * Konvertimi atomik i një grupi të vjetër. Në një transaksion të vetëm:
   *
   *   1. bllokon grupin dhe kontrollon që është ende 'legacy';
   *   2. bllokon draftin dhe kontrollon versionin që pa faqja;
   *   3. bllokon kursin dhe kursantët e grupit, lexon strukturën aktuale;
   *   4. kontrollon gjurmën e burimit (asgjë nuk ndryshoi ndërkohë);
   *   5. kontrollon të gjitha pengesat (kursi gati, kursantët, provimet, kapaciteti);
   *   6. ndërton orarin nga plani i miratuar dhe e verifikon në mënyrë të pavarur;
   *   7. shënon konvertimin ('applying') — vetëm kjo e lejon bazën të ndryshojë llojin;
   *   8. kalon grupin 'legacy' → 'scheduled' (i njëjti ID, kurs dhe data);
   *   9. krijon orarin me data historike, kopjen e temave, planin e ditëve, ditët dhe pjesët;
   *  10. lexon sërish gjithçka dhe e verifikon, bashkë me kursantët, provimet dhe pikët;
   *  11. mbyll konvertimin ('completed') dhe heq draftin.
   *
   * Çdo gabim e kthen gjithçka mbrapsht: grupi mbetet saktësisht siç ishte.
   * @return array{group_id:int,hours:int,teaching_days:int,start_date:string,end_date:string,members:int}
   */
  function qta_conv_apply(PDO $pdo, int $groupId, $revision, $seenSource, ?int $userId): array
  {
    return qta_tx($pdo, function () use ($pdo, $groupId, $revision, $seenSource, $userId): array {
      $g = qta_conv_lock_group($pdo, $groupId);
      $draft = qta_conv_draft_find($pdo, $groupId, true);
      if (!$draft) {
        throw new QtaUserError('Ruaj së pari propozimin si draft, pastaj konverto grupin.', ['code' => 'no_draft']);
      }
      qta_conv_assert_revision($draft, $revision);
      qta_curriculum_lock_course($pdo, (int)$g['course_id']);
      $pdo->prepare('SELECT student_id FROM course_group_students WHERE group_id = ? FOR UPDATE')->execute([$groupId]);

      $src = qta_conv_source($pdo, $groupId);
      $fp = qta_conv_fingerprint($src);
      qta_conv_assert_source($draft, $fp, $seenSource);
      $start = $src['group']['start_date'];
      $end = $src['group']['end_date'];
      $hours = qta_conv_hours($src);

      $issues = qta_conv_member_issues($pdo, [$groupId])[$groupId] ?? [];
      $courseIssue = $src['check']['ready'] ? null : (string)($src['check']['issues'][0]['text'] ?? 'Kursi nuk ka ende module dhe tema.');
      $pre = qta_conv_blockers($src['group'], $src['course'], $hours, $courseIssue, $issues);
      if ($pre['blockers']) {
        throw new QtaUserError($pre['blockers'][0]['text'], ['code' => 'blocked', 'blockers' => $pre['blockers']]);
      }

      $topics = $src['topics'];
      $plan = $draft['plan'];
      $schedule = qta_sched_build_fixed_range($topics, $start, $end, $plan['days']);
      qta_lg_verify_fixed_or_fail($topics, $schedule, $start, $end, $plan['days']);
      $teachingDays = count($schedule['days']);

      $pdo->prepare("INSERT INTO group_conversions (group_id, source_start_date, source_end_date, course_hours, teaching_days, algorithm_version, approved_plan_hash, source_fingerprint, status, converted_by, converted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'applying', ?, NOW())")
          ->execute([$groupId, $start, $end, $hours, $teachingDays, (string)$draft['algorithm_version'], qta_conv_plan_hash($plan), $fp, $userId]);
      $upd = $pdo->prepare("UPDATE course_groups SET model = 'scheduled' WHERE id = ? AND model = 'legacy'");
      $upd->execute([$groupId]);
      if ($upd->rowCount() !== 1) {
        throw new QtaUserError('Grupi nuk u konvertua, sepse gjendja e tij ndryshoi ndërkohë. Rifresko të dhënat dhe provo sërish.', ['code' => 'draft_changed']);
      }
      $pdo->prepare("INSERT INTO group_schedules (group_id, schedule_mode, daily_hours, course_hours, curriculum_taken_at, teaching_days, revision, generated_at) VALUES (?, 'fixed_range', NULL, ?, NOW(), ?, 1, NOW())")
          ->execute([$groupId, $hours, $teachingDays]);
      qta_lg_write_topics($pdo, $groupId, $topics);
      qta_lg_write_fixed_days($pdo, $groupId, $plan['days'], $plan['notes']);
      qta_lg_write_plan($pdo, $groupId, $schedule);

      /* Leximi i fundit: orari, plani dhe datat historike; kursantët, provimet dhe pikët të paprekura. */
      qta_lg_assert_stored_fixed($pdo, $groupId, qta_lg_topics($pdo, $groupId), $start, $end);
      $after = qta_conv_source($pdo, $groupId);
      $same = static fn(array $s): string => (string)json_encode([
        array_map(static fn($m) => [(int)$m['student_id'], $m['exam_date'], $m['final_score']], $s['members']),
        [$s['group']['id'], $s['group']['course_id'], $s['group']['start_date'], $s['group']['end_date'], $s['group']['is_completed']],
      ]);
      if ($after['group']['model'] !== 'scheduled' || $same($after) !== $same($src)) {
        error_log('[QTA conversion] grupi ' . $groupId . ': të dhënat e grupit ndryshuan gjatë konvertimit');
        throw new QtaUserError('Grupi nuk u konvertua, sepse kontrolli i fundit gjeti një mospërputhje. Asgjë nuk ndryshoi. Provo sërish; nëse përsëritet, njofto administratorin.');
      }

      $done = $pdo->prepare("UPDATE group_conversions SET status = 'completed' WHERE group_id = ? AND status = 'applying'");
      $done->execute([$groupId]);
      if ($done->rowCount() !== 1) {
        throw new QtaUserError('Grupi nuk u konvertua, sepse shënimi i konvertimit nuk u mbyll. Asgjë nuk ndryshoi. Njofto administratorin.');
      }
      $pdo->prepare('DELETE FROM legacy_conversion_drafts WHERE group_id = ?')->execute([$groupId]);

      return ['group_id' => $groupId, 'hours' => $hours, 'teaching_days' => $teachingDays, 'start_date' => $start, 'end_date' => $end,
              'members' => count($src['members'])];
    });
  }
}
