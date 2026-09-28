<?php
declare(strict_types=1);

/**
 * Regjistri i orëve të mësimit — modeli dhe ndarja e faqeve (pa databazë).
 * Oraret ndërtohen me motorin e vërtetë (qta_sched_build), si në grupet e ruajtura.
 */

require_once __DIR__ . '/../../app/shared/lesson_register.php';

date_default_timezone_set('Europe/Tirane');

/**
 * Temat në formatin e kopjes së grupit.
 * @param array<int,array{0:string,1:array<int,array{0:string,1:int}>}> $modules [titulli, [[tema, orë], …]]
 */
function lr_topics(array $modules): array
{
  $out = [];
  $seq = 0;
  foreach ($modules as $mi => [$title, $topics]) {
    $mh = array_sum(array_column($topics, 1));
    foreach ($topics as $ti => [$tt, $h]) {
      $out[] = ['seq' => ++$seq, 'module_seq' => $mi + 1, 'module_title' => $title, 'module_hours' => $mh,
                'topic_seq' => $ti + 1, 'topic_title' => $tt, 'hours' => $h];
    }
  }
  return $out;
}

function lr_students(array $amze): array
{
  return array_map(static fn($a, $i) => ['student_id' => 500 + $i, 'nr_amze' => (string)$a, 'first_name' => 'Emër' . $i, 'father_name' => 'Atësi', 'last_name' => 'Mbiemër'], $amze, array_keys($amze));
}

function lr_model(array $topics, string $start, int $daily, array $rules = [], array $amze = [3401, 3402]): array
{
  $plan = qta_sched_build($topics, $start, $daily, $rules);
  return qta_lesson_register_model(['id' => 42, 'course_name' => 'Operator kompjuteri', 'course_code' => 'OPK',
    'start_date' => $plan['start_date'], 'end_date' => $plan['end_date']], $topics, $plan['days'], lr_students($amze), '2026-09-26');
}

/** Faqet e një lloji për një modul. */
function lr_pages(array $m, string $kind, ?int $module = null): array
{
  return array_values(array_filter($m['pages'], static fn($p) => $p['kind'] === $kind && ($module === null || $p['module_seq'] === $module)));
}

/** Dates e kolonave të një faqeje prezence (pa kolonat bosh). */
function lr_cols(array $page): array
{
  return array_values(array_map(static fn($c) => $c['date'], array_filter($page['columns'])));
}

$WORD = [['Hyrje në Word', 2], ['Formatimi i tekstit', 2], ['Tabelat', 2], ['Printimi', 1]];

t_case('Regjistri: numrat 1…N ndjekin radhën e Listës emërore, jo numrin e amzës', function () use ($WORD) {
  $m = lr_model(lr_topics([['Microsoft Word', $WORD]]), '2026-10-01', 5, [], [3409, 99, 1200]);
  t_eq([1, 2, 3], array_column($m['students'], 'sequence'), 'numrat rendorë 1, 2, 3');
  t_eq(['3409', '99', '1200'], array_column($m['students'], 'nr_amze'), 'radha e dhënë nuk ndryshon (renditja bëhet si te Lista emërore)');
  foreach (lr_pages($m, 'attendance') as $p) {
    t_eq(3, $p['numbered'], 'faqja ' . $p['number'] . ': vetëm 3 rreshta me numër');
    t_eq(QTA_LR_FORM_ROWS, $p['row_count'], 'faqja ' . $p['number'] . ': 35 rreshta si formulari');
  }
});

t_case('Regjistri: 1 kursant, 10 kursantë, dhe mbi 35 (rreshtat ngushtohen)', function () use ($WORD) {
  $one = lr_model(lr_topics([['Microsoft Word', $WORD]]), '2026-10-01', 5, [], [5009]);
  t_eq(1, lr_pages($one, 'attendance')[0]['numbered'], 'një kursant: vetëm rreshti 1 ka numër');
  $ten = lr_model(lr_topics([['Microsoft Word', $WORD]]), '2026-10-01', 5, [], range(3400, 3409));
  t_eq(10, lr_pages($ten, 'attendance')[0]['numbered'], '10 kursantë: rreshtat 1…10');
  t_eq(17.0, lr_pages($ten, 'attendance')[0]['row_height'], 'lartësia e zakonshme e rreshtit');
  $many = lr_model(lr_topics([['Microsoft Word', $WORD]]), '2026-10-01', 5, [], range(1, 40));
  $p = lr_pages($many, 'attendance')[0];
  t_eq([40, 40], [$p['numbered'], $p['row_count']], '40 kursantë: 40 rreshta, të gjithë me numër');
  t_ok($p['row_height'] * 40 <= qta_lr_geometry()['att_body_h'] + 0.01, 'rreshtat ngushtohen që të zënë në faqe');
  $none = lr_model(lr_topics([['Microsoft Word', $WORD]]), '2026-10-01', 5, [], []);
  t_eq(0, lr_pages($none, 'attendance')[0]['numbered'], 'pa kursantë: asnjë rresht me numër');
});

t_case('Regjistri: modulet sipas module_seq, faqet tek = prezenca, çift = temat', function () use ($WORD) {
  $m = lr_model(lr_topics([['Njohuri të përgjithshme', [['Hyrje', 3]]], ['Përpunimi i të dhënave', [['Excel', 8]]], ['Çështje praktike', [['Praktikë', 4]]]]), '2026-10-01', 5);
  t_eq([1, 2, 3], array_column($m['modules'], 'module_seq'), 'modulet 1, 2, 3');
  t_eq(count($m['pages']), $m['page_count'], 'numri i faqeve');
  t_eq(0, $m['page_count'] % 2, 'numër çift faqesh');
  foreach ($m['pages'] as $i => $p) {
    t_eq($i + 1, $p['number'], 'numri i faqes ' . ($i + 1));
    t_eq($i % 2 === 0 ? 'attendance' : 'topics', $p['kind'], 'faqja ' . ($i + 1) . ' është ' . ($i % 2 === 0 ? 'prezencë' : 'tema'));
    if ($i % 2 === 1) {
      $prev = $m['pages'][$i - 1];
      t_eq([$prev['module_seq'], $prev['part'], $prev['dates']], [$p['module_seq'], $p['part'], $p['dates']], 'faqja ' . ($i + 1) . ' është çifti i faqes ' . $i);
    }
  }
  t_eq([1, 1, 2, 2, 3, 3], array_column($m['pages'], 'module_seq'), 'çiftet ndjekin radhën e moduleve');
  t_eq('Moduli 1 — Njohuri të përgjithshme', $m['pages'][1]['heading'], 'titulli i faqes çift');
  t_eq('Moduli 3 — Çështje praktike', $m['pages'][5]['heading'], 'titulli shqip mbetet i saktë');
  t_eq('Përpunimi i të dhënave', $m['modules'][1]['module_title'], 'emri i modulit shqip');
  t_eq('Excel', $m['pages'][3]['rows'][0]['topic_title'], 'faqja 4 ka temat e modulit 2');
});

t_case('Regjistri: datat në rritje, një kolonë dhe një rresht për çdo orë', function () use ($WORD) {
  $m = lr_model(lr_topics([['Microsoft Word', $WORD]]), '2026-10-01', 5);
  $att = lr_pages($m, 'attendance')[0];
  $top = lr_pages($m, 'topics')[0];
  t_eq(['2026-10-01', '2026-10-02'], $att['dates'], 'dy data mësimi');
  t_eq(array_merge(array_fill(0, 5, '2026-10-01'), array_fill(0, 2, '2026-10-02')), lr_cols($att), 'një kolonë për çdo orë: 01.10 pesë herë, 02.10 dy herë');
  t_eq(['01.10.2026', '01.10.2026', '01.10.2026', '01.10.2026', '01.10.2026', '02.10.2026', '02.10.2026'], array_column($top['rows'], 'date_label'), 'një rresht për çdo orë: 5 më 01.10, 2 më 02.10');
  t_eq(['Hyrje në Word', 'Hyrje në Word', 'Formatimi i tekstit', 'Formatimi i tekstit', 'Tabelat', 'Tabelat', 'Printimi'], array_column($top['rows'], 'topic_title'), 'temat në radhë; "Tabelat" vazhdon në ditën tjetër');
  t_eq(array_fill(0, 7, 1), array_column($top['rows'], 'hours'), 'çdo rresht është një orë');
  $dates = array_column($top['rows'], 'date');
  $sorted = $dates;
  sort($sorted);
  t_eq($sorted, $dates, 'rreshtat në radhë kronologjike');
  t_eq(['1', '1', '1', '1', '1', '2', '2'], array_map(static fn($c) => $c['day'], array_values(array_filter($att['columns']))), 'rreshti "Dt." ka ditën e muajit pa zero përpara, një herë për çdo orë');
  t_eq(QTA_LR_MAX_COLUMNS, count($att['columns']), '31 kolona si formulari');
});

t_case('Regjistri: kolona k e faqes tek është rreshti k i faqes çift', function () {
  $m = lr_model(lr_topics([['Njohuri', [['Hyrje', 3], ['Siguria', 4]]], ['Praktikë', array_map(static fn($i) => ['Ushtrimi ' . $i, 3], range(1, 15))]]), '2026-09-28', 5, ['2026-10-04' => 4, '2026-10-05' => 2]);
  $pages = $m['pages'];
  for ($i = 0; $i < count($pages); $i += 2) {
    [$att, $top] = [$pages[$i], $pages[$i + 1]];
    $cols = array_values(array_filter($att['columns']));
    t_eq(array_column($top['rows'], 'date'), array_column($cols, 'date'), 'faqet ' . $att['number'] . '–' . $top['number'] . ': e njëjta datë në kolonë dhe në rresht');
    t_eq(array_column($top['rows'], 'topic_seq'), array_column($cols, 'topic_seq'), 'faqet ' . $att['number'] . '–' . $top['number'] . ': e njëjta orë e së njëjtës temë');
    t_ok(count($cols) <= QTA_LR_MAX_COLUMNS, 'faqja ' . $att['number'] . ': jo më shumë se 31 kolona');
  }
  $total = 0;
  foreach (lr_pages($m, 'attendance') as $p) $total += count(array_filter($p['columns']));
  t_eq(7 + 45, $total, 'kolonat gjithsej = orët e kursit');
  t_ok(count(lr_pages($m, 'attendance', 2)) > 1, '45 orë praktikë → më shumë se një çift faqesh');
});

t_case('Regjistri: një modul mbaron dhe tjetri fillon në të njëjtën ditë', function () {
  $m = lr_model(lr_topics([['Microsoft Word', [['Word 1', 4], ['Word 2', 3]]], ['Microsoft Excel', [['Excel 1', 3]]]]), '2026-10-01', 5);
  t_eq(['2026-10-01', '2026-10-02'], lr_pages($m, 'attendance', 1)[0]['dates'], 'Word: 01.10 dhe 02.10');
  t_eq(['2026-10-02'], lr_pages($m, 'attendance', 2)[0]['dates'], 'Excel nis më 02.10, në mes të ditës');
  t_eq(array_merge(array_fill(0, 5, '2026-10-01'), array_fill(0, 2, '2026-10-02')), lr_cols(lr_pages($m, 'attendance', 1)[0]), 'Word: 5 kolona më 01.10, 2 më 02.10');
  t_eq(array_fill(0, 3, '2026-10-02'), lr_cols(lr_pages($m, 'attendance', 2)[0]), 'Excel: 3 kolona më 02.10');
  t_eq(array_fill(0, 2, ['2026-10-02', 'Word 2']), array_map(static fn($r) => [$r['date'], $r['topic_title']], array_slice(lr_pages($m, 'topics', 1)[0]['rows'], -2)), 'Word mbaron me 2 orë më 02.10: dy rreshta');
  t_eq(array_fill(0, 3, ['2026-10-02', 'Excel 1']), array_map(static fn($r) => [$r['date'], $r['topic_title']], lr_pages($m, 'topics', 2)[0]['rows']), 'Excel me 3 orë po atë ditë: tre rreshta');
});

t_case('Regjistri: një temë me X orë del X herë, me datën në çdo rresht', function () {
  $m = lr_model(lr_topics([['Microsoft Excel', [['Formulat', 3], ['Grafikët', 4], ['Printimi', 1]]]]), '2026-10-01', 5);
  $rows = lr_pages($m, 'topics')[0]['rows'];
  $pairs = array_map(static fn($r) => $r['date_label'] . ' ' . $r['topic_title'], $rows);
  t_eq([
    '01.10.2026 Formulat', '01.10.2026 Formulat', '01.10.2026 Formulat',
    '01.10.2026 Grafikët', '01.10.2026 Grafikët',
    '02.10.2026 Grafikët', '02.10.2026 Grafikët',
    '02.10.2026 Printimi',
  ], $pairs, 'Formulat 3 herë, Grafikët 2 + 2 herë (vazhdon të nesërmen), Printimi 1 herë');
  $count = [];
  foreach ($rows as $r) $count[$r['topic_title']] = ($count[$r['topic_title']] ?? 0) + 1;
  t_eq(['Formulat' => 3, 'Grafikët' => 4, 'Printimi' => 1], $count, 'rreshtat e çdo teme = orët e saj');
  t_eq([1, 2, 3, 1, 2, 3, 4, 1], array_column($rows, 'topic_hour'), 'ora e temës: 1…X, edhe kur tema vazhdon në ditën tjetër');
  t_eq(8, count($rows), '8 orë kursi → 8 rreshta');
});

t_case('Regjistri: kalimi i muajit — numri i muajit mbi datën e parë të muajit të ri', function () {
  $m = lr_model(lr_topics([['Microsoft Word', [['Tema', 20]]]]), '2026-09-29', 5);
  $p = lr_pages($m, 'attendance')[0];
  $days = [];
  foreach (['29', '30', '1', '2'] as $d) array_push($days, ...array_fill(0, 5, $d));
  t_eq($days, array_map(static fn($c) => $c['day'], array_values(array_filter($p['columns']))), 'datat 29 | 30 | 1 | 2, secila 5 herë (5 orë)');
  t_eq([[0, 10, 9, false], [10, 21, 10, true]], array_map(static fn($s) => [$s['from'], $s['span'], $s['month'], $s['is_change']], $p['months']), 'muaji 9 mbi 29–30, muaji 10 nis mbi kolonën e parë të datës 1');
  t_eq(['9', '10'], array_column($p['months'], 'label'), 'muajt me numër');
  t_eq(QTA_LR_MAX_COLUMNS, array_sum(array_column($p['months'], 'span')), 'segmentet mbulojnë të 31 kolonat');

  /* Faqja nis në mes të muajit: muaji i datës së parë shfaqet sërish. */
  $mid = lr_model(lr_topics([['Microsoft Word', [['Tema', 10]]]]), '2026-09-17', 5);
  t_eq([[0, 31, 9]], array_map(static fn($s) => [$s['from'], $s['span'], $s['month']], lr_pages($mid, 'attendance')[0]['months']), '17.09: muaji 9 mbi datën e parë');

  /* 01.10 pa mësim: muaji 10 shënohet mbi datën e parë të tetorit në orar (02.10). */
  $skip = lr_model(lr_topics([['Microsoft Word', [['Tema', 10]]]]), '2026-09-30', 5, ['2026-10-01' => 0]);
  $sp = lr_pages($skip, 'attendance')[0];
  t_eq(['2026-09-30', '2026-10-02'], $sp['dates'], '30.09 dhe 02.10');
  t_eq([[0, 5, 9], [5, 26, 10]], array_map(static fn($s) => [$s['from'], $s['span'], $s['month']], $sp['months']), 'muaji 10 mbi kolonën e parë të 02.10');
});

t_case('Regjistri: kalimi i vitit — dhjetor → janar', function () {
  $m = lr_model(lr_topics([['Saldimi', [['Praktikë', 16]]]]), '2026-12-29', 4, ['2027-01-01' => 0]);
  $p = lr_pages($m, 'attendance')[0];
  t_eq(['2026-12-29', '2026-12-30', '2026-12-31', '2027-01-02'], $p['dates'], 'pa 01.01 (pa mësim) dhe pa të dielën 03.01');
  t_eq(16, count(lr_cols($p)), '16 orë → 16 kolona');
  t_eq([[2026, 12, 0, 12], [2027, 1, 12, 19]], array_map(static fn($s) => [$s['year'], $s['month'], $s['from'], $s['span']], $p['months']), '12 pastaj 1, me vitin e ri');
  $top = lr_pages($m, 'topics')[0];
  t_eq('02.01.2027', $top['rows'][count($top['rows']) - 1]['date_label'], 'faqja çift tregon datën e plotë');
  t_eq(16, count($top['rows']), '16 orë → 16 rreshta');
});

t_case('Regjistri: të dielat dhe ditët pa mësim nuk bëhen kolona; e diela me mësim po', function () {
  $rules = ['2026-10-04' => 4, '2026-10-05' => 2, '2026-10-06' => 0];
  $topics = lr_topics([['Moduli A', [['A1', 12]]], ['Moduli B', [['B1', 9], ['B2', 8]]]]);
  $plan = qta_sched_build($topics, '2026-10-01', 5, $rules);
  $m = lr_model($topics, '2026-10-01', 5, $rules);
  $all = [];
  foreach (lr_pages($m, 'attendance') as $p) {
    foreach (lr_cols($p) as $d) $all[$d] = true;
  }
  $stored = array_column($plan['days'], 'date');
  t_eq($stored, array_keys($all), 'kolonat = ditët e ruajtura të mësimit, asnjë më shumë');
  t_ok(isset($all['2026-10-04']), 'e diela 04.10 me mësim është kolonë');
  t_ok(!isset($all['2026-10-06']) && !isset($all['2026-10-11']), 'dita pa mësim dhe e diela e zakonshme mungojnë');
  $hours = 0;
  foreach (lr_pages($m, 'topics') as $p) {
    foreach ($p['rows'] as $r) if ($r['date'] === '2026-10-05') $hours += $r['hours'];
    foreach ($p['rows'] as $r) t_eq('', $r['notes'], 'shënimet mbeten bosh');
  }
  t_eq(2, $hours, 'dita e veçantë 05.10 ka vetëm 2 orë mësim');
});

t_case('Regjistri: mbi 31 data → disa çifte faqesh, me "vazhdim"', function () {
  /* Një orë në ditë: një rresht për datë, që kufiri të jetë numri i datave (31). */
  $m = lr_model(lr_topics([['Praktika në kantier', array_map(static fn($i) => ['Dita ' . $i, 1], range(1, 40))]]), '2026-10-01', 1);
  $att = lr_pages($m, 'attendance');
  t_eq(2, count($att), 'dy faqe prezence për 40 data');
  t_eq([31, 9], array_map(static fn($p) => count($p['dates']), $att), '31 data në faqen e parë, 9 në të dytën');
  t_eq(4, $m['page_count'], 'katër faqe: tek, çift, tek, çift');
  t_eq([false, true], array_column($att, 'continued'), 'pjesa e dytë është vazhdim');
  t_eq([[1, 2], [2, 2]], array_map(static fn($p) => [$p['part'], $p['parts']], $att), 'pjesa 1 nga 2, 2 nga 2');
  t_ok(str_contains($att[1]['caption']['left'], 'Moduli 1 — vazhdim'), 'faqja e prezencës së vazhdimit e thotë');
  t_eq('vazhdim', lr_pages($m, 'topics')[1]['heading_note'], 'faqja e temave e vazhdimit e thotë');
  t_eq('', lr_pages($m, 'topics')[0]['heading_note'], 'pjesa e parë jo');
  $firstLast = end($att[0]['dates']);
  t_ok($firstLast < $att[1]['dates'][0], 'pjesët ndjekin njëra-tjetrën në kohë');
  foreach ($m['pages'] as $p) {
    $n = $p['kind'] === 'attendance' ? count(array_filter($p['columns'])) : count($p['rows']);
    t_ok($n <= QTA_LR_MAX_COLUMNS, 'faqja ' . $p['number'] . ': jo më shumë se 31 orë');
  }
});

t_case('Regjistri: rreshtat e temave zënë gjithmonë në faqen çift', function () {
  $long = str_repeat('Përpunimi i të dhënave me formula, funksione dhe tabela të mëdha ', 4);
  $m = lr_model(lr_topics([['Excel', array_map(static fn($i) => [$i % 2 ? $long : 'Tema ' . $i, 1], range(1, 60))]]), '2026-10-05', QTA_DAY_MAX_HOURS);
  t_ok(count(lr_pages($m, 'topics')) > 1, 'tituj të gjatë → më shumë se një çift faqesh');
  $g = qta_lr_geometry();
  foreach (lr_pages($m, 'topics') as $p) {
    $used = array_sum(array_column($p['rows'], 'height'));
    t_ok($used <= $p['budget'] + 0.001, 'faqja ' . $p['number'] . ': ' . $used . ' pt ≤ ' . $p['budget']);
    t_ok($used + $p['fillers'] * $g['top_row_min'] <= $p['budget'] + 0.001 && $used + ($p['fillers'] + 1) * $g['top_row_min'] > $p['budget'], 'faqja ' . $p['number'] . ': rreshtat bosh mbushin pjesën tjetër');
  }
  $row = null;
  foreach (lr_pages($m, 'topics')[0]['rows'] as $r) if ($r['topic_title'] !== 'Tema 2') { $row = $r; break; }
  t_ok($row['lines'] >= 3 && $row['height'] > $g['top_row_min'], 'tema e gjatë zë disa rreshta dhe rreshti zmadhohet');
  t_eq(trim($long), $row['topic_title'], 'titulli i gjatë nuk shkurtohet');
});

t_case('Regjistri: një ditë më e gjatë se një faqe ndahet pa humbur rreshta', function () {
  $g = qta_lr_geometry();
  $rows = [];
  foreach (['2026-10-01' => 4, '2026-10-02' => 1] as $d => $n) {
    for ($i = 1; $i <= $n; $i++) $rows[] = ['date' => $d, 'date_label' => qta_date($d), 'topic_seq' => $i, 'topic_title' => 'T' . $i, 'hours' => 1];
  }
  $chunks = qta_lr_paginate($rows, $g, 60.0); // 3 rreshta nga 20 pt
  t_eq([['2026-10-01'], ['2026-10-01', '2026-10-02']], array_column($chunks, 'dates'), 'dita e parë del në të dyja pjesët');
  t_eq(5, array_sum(array_map(static fn($c) => count($c['rows']), $chunks)), 'asnjë rresht nuk humbet');
  foreach ($chunks as $c) t_ok($c['height'] <= 60.0, 'çdo pjesë zë në faqe');
});

t_case('Regjistri: tituj shqip, rreshti poshtë, emri i skedarit, përsëritshmëria', function () {
  $topics = lr_topics([['Njohuri të përgjithshme', [['Çështje praktike', 3], ['Përpunimi i të dhënave', 2]]]]);
  $a = lr_model($topics, '2026-10-01', 5);
  $b = lr_model($topics, '2026-10-01', 5);
  t_eq(json_encode($a), json_encode($b), 'i njëjti orar jep të njëjtin model');
  t_eq(['Çështje praktike', 'Çështje praktike', 'Çështje praktike', 'Përpunimi i të dhënave', 'Përpunimi i të dhënave'], array_column($a['pages'][1]['rows'], 'topic_title'), 'ë dhe ç ruhen (3 + 2 orë)');
  t_eq(['Grupi #42 · Operator kompjuteri · Moduli 1', 'Faqja 1 nga 2'], [$a['pages'][0]['caption']['left'], $a['pages'][0]['caption']['right']], 'rreshti poshtë i faqes tek');
  t_eq('Faqja 2 nga 2', $a['pages'][1]['caption']['right'], 'faqja çift');
  t_eq('QTA-Regjistri-Mesimit-Grupi-42-2026-09-26.pdf', qta_lr_filename(42, 'pdf', '2026-09-26'), 'emri PDF');
  t_eq('QTA-Regjistri-Mesimit-Grupi-42-2026-09-26.docx', qta_lr_filename(42, 'DOCX', '2026-09-26'), 'emri Word');
  t_eq('QTA-Regjistri-Mesimit-Grupi-7-' . date('Y-m-d') . '.pdf', qta_lr_filename(7, 'p"d/f', '../x'), 'asgjë e rrezikshme në emër');
  $longCourse = qta_lr_caption(9, str_repeat('Kurs shumë i gjatë ', 20), 'Moduli 2 — vazhdim', 3, 12, qta_lr_geometry());
  t_ok(str_ends_with($longCourse['left'], ' · Moduli 2 — vazhdim') && str_contains($longCourse['left'], '…'), 'emri i gjatë i kursit shkurtohet, moduli mbetet');
  t_eq('A B', qta_lr_clean("  A\x07\u{E000}B \n"), 'shenjat e kontrollit dhe private hiqen');
});

t_case('Regjistri: matja e tekstit është e kujdesshme', function () {
  t_eq(1, qta_lr_text_lines('Hyrje', 290.0, 11.0), 'titull i shkurtër: 1 rresht');
  t_ok(qta_lr_text_lines(str_repeat('fjalë ', 60), 290.0, 11.0) >= 4, '60 fjalë: disa rreshta');
  t_ok(qta_lr_text_lines(str_repeat('x', 200), 100.0, 11.0) >= 5, 'fjalë pa hapësira thyhet në shkronja');
  $w = qta_lr_text_width('Muaji:', 14.0);
  t_ok(abs($w - 36.34) < 0.05, 'gjerësia e "Muaji:" me Calibri 14 pt ≈ 36,34 pt (doli ' . round($w, 2) . ')');
  t_ok(qta_lr_text_width('語', 10.0) >= 10.0, 'shkronja e panjohur llogaritet 1 em');
});
