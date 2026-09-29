<?php
declare(strict_types=1);

/**
 * calendar_data.php — Të dhënat e kalendarit të grupeve (JSON, vetëm lexim).
 *
 *   GET ?from=2026-10-01&to=2026-10-31[&legacy=1]   grupet e intervalit (të shumtën 62 ditë)
 *   GET ?group=12                                   detajet e një grupi, kur hapet dritarja
 *
 * Vetëm administrator/editor: seanca dhe roli lexohen nga databaza në çdo kërkesë.
 * Nuk shkruan asgjë, prandaj nuk kërkon tokenin e faqes as "Lejo ndryshimet". Lista e
 * grupeve nuk mban kursantët; emrat dhe amzat vijnë vetëm me detajet e një grupi.
 * Rregullat janë te app/shared/group_calendar.php.
 */

session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/staff_guard.php';
require_once __DIR__ . '/../shared/group_calendar.php';

header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  header('Allow: GET');
  qta_json_out(['ok' => false, 'error' => 'Kjo adresë vetëm lexon kalendarin.'], 405);
}

$pdo = getPDO();
qta_json_require_staff($pdo);
/* Vetëm lexim: seanca lirohet, që kërkesat e njëpasnjëshme të faqes të mos presin njëra-tjetrën. */
session_write_close();

try {
  if (array_key_exists('group', $_GET)) {
    $groupId = is_string($_GET['group']) ? qta_parse_int_input($_GET['group'], 1, PHP_INT_MAX) : null;
    if ($groupId === null) {
      throw new QtaUserError('Grupi nuk u gjet. Ndoshta u fshi.', ['code' => 'not_found']);
    }
    qta_json_out(['ok' => true, 'group' => qta_calendar_group($pdo, $groupId)]);
  }
  $range = qta_calendar_range($_GET['from'] ?? null, $_GET['to'] ?? null);
  qta_json_out(['ok' => true] + qta_calendar_events($pdo, $range['from'], $range['to'], ($_GET['legacy'] ?? '') === '1'));
} catch (Throwable $e) {
  if ($e instanceof QtaUserError && ($e->data['code'] ?? '') === 'not_found') {
    qta_json_out(['ok' => false, 'error' => $e->getMessage(), 'code' => 'not_found'], 404);
  }
  qta_json_fail($e, 'Kalendari nuk u ngarkua për shkak të një gabimi të papritur.');
}
