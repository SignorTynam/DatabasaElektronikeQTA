# QTA repository instructions

## UI/UX source of truth

For every user-facing change, invoke the repository skill at `.codex/skills/qta-claude-ui/SKILL.md` and follow the documents under `docs/ui-ux/`.

The target is a QTA-owned, Claude-inspired interface: calm editorial typography, warm neutral surfaces, restrained clay accent, low visual chrome, clear hierarchy, progressive disclosure, and accessible task flows. Do not copy Claude logos, names, copy, or proprietary assets.

## Non-negotiable implementation constraints

- Preserve PHP routes, role permissions, session behavior, CSRF protections, database operations, exports, audit logs, and edit-mode semantics unless the task explicitly changes them.
- Add shared UI through `app/shared/`, `app/assets/css/`, and `app/assets/js/`; avoid new page-local styles or scripts.
- Use semantic design tokens. No raw color values or arbitrary spacing in page templates.
- Do not add another UI framework. Bootstrap is a migration dependency until the redesign prompt's removal gate is satisfied.
- Never delete a compatibility wrapper or asset solely because it is small or duplicated. Prove it has no runtime or rewritten-route callers first.
- New controls require keyboard behavior, visible focus, accessible names, and all relevant loading/empty/error/disabled/read-only states.

## Required checks

Run PHP syntax checks, JavaScript syntax checks, focused route/role tests, keyboard checks, responsive checks, and light/dark checks in proportion to the change. Treat `docs/ui-ux/current-state-audit.md` as findings to verify, not permission to make unrelated backend changes.
