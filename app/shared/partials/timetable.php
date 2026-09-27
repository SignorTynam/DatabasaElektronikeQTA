<?php
declare(strict_types=1);

/**
 * timetable.php — Orari i mësimit ditë pas dite (grupet me orar).
 *
 * Çdo datë nga fillimi te mbarimi është një rresht: ditët e mësimit me temat e
 * tyre (të grupuara sipas modulit), ditët pa mësim (të dielat, ditët e shënuara
 * pa mësim) si rreshta të ngushtë. Një temë që vazhdon në ditën tjetër tregon
 * cilat orë të saj zhvillohen: "ora 1 nga 2".
 *
 *   echo qta_render_timetable($annotatedDays, $rules, $group, $edit, $today);
 */

require_once __DIR__ . '/../themeli.php';
require_once __DIR__ . '/../schedule.php';

if (!function_exists('qta_render_timetable')) {
  /**
   * @param array<int,array<string,mixed>> $days   nga qta_sched_annotate()
   * @param array<string,array{hours:?int,note:?string}> $rules
   * @param array<string,mixed> $g   grupi (start_date, end_date, daily_hours)
   */
  function qta_render_timetable(array $days, array $rules, array $g, bool $edit, string $today): string
  {
    if (!$days) {
      return qta_empty('Orari është bosh', 'Nuk u gjet asnjë ditë mësimi për këtë grup.', 'bi-calendar-x');
    }
    $byDate = [];
    foreach ($days as $d) $byDate[(string)$d['date']] = $d;
    $start = (string)$g['start_date'];
    $end = (string)$g['end_date'];
    $daily = (int)$g['daily_hours'];
    $months = [1 => 'jan', 'shk', 'mar', 'pri', 'maj', 'qer', 'kor', 'gus', 'sht', 'tet', 'nën', 'dhj'];

    ob_start();
    echo '<ol class="timetable" aria-label="Orari i mësimit, ditë pas dite">';
    $date = $start;
    $guard = 0;
    while ($date <= $end && ++$guard <= QTA_SCHEDULE_MAX_CALENDAR_DAYS) {
      $dt = qta_sched_date($date);
      $label = qta_sched_day_label($date);
      $rule = $rules[$date] ?? null;
      $isToday = $date === $today;
      $weekStart = (int)$dt->format('N') === 1 && $date !== $start;
      $cls = 'tt-day' . ($isToday ? ' is-today' : '') . ($weekStart ? ' is-week' : '');
      $dateBlock = '<span class="tt-date" aria-hidden="true"><b>' . (int)$dt->format('j') . '</b><span>' . h($months[(int)$dt->format('n')]) . '</span></span>';

      if (!isset($byDate[$date])) {
        /* Ditë pa mësim brenda orarit */
        /* Dita e javës është te titulli; këtu thuhet vetëm se nuk ka mësim (dhe pse, kur ka shënim). */
        $why = 'Pa mësim' . ($rule !== null && !empty($rule['note']) ? ' — ' . $rule['note'] : '');
        echo '<li class="' . $cls . ' is-off" id="dita-' . h($date) . '"' . ($isToday ? ' aria-current="date"' : '') . '>';
        echo $dateBlock;
        echo '<div class="tt-main"><p class="tt-head"><span class="tt-title">' . h(ucfirst($label)) . '</span>'
          . '<span class="tt-off">' . h($why) . '</span></p></div>';
        if ($edit) {
          $btn = qta_sched_is_sunday($date) && $rule === null ? 'Shto mësim këtë ditë' : 'Ndrysho ditën';
          echo '<div class="tt-actions"><button class="btn btn-ghost btn-sm" type="button" data-lg-day="' . h($date) . '"'
            . ' aria-label="' . h($btn . ': ' . $label) . '"><i class="bi bi-calendar-plus" aria-hidden="true"></i>' . h($btn) . '</button></div>';
        }
        echo '</li>';
        $date = qta_sched_next_day($date);
        continue;
      }

      $d = $byDate[$date];
      $hours = (int)$d['hours'];
      $capacity = qta_sched_day_hours($date, $daily, array_map(static fn($r) => $r['hours'], $rules));
      $flags = [];
      if ($rule !== null) {
        $flags[] = qta_sched_is_sunday($date)
          ? qta_status('E diel me mësim', 'info', 'bi-calendar-check')
          : qta_status('Ditë e veçantë', 'info', 'bi-calendar-event');
      }
      if ($hours < $capacity) {
        $flags[] = '<span class="tt-note">dita e fundit: vetëm orët që mbeten</span>';
      }
      echo '<li class="' . $cls . '" id="dita-' . h($date) . '"' . ($isToday ? ' aria-current="date"' : '') . '>';
      echo $dateBlock;
      echo '<div class="tt-main">';
      echo '<h3 class="tt-head"><span class="tt-title">Dita ' . (int)$d['seq'] . ' · ' . h($label) . '</span>'
        . '<span class="tt-hours">' . h(qta_hours_label($hours)) . '</span>'
        . ($isToday ? qta_status('Sot', 'accent', 'bi-geo-alt-fill') : '') . implode('', $flags) . '</h3>';
      if ($rule !== null && !empty($rule['note'])) {
        echo '<p class="tt-rule-note">' . h((string)$rule['note']) . '</p>';
      }

      /* Pjesët e ditës, të grupuara sipas modulit */
      $runs = [];
      foreach ($d['slots'] as $s) {
        $k = count($runs) - 1;
        if ($k < 0 || $runs[$k]['module_seq'] !== (int)$s['module_seq']) {
          $runs[] = ['module_seq' => (int)$s['module_seq'], 'title' => (string)$s['module_title'], 'slots' => []];
          $k++;
        }
        $runs[$k]['slots'][] = $s;
      }
      foreach ($runs as $run) {
        $starts = (bool)$run['slots'][0]['module_starts'];
        $ends = (bool)$run['slots'][count($run['slots']) - 1]['module_ends'];
        echo '<div class="tt-module">';
        echo '<p class="tt-module-name">Moduli ' . (int)$run['module_seq'] . ' · ' . h($run['title'])
          . ($starts ? ' <span class="tt-flag">fillon</span>' : '') . ($ends ? ' <span class="tt-flag">mbaron</span>' : '') . '</p>';
        echo '<ul class="tt-slots">';
        foreach ($run['slots'] as $s) {
          $part = '';
          if (!empty($s['is_split'])) {
            $range = (int)$s['from'] === (int)$s['to'] ? 'ora ' . (int)$s['from'] : 'orët ' . (int)$s['from'] . '–' . (int)$s['to'];
            $cont = (int)$s['to'] < (int)$s['topic_hours'] ? ' · vazhdon në ditën tjetër' : ' · vazhdim';
            $part = '<span class="tt-part">' . h($range . ' nga ' . (int)$s['topic_hours'] . $cont) . '</span>';
          }
          echo '<li class="tt-slot"><span class="tt-topic"><span class="tt-num">' . (int)$s['topic_seq'] . '.</span> ' . h((string)$s['topic_title']) . '</span>'
            . '<span class="tt-slot-hours">' . h(qta_hours_label((int)$s['hours'])) . '</span>' . $part . '</li>';
        }
        echo '</ul></div>';
      }
      echo '</div>';
      if ($edit) {
        echo '<div class="tt-actions"><button class="btn btn-ghost btn-sm" type="button" data-lg-day="' . h($date) . '"'
          . ' aria-label="' . h('Ndrysho ditën: ' . $label) . '"><i class="bi bi-pencil" aria-hidden="true"></i>Ndrysho ditën</button></div>';
      }
      echo '</li>';
      $date = qta_sched_next_day($date);
    }
    echo '</ol>';
    return (string)ob_get_clean();
  }

  /** Përshkrimi me fjalë i një rregulli dite: "4 orë", "Pa mësim", "Orari i zakonshëm (5 orë)". */
  function qta_rule_label(?int $hours, int $daily): string
  {
    if ($hours === null) return 'Mësim me orarin e zakonshëm (' . qta_hours_label($daily) . ')';
    if ($hours === 0) return 'Pa mësim';
    return qta_hours_label($hours);
  }
}
