<?php
declare(strict_types=1);

require_once __DIR__ . '/public_ui.php';

$pageScripts = $pageScripts ?? [];
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (qta_plugin_enabled('aos')): ?>
  <script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
<?php endif; ?>
<?php if (qta_plugin_enabled('swiper')): ?>
  <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
<?php endif; ?>
<?php if (qta_plugin_enabled('leaflet')): ?>
  <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<?php if (qta_plugin_enabled('html5-qrcode')): ?>
  <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<?php endif; ?>
<script src="app/assets/js/public.js" defer></script>
<?php foreach ($pageScripts as $script): ?>
  <script src="<?= h((string)$script) ?>" defer></script>
<?php endforeach; ?>
