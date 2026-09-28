<?php
declare(strict_types=1);

/**
 * schedule_fixed.php — Orari me data historike ('fixed_range'): grupet e
 * konvertuara nga "Regjistri i vjetër i kurseve profesionale".
 *
 * Te një grup i konvertuar fillimi (S) dhe mbarimi (E) janë fakte historike: nuk
 * lëvizin kurrë. Problemi është i kundërt me orarin e llogaritur: jo "sa zgjat
 * kursi", por "si ndahen orët e kursit brenda periudhës [S, E]".
 *
 * Plani i ditëve është burimi i orarit: çdo datë nga S te E ka orët e veta,
 * 0–8 (0 = pa mësim). Ruhet i plotë — asnjë datë nuk mungon, që të mos ketë
 * paqartësi. Formati: ['2026-10-01' => 8, '2026-10-02' => 7, '2026-10-03' => 0, …].
 *
 * Rregullat (i kontrollon qta_sched_fixed_issues() dhe sërish, pa u mbështetur
 * te builder-i, qta_sched_verify_fixed_range()):
 *   S ≤ data ≤ E për çdo datë të planit, dhe çdo datë e [S, E] ka orët e veta;
 *   0 ≤ orët ≤ 8 për çdo datë; 1 ≤ orët ≤ 8 për çdo ditë mësimi;
 *   orët(S) ≥ 1 dhe orët(E) ≥ 1 — kufijtë historikë janë ditë mësimi;
 *   Σ orët = orët e kursit (të kopjes së temave);
 *   temat ndahen në radhën e kursit me të njëjtin algoritëm si orari i
 *   llogaritur (qta_sched_allocate).
 *
 * Funksione të pastra: nuk lexojnë databazën dhe nuk varen nga ora e serverit.
 */

require_once __DIR__ . '/schedule.php';

/** Versioni i propozimit automatik; ruhet te çdo konvertim. */
const QTA_FIXED_ALGORITHM_VERSION = 'fixed-range-1';

/* ============================================================ Ndihmës */

if (!function_exists('qta_sched_dates')) {
  /** Të gjitha datat nga $start te $end, përfshirë të dyja. */
  function qta_sched_dates(string $start, string $end): array
  {
    $out = [];
    $d = $start;
    while ($d <= $end) {
      if (count($out) >= QTA_SCHEDULE_MAX_CALENDAR_DAYS) {
        throw new QtaUserError('Periudha është më e gjatë se 10 vjet. Kontrollo datat e grupit.');
      }
      $out[] = $d;
      $d = qta_sched_next_day($d);
    }
    return $out;
  }

  /** "05.10.2026" */
  function qta_sched_dmy(string $iso): string
  {
    return qta_sched_date($iso)->format('d.m.Y');
  }

  /** "01.10.2026–10.10.2026" */
  function qta_sched_range_label(string $start, string $end): string
  {
    return qta_sched_dmy($start) . '–' . qta_sched_dmy($end);
  }

  /** "1 orë mungon" / "7 orë mungojnë" — folja ndjek numrin. */
  function qta_sched_hours_phrase(int $n, string $one, string $many): string
  {
    return sprintf($n === 1 ? $one : $many, $n);
  }

  /**
   * Zgjedh $k elemente nga $items (në radhë), të shpërndara njëtrajtësisht, me
   * të parin dhe të fundit gjithmonë brenda (për $k ≥ 2; $k = 1 → i pari).
   *
   * Rregulli i barazimit (i dokumentuar dhe i testuar): indeksi i elementit j
   * është round(j · (m − 1) / (k − 1)), me gjysmën lart, i llogaritur me numra të
   * plotë: ⌊(2j(m − 1) + (k − 1)) / (2(k − 1))⌋. Për k ≤ m indekset rriten
   * rreptësisht, prandaj asnjë element nuk zgjidhet dy herë.
   */
  function qta_sched_spread(array $items, int $k): array
  {
    $items = array_values($items);
    $m = count($items);
    if ($k >= $m) return $items;
    if ($k <= 0) return [];
    if ($k === 1) return [$items[0]];
    $out = [];
    for ($j = 0; $j < $k; $j++) {
      $out[] = $items[intdiv(2 * $j * ($m - 1) + ($k - 1), 2 * ($k - 1))];
    }
    return $out;
  }

  /**
   * Zgjedh $k elemente nga $items, të përqendruara në mes të pjesëve të barabarta
   * (pa i shtyrë drejt skajeve): indeksi j = ⌊(2j + 1) · m / (2k)⌋.
   * Përdoret për të dielat e shtuara dhe për ditët që aktivizohen gjatë rishpërndarjes.
   */
  function qta_sched_spread_centered(array $items, int $k): array
  {
    $items = array_values($items);
    $m = count($items);
    if ($k >= $m) return $items;
    if ($k <= 0) return [];
    $out = [];
    for ($j = 0; $j < $k; $j++) {
      $out[] = $items[intdiv((2 * $j + 1) * $m, 2 * $k)];
    }
    return $out;
  }
}

/* =========================================================== Mundësia */

if (!function_exists('qta_sched_fixed_feasibility')) {
  /**
   * A mund t'i mbajë periudha [S, E] orët e kursit me të shumtën 8 orë në ditë?
   * Kapaciteti absolut = ditët kalendarike × 8; kur S ≠ E duhen të paktën 2 orë
   * (një në secilin kufi). Kthen edhe sa ditë mësimi duhen (⌈H / 8⌉) dhe sa të
   * diela të brendshme duhen patjetër kur ditët e tjera nuk mjaftojnë.
   *
   * @return array{ok:bool,code:?string,reason:?string,calendar_days:int,capacity:int,required_days:int,eligible_days:int,sundays_needed:int}
   */
  function qta_sched_fixed_feasibility(int $hours, string $start, string $end): array
  {
    $out = ['ok' => false, 'code' => null, 'reason' => null, 'calendar_days' => 0, 'capacity' => 0,
            'required_days' => 0, 'eligible_days' => 0, 'sundays_needed' => 0];
    if (!qta_sched_is_iso_date($start) || !qta_sched_is_iso_date($end)) {
      return ['code' => 'bad_dates', 'reason' => 'Grupi nuk ka datë fillimi ose mbarimi të vlefshme. Korrigjoje te "Regjistri i vjetër i kurseve profesionale".'] + $out;
    }
    if ($end < $start) {
      return ['code' => 'end_before_start', 'reason' => 'Mbarimi i grupit (' . qta_sched_dmy($end) . ') është para fillimit (' . qta_sched_dmy($start) . '). Korrigjoje te "Regjistri i vjetër i kurseve profesionale".'] + $out;
    }
    try {
      $dates = qta_sched_dates($start, $end);
    } catch (QtaUserError $e) {
      return ['code' => 'too_long', 'reason' => $e->getMessage()] + $out;
    }
    $n = count($dates);
    $capacity = $n * QTA_DAY_MAX_HOURS;
    $out['calendar_days'] = $n;
    $out['capacity'] = $capacity;
    $range = qta_sched_range_label($start, $end);
    if ($hours < 1) {
      return ['code' => 'no_hours', 'reason' => 'Kursi nuk ka orë mësimi. Plotësoje te "Katalogu i kurseve".'] + $out;
    }
    if ($hours > $capacity) {
      return ['code' => 'capacity',
              'reason' => 'Kursi ka ' . $hours . ' orë, por periudha ' . $range . ' ka vetëm ' . $n . ($n === 1 ? ' ditë' : ' ditë kalendarike')
                . '. Me të shumtën ' . QTA_DAY_MAX_HOURS . ' orë në ditë aty zënë ' . $capacity . ' orë — '
                . ($hours - $capacity) . ' orë më pak se kursi. Me këto data dhe këto orë grupi nuk mund të konvertohet.'] + $out;
    }
    if ($start !== $end && $hours < 2) {
      return ['code' => 'boundaries',
              'reason' => 'Kursi ka vetëm 1 orë, por dita e parë dhe e fundit e periudhës ' . $range . ' duhet të kenë mësim. Me këto data grupi nuk mund të konvertohet.'] + $out;
    }
    $required = max((int)ceil($hours / QTA_DAY_MAX_HOURS), $start === $end ? 1 : 2);
    $eligible = 0;
    foreach ($dates as $d) {
      if ($d === $start || $d === $end || !qta_sched_is_sunday($d)) $eligible++;
    }
    return ['ok' => true, 'required_days' => $required, 'eligible_days' => $eligible,
            'sundays_needed' => max(0, $required - $eligible)] + $out;
  }
}

/* ============================================= Kontrolli i planit (hyrjet) */

if (!function_exists('qta_sched_fixed_issues')) {
  /**
   * Çfarë nuk shkon në një plan ditësh, me fjalë, pa hedhur gabim. E vetmja
   * listë rregullash për planin: e përdorin builder-i (hedh të parin), ndërfaqja
   * (i tregon të gjitha) dhe konvertimi.
   *
   * @param array<string,mixed> $dayHours
   * @return array<int,array{code:string,text:string,date?:string}>
   */
  function qta_sched_fixed_issues(array $dayHours, int $hours, string $start, string $end): array
  {
    if (!qta_sched_is_iso_date($start) || !qta_sched_is_iso_date($end)) {
      return [['code' => 'bad_dates', 'text' => 'Data e fillimit ose e mbarimit nuk është e vlefshme.']];
    }
    if ($end < $start) {
      return [['code' => 'end_before_start', 'text' => 'Mbarimi (' . qta_sched_dmy($end) . ') është para fillimit (' . qta_sched_dmy($start) . ').']];
    }
    $dates = qta_sched_dates($start, $end);
    $range = qta_sched_range_label($start, $end);
    $issues = [];

    $shape = [];
    foreach ($dayHours as $date => $h) {
      $date = (string)$date;
      if (!qta_sched_is_iso_date($date)) {
        $shape[] = ['code' => 'bad_date', 'text' => 'Plani ka një datë të pavlefshme. Rifresko faqen dhe provo sërish.'];
      } elseif ($date < $start || $date > $end) {
        $shape[] = ['code' => 'outside', 'date' => $date, 'text' => 'Data ' . qta_sched_dmy($date) . ' është jashtë periudhës historike ' . $range . '.'];
      } elseif (!is_int($h) || $h < 0) {
        $shape[] = ['code' => 'bad_hours', 'date' => $date, 'text' => 'Orët e datës ' . qta_sched_dmy($date) . ' duhet të jenë një numër i plotë nga 0 deri në ' . QTA_DAY_MAX_HOURS . '.'];
      }
    }
    foreach ($dates as $d) {
      if (!array_key_exists($d, $dayHours)) {
        $shape[] = ['code' => 'missing_date', 'date' => $d, 'text' => 'Plani nuk ka orët e datës ' . qta_sched_dmy($d) . '. Çdo datë e periudhës ka orët e veta (0 = pa mësim).'];
      }
    }
    if ($shape) {
      return $shape;
    }

    foreach ($dates as $d) {
      $h = (int)$dayHours[$d];
      if ($h > QTA_DAY_MAX_HOURS) {
        $issues[] = ['code' => 'day_over', 'date' => $d,
                     'text' => 'Më ' . qta_sched_dmy($d) . ' janë vendosur ' . $h . ' orë. Një ditë mund të ketë maksimumi ' . QTA_DAY_MAX_HOURS . ' orë.'];
      }
    }
    if ((int)$dayHours[$start] < 1) {
      $issues[] = ['code' => 'start_off', 'date' => $start,
                   'text' => 'Dita e parë, ' . qta_sched_day_label($start) . ', duhet të ketë mësim: është data historike e fillimit të grupit.'];
    }
    if ($end !== $start && (int)$dayHours[$end] < 1) {
      $issues[] = ['code' => 'end_off', 'date' => $end,
                   'text' => 'Dita e fundit, ' . qta_sched_day_label($end) . ', duhet të ketë mësim: është data historike e mbarimit të grupit.'];
    }
    $sum = 0;
    foreach ($dates as $d) $sum += (int)$dayHours[$d];
    if ($sum < $hours) {
      $gap = $hours - $sum;
      $issues[] = ['code' => 'missing_hours',
                   'text' => qta_sched_hours_phrase($gap, 'Mungon %d orë mësimi', 'Mungojnë %d orë mësimi') . ' (' . $sum . ' nga ' . $hours . '). Shpërndaji brenda periudhës ' . $range . '.'];
    } elseif ($sum > $hours) {
      $over = $sum - $hours;
      $issues[] = ['code' => 'extra_hours',
                   'text' => qta_sched_hours_phrase($over, 'Është vendosur %d orë më shumë', 'Janë vendosur %d orë më shumë') . ' se orët e kursit (' . $sum . ' nga ' . $hours . '). Hiqi nga ndonjë ditë brenda periudhës ' . $range . '.'];
    }
    return $issues;
  }

  /**
   * Përmbledhja e një plani për ndërfaqen: orët e vendosura, ditët, të dielat e
   * përdorura dhe problemet. 'ok' = gati për konvertim nga ana e orarit.
   *
   * @return array{planned:int,hours:int,teaching_days:int,off_days:int,sundays:string[],boundary_sundays:string[],max_day:int,issues:array,ok:bool}
   */
  function qta_sched_fixed_summary(array $dayHours, int $hours, string $start, string $end): array
  {
    $issues = qta_sched_fixed_issues($dayHours, $hours, $start, $end);
    $planned = 0; $teaching = 0; $off = 0; $max = 0;
    $sundays = []; $boundarySundays = [];
    foreach ($dayHours as $d => $h) {
      $d = (string)$d;
      $h = is_int($h) ? $h : 0;
      $planned += $h;
      $max = max($max, $h);
      if ($h > 0) $teaching++; else $off++;
      if (qta_sched_is_iso_date($d) && qta_sched_is_sunday($d)) {
        if ($d === $start || $d === $end) $boundarySundays[] = $d;
        elseif ($h > 0) $sundays[] = $d;
      }
    }
    sort($sundays);
    return ['planned' => $planned, 'hours' => $hours, 'teaching_days' => $teaching, 'off_days' => $off,
            'sundays' => $sundays, 'boundary_sundays' => $boundarySundays, 'max_day' => $max,
            'issues' => $issues, 'ok' => !$issues];
  }
}

/* ======================================================== Ndërtimi */

if (!function_exists('qta_sched_build_fixed_range')) {
  /**
   * Temat + plani i ditëve → orari. Hedh QtaUserError me problemin e parë kur
   * plani nuk i mban rregullat; përndryshe ndan temat në radhë nëpër ditët me
   * orë (qta_sched_allocate) dhe kthen të njëjtën strukturë si qta_sched_build().
   * start_date dhe end_date janë gjithmonë datat historike S dhe E.
   *
   * @param array<int,array<string,mixed>> $topics  në radhë
   * @param array<string,int>              $dayHours çdo datë e [S, E]
   */
  function qta_sched_build_fixed_range(array $topics, string $start, string $end, array $dayHours): array
  {
    $topics = array_values($topics);
    $total = qta_sched_validate_topics($topics);
    $issues = qta_sched_fixed_issues($dayHours, $total, $start, $end);
    if ($issues) {
      throw new QtaUserError($issues[0]['text'], ['code' => $issues[0]['code'], 'issues' => $issues]);
    }
    $teaching = [];
    foreach (qta_sched_dates($start, $end) as $d) {
      $h = (int)$dayHours[$d];
      if ($h > 0) {
        $teaching[] = ['date' => $d, 'hours' => $h, 'capacity' => $h];
      }
    }
    return [
      'mode'        => 'fixed_range',
      'start_date'  => $start,
      'end_date'    => $end,
      'total_hours' => $total,
      'days'        => qta_sched_allocate($topics, $teaching),
    ];
  }
}

/* ===================================================== Kontrolli i pavarur */

if (!function_exists('qta_sched_verify_fixed_range')) {
  /**
   * Kontroll i pavarur i një orari me data historike. Nuk i beson builder-it:
   * rikontrollon ndarjen e temave (qta_sched_verify_allocation) dhe, veç saj,
   * kufijtë e palëvizshëm, periudhën, planin e plotë, ndjekjen e planit dhe
   * shumat. Kthen listën e shkeljeve (bosh = e saktë).
   *
   * @param array<int,array<string,mixed>> $topics
   * @param array<string,mixed>            $plan
   * @param array<string,mixed>            $dayHours
   * @return string[]
   */
  function qta_sched_verify_fixed_range(array $topics, array $plan, string $start, string $end, array $dayHours): array
  {
    $v = qta_sched_verify_allocation($topics, $plan);
    if (!qta_sched_is_iso_date($start) || !qta_sched_is_iso_date($end) || $end < $start) {
      $v[] = 'periudha historike është e pavlefshme: ' . $start . ' – ' . $end;
      return $v;
    }
    if (($plan['start_date'] ?? null) !== $start) {
      $v[] = 'fillimi i orarit (' . ($plan['start_date'] ?? '—') . ') nuk është fillimi historik (' . $start . ')';
    }
    if (($plan['end_date'] ?? null) !== $end) {
      $v[] = 'mbarimi i orarit (' . ($plan['end_date'] ?? '—') . ') nuk është mbarimi historik (' . $end . ')';
    }
    $days = $plan['days'] ?? [];
    if ($days) {
      if ((string)$days[0]['date'] !== $start) {
        $v[] = 'dita e parë e mësimit (' . $days[0]['date'] . ') nuk është fillimi historik';
      }
      if ((string)$days[count($days) - 1]['date'] !== $end) {
        $v[] = 'dita e fundit e mësimit (' . $days[count($days) - 1]['date'] . ') nuk është mbarimi historik';
      }
    }

    $dates = qta_sched_dates($start, $end);
    $inRange = array_flip($dates);
    $planSum = 0;
    foreach ($dayHours as $d => $h) {
      $d = (string)$d;
      if (!isset($inRange[$d])) {
        $v[] = 'plani ka një datë jashtë periudhës: ' . $d;
      }
      if (!is_int($h) || $h < 0 || $h > QTA_DAY_MAX_HOURS) {
        $v[] = 'plani ka orë të pavlefshme më ' . $d;
      } else {
        $planSum += $h;
      }
    }
    foreach ($dates as $d) {
      if (!array_key_exists($d, $dayHours)) {
        $v[] = 'plani nuk ka datën ' . $d;
      }
    }
    if ((int)($dayHours[$start] ?? 0) < 1) {
      $v[] = 'dita e fillimit nuk ka mësim';
    }
    if ((int)($dayHours[$end] ?? 0) < 1) {
      $v[] = 'dita e mbarimit nuk ka mësim';
    }

    /* Orari ndjek planin: çdo ditë mësimi me orët e planit, asnjë ditë me orë e anashkaluar. */
    $seen = [];
    foreach ($days as $day) {
      $d = (string)$day['date'];
      if (isset($seen[$d])) {
        $v[] = 'data përsëritet: ' . $d;
      }
      $seen[$d] = true;
      if (!isset($inRange[$d])) {
        $v[] = 'ditë mësimi jashtë periudhës historike: ' . $d;
      } elseif ((int)($dayHours[$d] ?? -1) !== (int)$day['hours']) {
        $v[] = 'dita ' . $d . ' ka ' . (int)$day['hours'] . ' orë, plani ' . var_export($dayHours[$d] ?? null, true);
      }
    }
    foreach ($dates as $d) {
      if ((int)($dayHours[$d] ?? 0) > 0 && !isset($seen[$d])) {
        $v[] = 'dita ' . $d . ' ka orë në plan, por mungon në orar';
      }
    }
    $total = 0;
    foreach ($topics as $t) $total += (int)$t['hours'];
    if ($planSum !== $total) {
      $v[] = 'shuma e planit ' . $planSum . ' ≠ orët e kursit ' . $total;
    }
    return $v;
  }
}

/* ================================================ Propozimi automatik */

if (!function_exists('qta_sched_propose_fixed_range')) {
  /**
   * Propozimi automatik i orëve brenda periudhës historike. Përcaktues: të
   * njëjtat H, S, E japin gjithmonë të njëjtin propozim. Nuk është e vërteta
   * historike — është një rindërtim i vlefshëm që njeriu e shqyrton.
   *
   * 1. Ditët e nevojshme: k = ⌈H / 8⌉, të paktën 2 kur S ≠ E.
   * 2. Ditët e preferuara: e hëna–e shtuna, plus S dhe E edhe kur janë të diela
   *    (janë data historike, prandaj lejohen).
   * 3. Kur ka të paktën k të tilla, zgjidhen k, njëtrajtësisht në gjithë periudhën
   *    dhe me S e E brenda (qta_sched_spread: round(j·(m−1)/(k−1)), gjysma lart).
   * 4. Përndryshe merren të gjitha dhe vetëm aq të diela të brendshme sa mungojnë,
   *    të përqendruara njëtrajtësisht mes tyre (qta_sched_spread_centered). Ato
   *    kthehen te 'sundays' që ndërfaqja t'i shënojë për kontroll.
   * 5. Orët: çdo ditë e zgjedhur merr ⌊H / k⌋; r = H mod k prej tyre marrin +1.
   *    Ato r ditë zgjidhen me qta_sched_spread mbi ditët e zgjedhura (r = 1 → dita
   *    e parë). Prandaj max − min ≤ 1 dhe asnjë ditë nuk kalon 8.
   *
   * @return array{days:array<string,int>,sundays:string[],teaching_days:int}
   */
  function qta_sched_propose_fixed_range(int $hours, string $start, string $end): array
  {
    $f = qta_sched_fixed_feasibility($hours, $start, $end);
    if (!$f['ok']) {
      throw new QtaUserError((string)$f['reason'], ['code' => $f['code']]);
    }
    $dates = qta_sched_dates($start, $end);
    $k = $f['required_days'];
    $eligible = [];
    $interiorSundays = [];
    foreach ($dates as $d) {
      if ($d === $start || $d === $end || !qta_sched_is_sunday($d)) $eligible[] = $d;
      else $interiorSundays[] = $d;
    }
    $sundays = [];
    if ($k <= count($eligible)) {
      $selected = qta_sched_spread($eligible, $k);
    } else {
      $sundays = qta_sched_spread_centered($interiorSundays, $k - count($eligible));
      $selected = array_merge($eligible, $sundays);
      sort($selected);
    }
    $base = intdiv($hours, $k);
    $plusOne = array_flip(qta_sched_spread(range(0, $k - 1), $hours % $k));
    $plan = array_fill_keys($dates, 0);
    foreach (array_values($selected) as $i => $d) {
      $plan[$d] = $base + (isset($plusOne[$i]) ? 1 : 0);
    }
    return ['days' => $plan, 'sundays' => $sundays, 'teaching_days' => $k];
  }
}

/* ======================================================= Rishpërndarja */

if (!function_exists('qta_sched_rebalance_fixed_range')) {
  /**
   * Vendos orët që mungojnë (ose heq ato që tepërojnë) brenda [S, E], pa lëvizur
   * kufijtë, pa kaluar 8 orë në ditë dhe pa prekur ditët e caktuara me dorë kur
   * ka një rrugë tjetër.
   *
   * Kur mungojnë orë, marrin orë me këtë radhë:
   *   1. ditët me mësim (jo të diela të brendshme) që kanë vend — së pari ato me
   *      më pak orë, një orë në një kohë, më e hershmja së pari;
   *   2. ditët pa mësim (jo të diela) — aq sa duhen, të shpërndara njëtrajtësisht;
   *   3. të dielat me mësim, pastaj të dielat pa mësim — vetëm kur duhen;
   *   4. vetëm në fund, ditët e caktuara me dorë (thirrësi e tregon).
   * Kur tepërojnë orë: së pari zbrazen të dielat e brendshme (ajo me më pak orë
   * së pari), pastaj ulen ditët me më shumë orë (më e vona së pari), në fund ditët
   * e caktuara me dorë. S dhe E mbeten me të paktën 1 orë.
   *
   * @param array<string,int> $dayHours  çdo datë e [S, E]
   * @param string[]          $manual    datat e caktuara me dorë
   * @return array{days:array<string,int>,delta:int,changed:string[],sundays_added:string[],manual_changed:string[]}
   */
  function qta_sched_rebalance_fixed_range(array $dayHours, int $hours, string $start, string $end, array $manual = []): array
  {
    $f = qta_sched_fixed_feasibility($hours, $start, $end);
    if (!$f['ok']) {
      throw new QtaUserError((string)$f['reason'], ['code' => $f['code']]);
    }
    $dates = qta_sched_dates($start, $end);
    $plan = [];
    foreach ($dates as $d) {
      $h = $dayHours[$d] ?? null;
      if (!is_int($h) || $h < 0 || $h > QTA_DAY_MAX_HOURS) {
        throw new QtaUserError('Plani nuk është i plotë ose ka orë të pavlefshme. Rifresko faqen dhe provo sërish.', ['code' => 'bad_plan']);
      }
      $plan[$d] = $h;
    }
    $original = $plan;
    $isManual = array_fill_keys(array_map('strval', $manual), true);
    $bound = [$start => true, $end => true];
    $sundayInside = static fn(string $d): bool => !isset($bound[$d]) && qta_sched_is_sunday($d);

    /* Kufijtë historikë kanë gjithmonë mësim. */
    foreach ($bound as $d => $_) {
      if ($plan[$d] < 1) $plan[$d] = 1;
    }
    $need = $hours - array_sum($plan);
    $delta = $hours - array_sum($original);

    /* Mbush ditët me mësim, nivel pas niveli (më e ulëta së pari, më e hershmja së pari). */
    $fill = static function (array $cands) use (&$plan, &$need): void {
      for ($level = 1; $level <= QTA_DAY_MAX_HOURS && $need > 0; $level++) {
        foreach ($cands as $d) {
          if ($need === 0) return;
          if ($plan[$d] < $level) { $plan[$d]++; $need--; }
        }
      }
    };
    /* Aktivizon aq ditë pa mësim sa duhen, të shpërndara, dhe i mbush njësoj. */
    $activate = static function (array $cands) use (&$need, $fill): array {
      if ($need <= 0 || !$cands) return [];
      $picked = qta_sched_spread_centered($cands, min(count($cands), (int)ceil($need / QTA_DAY_MAX_HOURS)));
      $fill($picked);
      return $picked;
    };
    /* Ul ditët, nivel pas niveli (më e larta së pari, më e vona së pari), jo nën kufirin e tyre. */
    $drain = static function (array $cands) use (&$plan, &$need, $bound): void {
      $cands = array_reverse($cands);
      for ($level = QTA_DAY_MAX_HOURS - 1; $level >= 0 && $need < 0; $level--) {
        foreach ($cands as $d) {
          if ($need === 0) return;
          $floor = isset($bound[$d]) ? 1 : 0;
          if ($plan[$d] > $level && $plan[$d] > $floor) { $plan[$d]--; $need++; }
        }
      }
    };

    $pick = static function (callable $test) use ($dates): array {
      return array_values(array_filter($dates, $test));
    };

    if ($need > 0) {
      $fill($pick(fn($d) => !isset($isManual[$d]) && !$sundayInside($d) && $plan[$d] > 0 && $plan[$d] < QTA_DAY_MAX_HOURS));
      $activate($pick(fn($d) => !isset($isManual[$d]) && !$sundayInside($d) && $plan[$d] === 0));
      $fill($pick(fn($d) => !isset($isManual[$d]) && $sundayInside($d) && $plan[$d] > 0 && $plan[$d] < QTA_DAY_MAX_HOURS));
      $activate($pick(fn($d) => !isset($isManual[$d]) && $sundayInside($d) && $plan[$d] === 0));
      $fill($pick(fn($d) => isset($isManual[$d]) && $plan[$d] > 0 && $plan[$d] < QTA_DAY_MAX_HOURS));
      $activate($pick(fn($d) => isset($isManual[$d]) && $plan[$d] === 0));
    } elseif ($need < 0) {
      /* Të dielat e brendshme zbrazen së pari, ajo me më pak orë së pari (barazim: më e vona). */
      $suns = $pick(fn($d) => !isset($isManual[$d]) && $sundayInside($d) && $plan[$d] > 0);
      usort($suns, static fn($a, $b) => [$plan[$a], $b] <=> [$plan[$b], $a]);
      foreach ($suns as $d) {
        while ($need < 0 && $plan[$d] > 0) { $plan[$d]--; $need++; }
      }
      $drain($pick(fn($d) => !isset($isManual[$d]) && !$sundayInside($d)));
      $drain($pick(fn($d) => isset($isManual[$d])));
    }
    if ($need !== 0) {
      /* Me kapacitetin e kontrolluar më sipër nuk ndodh; mbrojtje për çdo rast. */
      throw new QtaUserError('Orët nuk mund të rishpërndahen brenda periudhës ' . qta_sched_range_label($start, $end) . ' pa kaluar ' . QTA_DAY_MAX_HOURS . ' orë në ditë.', ['code' => 'no_room']);
    }

    $changed = [];
    $sundaysAdded = [];
    $manualChanged = [];
    foreach ($dates as $d) {
      if ($plan[$d] === $original[$d]) continue;
      $changed[] = $d;
      if ($sundayInside($d) && $original[$d] === 0) $sundaysAdded[] = $d;
      if (isset($isManual[$d])) $manualChanged[] = $d;
    }
    return ['days' => $plan, 'delta' => $delta, 'changed' => $changed, 'sundays_added' => $sundaysAdded, 'manual_changed' => $manualChanged];
  }
}
