<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);
$role = qta_public_role($currentUser);
$loginHref = $currentUser ? qta_public_panel_href($role) : 'selectProfile.php';
$NAV_ACTIVE = 'contact';
$pageTitle = 'Kontakt - QTA';
$pageDescription = 'Kontaktoni QTA për regjistrime, kurse, akses në portal dhe verifikim certifikatash.';
$publicPlugins = ['aos'];
$pageScripts = ['app/assets/js/contact-ui.js'];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main>
  <section class="hero-public">
    <div class="container-public">
      <div class="row align-items-center g-4">
        <div class="col-lg-7" data-aos="fade-up">
          <h1>Na kontaktoni</h1>
          <p class="lead">
            Për regjistrime, kurse, akses në portal dhe verifikim certifikatash, mund të na kontaktoni në çdo kohë gjatë orarit të punës.
          </p>
          <div class="hero-actions">
            <a class="btn btn-primary qta-btn" href="#formulari"><i class="bi bi-send"></i>Dërgo mesazh</a>
            <a class="btn btn-outline-primary qta-btn" href="tel:+355698778837"><i class="bi bi-telephone"></i>Telefononi</a>
            <a class="btn btn-outline-secondary qta-btn" href="verify.php"><i class="bi bi-qr-code-scan"></i>Verifiko certifikatën</a>
          </div>
        </div>
        <div class="col-lg-5" data-aos="fade-left">
          <div class="qta-card qta-card-pad">
            <h2 class="h4 fw-bold mb-3">Kontakt zyrtar</h2>
            <div class="d-grid gap-3">
              <div class="d-flex gap-3">
                <span class="qta-icon"><i class="bi bi-telephone"></i></span>
                <div>
                  <div class="fw-bold">Telefon</div>
                  <a href="tel:+355698778837">+355 69 877 8837</a>
                </div>
              </div>
              <div class="d-flex gap-3">
                <span class="qta-icon accent"><i class="bi bi-envelope"></i></span>
                <div>
                  <div class="fw-bold">Email</div>
                  <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a>
                </div>
              </div>
              <div class="d-flex gap-3">
                <span class="qta-icon success"><i class="bi bi-geo-alt"></i></span>
                <div>
                  <div class="fw-bold">Adresë</div>
                  <div class="text-muted-public">Rruga Bilal Konxholli, Tiranë</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section-pad-sm">
    <div class="container-public">
      <div class="row g-3">
        <?php
        $cards = [
          ['bi-telephone', 'Telefon', '+355 69 877 8837', 'tel:+355698778837', '+355698778837'],
          ['bi-envelope', 'Email', 'officialqta@gmail.com', 'mailto:officialqta@gmail.com', 'officialqta@gmail.com'],
          ['bi-geo-alt', 'Adresë', 'Rruga Bilal Konxholli, Tiranë', 'https://www.google.com/maps/search/?api=1&query=Rruga%20Bilal%20Konxholli%20Tirane', 'Rruga Bilal Konxholli, Tiranë'],
          ['bi-clock', 'Orari', 'E hënë - e premte, 08:00 - 18:00', '#formulari', ''],
        ];
        foreach ($cards as $index => $card):
        ?>
          <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="<?= (int)$index * 50 ?>">
            <div class="qta-card qta-card-pad contact-card">
              <span class="qta-icon mb-3"><i class="bi <?= h($card[0]) ?>"></i></span>
              <h2 class="h5 fw-bold"><?= h($card[1]) ?></h2>
              <p class="text-muted-public mb-3"><?= h($card[2]) ?></p>
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary qta-btn" href="<?= h($card[3]) ?>">Hap</a>
                <?php if ($card[4] !== ''): ?>
                  <button class="btn btn-sm btn-outline-secondary qta-btn" type="button" data-copy="<?= h($card[4]) ?>" aria-label="Kopjo <?= h($card[1]) ?>">
                    <i class="bi bi-clipboard"></i>
                  </button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section id="formulari" class="section-pad">
    <div class="container-public">
      <div class="row g-4 align-items-start">
        <div class="col-lg-7" data-aos="fade-up">
          <div class="qta-card qta-card-pad">
            <div class="section-title mb-4">
              <h2>Dërgo një mesazh</h2>
              <p>Formulari përgatit një email të strukturuar. Nëse nuk ka backend për dërgim, përdorni butonin “Dërgo me email”.</p>
            </div>
            <div class="progress mb-4" style="height: 10px;">
              <div class="progress-bar" data-contact-progress style="width: 12%;"></div>
            </div>
            <form id="contactForm" class="needs-validation" novalidate>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label" for="full_name">Emër dhe mbiemër</label>
                  <input class="form-control" id="full_name" name="full_name" type="text" required autocomplete="name">
                  <div class="invalid-feedback">Shkruani emrin dhe mbiemrin.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="email">Email</label>
                  <input class="form-control" id="email" name="email" type="email" required autocomplete="email">
                  <div class="invalid-feedback">Shkruani një email të vlefshëm.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="phone">Telefon</label>
                  <input class="form-control" id="phone" name="phone" type="tel" autocomplete="tel">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="subject">Subjekti</label>
                  <input class="form-control" id="subject" name="subject" type="text" required>
                  <div class="invalid-feedback">Shkruani subjektin.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="request_type">Lloji i kërkesës</label>
                  <select class="form-select" id="request_type" name="request_type" required>
                    <option value="">Zgjidhni llojin</option>
                    <option>Informacion për kurse</option>
                    <option>Problem me hyrjen</option>
                    <option>Verifikim certifikate</option>
                    <option>Bashkëpunim</option>
                    <option>Tjetër</option>
                  </select>
                  <div class="invalid-feedback">Zgjidhni llojin e kërkesës.</div>
                </div>
                <div class="col-12">
                  <label class="form-label" for="message">Mesazhi</label>
                  <textarea class="form-control" id="message" name="message" rows="6" required></textarea>
                  <div class="invalid-feedback">Shkruani mesazhin.</div>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2 mt-4">
                <button class="btn btn-primary qta-btn" type="submit"><i class="bi bi-check2-circle"></i>Përgatit mesazhin</button>
                <a class="btn btn-outline-primary qta-btn" id="contactMailto" href="mailto:officialqta@gmail.com"><i class="bi bi-envelope-open"></i>Dërgo me email</a>
              </div>
            </form>
          </div>
        </div>

        <div class="col-lg-5" data-aos="fade-left">
          <div class="qta-card qta-card-pad map-card mb-4">
            <span class="qta-icon mb-3"><i class="bi bi-map"></i></span>
            <h2 class="h4 fw-bold">Harta</h2>
            <p class="text-muted-public">
              Adresa është Rruga Bilal Konxholli, Tiranë. Pa koordinata zyrtare nuk vendosim pin të sajuar; përdorni kërkimin në hartë.
            </p>
            <a class="btn btn-primary qta-btn align-self-start" href="https://www.google.com/maps/search/?api=1&query=Rruga%20Bilal%20Konxholli%20Tirane" target="_blank" rel="noopener">
              <i class="bi bi-box-arrow-up-right"></i>Shiko në hartë
            </a>
          </div>
          <div class="qta-card qta-card-pad">
            <h2 class="h5 fw-bold">Për akses në portal</h2>
            <p class="text-muted-public mb-3">Nëse nuk keni akses ose keni harruar të dhënat, kontaktoni administratën e QTA-së.</p>
            <a class="btn btn-outline-primary qta-btn" href="<?= h($loginHref) ?>">Hyr në sistem</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section-pad">
    <div class="container-public">
      <div class="section-title mb-4" data-aos="fade-up">
        <h2>Pyetje për kontakt</h2>
      </div>
      <div class="accordion" id="contactFaq" data-aos="fade-up">
        <?php
        $faqs = [
          ['Sa shpejt merr përgjigje?', 'Përgjigjja varet nga lloji i kërkesës dhe orari, por sugjerohet të jepni subjekt dhe të dhëna të qarta.'],
          ['Çfarë informacioni duhet të jap?', 'Shkruani emrin, kontaktin, llojin e kërkesës dhe përshkrimin e saktë të problemit ose pyetjes.'],
          ['Si verifikoj një certifikatë?', 'Përdorni faqen publike “Verifiko certifikatën” dhe vendosni QR, foto ose linkun/token-in.'],
        ];
        foreach ($faqs as $index => $faq):
          $qid = 'contactFaq' . $index;
        ?>
          <div class="accordion-item">
            <h3 class="accordion-header">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($qid) ?>">
                <?= h($faq[0]) ?>
              </button>
            </h3>
            <div id="<?= h($qid) ?>" class="accordion-collapse collapse" data-bs-parent="#contactFaq">
              <div class="accordion-body"><?= h($faq[1]) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="section-pad-sm">
    <div class="container-public">
      <div class="cta-band d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3" data-aos="fade-up">
        <div>
          <h2 class="h3 fw-bold mb-2">Për verifikim certifikate, përdorni faqen publike të verifikimit.</h2>
          <p class="mb-0 opacity-75">Nuk kërkohet llogari për të kontrolluar vlefshmërinë.</p>
        </div>
        <a class="btn btn-light qta-btn" href="verify.php">Verifiko certifikatën</a>
      </div>
    </div>
  </section>
</main>
<?php
require_once __DIR__ . '/footer.php';
require_once __DIR__ . '/../shared/public_scripts.php';
?>
</body>
</html>
