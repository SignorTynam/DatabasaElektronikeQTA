<?php
declare(strict_types=1);

/**
 * group_calendar.php — Kalendari i grupeve: një pamje vetëm për lexim e të dhënave që
 * ekzistojnë. Nuk ka tabelë të vetën, nuk shkruan asgjë dhe nuk rillogarit orarin.
 *
 * Burimi i së vërtetës mbetet domeni i grupeve:
 *   course_groups.start_date / end_date   kohëzgjatja; të dyja janë ditë mësimi (përfshirëse)
 *   group_schedules                       mënyra e orarit, orët e kursit, ditët e mësimit
 *   group_schedule_topics                 kopja e ngrirë e moduleve dhe temave të grupit
 *   group_schedule_days / _slots          orari i ruajtur: kur zhvillohet çdo modul
 *   course_group_students                 kursantët
 * Gjendja vjen nga qta_group_state_meta() (themeli.php), e njëjtë me regjistrat.
 *
 * Cilat grupe shfaqen:
 *   - grupet e "Regjistrit të kurseve profesionale" (model 'scheduled', me orar), si me
 *     orar të llogaritur ashtu edhe me data historike (të konvertuara);
 *   - grupet e regjistrit të vjetër (model 'legacy') vetëm kur kërkohen: nuk kanë orar as
 *     kopje temash, prandaj shfaqen të dallueshme dhe pa përmbajtje kursi. Kur nuk
 *     kërkohen, numërohen, që përdoruesi të dijë se ekzistojnë.
 *
 *   qta_calendar_month('2026-10', $today)       muaji, intervali dhe emri i tij
 *   qta_calendar_range($from, $to)              intervali i kërkuar nga faqja, i kontrolluar
 *   qta_calendar_events($pdo, $from, $to, …)    grupet e intervalit (2 query)
 *   qta_calendar_group($pdo, $id)               detajet e një grupi për dritaren (5 query)
 */

require_once __DIR__ . '/lesson_groups.php';

/** Kalendari kërkon vetëm atë që shfaq: një muaj, me pak rezervë. */
const QTA_CAL_MAX_RANGE_DAYS = 62;

/* ================================================================ Periudha */

if (!function_exists('qta_calendar_month')) {
  /**
   * Muaji nga adresa ('2026-10'); çdo vlerë tjetër jep muajin e $today.
   * @return array{month:string,from:string,to:string,label:string}
   */
  function qta_calendar_month($raw, string $today): array
  {
    $valid = is_string($raw) && preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) === 1
      && (int)$m[2] >= 1 && (int)$m[2] <= 12 && (int)$m[1] >= 1900 && (int)$m[1] <= 2200;
    $first = qta_sched_date(($valid ? $raw : substr($today, 0, 7)) . '-01');
    return [
      'month' => $first->format('Y-m'),
      'from'  => $first->format('Y-m-d'),
      'to'    => $first->modify('last day of this month')->format('Y-m-d'),
      'label' => ucfirst(qta_month_name((int)$first->format('n'))) . ' ' . $first->format('Y'),
    ];
  }

  /**
   * Intervali që kërkon faqja: dy data ISO, fillimi jo pas mbarimit, të shumtën
   * QTA_CAL_MAX_RANGE_DAYS ditë. Asnjë filtër tjetër nuk vjen nga jashtë.
   * @return array{from:string,to:string}
   */
  function qta_calendar_range($from, $to): array
  {
    $iso = static fn($v): bool => is_string($v) && qta_sched_is_iso_date($v) && $v >= '1900-01-01' && $v <= '2200-12-31';
    if (!$iso($from) || !$iso($to)) {
      throw new QtaUserError('Periudha e kalendarit nuk është e vlefshme. Rifresko faqen.', ['code' => 'bad_range']);
    }
    if ($from > $to) {
      throw new QtaUserError('Periudha e kalendarit nuk është e vlefshme: fillimi është pas mbarimit.', ['code' => 'bad_range']);
    }
    if (qta_calendar_days($from, $to) > QTA_CAL_MAX_RANGE_DAYS) {
      throw new QtaUserError('Kalendari lexon të shumtën ' . QTA_CAL_MAX_RANGE_DAYS . ' ditë njëherësh.', ['code' => 'bad_range']);
    }
    return ['from' => $from, 'to' => $to];
  }

  /** Ditët nga $start te $end, të dyja të përfshira: 01.10–20.10 = 20; një ditë = 1. */
  function qta_calendar_days(string $start, string $end): int
  {
    return (int)qta_sched_date($start)->diff(qta_sched_date($end))->days + 1;
  }

  /** Faqja e grupit, në regjistrin ku ndodhet. */
  function qta_calendar_group_href(int $groupId, bool $legacy): string
  {
    return $legacy ? 'groups.php?group=' . $groupId : 'lesson_group.php?id=' . $groupId;
  }
}

/* ================================================================= Grupet */

if (!function_exists('qta_calendar_events')) {
  /**
   * Grupet që kanë të paktën një ditë brenda [$from, $to]: start_date ≤ $to dhe
   * end_date ≥ $from, datat përfshirëse. Një query për grupet (kursantët numërohen
   * në të njëjtën query) dhe një për grupet e regjistrit të vjetër që nuk shfaqen.
   * Kur intervali është bosh, jepen edhe grupet më të afërta para dhe pas tij.
   *
   * @return array{from:string,to:string,today:string,legacy:bool,legacy_hidden:int,events:array,nearest:?array}
   */
  function qta_calendar_events(PDO $pdo, string $from, string $to, bool $legacy = false, ?string $today = null): array
  {
    $today = $today ?? date('Y-m-d');
    /* Si "Regjistri i kurseve profesionale": një grup me orar ka gjithnjë rreshtin e orarit. */
    $scope = $legacy
      ? "LEFT JOIN group_schedules gs ON gs.group_id = cg.id WHERE (cg.model = 'legacy' OR (cg.model = 'scheduled' AND gs.group_id IS NOT NULL))"
      : "JOIN group_schedules gs ON gs.group_id = cg.id WHERE cg.model = 'scheduled'";
    $st = $pdo->prepare("
      SELECT cg.id, cg.course_id, cg.start_date, cg.end_date, cg.is_completed, cg.model,
             c.name AS course_name, c.code AS course_code,
             COUNT(cgs.student_id) AS members
      FROM course_groups cg
      JOIN courses c ON c.id = cg.course_id
      LEFT JOIN course_group_students cgs ON cgs.group_id = cg.id
      $scope
        AND cg.start_date <= :to AND cg.end_date >= :from
      GROUP BY cg.id
      ORDER BY cg.start_date ASC, cg.id ASC
    ");
    $st->execute([':to' => $to, ':from' => $from]);
    $events = array_map(static fn(array $r): array => qta_calendar_event($r, $today), $st->fetchAll(PDO::FETCH_ASSOC));

    $hidden = 0;
    if (!$legacy) {
      $lc = $pdo->prepare("SELECT COUNT(*) FROM course_groups cg WHERE cg.model = 'legacy' AND cg.start_date <= :to AND cg.end_date >= :from");
      $lc->execute([':to' => $to, ':from' => $from]);
      $hidden = (int)$lc->fetchColumn();
    }

    return [
      'from' => $from,
      'to' => $to,
      'today' => $today,
      'legacy' => $legacy,
      'legacy_hidden' => $hidden,
      'events' => $events,
      'nearest' => $events ? null : qta_calendar_nearest($pdo, $from, $to, $legacy),
    ];
  }

  /**
   * Një grup si ngjarje e kalendarit: vetëm ajo që duhet për rreshtin — pa kursantët
   * dhe pa asnjë të dhënë personale.
   */
  function qta_calendar_event(array $r, string $today): array
  {
    $id = (int)$r['id'];
    $legacy = ($r['model'] ?? 'scheduled') === 'legacy';
    return [
      'id' => $id,
      'course_id' => (int)$r['course_id'],
      'course' => (string)$r['course_name'],
      'code' => (string)($r['course_code'] ?? ''),
      'start' => (string)$r['start_date'],
      'end' => (string)$r['end_date'],
      'days' => qta_calendar_days((string)$r['start_date'], (string)$r['end_date']),
      'members' => (int)$r['members'],
      'legacy' => $legacy,
      'status' => qta_group_state_meta($r, $today),
      'href' => qta_calendar_group_href($id, $legacy),
      'course_href' => 'course.php?id=' . (int)$r['course_id'],
    ];
  }

  /**
   * Kur intervali s'ka grupe: kur mbaroi grupi i fundit para tij dhe kur nis i pari pas
   * tij, që faqja të ofrojë kalimin atje. Një query.
   * @return array{prev:?string,next:?string}
   */
  function qta_calendar_nearest(PDO $pdo, string $from, string $to, bool $legacy): array
  {
    $scope = $legacy
      ? "course_groups cg WHERE cg.model IN ('scheduled', 'legacy')"
      : "course_groups cg JOIN group_schedules gs ON gs.group_id = cg.id WHERE cg.model = 'scheduled'";
    $st = $pdo->prepare("
      SELECT (SELECT MAX(cg.end_date) FROM $scope AND cg.end_date < :from) AS prev,
             (SELECT MIN(cg.start_date) FROM $scope AND cg.start_date > :to) AS next
    ");
    $st->execute([':from' => $from, ':to' => $to]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['prev' => $row['prev'] ?? null, 'next' => $row['next'] ?? null];
  }

  /** Për lidhjen calendar.php?group=12: kur fillon grupi dhe në cilin regjistër është. */
  function qta_calendar_group_brief(PDO $pdo, int $groupId): ?array
  {
    $st = $pdo->prepare('SELECT id, start_date, model FROM course_groups WHERE id = ?');
    $st->execute([$groupId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id' => (int)$row['id'], 'start' => (string)$row['start_date'], 'legacy' => $row['model'] === 'legacy'] : null;
  }
}

/* ================================================================ Një grup */

if (!function_exists('qta_calendar_group')) {
  /**
   * Detajet e një grupi për dritaren e kalendarit.
   *
   * Grup me orar: përmbajtja e kursit është kopja e ngrirë e grupit (group_schedule_topics),
   * jo kursi siç është sot; datat e çdo moduli vijnë nga orari i ruajtur. Grup i regjistrit
   * të vjetër: datat, gjendja dhe kursantët — ai nuk ka as orar, as kopje temash.
   * Gjithsej 5 query, sado kursantë, module e tema të ketë grupi.
   */
  function qta_calendar_group(PDO $pdo, int $groupId, ?string $today = null): array
  {
    $today = $today ?? date('Y-m-d');
    $g = qta_lg_find($pdo, $groupId);
    if (!$g) {
      throw new QtaUserError('Grupi nuk u gjet. Ndoshta u fshi.', ['code' => 'not_found']);
    }
    $legacy = $g['model'] !== 'scheduled';
    $start = (string)$g['start_date'];
    $end = (string)$g['end_date'];

    $ms = $pdo->prepare('
      SELECT s.id, s.nr_amze, p.first_name, p.father_name, p.last_name
      FROM course_group_students cgs
      JOIN students s ON s.id = cgs.student_id
      LEFT JOIN persons p ON p.id = s.person_id
      WHERE cgs.group_id = ?
      ORDER BY CAST(s.nr_amze AS UNSIGNED), s.nr_amze
    ');
    $ms->execute([$groupId]);
    $members = array_map(static fn(array $m): array => [
      'id' => (int)$m['id'],
      'name' => qta_full_name($m['first_name'] ?? '', $m['father_name'] ?? '', $m['last_name'] ?? ''),
      'amze' => (string)$m['nr_amze'],
      'href' => 'student_card.php?sid=' . (int)$m['id'],
    ], $ms->fetchAll(PDO::FETCH_ASSOC));

    $out = [
      'id' => $groupId,
      'legacy' => $legacy,
      'mode' => $legacy ? null : $g['schedule_mode'],
      'course' => [
        'id' => (int)$g['course_id'],
        'name' => (string)$g['course_name'],
        'code' => (string)($g['course_code'] ?? ''),
        'href' => 'course.php?id=' . (int)$g['course_id'],
      ],
      'start' => $start,
      'end' => $end,
      'days' => qta_calendar_days($start, $end),
      'status' => qta_group_state_meta($g, $today),
      'href' => qta_calendar_group_href($groupId, $legacy),
      'members' => $members,
      'capacity' => QTA_GROUP_MAX_MEMBERS,
      'facts' => [],
      'note' => null,
      'curriculum' => null,
    ];

    if ($legacy || $g['schedule_mode'] === null) {
      $out['note'] = [
        'icon' => 'bi-archive',
        'lead' => 'Grup i regjistrit të vjetër.',
        'text' => ' Nuk ka orar mësimi dhe as kopje të moduleve e temave. Me konvertimin merr orarin brenda datave të tij historike.',
      ];
      $out['conversion_href'] = 'group_conversion.php?id=' . $groupId;
      return $out;
    }

    $fixed = qta_lg_is_fixed($g);
    $topics = qta_lg_topics($pdo, $groupId);
    $days = qta_sched_annotate($topics, qta_lg_days($pdo, $groupId));

    $out['facts'] = [
      ['label' => 'Ditë mësimi', 'value' => (string)(int)$g['teaching_days']],
      ['label' => 'Orët e kursit', 'value' => qta_hours_label((int)$g['course_hours'])
        . ($fixed ? ' · data historike' : ' · ' . (int)$g['daily_hours'] . ' në ditë')],
      ['label' => 'Orari', 'value' => $fixed
        ? 'Konvertuar nga regjistri i vjetër më ' . qta_date((string)$g['converted_at']) . (!empty($g['converted_by_name']) ? ' nga ' . $g['converted_by_name'] : '')
        : 'Llogaritet nga data e fillimit'],
    ];
    $out['note'] = qta_calendar_group_note($g, $days, $today);

    /* Modulet e kopjes, në radhën e saj, me datat kur zhvillohen dhe temat e secilit. */
    $modules = [];
    foreach (qta_sched_module_windows($topics, $days) as $w) {
      $first = $w['first_date'];
      $last = $w['last_date'];
      $modules[(int)$w['module_seq']] = [
        'seq' => (int)$w['module_seq'],
        'title' => (string)$w['title'],
        'hours' => qta_hours_label((int)$w['hours']),
        'when' => $first === null ? '' : ($first === $last ? qta_date($first) : qta_date($first) . ' – ' . qta_date((string)$last)),
        'topics' => [],
      ];
    }
    foreach ($topics as $t) {
      $modules[(int)$t['module_seq']]['topics'][] = [
        'seq' => (int)$t['topic_seq'],
        'title' => (string)$t['topic_title'],
        'hours' => qta_hours_label((int)$t['hours']),
      ];
    }
    $out['curriculum'] = [
      'summary' => qta_plural(count($modules), 'modul', 'module') . ' · ' . qta_plural(count($topics), 'temë', 'tema')
        . ' · ' . qta_hours_label((int)$g['course_hours']),
      'source' => ($fixed ? 'Kopja e grupit, e ruajtur në konvertim më ' : 'Kopja e grupit: modulet dhe temat siç ishin më ')
        . qta_date((string)$g['curriculum_taken_at']) . '. Ndryshimet e mëvonshme të kursit nuk e prekin.',
      'modules' => array_values($modules),
    ];
    return $out;
  }

  /**
   * Një fjali për gjendjen e mësimit sot, me të njëjtat fjalë si faqja e grupit:
   * "Mësimi nis nesër, …", "Sot, dita 5 nga 17 …", "Sot nuk ka mësim …", "Mësimi mbaroi …".
   * Një grup i mbyllur nuk ka nevojë për të (statusi e thotë).
   * @param array<int,array<string,mixed>> $days nga qta_sched_annotate()
   * @return array{icon:string,lead:string,text:string}|null
   */
  function qta_calendar_group_note(array $g, array $days, string $today): ?array
  {
    $start = (string)$g['start_date'];
    $end = (string)$g['end_date'];
    if ((int)$g['is_completed'] === 1) {
      return null;
    }
    if ($today < $start) {
      return ['icon' => 'bi-calendar-event', 'lead' => 'Mësimi nis ' . qta_when_label($start, $today), 'text' => ', ' . qta_sched_day_label($start) . '.'];
    }
    if ($today > $end) {
      return ['icon' => 'bi-flag', 'lead' => 'Mësimi mbaroi', 'text' => ' ' . qta_sched_day_label($end) . '. Cakto provimet dhe pikët te grupi, pastaj mbylle.'];
    }
    foreach ($days as $i => $d) {
      if ($d['date'] === $today) {
        /* Temat e ditës, me modulin kur ndryshon: "Excel: 3. Formulat (2 orë); 4. Grafikët (3 orë)". */
        $parts = [];
        $module = null;
        foreach ($d['slots'] as $s) {
          $topic = $s['topic_seq'] . '. ' . $s['topic_title'] . ' (' . qta_hours_label((int)$s['hours']) . ')';
          $parts[] = $s['module_title'] !== $module ? $s['module_title'] . ': ' . $topic : $topic;
          $module = $s['module_title'];
        }
        return ['icon' => 'bi-geo-alt', 'lead' => 'Sot, dita ' . ($i + 1) . ' nga ' . count($days),
                'text' => ' · ' . qta_hours_label((int)$d['hours']) . ' — ' . implode('; ', $parts) . '.'];
      }
    }
    foreach ($days as $d) {
      if ($d['date'] > $today) {
        return ['icon' => 'bi-cup-hot', 'lead' => 'Sot nuk ka mësim.', 'text' => ' Dita tjetër e mësimit: ' . qta_sched_day_label((string)$d['date']) . '.'];
      }
    }
    return null;
  }
}
