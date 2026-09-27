# Claude Code Guidance — QTA UI/UX Redesign

Read AGENTS.md first. For any frontend or interaction task, load .claude/skills/qta-ui-ux/SKILL.md and follow the complete design-system documentation under docs/design-system/.

## Design intent

The target is not a clone of claude.ai and not a recolored Bootstrap dashboard. The target is the same interaction DNA:

- calm, warm-neutral canvas;
- high information clarity;
- strong but restrained typography;
- persistent, low-chrome navigation;
- generous workspace rather than nested card stacks;
- subtle borders and hierarchy instead of decorative shadows;
- one obvious primary action per context;
- direct microcopy;
- fast keyboard-first workflows;
- restrained motion tied to user action;
- excellent empty/error/loading states;
- accessibility as a baseline, not a cleanup pass.

QTA must still feel like a professional certification and training registry. Keep QTA's brand logo and domain semantics. Do not imitate Anthropic branding or wording.

## Implementation guardrails

Do not:
- reintroduce the current PROTOKOLL/paper-register aesthetic;
- keep legacy-map.css indefinitely;
- create a new one-off CSS vocabulary for each page;
- change database behavior while doing visual refactors;
- delete compatibility wrappers until a repository-wide reference scan proves they are unused;
- remove export assets until export generation has been verified.

Do:
- start from semantic tokens;
- build shared shell + components first;
- migrate representative pages before bulk migration;
- validate visual and functional regressions continuously;
- use browser screenshots at desktop and mobile widths;
- keep interaction names stable from action to feedback, e.g. "Ruaj ndryshimet" -> "Ndryshimet u ruajtën".

The authoritative implementation phases are in docs/design-system/MIGRATION.md.
