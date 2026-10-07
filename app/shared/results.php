<?php
declare(strict_types=1);

/**
 * results.php — Rezultatet e kursantëve: pikët sipas moduleve dhe rezultati përfundimtar.
 *
 * Burimi i së vërtetës janë pikët e moduleve (enrollment_module_scores): një rresht për
 * kursant në grup × modul, 0–100 me të shumtën dy shifra pas presjes. Mungesa e rreshtit
 * do të thotë "pa pikë" — bosh nuk është 0, dhe 0 është pikë e vlefshme.
 *
 * Rregullat përcaktohen vetëm këtu:
 *   modulet që kërkojnë pikë  grupi me orar (edhe i konvertuar): modulet e kopjes së tij
 *                             (group_schedule_topics), në radhën e kopjes — ato që grupi
 *                             zhvilloi, jo kursi siç është sot; grupi i regjistrit të vjetër
 *                             (pa kopje): modulet e kursit siç janë tani, në radhën e kursit;
 *   i plotë                   çdo modul i grupit ka pikë;
 *   rezultati përfundimtar    mesatarja e pikëve të moduleve kur është i plotë, e rrumbullakuar
 *                             në dy shifra pas presjes (gjysma lart); përndryshe mungon;
 *   pikët e vjetra            kursanti pa asnjë pikë moduli mban rezultatin e shkruar me dorë
 *                             para këtij ndryshimi (p.sh. grupet e konvertuara), të pandryshuar.
 *                             Kur merr pikët e para të moduleve, rezultati i vjetër ruhet te
 *                             legacy_final_score dhe rezultati llogaritet nga modulet. Pikët e
 *                             vjetra nuk ndahen kurrë nëpër module.
 *
 * course_group_students.final_score mban rezultatin zyrtar për leximet ekzistuese (listat,
 * kartela, eksportet, procesverbali). E shkruajnë vetëm qta_results_save() dhe, kur ndryshojnë
 * modulet e një kursi me grupe të regjistrit të vjetër, qta_results_sync_course(), në të
 * njëjtin transaksion; baza refuzon çdo shkrim tjetër (trg_cgs_results_bu).
 *
 * Llogaritjet bëhen me numra të plotë (qindëshe pikësh), pa numra me presje lëvizëse.
 */

require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/themeli.php';

const QTA_SCORE_MAX = 10000;          // 100,00 pikë, në qindëshe
const QTA_RESULTS_MAX_CELLS = 2000;   // kufiri i një ruajtjeje

/* ================================================================== Pikët */

if (!function_exists('qta_score_parse')) {
  /**
   * Pikët e shkruara ("85", "85,5", "85.25", 85) → qindëshe (8500, 8550, 8525).
   * Bosh → null. Çdo vlerë tjetër hedh QtaUserError me mesazhin për përdoruesin.
   */
  function qta_score_parse($raw): ?int
  {
    if ($raw === null) return null;
    if (is_bool($raw) || is_array($raw) || is_object($raw)) {
      throw new QtaUserError('Shkruaj pikët me shifra, nga 0 deri në 100, p.sh. 85 ose 85,5.', ['code' => 'score_format']);
    }
    $s = trim(str_replace(',', '.', (string)$raw));
    if ($s === '') return null;
    if (preg_match('/^0*(\d{1,3})(?:\.(\d{0,2}))?$/', $s, $m)) {
      $h = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
      if ($h <= QTA_SCORE_MAX) return $h;
      throw new QtaUserError('Pikët janë nga 0 deri në 100.', ['code' => 'score_range']);
    }
    if (preg_match('/^\d+\.\d{3,}$/', $s)) {
      throw new QtaUserError('Pikët kanë të shumtën dy shifra pas presjes, p.sh. 85,25.', ['code' => 'score_precision']);
    }
    if (preg_match('/^-?\d+(?:\.\d*)?$/', $s)) {
      throw new QtaUserError('Pikët janë nga 0 deri në 100.', ['code' => 'score_range']);
    }
    throw new QtaUserError('Shkruaj pikët me shifra, nga 0 deri në 100, p.sh. 85 ose 85,5.', ['code' => 'score_format']);
  }

  /** Qindëshe → vlera e bazës ("85.50"). */
  function qta_score_db(int $h): string
  {
    return ($h < 0 ? '-' : '') . sprintf('%d.%02d', intdiv(abs($h), 100), abs($h) % 100);
  }

  /** Qindëshe → pikët për njerëzit: "85,5", "84", "83,25". */
  function qta_score_label(?int $h, string $empty = '—'): string
  {
    if ($h === null) return $empty;
    $a = abs($h);
    $frac = $a % 100;
    return ($h < 0 ? '-' : '') . intdiv($a, 100) . ($frac === 0 ? '' : ',' . rtrim(sprintf('%02d', $frac), '0'));
  }

  /**
   * Vlera e bazës (DECIMAL(5,2) si tekst, p.sh. "85.50") → qindëshe, ose null. Pranon
   * edhe të dhëna të vjetra jashtë 0–100, që të shfaqen ashtu siç janë.
   */
  function qta_score_from_db($value): ?int
  {
    if ($value === null || $value === '') return null;
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2})\d*)?$/', trim((string)$value), $m)) return null;
    $h = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
    return $m[1] === '-' ? -$h : $h;
  }
}

/* ============================================================== Llogaritja */

if (!function_exists('qta_results_compute')) {
  /** Mesatarja e $n pikëve me shumë $sum (qindëshe), e rrumbullakuar gjysma lart. */
  function qta_results_round_avg(int $sum, int $n): int
  {
    return intdiv(2 * $sum + $n, 2 * $n);
  }

  /**
   * Rezultati i një kursanti nga pikët e moduleve. Funksion i pastër, pa databazë: i
   * njëjti rregull për dritaren e pikëve, ruajtjen, raportet dhe certifikatat.
   *
   * @param int[]            $moduleIds modulet që kërkojnë pikë (modulet e grupit), në radhë
   * @param array<int,?int>  $scores    module_id → pikët në qindëshe (vetëm ato që ekzistojnë)
   * @param int|null         $legacy    pikët e vjetra (të shkruara me dorë), në qindëshe
   * @return array{mode:string,required:int,scored:int,missing:int[],complete:bool,sum:int,final:?int,partial:?int,legacy:?int}
   *   mode     'modules' kur kursanti ka të paktën një pikë moduli; përndryshe 'legacy'
   *            (ka pikë të vjetra) ose 'none';
   *   final    rezultati zyrtar në qindëshe: mesatarja kur çdo modul ka pikë, pikët e
   *            vjetra te 'legacy', përndryshe null;
   *   partial  mesatarja e moduleve me pikë deri tani — vetëm për pamje, jo rezultat.
   */
  function qta_results_compute(array $moduleIds, array $scores, ?int $legacy = null): array
  {
    $required = count($moduleIds);
    $scored = 0;
    $sum = 0;
    $missing = [];
    foreach ($moduleIds as $mid) {
      $v = $scores[$mid] ?? null;
      if ($v === null) {
        $missing[] = (int)$mid;
        continue;
      }
      $scored++;
      $sum += (int)$v;
    }
    $any = false;
    foreach ($scores as $v) {
      if ($v !== null) { $any = true; break; }
    }
    if (!$any) {
      return ['mode' => $legacy !== null ? 'legacy' : 'none', 'required' => $required, 'scored' => 0, 'missing' => $missing,
              'complete' => false, 'sum' => 0, 'final' => $legacy, 'partial' => null, 'legacy' => $legacy];
    }
    $complete = $required > 0 && $scored === $required;
    return [
      'mode' => 'modules', 'required' => $required, 'scored' => $scored, 'missing' => $missing, 'complete' => $complete,
      'sum' => $sum,
      'final' => $complete ? qta_results_round_avg($sum, $required) : null,
      'partial' => $scored > 0 ? qta_results_round_avg($sum, $scored) : null,
      'legacy' => $legacy,
    ];
  }
}

/* ================================================================ Leximi */

if (!function_exists('qta_results_groups')) {
  /**
   * Grupet me kursin e tyre (një query).
   * @return array<int,array<string,mixed>> group_id → grupi
   */
  function qta_results_groups(PDO $pdo, array $groupIds): array
  {
    $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn($i) => $i > 0)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
      SELECT cg.id, cg.course_id, cg.model, cg.start_date, cg.end_date, cg.is_completed,
             c.name AS course_name, c.code AS course_code, gs.schedule_mode,
             gc.converted_at
      FROM course_groups cg
      JOIN courses c ON c.id = cg.course_id
      LEFT JOIN group_schedules gs ON gs.group_id = cg.id
      LEFT JOIN group_conversions gc ON gc.group_id = cg.id AND gc.status = 'completed'
      WHERE cg.id IN ($ph)
    ");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
      foreach (['id', 'course_id', 'is_completed'] as $k) $g[$k] = (int)$g[$k];
      $out[$g['id']] = $g;
    }
    return $out;
  }

  /**
   * Modulet që kërkojnë pikë për secilin grup, në radhë. Grupi me orar: kopja e tij
   * (group_schedule_topics); grupi i regjistrit të vjetër: modulet e kursit tani. Dy
   * query, sa do grupe të ketë.
   * @param array<int,array<string,mixed>> $groups nga qta_results_groups()
   * @return array<int,array<int,array{id:int,seq:int,title:string,hours:int}>> group_id → modulet
   */
  function qta_results_module_sets(PDO $pdo, array $groups): array
  {
    $out = [];
    $scheduled = [];
    $byCourse = [];
    foreach ($groups as $g) {
      $gid = (int)$g['id'];
      $out[$gid] = [];
      if ($g['model'] === 'scheduled') $scheduled[] = $gid;
      else $byCourse[(int)$g['course_id']][] = $gid;
    }

    if ($scheduled) {
      $ph = implode(',', array_fill(0, count($scheduled), '?'));
      $st = $pdo->prepare("
        SELECT group_id, module_seq, MIN(module_title) AS title, MIN(module_hours) AS hours,
               MIN(source_module_id) AS module_id, COUNT(DISTINCT source_module_id) AS ids, SUM(source_module_id IS NULL) AS unknown
        FROM group_schedule_topics
        WHERE group_id IN ($ph)
        GROUP BY group_id, module_seq
        ORDER BY group_id, module_seq
      ");
      $st->execute($scheduled);
      $seen = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $gid = (int)$r['group_id'];
        $mid = (int)$r['module_id'];
        if ((int)$r['unknown'] > 0 || (int)$r['ids'] !== 1 || isset($seen[$gid][$mid])) {
          error_log('[QTA results] grupi ' . $gid . ': moduli ' . (int)$r['module_seq'] . ' i kopjes nuk ka një modul të vetëm burimor');
          throw new QtaUserError('Kopja e moduleve të Grupit #' . $gid . ' nuk lidhet qartë me modulet e kursit, prandaj pikët nuk mund të vendosen. Njofto administratorin.');
        }
        $seen[$gid][$mid] = true;
        $out[$gid][] = ['id' => $mid, 'seq' => (int)$r['module_seq'], 'title' => (string)$r['title'], 'hours' => (int)$r['hours']];
      }
    }

    if ($byCourse) {
      $cids = array_keys($byCourse);
      $ph = implode(',', array_fill(0, count($cids), '?'));
      $st = $pdo->prepare("SELECT id, course_id, title, hours FROM course_modules WHERE course_id IN ($ph) ORDER BY course_id, position, id");
      $st->execute($cids);
      $seq = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['course_id'];
        $seq[$cid] = ($seq[$cid] ?? 0) + 1;
        foreach ($byCourse[$cid] as $gid) {
          $out[$gid][] = ['id' => (int)$r['id'], 'seq' => $seq[$cid], 'title' => (string)$r['title'], 'hours' => (int)$r['hours']];
        }
      }
    }
    return $out;
  }

  /**
   * Fleta e rezultateve të një grupi: grupi, modulet e tij, çdo kursant me pikët e
   * moduleve dhe rezultatin. Katër query, sa do kursantë e module të ketë.
   *
   * $lock bllokon grupin, kursantët dhe pikët deri në fund të transaksionit (ruajtja).
   */
  function qta_results_sheet(PDO $pdo, int $groupId, bool $lock = false): array
  {
    if ($lock) {
      /* Radha e bllokimit: grupi, kursantët, pikët — si caktimi i kursantëve në grup. */
      $pdo->prepare('SELECT id FROM course_groups WHERE id = ? FOR UPDATE')->execute([$groupId]);
      $pdo->prepare('SELECT student_id FROM course_group_students WHERE group_id = ? FOR UPDATE')->execute([$groupId]);
      $pdo->prepare('SELECT module_id FROM enrollment_module_scores WHERE group_id = ? FOR UPDATE')->execute([$groupId]);
    }
    $g = qta_results_groups($pdo, [$groupId])[$groupId] ?? null;
    if (!$g) {
      throw new QtaUserError('Grupi nuk u gjet. Ndoshta u fshi — rifresko faqen.', ['code' => 'not_found']);
    }
    $modules = qta_results_module_sets($pdo, [$groupId => $g])[$groupId] ?? [];
    $moduleIds = array_column($modules, 'id');

    $ms = $pdo->prepare('
      SELECT cgs.student_id, s.nr_amze, p.first_name, p.father_name, p.last_name,
             cgs.exam_date, cgs.final_score, cgs.legacy_final_score
      FROM course_group_students cgs
      JOIN students s ON s.id = cgs.student_id
      LEFT JOIN persons p ON p.id = s.person_id
      WHERE cgs.group_id = ?
      ORDER BY CAST(s.nr_amze AS UNSIGNED), s.nr_amze
    ');
    $ms->execute([$groupId]);
    $rows = $ms->fetchAll(PDO::FETCH_ASSOC);

    $sc = $pdo->prepare('SELECT student_id, module_id, score FROM enrollment_module_scores WHERE group_id = ?');
    $sc->execute([$groupId]);
    $scores = [];
    foreach ($sc->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $scores[(int)$r['student_id']][(int)$r['module_id']] = qta_score_from_db($r['score']);
    }

    $members = [];
    foreach ($rows as $r) {
      $sid = (int)$r['student_id'];
      $own = $scores[$sid] ?? [];
      $stored = qta_score_from_db($r['final_score']);
      /* Pa asnjë pikë moduli, rezultati i ruajtur është i shkruar me dorë (i vjetër). */
      $legacy = qta_score_from_db($r['legacy_final_score']) ?? ($own ? null : $stored);
      $members[] = [
        'student_id' => $sid,
        'amze' => (string)$r['nr_amze'],
        'name' => qta_full_name($r['first_name'] ?? '', $r['father_name'] ?? '', $r['last_name'] ?? ''),
        'exam_date' => $r['exam_date'] === null ? null : (string)$r['exam_date'],
        'scores' => $own,
        'legacy' => $legacy,
        'legacy_stored' => $r['legacy_final_score'] !== null,
        'stored_final' => $stored,
        'result' => qta_results_compute($moduleIds, $own, $legacy),
      ];
    }

    return [
      'group' => $g + ['closed' => (int)$g['is_completed'] === 1],
      'modules' => $modules,
      'module_source' => $g['model'] === 'scheduled' ? 'copy' : 'course',
      'members' => $members,
    ];
  }

  /**
   * Për tabelat e grupeve: sa module kërkojnë pikë në çdo grup dhe sa pikë moduli ka
   * çdo kursant. Katër query, sa do grupe të ketë faqja.
   * @return array<int,array{required:int,scored:array<int,int>}> group_id → …
   */
  function qta_results_progress(PDO $pdo, array $groupIds): array
  {
    $groups = qta_results_groups($pdo, $groupIds);
    if (!$groups) return [];
    $sets = qta_results_module_sets($pdo, $groups);
    $out = [];
    foreach ($sets as $gid => $mods) $out[$gid] = ['required' => count($mods), 'scored' => []];
    $ids = array_keys($out);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT group_id, student_id, COUNT(*) AS n FROM enrollment_module_scores WHERE group_id IN ($ph) GROUP BY group_id, student_id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $out[(int)$r['group_id']]['scored'][(int)$r['student_id']] = (int)$r['n'];
    }
    return $out;
  }

  /**
   * Rezultati i një kursanti në një grup, gati për certifikatë, raport ose eksport:
   * kursanti, kursi, grupi, çdo modul (në radhë) me pikët e tij dhe rezultati.
   * null kur grupi ose kursanti mungon. Pikët janë në formatin e bazës ("85.50").
   */
  function qta_results_enrollment(PDO $pdo, int $groupId, int $studentId): ?array
  {
    $g = qta_results_groups($pdo, [$groupId])[$groupId] ?? null;
    if (!$g) return null;
    $sheet = qta_results_sheet($pdo, $groupId);
    foreach ($sheet['members'] as $m) {
      if ($m['student_id'] !== $studentId) continue;
      $res = $m['result'];
      return [
        'student' => ['id' => $studentId, 'amze' => $m['amze'], 'name' => $m['name']],
        'course' => ['id' => $g['course_id'], 'code' => (string)$g['course_code'], 'name' => (string)$g['course_name']],
        'group' => ['id' => $g['id'], 'start_date' => (string)$g['start_date'], 'end_date' => (string)$g['end_date'], 'exam_date' => $m['exam_date']],
        'modules' => array_map(static fn($mod) => [
          'id' => $mod['id'], 'seq' => $mod['seq'], 'title' => $mod['title'], 'hours' => $mod['hours'],
          'score' => isset($m['scores'][$mod['id']]) ? qta_score_db($m['scores'][$mod['id']]) : null,
        ], $sheet['modules']),
        'result' => [
          'source' => $res['mode'],              // modules | legacy | none
          'complete' => $res['complete'],
          'scored' => $res['scored'],
          'required' => $res['required'],
          'final' => $res['final'] === null ? null : qta_score_db($res['final']),
          'legacy_final' => $res['legacy'] === null ? null : qta_score_db($res['legacy']),
        ],
      ];
    }
    return null;
  }

  /** Kursantët e grupit që kanë të paktën një pikë moduli. @return array<int,true> */
  function qta_results_scored_students(PDO $pdo, int $groupId): array
  {
    $st = $pdo->prepare('SELECT DISTINCT student_id FROM enrollment_module_scores WHERE group_id = ?');
    $st->execute([$groupId]);
    return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
  }

  /** Sa kursantë dhe cilat grupe të regjistrit të vjetër kanë pikë për këtë modul të kursit. */
  function qta_results_legacy_usage(PDO $pdo, int $moduleId): array
  {
    $st = $pdo->prepare("
      SELECT s.group_id, COUNT(*) AS n
      FROM enrollment_module_scores s JOIN course_groups g ON g.id = s.group_id
      WHERE s.module_id = ? AND g.model = 'legacy'
      GROUP BY s.group_id ORDER BY s.group_id
    ");
    $st->execute([$moduleId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return ['students' => (int)array_sum(array_column($rows, 'n')), 'groups' => array_map('intval', array_column($rows, 'group_id'))];
  }
}

/* =============================================================== Shkrimi */

if (!function_exists('qta_results_save')) {
  /** "Arben Hoxha" ose "amza 3401" kur kursanti nuk ka ende emër. */
  function qta_results_who(array $member): string
  {
    return $member['name'] !== '' ? $member['name'] : 'amza ' . $member['amze'];
  }

  /**
   * Shkruan rezultatin zyrtar (dhe, kur zëvendësohen, pikët e vjetra) të disa kursantëve.
   * Vetëm kjo rrugë e shkruan final_score: baza e lejon vetëm me @qta_results_sync = 1.
   * @param array<int,array{final:?int,legacy:?int}> $writes student_id → vlerat
   */
  function qta_results_write(PDO $pdo, int $groupId, array $writes): void
  {
    if (!$writes) return;
    $pdo->exec('SET @qta_results_sync = 1');
    try {
      $st = $pdo->prepare('UPDATE course_group_students SET final_score = ?, legacy_final_score = COALESCE(legacy_final_score, ?) WHERE group_id = ? AND student_id = ?');
      foreach ($writes as $sid => $w) {
        $st->execute([
          $w['final'] === null ? null : qta_score_db($w['final']),
          $w['legacy'] === null ? null : qta_score_db($w['legacy']),
          $groupId, $sid,
        ]);
      }
    } finally {
      $pdo->exec('SET @qta_results_sync = NULL');
    }
  }

  /**
   * Pas një ndryshimi të moduleve të kursit (shtim ose fshirje): grupet e regjistrit të
   * vjetër nuk kanë kopje, prandaj rezultati i kursantëve të tyre me pikë moduli rillogaritet
   * me modulet e reja — një modul i ri e bën të paplotë, një modul i hequr (pa pikë) mund ta
   * plotësojë. Grupet me orar kanë kopjen e tyre dhe nuk preken. Brenda transaksionit të
   * thirrësit. Kthen sa rezultate ndryshuan.
   */
  function qta_results_sync_course(PDO $pdo, int $courseId): int
  {
    $st = $pdo->prepare("
      SELECT DISTINCT s.group_id
      FROM enrollment_module_scores s JOIN course_groups g ON g.id = s.group_id
      WHERE g.course_id = ? AND g.model = 'legacy'
    ");
    $st->execute([$courseId]);
    $changed = 0;
    foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $gid) {
      $writes = [];
      foreach (qta_results_sheet($pdo, $gid)['members'] as $m) {
        if ($m['result']['mode'] === 'modules' && $m['result']['final'] !== $m['stored_final']) {
          $writes[$m['student_id']] = ['final' => $m['result']['final'], 'legacy' => null];
        }
      }
      qta_results_write($pdo, $gid, $writes);
      $changed += count($writes);
    }
    return $changed;
  }

  /**
   * Ruan njëherësh pikët e ndryshuara të një grupi me orar, në një transaksion: ose të
   * gjitha, ose asnjë. Shkruhen vetëm qelizat e dërguara që ndryshojnë vërtet.
   *
   * $cells: [['student_id' => 12, 'module_id' => 57, 'from' => '80' | null, 'to' => '85,5' | null], …]
   *   from  vlera që pa faqja; nëse dikush tjetër e ka ndryshuar ndërkohë, ruajtja
   *         refuzohet për të gjitha (code = stale) dhe kthehen vlerat e reja;
   *   to    vlera e re; null ose '' e heq pikën.
   * $opts: force (bool) — pranon konfirmimin (grup i mbyllur, pikë të vjetra që zëvendësohen).
   *
   * Serveri kontrollon gjithçka sërish: kursanti në grup, moduli në kopjen e grupit, pikët
   * 0–100 me dy shifra, data e provimit. Rezultati llogaritet këtu; faqja vetëm e parashikon.
   */
  function qta_results_save(PDO $pdo, int $groupId, $cells, array $opts = []): array
  {
    $force = !empty($opts['force']);
    $bad = static fn(): QtaUserError => new QtaUserError('Ndryshimet nuk u lexuan. Rifresko faqen dhe provo sërish.', ['code' => 'bad_request']);
    if (!is_array($cells)) throw $bad();
    if (count($cells) > QTA_RESULTS_MAX_CELLS) {
      throw new QtaUserError('Shumë ndryshime njëherësh. Ruaji në disa hapa.', ['code' => 'too_many']);
    }

    $req = [];
    foreach ($cells as $c) {
      if (!is_array($c)) throw $bad();
      $sid = qta_parse_int_input($c['student_id'] ?? '', 1, PHP_INT_MAX);
      $mid = qta_parse_int_input($c['module_id'] ?? '', 1, PHP_INT_MAX);
      if ($sid === null || $mid === null) throw $bad();
      $key = $sid . ':' . $mid;
      if (isset($req[$key])) throw $bad();
      try {
        $from = qta_score_parse($c['from'] ?? null);
      } catch (QtaUserError $e) {
        throw $bad();
      }
      $to = null;
      $error = null;
      try {
        $to = qta_score_parse($c['to'] ?? null);
      } catch (QtaUserError $e) {
        $error = $e->getMessage();
      }
      $req[$key] = ['sid' => $sid, 'mid' => $mid, 'from' => $from, 'to' => $to, 'error' => $error];
    }
    if (!$req) {
      throw new QtaUserError('Nuk ndryshove asnjë pikë.', ['code' => 'nothing']);
    }

    return qta_tx($pdo, function () use ($pdo, $groupId, $req, $force): array {
      $sheet = qta_results_sheet($pdo, $groupId, true);
      $moduleIds = array_column($sheet['modules'], 'id');
      $modules = array_combine($moduleIds, $sheet['modules']) ?: [];
      $members = array_combine(array_column($sheet['members'], 'student_id'), $sheet['members']) ?: [];

      $errors = [];
      $conflicts = [];
      $changes = [];
      foreach ($req as $r) {
        $m = $members[$r['sid']] ?? null;
        $mod = $modules[$r['mid']] ?? null;
        $cell = ['student_id' => $r['sid'], 'module_id' => $r['mid']];
        if (!$m) { $errors[] = $cell + ['message' => 'Ky kursant nuk është më në grup. Rifresko pikët.']; continue; }
        if (!$mod) { $errors[] = $cell + ['message' => qta_results_who($m) . ': ky modul nuk është te modulet e grupit.']; continue; }
        if ($r['error'] !== null) { $errors[] = $cell + ['message' => qta_results_who($m) . ' · ' . $mod['title'] . ': ' . $r['error']]; continue; }
        $current = $m['scores'][$r['mid']] ?? null;
        if ($current !== $r['to'] && $current !== $r['from']) {
          $conflicts[] = $cell + ['value' => $current === null ? null : qta_score_db($current),
                                  'label' => qta_results_who($m) . ' · ' . $mod['title']];
          continue;
        }
        if ($current === $r['to']) continue;
        if ($r['to'] !== null && $m['exam_date'] === null) {
          $errors[] = $cell + ['message' => qta_results_who($m) . ' nuk ka datë provimi. Cakto së pari datën e provimit, pastaj pikët.'];
          continue;
        }
        $changes[] = $r + ['current' => $current];
      }
      if ($errors) {
        $first = $errors[0]['message'];
        throw new QtaUserError(count($errors) === 1 ? 'Pikët nuk u ruajtën. ' . $first
            : 'Pikët nuk u ruajtën: ' . count($errors) . ' qeliza kanë probleme. ' . $first,
          ['code' => 'invalid', 'details' => ['cells' => $errors]]);
      }
      if ($conflicts) {
        throw new QtaUserError(
          (count($conflicts) === 1 ? 'Ndërkohë dikush tjetër ndryshoi 1 pikë' : 'Ndërkohë dikush tjetër ndryshoi ' . count($conflicts) . ' pikë')
            . ' (' . implode(', ', array_slice(array_column($conflicts, 'label'), 0, 3)) . (count($conflicts) > 3 ? ' …' : '') . '). '
            . 'Vlerat e reja u vendosën në tabelë; kontrolloji dhe ruaji sërish. Asgjë nuk u ruajt.',
          ['code' => 'stale', 'details' => ['conflicts' => $conflicts]]);
      }
      if (!$changes) {
        return ['changed' => 0, 'students' => 0, 'message' => 'Pikët janë njësoj si ato të ruajtura; asgjë nuk ndryshoi.', 'sheet' => $sheet];
      }

      /* Gjendja pas ndryshimit, për çdo kursant të prekur: pikët e reja dhe rezultati. */
      $after = [];
      foreach ($changes as $ch) {
        $sid = $ch['sid'];
        if (!isset($after[$sid])) $after[$sid] = $members[$sid]['scores'];
        if ($ch['to'] === null) unset($after[$sid][$ch['mid']]);
        else $after[$sid][$ch['mid']] = $ch['to'];
      }
      $writes = [];
      $replacing = [];
      foreach ($after as $sid => $scores) {
        $m = $members[$sid];
        $res = qta_results_compute($moduleIds, $scores, $m['legacy']);
        /* Pikët e vjetra zëvendësohen nga modulet: ruhen, të pandryshuara, para se të ikin. */
        $keepLegacy = $m['result']['mode'] === 'legacy' && $res['mode'] === 'modules' && !$m['legacy_stored'] ? $m['legacy'] : null;
        if ($keepLegacy !== null) $replacing[] = $m;
        if ($res['final'] !== $m['stored_final'] || $keepLegacy !== null) {
          $writes[$sid] = ['final' => $res['final'], 'legacy' => $keepLegacy];
        }
      }

      /* Pasojat që duhen pranuar: grupi i mbyllur dhe pikët e vjetra që zëvendësohen. */
      if (!$force && ($sheet['group']['closed'] || $replacing)) {
        $parts = [];
        if ($sheet['group']['closed']) {
          $parts[] = 'Grupi është i mbyllur dhe dokumentet e tij mund të jenë lëshuar tashmë.';
        }
        if ($replacing) {
          $names = array_map(static fn($m) => qta_results_who($m) . ' (' . qta_score_label($m['legacy']) . ')', $replacing);
          $parts[] = (count($replacing) === 1 ? '1 kursant ka' : count($replacing) . ' kursantë kanë') . ' pikë të vjetra: '
            . implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' …' : '') . '. '
            . 'Me pikët e moduleve, rezultati i tyre llogaritet nga modulet dhe mbetet i paplotë derisa çdo modul të ketë pikë. Pikët e vjetra nuk fshihen.';
        }
        throw new QtaConfirmNeeded(
          $replacing ? 'Rezultati do të llogaritet nga modulet' : 'Ky grup është i mbyllur',
          implode(' ', $parts),
          'Po, ruaj pikët');
      }

      $ins = $pdo->prepare('INSERT INTO enrollment_module_scores (group_id, student_id, module_id, score) VALUES (?, ?, ?, ?)');
      $upd = $pdo->prepare('UPDATE enrollment_module_scores SET score = ? WHERE group_id = ? AND student_id = ? AND module_id = ?');
      $del = $pdo->prepare('DELETE FROM enrollment_module_scores WHERE group_id = ? AND student_id = ? AND module_id = ?');
      foreach ($changes as $ch) {
        if ($ch['to'] === null) {
          $del->execute([$groupId, $ch['sid'], $ch['mid']]);
        } elseif ($ch['current'] === null) {
          $ins->execute([$groupId, $ch['sid'], $ch['mid'], qta_score_db($ch['to'])]);
        } else {
          $upd->execute([qta_score_db($ch['to']), $groupId, $ch['sid'], $ch['mid']]);
        }
      }
      qta_results_write($pdo, $groupId, $writes);

      /* Kontrolli i fundit: lexo sërish pikët dhe rezultatet dhe krahasoji me llogaritjen. */
      $check = qta_results_sheet($pdo, $groupId);
      $byId = array_combine(array_column($check['members'], 'student_id'), $check['members']) ?: [];
      foreach ($after as $sid => $scores) {
        $m = $byId[$sid] ?? null;
        ksort($scores);
        $stored = $m ? $m['scores'] : null;
        if (is_array($stored)) ksort($stored);
        if (!$m || $stored !== $scores || $m['stored_final'] !== $m['result']['final']) {
          error_log('[QTA results] grupi ' . $groupId . ', kursanti ' . $sid . ': leximi pas ruajtjes nuk përputhet');
          throw new QtaUserError('Pikët nuk u ruajtën, sepse kontrolli i fundit gjeti një mospërputhje. Asgjë nuk ndryshoi. Provo sërish; nëse përsëritet, njofto administratorin.');
        }
      }

      $n = count($changes);
      $s = count($after);
      return [
        'changed' => $n,
        'students' => $s,
        'message' => 'Pikët u ruajtën: ' . qta_plural($n, 'ndryshim', 'ndryshime') . ' te ' . qta_plural($s, 'kursant', 'kursantë') . '.',
        'sheet' => $check,
      ];
    });
  }
}

/* ================================================= Të dhënat për faqen */

if (!function_exists('qta_results_payload')) {
  /** Fleta si JSON për dritaren e pikëve (vlerat në formatin e bazës, "85.50"). */
  function qta_results_payload(array $sheet): array
  {
    $g = $sheet['group'];
    return [
      'group' => [
        'id' => $g['id'], 'course_id' => $g['course_id'], 'course' => (string)$g['course_name'],
        'closed' => (bool)$g['closed'], 'source' => $sheet['module_source'],
        'converted' => !empty($g['converted_at']),
      ],
      'modules' => array_map(static fn($m) => ['id' => $m['id'], 'title' => $m['title'], 'hours' => $m['hours']], $sheet['modules']),
      'members' => array_map(static function ($m) {
        $scores = [];
        foreach ($m['scores'] as $mid => $h) {
          if ($h !== null) $scores[(string)$mid] = qta_score_db($h);
        }
        return [
          'id' => $m['student_id'], 'amze' => $m['amze'], 'name' => $m['name'],
          'exam' => $m['exam_date'], 'exam_label' => $m['exam_date'] ? qta_date($m['exam_date']) : '',
          'scores' => (object)$scores,
          'legacy' => $m['legacy'] === null ? null : qta_score_db($m['legacy']),
          'final' => $m['stored_final'] === null ? null : qta_score_db($m['stored_final']),
        ];
      }, $sheet['members']),
    ];
  }
}
