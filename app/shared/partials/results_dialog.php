<?php
declare(strict_types=1);

/**
 * results_dialog.php — "Pikët sipas moduleve": dritarja e punës dhe hyrjet e saj te
 * tabelat e grupit (regjistri i vjetër dhe regjistri i kurseve profesionale).
 *
 *   echo qta_results_open_button($gid, $label, $edit);        // koka e seksionit të kursantëve
 *   echo qta_results_cell($gid, $sid, $final, $scored, $required, $who, $label);  // <td> "Pikët"
 *   echo qta_results_dialog($csrf, $edit, $unlockUrl);        // një herë për faqe
 *
 * Dritarja mbushet nga app/assets/js/group-results.js me të dhënat e
 * app/actions/group_results.php; rregullat e pikëve janë te app/shared/results.php.
 * Kolona "Pikët" e tabelave tregon rezultatin zyrtar (final_score) dhe nuk shkruhet më
 * me dorë: hap dritaren te kursanti i atij rreshti.
 */

require_once __DIR__ . '/../themeli.php';
require_once __DIR__ . '/../results.php';

if (!function_exists('qta_results_open_button')) {
  /** "Vendos pikët" (ndryshimet të hapura) ose "Shiko pikët". $label = "Grupi #12 · Microsoft Office". */
  function qta_results_open_button(int $gid, string $label, bool $edit): string
  {
    return '<button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#resultsModal"'
      . ' data-results-group="' . $gid . '" data-results-label="' . h($label) . '">'
      . '<i class="bi bi-table" aria-hidden="true"></i>' . ($edit ? 'Vendos pikët' : 'Shiko pikët') . '</button>';
  }

  /**
   * Qeliza "Pikët" e një kursanti: rezultati zyrtar ose "—", me "3 nga 5 module" kur
   * pikët e moduleve janë nisur por nuk janë plotësuar. Klikimi hap dritaren te ai kursant.
   * @param mixed $final final_score nga baza (tekst DECIMAL ose null)
   */
  function qta_results_cell(int $gid, int $sid, $final, int $scored, int $required, string $who, string $label): string
  {
    $h = qta_score_from_db($final);
    $pending = $h === null && $scored > 0 && $required > 0;
    $sub = $pending ? $scored . ' nga ' . $required . ' module' : '';
    $state = $h !== null ? qta_score_label($h) : ($pending ? $sub : 'ende pa pikë');
    return '<td class="nowrap num-col" data-final-cell data-group="' . $gid . '" data-student="' . $sid . '"'
      . ' data-final="' . h($h === null ? '' : qta_score_db($h)) . '">'
      . '<button type="button" class="rs-open" data-bs-toggle="modal" data-bs-target="#resultsModal"'
      . ' data-results-group="' . $gid . '" data-results-focus="' . $sid . '" data-results-label="' . h($label) . '"'
      . ' aria-label="' . h('Pikët e ' . $who . ': ' . $state . '. Hap pikët sipas moduleve') . '">'
      . '<span class="rs-open-value">' . h(qta_score_label($h)) . '</span>'
      . ($sub !== '' ? '<span class="rs-open-sub">' . h($sub) . '</span>' : '')
      . '</button></td>';
  }

  /**
   * Dritarja (një për faqe). $unlockUrl: adresa që hap ndryshimet dhe e rihap dritaren,
   * me {gid} për numrin e grupit, p.sh. "groups.php?edit=1&group={gid}&results={gid}".
   */
  function qta_results_dialog(string $csrf, bool $edit, string $unlockUrl): string
  {
    $cfg = json_encode(['csrf' => $csrf, 'endpoint' => 'group_results.php', 'edit' => $edit, 'unlock' => $unlockUrl],
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ob_start();
    ?>
<div class="modal fade modal-sheet" id="resultsModal" tabindex="-1" aria-labelledby="rsTitle" aria-describedby="rsMeta" aria-hidden="true" data-results="<?= h((string)$cfg) ?>">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
    <div class="modal-content">
      <div class="modal-header">
        <div class="rs-head">
          <span class="eyebrow mb-0" data-rs-eyebrow></span>
          <h2 class="modal-title" id="rsTitle">Pikët sipas moduleve</h2>
          <p class="modal-meta" id="rsMeta" data-rs-meta></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="rs-notices" data-rs-notices hidden></div>
      <div class="modal-body rs-body" data-rs-body></div>
      <div class="modal-footer rs-foot">
        <p class="rs-status" data-rs-status role="status" aria-live="polite"></p>
        <div class="rs-actions">
          <?php if ($edit): ?>
            <button type="button" class="btn btn-ghost" data-rs-revert hidden><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Anulo ndryshimet</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
            <button type="button" class="btn btn-primary" data-rs-save disabled><i class="bi bi-check-lg" aria-hidden="true"></i>Ruaj pikët</button>
          <?php else: ?>
            <a class="btn btn-secondary" data-rs-unlock href="#"><i class="bi bi-unlock" aria-hidden="true"></i>Lejo ndryshimet</a>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
    <?php
    return (string)ob_get_clean();
  }
}
