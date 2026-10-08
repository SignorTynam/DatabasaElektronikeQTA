<?php
declare(strict_types=1);

/**
 * lesson_group_update.php — Grupet me orar mësimi (JSON).
 *
 * Veprimet:
 *   preview_new   parashikimi i orarit për një grup të ri (pa ruajtur)
 *   change        orari: 'settings' (fillimi, orët në ditë), 'rule' (një ditë e
 *                 veçantë) ose 'refresh' (temat e reja të kursit); për një orar
 *                 fixed_range edhe 'fixed_range_settings' dhe 'fixed_days';
 *                 me dry_run vetëm parashikon
 *   rebalance     orar me periudhë: rishpërndan orët e planit (pa ruajtur)
 *   members       kursantët e grupit (numrat e amzës, deri në 10)
 *   delete        fshirja e grupit
 *
 * Të gjitha: vetëm administrator/editor dhe tokeni i faqes. Ruajtjet: edhe me
 * ndryshimet të hapura. Kur një veprim ka pasoja (ditë që kanë kaluar, grup i
 * mbyllur, fshirje), përgjigja kërkon konfirmim dhe ndërfaqja e dërgon me force.
 */

require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/staff_guard.php';
require_once __DIR__ . '/../shared/lesson_groups.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  qta_json_out(['ok' => false, 'error' => 'Kjo adresë pranon vetëm ruajtje nga faqja e grupit.'], 405);
}
qta_json_require_staff($pdo);
$data = qta_json_input();
qta_json_require_csrf($data);

$action = (string)($data['action'] ?? '');
$groupId = (int)($data['group_id'] ?? 0);
$force = !empty($data['force']);
$dry = !empty($data['dry_run']);

/** "më 23.10.2026 (e premte)" */
function lg_when(string $iso): string
{
  return qta_sched_on_label($iso);
}

try {
  switch ($action) {
    case 'preview_new': {
      $p = qta_lg_preview_new($pdo, $data['course_id'] ?? null, $data['start_date'] ?? null,
        $data['daily_hours'] ?? null, $data['schedule_mode'] ?? 'calculated', $data['end_date'] ?? null);
      $s = $p['summary'];
      if ($p['schedule_mode'] === 'fixed_range') {
        qta_json_out(['ok' => true, 'schedule_mode' => 'fixed_range',
          'summary' => $s + ['fixed' => $p['fixed_summary'], 'course_name' => $p['course']['name']]]);
      }
      $sundays = 0;
      for ($d = $s['start_date']; $d <= $s['end_date']; $d = qta_sched_next_day($d)) {
        if (qta_sched_is_sunday($d)) $sundays++;
      }
      qta_json_out(['ok' => true, 'summary' => $s + ['sundays_skipped' => $sundays, 'course_name' => $p['course']['name']]]);
    }

    case 'change': {
      if (!$dry) qta_json_require_edit_mode();
      $change = is_array($data['change'] ?? null) ? $data['change'] : [];
      $r = qta_lg_change($pdo, $groupId, $change, [
        'revision' => $data['revision'] ?? null,
        'force' => $force,
        'dry_run' => $dry,
      ]);
      $n = $r['new'];
      $o = $r['old'];
      if ($dry) {
        qta_json_out(['ok' => true, 'dry_run' => true, 'impact' => $r]);
      }
      if ($r['what'] === 'fixed_days') {
        $edited = count($r['edited_dates']);
        $moved = count(array_diff($r['changed_dates'], $r['edited_dates']));
        qta_json_out(['ok' => true, 'revision' => $r['revision'], 'impact' => $r,
          'message' => 'Korrigjimi u ruajt: ' . ($edited === 1 ? 'ndryshoi 1 ditë' : 'ndryshuan ' . $edited . ' ditë')
            . ($moved ? ($moved === 1 ? ', dhe temat u rindanë edhe në 1 ditë tjetër' : ', dhe temat u rindanë edhe në ' . $moved . ' ditë të tjera') : '')
            . '. Periudha mbetet ' . qta_sched_range_label($n['start_date'], $n['end_date']) . '.']);
      }
      if ($r['what'] === 'fixed_range_settings') {
        qta_json_out(['ok' => true, 'revision' => $r['revision'], 'impact' => $r,
          'message' => 'Periudha dhe i gjithë orari u rindërtuan: '
            . qta_sched_range_label($n['start_date'], $n['end_date']) . ', '
            . qta_plural((int)$n['days'], 'ditë mësimi', 'ditë mësimi') . '.']);
      }
      $endText = $n['end_date'] === $o['end_date']
        ? 'Mbarimi mbetet ' . lg_when($n['end_date']) . '.'
        : 'Mbaron tani ' . lg_when($n['end_date']) . '; më parë mbaronte më ' . qta_date($o['end_date']) . '.';
      $lead = [
        'settings' => 'Orari u rillogarit.',
        'rule' => 'Dita u ruajt dhe orari u rillogarit.',
        'refresh' => 'Grupi mori temat e reja të kursit dhe orari u rillogarit.',
      ][$r['what']] ?? 'Orari u rillogarit.';
      qta_json_out(['ok' => true, 'message' => $lead . ' ' . $endText, 'impact' => $r, 'revision' => $r['revision']]);
    }

    case 'rebalance': {
      /* Vetëm llogaritje: asgjë nuk ruhet, prandaj nuk kërkon "Lejo ndryshimet". */
      $g = qta_lg_require($pdo, $groupId);
      if (!qta_lg_is_fixed($g)) {
        throw new QtaUserError('Rishpërndarja vlen për grupet me periudhë të përcaktuar. Ky grup e llogarit orarin nga orët në ditë.');
      }
      $plan = [];
      foreach ((array)($data['plan'] ?? []) as $item) {
        $d = is_array($item) ? (string)($item['d'] ?? '') : '';
        $h = is_array($item) ? qta_parse_int_input($item['h'] ?? null, 0, QTA_DAY_MAX_HOURS) : null;
        if (!qta_sched_is_iso_date($d) || $h === null) {
          throw new QtaUserError('Plani i dërguar nuk është i plotë. Rifresko faqen dhe provo sërish.');
        }
        $plan[$d] = $h;
      }
      $manual = array_values(array_map(static fn($it) => (string)$it['d'], array_filter((array)$data['plan'], static fn($it) => is_array($it) && !empty($it['m']))));
      $rb = qta_sched_rebalance_fixed_range($plan, (int)$g['course_hours'], (string)$g['start_date'], (string)$g['end_date'], $manual);
      $moved = abs((int)$rb['delta']);
      $message = !$rb['changed'] ? 'Plani ka tashmë të gjitha orët: nuk ka asgjë për të rishpërndarë.'
        : ($rb['delta'] >= 0 ? ($moved === 1 ? 'Ora që mungonte u vendos.' : 'U vendosën ' . $moved . ' orët që mungonin.')
                             : ($moved === 1 ? 'Ora e tepërt u hoq.' : 'U hoqën ' . $moved . ' orët e tepërta.'))
          . ($rb['sundays_added'] ? ' U përdor edhe ' . (count($rb['sundays_added']) === 1 ? '1 e diel' : count($rb['sundays_added']) . ' të diela') . ' — kontrolloje.' : '')
          . ' Ruaje korrigjimin kur je gati.';
      qta_json_out(['ok' => true, 'plan' => array_map(static fn($d, $h) => ['d' => $d, 'h' => $h], array_keys($rb['days']), $rb['days']),
        'sundays_added' => $rb['sundays_added'], 'message' => $message]);
    }

    case 'members': {
      qta_json_require_edit_mode();
      $r = qta_lg_set_members($pdo, $groupId, (string)($data['amze_spec'] ?? ''), ['force' => $force]);
      $parts = [];
      if ($r['added']) $parts[] = 'u shtuan ' . $r['added'];
      if ($r['removed']) $parts[] = 'u hoqën ' . $r['removed'];
      qta_json_out(['ok' => true, 'message' => 'Kursantët e grupit u ruajtën: ' . implode(', ', $parts) . '. Grupi ka tani ' . $r['total'] . '/10.', 'result' => $r]);
    }

    case 'delete': {
      qta_json_require_edit_mode();
      $r = qta_lg_delete($pdo, $groupId, ['force' => $force]);
      qta_session_put(['flash_ok'], 'Grupi #' . $groupId . ' u fshi.' . ($r['members'] ? ' Kursantët e tij janë tani pa grup, te "Kursantët".' : ''));
      qta_json_out(['ok' => true, 'redirect' => 'lesson_groups.php']);
    }

    default:
      throw new QtaUserError('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
  }
} catch (Throwable $e) {
  qta_json_fail($e);
}
