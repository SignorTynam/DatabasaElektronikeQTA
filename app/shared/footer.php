<?php
declare(strict_types=1);

require_once __DIR__ . '/public_ui.php';
?>
<footer class="qta-footer">
  <div class="container-public">
    <div class="row g-4">
      <div class="col-lg-4">
        <div class="d-flex align-items-center gap-2 mb-3">
          <img class="footer-logo" src="image/logoPNG2.png" alt="Logo QTA">
          <div>
            <h3 class="mb-0">Qendra e Trajnimeve të Avancuara</h3>
            <div class="small text-muted-public">Portal trajnimesh dhe certifikimi</div>
          </div>
        </div>
        <p class="text-muted-public mb-0">
          QTA mbështet menaxhimin e kurseve, kursantëve dhe certifikatave me verifikim publik të thjeshtë dhe të sigurt.
        </p>
      </div>

      <div class="col-6 col-lg-2">
        <h4>Lidhje të shpejta</h4>
        <ul class="list-unstyled d-grid gap-2 mb-0">
          <li><a href="index.php">Kryefaqja</a></li>
          <li><a href="aboutus.php">Rreth nesh</a></li>
          <li><a href="verify.php">Verifiko certifikatën</a></li>
          <li><a href="contact.php">Kontakt</a></li>
          <li><a href="selectProfile.php">Hyr në sistem</a></li>
        </ul>
      </div>

      <div class="col-6 col-lg-3">
        <h4>Shërbime / Portali</h4>
        <ul class="list-unstyled d-grid gap-2 mb-0">
          <li><a href="index.php#funksionet">Kurse profesionale</a></li>
          <li><a href="index.php#per-ke">Regjistrim kursantësh</a></li>
          <li><a href="index.php#si-funksionon">Certifikim</a></li>
          <li><a href="verify.php">Verifikim publik</a></li>
        </ul>
      </div>

      <div class="col-lg-3">
        <h4>Kontakt</h4>
        <ul class="list-unstyled d-grid gap-2 mb-0 text-muted-public">
          <li><i class="bi bi-geo-alt me-2"></i>Rruga Bilal Konxholli, Tiranë</li>
          <li><a href="tel:+355698778837"><i class="bi bi-telephone me-2"></i>+355 69 877 8837</a></li>
          <li><a href="mailto:officialqta@gmail.com"><i class="bi bi-envelope me-2"></i>officialqta@gmail.com</a></li>
        </ul>
      </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between gap-2 pt-4 mt-4 border-top" style="border-color: var(--qta-border) !important;">
      <div class="small text-muted-public">
        &copy; <?= date('Y') ?> QTA. Të gjitha të drejtat e rezervuara.
      </div>
      <div class="small d-flex gap-3">
        <a href="#">Privatësia</a>
        <a href="#">Kushtet</a>
      </div>
    </div>
  </div>
</footer>

<button class="back-top" type="button" data-back-top aria-label="Kthehu në krye">
  <i class="bi bi-arrow-up"></i>
</button>
