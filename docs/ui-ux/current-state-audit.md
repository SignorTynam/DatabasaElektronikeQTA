# Current-state audit

Audit scope: branch `feature/raporti-qkl`, commit `69608ce` (`Refactor edit mode session handling and enhance advanced search functionality`), inspected 2026-09-25. The worktree was clean before this documentation was added.

## Validation completed

- All tracked PHP files passed `php -l`.
- All tracked JavaScript files passed `node --check`.
- Repository structure, includes, static routes, asset references, duplicate hashes, inline styles/scripts, and the last commit were inspected.
- Runtime browser validation was blocked because `localhost:80` refused connections; Apache/MySQL-backed flows were not exercised.

## Confirmed defects

### P1 — agency groups search/clear targets a nonexistent route

`app/pages/groups_agjencia.php` posts the filter form and clear action to `groups_agency.php`, but the real route is `groups_agjencia.php`. This will produce a failed navigation/404 when the controls are used.

Required fix: change both references and add a route-level regression check.

### P1 — database failures expose internal exception details

`app/shared/database.php` exits with the raw PDO exception message. Production users may see host/schema/driver details.

Required fix: move configuration to environment/deployment config, log server-side with a correlation ID, and show a generic user-safe error page. This is outside a purely visual refactor but must be tracked before release.

### P2 — CSRF/session token is embedded in export URLs

Register/export links place the CSRF token in query strings. URLs can be retained in browser history, logs, analytics, and referrer data. File generation may be read-like, but authorization still belongs server-side and session tokens should not travel in URLs.

Required fix: use POST for protected export creation or replace the session CSRF value with a short-lived, narrowly scoped download token. Preserve bookmarkable GET filters separately.

## Confirmed cleanup candidates

Deletion still requires one final runtime/reference check in the implementation branch.

- `app/shared/partials/form1_modal.php` and `form2_modal.php`, plus their two wrappers under `app/pages/partials/`, have no callers found in tracked PHP. The active group export UI uses `qkl_report_modal.php` and the four named download modals.
- `app/exports/image/*` contains four byte-identical copies of the root `image/*` logos. Consolidate to one canonical asset directory after confirming export libraries can resolve the root assets.
- Root `400.html`, `401.html`, `403.html`, `404.html`, and `500.html` are not referenced by `.htaccess` or application code. Either wire them through `ErrorDocument` and restyle them, or remove them after confirming server-level configuration does not reference them externally.

## Keep until migration proves otherwise

- `app/pages/database.php`, action/export database shims, navbar wrappers, audit bootstrap wrappers, and partial wrappers are active compatibility paths. They are duplication, but not dead code.
- `app/exports/vendor/autoload.php` and `app/vendor/autoload.php` are small Composer path shims used by export fallback logic. Consolidate the loader strategy before deleting either.
- `.htaccess` root-level route rewrites are part of the current public URL contract.

## Architecture and maintainability risks

### CSS cascade has overlapping owners

Authenticated pages load Bootstrap, `tokens.css`, `protokoll.css`, `app.css`, and `legacy-map.css`; public pages load Bootstrap, `tokens.css`, `protokoll.css`, and `public.css`. Both `protokoll.css` and `app.css` redefine buttons, forms, tables, cards, modals, and responsive behavior. `legacy-map.css` adds another compatibility layer. Specificity and load order, rather than component contracts, decide many results.

Migration rule: create a new semantic token/component layer, move one component family at a time, then delete the replaced legacy rules only after visual/route coverage.

### Large mixed-responsibility files

Examples: `groups.php` (~1.9k lines), `students.php` (~1.5k), `verify.php` (~1k), `student_card.php` (~900), `app.css` (~2k), and `app.js` (~730). Several pages mix queries, mutations, HTML, inline JS, and inline styles.

Migration rule: do not combine visual redesign with a wholesale backend rewrite. Extract shared presentation primitives first; separate endpoint/domain logic only in focused, tested follow-ups.

### Inline presentation and page-local behavior

Many pages contain `style=` attributes or embedded scripts; `selectProfile.php`, `verify.php`, and `contact.php` are the heaviest. This prevents reliable theming and component reuse.

Migration rule: move repeated presentation to semantic CSS classes and shared JS modules. Inline values may remain only when genuinely data-derived (for example, a measured progress value) and must expose accessible text.

### Fragile relative URL assumptions

The application depends on root-level rewritten URLs while templates live under `app/pages`. Asset and endpoint URLs are relative strings. Directly serving `/app/pages/...` may resolve assets differently.

Migration rule: introduce one base-path/URL helper and use it for assets, routes, AJAX, and exports before changing deployment paths.

### No automated application test harness

Composer defines dependencies but no test/static-analysis scripts. Syntax checks passed, but there is no route/role regression suite or browser accessibility/visual coverage.

Migration rule: add a small smoke matrix for public routes and each role, then browser tests for navigation, filters, editing, modals, exports, theme, and responsive overflow.

### CDN and font dependencies

Core CSS/JS/fonts are loaded from Google Fonts and jsDelivr. This introduces availability, privacy, CSP, and version-control concerns for an institutional portal.

Migration rule: pin and preferably self-host approved font/icon/runtime assets; define CSP and integrity/caching strategy.

## Recommended sequence

1. Fix the broken agency route and production error exposure separately from visual work.
2. Add URL helper and route/role smoke tests.
3. Add the new token layer and application shell behind a reversible body/feature flag.
4. Migrate shared navigation, page header, buttons, inputs, feedback, dialogs, filters, then tables.
5. Migrate dashboards and high-value workflows in vertical slices by role.
6. Migrate public pages and error pages.
7. Run visual/accessibility regression; remove proven dead partials, duplicate assets, and superseded CSS/JS.
