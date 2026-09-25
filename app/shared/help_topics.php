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
      ['Ndiq javën', 'Grupet që nisin ose mbarojnë së shpejti shfaqen me datë.'],
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
      ['Trego kodin QR', 'Kodi yt QR i tregon inspektorit që certifikatat e tua janë të vërteta.'],
    ],
    'tips' => [
      'Nëse diçka nuk është e saktë (emri, data e lindjes), kontakto QTA-në.',
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
    'roles' => $staff,
    'intro' => 'Kartela bashkon gjithçka për një person: të dhënat, regjistrimet (AMZË), modulet, grupet, provimet dhe kodin QR të verifikimit.',
    'steps' => [
      ['Gjej personin', 'Kërko me emër, numër personal ose numër amze.'],
      ['Lexo kartelën', 'Në krye janë të dhënat personale; më poshtë çdo regjistrim me modulin dhe rezultatin.'],
      ['Printo ose shkarko QR-në', 'Kodi QR lidhet me verifikimin publik të certifikatës.'],
    ],
    'tips' => [],
  ],

  'students_without_groups' => [
    'title' => 'Kursantët pa grup',
    'roles' => $staff,
    'intro' => 'Kursantët që janë regjistruar por ende nuk janë caktuar në një grup. Këtu i cakton në grupe.',
    'steps' => [
      ['Zgjidh kursantët', 'Shëno kutitë pranë kursantëve që do të caktosh.'],
      ['Zgjidh grupin', 'Zgjidh një grup ekzistues të të njëjtit modul ose krijo një të ri.'],
      ['Konfirmo', 'Sistemi të tregon sa kursantë u caktuan. Një grup ka maksimumi 10 kursantë.'],
    ],
    'tips' => [
      'Nëse grupi mbushet, pjesa tjetër ndahet automatikisht në grupin e radhës.',
    ],
  ],

  /* ----------------------------------------------------- Grupet, regjistri */
  'groups' => [
    'title' => 'Grupet',
    'roles' => $staff,
    'intro' => 'Çdo grup ndjek një modul në data të caktuara. Këtu sheh grupet, anëtarët, provimet dhe shkarkon dokumentet e grupit.',
    'steps' => [
      ['Hap një grup', 'Kliko rreshtin e grupit për të parë kursantët brenda tij.'],
      ['Cakto datat', 'Me ndryshimet e hapura, vendos datën e fillimit, mbarimit dhe të provimit.'],
      ['Shëno rezultatet', 'Pikët vendosen vetëm pasi të jetë caktuar data e provimit. Kalon kush merr 50 ose më shumë.'],
      ['Mbyll grupin', 'Kur grupi përfundon, shëno "Mbyllur". Sistemi kërkon konfirmim.'],
      ['Shkarko dokumentet', 'Lista emërore, procesverbali, praktika profesionale dhe rregullat e sigurisë shkarkohen nga veprimet e grupit.'],
    ],
    'tips' => [
      'Një grup mban deri në 10 kursantë. Një kursant (AMZË) mund të jetë vetëm në një grup.',
    ],
  ],

  'register' => [
    'title' => 'Regjistri i plotë',
    'roles' => $staff,
    'intro' => 'Çdo rresht është një regjistrim: personi, moduli, datat e grupit, provimi dhe pikët. Është pamja më e plotë e regjistrit.',
    'steps' => [
      ['Filtro', 'Kërko me emër, numër personal ose nis nga një numër amze.'],
      ['Ndrysho', 'Me "Lejo ndryshimet" mund të ndryshosh datat e provimit dhe pikët direkt në tabelë.'],
      ['Shkarko', 'Përdor "Shkarko" për ta marrë listën në Excel, PDF ose Word.'],
    ],
    'tips' => [],
  ],

  'courses' => [
    'title' => 'Modulet',
    'roles' => $staff,
    'intro' => 'Modulet janë zanatet dhe trajnimet që ofron QTA, me kodin dhe numrin e orëve.',
    'steps' => [
      ['Shto një modul', 'Jep një kod të shkurtër (p.sh. SLD-04), emrin dhe orët.'],
      ['Ndrysho', 'Me ndryshimet e hapura mund të korrigjosh emrin ose orët.'],
    ],
    'tips' => [
      'Kodi i modulit shfaqet në certifikata dhe në dokumente — mbaje të qëndrueshëm.',
    ],
  ],

  /* --------------------------------------------------------- Administrimi */
  'agencies' => [
    'title' => 'Agjencitë',
    'roles' => $staff,
    'intro' => 'Agjencitë janë kompanitë që dërgojnë punonjësit e tyre për trajnim. Çdo agjenci hyn me NIPT-in e saj.',
    'steps' => [
      ['Shto një agjenci', 'Plotëso NIPT-in, emrin, adresën dhe telefonin, pastaj vendos një fjalëkalim.'],
      ['Lidh punonjësit', 'Kursantët lidhen me agjencinë nga lista e studentëve të saj.'],
    ],
    'tips' => [],
  ],

  'users' => [
    'title' => 'Administratorët',
    'roles' => ['administrator'],
    'intro' => 'Llogaritë me qasje të plotë në sistem. Shto vetëm persona të besuar.',
    'steps' => [
      ['Shto një administrator', 'Emri, email-i dhe një fjalëkalim i fortë (të paktën 8 shenja).'],
      ['Ndrysho ose hiq', 'Me ndryshimet e hapura mund të ndryshosh emrin/email-in ose të fshish llogarinë.'],
    ],
    'tips' => [
      'Mos e fshi llogarinë tënde — sistemi ka nevojë për të paktën një administrator.',
    ],
  ],

  'editors' => [
    'title' => 'Editorët',
    'roles' => ['administrator'],
    'intro' => 'Editorët regjistrojnë kursantë, caktojnë grupe dhe shënojnë provimet, por nuk menaxhojnë llogaritë.',
    'steps' => [
      ['Shto një editor', 'Emri, email-i dhe fjalëkalimi.'],
      ['Ndiq punën', 'Çdo ndryshim i editorit shfaqet te "Historiku i ndryshimeve".'],
    ],
    'tips' => [],
  ],

  'logs' => [
    'title' => 'Historiku i ndryshimeve',
    'roles' => $staff,
    'intro' => 'Çdo shtim, ndryshim ose fshirje në regjistër shënohet këtu: kush e bëri, kur dhe çfarë ndryshoi.',
    'steps' => [
      ['Filtro', 'Zgjidh periudhën, llojin e veprimit ose përdoruesin.'],
      ['Lexo ndryshimin', 'Vlera e vjetër shfaqet e hequr me vijë, vlera e re me të gjelbër.'],
    ],
    'tips' => [
      'Historiku nuk mund të ndryshohet — është dëshmia e punës së bërë.',
    ],
  ],

  'profile' => [
    'title' => 'Profili im',
    'roles' => ['administrator', 'editor', 'agjencia', 'student'],
    'intro' => 'Këtu shikon të dhënat e llogarisë dhe ndryshon fjalëkalimin.',
    'steps' => [
      ['Ndrysho fjalëkalimin', 'Shkruaj fjalëkalimin aktual, pastaj të riun dy herë.'],
    ],
    'tips' => [
      'Një fjalëkalim i mirë ka të paktën 8 shenja dhe nuk është data e lindjes.',
    ],
  ],

  /* ------------------------------------------------------------- Agjencia */
  'agency_students' => [
    'title' => 'Punonjësit tanë',
    'roles' => ['agjencia'],
    'intro' => 'Lista e punonjësve të agjencisë suaj që janë regjistruar në QTA, me modulet, datat dhe rezultatet.',
    'steps' => [
      ['Kërko', 'Shkruaj emrin ose numrin e amzës.'],
      ['Shkarko listën', 'Përdor "Shkarko" për ta marrë në Excel, PDF ose Word.'],
    ],
    'tips' => [
      'Për të regjistruar punonjës të rinj, kontaktoni QTA-në.',
    ],
  ],

  'agency_groups' => [
    'title' => 'Grupet',
    'roles' => ['agjencia'],
    'intro' => 'Grupet ku janë caktuar punonjësit tuaj, me datat e trajnimit dhe të provimit.',
    'steps' => [
      ['Hap një grup', 'Shiko cilët punonjës janë në grup dhe rezultatet e tyre.'],
    ],
    'tips' => [],
  ],

  /* ------------------------------------------------------------- Kursanti */
  'student_groups' => [
    'title' => 'Modulet e mia',
    'roles' => ['student'],
    'intro' => 'Të gjitha modulet ku je regjistruar, me datat, provimin dhe rezultatin.',
    'steps' => [
      ['Lexo statusin', '"Kaloi" do të thotë që certifikata është e vlefshme për atë modul.'],
    ],
    'tips' => [],
  ],

  /* -------------------------------------------------------- Verifikimi */
  'verify' => [
    'title' => 'Verifikimi i certifikatës',
    'roles' => ['public', 'administrator', 'editor', 'agjencia', 'student'],
    'intro' => 'Kushdo mund të kontrollojë nëse një certifikatë QTA është e vërtetë, pa llogari.',
    'steps' => [
      ['Skano kodin QR', 'Shtyp "Skano kodin QR" dhe drejtoje kamerën te kodi në certifikatë.'],
      ['Ose shkruaj kodin', 'Kodi ndodhet poshtë QR-së në certifikatë.'],
      ['Lexo përgjigjen', 'E gjelbër = certifikata është e vlefshme. E kuqe = kodi nuk u gjet.'],
      ['Krahaso emrin', 'Emri në ekran duhet të jetë i njëjtë me emrin në certifikatë.'],
    ],
    'tips' => [
      'Nëse emrat nuk përputhen, certifikata mund të jetë e falsifikuar. Njoftoni QTA-në.',
    ],
  ],
];
