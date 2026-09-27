# QTA Component Contracts

Status: canonical UI component contract for the Claude-inspired redesign.

The goal is a small, consistent component vocabulary that replaces page-local Bootstrap styling and the PROTOKOLL visual model without changing QTA business behavior.

## 1. App shell

### Desktop

Authenticated pages use one persistent left sidebar:

- expanded width: 248px;
- collapsed rail: 64px when the viewport and task justify it;
- warm neutral sidebar surface;
- QTA mark and product name at the top;
- task-based navigation groups;
- global search/command trigger;
- current user, theme and account actions at the bottom;
- active navigation state uses surface/contrast first and accent only as a restrained indicator.

The main workspace:

- fills the remaining viewport;
- has a contextual page header;
- does not repeat the entire global navigation in a top navbar;
- ordinary pages use a comfortable content width;
- tables/data workspaces may use full available width.

### Tablet/mobile

- sidebar becomes an off-canvas drawer;
- a compact app bar provides menu, short title and the one most important local action;
- drawer traps focus while modal, Escape closes it, focus returns to the trigger;
- no hover-only navigation;
- no separate duplicate permission model for mobile.

## 2. Public shell

Anonymous/public pages use:

- quiet masthead;
- QTA identity;
- Verifikimi as a high-priority public utility;
- restrained navigation;
- no authenticated sidebar;
- warm neutral canvas and Claude-like editorial hierarchy;
- real content before decorative marketing.

## 3. Page header

Each authenticated view should expose:

1. optional eyebrow/context only when useful;
2. h1;
3. short supporting sentence only if it clarifies the task;
4. one primary action;
5. optional secondary actions in a compact menu/group.

Do not turn the page header into a toolbar containing every possible action.

## 4. Buttons

### Variants

- primary: high-contrast neutral fill;
- secondary: surface + border;
- ghost: transparent, low chrome;
- accent: terracotta only when the interaction genuinely benefits from accent emphasis;
- danger: destructive only.

### Rules

- minimum preferred height: 40px; use 44px for primary touch actions where space permits;
- labels describe outcomes;
- icon + text for important actions;
- icon-only requires accessible name and tooltip when meaning is not universal;
- loading preserves width and context;
- disabled state must remain legible;
- focus-visible is never removed.

## 5. Form controls

Input, textarea, select, checkbox, radio and switch share:

- visible label;
- optional description;
- input/control;
- validation/error message;
- consistent focus ring;
- consistent disabled/read-only presentation.

Placeholder is never a label replacement.

Validation:

- error is adjacent to the field;
- long forms may also use an error summary;
- aria-describedby links description/error to the control;
- color is never the only error signal.

## 6. Search and command palette

Global search/command palette:

- Ctrl/Cmd+K shortcut;
- optional "/" shortcut only outside editable controls;
- grouped results;
- keyboard up/down navigation;
- Enter opens selected result;
- Escape closes;
- active descendant/focus behavior must be screen-reader compatible;
- loading and empty state are explicit;
- search never becomes an authorization boundary.

## 7. Tables

QTA is data-heavy. Tables remain first-class, not converted blindly into card grids.

Desktop:

- compact but readable 13–14px data text;
- sticky header only where useful and never obscuring focus;
- consistent row height;
- sortable headers where functionality exists;
- actions in a predictable trailing column/menu;
- bulk actions appear after selection;
- selected/editing state visible beyond color alone;
- identifiers may use monospace.

Responsive:

- preserve table semantics when horizontal comparison matters;
- use horizontal scroll only when necessary;
- where row-wise comprehension matters more than column comparison, provide a stacked record pattern instead of squeezing.

## 8. Cards and surfaces

Use a card only for a bounded object or a surface that genuinely needs separation.

Default hierarchy:

1. whitespace/alignment;
2. subtle surface change;
3. border;
4. shadow only for temporary/elevated layers.

Avoid card-inside-card nesting.

## 9. Badges and statuses

Badges are compact state/category labels.

- pill radius is allowed here;
- use icon/text or text/state, not color alone;
- success, warning, danger and info colors are semantic only;
- avoid using badges as decoration for ordinary metadata.

## 10. Alerts, banners and toasts

Use:

- inline alert: local section problem/state;
- page banner: condition affecting the entire page/workspace;
- toast: short success/info acknowledgement;
- dialog: action requiring an immediate decision.

Never report an actionable failure only in a disappearing toast.

## 11. Dialogs and drawers

Prefer:

1. inline expansion;
2. popover;
3. side panel/drawer;
4. modal only for short interruptive tasks.

Dialogs must:

- have an accessible name;
- trap focus;
- close on Escape unless blocking is intentionally required;
- restore focus to the trigger;
- separate destructive confirmation from ordinary actions;
- not contain giant multi-section editing workflows.

## 12. Tabs

Tabs are for peer views of the same local context, not site navigation.

- keyboard arrow navigation;
- selected state exposed with aria-selected;
- tab panel relationship explicit;
- no role tabs that silently change authorization assumptions.

## 13. Empty states

Every empty state states:

- what is empty;
- why it matters if useful;
- what the user can do next.

Use a CTA only if the user is allowed to perform it.

## 14. Loading

- preserve context and layout;
- avoid full-page spinners for normal server navigation;
- skeletons only for genuinely asynchronous regions;
- submit buttons show in-progress state and prevent accidental duplicate submission where appropriate.

## 15. Edit mode

QTA has explicit edit-mode behavior. Preserve its business rules.

Redesign requirements:

- edit mode state must be impossible to miss;
- active editing gets a restrained persistent indicator, not a giant warning banner;
- field-level changes are obvious;
- save/cancel actions remain stable;
- destructive controls appear only where authorized and understandable;
- leaving with unsaved changes receives appropriate warning if the existing behavior requires it.

## 16. Dashboard

Dashboard hierarchy by role:

- tasks requiring attention;
- resume/continue work;
- upcoming items;
- recent changes/activity;
- useful metrics after actionable information.

Do not create a grid of oversized KPI cards merely because numbers are available.

## 17. Public verification

Verification is a primary QTA utility.

Design:

- one obvious input/scan action;
- QR/file scanning as a secondary path;
- result presents validity first;
- certificate identity/details follow;
- invalid/not-found/error states are unambiguous;
- no paper-document simulation is required for the web result;
- printable/exported documents may preserve their own print-specific design.

## 18. Theme control

Support:

- Light;
- System;
- Dark.

The persisted user preference should be explicit; System follows prefers-color-scheme.

Do not duplicate theme logic in every page.

## 19. Typography roles

Baseline:

- UI/body: IBM Plex Sans;
- display/editorial headings: Source Serif 4;
- technical identifiers: IBM Plex Mono.

If implementation keeps Spectral temporarily, treat it as a migration state. The final system must choose one canonical serif and load only what is used.

## 20. Definition of complete component

A component is complete only when:

- semantic markup is correct;
- all states exist;
- light/dark work;
- keyboard works;
- mobile works;
- reduced motion is respected;
- labels/messages are Albanian and consistent;
- no page-local hardcoded visual values are needed to make it usable.
