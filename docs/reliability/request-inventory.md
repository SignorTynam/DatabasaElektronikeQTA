# Inventari i request-eve QTA

2026-10-08. Inventar statik i entrypoint-eve; veprimet dhe transaksionet ne sherbimet e perbashketa pershkruhen ne raport. Route-t e rishkruara dhe wrappers ruhen. Kolona e transaksionit nuk pretendon se leximet kane nevoje per transaksion.

| Skedari | Lloji | Sesioni | Veprime te deklaruara | Transaksioni | State/flash i shkurter |
|---|---|---|---|---|---|
| `app/actions/agencies_inline_update.php` | POST / mutations JSON | qta_session_boot -> close | - | begin/commit + rollback | - |
| `app/actions/agencies_students_update.php` | POST / list read-only + mutations JSON | qta_session_boot -> close | list_assigned (read), assign_by_amze, unlink | batch inserts ne transaksion, unlink nje DELETE | - |
| `app/actions/calendar_data.php` | GET / JSON read-only | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/actions/course_structure_update.php` | POST / mutations JSON | qta_session_boot -> close | add_module, update_module, delete_module, add_topic, update_topic, delete_topic, move, normalize, set_course_hours, set_module_hours, update_course | qta_tx ne sherbimet curriculum | - |
| `app/actions/courses_inline_update.php` | POST / mutations JSON | qta_session_boot -> close | move_group_course, update_field | qta_tx (sherbim) | - |
| `app/actions/create_admin.php` | Vetem CLI / mutation | pa sesion lokal / wrapper | - | begin/commit + rollback | - |
| `app/actions/editors_inline.php` | POST / mutations JSON | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/actions/group_conversion_update.php` | POST / preview read-only + mutations JSON | qta_session_boot -> close | propose (read), rebalance (read), save, refresh, convert | qta_tx ne sherbimet conversion | flash_ok |
| `app/actions/group_results.php` | POST / sheet read-only + save JSON | qta_session_boot -> close | sheet (read), save | qta_tx ne sherbimet results | - |
| `app/actions/groups_inline_update.php` | POST / mutations JSON | qta_session_boot -> close | set_group_completed, update_cell, update_final_score, update_group_end, update_group_start, update_student_exam_date | nje statement ose sherbim domaini / lexim | - |
| `app/actions/lesson_group_update.php` | POST / preview read-only + mutations JSON | qta_session_boot -> close | preview_new (read), change (preview ose save), rebalance (preview ose save), members, delete | qta_tx ne sherbimet lesson_groups | flash_ok |
| `app/actions/login_handler.php` | POST / mutation sesioni, JSON ose redirect | qta_session_boot -> close | login | pa transaction DB, session ID regeneration | login_error, login_identifier |
| `app/actions/logout.php` | GET / ndryshim sesioni, redirect | start -> destroy (pa DB) | - | nje statement ose sherbim domaini / lexim | - |
| `app/actions/search_advanced.php` | GET / JSON read-only | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/actions/student_assignment.php` | POST / mutations JSON | qta_session_boot -> close | assign_to_group, set_student_plan, remove_student_plan | qta_tx | - |
| `app/actions/student_card_inline.php` | POST / mutations JSON | qta_session_boot -> close | add_amze_for_person, delete_amze, delete_participation, generate_qr, generate_qr_person, qr_payload, set_person_field, set_student_field | begin/commit + rollback | - |
| `app/actions/students_inline_update.php` | POST / mutations JSON | qta_session_boot -> close | check_amze, link_person_by_pn, merge_students | begin/commit + rollback | - |
| `app/actions/user_inline.php` | POST / mutations JSON | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/aboutus.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/agencies.php` | GET/POST ose POST / shih veprimet | qta_session_boot -> close | create_agency, delete_agency | begin/commit + rollback | csrf_token, edit_mode, flash |
| `app/pages/calendar.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/contact.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/course.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | csrf_token, edit_mode, flash |
| `app/pages/courses.php` | GET/POST ose POST / shih veprimet | qta_session_boot -> close | create_course, delete_course | nje statement ose sherbim domaini / lexim | csrf_token, edit_mode, flash |
| `app/pages/dashboard_admin.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/dashboard_agjencia.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/dashboard_editor.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/dashboard_student.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/editors.php` | GET/POST ose POST / shih veprimet | qta_session_boot -> close | create_editor, delete_user, reset_password | begin/commit + rollback | csrf_token, edit_mode, flash |
| `app/pages/footer.php` | Wrapper kompatibiliteti / perfshirje | pa sesion lokal / wrapper | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/group_conversion.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | csrf_token, edit_mode, flash_ok |
| `app/pages/group_conversions.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | edit_mode |
| `app/pages/groups.php` | GET/POST ose POST / shih veprimet | qta_session_boot -> close | create_group, delete_group, edit_members, update_group_course | begin/commit + rollback | csrf_token, edit_mode, flash_err, flash_err_list, flash_ok, flash_ok_list |
| `app/pages/groups_agjencia.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/groups_student.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/index.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/lesson_group.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | csrf_token, edit_mode, flash_ok |
| `app/pages/lesson_groups.php` | GET/POST ose POST / shih veprimet | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | csrf_token, edit_mode, flash_err, flash_ok, lg_create_form |
| `app/pages/logs.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/logs_editor.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/navbarMain.php` | Wrapper kompatibiliteti / perfshirje | pa sesion lokal / wrapper | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/ndihme.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/profile.php` | GET/POST ose POST / shih veprimet | qta_session_boot -> close | change_password, update_staff_extra, update_user_info | nje statement ose sherbim domaini / lexim | csrf_token, flash |
| `app/pages/register_agjencia.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | csrf_token |
| `app/pages/selectProfile.php` | GET / redirect i hyrjes | pa sesion lokal / wrapper | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/student_card.php` | GET / lexim | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | csrf_token, edit_mode, flash |
| `app/pages/students.php` | GET/POST ose POST / shih veprimet | qta_session_boot -> close | create_student, delete_student | begin/commit + rollback | csrf_token, edit_mode, flash |
| `app/pages/students_without_groups.php` | GET / lexim | pa sesion lokal / wrapper | - | nje statement ose sherbim domaini / lexim | - |
| `app/pages/users.php` | GET/POST ose POST / shih veprimet | qta_session_boot -> close | create_admin, delete_user, reset_password | begin/commit + rollback | csrf_token, edit_mode, flash |
| `app/pages/verify.php` | GET/POST ose POST / shih veprimet | qta_session_boot -> close | verify | nje statement ose sherbim domaini / lexim | - |
| `app/exports/download_lista_emerore.php` | Eksport / lexim (GET ose POST sipas formatit) | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/exports/download_praktika_profesionale.php` | Eksport / lexim (GET ose POST sipas formatit) | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/exports/download_proces_verbal.php` | Eksport / lexim (GET ose POST sipas formatit) | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/exports/download_regjistri_mesimit.php` | Eksport / lexim (GET ose POST sipas formatit) | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/exports/download_rregullat_sigurimi_teknik.php` | Eksport / lexim (GET ose POST sipas formatit) | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/exports/groups_export.php` | Eksport / lexim (GET ose POST sipas formatit) | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |
| `app/exports/register_export_agency.php` | Eksport / lexim (GET ose POST sipas formatit) | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | csrf_token |
| `app/exports/students_export.php` | Eksport / lexim (GET ose POST sipas formatit) | qta_session_boot -> close | - | nje statement ose sherbim domaini / lexim | - |

## AJAX dhe loading

Te gjitha thirrjet fetch ne kodin e aplikacionit kalojne te `qtaFetch.response`; `request.js` mban transportin native. Nuk u gjet XMLHttpRequest. PDO::fetch nuk eshte kerkese HTTP.

| Skedari | Thirrje te transportit | finally |
|---|---:|---:|
| `app/assets/js/app.js` | 2 | 3 |
| `app/assets/js/calendar.js` | 2 | 2 |
| `app/assets/js/curriculum.js` | 1 | 5 |
| `app/assets/js/group-conversion.js` | 1 | 5 |
| `app/assets/js/group-results.js` | 1 | 1 |
| `app/assets/js/lesson-group.js` | 1 | 11 |
| `app/assets/js/lesson-groups.js` | 1 | 1 |
| `app/assets/js/login-ui.js` | 1 | 1 |
| `app/assets/js/students.js` | 3 | 7 |
| `app/assets/js/verify.js` | 1 | 2 |
| `app/pages/agencies.php` | 1 | 2 |
| `app/pages/courses.php` | 1 | 2 |
| `app/pages/groups.php` | 1 | 2 |
| `app/pages/student_card.php` | 1 | 1 |
| `app/shared/partials/staff_accounts.php` | 1 | 1 |

`finally` ne nje skedar nuk provon vetem pastrimin e cdo rrjedhe: rrjedhat inline, format native, kalendari, kerkimet, piket, konvertimi, curriculum dhe bulk u kontrolluan vecmas. Gjendjet pa spinner (preview/empty/error) perfundojne ne then/catch. Format POST normale menaxhohen nga lifecycle i perbashket.
