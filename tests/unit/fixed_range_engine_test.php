<?php
declare(strict_types=1);

/**
 * Orari me data historike (grupet e konvertuara): ndërtimi, kontrolli i pavarur,
 * propozimi automatik dhe rishpërndarja.
 */

require_once __DIR__ . '/../../app/shared/schedule_fixed.php';

date_default_timezone_set('Europe/Tirane');

/** Kurs me module të dhëna si [orët e temave të modulit 1], [… moduli 2] … */
function fx_topics(array $modules): array
{
  $out = [];
  $seq = 0;
  foreach ($modules as $mi => $topicHours) {
    $mh = array_sum($topicHours);
    foreach ($topicHours as $ti => $h) {
      $out[] = ['seq' => ++$seq, 'module_seq' => $mi + 1, 'module_hours' => $mh, 'module_title' => 'Moduli ' . ($mi + 1),
                'topic_seq' => $ti + 1, 'topic_title' => 'Tema ' . ($ti + 1), 'hours' => $h];
    }
  }
  return $out;
}

/** Kurs me $hours orë: module me tema 2-orëshe (dhe një 1-orëshe kur duhet). */
function fx_course(int $hours): array
{
  $modules = [];
  $left = $hours;
  while ($left > 0) {
    $mh = min(10, $left);
    $topics = array_fill(0, intdiv($mh, 2), 2);
    if ($mh % 2) $topics[] = 1;
    $modules[] = $topics;
    $left -= $mh;
  }
  return fx_topics($modules);
}

/** Plani me orët e dhëna për datat e përmendura, 0 për të tjerat. */
function fx_plan(string $start, string $end, array $hours): array
{
  $plan = array_fill_keys(qta_sched_dates($start, $end), 0);
  foreach ($hours as $d => $h) $plan[$d] = $h;
  return $plan;
}

function fx_active(array $plan): array
{
  return array_values(array_filter($plan, static fn($h) => $h > 0));
}

$WORD = [2, 2, 2, 2, 1, 1];
$MSO50 = fx_topics(array_fill(0, 5, $WORD)); // Microsoft Office: 5 module × 10 orë

t_case('Fiks — shembulli 50 orë, 01.10.2026–10.10.2026 (i detyrueshëm)', function () use ($MSO50) {
  $p = qta_sched_propose_fixed_range(50, '2026-10-01', '2026-10-10');
  t_eq(['2026-10-01' => 8, '2026-10-02' => 7, '2026-10-03' => 0, '2026-10-04' => 0, '2026-10-05' => 7,
        '2026-10-06' => 7, '2026-10-07' => 7, '2026-10-08' => 0, '2026-10-09' => 7, '2026-10-10' => 7], $p['days'],
    'propozimi: 8, 7, –, – (e diel), 7, 7, 7, –, 7, 7');
  t_eq(50, array_sum($p['days']), '50 nga 50 orë');
  t_eq(7, $p['teaching_days'], '7 ditë mësimi = ⌈50 / 8⌉');
  t_eq([], $p['sundays'], 'asnjë e diel');
  $active = fx_active($p['days']);
  t_ok(max($active) - min($active) <= 1 && max($active) <= 8, 'të balancuara: 8 dhe 7');

  $plan = qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $p['days']);
  t_eq(['2026-10-01', '2026-10-10', 50, 'fixed_range'], [$plan['start_date'], $plan['end_date'], $plan['total_hours'], $plan['mode']], 'fillimi dhe mbarimi janë datat historike');
  t_eq('2026-10-01', $plan['days'][0]['date'], 'dita e parë = 01.10');
  t_eq('2026-10-10', $plan['days'][6]['date'], 'dita e fundit = 10.10');
  t_eq([], qta_sched_verify_fixed_range($MSO50, $plan, '2026-10-01', '2026-10-10', $p['days']), 'kontrolli i pavarur: pa shkelje');
  t_eq([], qta_sched_verify_allocation($MSO50, $plan), 'ndarja e temave: pa shkelje');
  $sum = qta_sched_fixed_summary($p['days'], 50, '2026-10-01', '2026-10-10');
  t_eq([50, 7, 3, true], [$sum['planned'], $sum['teaching_days'], $sum['off_days'], $sum['ok']], 'përmbledhja: 50 orë, 7 ditë, 3 pa mësim, gati');
});

t_case('Fiks — një ditë e vetme', function () {
  $topics = fx_topics([[2, 2, 2]]);
  $plan = qta_sched_build_fixed_range($topics, '2026-10-05', '2026-10-05', ['2026-10-05' => 6]);
  t_eq(1, count($plan['days']), 'një ditë mësimi');
  t_eq(['2026-10-05', '2026-10-05', 6], [$plan['start_date'], $plan['end_date'], $plan['days'][0]['hours']], 'S = E, 6 orë');
  t_eq([], qta_sched_verify_fixed_range($topics, $plan, '2026-10-05', '2026-10-05', ['2026-10-05' => 6]), 'e vlefshme');
  t_eq(['2026-10-05' => 6], qta_sched_propose_fixed_range(6, '2026-10-05', '2026-10-05')['days'], 'propozimi: gjithë kursi atë ditë');
  $f = qta_sched_fixed_feasibility(9, '2026-10-05', '2026-10-05');
  t_eq([false, 'capacity'], [$f['ok'], $f['code']], '9 orë në një ditë: e pamundur');
  t_throws(QtaUserError::class, fn() => qta_sched_propose_fixed_range(9, '2026-10-05', '2026-10-05'), 'propozimi refuzohet', 'vetëm 1 ditë');
});

t_case('Fiks — 8 orë çdo ditë dhe e diela kur duhet patjetër', function () {
  $p = qta_sched_propose_fixed_range(80, '2026-10-01', '2026-10-10');
  t_eq(array_fill_keys(qta_sched_dates('2026-10-01', '2026-10-10'), 8), $p['days'], '80 orë në 10 ditë: 8 orë çdo ditë');
  t_eq(['2026-10-04'], $p['sundays'], 'e diela 04.10 përdoret dhe shënohet për kontroll');
  $topics = fx_course(80);
  $plan = qta_sched_build_fixed_range($topics, '2026-10-01', '2026-10-10', $p['days']);
  t_eq([], qta_sched_verify_fixed_range($topics, $plan, '2026-10-01', '2026-10-10', $p['days']), 'orari i plotë është i vlefshëm');
  t_eq(['2026-10-04'], qta_sched_fixed_summary($p['days'], 80, '2026-10-01', '2026-10-10')['sundays'], 'përmbledhja e tregon të dielën');
});

t_case('Fiks — e pamundur: kapaciteti dhe kufijtë', function () {
  $f = qta_sched_fixed_feasibility(81, '2026-10-01', '2026-10-10');
  t_eq([false, 'capacity', 10, 80], [$f['ok'], $f['code'], $f['calendar_days'], $f['capacity']], '81 orë në 10 ditë: kapaciteti 80, e pamundur');
  t_ok(str_contains((string)$f['reason'], '1 orë më pak'), 'arsyeja thotë sa mungon');
  $f = qta_sched_fixed_feasibility(20, '2026-10-01', '2026-10-02');
  t_eq([false, 'capacity', 16], [$f['ok'], $f['code'], $f['capacity']], '20 orë në 2 ditë (16): e pamundur');
  t_throws(QtaUserError::class, fn() => qta_sched_propose_fixed_range(81, '2026-10-01', '2026-10-10'), 'propozimi refuzohet', '80 orë');
  $f = qta_sched_fixed_feasibility(1, '2026-10-01', '2026-10-02');
  t_eq([false, 'boundaries'], [$f['ok'], $f['code']], '1 orë, por dy kufij me mësim: e pamundur');
  t_eq([false, 'end_before_start'], [qta_sched_fixed_feasibility(10, '2026-10-05', '2026-10-01')['ok'], qta_sched_fixed_feasibility(10, '2026-10-05', '2026-10-01')['code']], 'mbarimi para fillimit');
  t_eq('bad_dates', qta_sched_fixed_feasibility(10, '2026-02-30', '2026-03-05')['code'], 'datë e pavlefshme');
  t_eq(true, qta_sched_fixed_feasibility(80, '2026-10-01', '2026-10-10')['ok'], '80 orë në 10 ditë: e mundur');
});

t_case('Fiks — e diela: shmanget kur s\'duhet, minimumi kur duhet, kufiri lejohet', function () {
  /* 14 ditë (të diela 04 dhe 11): 90 orë → 12 ditë, mjaftojnë 12 ditët e tjera. */
  $p = qta_sched_propose_fixed_range(90, '2026-10-01', '2026-10-14');
  t_eq([0, 0], [$p['days']['2026-10-04'], $p['days']['2026-10-11']], '90 orë: asnjë e diel');
  /* 100 orë → 13 ditë, vetëm 12 jo të diela: saktësisht 1 e diel, në mes. */
  $p = qta_sched_propose_fixed_range(100, '2026-10-01', '2026-10-14');
  t_eq(['2026-10-11'], $p['sundays'], '100 orë: vetëm një e diel (11.10)');
  t_eq(0, $p['days']['2026-10-04'], 'e diela tjetër mbetet pa mësim');
  t_eq(13, count(fx_active($p['days'])), '13 ditë mësimi');
  $active = fx_active($p['days']);
  t_ok(max($active) - min($active) <= 1, '8 dhe 7 orë: max − min ≤ 1');
  /* Fillimi historik është e diel: lejohet dhe ka mësim, pa u shënuar si e diel e shtuar. */
  $p = qta_sched_propose_fixed_range(16, '2026-10-04', '2026-10-10');
  t_eq(8, $p['days']['2026-10-04'], 'e diela 04.10 është fillimi historik dhe ka mësim');
  t_eq(8, $p['days']['2026-10-10'], 'mbarimi 10.10 ka mësim');
  t_eq([], $p['sundays'], 'kufiri nuk numërohet si e diel e shtuar');
  $s = qta_sched_fixed_summary($p['days'], 16, '2026-10-04', '2026-10-10');
  t_eq([['2026-10-04'], []], [$s['boundary_sundays'], $s['sundays']], 'përmbledhja: e diel kufitare, jo e diel e shtuar');
});

t_case('Fiks — shpërndarje e pabarabartë dhe ditë me 0 orë', function () {
  $p = qta_sched_propose_fixed_range(23, '2026-10-05', '2026-10-09');
  t_eq(['2026-10-05' => 8, '2026-10-06' => 0, '2026-10-07' => 7, '2026-10-08' => 0, '2026-10-09' => 8], $p['days'],
    '23 orë në 3 ditë: 8, 7, 8 (+1 te e para dhe e fundit, njëtrajtësisht)');
  $topics = fx_topics([[3, 4], [5, 5, 6]]);
  $plan = qta_sched_build_fixed_range($topics, '2026-10-05', '2026-10-09', $p['days']);
  t_eq(3, count($plan['days']), 'ditët me 0 orë nuk bëhen ditë mësimi');
  t_eq(['2026-10-05', '2026-10-07', '2026-10-09'], array_column($plan['days'], 'date'), 'vetëm datat me orë');
  t_eq([], qta_sched_verify_fixed_range($topics, $plan, '2026-10-05', '2026-10-09', $p['days']), 'e vlefshme');
});

t_case('Fiks — moduli ndryshon brenda ditës, tema ndahet, radha ruhet', function () use ($WORD) {
  $topics = fx_topics([$WORD, $WORD]);
  $dayHours = fx_plan('2026-10-01', '2026-10-05', ['2026-10-01' => 4, '2026-10-02' => 4, '2026-10-03' => 4, '2026-10-04' => 4, '2026-10-05' => 4]);
  $plan = qta_sched_build_fixed_range($topics, '2026-10-01', '2026-10-05', $dayHours);
  $pairs = static fn(array $day) => array_map(static fn($s) => [$s['topic'], $s['hours']], $day['slots']);
  t_eq([[5, 1], [6, 1], [7, 2]], $pairs($plan['days'][2]), 'dita 3: moduli 1 mbaron (T5, T6), moduli 2 fillon (T7)');
  $ann = qta_sched_annotate($topics, $plan['days']);
  t_ok($ann[2]['slots'][1]['module_ends'] && $ann[2]['slots'][2]['module_starts'], 'shënohen mbarimi dhe fillimi i modulit');
  t_ok(!isset(array_flip(array_column($plan['days'], 'date'))['2026-10-06']), 'asnjë ditë pas mbarimit historik');

  /* Tema 4-orëshe me 3 orë në ditë vazhdon në ditën tjetër. */
  $t4 = fx_topics([[4, 2]]);
  $plan = qta_sched_build_fixed_range($t4, '2026-10-05', '2026-10-06', ['2026-10-05' => 3, '2026-10-06' => 3]);
  t_eq([[1, 3]], $pairs($plan['days'][0]), 'dita 1: 3 orë nga tema 1');
  t_eq([[1, 1], [2, 2]], $pairs($plan['days'][1]), 'dita 2: ora e fundit e temës 1, pastaj tema 2');
  $ann = qta_sched_annotate($t4, $plan['days']);
  t_eq([1, 3, true], [$ann[0]['slots'][0]['from'], $ann[0]['slots'][0]['to'], $ann[0]['slots'][0]['is_split']], 'orët 1–3 nga 4');

  /* Radha e temave dhe e moduleve: çdo temë një bllok, në radhë. */
  $seq = [];
  foreach (qta_sched_build_fixed_range(fx_topics([[3, 1], [2, 2], [5]]), '2026-10-05', '2026-10-07', ['2026-10-05' => 5, '2026-10-06' => 3, '2026-10-07' => 5])['days'] as $d) {
    foreach ($d['slots'] as $s) if (!$seq || end($seq) !== $s['topic']) $seq[] = $s['topic'];
  }
  t_eq([1, 2, 3, 4, 5], $seq, 'temat vijnë në radhën e kursit');
});

t_case('Fiks — plani refuzohet me mesazh të qartë', function () use ($MSO50) {
  $ok = qta_sched_propose_fixed_range(50, '2026-10-01', '2026-10-10')['days'];
  $less = $ok; $less['2026-10-05'] = 0;
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $less), '43 nga 50: refuzohet', 'Mungojnë 7 orë');
  $one = $ok; $one['2026-10-05'] = 6;
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $one), '49 nga 50', 'Mungon 1 orë');
  $more = $ok; $more['2026-10-03'] = 4;
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $more), '54 nga 50: refuzohet', 'Janë vendosur 4 orë më shumë');
  $nine = $ok; $nine['2026-10-05'] = 9; $nine['2026-10-06'] = 5;
  $e = t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $nine), 'ditë me 9 orë refuzohet', 'Më 05.10.2026 janë vendosur 9 orë');
  t_eq('day_over', $e instanceof QtaUserError ? $e->data['code'] : null, 'kodi day_over');
  $noStart = $ok; $noStart['2026-10-01'] = 0; $noStart['2026-10-03'] = 8;
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $noStart), 'fillimi pa mësim refuzohet', 'data historike e fillimit');
  $noEnd = $ok; $noEnd['2026-10-10'] = 0; $noEnd['2026-10-08'] = 7;
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $noEnd), 'mbarimi pa mësim refuzohet', 'data historike e mbarimit');
  $outside = $ok; $outside['2026-10-11'] = 1;
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $outside), 'datë jashtë periudhës', 'jashtë periudhës historike');
  $missing = $ok; unset($missing['2026-10-03']);
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $missing), 'datë që mungon', 'nuk ka orët e datës 03.10.2026');
  $bad = $ok; $bad['x'] = 1;
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $bad), 'datë e pavlefshme', 'datë të pavlefshme');
  $float = $ok; $float['2026-10-02'] = 7.5;
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $float), 'orë jo të plota', 'numër i plotë');
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-10-10', '2026-10-01', $ok), 'mbarimi para fillimit', 'para fillimit');
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range($MSO50, '2026-02-30', '2026-10-10', $ok), 'fillim i pavlefshëm', 'nuk është e vlefshme');
  t_throws(QtaUserError::class, fn() => qta_sched_build_fixed_range([], '2026-10-01', '2026-10-10', $ok), 'kurs pa tema', 'nuk ka tema');
  $issues = qta_sched_fixed_issues(['2026-10-01' => 0] + $less, 50, '2026-10-01', '2026-10-10');
  t_eq(['start_off', 'missing_hours'], array_column($issues, 'code'), 'të gjitha problemet thuhen njëherësh, në radhë');
});

t_case('Fiks — kontrolli i pavarur zbulon orar të prishur', function () use ($MSO50) {
  $dh = qta_sched_propose_fixed_range(50, '2026-10-01', '2026-10-10')['days'];
  $plan = qta_sched_build_fixed_range($MSO50, '2026-10-01', '2026-10-10', $dh);
  $check = static fn(array $p, ?array $d = null) => qta_sched_verify_fixed_range($MSO50, $p, '2026-10-01', '2026-10-10', $d ?? $dh);
  t_eq([], $check($plan), 'orari i saktë');

  $shift = $plan; $shift['start_date'] = '2026-10-02';
  t_ok($check($shift) !== [], 'fillimi i lëvizur zbulohet');
  $late = $plan; $late['end_date'] = '2026-10-11';
  t_ok($check($late) !== [], 'mbarimi i lëvizur zbulohet');
  $out = $plan; $out['days'][6]['date'] = '2026-10-11'; $out['end_date'] = '2026-10-11';
  t_ok($check($out) !== [], 'ditë jashtë periudhës zbulohet');
  $dup = $plan; $dup['days'][2]['date'] = $dup['days'][1]['date'];
  t_ok($check($dup) !== [], 'datë e përsëritur zbulohet');
  $lost = $plan; $lost['days'][3]['slots'][0]['hours']--; $lost['days'][3]['hours']--;
  t_ok($check($lost) !== [], 'orë e humbur zbulohet');
  $extra = $plan; $extra['days'][3]['slots'][0]['hours']++; $extra['days'][3]['hours']++;
  t_ok($check($extra) !== [], 'orë e tepërt zbulohet');
  $over = $plan; $over['days'][0]['hours'] = 9; $over['days'][0]['slots'][] = ['seq' => count($over['days'][0]['slots']) + 1, 'topic' => 25, 'hours' => 1];
  t_ok($check($over) !== [], 'ditë me 9 orë zbulohet');
  $swap = $plan; [$swap['days'][0]['slots'][0], $swap['days'][0]['slots'][1]] = [$swap['days'][0]['slots'][1], $swap['days'][0]['slots'][0]];
  $swap['days'][0]['slots'][0]['seq'] = 1; $swap['days'][0]['slots'][1]['seq'] = 2;
  t_ok($check($swap) !== [], 'radha e temave e prishur zbulohet');
  $unknown = $plan; $unknown['days'][0]['slots'][0]['topic'] = 99;
  t_ok($check($unknown) !== [], 'temë e panjohur zbulohet');
  $moved = $plan; $moved['days'][1]['date'] = '2026-10-03';
  t_ok($check($moved) !== [], 'orari që nuk ndjek planin zbulohet');
  $drift = $dh; $drift['2026-10-03'] = 2; $drift['2026-10-05'] = 5;
  t_ok($check($plan, $drift) !== [], 'plan i ndryshuar pa rindërtim zbulohet');
});

t_case('Fiks — propozimi është përcaktues, i balancuar dhe mbulon periudhën', function () {
  $ranges = [['2026-10-01', '2026-10-10'], ['2026-10-04', '2026-10-18'], ['2025-02-10', '2025-05-15'], ['2026-12-28', '2027-01-09'], ['2026-10-05', '2026-10-06']];
  foreach ($ranges as [$s, $e]) {
    $n = count(qta_sched_dates($s, $e));
    foreach ([2, 7, 16, 23, 50, 99, 100, 150, 240] as $h) {
      $f = qta_sched_fixed_feasibility($h, $s, $e);
      if (!$f['ok']) {
        t_ok($h > $n * 8, "{$s}–{$e}, $h orë: e pamundur vetëm kur kalon kapacitetin");
        continue;
      }
      $a = qta_sched_propose_fixed_range($h, $s, $e);
      $b = qta_sched_propose_fixed_range($h, $s, $e);
      t_eq($a, $b, "{$s}–{$e}, $h orë: i njëjti propozim");
      $active = fx_active($a['days']);
      t_eq($h, array_sum($a['days']), "{$s}–{$e}, $h orë: shuma");
      t_ok(max($a['days']) <= 8 && $a['days'][$s] >= 1 && $a['days'][$e] >= 1, "{$s}–{$e}, $h orë: ≤ 8 në ditë, kufijtë me mësim");
      t_ok(max($active) - min($active) <= 1, "{$s}–{$e}, $h orë: max − min ≤ 1");
      t_eq($f['sundays_needed'], count($a['sundays']), "{$s}–{$e}, $h orë: vetëm të dielat e domosdoshme");
      t_eq($f['required_days'], count($active), "{$s}–{$e}, $h orë: numri minimal i ditëve");
      $topics = fx_course($h);
      $plan = qta_sched_build_fixed_range($topics, $s, $e, $a['days']);
      t_eq([], qta_sched_verify_fixed_range($topics, $plan, $s, $e, $a['days']), "{$s}–{$e}, $h orë: orari i vlefshëm");
    }
  }
  /* Periudha mbulohet: në 94 ditë (2025-02-10 → 2025-05-15), 100 orë nuk grumbullohen në fillim. */
  $p = qta_sched_propose_fixed_range(100, '2025-02-10', '2025-05-15')['days'];
  $teach = array_keys(array_filter($p));
  t_ok($teach[1] > '2025-02-15' && $teach[count($teach) - 2] < '2025-05-10', 'ditët shpërndahen në gjithë periudhën');
  $mid = array_filter($teach, static fn($d) => $d >= '2025-03-20' && $d <= '2025-04-10');
  t_ok(count($mid) >= 2, 'ka mësim edhe në mes të periudhës');
});

t_case('Fiks — rishpërndarja ruan zgjedhjet me dorë dhe kufijtë', function () {
  $s = '2026-10-01'; $e = '2026-10-10';
  $base = qta_sched_propose_fixed_range(50, $s, $e)['days'];

  /* 05.10 bëhet pa mësim me dorë: 43 nga 50 → mungojnë 7. */
  $plan = $base; $plan['2026-10-05'] = 0;
  $r = qta_sched_rebalance_fixed_range($plan, 50, $s, $e, ['2026-10-05']);
  t_eq(50, array_sum($r['days']), '50 nga 50 pas rishpërndarjes');
  t_eq(0, $r['days']['2026-10-05'], 'dita e caktuar me dorë mbetet pa mësim');
  t_eq(0, $r['days']['2026-10-04'], 'e diela nuk përdoret');
  t_ok(max($r['days']) <= 8, 'asnjë ditë mbi 8');
  t_eq(7, $r['delta'], 'mungonin 7 orë');
  t_eq([], $r['sundays_added'], 'asnjë e diel e shtuar');
  t_eq([], $r['manual_changed'], 'asnjë zgjedhje me dorë nuk u prek');
  t_eq(['2026-10-01' => 8, '2026-10-02' => 8, '2026-10-03' => 0, '2026-10-04' => 0, '2026-10-05' => 0, '2026-10-06' => 8,
        '2026-10-07' => 8, '2026-10-08' => 2, '2026-10-09' => 8, '2026-10-10' => 8], $r['days'],
    'së pari ditët me mësim deri në 8, pastaj një ditë pa mësim (08.10) për 2 orët e fundit');
  t_eq($r, qta_sched_rebalance_fixed_range($plan, 50, $s, $e, ['2026-10-05']), 'përcaktuese');

  /* 03.10 merr 4 orë me dorë: 54 nga 50 → hiqen 4 nga ditët më të ngarkuara, më e vona së pari. */
  $plan = $base; $plan['2026-10-03'] = 4;
  $r = qta_sched_rebalance_fixed_range($plan, 50, $s, $e, ['2026-10-03']);
  t_eq(['2026-10-01' => 7, '2026-10-02' => 7, '2026-10-03' => 4, '2026-10-04' => 0, '2026-10-05' => 7, '2026-10-06' => 7,
        '2026-10-07' => 6, '2026-10-08' => 0, '2026-10-09' => 6, '2026-10-10' => 6], $r['days'], 'tepricat hiqen, 03.10 mbetet 4');
  t_eq(-4, $r['delta'], 'tepronin 4 orë');

  /* Plani i plotë: asgjë për të ndryshuar. */
  $r = qta_sched_rebalance_fixed_range($base, 50, $s, $e);
  t_eq([[], $base], [$r['changed'], $r['days']], 'plan i plotë: asnjë ndryshim');

  /* E diela përdoret vetëm kur ditët e tjera janë plot — dhe thuhet. */
  $full = array_fill_keys(qta_sched_dates($s, $e), 8);
  $full['2026-10-04'] = 0; $full['2026-10-02'] = 1;
  $r = qta_sched_rebalance_fixed_range($full, 73, $s, $e);
  t_eq(8, $r['days']['2026-10-02'], 'së pari mbushet dita me vend');
  t_eq(['2026-10-04'], $r['sundays_added'], 'pastaj përdoret e diela dhe raportohet');
  t_eq(1, $r['days']['2026-10-04'], 'e diela merr vetëm orën që mungon');

  /* Kur tepron shumë: kufijtë mbeten me 1 orë. */
  $r = qta_sched_rebalance_fixed_range(['2026-10-01' => 8, '2026-10-02' => 8, '2026-10-03' => 8], 3, '2026-10-01', '2026-10-03');
  t_eq(['2026-10-01' => 1, '2026-10-02' => 1, '2026-10-03' => 1], $r['days'], 'kufijtë nuk bien në 0');

  /* Ditët me dorë preken vetëm kur s'ka rrugë tjetër — dhe thuhet. */
  $plan = array_fill_keys(qta_sched_dates($s, $e), 8); $plan['2026-10-04'] = 8; $plan['2026-10-06'] = 0;
  $r = qta_sched_rebalance_fixed_range($plan, 80, $s, $e, ['2026-10-06']);
  t_eq([8, ['2026-10-06']], [$r['days']['2026-10-06'], $r['manual_changed']], 'pa vend tjetër, dita me dorë merr orët dhe raportohet');

  t_throws(QtaUserError::class, fn() => qta_sched_rebalance_fixed_range($base, 81, $s, $e), 'kapaciteti i kaluar: refuzohet', '80 orë');
  $broken = $base; unset($broken['2026-10-02']);
  t_throws(QtaUserError::class, fn() => qta_sched_rebalance_fixed_range($broken, 50, $s, $e), 'plan i paplotë: refuzohet', 'nuk është i plotë');
});

t_case('Fiks — ndihmësit e shpërndarjes: rregulli i barazimit', function () {
  t_eq([0, 1, 3, 4, 5, 7, 8], qta_sched_spread(range(0, 8), 7), '7 nga 9: round(j·8/6), gjysma lart');
  t_eq([0, 8], qta_sched_spread(range(0, 8), 2), '2 nga 9: i pari dhe i fundit');
  t_eq([0], qta_sched_spread(range(0, 8), 1), '1: i pari');
  t_eq(range(0, 4), qta_sched_spread(range(0, 4), 9), 'më shumë se sa ka: të gjithë');
  t_eq([1], qta_sched_spread_centered([0, 1], 1), 'i përqendruar: 1 nga 2 → i dyti');
  t_eq([1, 4, 7], qta_sched_spread_centered(range(0, 8), 3), '3 nga 9: në mes të çdo të tretës');
  t_eq(['2026-10-30', '2026-10-31', '2026-11-01'], qta_sched_dates('2026-10-30', '2026-11-01'), 'datat kalojnë muajin');
});
