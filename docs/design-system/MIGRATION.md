# QTA UI/UX Migration Plan

This plan converts the current PROTOKOLL-based branch into the canonical Claude-inspired QTA system without rewriting business logic.

## Phase 0 — verify and inventory

At the start of implementation:

1. verify current branch and HEAD;
2. read AGENTS.md, CLAUDE.md, qta-ui-ux skill and all design-system docs;
3. inventory CSS/JS/includes;
4. inventory role menus and page families;
5. scan references before deleting anything;
6. capture baseline screenshots for representative routes.

Representative routes:

- public home;
- verify;
- selectProfile/login;
- admin dashboard;
- editor dashboard;
- agency dashboard;
- student dashboard;
- students;
- groups;
- register;
- logs/audit;
- profile.

## Phase 1 — foundations

Refactor `tokens.css`.

Replace paper/ledger primitives with semantic tokens.

Required semantic roles include:

- canvas;
- sidebar;
- surface;
- surface-raised;
- surface-muted;
- surface-selected;
- text-primary;
- text-secondary;
- text-subtle;
- border;
- border-strong;
- primary action/background/text;
- accent interaction;
- link/reference;
- success;
- warning;
- danger;
- focus.

Define Light and Dark values.

Add System behavior in shared theme control.

Remove paper grain and document/microfilm metaphor from web UI tokens.

Keep print/export-specific styling separate.

## Phase 2 — CSS architecture

Target modules:

- tokens.css
- base.css
- components.css
- shell.css
- public.css
- print/export-specific CSS as needed

Do not add a sixth compatibility theme.

Migrate rules out of protokoll.css as components/shell are rebuilt.

`protokoll.css` may remain temporarily only as a shrinking migration source.

`legacy-map.css` must shrink as old markup is converted.

## Phase 3 — component primitives

Implement shared visual components/classes first:

- buttons;
- inputs;
- textarea;
- selects;
- check/radio/switch;
- field wrapper;
- alerts;
- badges/status;
- cards/surfaces;
- menus/dropdowns;
- dialog;
- drawer;
- tabs;
- table;
- pagination;
- empty state;
- skeleton/loading;
- toast;
- command palette;
- page header.

Every component includes all interaction states and dark mode.

## Phase 4 — authenticated shell

Replace horizontal `app_navbar.php` presentation with:

- left sidebar on desktop;
- drawer on mobile;
- contextual main header;
- bottom account/settings/theme controls;
- existing role-specific menu data.

Keep permission/menu logic centralized in PHP helpers.

Do not duplicate menu arrays for desktop and mobile.

## Phase 5 — representative authenticated pages

Migrate in this order:

1. dashboard_admin.php;
2. students.php;
3. groups.php;
4. dashboard_editor.php;
5. dashboard_agjencia.php;
6. dashboard_student.php.

Why:

- dashboard proves shell/hierarchy;
- students proves data table/search/edit mode;
- groups proves complex forms/dialogs/business state;
- other dashboards prove role reuse.

After these are stable, bulk-migrate remaining authenticated pages.

## Phase 6 — public shell

Rebuild:

- navbarMain.php;
- public.css;
- footer;
- index.php;
- verify.php;
- selectProfile.php;
- aboutus.php;
- contact.php.

Preserve QTA identity and verification prominence.

Remove registry-paper metaphors from web chrome.

## Phase 7 — login and forms

Standardize:

- visible labels;
- consistent field components;
- role selection;
- password reveal;
- validation;
- errors;
- loading;
- submit feedback.

Remove page-local inline visual styles.

Remove AOS from login unless a deliberate, accessible use survives the redesign.

## Phase 8 — tables/edit mode

Consolidate:

- table header;
- density;
- filtering;
- sorting;
- bulk selection;
- row actions;
- edit mode state;
- mobile overflow/stack behavior.

No role/page invents a separate table design.

## Phase 9 — dialogs and complex flows

Audit Bootstrap modals and browser confirm/alert usage.

Replace visual treatment with canonical dialogs while preserving logic.

High-risk flows:

- delete confirmation;
- group splitting;
- completed-group force confirmation;
- export generation;
- unsaved/edit-mode transitions.

Do not alter domain consequences.

## Phase 10 — error and system pages

Redesign:

- 400;
- 401;
- 403;
- 404;
- 500.

Keep them static/server-compatible.

## Phase 11 — cleanup gate

Only after migrations:

1. repository-wide search;
2. remove zero-reference PROTOKOLL selectors;
3. remove legacy-map.css mappings with no callers;
4. migrate callers away from navbar compatibility wrappers;
5. remove wrappers with zero references;
6. remove unused fonts/plugins;
7. remove AOS if no page uses it;
8. remove `$headExtra` support only if no page requires it;
9. remove old document-grain assets from web UI.

Do not delete print/export CSS that remains required.

## Phase 12 — QA

Run the complete checklist in QA.md.

Minimum representative role coverage:

- administrator;
- editor;
- agjencia;
- student;
- anonymous/public.

Only declare revamp complete when the old web visual system is no longer globally loaded.

## Rollback discipline

Keep changes phase-oriented and reviewable.

Avoid a single commit that simultaneously rewrites:

- shell;
- all pages;
- business logic;
- database.

If a redesign regression appears, it must be possible to isolate the phase/component responsible.
