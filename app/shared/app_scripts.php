<?php
declare(strict_types=1);

/**
 * app_scripts.php — skriptet e përbashkëta të panelit + butoni "kthehu në krye".
 *
 * Variabla opsionale:
 *   $pageScripts  array  URL-ra JS shtesë (ngarkohen me defer, pas app.js)
 *   $hideBackTop  bool   fshih butonin e kthimit në krye
 */

require_once __DIR__ . '/app_ui.php';

$pageScripts = $pageScripts ?? [];
$hideBackTop = $hideBackTop ?? false;
?>
<?php if (!$hideBackTop): ?>
<button class="app-back-top no-print" type="button" data-back-top aria-label="Kthehu në krye">
  <i class="bi bi-arrow-up"></i>
</button>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= h(qta_asset('app/assets/js/app.js')) ?>" defer></script>
<?php foreach ($pageScripts as $script): ?>
<script src="<?= h((string)$script) ?>" defer></script>
<?php endforeach; ?>
