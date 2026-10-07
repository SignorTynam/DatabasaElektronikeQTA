<?php
declare(strict_types=1);

/**
 * day_plan.php — Plani i ditëve si kalendar mujor (periudhë e përcaktuar).
 *
 * Çdo muaj i periudhës [S, E] është një tabelë e hënë–e diel. Çdo datë e
 * periudhës tregon orët e saj (ose "—" kur s'ka mësim), fillimin dhe mbarimin
 * si kufij të periudhës, dhe të dielat me mësim si shenjë kontrolli. Datat e muajit
 * jashtë periudhës shfaqen të zbehura, vetëm për orientim.
 *
 * Me $opts['editable'] tabela merr role="grid" dhe çdo datë bëhet qelizë e
 * fokusueshme; day-plan.js shton redaktimin (klik ose Enter hap zgjedhjen e
 * orëve, shifrat 0–8 i vendosin menjëherë, shigjetat lëvizin mes datave).
 *
 *   echo qta_render_day_plan($dayHours, $start, $end, [
 *     'id' => 'cvPlan', 'editable' => true, 'manual' => [date => true], 'notes' => [date => 'tekst'],
 *     'label' => 'Propozimi: orët e çdo date', 'today' => '2026-09-28',
 *   ]);
 */

require_once __DIR__ . '/../themeli.php';
require_once __DIR__ . '/../schedule_fixed.php';

if (!function_exists('qta_render_day_plan')) {
  /** Përshkrimi i plotë i një date për lexuesit e ekranit dhe për titullin e redaktimit. */
  function qta_day_plan_label(string $date, int $hours, bool $isStart, bool $isEnd, bool $manual, string $note): string
  {
    $parts = [qta_sched_day_label($date) . ': ' . ($hours > 0 ? qta_hours_label($hours) : 'pa mësim')];
    if ($isStart) $parts[] = 'fillimi i periudhës';
    if ($isEnd) $parts[] = 'mbarimi i periudhës';
    if ($hours > 0 && !$isStart && !$isEnd && qta_sched_is_sunday($date)) $parts[] = 'e diel me mësim, kontrolloje';
    if ($manual) $parts[] = 'ndryshuar me dorë';
    if ($note !== '') $parts[] = 'shënim: ' . $note;
    return implode('. ', $parts);
  }

  /**
   * @param array<string,int>    $days  orët e çdo date të [S, E]
   * @param array<string,mixed>  $opts
   */
  function qta_render_day_plan(array $days, string $start, string $end, array $opts = []): string
  {
    $editable = !empty($opts['editable']);
    $manual = $opts['manual'] ?? [];
    $notes = $opts['notes'] ?? [];
    $id = (string)($opts['id'] ?? 'dayPlan');
    $today = (string)($opts['today'] ?? '');
    $months = [1 => 'Janar', 'Shkurt', 'Mars', 'Prill', 'Maj', 'Qershor', 'Korrik', 'Gusht', 'Shtator', 'Tetor', 'Nëntor', 'Dhjetor'];
    $weekdays = ['Hën' => 'e hënë', 'Mar' => 'e martë', 'Mër' => 'e mërkurë', 'Enj' => 'e enjte', 'Pre' => 'e premte', 'Sht' => 'e shtunë', 'Die' => 'e diel'];
    $first = true;

    ob_start();
    echo '<div class="dplan' . ($editable ? ' is-editable' : '') . '" id="' . h($id) . '" data-dplan data-start="' . h($start) . '" data-end="' . h($end) . '"'
      . ' data-max="' . QTA_DAY_MAX_HOURS . '">';
    echo '<div class="dplan-months">';
    $month = qta_sched_date($start)->modify('first day of this month');
    $last = qta_sched_date($end);
    while ($month <= $last) {
      $key = $month->format('Y-m');
      $titleId = $id . '-m-' . $key;
      echo '<section class="dplan-month">';
      echo '<h3 class="dplan-month-title" id="' . h($titleId) . '">' . h($months[(int)$month->format('n')] . ' ' . $month->format('Y')) . '</h3>';
      echo '<table class="dplan-grid"' . ($editable ? ' role="grid"' : '') . ' aria-labelledby="' . h($titleId) . '">';
      echo '<thead><tr>';
      foreach ($weekdays as $short => $long) {
        echo '<th scope="col"><abbr title="' . h($long) . '">' . h($short) . '</abbr></th>';
      }
      echo '</tr></thead><tbody><tr>';
      $pad = (int)$month->format('N') - 1;
      echo str_repeat('<td class="dplan-pad"></td>', $pad);
      $col = $pad;
      $daysInMonth = (int)$month->format('t');
      for ($dn = 1; $dn <= $daysInMonth; $dn++) {
        if ($col === 7) {
          echo '</tr><tr>';
          $col = 0;
        }
        $date = $key . '-' . str_pad((string)$dn, 2, '0', STR_PAD_LEFT);
        $col++;
        if ($date < $start || $date > $end) {
          echo '<td class="dplan-out"><span aria-hidden="true">' . $dn . '</span></td>';
          continue;
        }
        $hours = (int)($days[$date] ?? 0);
        $isStart = $date === $start;
        $isEnd = $date === $end;
        $isSunday = qta_sched_is_sunday($date);
        $isManual = !empty($manual[$date]);
        $note = (string)($notes[$date] ?? '');
        $cls = 'dplan-day ' . ($hours > 0 ? 'is-on' : 'is-off')
          . ($isStart || $isEnd ? ' is-bound' : '')
          . ($isSunday ? ' is-sun' : '')
          . ($isSunday && $hours > 0 && !$isStart && !$isEnd ? ' is-warn' : '')
          . ($isManual ? ' is-manual' : '')
          . ($note !== '' ? ' has-note' : '')
          . ($date === $today ? ' is-today' : '');
        $label = qta_day_plan_label($date, $hours, $isStart, $isEnd, $isManual, $note);
        $attrs = ' data-date="' . h($date) . '" data-hours="' . $hours . '"'
          . ($isManual ? ' data-manual="1"' : '') . ($note !== '' ? ' data-note="' . h($note) . '"' : '')
          . ($isStart ? ' data-bound="start"' : ($isEnd ? ' data-bound="end"' : ''));
        if ($editable) {
          $attrs .= ' role="gridcell" tabindex="' . ($first ? '0' : '-1') . '" aria-haspopup="dialog" aria-label="' . h($label) . '"';
          $first = false;
        }
        echo '<td class="' . $cls . '"' . $attrs . ($date === $today ? ' aria-current="date"' : '') . '>';
        echo '<span class="dplan-num" aria-hidden="true">' . $dn . '</span>';
        echo '<span class="dplan-val" aria-hidden="true">' . ($hours > 0 ? $hours . '<span class="dplan-unit"> orë</span>' : '—') . '</span>';
        echo '<span class="dplan-marks" aria-hidden="true">'
          . ($isSunday && $hours > 0 && !$isStart && !$isEnd ? '<i class="bi bi-exclamation-triangle-fill"></i>' : '')
          . ($note !== '' ? '<i class="bi bi-chat-left-text"></i>' : '')
          . '</span>';
        if (!$editable) {
          echo '<span class="visually-hidden">' . h($label) . '</span>';
        }
        echo '</td>';
      }
      echo str_repeat('<td class="dplan-pad"></td>', (7 - $col) % 7);
      echo '</tr></tbody></table></section>';
      $month = $month->modify('first day of next month');
    }
    echo '</div>';

    echo '<ul class="dplan-legend" aria-label="Shenjat e kalendarit">'
      . '<li><span class="dplan-swatch is-on" aria-hidden="true"></span>Ditë mësimi, me orët</li>'
      . '<li><span class="dplan-swatch is-off" aria-hidden="true"></span>Pa mësim</li>'
      . '<li><i class="bi bi-calendar-range" aria-hidden="true"></i>Fillimi dhe mbarimi i periudhës</li>'
      . '<li><i class="bi bi-exclamation-triangle-fill is-warn" aria-hidden="true"></i>E diel me mësim — kontrolloje</li>'
      . ($editable ? '<li><span class="dplan-swatch is-manual" aria-hidden="true"></span>Ndryshuar me dorë</li>' : '')
      . '</ul>';

    if ($editable) {
      /* Zgjedhja e orëve të një date: hapet mbi datën (në celular, nga poshtë). */
      echo '<div class="dplan-editor" role="dialog" aria-modal="false" aria-labelledby="' . h($id) . '-ed-title" hidden data-dplan-editor>'
        . '<div class="dplan-editor-head"><p class="dplan-editor-title" id="' . h($id) . '-ed-title" data-ed-title></p>'
        . '<button type="button" class="btn-close" data-ed-close aria-label="Mbyll"></button></div>'
        . '<p class="dplan-editor-hint" data-ed-hint hidden></p>'
        . '<div class="dplan-opts" role="radiogroup" aria-label="Orët e mësimit këtë ditë">'
        . '<button type="button" class="dplan-opt is-none" role="radio" aria-checked="false" data-h="0">Pa mësim</button>';
      for ($i = 1; $i <= QTA_DAY_MAX_HOURS; $i++) {
        echo '<button type="button" class="dplan-opt" role="radio" aria-checked="false" data-h="' . $i . '" aria-label="' . h(qta_hours_label($i)) . '">' . $i . '</button>';
      }
      echo '</div>'
        . '<label class="dplan-editor-label" for="' . h($id) . '-ed-note">Shënim <span class="optional">(opsional)</span></label>'
        . '<input class="form-control form-control-sm" id="' . h($id) . '-ed-note" type="text" maxlength="160" autocomplete="off" placeholder="p.sh. Festë, ose mësim zëvendësues" data-ed-note>'
        . '<p class="dplan-editor-keys">Shifrat <kbd>0</kbd>–<kbd>' . QTA_DAY_MAX_HOURS . '</kbd> vendosin orët edhe pa e hapur këtë dritare.</p>'
        . '</div>';
      echo '<p class="visually-hidden" aria-live="polite" data-dplan-live></p>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }
}
