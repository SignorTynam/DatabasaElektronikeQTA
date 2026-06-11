<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);
$role = qta_public_role($currentUser);
$loginHref = $currentUser ? qta_public_panel_href($role) : 'selectProfile.php';
$loginText = $currentUser ? 'Vazhdo në panel' : 'Hyr në sistem';
$NAV_ACTIVE = 'about';
$pageTitle = 'Rreth nesh - QTA';
$pageDescription = 'Rreth Qendrës së Trajnimeve të Avancuara, misionit, vlerave dhe verifikimit publik.';
$publicPlugins = ['aos', 'swiper'];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main>
  <section class="hero-public">
    <div class="container-public">
      <div class="row align-items-center g-4 g-xl-5">
        <div class="col-lg-7" data-aos="fade-up">
          <h1>Rreth Qendrës së Trajnimeve të Avancuara</h1>
          <p class="lead">
            QTA fokusohet në trajnim praktik, proces të strukturuar dhe certifikim të verifikueshëm për kursantë, agjenci dhe institucione partnere.
          </p>
          <div class="hero-actions">
            <a class="btn btn-primary qta-btn" href="#si-punojme"><i class="bi bi-diagram-3"></i>Shiko si funksionon</a>
            <a class="btn btn-outline-primary qta-btn" href="contact.php"><i class="bi bi-envelope"></i>Kontakto QTA</a>
            <a class="btn btn-outline-secondary qta-btn" href="verify.php"><i class="bi bi-qr-code-scan"></i>Verifiko certifikatën</a>
          </div>
        </div>
        <div class="col-lg-5" data-aos="fade-left">
          <div class="qta-card qta-card-pad">
            <div class="d-flex gap-3 align-items-start mb-4">
              <span class="qta-icon"><i class="bi bi-bullseye"></i></span>
              <div>
                <h2 class="h5 fw-bold">Trajnim me proces të matshëm</h2>
                <p class="text-muted-public mb-0">Nga regjistrimi te vlerësimi dhe certifikimi, çdo hap synon qartësi dhe përgjegjësi.</p>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-6">
                <div class="fact">
                  <div class="fw-bold">Praktikë</div>
                  <div class="small text-muted-public">Fokus te aftësitë reale.</div>
                </div>
              </div>
              <div class="col-6">
                <div class="fact">
                  <div class="fw-bold">Transparencë</div>
                  <div class="small text-muted-public">Certifikata me verifikim publik.</div>
                </div>
              </div>
              <div class="col-12">
                <div class="fact d-flex align-items-center justify-content-between gap-3">
                  <span class="fw-bold">QR/token unik</span>
                  <i class="bi bi-qr-code fs-2 text-primary"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section-pad" id="misioni">
    <div class="container-public">
      <div class="section-title mb-4" data-aos="fade-up">
        <h2>Misioni, vizioni dhe vlerat</h2>
        <p>QTA ndërton procese të qarta për trajnim profesional, certifikim transparent dhe përdorim të lehtë të portalit.</p>
      </div>
      <div class="qta-card qta-card-pad" data-aos="fade-up">
        <ul class="nav nav-pills verify-tabs mb-4" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#mission" type="button" role="tab">Misioni</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#vision" type="button" role="tab">Vizioni</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#values" type="button" role="tab">Vlerat</button>
          </li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="mission" role="tabpanel">
            <div class="row g-3 align-items-center">
              <div class="col-lg-7">
                <h3 class="h4 fw-bold">Aftësi praktike dhe trajnim profesional</h3>
                <p class="text-muted-public mb-0">Misioni ynë është të ofrojmë trajnim profesional me fokus te praktika, proces i matshëm dhe standard i qartë për çdo kursant.</p>
              </div>
              <div class="col-lg-5">
                <div class="fact"><i class="bi bi-check2-circle me-2 text-success"></i>Rezultat i kuptueshëm nga kursanti dhe administrata.</div>
              </div>
            </div>
          </div>
          <div class="tab-pane fade" id="vision" role="tabpanel">
            <div class="row g-3 align-items-center">
              <div class="col-lg-7">
                <h3 class="h4 fw-bold">Digjitalizim dhe certifikim transparent</h3>
                <p class="text-muted-public mb-0">Vizioni ynë është një standard më i lartë për trajnime, ku të dhënat, certifikatat dhe verifikimi publik janë të organizuara në një portal të vetëm.</p>
              </div>
              <div class="col-lg-5">
                <div class="fact"><i class="bi bi-shield-check me-2 text-primary"></i>Verifikim publik pa pengesa të panevojshme.</div>
              </div>
            </div>
          </div>
          <div class="tab-pane fade" id="values" role="tabpanel">
            <div class="row g-3">
              <?php foreach (['Korrektësi', 'Transparencë', 'Përgjegjësi', 'Fokus te kursanti'] as $value): ?>
                <div class="col-md-6">
                  <div class="fact h-100"><i class="bi bi-stars me-2 text-primary"></i><strong><?= h($value) ?></strong></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="si-punojme" class="section-pad">
    <div class="container-public">
      <div class="section-title mb-4" data-aos="fade-up">
        <h2>Procesi i QTA</h2>
        <p>Rrjedha kryesore është e njëjtë në desktop dhe mobile: e thjeshtë, e lexueshme dhe e verifikueshme.</p>
      </div>
      <div class="timeline-list">
        <?php
        $items = [
          ['Regjistrimi', 'Të dhënat bazë regjistrohen dhe lidhen me kursin përkatës.'],
          ['Trajnimi', 'Kursanti ndjek programin sipas grupit dhe kalendarit.'],
          ['Vlerësimi', 'Rezultatet dhe statusi i procesit kontrollohen para certifikimit.'],
          ['Certifikimi', 'Certifikata lidhet me të dhëna të kontrolluara dhe token unik.'],
          ['Verifikimi publik', 'QR ose linku kontrollohet pa llogari, me të dhëna minimale.'],
        ];
        foreach ($items as $index => $item):
        ?>
          <div class="timeline-item" data-aos="fade-up" data-aos-delay="<?= (int)$index * 50 ?>">
            <span class="timeline-dot"><?= $index + 1 ?></span>
            <div class="qta-card qta-card-pad">
              <h3 class="h5 fw-bold mb-1"><?= h($item[0]) ?></h3>
              <p class="text-muted-public mb-0"><?= h($item[1]) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="section-pad">
    <div class="container-public">
      <div class="section-title mb-4" data-aos="fade-up">
        <h2>Pse të zgjedhësh QTA</h2>
      </div>
      <div class="swiper qta-swiper" data-aos="fade-up">
        <div class="swiper-wrapper">
          <?php
          $reasons = [
            ['Trajnime praktike', 'Programet fokusohen te aftësitë që përdoren në punë reale.'],
            ['Proces i standardizuar', 'Hapat dhe përgjegjësitë janë të organizuara në portal.'],
            ['Certifikata të verifikueshme', 'Çdo certifikatë mund të kontrollohet publikisht me QR.'],
            ['Mbështetje gjatë procesit', 'Kontakt i qartë për regjistrim, akses dhe pyetje.'],
          ];
          foreach ($reasons as $reason):
          ?>
            <div class="swiper-slide">
              <div class="qta-card qta-card-pad h-100">
                <span class="qta-icon mb-3"><i class="bi bi-check2-circle"></i></span>
                <h3 class="h5 fw-bold"><?= h($reason[0]) ?></h3>
                <p class="text-muted-public mb-0"><?= h($reason[1]) ?></p>
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

  <section class="section-pad" id="siguria">
    <div class="container-public">
      <div class="row g-4 align-items-center">
        <div class="col-lg-6" data-aos="fade-up">
          <div class="section-title">
            <h2>Siguria dhe transparenca</h2>
            <p>Verifikimi publik përdor QR/token unik, shfaq vetëm të dhënat e nevojshme dhe ndihmon në parandalimin e abuzimit me certifikata.</p>
          </div>
        </div>
        <div class="col-lg-6" data-aos="fade-left">
          <div class="row g-3">
            <div class="col-md-6"><div class="fact h-100"><strong>QR/token</strong><div class="small text-muted-public">Identifikues unik për kontroll publik.</div></div></div>
            <div class="col-md-6"><div class="fact h-100"><strong>Pa login</strong><div class="small text-muted-public">Verifikim publik i aksesueshëm.</div></div></div>
            <div class="col-md-6"><div class="fact h-100"><strong>Të dhëna minimale</strong><div class="small text-muted-public">Shfaqet vetëm ajo që duhet për kontroll.</div></div></div>
            <div class="col-md-6"><div class="fact h-100"><strong>Anti-abuzim</strong><div class="small text-muted-public">Status i qartë për vlefshmërinë.</div></div></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section-pad-sm">
    <div class="container-public">
      <div class="cta-band d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3" data-aos="fade-up">
        <div>
          <h2 class="h3 fw-bold mb-2">Dëshironi më shumë informacion?</h2>
          <p class="mb-0 opacity-75">Kontaktoni QTA ose hyni në portalin tuaj sipas rolit.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-light qta-btn" href="contact.php">Na kontaktoni</a>
          <a class="btn btn-outline-light qta-btn" href="<?= h($loginHref) ?>"><?= h($loginText) ?></a>
        </div>
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
