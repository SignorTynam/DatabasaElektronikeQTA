# QTA UI/UX Research Sources

Research baseline: 2026-09-25.

The project distinguishes:

- **official Anthropic product behavior/guidance**;
- **standards**;
- **QTA design decisions inspired by Claude**.

Do not claim that QTA knows or reproduces Anthropic's private design tokens.

## 1. Official Anthropic — Claude Design

Source:
https://www.anthropic.com/news/claude-design-anthropic-labs

Relevant principles:

- design systems can be derived from code/design files and reused consistently;
- explore multiple directions, then refine;
- prototypes should be realistic enough to evaluate;
- fine-grained control over spacing, color and layout matters;
- consistent system-level design is preferable to isolated mockups.

QTA implication:

Design foundations/components come before mass page migration.

## 2. Official Claude appearance behavior

Source:
https://support.claude.com/en/articles/8887527-customizing-your-appearance-settings

Documented behaviors include:

- Light;
- Match System;
- Dark;
- chat font preference;
- persistent but collapsible sidebar.

QTA implication:

Use Light/System/Dark and a persistent/collapsible authenticated sidebar.

QTA does not need to copy Claude's exact appearance controls or wording.

## 3. Official Claude Artifacts behavior

Source:
https://support.claude.com/en/articles/17153992-what-are-artifacts-and-how-do-i-use-them

Relevant behavior:

- work opens beside the primary conversation/context;
- the user can keep context and output visible together;
- artifact/work area is persistent and editable.

QTA implication:

Complex work should prefer workspace/context layouts and side panels over repeated modal interruptions.

## 4. Official Anthropic frontend-design skill

Source:
https://github.com/anthropics/claude-code/blob/main/plugins/frontend-design/skills/frontend-design/SKILL.md

Also:
https://github.com/anthropics/skills/blob/main/skills/frontend-design/SKILL.md

Core ideas to reuse:

- production-grade implementation;
- deliberate aesthetic direction;
- distinctive typography/palette/layout;
- CSS variables/design-system consistency;
- avoid generic AI/SaaS styling;
- design should be grounded in the actual product/domain.

QTA implication:

The design must look like a professional QTA operational system with Claude-like restraint—not like a generic “AI dashboard”.

## 5. Official Anthropic skill/project conventions

Sources:
https://github.com/anthropics/skills
https://github.com/anthropics/claude-plugins-official

Relevant convention:

Project-specific skills can live under:

`.claude/skills/<skill-name>/SKILL.md`

QTA uses:

`.claude/skills/qta-ui-ux/SKILL.md`

## 6. WCAG 2.2

Source:
https://www.w3.org/TR/WCAG22/

Additional overview:
https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/

Relevant requirements:

- contrast;
- keyboard;
- focus visibility;
- focus not obscured;
- target size;
- dragging alternatives;
- accessible authentication and interaction patterns.

Project target:

WCAG 2.2 AA minimum, with stronger focus/touch ergonomics where practical.

## 7. Figma MCP / design-system tooling

When available to the coding/design agent, use Figma design-system tooling for broad redesign tasks.

Recommended sequence:

1. inspect code tokens/components first;
2. inspect existing design file/library if one exists;
3. create primitives;
4. create semantic variables with Light/Dark modes;
5. document typography/spacing/radius/elevation;
6. create components and states;
7. bind variables;
8. visually review;
9. reconcile design and code.

Figma is not a production dependency. PHP/CSS remains application source of truth.

## 8. Browser automation

Use agent-browser, Playwright-style MCP, or equivalent when available.

Required for broad redesign verification:

- open real local route;
- wait for stable render/network;
- screenshot;
- inspect interactive elements;
- check console;
- check framework/runtime overlays;
- test navigation/dialog/form/table/theme;
- verify mobile and desktop.

A successful PHP render/build does not prove visual correctness.

## 9. Accessibility tooling

When available:

- axe-core / browser accessibility audit;
- Lighthouse accessibility;
- browser Accessibility Tree inspection.

Automated scans do not replace keyboard/manual review.

## 10. Community resources

Community UI/UX skills may be used as secondary checklists, but official Anthropic guidance and QTA's own docs win on conflict.

Do not copy third-party “Claude clones” or scraped unofficial token lists and present them as authoritative Claude design values.

## 11. Source hierarchy

When guidance conflicts, use:

1. current QTA functional requirements/security;
2. QTA design-system docs;
3. WCAG;
4. official Anthropic guidance/product behavior;
5. third-party UI guidance.

The goal is Claude-like quality and interaction DNA, not brand impersonation.
