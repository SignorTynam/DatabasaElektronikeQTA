<?php
declare(strict_types=1);

/**
 * group_conversion_update.php — Konvertimi i grupeve të regjistrit të vjetër (JSON).
 *
 * Veprimet:
 *   propose     propozimi automatik nga e para ("Rikthe propozimin") — nuk ruan
 *   rebalance   rishpërndarja e orëve të planit të dërguar — nuk ruan
 *   save        ruan draftin (e krijon herën e parë); grupi nuk preket
 *   refresh     rifreskon draftin pasi ndryshuan të dhënat burimore
 *   convert     konvertimi atomik i grupit
 *
 * Të gjitha: vetëm administrator/editor, POST dhe tokeni i faqes. Ato që ruajnë:
 * edhe me ndryshimet të hapura. Ruajtja dhe konvertimi kontrollojnë versionin e
 * draftit ('revision') dhe gjurmën e të dhënave që pa faqja ('source'); serveri
 * rikontrollon gjithçka, pavarësisht nga ç'tregon faqja.
 */

require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/staff_guard.php';
require_once __DIR__ . '/../shared/legacy_conversion.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  qta_json_out(['ok' => false, 'error' => 'Kjo adresë pranon vetëm ruajtje nga faqja e konvertimit.'], 405);
}
$me = qta_json_require_staff($pdo);
$data = qta_json_input();
qta_json_require_csrf($data);

$action = (string)($data['action'] ?? '');
$groupId = (int)($data['group_id'] ?? 0);
$userId = (int)$me['id'];

/** "e diel, 04.10.2026 dhe e diel, 11.10.2026" */
function cv_days_phrase(array $dates): string
{
  $labels = array_map('qta_sched_day_label', $dates);
  return count($labels) > 1 ? implode(', ', array_slice($labels, 0, -1)) . ' dhe ' . end($labels) : (string)($labels[0] ?? '');
}

try {
  if ($groupId <= 0) {
    throw new QtaUserError('Grupi nuk u gjet. Kthehu te lista e konvertimit.');
  }
  switch ($action) {
    case 'propose': {
      $p = qta_conv_propose($pdo, $groupId);
      $n = count($p['sundays']);
      qta_json_out(['ok' => true, 'plan' => qta_conv_plan_for_client(['days' => $p['days'], 'manual' => [], 'notes' => []]),
        'message' => 'Propozimi fillestar u rikthye: ' . $p['hours'] . ' orë në ' . $p['teaching_days'] . ' ditë mësimi.'
          . ($n ? ' Përdor edhe ' . ($n === 1 ? '1 të diel' : $n . ' të diela') . ' (' . cv_days_phrase($p['sundays']) . ') — kontrolloje.' : '')]);
    }

    case 'rebalance': {
      $r = qta_conv_rebalance($pdo, $groupId, $data['plan'] ?? null);
      if (!$r['changed']) {
        $message = 'Plani ka tashmë të gjitha orët: nuk ka asgjë për të rishpërndarë.';
      } else {
        $moved = abs((int)$r['delta']);
        $message = $r['delta'] >= 0
          ? ($moved === 1 ? 'Ora që mungonte u vendos' : 'U vendosën ' . $moved . ' orët që mungonin') . ' brenda periudhës.'
          : ($moved === 1 ? 'Ora e tepërt u hoq.' : 'U hoqën ' . $moved . ' orët e tepërta.');
        if ($r['sundays_added']) {
          $n = count($r['sundays_added']);
          $message .= ' Për t\'i vendosur të gjitha u përdor edhe ' . ($n === 1 ? '1 e diel' : $n . ' të diela') . ' (' . cv_days_phrase($r['sundays_added']) . '). Kontrolloje.';
        }
        if ($r['manual_changed']) {
          $n = count($r['manual_changed']);
          $message .= $n === 1
            ? ' U desh të ndryshohej edhe 1 ditë që e kishe caktuar vetë.'
            : ' U desh të ndryshoheshin edhe ' . $n . ' ditë që i kishe caktuar vetë.';
        }
      }
      qta_json_out(['ok' => true, 'plan' => array_map(static fn($d, $h) => ['d' => $d, 'h' => $h], array_keys($r['days']), $r['days']),
        'changed' => $r['changed'], 'sundays_added' => $r['sundays_added'], 'manual_changed' => $r['manual_changed'], 'message' => $message]);
    }

    case 'save': {
      qta_json_require_edit_mode();
      $r = qta_conv_save($pdo, $groupId, $data['plan'] ?? null, $data['revision'] ?? null, $data['source'] ?? null, $userId);
      qta_json_out(['ok' => true, 'revision' => $r['revision'], 'summary' => $r['summary'],
        'message' => $r['unchanged'] ? 'Drafti është i ruajtur; s\'kishte ndryshime të reja.' : 'Drafti u ruajt.']);
    }

    case 'refresh': {
      qta_json_require_edit_mode();
      $r = qta_conv_refresh($pdo, $groupId, $data['revision'] ?? null, $userId);
      qta_session_put(['flash_ok'], $r['discarded']
        ? 'Të dhënat e reja nuk i mbajnë orët e kursit brenda periudhës historike, prandaj drafti u hoq. Shiko arsyen më poshtë.'
        : ($r['kept'] ? 'Të dhënat u rifreskuan. Plani yt mbeti i njëjtë — kontrolloje sërish para konvertimit.'
                      : 'Periudha e grupit ndryshoi, prandaj plani filloi nga propozimi automatik. Kontrolloje para konvertimit.'));
      qta_json_out(['ok' => true, 'reload' => true]);
    }

    case 'convert': {
      qta_json_require_edit_mode();
      $r = qta_conv_apply($pdo, $groupId, $data['revision'] ?? null, $data['source'] ?? null, $userId);
      qta_session_put(['flash_ok'], 'Grupi #' . $groupId . ' u konvertua. Tani është te "Regjistri i kurseve profesionale" me orarin e miratuar: '
        . $r['hours'] . ' orë në ' . $r['teaching_days'] . ' ditë mësimi, ' . qta_sched_range_label($r['start_date'], $r['end_date']) . '.');
      qta_json_out(['ok' => true, 'redirect' => 'lesson_group.php?id=' . $groupId]);
    }

    default:
      throw new QtaUserError('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
  }
} catch (Throwable $e) {
  qta_json_fail($e);
}
