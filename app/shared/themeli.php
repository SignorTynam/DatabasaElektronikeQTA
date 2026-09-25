<?php
declare(strict_types=1);

/**
 * themeli.php — Ndihmësit e prezantimit të sistemit Themeli.
 * Përdoren nga shell-i i panelit dhe nga faqet publike. Vetëm prezantim:
 * asnjë funksion këtu nuk lexon apo shkruan në databazë.
 */

require_once __DIR__ . '/url.php';

if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
  }
}

/* ------------------------------------------------------------------ Datat */

if (!function_exists('qta_date')) {
  /**
   * Data në formatin e vetëm të ndërfaqes: dd.mm.yyyy.
   * Pranon 'Y-m-d', 'Y-m-d H:i:s' ose 'd-m-Y'; kthen $empty kur mungon.
   */
  function qta_date(?string $value, string $empty = '—'): string {
    $value = trim((string)$value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
      return $empty;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
      return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    if (preg_match('/^(\d{2})[-.\/](\d{2})[-.\/](\d{4})$/', $value, $m)) {
      return $m[1] . '.' . $m[2] . '.' . $m[3];
    }
    return $value;
  }
}

if (!function_exists('qta_datetime')) {
  function qta_datetime(?string $value, string $empty = '—'): string {
    $value = trim((string)$value);
    if ($value === '') {
      return $empty;
    }
    $ts = strtotime($value);
    return $ts ? date('d.m.Y, H:i', $ts) : $value;
  }
}

if (!function_exists('qta_month_short')) {
  function qta_month_short(int $month): string {
    $names = [1 => 'jan', 'shk', 'mar', 'pri', 'maj', 'qer', 'kor', 'gus', 'sht', 'tet', 'nën', 'dhj'];
    return $names[$month] ?? '';
  }
}

if (!function_exists('qta_weekday')) {
  function qta_weekday(int $isoDay): string {
    $names = [1 => 'e hënë', 'e martë', 'e mërkurë', 'e enjte', 'e premte', 'e shtunë', 'e diel'];
    return $names[$isoDay] ?? '';
  }
}

if (!function_exists('qta_today_label')) {
  /** p.sh. "e premte, 25 shtator 2026" */
  function qta_today_label(?int $ts = null): string {
    $ts = $ts ?? time();
    $months = [1 => 'janar', 'shkurt', 'mars', 'prill', 'maj', 'qershor', 'korrik', 'gusht', 'shtator', 'tetor', 'nëntor', 'dhjetor'];
    return qta_weekday((int)date('N', $ts)) . ', ' . (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
  }
}

if (!function_exists('qta_ago')) {
  /** Kohë relative e shkurtër: "5 min më parë", "dje", "3 ditë më parë". */
  function qta_ago(?string $value): string {
    $ts = $value ? strtotime($value) : false;
    if (!$ts) {
      return '';
    }
    $d = time() - $ts;
    if ($d < 60) return 'tani';
    if ($d < 3600) return (int)floor($d / 60) . ' min më parë';
    if ($d < 86400) return (int)floor($d / 3600) . ' orë më parë';
    if ($d < 172800) return 'dje';
    if ($d < 30 * 86400) return (int)floor($d / 86400) . ' ditë më parë';
    return date('d.m.Y', $ts);
  }
}

if (!function_exists('qta_days_until')) {
  /** Numri i ditëve nga sot deri në datë (negativ = në të kaluarën). */
  function qta_days_until(?string $value): ?int {
    $ts = $value ? strtotime(substr($value, 0, 10)) : false;
    if (!$ts) {
      return null;
    }
    $today = strtotime(date('Y-m-d'));
    return (int)round(($ts - $today) / 86400);
  }
}

if (!function_exists('qta_when_label')) {
  /** "sot", "nesër", "pas 5 ditësh", "dje", "3 ditë më parë". */
  function qta_when_label(?string $value): string {
    $n = qta_days_until($value);
    if ($n === null) return '';
    if ($n === 0) return 'sot';
    if ($n === 1) return 'nesër';
    if ($n === -1) return 'dje';
    if ($n > 1) return 'pas ' . $n . ' ditësh';
    return abs($n) . ' ditë më parë';
  }
}

/* --------------------------------------------------------------- Tekstet */

if (!function_exists('qta_plural')) {
  function qta_plural(int $n, string $one, string $many): string {
    return number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $one : $many);
  }
}

if (!function_exists('qta_initials')) {
  function qta_initials(?string $name): string {
    $name = trim((string)$name);
    if ($name === '') {
      return 'Q';
    }
    $initials = '';
    foreach (preg_split('/\s+/u', $name) ?: [] as $part) {
      if ($part === '') {
        continue;
      }
      $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
      if (mb_strlen($initials, 'UTF-8') >= 2) {
        break;
      }
    }
    return $initials !== '' ? $initials : 'Q';
  }
}

if (!function_exists('qta_greeting')) {
  function qta_greeting(?int $hour = null): string {
    $hour = $hour ?? (int)date('G');
    return $hour < 12 ? 'Mirëmëngjes' : ($hour < 18 ? 'Mirëdita' : 'Mirëmbrëma');
  }
}

if (!function_exists('qta_full_name')) {
  /** Emër + atësi + mbiemër, pa hapësira të tepërta. */
  function qta_full_name(?string $first, ?string $father = null, ?string $last = null): string {
    return trim(preg_replace('/\s+/u', ' ', trim((string)$first) . ' ' . trim((string)$father) . ' ' . trim((string)$last)) ?? '');
  }
}

/* -------------------------------------------------------------- Komponentë */

if (!function_exists('qta_status')) {
  /**
   * Status me fjalë + ikonë + ngjyrë.
   * $variant: success | warning | danger | info | accent | neutral
   */
  function qta_status(string $label, string $variant = 'neutral', ?string $icon = null): string {
    $icons = [
      'success' => 'bi-check-circle-fill',
      'warning' => 'bi-clock-fill',
      'danger'  => 'bi-x-circle-fill',
      'info'    => 'bi-info-circle-fill',
      'accent'  => 'bi-record-circle-fill',
      'neutral' => 'bi-dash-circle',
    ];
    $icon = $icon ?? ($icons[$variant] ?? 'bi-dash-circle');
    return '<span class="status status-' . h($variant) . '"><i class="bi ' . h($icon) . '" aria-hidden="true"></i>' . h($label) . '</span>';
  }
}

if (!function_exists('qta_score_status')) {
  /** Rezultati i provimit si status i kuptueshëm. Kalon me ≥ 50 pikë. */
  function qta_score_status($score, ?string $examDate = null): string {
    if ($score !== null && $score !== '') {
      $value = rtrim(rtrim(number_format((float)$score, 2, ',', ''), '0'), ',');
      return (float)$score >= 50
        ? qta_status('Kaloi · ' . $value, 'success')
        : qta_status('Nuk kaloi · ' . $value, 'danger');
    }
    if ($examDate) {
      $n = qta_days_until($examDate);
      if ($n !== null && $n >= 0) {
        return qta_status('Provimi ' . qta_when_label($examDate), 'info', 'bi-calendar-event');
      }
      return qta_status('Pret rezultatin', 'warning');
    }
    return qta_status('Pa provim ende', 'neutral');
  }
}

if (!function_exists('qta_help_button')) {
  /** Butoni "Si funksionon?" që hap panelin e ndihmës së faqes. */
  function qta_help_button(string $label = 'Si funksionon?'): string {
    return '<button class="btn btn-ghost" type="button" data-bs-toggle="offcanvas" data-bs-target="#helpPanel" aria-controls="helpPanel">'
      . '<i class="bi bi-question-circle" aria-hidden="true"></i><span>' . h($label) . '</span></button>';
  }
}

if (!function_exists('qta_empty')) {
  /** Gjendje bosh: çfarë mungon dhe çfarë mund të bësh. */
  function qta_empty(string $title, string $text = '', string $icon = 'bi-inbox', string $actions = '', string $class = ''): string {
    return '<div class="empty ' . h($class) . '">'
      . '<span class="empty-icon"><i class="bi ' . h($icon) . '" aria-hidden="true"></i></span>'
      . '<p class="empty-title">' . h($title) . '</p>'
      . ($text !== '' ? '<p class="empty-text">' . h($text) . '</p>' : '')
      . ($actions !== '' ? '<div class="empty-actions">' . $actions . '</div>' : '')
      . '</div>';
  }
}
