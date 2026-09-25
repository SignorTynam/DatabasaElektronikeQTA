# Claude-inspired design system for QTA

Status: implementation contract. Last researched: 2026-09-25.

## 1. Design intent

The portal should feel calm, literate, capable, and direct. Favor generous negative space, warm neutral surfaces, strong typography, subtle borders, limited elevation, and one scarce warm action color. Complexity belongs in progressive disclosure, not in visual noise.

QTA remains the brand. Use QTA logos and Albanian institutional language. Never use the Claude name/logo as portal branding or reproduce proprietary illustrations/assets.

## 2. Evidence from Claude

Official Anthropic material establishes these product patterns:

- A collapsible left sidebar and appearance choices for Light, System, and Dark; Claude also offers a dyslexia-friendly font option ([Claude Help: appearance settings](https://support.claude.com/en/articles/8887527-customizing-your-appearance-settings)).
- Work is grouped into persistent Projects, while generated work opens side-by-side with the conversation in a dedicated panel ([Anthropic: Projects and Artifacts](https://www.anthropic.com/news/projects)).
- Claude Design extracts and maintains colors, typography, components, and layout patterns from real codebases/assets; Anthropic recommends validating with real examples and iterating ([Claude Help: design systems](https://support.claude.com/en/articles/14604397-set-up-your-design-system-in-claude-design)).
- Artifacts are persistent, editable, reusable outputs discoverable from the sidebar ([Claude Help: Artifacts](https://support.claude.com/en/articles/17153992-what-are-artifacts-and-how-do-i-use-them)).

Observed on the public Claude web surface on 2026-09-25:

- Product brand/action clay variable: `#C6613F`.
- Dark base: approximately `#151515`; high-emphasis dark-theme text: approximately `#F0EFEC`.
- Warm light neutrals include `#F3F3F0`, `#F0EFEC`, and `#E7E6E1`.
- Product sans is `anthropic-sans`; because it is not a QTA-owned redistributable font, QTA uses a legal open/system substitute.
- Controls commonly use 6–10px radii, low/no shadow, and compact 14–16px UI text.

Third-party measured reference: Mozaika reports `#FAF9F5` background, `#3D3929` text, `#C96442` accent, a 4px spacing base, and 8px radii ([Mozaika Claude UI decode](https://mozaika.design/ds/claude-ui)). Treat this as corroboration, not an Anthropic specification.

## 3. QTA token contract

All implementation values must be exposed as semantic CSS custom properties. Component CSS consumes semantic tokens, never palette primitives directly.

### Light theme

| Token | Value | Use |
|---|---:|---|
| `--qta-bg` | `#FAF9F5` | application canvas |
| `--qta-surface` | `#FFFFFF` | raised working surface/dialog |
| `--qta-surface-subtle` | `#F0EFEC` | sidebar, grouped rows, muted panels |
| `--qta-surface-hover` | `#E7E6E1` | neutral hover/selected background |
| `--qta-border` | `#DDDCD6` | default separators and control borders |
| `--qta-border-strong` | `#B8B5AC` | emphasized separation |
| `--qta-text` | `#2B2922` | primary text |
| `--qta-text-muted` | `#68655D` | secondary text; chosen darker than Claude-like `#848176` for AA |
| `--qta-action-fill` | `#C6613F` | primary button/highlight only |
| `--qta-action-on-fill` | `#151515` | text/icons on clay; contrast ≈4.51:1 |
| `--qta-action-text` | `#9C452A` | links/focus/accent text; contrast >6:1 |
| `--qta-focus` | `#1C5CAB` | focus ring; distinct from brand/action meaning |
| `--qta-success` | `#2F6B3C` | success only |
| `--qta-warning` | `#8A5A00` | warning/pending only |
| `--qta-danger` | `#B42318` | destructive/error only |

### Dark theme

| Token | Value | Use |
|---|---:|---|
| `--qta-bg` | `#151515` | application canvas |
| `--qta-surface` | `#1E1E1D` | working surface/dialog |
| `--qta-surface-subtle` | `#252523` | sidebar/grouped content |
| `--qta-surface-hover` | `#30302D` | hover/selected |
| `--qta-border` | `#3B3A36` | default border |
| `--qta-border-strong` | `#57554E` | emphasized border |
| `--qta-text` | `#F0EFEC` | primary text |
| `--qta-text-muted` | `#C3C2B7` | secondary text |
| `--qta-action-fill` | `#D97757` | primary action |
| `--qta-action-on-fill` | `#151515` | on-action content |
| `--qta-action-text` | `#F19A7C` | links/accent text |
| `--qta-focus` | `#6DA7EC` | focus ring |

Semantic status colors need paired subtle backgrounds and borders. Never communicate status by color alone.

### Typography

- UI/body: `Inter`, `system-ui`, `Segoe UI`, sans-serif. Prefer self-hosted Inter or the system stack; do not depend on Anthropic fonts.
- Editorial/display accent: `Source Serif 4`, `Iowan Old Style`, Georgia, serif. Use only for public hero headings or a restrained page title, never for dense tables/forms.
- Data/code: `IBM Plex Mono`, `Cascadia Mono`, monospace for protocol numbers, IDs, dates, and tokens.
- Base size: 16px public/content pages; 15px administrative UI; never below 12px.
- Scale: 12, 14, 15/16, 18, 24, 32, 40px with proportional line heights. Use `clamp()` for 24px+ headings.
- Limit prose lines to roughly 65–75 characters. Use tabular numerals in dense data.

### Spacing, shape, elevation, motion

- Spacing base: 4px; allowed scale: 4, 8, 12, 16, 24, 32, 48, 64px.
- Default control height: 40px; compact table control: 32px; prominent touch action: 44px minimum.
- Radius: 6px compact controls, 8px buttons/inputs/cards, 12px dialogs/large panels. Pills only for true tags/statuses/toggles.
- Shadows: none for ordinary cards. Use borders and surface contrast. Dialogs/popovers may use one low, diffuse shadow.
- Motion: 120–180ms for hover/focus and 180–240ms for panel/dialog transitions. Animate opacity/transform only. Respect `prefers-reduced-motion`.

## 4. Layout model

### Authenticated application shell

- Desktop ≥1024px: collapsible 248px left sidebar, 56–64px top/context bar if needed, flexible main area capped around 1440px for dashboards; data workspaces can use full width.
- Tablet 768–1023px: sidebar collapses to an icon rail or modal drawer; labels remain available via drawer and accessible names.
- Mobile <768px: single column, off-canvas navigation, sticky compact page actions only when they do not obscure focus/content.
- Every page starts with a consistent header: breadcrumb/context (optional), H1, concise description, then one primary action and grouped secondary actions.
- Keep secondary details in drawers, accordions, or side panels when they support the current task. Do not hide primary data or required form inputs.

### Public pages

Use editorial serif headings, larger whitespace, one clear call to action, and the same tokens/components as the app. Avoid decorative gradients, glassmorphism, oversized rounded cards, floating blobs, or animation without task value.

## 5. Component rules

- **Buttons:** primary clay fill; neutral secondary; text/quiet tertiary; red destructive. One primary per action region. Disabled controls explain why when non-obvious.
- **Inputs:** persistent visible label, optional hint, 40px height, clear required/optional semantics, inline error tied with `aria-describedby`. Placeholder is never the label.
- **Tables:** visible column headers, sortable state in text/ARIA, sticky header for long tables, restrained row separators, optional density toggle, horizontal scroll contained locally. Critical actions remain reachable by keyboard and never appear only on hover.
- **Filters/search:** one obvious search field; active filters shown as removable chips plus “Pastro të gjitha”; URL reflects shareable GET filters; filter drawer on small screens.
- **Navigation:** clear current page, grouped by task/domain, stable position, labels plus icons. Role-specific navigation remains server-authorized.
- **Cards:** use only to group a coherent object or action; no dashboard “card soup.” Prefer sections, lists, and tables.
- **Dialogs:** proper accessible dialog semantics, labelled title, focus trap/restore, Escape for cancel, explicit destructive confirmation, no full workflows squeezed into small modals.
- **Feedback:** skeleton/progress for waits, inline validation for fields, toast only for transient confirmations, persistent alert for blocking failures, empty states that explain the next action.

## 6. Prohibited drift

- No arbitrary hex colors, arbitrary radii, or page-specific spacing scales.
- No new Bootstrap visual utility combinations as permanent design API; wrap shared semantic classes/components.
- No icon-only mystery actions, hover-only row actions, or color-only statuses.
- No wholesale use of clay on navigation/sidebar/backgrounds.
- No generic AI aesthetics: gradients, glowing borders, excessive pills, large hero metrics, or decorative motion.
