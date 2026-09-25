<?php
declare(strict_types=1);

/**
 * help_topics.php — Përmbajtja e udhëzimeve. Kthen një varg temash.
 * Mbaje gjuhën të thjeshtë: çfarë bën faqja, hapat me radhë, këshilla.
 * Emrat e butonave duhet të jenë identikë me ata në ndërfaqe.
 */

$staff = ['administrator', 'editor'];

return [

  /* ------------------------------------------------------------ Fillimi */
  'start' => [
    'title' => 'Hapat e parë',
    'roles' => ['administrator', 'editor', 'agjencia', 'student'],
    'intro' => 'Regjistri QTA mban kualifikimet profesionale të punonjësve: kush u regjistrua, në cilin modul, në cilin grup, kur dha provimin dhe me çfarë rezultati.',
    'steps' => [
      ['Hyr me rolin tënd', 'Stafi hyn me email, agjencitë me NIPT, kursantët me numrin personal.'],
      ['Nis nga "Kreu"', 'Aty sheh çfarë pret për ty sot dhe veprimet më të shpeshta.'],
      ['Përdor menunë majtas', 'Çdo seksion ka një emër të qartë. Në telefon menuja hapet me butonin ☰ lart majtas.'],
      ['Kërko kudo', 'Shtyp "Kërko…" ose Ctrl + K për të gjetur një kursant, grup ose modul.'],
    ],
    'tips' => [
      'Në çdo faqe ka një buton "Si funksionon?" me udhëzime të shkurtra për atë faqe.',
      'Pamjen (e çelët, e errët ose sipas pajisjes) e zgjedh te "Profili im".',
    ],
  ],

  'editing' => [
    'title' => 'Si ndryshohen të dhënat',
    'roles' => $staff,
    'intro' => 'Të dhënat janë të mbrojtura nga ndryshimet pa dashje. I hap kur do të punosh dhe i mbyll kur mbaron.',
    'steps' => [
      ['Shtyp "Lejo ndryshimet"', 'Butoni është lart djathtas në faqet e punës. Një vijë e verdhë në krye tregon që ndryshimet janë të hapura.'],
      ['Kliko vlerën', 'Vlerat që ndryshohen kanë një vijë me pika poshtë. Kliko, shkruaj dhe shtyp Enter.'],
      ['Shiko ngjyrën', 'Qeliza bëhet e gjelbër kur ruhet. E kuqja do të thotë që nuk u ruajt — lexo mesazhin poshtë djathtas.'],
      ['Esc kthen vlerën', 'Nëse gabon para se të shtypësh Enter, shtyp Esc dhe vlera e vjetër kthehet.'],
      ['Shtyp "Mbyll ndryshimet"', 'Kur mbaron, mbyll ndryshimet që të mos ndryshosh gjë pa dashje.'],
    ],
    'tips' => [
      'Datat shkruhen si dd.mm.vvvv, p.sh. 05.03.2026. Pranohen edhe viza ose pjerrëta.',
      'Çdo ndryshim ruhet te "Historiku i ndryshimeve", me vlerën para dhe pas.',
    ],
  ],

  /* ------------------------------------------------------------ Paneli */
  'dashboard_staff' => [
    'title' => 'Kreu',
    'roles' => $staff,
    'intro' => 'Kreu tregon punën që pret, veprimet e shpeshta dhe grupet e kësaj jave.',
    'steps' => [
      ['Shiko "Çfarë pret për ty"', 'Çdo rresht tregon sa raste kërkojnë vëmendje. Kliko rreshtin për t\'i zgjidhur.'],
      ['Nis një punë', 'Butonat e mëdhenj të çojnë drejt e te regjistrimi, grupet ose kërkimi.'],
      ['Ndiq javën', 'Grupet që nisin ose mbarojnë së shpejti dhe provimet shfaqen me datë.'],
    ],
    'tips' => [
      'Kur lista "Çfarë pret për ty" është bosh, çdo gjë është në rregull.',
    ],
  ],

  'dashboard_agency' => [
    'title' => 'Kreu i agjencisë',
    'roles' => ['agjencia'],
    'intro' => 'Këtu sheh ku janë punonjësit tuaj: kush pret të caktohet në grup, cilat grupe janë në vazhdim dhe provimet e ardhshme.',
    'steps' => [
      ['Gjej një punonjës', 'Shkruaj emrin, numrin personal ose numrin e amzës te fusha e kërkimit.'],
      ['Shiko kush pret', 'Punonjësit pa grup do të caktohen nga stafi i QTA-së në grupin e radhës.'],
      ['Ndiq grupet', 'Tabela tregon grupet ku keni punonjës, me datat e fillimit dhe mbarimit.'],
    ],
    'tips' => [
      'Për të verifikuar një certifikatë nuk duhet të hyni në sistem — përdorni "Verifiko certifikatë".',
    ],
  ],

  'dashboard_student' => [
    'title' => 'Faqja ime',
    'roles' => ['student'],
    'intro' => 'Këtu sheh modulet ku je regjistruar, datat, provimet dhe rezultatet e tua.',
    'steps' => [
      ['Shiko provimin e radhës', 'Nëse ke një provim të caktuar, data shfaqet në krye.'],
      ['Shiko modulet', 'Çdo modul tregon nëse është në vazhdim, nëse pret provimin ose nëse ke kaluar.'],
      ['Trego kodin QR', 'Kodi yt QR i tregon inspektorit që certifikatat e tua janë të vërteta. Shtyp "Shfaq më të madh" për ta treguar nga telefoni.'],
    ],
    'tips' => [
      'Nëse diçka nuk është e saktë (emri, data e lindjes), kontakto QTA-në.',
      'Ndrysho fjalëkalimin që të dha QTA te "Profili im".',
    ],
  ],

  /* --------------------------------------------------------- Kursantët */
  'students' => [
    'title' => 'Kursantët',
    'roles' => $staff,
    'intro' => 'Lista e të gjithë kursantëve të regjistruar. Këtu kërkon, shton dhe ndryshon të dhënat personale.',
    'steps' => [
      ['Kërko', 'Shkruaj emrin, numrin personal ose numrin e amzës dhe shtyp "Kërko".'],
      ['Shto një kursant', 'Shtyp "Shto kursant", plotëso fushat dhe ruaj. Numri i amzës duhet të jetë unik.'],
      ['Ndrysho të dhënat', 'Shtyp "Lejo ndryshimet". Pastaj kliko mbi vlerën në tabelë, shkruaj dhe shtyp Enter. Ndryshimi ruhet vetë.'],
      ['Mbyll ndryshimet', 'Kur mbaron, shtyp "Mbyll ndryshimet" që të mos ndryshosh gjë pa dashje.'],
    ],
    'tips' => [
      'Kutia ngjyrë jeshile pas ruajtjes do të thotë që ndryshimi u ruajt. E kuqja do të thotë që nuk u ruajt — kontrollo formatin.',
      'Datat shkruhen si dd.mm.vvvv, p.sh. 05.03.1990.',
    ],
  ],

  'student_card' => [
    'title' => 'Kartela e kursantit',
    'roles' => ['administrator', 'editor', 'agjencia'],
    'intro' => 'Kartela bashkon gjithçka për një person: të dhënat personale, çdo modul me datat dhe rezultatin, provimet e ardhshme dhe kodin QR të verifikimit.',
    'steps' => [
      ['Gjej personin', 'Kërko me emër, numër personal ose numër amze dhe shtyp "Hap kartelën".'],
      ['Lexo kartelën', 'Në krye janë shifrat; poshtë çdo modul me gjendjen ("Në mësim", "Kaloi · 64" …).'],
      ['Ndrysho të dhënat', 'Stafi, me "Lejo ndryshimet", klikon një vlerë te "Të dhënat personale", e ndryshon dhe del nga fusha. Esc e kthen vlerën.'],
      ['Kodi QR', 'Hap faqen e verifikimit, shkarko kodin si PNG për printim ose kopjo lidhjen. Nëse mungon, shtyp "Krijo kodin QR".'],
    ],
    'tips' => [
      'Agjencitë shohin vetëm kartelat e punonjësve të tyre dhe nuk mund t\'i ndryshojnë.',
    ],
  ],

  'students_without_groups' => [
    'title' => 'Kursantët pa grup',
    'roles' => $staff,
    'intro' => 'Kursantët që janë regjistruar por ende nuk janë caktuar në një grup. Këtu u zgjedh modulin dhe i cakton në grupe.',
    'steps' => [
      ['Zgjidh modulin', 'Për kursantët "Pa modul", zgjidh modulin te lista dhe shtyp "Ruaj".'],
      ['Cakto në grup', 'Zgjidh grupin — lista tregon datat dhe vendet e zëna, p.sh. 6/10 — dhe shtyp "Cakto".'],
      ['Disa njëherësh', 'Shëno kutitë majtas; poshtë shfaqet një shirit. Zgjidh grupin dhe shtyp "Cakto të zgjedhurit".'],
    ],
    'tips' => [
      'Një grup mban deri në 10 kursantë. Ata që nuk nxënë mbeten në listë — caktoji në një grup tjetër.',
      'Nëse një modul nuk ka grup, krijoje te "Grupet" me "Krijo grup".',
    ],
  ],

  /* ----------------------------------------------------- Grupet, regjistri */
  'groups' => [
    'title' => 'Grupet',
    'roles' => $staff,
    'intro' => 'Çdo grup ndjek një modul në data të caktuara. Këtu sheh grupet, kursantët, provimet dhe shkarkon dokumentet e grupit.',
    'steps' => [
      ['Hap një grup', 'Kliko emrin e modulit për të parë kursantët brenda grupit.'],
      ['Cakto datat', 'Me ndryshimet e hapura, kliko datën e fillimit, mbarimit ose të provimit dhe shkruaj dd.mm.vvvv.'],
      ['Shëno rezultatet', 'Kliko pikët dhe shkruaj 0–100. Kalon kush merr 50 ose më shumë.'],
      ['Ndrysho kursantët', '"Ndrysho" → "Kursantët e grupit": shkruaj numrat e amzës, p.sh. 3400-3403, 3409. Mbi 10, grupi ndahet vetë — të tregohet si para se të ruhet.'],
      ['Mbyll grupin', 'Kur mbarojnë provimet, ndiz çelësin "Mbyllur". Pas kësaj çdo ndryshim kërkon konfirmim.'],
      ['Shkarko dokumentet', 'Te "Dokumentet e grupit": Procesverbali, Lista emërore, Praktika profesionale dhe Rregullat e sigurisë, në PDF, Word ose Excel.'],
    ],
    'tips' => [
      'Një grup mban deri në 10 kursantë. Një regjistrim (nr. i amzës) mund të jetë vetëm në një grup.',
      '"Raporti për QKL" krijon raportin për një interval numrash amze.',
    ],
  ],

  'register' => [
    'title' => 'Regjistri i plotë',
    'roles' => $staff,
    'intro' => 'Çdo rresht është një regjistrim: personi, moduli, datat e grupit, provimi dhe pikët. Është pamja më e plotë e regjistrit.',
    'steps' => [
      ['Filtro', 'Kërko me emër, numër personal ose nis nga një numër amze.'],
      ['Ndrysho', 'Me "Lejo ndryshimet" mund të ndryshosh datat e provimit dhe pikët direkt në tabelë. Kolona "Gjendja" përditësohet vetë.'],
      ['Shkarko', 'Përdor "Shkarko" për ta marrë listën në Excel, PDF ose Word.'],
    ],
    'tips' => [],
  ],

  'courses' => [
    'title' => 'Modulet',
    'roles' => $staff,
    'intro' => 'Modulet janë zanatet dhe trajnimet që ofron QTA, me kodin dhe numrin e orëve.',
    'steps' => [
      ['Shto një modul', 'Shtyp "Shto modul": emri, një kod i shkurtër (p.sh. SLD-04) dhe orët e mësimit.'],
      ['Ndrysho', 'Me ndryshimet e hapura kliko kodin, emrin ose orët dhe shkruaj vlerën e re.'],
      ['Shiko grupet e modulit', 'Kliko "N grupe" për t\'i parë. "Kalo te një modul tjetër" korrigjon një grup të vendosur gabim.'],
    ],
    'tips' => [
      'Një modul që ka grupe nuk fshihet, që të mos humbasin datat e provimeve dhe pikët.',
      'Kodi i modulit shfaqet në certifikata dhe në dokumente — mbaje të qëndrueshëm.',
    ],
  ],

  /* --------------------------------------------------------- Administrimi */
  'agencies' => [
    'title' => 'Agjencitë',
    'roles' => $staff,
    'intro' => 'Agjencitë janë kompanitë që dërgojnë punonjësit e tyre për trajnim. Çdo agjenci hyn me NIPT-in e saj dhe sheh vetëm punonjësit e vet.',
    'steps' => [
      ['Shto një agjenci', 'Shtyp "Shto agjenci": emri, NIPT-i (10 shenja, p.sh. L42202012A) dhe një fjalëkalim me të paktën 8 shenja.'],
      ['Lidh punonjësit', 'Kliko "N punonjës" te agjencia, shkruaj numrat e amzës dhe shtyp "Shto".'],
      ['Ndrysho', 'Me ndryshimet e hapura kliko emrin, NIPT-in, telefonin ose adresën.'],
    ],
    'tips' => [
      'Kur fshihet një agjenci, punonjësit e saj mbeten në regjistër.',
    ],
  ],

  'users' => [
    'title' => 'Administratorët',
    'roles' => ['administrator'],
    'intro' => 'Llogaritë me qasje të plotë në sistem. Shto vetëm persona të besuar.',
    'steps' => [
      ['Shto një administrator', 'Shtyp "Shto administrator": emri, email-i dhe një fjalëkalim me të paktën 8 shenja.'],
      ['Fjalëkalim i ri', 'Kur dikush e harron fjalëkalimin, shtyp "Fjalëkalim i ri" te rreshti i tij dhe njoftoje.'],
      ['Ndrysho ose fshi', 'Me ndryshimet e hapura kliko emrin ose email-in; ikona e koshit fshin llogarinë.'],
    ],
    'tips' => [
      'Llogaria jote shënohet "Ti" dhe nuk mund ta fshish.',
    ],
  ],

  'editors' => [
    'title' => 'Editorët',
    'roles' => ['administrator'],
    'intro' => 'Editorët regjistrojnë kursantë, caktojnë grupe dhe shënojnë provimet, por nuk menaxhojnë llogaritë.',
    'steps' => [
      ['Shto një editor', 'Shtyp "Shto editor": emri, email-i dhe fjalëkalimi.'],
      ['Fjalëkalim i ri', 'Përdore kur një editor e ka harruar fjalëkalimin.'],
      ['Ndiq punën', 'Çdo ndryshim i editorit shfaqet te "Historiku i ndryshimeve".'],
    ],
    'tips' => [],
  ],

  'logs' => [
    'title' => 'Historiku i ndryshimeve',
    'roles' => $staff,
    'intro' => 'Çdo shtim, ndryshim ose fshirje në regjistër shënohet këtu: kush e bëri, kur dhe çfarë ndryshoi.',
    'steps' => [
      ['Filtro', 'Zgjidh "Çfarë ndodhi", "Ku", "Kush" ose një periudhë — ose kërko një emër.'],
      ['Lexo ndryshimin', 'Vlera e vjetër ka sfond të kuq, e reja të gjelbër: "Pikët 45 → 55".'],
      ['Shkarko', '"Shkarko listën (Excel)" merr të gjitha veprimet që përputhen me filtrat.'],
    ],
    'tips' => [
      'Historiku nuk mund të ndryshohet — është dëshmia e punës së bërë.',
      'Editorët shohin vetëm veprimet e tyre ("Historiku im").',
    ],
  ],

  'profile' => [
    'title' => 'Profili im',
    'roles' => ['administrator', 'editor', 'agjencia', 'student'],
    'intro' => 'Këtu shikon të dhënat e llogarisë, ndryshon fjalëkalimin dhe zgjedh pamjen e portalit.',
    'steps' => [
      ['Ndrysho fjalëkalimin', 'Shkruaj fjalëkalimin aktual, pastaj të riun dy herë dhe shtyp "Ndrysho fjalëkalimin".'],
      ['Zgjidh pamjen', 'E çelët, e errët ose sipas pajisjes.'],
    ],
    'tips' => [
      'Një fjalëkalim i mirë ka të paktën 8 shenja dhe nuk është emri ose data e lindjes.',
    ],
  ],

  /* ------------------------------------------------------------- Agjencia */
  'agency_students' => [
    'title' => 'Punonjësit tanë',
    'roles' => ['agjencia'],
    'intro' => 'Lista e punonjësve të agjencisë suaj që janë regjistruar në QTA, me modulin e fundit, provimin dhe rezultatin.',
    'steps' => [
      ['Kërko', 'Shkruaj emrin, numrin personal ose numrin e amzës.'],
      ['Hap kartelën', 'Kliko emrin për të parë të gjitha modulet e punonjësit.'],
      ['Shkarko listën', 'Përdor "Shkarko" për ta marrë në Excel, PDF ose Word.'],
    ],
    'tips' => [
      'Për të regjistruar punonjës të rinj, kontaktoni QTA-në.',
    ],
  ],

  'agency_groups' => [
    'title' => 'Grupet',
    'roles' => ['agjencia'],
    'intro' => 'Grupet ku janë caktuar punonjësit tuaj, me datat e trajnimit, provimet dhe rezultatet.',
    'steps' => [
      ['Hap një grup', 'Kliko emrin e modulit për të parë punonjësit dhe rezultatin e secilit.'],
      ['Shiko kush pret', 'Poshtë, "Presin një grup" tregon punonjësit që QTA do t\'i caktojë së shpejti.'],
    ],
    'tips' => [],
  ],

  /* ------------------------------------------------------------- Kursanti */
  'student_groups' => [
    'title' => 'Modulet e mia',
    'roles' => ['student'],
    'intro' => 'Të gjitha modulet ku je regjistruar, me datat, provimin dhe rezultatin.',
    'steps' => [
      ['Lexo gjendjen', '"Në mësim", "Pret rezultatin", "Kaloi · 64" ose "Nuk kaloi · 45".'],
      ['Moduli pa grup', '"Pret grupin" do të thotë që QTA do të të caktojë në grupin e radhës.'],
    ],
    'tips' => [
      '"Kaloi" do të thotë që moduli shfaqet si i vlefshëm kur dikush skanon kodin tënd QR.',
    ],
  ],

  /* -------------------------------------------------------- Verifikimi */
  'verify' => [
    'title' => 'Verifikimi i certifikatës',
    'roles' => ['public', 'administrator', 'editor', 'agjencia', 'student'],
    'intro' => 'Kushdo mund të kontrollojë nëse një certifikatë QTA është e vërtetë, pa llogari.',
    'steps' => [
      ['Skano kodin QR', 'Shtyp "Skano kodin QR" dhe drejtoje kamerën te kodi në certifikatë. Shfletuesi kërkon leje për kamerën vetëm atëherë.'],
      ['Ose shkruaj kodin', 'Kodi ndodhet poshtë QR-së në certifikatë.'],
      ['Lexo përgjigjen', 'E gjelbër = certifikata është e vlefshme. E kuqe = kodi nuk u gjet.'],
      ['Krahaso emrin', 'Emri në ekran duhet të jetë i njëjtë me emrin në certifikatë.'],
    ],
    'tips' => [
      'Nëse emrat nuk përputhen, certifikata mund të jetë e falsifikuar. Njoftoni QTA-në.',
    ],
  ],
];
