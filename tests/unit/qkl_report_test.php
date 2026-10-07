<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/exports/inc/qkl_report.php';

function qkl_test_record(array $extra = []): array
{
  return array_merge([
    'personal_number' => 'A001234567',
    'first_name' => 'Arta',
    'father_name' => 'Ilir',
    'last_name' => 'Hoxha',
    'citizenship_value' => 'Shqiptare',
    'gender_code' => 'F',
    'gender_label' => 'Femër',
    'birth_date' => '1991-02-03',
    'edu_code' => 'AM',
    'edu_label' => 'Arsimi i mesëm',
    'nr_amze' => '005601',
    'group_id' => null,
    'group_course_name' => null,
    'group_start_date' => null,
    'group_end_date' => null,
    'group_member_exam_date' => null,
    'group_legacy_exam_date' => null,
    'plan_id' => null,
    'plan_status' => null,
    'plan_group_id' => null,
    'plan_course_name' => null,
    'plan_start_date' => null,
    'plan_end_date' => null,
    'plan_exam_date' => null,
    'interruption_date' => null,
  ], $extra);
}

t_case('QKL: rendi dhe emrat e 14 kolonave mbeten kontrata zyrtare', function (): void {
  t_eq([
    'Nr.ID', 'Emër', 'Atësi', 'Mbiemër', 'Shtetësia', 'Gjinia', 'Datëlindje',
    'Arsimi', 'Nr.Amze', 'Emërtimi i kursit', 'Datë fillimi', 'Datë mbarimi',
    'Datë certifikimi', 'Datë ndërprerje',
  ], qkl_report_headers(), 'header-at dhe rendi i tyre');
});

t_case('QKL: grupi real fiton ndaj kursit të planifikuar', function (): void {
  $row = qkl_normalize_record(qkl_test_record([
    'group_id' => 17,
    'group_course_name' => 'Kursi i grupit',
    'group_start_date' => '2026-01-02',
    'group_end_date' => '2026-02-03',
    'group_member_exam_date' => '2026-02-10',
    'plan_id' => 90,
    'plan_status' => 'planned',
    'plan_course_name' => 'Kursi në pritje',
  ]));
  t_eq('Kursi i grupit', $row['course_name'], 'kursi merret nga anëtarësia reale');
  t_eq('2026-01-02', $row['start_date'], 'fillimi merret nga grupi');
  t_eq('2026-02-03', $row['end_date'], 'mbarimi merret nga grupi');
  t_eq('2026-02-10', $row['exam_date'], 'provimi individual merret nga anëtarësia');
});

t_case('QKL: kursi i planifikuar plotëson emërtimin pa shpikur data', function (): void {
  $row = qkl_normalize_record(qkl_test_record([
    'plan_id' => 91,
    'plan_status' => 'planned',
    'plan_course_name' => 'Hidroizolues',
  ]));
  t_eq('Hidroizolues', $row['course_name'], 'kursi fallback nuk humbet');
  t_eq(null, $row['start_date'], 'selected_at nuk bëhet fillim kursi');
  t_eq(null, $row['end_date'], 'assigned_at nuk bëhet mbarim kursi');
  t_eq(null, $row['exam_date'], 'pa grup nuk shpiket provim');
});

t_case('QKL: plani me group_id përdor datat reale të atij grupi', function (): void {
  $row = qkl_normalize_record(qkl_test_record([
    'plan_id' => 92,
    'plan_status' => 'assigned',
    'plan_group_id' => 22,
    'plan_course_name' => 'Saldator',
    'plan_start_date' => '2026-03-04',
    'plan_end_date' => '2026-04-05',
    'plan_exam_date' => '2026-04-12',
  ]));
  t_eq('Saldator', $row['course_name'], 'kursi merret nga plani i lidhur');
  t_eq('2026-03-04', $row['start_date'], 'fillimi real i grupit të planit');
  t_eq('2026-04-05', $row['end_date'], 'mbarimi real i grupit të planit');
  t_eq('2026-04-12', $row['exam_date'], 'provimi legacy/default i grupit të planit');
});

t_case('QKL: provimi individual fiton dhe data legacy është fallback', function (): void {
  $individual = qkl_normalize_record(qkl_test_record([
    'group_id' => 30,
    'group_member_exam_date' => '2026-06-07',
    'group_legacy_exam_date' => '2026-06-08',
  ]));
  t_eq('2026-06-07', $individual['exam_date'], 'data individuale ka përparësi');

  $legacy = qkl_normalize_record(qkl_test_record([
    'group_id' => 31,
    'group_member_exam_date' => null,
    'group_legacy_exam_date' => '2026-06-08',
  ]));
  t_eq('2026-06-08', $legacy['exam_date'], 'data e grupit mbulon të dhënat legacy');
});

t_case('QKL: arsimi i panjohur por i vlefshëm nuk humbet', function (): void {
  t_eq('Master profesional', qkl_education_label('MP', '  Master profesional  '), 'fallback te etiketa e databazës');
  t_eq('Arsim 8/9 vjeçar', qkl_education_label('AU', 'Arsimi i ulët'), 'mapping-u AU');
});

t_case('QKL: ndërprerja formatohet vetëm kur ekziston një datë canonike', function (): void {
  $withDate = qkl_build_rows([qkl_normalize_record(qkl_test_record([
    'interruption_date' => '2026-07-09',
  ]))]);
  t_eq('09-07-2026', $withDate[0][13], 'data canonike formatohet');

  $withoutDate = qkl_build_rows([qkl_normalize_record(qkl_test_record())]);
  t_eq('', $withoutDate[0][13], 'mungesa e datës mbetet bosh');
});

t_case('QKL: datat janë dd-mm-YYYY me zero në fillim', function (): void {
  t_eq('03-02-1991', qkl_iso_to_dmy('1991-02-03'), 'formati i datës');
  t_eq('', qkl_iso_to_dmy('2026-02-30'), 'data jo valide nuk raportohet');
});

t_case('QKL: normalizimi dhe ndërtimi i rreshtave nuk humbin records', function (): void {
  $records = [];
  for ($i = 1; $i <= 100; $i++) {
    $records[] = qkl_test_record([
      'nr_amze' => str_pad((string)$i, 6, '0', STR_PAD_LEFT),
      'plan_course_name' => 'Kursi ' . $i,
      'plan_status' => 'planned',
      'plan_id' => $i,
    ]);
  }
  $rows = qkl_build_rows(qkl_normalize_records($records));
  t_eq(100, count($rows), 'numri i rreshtave ruhet');
  t_eq('000001', $rows[0][8], 'rreshti i parë ruhet');
  t_eq('000100', $rows[99][8], 'rreshti i fundit ruhet');
  t_ok(count(array_filter($rows, static fn(array $row): bool => count($row) === 14)) === 100, 'secilin rresht ka 14 fusha');
});

t_case('QKL: SQL ka renditje deterministike dhe përjashton planet e anuluara', function (): void {
  $sql = qkl_dataset_sql("'Shqiptare' AS citizenship_value");
  t_ok(substr_count($sql, 'ROW_NUMBER() OVER') === 2, 'nga një window rank për grupin dhe planin');
  t_ok(str_contains($sql, "WHERE scp.status IN ('planned', 'assigned', 'completed')"), 'cancelled nuk zgjidhet si kurs aktual');
  t_ok(str_contains($sql, 'cg.start_date DESC, cg.id DESC'), 'grupi i fundit zgjidhet deterministikisht');
  t_ok(str_contains($sql, 'scp.id DESC'), 'barazimet e planeve zgjidhen deterministikisht');
  t_ok(str_contains($sql, ':amze_start') && str_contains($sql, ':amze_end'), 'intervali mbetet me prepared parameters');
});

t_case('QKL: PDF është një tabelë e vazhdueshme me header që përsëritet', function (): void {
  $rows = array_fill(0, 100, qkl_build_rows([qkl_normalize_record(qkl_test_record([
    'group_id' => 1,
    'group_course_name' => 'Përgjegjës për Mbrojtjen Dhe Sigurinë e Shëndetit Në Punë',
    'group_start_date' => '2026-01-02',
    'group_end_date' => '2026-02-03',
  ]))])[0]);
  $html = qkl_render_pdf_html($rows);
  t_eq(1, substr_count($html, '<table class="report">'), 'vetëm një tabelë raporti');
  t_ok(str_contains($html, '.report thead { display: table-header-group; }'), 'thead përsëritet në faqe');
  t_ok(str_contains($html, 'page-break-inside: avoid'), 'rreshti nuk ndahet mes faqeve');
  t_ok(str_contains($html, '@page { margin: 28.35pt; } /* 10 mm print-safe margin */'), 'margjinat e printimit janë 10 mm në të katër anët');
  t_ok(str_contains($html, 'body { margin: 10mm;'), 'Dompdf merr margjinën reale 10 mm nga trupi i dokumentit');
  t_ok(!str_contains($html, 'class="page"'), 'nuk ka faqe/chunks manuale');
  t_ok(str_contains($html, 'LN-2358-11-2016'), 'licenca është e njëjtë në PDF');
  t_ok(str_contains($html, 'Përgjegjës për Mbrojtjen'), 'teksti i kursit mbetet në dokument');
});

