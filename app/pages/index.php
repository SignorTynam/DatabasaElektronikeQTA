<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);
$role = qta_public_role($currentUser);
$panelHref = qta_public_panel_href($role);
$loginHref = $currentUser ? $panelHref : 'selectProfile.php';
$loginText = $currentUser ? 'Vazhdo në panel' : 'Hyr në sistem';
$NAV_ACTIVE = 'home';
$pageTitle = 'QTA - Portali i trajnimeve profesionale';
$pageDescription = 'Trajnime profesionale, certifikim dhe verifikim publik në portalin QTA.';
$publicPlugins = ['aos', 'swiper'];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main>
  <section class="hero-public">
    <div class="container-public">
      <div class="row align-items-center g-4 g-xl-5">
        <div class="col-lg-7" data-aos="fade-up">
          <h1>Trajnime profesionale, certifikim dhe verifikim publik në një portal të vetëm</h1>
          <p class="lead">
            QTA menaxhon kurse, kursantë, grupe, certifikata dhe verifikim publik me QR për një proces të qartë nga regjistrimi deri te kontrolli i vlefshmërisë.
          </p>
          <div class="hero-actions">
            <a class="btn btn-primary qta-btn" href="<?= h($loginHref) ?>">
              <i class="bi bi-box-arrow-in-right"></i><?= h($loginText) ?>
            </a>
            <a class="btn btn-outline-primary qta-btn" href="verify.php">
              <i class="bi bi-qr-code-scan"></i>Verifiko certifikatën
            </a>
            <a class="btn btn-outline-secondary qta-btn" href="contact.php">
              <i class="bi bi-envelope"></i>Na kontaktoni
            </a>
          </div>
        </div>

        <div class="col-lg-5" data-aos="fade-left">
          <div class="hero-mockup qta-card">
            <div class="mockup-window">
              <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                  <div class="fw-bold">Certifikatë QTA</div>
                  <div class="small text-muted-public">Verifikim publik</div>
                </div>
                <span class="mockup-status"><i class="bi bi-check2-circle me-1"></i>i verifikuar</span>
              </div>
              <div class="qta-card qta-card-pad mb-3">
                <div class="d-flex gap-3 align-items-center">
                  <span class="qta-icon"><i class="bi bi-person-badge"></i></span>
                  <div class="w-100">
                    <div class="small text-muted-public">Kursant</div>
                    <div class="fw-bold">Kursant i regjistruar</div>
                    <div class="mockup-line mt-2" style="width: 72%;"></div>
                  </div>
                </div>
              </div>
              <div class="qta-card qta-card-pad mb-3">
                <div class="d-flex gap-3 align-items-center">
                  <span class="qta-icon accent"><i class="bi bi-mortarboard"></i></span>
                  <div class="w-100">
                    <div class="small text-muted-public">Kurs</div>
                    <div class="fw-bold">Program profesional</div>
                    <div class="mockup-line mt-2" style="width: 64%;"></div>
                  </div>
                </div>
              </div>
              <div class="d-flex align-items-center justify-content-between qta-card qta-card-pad">
                <div>
                  <div class="fw-bold">Kod QR</div>
                  <div class="small text-muted-public">Token unik për verifikim</div>
                </div>
                <i class="bi bi-qr-code fs-1"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section-pad-sm">
    <div class="container-public">
      <div class="trust-strip">
        <div class="trust-item" data-aos="fade-up">
          <i class="bi bi-qr-code text-primary me-2"></i><strong>Certifikata me QR</strong>
          <div class="small text-muted-public mt-1">Kontroll publik pa hyrje në sistem.</div>
        </div>
        <div class="trust-item" data-aos="fade-up" data-aos-delay="60">
          <i class="bi bi-person-lock text-primary me-2"></i><strong>Role të ndara</strong>
          <div class="small text-muted-public mt-1">Administrator, editor, agjenci dhe student.</div>
        </div>
        <div class="trust-item" data-aos="fade-up" data-aos-delay="120">
          <i class="bi bi-diagram-3 text-primary me-2"></i><strong>Proces i standardizuar</strong>
          <div class="small text-muted-public mt-1">Regjistrim, ndjekje, vlerësim dhe certifikim.</div>
        </div>
        <div class="trust-item" data-aos="fade-up" data-aos-delay="180">
          <i class="bi bi-shield-check text-primary me-2"></i><strong>Verifikim publik</strong>
          <div class="small text-muted-public mt-1">Status i qartë për çdo certifikatë.</div>
        </div>
      </div>
    </div>
  </section>

  <section id="si-funksionon" class="section-pad">
    <div class="container-public">
      <div class="section-title mb-4" data-aos="fade-up">
        <h2>Si funksionon</h2>
        <p>Portali e ndan procesin në hapa të lexueshëm, që çdo rol të kuptojë shpejt çfarë duhet të bëjë.</p>
      </div>
      <div class="row g-3">
        <?php
        $steps = [
          ['Regjistrimi', 'Kursanti regjistrohet me të dhënat bazë dhe lidhet me kursin ose grupin.'],
          ['Ndjekja e kursit', 'Grupi dhe kursi ndiqen nga përdoruesit me rolet përkatëse.'],
          ['Certifikimi', 'Rezultatet dhe të dhënat finale përgatiten për certifikim.'],
          ['Verifikimi publik', 'Certifikata kontrollohet me QR ose link publik pa llogari.'],
        ];
        foreach ($steps as $index => $step):
        ?>
          <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="<?= (int)$index * 60 ?>">
            <div class="qta-card qta-card-pad step-card">
              <span class="step-number mb-3"><?= $index + 1 ?></span>
              <h3 class="h5 fw-bold"><?= h($step[0]) ?></h3>
              <p class="text-muted-public mb-0"><?= h($step[1]) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section id="per-ke" class="section-pad">
    <div class="container-public">
      <div class="section-title mb-4" data-aos="fade-up">
        <h2>Për kë është portali</h2>
        <p>Ndërfaqja publike orienton shpejt kursantët, agjencitë dhe administratën drejt veprimit të duhur.</p>
      </div>
      <div class="row g-3">
        <div class="col-md-4" data-aos="fade-up">
          <div class="qta-card qta-card-pad target-card">
            <span class="qta-icon mb-3"><i class="bi bi-mortarboard"></i></span>
            <h3 class="h5 fw-bold">Për kursantë</h3>
            <p class="text-muted-public mb-0">Akses te të dhënat personale, progresi dhe certifikatat.</p>
          </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="80">
          <div class="qta-card qta-card-pad target-card">
            <span class="qta-icon success mb-3"><i class="bi bi-building"></i></span>
            <h3 class="h5 fw-bold">Për agjenci</h3>
            <p class="text-muted-public mb-0">Menaxhim i kursantëve dhe grupeve sipas aksesit të lejuar.</p>
          </div>
        </div>
        <div class="col-md-4" data-aos="fade-up" data-aos-delay="160">
          <div class="qta-card qta-card-pad target-card">
            <span class="qta-icon accent mb-3"><i class="bi bi-person-gear"></i></span>
            <h3 class="h5 fw-bold">Për administratën QTA</h3>
            <p class="text-muted-public mb-0">Kontroll i proceseve, kurseve, grupeve, certifikatave dhe raporteve.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="funksionet" class="section-pad">
    <div class="container-public">
      <div class="section-title mb-4" data-aos="fade-up">
        <h2>Funksionet kryesore</h2>
        <p>Funksione të organizuara për punë të përditshme, raportim dhe verifikim të certifikatave.</p>
      </div>
      <div class="row g-3">
        <?php
        $features = [
          ['bi-journal-bookmark', 'Menaxhim kursesh'],
          ['bi-people', 'Menaxhim grupesh'],
          ['bi-person-lines-fill', 'Regjistër kursantësh'],
          ['bi-qr-code', 'Certifikata me QR'],
          ['bi-file-earmark-spreadsheet', 'Eksporte Excel/PDF'],
          ['bi-patch-check', 'Verifikim publik'],
        ];
        foreach ($features as $index => $feature):
        ?>
          <div class="col-md-6 col-lg-4" data-aos="fade-up" data-aos-delay="<?= (int)($index % 3) * 60 ?>">
            <div class="qta-card qta-card-pad feature-card d-flex gap-3 align-items-start">
              <span class="qta-icon"><i class="bi <?= h($feature[0]) ?>"></i></span>
              <div>
                <h3 class="h5 fw-bold mb-1"><?= h($feature[1]) ?></h3>
                <p class="text-muted-public mb-0">Pjesë e portalit për procese më të qarta dhe më të kontrolluara.</p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="section-pad">
    <div class="container-public">
      <div class="section-title mb-4" data-aos="fade-up">
        <h2>Çfarë mbështet QTA</h2>
      </div>
      <div class="swiper qta-swiper" data-aos="fade-up">
        <div class="swiper-wrapper">
          <?php
          $slides = [
            ['Kurse profesionale', 'Programet organizohen për aftësi praktike dhe proces të matshëm.'],
            ['Proces i kontrolluar', 'Çdo hap lidhet me rol, përgjegjësi dhe të dhëna të sakta.'],
            ['Certifikim i verifikueshëm', 'QR dhe token unik për verifikim publik pa llogari.'],
            ['Mbështetje për përdoruesit', 'Kontakt i qartë për akses, regjistrim dhe verifikim.'],
          ];
          foreach ($slides as $slide):
          ?>
            <div class="swiper-slide">
              <div class="qta-card qta-card-pad h-100">
                <h3 class="h5 fw-bold"><?= h($slide[0]) ?></h3>
                <p class="text-muted-public mb-0"><?= h($slide[1]) ?></p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="swiper-pagination"></div>
        <div class="swiper-button-prev" aria-label="Prapa"></div>
        <div class="swiper-button-next" aria-label="Para"></div>
      </div>
    </div>
  </section>

  <section class="section-pad-sm">
    <div class="container-public">
      <div class="cta-band d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3" data-aos="fade-up">
        <div>
          <h2 class="h3 fw-bold mb-2">Gati për të vazhduar?</h2>
          <p class="mb-0 opacity-75">Hyni në sistem ose verifikoni menjëherë një certifikatë QTA.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-light qta-btn" href="<?= h($loginHref) ?>"><?= h($loginText) ?></a>
          <a class="btn btn-outline-light qta-btn" href="verify.php">Verifiko certifikatën</a>
        </div>
      </div>
    </div>
  </section>

  <section class="section-pad">
    <div class="container-public">
      <div class="section-title mb-4" data-aos="fade-up">
        <h2>Pyetje të shpeshta</h2>
      </div>
      <div class="accordion" id="homeFaq" data-aos="fade-up">
        <?php
        $faqs = [
          ['Si hyj në sistem?', 'Klikoni “Hyr në sistem”, zgjidhni rolin dhe plotësoni të dhënat e hyrjes.'],
          ['Si verifikohet një certifikatë?', 'Hapni faqen e verifikimit, skanoni QR ose vendosni linkun/token-in e certifikatës.'],
          ['A duhet llogari për verifikim publik?', 'Jo. Verifikimi publik funksionon pa hyrje në sistem.'],
          ['Çfarë bëj nëse QR nuk punon?', 'Provoni me foto më të qartë ose kontaktoni QTA me të dhënat e certifikatës.'],
        ];
        foreach ($faqs as $index => $faq):
          $qid = 'homeFaq' . $index;
        ?>
          <div class="accordion-item">
            <h3 class="accordion-header">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($qid) ?>">
                <?= h($faq[0]) ?>
              </button>
            </h3>
            <div id="<?= h($qid) ?>" class="accordion-collapse collapse" data-bs-parent="#homeFaq">
              <div class="accordion-body"><?= h($faq[1]) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
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
