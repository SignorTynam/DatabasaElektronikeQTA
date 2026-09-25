# QTA AI Agent Instructions

This repository is undergoing a full UI/UX redesign. Any coding agent that changes user-facing UI MUST read the design-system documents before editing markup, CSS, or interaction code.

## Required reading order

1. docs/design-system/README.md
2. docs/design-system/FOUNDATIONS.md
3. docs/design-system/COMPONENTS.md
4. docs/design-system/ACCESSIBILITY.md
5. docs/design-system/AUDIT.md
6. docs/design-system/MIGRATION.md
7. docs/design-system/QA.md
8. docs/design-system/SOURCES.md
9. docs/ui-ux/ (current-state audit, implementation plan and report from the feature/raporti-qkl pass)

Claude Code must also load .claude/skills/qta-ui-ux/SKILL.md. Codex agents use .codex/skills/qta-claude-ui/SKILL.md; both skills describe the same target.

## Source of truth

The redesign baseline is repository branch feature/raporti-qkl at commit 69608cefc55ccf5a5fc92114c342d1b94aa58d1a (2026-08-25).

The visual direction is Claude-inspired, but QTA-specific: calm editorial typography, warm neutral surfaces, restrained clay accent, low visual chrome, clear hierarchy, progressive disclosure, and accessible task flows. Do not claim or attempt to reproduce private Anthropic design tokens, logos, names, copy, or proprietary assets. Use the public Anthropic frontend-design guidance and observable Claude interaction patterns as references, then apply them to QTA's certification/register workflow.

## Non-negotiable implementation rules

- Preserve PHP routes, role permissions, session behavior, CSRF protections, database operations, exports, audit logs, search capabilities, and edit-mode semantics unless a task explicitly requires changing them.
- Add shared UI through `app/shared/`, `app/assets/css/`, and `app/assets/js/`; avoid new page-local styles or scripts.
- Do not add another compatibility stylesheet on top of the current PROTOKOLL system.
- Migrate old markup to the new component vocabulary, then delete obsolete compatibility CSS only after references are gone.
- Never delete a compatibility wrapper or asset solely because it is small or duplicated. Prove it has no runtime or rewritten-route callers first.
- Do not add page-level style blocks. Avoid inline style attributes. Use design tokens and reusable component classes.
- Do not hardcode visual hex colors, radii, shadows, spacing, or typography inside page PHP.
- Use semantic tokens; component code must consume semantic tokens rather than primitive values.
- Keep Bootstrap 5 initially as layout/behavior infrastructure. Do not visually depend on Bootstrap defaults. Do not add another UI framework or perform a framework rewrite unless explicitly requested.
- Prefer native semantic HTML. ARIA supplements semantics; it does not replace correct elements.
- All UI must work with keyboard only, visible focus, 200% text zoom, reduced motion, and narrow mobile screens.
- UI copy is Albanian unless the existing product context requires another language. Use sentence case and consistent terminology.
- Destructive actions require explicit confirmation and clear recovery/cancel paths.
- Every state must be designed: default, hover, focus-visible, active/selected, disabled, loading, empty, success, warning, and error where applicable.
- No decorative gradients, glassmorphism, glowing blobs, paper-grain texture, universal pill shapes, oversized dashboard KPI cards, or repetitive card grids.
- Motion must explain state change. Avoid automatic entrance animation for every section/card.
- Do not use all-caps tracking as a default label treatment.
- Do not append decorative arrows to every link/button.
- Do not hide functionality behind hover-only interaction.

## Workflow for UI changes

Before coding:
- inspect the existing page and its server-side behavior;
- identify the reusable components involved;
- list the design tokens and states needed;
- note any legacy classes that can be retired.

During coding:
- keep changes incremental;
- preserve URLs, form field names, CSRF handling, permissions, and query behavior;
- migrate repeated markup into shared components/helpers where practical.

After coding:
- run PHP syntax checks on modified PHP files and JavaScript syntax checks on modified scripts;
- check browser console and network failures;
- verify representative pages for administrator, editor, agjencia, student, and public/anonymous states;
- test keyboard navigation and focus;
- test light and dark themes;
- test 320/375/768/1024/1440+ viewport widths;
- take screenshots and visually compare hierarchy, spacing, and state consistency;
- run the checklist in docs/design-system/QA.md.

Treat `docs/design-system/AUDIT.md` and `docs/ui-ux/current-state-audit.md` as findings to verify, not permission to make unrelated backend changes.

If agent-browser or an equivalent browser MCP is available, use it for final visual verification instead of assuming that a successful server start means the UI works.
