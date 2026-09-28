# Courses, modules, topics and scheduled groups

Status: **implemented** on branch `revamp/super-portal` (26.09.2026); conversion of legacy
groups (§14) and the 8-hour daily limit on branch `feature/legacy-conversion` (28.09.2026).
Migrations: `db/migrations/2026-09-26-kurset-modulet-temat-orari.sql`, then
`db/migrations/2026-09-28-konvertimi-i-grupeve.sql` (see `db/migrations/README.md`).
UI components and copy: `docs/design-system/THEMELI.md`.

This document is the reference for the domain change "Kurset → Modulet → Temat", for
the new group register with a day-by-day lesson schedule, and for the conversion of legacy
groups into that register. It records the rules the code enforces, where they are enforced,
and the policies chosen where the product had a choice.

---

## 1. Vocabulary

| UI (Albanian) | Meaning | Storage |
|---|---|---|
| **Kurs / Kurset** | What a trainee enrols in and is certified for. Until this change the UI called it "Modul". | `courses` (table name and IDs unchanged) |
| **Modul / Modulet** | An ordered part of a course, with its own hours. | `course_modules` |
| **Temë / Temat** | An ordered unit of a module, with its own hours. | `course_topics` |
| **Regjistri i kurseve profesionale** | The register of groups with a lesson schedule (`lesson_groups.php`, until phase 14 called "Grupet"). One of its groups is a "grup me orar". | `course_groups.model = 'scheduled'` + `group_schedules` … |
| **Regjistri i vjetër i kurseve profesionale** | Every group that existed before this change, kept exactly as it was (`groups.php`, until phase 14 called "Grupet e mëparshme"). One of its groups is still a "grup i mëparshëm". | `course_groups.model = 'legacy'` |
| **Katalogu i kurseve** | The list of courses with their modules, topics and hours (`courses.php`, until phase 14 called "Kurset"; in the menu under Administrimi, same permissions). | `courses`, `course_modules`, `course_topics` |
| **Orari i mësimit / Ditë pas dite** | The lesson days and which topic hours fall on each day. | `group_schedule_days`, `group_schedule_slots` |
| **Orar i llogaritur** | A schedule calculated from a start date, usual hours per day and special days; the end date follows from it (§6). | `group_schedules.schedule_mode = 'calculated'` |
| **Orar me data historike** | The schedule of a converted group: start and end are the legacy group's historical dates and never move; the hours of every date come from the day plan (§14). | `group_schedules.schedule_mode = 'fixed_range'` |
| **Ditë e veçantë** | A date that differs from the usual pattern (other hours, no lesson, a Sunday with lessons). Calculated schedules only. | `group_day_rules` |
| **Plani i ditëve** | Every date from start to end of a converted group with its hours (0 = no lesson). The source of its schedule. | `group_fixed_days` |
| **Konvertimi i grupeve** | Moving a legacy group, in place, into "Regjistri i kurseve profesionale" (`group_conversions.php`, `group_conversion.php`). | `legacy_conversion_drafts`, `group_conversions` |
| **Grup i konvertuar** | A scheduled group that came from the legacy register. Badge "Konvertuar nga regjistri i vjetër". | `group_conversions.status = 'completed'` |
| ~~Regjistri i plotë~~ | Removed in phase 14 (`register.php`, `register_inline_update.php`, `register_export.php`). Registrations are listed in "Të gjithë kursantët" (`students.php`, with each trainee's group); exam dates and points are edited in the group itself. | no data removed |

Hours are always whole teaching hours ("orë mësimore").

## 2. Curriculum: course → modules → topics

### Rules

- A course has 0…n modules; a module has 0…n topics. Titles are required (module ≤ 200,
  topic ≤ 255 characters); hours are whole numbers ≥ 1.
- **Order is explicit.** `position` is 1…n inside the parent and is the only ordering used
  anywhere (never id, creation time or title). Every write keeps it contiguous; a legacy or
  hand-edited gap is reported as an issue with a one-click "Rregullo radhën".
- **Hour invariants:** Σ module hours = course hours, and for every module Σ topic hours =
  module hours.
- **The parts never exceed the whole — enforced on every save.** Σ topic hours ≤ module
  hours and Σ module hours ≤ course hours; a module cannot drop below its topics, nor a course
  below its modules (`qta_curriculum_assert_topic_hours/_module_hours/_course_hours`, used by
  every write path, including the inline hours cell in `courses.php`). A refused save changes
  nothing and returns `code = hours_limit` with a dialog: what is wrong, how many hours are
  allowed, and — when a valid value exists — a one-click "Vendos 10 orë" that saves it.
  A change that *lowers* hours is always allowed, so data saved before this rule (e.g. topics
  100 h in a 20 h module) can be repaired step by step; opening such a course with the edit
  mode on shows "Orët nuk përputhen" once per browser session.
- Less than the whole is a **draft**: it can be edited and saved, but it is never used to
  create a scheduled group. `qta_course_check()` returns `ready` plus a list of
  plain-language issues, each with a fix where one is valid ("Vendos orët e kursit në 40";
  a module is offered more hours only when the course still has room for them).
- A course is **ready** ("Gati për grup") when it has at least one module, every module has
  at least one topic, both hour invariants hold, and all positions are contiguous.

### Where

| Concern | Code |
|---|---|
| Reads, readiness, summaries | `app/shared/curriculum.php` (`qta_course_modules`, `qta_course_check`, `qta_course_summaries`) |
| Writes (add/update/delete/move/normalize/set hours) | `app/shared/curriculum.php` — each in one transaction, course row locked `FOR UPDATE` |
| JSON endpoint | `app/actions/course_structure_update.php` (POST, Admin/Editor, CSRF, edit mode) |
| Page | `app/pages/course.php` ("Kursi"), linked from `courses.php` |
| Rendering | `app/shared/partials/course_structure.php`, `app/assets/js/curriculum.js` |

Reordering is keyboard-first: up/down arrow buttons ("Lëviz lart" / "Lëviz poshtë") on every module and topic (focus stays
on the moved item, the new position is announced), and a position select in the edit
dialogs. There is no drag-only interaction.

### Deleting

- Deleting a topic or a module is always allowed on the curriculum; it never touches groups,
  because scheduled groups keep their own copy (§5). Deleting a module deletes its topics
  explicitly (so both deletions reach the history), then renumbers the siblings.
- **A course is deleted only when it has no group at all** (legacy or scheduled). The service
  (`qta_course_delete`) blocks it with an explanation, and the database agrees: the
  migration changes `course_groups → courses` from `ON DELETE CASCADE` to `RESTRICT`, so no
  path can delete groups, exam dates and points through a course deletion.

## 3. Two kinds of groups, kept apart

`course_groups.model` is `ENUM('legacy','scheduled') NOT NULL DEFAULT 'legacy'`.
The migration adds it; every existing group receives `legacy` from the default and nothing
else is changed (no dates, exams, points, members or audit rows are rewritten).

Guarantees, from the inside out:

| Layer | Guard |
|---|---|
| Database | `trg_cg_model_guard_bu`: `model` changes only `legacy` → `scheduled`, and only for a group with a conversion in progress (§14.8); `scheduled` → `legacy` never. A scheduled group's `course_id` can never change. `trg_gs_requires_scheduled_bi`: a schedule row can only exist for a `scheduled` group. |
| Services | `qta_lg_require()` refuses legacy groups (`code = legacy_group`); `qta_assert_legacy_group()` makes the legacy actions in `groups.php` refuse scheduled groups. |
| Endpoints | `groups_inline_update.php` refuses start/end date edits on scheduled groups (their dates come from the schedule); `register_inline_update.php`, which did the same, was removed with "Regjistri i plotë". `courses_inline_update.php` refuses moving a scheduled group to another course. |
| Pages | `groups.php` lists only legacy groups and redirects `?group=N` of a scheduled group to `lesson_group.php?id=N`; `lesson_group.php` redirects a legacy id to `groups.php?group=N`. Search, the trainees list, the trainee card, the catalogue and the dashboards link each group to its own area. |

The only way from one kind to the other is the **conversion** of §14: one legacy group at a
time, reviewed and approved by a person, in one transaction. Nothing converts a group
implicitly — a legacy group is never given a schedule, inferred topics or recalculated dates
by any other path, and a scheduled group never goes back to the legacy register.

New groups are created in "Regjistri i kurseve profesionale". "Regjistri i vjetër i kurseve
profesionale" shows one compact notice: "Ky regjistër ruan kurset profesionale të mëparshme.
Konvertoji një nga një te Regjistri i kurseve profesionale, me datat e tyre historike." with
the button "Konvertimi i grupeve"; each group's dialog links to "Përgatit konvertimin". Its own
"Shto grup të mëparshëm" stays only for recording a group held earlier, without a schedule.

## 4. Creating a scheduled group

`lesson_groups.php` → "Krijo grup" (Admin/Editor, edit mode). Inputs: a **ready** course,
start date, hours per usual day (1–8), and optionally the trainees' AMZË
(`3400-3403, 3409`). A live preview shows the end date, number of lesson days and skipped
Sundays before saving (`preview_new`, same engine).

`qta_lg_create()` runs in one transaction:

1. lock the course row and re-check readiness;
2. copy the modules and topics, in order, into `group_schedule_topics` (the **snapshot**);
3. build and independently verify the schedule (§6);
4. resolve trainees exactly like the legacy flow: a registration can be in only one group,
   a person cannot take the same course twice, at most 10 per group; unknown AMZË get the
   same placeholder records as before; more than 10 are split into balanced groups in AMZË
   order, each with the same schedule;
5. insert the group(s) with `model = 'scheduled'`, the schedule header (`revision = 1`),
   the snapshot, days, slots and members;
6. read everything back and verify it again before commit.

Any failure rolls back everything — no half-created group, no stray trainee rows.

## 5. The snapshot and changes to the course (historical policy)

A scheduled group follows **its own copy** of the curriculum, taken when it was created
(`group_schedules.curriculum_taken_at`, `course_hours`). Editing the course never rewrites a
group's topics, days or dates.

- The course page shows how many groups use the course and that they keep their copy.
- The group page shows "Kursi është ndryshuar pas krijimit të grupit" when the live course
  differs from the copy.
- "Merr temat e reja" (refresh the copy) is allowed **only before the group starts**
  (`start_date > today`), only while the group is open, and only when the course is ready.
  After the start it is refused with an explanation: lessons already held must not change.
- Documents use the copy: the procesverbal prints `group_schedules.course_hours`.

## 6. Scheduling rules (`app/shared/schedule.php`)

The engine is pure PHP (no database), uses `DateTimeImmutable` in UTC on ISO dates, and is
deterministic: the same topics, start date, hours per day and special days always produce the
same schedule.

**Hours available on a date**

| Date | Hours |
|---|---|
| A special day with hours *h* | *h* (0 = no lesson) |
| A special Sunday marked "with lessons" | the usual hours per day |
| Any other Sunday | 0 — Sundays have no lessons unless explicitly included |
| Any other day (Monday–Saturday) | the usual hours per day |

**Allocation.** Topics are taken in order (module position, then topic position). Each lesson
day is filled up to its hours; a topic that does not fit continues on the next lesson day; a
module can end and the next one begin inside the same day. The last day receives only the
hours that remain, so it can be shorter. Days with 0 hours are skipped.

**Results.** The **end date is calculated** — it is the date of the last lesson day and is the
source of truth; `course_groups.start_date/end_date` are updated in the same transaction.
The start date must be a lesson day (a Sunday start requires marking that Sunday "with
lessons"). Limits: **8 hours per day** (`QTA_DAY_MAX_HOURS`, §14.3), 3 700 calendar days per
schedule.

The allocation (`qta_sched_allocate`) and the core of the verification
(`qta_sched_verify_allocation`) are shared with the schedules of converted groups (§14.2), so
both kinds divide topics into days by exactly the same rules.

**Verification.** `qta_sched_verify()` re-checks every invariant independently of the
builder: dates strictly increasing, first day = start, last day = end, no day above its
capacity, no plain Sunday used, only the last day may be partial, Σ day hours = Σ slot hours
= course hours, every topic and module receives exactly its hours, order preserved, and no
lesson day skipped. Stored rows are read back and verified again before commit.

**Worked example (acceptance C).** 100-hour course, start Thursday 01.10.2026, 5 hours per
day, Sundays 04.10 and 18.10 without lessons, Sunday 11.10.2026 marked with 4 hours:
21 lesson days, 99 hours up to Thursday 22.10, and the last day **Friday 23.10.2026 with
1 hour**. Removing the 11.10 rule restores the previous schedule exactly (20 days, ending
23.10.2026 with 5 hours).

## 7. Changing a scheduled group

All changes go through `qta_lg_change()` (`app/actions/lesson_group_update.php`, action
`change`):

| Change | Effect |
|---|---|
| `settings` | new start date and/or usual hours per day |
| `rule` | a special day: usual hours / other hours / no lesson / Sunday with lessons / remove |
| `refresh` | take the course's current modules and topics (only before the start, §5) |
| `fixed_days` | converted groups only: the hours (and notes) of some dates of the day plan (§14.10) |

A converted group refuses `settings`, `rule` and `refresh` with an explanation: its start
and end are historical dates and its topics are the copy frozen at conversion.

Every change:

1. locks the schedule row and checks the page's `revision` (someone else's change →
   "Orari i këtij grupi u ndryshua nga dikush tjetër ndërkohë…");
2. recomputes the full schedule from the snapshot and the special days, and verifies it;
3. computes the impact: new end date, number of days, which dates change;
4. **blocks** the change if the new end date would fall after a trainee's exam date
   (exam dates are fixed first, in "Kursantët");
5. **asks for confirmation** if a date before today changes ("Ndryshon edhe ditë që kanë
   kaluar … vazhdo vetëm nëse po korrigjon një gabim") or if the group is closed;
6. writes the rule, the snapshot (refresh only), days, slots, group dates and the schedule
   header (`revision + 1`) atomically, then reads back and verifies.

The dialogs show the same impact before saving (a `dry_run` through the same code path), and
the server-driven confirmation (HTTP 409 with `confirm`) is shown with `qtaConfirm`, then
resent with `force = 1`. Removing a special day restores the earlier schedule exactly.

Rules on dates before the start are refused. A rule outside the current schedule is kept and
labelled "Jashtë orarit tani — përdoret nëse orari zgjatet deri këtu".

## 8. Trainees, exams, points, closing

Unchanged rules, shared with legacy groups: one group per registration, at most 10 trainees,
a person cannot take the same course twice, exam date on or after the group's end date,
points 0–100 only, closing/reopening a group, confirmation before changing a closed group,
and confirmation before removing trainees who already have an exam date or points. The group
page edits exam dates and points inline through the existing `groups_inline_update.php`.

## 9. Permissions

- `course.php`, `lesson_groups.php`, `lesson_group.php`, `group_conversions.php`,
  `group_conversion.php` and their JSON endpoints (`course_structure_update.php`,
  `lesson_group_update.php`, `group_conversion_update.php`) are for **Administrator and
  Editor** only; every endpoint accepts only POST and checks the session, the role (read from
  the database), the CSRF token and the edit mode server-side. Calculations that save nothing
  (previews, the conversion proposal, "Rishpërndaj automatikisht") do not need the edit mode;
  saving a draft, refreshing it, converting and correcting a converted group do.
- Agencies and trainees get no new access: they are redirected away from the new pages and
  keep their existing views (group dates, exams, points), which now say "Kurs".
- Assigning trainees to a group and choosing their course (the former JSON writes of
  `students_without_groups.php`, now `app/actions/student_assignment.php`) check the role,
  the CSRF token and the edit mode server-side, and lock the group row while counting its
  members. `students_without_groups.php` only redirects to `students.php?status=no_group`.

## 10. History (audit)

The existing trigger architecture (`audit_capture`, `@audit_user_id` from `qta_audit_attach`)
is reused:

| Table | Logged |
|---|---|
| `course_modules`, `course_topics` | insert, update, delete |
| `group_schedules` | insert, delete, and updates of usual hours, course hours, curriculum copy time, lesson days; the schedule kind (`schedule_mode`) is part of every row |
| `group_day_rules` | insert, update, delete ("Orari i zakonshëm", "Pa mësim", hours, note) |
| `course_groups` | as before, now including `model` (the conversion appears as `model` legacy → scheduled) |
| `group_conversions` | insert (the conversion: who, when, historical dates, hours, lesson days) and delete (only when the converted group itself is deleted) |
| `group_fixed_days` | every later correction of a converted group, date by date (hours, note) |

Days, slots and snapshot topics are derived data and are not logged row by row; they are
fully determined by the logged inputs. The same holds for the day plan written at conversion:
its hash (`approved_plan_hash`) is stored in the conversion record, and only later corrections
are logged row by row. Drafts are working copies and are not logged. Foreign-key cascades do
not fire triggers, so the services always delete explicitly (topics before modules, schedule
parts before the group, and the day plan and conversion record with a converted group).

## 11. Tests

`php tests/run.php` runs the unit tests (engine and readiness, no database).
`QTA_TEST_DB=1 QTA_DB_NAME=<test db> php tests/run.php --integration` also runs the
database tests; they refuse `qta_db` unless `QTA_ALLOW_MAIN_DB=1`. The test database must
already have both migrations. The HTTP test starts its own `php -S` on a free local port; the
migration test needs the right to create and drop databases (only `qta_migtest_*`).

| File | Covers |
|---|---|
| `tests/unit/schedule_engine_test.php` | calendar facts, acceptance B, C, E, module boundary inside a day, Sunday rules, 0-hour weekdays, input errors, verifier tamper detection, determinism, large totals |
| `tests/unit/curriculum_check_test.php` | acceptance A, hour mismatches and their one-click fixes, ordering, input validation |
| `tests/integration/lesson_groups_test.php` | legacy isolation (D), curriculum CRUD + audit, hour limits (parts never exceed the whole, dialog data, repair of older data, no history for refusals), readiness rollback, C and E through the services and stored rows, course edits after a schedule (F), past-day and closed-group confirmations, exam conflicts, members, legacy guards in services and database, concurrency lock |
| `tests/unit/lesson_register_test.php` | lesson register (§13): numbering 1…N independent of AMZË, 1/10/40 trainees, module order and odd/even page pairs, one column and one row per teaching hour (column k = row k), a topic of X hours written X times with its date, module change inside a day, split topics, month change (29, 30, 1, 2), mid-month start, missing day 1, December → January, Sundays and days without lessons never become columns, special-day hours, more than 31 hours → page pairs with "vazhdim", topic rows always fit their page, a day longer than a page, captions, file names, determinism, text measuring |
| `tests/integration/lesson_register_test.php` | lesson register on stored data: order = Lista emërore (numeric AMZË), one attendance column and one topic row per stored teaching hour, dates = stored lesson days, frozen titles unchanged after course edits, a saved special day appears, legacy and missing groups refused |
| `tests/unit/fixed_range_engine_test.php` | §14: the worked example (50 h, 01.10–10.10.2026), a single day, 8 hours every day with a required Sunday, impossible periods (capacity, boundaries), Sunday policy (avoided, minimum, boundary Sundays allowed), uneven plans and 0-hour days, module change inside a day and split topics, refused plans with clear messages, the independent verifier catching tampered schedules, determinism and balance over many periods, rebalance (manual days kept, boundaries kept, 8-hour cap, order of preference), the spreading and tie-breaking rules |
| `tests/integration/legacy_conversion_test.php` | §14 through the services and the database: preflight, proposal and draft never touch the group; draft revision and stale tabs; course or trainee changes invalidate the draft; atomic in-place conversion (same ID, trainees, AMZË, exams, points, S and E; frozen curriculum, day plan, days, slots, total hours, max 8); the group moves registers without duplication; lesson register and documents after conversion; later corrections inside [S, E]; database guards (arbitrary and reverse model changes, historical dates, schedule kind); rollback after an injected failure; blockers (course, capacity, trainees); Sunday → "Kërkon kontroll"; deleting a converted group |
| `tests/integration/legacy_conversion_http_test.php` | §14 over HTTP (`php -S` on the test database): GET refused, no session, wrong role, missing or wrong CSRF token, edit mode off; the pages refuse non-staff; propose → save → convert through the endpoint; stale revision and changed data refused; a second conversion refused; the correction endpoint of a converted group follows the same rules |
| `tests/integration/migration_conversion_test.php` | the 2026-09-28 migration on fresh databases (`qta_migtest_*`, created and dropped by the test): clean database, legacy groups only, with scheduled groups, run twice (identical schema and data), rows above 8 hours (stops, lists them, changes nothing; passes after correction), 2026-09-26 missing (stops, changes nothing), and 2026-09-26 re-run afterwards |

## 12. Known limitations

- Whole hours only; no half hours or time-of-day slots.
- One usual hours-per-day value per group; weekday patterns (e.g. no Saturdays) are entered
  as special days. There is no holiday calendar.
- Changing the usual hours per day after the start recalculates from the start date; past
  days change only after explicit confirmation (no effective-dated defaults).
- "Ndrysho kursantët" on a scheduled group accepts at most 10 trainees (no automatic split
  after creation).
- Removing trainees from a group leaves their course plan in state `assigned`, as for legacy
  groups (PROGRESS.md, open item 6).
- Agencies and trainees do not see the day-by-day schedule.
- The schedule of a converted group is a **reconstruction** approved by a person, not a record
  of the dates on which lessons were really held: the legacy register never stored them.
- A conversion cannot be undone from the interface (there is no scheduled → legacy path);
  a wrong day plan is corrected inside the historical dates (§14.10). Start, end and course of
  a converted group never change; a group with wrong historical dates is corrected in the
  legacy register **before** conversion.
- Converted groups have no special days and no "Merr temat e reja": their topics are the
  copy taken at conversion.
- PDF/Word/Excel exports need `composer install` on the server (`vendor/` is not in git).
  The lesson register (§13) was generated and checked locally in PDF and in Microsoft Word.

## 13. Lesson register ("Regjistri i orëve të mësimit")

A printable register for one **scheduled** group, in PDF (Dompdf) and Word (.docx, PHPWord),
from "Dokumentet e grupit" on `lesson_group.php`. Legacy groups do not get it: the card is
added only with `qta_group_documents($gid, $csrf, ['lesson_register' => true])`, and the
endpoint refuses legacy groups (`qta_lg_require`, code `legacy_group`). A converted group
gets it like any scheduled group: its frozen copy is the one taken at conversion and its days
come from the stored schedule built from the day plan, so the register needs no special case.

| Concern | Code |
|---|---|
| Model (data, pagination, page pairs) — no HTML, no Word | `app/shared/lesson_register.php` (`qta_lesson_register_build`, `qta_lesson_register_model`) |
| Drawing: PDF and DOCX from the same model | `app/exports/inc/lesson_register_documents.php` |
| Endpoint: POST, CSRF, Administrator/Editor, `format = pdf \| docx` | `app/exports/download_regjistri_mesimit.php` |
| Font for the PDF (Calibri metrics, SIL OFL) | `app/exports/fonts/Carlito-*.ttf` + `OFL.txt` |

**Sources.** Trainees in the order of "Lista emërore"
(`ORDER BY CAST(s.nr_amze AS UNSIGNED), s.nr_amze`): the first is Nr. 1, the second Nr. 2 …
(never the AMZË). Modules and topics from the group's frozen copy
(`group_schedule_topics`), dates from the stored schedule (`group_schedule_days`,
`group_schedule_slots`, annotated by `qta_sched_annotate`) — never the live course, never a
recalculated schedule. All reads run in one transaction.

**Pages.** The unit of both pages is the **teaching hour**: a date on which the module has
X hours appears X times — X columns on the odd page and X rows on the even page, in the same
order (column k of the attendance page is row k of the topics page). For every module in
`module_seq` order: an odd page with the attendance grid and an even page with the dates and
topics. A date belongs to a module when at least one slot of that date is of that module, so a
date where one module ends and the next begins appears in both, each with its own hours. A module
whose hours do not fit becomes several page pairs ("Moduli 2 — vazhdim"): at most 31 hours per
pair (31 columns = 31 rows), and the topic rows (measured with Calibri widths plus a 6 % reserve,
so they never overflow) must fit the even page. The hours of a date are never split between
pairs, except a single day whose topics are longer than a page. Odd pages are always attendance,
even pages always topics, in both formats.

**Attendance page (odd).** As the paper form: title row, "Nr./Dt." corner with a diagonal,
"Muaji:" row, the "Dt." row with the day of the month (1…31, no leading zero) once for every
teaching hour (5 hours on 1 October → "1 1 1 1 1"), 31 narrow columns and 35 rows. Rows 1…N
carry the trainees' numbers; the other rows stay empty and unnumbered. Under "Muaji:" the month
**number** (1 = January … 12 = December) stands over the first column of each month on the
page — so the first date always has its month, and a new month (30 30 | 1 1 1) shows its number
over the first "1", with a thin line where the month changes. Unused columns stay empty.
Attendance boxes stay empty: the system stores no attendance.

**Topics page (even).** "Moduli 1 — Microsoft Word" (Times New Roman Bold, like the form), then
Data | Tema | Shënime with **one row per teaching hour**: a topic taught X hours on a date is
written X times, each row with that date (`dd.mm.yyyy`) and the frozen topic title; a topic that
continues on the next day gets its remaining hours there, so every topic appears exactly as many
times as it has hours. Long titles wrap, "Shënime" stays empty, and empty rows fill the page as
on the form.

**Both formats.** A4 portrait, 2.54 cm margins, black 0.5 pt lines, no colours, a small line
under each table ("Grupi #42 · course · Moduli 2", "Faqja 3 nga 12"). Word: one section, fixed
table layout and exact row heights, a new page with "page break before" on the first paragraph
of each page (no section breaks, no empty pages), the diagonal is a native cell border
(`w:tr2bl`). PDF: Carlito (metric-compatible with Calibri) so lines break as in Word; the
diagonal is drawn on the page; if the PDF does not have the planned page count, the download
fails with a clear message instead of giving a register with mixed odd/even pages. When the
font cannot be prepared, the PDF uses DejaVu Sans at a smaller size and keeps the same pages.

Downloads call the export audit hook `qta_audit_event('lesson_register.download', …)` like the
other group documents; that hook records only when `qta_audit_log()` exists (it does not exist
in the code today, for any document).

## 14. Converting legacy groups ("Konvertimi i grupeve")

A legacy group moves **in place** into "Regjistri i kurseve profesionale": the same group ID,
trainees, AMZË, exam dates, points, documents and history. Nothing is deleted or re-created.
Its start **S** and end **E** are historical facts and never change; the schedule is built
inside them. The legacy register never recorded on which dates lessons were held, so the
system proposes a valid distribution and a person reviews and approves it.

### 14.1 Lifecycle

```
legacy group → preflight → automatic proposal → draft → review and edits
             → final validation → confirmation → atomic conversion → converted group
```

| State (list chip) | Meaning |
|---|---|
| **Gati për përgatitje** | No blockers, no draft yet. |
| **Draft për kontroll** | A saved draft, valid, no Sundays used. |
| **Kërkon kontroll** | Valid but needs a human look: the plan uses an interior Sunday, the plan has an issue (missing/extra hours, a boundary without lessons), or the source data changed after the draft (stale). |
| **Ka probleme** | A blocker that can be fixed elsewhere (course not ready, trainee anomalies). |
| **Nuk mund të konvertohet** | Impossible with these dates: the course hours do not fit in [S, E] at 8 hours per day, or S ≠ E and the course has only 1 hour. |

The first matching state wins, in the order impossible → problems → review → draft → ready
(`qta_conv_status`). The list (`group_conversions.php`) and the review page show the same
state and a short hint ("Përdor 1 të diel", "Mungojnë 3 orë", "Kursi nuk është gati").

### 14.2 Two schedule kinds

`group_schedules.schedule_mode` is `ENUM('calculated','fixed_range')`:

| | `calculated` (created in the new register) | `fixed_range` (converted) |
|---|---|---|
| Inputs | start, usual hours per day, special days | S, E, and the hours of every date in [S, E] |
| End date | calculated (§6) | fixed: always E |
| `daily_hours` | 1–8 | `NULL` — there is no "usual" day, and no fake value is stored |
| Source of truth | `group_day_rules` + engine | `group_fixed_days` (every date, 0 = no lesson) + engine |
| Engine | `qta_sched_build` / `qta_sched_verify` | `qta_sched_build_fixed_range` / `qta_sched_verify_fixed_range` (`app/shared/schedule_fixed.php`, pure PHP) |

Both kinds write the same derived rows (`group_schedule_days`, `group_schedule_slots`) with the
same allocator, so the timetable, the group page and the lesson register read them the same
way. The fixed-range builder refuses a plan that is incomplete, has a date outside [S, E], a
day above 8 hours, S or E without lessons, or a total different from the course hours
(`qta_sched_fixed_issues` — one list of rules used by the builder, the page and the
conversion). The independent verifier re-checks the allocation (`qta_sched_verify_allocation`)
and, separately, the fixed boundaries, the period, that every day follows the plan and all
sums. The stored rows are read back and verified again before every commit.

### 14.3 The 8-hour limit

A lesson day has at most **8 hours**, everywhere: the constant `QTA_DAY_MAX_HOURS`, input
validation, both builders and verifiers, the database CHECKs (`chk_gs_daily`,
`chk_gsd_hours`, `chk_gss_hours`, `chk_gdr_hours`, `chk_gfd_hours`), the pickers in the UI and
the tests. The previous limit was 12. The migration refuses to run while any existing
scheduled group has more than 8 hours on a day; it lists those rows and changes nothing
(`db/migrations/README.md`).

### 14.4 Preflight

`qta_conv_view` / `qta_conv_list` evaluate, without writing anything:

| Check | Kind | Where it is fixed |
|---|---|---|
| Course has a complete structure (modules, topics, hours match) | blocker — "Ka probleme" | "Katalogu i kurseve" (link to the course) |
| Course hours fit: H ≤ calendar days × 8 | blocker — **impossible** | nowhere: the historical dates cannot hold the course |
| S ≠ E needs H ≥ 2 (both boundaries teach) | blocker — **impossible** | nowhere |
| Dates valid, E ≥ S, period ≤ 3 700 days | blocker | legacy register |
| At most 10 trainees | blocker | legacy register (split the group) |
| A registration in two groups | blocker | legacy register |
| The same person (personal number) twice in the same course | blocker | legacy register |
| Exam date before E, points without exam date, points outside 0–100 | blocker | legacy register |
| Interior Sundays needed | **warning** — "Kërkon kontroll" | review them on the calendar |

Nothing is corrected automatically: every problem is stated in words with a link to where it
is fixed. The course hours used are the sum of the current topics (the curriculum that will be
frozen).

### 14.5 Automatic proposal (`qta_sched_propose_fixed_range`)

Deterministic: the same H, S, E always give the same plan.

1. **Days needed:** k = ⌈H / 8⌉, and at least 2 when S ≠ E.
2. **Preferred days:** Monday–Saturday, plus S and E even when they are Sundays (they are
   historical dates, so they are allowed).
3. If there are at least k preferred days, k of them are chosen **evenly over the whole
   period**, S and E always included: index j (0 … k−1) takes the preferred day
   round(j · (m − 1) / (k − 1)), where m is the number of preferred days. **Tie-break:** a
   half rounds up, computed in integers as ⌊(2j(m − 1) + (k − 1)) / (2(k − 1))⌋
   (`qta_sched_spread`). For k ≤ m the indices strictly increase, so no day is taken twice.
4. Otherwise all preferred days are used, plus only as many interior Sundays as are missing,
   centred evenly among the Sundays: index ⌊(2j + 1) · s / (2n)⌋ for n of s Sundays
   (`qta_sched_spread_centered`). These Sundays are returned for review and highlighted.
5. **Hours:** every chosen day gets ⌊H / k⌋; r = H mod k of them get one more, chosen with the
   same even spread over the chosen days (r = 1 → the first day). So max − min ≤ 1 and no day
   exceeds 8.

**Worked example (must pass).** 50 hours, S = Thursday 01.10.2026, E = Saturday 10.10.2026:
k = 7; the preferred days are the 9 non-Sundays; the chosen days are 01, 02, 05, 06, 07, 09
and 10 October; 01.10 has 8 hours and the other six 7 hours (8 + 6 × 7 = 50). Saturday 03.10,
Sunday 04.10 and Thursday 08.10 have no lessons.

### 14.6 Human review (`group_conversion.php`)

A guided page, not a form: header with the state; the historical data with locks ("Fillimi"
and "Mbarimi" cannot change); a live summary ("Orët e planit" x / H, lesson days, days without
lessons, hours per day); the **calendar** of [S, E] month by month; the curriculum that will be
frozen ("Modulet dhe temat që do të ruhen"); the checks ("Kontrollet"); and a sticky action
bar ("Ruaj draftin", "Konverto grupin") that always states the plan's validity in words.

- **Editing** (`app/assets/js/day-plan.js`, shared with the correction of converted groups):
  click or Enter/Space on a date opens the hours picker (0 = "Pa mësim", 1–8; on phones a
  bottom sheet) with an optional note; digits 0–8 set the hours directly; Delete makes the
  date "Pa mësim"; arrows move by day and week, PageUp/PageDown by month, Home/End to S/E;
  Ctrl+Z and "Zhbëj" undo. S and E can never become "Pa mësim"; 9 or more is refused with a
  spoken message. Changes are announced in a live region and marked "Ndryshuar me dorë".
- **"Rishpërndaj automatikisht"** (`qta_sched_rebalance_fixed_range`) places the missing
  hours or removes the extra ones without moving S or E, never above 8, and keeps the dates
  set by hand whenever another way exists. Missing hours go, in order: to days that already
  teach (not interior Sundays), the lowest first and one hour at a time, earliest first; then
  to days without lessons (not Sundays), spread evenly; then to Sundays that already teach, then
  to other Sundays; only last to dates set by hand. Extra hours are removed first from interior
  Sundays (the one with fewer hours first), then from the days with most hours (latest first),
  finally from dates set by hand. The message says which Sundays or manual dates were touched.
- **"Rikthe propozimin"** asks first, then replaces the plan with the automatic proposal
  (undo still works).
- Server rules are authoritative: the page only helps; every save and the conversion
  re-validate everything.
- **Confirmation** before converting lists exactly what happens: the group number stays;
  trainees, exam dates and points do not change; the historical dates stay; H hours in n lesson
  days become the official schedule with the course's current modules and topics; the group
  moves to the new register; and, when used, which Sundays.

### 14.7 Drafts (`legacy_conversion_drafts`)

One draft per legacy group, saved on the server ("Ruaj draftin"), so work survives closing
the page and is visible to colleagues. Saving never touches the group — it stays `legacy`.

- `plan_json`: every date's hours, the dates set by hand, and notes.
- `revision` (optimistic concurrency): starts at 1 and grows with every save; a save or
  conversion from a page that saw another revision is refused ("Ky draft u ndryshua
  ndërkohë…", `code = draft_changed`).
- `source_fingerprint`: SHA-256 of the group (course, dates, closed), its trainees with exam
  dates and points, the course hours and the current curriculum. If any of them changes after
  the draft was saved or the page was opened, saving and converting are refused
  (`code = source_changed`) and the page shows "Rifresko të dhënat". Refreshing keeps the plan
  when the period is the same, restarts from the proposal when the period changed, and removes
  the draft (saying why) when the new data make conversion impossible.
- `algorithm_version` (`fixed-range-1`) records which proposal algorithm produced the plan.
- A draft can exist only for a legacy group (`trg_lcd_requires_legacy_bi`); it is deleted by
  the conversion and with the group.

### 14.8 The conversion transaction (`qta_conv_apply`)

One transaction; any failure rolls everything back and the group stays exactly as it was:

1. lock the group (`FOR UPDATE`) and check it is still `legacy`;
2. lock the draft and check the revision the page saw;
3. lock the course and the group's trainees; read the current curriculum;
4. check the source fingerprint (nothing changed meanwhile);
5. re-run every blocker (course, trainees, exams, capacity);
6. build the schedule from the approved plan and verify it independently;
7. insert the conversion record with status `applying` — the only state in which the database
   lets `model` change;
8. `course_groups.model`: `legacy` → `scheduled` (same ID, course and dates);
9. insert the `fixed_range` schedule header (`daily_hours = NULL`), the frozen topics, the day
   plan, the days and the slots;
10. read everything back and verify it, and check that trainees, exam dates, points, dates and
    the closed flag are unchanged;
11. mark the conversion `completed` and delete the draft.

The `applying` state is never visible outside the transaction.

### 14.9 Database guards (migration 2026-09-28)

| Trigger | Rule |
|---|---|
| `trg_cg_model_guard_bu` | `model` changes only legacy → scheduled with an `applying` conversion, and then course and dates stay; never scheduled → legacy; a scheduled group keeps its course; a converted group keeps S and E forever |
| `trg_gs_requires_scheduled_bi` | a schedule only for a scheduled group; a `fixed_range` schedule only during a conversion |
| `trg_gs_group_fixed_bu` | a schedule never moves to another group and never changes kind |
| `trg_gdr_requires_calculated_bi` | special days only for calculated schedules |
| `trg_gfd_requires_fixed_bi`, `trg_gfd_fixed_bu` | day plan rows only for `fixed_range` schedules, only inside [S, E]; a row's date never changes |
| `trg_lcd_requires_legacy_bi` | drafts only for legacy groups |
| `trg_gc_start_bi` | a conversion starts `applying`, for a legacy group, with the group's own dates |
| `trg_gc_complete_bu` | only `applying` → `completed`, only when the group is scheduled with a `fixed_range` schedule; a completed record never changes |

Plus the CHECKs of §14.3 (`chk_gs_daily` ties the kind to `daily_hours`: 1–8 for calculated,
`NULL` for fixed_range). These hold for every client, not only for the application.

### 14.10 After conversion; correcting a converted group

The group disappears from the legacy register and its conversion list and appears in
"Regjistri i kurseve profesionale" with the badge "Konvertuar nga regjistri i vjetër", the
line "Konvertuar nga regjistri i vjetër më … nga …", locked dates marked "data historike" and
the list text "X orë · data historike". It has no "usual hours per day", no special days and
no "Merr temat e reja".

"Plani i ditëve" on `lesson_group.php` uses the same calendar editor. A correction (`change`
with `type = fixed_days`, `qta_lg_change_fixed_in_tx`) changes only the hours and notes of
dates inside [S, E], rebuilds and verifies the schedule from the frozen topics, and is saved
atomically with the schedule `revision` check. It asks for confirmation when a date before
today changes or the group is closed (the same dialogs as §7), and the success message says
how many days were edited and how many others received moved topics. "Rishpërndaj
automatikisht" works there too and saves nothing until the correction is saved.

### 14.11 Code map

| Concern | Code |
|---|---|
| Pure engine: feasibility, plan rules, builder, verifier, proposal, rebalance | `app/shared/schedule_fixed.php` |
| Shared allocator and verification core, 8-hour constant | `app/shared/schedule.php` |
| Conversion service: source, fingerprint, preflight, states, drafts, transaction | `app/shared/legacy_conversion.php` |
| Converted groups: storage, corrections, deletion | `app/shared/lesson_groups.php` (`qta_lg_is_fixed`, `qta_lg_fixed_days`, `qta_lg_change_fixed_in_tx`, `qta_lg_delete`) |
| JSON endpoint: `propose`, `rebalance`, `save`, `refresh`, `convert` | `app/actions/group_conversion_update.php` |
| Pages | `app/pages/group_conversions.php` (list), `app/pages/group_conversion.php` (review) |
| Calendar (server + client) | `app/shared/partials/day_plan.php`, `app/assets/js/day-plan.js` |
| Page controllers | `app/assets/js/group-conversion.js`, `app/assets/js/lesson-group.js` |
| Styles | `app/assets/css/components.css` §31 (`.dplan-*`), §32 (`.cv-*`) |
| History labels | `app/shared/activity_log.php` |
| Help | `app/shared/help_topics.php` (`conversions`, `conversion`) |
