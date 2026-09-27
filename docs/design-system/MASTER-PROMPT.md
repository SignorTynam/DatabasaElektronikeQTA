# Master implementation prompt — QTA complete Claude-inspired UI/UX revamp

Use this prompt with Claude Code, Codex, ChatGPT Work, or another capable coding agent from the repository root.

---

You are the lead product designer and frontend engineer responsible for a complete UI/UX redesign of the QTA application.

Repository:
`SignorTynam/DatabasaElektronikeQTA`

Target working branch:
`design/claude-ui-ux-system`

IMPORTANT: verify the current branch and HEAD before editing because this specification may receive additional commits.

## 1. Required context before coding

Read in this order:

1. `AGENTS.md`
2. `CLAUDE.md`
3. `.claude/skills/qta-ui-ux/SKILL.md`
4. `docs/design-system/README.md`
5. `docs/design-system/FOUNDATIONS.md`
6. `docs/design-system/COMPONENTS.md`
7. `docs/design-system/ACCESSIBILITY.md`
8. `docs/design-system/AUDIT.md`
9. `docs/design-system/MIGRATION.md`
10. `docs/design-system/QA.md`
11. `docs/design-system/SOURCES.md`

These files are the design/implementation contract.

Do not begin by inventing another theme.

## 2. Tooling and skills

### Claude Code

When available, use Anthropic's official `frontend-design` skill/plugin in addition to the project-specific `qta-ui-ux` skill.

The project-specific skill wins whenever generic frontend advice conflicts with QTA's actual requirements.

### Figma/design MCP

If a Figma MCP/design-system tool is available and broad visual work is being performed:

1. inspect current code tokens/components;
2. inspect any existing QTA Figma library/file;
3. map code ↔ design;
4. define primitives first;
5. define Light/Dark semantic variables;
6. create/document component variants/states;
7. review visually;
8. reconcile design and code.

Do not make Figma a production dependency.

### Browser automation

If agent-browser, Playwright MCP, browser-use, or equivalent is available, it MUST be used for representative visual QA after implementation.

At minimum:

- open the real local route;
- verify meaningful content;
- inspect console/runtime errors;
- capture screenshots;
- inspect interactive elements;
- exercise navigation;
- test a dialog;
- test a form;
- test a table/edit state;
- test Light and Dark;
- test desktop and mobile.

### Accessibility

Use axe/Lighthouse/accessibility-tree tooling when available, but always include manual keyboard/focus review.

## 3. Product objective

Completely replace the current PROTOKOLL/paper-register web aesthetic with a new QTA design system that has the same interaction DNA and quality bar as Claude:

- calm;
- warm-neutral;
- low chrome;
- precise;
- sophisticated;
- highly legible;
- workspace-first;
- responsive;
- keyboard efficient;
- direct;
- restrained;
- human.

The result must feel like a completely new portal.

Do not create a literal claude.ai clone.

Do not copy Anthropic logos, wording, proprietary assets, or pretend that guessed public colors are official internal Anthropic tokens.

The target is Claude-like interaction quality adapted to QTA.

## 4. Preserve QTA identity and behavior

QTA is a professional training/certification registry.

Preserve:

- QTA logo/identity;
- certificate verification;
- student/course/group workflows;
- administrator/editor/agjencia/student roles;
- authentication;
- authorization;
- CSRF;
- database behavior;
- audit logging;
- group split rules;
- edit-mode behavior;
- export/document generation;
- search semantics;
- URLs/form names unless migration explicitly requires a safe change.

Do not mix visual refactoring with an unrelated database/domain rewrite.

## 5. Hard technical constraints

The current project is PHP/HTML/CSS/JavaScript with Bootstrap used as transitional infrastructure.

Do NOT:

- rewrite the project in React/Vue/Next;
- add a SPA/router;
- introduce client-side authorization;
- install a new runtime UI framework just for styling;
- add a second compatibility theme;
- keep PROTOKOLL indefinitely under a new color layer;
- duplicate role/menu logic for desktop and mobile;
- break exports/print layouts while cleaning web styles.

Bootstrap 5 may remain temporarily for grid, modal/dropdown behavior and compatibility, but the visual identity must come from QTA's own design system.

## 6. Canonical visual direction

Follow `FOUNDATIONS.md`.

Core direction:

### Color

Warm-neutral canvas and surfaces.

Light baseline:

- canvas approximately #F7F6F2;
- sidebar approximately #F0EEE8;
- surface approximately #FCFBF8;
- raised surface #FFFFFF;
- near-black primary text;
- warm secondary text;
- subtle warm borders;
- high-contrast neutral primary action;
- restrained terracotta interaction accent;
- QTA blue retained only for trusted reference/link/verification semantics;
- semantic success/warning/danger used only for meaning.

Dark mode uses its own semantic values.

Do not blindly invert colors.

### Typography

Canonical target:

- IBM Plex Sans — UI/body;
- Source Serif 4 — major/editorial headings;
- IBM Plex Mono — identifiers/codes/AMZË/technical tabular values only.

Do not keep both Spectral and Source Serif 4 indefinitely.

Remove tracked uppercase as the default label language.

Use sentence case.

### Geometry

- controls around 8px radius;
- cards/popovers around 12px;
- larger panels/dialogs around 16px;
- pills only for true badges/status;
- minimal shadows;
- use spacing/surface/border before elevation.

### Motion

- 120–200ms typical user-triggered transition;
- no AOS-style reveal sequences across normal pages;
- no perpetual decorative motion;
- reduced-motion support.

## 7. Authenticated app shell

The current horizontal PROTOKOLL `app_navbar.php` presentation is a migration target.

Replace it with a Claude-like workspace shell:

### Desktop

Left sidebar:

- 248px expanded;
- 64px collapsed rail when appropriate;
- QTA mark/product at top;
- global search/command trigger;
- role-based task navigation;
- profile/theme/help/account area at bottom;
- active state based on surface/typography plus restrained accent.

Main workspace:

- flexible width;
- page-local header;
- one obvious primary action;
- data-heavy views can use wide workspace;
- no giant duplicated top navigation.

### Mobile/tablet

- sidebar becomes accessible off-canvas drawer;
- compact top app bar;
- menu button;
- short page title;
- most important local action;
- focus trap/return;
- Escape closes;
- no hover-only actions.

Keep one canonical menu data source.

## 8. Public shell

Replace the PROTOKOLL public masthead and paper-register visual language.

Preserve:

- QTA identity;
- home;
- about/contact;
- public verification;
- login;
- real registry/module information.

Public verification must remain immediately discoverable.

The public site should feel calm and editorial, not like a governmental paper form and not like a marketing SaaS template.

## 9. Component-first implementation

Do not repaint pages independently.

First implement/reconcile shared components:

- app shell;
- public shell;
- page header;
- button;
- icon button;
- field wrapper;
- input;
- textarea;
- select;
- checkbox;
- radio;
- switch;
- badge/status;
- alert/banner;
- surface/card;
- dropdown/menu;
- tooltip/popover;
- dialog;
- drawer;
- tabs;
- table;
- pagination;
- empty state;
- loading/skeleton;
- toast;
- command palette;
- theme control.

Every component must cover:

- default;
- hover;
- focus-visible;
- active/selected;
- disabled;
- loading where applicable;
- success/warning/error where applicable;
- Light;
- Dark;
- keyboard;
- mobile.

## 10. CSS migration architecture

Target:

- `tokens.css` — primitives + semantic Light/Dark values;
- `base.css` — reset/type/links/focus/base controls;
- `components.css` — reusable UI;
- `shell.css` — sidebar/drawer/header/command palette;
- `public.css` — public layouts;
- print/export CSS only for genuine printable/export content.

The current:

- `protokoll.css`;
- `legacy-map.css`;

are migration sources/debt.

Do not add another large compatibility stylesheet.

## 11. Known audit findings that must be addressed

Read `AUDIT.md`.

In particular:

1. old PROTOKOLL tokens are still globally active;
2. `protokoll.css` is globally loaded;
3. `legacy-map.css` is globally loaded on authenticated pages;
4. authenticated navigation is still top-horizontal, not sidebar;
5. public shell remains PROTOKOLL;
6. current heads load Spectral while final docs specify Source Serif 4;
7. inline style attributes remain in pages/shared templates;
8. `app_head.php` still supports raw `$headExtra` page styles;
9. AOS support remains and `selectProfile.php` enables it;
10. navbar compatibility wrappers still exist;
11. top-level error pages require redesign;
12. old PROTOKOLL selector vocabulary must be removed only after caller migration.

Do not assume “legacy” in a filename means dead code.

## 12. Dead-code protocol

Before deletion classify:

### CONFIRMED DEAD

Delete only if:

- repository-wide search shows no references;
- dynamic include/use is ruled out;
- server config does not reference it;
- export/print does not need it;
- representative browser flows work after removal.

### REPLACED BY REVAMP

Currently used but intentionally removed after migration.

Examples:

- PROTOKOLL web shell;
- PROTOKOLL web tokens;
- top authenticated navbar layout.

### COMPATIBILITY

Adapter/wrapper still serving live callers.

Examples may include navbar wrappers.

Migrate callers first.

### UNKNOWN

Do not delete.

For every removed file/selector, document the evidence.

## 13. Implementation phases

Follow `MIGRATION.md` in order.

### Phase 0 — inventory/baseline

- verify HEAD;
- capture current screenshots;
- inventory includes/assets/plugins;
- inventory representative pages;
- search references;
- create a progress ledger.

### Phase 1 — semantic foundations

Rebuild tokens and theme model.

Implement Light/System/Dark.

Separate print/export concepts from normal web UI.

### Phase 2 — base/components/shell CSS

Create/refactor the canonical CSS modules.

Avoid page-specific hardcoding.

### Phase 3 — components

Implement shared primitives and states.

### Phase 4 — authenticated shell

Build sidebar/drawer/contextual header.

Reuse role/menu logic.

### Phase 5 — representative authenticated pages

Start with:

1. `dashboard_admin.php`;
2. `students.php`;
3. `groups.php`;
4. `dashboard_editor.php`;
5. `dashboard_agjencia.php`;
6. `dashboard_student.php`.

Do not bulk-migrate before these prove the system.

### Phase 6 — public shell

Migrate:

- navbarMain.php;
- index.php;
- verify.php;
- selectProfile.php;
- aboutus.php;
- contact.php;
- footer;
- public CSS/JS.

### Phase 7 — remaining authenticated pages

Apply the established patterns.

### Phase 8 — error/system pages

Redesign 400/401/403/404/500.

### Phase 9 — cleanup

Only now remove confirmed dead styles/wrappers/plugins.

### Phase 10 — full QA

Follow `QA.md`.

## 14. Specific UX expectations

### Dashboard

Do not lead with decorative KPI tiles.

Prioritize:

- tasks requiring attention;
- continue/recent work;
- upcoming actions;
- useful changes;
- then metrics.

### Students

Must prove:

- high-density table readability;
- search/filter;
- edit mode;
- row actions;
- bulk actions if applicable;
- responsive behavior.

### Groups

Must prove:

- complex forms;
- members;
- split logic;
- confirmation dialogs;
- completed-group safeguards;
- save/cancel;
- no domain regression.

### Login/selectProfile

Preserve role selection and CSRF.

Redesign into a calm, focused access screen.

Remove AOS unless a deliberate justified use remains.

### Verification

Make the core task obvious:

1. enter/scan certificate;
2. show validity;
3. show identity/details;
4. offer next appropriate action.

Do not require a paper-sheet visual metaphor for web results.

## 15. Accessibility

Target WCAG 2.2 AA.

Use `ACCESSIBILITY.md`.

Minimum:

- normal text 4.5:1;
- large text 3:1;
- keyboard complete;
- visible focus;
- focus not obscured;
- form labels;
- status not color-only;
- dialogs focus trap/return;
- reduced motion;
- ergonomic target sizes;
- 200% zoom;
- no hover-only functionality.

## 16. Theme behavior

Support:

- Light;
- System;
- Dark.

Replace current binary stored light/dark behavior with an explicit three-mode preference if it can be done safely without regressions.

Centralize theme bootstrap.

Avoid flash-of-wrong-theme.

## 17. Inline styles and one-off CSS

During migration:

- move reusable inline visual styles into components/classes;
- do not add new inline visual styles;
- do not add page `<style>` blocks;
- retain truly dynamic values only where CSS variables/data attributes are more appropriate than classes.

Only remove `$headExtra` after reference verification.

## 18. Plugin/dependency cleanup

Audit:

- AOS;
- Swiper;
- Leaflet;
- html5-qrcode;
- Bootstrap components;
- fonts.

Do not remove functionality.

Examples:

- html5-qrcode may be essential to verification;
- Leaflet may be used by a real map page;
- AOS may be removable if no meaningful usage remains.

Remove dependencies only with evidence.

## 19. Browser verification

After each large phase, use browser automation if available.

Required viewport set:

- 320;
- 375;
- 768;
- 1024;
- 1440.

Representative screenshots:

- public home Light/Dark;
- login Light/Dark;
- admin dashboard;
- students;
- groups;
- verify result;
- one mobile drawer;
- one modal/dialog.

Check:

- console;
- network;
- overflow;
- focus;
- long Albanian text;
- theme;
- runtime warnings.

## 20. Functional verification

At minimum preserve:

- authentication;
- CSRF;
- role menus;
- student edit/create;
- group edit/create/split;
- exports;
- audit;
- global search;
- public verification;
- profile/logout.

Run PHP syntax checks on all modified PHP files.

Use the project's actual available test/runtime tooling; do not invent a test command that does not exist.

## 21. Progress reporting

Maintain a concise progress ledger containing:

- current phase;
- files modified;
- components completed;
- pages migrated;
- compatibility items retained;
- dead code deleted with evidence;
- QA completed;
- open risks.

Do not stop at documentation/mockups if the user asked for implementation.

## 22. Definition of done

The revamp is complete only when:

1. ordinary web UI no longer globally depends on PROTOKOLL styling;
2. ordinary authenticated pages no longer require `legacy-map.css`;
3. authenticated shell is sidebar/drawer based;
4. public shell uses the new system;
5. one canonical token system controls Light/Dark;
6. all representative role pages are migrated;
7. all major components share one vocabulary;
8. inline visual styling is eliminated or justified;
9. AOS/other unused dependencies are removed when proven unused;
10. error pages match the new system;
11. keyboard/mobile/zoom/reduced-motion QA passes;
12. no major console/network errors;
13. business workflows remain intact;
14. dead compatibility code is removed only when proven dead.

## 23. Final report

Return:

- starting HEAD;
- final HEAD;
- files created;
- files modified;
- files deleted;
- design-system changes;
- app shell changes;
- public shell changes;
- page migrations;
- dead-code evidence;
- compatibility code deliberately retained;
- dependency cleanup;
- accessibility results;
- browser QA results;
- functional test/syntax results;
- unresolved risks.

Do not call the work complete merely because it “looks more Claude-like”.

It is complete when QTA has one coherent, production-grade, accessible, maintainable UI/UX system.
