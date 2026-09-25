<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);

$NAV_ACTIVE = 'contact';
$pageTitle = 'Kontakt — Regjistri QTA';
$pageDescription = 'Na kontaktoni për kualifikime, regjistrime ose probleme me hyrjen në sistem.';
$pageScripts = [qta_asset('app/assets/js/contact-ui.js')];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main id="main" tabindex="-1">

  <section class="wrap page-intro">
    <span class="hero-eyebrow"><i class="bi bi-envelope" aria-hidden="true"></i>Kontakt · përgjigjemi brenda 2 ditëve të punës</span>
    <h1 class="page-title">Na kontaktoni</h1>
    <p class="page-lead">
      Për kualifikime, regjistrime ose probleme me hyrjen në sistem. Për të kontrolluar një
      certifikatë nuk duhet të na shkruani — <a href="verify.php">hapni verifikimin</a>.
    </p>
  </section>

  <div class="wrap pb-5">
    <div class="row g-4 g-lg-5">

      <div class="col-lg-4">
        <h2 class="section-title mb-3">Na gjeni këtu</h2>
        <ul class="contact-list">
          <li class="contact-item">
            <i class="bi bi-telephone" aria-hidden="true"></i>
            <div><b>Telefon</b><a href="tel:+355698778837">+355 69 877 8837</a></div>
          </li>
          <li class="contact-item">
            <i class="bi bi-envelope" aria-hidden="true"></i>
            <div><b>Email</b><a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a></div>
          </li>
          <li class="contact-item">
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            <div><b>Adresa</b><span>Rruga Bilal Konxholli, Tiranë</span></div>
          </li>
          <li class="contact-item">
            <i class="bi bi-clock" aria-hidden="true"></i>
            <div><b>Orari</b><span>E hënë – e premte, 09:00–17:00</span></div>
          </li>
        </ul>
      </div>

      <div class="col-lg-8">
        <section class="panel" aria-labelledby="formTitle">
          <h2 class="section-title" id="formTitle">Shkruani mesazhin</h2>
          <p class="text-muted mb-4">
            Plotësoni fushat dhe shtypni "Hap në email". Mesazhi hapet i gatshëm në programin tuaj
            të email-it — ju e dërgoni vetë. Asgjë nuk ruhet në regjistër.
          </p>

          <form id="contactForm" novalidate>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="full_name">Emri dhe mbiemri <span class="req" aria-hidden="true">*</span></label>
                <input class="form-control" id="full_name" name="full_name" type="text" required autocomplete="name">
                <p class="invalid-feedback">Shkruani emrin.</p>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="email">Email-i juaj <span class="req" aria-hidden="true">*</span></label>
                <input class="form-control" id="email" name="email" type="email" required autocomplete="email">
                <p class="invalid-feedback">Shkruani një email të vlefshëm, p.sh. emri@shembull.com.</p>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone">Telefoni <span class="optional">(nëse doni)</span></label>
                <input class="form-control input-code" id="phone" name="phone" type="tel" autocomplete="tel">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="request_type">Për çfarë bëhet fjalë? <span class="req" aria-hidden="true">*</span></label>
                <select class="form-select" id="request_type" name="request_type" required>
                  <option value="">Zgjidhni…</option>
                  <option>Informacion për module</option>
                  <option>Regjistrim kursantësh</option>
                  <option>Problem me hyrjen</option>
                  <option>Verifikim certifikate</option>
                  <option>Bashkëpunim</option>
                  <option>Tjetër</option>
                </select>
                <p class="invalid-feedback">Zgjidhni një arsye.</p>
              </div>
              <div class="col-12">
                <label class="form-label" for="subject">Titulli i mesazhit <span class="req" aria-hidden="true">*</span></label>
                <input class="form-control" id="subject" name="subject" type="text" required>
                <p class="invalid-feedback">Shkruani një titull të shkurtër.</p>
              </div>
              <div class="col-12">
                <label class="form-label" for="message">Mesazhi <span class="req" aria-hidden="true">*</span></label>
                <textarea class="form-control" id="message" name="message" rows="6" required></textarea>
                <p class="invalid-feedback">Shkruani mesazhin.</p>
              </div>
              <div class="col-12 d-flex flex-wrap gap-2 pt-2">
                <button class="btn btn-primary btn-lg" type="submit">
                  <i class="bi bi-envelope-arrow-up" aria-hidden="true"></i>Hap në email
                </button>
                <a class="btn btn-secondary btn-lg" href="tel:+355698778837">
                  <i class="bi bi-telephone" aria-hidden="true"></i>Ose na telefononi
                </a>
              </div>
              <p class="col-12 text-muted small mb-0"><span class="req" aria-hidden="true">*</span> Fushat me yll janë të detyrueshme.</p>
            </div>
          </form>
        </section>
      </div>

    </div>
  </div>
</main>

<?php
require_once __DIR__ . '/../shared/footer.php';
require_once __DIR__ . '/../shared/public_scripts.php';
?>
</body>
</html>
