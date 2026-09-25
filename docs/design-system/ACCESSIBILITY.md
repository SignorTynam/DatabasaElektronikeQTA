# QTA Accessibility Contract

Target: WCAG 2.2 AA minimum, with selected stronger interaction targets where practical.

Accessibility is a design-system requirement, not a final cleanup phase.

## 1. Keyboard

Every interactive feature must be usable without a pointer.

Required:

- logical tab order;
- no positive tabindex values;
- visible focus;
- Escape closes dismissible modal layers;
- dialogs/drawers restore focus;
- menus/tabs/command palette use expected keyboard patterns;
- drag/reorder interactions have button/keyboard alternatives;
- hover is never the only way to reveal required actions.

## 2. Focus

- never globally remove outline;
- custom focus must be clearly visible against adjacent colors;
- sticky headers/sidebars must not fully hide focused elements;
- use scroll-margin or equivalent when sticky UI can cover anchors/focused targets;
- modal focus stays inside until the modal closes.

Project preference: a clear 2px-equivalent focus treatment with strong visual contrast.

## 3. Contrast

Minimum targets:

- normal text: 4.5:1;
- large text: 3:1;
- important UI component boundaries/state indicators: 3:1 where WCAG non-text contrast applies.

Check actual semantic foreground/background pairs in both themes. Do not infer compliance from token names.

## 4. Target size

WCAG 2.2 minimum target-size requirements must be satisfied.

Project ergonomic target:

- ordinary primary controls around 40px minimum;
- 44px preferred for important touch controls.

Small icon actions require spacing or expanded hit areas.

## 5. Forms

Every form control:

- has a programmatic and visible label unless the visual context is legitimately self-labeling;
- exposes required state;
- exposes error state;
- associates explanatory/error text with aria-describedby where useful;
- does not rely on placeholder text as the only instruction;
- supports 200% text zoom without clipping critical content.

On validation failure:

- preserve entered values where safe;
- show clear field-level error;
- for long forms, provide an error summary linking to invalid fields where practical;
- move/focus only when doing so helps the user and does not create unexpected context changes.

## 6. Tables

- real tabular data uses table semantics;
- th scope/headers are correct;
- sort controls are buttons/links with accessible state;
- selected rows expose state beyond color;
- bulk actions announce selected count;
- responsive changes must not destroy reading order.

## 7. Dialogs, drawers and popovers

Dialogs/drawers:

- accessible name;
- correct modal semantics;
- initial focus placed intentionally;
- focus trap;
- Escape support unless intentionally blocking;
- focus restoration.

Popovers/menus:

- trigger exposes expanded state;
- relationship to popup is understandable;
- keyboard navigation works;
- clicking outside may close, but keyboard users get an equivalent.

## 8. Status and feedback

- do not use color alone;
- success/error text is explicit;
- async completion uses appropriate live-region behavior when needed;
- toasts are not the sole location for critical/actionable errors;
- loading states have accessible text, not spinner-only meaning.

## 9. Motion

Respect prefers-reduced-motion.

When reduced motion is active:

- remove nonessential transform/parallax/entrance sequences;
- keep state change understandable;
- do not replace motion with flashing.

## 10. Themes

Light, System and Dark must preserve:

- text contrast;
- focus visibility;
- input boundaries;
- selected/active states;
- status meaning.

Dark mode is a semantic token mode, not a CSS invert filter.

## 11. Mobile and zoom

Verify:

- 320px and 375px widths;
- 200% browser text zoom;
- no hidden submit/cancel actions;
- no horizontal overflow for ordinary pages;
- data tables may scroll only when comparison semantics require it;
- drawer and dialog remain operable with virtual keyboards.

## 12. Images/icons

- informative images have meaningful alt;
- decorative images use empty alt when appropriate;
- icon-only buttons have accessible names;
- decorative icons are hidden from assistive technology;
- QR/certificate status is also provided as text.

## 13. Language

Document language is Albanian (`lang="sq"`) unless a page genuinely uses another primary language.

Avoid unexplained English UI strings in Albanian workflows.

## 14. Acceptance checklist

Before a redesigned surface is complete:

- keyboard-only task completed;
- focus visibly tracked;
- screen-reader naming/roles checked for primary controls;
- light and dark contrast checked;
- 200% zoom checked;
- reduced-motion checked;
- mobile drawer/dialog checked;
- error flow checked;
- loading flow checked if asynchronous;
- no functionality is hover-only.
