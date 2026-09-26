# QTA Design System — Claude-inspired Portal Revamp

Status: **implemented as "Themeli"** on branch `revamp/super-portal`.
Start with **THEMELI.md** (what exists in the code: tokens, components, JS/PHP helpers,
writing guide) and **PROGRESS.md** (phases, defects fixed, open items). The files below
are the original specification and audit baseline.

Repository state audited:
- repository: SignorTynam/DatabasaElektronikeQTA
- newest repository branch: feature/raporti-qkl
- audited commit: 69608cefc55ccf5a5fc92114c342d1b94aa58d1a
- commit date: 2026-08-25

## Goal

Rebuild the complete QTA public website and authenticated portal around a calm, efficient, Claude-inspired interaction system while keeping QTA's own identity and certification workflow.

This is a structural redesign:
- new palette and semantic tokens;
- new typography hierarchy;
- new authenticated app shell;
- new public shell;
- new navigation and command/search treatment;
- new form, table, dialog, feedback, and empty-state patterns;
- removal of legacy visual compatibility layers;
- systematic accessibility and responsive behavior.

It is not a cosmetic reskin.

## What "Claude-inspired" means here

Public Anthropic guidance favors deliberate typography, disciplined hierarchy, meaningful structure, restrained motion, direct interface writing, and avoiding generic SaaS/AI dashboard defaults. Claude's public product patterns also emphasize persistent navigation and a dedicated work area.

QTA adapts those principles to a certification registry. We do not claim that these files reproduce Anthropic's private/internal design tokens.

## Documentation map

- THEMELI.md — the implemented system (read first).
- PROGRESS.md — progress ledger, defects fixed, security fixes, open items.
- ../domain/COURSES-AND-SCHEDULES.md — courses → modules → topics, scheduled groups,
  scheduling rules, legacy isolation, historical policy (with `db/migrations/README.md`).
- SUPER-PORTAL-PROMPT.md — the execution prompt that drove the implementation.
- FOUNDATIONS.md — design DNA, layout, palette, typography, spacing, radii, motion.
- COMPONENTS.md — shell and reusable component contracts.
- ACCESSIBILITY.md — WCAG 2.2 AA and keyboard/ARIA requirements.
- AUDIT.md — findings from the audited commit, including dead-code candidates.
- MIGRATION.md — phased implementation sequence and cleanup gates.
- QA.md — acceptance checklist.
- SOURCES.md — official/public research sources.

## Architecture principle

The current branch already centralized much of its visual system into tokens.css, protokoll.css, app.css, public.css and legacy-map.css. The new redesign must use that centralization advantage but replace the PROTOKOLL concept. Do not stack an additional theme layer over it.

The desired end state is a small set of semantic CSS modules:
- tokens.css — primitives + semantic light/dark tokens;
- base.css — reset, typography, links, focus, base controls;
- components.css — buttons, inputs, badges, alerts, dialogs, tables, pagination, states;
- shell.css — authenticated sidebar/topbar/drawer/command palette;
- public.css — public masthead, landing/login/contact/verification layouts;
- page-specific CSS only when a page has a truly unique visualization.

Bootstrap may remain as a transitional layout/behavior dependency, but QTA's appearance must come from QTA components and tokens.

**End state reached:** tokens.css, base.css, components.css, shell.css, public.css (+ the
standalone error.css). protokoll.css, app.css, legacy-map.css and claude-ui.css were removed
after a repository-wide reference scan showed no page loading them.
