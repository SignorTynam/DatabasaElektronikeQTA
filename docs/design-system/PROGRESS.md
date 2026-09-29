# Super-portal revamp — progress ledger

Branch: `revamp/super-portal` (from `feature/raporti-qkl` + `design/claude-ui-ux-system`).
Execution prompt: `SUPER-PORTAL-PROMPT.md`. Implemented system: `THEMELI.md`.

## Phases

| Phase | Scope | State |
|---|---|---|
| 0 | Inventory, verified defects B1–B10, isolated test environment | Done |
| 1–3 | Tokens, base, components, app shell, public shell | Done |
| 4 | Dashboards (staff, agency, trainee) | Done |
| 5 | Public pages: home, verification, login, about, contact | Done |
| 6 | Work pages: trainees, card, without group, groups, register, modules, agencies, admins, editors, history, profile, agency pages, trainee pages | Done |
| 7 | Help centre (`ndihme.php`), help texts aligned with the UI | Done |
| 8 | Error pages 400/401/403/404/500 | Done |
| 9 | Cleanup (legacy CSS, dead endpoints, old download modals) after reference scans | Done |
| 10 | QA: lint, automated DOM audit, real Apache check, manual flows | Done |
| 11 | Domain change: "Modul" becomes **Kurs**, with ordered **Modulet** and **Temat**; new **Grupet** with a lesson schedule (`lesson_groups.php`, `lesson_group.php`, `course.php`); every earlier group stays in **Grupet e mëparshme** unchanged. Migration `db/migrations/2026-09-26-…`, reference `docs/domain/COURSES-AND-SCHEDULES.md`, tests in `tests/` | Done |
| 12 | Document **"Regjistri i orëve të mësimit"** (PDF + Word) for groups with a schedule: odd pages attendance grid, even pages dates and topics of the module, one column and one row per teaching hour, from the group's stored schedule and frozen topics. `app/shared/lesson_register.php`, `app/exports/download_regjistri_mesimit.php`, reference `docs/domain/COURSES-AND-SCHEDULES.md` §13 | Done |
| 13 | **Calendar dialog** for every date: form fields (new groups, earlier groups, trainee, card, special day, history filter) and editable date cells (groups, register, trainees, exams). Replaces the browser's date picker in the history filter. `app/assets/js/date-picker.js`, components §29 | Done |
| 14 | **UX refinement after the revamp.** One live search and filter system for every list (`app/shared/list_filter.php`, `partials/list_toolbar.php`, app.js "Listat"; THEMELI.md §5a): word-by-word search over the whole dataset, state chips with counts, rare filters behind "Filtra", filters in the URL. "Kursantët pa grup" merged into **Kursantët** (chips "Pa grup / Gati për grup / Pa kurs", assignment one by one or in bulk; writes in `app/actions/student_assignment.php`; the old address redirects). **"Regjistri i plotë" removed** (`register.php`, `register_inline_update.php`, `register_export.php`, `partials/table_filter.php`) after a repository-wide reference scan. Names: **Regjistri i kurseve profesionale**, **Regjistri i vjetër i kurseve profesionale**, **Katalogu i kurseve** (now under Administrimi, same permissions). Menu: Kursantët · Kurset profesionale · Administrimi. Staff home: "Çfarë pret për ty" unchanged, "Nis një punë" in the centre, "Regjistri në shifra" removed; agency home simplified. Icon-only help button, shorter page texts, motion pass and page transitions (components §30, shell.css; THEMELI.md §5b) | Done |
| 15 | **Konvertimi i grupeve** (branch `feature/legacy-conversion`): legacy groups move in place into "Regjistri i kurseve profesionale" with their historical start and end, a reviewed day plan (`fixed_range` schedules), server-side drafts, an atomic conversion and database guards; later corrections inside the historical dates. **8 hours per day** everywhere (was 12). Pages `group_conversions.php`, `group_conversion.php`; day plan calendar (components §31, `day-plan.js`) and conversion layout (§32). Migration `db/migrations/2026-09-28-…`, reference `docs/domain/COURSES-AND-SCHEDULES.md` §14, tests in `tests/` (engine, services, HTTP, migration) | Done |
| 16 | **Pikët sipas moduleve** (both registries): one score (0–100, two decimals) per trainee in a group × module (`enrollment_module_scores`); the final result is the average of all modules, "—" with "2 nga 3 module" until every module has points, and is written only by the results service (`final_score` stays as a derived value for every existing reader; the database refuses manual writes). Earlier groups follow the course's current modules, groups with a schedule their frozen copy. Old points stay as "pikë të vjetra" and are kept in `legacy_final_score` once modules replace them. Work window "Vendos pikët" (trainee × module table, sticky columns, live result, keyboard entry, one transactional save with per-cell conflict checks) on `groups.php` and `lesson_group.php`; the "Pikët" column opens it. Certificate-ready data: `qta_results_enrollment()`. `app/shared/results.php`, `app/actions/group_results.php`, `partials/results_dialog.php`, `group-results.js`, components §33. Migration `db/migrations/2026-09-28-piket-sipas-moduleve.sql`, reference `docs/domain/COURSES-AND-SCHEDULES.md` §15, tests in `tests/` (unit, services, HTTP, migration) | Done |

## Pages

All authenticated and public pages use the Themeli shell and components. Old markup
classes (`title-block`, `leaf`, `ledger`, `btn-ink`, `btn-soft-*`, FABs) are gone.

## Defects fixed

| # | Where | What was wrong |
|---|---|---|
| B1 | Trainee dashboard | PHP warning and "Array" printed on the page |
| B2 | Verification | A bare code (without "QTA\|…") could not be verified; home quick check did nothing |
| B3 | Verification | Camera started by itself; the code field was below the fold on phones |
| B4 | Agency dashboard | Wrong NIPT column |
| B5 | Trainee card | QR code never rendered (library not loaded) |
| B6 | Search palette | Typing in page fields opened the palette |
| B7 | Theme | Only two states; now light / device / dark with plain labels |
| B8 | Everywhere | Mixed date formats; now dd.mm.yyyy in and out |
| B9 | Banners | Technical "Edit Mode OFF" copy |
| B10 | Login | CSRF token not checked |
| B11 | Modules | Every inline save and group move failed (endpoint checked `courses_edit_mode`) |
| B12 | Agencies | Editing a NIPT typed in lower case stripped its letters |
| B13 | Profile | Trainees got a fatal error, so they could never change the initial password |
| B14 | Agency pages | Exam dates read from an unused group column (always empty) |
| B15 | Agency export | SQL read names/birth data from `students` instead of `persons` (export failed) |
| G1 | Groups | Members / module / delete dialogs missing since ba70e8a; restored |
| G2 | Groups | Group document downloads missing; one documents dialog, sent by POST |
| G3 | Groups | Create-group confirmation shown twice; one split preview for >10 trainees |
| G4 | Groups | Edits on closed groups failed silently; now ask once and send `force` |
| M1 | Modules | Deleting a module cascaded to its groups, exam dates and scores; now blocked while groups exist |
| M2 | Modules | Moving a group skipped the closed-group and "already took this module" checks |
| W1 | Without group | "Hiq modulin" had server support but no button; restored |
| L1 | History | CSV exported only the visible page; now all matches, Excel-friendly |
| D1 | Dialogs | Footer buttons had no padding or gap (invalid Bootstrap calc from a two-value `--bs-modal-padding`); a confirm opened over another dialog sat under its backdrop |
| D2 | Groups / modules | Group details and a module's groups opened as show/hide rows; now dialogs over the page, documents inside the group dialog |
| D3 | Everywhere | "Kaloi / Nuk kaloi" (pass at ≥ 50) shown although the system records only points; now points only |
| D4 | Verification | Public result showed extra personal data ("Të dhëna shtesë"); now name, masked personal number and modules only; QR photo reader library did not load |
| K1 | Courses (database) | `course_groups → courses` was `ON DELETE CASCADE`: a course deleted outside the app took its groups, exam dates and points with it; now `RESTRICT` (migration) |
| K2 | Search | Group results for staff pointed to `groups.php?q=<course code>`, not to the group itself; now each group opens directly in its own area |
| K3 | History | Course-plan changes showed "(nuk ekziston më)" for plans that still exist |
| K4 | Dialogs | Dialogs opened from code (not `data-bs-toggle`) left focus on the page body when closed; focus now returns to the button that opened them |
| K5 | Procesverbal | Printed the course's current hours; a scheduled group now prints the hours of the course copy it follows |
| K6 | Course structure | Topics could get more hours than their module (e.g. 100 h in a 20 h module), and modules more than the course; now every save refuses it with a dialog that says how many hours are allowed and can set them with one click |
| K7 | Course page | "Ndrysho kursin" kept the values from page load, so after a one-click fix saving it could bring old hours back; it now opens with the last saved values |
| K8 | Group page | Opening a group at `#kursantet` showed an empty page for a moment; the tab now opens without the transition |
| K9 | Tables | Names of trainees, courses and groups were underlined; they are now plain links (underline on hover) |
| K10 | Earlier groups | "Grupet e mëparshme" opens with a banner: new groups are created in "Grupet" (link to the create dialog); "Shto grup të mëparshëm" is only for groups held earlier |
| E1 | Procesverbal (Excel) | Fatal error: `setCellValueByColumnAndRow()` was removed in PhpSpreadsheet 2 (the project uses 5.0); now `setCellValue([col, row])` |
| E2 | Documents (Word, PDF) | Word files and PDFs with the QTA logo failed with a PHP fatal error when the server lacked the `zip` or `gd` extension; now the user reads "serverit i mungon një pjesë e nevojshme — njofto administratorin" and the log names the extension (`app/exports/inc/export_requirements.php`). Locally both extensions were enabled in XAMPP's `php.ini` |
| E3 | Documents | Technical failure texts ("CSRF token mismatch", "Unauthorized", "Composer autoload…", "f=xlsx\|pdf\|docx") replaced with plain Albanian |
| E4 | History filter | "Nga data / Deri më" used the browser's date picker (yyyy-mm-dd); now the QTA calendar and `dd.mm.yyyy`, with ISO links still accepted |
| U1 | Lists | Search compared the whole query as one string: "Arben Hoxha", "1001 Tirane" or a phone without its leading 0 found nothing, and the in-page filter (`table_filter.php`) searched only the rows of the visible page. Now word by word, on the server, over the whole list |
| U2 | Exports | The agency export applied only the text search, not the chosen state. Trainee and agency exports now reuse the list's own filters (`students_list.php`, `agency_list.php`): the file contains exactly the rows on screen |
| U3 | Assign to group | The 10-trainee limit was checked outside the transaction, so two assignments at the same moment (e.g. bulk from two tabs) could exceed it. The group row is now locked while checking |
| U4 | Assign to group | Only "already in this group" was checked: a registration already in another group could be added to a second one, against the rule "one group per registration". Now refused with the group's number and course |
| U5 | Staff home | "Kursantë pa datë provimi" opened the whole register (no filter); it now opens exactly those trainees (`students.php?status=no_exam`; the count and the list match) |
| U6 | Layout | `body { min-width: 320px }` made pages scroll sideways at 320 px when the browser shows a classic scrollbar (reflow, WCAG 1.4.10). Removed; every page fits 310 px |
| U7 | Messages | The missing-AMZË warning showed a blue information icon; `.notice.is-warning` |
| U8 | Registries | The course column wrapped into three or four lines in both registries; now `col-wide` |

## Security fixes

| # | What |
|---|---|
| S1 | `create_admin.php` created an administrator (`admin@qta.test` / password in the repo) for any web visitor → now CLI-only |
| S2 | `.htaccess` served `db/*.sql`, `docs/`, `composer.*`, `*.md`, shared PHP includes and `.git/` → 403 (tested on Apache 2.4) |
| S3 | History printed the change subject without escaping (stored XSS via a name) → escaped |
| S4 | Agency and staff-account endpoints ignored the edit lock → enforced server-side |
| S5 | New/reset passwords: minimum raised to 8 (agencies had none, profile had 6) |
| S6 | `students_without_groups.php` JSON writes ignored the edit lock → enforced server-side |
| S7 | `.htaccess` now also blocks `tests/` (the runner is CLI-only as well) |

## Open items and recommendations

1. **Initial trainee passwords are predictable** (first name + birth year). Generate random
   initial passwords or force a change at first sign-in. The profile page now works and
   reminds trainees to change it.
2. **No sign-in rate limiting / lockout.** Add attempt counting per identifier + IP.
3. **Session cookies**: set `session.cookie_httponly=1`, `session.cookie_samesite=Lax`,
   `session.cookie_secure=1` (HTTPS) and `session.use_strict_mode=1` in php.ini.
4. **Production**: check for an `admin@qta.test` account and remove it or change its password;
   keep `display_errors=Off`.
5. **Exports** (PDF/Word/Excel) need `composer install` and the PHP extensions `gd` and `zip`
   (plus `mbstring`, `fileinfo`, `dom`, `xml`) on the server. Verified locally on 2026-09-27: every
   document in every format opens (31 files; the lesson register is refused for earlier groups, as designed). Consider updating `dompdf/dompdf` from 2.0.0 to the
   latest 2.x or 3.x release (later versions fix published security advisories).
6. **Deleting a group** leaves the trainees' module plans in state `assigned`, so they return to
   "Kursantët → Pa grup" without their course. Pre-existing; decide the intended rule.
7. **CDN assets** (Bootstrap, icons, fonts, qrcodejs, html5-qrcode) load without SRI; add
   integrity hashes or self-host.
8. `course_groups.exam_date` is unused legacy data; per-trainee exam dates live in
   `course_group_students.exam_date`.
9. **Run the migration** `db/migrations/2026-09-26-kurset-modulet-temat-orari.sql` on the
   production database before deploying phase 11 (backup first; see `db/migrations/README.md`).
   The new pages need its tables.
10. Scheduled groups: limitations and possible next steps are listed in
    `docs/domain/COURSES-AND-SCHEDULES.md` §12 (whole hours only, no weekday patterns or
    holiday calendar, member edits after creation capped at 10, schedule not shown to
    agencies and trainees).
11. **Trainees list on a large database** (phase 14): the list joins each trainee's latest
    group and latest waiting course (`ROW_NUMBER()` in derived tables, MariaDB ≥ 10.2) and
    counts every chip in one query. The joins use existing indexes (`idx_cgs_student`,
    `uq_scp_student_course`) and the list is fast on the current data; on tens of thousands
    of trainees, check the plan with `EXPLAIN` before assuming it scales.
12. **Old bookmarks**: `students_without_groups.php` redirects to `students.php?status=no_group`;
    `register.php` no longer exists (404). Tell users who bookmarked "Regjistri i plotë" to use
    "Të gjithë kursantët" or the registries.
13. **Run the migration** `db/migrations/2026-09-28-piket-sipas-moduleve.sql` before deploying
    phase 16, after `2026-09-26` and `2026-09-28-konvertimi-i-grupeve` (backup first; see
    `db/migrations/README.md`). The group pages (`groups.php`, `lesson_group.php`) need its
    table. After it,
    `final_score` can no longer be edited by hand (phpMyAdmin included): points go in per module.
14. **Merging duplicate trainees** (`students_inline_update.php`, `merge_students`) copies only
    the group membership of the duplicate: its exam dates and points — now also its module
    points — are deleted with the duplicate's membership (the deletion is in the history).
    Pre-existing; if merges are used, move the membership with its data instead.
15. **Certificates and reports** can read one trainee's modules, points and result from
    `qta_results_enrollment($pdo, $groupId, $studentId)` (`complete`, `final`, `legacy_final`
    and the source: modules, old points or none). No document uses it yet; the existing
    documents still print `final_score`, which the service keeps in step.
