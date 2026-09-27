# UI/UX implementation plan

Status: active implementation plan for branch `feature/raporti-qkl`.

## Route and role matrix

| Surface | Visitor | Administrator | Editor | Agency | Student |
|---|---:|---:|---:|---:|---:|
| Public home/about/contact/verify/errors | yes | yes | yes | yes | yes |
| Role dashboard/profile | — | admin dashboard | editor dashboard | agency dashboard | student dashboard |
| Users/editors/agencies/students/cards | — | full | scoped | own students/cards | own record |
| Register/groups/courses | — | full | edit-mode scoped | own register/groups | own certifications |
| Audit logs | — | all | own | — | — |
| Exports/downloads | — | authorized | authorized | own records | — |

Every authenticated route has normal, edit/read-only, empty, filtered-empty, error, and responsive states where applicable. Export flows retain authorization and move protected parameters from GET URLs to POST.

## Component migration map

| Existing surface | New shared contract |
|---|---|
| `tokens.css` legacy paper/ink variables | semantic `--qta-*` tokens plus compatibility aliases |
| horizontal `.app-bar` | desktop Claude-inspired sidebar; compact mobile top bar/drawer |
| `.masthead` | restrained public header using the same tokens |
| Bootstrap `.btn*`, forms, cards, tables, modals | shared semantic appearance in `claude-ui.css` |
| page title blocks | consistent editorial page header |
| table filters/search palette | low-chrome search, explicit focus/loading/empty/error states |
| static error-page inline CSS | shared `error.css` and server `ErrorDocument` wiring |

## Phases and rollback boundaries

1. **Safety fixes:** broken agency route, safe database failure, POST-based register exports. Independently reversible PHP diffs.
2. **Foundation:** semantic tokens, font stack, shared Claude-inspired stylesheet. Revert by removing the final stylesheet link.
3. **Shell/components:** sidebar/mobile drawer and shared component overrides. Existing markup and Bootstrap behavior remain underneath.
4. **Public/errors:** public masthead/content and shared static error page system.
5. **Cleanup:** delete only caller-free form partials after a final reference scan. Duplicate export images remain until document-generation runtime tests are available.
6. **Verification:** PHP/JS syntax, static route checks, and real-browser viewport/theme/keyboard checks when Apache/MySQL are available.

## Files in scope

- Modify: shared heads, navigation markup, tokens, application JS, affected routes/exports, database bootstrap, `.htaccess`, and static error pages.
- Add: `app/assets/css/claude-ui.css`, `app/assets/css/error.css`.
- Delete only after proof: the unused `form1_modal.php` and `form2_modal.php` shared partials and wrappers.
- Retain: database/navbar/audit/autoload compatibility shims, rewrite routes, and duplicate export images pending runtime export validation.
