<?php
declare(strict_types=1);

/**
 * footer.php — Kolofoni i regjistrit publik.
 * Mban vetëm atë që një dokument zyrtar mban në fund: kush e lëshon, ku
 * gjendet, si kontaktohet dhe nën çfarë kushtesh përdoret.
 */

require_once __DIR__ . '/public_ui.php';
?>
<footer class="colophon">
  <div class="wrap">
    <div class="row g-4">

      <div class="col-lg-5">
        <div class="d-flex align-items-center gap-2 mb-3">
          <img src="image/logoPNG2.png" alt="QTA" style="height:26px;width:auto">
          <span style="font-family:var(--font-record);font-weight:600;font-size:var(--fs-lg)">
            Qendra e Trajnimeve të Avancuara
          </span>
        </div>
        <p class="prose" style="font-size:var(--fs-sm);max-width:46ch">
          Qendër e akredituar për kualifikimin dhe certifikimin e punonjësve në zanatet e ndërtimit
          dhe në sigurinë e shëndetit në punë. Çdo certifikatë e lëshuar mund të kontrollohet publikisht.
        </p>
      </div>

      <div class="col-6 col-lg-2">
        <h3>Regjistri</h3>
        <ul>
          <li><a href="index.php">Kryefaqja</a></li>
          <li><a href="index.php#modulet">Modulet</a></li>
          <li><a href="index.php#procedura">Procedura</a></li>
          <li><a href="verify.php">Verifiko certifikatën</a></li>
        </ul>
      </div>

      <div class="col-6 col-lg-2">
        <h3>Institucioni</h3>
        <ul>
          <li><a href="aboutus.php">Rreth nesh</a></li>
          <li><a href="contact.php">Kontakt</a></li>
          <li><a href="selectProfile.php">Hyr në sistem</a></li>
        </ul>
      </div>

      <div class="col-lg-3">
        <h3>Selia</h3>
        <ul>
          <li class="muted" style="font-size:var(--fs-sm)">Rruga Bilal Konxholli, Tiranë</li>
          <li><a href="tel:+355698778837" class="code">+355 69 877 8837</a></li>
          <li><a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a></li>
        </ul>
      </div>

    </div>

    <div class="colophon-foot">
      <span>&copy; <?= date('Y') ?> Qendra e Trajnimeve të Avancuara</span>
      <span>Tiranë, Shqipëri</span>
    </div>
  </div>
</footer>

<button class="to-top no-print" type="button" data-back-top aria-label="Kthehu në krye">
  <i class="bi bi-arrow-up"></i>
</button>
