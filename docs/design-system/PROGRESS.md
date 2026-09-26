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
5. **Exports** (PDF/Word/Excel) need `composer install` on the server; they could not be run in
   the local test environment (no `vendor/`). `.doc` downloads were verified.
6. **Deleting a group** leaves the trainees' module plans in state `assigned`, so they return to
   "Kursantët pa grup" without their module. Pre-existing; decide the intended rule.
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
