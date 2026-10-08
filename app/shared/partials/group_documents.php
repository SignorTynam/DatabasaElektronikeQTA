<?php
declare(strict_types=1);

/**
 * group_documents.php — Dokumentet e një grupi, brenda dritares së grupit.
 *
 * Çdo dokument është një kartë me një formular të vogël: çdo buton është një
 * format dhe e nis shkarkimin direkt (POST — tokeni CSRF nuk del në URL —
 * me modal bllokues deri në marrjen e skedarit). Nuk ka dialog të dytë për zgjedhjen e formatit.
 *
 *   require_once __DIR__ . '/../shared/partials/group_documents.php';
 *   echo qta_group_documents($groupId, $CSRF);
 *   echo qta_group_documents($groupId, $CSRF, ['lesson_register' => true]); // grupet me orar
 *
 * "Regjistri i orëve të mësimit" del vetëm kur thirrësi e kërkon shprehimisht:
 * ai ndërtohet nga orari i grupit, që grupet e mëparshme nuk e kanë.
 *
 * Modali "Po përgatitet dokumenti" vjen nga download_generation_toast.php.
 */

if (!function_exists('qta_group_documents')) {
  /** @param array{lesson_register?:bool} $opts */
  function qta_group_documents(int $groupId, string $csrf, array $opts = []): string {
    $docs = [
      [
        'action' => 'download_proces_verbal.php', 'field' => 'f', 'formats' => ['pdf', 'docx', 'xlsx'],
        'icon' => 'bi-file-earmark-check', 'title' => 'Procesverbali i provimit',
        'text' => 'Dokumenti zyrtar me kursantët, datat e provimit dhe pikët.',
      ],
      [
        'action' => 'download_lista_emerore.php', 'field' => 'format', 'formats' => ['pdf', 'doc'],
        'icon' => 'bi-list-ol', 'title' => 'Lista emërore',
        'text' => 'Numri rendor dhe emri i plotë (emër, atësi, mbiemër) i çdo kursanti.',
      ],
    ];
    if (!empty($opts['lesson_register'])) {
      $docs[] = [
        'action' => 'download_regjistri_mesimit.php', 'field' => 'format', 'formats' => ['pdf', 'docx'],
        'icon' => 'bi-calendar-check', 'title' => 'Regjistri i orëve të mësimit',
        'text' => 'Regjistri i printueshëm me datat e mësimit dhe temat e moduleve.',
      ];
    }
    array_push($docs,
      [
        'action' => 'download_praktika_profesionale.php', 'field' => 'format', 'formats' => ['pdf', 'doc'],
        'icon' => 'bi-tools', 'title' => 'Praktika profesionale',
        'text' => 'Dokumenti i praktikës profesionale për kursantët e grupit.',
      ],
      [
        'action' => 'download_rregullat_sigurimi_teknik.php', 'field' => 'format', 'formats' => ['pdf', 'doc'],
        'icon' => 'bi-shield-check', 'title' => 'Rregullat e sigurimit teknik',
        'text' => 'Rregullat e sigurisë në punë që nënshkruajnë kursantët.',
      ]
    );
    $formats = [
      'pdf'  => ['bi-file-earmark-pdf', 'PDF'],
      'doc'  => ['bi-file-earmark-word', 'Word'],
      'docx' => ['bi-file-earmark-word', 'Word'],
      'xlsx' => ['bi-file-earmark-spreadsheet', 'Excel'],
    ];

    $out = '<div class="doc-grid">';
    foreach ($docs as $doc) {
      $out .= '<form class="doc-card" method="post" action="' . h($doc['action']) . '" target="_blank"'
        . ' data-download-toast="Prit derisa dokumenti të jetë gati.">'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="group_id" value="' . $groupId . '">'
        . '<span class="doc-card-icon"><i class="bi ' . h($doc['icon']) . '" aria-hidden="true"></i></span>'
        . '<div class="doc-card-body">'
        . '<h4 class="doc-card-title">' . h($doc['title']) . '</h4>'
        . '<p class="doc-card-text">' . h($doc['text']) . '</p>'
        . '<div class="doc-card-actions">';
      foreach ($doc['formats'] as $fmt) {
        [$icon, $label] = $formats[$fmt];
        $out .= '<button class="btn btn-secondary btn-sm" type="submit" name="' . h($doc['field']) . '" value="' . h($fmt) . '"'
          . ' aria-label="' . h($doc['title'] . ' — ' . $label) . '">'
          . '<i class="bi ' . h($icon) . '" aria-hidden="true"></i>' . h($label) . '</button>';
      }
      $out .= '</div></div></form>';
    }
    return $out . '</div>';
  }
}
