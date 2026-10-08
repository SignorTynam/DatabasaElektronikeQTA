# QTA — request/session/loading reliability

Data: 08.10.2026. Bazë: `main`, commit `a2eeadc` (i njëjtë me `origin/main` pas fetch). Dega e punës: `codex/request-reliability`. Workspace ishte i pastër para ndryshimeve. Nuk është bërë deploy apo ndryshim skeme/të dhënash në `qta_db`.

Pas verifikimit u mbyll serveri i përkohshëm PHP në portën 8765 dhe u fshinë vetëm pesë databazat sintetike të krijuara për këtë punë. Apache/MySQL ekzistues dhe databaza `qta_db` mbetën të paprekur.

## ROOT CAUSES FOUND

1. **Session locking i provuar.** Faqet, AJAX dhe eksportet nisnin sesionin dhe e mbanin gjatë DB work/renderimit. Një worker legacy me 1.5 s punë bllokoi lexuesin tjetër për 1287–1326 ms. Me lifecycle të ri lexuesi përfundoi në 2–3 ms. Kjo provon mekanizmin lokal; pa trace nga prodhimi nuk mund t'i atribuojmë çdo incident historik vetëm këtij shkaku.
2. **Auditimi e rihapte sesionin fshehurazi.** `audit_bootstrap.php` thërriste `session_start()` edhe pasi endpoint-i e kishte mbyllur. Tani merr actor ID në mënyrë eksplicite; testi provon që përfshirja e helper-it nuk e rihap sesionin.
3. **Kërkesa AJAX pa deadline.** Fetch-et prisnin headers/body pa kufi të aplikacionit. Pastrimi i busy/disabled/aria-busy ishte i shpërndarë në success/catch dhe mungonte në disa rrjedha, përfshirë redaktimin e numrit personal dhe zhvendosjen e grupit.
4. **Krijim ndihmës jashtë transaksionit.** `groups.php` krijonte persons/users/students para validimit përfundimtar dhe para transaksionit të anëtarësisë. `add_amze_for_person` mund të linte user pasi insert-i i studentit dështonte. Testet tani injektojnë dështim pas këtyre shkrimeve dhe provojnë rollback të plotë, përfshirë audit-in.
5. **N kërkime numerike dhe input pa kufi legacy.** `CAST(nr_amze AS UNSIGNED)=?` përsëritej për çdo amzë. Parser-at legacy të grupit/agjencisë lejonin intervale masive; parser-i i preview-t në shfletues te grupet mund të bënte po ashtu loop masiv.
6. **Rrugë të dyfishta submit/confirmation.** `dataset.ready`, capture listeners dhe resubmit programatik nuk kishin një lifecycle të përbashkët. Tani eventi i anuluar për konfirmim nuk aktivizon loading; një submit në punë bllokon të dytin.
7. **Diagnozë dhe trajtim gabimesh jo konsistent.** Disa mutation exceptions ekspozonin mesazhin PDO, disa ktheheshin gjithmonë 400, disa merge errors injoroheshin. Lidhjet me DB nuk kishin connection/lock deadline të aplikacionit. Këto janë faktorë kontribuues, jo prova që çdo kërkesë e ngadaltë ishte lock i DB-së.

Inventari i 58 entrypoint-eve dhe thirrjeve AJAX është te [request-inventory.md](request-inventory.md). U kontrolluan `app/actions`, `app/pages`, `app/shared`, `app/assets/js`, eksportet, shims dhe routes ekzistuese. Nuk u gjet XMLHttpRequest. PDO::fetch është lexim DB, jo HTTP fetch.

## SESSION LOCKING FIX

`qta_session_boot()` nis sesionin një herë për request, siguron CSRF tokens, lexon identity/edit-mode dhe mbyll menjëherë file lock-un. `$_SESSION` shërben si snapshot lokal. Lidhja PDO, verifikimi i rolit nga DB, validimet dhe puna domain vazhdojnë pa session lock. Autorizimi/CSRF vazhdojnë të kryhen në server me të njëjtat fusha dhe role.

`qta_session_put/push/take` përdorin `qta_session_update`: rihapin për pak kohë, lexojnë state-in aktual, ndryshojnë vetëm çelësin përkatës dhe mbyllin në finally. Kështu një flash i vonuar nuk mbishkruan edit mode nga një kërkesë tjetër. Nëse actor-i ka ndryshuar ndërkohë, update nuk shkruan mbi llogarinë tjetër. Flash konsumohet para output-it; login dialog nuk rihap sesion gjatë renderimit.

Login rihap vetëm në sukses, rigjeneron session ID dhe mbyll pasi shkruan identity. Logout ruan nisjen/shkatërrimin e shkurtër ekzistues dhe nuk kryen DB work. CLI `create_admin` jep actor null. Navbar dhe audit shims ruhen; nuk u hoq wrapper/asset pa verifikim callers.

Prova HTTP përdor **dy procese serveri**, të njëjtin cookie/session store dhe një live request të autentikuar që kryen `SELECT SLEEP(1.5)`. POST real `edit_members` përfundoi në 34.2 ms në provën e fokusuar dhe 40 ms në paketën e plotë, ndërsa live request ishte ende aktiv. Një server zhvillimi PHP me një worker i vetëm do t'i serializonte kërkesat pavarësisht sesionit, ndaj nuk përdoret për këtë provë.

## REQUEST RELIABILITY FIX

`app/assets/js/request.js` ngarkohet para app.js dhe handlers inline, në panel dhe faqe publike. `qtaFetch` ofron credentials same-origin, JSON, AbortController, deadline default **20 s** të konfigurueshëm dhe objekt `QtaRequestError` me code/status/data/requestId. Deadline përfshin edhe leximin e body-t; testi provon transport që injoron abort dhe body të bllokuar. Nuk ka retry automatik për mutations. Login ruan vetëm riprovimin e vet ekzistues për rifreskim CSRF.

Të gjitha AJAX fetch-et e aplikacionit përdorin transportin e përbashkët. `qtaFetch.response` ruan API-në Response dhe protokollet ekzistuese 400/403/409/422 për handlers që interpretojnë validime/konfirmime. 401, 5xx, network, invalid JSON dhe timeout refuzohen. Varianti normal `qtaFetch` refuzon edhe HTTP/domain errors dhe kthen JSON në sukses. Varianti `.response` bufferon tekstin dhe përdoret për JSON/HTML; nuk është helper për shkarkime binare.

Busy states për inline edits, pikët, curriculum, grupet, konvertimin, agjencitë, kërkimet dhe kalendarin pastrohen në finally. Rezultatet e vjetra të live search nuk pastrojnë loading të kërkesës së re. Shkurtimi i kërkimit në më pak se dy shkronja anulon/invalidojnë përgjigjen e vjetër. Bulk assignment ndalon pas dështimit të transportit, ruan sukseset e njohura dhe paralajmëron për rezultatin e pasigurt.

Format normale POST kanë guard të përbashkët: loading aktivizohet vetëm pasi eventi ka mbetur i paanuluar; submitter name/value serializohet para disable. Konfirmimet dhe anulimi mbeten funksionale. Kontrollet rikthehen në pageshow ose pas watchdog 20 s me mesazh që kërkon kontroll të gjendjes. **Watchdog nuk anulon navigimin native dhe nuk mund të anulojë një commit në server.** Prandaj mesazhi nuk pretendon që ndryshimi nuk u ruajt.

Nuk u shtua CSS, framework, ngjyrë/token vizual apo loading global që ngrin portalin. Përdoren komponentët ekzistues, aria-busy dhe mesazhe shqip.

## DATABASE / TRANSACTION FIXES

- `getPDO`: utf8mb4, native prepares, exception mode; connection timeout **5 s**. `innodb_lock_wait_timeout` dhe `lock_wait_timeout` default **8 s**, të konfigurueshme me `QTA_DB_LOCK_WAIT_SECONDS`, kufizuar në 1–15 s. Këto kufizojnë pritjen për locks; nuk janë deadline universal për çdo SELECT.
- `QtaPDO` regjistron kohën e begin/commit/rollback edhe për kodin legacy. `qta_tx` nis transaksionin brenda try dhe ruan rollback mbi Throwable; transaksionet e jashtme nuk bëhen nested transactions.
- `groups.php create_group/edit_members`: transaksioni mbulon krijimin e amzave që mungojnë, të gjitha validimet dhe shkrimet e anëtarësisë/ndarjes. Edit lexon grupin me FOR UPDATE për të serializuar shkrimet mbi të njëjtin grup. Maksimumi 10 dhe ndarja automatike ruhen.
- `student_card_inline add_amze_for_person`: lock i personit, krijimi i user-it dhe studentit në një transaksion; rollback në handler-in e jashtëm.
- Merge students nuk vazhdon më pas DB errors të gëlltitura. Tabelat e instalimit të plotë kërkohen; schema e paplotë shkakton dështim të sigurt me rollback. Semantika ekzistuese INSERT IGNORE nuk u rishkrua në këtë fazë.
- Agjencia ruan transaksionin për sinkronizimin agencies/users dhe handler-i i jashtëm jep error të standardizuar. Agency assignment ruan batch lookup ekzistues dhe tani ka parser të kufizuar/rollback.
- Domain messages dhe trigger SQLSTATE 45000 ruhen. DB busy klasifikohet 409; gabimet e papritura 500, lidhja DB 503. Redirect POST ruan redirect/flash ekzistues.

Observability: `X-QTA-Request-ID`, referencë e njëjtë në mesazh/log, dhe shutdown log për request >=2 s ose unexpected exception/fatal. Regjistrohen endpoint basename, action identifier, actor ID, elapsed_ms, transaction_ms, status dhe exception class. Exception log përmban code/driver/source location. Nuk përmban SQL, bound values, password, CSRF apo payload. Handler global mbulon edhe exceptions para try/catch-it lokal, bën rollback kur PDO global është aktiv dhe jep 409/500 me referencë; testi i exception-it të pakapur provon që payload nuk shfaqet as në body, as në log. Read-only fallbacks ruajnë rrjedhën ekzistuese, por logojnë dështimin.

## PERFORMANCE FIXES

`qta_amze_ensure_batch` bën një SELECT për të gjitha amzat, krijon vetëm ato që mungojnë dhe lexon role/gender vetëm një herë për batch. Përdoret te grupet legacy dhe scheduled. `ORDER BY id` zgjedh një regjistrim në mënyrë deterministe kur vlera tekstuale kanë të njëjtën përfaqësim numerik. Testi me zero në fillim provon që nuk krijohet regjistrim tjetër.

Parser-i server përdor `QTA_AMZE_MAX_PER_REQUEST=200`, kufi 8 KB dhe formatin numerik ekzistues të helper-it të ri. Intervali `1-999999999` refuzohet para loop-it. Preview client kufizohet para loop-it dhe e lë serverin të japë mesazhin autoritativ.

Benchmark lokal MariaDB/InnoDB, tabela TEMPORARY, 5 mostra për medianë; query batch ndjek projection dhe ORDER BY të implementimit real. Nuk u ndryshua skema e aplikacionit.

| Kursantë | Amza të kërkuara | Legacy N SELECT, ms | Batch 1 SELECT, ms | Kandidat indexed numeric, ms |
|---:|---:|---:|---:|---:|
| 100 | 100 | 10.40 | 0.71 | 1.10 |
| 1,000 | 200 | 127.05 | 2.56 | 2.53 |
| 10,000 | 200 | 1684.33 | 19.45 | 3.28 |

EXPLAIN batch: type=index, key=PRIMARY, rows afërsisht 100/1080/10495, Using where. Pra **mbetet një skanim i plotë**; N skanimet janë hequr. Kandidati generated/indexed numeric përdor range/ix_numeric, rreth 200 rows në 10,000, me filesort për ORDER BY id. Në volume të vogla optimizuesi zgjedh PRIMARY scan edhe për kandidatin. Matjet janë lokale, jo SLO prodhimi. [JSON i matjeve dhe planeve](amze-benchmark.json).

Migrimi i mundshëm i ardhshëm duhet të auditojë zero në fillim, vlera jonumerike, përplasje numerike, versionin MySQL/MariaDB dhe koston e backfill/index. Mos zëvendëso CAST me krahasim tekstual pa këtë analizë.

## TESTS RUN

Mjedisi: Windows/XAMPP, PHP 8.2.12, MariaDB/InnoDB, Node dhe Chromium Playwright. U përdorën vetëm databaza të krijuara posaçërisht `qta_reliability_test_*`, me skemë të migruar dhe të dhëna sintetike. Nuk u kopjuan persona, llogari apo credentials prodhimi.

Përgatitja nga skedarët e repository-t u provua gjithashtu: instalim bosh, katër migrimet ekzistuese, pastaj seed i shfletuesit → një grup legacy, një scheduled dhe shtatë kursantë sintetikë. Baza SQL rishkruhet vetëm në një kopje të përkohshme, që `CREATE DATABASE qta_db` të mos prekë databazën e punës.

```powershell
$qtaTestDb = 'qta_reliability_test_' + [guid]::NewGuid().ToString('N').Substring(0,8)
$qtaBaseSql = ($env:TEMP + '/qta-reliability-base.sql').Replace('\','/')
(Get-Content -Raw db/tables.sql).Replace('qta_db',$qtaTestDb) | Set-Content -Encoding utf8 $qtaBaseSql
& C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE $qtaTestDb CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
& C:\xampp\mysql\bin\mysql.exe -uroot $qtaTestDb -e "source $qtaBaseSql"
# Ndal ne cdo exit code jo-zero gjate importit.
foreach ($qtaSql in @('db/create_audit.sql',
  'db/migrations/2026-09-26-kurset-modulet-temat-orari.sql',
  'db/migrations/2026-09-28-konvertimi-i-grupeve.sql',
  'db/migrations/2026-09-28-piket-sipas-moduleve.sql',
  'db/migrations/2026-10-07-orari-me-periudhe-te-percaktuar.sql')) {
  & C:\xampp\mysql\bin\mysql.exe -uroot $qtaTestDb -e "source $qtaSql"
  if ($LASTEXITCODE -ne 0) { throw "Import failed: $qtaSql" }
}
$env:QTA_TEST_DB = '1'
$env:QTA_DB_NAME = $qtaTestDb
```

```powershell
& C:\xampp\php\php.exe tests/run.php
node --test tests/js/request_test.cjs
$env:QTA_TEST_DB = '1'
$env:QTA_DB_NAME = '<databaza e fresket e testimit>'
& C:\xampp\php\php.exe tests/fixtures/seed_reliability.php
& C:\xampp\php\php.exe tests/run.php --integration
& C:\xampp\php\php.exe tests/run.php --integration --filter=request_
& C:\xampp\php\php.exe tests/performance/amze_benchmark.php
```

Rezultate: **795 unit checks**, **1671 kontrolle në paketën e plotë PHP**, **45 kontrolle të fokusuara request/session**, **7 teste JavaScript**, të gjitha pa dështime. Paketat mbivendosen; këta numra nuk duhen mbledhur si teste të pavarura. [Output i plotë](full-test-results.txt), [output i fokusuar](request-test-results.txt).

Mbulohen: role/CSRF/edit-lock, konfirmimet 409, legacy/scheduled/converted groups, kurset/modulet/temat, kalendari, pikët/provimet, audit, migrimet, raporti QKL; plus concurrency real HTTP, heqja 7982, rollback pas auto-krijimit, rollback pas DB exception, lock wait i shkurtër, person/student atomicity dhe zero numerike në fillim. SQL failure injektohet vetëm me trigger të përkohshëm në DB testimi.

Testet legacy të integrimit lënë fixtures me amza fikse; paketa e plotë kërkon databazë të freskët për çdo run. Një përsëritje mbi fixtures e vjetra dha konflikte të pritshme për anëtarësi. Një run i hershëm zbuloi edhe flash login të konsumuar gjatë renderimit; ky regresion u korrigjua dhe testet login/full kaluan. Testet e reja request pastrojnë fixtures e tyre pas ekzekutimit.

Për shfletuesin përdoret **një DB tjetër e freskët**, me të njëjtën skemë/lookup seeds, dhe `seed_reliability.php --browser`. Mos ekzekuto paketën e plotë PHP paralelisht mbi të njëjtat fixtures.

```powershell
# Shell i serverit: QTA_TEST_DB=1 dhe QTA_DB_NAME te DB e shfletuesit.
& C:\xampp\php\php.exe tests/fixtures/seed_reliability.php --browser
& C:\xampp\php\php.exe -S 127.0.0.1:8765 -t . tests/fixtures/browser_router.php
# Shell tjeter; serveri eshte vetem lokal/testimi.
npx --yes --package @playwright/cli playwright-cli -s=qta-reliability-final open http://127.0.0.1:8765/index.php
npx --yes --package @playwright/cli playwright-cli -s=qta-reliability-final run-code --filename tests/browser/reliability_check.js
npx --yes --package @playwright/cli playwright-cli -s=qta-reliability-final run-code --filename tests/browser/error_lifecycle_check.js
npx --yes --package @playwright/cli playwright-cli -s=qta-reliability-final run-code --filename tests/browser/roles_check.js
```

Shfletues: një POST për double click, njoftim suksesi dhe modal i rikthyer pa busy; 10 screenshots light/dark në 320/375/768/1024/1440 px pa overflow të faqes; fokus me outline solid dhe reduced motion. Inline UI u provua me 401/403/409/500/invalid JSON/network/timeout: vlera e vjetër rikthehet dhe busy pastrohet. Konfirmimi server 409 u anulua me Escape pa mutation dhe pa spinner të mbetur. Dy thirrje inline paralel prodhuan vetëm një request; thirrja e dytë nuk pastroi busy të së parës. [Rezultatet e roleve/gabimeve](browser-results.json).

U hapën dashboard-et e katër roleve, students, groups, register, calendar, logs, profile, faqe publike dhe login. Kontrollet e roleve kanë 21 navigime përveç login/dashboard, pa page errors ose resource failures. 200% text zoom në 375 px dhe Tab focus u provuan për të pesë state-t. HTTP 400/401/403/409/500 të injektuara qëllimisht shfaqen si resource errors në console; nuk janë failed assets. Screenshots lokale janë në `output/playwright/` (gitignored); u inspektuan vizualisht mobile light, desktop dark dhe zoom i profilit.

PHP lint kaloi për 153 skedarë app/tests; `node --check` kaloi për 19 assets/test scripts; `git diff --check` kaloi. UI QA u zbatua për ndërhyrjen e reliability; ky raport nuk deklaron përfundimin e redesign-it të plotë.

## MANUAL TEST CHECKLIST

- [ ] Në staging me skemën/migrimet reale: administrator dhe editor hyjnë, hapin/mbyllin edit mode, dhe agjencia/kursanti/anonimi marrin ndalim për URL/API të paautorizuara.
- [ ] Me dy tabs të së njëjtës llogari: live search/calendar aktiv + ndryshim anëtarësh. Kontrollo kohën, suksesin dhe request ID në Network/server log.
- [ ] Grup 7977–7982 → 7977–7981; hiqet vetëm 7982, audit i saktë, asnjë person/user/student shtesë. Përdor fixtures të dedikuara, jo këta numra mbi regjistrime reale.
- [ ] Shtim/heqje kursanti, ndarje mbi 10, kurs i dyfishuar, amzë e zënë, grup i mbyllur: konfirmo/anulo; gabimi nuk lë shkrime të pjesshme.
- [ ] Inline në kursantë/agjenci/kurse, curriculum, pikët dhe provimet, grupet scheduled dhe conversion: provo sukses, gabim validimi dhe dy klikime të shpejta.
- [ ] Offline/throttling, session e skaduar, edit lock OFF: controls dalin nga loading; vlerat nuk pretendojnë save të sigurt pas timeout-it; kontrollo gjendjen para riprovimit.
- [ ] 409: anulo pa save, pastaj konfirmo një herë dhe verifiko vetëm mutation-in e autorizuar.
- [ ] Provo PDF/Word/Excel exports me të dhëna përfaqësuese, QR/verifikim, logs, profile/password dhe kërkimet e roleve. Rendering i të gjitha formateve të eksportit dhe kamera QR fizike kërkojnë verifikim në mjedisin final.
- [ ] Keyboard-only për login, sidebar, kërkim, dialogs, save/confirm/cancel; Escape dhe rikthimi i fokusit. Light/system/dark, reduced motion, 200% zoom, 320/375/768/1024/1440+ px.
- [ ] Provo DB lock contention vetëm në staging; verifiko rollback, mesazhin e kuptueshëm dhe log me ID të njëjtë. Monitoro SLOW REQUEST pas publikimit për query/endpoint realisht të ngadaltë.

## REMAINING RISKS

- Abort/timeout në shfletues nuk garanton ndalim në PHP/MySQL; native POST watchdog nuk anulon navigimin. Nuk ka retry mutations dhe nuk është shtuar idempotency key server-side. Guard parandalon duplicate nga UI e njëjtë, jo POST manual të dyfishtë ose klientë të tjerë.
- Connection/lock deadlines nuk ndalojnë çdo query të gjatë ose totalin e shumë lock waits. Timeout universal server-side duhet përshtatur me exports dhe konfigurimin Apache/FPM/proxy; nuk u rritën PHP timeouts për të fshehur problemin.
- Një CAST scan mbetet. EXPLAIN dhe benchmark duhet përsëritur me distribucionin/statistikat/versionin e DB-së reale para një migrimi numeric-index.
- Authorization përdor snapshot të request-it dhe rol nga DB; logout nuk anulon prapa në kohë një mutation tashmë të autorizuar. Requests e nisura me kodin e vjetër mund të mbajnë lock deri në përfundim gjatë rollout-it.
- Session helper u provua me PHP file sessions në Windows. Redis/custom session handler dhe reverse proxy/FPM i prodhimit duhen verifikuar në staging.
- Instalimi lokal kryesor nuk kishte të gjitha migrimet e domain-it; ato u aplikuan vetëm në DB-të e testimit. Vendosja e kodit kërkon skemën ekzistuese të migruar sipas `db/migrations/README.md`. Kjo punë nuk shton migrim të ri.
- Nuk u vlerësuan query plans, shpejtësia e storage, workers, packet loss apo logs nga prodhimi. Screenshots/role smoke tests nuk zëvendësojnë një audit të plotë accessibility ose çdo variant eksporti.

## FILES CHANGED

Tabela vijon më poshtë; çdo rresht tregon ndryshimin konkret dhe arsyen. Skedarët e kompatibilitetit pa ndryshim funksional nuk janë fshirë.

| Skedari | Cfare ndryshoi | Pse |
|---|---|---|
| `app/actions/agencies_inline_update.php` | Session/actor, rollback i brendshem, private status/error jashte. | Sinkronizimi agency/user deshton ne menyre atomike dhe te diagnostikueshme. |
| `app/actions/agencies_students_update.php` | Session/actor, parser i perbashket i kufizuar, action/error/rollback. | Pengon intervale masive dhe shkrime lidhjesh te pjesshme. |
| `app/actions/calendar_data.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/actions/course_structure_update.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/actions/courses_inline_update.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/actions/create_admin.php` | CLI jep audit actor null dhe error te kontrolluar. | Pershtatet me firmën eksplicite te audit-it, pa sesion artificial. |
| `app/actions/editors_inline.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/actions/group_conversion_update.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/actions/group_results.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/actions/groups_inline_update.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/actions/lesson_group_update.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/actions/login_handler.php` | Liron sesion para lookup; rigjeneron ne sukses; short flash dhe private referenced errors. | Login nuk bllokon kerkesa te tjera gjate DB work dhe ruan session security. |
| `app/actions/search_advanced.php` | Liron sesion dhe logon exception pa payload me reference. | Kerkimi live nuk bllokon mutations dhe nuk logon tekst SQL/personal. |
| `app/actions/student_assignment.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/actions/student_card_inline.php` | Session/actor/errors dhe transaksion per user+student me lock personi. | Deshtimi i AMZE insert nuk le user te pjesshem. |
| `app/actions/students_inline_update.php` | Session/actor/errors; merge exceptions propagohen te rollback. | Mutation nuk vazhdon pas deshtimit te nje shkrimi ndihmes. |
| `app/actions/user_inline.php` | Session bootstrap, actor eksplicit dhe standardizim action/error ku ka handler legacy. | AJAX/DB work pa session lock dhe pa ekspozim exception payload. |
| `app/assets/js/app.js` | Live/palette transport + finally, cancellation dhe confirm/native submit guard. | Rezultatet e vjetra dhe nested listeners nuk lene busy ose submit te dyfishte. |
| `app/assets/js/calendar.js` | Transport i kufizuar, finally per liste/detaje dhe error mesazh. | Kalendari del nga busy pas gabimit/timeout pa prekur kerkesen e re. |
| `app/assets/js/curriculum.js` | Transport i perbashket, busy guards dhe pastrim finally. | Rrjedha AJAX rikthen controls pas suksesit/gabimit/timeout. |
| `app/assets/js/group-conversion.js` | Transport i perbashket, busy guards dhe pastrim finally. | Rrjedha AJAX rikthen controls pas suksesit/gabimit/timeout. |
| `app/assets/js/group-results.js` | Transport, error me pasiguri te ruajtjes, finally i lidhur me grupin aktual. | Piket mbeten te redaktueshme pas failure dhe nuk reset-ohet grupi tjeter. |
| `app/assets/js/lesson-group.js` | Transport i perbashket, busy guards dhe pastrim finally. | Rrjedha AJAX rikthen controls pas suksesit/gabimit/timeout. |
| `app/assets/js/lesson-groups.js` | Transport preview, bounded AMZE preview dhe shared native submit me confirming guard. | Nuk bllokon shfletuesin nga input masiv; ruan ndarjen/konfirmimet. |
| `app/assets/js/login-ui.js` | Transport i kufizuar; finally ruan identitetin e pending request. | Rikthen submit pas error pa pastruar riprovimin e ri CSRF. |
| `app/assets/js/request.js` | Transport i kufizuar plus guard/watchdog i native POST. | Garanton perfundim client-side te AJAX dhe rikthim controls pa retries mutation. |
| `app/assets/js/students.js` | Transport/finally, catch PN/AMZE, guards dhe bulk stop ne transport error. | Rikthen qelizat/butonat dhe nuk vazhdon nje batch me rezultat te pasigurt. |
| `app/assets/js/verify.js` | Transport i kufizuar dhe aria-busy finally. | Verifikimi publik nuk mbetet ne loading pas deshtimit. |
| `app/exports/download_lista_emerore.php` | Session bootstrap i shkurter dhe audit actor eksplicit. | Export work nuk mban file-session lock; formati/permissions ruhen. |
| `app/exports/download_praktika_profesionale.php` | Session bootstrap i shkurter dhe audit actor eksplicit. | Export work nuk mban file-session lock; formati/permissions ruhen. |
| `app/exports/download_proces_verbal.php` | Session bootstrap i shkurter dhe audit actor eksplicit. | Export work nuk mban file-session lock; formati/permissions ruhen. |
| `app/exports/download_regjistri_mesimit.php` | Session bootstrap i shkurter dhe audit actor eksplicit. | Export work nuk mban file-session lock; formati/permissions ruhen. |
| `app/exports/download_rregullat_sigurimi_teknik.php` | Session bootstrap i shkurter dhe audit actor eksplicit. | Export work nuk mban file-session lock; formati/permissions ruhen. |
| `app/exports/groups_export.php` | Session bootstrap i shkurter dhe audit actor eksplicit. | Export work nuk mban file-session lock; formati/permissions ruhen. |
| `app/exports/register_export_agency.php` | Session bootstrap i shkurter dhe audit actor eksplicit. | Export work nuk mban file-session lock; formati/permissions ruhen. |
| `app/exports/students_export.php` | Session bootstrap i shkurter dhe audit actor eksplicit. | Export work nuk mban file-session lock; formati/permissions ruhen. |
| `app/pages/aboutus.php` | session bootstrap i shkurter, read fallback diagnostics. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/agencies.php` | session bootstrap i shkurter, actor audit eksplicit, edit-mode/flash me state te fresket, transport/finally inline. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/calendar.php` | session bootstrap i shkurter. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/contact.php` | session bootstrap i shkurter. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/course.php` | session bootstrap i shkurter, actor audit eksplicit, edit-mode/flash me state te fresket. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/courses.php` | Session/flash, transport, inline finally dhe finally per zhvendosjen e grupit. | Kodi legacy CRUD del nga loading edhe ne timeout. |
| `app/pages/dashboard_admin.php` | session bootstrap i shkurter, actor audit eksplicit. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/dashboard_agjencia.php` | session bootstrap i shkurter. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/dashboard_editor.php` | session bootstrap i shkurter, actor audit eksplicit. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/dashboard_student.php` | session bootstrap i shkurter, read fallback diagnostics. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/editors.php` | session bootstrap i shkurter, actor audit eksplicit, edit-mode/flash me state te fresket. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/group_conversion.php` | session bootstrap i shkurter, actor audit eksplicit, edit-mode/flash me state te fresket. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/group_conversions.php` | session bootstrap i shkurter, actor audit eksplicit, edit-mode/flash me state te fresket. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/groups.php` | Session/flash lifecycle, transaksion para auto-krijimit, lock grupi, batch, finally dhe bounded preview/submit guard. | Rregullon heqjen/shtimin/ndarjen pa orphan ose submit te dyfishte. |
| `app/pages/groups_agjencia.php` | session bootstrap i shkurter. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/groups_student.php` | session bootstrap i shkurter. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/index.php` | session bootstrap i shkurter, read fallback diagnostics. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/lesson_group.php` | session bootstrap i shkurter, actor audit eksplicit, edit-mode/flash me state te fresket. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/lesson_groups.php` | Session/flash/error reference dhe amze_max ne config client. | Krijimi normal POST ruan sjelljen dhe preview perdor limitin server. |
| `app/pages/logs.php` | session bootstrap i shkurter, actor audit eksplicit. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/logs_editor.php` | session bootstrap i shkurter, actor audit eksplicit. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/ndihme.php` | session bootstrap i shkurter. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/profile.php` | session bootstrap i shkurter, actor audit eksplicit, edit-mode/flash me state te fresket. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/register_agjencia.php` | session bootstrap i shkurter, edit-mode/flash me state te fresket. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/student_card.php` | Session/actor, read fallback diagnostics, transport dhe QR busy finally. | Kartela dhe QR nuk mbajne lock dhe controls rikthehen. |
| `app/pages/students.php` | Session/flash, action logging, JSON error status dhe read diagnostics. | CRUD/live list ruan autorizimin dhe jep gabime pa SQL. |
| `app/pages/users.php` | session bootstrap i shkurter, actor audit eksplicit, edit-mode/flash me state te fresket. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/pages/verify.php` | session bootstrap i shkurter. | Faqja/POST nuk mban session lock; ruan rolet, URLs dhe sjelljen ekzistuese. |
| `app/shared/app_scripts.php` | Ngarkon request.js para app.js/inline handlers. | Transport dhe native submit lifecycle jane gati per te gjitha faqet e panelit. |
| `app/shared/database.php` | QtaPDO timing, connection 5 s, lock waits 8 s, JSON 503. | Kufizon pritjen dhe standardizon gabimet e lidhjes. |
| `app/shared/domain.php` | beginTransaction brenda try ne qta_tx. | Siguron rrugen e trajtimit edhe kur begin deshton. |
| `app/shared/group_members.php` | Parser i kufizuar dhe qta_amze_ensure_batch atomik. | Heq N SELECT dhe krijimin ndihmes jashte transaksionit. |
| `app/shared/inc/app_navbar.php` | Bootstrap idempotent i sesionit. | Navbar nuk e rihap sesionin pas DB work. |
| `app/shared/inc/audit_bootstrap.php` | qta_audit_attach merr actor ID; heq session_start. | Audit helper nuk rimerr lock-un ne menyre te fshehte. |
| `app/shared/lesson_groups.php` | Krijim dhe editim anetaresh perdorin batch lookup. | Shmang skanimet per cdo amze pa ndryshuar domain rules. |
| `app/shared/partials/login_dialog.php` | Lexon flash nga context-i i pergatitur. | Renderimi nuk shkruan sesionin. |
| `app/shared/partials/staff_accounts.php` | Transport i perbashket dhe finally/aria-busy per edit inline. | Administrator/editor nuk mbeten ne cell-saving pas gabimit. |
| `app/shared/public_head.php` | Bootstrap dhe konsumim i flash login para HTML. | Shmang session_start pas headers dhe konsumon flash vetem nje here. |
| `app/shared/public_scripts.php` | Ngarkon request.js para scripts publike. | Login dhe verifikimi perdorin te njejtin deadline. |
| `app/shared/request.php` | Correlation ID, slow/error logging pa payload, klasifikim busy/status/messages. | Diagnostikon vonesat dhe mban private SQL/te dhenat personale. |
| `app/shared/session.php` | Lifecycle i ri boot/update/put/push/take me mbyllje eksplicite. | Heq session lock nga DB work dhe shmang mbishkrimin e state-it paralel. |
| `app/shared/staff_guard.php` | request_id ne JSON, action diagnostics, busy 409, private 500. | Ruan rolet/CSRF/edit lock dhe protokollin e konfirmimit. |
| `docs/reliability/amze-benchmark.json` | Medianat dhe EXPLAIN ne 100/1000/10000 records. | Mbështet optimizimin dhe kufizimin e mbetur te CAST. |
| `docs/reliability/browser-results.json` | Rezultate roles/error UI. | Ruan evidence te simulimeve pa te dhena reale. |
| `docs/reliability/full-test-results.txt` | Output i run-it te plote te fresket. | Ruan rezultatin 1671/0 dhe matjet concurrency. |
| `docs/reliability/request-inventory.md` | Inventar entrypoints, action/state/transaction dhe AJAX. | Tregon shtrirjen e auditimit ne te gjithe portalin. |
| `docs/reliability/request-reliability.md` | Raporti, provat, vendimet dhe checklist prodhimi. | E ben ndryshimin dhe kufizimet te rishikueshme. |
| `docs/reliability/request-test-results.txt` | Output i kontrollit request/session. | Ruan rezultatin 45/0 dhe baseline/fix timings. |
| `tests/browser/error_lifecycle_check.js` | Fault injection ne inline UI dhe 409 cancel me Escape. | Provon reset busy dhe ruajtjen e protokollit confirmation. |
| `tests/browser/reliability_check.js` | Login, massive preview guard, native double click, themes/widths/focus/screenshots. | Provon save/loading feedback ne Chromium real. |
| `tests/browser/roles_check.js` | Kontekste te izoluara per role/public dhe 200% text zoom. | Kontrollon pages/assets/console/focus pa credentials reale. |
| `tests/fixtures/browser_router.php` | Router lokal i mbrojtur nga CLI-server/QTA_TEST_DB. | Teston routes dhe read te ngadalte pa authentication bypass. |
| `tests/fixtures/http_worker.php` | Worker HTTP CLI paralel. | Lejon live read dhe mutation ne dy procese serveri. |
| `tests/fixtures/seed_reliability.php` | Seed sintetike vetem ne users-empty test DB; --browser fixtures. | Mundeson teste role/full/browser te riprodhueshme. |
| `tests/fixtures/session_worker.php` | Worker CLI me hold/release/flash/toggle/read/audit. | Izolon mekanizmin PHP file-session lock. |
| `tests/integration/request_reliability_test.php` | HTTP concurrency, AMZE remove, rollback/failure/lock tests dhe cleanup. | Provon sjelljen reale te serverit dhe mungesen e orphans. |
| `tests/js/request_test.cjs` | Mocks te transportit, headers/body deadlines, abort/status dhe submit guards. | Mbulon rrjedhat qe serveri normal nuk i riprodhon lehte. |
| `tests/performance/amze_benchmark.php` | Benchmark ne tabele TEMPORARY dhe dy EXPLAIN. | Mat N scans kundrejt batch/index pa migration runtime. |
| `tests/run.php` | Shton --filter per nenkete testesh. | Lejon perseritjen e testeve te reliability pa run te plote. |
| `tests/unit/request_session_test.php` | Procese paralele, fresh session writes, audit no-lock dhe input caps. | Provon root cause dhe state correctness pa DB. |
