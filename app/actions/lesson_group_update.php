<?php
declare(strict_types=1);

/**
 * lesson_group_update.php — Grupet me orar mësimi (JSON).
 *
 * Veprimet:
 *   preview_new   parashikimi i orarit për një grup të ri (pa ruajtur)
 *   change        orari: 'settings' (fillimi, orët në ditë), 'rule' (një ditë e
 *                 veçantë) ose 'refresh' (temat e reja të kursit); me dry_run
 *                 vetëm parashikon
 *   members       kursantët e grupit (numrat e amzës, deri në 10)
 *   delete        fshirja e grupit
 *
 * Të gjitha: vetëm administrator/editor dhe tokeni i faqes. Ruajtjet: edhe me
 * ndryshimet të hapura. Kur një veprim ka pasoja (ditë që kanë kaluar, grup i
 * mbyllur, fshirje), përgjigja kërkon konfirmim dhe ndërfaqja e dërgon me force.
 */

session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/staff_guard.php';
require_once __DIR__ . '/../shared/lesson_groups.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo);

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
      $p = qta_lg_preview_new($pdo, $data['course_id'] ?? null, $data['start_date'] ?? null, $data['daily_hours'] ?? null);
      $s = $p['summary'];
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
      $_SESSION['flash_ok'] = 'Grupi #' . $groupId . ' u fshi.' . ($r['members'] ? ' Kursantët e tij janë tani te "Kursantët pa grup".' : '');
      qta_json_out(['ok' => true, 'redirect' => 'lesson_groups.php']);
    }

    default:
      throw new QtaUserError('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
  }
} catch (Throwable $e) {
  qta_json_fail($e);
}
