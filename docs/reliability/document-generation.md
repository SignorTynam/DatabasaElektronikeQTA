# Gjenerimi i dokumenteve

Shkarkimet e kursantëve, agjencisë, raportit QKL, dokumenteve të grupit dhe historikut
përdorin `document_generation.php`. Kërkesa POST kontrollon sesionin, CSRF-në dhe një
listë të mbyllur eksportesh; eksporti ekzistues kontrollon përsëri rolin dhe të dhënat.
Emrat e fushave, filtrat, pyetjet, përmbajtja dhe auditimi i eksporteve ruhen.

Serveri kthen menjëherë identifikuesin e punës, përfundon përgjigjen HTTP dhe nis
gjenerimin në të njëjtën kërkesë PHP. FPM përdor `fastcgi_finish_request()`; Apache
përdor përgjigje me `Content-Length` dhe `flush()`. Nuk kërkohet cron, radhë pune ose
proces CLI. PHP përdor `set_time_limit(0)` dhe puna vazhdon pas shkëputjes së klientit.
Statusi dhe skedari ruhen jashtë webroot-it, të lidhur me përdoruesin dhe sesionin.
Vetëm punët e përfunduara më shumë se një ditë më parë pastrohen; gjenerimi nuk skadon.

Modali native bën faqen dhe çdo modal Bootstrap poshtë tij joaktive. Nuk ka mbyllje
me Escape ose klikim jashtë. Tab mbetet brenda; fokusi kthehet te butoni burim pas
marrjes së plotë të skedarit. Përqindjet shënojnë faza reale të serverit, jo kohë të
hamendësuar: kontrolli, krijimi, përpunimi PDF, ruajtja dhe përfundimi. Word-i i
regjistrit raporton edhe faqet. Sinjali i vjetër “ok” nuk e përfundon punën: statusi
`ready` vendoset vetëm në shutdown, pasi dalja të jetë shkruar dhe kontrolluar.

Gjeneruesi mban edhe një file lock gjatë gjithë punës, të marrë para publikimit të
identifikuesit. Nëse procesi PHP ndalet pa ekzekutuar shutdown, kontrolli i statusit
zbulon lirimin e lock-ut dhe shfaq gabimin me mundësi riprovimi brenda modalit.
Kjo nuk përdor afate kohore: një proces aktiv nuk skadon edhe kur metadata është e
vjetër. Kontrollet e sesionit kryhen para kontrollit të procesit.

Faqja pyet statusin pa afat; 502/503/504 ose humbja e rrjetit gjatë kontrollit të
statusit provohen përsëri, duke mbajtur modalin hapur dhe të njëjtën punë. Rifreskimi
i faqes në të njëjtën skedë rimerr punën nga sessionStorage. Gabimet përfundimtare
shfaqen në modal me “Provo përsëri”; modalit nuk i shtohet buton mbylljeje. Dështimi i
marrjes së një skedari të gatshëm riprovon marrjen pa krijuar dokument të dytë.

## PDF-ja e të gjithë kursantëve

Tabela HTML e vetme me 2.758 kursantë tejkaloi 128 MB memorie gjatë një prove lokale
me të dhëna sintetike. Shifra 70% shënonte hyrjen në `Dompdf::render()`, pa progres
të ndërmjetëm gjatë paraqitjes së asaj tabele.

`app/exports/inc/students_pdf.php` përdor canvas-in CPDF të Dompdf-it ekzistues:
paraqet një rresht në çdo hap, përsërit titujt në çdo faqe A3 horizontale dhe mbështjell
tekstin Unicode sipas gjerësisë së kolonës. Edhe një fushë më e gjatë se një faqe
vazhdon në faqen tjetër pa humbur tekst. Të 12 kolonat, filtrat, renditja dhe të gjithë
kursantët ruhen. Progresi 45–90% raporton numrin real të kursantëve të përpunuar çdo
25 rreshta; 95% shënon ruajtjen dhe 100% vetëm skedarin e përfunduar.

E njëjta provë me 2.758 rreshta përfundoi në rreth 3 sekonda, me 20 MB memorie dhe
75 faqe. Këto janë matje lokale të gjeneruesit; koha në host varet nga mjedisi dhe
nga të dhënat. Nuk u shtua varësi Composer dhe nuk u rrit kufiri i memories.

Për publikim duhen të katër skedarët PHP së bashku (helper-i i ri ngarkohet i pari):

- `app/exports/inc/students_pdf.php`
- `app/exports/students_export.php`
- `app/shared/document_generation.php`
- `app/actions/document_generation.php`

Pas publikimit, prova bëhet me një shkarkim të ri në një skedë të re. Një punë e
nisur para përditësimit ka ende gjeneruesin e vjetër; nuk migrohet gjatë përpunimit.
Zbulimi me file lock vlen për punët e nisura nga versioni i ri.

## Verifikimi (08.10.2026)

- Testet unit të kapjes: skedar i plotë, buffers të ndërthurur, sinjal i hershëm,
  gabim i trajtuar, fatal, mungesë memorieje, skedar i cunguar, proces aktiv pa skadencë
  dhe proces i ndërprerë pa shutdown; 25 kontrolle.
- PDF-ja e kursantëve: 2.758 rreshta me kufi 64 MB, listë bosh, tekst Unicode pa
  hapësira, fushë më e gjatë se një faqe, çdo identifikues saktësisht një herë sipas
  renditjes dhe tituj të përsëritur në çdo faqe; 28 kontrolle.
- Paketa e plotë unit: 848 kontrolle kaluan; syntax PHP kaloi për 164 skedarë dhe
  syntax JavaScript kaloi. U provuan edhe eksportet e drejtpërdrejta PDF/XLSX/DOCX/DOC.
- Testet HTTP: të gjitha formatet e nëntë eksporteve, përfshirë historikun e adminit
  dhe historikun personal të editorit; sesioni, CSRF, refuzimi i roleve, izolimi i
  statusit/skedarit dhe grupi pa orar. Me Apache u provua që përgjigjja e nisjes
  kthehet ndërsa puna vazhdon dhe progresi lexohet njëkohësisht. 159 kontrolle HTTP
  kaluan edhe me databazën sintetike me 2.758 kursantë; testet unit të fokusuara kanë
  edhe 25 kontrolle.
- Shfletuesi MCP: shkarkim real PDF nga lista dhe nga modali i grupit; 10/45/70% të
  lexuara nga serveri, Escape, Tab, bllokimi i faqes, kthimi i fokusit, gjendja e
  gabimit, tema e çelët/e errët dhe gjerësi 320/375/768/1024/1440. Pamjet u kontrolluan
  me screenshots gjatë sesionit. Nuk pati gabime console në serverin Apache të izoluar.
- Shfletuesi MCP shkarkoi edhe PDF-në me 2.758 kursantë nga lista e plotë. Skedari i
  marrë kishte 75 faqe, të gjithë kursantët sipas renditjes dhe shkronjat shqipe;
  modali u mbyll pasi u mor skedari. Nuk pati gabime në console.

Komandat e testeve:

```powershell
php tests/run.php
$env:QTA_TEST_DB = '1'
$env:QTA_DB_NAME = '<databaza-sintetike-e-migruar-me-seed-browser>'
$env:QTA_DOCUMENT_TEST_ZIP = '1' # vetëm nëse zip është i çaktivizuar në php.ini
php tests/run.php --integration --filter=document_generation
```

Për Apache të izoluar vendosen edhe `QTA_DOCUMENT_TEST_URL` (baza e URL-së) dhe
`QTA_DOCUMENT_TEST_SESSION_DIR` (session.save_path i atij serveri).

## Kufijtë e mjedisit

PHP-ja lokale e parazgjedhur e ka ZIP të çaktivizuar. DOCX u verifikua me ZIP të
aktivizuar vetëm në serverët e izoluar të testeve; konfigurimi global nuk u ndryshua.
Në serverin real duhen varësitë ekzistuese të eksporteve. Nuk riparohen nga një modal
ndërprerjet e databazës, mungesa e hapësirës apo ndalimi i procesit nga hosti.

Hosti duhet të lejojë kërkesa PHP pa afat të jashtëm gjenerimi (p.sh. kufijtë FPM
`request_terminate_timeout`/`request_terminate_timeout_track_finished` ose kufij të
platformës). Një server PHP me një worker mund të gjenerojë dokumentin, por kërkesat
e statusit presin worker-in; Apache/FPM me workers të lirë tregon fazat gjatë punës.
Browser-i kontrollon mbylljen e vet, navigimin e detyruar dhe shkrimin përfundimtar
në disk; aplikacioni bllokon ndërveprimin me faqen dhe merr të gjithë skedarin para
se të heqë modalin.
