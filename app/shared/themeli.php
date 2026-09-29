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

if (!function_exists('qta_month_name')) {
  /** "tetor"; me ucfirst() për tituj: "Tetor 2026". */
  function qta_month_name(int $month): string {
    $names = [1 => 'janar', 'shkurt', 'mars', 'prill', 'maj', 'qershor', 'korrik', 'gusht', 'shtator', 'tetor', 'nëntor', 'dhjetor'];
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
  /**
   * Numri i ditëve nga sot deri në datë (negativ = në të kaluarën).
   * $today ('Y-m-d') zëvendëson datën e sotme, p.sh. kur gjendja llogaritet për një ditë të dhënë.
   */
  function qta_days_until(?string $value, ?string $today = null): ?int {
    $ts = $value ? strtotime(substr($value, 0, 10)) : false;
    if (!$ts) {
      return null;
    }
    $base = strtotime($today ?? date('Y-m-d'));
    return (int)round(($ts - $base) / 86400);
  }
}

if (!function_exists('qta_when_label')) {
  /** "sot", "nesër", "pas 5 ditësh", "dje", "3 ditë më parë". */
  function qta_when_label(?string $value, ?string $today = null): string {
    $n = qta_days_until($value, $today);
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
  /** Rezultati i provimit: vetëm pikët. Sistemi nuk ka "kaloi / nuk kaloi". */
  function qta_score_status($score, ?string $examDate = null): string {
    if ($score !== null && $score !== '') {
      $value = rtrim(rtrim(number_format((float)$score, 2, ',', ''), '0'), ',');
      return qta_status($value . ' pikë', 'neutral', 'bi-clipboard-check');
    }
    if ($examDate) {
      $n = qta_days_until($examDate);
      if ($n !== null && $n >= 0) {
        return qta_status('Provimi ' . qta_when_label($examDate), 'info', 'bi-calendar-event');
      }
      return qta_status('Pret pikët', 'warning', 'bi-hourglass-split');
    }
    return qta_status('Pa provim ende', 'neutral');
  }
}

if (!function_exists('qta_enrollment_status')) {
  /**
   * Gjendja e një regjistrimi (kursant në grup) me fjalë të thjeshta.
   * Pret: start_date, end_date, exam_date, final_score (çdonjëra mund të mungojë).
   * $showScore = false në tabelat që kanë kolonë më vete për pikët: atëherë
   * thuhet vetëm "Përfunduar", që numri të mos përsëritet.
   */
  function qta_enrollment_status(array $row, bool $showScore = true): string {
    $score = $row['final_score'] ?? null;
    if ($score !== null && $score !== '') {
      return $showScore ? qta_score_status($score) : qta_status('Përfunduar', 'neutral', 'bi-check2');
    }
    $exam = (string)($row['exam_date'] ?? $row['my_exam'] ?? '');
    if ($exam !== '') {
      return qta_score_status(null, $exam);
    }
    $today = date('Y-m-d');
    $start = substr((string)($row['start_date'] ?? ''), 0, 10);
    $end   = substr((string)($row['end_date'] ?? ''), 0, 10);
    if ($start !== '' && $start > $today) {
      return qta_status('Nis ' . qta_when_label($start), 'info', 'bi-calendar-event');
    }
    if ($start !== '' && $end !== '' && $today >= $start && $today <= $end) {
      return qta_status('Në mësim', 'accent', 'bi-easel');
    }
    if ($end !== '' && $end < $today) {
      return qta_status('Pret datën e provimit', 'warning');
    }
    return qta_status('Pa grup ende', 'neutral');
  }
}

if (!function_exists('qta_group_state')) {
  /**
   * Gjendja e një grupi për stafin, në këtë radhë: i mbyllur → nis më vonë → në mësim
   * → pret mbylljen. E njëjta në regjistrat, katalogun dhe kalendarin; qta_group_state_sql()
   * (list_filter.php) është e njëjta rregull në SQL, për çipat e listave.
   * Pret is_completed, start_date dhe end_date ('Y-m-d'); fillimi dhe mbarimi janë ditë
   * mësimi, prandaj grupi është "në mësim" edhe në ditën e fundit.
   * @return string closed | upcoming | active | awaiting_close
   */
  function qta_group_state(array $g, ?string $today = null): string {
    $today = $today ?? date('Y-m-d');
    if ((int)($g['is_completed'] ?? 0) === 1) return 'closed';
    if ((string)$g['start_date'] > $today) return 'upcoming';
    if ((string)$g['end_date'] >= $today) return 'active';
    return 'awaiting_close';
  }

  /** Pamja e një gjendjeje: toni i qta_status() dhe ikona. */
  function qta_group_state_look(string $state): array {
    return match ($state) {
      'closed'   => ['tone' => 'success', 'icon' => 'bi-lock-fill'],
      'upcoming' => ['tone' => 'info', 'icon' => 'bi-calendar-event'],
      'active'   => ['tone' => 'accent', 'icon' => 'bi-easel'],
      default    => ['tone' => 'warning', 'icon' => 'bi-hourglass-split'],
    };
  }

  /**
   * Gjendja me fjalë ("Nis pas 3 ditësh", "Në mësim" …), me tonin dhe ikonën e saj.
   * @return array{key:string,label:string,tone:string,icon:string}
   */
  function qta_group_state_meta(array $g, ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    $state = qta_group_state($g, $today);
    $label = match ($state) {
      'closed'   => 'I mbyllur',
      'upcoming' => 'Nis ' . qta_when_label((string)$g['start_date'], $today),
      'active'   => 'Në mësim',
      default    => 'Pret mbylljen',
    };
    return ['key' => $state, 'label' => $label] + qta_group_state_look($state);
  }

  /** Gjendja e grupit si status (fjalë + ikonë), p.sh. në kolonën "Gjendja". */
  function qta_group_status(array $g, ?string $today = null): string {
    $m = qta_group_state_meta($g, $today);
    return qta_status($m['label'], $m['tone'], $m['icon']);
  }
}

if (!function_exists('qta_absolute_url')) {
  /** URL e plotë (me host) — p.sh. për kodin QR të verifikimit. */
  function qta_absolute_url(string $path): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https://' : 'http://') . $host . qta_url($path);
  }
}

if (!function_exists('qta_help_button')) {
  /**
   * Butoni i ndihmës së faqes: vetëm një pikëpyetje (40×40), që nuk zë vend te
   * koka e faqes. Emri "Si funksionon kjo faqe?" është te aria-label dhe te
   * këshilla (data-tip). Hap panelin anësor të udhëzimeve (help.php).
   */
  function qta_help_button(string $label = 'Si funksionon kjo faqe?'): string {
    return '<button class="btn btn-help btn-icon" type="button" data-bs-toggle="offcanvas" data-bs-target="#helpPanel" aria-controls="helpPanel"'
      . ' aria-label="' . h($label) . '" data-tip="' . h($label) . '">'
      . '<i class="bi bi-question-lg" aria-hidden="true"></i></button>';
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
