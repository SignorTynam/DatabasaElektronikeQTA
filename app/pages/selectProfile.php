<?php
declare(strict_types=1);

/**
 * selectProfile.php — Hyrja nuk është më faqe më vete: është një dialog mbi
 * faqet publike (shared/partials/login_dialog.php).
 *
 * Adresa mbetet, sepse këtu çojnë faqet e mbrojtura kur seanca mbaron, dalja nga
 * llogaria, faqet e gabimeve, hyrja e dështuar pa JavaScript dhe lidhjet e ruajtura.
 * Të gjitha hapin kryefaqen me dialogun e hyrjes (?hyr=<roli>). Mesazhi i një
 * hyrjeje të dështuar mbetet në seancë dhe e lexon dialogu.
 */

$asked = $_GET['role'] ?? '';
$role = match (is_string($asked) ? $asked : '') {
  'student'  => 'student',
  'agjencia' => 'agjencia',
  'staff', 'administrator', 'editor' => 'staff',
  default    => '',
};

header('Cache-Control: no-store');
header('Location: index.php?hyr' . ($role !== '' ? '=' . $role : ''), true, 302);
exit;
