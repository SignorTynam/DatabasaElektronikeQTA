# Copy-ready redesign execution prompt

Use the prompt below with a capable coding agent in the repository root.

---

You are the lead product designer, UX architect, accessibility specialist, and senior PHP frontend engineer for the QTA electronic database portal.

Your goal is to execute a complete UI/UX redesign of this repository into a **QTA-owned, Claude-inspired** product: warm neutral surfaces, editorial clarity, restrained clay accent, low visual chrome, excellent hierarchy, progressive disclosure, fast data workflows, and calm, precise feedback. Do not copy Claude logos, proprietary fonts/assets, product wording, or pretend this is an official Anthropic design system.

## Mandatory context

Before editing any code:

1. Invoke and follow `.codex/skills/qta-claude-ui/SKILL.md`.
2. Read `AGENTS.md` and every file under `docs/ui-ux/`.
3. Inspect the current branch, `git status`, latest commit, `.htaccess`, `composer.json`, shared heads/scripts/navigation, all page routes, actions, exports, CSS/JS, database schema, and role checks.
4. Verify every audit finding yourself. Treat cleanup candidates as candidates until repository search and runtime tests prove them unused.
5. Create a route × role × state inventory covering public visitor, administrator, editor, agency, and student; include edit mode, read-only mode, empty/loading/error states, export/download paths, and mobile behavior.

Do not start with isolated page styling. Establish the shared system and migration safety net first.

## Non-negotiable functional constraints

- Preserve authentication, role authorization, sessions, CSRF, audit logs, database behavior, validation, search/filter semantics, pagination, exports, document generation, QR verification, edit-mode locking, and all existing user data.
- Do not change schema or business rules unless a verified defect requires it and the change is explicitly documented and tested.
- Keep existing public/root URLs working. Compatibility rewrites and wrapper files may be removed only after replacement and regression coverage.
- Do not add a new frontend framework or convert the product to an SPA. Work with the existing PHP architecture and progressively reduce Bootstrap dependence.
- Do not place secrets/config in source, expose raw exceptions, or put session/CSRF tokens in URLs.
- Avoid page-local `<style>`, inline `style=`, and embedded page scripts. Shared UI belongs in the shared asset/component layer.

## First deliverable: written implementation plan

Before code changes, produce:

- Current architecture and route/role matrix.
- Confirmed bugs, security/UX risks, dead-code candidates, and compatibility files that must remain.
- Component inventory and mapping from existing selectors/markup to the new system.
- Phased migration plan with rollback boundaries and verification per phase.
- Exact list of files to add, modify, consolidate, and—only after proof—delete.

Then implement the plan without waiting for confirmation unless a discovery would materially change scope or business behavior.

## Design system implementation

Implement the exact semantic contract in `docs/ui-ux/claude-inspired-design-system.md`:

- Light/dark semantic color tokens with accessible action-text variants.
- Inter/system UI typography, restrained Source Serif 4 editorial use, and monospace data identifiers.
- 4px spacing scale; 6/8/12px radius system; minimal shadows; 120–240ms motion; reduced-motion support.
- Desktop collapsible sidebar, coherent page header, flexible main workspace, mobile drawer, and contained table overflow.
- Shared primitives for buttons, inputs, selects, textareas, checkboxes, status tags, alerts, toasts, dialogs, drawers, tabs, breadcrumbs, pagination, search, filters, empty states, loading skeletons, tables, and page/section headers.
- Complete component states: hover, active, focus-visible, disabled, loading, empty, error, success, permission/read-only.

Use clay only for the primary action/must-notice emphasis. Never use it for large surfaces. Never communicate status by color alone.

## UX requirements by workflow

### Global shell and navigation

- Group navigation by user task and role; show current page and role/edit state clearly.
- Provide a collapsible desktop sidebar and accessible mobile drawer with focus management.
- Keep global search discoverable; show search scope, loading, no-results, keyboard navigation, and safe destination labels.
- Keep profile, theme, logout, and help/findability consistent.

### Dashboards

- Prioritize “what needs attention” and high-frequency actions over decorative KPIs.
- Use compact lists/sections; avoid card grids unless each card is a coherent interactive object.
- Every metric links to its source list with matching filters where meaningful.

### Data tables and registers

- Preserve comparison and density. Use sticky headers for long tables, restrained dividers, tabular numerals, contained horizontal scrolling, and explicit sortable state.
- Keep primary row identity visible; move secondary data into expandable details only on narrow screens.
- Keep actions visible/focusable without hover. Batch actions must state selection count/scope and require confirmation when destructive.
- Filters must be discoverable, reflected in the URL when safe, summarized as removable active chips, and cleared in one action.
- Pagination preserves filters and announces result ranges.

### Forms and edit mode

- Persistent labels, Albanian hints, input purpose/autocomplete where applicable, clear required/optional markers, field-linked errors, and an error summary that moves focus appropriately.
- Preserve entered values after validation errors. Disable double submission and show progress.
- Edit/read-only mode must be unmistakable but not visually aggressive. Explain why controls are disabled.
- Destructive actions name the affected record and require a deliberate confirmation path.

### Dialogs, downloads, and exports

- Use dialogs only for bounded tasks. Implement labelled dialog semantics, initial focus, trap, Escape/cancel, and focus restoration.
- Unify the multiple download modals into a shared pattern without changing generated document contents.
- Do not expose CSRF/session values in download URLs; use an authorized POST or short-lived scoped token design.

### Public, login, verification, and error pages

- Use the same tokens with more editorial spacing and restrained serif headings.
- Make verification state immediately understandable with text and icon, not color alone.
- Login/profile selection works with password managers, paste, keyboard, screen readers, and generic authentication errors.
- Wire and redesign 400/401/403/404/500 pages, or remove unused files only after server configuration is confirmed.

## Required defect/cleanup work

- Fix the two `groups_agency.php` references in `app/pages/groups_agjencia.php` to the real `groups_agjencia.php` route and test search/clear.
- Replace raw PDO exception output with logged server-side detail and a safe user-facing failure.
- Replace export URLs containing the session CSRF token with a safer authorized flow.
- Consolidate byte-identical `app/exports/image/*` assets only after export path tests.
- Remove unreferenced `form1_modal.php`/`form2_modal.php` shared files and wrappers only after a final static and runtime check.
- Preserve database/navbar/audit/autoload compatibility shims until their callers are migrated.

## Accessibility and quality gates

Meet WCAG 2.2 AA and `docs/ui-ux/ux-accessibility-standard.md`:

- Keyboard-only operation, logical focus order, no traps, visible/non-obscured focus.
- Correct landmarks, headings, labels, names, descriptions, and live status behavior.
- Contrast ≥4.5:1 for normal text and ≥3:1 for large text/non-text UI.
- Minimum 24×24 CSS px pointer targets; prefer 44×44 for primary touch actions.
- 200% zoom/reflow, 320px minimum content width, no page-level horizontal overflow.
- Reduced motion, no color-only meaning, accessible tables and dialogs.

## Verification tooling

Use the strongest available tools:

- Repository `qta-claude-ui` skill for design constraints.
- A real-browser automation tool (Playwright or equivalent) for route flows, viewport checks, keyboard navigation, screenshots, console/network errors, and regression tests.
- Browser accessibility tooling (axe-core or equivalent) plus manual keyboard/focus checks.
- Optional Figma tooling only if producing or validating reference mockups; code and rendered behavior remain the source of truth.
- PHP lint, JavaScript syntax checks, and focused tests for route/role/permission behavior.

Create baseline and after screenshots for representative pages at 360×800, 768×1024, 1024×768, and 1440×900 in light and dark modes. Do not approve the redesign from code inspection alone.

Minimum route matrix: public home/about/contact/login/verify/errors; each role dashboard; users/editors/agencies/students; student card; register; groups; students without groups; courses; logs; profile; agency register/groups; student groups; all relevant modals and export actions.

## Migration phases

1. Safety fixes, URL helper, smoke tests, visual baselines.
2. Semantic tokens, fonts/assets, theme initialization, application shell.
3. Core components and all states.
4. Administrator/editor high-density workflows.
5. Agency/student workflows.
6. Public/login/verify/error pages.
7. Accessibility/performance/browser regression.
8. Remove proven dead code and legacy CSS/JS; document retained compatibility.

Each phase must be independently reviewable and leave the application functional. Keep diffs focused and avoid mixing unrelated backend refactors with visual migration.

## Final output

Provide:

- A concise summary of implemented UX changes.
- Changed/deleted file list with reasons.
- Confirmed audit findings fixed and candidates retained.
- Test matrix and exact results, including roles/viewports/themes.
- Accessibility results and any justified exceptions.
- Before/after screenshots or links to the visual artifacts.
- Remaining risks and the next smallest safe follow-up.

Do not declare completion if any major route/role was not rendered, if keyboard/accessibility checks were skipped, or if legacy code was deleted without proof.

---
