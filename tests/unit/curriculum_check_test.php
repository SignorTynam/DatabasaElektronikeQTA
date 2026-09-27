<?php
declare(strict_types=1);

/**
 * Struktura e kursit — rregullat e orëve dhe të radhës (rasti i pranimit A).
 */

require_once __DIR__ . '/../../app/shared/curriculum.php';

/** Kurs për testim: $modules = [['Word', 10, [2,2,2,2,1,1]], …] */
function cur_fixture(int $courseHours, array $modules): array
{
  $course = ['id' => 1, 'code' => 'MSO', 'name' => 'Microsoft Office', 'hours' => $courseHours];
  $out = [];
  $mid = 100;
  $tid = 1000;
  foreach ($modules as $i => [$title, $hours, $topics]) {
    $m = ['id' => ++$mid, 'course_id' => 1, 'position' => $i + 1, 'title' => $title, 'hours' => $hours, 'topics' => []];
    foreach ($topics as $j => $h) {
      $m['topics'][] = ['id' => ++$tid, 'module_id' => $mid, 'position' => $j + 1, 'title' => 'Tema ' . ($j + 1), 'hours' => $h];
    }
    $out[] = $m;
  }
  return [$course, $out];
}

$W = [2, 2, 2, 2, 1, 1];
$office = [['Word', 10, $W], ['Excel', 10, $W], ['PowerPoint', 10, $W], ['Access', 10, $W], ['Outlook', 10, $W]];

t_case('Pranimi A — Microsoft Office 50 orë, 5 module × 10 orë, Word me 6 tema', function () use ($office) {
  [$course, $modules] = cur_fixture(50, $office);
  $c = qta_course_check($course, $modules);
  t_eq(true, $c['ready'], 'kursi është gati');
  t_eq(50, $c['module_hours'], '10 + 10 + 10 + 10 + 10 = 50');
  t_eq(10, $c['modules'][101]['topic_hours'], 'Word: 2 + 2 + 2 + 2 + 1 + 1 = 10');
  t_eq(30, $c['topic_count'], '30 tema gjithsej');
  t_eq([], $c['issues'], 'asnjë problem');

  $topics = qta_course_schedule_topics($modules);
  t_eq(30, count($topics), 'lista e temave për orarin ka 30 tema');
  t_eq([1, 1, 1], [$topics[0]['seq'], $topics[0]['module_seq'], $topics[0]['topic_seq']], 'tema e parë: moduli 1, tema 1');
  t_eq([7, 2, 1], [$topics[6]['seq'], $topics[6]['module_seq'], $topics[6]['topic_seq']], 'tema e 7-të është tema 1 e Excel');
  t_eq('Excel', $topics[6]['module_title'], 'emri i modulit kopjohet');
});

t_case('Mospërputhjet e orëve zbulohen dhe shpjegohen', function () use ($W, $office) {
  [$course, $modules] = cur_fixture(60, $office);
  $c = qta_course_check($course, $modules);
  t_eq(false, $c['ready'], 'modulet < kursi: jo gati');
  t_ok(str_contains($c['issues'][0]['text'], '50 orë nga 60 orë'), 'mesazhi thotë 50 nga 60');
  t_eq(['set_course_hours', 50], [$c['issues'][0]['fix']['action'], $c['issues'][0]['fix']['value']], 'rregullim me një klik: orët e kursit 50');

  [$course, $modules] = cur_fixture(40, $office);
  $c = qta_course_check($course, $modules);
  t_eq(false, $c['ready'], 'modulet > kursi: jo gati');
  t_ok(str_contains($c['issues'][0]['text'], 'kursi ka vetëm 40 orë'), 'mesazhi thotë se kursi ka vetëm 40');

  [$course, $modules] = cur_fixture(50, [['Word', 10, [2, 2, 2, 2, 1]], ['Excel', 10, $W], ['PowerPoint', 10, $W], ['Access', 10, $W], ['Outlook', 10, $W]]);
  $c = qta_course_check($course, $modules);
  t_eq(false, $c['ready'], 'temat < moduli: jo gati');
  t_ok(str_contains($c['issues'][0]['text'], '"Word" kanë 9 orë nga 10 orë'), 'mesazhi emërton modulin dhe orët');
  t_eq(['set_module_hours', 9], [$c['issues'][0]['fix']['action'], $c['issues'][0]['fix']['value']], 'rregullim: orët e modulit 9');

  [$course, $modules] = cur_fixture(50, [['Word', 10, [2, 2, 2, 2, 1, 1, 3]], ['Excel', 10, $W], ['PowerPoint', 10, $W], ['Access', 10, $W], ['Outlook', 10, $W]]);
  $c = qta_course_check($course, $modules);
  t_ok(!$c['ready'] && str_contains($c['issues'][0]['text'], 'moduli ka vetëm 10 orë'), 'temat > moduli: mesazh i qartë');
  t_eq(null, $c['issues'][0]['fix'], 'temat > moduli, kursi pa vend: moduli nuk rritet me një klik');
  t_ok(!str_contains($c['issues'][0]['text'], 'rrit orët e modulit'), 'nuk propozohet rritja e modulit');

  [$course, $modules] = cur_fixture(60, [['Word', 10, [2, 2, 2, 2, 1, 1, 3]], ['Excel', 10, $W], ['PowerPoint', 10, $W], ['Access', 10, $W], ['Outlook', 10, $W]]);
  $c = qta_course_check($course, $modules);
  $word = array_values(array_filter($c['issues'], static fn($i) => ($i['module_id'] ?? null) === 101));
  t_eq(['set_module_hours', 13], [$word[0]['fix']['action'] ?? null, $word[0]['fix']['value'] ?? null], 'kursi ka vend: propozohet moduli 13 orë');

  [$course, $modules] = cur_fixture(50, [['Word', 10, $W], ['Excel', 10, $W], ['PowerPoint', 10, $W], ['Access', 10, $W], ['Outlook', 10, []]]);
  $c = qta_course_check($course, $modules);
  t_ok(!$c['ready'] && str_contains($c['issues'][0]['text'], '"Outlook" nuk ka ende tema'), 'modul pa tema');

  [$course, $modules] = cur_fixture(50, []);
  $c = qta_course_check($course, $modules);
  t_ok(!$c['ready'] && str_contains($c['issues'][0]['text'], 'nuk ka ende module'), 'kurs pa module');
});

t_case('Radha e dyfishtë ose me boshllëqe nuk lejohet për orar', function () use ($office) {
  [$course, $modules] = cur_fixture(50, $office);
  $modules[1]['position'] = 1; // dy module në vendin 1
  $c = qta_course_check($course, $modules);
  t_eq(false, $c['ready'], 'module me të njëjtin vend: jo gati');
  t_ok((bool)array_filter($c['issues'], static fn($i) => ($i['fix']['action'] ?? '') === 'normalize'), 'ofrohet "Rregullo radhën"');

  [$course, $modules] = cur_fixture(50, $office);
  $modules[0]['topics'][3]['position'] = 9; // boshllëk te temat
  $c = qta_course_check($course, $modules);
  t_ok(!$c['ready'] && str_contains($c['issues'][0]['text'], 'Radha e temave te moduli "Word"'), 'boshllëk në radhën e temave');
});

t_case('Orë të pavlefshme në hyrje refuzohen', function () {
  t_throws(QtaUserError::class, fn() => qta_curriculum_hours('0', 'module'), '0 orë refuzohet', 'të paktën 1');
  t_throws(QtaUserError::class, fn() => qta_curriculum_hours('-3', 'topic'), 'orë negative refuzohen', 'të paktën 1');
  t_throws(QtaUserError::class, fn() => qta_curriculum_hours('2.5', 'topic'), 'gjysmë ore refuzohet (orë të plota)', 'numër i plotë');
  t_throws(QtaUserError::class, fn() => qta_curriculum_hours('dhjetë', 'course'), 'tekst refuzohet', 'Orët e kursit');
  t_eq(12, qta_curriculum_hours(' 12 ', 'module'), 'hapësirat pranohen');
  t_throws(QtaUserError::class, fn() => qta_curriculum_title('   ', 200, 'module'), 'emër bosh refuzohet', 'emrin e modulit');
  t_eq('Word bazë', qta_curriculum_title("  Word \n  bazë ", 200, 'module'), 'hapësirat e tepërta hiqen');
});
