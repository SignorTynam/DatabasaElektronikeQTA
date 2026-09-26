<?php
declare(strict_types=1);

/**
 * public_scripts.php — skriptet e faqeve publike.
 *   $publicPlugins  array  ['html5-qrcode'] kur faqja skanon kode QR
 *   $pageScripts    array  URL-ra JS shtesë
 */

require_once __DIR__ . '/public_ui.php';

$pageScripts = $pageScripts ?? [];
?>
<button class="back-top no-print" type="button" data-back-top aria-label="Kthehu në krye të faqes">
  <i class="bi bi-arrow-up" aria-hidden="true"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (qta_plugin_enabled('html5-qrcode', $publicPlugins ?? null)): ?>
  <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<?php endif; ?>
<script src="<?= h(qta_asset('app/assets/js/app.js')) ?>" defer></script>
<?php foreach ($pageScripts as $script): ?>
  <script src="<?= h((string)$script) ?>" defer></script>
<?php endforeach; ?>
