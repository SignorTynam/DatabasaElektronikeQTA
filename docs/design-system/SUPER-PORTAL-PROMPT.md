# QTA Super Portal — execution prompt (Themeli)

> **Përmbledhje në shqip.** Ky dokument është prompt-i i plotë i ekzekutimit për rinovimin
> e portalit QTA. Ai bazohet në ekzaminimin e degës më të fundit (`feature/raporti-qkl`) dhe
> dokumentet e dizajnit (`design/claude-ui-ux-system`). Qëllimi: një portal i qetë, i bukur
> dhe **i kuptueshëm për njerëz që nuk janë nga IT** — stafi i QTA-së, agjencitë, kursantët
> (punëtorë ndërtimi) dhe inspektorët që verifikojnë certifikata në kantier. Sistemi i ri i
> dizajnit quhet **Themeli**: gjuhë e thjeshtë shqipe, shkronja shumë të lexueshme
> (Atkinson Hyperlegible), ngjyra të ngrohta me theks "tulle", një shell me menu anësore,
> ndihmë në çdo faqe dhe një përvojë verifikimi që punon me një prekje. Logjika e biznesit,
> databaza, lejet, CSRF, eksportet dhe auditimi mbeten të paprekura.

Use this prompt from the repository root. It supersedes `MASTER-PROMPT.md` and
`docs/ui-ux/redesign-execution-prompt.md` wherever they conflict; everything else in
`docs/design-system/` and `docs/ui-ux/` remains valid reference material.

---

## 1. Mission

You own QTA's portal. Make it the best possible tool for the people who actually use it:
calm, beautiful, fast, and self-explanatory for users with low digital literacy — without
changing what the system does.

Starting point (verified 2026-09-25):

- work branch `revamp/super-portal`, created from `origin/feature/raporti-qkl@529de55`
  (newest code) with `origin/design/claude-ui-ux-system@b7b9468` (design docs) merged in;
- `main` is ~21 commits behind and must not be used as a base.

QTA (Qendra e Trajnimeve të Avancuara) is a public registry of professional qualifications
for construction trades and occupational health & safety. Staff register trainees, place
them in module groups (max 10 per group), record exams and scores, and issue certificates
with a QR code that anyone can verify publicly.

## 2. Who we design for

| Persona | Role key | Context | What they need |
|---|---|---|---|
| **Stafi** (office staff) | `administrator`, `editor` | Desktop, long sessions, daily data entry, moderate digital skills | Find a person in seconds, register/assign without errors, never lose work, clear "what's waiting for me" |
| **Agjencia** (company HR / site manager) | `agjencia` | Occasional, desktop or phone, low–moderate skills | See where each employee stands, register employees, download documents |
| **Kursanti** (construction worker) | `student` | Rare, phone, low digital literacy | "Which modules do I have, when is my exam, did I pass?" and a QR to show an inspector |
| **Inspektori / publiku** | anonymous | Phone at a construction site, outdoors | Scan or type a code and get an unmistakable yes/no in seconds |

## 3. Product principles (the "why" behind every decision)

1. **Plain Albanian.** No English or developer jargon in the UI (see glossary §6). Sentence
   case. Verbs that describe outcomes: "Ruaj ndryshimet", "Shto kursantin", "Shkarko listën".
2. **One screen, one job.** Every page states its purpose in one sentence and has one obvious
   primary action.
3. **Always say what happens next.** Empty states, results, and errors tell the user the next
   useful step. Errors say what failed, why (if known), and what to do.
4. **Prevent mistakes, then make them recoverable.** Editing is protected by an explained
   lock; destructive actions use an accessible confirm dialog that states the consequence.
5. **Status in words + icon + colour** — never colour alone ("✓ Kaloi", "Pritet provimi").
6. **Recognition over recall.** Search everywhere, recent items, visible labels, consistent
   icons, identical names for the same thing on every page.
7. **Legible by default.** Hyperlegible type (distinguishes I/l/1 and O/0 — essential for
   personal numbers like `J750101100A` and NIPT `L12345678Q`), 15–16 px base, WCAG 2.2 AA
   contrast, 44 px touch targets.
8. **Mobile first** for trainees, inspectors, and agencies; desktop-dense for staff.
9. **Visible system state:** loading, saved, locked/unlocked, filtered, empty.
10. **Help one click away:** a Help centre (`ndihme.php`) plus a contextual "Si funksionon?"
    panel on every workspace page.

## 4. Verified findings on the starting branch

### 4.1 Functional defects (fix them — they are real user-facing failures)

| # | Where | Defect |
|---|---|---|
| B1 | `app/pages/dashboard_student.php:196` | PHP warning "Array to string conversion" printed to trainees; agency shows "Array"; hero layout breaks |
| B2 | `index.php` quick check → `verify.php?t=…` | Token-only lookups are ignored (verify requires `sid`/`pid`), so the homepage check does nothing. Tokens are UNIQUE in both token tables → support token-only verification |
| B3 | `verify.php` | Camera starts on page load → immediate permission prompt and a "Kamera nuk u lejua" warning on first paint; the manual code field is below the fold on phones |
| B4 | `dashboard_agjencia.php` | Reads `$COMPANY['nipt']` but the column is `nip_t` → NIPT never shown; several computed-but-unused queries |
| B5 | `student_card.php` | Calls `new QRCode(...)` but no QR library is loaded → QR never renders |
| B6 | `app/assets/js/app.js` | Any page `input[name=q]`/`type=search` hijacks focus into the global palette — page search fields cannot be typed into normally |
| B7 | Theme toggle | Two-state; labels "Fleta origjinale / Kopja e arkivit" are metaphors, not instructions |
| B8 | Many pages | Dates rendered in three formats (`2026-08-27`, `27-08-2026`, `27.08.2026`) |
| B9 | `edit_mode_off_banner.php` | "Edit Mode eshte OFF" — English + missing "ë" |
| B10 | `login_handler.php` | Login CSRF token is issued but never verified (verify it; keep the redirect contract) |

Record any additional defects found during migration in the progress ledger (§11).

### 4.2 Architecture and UX debt

- Five competing CSS layers (Bootstrap → `tokens.css` PROTOKOLL → `protokoll.css` →
  `app.css` → `legacy-map.css` → `claude-ui.css`), ~4,400 lines and ~140 `!important`. The
  "sidebar" is a CSS reskin of a horizontal bar; desktop sub-menus open on hover only.
- Paper/registry metaphors in chrome and copy ("Fleta e punës", "zëra", "nënshkrues",
  "Regjistri i mbyllur", monospace "protocol lines", stamps).
- Mixed terminology: "Studentët" vs "Kursantët", "Dashboard", "Logs", "Edit Mode".
- Grey, low-contrast table text in read-only mode; wide tables unusable on phones.
- Public masthead offset/gap on mobile; hero pushes the real task below the fold.
- Inline `style=` attributes and page-local `<style>` blocks.

## 5. Design system: **Themeli**

"Themeli" (the foundation) — a construction-native, calm, warm system. Warm neutral canvas
(concrete/paper), a restrained **brick** accent for primary actions, QTA **blue** for links,
trust, and verification, semantic status colours only for meaning.

### 5.1 Tokens (all implemented as CSS custom properties in `tokens.css`)

Light — verified contrast (text on canvas/surface): text 15.3/16.7, muted 6.5/7.1,
subtle 5.0/5.5, link 6.7/7.3, success 5.9, warning 5.8, danger 6.0; white on brick 6.0.

| Role | Light | Dark |
|---|---|---|
| canvas | `#F6F5F1` | `#171614` |
| surface (cards, tables, inputs) | `#FFFFFF` | `#1F1E1B` |
| surface-sunken (sidebar, table head) | `#EFEDE7` | `#1B1A17` |
| surface-hover / selected | `#E8E5DD` | `#2A2925` |
| border | `#E0DCD3` | `#34322D` |
| border-strong (inputs, ≥3:1) | `#8F897C` | `#6F6A60` |
| text | `#1F1E1B` | `#F2EFE9` |
| text-muted | `#5C584F` | `#B8B2A6` |
| text-subtle | `#6E695F` | `#A39D91` |
| action (primary button) | `#A4472A` / text `#FFFFFF` | `#E08A6A` / text `#1A1917` |
| action-hover | `#8E3B20` | `#EA9C7E` |
| accent-text (selected nav, highlights) | `#9A4326` | `#EFA07F` |
| link / info / trust (QTA blue) | `#0B57A0` | `#8CC2EE` |
| success | `#1E6B45` on `#E6F4EC` | `#7FCB9A` |
| warning | `#8A5300` on `#FFF3D9` | `#E6B45C` |
| danger | `#B42318` on `#FDECEA` | `#F29A8E` |
| focus ring | `#0B57A0`, 2 px + 2 px offset | `#8CC2EE` |

Brand tiles in the logo (blue `#0858A0`, red `#D80008`, black) stay untouched.

### 5.2 Typography

- UI/body: **Atkinson Hyperlegible Next** (400–700). App base 15 px, public/body 16 px.
- Codes, IDs, NIPT, AMZË, tokens, tabular numbers: **Atkinson Hyperlegible Mono**.
- Display (page titles, public headings): **Source Serif 4** 500/600, used sparingly.
- Scale: 12 · 13 · 14 · 15 · 16 · 18 · 22 · 28 · 36 · 48 (fluid `clamp()` ≥ 28).
- Sentence case. No tracked all-caps labels. Prose ≤ 72ch.

### 5.3 Space, shape, depth, motion

- 4 px grid: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64.
- Radii: 6 (chips, small), 8 (buttons, inputs), 12 (cards, menus), 16 (dialogs, hero panels);
  full radius only for avatars and status pills.
- Depth: borders and surface steps first; shadows only for menus, dialogs, toasts, drawer.
- Motion: 120–200 ms, opacity/transform only, tied to user action; honour
  `prefers-reduced-motion`. No scroll-reveal animations (remove AOS).

### 5.4 Theme

Three explicit modes — **E çelët · Sipas pajisjes · E errët** — stored in `localStorage`
(`qta_theme` = `light|dark|system`), resolved before first paint by one shared inline
bootstrap that sets both `data-theme` and `data-bs-theme` on `<html>`.

### 5.5 CSS and JS architecture

```
app/assets/css/
  tokens.css      primitives + semantic light/dark + Bootstrap variable mapping
  base.css        element defaults, typography, links, focus, forms baseline, utilities, print
  components.css  buttons, fields, tables, badges/status, alerts, cards/panels, menus,
                  dialogs, toasts, tabs, pagination, empty states, page header, stats,
                  key–value lists, filters, edit lock, command palette, help panel
  shell.css       authenticated shell: sidebar, drawer, top bar, workspace
  public.css      public shell: masthead, footer, home, verify, login, about, contact
  error.css       static error pages (standalone, consumes tokens.css)
app/assets/js/
  app.js          theme, shell/drawer, disclosure, table filter + sort, palette, toasts,
                  confirm dialog, clipboard, password reveal, edit-lock UX
  public.js       public shell behaviour
```

Bootstrap 5.3 stays as **infrastructure only** (grid, utilities, modal/dropdown/collapse/
offcanvas/toast behaviour). Its visuals are driven by Themeli through `--bs-*` variables and
`data-bs-theme`; Themeli component CSS owns every visual decision. `protokoll.css`,
`app.css`, `legacy-map.css`, and `claude-ui.css` are removed once no caller remains.
No page-level `<style>` blocks, no new inline visual styles, no raw hex in PHP.

## 6. Language and glossary (UI copy is Albanian)

| Concept | Use | Avoid |
|---|---|---|
| role `student` | **Kursant / Kursantët** | Student, Studentët (in UI) |
| role `agjencia` | **Agjencia** (explain once as "kompania që dërgon punonjësit") | Kompani/agency mixed |
| dashboard | **Kreu** | Dashboard, Fleta e punës |
| audit log | **Historiku i ndryshimeve** | Logs, Audit |
| edit mode | **Ndryshimet: të mbyllura / të hapura**; buttons "Lejo ndryshimet" / "Mbyll ndryshimet" | Edit Mode, Regjistri i mbyllur, Hap për shkrim |
| course | **Modul** | Kurs (mixed) |
| group | **Grup** | — |
| exam / score | **Provimi / Pikët** | Testi (mixed) |
| registration number | **Nr. i amzës (AMZË)** with a tooltip "Numri i regjistrimit në librin e amzës" | — |
| export | **Shkarko (Excel / PDF / Word)** | Eksport, Apliko |
| filter/search submit | **Kërko** / **Pastro kërkimin** | Apliko |
| theme | **Pamja: E çelët · Sipas pajisjes · E errët** | Fleta origjinale, Kopja e arkivit |
| date format | **`dd.mm.yyyy`** everywhere in display; inputs accept `dd.mm.yyyy`, `dd-mm-yyyy`, ISO | Mixed ISO/dash |

Keep the action verb stable from button to feedback: "Ruaj ndryshimet" → "Ndryshimet u ruajtën".

## 7. Shells and navigation

### 7.1 Authenticated shell

- Desktop ≥ 1100 px: persistent **248 px sidebar** (collapsible to a 72 px rail, persisted):
  QTA mark + "Regjistri QTA"; a search button "Kërko… Ctrl K"; role navigation in labelled
  groups with **click/keyboard disclosure** (no hover-only); footer area with Ndihmë,
  Pamja (3-state), and the account menu (Profili, Faqja publike, Dil).
- < 1100 px: compact top bar (menu button, page title, search, avatar); the sidebar becomes an
  accessible **off-canvas drawer** (focus trap, Escape, focus return).
- One canonical menu definition in `app/shared/app_ui.php`; desktop and mobile render the same
  data. Menu per role:
  - **Administrator:** Kreu · Kursantët (Të gjithë kursantët, Kartela e kursantit,
    Kursantët pa grup) · Grupet dhe provimet (Grupet, Regjistri i plotë) · Modulet ·
    Agjencitë · Administrimi (Administratorët, Editorët, Historiku i ndryshimeve).
  - **Editor:** same without Administratorët/Editorët; "Historiku im" (`logs_editor.php`).
  - **Agjencia:** Kreu · Punonjësit tanë (`register_agjencia.php`) · Grupet (`groups_agjencia.php`).
  - **Kursant:** Kreu · Modulet dhe certifikatat (`groups_student.php`).
  - Everyone: Verifiko certifikatë (secondary), Ndihmë, Profili.
- Every workspace page opens with a **page header**: breadcrumb (optional), H1, one-sentence
  purpose, primary action, secondary actions, and a "Si funksionon?" help button.

### 7.2 Public shell

Quiet masthead (logo, "Regjistri QTA", links: Kreu · Verifiko · Rreth nesh · Kontakt, theme,
"Hyr"), a footer with contact and quick links. Verification is the most prominent public
action on every public page.

## 8. Page specifications

Preserve every query, POST handler, field name, endpoint, CSRF token, permission check,
export parameter, and JS data hook. Change presentation, copy, and interaction only.

- **Home (`index.php`)** — Hero: "Verifiko një certifikatë" with an inline code field that
  really works (B2) and a "Skano QR" button; real figures; module index as a clean list; the
  four-step procedure; audiences; closing CTA.
- **Verify (`verify.php`)** — Mobile-first task page: large "Skano kodin QR" button (camera
  starts only on click, B3), "Ngarko foto", and "Shkruaj kodin" field above the fold.
  Result = verdict first (✓ E vlefshme / ✗ Nuk u gjet) in a big, unmistakable panel, then
  identity (name, masked ID), then modules with status, then agency/education, then
  "Kopjo linkun / Printo". Plain-language "Si ta lexoj rezultatin?" guidance and fraud
  reporting contact. Accept token-only codes, full URLs, and the legacy `QTA|…` payloads.
- **Login (`selectProfile.php`)** — Focused two-column (one column on mobile) card: role chooser
  as large radio cards with plain descriptions ("Jam punonjës i QTA-së", "Përfaqësoj një
  agjenci", "Jam kursant"), field label/format hints per role ("NIPT — p.sh. L12345678Q"),
  password reveal, Caps Lock warning, clear error, "Harrove fjalëkalimin?" guidance. Keep
  CSRF, `role` values, and field names.
- **About / Contact** — migrate to Themeli components; keep content and the contact form
  behaviour.
- **Dashboards (4)** — task-first: greeting + date → "Çfarë pret për ty" (actionable counts
  with one-click actions) → quick actions → this week (groups starting/ending, upcoming
  exams) → recent activity → metrics last. Fix B1 and B4. Trainee dashboard becomes a
  mobile-first "my modules" view: next exam, modules with status, and **"Kodi im QR"** (the
  person's verification QR, rendered client-side from the existing `person_qr_tokens`
  token, with the instruction "Tregoja këtë kod inspektorit").
- **Data pages** (`students`, `register`, `groups`, `students_without_groups`,
  `student_card`, `courses`, `agencies`, `users`, `editors`, `logs`, `logs_editor`,
  `profile`, `register_agjencia`, `groups_agjencia`, `groups_student`) — one page-header
  pattern, one filter bar pattern ("Kërko" + filters + "Pastro kërkimin"), one table
  component (sticky head, readable 14 px text, full-contrast values in read-only mode,
  numeric alignment, sortable headers, row actions with labels/tooltips), one edit-lock
  pattern (§ glossary), one export menu ("Shkarko ▾ Excel · PDF · Word"), accessible
  confirmation dialogs, and mobile layouts that do not require horizontal page scroll.
- **Help (`ndihme.php`, new)** — role-aware guides: first steps, how to register a trainee,
  how to place trainees in a group, exams and scores, documents, verification, account and
  password, glossary. Contextual "Si funksionon?" off-canvas on workspace pages reuses the
  same content registry.
- **Error pages (400/401/403/404/500)** — Themeli, plain explanation, next steps (Kreu,
  Verifiko, Kontakt); keep them static and server-compatible.

## 9. Guardrails (non-negotiable)

- Do not change database schema, SQL semantics, permissions, authentication, CSRF behaviour,
  audit semantics, export contents, group split / 10-member rules, or edit-mode rules.
- Do not add a SPA, framework, or build step. PHP + HTML + CSS + vanilla JS only.
- Keep compatibility wrappers (`app/pages/*.php` shims, `inc/navbar*.php`) unless a
  repository-wide search proves they have no callers; document evidence for every deletion.
- Keep export/print-specific assets until export generation is verified.
- Never type real credentials anywhere; use the isolated dev fixture (§10).

## 10. Execution protocol

**Phase 0 — environment and baseline (done before coding).** Isolated MariaDB 10.4 on port
3310 (scratch data dir), schema from `db/tables.sql`, seeded fixture (admin, editor, 3
agencies, 46 persons / 54 registrations, 12 modules, 9 groups in every lifecycle state,
plans, QR tokens), PHP built-in server with a router emulating `.htaccess` at
`/DatabasaElektronike/`, and a scratch-only `__dev_login?role=` session fixture so no
password is typed into a browser. Baseline screenshots per role.

1. **Foundations** — `tokens.css`, `base.css`, fonts, 3-state theme bootstrap.
2. **Components** — `components.css` + Bootstrap variable mapping; every state, both themes.
3. **App shell** — rewrite `app_navbar.php` into sidebar/drawer/top bar; palette fixes (B6);
   help panel infrastructure; `app_head.php` loads only Themeli CSS.
4. **Public shell** — `navbarMain.php`, `footer.php`, `public_head.php`, `public.css`; home,
   verify (B2, B3), login (B10), about, contact.
5. **Dashboards** — admin, editor, agency (B4), student (B1).
6. **Data pages** — migrate each page's markup to Themeli; normalize dates (B8); fix B5, B9.
7. **Help centre** — `ndihme.php` + contextual help registry.
8. **Error pages.**
9. **Cleanup** — remove superseded CSS/JS/plugins with evidence.
10. **QA** — see §11; fix, re-verify, then update docs (`FOUNDATIONS.md`, `COMPONENTS.md`,
    `README.md`) so documentation matches the shipped system.

Commit at each phase boundary with a descriptive message. Lint every modified PHP file
(`php -l`) and JS file (`node --check`) before committing.

## 11. QA protocol and definition of done

Matrix: {public, administrator, editor, agjencia, student} × every reachable page ×
{1440, 1024, 768, 390, 320 px} × {light, dark} — at least one full pass on desktop light,
plus mobile and dark passes on representative pages (home, verify, login, each dashboard,
students, groups, register, student card).

Check on every pass: no PHP warnings/notices in output or log, no console errors, no failed
network requests, no horizontal page overflow, visible focus and full keyboard operation,
dialogs trap/restore focus, drawer works, status never colour-only, AA contrast, 200 % zoom
reflow, reduced motion.

Functional smoke: login for each role (fixture), edit lock on/off, inline edit + save
(student, register row), group create/assign/complete confirmation, verify by URL, by
`QTA|…` payload, and by bare token, export links still POST with CSRF, search palette,
logout.

Done means:

1. Only Themeli CSS is loaded (plus Bootstrap infrastructure and icons); PROTOKOLL,
   `app.css`, `legacy-map.css`, and `claude-ui.css` are gone.
2. Every page uses the shared shell, page header, and component vocabulary.
3. B1–B10 are fixed and verified in the browser.
4. Copy follows the glossary; dates are `dd.mm.yyyy`.
5. Help centre and contextual help exist for every workspace page.
6. QA matrix passes with no known regressions; residual risks are written down.

Maintain a progress ledger in `docs/design-system/PROGRESS.md`: phase, files changed,
defects fixed, deletions with evidence, QA results, open risks.
