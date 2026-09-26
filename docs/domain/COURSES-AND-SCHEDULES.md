# Courses, modules, topics and scheduled groups

Status: **implemented** on branch `revamp/super-portal` (26.09.2026).
Migration: `db/migrations/2026-09-26-kurset-modulet-temat-orari.sql` (see `db/migrations/README.md`).
UI components and copy: `docs/design-system/THEMELI.md`.

This document is the reference for the domain change "Kurset → Modulet → Temat" and for
the new group register with a day-by-day lesson schedule. It records the rules the code
enforces, where they are enforced, and the policies chosen where the product had a choice.

---

## 1. Vocabulary

| UI (Albanian) | Meaning | Storage |
|---|---|---|
| **Kurs / Kurset** | What a trainee enrols in and is certified for. Until this change the UI called it "Modul". | `courses` (table name and IDs unchanged) |
| **Modul / Modulet** | An ordered part of a course, with its own hours. | `course_modules` |
| **Temë / Temat** | An ordered unit of a module, with its own hours. | `course_topics` |
| **Grupet** | Groups with a lesson schedule (the new register). | `course_groups.model = 'scheduled'` + `group_schedules` … |
| **Grupet e mëparshme** | Every group that existed before this change, kept exactly as it was. | `course_groups.model = 'legacy'` |
| **Orari i mësimit / Ditë pas dite** | The calculated lesson days and which topic hours fall on each day. | `group_schedule_days`, `group_schedule_slots` |
| **Ditë e veçantë** | A date that differs from the usual pattern (other hours, no lesson, a Sunday with lessons). | `group_day_rules` |
| **Regjistri i plotë** | The existing enrolment register (`register.php`): one row per registration. It is *not* the lesson schedule. | unchanged |

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
| Database | `trg_cg_model_guard_bu`: `model` can never change; a scheduled group's `course_id` can never change. `trg_gs_requires_scheduled_bi`: a schedule row can only exist for a `scheduled` group. |
| Services | `qta_lg_require()` refuses legacy groups (`code = legacy_group`); `qta_assert_legacy_group()` makes the legacy actions in `groups.php` refuse scheduled groups. |
| Endpoints | `groups_inline_update.php` and `register_inline_update.php` refuse start/end date edits on scheduled groups (their dates come from the schedule). `courses_inline_update.php` refuses moving a scheduled group to another course. |
| Pages | `groups.php` lists only legacy groups and redirects `?group=N` of a scheduled group to `lesson_group.php?id=N`; `lesson_group.php` redirects a legacy id to `groups.php?group=N`. Search, register, trainee card, courses and dashboards link each group to its own area. |

There is **no conversion** between the two kinds. A legacy group is never given a schedule,
inferred topics or recalculated dates.

New groups are created in "Grupet". "Grupet e mëparshme" opens with a banner that says so
and links to the create dialog there; its own "Shto grup të mëparshëm" stays only for
recording a group held earlier, without a schedule.

## 4. Creating a scheduled group

`lesson_groups.php` → "Krijo grup" (Admin/Editor, edit mode). Inputs: a **ready** course,
start date, hours per usual day (1–12), and optionally the trainees' AMZË
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
lessons"). Limits: 12 hours per day, 3 700 calendar days per schedule.

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

- `course.php`, `lesson_groups.php`, `lesson_group.php` and both JSON endpoints are for
  **Administrator and Editor** only; every endpoint checks the role, the CSRF token and the
  edit mode server-side (previews that save nothing do not need the edit mode).
- Agencies and trainees get no new access: they are redirected away from the new pages and
  keep their existing views (group dates, exams, points), which now say "Kurs".
- `students_without_groups.php` now enforces the edit mode on its JSON writes as well.

## 10. History (audit)

The existing trigger architecture (`audit_capture`, `@audit_user_id` from `qta_audit_attach`)
is reused:

| Table | Logged |
|---|---|
| `course_modules`, `course_topics` | insert, update, delete |
| `group_schedules` | insert, delete, and updates of usual hours, course hours, curriculum copy time, lesson days |
| `group_day_rules` | insert, update, delete ("Orari i zakonshëm", "Pa mësim", hours, note) |
| `course_groups` | as before, now including `model` |

Days, slots and snapshot topics are derived data and are not logged row by row; they are
fully determined by the logged inputs. Foreign-key cascades do not fire triggers, so the
services always delete explicitly (topics before modules, schedule parts before the group).

## 11. Tests

`php tests/run.php` runs the unit tests (engine and readiness, no database).
`QTA_TEST_DB=1 QTA_DB_NAME=<test db> php tests/run.php --integration` also runs the
database tests; they refuse `qta_db` unless `QTA_ALLOW_MAIN_DB=1`.

| File | Covers |
|---|---|
| `tests/unit/schedule_engine_test.php` | calendar facts, acceptance B, C, E, module boundary inside a day, Sunday rules, 0-hour weekdays, input errors, verifier tamper detection, determinism, large totals |
| `tests/unit/curriculum_check_test.php` | acceptance A, hour mismatches and their one-click fixes, ordering, input validation |
| `tests/integration/lesson_groups_test.php` | legacy isolation (D), curriculum CRUD + audit, hour limits (parts never exceed the whole, dialog data, repair of older data, no history for refusals), readiness rollback, C and E through the services and stored rows, course edits after a schedule (F), past-day and closed-group confirmations, exam conflicts, members, legacy guards in services and database, concurrency lock |

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
- PDF/Word/Excel exports need `composer install`; they could not be generated in the local
  test environment.
