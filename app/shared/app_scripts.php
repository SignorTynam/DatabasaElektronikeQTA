<?php
declare(strict_types=1);

/**
 * app_scripts.php — fundi i përbashkët i faqeve të panelit.
 *
 * Variabla opsionale:
 *   $HELP_TOPIC   string  tema e ndihmës për panelin "Si funksionon?"
 *   $pageScripts  array   URL-ra JS shtesë (ngarkohen me defer, pas app.js)
 *   $hideBackTop  bool    fshih butonin e kthimit në krye
 */

require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/help.php';

$pageScripts = $pageScripts ?? [];
$hideBackTop = $hideBackTop ?? false;
$helpTopic   = $HELP_TOPIC ?? null;
?>
<footer class="app-footer no-print">
  <span>&copy; <?= date('Y') ?> Qendra e Trajnimeve të Avancuara</span>
  <span><a href="ndihme.php">Ndihmë</a> · <a href="contact.php">Kontakt</a></span>
</footer>

<?php if ($helpTopic !== null): ?>
  <?php qta_help_panel((string)$helpTopic); ?>
<?php endif; ?>

<?php if (!$hideBackTop): ?>
<button class="back-top no-print" type="button" data-back-top aria-label="Kthehu në krye të faqes">
  <i class="bi bi-arrow-up" aria-hidden="true"></i>
</button>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= h(qta_asset('app/assets/js/app.js')) ?>" defer></script>
<?php foreach ($pageScripts as $script): ?>
<script src="<?= h((string)$script) ?>" defer></script>
<?php endforeach; ?>
