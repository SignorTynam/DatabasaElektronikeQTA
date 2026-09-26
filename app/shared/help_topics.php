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
    'intro' => 'Regjistri QTA mban kualifikimet profesionale të punonjësve: kush u regjistrua, në cilin kurs, në cilin grup, kur dha provimin dhe sa pikë mori.',
    'steps' => [
      ['Hyr me rolin tënd', 'Stafi hyn me email, agjencitë me NIPT, kursantët me numrin personal.'],
      ['Nis nga "Kreu"', 'Aty sheh çfarë pret për ty sot dhe veprimet më të shpeshta.'],
      ['Përdor menunë majtas', 'Çdo seksion ka një emër të qartë. Në telefon menuja hapet me butonin ☰ lart majtas.'],
      ['Kërko kudo', 'Shtyp "Kërko…" ose Ctrl + K për të gjetur një kursant, grup ose kurs.'],
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
    'intro' => 'Këtu sheh kurset ku je regjistruar, datat, provimet dhe pikët e tua.',
    'steps' => [
      ['Shiko provimin e radhës', 'Nëse ke një provim të caktuar, data shfaqet në krye.'],
      ['Shiko kurset', 'Çdo kurs tregon nëse është në vazhdim, kur është provimi dhe pikët që more.'],
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
    'intro' => 'Kartela bashkon gjithçka për një person: të dhënat personale, çdo kurs me datat dhe pikët, provimet e ardhshme dhe kodin QR të verifikimit.',
    'steps' => [
      ['Gjej personin', 'Kërko me emër, numër personal ose numër amze dhe shtyp "Hap kartelën".'],
      ['Lexo kartelën', 'Në krye janë shifrat; poshtë çdo kurs me gjendjen ("Në mësim", "Pret pikët", "64 pikë" …).'],
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
    'intro' => 'Kursantët që janë regjistruar por ende nuk janë caktuar në një grup. Këtu u zgjedh kursin dhe i cakton në grupe.',
    'steps' => [
      ['Zgjidh kursin', 'Për kursantët "Pa kurs", zgjidh kursin te lista dhe shtyp "Ruaj".'],
      ['Cakto në grup', 'Zgjidh grupin — lista tregon datat dhe vendet e zëna, p.sh. 6/10 — dhe shtyp "Cakto".'],
      ['Disa njëherësh', 'Shëno kutitë majtas; poshtë shfaqet një shirit. Zgjidh grupin dhe shtyp "Cakto të zgjedhurit".'],
    ],
    'tips' => [
      'Një grup mban deri në 10 kursantë. Ata që nuk nxënë mbeten në listë — caktoji në një grup tjetër.',
      'Nëse një kurs nuk ka grup, krijoje te "Grupet" me "Krijo grup". Grupet me orar shënohen "(me orar)" në listë.',
    ],
  ],

  /* ----------------------------------------------------- Grupet, regjistri */
  'groups' => [
    'title' => 'Grupet e mëparshme',
    'roles' => $staff,
    'intro' => 'Grupet e krijuara para orarit të mësimit. Mbeten siç ishin: çdo grup ndjek një kurs në data të caktuara, pa orar ditë pas dite. Këtu sheh grupet, kursantët, provimet dhe shkarkon dokumentet. Grupet e reja krijohen te "Grupet".',
    'steps' => [
      ['Hap një grup', 'Kliko emrin e kursit: grupi hapet në një dritare me kursantët, provimet, pikët dhe dokumentet.'],
      ['Cakto datat', 'Me ndryshimet e hapura, kliko datën e fillimit ose të mbarimit në tabelë, ose datën e provimit brenda grupit, dhe shkruaj dd.mm.vvvv.'],
      ['Shëno pikët', 'Kliko pikët dhe shkruaj një numër nga 0 deri në 100.'],
      ['Ndrysho kursantët', 'Brenda grupit shtyp "Ndrysho kursantët" dhe shkruaj numrat e amzës, p.sh. 3400-3403, 3409. Mbi 10, grupi ndahet vetë — të tregohet si para se të ruhet.'],
      ['Mbyll grupin', 'Kur provimet dhe pikët janë të plota, shtyp "Mbylle grupin" brenda grupit ose ndiz çelësin "Mbyllur" në tabelë. Pas kësaj çdo ndryshim kërkon konfirmim.'],
      ['Shkarko dokumentet', 'Brenda grupit, te "Dokumentet e grupit": shtyp formatin (PDF, Word ose Excel) te Procesverbali, Lista emërore, Praktika profesionale ose Rregullat e sigurisë.'],
    ],
    'tips' => [
      'Një grup mban deri në 10 kursantë. Një regjistrim (nr. i amzës) mund të jetë vetëm në një grup.',
      '"Raporti për QKL" krijon raportin për një interval numrash amze.',
      'Grupet e reja krijohen te "Grupet", me orar mësimi. "Shto grup të mëparshëm" është vetëm për grupe të mbajtura më parë.',
    ],
  ],

  'register' => [
    'title' => 'Regjistri i plotë',
    'roles' => $staff,
    'intro' => 'Çdo rresht është një regjistrim: personi, kursi, datat e grupit, provimi dhe pikët. Është pamja më e plotë e regjistrit.',
    'steps' => [
      ['Filtro', 'Kërko me emër, numër personal ose nis nga një numër amze.'],
      ['Ndrysho', 'Me "Lejo ndryshimet" mund të ndryshosh datat e provimit dhe pikët direkt në tabelë. Kolona "Gjendja" përditësohet vetë.'],
      ['Shkarko', 'Përdor "Shkarko" për ta marrë listën në Excel, PDF ose Word.'],
    ],
    'tips' => [
      'Te grupet me orar mësimi, datat e fillimit dhe të mbarimit i llogarit orari: ndryshohen te faqja e grupit, jo këtu.',
      'Regjistri i plotë është lista e regjistrimeve. Orari ditë pas dite i një grupi (temat e çdo dite) është te faqja e grupit, te "Grupet".',
    ],
  ],

  'courses' => [
    'title' => 'Kurset',
    'roles' => $staff,
    'intro' => 'Kurset janë zanatet dhe trajnimet që ofron QTA, me kodin dhe numrin e orëve. Çdo kurs ndahet në module (p.sh. Word, Excel) dhe çdo modul në tema.',
    'steps' => [
      ['Shto një kurs', 'Shtyp "Shto kurs": emri, një kod i shkurtër (p.sh. SLD-04) dhe orët e mësimit. Pastaj hapet kursi që t\'i shtosh modulet.'],
      ['Ndërto modulet dhe temat', 'Kliko emrin e kursit. Shto modulet me radhë dhe temat e secilit modul, me orët e tyre.'],
      ['Shiko gatishmërinë', 'Kolona "Modulet dhe temat" tregon "Gati" kur orët e moduleve mblidhen në orët e kursit dhe orët e temave në orët e çdo moduli. Vetëm një kurs "Gati" përdoret për grupe me orar.'],
      ['Shiko grupet e kursit', 'Kliko "N grupe": grupet hapen në një dritare. Kliko një grup për ta hapur.'],
    ],
    'tips' => [
      'Një kurs që ka grupe nuk fshihet, që të mos humbasin datat e provimeve dhe pikët.',
      'Kodi i kursit shfaqet në certifikata dhe në dokumente — mbaje të qëndrueshëm.',
    ],
  ],

  'course' => [
    'title' => 'Modulet dhe temat e kursit',
    'roles' => $staff,
    'intro' => 'Një kurs ndahet në module dhe çdo modul në tema, me radhë. Grupet me orar i zhvillojnë pikërisht në këtë radhë, ditë pas dite.',
    'steps' => [
      ['Shto modulet', 'Shtyp "Shto modul": emri, orët dhe vendi në radhë. P.sh. Microsoft Office 50 orë = Word 10 + Excel 10 + PowerPoint 10 + Access 10 + Outlook 10.'],
      ['Shto temat', 'Poshtë çdo moduli shkruaj emrin e temës dhe orët, pastaj shtyp "Shto temën" (ose Enter). Fusha mbetet gati për temën tjetër.'],
      ['Rendit', 'Butonat me shigjetë lëvizin një modul ose një temë një vend lart ose poshtë. Për një vend të largët, përdor "Ndrysho" dhe zgjidh "Vendi në radhë".'],
      ['Kontrollo orët', 'Lart shfaqet sa orë kanë modulet nga orët e kursit; te çdo modul, sa orë kanë temat dhe sa mbeten. Çdo problem thuhet me fjalë, disa me një buton rregullimi, p.sh. "Vendos orët e modulit në 10".'],
    ],
    'tips' => [
      'Orët janë orë mësimi të plota: 1, 2, 3 …',
      'Orët e temave nuk kalojnë kurrë orët e modulit, dhe orët e moduleve nuk kalojnë orët e kursit. Nëse një ndryshim do t\'i kalonte, del një dritare që të thotë sa orë lejohen dhe t\'i vendos me një klik.',
      'Grupet që ekzistojnë kanë kopjen e tyre të moduleve dhe temave: ndryshimet këtu vlejnë për grupet e reja.',
      'Një kurs që nuk është "Gati" mund të ruhet dhe të plotësohet më vonë, por nuk mund të përdoret për grup me orar.',
    ],
  ],

  'lesson_groups' => [
    'title' => 'Grupet',
    'roles' => $staff,
    'intro' => 'Çdo grup ndjek një kurs me orar mësimi ditë pas dite. Zgjedh kursin, datën e fillimit dhe orët në ditë; data e mbarimit llogaritet vetë.',
    'steps' => [
      ['Krijo grup', 'Shtyp "Krijo grup", zgjidh kursin (vetëm kurset "Gati"), datën e fillimit dhe orët e mësimit në ditë. Poshtë del menjëherë kur mbaron mësimi.'],
      ['Shto kursantët', 'Shkruaj numrat e amzës, p.sh. 3400-3403, 3409. Mbi 10 kursantë krijohen disa grupe të barabarta me të njëjtin orar.'],
      ['Hap një grup', 'Kliko emrin e kursit: hapet orari ditë pas dite, kursantët me provimet dhe pikët, dhe dokumentet.'],
    ],
    'tips' => [
      'Të dielat nuk kanë mësim, përveç kur shënohen si ditë mësimi te grupi.',
      'Grupet e krijuara para orarit të mësimit janë te "Grupet e mëparshme" dhe mbeten siç ishin.',
    ],
  ],

  'lesson_group' => [
    'title' => 'Orari i mësimit',
    'roles' => $staff,
    'intro' => 'Orari ndan orët e kursit nëpër ditë, në radhën e moduleve dhe të temave. Një temë mund të vazhdojë në ditën tjetër dhe një modul i ri mund të fillojë në mes të ditës.',
    'steps' => [
      ['Lexo orarin', 'Te "Ditë pas dite" çdo datë tregon temat dhe orët. "ora 1 nga 2" do të thotë që tema vazhdon në ditën tjetër të mësimit.'],
      ['Ndrysho një ditë', 'Me ndryshimet e hapura shtyp "Ndrysho ditën": orë të tjera, pa mësim (p.sh. festë), ose mësim të dielën. Orari rillogaritet vetë dhe data e mbarimit përditësohet.'],
      ['Ndrysho fillimin ose orët në ditë', 'Te "Orari në shkurt" shtyp "Ndrysho fillimin ose orët në ditë". Para ruajtjes të tregohet kur do të mbarojë mësimi.'],
      ['Provimet dhe pikët', 'Te "Kursantët dhe provimet" kliko datën e provimit ose pikët. Provimi nuk mund të jetë para mbarimit të mësimit.'],
      ['Mbyll grupin', 'Kur provimet dhe pikët janë të plota, shtyp "Mbylle grupin". Pas kësaj çdo ndryshim kërkon konfirmim.'],
    ],
    'tips' => [
      'Dita e fundit ka vetëm orët që mbeten — nuk mbushet deri në orarin e plotë.',
      'Një ndryshim që prek ditë që kanë kaluar kërkon konfirmim, sepse ato ditë janë zhvilluar tashmë.',
      'Grupi ka kopjen e vet të temave. Nëse kursi ndryshon para se të nisë grupi, mund të marrësh temat e reja me "Merr temat e reja".',
      '"Printo orarin" printon orarin ditë pas dite.',
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
    'intro' => 'Lista e punonjësve të agjencisë suaj që janë regjistruar në QTA, me kursin e fundit, provimin dhe pikët.',
    'steps' => [
      ['Kërko', 'Shkruaj emrin, numrin personal ose numrin e amzës.'],
      ['Hap kartelën', 'Kliko emrin për të parë të gjitha kurset e punonjësit.'],
      ['Shkarko listën', 'Përdor "Shkarko" për ta marrë në Excel, PDF ose Word.'],
    ],
    'tips' => [
      'Për të regjistruar punonjës të rinj, kontaktoni QTA-në.',
    ],
  ],

  'agency_groups' => [
    'title' => 'Grupet',
    'roles' => ['agjencia'],
    'intro' => 'Grupet ku janë caktuar punonjësit tuaj, me datat e trajnimit, provimet dhe pikët.',
    'steps' => [
      ['Hap një grup', 'Kliko emrin e kursit: grupi hapet në një dritare me punonjësit dhe pikët e secilit.'],
      ['Shiko kush pret', 'Poshtë, "Presin një grup" tregon punonjësit që QTA do t\'i caktojë së shpejti.'],
    ],
    'tips' => [],
  ],

  /* ------------------------------------------------------------- Kursanti */
  'student_groups' => [
    'title' => 'Kurset e mia',
    'roles' => ['student'],
    'intro' => 'Të gjitha kurset ku je regjistruar, me datat, provimin dhe rezultatin.',
    'steps' => [
      ['Lexo gjendjen', '"Në mësim", "Provimi pas 3 ditësh", "Pret pikët" ose pikët që more, p.sh. "64 pikë".'],
      ['Kursi pa grup', '"Pret grupin" do të thotë që QTA do të të caktojë në grupin e radhës.'],
    ],
    'tips' => [
      'Kushdo që skanon kodin tënd QR sheh kurset e tua në regjistër.',
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
