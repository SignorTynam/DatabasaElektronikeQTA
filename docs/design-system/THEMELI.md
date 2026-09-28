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
5. **Dates are always `dd.mm.yyyy`**; inputs also accept `-`, `/` and ISO. Every date field and
   editable date cell opens the same calendar dialog (never the browser's own date picker).
6. **Accessible baseline**: WCAG 2.2 AA contrast, visible focus, labels on every
   control, keyboard paths for every pointer action, dialogs that trap and return focus.

## 2. Files

| File | Role |
|---|---|
| `app/assets/css/tokens.css` | Primitives (stone, brick, blue, green, amber, red) → semantic tokens (light + dark) → Bootstrap `--bs-*` mapping |
| `app/assets/css/base.css` | Element defaults, headings, links, focus, utilities (`.num`, `.code`, `.eyebrow`, `.visually-hidden`…) |
| `app/assets/css/components.css` | Every reusable component (sections 1–30, see §5); motion is section 30 |
| `app/assets/css/shell.css` | Authenticated shell: sidebar, rail mode, mobile drawer, topbar, footer, page-to-page transition |
| `app/assets/css/public.css` | Public shell: masthead, hero, verification, login, contact, footer |
| `app/assets/css/error.css` | Standalone styles for the static 400/401/403/404/500 pages |
| `app/assets/js/app.js` | Theme, drawer, tooltips (`data-tip`), live lists (`qtaLive`), sort, inline editing (`qtaEditable`), toasts, confirm dialog, QR, copy, search palette… (§6) |
| `app/assets/js/verify.js`, `login-ui.js`, `contact-ui.js`, `error-page.js` | Page-specific behaviour |
| `app/assets/js/curriculum.js` | Course page: modules and topics, order, fixes (`course.php`) |
| `app/assets/js/students.js` | Trainees list (`students.php`): inline editing, AMZË and personal-number checks, assigning to a group one by one or in bulk |
| `app/shared/list_filter.php`, `partials/list_toolbar.php` | One way to search and filter every list (§5a) |
| `app/shared/students_list.php`, `group_list.php`, `agency_list.php` | The filters of one dataset, shared by its page and its export, so both always show the same rows |
| `app/assets/js/date-picker.js` | Calendar dialog for every `data-dmy` field or editable cell (§5 "Calendar", §6) — loaded on every panel page by `app_scripts.php` |
| `app/assets/js/lesson-groups.js`, `lesson-group.js` | Scheduled groups: create preview; group page tabs, schedule changes, trainees, exams and points |
| `app/shared/themeli.php` | PHP helpers for dates, names, statuses, empty states (§7) |
| `app/shared/domain.php`, `schedule.php`, `curriculum.php`, `group_members.php`, `lesson_groups.php`, `staff_guard.php` | Domain services for courses, modules, topics and scheduled groups — see `docs/domain/COURSES-AND-SCHEDULES.md` |
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
$NAV_ACTIVE = 'users_agencies';    // key from qta_app_menu()
$HELP_TOPIC = 'agencies';          // key from help_topics.php → help panel
require __DIR__ . '/inc/navbar.php';   // role shell (navbar.php admin, navbar4 editor, navbar2 agency, navbar3 trainee)
$LF = ['action' => 'agencies.php', 'label' => 'Kërko agjenci',
       'placeholder' => 'Emri, NIPT, telefoni ose adresa', 'q' => $q, 'target' => 'agenciesResults'];
$pageTitle = 'Agjencitë';
require __DIR__ . '/../shared/app_head.php';
?>
<main class="app-main" id="main" tabindex="-1">          <!-- add is-wide for spreadsheet pages -->
  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Agjencitë</h1>
      <p class="page-lead">Kompanitë që dërgojnë punonjës në trajnim.</p>   <!-- optional, one short sentence -->
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/../shared/partials/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <button class="btn btn-primary">…one primary action…</button>
    </div>
  </header>
  <section class="section" aria-labelledby="agTitle">
    <div class="list-head" data-live-region="list-head">
      <h2 class="section-title" id="agTitle" tabindex="-1" data-live-focus>Të gjitha agjencitë <span class="count">9</span></h2>
      <div class="list-actions">…export menu…</div>
    </div>
    <?php require __DIR__ . '/../shared/partials/list_toolbar.php'; ?>
    <div id="agenciesResults" data-live-region="results" data-live-announce="9 agjenci">
      <div class="table-responsive"><table class="table" data-sortable>…</table></div>
      <?= qta_list_pager('agencies.php', ['q' => $q], $page, $pages, '9 agjenci') ?>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../shared/app_scripts.php'; ?>
```

Every page has exactly one `h1` and an empty state for "nothing yet" and "nothing
matches". The lead is optional: keep it to one short sentence, and leave it out when
the title already says it. Help lives behind the icon button, not in paragraphs on
the page. Notices appear only when something needs attention ("Mungojnë 3 numra amze
midis 1001 dhe 1040.") — never as a permanent explanation.

## 5. Components (components.css)

| Component | Classes | Notes |
|---|---|---|
| Buttons | `.btn-primary`, `.btn-secondary`, `.btn-ghost(.btn-ghost-danger)`, `.btn-danger`, `.btn-icon`, `.btn.is-loading` | Icon + verb; `form[data-loading]` sets `is-loading` on submit. Icon-only buttons carry `aria-label` + `data-tip` (row actions: open the card, delete) |
| Help button | `.btn-help.btn-icon` via `qta_help_button()` | 40×40, question-mark icon only; name and tooltip "Si funksionon kjo faqe?"; opens the help panel of `$HELP_TOPIC`. On phones the top bar then drops its own "?" link to the help centre (one "?" per screen); the help centre stays in the menu and at the end of the panel |
| Fields | `.form-control`, `.form-select`, `.input-code`, `.search-field(.is-lg)`, `.password-field` + `.password-toggle`, `.req`, `.optional` | Labels always visible |
| Page head | `.page-head`, `.page-title`, `.page-lead`, `.page-actions`, `.crumbs` (ol/li), `.eyebrow` | |
| Sections | `.section`, `.section-head`, `.section-title`, `.section-meta`, `.section-link`, `.count` | |
| Panels | `.panel`, `.panel-sunken`, `.card` | Use only for a bounded object |
| Tables | `.table`, `.table-sm`, `.id-code`, `.person-name`, `.cell-sub`, `.num-col`, `.col-wide`, `.col-medium`, `.col-actions`, `.pick-col`, `.row-actions`, `.inline-action` | `data-sortable` + `th[data-sort]`; `td[data-sort-value]`. Names that open a page (`a.person-name`, `a.row-open`) are not underlined; the underline appears on hover |
| Rows that open a dialog | `.row-open` (+ `.row-open-text`) with `data-bs-toggle="modal"` | Details open over the page, never as show/hide rows (groups, a module's groups, agency groups) |
| Record dialog | `.modal-record`, `.modal-meta`, `.modal-section`, `.modal-section-head`, `.modal-section-title`, `.modal-footer-start` | Header = the record's key facts; sections inside; quiet actions left, "Mbyll" right. `modal-fullscreen-md-down` for tables |
| Documents | `.doc-grid`, `.doc-card(-icon/-body/-title/-text/-actions)` via `qta_group_documents()` | One card per document, one button per format; downloads start directly (POST, new tab). Groups with a schedule pass `['lesson_register' => true]` for "Regjistri i orëve të mësimit" |
| Dialog in dialog | automatic (app.js) | A dialog opened from another returns to it on cancel and reopens it after a save reload; `qtaConfirm` stacks above (`.is-stacked`) |
| Frozen columns | `.table-freeze` | First two columns (AMZË + name) stay visible ≥768px |
| Inline editing | `.editable[contenteditable]`, `td.cell-saving/-ok/-err`, `.is-saved/.is-failed` | Enter saves, Esc restores, empty shows "Shto…" |
| Status | `.status.status-{success,warning,danger,info,accent,neutral}` via `qta_status()` | Always a word + icon |
| Messages | `.alert`, `.notice(.is-sunken,.is-warning)`, `.callout(.is-info,.is-warning)`, toasts | Toasts via `qtaToast()`. A `.notice` is one line with an icon and at most one link (e.g. the old registry: "Ky regjistër ruan kurset profesionale të mëparshme…"); `.is-warning` when something is missing. `.callout` is for one highlighted fact (the next exam, today's lesson) |
| Edit lock | `.edit-lock(.is-open)` via `partials/edit_lock.php` | Yellow strip on `body.is-editing` |
| Stats | `.stats`, `.stat`, `.stat-label`, `.stat-value`, `.stat-note` | Two per row on phones |
| Key/value | `.kv`, `.kv.kv-2` | Two pairs per row ≥768px |
| List toolbar (22b) | `.list-head`, `.list-actions`, `.lf` › `.lf-bar` › `.lf-search` (`.lf-input`, `.lf-clear`, `.lf-progress`) + `.lf-more` (`.lf-more-btn`, `.lf-more-count`, `.lf-panel`, `.lf-field`, `.lf-presets`, `.lf-panel-foot`), `.lf-chips` › `.lf-chip-set`, `.lf-error` | Rendered only by `partials/list_toolbar.php` (§5a). Phones: the filter button shows only its icon, the chips scroll sideways in one row |
| Chips | `.chip(.is-on)` + `.chip-count`, `.chip.is-filter` | A chip is a structured state (`?status=no_group`), never visible text matched against the rows. `aria-pressed` carries the state. `.is-filter` = an active filter from "Filtra", removable with one click |
| Pager | `.pager`, `.pager-info` + Bootstrap `.pagination` via `qta_list_pager()` | "Faqja 2 nga 5 · 96 kursantë"; the links keep the filters and open without a reload |
| Live results | `[data-live-region](.is-stale,.is-fresh)` | Old results stay visible while loading (faded only after 160 ms); new ones fade in |
| Filters (old form) | `.filters(.filters-compact)`, `.filter-field(.is-grow)`, `.filter-actions` | Only for a single search that opens another record ("Kërko një person tjetër" on the trainee card) — lists use the list toolbar |
| Segmented | `.segmented > button[role=radio][aria-checked]` | e.g. theme choice |
| Lists | `.agenda`, `.enroll-list/.enroll`, `.tasks/.task`, `.quick-grid(.is-dense)/.quick`, `.steps-list`, `.tip` | `.quick-grid.is-dense` = "Nis një punë" on the staff home: 1 / 2 / 3 columns, title only |
| History | `.log-day`, `.log-list`, `.log-item.is-{add,edit,del}`, `.log-changes`, `.val-old/.val-new/.val-arrow` | |
| QR | `.qr-frame[data-qr]`, `.qr-code-text` | Always dark on white |
| Bulk | `.bulk-bar`, `.bulk-count` | Sticky at the bottom while rows are selected |
| Other | `.person-head`, `.avatar(-lg,-xl)`, `.empty(.is-compact,.is-success)`, `.skeleton`, `.back-top`, `.help-layout` | |
| Course structure (27) | `.cur-summary(-head)`, `.cur-meter(-text)`, `.hours-bar(.is-full,.is-over)`, `.cur-issues`, `.cur-usage`, `.cur-modules > .cur-module(.has-issue,.is-over)` (`-head/-main/-title/-meta`), `.cur-pos`, `.cur-actions`, `.cur-topics > .cur-topic` (`-pos/-title/-hours`), `.cur-quick(-title/-hours)`, `.cur-empty` | Rendered by `qta_render_course_structure()`. Ordered lists (`ol`) carry the order; up/down arrow buttons ("Lëviz lart" / "Lëviz poshtë"), never drag-only. The hours bar is a native `<progress>` with the numbers in text next to it. Each module says "Temat: 15 nga 20 orë · mbeten 5" (or "· 80 tepër" in red for older data) |
| Plan preview (28) | `.plan-preview(.is-ok,.is-warning,.is-error)` | Live result inside a form: end date, lesson days, what changes; `aria-live="polite"` |
| Timetable (28) | `.timetable > .tt-day(.is-week,.is-off,.is-today)`, `.tt-date`, `.tt-main`, `.tt-head`, `.tt-title`, `.tt-hours`, `.tt-off`, `.tt-note`, `.tt-rule-note`, `.tt-module(-name)`, `.tt-flag`, `.tt-slots > .tt-slot` (`.tt-num`, `.tt-topic`, `.tt-slot-hours`, `.tt-part`), `.tt-actions` | Rendered by `qta_render_timetable()`. One `li#dita-YYYY-MM-DD` per calendar date; split topics say "ora 1 nga 2 · vazhdon në ditën tjetër"; prints as a plain list |
| Calendar (29) | `.date-field` + `.date-field-btn` (added around `input[data-dmy]`), `.dp-cell-icon` (after an editable date cell), dialog `.dp-modal` › `.dp-head` (`.dp-title`, `.dp-entry`, `.dp-words`), `.dp-nav` (`.dp-step`, `.dp-period`), `.dp-grid` (days) / `.dp-cells` › `.dp-cell` (months, years) | Built by `date-picker.js`, never by hand. Days Monday–Sunday, today ringed, the chosen day filled, days outside the allowed range faded and not selectable; the month title zooms out to months and years (birth dates start at the years). The date can also be typed at the top. Stacks over another dialog like `qtaConfirm` |
| Scheduled group page (28) | `.lg-lead`, `.lg-tabs` (scrolls sideways on phones), `.lg-date`, `.lg-hours-choice`, `.lg-danger`, `.lg-lock`, `fieldset > legend.form-label` | Facts row under the title; tabs "Orari i mësimit / Kursantët dhe provimet / Dokumentet"; the delete zone sits last. `.lg-lock` = the padlock before a historical date of a converted group ("data historike") |
| Day plan (31) | `.dplan` › `.dplan-months` › `.dplan-month` (`.dplan-month-title`, `table.dplan-grid[role=grid]`) › `td.dplan-day[role=gridcell](.is-on,.is-off,.is-bound,.is-sun,.is-warn,.is-manual,.is-selected,.is-today,.is-refused)` (`.dplan-num`, `.dplan-val`, `.dplan-unit`, `.dplan-marks`), `.dplan-pad`, `.dplan-out`; `.dplan-legend` + `.dplan-swatch(.is-on,.is-off,.is-manual)`; picker `.dplan-editor(.is-sheet)` (`-head/-title/-hint/-label/-keys`) › `.dplan-opts[role=radiogroup] > .dplan-opt[role=radio](.is-none)` | Rendered by `qta_render_day_plan()` (`partials/day_plan.php`), editing by `QtaDayPlan.mount()` (`day-plan.js`). One cell per date of a historical period, each with a full accessible name ("e enjte, 01.10.2026: 8 orë. data historike e fillimit"). Roving tabindex: arrows day/week, PageUp/PageDown month, Home/End start/end, Enter/Space opens the hours picker (a bottom sheet on phones), digits 0–8 set hours, Delete = "Pa mësim", Ctrl+Z undo; Esc returns focus to the date. States never rely on colour alone: hours are written, boundaries carry a padlock, Sundays with lessons a warning icon, manual changes a dot, and the legend names them all |
| Conversion (32) | `.cv-layout` › `.cv-main` (`.cv-tools`, `.cv-intro`, `.cv-plan`, `details.cv-curriculum` › `.cv-modules` › `.cv-module-name/-hours`, `.cv-topics` › `.cv-topic-num/-hours`) + `.cv-side` (`.cv-panel-title`, `.cv-total`, `.cv-counts`, `.cv-verdict`, `.cv-check-list` › `.cv-check` (+ `.cv-check-fix`), `.cv-facts`, `.cv-locked`, `.cv-note`, `.cv-muted`); `.cv-bar` › `.cv-bar-status` (`.cv-bar-text`, `.cv-saved(.is-dirty)`) + `.cv-bar-actions` | `group_conversion.php` and the correction bar of a converted group. Two columns from 1100px; below, one column with the summary, checks and historical data after the calendar. `.cv-bar` is the page's sticky action bar, the same shape as `.bulk-bar`: it always says in words whether the plan is valid ("80 / 80 orë · I vlefshëm, me të diela") and whether it is saved. While it is visible the `.back-top` button is hidden, so it never covers the actions |
| Motion (30) | `@keyframes qta-pop`, `qta-pop-up`, `qta-fade-in`, `qta-rise`, `qta-flash`; `.is-flash` | See §5b |

### 5a. Lists: search and filters

Every list has one way to filter it, built from the same parts:

```
Title of the list  54                                  [Shkarko]
[ Kërko……………………………………………… × ]  [Filtra]
[Të gjithë 54] [Pa grup 5] [Gati për grup 3] …  [Arsimi i mesëm ×]
table · pager
```

- **One search field, no "Kërko" button.** The list changes while typing (220 ms after
  the last key). Enter searches at once, Esc or × clears. The server filters the
  **whole dataset**, not the visible page.
- **Tokens.** "Arben Agim Hoxha" or "1001 Tirane": every word must match (AND) and each
  word may match any meaningful field (OR) — names, full name, AMZË, personal number,
  phone, city, course, dates as shown (`dd.mm.yyyy`). Accents do not matter ("korce"
  finds "Korçë", "tirane" finds "Tiranë") because the database collation ignores them.
  Phone numbers match by their digits (with or without the leading 0), `#12` finds
  record 12. Phrases of a state ("pa grup", "gati për grup") become the state filter.
- **Chips are states,** read from the URL (`?status=no_group`) and computed by SQL,
  with counts for the current search. A chip that is only reachable from a link (e.g.
  "Pa datë provimi" from the home page) appears only while it is active (`optional`).
- **Rare filters live behind "Filtra"** (funnel icon) in a `<details>` panel: they apply
  as soon as they change; each active one shows as a removable chip, so no filter is
  ever hidden. "Hiq këta filtra" clears only these.
- **The URL follows the filters:** refresh, share or Back/Forward show the same list.
  Without JavaScript the form is an ordinary GET (Enter, chips, "Apliko").
- **Feedback without jumps:** a thin line under the field while loading, the previous
  results stay; the result count is announced (`role=status`); on a network error the
  list stays and says "Lista nuk u përditësua. Kontrollo lidhjen." with "Provo sërish".
- **Focus is kept:** a chip keeps focus after the list changes; after a pager click or
  when the focused element disappears, focus goes to the list title (`data-live-focus`).

Server side (PHP):

| Helper | Use |
|---|---|
| `qta_search_q($raw)`, `qta_search_tokens($q)` | Clean the query; split into at most 8 words (quotes and extra spaces removed, repeats counted once) |
| `qta_search_phrases(&$q, $map)` | Take state phrases out of the query ("pa grup" → `no_group`) |
| `qta_search_sql($tokens, $fields, &$params, $prefix, ['digits' => …, 'ids' => …, 'exists' => …])` | The AND-of-ORs predicate with unique named parameters; `digits` compares phone digits, `ids` matches `#12`, `exists` searches related rows (e.g. group members) |
| `qta_search_fold()`, `qta_search_hit($tokens, $text)` | The same rules in PHP, for small lists filtered after loading (the course catalogue) |
| `qta_list_choice($raw, $allowed)`, `qta_list_url($page, $params)`, `qta_list_pager(…)` | Whitelisted choice, clean URL without empty parameters, pager |
| `qta_group_state_sql($state)` | Group states: `active` (Në mësim), `upcoming` (Nisin së shpejti), `awaiting_close` (Presin mbylljen), `closed` (Të mbyllura) |
| `qta_students_filters()` / `_where()` / `_counts()` / `_from_sql()` | Trainees: used by `students.php` and `students_export.php` |
| `qta_group_filters()` / `_where()` / `_counts()` | Groups of both registries |
| `qta_agency_filters()` / `_where()` | The agency's trainees: `register_agjencia.php` and its export |

Client side: `form[data-live-filter]` (one per page) and the regions to replace,
`[data-live-region="name"]` — see §6.

### 5b. Motion

Motion shows a change of state; it never decorates. Durations come only from the
tokens: `--dur-1` 120 ms (hover, press, chips, menus), `--dur-2` 180 ms (state changes,
toasts, results), `--dur-3` 240 ms (dialogs, panels, the page content). All use
`--ease`. With `prefers-reduced-motion: reduce` every duration becomes 1 ms
(tokens.css) and there is no page transition at all.

- Menus and "Filtra" open near their button (`qta-pop`, 4 px); dialogs fade and rise
  8 px instead of Bootstrap's 50 px slide; toasts move 6 px.
- Buttons and chips press (`scale(.97–.98)`); the bulk bar rises when the first row is
  selected; a moved module or topic flashes briefly (`.is-flash`).
- Between pages (shell.css): a cross-document View Transition (`@view-transition`),
  only with `prefers-reduced-motion: no-preference`. The sidebar and top bar stay
  still (`view-transition-name`), only the content fades in. Browsers without support
  simply load the page. A skipped transition is silenced in `head_common.php`.
- No animation on page load, no bounces, no large movements, no parallax.

## 6. JavaScript API (app.js)

| API | Use |
|---|---|
| `qtaToast(message, variant, title?, {autohide, delay})` | Feedback toast; identical repeated messages merge with a counter |
| `qtaConfirm({title, message, confirm, cancel, danger, icon, points})` → `Promise<boolean>` | Accessible confirm dialog; returns focus to the opener. `cancel: false` = one button; `icon: 'bi-exclamation-triangle'` for a problem to fix; `points: ['…', …]` adds a short list of consequences under the message (`ul.confirm-points`), e.g. what a conversion keeps and changes |
| `QtaDayPlan.mount(root)` → `{plan(), serialize(), apply(changes, opts), undo(), canUndo(), focus(date), announce(text), dates, start, end, max}` | Editing for a server-rendered day plan (`day-plan.js`, §5 "Day plan"). Fires `dplan:change` with the changed dates; the page script decides what to do (summary, "Ruaj draftin", correction bar). The server re-checks every rule |
| `form[data-confirm="…"]` (+ `data-confirm-title`, `-ok`, `-danger="0"`) | Declarative confirmation before submit |
| `form[data-loading]` | Busy state on the submit button |
| `.modal[data-open-on-load="param"]` | Opens on load (e.g. `?add=1`) and removes the parameter from the URL |
| `[data-copy="text"]` (+ `data-copy-message`) | Copy with fallback when the page is not HTTPS |
| `[data-password-toggle="#id"]` | Show/hide password |
| `[data-qr="url"]`, `qtaRenderQr(el)`, `qtaQrPng(el)` | Render QR (needs qrcodejs via `$pageScripts`), PNG with quiet zone |
| `table[data-sortable]` | Click/Enter on `th[data-sort="text|num|date"]`; also for tables that arrive with a live list |
| `form[data-live-filter]` + `[data-live-region="name"]` | Live list (§5a): typing, chips, "Filtra" and pager links (`a[data-live-page]`) fetch the same URL with the new filters (`X-QTA-Live: 1`) and replace every region with the same name. The previous request is aborted, late answers are ignored, the URL is updated (`replaceState` while typing, `pushState` for chips and pages), Back/Forward restore the filters. If a region is missing in the answer or the session ended, the full page opens instead |
| `data-live-announce="96 kursantë"` (on a region), `data-live-focus` (list title), `data-focus-key` (chips) | What is announced after a change, where focus goes when the focused element disappears, and how a chip keeps focus |
| `qtaLive.refresh()` | Reload the current list after an action (assign to group, delete) without losing filters; focus falls back to the list title |
| `document` event `qta:content` (`detail.root`) | Fired for every replaced region: sortable tables and date fields are enhanced again. Listen to it for page scripts that must set up new rows |
| `qtaEditable(selector, commit)` | Inline editing by delegation (works for rows that arrive later): Enter saves, Esc restores, paste keeps plain text, `commit(el, previousText)` on blur. `app.js` is `defer`: call it inside `DOMContentLoaded` |
| `[data-tip="…"]` | Tooltip for icon-only buttons on hover or keyboard focus (not on touch), created on demand; the accessible name stays in `aria-label` |
| `a[data-edit-toggle]` | "Lejo ndryshimet" / "Mbyll ndryshimet" keep the list filters that are in the URL |
| `[data-theme-set="light|system|dark"]` | Theme choice anywhere |
| `[data-open-palette]`, Ctrl+K, `/` | Search palette (`app/actions/search_advanced.php`) |
| `input[data-dmy]`, `.editable[contenteditable][data-dmy]` | Calendar dialog on click, on the calendar button or with Alt+↓; typing still works (digits only needed, formatted as `dd.mm.vvvv`). Options: `data-dmy-min` / `data-dmy-max` (`yyyy-mm-dd`, `today` or `#id` of another date field), `data-dmy-kind="birth"` (up to today, starts at the years, no "Sot"), `data-dmy-title`, `data-dmy-required` (no "Pastro"), `data-dmy-commit` (field that saves on blur: the choice saves at once). A field gets `input` + `change`; an editable cell gets focus, the new text and blur, so the page saves it as if typed. In the dialog: arrows day/week, PageUp/PageDown month (+Shift year), Home/End week, Enter picks, Esc closes, digits go to the typed date |
| `qtaDatePicker.open(el)`, `.enhance(root)`, `.parse(value)` | Open the calendar from code; enhance fields added later; `dd.mm.yyyy` → `yyyy-mm-dd` or `null` |
| Dialogs opened from code | `Modal.show(opener)`: on close without saving, focus returns to the opener (as with `data-bs-toggle`) |
| Hours that do not fit | JSON endpoints answer HTTP 400 `{code: 'hours_limit', dialog: {title, message, fix: {value, label} \| null}}`; the page shows `qtaConfirm` with "Vendos 10 orë" (saves the valid value) and "Ndrysho orët" (back to the field). Dialog hints say beforehand how many hours are allowed |
| Server-driven confirmation | JSON endpoints answer HTTP 409 `{confirm: {title, message, confirm}}` (`QtaConfirmNeeded`); the page shows `qtaConfirm` and resends with `force = 1`. The same service computes `dry_run` previews, so the dialog shows the consequence before saving |

## 7. PHP helpers (themeli.php)

`h()`, `qta_date()`, `qta_datetime()`, `qta_ago()`, `qta_when_label()` ("sot", "nesër",
"pas 3 ditësh"), `qta_weekday()`, `qta_month_short()`, `qta_plural()`, `qta_full_name()`,
`qta_initials()`, `qta_status()`, `qta_score_status()`, `qta_enrollment_status()`
("Nis …", "Në mësim", "Provimi …", "Pret pikët", "64 pikë" — or "Përfunduar" where the points have their own column — "Pa grup ende"),
`qta_absolute_url()`, `qta_help_button()` (icon-only, "Si funksionon kjo faqe?"), `qta_empty()`.
List helpers (`list_filter.php` and the dataset files) are in §5a.

Shared partials: `edit_lock`, `edit_mode_off_banner`, `list_toolbar` (`$LF`), `export_menu`
(POST, CSRF never in URLs), `group_documents` (`qta_group_documents()`), `qkl_report_modal`,
`staff_accounts` (admins + editors), `dashboard_staff`, `download_generation_toast`,
`course_structure` (`qta_render_course_structure()`), `timetable` (`qta_render_timetable()`).
Shared pages: `activity_log.php` (history for admins and editors).

`$LF` for `list_toolbar.php`: `action`, `label` (for screen readers), `placeholder` (names
the fields that are searched), `q`, `target` (id of the results region), `status` (active
chip), `chip_param` (default `status`; the history uses `action`), `chips`
(`[value, label, count, optional]`), `chips_label`, `more` (rare filters: `name`, `label`,
`type` `select`|`date`, `value`, `options`, `empty`, `chip` format, `attrs`), `presets`
(e.g. "Sot", "7 ditët e fundit").

Domain helpers (`domain.php`): `QtaUserError` (message shown as is), `QtaConfirmNeeded`
(title, message, confirm label), `qta_parse_date_input()` (dd.mm.yyyy, `-`, `/`, ISO),
`qta_parse_int_input()`, `qta_clean_text()`, `qta_tx()`, `qta_hours_label()` ("1 orë",
"5 orë"). Date phrases for sentences: `qta_sched_day_label()` ("e diel, 04.10.2026") and
`qta_sched_on_label()` ("më 23.10.2026 (e premte)"). JSON endpoints for staff use
`staff_guard.php`: `qta_json_require_staff()`, `qta_json_require_csrf()`,
`qta_json_require_edit_mode()`, `qta_json_fail()` (user errors → 400, confirmations → 409,
database rule messages as written, anything else → 500 with a reference, no details).

## 8. Writing guide

| Use | Not |
|---|---|
| Kursant / Kursantët | Student, studentë |
| Nr. i amzës (AMZË in short messages) | ID, amze |
| **Kursi** (what a trainee enrols in and is certified for) → **Modulet** → **Temat** | "Modul" for the whole course (the name before September 2026), lëndë |
| Grupi, Provimi, Pikët | notë, test |
| Page names: **"Regjistri i kurseve profesionale"** (groups with a lesson schedule, `lesson_groups.php`), **"Regjistri i vjetër i kurseve profesionale"** (groups created before schedules, `groups.php`), **"Katalogu i kurseve"** (courses, modules, topics, `courses.php`). In a sentence an old group is still "grup i mëparshëm"; "Shto grup të mëparshëm" records one held earlier | "Grupet" / "Grupet e mëparshme" / "Kurset" as page names, "Regjistri i plotë" (removed), legacy, model |
| Menu sections: "Kursantët", "Kurset profesionale", "Administrimi" | Technical groupings ("Të dhënat", "Konfigurimi") |
| Search placeholders name what is searched: "Emër, nr. i amzës, nr. personal, telefon, vendlindje ose kurs" | "Kërko…" alone |
| Chips name a state in two or three words: "Pa grup", "Gati për grup", "Pa kurs", "Në mësim", "Presin mbylljen" | Codes or symbols ("—", "N/A") |
| "Orari i mësimit", "Ditë pas dite", "Ditë e veçantë", "Pa mësim", "orë mësimi" | kalendar, slot, override |
| "Gati për grup" / "Jo gati" + the reason + the fix ("Shto edhe 10 orë te modulet, ose ul orët e kursit në 40.") | "Invalid curriculum" |
| Dates inside sentences: "Mbaron më 23.10.2026 (e premte)." | "Mbaron e premte, 23.10.2026" |
| The action's verb returns in its feedback: "Shto modulin" → "Moduli … u shtua në vendin 3."; "Ruaj ditën" → "Dita u ruajt dhe orari u rillogarit." | A different word for the same action |
| "Lejo ndryshimet" / "Mbyll ndryshimet" | Edit mode ON/OFF |
| "Ndryshimi u ruajt." | "U ruajt me sukses." |
| "Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish." | "CSRF token mismatch" |
| "Ky grup është i mbyllur. Konfirmo që do ta ndryshosh." | "Group is completed" |
| "Shkarko" menu with "Excel / PDF / Word" | Separate coloured export buttons |

Errors say what happened and what to do next. Confirmations say the consequence
("Agjencia … dhe llogaria e saj fshihen. 3 punonjës mbeten në regjistër, por nuk lidhen
më me këtë agjenci. Kjo nuk mund të kthehet mbrapsht."). No technical words
(table names, JSON, CSRF, IDs) reach the screen.

## 9. Quality gates used on this branch

- `php -l` on every PHP file; `node --check` on every JS file.
- `php tests/run.php` (scheduling engine, course readiness) and, against a test database,
  `QTA_TEST_DB=1 QTA_DB_NAME=… php tests/run.php --integration` (services, database guards,
  legacy isolation, audit, concurrency). See `docs/domain/COURSES-AND-SCHEDULES.md` §11.
- Automated DOM audit on every page × role at 320/360/375/390/768/1024/1280/1440 px, with
  the edit mode on and off: horizontal overflow, one `h1`, labelled controls, named
  buttons/links, duplicate ids, PHP notices — and the browser console must stay empty
  (a page script that runs before the deferred `app.js` shows up only there).
- Search matrix for every live list: one word, several words in any order, full name,
  AMZË, personal number in any case, phone with and without the leading 0, accents
  ("Tirane"/"Tiranë", "korce"/"Korçë"), status phrases, chips × search × "Filtra",
  Back/Forward, and the export of a filtered list containing exactly the same rows.
- Real Apache 2.4 check of `.htaccess` (routing, blocked internal paths, error pages).
- Manual flows in the browser for every changed action (create/edit/delete, dialogs,
  confirmations, exports, QR, password change), with the database checked after saves.
