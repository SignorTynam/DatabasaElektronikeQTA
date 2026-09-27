<?php
declare(strict_types=1);

/**
 * Motori i orarit — rregullat e biznesit dhe rastet e pranimit (B, C, E).
 */

require_once __DIR__ . '/../../app/shared/schedule.php';

/* Zona e serverit nuk duhet të ndikojë te ditët e javës (UTC brenda motorit). */
date_default_timezone_set('Europe/Tirane');

/**
 * Kurs me module të dhëna si [orët e temave të modulit 1], [… moduli 2] …
 * @param array<int,int[]> $modules
 */
function sched_topics(array $modules, array $titles = []): array
{
  $out = [];
  $seq = 0;
  foreach ($modules as $mi => $topicHours) {
    $mh = array_sum($topicHours);
    foreach ($topicHours as $ti => $h) {
      $out[] = [
        'seq' => ++$seq, 'module_seq' => $mi + 1, 'module_hours' => $mh,
        'module_title' => $titles[$mi] ?? ('Moduli ' . ($mi + 1)),
        'topic_seq' => $ti + 1, 'topic_title' => 'Tema ' . ($ti + 1), 'hours' => $h,
      ];
    }
  }
  return $out;
}

function sched_day(array $plan, string $date): ?array
{
  foreach ($plan['days'] as $d) {
    if ($d['date'] === $date) return $d;
  }
  return null;
}

/** [[topicSeq, hours], …] e një dite */
function sched_pairs(array $day): array
{
  return array_map(static fn($s) => [$s['topic'], $s['hours']], $day['slots']);
}

$WORD = [2, 2, 2, 2, 1, 1]; // Moduli Word: 10 orë

t_case('Kalendari: ditët e javës llogariten nga data', function () {
  t_eq(4, qta_sched_weekday('2026-10-01'), '01.10.2026 është e enjte');
  t_eq(7, qta_sched_weekday('2026-10-11'), '11.10.2026 është e diel');
  t_eq(1, qta_sched_weekday('2026-10-12'), '12.10.2026 është e hënë');
  t_eq(7, qta_sched_weekday('2026-10-25'), '25.10.2026 (ndërrimi i orës) është e diel');
  t_eq('2028-02-29', qta_sched_next_day('2028-02-28'), 'viti i brishtë');
  t_eq('2026-10-26', qta_sched_next_day('2026-10-25'), 'ndërrimi i orës nuk humb ditë');
  t_eq('2027-01-01', qta_sched_next_day('2026-12-31'), 'kalimi i vitit');
});

t_case('Pranimi B — tema ndahet mes dy ditëve (5 orë në ditë)', function () use ($WORD) {
  $plan = qta_sched_build(sched_topics([$WORD]), '2026-10-01', 5);
  t_eq(2, count($plan['days']), 'Word (10 orë) zgjat dy ditë');
  t_eq([[1, 2], [2, 2], [3, 1]], sched_pairs($plan['days'][0]), 'Dita 1: T1 2 orë, T2 2 orë, T3 1 orë');
  t_eq([[3, 1], [4, 2], [5, 1], [6, 1]], sched_pairs($plan['days'][1]), 'Dita 2 nis me orën e mbetur të T3');
  t_eq(5, $plan['days'][0]['hours'], 'Dita 1 nuk kalon 5 orë');
  t_eq([], qta_sched_verify(sched_topics([$WORD]), $plan, 5, []), 'të gjitha rregullat mbahen');

  $ann = qta_sched_annotate(sched_topics([$WORD]), $plan['days']);
  $t3a = $ann[0]['slots'][2];
  $t3b = $ann[1]['slots'][0];
  t_eq([1, 1, true], [$t3a['from'], $t3a['to'], $t3a['is_split']], 'T3 pjesa e parë: ora 1 nga 2');
  t_eq([2, 2, true], [$t3b['from'], $t3b['to'], $t3b['is_split']], 'T3 pjesa e dytë: ora 2 nga 2');
});

t_case('Pranimi C — kurs 100 orë nga 01.10.2026, 5 orë në ditë, 11.10 (e diel) 4 orë', function () use ($WORD) {
  $topics = sched_topics(array_fill(0, 5, array_merge($WORD, $WORD))); // 5 module × 20 orë
  t_eq(100, array_sum(array_column($topics, 'hours')), 'kursi ka 100 orë');
  $rules = ['2026-10-11' => 4];
  $plan = qta_sched_build($topics, '2026-10-01', 5, $rules);

  t_eq('2026-10-23', $plan['end_date'], 'mbaron më 23.10.2026');
  $last = $plan['days'][count($plan['days']) - 1];
  t_eq(1, $last['hours'], 'dita e fundit ka 1 orë');
  t_eq(99, array_sum(array_column(array_slice($plan['days'], 0, -1), 'hours')), 'para ditës së fundit janë 99 orë');
  t_eq(4, sched_day($plan, '2026-10-11')['hours'] ?? null, 'e diela 11.10 është ditë mësimi me 4 orë');
  t_eq(null, sched_day($plan, '2026-10-04'), 'e diela 04.10 nuk ka mësim');
  t_eq(null, sched_day($plan, '2026-10-18'), 'e diela 18.10 nuk ka mësim');
  t_eq(5, sched_day($plan, '2026-10-12')['hours'] ?? null, 'e hëna 12.10 ka 5 orë');
  t_eq(21, count($plan['days']), '21 ditë mësimi');
  t_eq([], qta_sched_verify($topics, $plan, 5, $rules), 'të gjitha rregullat mbahen');

  /* Pa rregullin: 11.10 është e diel e zakonshme, pa mësim. */
  $plain = qta_sched_build($topics, '2026-10-01', 5);
  t_eq(null, sched_day($plain, '2026-10-11'), 'pa rregull, 11.10 nuk ka mësim');
  t_eq('2026-10-23', $plain['end_date'], 'pa rregull: 20 ditë × 5 orë, mbaron 23.10');
  t_eq(5, $plain['days'][19]['hours'], 'pa rregull dita e fundit është e plotë');
});

t_case('Pranimi E — rillogaritje: 5 → 4 orë në një datë, pastaj kthim', function () use ($WORD) {
  $topics = sched_topics(array_fill(0, 5, array_merge($WORD, $WORD)));
  $base = ['2026-10-11' => 4];
  $before = qta_sched_build($topics, '2026-10-01', 5, $base);

  $changed = $base + ['2026-10-06' => 4];
  $after = qta_sched_build($topics, '2026-10-01', 5, $changed);
  t_eq([], qta_sched_verify($topics, $after, 5, $changed), 'orari i ri mban të gjitha rregullat');
  t_eq(4, sched_day($after, '2026-10-06')['hours'], '06.10 ka tani 4 orë');
  t_eq(100, array_sum(array_column($after['days'], 'hours')), 'totali mbetet 100');
  t_eq('2026-10-23', $after['end_date'], 'mbarimi mbetet 23.10');
  t_eq(2, $after['days'][count($after['days']) - 1]['hours'], 'dita e fundit merr orën e humbur (2 orë)');
  $diff = qta_sched_diff($before['days'], $after['days']);
  t_eq('2026-10-06', $diff[0] ?? null, 'ndryshimi fillon te 06.10');
  t_ok(!in_array('2026-10-05', $diff, true), 'ditët para 06.10 nuk ndryshojnë');

  /* Një ditë pa mësim zhvendos mbarimin. */
  $off = $base + ['2026-10-23' => 0];
  $moved = qta_sched_build($topics, '2026-10-01', 5, $off);
  t_eq('2026-10-24', $moved['end_date'], '23.10 pa mësim → mbaron të shtunën 24.10');
  t_eq([], qta_sched_verify($topics, $moved, 5, $off), 'rregullat mbahen');

  /* Kthimi prapa jep të njëjtin orar, bit për bit. */
  $restored = qta_sched_build($topics, '2026-10-01', 5, $base);
  t_eq($before, $restored, 'rikthimi i rregullit jep orarin e parë');
  t_eq([], qta_sched_diff($before['days'], $restored['days']), 'asnjë ndryshim pas kthimit');
});

t_case('Kufiri i modulit brenda ditës (4 orë në ditë)', function () use ($WORD) {
  $topics = sched_topics([$WORD, $WORD], ['Word', 'Excel']);
  $plan = qta_sched_build($topics, '2026-10-01', 4);
  $d3 = $plan['days'][2];
  t_eq([[5, 1], [6, 1], [7, 2]], sched_pairs($d3), 'Dita 3: Word mbaron (T5, T6) dhe Excel fillon (T1)');
  t_eq(4, $d3['hours'], 'asnjë orë nuk humbet kur mbaron moduli');
  $ann = qta_sched_annotate($topics, $plan['days']);
  t_ok($ann[2]['slots'][1]['module_ends'] === true, 'shënohet mbarimi i Word');
  t_ok($ann[2]['slots'][2]['module_starts'] === true, 'shënohet fillimi i Excel');
  $win = qta_sched_module_windows($topics, $plan['days']);
  t_eq(['2026-10-01', '2026-10-03'], [$win[0]['first_date'], $win[0]['last_date']], 'Word: 01.10 – 03.10');
  t_eq('2026-10-03', $win[1]['first_date'], 'Excel fillon 03.10');
  t_eq([], qta_sched_verify($topics, $plan, 4, []), 'rregullat mbahen');
});

t_case('E diela dhe rregullat e datave', function () use ($WORD) {
  $topics = sched_topics([$WORD, $WORD]);
  /* E diel e përfshirë me orarin e zakonshëm (null). */
  $plan = qta_sched_build($topics, '2026-10-01', 5, ['2026-10-04' => null]);
  t_eq(5, sched_day($plan, '2026-10-04')['hours'] ?? null, 'e diela 04.10 me orarin e zakonshëm');
  /* Ditë e zakonshme pa mësim (0). */
  $plan = qta_sched_build($topics, '2026-10-01', 5, ['2026-10-02' => 0]);
  t_eq(null, sched_day($plan, '2026-10-02'), '02.10 pa mësim');
  t_eq('2026-10-03', $plan['days'][1]['date'], 'vazhdon të shtunën');
  /* Rregull më i madh se orët që mbeten: dita e fundit merr vetëm sa mbeten. */
  $plan = qta_sched_build(sched_topics([[2, 2, 3]]), '2026-10-01', 5, ['2026-10-02' => 8]);
  t_eq(2, $plan['days'][1]['hours'], 'dita e fundit ka 2 orë, jo 8');
  t_eq(8, $plan['days'][1]['capacity'], 'kapaciteti i asaj dite mbetet 8');
  /* Rregullat para fillimit ose pas mbarimit nuk ndikojnë. */
  $a = qta_sched_build($topics, '2026-10-05', 5);
  $b = qta_sched_build($topics, '2026-10-05', 5, ['2026-10-01' => 0, '2027-01-10' => 3]);
  t_eq($a, $b, 'rregullat jashtë orarit nuk e ndryshojnë');
});

t_case('Gabimet e hyrjeve', function () use ($WORD) {
  $topics = sched_topics([$WORD]);
  t_throws(QtaUserError::class, fn() => qta_sched_build($topics, '2026-10-04', 5), 'fillimi të dielën refuzohet', 'nuk është ditë mësimi');
  t_throws(QtaUserError::class, fn() => qta_sched_build($topics, '2026-10-02', 5, ['2026-10-02' => 0]), 'fillimi në ditë pa mësim refuzohet', 'nuk është ditë mësimi');
  $ok = qta_sched_build($topics, '2026-10-04', 5, ['2026-10-04' => 3]);
  t_eq('2026-10-04', $ok['start_date'], 'fillimi të dielën lejohet kur e diela shënohet ditë mësimi');
  t_throws(QtaUserError::class, fn() => qta_sched_build($topics, '2026-10-01', 0), '0 orë në ditë refuzohet', 'nga 1 deri');
  t_throws(QtaUserError::class, fn() => qta_sched_build($topics, '2026-10-01', 13), '13 orë në ditë refuzohet', 'nga 1 deri');
  t_throws(QtaUserError::class, fn() => qta_sched_build($topics, '31.02.2026', 5), 'data e pavlefshme refuzohet', 'Data e fillimit');
  t_throws(QtaUserError::class, fn() => qta_sched_build($topics, '2026-02-30', 5), '30 shkurt refuzohet', 'Data e fillimit');
  t_throws(QtaUserError::class, fn() => qta_sched_build($topics, '2026-10-01', 5, ['2026-10-02' => 13]), 'rregull me 13 orë refuzohet', 'orë të pavlefshme');
  t_throws(QtaUserError::class, fn() => qta_sched_build($topics, '2026-10-01', 5, ['x' => 3]), 'rregull pa datë refuzohet', 'datë të pavlefshme');
  t_throws(QtaUserError::class, fn() => qta_sched_build([], '2026-10-01', 5), 'kurs pa tema refuzohet', 'nuk ka tema');
  $bad = $topics; $bad[2]['hours'] = 0;
  t_throws(QtaUserError::class, fn() => qta_sched_build($bad, '2026-10-01', 5), 'temë me 0 orë refuzohet', 'orë të pavlefshme');
  $dup = $topics; $dup[3]['seq'] = $dup[2]['seq'];
  t_throws(QtaUserError::class, fn() => qta_sched_build($dup, '2026-10-01', 5), 'radha e dyfishtë refuzohet', 'Radha');
  /* Të gjitha ditët pa mësim për 10 vjet → ndalet me mesazh, pa cikël të pafund. */
  $rules = [];
  $d = '2026-10-02';
  for ($i = 0; $i < 3800; $i++) { $rules[$d] = 0; $d = qta_sched_next_day($d); }
  t_throws(QtaUserError::class, fn() => qta_sched_build($topics, '2026-10-01', 5, $rules), 'orari pa fund ndalet', 'mbi 10 vjet');
});

t_case('Kontrolli i pavarur zbulon orar të prishur', function () use ($WORD) {
  $topics = sched_topics([$WORD, $WORD]);
  $plan = qta_sched_build($topics, '2026-10-01', 5);
  t_eq([], qta_sched_verify($topics, $plan, 5, []), 'orari i saktë');

  $lost = $plan; $lost['days'][0]['slots'][0]['hours'] = 1; $lost['days'][0]['hours'] = 4;
  t_ok(qta_sched_verify($topics, $lost, 5, []) !== [], 'orë e humbur zbulohet');

  $swap = $plan; [$swap['days'][0]['slots'][0], $swap['days'][0]['slots'][1]] = [$swap['days'][0]['slots'][1], $swap['days'][0]['slots'][0]];
  $swap['days'][0]['slots'][0]['seq'] = 1; $swap['days'][0]['slots'][1]['seq'] = 2;
  t_ok(qta_sched_verify($topics, $swap, 5, []) !== [], 'radhë e ndryshuar zbulohet');

  $sunday = $plan; $sunday['days'][2]['date'] = '2026-10-04';
  t_ok(qta_sched_verify($topics, $sunday, 5, []) !== [], 'mësim të dielën pa rregull zbulohet');

  $gap = $plan; foreach ($gap['days'] as $i => &$d) { if ($i >= 2) { $d['date'] = qta_sched_next_day($d['date']); } } unset($d);
  $gap['end_date'] = $gap['days'][count($gap['days']) - 1]['date'];
  t_ok(qta_sched_verify($topics, $gap, 5, []) !== [], 'ditë mësimi e anashkaluar zbulohet');

  $end = $plan; $end['end_date'] = '2026-12-31';
  t_ok(qta_sched_verify($topics, $end, 5, []) !== [], 'data e mbarimit që nuk përputhet zbulohet');
});

t_case('Përcaktueshmëria: të njëjtat hyrje, i njëjti orar', function () use ($WORD) {
  $topics = sched_topics([[3, 4, 5, 1], $WORD, [7]]);
  $rules = ['2026-10-11' => 4, '2026-10-14' => 0, '2026-10-18' => null, '2026-10-20' => 2];
  $a = qta_sched_build($topics, '2026-10-01', 6, $rules);
  $b = qta_sched_build($topics, '2026-10-01', 6, array_reverse($rules, true));
  t_eq($a, $b, 'rendi i rregullave nuk ndikon');
  t_eq([], qta_sched_verify($topics, $a, 6, $rules), 'rregullat mbahen');
});

t_case('Totale të mëdha dhe mbrojtja nga gabimet e rrumbullakimit', function () {
  $mods = [];
  for ($m = 0; $m < 12; $m++) { $mods[] = [7, 3, 11, 1, 5, 2, 9]; } // 38 × 12 = 456 orë
  $topics = sched_topics($mods);
  foreach ([1, 3, 5, 7, 12] as $daily) {
    $plan = qta_sched_build($topics, '2026-09-28', $daily, ['2026-11-01' => null, '2026-12-25' => 0]);
    t_eq([], qta_sched_verify($topics, $plan, $daily, ['2026-11-01' => null, '2026-12-25' => 0]), $daily . ' orë në ditë: rregullat mbahen');
    t_eq(456, array_sum(array_column($plan['days'], 'hours')), $daily . ' orë në ditë: totali 456');
  }
});
