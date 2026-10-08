<?php
declare(strict_types=1);

/**
 * group_results.php — Pikët sipas moduleve të një grupi (JSON).
 *
 * Veprimet:
 *   sheet  fleta e rezultateve: modulet e grupit, kursantët, pikët e moduleve dhe
 *          rezultati i secilit (lexim; nuk kërkon kyçin e ndryshimeve);
 *   save   ruan njëherësh qelizat e ndryshuara (cells), në një transaksion; kthen
 *          fletën e re. Grupi i mbyllur dhe zëvendësimi i pikëve të regjistrit të
 *          vjetër kërkojnë konfirmim (409 → force = 1).
 *
 * Vetëm administrator/editor, vetëm POST, me tokenin e faqes; ruajtja edhe me
 * ndryshimet të hapura. Rregullat e pikëve janë te app/shared/results.php.
 */

require_once __DIR__ . '/../shared/session.php';
qta_session_boot();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/staff_guard.php';
require_once __DIR__ . '/../shared/results.php';

$pdo = getPDO();
require_once __DIR__ . '/inc/audit_bootstrap.php';
qta_audit_attach($pdo, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  qta_json_out(['ok' => false, 'error' => 'Kjo adresë pranon vetëm kërkesa nga faqja e grupit.'], 405);
}
qta_json_require_staff($pdo);
$data = qta_json_input();
qta_json_require_csrf($data);

$action = (string)($data['action'] ?? '');
$groupId = (int)($data['group_id'] ?? 0);

try {
  if ($groupId <= 0) {
    throw new QtaUserError('Grupi nuk u gjet. Rifresko faqen.', ['code' => 'not_found']);
  }
  if ($action === 'sheet') {
    qta_json_out(['ok' => true, 'sheet' => qta_results_payload(qta_results_sheet($pdo, $groupId))]);
  }
  if ($action === 'save') {
    qta_json_require_edit_mode();
    $res = qta_results_save($pdo, $groupId, $data['cells'] ?? null, ['force' => !empty($data['force'])]);
    qta_json_out([
      'ok' => true,
      'changed' => $res['changed'],
      'students' => $res['students'],
      'message' => $res['message'],
      'sheet' => qta_results_payload($res['sheet']),
    ]);
  }
  throw new QtaUserError('Ky veprim nuk njihet. Rifresko faqen dhe provo sërish.');
} catch (Throwable $e) {
  qta_json_fail($e);
}
