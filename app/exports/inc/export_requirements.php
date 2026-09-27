<?php
declare(strict_types=1);

/**
 * export_requirements.php — Shtesat e PHP-së që duhen për dokumentet.
 *
 * Word (.docx) paketohet me ZipArchive (shtesa "zip"). Dompdf nuk vizaton dot
 * logot PNG pa GD (shtesa "gd"). Kur njëra mungon, dokumenti nuk krijohet:
 * përdoruesi merr një mesazh të qartë në vend të një gabimi fatal, dhe log-u
 * tregon saktësisht çfarë duhet aktivizuar te php.ini.
 *
 *   require_once __DIR__ . '/inc/export_requirements.php';
 *   $msg = qta_export_requirements_message('download_proces_verbal', ['docx' => ['zip']][$fmt] ?? []);
 *   if ($msg !== null) qta_fail(500, $msg);
 */

if (!function_exists('qta_export_missing_extensions')) {
  /**
   * @param list<string> $needs 'zip' | 'gd' (ose emri i një shtese tjetër)
   * @return list<string> shtesat që mungojnë
   */
  function qta_export_missing_extensions(array $needs): array
  {
    $missing = [];
    foreach (array_unique($needs) as $ext) {
      $ok = match ($ext) {
        'zip' => class_exists('ZipArchive'),
        'gd' => function_exists('imagecreatefrompng'),
        default => extension_loaded($ext),
      };
      if (!$ok) {
        $missing[] = $ext;
      }
    }
    return $missing;
  }

  /**
   * Mesazhi për përdoruesin kur mungon diçka (pasi e shënon në log), ose null.
   * @param list<string> $needs
   */
  function qta_export_requirements_message(string $export, array $needs): ?string
  {
    $missing = qta_export_missing_extensions($needs);
    if (!$missing) {
      return null;
    }
    error_log('[QTA eksport] ' . $export . ': mungon shtesa e PHP-së "' . implode('", "', $missing)
      . '". Aktivizoje te php.ini (extension=' . implode(', extension=', $missing) . ') dhe rinis serverin.');
    return 'Dokumenti nuk mund të krijohet tani, sepse serverit i mungon një pjesë e nevojshme. Njofto administratorin.';
  }
}
