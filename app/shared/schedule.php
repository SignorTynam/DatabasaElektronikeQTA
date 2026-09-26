<?php
declare(strict_types=1);

/**
 * schedule.php — Motori i orarit të mësimit. Një implementim i vetëm, i përdorur
 * nga parashikimi, ruajtja, shfaqja dhe testet.
 *
 * Funksione të pastra: nuk lexojnë databazën dhe nuk varen nga ora e serverit.
 *
 * Rregullat
 *  - Temat vijnë në radhë: moduli 1 tema 1, tema 2, … pastaj moduli 2 …
 *  - Një ditë e zakonshme ka $defaultHours orë. E diela nuk ka mësim, përveç kur
 *    ka një rregull për atë datë. Dita e javës llogaritet nga kalendari (UTC).
 *  - Rregulli i një date: null = orari i zakonshëm, 0 = pa mësim, 1–12 = aq orë.
 *  - Një temë mund të vazhdojë në ditën tjetër të mësimit; kur një modul mbaron
 *    në mes të ditës, moduli tjetër fillon menjëherë — asnjë orë nuk humbet.
 *  - Dita e fundit ka vetëm orët që mbeten (nuk mbushet deri në orarin e plotë).
 *  - Data e fillimit duhet të jetë ditë mësimi; data e mbarimit = dita e fundit.
 *
 * Formati i temave (në radhë):
 *   [['seq' => 1, 'module_seq' => 1, 'hours' => 2, (opsionale) 'module_hours',
 *     'module_title', 'topic_seq', 'topic_title'], …]
 * Formati i rregullave: ['2026-10-11' => 4, '2026-11-28' => 0, '2026-10-04' => null]
 */

require_once __DIR__ . '/domain.php';

const QTA_DAY_MAX_HOURS = 12;
const QTA_SCHEDULE_MAX_CALENDAR_DAYS = 3700; // ~10 vjet: mbrojtje, jo kufi pune

if (!function_exists('qta_sched_is_iso_date')) {
  function qta_sched_is_iso_date($s): bool
  {
    return is_string($s)
      && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) === 1
      && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
  }

  function qta_sched_date(string $iso): DateTimeImmutable
  {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $iso) {
      throw new InvalidArgumentException('Datë e pavlefshme: ' . $iso);
    }
    return $d;
  }

  /** Dita e javës sipas ISO-8601: 1 = e hënë … 7 = e diel. */
  function qta_sched_weekday(string $iso): int
  {
    return (int)qta_sched_date($iso)->format('N');
  }

  function qta_sched_is_sunday(string $iso): bool
  {
    return qta_sched_weekday($iso) === 7;
  }

  function qta_sched_next_day(string $iso): string
  {
    return qta_sched_date($iso)->modify('+1 day')->format('Y-m-d');
  }

  /**
   * Orët e mësimit për një datë, sipas rregullave të grupit.
   * @param array<string,?int> $rules
   */
  function qta_sched_day_hours(string $iso, int $defaultHours, array $rules): int
  {
    if (array_key_exists($iso, $rules)) {
      $h = $rules[$iso];
      return $h === null ? $defaultHours : (int)$h;
    }
    return qta_sched_is_sunday($iso) ? 0 : $defaultHours;
  }

  /** "e diel, 04.10.2026" — për mesazhe. */
  function qta_sched_day_label(string $iso): string
  {
    $names = [1 => 'e hënë', 'e martë', 'e mërkurë', 'e enjte', 'e premte', 'e shtunë', 'e diel'];
    $d = qta_sched_date($iso);
    return $names[(int)$d->format('N')] . ', ' . $d->format('d.m.Y');
  }

  /** "më 23.10.2026 (e premte)" — për fjali si "Mbaron më …". */
  function qta_sched_on_label(string $iso): string
  {
    $names = [1 => 'e hënë', 'e martë', 'e mërkurë', 'e enjte', 'e premte', 'e shtunë', 'e diel'];
    $d = qta_sched_date($iso);
    return 'më ' . $d->format('d.m.Y') . ' (' . $names[(int)$d->format('N')] . ')';
  }
}

if (!function_exists('qta_sched_validate_inputs')) {
  /**
   * Kontrollon hyrjet para ndërtimit. Hedh QtaUserError me mesazh të qartë.
   * @param array<int,array<string,mixed>> $topics
   * @param array<string,?int> $rules
   */
  function qta_sched_validate_inputs(array $topics, string $start, int $defaultHours, array $rules): int
  {
    if (!$topics) {
      throw new QtaUserError('Kursi nuk ka tema, prandaj orari nuk mund të ndërtohet. Shto modulet dhe temat te "Kurset".');
    }
    $total = 0;
    $prevSeq = 0;
    foreach ($topics as $t) {
      $h = $t['hours'] ?? null;
      $seq = $t['seq'] ?? null;
      if (!is_int($h) || $h < 1) {
        throw new QtaUserError('Një temë ka orë të pavlefshme. Çdo temë duhet të ketë të paktën 1 orë.');
      }
      if (!is_int($seq) || $seq <= $prevSeq) {
        throw new QtaUserError('Radha e temave nuk është e qartë. Rregullo radhën e temave te "Kurset".');
      }
      $prevSeq = $seq;
      $total += $h;
    }
    if (!qta_sched_is_iso_date($start)) {
      throw new QtaUserError('Data e fillimit nuk është e vlefshme. Shkruaje si dd.mm.vvvv, p.sh. 01.10.2026.');
    }
    if ($defaultHours < 1 || $defaultHours > QTA_DAY_MAX_HOURS) {
      throw new QtaUserError('Orët e mësimit në ditë duhet të jenë nga 1 deri në ' . QTA_DAY_MAX_HOURS . '.');
    }
    foreach ($rules as $date => $h) {
      if (!qta_sched_is_iso_date((string)$date)) {
        throw new QtaUserError('Një ditë e veçantë ka datë të pavlefshme. Hiqe dhe shtoje sërish.');
      }
      if ($h !== null && (!is_int($h) || $h < 0 || $h > QTA_DAY_MAX_HOURS)) {
        throw new QtaUserError('Dita ' . qta_sched_day_label((string)$date) . ' ka orë të pavlefshme. Një ditë ka nga 0 deri në ' . QTA_DAY_MAX_HOURS . ' orë.');
      }
    }
    if (qta_sched_day_hours($start, $defaultHours, $rules) === 0) {
      $why = qta_sched_is_sunday($start) && !array_key_exists($start, $rules)
        ? 'Të dielën nuk ka mësim.'
        : 'Kjo ditë është shënuar pa mësim.';
      throw new QtaUserError('Data e fillimit (' . qta_sched_day_label($start) . ') nuk është ditë mësimi. ' . $why
        . ' Zgjidh një ditë tjetër, ose shëno këtë ditë si ditë mësimi.');
    }
    return $total;
  }
}

if (!function_exists('qta_sched_build')) {
  /**
   * Ndërton orarin: ditët e mësimit dhe ndarjen e orëve të temave nëpër ditë.
   *
   * @param array<int,array<string,mixed>> $topics  në radhë
   * @param array<string,?int>             $rules
   * @return array{start_date:string,end_date:string,total_hours:int,days:array<int,array{seq:int,date:string,hours:int,capacity:int,slots:array<int,array{seq:int,topic:int,hours:int}>}>}
   */
  function qta_sched_build(array $topics, string $start, int $defaultHours, array $rules = []): array
  {
    $topics = array_values($topics);
    $total = qta_sched_validate_inputs($topics, $start, $defaultHours, $rules);

    $days = [];
    $remaining = $total;
    $ti = 0;
    $left = (int)$topics[0]['hours'];
    $date = $start;
    $steps = 0;

    while ($remaining > 0) {
      if (++$steps > QTA_SCHEDULE_MAX_CALENDAR_DAYS) {
        throw new QtaUserError('Orari do të zgjaste mbi 10 vjet. Kontrollo orët në ditë dhe ditët pa mësim.');
      }
      $capacity = qta_sched_day_hours($date, $defaultHours, $rules);
      if ($capacity > 0) {
        $use = min($capacity, $remaining);
        $slots = [];
        $todo = $use;
        while ($todo > 0) {
          $take = min($todo, $left);
          $slots[] = ['seq' => count($slots) + 1, 'topic' => (int)$topics[$ti]['seq'], 'hours' => $take];
          $left -= $take;
          $todo -= $take;
          $remaining -= $take;
          if ($left === 0 && $remaining > 0) {
            $ti++;
            $left = (int)$topics[$ti]['hours'];
          }
        }
        $days[] = ['seq' => count($days) + 1, 'date' => $date, 'hours' => $use, 'capacity' => $capacity, 'slots' => $slots];
      }
      $date = qta_sched_next_day($date);
    }

    return [
      'start_date'  => $days[0]['date'],
      'end_date'    => $days[count($days) - 1]['date'],
      'total_hours' => $total,
      'days'        => $days,
    ];
  }
}

if (!function_exists('qta_sched_verify')) {
  /**
   * Kontroll i pavarur i çdo rregulli të orarit. Kthen listën e shkeljeve
   * (bosh = orari është i saktë). Përdoret para çdo ruajtjeje dhe në teste.
   *
   * @param array<int,array<string,mixed>> $topics
   * @param array<string,mixed>            $plan
   * @param array<string,?int>             $rules
   * @return string[]
   */
  function qta_sched_verify(array $topics, array $plan, int $defaultHours, array $rules, ?string $start = null): array
  {
    $v = [];
    $topics = array_values($topics);
    $days = $plan['days'] ?? [];
    if (!$days) {
      return ['orari nuk ka asnjë ditë'];
    }

    $topicHours = [];
    $topicModule = [];
    $moduleHours = [];
    $total = 0;
    foreach ($topics as $t) {
      $topicHours[(int)$t['seq']] = (int)$t['hours'];
      $topicModule[(int)$t['seq']] = (int)$t['module_seq'];
      $total += (int)$t['hours'];
      if (isset($t['module_hours'])) {
        $moduleHours[(int)$t['module_seq']] = (int)$t['module_hours'];
      }
    }

    $start = $start ?? $plan['start_date'] ?? $days[0]['date'];
    if ($days[0]['date'] !== $start) {
      $v[] = 'dita e parë (' . $days[0]['date'] . ') nuk është data e fillimit (' . $start . ')';
    }
    if (($plan['start_date'] ?? null) !== $days[0]['date']) {
      $v[] = 'start_date nuk përputhet me ditën e parë';
    }
    if (($plan['end_date'] ?? null) !== $days[count($days) - 1]['date']) {
      $v[] = 'end_date nuk përputhet me ditën e fundit';
    }

    $sumDays = 0;
    $sumSlots = 0;
    $perTopic = [];
    $perModule = [];
    $sequence = [];
    $prevDate = null;
    $teachingDates = [];
    $lastIndex = count($days) - 1;

    foreach ($days as $i => $day) {
      $date = (string)$day['date'];
      if (!qta_sched_is_iso_date($date)) {
        $v[] = 'datë e pavlefshme: ' . $date;
        continue;
      }
      if ((int)$day['seq'] !== $i + 1) {
        $v[] = 'numri i ditës ' . $date . ' nuk është ' . ($i + 1);
      }
      if ($prevDate !== null && $date <= $prevDate) {
        $v[] = 'datat nuk janë në rritje: ' . $prevDate . ' → ' . $date;
      }
      $prevDate = $date;
      $teachingDates[$date] = true;

      $cap = qta_sched_day_hours($date, $defaultHours, $rules);
      $hours = (int)$day['hours'];
      if ($cap <= 0) {
        $v[] = 'mësim në një ditë pa mësim: ' . $date . (qta_sched_is_sunday($date) ? ' (e diel)' : '');
      }
      if ($hours < 1 || $hours > $cap) {
        $v[] = 'dita ' . $date . ' ka ' . $hours . ' orë, kufiri është ' . $cap;
      }
      if ($i < $lastIndex && $hours !== $cap) {
        $v[] = 'dita ' . $date . ' nuk është e plotë (' . $hours . ' nga ' . $cap . ') edhe pse nuk është dita e fundit';
      }

      $slotSum = 0;
      foreach (($day['slots'] ?? []) as $j => $slot) {
        $h = (int)$slot['hours'];
        $t = (int)$slot['topic'];
        if ((int)$slot['seq'] !== $j + 1) {
          $v[] = 'radha e pjesëve në ditën ' . $date . ' nuk është e vazhdueshme';
        }
        if ($h < 1) {
          $v[] = 'pjesë pa orë në ditën ' . $date;
        }
        if (!isset($topicHours[$t])) {
          $v[] = 'temë e panjohur ' . $t . ' në ditën ' . $date;
          continue;
        }
        $slotSum += $h;
        $perTopic[$t] = ($perTopic[$t] ?? 0) + $h;
        $m = $topicModule[$t];
        $perModule[$m] = ($perModule[$m] ?? 0) + $h;
        if (!$sequence || end($sequence) !== $t) {
          $sequence[] = $t;
        }
      }
      if ($slotSum !== $hours) {
        $v[] = 'dita ' . $date . ': pjesët kanë ' . $slotSum . ' orë, dita ' . $hours;
      }
      $sumDays += $hours;
      $sumSlots += $slotSum;
    }

    if ($sumDays !== $total) {
      $v[] = 'shuma e ditëve ' . $sumDays . ' ≠ orët e kursit ' . $total;
    }
    if ($sumSlots !== $total) {
      $v[] = 'shuma e pjesëve ' . $sumSlots . ' ≠ orët e kursit ' . $total;
    }
    foreach ($topicHours as $t => $h) {
      if (($perTopic[$t] ?? 0) !== $h) {
        $v[] = 'tema ' . $t . ' ka ' . ($perTopic[$t] ?? 0) . ' orë në orar, duhet ' . $h;
      }
    }
    foreach ($moduleHours as $m => $h) {
      if (($perModule[$m] ?? 0) !== $h) {
        $v[] = 'moduli ' . $m . ' ka ' . ($perModule[$m] ?? 0) . ' orë në orar, duhet ' . $h;
      }
    }
    /* Radha: çdo temë shfaqet një herë si bllok i vazhdueshëm, pikërisht në radhën e dhënë. */
    $expected = array_map(static fn($t) => (int)$t['seq'], $topics);
    if ($sequence !== $expected) {
      $v[] = 'temat nuk ndjekin radhën e kursit';
    }

    /* Asnjë ditë mësimi nuk anashkalohet mes fillimit dhe mbarimit. */
    if (qta_sched_is_iso_date($start) && isset($days[$lastIndex]['date']) && qta_sched_is_iso_date((string)$days[$lastIndex]['date'])) {
      $d = $start;
      $end = (string)$days[$lastIndex]['date'];
      $guard = 0;
      while ($d <= $end && ++$guard <= QTA_SCHEDULE_MAX_CALENDAR_DAYS) {
        if (!isset($teachingDates[$d]) && qta_sched_day_hours($d, $defaultHours, $rules) > 0) {
          $v[] = 'dita ' . $d . ' është ditë mësimi, por u anashkalua';
        }
        $d = qta_sched_next_day($d);
      }
    }

    return $v;
  }
}

if (!function_exists('qta_sched_annotate')) {
  /**
   * Plotëson ditët për shfaqje: emrat e temave dhe moduleve, cilat orë të temës
   * zhvillohen atë ditë ("ora 1–2 nga 4"), ku fillon dhe ku mbaron një modul.
   *
   * @param array<int,array<string,mixed>> $topics
   * @param array<int,array<string,mixed>> $days
   * @return array<int,array<string,mixed>>
   */
  function qta_sched_annotate(array $topics, array $days): array
  {
    $bySeq = [];
    $moduleFirst = [];
    $moduleLast = [];
    foreach ($topics as $t) {
      $bySeq[(int)$t['seq']] = $t;
      $m = (int)$t['module_seq'];
      $moduleFirst[$m] = $moduleFirst[$m] ?? (int)$t['seq'];
      $moduleLast[$m] = (int)$t['seq'];
    }
    $done = [];
    foreach ($days as &$day) {
      foreach ($day['slots'] as &$slot) {
        $t = (int)$slot['topic'];
        $topic = $bySeq[$t] ?? ['seq' => $t, 'module_seq' => 0, 'hours' => (int)$slot['hours']];
        $before = $done[$t] ?? 0;
        $slot['from'] = $before + 1;
        $slot['to'] = $before + (int)$slot['hours'];
        $done[$t] = $slot['to'];
        $slot['topic_hours'] = (int)$topic['hours'];
        $slot['is_split'] = $slot['from'] > 1 || $slot['to'] < (int)$topic['hours'];
        $slot['topic_seq'] = (int)($topic['topic_seq'] ?? $t);
        $slot['topic_title'] = (string)($topic['topic_title'] ?? '');
        $slot['module_seq'] = (int)$topic['module_seq'];
        $slot['module_title'] = (string)($topic['module_title'] ?? '');
        $m = (int)$topic['module_seq'];
        $slot['module_starts'] = $slot['from'] === 1 && ($moduleFirst[$m] ?? null) === $t;
        $slot['module_ends'] = $slot['to'] === (int)$topic['hours'] && ($moduleLast[$m] ?? null) === $t;
      }
      unset($slot);
    }
    unset($day);
    return $days;
  }

  /**
   * Kur zhvillohet çdo modul: dita e parë dhe e fundit.
   * @return array<int,array{module_seq:int,title:string,hours:int,topics:int,first_date:?string,last_date:?string}>
   */
  function qta_sched_module_windows(array $topics, array $days): array
  {
    $out = [];
    foreach ($topics as $t) {
      $m = (int)$t['module_seq'];
      if (!isset($out[$m])) {
        $out[$m] = ['module_seq' => $m, 'title' => (string)($t['module_title'] ?? ''), 'hours' => (int)($t['module_hours'] ?? 0),
                    'topics' => 0, 'first_date' => null, 'last_date' => null];
      }
      $out[$m]['topics']++;
    }
    $topicModule = [];
    foreach ($topics as $t) {
      $topicModule[(int)$t['seq']] = (int)$t['module_seq'];
    }
    foreach ($days as $day) {
      foreach ($day['slots'] as $slot) {
        $m = $topicModule[(int)$slot['topic']] ?? null;
        if ($m === null || !isset($out[$m])) continue;
        $out[$m]['first_date'] = $out[$m]['first_date'] ?? (string)$day['date'];
        $out[$m]['last_date'] = (string)$day['date'];
      }
    }
    ksort($out);
    return array_values($out);
  }

  /**
   * Datat ku mësimi ndryshon mes dy orareve (orë ose tema të ndryshme, ose
   * ditë që shtohet/hiqet). Kthehen të renditura.
   * @return string[]
   */
  function qta_sched_diff(array $oldDays, array $newDays): array
  {
    $sig = static function (array $days): array {
      $out = [];
      foreach ($days as $d) {
        $parts = [];
        foreach ($d['slots'] as $s) {
          $parts[] = (int)$s['topic'] . ':' . (int)$s['hours'];
        }
        $out[(string)$d['date']] = (int)$d['hours'] . '|' . implode(',', $parts);
      }
      return $out;
    };
    $a = $sig($oldDays);
    $b = $sig($newDays);
    $dates = array_unique(array_merge(array_keys($a), array_keys($b)));
    sort($dates);
    $changed = [];
    foreach ($dates as $d) {
      if (($a[$d] ?? null) !== ($b[$d] ?? null)) {
        $changed[] = $d;
      }
    }
    return $changed;
  }
}
