<?php
declare(strict_types=1);

/**
 * lesson_register.php — "Regjistri i orëve të mësimit" i një grupi me orar.
 *
 * Ndërton modelin e vetëm të dokumentit, të përbashkët për PDF-në dhe Word-in:
 * kush janë kursantët (numrat 1, 2, 3… sipas Listës emërore), cilat data dhe tema
 * shkojnë në çdo faqe, dhe si ndahen faqet. Vizatimi bëhet te
 * app/exports/inc/lesson_register_documents.php; këtu nuk ka HTML dhe as Word.
 *
 * Burimi i të dhënave
 *   - modulet dhe temat: kopja e ngrirë e grupit (group_schedule_topics), jo kursi i sotëm;
 *   - datat: orari i ruajtur i grupit (group_schedule_days / group_schedule_slots);
 *   - kursantët: në radhën e Listës emërore (CAST(nr_amze AS UNSIGNED), nr_amze).
 *
 * Faqet
 *   Çdo modul (në radhën module_seq) jep një ose më shumë çifte faqesh. Njësia është
 *   ora e mësimit: një datë ku moduli ka X orë del X herë në të dyja faqet.
 *     faqja tek  = regjistri i prezencës: një kolonë për çdo orë (dita e datës X herë);
 *     faqja çift = datat dhe temat: një rresht për çdo orë (data dhe tema X herë).
 *   Kolona k e faqes tek është rreshti k i faqes çift. Një modul me shumë orë ndahet në
 *   disa çifte ("Moduli 2 — vazhdim"): deri në 31 orë për çift (31 kolona), dhe rreshtat
 *   e temave duhet të zënë në një A4. Kështu faqet tek janë gjithmonë prezenca dhe faqet
 *   çift gjithmonë temat.
 *
 * Asgjë nuk shpiket: kutitë e prezencës dhe kolona "Shënime" mbeten bosh.
 */

require_once __DIR__ . '/lesson_groups.php';

/** Sa orë mban një çift faqesh: kolonat e ngushta të formularit (dhe rreshtat e temave). */
const QTA_LR_MAX_COLUMNS = 31;
/** Rreshtat e formularit të prezencës, si te modeli i printuar. */
const QTA_LR_FORM_ROWS = 35;

if (!function_exists('qta_lr_geometry')) {
  /**
   * Përmasat e faqeve në pikë (1 pt = 1/72 inç). Të njëjtat numra përdoren nga
   * PDF-ja dhe Word-i, prandaj faqet dalin njësoj në të dy formatet.
   * A4 vertikale, kufij 2,54 cm, si modeli i Word-it.
   */
  function qta_lr_geometry(): array
  {
    return [
      'page_w' => 595.28, 'page_h' => 841.89, 'margin' => 72.0,
      'content_w' => 451.0, 'content_h' => 697.89,
      'safety' => 8.0,                // hapësirë rezervë në fund të çdo faqeje
      /* Faqja tek: regjistri i prezencës */
      'att_first_col' => 34.0,        // kolona "Nr."
      'att_date_col' => 13.45,        // 31 kolona, një për orë: 34 + 31 × 13,45 = 450,95
      'att_title_h' => 17.3,          // "Regjistër për orët e mësimit"
      'att_label_h' => 17.3,          // "Muaji:"
      'att_months_h' => 12.5,         // numrat e muajve mbi datat
      'att_dates_h' => 18.0,          // rreshti "Dt."
      'att_row_h' => 17.0,            // rreshtat 1, 2, 3 …
      'att_body_h' => 595.0,          // 35 × 17
      'att_title_size' => 14.0, 'att_label_size' => 14.0, 'att_number_size' => 14.0,
      'att_small_size' => 10.0,       // "Nr.", "Dt.", ditët dhe muajt
      /* Faqja çift: datat dhe temat e modulit */
      'top_cols' => [70.0, 302.5, 78.5],   // Data | Tema | Shënime
      'top_head_h' => 15.0,
      'top_row_min' => 20.0,
      'top_text_size' => 11.0,
      'top_line_h' => 13.43,          // një rresht Calibri 11 pt
      'top_row_pad' => 6.0,
      'top_cell_pad' => 5.4,          // hapësira majtas/djathtas në qelizë
      'heading_size' => 11.0,
      'heading_line_ratio' => 1.15,   // Times New Roman
      'heading_after' => 8.0,
      'heading_max_lines' => 3,
      /* Rreshti i vogël poshtë tabelës: grupi, moduli, faqja */
      'caption_size' => 8.0,
      'caption_line' => 9.77,         // një rresht Calibri 8 pt
      'caption_gap' => 6.0,
    ];
  }

  /**
   * Lartësia që u mbetet rreshtave të tabelës së temave, pasi zbriten titulli i
   * modulit, koka e tabelës, rreshti poshtë dhe rezerva. Gjithmonë një numër i
   * plotë rreshtash bosh (20 pt), deri në 31 — si datat e një faqeje prezence.
   */
  function qta_lr_topics_budget(float $headingSize, int $headingLines, array $geo): float
  {
    $heading = $headingLines * $headingSize * $geo['heading_line_ratio'] + $geo['heading_after'];
    $free = $geo['content_h'] - $heading - $geo['top_head_h'] - $geo['caption_gap'] - $geo['caption_line'] - $geo['safety'];
    $rows = min(QTA_LR_MAX_COLUMNS, (int)floor($free / $geo['top_row_min']));
    return $rows * $geo['top_row_min'];
  }
}

/* ======================================================= Matja e tekstit */

if (!function_exists('qta_lr_text_width')) {
  /**
   * Gjerësia e një teksti në pikë, me gjerësitë e shkronjave të Calibri-t
   * (= Carlito) ose Times New Roman Bold. Përdoret vetëm për të llogaritur sa
   * rreshta zë një temë, që faqja të mos derdhet në një faqe tjetër.
   * Një shkronjë e panjohur llogaritet 1 em (më gjerë se çdo shkronjë latine).
   */
  function qta_lr_text_width(string $text, float $size, string $face = 'sans'): float
  {
    static $tables = null;
    if ($tables === null) {
      /* Njësi për 2048/em, shkronjat ASCII 32–126 */
      $sans = [463, 667, 821, 1020, 1038, 1464, 1397, 452, 621, 621, 1020, 1020, 511, 627, 517, 791,
        1038, 1038, 1038, 1038, 1038, 1038, 1038, 1038, 1038, 1038, 548, 548, 1020, 1020, 1020, 949,
        1831, 1185, 1114, 1092, 1260, 1000, 941, 1292, 1276, 516, 653, 1064, 861, 1751, 1322, 1356,
        1058, 1378, 1112, 941, 998, 1314, 1162, 1822, 1063, 998, 959, 628, 791, 628, 1020, 1020,
        596, 981, 1076, 866, 1076, 1019, 625, 964, 1076, 470, 490, 931, 470, 1636, 1076, 1080,
        1076, 1076, 714, 801, 686, 1076, 925, 1464, 887, 927, 809, 644, 943, 644, 1020];
      $serifBold = [512, 682, 1137, 1024, 1024, 2048, 1706, 569, 682, 682, 1024, 1167, 512, 682, 512, 569,
        1024, 1024, 1024, 1024, 1024, 1024, 1024, 1024, 1024, 1024, 682, 682, 1167, 1167, 1167, 1024,
        1905, 1479, 1366, 1479, 1479, 1366, 1251, 1593, 1593, 797, 1024, 1593, 1366, 1933, 1479, 1593,
        1251, 1593, 1479, 1139, 1366, 1479, 1479, 2048, 1479, 1479, 1366, 682, 569, 682, 1190, 1024,
        682, 1024, 1139, 909, 1139, 909, 682, 1024, 1139, 569, 682, 1139, 569, 1706, 1139, 1024,
        1139, 1139, 909, 797, 682, 1139, 1024, 1479, 1024, 1024, 909, 807, 451, 807, 1065];
      $tables = [
        'sans' => array_combine(range(32, 126), $sans) + [
          0xA0 => 463, 0xB7 => 517, 0xC7 => 1092, 0xCB => 1000, 0xE7 => 866, 0xEB => 1019,
          0x2013 => 1020, 0x2014 => 1854, 0x2018 => 511, 0x2019 => 511, 0x201C => 857, 0x201D => 857, 0x2026 => 1414,
        ],
        'serif-bold' => array_combine(range(32, 126), $serifBold) + [
          0xA0 => 512, 0xB7 => 683, 0xC7 => 1479, 0xCB => 1366, 0xE7 => 909, 0xEB => 909,
          0x2013 => 1024, 0x2014 => 2048, 0x2018 => 682, 0x2019 => 682, 0x201C => 1024, 0x201D => 1024, 0x2026 => 2048,
        ],
      ];
    }
    $table = $tables[$face] ?? $tables['sans'];
    $units = 0;
    foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
      $units += $table[mb_ord($ch, 'UTF-8')] ?? 2048;
    }
    return $units / 2048 * $size;
  }

  /**
   * Sa rreshta zë teksti në një gjerësi të dhënë (thyerje te hapësirat; një fjalë
   * më e gjatë se rreshti thyhet në shkronja, si në Word). Llogaritja është pak
   * më e gjerë se e vërteta (6%), që një temë të mos dalë kurrë më e gjatë se
   * vendi që i është lënë.
   */
  function qta_lr_text_lines(string $text, float $width, float $size, string $face = 'sans'): int
  {
    $text = trim($text);
    if ($text === '') {
      return 1;
    }
    $width = $width / 1.06;
    $space = qta_lr_text_width(' ', $size, $face);
    $lines = 1;
    $cur = 0.0;
    foreach (preg_split('/ +/u', $text) ?: [] as $word) {
      $w = qta_lr_text_width($word, $size, $face);
      if ($w > $width) {
        if ($cur > 0) {
          $lines++;
        }
        $cur = 0.0;
        foreach (mb_str_split($word, 1, 'UTF-8') as $ch) {
          $cw = qta_lr_text_width($ch, $size, $face);
          if ($cur > 0 && $cur + $cw > $width) {
            $lines++;
            $cur = 0.0;
          }
          $cur += $cw;
        }
        continue;
      }
      if ($cur > 0 && $cur + $space + $w > $width) {
        $lines++;
        $cur = $w;
      } else {
        $cur += ($cur > 0 ? $space : 0.0) + $w;
      }
    }
    return $lines;
  }

  /** Teksti i shkurtuar me "…" që të zërë në një rresht. */
  function qta_lr_fit_text(string $text, float $width, float $size, string $face = 'sans'): string
  {
    if (qta_lr_text_width($text, $size, $face) <= $width) {
      return $text;
    }
    $chars = mb_str_split($text, 1, 'UTF-8');
    while ($chars && qta_lr_text_width(rtrim(implode('', $chars)) . '…', $size, $face) > $width) {
      array_pop($chars);
    }
    return rtrim(implode('', $chars)) . '…';
  }

  /** Tekst i pastër për dokument: pa shenja kontrolli ose private dhe pa hapësira të tepërta. */
  function qta_lr_clean(string $s): string
  {
    $s = preg_replace('/[\x00-\x1F\x7F\x{E000}-\x{F8FF}]+/u', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
  }
}

/* ============================================================ Leximi */

if (!function_exists('qta_lesson_register_students')) {
  /**
   * Kursantët e grupit në radhën e Listës emërore (download_lista_emerore.php):
   * i pari këtu është Nr. 1 në regjistër, i dyti Nr. 2 …
   */
  function qta_lesson_register_students(PDO $pdo, int $groupId): array
  {
    $st = $pdo->prepare('
      SELECT s.id AS student_id, s.nr_amze, p.first_name, p.father_name, p.last_name
      FROM course_group_students cgs
      JOIN students s ON s.id = cgs.student_id
      LEFT JOIN persons p ON p.id = s.person_id
      WHERE cgs.group_id = ?
      ORDER BY CAST(s.nr_amze AS UNSIGNED) ASC, s.nr_amze ASC
    ');
    $st->execute([$groupId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Modeli i regjistrit për një grup me orar. Refuzon grupet që mungojnë dhe
   * grupet e mëparshme (QtaUserError nga qta_lg_require), si dhe një grup pa orar.
   * Të gjitha leximet bëhen në një transaksion, që të shihet një gjendje e vetme.
   */
  function qta_lesson_register_build(PDO $pdo, int $groupId, ?string $today = null): array
  {
    return qta_tx($pdo, static function () use ($pdo, $groupId, $today): array {
      $g = qta_lg_require($pdo, $groupId);
      $topics = qta_lg_topics($pdo, $groupId);
      $days = qta_lg_days($pdo, $groupId);
      if (!$topics || !$days) {
        throw new QtaUserError('Grupi #' . $groupId . ' nuk ka ende orar mësimi, prandaj regjistri nuk mund të krijohet.', ['code' => 'no_schedule']);
      }
      $students = qta_lesson_register_students($pdo, $groupId);
      return qta_lesson_register_model([
        'id' => (int)$g['id'],
        'course_name' => (string)$g['course_name'],
        'course_code' => (string)($g['course_code'] ?? ''),
        'start_date' => (string)$g['start_date'],
        'end_date' => (string)$g['end_date'],
      ], $topics, $days, $students, $today);
    });
  }
}

/* ================================================== Modeli (pa databazë) */

if (!function_exists('qta_lesson_register_model')) {
  /**
   * @param array $group     id, course_name, course_code, start_date, end_date
   * @param array $topics    kopja e temave (formati i qta_lg_topics)
   * @param array $days      orari i ruajtur (formati i qta_lg_days / qta_sched_build)
   * @param array $students  kursantët, tashmë në radhën e Listës emërore
   */
  function qta_lesson_register_model(array $group, array $topics, array $days, array $students, ?string $today = null): array
  {
    $geo = qta_lr_geometry();
    $gid = (int)($group['id'] ?? 0);
    $course = qta_lr_clean((string)($group['course_name'] ?? ''));

    /* Kursantët: numri rendor 1…N, pavarësisht nga numri i amzës. */
    $people = [];
    foreach (array_values($students) as $i => $s) {
      $people[] = [
        'sequence' => $i + 1,
        'student_id' => (int)($s['student_id'] ?? 0),
        'nr_amze' => (string)($s['nr_amze'] ?? ''),
        'full_name' => qta_lr_clean(trim((string)($s['first_name'] ?? '') . ' ' . (string)($s['father_name'] ?? '') . ' ' . (string)($s['last_name'] ?? ''))),
      ];
    }

    /* Modulet në radhën module_seq, me titullin e kopjes së grupit. */
    $modules = [];
    foreach ($topics as $t) {
      $m = (int)$t['module_seq'];
      if (!isset($modules[$m])) {
        $modules[$m] = ['module_seq' => $m, 'module_title' => qta_lr_clean((string)($t['module_title'] ?? '')), 'rows' => []];
      }
    }
    ksort($modules);

    /* Rreshtat e temave nga orari i ruajtur: një rresht për çdo orë mësimi. Një temë
       që zhvillohet X orë në një datë del X herë, secila me datën e saj; radha është
       kronologjike dhe sipas radhës brenda ditës. */
    $days = array_values($days);
    usort($days, static fn(array $a, array $b): int => [(string)$a['date'], (int)($a['seq'] ?? 0)] <=> [(string)$b['date'], (int)($b['seq'] ?? 0)]);
    foreach ($days as &$d) {
      $slots = array_values($d['slots'] ?? []);
      usort($slots, static fn(array $a, array $b): int => (int)$a['seq'] <=> (int)$b['seq']);
      $d['slots'] = $slots;
    }
    unset($d);
    foreach (qta_sched_annotate($topics, $days) as $day) {
      foreach ($day['slots'] as $slot) {
        $m = (int)$slot['module_seq'];
        if (!isset($modules[$m])) {
          continue; // temë e panjohur për kopjen: nuk shpikim modul
        }
        for ($k = 0; $k < (int)$slot['hours']; $k++) {
          $modules[$m]['rows'][] = [
            'date' => (string)$day['date'],
            'date_label' => qta_date((string)$day['date']),
            'topic_seq' => (int)$slot['topic_seq'],
            'topic_title' => qta_lr_clean((string)$slot['topic_title']),
            'hours' => 1,
            'topic_hour' => (int)$slot['from'] + $k,     // ora e temës: 1 … topic_hours
            'topic_hours' => (int)$slot['topic_hours'],
          ];
        }
      }
    }

    /* Ndarja në çifte faqesh */
    $out = [];
    foreach ($modules as $m => $mod) {
      if (!$mod['rows']) {
        continue;
      }
      $mod['heading'] = 'Moduli ' . $m . ($mod['module_title'] !== '' ? ' — ' . $mod['module_title'] : '');
      [$mod['heading_size'], $mod['heading_lines']] = qta_lr_heading_size($mod['heading'], $geo);
      $mod['topics_budget'] = qta_lr_topics_budget($mod['heading_size'], $mod['heading_lines'], $geo);
      $chunks = qta_lr_paginate($mod['rows'], $geo, $mod['topics_budget']);
      $mod['pages'] = [];
      foreach ($chunks as $i => $c) {
        $mod['pages'][] = ['part' => $i + 1, 'parts' => count($chunks), 'continued' => $i > 0, 'dates' => $c['dates'], 'rows' => $c['rows']];
      }
      unset($mod['rows']);
      $out[] = $mod;
    }

    $pages = qta_lr_pages($out, $people, $gid, $course, $geo);
    return [
      'group' => [
        'id' => $gid,
        'course_name' => $course,
        'course_code' => qta_lr_clean((string)($group['course_code'] ?? '')),
        'start_date' => (string)($group['start_date'] ?? ''),
        'end_date' => (string)($group['end_date'] ?? ''),
      ],
      'title' => 'Regjistri i orëve të mësimit — Grupi #' . $gid,
      'generated_on' => $today ?? date('Y-m-d'),
      'students' => $people,
      'modules' => $out,
      'pages' => $pages,
      'page_count' => count($pages),
      'geometry' => $geo,
    ];
  }

  /** Lartësia e një rreshti teme në faqen çift: [rreshta teksti, lartësia në pt]. */
  function qta_lr_topic_row(string $title, array $geo): array
  {
    $textWidth = $geo['top_cols'][1] - 2 * $geo['top_cell_pad'];
    $lines = qta_lr_text_lines($title, $textWidth, $geo['top_text_size']);
    $h = max($geo['top_row_min'], $lines * $geo['top_line_h'] + $geo['top_row_pad']);
    return [$lines, round($h, 2)];
  }

  /**
   * Ndan rreshtat e një moduli (një për çdo orë) në pjesë: çdo pjesë ka deri në 31
   * orë — 31 kolona në faqen tek — dhe rreshtat e saj zënë në tabelën e faqes çift.
   * Orët e një date nuk ndahen mes pjesëve; vetëm një datë që s'zë dot e vetme në një
   * faqe (shumë tema të gjata në një ditë) ndahet, dhe atëherë ajo datë del në të dy
   * pjesët.
   *
   * @return array<int,array{dates:string[],rows:array,height:float}>
   */
  function qta_lr_paginate(array $rows, array $geo, float $budget): array
  {
    $byDate = [];
    $measured = [];   // e njëjta temë përsëritet për çdo orë: matet një herë
    foreach ($rows as $r) {
      $title = (string)$r['topic_title'];
      [$lines, $h] = $measured[$title] ??= qta_lr_topic_row($title, $geo);
      $byDate[(string)$r['date']][] = $r + ['lines' => $lines, 'height' => $h];
    }

    $chunks = [];
    $cur = ['dates' => [], 'rows' => [], 'height' => 0.0];
    $flush = static function () use (&$chunks, &$cur): void {
      if ($cur['rows']) {
        $chunks[] = $cur;
      }
      $cur = ['dates' => [], 'rows' => [], 'height' => 0.0];
    };

    foreach ($byDate as $date => $dateRows) {
      $h = array_sum(array_column($dateRows, 'height'));
      if ($h <= $budget && count($dateRows) <= QTA_LR_MAX_COLUMNS) {
        if ($cur['rows'] && (count($cur['rows']) + count($dateRows) > QTA_LR_MAX_COLUMNS || $cur['height'] + $h > $budget)) {
          $flush();
        }
        $cur['dates'][] = $date;
        array_push($cur['rows'], ...$dateRows);
        $cur['height'] += $h;
        continue;
      }
      /* Një ditë më e gjatë se një faqe: rreshtat e saj ndahen. */
      foreach ($dateRows as $r) {
        $fits = $cur['height'] + $r['height'] <= $budget && count($cur['rows']) < QTA_LR_MAX_COLUMNS;
        if ($cur['rows'] && !$fits) {
          $flush();
        }
        if (!in_array($date, $cur['dates'], true)) {
          $cur['dates'][] = $date;
        }
        $cur['rows'][] = $r;
        $cur['height'] += $r['height'];
      }
    }
    $flush();
    return $chunks;
  }

  /**
   * Segmentet e rreshtit "Muaji:": një segment për çdo muaj që shfaqet, që nis
   * mbi kolonën e parë të atij muaji. Kolonat bosh pas datës së fundit i bashkohen
   * segmentit të fundit. Muaji jepet me numër (1 = janar … 12 = dhjetor).
   *
   * @param string[] $dates data e çdo kolone (një për orë), në radhë kronologjike
   * @return array<int,array{from:int,span:int,year:int,month:int,label:string,first_date:string,is_change:bool}>
   */
  function qta_lr_month_segments(array $dates, int $columns = QTA_LR_MAX_COLUMNS): array
  {
    $segs = [];
    foreach (array_values($dates) as $i => $iso) {
      $y = (int)substr($iso, 0, 4);
      $m = (int)substr($iso, 5, 2);
      $n = count($segs);
      if ($n && $segs[$n - 1]['year'] === $y && $segs[$n - 1]['month'] === $m) {
        $segs[$n - 1]['span']++;
      } else {
        $segs[] = ['from' => $i, 'span' => 1, 'year' => $y, 'month' => $m, 'label' => (string)$m, 'first_date' => $iso, 'is_change' => $n > 0];
      }
    }
    $used = count($dates);
    if (!$segs) {
      return [['from' => 0, 'span' => $columns, 'year' => 0, 'month' => 0, 'label' => '', 'first_date' => '', 'is_change' => false]];
    }
    if ($used < $columns) {
      $segs[count($segs) - 1]['span'] += $columns - $used;
    }
    return $segs;
  }

  /**
   * Radha përfundimtare e faqeve: tek = prezenca, çift = temat, për çdo pjesë
   * të çdo moduli. Çdo faqe ka gjithçka që duhet për ta vizatuar.
   */
  function qta_lr_pages(array $modules, array $people, int $gid, string $course, array $geo): array
  {
    $total = 0;
    foreach ($modules as $mod) {
      $total += 2 * count($mod['pages']);
    }
    $n = count($people);
    $formRows = max(QTA_LR_FORM_ROWS, $n);
    $rowH = $n > QTA_LR_FORM_ROWS ? floor($geo['att_body_h'] / $n * 100) / 100 : $geo['att_row_h'];

    $pages = [];
    $no = 0;
    foreach ($modules as $mod) {
      $moduleLabel = 'Moduli ' . $mod['module_seq'];
      foreach ($mod['pages'] as $part) {
        /* Një kolonë për çdo orë, në radhën e rreshtave të faqes çift: kolona k = rreshti k. */
        $colDates = array_column($part['rows'], 'date');
        $columns = [];
        foreach (range(0, QTA_LR_MAX_COLUMNS - 1) as $i) {
          $iso = $colDates[$i] ?? null;
          $columns[] = $iso === null ? null : [
            'date' => $iso, 'day' => (string)(int)substr($iso, 8, 2), 'month' => (int)substr($iso, 5, 2), 'year' => (int)substr($iso, 0, 4),
            'topic_seq' => $part['rows'][$i]['topic_seq'], 'topic_hour' => $part['rows'][$i]['topic_hour'] ?? null,
          ];
        }
        $pages[] = [
          'number' => ++$no,
          'kind' => 'attendance',
          'module_seq' => $mod['module_seq'],
          'module_title' => $mod['module_title'],
          'part' => $part['part'], 'parts' => $part['parts'], 'continued' => $part['continued'],
          'dates' => $part['dates'],
          'columns' => $columns,
          'months' => qta_lr_month_segments($colDates),
          'row_count' => $formRows,
          'numbered' => $n,
          'row_height' => $rowH,
          'caption' => qta_lr_caption($gid, $course, $moduleLabel . ($part['continued'] ? ' — vazhdim' : ''), $no, $total, $geo),
        ];

        $used = array_sum(array_column($part['rows'], 'height'));
        $pages[] = [
          'number' => ++$no,
          'kind' => 'topics',
          'module_seq' => $mod['module_seq'],
          'module_title' => $mod['module_title'],
          'part' => $part['part'], 'parts' => $part['parts'], 'continued' => $part['continued'],
          'heading' => $mod['heading'],
          'heading_note' => $part['continued'] ? 'vazhdim' : '',
          'heading_size' => $mod['heading_size'],
          'heading_lines' => $mod['heading_lines'],
          'dates' => $part['dates'],
          'rows' => array_map(static fn(array $r): array => [
            'date' => $r['date'], 'date_label' => $r['date_label'], 'topic_seq' => $r['topic_seq'],
            'topic_title' => $r['topic_title'], 'hours' => $r['hours'],
            'topic_hour' => $r['topic_hour'] ?? null, 'topic_hours' => $r['topic_hours'] ?? null, 'notes' => '',
            'lines' => $r['lines'], 'height' => $r['height'],
          ], $part['rows']),
          'budget' => $mod['topics_budget'],
          'fillers' => max(0, (int)floor(($mod['topics_budget'] - $used + 0.001) / $geo['top_row_min'])),
          'caption' => qta_lr_caption($gid, $course, '', $no, $total, $geo),
        ];
      }
    }
    return $pages;
  }

  /**
   * Madhësia e titullit "Moduli X — …" (Times New Roman Bold) që të mos kalojë
   * 3 rreshta: 11 pt, dhe vetëm për tituj shumë të gjatë më e vogël.
   * @return array{0:float,1:int}
   */
  function qta_lr_heading_size(string $heading, array $geo): array
  {
    $text = $heading . ' (vazhdim)';
    foreach ([$geo['heading_size'], 10.0, 9.0, 8.0] as $size) {
      $lines = qta_lr_text_lines($text, $geo['content_w'], $size, 'serif-bold');
      if ($lines <= $geo['heading_max_lines']) {
        return [$size, $lines];
      }
    }
    return [8.0, $geo['heading_max_lines']];
  }

  /**
   * Rreshti i vogël poshtë tabelës: majtas grupi, kursi (i shkurtuar kur është
   * i gjatë) dhe moduli; djathtas "Faqja 3 nga 12".
   * @return array{left:string,right:string}
   */
  function qta_lr_caption(int $gid, string $course, string $module, int $page, int $total, array $geo): array
  {
    $size = $geo['caption_size'];
    $right = 'Faqja ' . $page . ' nga ' . $total;
    $head = 'Grupi #' . $gid;
    $tail = $module !== '' ? ' · ' . $module : '';
    $room = $geo['content_w'] - qta_lr_text_width($right, $size) - 24.0
      - qta_lr_text_width($head . ' · ' . $tail, $size);
    $left = $head . ($course !== '' ? ' · ' . qta_lr_fit_text($course, max(30.0, $room), $size) : '') . $tail;
    return ['left' => $left, 'right' => $right];
  }

  /** Emri i skedarit: vetëm shkronja latine, shifra dhe viza. */
  function qta_lr_filename(int $groupId, string $ext, string $date): string
  {
    $ext = preg_replace('/[^a-z]/', '', strtolower($ext)) ?: 'pdf';
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    return 'QTA-Regjistri-Mesimit-Grupi-' . max(0, $groupId) . '-' . $date . '.' . $ext;
  }
}
