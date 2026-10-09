# Regjistrimi i qëndrueshëm në kurs — raport implementimi

Data e verifikimit: 09.10.2026. Ndryshimet janë në working tree; pa commit.
Migrimi është provuar vetëm në databaza testimi të izoluara, në MariaDB port 3307.
Databaza e përdorimit real nuk është migruar.

## Architecture

Më parë periudha dhe rezultatet vareshin nga anëtarësia në grup. Largimi nga
grupi mund të shkëpuste identitetin e kursit dhe të fshinte pikët e moduleve.
`student_course_plans` është tani regjistrimi i qëndrueshëm kursant–kurs:
periudha individuale, mënyra e rezultatit, provimi para grupit dhe revision.
Anëtarësia është organizimi operacional i këtij regjistrimi. Identiteti dhe
pikët mbeten kur kursanti largohet ose grupi fshihet.

Modeli zgjeron tabelat ekzistuese dhe përdor motorin ekzistues të orarit;
nuk krijon një regjistër paralel kursantësh ose një motor të dytë planifikimi.

## Database

Migrimi: `db/migrations/2026-10-09-student-course-enrollment-details.sql`.
Kërkon migrimet deri më 08.10.2026; kontrolli i parakushteve ndodh para ndryshimit
të strukturës. Udhëzimet dhe SQL e verifikimit janë në `db/migrations/README.md`.

- `student_course_plans`: `start_date`, `end_date`, `exam_date`,
  `manual_final_score`, `legacy_result`, `result_source`, `revision`.
- `enrollment_result_modules`: kopje historike e moduleve me ID burimore,
  rend, titull dhe orë; identiteti nuk varet nga ndryshimet e katalogut.
- `enrollment_module_scores`: pronësi përmes `enrollment_id`; çelës kryesor
  `(enrollment_id,module_id)`, FK te regjistrimi/kursanti dhe moduli historik.
  `group_id` bëhet nullable dhe mbetet projekcion për përputhshmërinë e lexuesve.
- `group_enrollment_capacity`: numërues atomik me kufi 0–10; trigger-at e
  insert/delete/move e mbajnë të sinkronizuar edhe nën shkrime konkurruese.
- `uq_cgs_student`, `uq_scp_id_student`, `idx_cg_course_period`, indekset e
  moduleve dhe CHECK-et mbrojnë anëtarësinë, datat, intervalin dhe precizionin.
- Trigger-at mbrojnë caktimin, ndryshimet e periudhës, pronësinë e rezultatit,
  largimin/fshirjen e grupit, fshirjet e prindërve dhe auditimin.

Backfill-i ruan ID-të ekzistuese, plotëson periudhat nga grupi dhe lidh pikët
me regjistrimin dhe modulet burimore. Provimi i vjetër i grupit kopjohet vetëm
nëse provimi individual mungon; shënuesi i migrimit pengon rikthimin e një
provimi të pastruar individualisht gjatë riekzekutimit. Pikët manuale/legacy
nuk shpërndahen në module dhe nuk krijohen rezultate artificiale.

Migrimi ndalon për të dhëna historike që shkelin invariantet; nuk i korrigjon
duke hedhur të dhëna. DDL kërkon dritare mirëmbajtjeje dhe backup sipas README.
Garancia e ruajtjes së historikut vlen për largim nga grupi/fshirje grupi;
fshirja e qëllimshme e vetë kursantit mbetet fshirje e të dhënave të tij.

## Backend

`app/shared/enrollments.php` përqendron validimin, snapshot-in, ruajtjen,
parakontrollin e krijimit të grupit, kandidatët, caktimin, operacionet masive
dhe pajtimin e periudhave. `results.php` ruan rregullat e pikëve dhe mesataren
vetëm kur të gjitha modulet kanë rezultat. Input-et pranojnë maksimum dy
shifra dhjetore; CHECK-et pengojnë rrumbullakimin e heshtur të input-eve të tjera.

Endpoint-et e kursantit, kartelës, grupeve të vjetra dhe grupeve me orar
përdorin këta helpers. Wizard-i regjistron kursantin, kursin, periudhën,
rezultatin dhe provimin brenda një transaksioni. Caktimi ruan anëtarësinë,
provimin dhe pikët atomikisht. Konflikti përmban revision/baseline; serveri
riverifikon kursin, kapacitetin, orarin dhe provimin pas zgjedhjes së përdoruesit.

Tre zgjidhjet janë: datat e grupit me konfirmim, datat e kursantit duke
ripërdorur ose krijuar grup me periudhë të saktë, ose grupet e sugjeruara.
Kandidatët renditen sipas përputhjes dhe diferencës së datave, me lexime në
grup dhe kufi SQL 30. Shkrimet bllokojnë rreshtat përkatës; numëruesi DB
garanton kufirin edhe kur dy sesione shohin të njëjtin vend të fundit.
Operacionet masive parakontrollohen dhe kryhen të gjitha ose asnjëra.

U përsërit kërkimi në të gjithë `app/actions`, `app/pages` dhe `app/shared`
për shkrime direkte në anëtarësi, regjistrime, rezultate dhe data grupi.
Rrugët ekzistuese të largimit/fshirjes mbështeten në trigger-at e ruajtjes;
ndryshimet e datave kalojnë në pajtim. Bashkimi automatik i kursantëve me
regjistrime kursi bllokohet që të mos humbasë historiku.

## UI/UX

- Wizard i përbashkët: të dhënat bazë → kursi → rishikimi → një ruajtje.
  Zgjedhja e kursit më vonë mbetet e mundur.
- Kursi i plotë përdor module; kursi draft përdor rezultat manual. Kalimi i
  mëvonshëm në module është eksplicit dhe ruan prejardhjen manuale.
- Dialogu i konfliktit shpjegon të tre zgjedhjet, periudhat dhe pasojat;
  adoptimi i datave kërkon konfirmimin përkatës.
- Krijimi i grupit përdor provimin e përbashkët vetëm për anëtarët fillestarë;
  grupi bosh nuk ruan një provim grupi si burim aktiv.
- Anulimi ruan fushat dhe rikthen kontekstin. Nga rezultatet, editimi i
  regjistrimit kërkon më parë ruajtjen/zhbërjen e pikëve të ndryshuara;
  anulimi rikthen fletën e rezultateve dhe fokusin te butoni origjinal.

Komponentët përdorin Themeli dhe token-et semantike ekzistuese. Nuk shtohet
stylesheet përputhshmërie ose framework i ri.

## Compatibility

Grupet legacy vazhdojnë në rrugët ekzistuese; grupet scheduled përdorin
modulet historike dhe motorin ekzistues. Rezultatet ekzistuese mbeten të
lexueshme dhe të audituara. Raporti QKL, kartela, regjistri i agjencisë,
pamjet e kursantit dhe eksportet lexojnë periudhën individuale me fallback
te grupi për të dhënat e vjetra. Kalendari dhe dokumentet operative të grupit
vazhdojnë të tregojnë periudhën e grupit.

`qta_enrollment_snapshot` ekspozon periudhën, modulet, rezultatin dhe provimin
për një lexues të ardhshëm certifikatash. Nuk shpiket status lëshimi dhe nuk
ndryshon autentikimi, QR-ja ose mekanizmi i lëshimit të certifikatës.

## Tests

- Suita përfundimtare `php tests/run.php --integration`: **2154 kaluan, 0 dështuan**.
  U ekzekutua në një skemë të re të izoluar, pas migrimeve dhe seed-it të testimit.
- PHP syntax: **172 skedarë**; JavaScript syntax: **6 skripte**; `git diff --check` kaloi.
- Teste të reja: parser/rezultate, shërbimi i regjistrimit, migrimi mbi të
  dhëna ekzistuese/riekzekutim/parakushte, konkurrenca dhe HTTP real.
- HTTP: role, CSRF, edit mode, wizard atomik, ID e pavlefshme, manual/modules
  dhe rishikimi i konfliktit. Regresionet ekzistuese të kalendarit, rezultateve,
  konvertimit, grupeve dhe raporteve u përfshinë në suitën e plotë.
- Browser: rrjedhat e administratorit për regjistrim/caktim/krijim grupi;
  lista dhe hapja/anulimi i wizard-it si editor; pamje publike, agjenci dhe
  kursant; gjendje të zbrazëta dhe leje.
- Editor-i dhe konflikti u kontrolluan në 320/375/768/1024/1440 px, tema e
  çelët/e errët, pa overflow horizontal të faqes/dialogut. U provuan Tab,
  Enter, anulimi dhe rikthimi i fokusit. Pamjet ruhen në `output/playwright/`.

Kufizime verifikimi: emulimi i zmadhimit real të tekstit në 200% dhe i
`prefers-reduced-motion` nuk ishte i disponueshëm në API-n e këtij browser-i;
reflow në ekran të ngushtë dhe mbështetja ekzistuese në CSS/JS u kontrolluan.
Konsola shënoi gabimin ekzistues `InvalidStateError: Transition was aborted
because of invalid state. ViewTransition opt-in disabled` gjatë navigimit;
nuk u pa përjashtim JavaScript nga kodi i ri. Nuk është kryer një audit
i plotë i çdo kërkese network të të gjithë portalit.

## Manual verification

- [ ] Kurs me module: ruaj 80 dhe 90; rezultati përfundimtar 85 vetëm pasi
  plotësohen të gjitha modulet. Hiq një pikë dhe kontrollo gjendjen e paplotë.
- [ ] Kurs draft: ruaj 78 manualisht; shto module më vonë dhe verifiko që
  78 ruhet pa u ndarë në module.
- [ ] Provo të tre zgjidhjet e konfliktit dhe anulimin; refuzo provimin para
  mbarimit dhe provo një kandidat që mbushet nga një sesion tjetër.
- [ ] Largo kursantin dhe fshi grupin testues; kontrollo periudhën, provimin,
  pikët dhe auditimin në kartelë. Përdor vetëm të dhëna testimi.
- [ ] Krahaso QKL/eksportet me periudhën individuale dhe kalendarin me grupin.
- [ ] Provo vetëm me tastierë, tekst 200%, reduced motion, tema të dyfishta
  dhe një ekran të ngushtë në browser-in e përditshëm.

## Files changed

Lista e saktë më poshtë përfshin skedarët e versionuar dhe skedarët e rinj të
implementimit. Artefaktet lokale të testimit në `output/` nuk përfshihen.

Gjithsej: **56 skedarë**.

- [app/actions/courses_inline_update.php](C:/xampp/htdocs/DatabasaElektronike/app/actions/courses_inline_update.php) — ndryshuar
- [app/actions/groups_inline_update.php](C:/xampp/htdocs/DatabasaElektronike/app/actions/groups_inline_update.php) — ndryshuar
- [app/actions/lesson_group_update.php](C:/xampp/htdocs/DatabasaElektronike/app/actions/lesson_group_update.php) — ndryshuar
- [app/actions/student_assignment.php](C:/xampp/htdocs/DatabasaElektronike/app/actions/student_assignment.php) — ndryshuar
- [app/actions/student_card_inline.php](C:/xampp/htdocs/DatabasaElektronike/app/actions/student_card_inline.php) — ndryshuar
- [app/actions/students_inline_update.php](C:/xampp/htdocs/DatabasaElektronike/app/actions/students_inline_update.php) — ndryshuar
- [app/assets/css/components.css](C:/xampp/htdocs/DatabasaElektronike/app/assets/css/components.css) — ndryshuar
- [app/assets/js/enrollment-review.js](C:/xampp/htdocs/DatabasaElektronike/app/assets/js/enrollment-review.js) — i ri
- [app/assets/js/enrollments.js](C:/xampp/htdocs/DatabasaElektronike/app/assets/js/enrollments.js) — i ri
- [app/assets/js/group-results.js](C:/xampp/htdocs/DatabasaElektronike/app/assets/js/group-results.js) — ndryshuar
- [app/assets/js/lesson-group.js](C:/xampp/htdocs/DatabasaElektronike/app/assets/js/lesson-group.js) — ndryshuar
- [app/assets/js/lesson-groups.js](C:/xampp/htdocs/DatabasaElektronike/app/assets/js/lesson-groups.js) — ndryshuar
- [app/assets/js/students.js](C:/xampp/htdocs/DatabasaElektronike/app/assets/js/students.js) — ndryshuar
- [app/exports/inc/qkl_report.php](C:/xampp/htdocs/DatabasaElektronike/app/exports/inc/qkl_report.php) — ndryshuar
- [app/exports/register_export_agency.php](C:/xampp/htdocs/DatabasaElektronike/app/exports/register_export_agency.php) — ndryshuar
- [app/exports/students_export.php](C:/xampp/htdocs/DatabasaElektronike/app/exports/students_export.php) — ndryshuar
- [app/pages/dashboard_student.php](C:/xampp/htdocs/DatabasaElektronike/app/pages/dashboard_student.php) — ndryshuar
- [app/pages/groups_agjencia.php](C:/xampp/htdocs/DatabasaElektronike/app/pages/groups_agjencia.php) — ndryshuar
- [app/pages/groups_student.php](C:/xampp/htdocs/DatabasaElektronike/app/pages/groups_student.php) — ndryshuar
- [app/pages/groups.php](C:/xampp/htdocs/DatabasaElektronike/app/pages/groups.php) — ndryshuar
- [app/pages/lesson_group.php](C:/xampp/htdocs/DatabasaElektronike/app/pages/lesson_group.php) — ndryshuar
- [app/pages/lesson_groups.php](C:/xampp/htdocs/DatabasaElektronike/app/pages/lesson_groups.php) — ndryshuar
- [app/pages/register_agjencia.php](C:/xampp/htdocs/DatabasaElektronike/app/pages/register_agjencia.php) — ndryshuar
- [app/pages/student_card.php](C:/xampp/htdocs/DatabasaElektronike/app/pages/student_card.php) — ndryshuar
- [app/pages/students.php](C:/xampp/htdocs/DatabasaElektronike/app/pages/students.php) — ndryshuar
- [app/shared/activity_log.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/activity_log.php) — ndryshuar
- [app/shared/app_scripts.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/app_scripts.php) — ndryshuar
- [app/shared/enrollments.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/enrollments.php) — i ri
- [app/shared/group_members.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/group_members.php) — ndryshuar
- [app/shared/help_topics.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/help_topics.php) — ndryshuar
- [app/shared/lesson_groups.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/lesson_groups.php) — ndryshuar
- [app/shared/partials/enrollment_dialog.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/partials/enrollment_dialog.php) — i ri
- [app/shared/partials/results_dialog.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/partials/results_dialog.php) — ndryshuar
- [app/shared/results.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/results.php) — ndryshuar
- [app/shared/students_list.php](C:/xampp/htdocs/DatabasaElektronike/app/shared/students_list.php) — ndryshuar
- [db/migrations/2026-10-09-student-course-enrollment-details.sql](C:/xampp/htdocs/DatabasaElektronike/db/migrations/2026-10-09-student-course-enrollment-details.sql) — i ri
- [db/migrations/README.md](C:/xampp/htdocs/DatabasaElektronike/db/migrations/README.md) — ndryshuar
- [docs/domain/COURSES-AND-SCHEDULES.md](C:/xampp/htdocs/DatabasaElektronike/docs/domain/COURSES-AND-SCHEDULES.md) — ndryshuar
- [docs/domain/ENROLLMENT-AUDIT.md](C:/xampp/htdocs/DatabasaElektronike/docs/domain/ENROLLMENT-AUDIT.md) — i ri
- [docs/domain/ENROLLMENT-IMPLEMENTATION.md](C:/xampp/htdocs/DatabasaElektronike/docs/domain/ENROLLMENT-IMPLEMENTATION.md) — i ri
- [tests/fixtures/enrollment_worker.php](C:/xampp/htdocs/DatabasaElektronike/tests/fixtures/enrollment_worker.php) — i ri
- [tests/integration/calendar_http_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/calendar_http_test.php) — ndryshuar
- [tests/integration/calendar_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/calendar_test.php) — ndryshuar
- [tests/integration/course_change_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/course_change_test.php) — ndryshuar
- [tests/integration/enrollment_concurrency_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/enrollment_concurrency_test.php) — i ri
- [tests/integration/enrollment_http_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/enrollment_http_test.php) — i ri
- [tests/integration/enrollment_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/enrollment_test.php) — i ri
- [tests/integration/legacy_conversion_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/legacy_conversion_test.php) — ndryshuar
- [tests/integration/lesson_groups_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/lesson_groups_test.php) — ndryshuar
- [tests/integration/lesson_register_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/lesson_register_test.php) — ndryshuar
- [tests/integration/migration_enrollment_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/migration_enrollment_test.php) — i ri
- [tests/integration/qkl_report_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/qkl_report_test.php) — ndryshuar
- [tests/integration/request_reliability_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/request_reliability_test.php) — ndryshuar
- [tests/integration/results_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/integration/results_test.php) — ndryshuar
- [tests/unit/enrollment_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/unit/enrollment_test.php) — i ri
- [tests/unit/qkl_report_test.php](C:/xampp/htdocs/DatabasaElektronike/tests/unit/qkl_report_test.php) — ndryshuar
