<?php
declare(strict_types=1);

/**
 * Pikët sipas moduleve — leximi i pikëve dhe llogaritja e rezultatit (pa databazë).
 * Të njëjtat rregulla i zbaton edhe group-results.js për parashikimin në faqe.
 */

require_once __DIR__ . '/../../app/shared/results.php';

/** Pikët si tekst → qindëshe, ose mesazhi i gabimit. */
function res_parse($raw)
{
  try {
    return qta_score_parse($raw);
  } catch (QtaUserError $e) {
    return 'ERR:' . ($e->data['code'] ?? '');
  }
}

t_case('Pikët: shkrimi që pranohet', function () {
  t_eq(8500, res_parse('85'), '"85"');
  t_eq(8550, res_parse('85,5'), '"85,5" (presje)');
  t_eq(8550, res_parse('85.5'), '"85.5" (pikë)');
  t_eq(8525, res_parse('85,25'), '"85,25"');
  t_eq(8500, res_parse('85,'), '"85," (presja në fund)');
  t_eq(8500, res_parse(' 85 '), 'hapësirat hiqen');
  t_eq(8500, res_parse('085'), 'zero para numrit');
  t_eq(0, res_parse('0'), '0 është pikë e vlefshme');
  t_eq(0, res_parse('0,00'), '"0,00"');
  t_eq(10000, res_parse('100'), '100');
  t_eq(10000, res_parse('100,00'), '"100,00"');
  t_eq(8550, res_parse(85.5), 'numër nga JSON');
  t_eq(null, res_parse(''), 'bosh → pa pikë (jo 0)');
  t_eq(null, res_parse('   '), 'vetëm hapësira → pa pikë');
  t_eq(null, res_parse(null), 'null → pa pikë');
});

t_case('Pikët: shkrimi që refuzohet, me mesazhin e duhur', function () {
  t_eq('ERR:score_range', res_parse('100,01'), 'mbi 100');
  t_eq('ERR:score_range', res_parse('101'), '101');
  t_eq('ERR:score_range', res_parse('1000'), '1000');
  t_eq('ERR:score_range', res_parse('-1'), 'negative');
  t_eq('ERR:score_precision', res_parse('85,255'), 'tri shifra pas presjes');
  t_eq('ERR:score_format', res_parse('abc'), 'shkronja');
  t_eq('ERR:score_format', res_parse('1e2'), 'shkrim shkencor');
  t_eq('ERR:score_format', res_parse('8,5,5'), 'dy presje');
  t_eq('ERR:score_format', res_parse(',5'), 'pa numër para presjes');
  t_eq('ERR:score_format', res_parse(true), 'e vërtetë/e rreme');
  t_eq('ERR:score_format', res_parse(['85']), 'listë');
  t_throws(QtaUserError::class, fn() => qta_score_parse('abc'), 'mesazhi shpjegon çfarë pritet', 'nga 0 deri në 100');
});

t_case('Pikët: shfaqja dhe formati i bazës', function () {
  t_eq('85,5', qta_score_label(8550), '85,5');
  t_eq('84', qta_score_label(8400), '84 pa ,00');
  t_eq('83,33', qta_score_label(8333), '83,33');
  t_eq('0', qta_score_label(0), '0');
  t_eq('0,05', qta_score_label(5), '0,05');
  t_eq('—', qta_score_label(null), 'mungon → —');
  t_eq('85.50', qta_score_db(8550), 'baza: 85.50');
  t_eq('0.00', qta_score_db(0), 'baza: 0.00');
  t_eq('100.00', qta_score_db(10000), 'baza: 100.00');
  t_eq(8550, qta_score_from_db('85.50'), 'nga baza: 85.50');
  t_eq(null, qta_score_from_db(null), 'nga baza: NULL');
  t_eq(12000, qta_score_from_db('120.00'), 'të dhëna të vjetra jashtë 0–100 lexohen ashtu siç janë');
  t_eq(-500, qta_score_from_db('-5.00'), 'edhe negative');
  t_eq('-5', qta_score_label(-500), 'dhe shfaqen ashtu siç janë');
});

t_case('Rezultati: mesatarja e moduleve, vetëm kur çdo modul ka pikë', function () {
  $mods = [11, 12, 13, 14, 15];
  /* Shembulli i kërkesës: Word 85, Excel 90, PowerPoint 75, Outlook 80, Access 90 → 84. */
  $r = qta_results_compute($mods, [11 => 8500, 12 => 9000, 13 => 7500, 14 => 8000, 15 => 9000]);
  t_eq('modules', $r['mode'], 'rezultat nga modulet');
  t_eq(true, $r['complete'], 'i plotë');
  t_eq(8400, $r['final'], '(85+90+75+80+90)/5 = 84');
  t_eq([], $r['missing'], 'asnjë modul pa pikë');

  /* Word 90, Excel 80, PowerPoint bosh → i paplotë: jo (90+80+0)/3, jo (90+80)/2. */
  $r = qta_results_compute([11, 12, 13], [11 => 9000, 12 => 8000]);
  t_eq(false, $r['complete'], 'i paplotë');
  t_eq(null, $r['final'], 'pa rezultat zyrtar');
  t_eq(8500, $r['partial'], 'mesatarja e moduleve me pikë (vetëm për pamje)');
  t_eq([13], $r['missing'], 'PowerPoint mungon');
  t_eq(2, $r['scored'], '2 nga 3');

  /* Shembulli i llogaritjes së menjëhershme: 80, 90, 70 → 80; Excel 100 → 83,33. */
  t_eq(8000, qta_results_compute([1, 2, 3], [1 => 8000, 2 => 9000, 3 => 7000])['final'], '80, 90, 70 → 80,00');
  t_eq(8333, qta_results_compute([1, 2, 3], [1 => 8000, 2 => 10000, 3 => 7000])['final'], '80, 100, 70 → 83,33');
});

t_case('Rezultati: 0 është pikë, bosh nuk është 0', function () {
  $r = qta_results_compute([1, 2], [1 => 0, 2 => 0]);
  t_eq(true, $r['complete'], 'dy module me 0 janë të plota');
  t_eq(0, $r['final'], 'rezultati 0 (jo mungesë)');
  $r = qta_results_compute([1, 2], [1 => 0]);
  t_eq(false, $r['complete'], 'një 0 dhe një bosh: i paplotë');
  t_eq(null, $r['final'], 'pa rezultat');
  $r = qta_results_compute([1, 2], [1 => 0, 2 => null]);
  t_eq(false, $r['complete'], 'null brenda listës = bosh');
});

t_case('Rezultati: rrumbullakimi gjysma lart, me numra të plotë', function () {
  t_eq(8334, qta_results_compute([1, 2], [1 => 8333, 2 => 8334])['final'], '83,335 → 83,34');
  t_eq(8333, qta_results_compute([1, 2, 3], [1 => 8333, 2 => 8333, 3 => 8334])['final'], '83,3333 → 83,33');
  t_eq(6667, qta_results_compute([1, 2, 3], [1 => 10000, 2 => 10000, 3 => 0])['final'], '66,666… → 66,67');
  t_eq(10000, qta_results_compute([1], [1 => 10000])['final'], 'një modul me 100');
  t_eq(1, qta_results_round_avg(1, 1), '0,01');
  t_eq(1, qta_results_round_avg(1, 2), '0,005 → 0,01');
  t_eq(0, qta_results_round_avg(1, 3), '0,00333 → 0,00');
});

t_case('Rezultati: pikët e vjetra dhe kursi pa module', function () {
  $r = qta_results_compute([1, 2, 3], [], 8000);
  t_eq('legacy', $r['mode'], 'vetëm pikë të vjetra');
  t_eq(8000, $r['final'], 'rezultati mbetet 80');
  t_eq(0, $r['scored'], 'asnjë modul me pikë');

  $r = qta_results_compute([1, 2, 3], [1 => 9000], 8000);
  t_eq('modules', $r['mode'], 'me një pikë moduli, rezultati llogaritet nga modulet');
  t_eq(null, $r['final'], 'i paplotë — jo 80 dhe jo 90');
  t_eq(8000, $r['legacy'], 'pikët e vjetra mbeten të ditura');

  $r = qta_results_compute([1, 2], [1 => 9000, 2 => 7000], 8000);
  t_eq(8000, $r['final'], 'i plotë: 90 dhe 70 → 80 (nga modulet)');

  $r = qta_results_compute([], [], 7500);
  t_eq('legacy', $r['mode'], 'kurs pa module: vetëm pikët e vjetra');
  t_eq(7500, $r['final'], 'pikët e vjetra mbeten');
  $r = qta_results_compute([], [], null);
  t_eq('none', $r['mode'], 'kurs pa module dhe pa pikë');
  t_eq(null, $r['final'], 'pa rezultat');
  t_eq(false, qta_results_compute([], [5 => 9000])['complete'], 'pa module nuk ka "të plotë"');
});

t_case('Rezultati: pikë jashtë moduleve të grupit nuk hyjnë në mesatare', function () {
  $r = qta_results_compute([1, 2], [1 => 8000, 2 => 9000, 99 => 0]);
  t_eq(8500, $r['final'], 'moduli 99 nuk është i grupit');
  t_eq(2, $r['scored'], 'numërohen vetëm modulet e grupit');
});
