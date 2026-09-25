# Foundations

## 1. Product character

QTA is a professional working system for people who need to find, register, verify, group, edit, export, and audit records quickly.

The interface should feel:
- calm;
- precise;
- contemporary;
- trustworthy;
- human;
- information-dense without being visually dense.

It should not feel like:
- a paper register simulation;
- a government form facsimile;
- a generic Bootstrap admin template;
- an AI-generated gradient dashboard;
- a clone of claude.ai.

## 2. Core layout

### Authenticated desktop

Use a persistent left navigation sidebar.

Baseline:
- sidebar: 248px;
- main canvas fills remaining viewport;
- page content: fluid with a comfortable max-width around 1440px for ordinary pages;
- data-heavy tables may use wider fluid space;
- page header remains in the main canvas, not in a global giant top navbar;
- command search is available globally with Ctrl/Cmd+K and optionally / when focus is not in an editable control.

Structure:

    +----------------------+-------------------------------------------+
    | QTA                  | Page title                 [actions]       |
    | Search / command     | context / breadcrumb if useful            |
    |----------------------|-------------------------------------------|
    | Dashboard            |                                           |
    | Kursantët            | main workspace                            |
    | Grupet               |                                           |
    | Modulet              |                                           |
    | ...                  |                                           |
    |                      |                                           |
    |----------------------|                                           |
    | theme / account      |                                           |
    +----------------------+-------------------------------------------+

Navigation content remains role-based. One shell renders different allowed items from the existing role/capability model.

### Tablet/mobile

Below the desktop breakpoint:
- sidebar becomes an off-canvas drawer;
- top app bar contains menu trigger, short page title, and the most important action;
- drawer opening moves focus into it, traps focus while modal, Escape closes it, and close returns focus to the trigger;
- content remains one column unless two-column layout genuinely improves the task.

### Public pages

Use a quiet masthead, wide neutral canvas, and clear content hierarchy. Do not use the authenticated sidebar for anonymous users.

Public verification is the primary public utility and must be immediately discoverable.

## 3. Color system

These values are the QTA baseline, chosen to capture Claude-like warmth and restraint while preserving QTA identity. They are not claimed as Anthropic's internal tokens.

### Light semantic baseline

- canvas: #F7F6F2
- sidebar: #F0EEE8
- surface: #FCFBF8
- surface-raised: #FFFFFF
- text-primary: #272622
- text-secondary: #6B6862
- border: #E3DFD6
- border-strong: #CFC8BC
- action-primary: #2F2E2B
- action-primary-text: #FFFFFF
- accent-interaction: #D97757
- accent-interaction-text: #242320
- qta-reference/link: #0858A0
- danger: #B42318
- success: #256B4A
- warning: #8A6500

Contrast checks for the intended pairs:
- text-primary on canvas: about 14:1
- text-secondary on canvas: about 5.1:1
- white on action-primary: about 13.6:1
- accent-interaction-text on accent-interaction: about 5.0:1
- QTA reference blue on canvas: about 6.7:1
- danger/success/warning on canvas all meet 4.5:1.

Important: #D97757 is not suitable as a background with white normal-size text. When the accent itself is the filled surface, use the dark accent text token.

### Dark semantic baseline

- canvas: #1B1A18
- sidebar: #161513
- surface: #22211E
- surface-raised: #2A2824
- text-primary: #F1EEE8
- text-secondary: #AAA49A
- border: #3D3A34
- border-strong: #575249
- action-primary: #F1EEE8
- action-primary-text: #1B1A18
- accent-interaction: #E08B6D
- accent-interaction-text: #242320
- qta-reference/link: #8CC2EE

Semantic status colors need dark-mode counterparts tested against dark surfaces.

### Color behavior

- Accent is for selection, focus emphasis, small indicators, and occasional secondary action emphasis.
- Primary actions are normally high-contrast neutral, not terracotta-filled by default.
- QTA blue is retained for trusted references, links, verified-reference semantics, and the logo ecosystem.
- Red is destructive/error only.
- Do not communicate status through color alone.

## 4. Typography

Recommended:
- UI/body: IBM Plex Sans.
- Display/section headings: Source Serif 4.
- Monospace: IBM Plex Mono only for identifiers, codes, AMZË, generated tokens, tabular technical values.

Rules:
- sentence case by default;
- no decorative all-caps tracking;
- one clear type scale shared across all pages;
- ordinary body copy line length generally <= 80 characters;
- strong headings should use typography and whitespace, not gradient fills;
- labels remain visible; placeholder is not a replacement for a label.

Suggested scale:
- xs: 12px / 16px
- sm: 13px / 18px
- body: 14px / 20px
- body-lg: 16px / 24px
- h3: 18–20px / 26px
- h2: 24–28px / 32px
- h1: 32–40px / 44px depending on context

Dense data pages can use 13–14px table text while maintaining usable row height.

## 5. Spacing

Use a 4px base:
- 4, 8, 12, 16, 20, 24, 32, 40, 48, 64.

Prefer spacing to extra boxes. Before adding a border/card, first ask whether whitespace and alignment can express the grouping.

## 6. Radii

Use hierarchy:
- 6px: small controls/chips where needed;
- 8px: inputs/buttons;
- 12px: menus/popovers/compact cards;
- 16px: larger contained panels/dialogs;
- full radius only for avatars and true status pills.

Do not use one radius for every component.

## 7. Elevation

Most surfaces use no shadow.

Allowed:
- subtle menu/popover shadow;
- dialog shadow;
- drag/temporary floating state.

Use border + surface change before shadow.

## 8. Icons

Bootstrap Icons can remain initially.

Rules:
- icon + text for important actions;
- icon-only actions need accessible names and tooltips when meaning is not universal;
- 16–20px standard size;
- avoid large decorative icon circles;
- do not use icons as substitutes for labels in complex workflows.

## 9. Motion

Default interaction transitions: about 120–200 ms.

Use motion for:
- drawer/menu opening;
- dialog state;
- expanding/collapsing details;
- confirmation of a state change.

Avoid:
- AOS-style scroll reveals across the product;
- card hover lift on every surface;
- page-load cascade on every section;
- perpetual decorative motion.

Respect prefers-reduced-motion.

## 10. Content style

UI language is Albanian.

Use active, concrete verbs:
- "Ruaj ndryshimet"
- "Krijo grupin"
- "Shto kursantin"
- "Gjenero dokumentin"
- "Anulo"

Keep action names stable in feedback:
- button: "Ruaj ndryshimet"
- toast: "Ndryshimet u ruajtën"

Errors describe:
1. what failed;
2. why, when known;
3. what the user can do next.

Empty states describe the next useful action, not a decorative slogan.
