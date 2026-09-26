# Themeli — the implemented QTA design system

Status: **implemented** on branch `revamp/super-portal` (September 2026).
This file describes what exists in the code today. FOUNDATIONS.md and
COMPONENTS.md are the original specification; where they differ, this file wins.

"Themeli" (Albanian for *foundation*) is the name used in code comments and commits.

---

## 1. Principles in one screen

1. **Plain Albanian first.** Users are not IT people. Every label, button and message
   says what happens in everyday words: "Lejo ndryshimet", "Krijo grupin",
   "Ndryshimi u ruajt", "Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje…".
2. **Protected by default.** Data pages open read-only ("Vetëm për lexim"); edits are
   unlocked on purpose ("Lejo ndryshimet") and every save gives feedback in place.
3. **One primary action per context**, secondary actions quieter, destructive actions
   always confirmed with a sentence that explains the consequence.
4. **Status in words**, never colour alone: "Në mësim", "Pret pikët", "64 pikë".
   The system records **points only** (0–100): there is no "kaloi / nuk kaloi" anywhere.
5. **Dates are always `dd.mm.yyyy`**; inputs also accept `-`, `/` and ISO.
6. **Accessible baseline**: WCAG 2.2 AA contrast, visible focus, labels on every
   control, keyboard paths for every pointer action, dialogs that trap and return focus.

## 2. Files

| File | Role |
|---|---|
| `app/assets/css/tokens.css` | Primitives (stone, brick, blue, green, amber, red) → semantic tokens (light + dark) → Bootstrap `--bs-*` mapping |
| `app/assets/css/base.css` | Element defaults, headings, links, focus, utilities (`.num`, `.code`, `.eyebrow`, `.visually-hidden`…) |
| `app/assets/css/components.css` | Every reusable component (sections 1–26, see §5) |
| `app/assets/css/shell.css` | Authenticated shell: sidebar, rail mode, mobile drawer, topbar, footer |
| `app/assets/css/public.css` | Public shell: masthead, hero, verification, login, contact, footer |
| `app/assets/css/error.css` | Standalone styles for the static 400/401/403/404/500 pages |
| `app/assets/js/app.js` | Theme, drawer, toasts, confirm dialog, table filter/sort, QR, copy, search palette… (§6) |
| `app/assets/js/verify.js`, `login-ui.js`, `contact-ui.js`, `error-page.js` | Page-specific behaviour |
| `app/shared/themeli.php` | PHP helpers for dates, names, statuses, empty states (§7) |
| `app/shared/app_ui.php` | Role labels, menus, active item |
| `app/shared/help.php`, `help_topics.php` | Help panel ("Si funksionon?") and help centre content |

Bootstrap 5.3 stays as infrastructure (grid, utilities, JS behaviour for dropdowns,
modals, collapse, toasts) and is themed entirely through tokens. Bootstrap Icons for icons.

The legacy layers (`protokoll.css`, `app.css`, `legacy-map.css`, `claude-ui.css`) were
removed after a repository-wide reference scan.

## 3. Tokens (use these, never raw colours)

Surfaces: `--canvas` (page), `--surface` (cards, tables, fields), `--surface-sunken`
(sidebar, table heads, quiet panels), `--surface-hover`, `--surface-inverse`.

Lines: `--border`, `--border-control` (secondary buttons, chips), `--border-strong` (fields, ≥3:1).

Text: `--text` (15:1), `--text-muted` (6.5:1), `--text-subtle` (5:1; not on hover surfaces), `--text-inverse`.

Action (brick): `--action`, `--action-hover`, `--action-text`; accent `--accent-text`,
`--accent-soft`, `--accent-border` for selection and indicators.

Trust (QTA blue): `--link`, `--link-hover`, `--info*`, `--focus`, `--focus-ring`.

States: `--success*`, `--warning*`, `--danger*` (each with `-soft` and `-border`).

Type: `--font-sans` Atkinson Hyperlegible Next (UI), `--font-mono` Atkinson Hyperlegible
Mono (AMZË, NIPT, personal numbers, codes), `--font-display` Source Serif 4 (page titles).
Scale `--fs-2xs … --fs-4xl`; weights `--fw-*`.

Space `--sp-1 … --sp-16`; radii `--r-xs … --r-full`; controls `--control-h(-sm|-lg)`,
`--touch-min`; motion `--dur-1/2/3`, `--ease` (reduced-motion respected);
layout `--sidebar-w`, `--sidebar-w-rail`, `--topbar-h`, `--content-max`, `--content-wide`.

Dark mode: `:root[data-theme="dark"]` redefines the semantic tokens. The theme is
chosen in "Profili im → Pamja" or the account menu (light / system / dark), stored in
`localStorage.qta_theme` and applied before paint.

## 4. Page anatomy (authenticated)

```php
$NAV_ACTIVE = 'register_groups';   // key from qta_app_menu()
$HELP_TOPIC = 'groups';            // key from help_topics.php → "Si funksionon?" panel
require __DIR__ . '/inc/navbar.php';   // role shell (navbar.php admin, navbar4 editor, navbar2 agency, navbar3 trainee)
$pageTitle = 'Grupet';
require __DIR__ . '/../shared/app_head.php';
?>
<main class="app-main" id="main" tabindex="-1">          <!-- add is-wide for spreadsheet pages -->
  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Grupet</h1>
      <p class="page-lead">What this page is for, in one or two sentences.</p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <button class="btn btn-primary">…one primary action…</button>
    </div>
  </header>
  <form class="filters">…</form>                           <!-- or .filters.filters-compact -->
  <?php require __DIR__ . '/../shared/partials/edit_mode_off_banner.php'; ?>
  <section class="section" aria-labelledby="…">
    <div class="section-head"><h2 class="section-title">… <span class="count">9</span></h2></div>
    <div class="table-responsive"><table class="table" data-sortable>…</table></div>
  </section>
</main>
<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
```

Every page has exactly one `h1`, a lead sentence, and an empty state for "nothing yet"
and "nothing matches".

## 5. Components (components.css)

| Component | Classes | Notes |
|---|---|---|
| Buttons | `.btn-primary`, `.btn-secondary`, `.btn-ghost`, `.btn-danger`, `.btn-icon`, `.btn.is-loading` | Icon + verb; `form[data-loading]` sets `is-loading` on submit |
| Fields | `.form-control`, `.form-select`, `.input-code`, `.search-field(.is-lg)`, `.password-field` + `.password-toggle`, `.req`, `.optional` | Labels always visible |
| Page head | `.page-head`, `.page-title`, `.page-lead`, `.page-actions`, `.crumbs` (ol/li), `.eyebrow` | |
| Sections | `.section`, `.section-head`, `.section-title`, `.section-meta`, `.section-link`, `.count` | |
| Panels | `.panel`, `.panel-sunken`, `.card` | Use only for a bounded object |
| Tables | `.table`, `.table-sm`, `.id-code`, `.person-name`, `.cell-sub`, `.num-col`, `.col-wide`, `.col-medium`, `.col-actions`, `.pick-col`, `.row-actions`, `.inline-action` | `data-sortable` + `th[data-sort]`; `td[data-sort-value]` |
| Rows that open a dialog | `.row-open` (+ `.row-open-text`) with `data-bs-toggle="modal"` | Details open over the page, never as show/hide rows (groups, a module's groups, agency groups) |
| Record dialog | `.modal-record`, `.modal-meta`, `.modal-section`, `.modal-section-head`, `.modal-section-title`, `.modal-footer-start` | Header = the record's key facts; sections inside; quiet actions left, "Mbyll" right. `modal-fullscreen-md-down` for tables |
| Documents | `.doc-grid`, `.doc-card(-icon/-body/-title/-text/-actions)` via `qta_group_documents()` | One card per document, one button per format; downloads start directly (POST, new tab) |
| Dialog in dialog | automatic (app.js) | A dialog opened from another returns to it on cancel and reopens it after a save reload; `qtaConfirm` stacks above (`.is-stacked`) |
| Frozen columns | `.table-freeze` | First two columns (AMZË + name) stay visible ≥768px |
| Inline editing | `.editable[contenteditable]`, `td.cell-saving/-ok/-err`, `.is-saved/.is-failed` | Enter saves, Esc restores, empty shows "Shto…" |
| Status | `.status.status-{success,warning,danger,info,accent,neutral}` via `qta_status()` | Always a word + icon |
| Messages | `.alert`, `.notice(.is-sunken)`, `.callout(.is-info)`, toasts | Toasts via `qtaToast()` |
| Edit lock | `.edit-lock(.is-open)` via `partials/edit_lock.php` | Yellow strip on `body.is-editing` |
| Stats | `.stats`, `.stat`, `.stat-label`, `.stat-value`, `.stat-note` | Two per row on phones |
| Key/value | `.kv`, `.kv.kv-2` | Two pairs per row ≥768px |
| Filters | `.filters(.filters-compact)`, `.filter-field(.is-grow)`, `.filter-actions`, `.chip(.is-on)`, `.tfilter` | |
| Segmented | `.segmented > button[role=radio][aria-checked]` | e.g. theme choice |
| Lists | `.agenda`, `.enroll-list/.enroll`, `.tasks/.task`, `.quick-grid/.quick`, `.steps-list`, `.tip` | |
| History | `.log-day`, `.log-list`, `.log-item.is-{add,edit,del}`, `.log-changes`, `.val-old/.val-new/.val-arrow` | |
| QR | `.qr-frame[data-qr]`, `.qr-code-text` | Always dark on white |
| Bulk | `.bulk-bar`, `.bulk-count` | Sticky at the bottom while rows are selected |
| Other | `.person-head`, `.avatar(-lg,-xl)`, `.empty(.is-compact,.is-success)`, `.skeleton`, `.back-top`, `.help-layout` | |

## 6. JavaScript API (app.js)

| API | Use |
|---|---|
| `qtaToast(message, variant, title?, {autohide, delay})` | Feedback toast; identical repeated messages merge with a counter |
| `qtaConfirm({title, message, confirm, cancel, danger})` → `Promise<boolean>` | Accessible confirm dialog; returns focus to the opener |
| `form[data-confirm="…"]` (+ `data-confirm-title`, `-ok`, `-danger="0"`) | Declarative confirmation before submit |
| `form[data-loading]` | Busy state on the submit button |
| `.modal[data-open-on-load="param"]` | Opens on load (e.g. `?add=1`) and removes the parameter from the URL |
| `[data-copy="text"]` (+ `data-copy-message`) | Copy with fallback when the page is not HTTPS |
| `[data-password-toggle="#id"]` | Show/hide password |
| `[data-qr="url"]`, `qtaRenderQr(el)`, `qtaQrPng(el)` | Render QR (needs qrcodejs via `$pageScripts`), PNG with quiet zone |
| `table[data-sortable]` | Click/Enter on `th[data-sort="text|num|date"]` |
| `partials/table_filter.php` | Instant filter of visible rows (ignores dropdown options) with quick chips |
| `[data-theme-set="light|system|dark"]` | Theme choice anywhere |
| `[data-open-palette]`, Ctrl+K, `/` | Search palette (`app/actions/search_advanced.php`) |

## 7. PHP helpers (themeli.php)

`h()`, `qta_date()`, `qta_datetime()`, `qta_ago()`, `qta_when_label()` ("sot", "nesër",
"pas 3 ditësh"), `qta_weekday()`, `qta_month_short()`, `qta_plural()`, `qta_full_name()`,
`qta_initials()`, `qta_status()`, `qta_score_status()`, `qta_enrollment_status()`
("Nis …", "Në mësim", "Provimi …", "Pret pikët", "64 pikë" — or "Përfunduar" where the points have their own column — "Pa grup ende"),
`qta_absolute_url()`, `qta_help_button()`, `qta_empty()`.

Shared partials: `edit_lock`, `edit_mode_off_banner`, `table_filter`, `export_menu`
(POST, CSRF never in URLs), `group_documents` (`qta_group_documents()`), `qkl_report_modal`,
`staff_accounts` (admins + editors), `dashboard_staff`, `download_generation_toast`.
Shared pages: `activity_log.php` (history for admins and editors).

## 8. Writing guide

| Use | Not |
|---|---|
| Kursant / Kursantët | Student, studentë |
| Nr. i amzës (AMZË in short messages) | ID, amze |
| Moduli, Grupi, Provimi, Pikët | Kurs, notë, test |
| "Lejo ndryshimet" / "Mbyll ndryshimet" | Edit mode ON/OFF |
| "Ndryshimi u ruajt." | "U ruajt me sukses." |
| "Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish." | "CSRF token mismatch" |
| "Ky grup është i mbyllur. Konfirmo që do ta ndryshosh." | "Group is completed" |
| "Shkarko" menu with "Excel / PDF / Word" | Separate coloured export buttons |

Errors say what happened and what to do next. Confirmations say the consequence
("Kursantët nuk fshihen — ata kthehen te 'Kursantët pa grup'"). No technical words
(table names, JSON, CSRF, IDs) reach the screen.

## 9. Quality gates used on this branch

- `php -l` on every PHP file; `node --check` on every JS file.
- Automated DOM audit on every page × role at 320/360/390/1280/1440 px: horizontal
  overflow, one `h1`, labelled controls, named buttons/links, duplicate ids, PHP notices.
- Real Apache 2.4 check of `.htaccess` (routing, blocked internal paths, error pages).
- Manual flows in the browser for every changed action (create/edit/delete, dialogs,
  confirmations, exports, QR, password change), with the database checked after saves.
