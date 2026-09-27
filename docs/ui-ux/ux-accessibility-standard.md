# QTA UX and accessibility standard

Target: WCAG 2.2 AA and efficient use by administrators, editors, agencies, students, and public visitors.

## 1. UX principles

Apply Nielsen's usability heuristics: visible system status, real-world/domain language, user control, consistency, error prevention, recognition over recall, efficient expert paths, minimalist presentation, useful recovery messages, and contextual help ([NN/g heuristics](https://www.nngroup.com/articles/ten-usability-heuristics/)).

For an institutional service, borrow the GOV.UK discipline of reusable researched components, small-screen-first layout, bounded line length, and consistent responsive spacing—not its branding ([GOV.UK Design System](https://design-system.service.gov.uk/), [layout](https://design-system.service.gov.uk/styles/layout/), [spacing](https://design-system.service.gov.uk/styles/spacing/)).

### Portal-specific interpretation

- Organize navigation and page titles around user tasks, not database table names.
- Keep the current role and edit/read-only state visible.
- Preserve context after save, validation failure, filtering, pagination, export, and back navigation.
- Prefer safe defaults and reversible actions. Destructive operations require explicit confirmation and a clear object name.
- Reduce memory load: show active filters, selected records, required formats, and next steps.
- Support expert efficiency with search, sensible focus placement, keyboard shortcuts only when discoverable, and bulk actions with explicit scope.

## 2. Accessibility acceptance criteria

Use the official [WCAG 2.2 standard](https://www.w3.org/TR/WCAG22/) and [W3C understanding guidance](https://www.w3.org/WAI/WCAG22/Understanding/).

- Semantic landmarks: one `main`, labelled navigation, useful headings in order, real buttons/links, and native form controls where possible.
- Full keyboard operation with logical DOM/focus order and no traps. Focus must remain visible and not be hidden behind sticky UI.
- Focus indicator: at least a 2px equivalent perimeter and ≥3:1 contrast against adjacent states; use `:focus-visible` without removing the browser fallback.
- Text contrast ≥4.5:1 (≥3:1 for large text); component boundaries and meaningful graphics ≥3:1. Never rely on color alone.
- Pointer targets ≥24×24 CSS px or sufficient spacing; prefer 44×44 for primary mobile controls.
- At 200% zoom and 320 CSS px width, content reflows without two-dimensional page scrolling. A wide data table may scroll within a labelled region.
- Form labels remain visible. Errors identify the field, explain the problem in Albanian, suggest recovery, and are programmatically associated/announced.
- Loading/status updates use `aria-live` only at the appropriate politeness. Do not steal focus for background completion.
- Dialogs set initial focus deliberately, trap it while open, close with Escape when cancellation is safe, and restore focus to the trigger.
- Images have purposeful alternatives; decorative images use empty alt. Icons supplement text or receive accessible names.
- No essential action requires dragging. Motion and autoplay obey reduced-motion preferences.
- Authentication must not impose avoidable cognitive tests; paste/password-manager use should not be blocked.

## 3. Responsive and data-dense behavior

- 360px: single column, drawer navigation, full-width primary action, filters in a sheet/drawer, no clipped dialogs.
- 768px: two-column layouts only when reading order remains clear; sidebar may be collapsed.
- 1024px: persistent navigation, flexible content grid.
- 1440px+: cap reading content; allow operational tables to use extra width.
- Do not convert every table to cards. Preserve comparison across columns. On narrow screens, prioritize columns, offer row details, and keep the table's accessible structure.
- Sticky headers/columns must not obscure focused cells or create duplicate announcements.

## 4. Required states per feature

Every async or data-backed view must define: initial, loading, populated, empty, filtered-empty, validation error, permission/read-only, server/network error, and success/recovery. Actions additionally define hover, pressed, focus-visible, disabled, and in-progress states.

## 5. Content standard

- UI language is Albanian; retain established domain terms. Avoid English labels except unavoidable technical identifiers.
- Button labels are verbs plus object when useful: “Ruaj ndryshimet”, “Shto student”, “Eksporto regjistrin”. Avoid generic “OK” or “Submit”.
- Error copy says what happened, what remains safe, and what to do next. Never expose raw SQL/PDO/server errors.
- Dates display consistently as `DD-MM-YYYY`; machine values use valid ISO formats underneath.

## 6. Definition of done

A user-facing change is not done until it has been rendered and checked at representative widths, keyboard-only, light/dark, 200% zoom, reduced motion, and relevant role/state combinations. Automated linting alone is insufficient.
