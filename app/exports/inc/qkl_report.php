<?php
declare(strict_types=1);

/**
 * Data and presentation helpers for "Raporti për QKL".
 *
 * The HTTP endpoint keeps authentication, CSRF and streaming concerns. This file
 * owns the one canonical dataset consumed by both XLSX and PDF output.
 */

function qkl_report_subject(): array
{
  return [
    ['label' => 'Emërtimi i Subjektit:', 'value' => 'Qendra e Trajnimeve të Avancuara'],
    ['label' => 'NIPT:', 'value' => 'L61325037A'],
    ['label' => 'Numër Licence:', 'value' => 'LN-2358-11-2016'],
    ['label' => 'Adresë/Kontakt:', 'value' => 'Rruga Bilal Konxholli'],
  ];
}

function qkl_report_headers(): array
{
  return [
    'Nr.ID',
    'Emër',
    'Atësi',
    'Mbiemër',
    'Shtetësia',
    'Gjinia',
    'Datëlindje',
    'Arsimi',
    'Nr.Amze',
    'Emërtimi i kursit',
    'Datë fillimi',
    'Datë mbarimi',
    'Datë certifikimi',
    'Datë ndërprerje',
  ];
}

function qkl_iso_to_dmy(?string $iso): string
{
  $iso = trim((string)$iso);
  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $parts)) return '';
  $year = (int)$parts[1];
  $month = (int)$parts[2];
  $day = (int)$parts[3];
  if (!checkdate($month, $day, $year)) return '';
  return sprintf('%02d-%02d-%04d', $day, $month, $year);
}

function qkl_clean_key(string $value): string
{
  return strtr(mb_strtolower(trim($value), 'UTF-8'), [
    'ë' => 'e',
    'ç' => 'c',
  ]);
}

function qkl_gender_label(?string $code, ?string $label): string
{
  $code = strtoupper(trim((string)$code));
  if ($code === 'M') return 'Mashkull';
  if ($code === 'F') return 'Femër';
  return trim((string)$label);
}

function qkl_education_label(?string $code, ?string $label): string
{
  $code = strtoupper(trim((string)$code));
  if ($code === 'AU') return 'Arsim 8/9 vjeçar';
  if ($code === 'AM') return 'Arsim i mesëm';
  if ($code === 'AL') return 'Arsim i lartë';

  $original = trim((string)$label);
  $normalized = qkl_clean_key($original);
  if ($normalized === '') return '';
  if (str_contains($normalized, '8') || str_contains($normalized, '9') || str_contains($normalized, 'ulet')) {
    return 'Arsim 8/9 vjeçar';
  }
  if (str_contains($normalized, 'mes')) return 'Arsim i mesëm';
  if (str_contains($normalized, 'lart')) return 'Arsim i lartë';

  // QKL should not erase a valid database label merely because it is new to the mapper.
  return $original;
}

function qkl_first_nonempty(array $record, array $keys): ?string
{
  foreach ($keys as $key) {
    if (!array_key_exists($key, $record) || $record[$key] === null) continue;
    $value = trim((string)$record[$key]);
    if ($value !== '') return $value;
  }
  return null;
}

/**
 * Resolve the ranked SQL candidates into the report's canonical record.
 *
 * A real course-group membership wins. Without one, the ranked non-cancelled
 * student_course_plans record supplies the course and, only when its group_id
 * still points to a group, the group's real dates. selected_at/assigned_at are
 * used only to rank plans in SQL and are never reported as course dates.
 */
function qkl_normalize_record(array $record): array
{
  $hasGroup = qkl_first_nonempty($record, ['group_id']) !== null;

  if ($hasGroup) {
    $courseName = qkl_first_nonempty($record, ['group_course_name']) ?? '';
    $startDate = qkl_first_nonempty($record, ['group_start_date']);
    $endDate = qkl_first_nonempty($record, ['group_end_date']);
    // cgs.exam_date is the individual date; cg.exam_date is the legacy/default fallback.
    $examDate = qkl_first_nonempty($record, ['group_member_exam_date', 'group_legacy_exam_date']);
  } else {
    $courseName = qkl_first_nonempty($record, ['plan_course_name']) ?? '';
    $startDate = qkl_first_nonempty($record, ['plan_start_date']);
    $endDate = qkl_first_nonempty($record, ['plan_end_date']);
    $examDate = qkl_first_nonempty($record, ['plan_exam_date']);
  }

  return array_merge($record, [
    'course_name' => $courseName,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'exam_date' => $examDate,
    // The current schema has no canonical interruption/cancellation timestamp.
    'interruption_date' => qkl_first_nonempty($record, ['interruption_date']),
  ]);
}

function qkl_normalize_records(array $records): array
{
  return array_map('qkl_normalize_record', $records);
}

function qkl_build_rows(array $records): array
{
  $rows = [];
  foreach ($records as $record) {
    $rows[] = [
      (string)($record['personal_number'] ?? ''),
      (string)($record['first_name'] ?? ''),
      (string)($record['father_name'] ?? ''),
      (string)($record['last_name'] ?? ''),
      trim((string)($record['citizenship_value'] ?? '')) !== '' ? (string)$record['citizenship_value'] : 'Shqiptare',
      qkl_gender_label($record['gender_code'] ?? null, $record['gender_label'] ?? null),
      qkl_iso_to_dmy($record['birth_date'] ?? null),
      qkl_education_label($record['edu_code'] ?? null, $record['edu_label'] ?? null),
      (string)($record['nr_amze'] ?? ''),
      (string)($record['course_name'] ?? ''),
      qkl_iso_to_dmy($record['start_date'] ?? null),
      qkl_iso_to_dmy($record['end_date'] ?? null),
      qkl_iso_to_dmy($record['exam_date'] ?? null),
      qkl_iso_to_dmy($record['interruption_date'] ?? null),
    ];
  }
  return $rows;
}

/**
 * One query, one row per AMZË. Window functions make both candidates deterministic:
 * - latest real membership: group start, then group id;
 * - fallback plan: active planned course first, then linked assigned/completed history,
 *   then unlinked assigned/completed history; cancelled plans never describe the
 *   current/relevant course and cannot provide a trustworthy interruption date.
 */
function qkl_dataset_sql(string $citizenshipSelect): string
{
  return "
    SELECT
      s.nr_amze,
      p.personal_number,
      p.first_name,
      p.father_name,
      p.last_name,
      p.birth_date,
      $citizenshipSelect,
      g.code AS gender_code,
      g.label AS gender_label,
      el.code AS edu_code,
      el.label AS edu_label,
      lastg.group_id,
      lastg.course_name AS group_course_name,
      lastg.start_date AS group_start_date,
      lastg.end_date AS group_end_date,
      lastg.member_exam_date AS group_member_exam_date,
      lastg.legacy_exam_date AS group_legacy_exam_date,
      lastp.plan_id,
      lastp.status AS plan_status,
      lastp.group_id AS plan_group_id,
      lastp.course_name AS plan_course_name,
      lastp.start_date AS plan_start_date,
      lastp.end_date AS plan_end_date,
      lastp.exam_date AS plan_exam_date,
      NULL AS interruption_date
    FROM students s
    JOIN persons p ON p.id = s.person_id
    LEFT JOIN genders g ON g.id = p.gender_id
    LEFT JOIN education_levels el ON el.id = s.education_level_id
    LEFT JOIN (
      SELECT ranked.student_id, ranked.group_id, ranked.course_name, ranked.start_date,
             ranked.end_date, ranked.member_exam_date, ranked.legacy_exam_date
      FROM (
        SELECT
          cgs.student_id,
          cg.id AS group_id,
          c.name AS course_name,
          cg.start_date,
          cg.end_date,
          cgs.exam_date AS member_exam_date,
          cg.exam_date AS legacy_exam_date,
          ROW_NUMBER() OVER (
            PARTITION BY cgs.student_id
            ORDER BY cg.start_date DESC, cg.id DESC
          ) AS rn
        FROM course_group_students cgs
        JOIN course_groups cg ON cg.id = cgs.group_id
        JOIN courses c ON c.id = cg.course_id
      ) ranked
      WHERE ranked.rn = 1
    ) lastg ON lastg.student_id = s.id
    LEFT JOIN (
      SELECT ranked.student_id, ranked.plan_id, ranked.status, ranked.group_id,
             ranked.course_name, ranked.start_date, ranked.end_date, ranked.exam_date
      FROM (
        SELECT
          scp.student_id,
          scp.id AS plan_id,
          scp.status,
          scp.group_id,
          COALESCE(group_course.name, plan_course.name) AS course_name,
          plan_group.start_date,
          plan_group.end_date,
          plan_group.exam_date,
          ROW_NUMBER() OVER (
            PARTITION BY scp.student_id
            ORDER BY
              CASE
                WHEN scp.status = 'planned' THEN 0
                WHEN scp.status = 'assigned' AND plan_group.id IS NOT NULL THEN 1
                WHEN scp.status = 'completed' AND plan_group.id IS NOT NULL THEN 2
                WHEN scp.status = 'assigned' THEN 3
                ELSE 4
              END ASC,
              COALESCE(plan_group.start_date, DATE(scp.assigned_at), DATE(scp.selected_at)) DESC,
              scp.id DESC
          ) AS rn
        FROM student_course_plans scp
        JOIN courses plan_course ON plan_course.id = scp.course_id
        LEFT JOIN course_groups plan_group ON plan_group.id = scp.group_id
        LEFT JOIN courses group_course ON group_course.id = plan_group.course_id
        WHERE scp.status IN ('planned', 'assigned', 'completed')
      ) ranked
      WHERE ranked.rn = 1
    ) lastp ON lastp.student_id = s.id
    WHERE CAST(s.nr_amze AS UNSIGNED) BETWEEN :amze_start AND :amze_end
    ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
  ";
}

function qkl_fetch_records(PDO $pdo, int $amzeStart, int $amzeEnd, string $citizenshipSelect): array
{
  $stmt = $pdo->prepare(qkl_dataset_sql($citizenshipSelect));
  $stmt->bindValue(':amze_start', $amzeStart, PDO::PARAM_INT);
  $stmt->bindValue(':amze_end', $amzeEnd, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function qkl_render_pdf_html(array $rows): string
{
  $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $headers = qkl_report_headers();
  $subject = qkl_report_subject();
  $columnClasses = [
    'col-id', 'col-name', 'col-name', 'col-name', 'col-citizen', 'col-gender',
    'col-date', 'col-edu', 'col-amze', 'col-course', 'col-date', 'col-date',
    'col-date', 'col-date',
  ];

  ob_start(); ?>
  <!doctype html>
  <html lang="sq">
  <head>
    <meta charset="UTF-8">
    <style>
      @page { margin: 28.35pt; } /* 10 mm print-safe margin */
      * { box-sizing: border-box; }
      html { margin: 0; padding: 0; }
      body { margin: 10mm; padding: 0; color: #111; font-family: DejaVu Sans, sans-serif; }
      .subject { width: 100%; margin: 0 0 3mm; border-collapse: collapse; table-layout: fixed; }
      .subject td { border: 0; padding: .45mm .8mm; font-size: 8pt; line-height: 1.2; }
      .subject td:first-child { width: 37mm; padding-left: 0; font-weight: bold; }
      .report { width: 100%; border-collapse: collapse; table-layout: fixed; }
      .report thead { display: table-header-group; }
      .report tfoot { display: table-footer-group; }
      .report tr { page-break-inside: avoid; break-inside: avoid; }
      .report th, .report td {
        border: .2mm solid #666;
        padding: 1.05mm .65mm;
        vertical-align: top;
        font-size: 7pt;
        line-height: 1.18;
        overflow-wrap: break-word;
        word-wrap: break-word;
      }
      .report th { padding-top: 1.2mm; padding-bottom: 1.2mm; background: #ececec; font-size: 6.8pt; font-weight: bold; vertical-align: middle; }
      .report td:nth-child(1),
      .report td:nth-child(7),
      .report td:nth-child(9),
      .report td:nth-child(11),
      .report td:nth-child(12),
      .report td:nth-child(13),
      .report td:nth-child(14) { white-space: nowrap; }
      .col-id { width: 8%; }
      .col-name { width: 6.5%; }
      .col-citizen { width: 6%; }
      .col-gender { width: 5%; }
      .col-date { width: 7%; }
      .col-edu { width: 8%; }
      .col-amze { width: 6%; }
      .col-course { width: 12.5%; }
    </style>
  </head>
  <body>
    <table class="subject">
      <tbody>
        <?php foreach ($subject as $item): ?>
          <tr><td><?= $escape($item['label']) ?></td><td><?= $escape($item['value']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <table class="report">
      <colgroup>
        <?php foreach ($columnClasses as $class): ?><col class="<?= $class ?>"><?php endforeach; ?>
      </colgroup>
      <thead>
        <tr>
          <?php foreach ($headers as $header): ?><th><?= $escape($header) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <?php foreach ($row as $cell): ?><td><?= $escape($cell) ?></td><?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </body>
  </html>
  <?php
  return (string)ob_get_clean();
}
