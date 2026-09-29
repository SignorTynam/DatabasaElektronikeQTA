<?php
declare(strict_types=1);

/**
 * Kalendari i grupeve pa databazë: gjendja e grupit (e njëjtë me regjistrat), muaji dhe
 * intervali që pranon faqja, ditët përfshirëse, dhe grupi si ngjarje (pa të dhëna personale).
 */

require_once __DIR__ . '/../../app/shared/group_calendar.php';

/** Grupi minimal për gjendjen. */
function cal_g(string $start, string $end, int $closed = 0): array
{
  return ['start_date' => $start, 'end_date' => $end, 'is_completed' => $closed];
}

t_case('Kalendari: gjendja e grupit sipas datës së sotme, me datat përfshirëse', function () {
  $today = '2026-10-10';
  t_eq('upcoming', qta_group_state(cal_g('2026-10-11', '2026-10-20'), $today), 'nis nesër: nis më vonë');
  t_eq('active', qta_group_state(cal_g('2026-10-10', '2026-10-20'), $today), 'nis sot: në mësim');
  t_eq('active', qta_group_state(cal_g('2026-10-01', '2026-10-10'), $today), 'mbaron sot: ende në mësim (dita e fundit është ditë mësimi)');
  t_eq('active', qta_group_state(cal_g('2026-10-10', '2026-10-10'), $today), 'grup njëditor sot: në mësim');
  t_eq('awaiting_close', qta_group_state(cal_g('2026-10-01', '2026-10-09'), $today), 'mbaroi dje: pret mbylljen');
  t_eq('closed', qta_group_state(cal_g('2026-10-01', '2026-10-09', 1), $today), 'i mbyllur');
  t_eq('closed', qta_group_state(cal_g('2026-11-01', '2026-11-09', 1), $today), 'i mbyllur fiton edhe mbi "nis më vonë"');

  $m = qta_group_state_meta(cal_g('2026-10-15', '2026-10-20'), $today);
  t_eq(['upcoming', 'Nis pas 5 ditësh', 'info', 'bi-calendar-event'], [$m['key'], $m['label'], $m['tone'], $m['icon']], 'nis më vonë: fjala dhe toni si te regjistri');
  t_eq('Nis nesër', qta_group_state_meta(cal_g('2026-10-11', '2026-10-20'), $today)['label'], 'nesër');
  t_eq(['Në mësim', 'accent'], array_values(array_intersect_key(qta_group_state_meta(cal_g('2026-10-01', '2026-10-20'), $today), ['label' => 1, 'tone' => 1])), 'në mësim');
  t_eq(['Pret mbylljen', 'warning'], array_values(array_intersect_key(qta_group_state_meta(cal_g('2026-10-01', '2026-10-02'), $today), ['label' => 1, 'tone' => 1])), 'pret mbylljen');
  t_eq(['I mbyllur', 'success'], array_values(array_intersect_key(qta_group_state_meta(cal_g('2026-10-01', '2026-10-02', 1), $today), ['label' => 1, 'tone' => 1])), 'i mbyllur');
  t_ok(str_contains(qta_group_status(cal_g('2026-10-01', '2026-10-20'), $today), 'status-accent'), 'statusi HTML përdor qta_status()');
});

t_case('Kalendari: muaji nga adresa, me emrin shqip', function () {
  t_eq(['month' => '2026-10', 'from' => '2026-10-01', 'to' => '2026-10-31', 'label' => 'Tetor 2026'], qta_calendar_month('2026-10', '2026-09-29'), 'tetori 2026');
  t_eq('2028-02-29', qta_calendar_month('2028-02', '2026-09-29')['to'], 'shkurti i vitit të brishtë ka 29 ditë');
  t_eq('2027-02-28', qta_calendar_month('2027-02', '2026-09-29')['to'], 'shkurti i zakonshëm ka 28');
  t_eq(['2026-12-01', '2026-12-31', 'Dhjetor 2026'], array_values(array_intersect_key(qta_calendar_month('2026-12', '2026-09-29'), ['from' => 1, 'to' => 1, 'label' => 1])), 'dhjetori');
  t_eq('Nëntor 2026', qta_calendar_month('2026-11', '2026-09-29')['label'], 'emri me ë');
  foreach (['2026-13', '2026-00', '26-10', '2026-1', 'abc', '', null, ['2026-10'], '1899-12'] as $bad) {
    t_eq('2026-09', qta_calendar_month($bad, '2026-09-29')['month'], 'vlerë e pavlefshme → muaji i sotëm: ' . json_encode($bad));
  }
});

t_case('Kalendari: intervali që pranon pika e të dhënave', function () {
  t_eq(['from' => '2026-10-01', 'to' => '2026-10-31'], qta_calendar_range('2026-10-01', '2026-10-31'), 'një muaj');
  t_eq(['from' => '2026-10-01', 'to' => '2026-10-01'], qta_calendar_range('2026-10-01', '2026-10-01'), 'një ditë');
  t_eq('2026-12-01', qta_calendar_range('2026-10-01', '2026-12-01')['to'], QTA_CAL_MAX_RANGE_DAYS . ' ditë: kufiri i lejuar');
  $bad = [
    ['2026-10-31', '2026-10-01', 'fillimi pas mbarimit'],
    ['2026-10-01', '2026-12-02', 'më shumë se ' . QTA_CAL_MAX_RANGE_DAYS . ' ditë'],
    ['01.10.2026', '31.10.2026', 'jo ISO'],
    ['2026-02-30', '2026-03-10', 'datë që nuk ekziston'],
    ['2026-10-01', null, 'mungon mbarimi'],
    [['2026-10-01'], '2026-10-31', 'varg në vend të datës'],
    ["2026-10-01' OR 1=1 --", '2026-10-31', 'tekst i rastësishëm'],
    ['1800-01-01', '1800-01-31', 'jashtë viteve të lejuara'],
  ];
  foreach ($bad as [$from, $to, $what]) {
    t_throws(QtaUserError::class, fn() => qta_calendar_range($from, $to), 'refuzohet: ' . $what, 'Kalendari');
  }
});

t_case('Kalendari: kohëzgjatja përfshin ditën e fillimit dhe të mbarimit', function () {
  t_eq(1, qta_calendar_days('2026-10-01', '2026-10-01'), 'fillimi = mbarimi: 1 ditë');
  t_eq(20, qta_calendar_days('2026-10-01', '2026-10-20'), '01.10–20.10: 20 ditë');
  t_eq(7, qta_calendar_days('2026-12-28', '2027-01-03'), 'kalimi i vitit');
  t_eq(3, qta_calendar_days('2028-02-28', '2028-03-01'), 'viti i brishtë: 28.02, 29.02, 01.03');
  t_eq(3, qta_calendar_days('2026-10-24', '2026-10-26'), 'ndërrimi i orës (25.10) nuk ndikon: data pa orë');
  t_eq(3, qta_calendar_days('2026-03-28', '2026-03-30'), 'ndërrimi i orës në mars nuk ndikon');
});

t_case('Kalendari: grupi si ngjarje — datat e ruajtura, pa të dhëna personale', function () {
  $row = ['id' => 12, 'course_id' => 5, 'course_name' => 'Microsoft Office', 'course_code' => 'MSO-50', 'start_date' => '2026-10-01',
          'end_date' => '2026-10-20', 'is_completed' => 0, 'model' => 'scheduled', 'members' => '7'];
  $e = qta_calendar_event($row, '2026-10-05');
  t_eq(['2026-10-01', '2026-10-20', 20, 7], [$e['start'], $e['end'], $e['days'], $e['members']], 'fillimi, mbarimi (përfshirës), ditët, kursantët');
  t_eq(['active', 'Në mësim'], [$e['status']['key'], $e['status']['label']], 'gjendja nga qta_group_state_meta');
  t_eq(['lesson_group.php?id=12', 'course.php?id=5', false], [$e['href'], $e['course_href'], $e['legacy']], 'lidhjet e grupit me orar dhe të kursit');
  t_eq(['id', 'course_id', 'course', 'code', 'start', 'end', 'days', 'members', 'legacy', 'status', 'href', 'course_href'], array_keys($e), 'vetëm fushat e rreshtit: asnjë emër, amzë apo numër personal');

  $legacy = qta_calendar_event(['model' => 'legacy'] + $row, '2026-10-05');
  t_eq(['groups.php?group=12', true], [$legacy['href'], $legacy['legacy']], 'grupi i regjistrit të vjetër hapet te regjistri i vjetër');
  t_eq(1, qta_calendar_event(['start_date' => '2026-10-20', 'end_date' => '2026-10-20'] + $row, '2026-10-05')['days'], 'grup njëditor');
});
