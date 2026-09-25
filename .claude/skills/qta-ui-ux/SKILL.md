---
name: qta-ui-ux
description: Apply the QTA Claude-inspired UI/UX design system when creating, reviewing, or refactoring public pages, authenticated portal pages, forms, tables, navigation, search, dialogs, states, or frontend behavior in DatabasaElektronikeQTA.
---

# QTA UI/UX Skill

Use this skill for every user-facing change in QTA.

## First principle

Design for the actual QTA job: managing training registrations, groups, modules, student records, certification evidence, audit history, document generation, and public certificate verification.

Claude is the interaction reference, not the product subject. Copy its restraint and workspace clarity, not its identity.

## Required context

Read first:
- docs/design-system/THEMELI.md — the implemented system ("Themeli"): tokens, components, JS/PHP helpers, page anatomy, writing guide. Reuse these; do not invent a per-page vocabulary.
- docs/design-system/PROGRESS.md — what is done and what is still open.

Background specification:
- docs/design-system/FOUNDATIONS.md
- docs/design-system/COMPONENTS.md
- docs/design-system/ACCESSIBILITY.md
- docs/design-system/AUDIT.md
- docs/design-system/MIGRATION.md
- docs/design-system/QA.md

## Two-pass design process

Pass 1 — plan before code:
- identify the page's primary job and primary user;
- identify what must be immediately visible;
- list the 4–6 semantic visual tokens most relevant to this view;
- identify reusable components and interaction states;
- sketch the hierarchy in text before editing PHP/CSS.

Pass 2 — critique:
- remove anything that looks like generic AI dashboard decoration;
- check that every border, label, icon, badge, card, and section exists because it conveys structure or state;
- ensure the page is still clearly QTA, not a Claude mockup;
- only then implement.

## QTA visual DNA

- Warm neutral background and surfaces.
- Dark neutral typography with high contrast.
- Terracotta interaction accent is allowed sparingly; it is not a background wash applied everywhere.
- QTA blue remains available for trusted reference/link/verification semantics.
- Moderate radii; not square "document" geometry and not oversized SaaS pills.
- Minimal shadows.
- A desktop left sidebar for authenticated navigation, collapsing to an accessible mobile drawer.
- Page-local top toolbar for title, context, search/command access, and actions.
- Content organized by task flow; use cards only when a bounded object truly needs a container.
- Data tables remain dense and efficient, with clear row actions and edit states.

## Typography

Use one functional sans-serif for UI and one clearly distinct serif only where it improves hierarchy. The implemented baseline is Atkinson Hyperlegible Next for UI (chosen for legibility for non-technical users), Atkinson Hyperlegible Mono for identifiers, and Source Serif 4 for page titles (tokens `--font-sans`, `--font-mono`, `--font-display`). Do not use monospace as decorative metadata. Reserve monospace for identifiers, codes, AMZË values, tokens, or technically tabular data.

Use sentence case. Avoid tracked all-caps labels. Keep prose line length generally below 80 characters.

## Interaction rules

- One primary action per local context.
- Button labels describe outcomes: "Ruaj ndryshimet", "Krijo grupin", "Gjenero dokumentin", not vague "Submit".
- Keep the same verb through the action and feedback.
- Error text says what happened and what the user can do next.
- Empty states propose the next useful action.
- Loading state never erases context.
- User-triggered transitions: about 120–200 ms.
- Respect prefers-reduced-motion.
- Never require hover.
- Do not auto-animate every section on page load.

## Accessibility floor

Target WCAG 2.2 AA:
- normal text contrast >= 4.5:1;
- large text >= 3:1;
- visible keyboard focus;
- project target size >= 40x40 CSS px for primary controls, never below WCAG 24x24 without the allowed spacing/equivalent exception;
- semantic headings/landmarks;
- labels bound to form controls;
- validation tied with aria-describedby where useful;
- accessible dialogs with focus trapping, Escape, and focus return;
- accessible menu buttons with correct aria-expanded;
- all pointer interactions must have keyboard equivalents.

## Legacy migration

Done on `revamp/super-portal`: the PROTOKOLL layer and legacy-map.css (with app.css, protokoll.css, claude-ui.css) are removed; every page uses the Themeli shell.

The role navbars (`app/shared/inc/navbar*.php` and the `app/pages/inc/navbar*.php` wrappers) remain on purpose: they are thin role entry points into the shared shell (`app/shared/inc/app_navbar.php`). Remove them only after a repository-wide reference scan and a browser check for every role.

## Verification

If browser automation is available:
- start/load the local app;
- capture desktop and mobile screenshots;
- check console errors;
- verify interactive elements;
- exercise navigation, command search, one dialog, one form, one table, and theme switch;
- compare light and dark;
- retry fixes, then re-check.

A UI task is not complete because PHP renders without an exception.
