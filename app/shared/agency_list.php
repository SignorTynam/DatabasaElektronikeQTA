<?php
declare(strict_types=1);

/**
 * agency_list.php — "Punonjësit tanë" (register_agjencia.php) dhe eksporti i saj
 * (register_export_agency.php): i njëjti kërkim dhe të njëjtat gjendje, që lista
 * në ekran dhe dokumenti të kenë të njëjtët punonjës.
 *
 * Pritet që pyetja të ketë: s (students), p (persons), el (education_levels),
 * lastg (grupi i fundit), cg (course_groups) dhe c (courses).
 *   no_group  punonjës që nuk është ende në asnjë grup
 *   active    në mësim sot (grupi i fundit ka nisur dhe s'ka mbaruar)
 */

require_once __DIR__ . '/list_filter.php';

const QTA_AGENCY_STATES = ['no_group', 'active'];

if (!function_exists('qta_agency_filters')) {
  function qta_agency_filters(array $in): array
  {
    return [
      'q'      => qta_search_q($in['q'] ?? ''),
      'status' => qta_list_choice($in['status'] ?? '', QTA_AGENCY_STATES),
    ];
  }
}

if (!function_exists('qta_agency_state_sql')) {
  function qta_agency_state_sql(string $state): string
  {
    $today = date('Y-m-d');
    return match ($state) {
      'no_group' => '(lastg.group_id IS NULL)',
      'active'   => "(lastg.group_id IS NOT NULL AND cg.is_completed = 0 AND cg.start_date <= '$today' AND cg.end_date >= '$today')",
      default    => '1=1',
    };
  }
}

if (!function_exists('qta_agency_where')) {
  /** Kushtet shtesë (pa agjencinë): " AND …". $withState = false për numrat e çipave. */
  function qta_agency_where(array $f, array &$params, bool $withState = true): string
  {
    $w = [];
    $tokens = qta_search_tokens($f['q'] ?? '');
    if ($tokens) {
      $w[] = qta_search_sql($tokens, [
        's.nr_amze', 'p.personal_number', 'p.first_name', 'p.father_name', 'p.last_name',
        "CONCAT_WS(' ', p.first_name, p.father_name, p.last_name)",
        'c.name', 'c.code', 'el.label',
      ], $params, 'ag');
    }
    if ($withState && ($f['status'] ?? '') !== '') {
      $w[] = qta_agency_state_sql((string)$f['status']);
    }
    return $w ? ' AND ' . implode(' AND ', $w) : '';
  }
}
