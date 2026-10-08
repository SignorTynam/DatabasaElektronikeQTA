<?php
// Kept at its existing include path for pages that already load this component.
if (!empty($GLOBALS['qta_document_dialog_rendered'])) return;
$GLOBALS['qta_document_dialog_rendered'] = true;
?>
<dialog class="document-generation" id="documentGeneration" aria-labelledby="documentGenerationTitle" aria-describedby="documentGenerationHelp">
  <div class="modal-header">
    <h2 class="modal-title" id="documentGenerationTitle" tabindex="-1">Po përgatitet dokumenti</h2>
  </div>
  <div class="modal-body">
    <p id="documentGenerationHelp">Prit derisa dokumenti të jetë gati. Gjatë përgatitjes nuk mund të kryesh veprime të tjera.</p>
    <div class="document-generation-status" role="status" aria-live="polite" aria-atomic="true">
      <span data-document-status>Po nis përgatitja e dokumentit.</span>
      <span class="num" data-document-percent>0%</span>
    </div>
    <progress class="document-generation-progress" max="100" value="0" aria-label="Përgatitja e dokumentit"></progress>
    <p class="text-muted mt-3 mb-0">Mbaje këtë faqe hapur. Dokumenti do të shkarkohet sapo të jetë gati.</p>
    <div class="alert alert-danger mt-3 mb-0" role="alert" data-document-error hidden></div>
  </div>
  <div class="modal-footer" data-document-actions hidden>
    <button type="button" class="btn btn-primary" data-document-retry>Provo përsëri</button>
  </div>
</dialog>
<script src="<?= h(qta_asset('app/assets/js/document-generation.js')) ?>" defer></script>
