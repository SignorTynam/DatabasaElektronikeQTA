<?php
declare(strict_types=1);

/**
 * list_toolbar.php — Kërkimi dhe filtrat e një liste. Një për çdo listë.
 *
 *   [ Kërko…………………………………………… × ] [Filtra]
 *   [Të gjithë 54] [Pa grup 5] [Gati për grup 3] …  [Arsimi i ulët ×]
 *
 * Kërkimi nuk ka buton "Kërko": lista ndryshon ndërsa shkruan (app.js, "Listat").
 * Pa JavaScript formulari dërgohet si GET i zakonshëm (Enter, çipat, "Apliko").
 *
 * Pritet $LF = [
 *   'action'      => 'students.php',        faqja e listës
 *   'label'       => 'Kërko kursantë',       emri i fushës për lexuesit e ekranit
 *   'placeholder' => 'Emër, nr. i amzës…',
 *   'q'           => '…',
 *   'target'      => 'studentsResults',      id e zonës së rezultateve (aria-controls)
 *   'status'      => 'no_group',             çipi aktiv ('' = të gjitha)
 *   'chip_param'  => 'status',               emri i parametrit të çipave (p.sh. 'action' te historiku)
 *   'chips'       => [['value' => '', 'label' => 'Të gjithë', 'count' => 54, 'optional' => false], …],
 *                    'optional' => true: çipi del vetëm kur është aktiv (p.sh. nga një lidhje e Kreut)
 *   'chips_label' => 'Gjendja',
 *   'more'        => [['name' => 'edu', 'label' => 'Arsimi', 'type' => 'select'|'date', 'value' => '…',
 *                      'options' => [vlerë => etiketë], 'empty' => 'Çdo nivel', 'chip' => 'Arsimi: %s',
 *                      'attrs' => 'data-dmy-max="today"'], …],
 *   'presets'     => [['label' => 'Sot', 'set' => ['from' => '…', 'to' => '…']], …]   (opsionale, brenda "Filtra")
 * ]
 */

require_once __DIR__ . '/../list_filter.php';

$lfAction  = (string)($LF['action'] ?? '');
$lfLabel   = (string)($LF['label'] ?? 'Kërko në listë');
$lfQ       = (string)($LF['q'] ?? '');
$lfStatus  = (string)($LF['status'] ?? '');
$lfChips   = $LF['chips'] ?? [];
$lfMore    = $LF['more'] ?? [];
$lfPresets = $LF['presets'] ?? [];
$lfTarget  = (string)($LF['target'] ?? '');
$lfParam   = (string)($LF['chip_param'] ?? 'status');

/* Gjendja aktuale (për lidhjet "hiq këtë filtër" kur mungon JavaScript). */
$lfState = ['q' => $lfQ, $lfParam => $lfStatus];
foreach ($lfMore as $f) {
  $lfState[(string)$f['name']] = (string)($f['value'] ?? '');
}

/* Filtrat e rrallë që janë aktivë dalin si çipa të heqshëm: gjendja nuk fshihet. */
$lfActive = [];
foreach ($lfMore as $f) {
  $val = (string)($f['value'] ?? '');
  if ($val === '') continue;
  $text = ($f['type'] ?? 'select') === 'select' ? (string)(($f['options'] ?? [])[$val] ?? $val) : qta_date($val, $val);
  $lfActive[] = [
    'name'  => (string)$f['name'],
    'text'  => sprintf((string)($f['chip'] ?? '%s'), $text),
    'href'  => qta_list_url($lfAction, array_merge($lfState, [(string)$f['name'] => ''])),
  ];
}
$lfMoreCount = count($lfActive);
$lfShowChips = $lfChips || $lfActive;
?>
<form class="lf" method="get" action="<?= h($lfAction) ?>" role="search" aria-label="<?= h($lfLabel) ?>" data-live-filter>
  <?php /* Butoni i parë i formularit: Enter në fushë e dërgon pa ndryshuar çipin. */ ?>
  <button class="lf-submit" type="submit" tabindex="-1" aria-hidden="true">Kërko</button>

  <div class="lf-bar">
    <div class="lf-search">
      <label class="visually-hidden" for="lfQ"><?= h($lfLabel) ?></label>
      <i class="bi bi-search" aria-hidden="true"></i>
      <input class="lf-input" id="lfQ" type="search" name="q" value="<?= h($lfQ) ?>"
             placeholder="<?= h((string)($LF['placeholder'] ?? 'Kërko…')) ?>"
             autocomplete="off" spellcheck="false" enterkeyhint="search"
             <?= $lfTarget !== '' ? 'aria-controls="' . h($lfTarget) . '"' : '' ?> data-lf-input>
      <button class="lf-clear" type="button" data-lf-clear aria-label="Pastro kërkimin" title="Pastro kërkimin (Esc)"<?= $lfQ === '' ? ' hidden' : '' ?>>
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
      <span class="lf-progress" aria-hidden="true"></span>
    </div>

    <?php if ($lfMore): ?>
      <details class="lf-more" data-lf-more>
        <summary class="lf-more-btn" title="Filtra të tjerë">
          <i class="bi bi-funnel" aria-hidden="true"></i><span class="lf-more-text">Filtra</span>
          <span class="lf-more-count" data-lf-more-count<?= $lfMoreCount ? '' : ' hidden' ?>><?= $lfMoreCount ?><span class="visually-hidden"> aktivë</span></span>
        </summary>
        <div class="lf-panel">
          <?php if ($lfPresets): ?>
            <div class="lf-presets" role="group" aria-label="Periudha e shpejtë">
              <?php foreach ($lfPresets as $p): ?>
                <button class="chip" type="button" data-lf-preset="<?= h((string)json_encode($p['set'], JSON_UNESCAPED_UNICODE)) ?>"><?= h((string)$p['label']) ?></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php foreach ($lfMore as $i => $f):
            $fid = 'lfMore' . $i;
            $type = (string)($f['type'] ?? 'select');
            $val = (string)($f['value'] ?? ''); ?>
            <div class="lf-field">
              <label class="form-label" for="<?= h($fid) ?>"><?= h((string)$f['label']) ?></label>
              <?php if ($type === 'date'): ?>
                <input class="form-control form-control-sm" id="<?= h($fid) ?>" type="text" name="<?= h((string)$f['name']) ?>"
                       value="<?= h(qta_date($val, '')) ?>" inputmode="numeric" placeholder="dd.mm.vvvv" data-dmy <?= (string)($f['attrs'] ?? '') ?>>
              <?php else: ?>
                <select class="form-select form-select-sm" id="<?= h($fid) ?>" name="<?= h((string)$f['name']) ?>">
                  <option value=""><?= h((string)($f['empty'] ?? 'Të gjitha')) ?></option>
                  <?php foreach (($f['options'] ?? []) as $ov => $ol): ?>
                    <option value="<?= h((string)$ov) ?>"<?= (string)$ov === $val ? ' selected' : '' ?>><?= h((string)$ol) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <div class="lf-panel-foot">
            <button class="btn btn-ghost btn-sm" type="button" data-lf-more-reset>Hiq këta filtra</button>
            <button class="btn btn-secondary btn-sm lf-apply" type="submit">Apliko</button>
          </div>
        </div>
      </details>
    <?php endif; ?>
  </div>

  <?php if ($lfShowChips): ?>
    <div class="lf-chips" data-live-region="lf-chips">
      <input type="hidden" name="<?= h($lfParam) ?>" value="<?= h($lfStatus) ?>" data-lf-status-field>
      <?php if ($lfChips): ?>
        <div class="lf-chip-set" role="group" aria-label="<?= h((string)($LF['chips_label'] ?? 'Filtra të shpejtë')) ?>">
          <?php foreach ($lfChips as $c):
            $cv = (string)$c['value'];
            $on = $cv === $lfStatus;
            if (!empty($c['optional']) && !$on) continue; ?>
            <button class="chip<?= $on ? ' is-on' : '' ?>" type="submit" name="<?= h($lfParam) ?>" value="<?= h($cv) ?>"
                    aria-pressed="<?= $on ? 'true' : 'false' ?>" data-lf-chip="<?= h($cv) ?>" data-focus-key="chip:<?= h($cv) ?>">
              <?= h((string)$c['label']) ?>
              <?php if (isset($c['count'])): ?><span class="chip-count"><?= number_format((int)$c['count'], 0, ',', '.') ?></span><?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php foreach ($lfActive as $a): ?>
        <a class="chip is-filter" href="<?= h($a['href']) ?>" data-lf-remove="<?= h($a['name']) ?>" data-focus-key="remove:<?= h($a['name']) ?>"
           aria-label="Hiq filtrin: <?= h($a['text']) ?>" title="Hiq filtrin">
          <?= h($a['text']) ?><i class="bi bi-x" aria-hidden="true"></i>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <p class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" data-lf-announce></p>
  <p class="lf-error" data-lf-error hidden>
    <i class="bi bi-wifi-off" aria-hidden="true"></i>
    <span>Lista nuk u përditësua. Kontrollo lidhjen.</span>
    <button class="btn btn-link btn-sm p-0" type="button" data-lf-retry>Provo sërish</button>
  </p>
</form>
