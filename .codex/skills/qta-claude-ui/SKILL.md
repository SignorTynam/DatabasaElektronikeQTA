---
name: qta-claude-ui
description: Implement or review UI/UX changes in the QTA PHP portal using the repository's Claude-inspired design system. Use for pages, navigation, tables, forms, modals, dashboards, responsive behavior, accessibility, and UI cleanup; do not use for backend-only work with no user-facing effect.
---

# QTA Claude-inspired UI

Preserve QTA's identity and workflows while applying the repository design contract. “Claude-inspired” means the same calm, editorial, low-chrome interaction language; it does not mean copying Claude trademarks, product copy, or undocumented behavior.

## Before editing

Read only the references needed for the task:

- For any visual change, read [`docs/ui-ux/claude-inspired-design-system.md`](../../../docs/ui-ux/claude-inspired-design-system.md).
- For forms, tables, dialogs, navigation, responsive behavior, or states, also read [`docs/ui-ux/ux-accessibility-standard.md`](../../../docs/ui-ux/ux-accessibility-standard.md).
- For migration or cleanup work, read [`docs/ui-ux/current-state-audit.md`](../../../docs/ui-ux/current-state-audit.md).
- For a full redesign pass, use [`docs/ui-ux/redesign-execution-prompt.md`](../../../docs/ui-ux/redesign-execution-prompt.md) as the execution contract.

## Working rules

1. Inspect the page, its role permissions, POST/GET actions, AJAX endpoints, exports, and includes before changing markup. Visual work must not alter authorization, CSRF, SQL, export, or edit-mode behavior unless explicitly requested.
2. Reuse semantic tokens and shared components. Do not add raw colors, one-off spacing, page-local `<style>` blocks, inline `style=`, or a new component when an existing primitive can express the same behavior.
3. Keep one primary action per region. Use clay sparingly for the primary action or must-notice state; do not use it as a large background. Use semantic success, warning, and danger colors only for their meanings.
4. Prefer persistent, explicit controls over hover-only controls. Every icon-only control needs an accessible name and tooltip only as supplementary help.
5. Design mobile-first. Wide data tables may scroll inside their own container; the page itself must not overflow horizontally. Preserve headers/context when the table scrolls.
6. Implement complete states: default, hover, active, focus-visible, disabled, loading, empty, error, success, and permission/read-only where applicable.
7. Meet WCAG 2.2 AA: keyboard operation, visible non-obscured focus, semantic structure, labels, announced validation/status, sufficient contrast, 24×24 CSS px minimum targets (44×44 preferred for primary touch controls), zoom/reflow, and reduced motion.
8. Keep Albanian copy concise and task-oriented. Reuse the application's domain terms (`Studentët`, `Agjencitë`, `Grupet`, `Regjistri`, `Modulet`, `Verifikimi`).
9. Migrate in vertical slices. Remove a legacy selector or wrapper only after repository search and route tests prove it has no callers.

## Verification

- Run PHP lint on every tracked PHP file and `node --check` on changed JavaScript.
- Exercise the changed route for every permitted role and edit/read-only mode it supports.
- Test keyboard-only use and responsive widths around 360, 768, 1024, and 1440 CSS px.
- Check light/dark themes, 200% zoom, reduced motion, empty/error/loading states, and table overflow.
- Report any verification blocked by missing Apache/MySQL or unavailable fixtures; do not claim visual completion without rendering the affected pages.
