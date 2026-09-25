# QTA UI/UX Audit

Audited repository: SignorTynam/DatabasaElektronikeQTA  
Audited branch: `design/claude-ui-ux-system`  
Audited HEAD: `9482e8d5aec84168743253fda946972675ee7b87`  
HEAD message: `docs: add Claude-inspired QTA foundations`  
Audit date: 2026-09-25

## 1. Executive finding

The repository already contains a useful refactoring foundation, but the production UI on this branch is still predominantly the previous **PROTOKOLL** visual system.

The correct strategy is not another overlay/theme. It is a controlled replacement:

1. keep QTA business behavior;
2. keep the useful shared PHP helpers and centralized asset loading;
3. replace PROTOKOLL primitives/tokens;
4. replace the authenticated top-navbar shell with the new sidebar workspace;
5. migrate page markup to the canonical component vocabulary;
6. remove legacy/compatibility CSS only after references are gone.

## 2. What is already good

Preserve these architectural improvements:

- centralized `app_head.php` and `public_head.php`;
- centralized `app_scripts.php` and `public_scripts.php`;
- shared `app_ui.php` and `public_ui.php`;
- shared role/menu logic;
- one shared authenticated navbar implementation behind compatibility wrappers;
- central `tokens.css`;
- separate app/public JS;
- role-based navigation behavior;
- command/search work already introduced;
- light/dark theme bootstrap before paint;
- helper-based asset URLs;
- existing CSRF/security/business behavior;
- Bootstrap can remain transitional behavior/layout infrastructure.

## 3. Documentation defects found

### Confirmed defect: missing required design docs

`AGENTS.md` and `.claude/skills/qta-ui-ux/SKILL.md` require:

- COMPONENTS.md
- ACCESSIBILITY.md
- AUDIT.md
- MIGRATION.md
- QA.md
- SOURCES.md

At audited HEAD those files were missing. Coding agents therefore received broken required-reading instructions.

This audit and the companion documentation files fix that gap.

### Confirmed defect: stale baseline metadata

Existing README/AGENTS referenced an older feature branch/commit as the redesign baseline. The active design branch is now `design/claude-ui-ux-system` at the audited HEAD above.

Agent documentation must not hard-code an obsolete baseline as though it were current source of truth. Agents should verify HEAD at task start.

## 4. Visual architecture conflicts

### A. tokens.css is still explicitly PROTOKOLL

Current tokens describe:

- paper;
- ink;
- pencil;
- ledger rules;
- official-document geometry;
- paper grain;
- microfilm/archive dark theme;
- tiny 2–4px document corners.

This directly conflicts with the new Claude-inspired system.

Status: **REPLACED BY REVAMP**.

### B. protokoll.css is loaded globally

`app_head.php` loads:

- tokens.css
- protokoll.css
- app.css
- legacy-map.css

`public_head.php` loads:

- tokens.css
- protokoll.css
- public.css

Therefore PROTOKOLL is not isolated legacy styling; it is currently a global dependency.

Status: **MIGRATION BLOCKER**, not dead code yet.

### C. legacy-map.css is loaded on every authenticated page

This compatibility layer is globally loaded even for pages that may not need legacy mappings.

Status: **MIGRATION DEBT**.

End state: remove from global head, then delete only after repository-wide reference verification.

### D. authenticated shell is still a top navbar

`app/shared/inc/app_navbar.php` is explicitly documented as PROTOKOLL and implements a horizontal app bar/navigation.

The new design requires:

- persistent desktop left sidebar;
- mobile drawer;
- contextual top/page header.

Status: **REPLACED BY REVAMP**.

### E. public shell is still PROTOKOLL

`navbarMain.php`, `public.css` and public page markup use registry/document metaphors such as:

- protocol line;
- paper/record sheet;
- official-act styling;
- uppercase tracked labels;
- document-like dotted index;
- hard 2px ink rules.

Status: **REPLACED BY REVAMP**, while preserving public information architecture and verification workflows.

## 5. Typography conflict

Documentation proposes IBM Plex Sans + Source Serif 4, while current heads load:

- Spectral;
- IBM Plex Sans;
- IBM Plex Mono.

Current token comments also treat Spectral as the “register voice”.

Decision required by redesign: choose one canonical serif. The design-system specification selects Source Serif 4. During migration, do not load both Source Serif 4 and Spectral indefinitely.

Status: **MIGRATION DEBT**.

## 6. Inline visual styling conflict

The new rules prohibit page-local hardcoded visual styling, but current pages/shared templates still contain inline style attributes and `$headExtra` support.

Examples found:

- `selectProfile.php` contains multiple inline margin/grid/font styles;
- `app_navbar.php` contains inline dropdown/font-size styles;
- `app_head.php` explicitly supports raw `$headExtra` HTML for page-specific `<style>` blocks.

Status:
- existing instances: **MIGRATION TARGET**;
- new instances: **FORBIDDEN** unless technically unavoidable and documented.

Do not remove `$headExtra` until reference scanning proves no remaining pages need it.

## 7. AOS/animation conflict

The new direction explicitly avoids automatic reveal animation across ordinary application/public content.

`public_head.php` and `public_scripts.php` still support AOS; `selectProfile.php` requests the AOS plugin.

If a page enables AOS but has no meaningful `data-aos` use after redesign, remove that dependency from the page. When no page uses AOS, remove plugin support from shared public helpers.

Status: **DEAD-CODE CANDIDATE**, verification required.

## 8. Compatibility wrappers

Files such as:

- `app/shared/inc/navbar.php`
- `navbar2.php`
- `navbar3.php`
- `navbar4.php`
- `app/pages/inc/navbar.php`
- `navbar2.php`
- `navbar3.php`
- `navbar4.php`

are tiny wrappers around shared navigation.

They are not automatically dead code. Existing pages may still include them.

Status: **COMPATIBILITY**.

Migration goal:

1. move callers to a canonical shell/bootstrap include;
2. repository-wide search;
3. delete wrappers only after zero runtime references.

## 9. Old CSS and markup vocabulary

Migration candidates include terms/selectors tied specifically to PROTOKOLL:

- paper / record / ledger / protocol metaphors;
- `.protocol-line`;
- `.record-sheet` when used only as web chrome rather than printable document output;
- `.leaf`, `.plate-*`, other document-sheet primitives;
- paper grain background;
- document corner radius tokens;
- stamp/vula decoration where it does not encode real status;
- tracked uppercase label defaults;
- universal top/left “official document” rules.

Do not bulk-delete by name. Some may be legitimately used in print/export content.

Status: **REPLACED BY REVAMP or PRINT-SPECIFIC — inspect each caller**.

## 10. Error pages

Top-level `400.html`, `401.html`, `403.html`, `404.html`, `500.html` are still separate static artifacts.

They must be included in visual migration so the portal does not fall back to the old style during errors.

Do not remove them merely because they are not linked from normal navigation; server configuration may use them.

Status: **KEEP + REDESIGN**.

## 11. Public homepage

`app/pages/index.php` has useful real registry data and public verification priority, but its hierarchy is still built around the former registry-document metaphor.

Preserve:

- real figures;
- module listing;
- verification CTA;
- audience explanation;
- procedure content.

Redesign presentation into the new public shell.

## 12. Login/selectProfile

Strengths:

- one screen handles role selection;
- CSRF behavior;
- visible labels;
- role-specific identifiers;
- password toggle;
- error feedback.

Issues:

- inline styling;
- AOS enabled despite the new restrained-motion direction;
- document/protocol explanatory panel;
- tracked-uppercase tab style;
- current public PROTOKOLL surface.

Status: **HIGH-PRIORITY REPRESENTATIVE MIGRATION PAGE**.

## 13. Authenticated dashboards

Four role dashboards exist and should be migrated using a shared shell and common dashboard grammar.

Do not duplicate four visual systems.

Dashboard redesign should prioritize:

- tasks requiring attention;
- recent/next work;
- role-specific operational information;
- metrics only after actionable items.

## 14. Dead-code policy

Classify before deletion:

### CONFIRMED DEAD

All conditions must be true:

- repository-wide search finds no direct reference;
- no dynamic include/class/plugin construction can reference it;
- no server config references it;
- no export/print flow references it;
- removing it does not break syntax/runtime/browser QA.

Only then delete.

### REPLACED BY REVAMP

Used today, but deliberately removed after callers migrate.

Examples:
- PROTOKOLL web shell;
- global PROTOKOLL tokens;
- top authenticated navbar layout.

### COMPATIBILITY

Thin adapter/wrapper still supporting live callers.

Do not delete until callers migrate.

### UNKNOWN

Evidence incomplete.

Never delete.

## 15. Priority order

P0:
- repair design documentation;
- establish canonical token/component contract.

P1:
- new tokens/base/components/shell;
- authenticated sidebar/drawer;
- remove global reliance on PROTOKOLL from representative pages.

P2:
- migrate representative pages: admin dashboard, students, groups, selectProfile, verify.

P3:
- remaining authenticated pages by role.

P4:
- public homepage/about/contact/login/error pages.

P5:
- repository-wide cleanup and deletion of confirmed dead compatibility CSS/wrappers.

## 16. Functional guardrail

Do not redesign:

- database schema;
- permissions;
- search result rules;
- export contents;
- edit-mode business logic;
- group split logic;
- audit semantics;
- authentication semantics;

unless a separate task explicitly requires it.

Visual refactoring must not become a business-logic rewrite.
