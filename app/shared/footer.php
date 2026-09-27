<?php
declare(strict_types=1);

/**
 * footer.php — Fundi i faqeve publike: kush jemi, lidhje, kontakt.
 */

require_once __DIR__ . '/public_ui.php';
?>
<footer class="site-footer no-print">
  <div class="wrap">
    <div class="site-footer-grid">

      <div>
        <div class="site-footer-brand">
          <img src="<?= h(qta_asset('image/logoPNG2.png')) ?>" alt="QTA">
          <b>Qendra e Trajnimeve të Avancuara</b>
        </div>
        <p class="site-footer-about">
          Kualifikim dhe certifikim i punonjësve në zanatet e ndërtimit dhe në sigurinë në punë.
          Çdo certifikatë e lëshuar mund të kontrollohet publikisht.
        </p>
      </div>

      <div>
        <h2>Regjistri</h2>
        <ul>
          <li><a href="index.php">Kreu</a></li>
          <li><a href="verify.php">Verifiko certifikatë</a></li>
          <li><a href="index.php#kurset">Kurset</a></li>
          <li><a href="index.php#si-funksionon">Si funksionon</a></li>
        </ul>
      </div>

      <div>
        <h2>Institucioni</h2>
        <ul>
          <li><a href="aboutus.php">Rreth nesh</a></li>
          <li><a href="contact.php">Kontakt</a></li>
          <li><a href="selectProfile.php">Hyr në sistem</a></li>
        </ul>
      </div>

      <div>
        <h2>Na kontaktoni</h2>
        <ul>
          <li class="text-muted">Rruga Bilal Konxholli, Tiranë</li>
          <li><a href="tel:+355698778837">+355 69 877 8837</a></li>
          <li><a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a></li>
        </ul>
      </div>

    </div>

    <div class="site-footer-bottom">
      <span>&copy; <?= date('Y') ?> Qendra e Trajnimeve të Avancuara · Tiranë</span>
      <span>E hënë – e premte, 09:00–17:00</span>
    </div>
  </div>
</footer>
