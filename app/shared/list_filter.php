<?php
declare(strict_types=1);

/**
 * list_filter.php — Kërkimi dhe filtrat e listave (Themeli).
 *
 * Çdo listë ka një mënyrë të vetme filtrimi:
 *   - një fushë kërkimi që gjen në të gjitha fushat kuptimplota të rreshtit;
 *   - çipa me gjendje të strukturuara (?status=no_group), kurrë tekst i dukshëm;
 *   - filtra të rrallë pas butonit "Filtra" (p.sh. arsimi, kursi, periudha).
 * Serveri vendos gjithmonë cilat rreshta përputhen, për të gjithë listën (jo
 * vetëm faqen që shihet). app.js e rifreskon listën ndërsa shkruan, pa
 * ringarkuar faqen; pa JavaScript formulari punon si GET i zakonshëm.
 */

require_once __DIR__ . '/themeli.php';

const QTA_SEARCH_MAX_TOKENS = 8;

if (!function_exists('qta_search_tokens')) {
  /**
   * "  Arben   Agim Hoxha " → ['Arben', 'Agim', 'Hoxha'].
   * Hapësirat e tepërta dhe thonjëzat hiqen; fjalët e përsëritura numërohen një herë.
   */
  function qta_search_tokens(?string $q): array
  {
    $q = trim(preg_replace('/\s+/u', ' ', (string)$q) ?? '');
    if ($q === '') {
      return [];
    }
    $out = [];
    foreach (explode(' ', $q) as $t) {
      /* preg me /u: trim() punon me bajte dhe do të priste gjysmën e "ë" (C3 AB, si "«"). */
      $t = preg_replace('/^[\s"\'“”„«»,;]+|[\s"\'“”„«»,;]+$/u', '', $t) ?? '';
      if ($t === '') {
        continue;
      }
      $t = mb_substr($t, 0, 64, 'UTF-8');
      $key = mb_strtolower($t, 'UTF-8');
      if (!isset($out[$key])) {
        $out[$key] = $t;
      }
      if (count($out) >= QTA_SEARCH_MAX_TOKENS) {
        break;
      }
    }
    return array_values($out);
  }
}

if (!function_exists('qta_search_q')) {
  /** Teksti i kërkimit siç ruhet në adresë: pa hapësira të tepërta. */
  function qta_search_q($raw): string
  {
    return is_string($raw) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $raw) ?? ''), 0, 200, 'UTF-8') : '';
  }
}

if (!function_exists('qta_search_phrases')) {
  /**
   * Fjalët e gjendjes brenda kërkimit ("pa grup", "gati për grup") bëhen filtra të
   * vërtetë: hiqen nga teksti dhe kthehen si çelësa. "pa grup Tiranë" → ['no_group'],
   * dhe mbetet "Tiranë". Pa dallim shkronjash dhe pa shenja (ë = e).
   * @param array<string,string> $map  frazë → çelës (fraza të shkruara pa shenja)
   */
  function qta_search_phrases(string &$q, array $map): array
  {
    $found = [];
    $plain = ' ' . qta_search_fold($q) . ' ';
    foreach ($map as $phrase => $key) {
      $needle = ' ' . $phrase . ' ';
      if (($pos = mb_strpos($plain, $needle, 0, 'UTF-8')) === false) {
        continue;
      }
      $found[] = $key;
      /* Pozicionet në tekstin e palosur përputhen me origjinalin (një shkronjë = një shkronjë). */
      /* $plain ka një hapësirë më shumë në fillim: fraza nis te $q[$pos]. */
      $len = mb_strlen($phrase, 'UTF-8');
      $q = mb_substr($q, 0, $pos, 'UTF-8') . ' ' . mb_substr($q, $pos + $len, null, 'UTF-8');
      $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
      $plain = ' ' . qta_search_fold($q) . ' ';
    }
    return array_values(array_unique($found));
  }
}

if (!function_exists('qta_like')) {
  /** Vlera për LIKE '%…%', me % dhe _ të mbrojtura. */
  function qta_like(string $t): string
  {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t) . '%';
  }
}

if (!function_exists('qta_search_sql')) {
  /**
   * Kushti SQL i kërkimit me fjalë. Çdo fjalë duhet të gjendet në të paktën një
   * fushë të rreshtit, por fjalët mund të jenë në fusha të ndryshme: kështu
   * "Arben Agim Hoxha" gjen emrin e plotë dhe "1001 Tirane" gjen amzën 1001 me
   * vendlindje Tiranë. Krahasimi ndjek renditjen e bazës (utf8mb4_general_ci):
   * pa dallim shkronjash të mëdha/vogla, "e" = "ë" dhe "c" = "ç".
   *
   * $opts
   *   'digits' => [shprehje]        fjalët me ≥ 6 shifra krahasohen edhe pa hapësira,
   *                                 viza e pika (telefoni "069 100 1000" = "0691001000")
   *   'ids'    => [shprehje]        numër i plotë i saktë ("#12" ose "12" → grupi 12)
   *   'exists' => [[sql, [fusha]]]  nënpyetje ku {cond} zëvendësohet me kushtin e fjalës
   *
   * Emrat e parametrave janë unikë: PDO pa emulim nuk lejon të njëjtin emër dy herë.
   * Kthen '' kur nuk ka fjalë.
   */
  function qta_search_sql(array $tokens, array $fields, array &$params, string $prefix = 'sq', array $opts = []): string
  {
    $and = [];
    foreach (array_values($tokens) as $i => $tok) {
      $n = 0;
      $bind = static function ($value) use (&$params, &$n, $prefix, $i): string {
        $name = ':' . $prefix . $i . '_' . $n++;
        $params[$name] = $value;
        return $name;
      };
      $like = qta_like($tok);
      $or = [];
      foreach ($fields as $f) {
        $or[] = $f . ' LIKE ' . $bind($like);
      }
      $digits = preg_replace('/[\s\-+.\/()]/', '', $tok) ?? '';
      if (strlen($digits) >= 6 && ctype_digit($digits)) {
        foreach ($opts['digits'] ?? [] as $f) {
          $or[] = $f . ' LIKE ' . $bind('%' . $digits . '%');
          if ($digits[0] === '0') {
            $or[] = $f . ' LIKE ' . $bind('%' . ltrim($digits, '0') . '%');
          }
        }
      }
      $id = ltrim($tok, '#');
      if ($id !== '' && strlen($id) <= 9 && ctype_digit($id)) {
        foreach ($opts['ids'] ?? [] as $f) {
          $or[] = $f . ' = ' . $bind((int)$id);
        }
      }
      foreach ($opts['exists'] ?? [] as $sub) {
        [$sql, $subFields] = $sub;
        $inner = [];
        foreach ($subFields as $f) {
          $inner[] = $f . ' LIKE ' . $bind($like);
        }
        $or[] = str_replace('{cond}', implode(' OR ', $inner), $sql);
      }
      $and[] = '(' . implode(' OR ', $or) . ')';
    }
    return implode(' AND ', $and);
  }
}

if (!function_exists('qta_phone_digits_sql')) {
  /** Telefoni vetëm me shifra, për krahasimin "0691001000" = "+355 69 100 1000". */
  function qta_phone_digits_sql(string $col): string
  {
    $expr = "COALESCE($col, '')";
    foreach ([' ', '-', '+', '.', '/', '(', ')'] as $ch) {
      $expr = "REPLACE($expr, '$ch', '')";
    }
    return $expr;
  }
}

if (!function_exists('qta_search_fold')) {
  /** Tekst për krahasim në PHP: shkronja të vogla, pa shenja ("Tiranë" → "tirane"). */
  function qta_search_fold(string $s): string
  {
    $s = mb_strtolower($s, 'UTF-8');
    if (class_exists('Normalizer')) {
      $d = Normalizer::normalize($s, Normalizer::FORM_D);
      if (is_string($d)) {
        return preg_replace('/\p{Mn}+/u', '', $d) ?? $s;
      }
    }
    return strtr($s, [
      'ë' => 'e', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ç' => 'c', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
      'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
      'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'š' => 's', 'ž' => 'z', 'č' => 'c', 'ć' => 'c',
    ]);
  }
}

if (!function_exists('qta_search_hit')) {
  /** Kërkimi në PHP (lista të vogla, p.sh. katalogu): çdo fjalë gjendet diku te $haystack. */
  function qta_search_hit(array $tokens, string $haystack): bool
  {
    $hay = qta_search_fold($haystack);
    foreach ($tokens as $t) {
      if (!str_contains($hay, qta_search_fold((string)$t))) {
        return false;
      }
    }
    return true;
  }
}

if (!function_exists('qta_list_choice')) {
  /** Vlerë e lejuar e një filtri (p.sh. status), përndryshe ''. */
  function qta_list_choice($raw, array $allowed): string
  {
    $raw = is_string($raw) ? trim($raw) : '';
    return in_array($raw, $allowed, true) ? $raw : '';
  }
}

if (!function_exists('qta_list_url')) {
  /** "students.php?q=…&status=…" pa vlerat bosh. */
  function qta_list_url(string $page, array $params): string
  {
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '' && $v !== false && $v !== 0);
    return $page . ($params ? '?' . http_build_query($params) : '');
  }
}

if (!function_exists('qta_list_pager')) {
  /**
   * Faqosja e listës: "Faqja 2 nga 5 · 96 kursantë" + ‹ 1 2 3 4 5 ›.
   * Lidhjet mbajnë filtrat; app.js i hap pa ringarkuar faqen (data-live-page).
   */
  function qta_list_pager(string $page, array $params, int $current, int $pages, string $summary = ''): string
  {
    if ($pages <= 1) {
      return '';
    }
    $link = static fn(int $p): string => qta_list_url($page, $params + ['page' => $p > 1 ? $p : null]);
    $item = static function (int $p, string $label, bool $disabled = false, bool $active = false, string $aria = '') use ($link): string {
      $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
      if ($disabled) {
        return '<li class="' . $cls . '"><span class="page-link"' . ($aria !== '' ? ' aria-label="' . h($aria) . '"' : '') . '>' . $label . '</span></li>';
      }
      return '<li class="' . $cls . '"><a class="page-link" href="' . h($link($p)) . '" data-live-page="' . $p . '"'
        . ($active ? ' aria-current="page"' : '') . ($aria !== '' ? ' aria-label="' . h($aria) . '"' : '') . '>' . $label . '</a></li>';
    };
    $html = '<nav class="pager" aria-label="Faqet e listës"><span class="pager-info">Faqja ' . $current . ' nga ' . $pages
      . ($summary !== '' ? ' · ' . h($summary) : '') . '</span><ul class="pagination">';
    $html .= $item(max(1, $current - 1), '<i class="bi bi-chevron-left" aria-hidden="true"></i>', $current <= 1, false, 'Faqja e mëparshme');
    $from = max(1, $current - 2);
    $to = min($pages, $current + 2);
    if ($from > 1) {
      $html .= $item(1, '1');
      if ($from > 2) $html .= '<li class="page-item disabled"><span class="page-link" aria-hidden="true">…</span></li>';
    }
    for ($p = $from; $p <= $to; $p++) {
      $html .= $item($p, (string)$p, false, $p === $current);
    }
    if ($to < $pages) {
      if ($to < $pages - 1) $html .= '<li class="page-item disabled"><span class="page-link" aria-hidden="true">…</span></li>';
      $html .= $item($pages, (string)$pages);
    }
    $html .= $item(min($pages, $current + 1), '<i class="bi bi-chevron-right" aria-hidden="true"></i>', $current >= $pages, false, 'Faqja tjetër');
    return $html . '</ul></nav>';
  }
}

if (!function_exists('qta_group_state_sql')) {
  /**
   * Gjendja e një grupi si kusht SQL — e njëjta radhë si qta_status() në faqe:
   * i mbyllur → nis më vonë → në mësim → pret mbylljen. Data e sotme vjen nga
   * PHP-ja (si etiketat në faqe), jo nga ora e serverit të bazës.
   */
  function qta_group_state_sql(string $state, string $alias = 'cg'): string
  {
    $today = date('Y-m-d');
    return match ($state) {
      'closed'         => "($alias.is_completed = 1)",
      'upcoming'       => "($alias.is_completed = 0 AND $alias.start_date > '$today')",
      'active'         => "($alias.is_completed = 0 AND $alias.start_date <= '$today' AND $alias.end_date >= '$today')",
      'awaiting_close' => "($alias.is_completed = 0 AND $alias.end_date < '$today')",
      default          => '1=1',
    };
  }
}
