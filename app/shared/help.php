<?php
declare(strict_types=1);

/**
 * help.php — Regjistri i udhëzimeve (Themeli).
 *
 * Një burim i vetëm për:
 *   - panelin kontekstual "Si funksionon?" në çdo faqe pune;
 *   - qendrën e ndihmës (ndihme.php).
 *
 * Çdo temë: title, intro, steps [[titull, tekst]], tips [tekst], roles [rolet].
 * Tekstet janë në shqip të thjeshtë, për njerëz që nuk janë nga IT.
 */

require_once __DIR__ . '/themeli.php';

if (!function_exists('qta_help_topics')) {
  function qta_help_topics(): array {
    return require __DIR__ . '/help_topics.php';
  }
}

if (!function_exists('qta_help_topic')) {
  function qta_help_topic(string $key): ?array {
    $topics = qta_help_topics();
    return $topics[$key] ?? null;
  }
}

if (!function_exists('qta_help_render_body')) {
  /** Përmbajtja e një teme (përdoret në panel dhe në ndihme.php). */
  function qta_help_render_body(array $t): void {
    if (!empty($t['intro'])): ?>
      <p class="mb-4"><?= h((string)$t['intro']) ?></p>
    <?php endif;
    if (!empty($t['steps'])): ?>
      <ol class="steps-list mb-4">
        <?php foreach ($t['steps'] as $step): ?>
          <li><b><?= h((string)$step[0]) ?></b><span><?= h((string)($step[1] ?? '')) ?></span></li>
        <?php endforeach; ?>
      </ol>
    <?php endif;
    foreach ($t['tips'] ?? [] as $tip): ?>
      <div class="tip mb-2"><i class="bi bi-lightbulb" aria-hidden="true"></i><span><?= h((string)$tip) ?></span></div>
    <?php endforeach;
  }
}

if (!function_exists('qta_help_panel')) {
  /** Paneli anësor "Si funksionon?" për faqen aktuale. */
  function qta_help_panel(string $key): void {
    $t = qta_help_topic($key);
    if (!$t) {
      return;
    }
    ?>
    <div class="offcanvas offcanvas-end no-print" tabindex="-1" id="helpPanel" aria-labelledby="helpPanelTitle">
      <div class="offcanvas-header">
        <div>
          <span class="eyebrow mb-0">Si funksionon</span>
          <h2 class="offcanvas-title" id="helpPanelTitle"><?= h((string)$t['title']) ?></h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Mbyll ndihmën"></button>
      </div>
      <div class="offcanvas-body">
        <?php qta_help_render_body($t); ?>
        <hr>
        <p class="mb-0 text-muted small">
          Ke ende pyetje? Shiko <a href="ndihme.php#<?= h($key) ?>">të gjitha udhëzimet</a>
          ose <a href="contact.php">na kontakto</a>.
        </p>
      </div>
    </div>
    <?php
  }
}
