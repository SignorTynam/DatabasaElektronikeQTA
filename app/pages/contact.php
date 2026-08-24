<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);

$NAV_ACTIVE = 'contact';
$pageTitle = 'Kontakt — Regjistri QTA';
$pageDescription = 'Shkruaji administratës së Qendrës së Trajnimeve të Avancuara.';
$pageScripts = ['app/assets/js/contact-ui.js'];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main class="wrap">

  <!-- ====================================================== KOKA ========= -->
  <section style="padding-top:2rem">
    <div class="protocol-line">
      <span>Qendra e Trajnimeve të Avancuara</span>
      <span class="sep">·</span>
      <span>Tiranë</span>
      <span class="sep">·</span>
      <span>Administrata</span>
    </div>

    <div class="title-block">
      <div class="title-block-main">
        <div class="title-block-eyebrow">Kontakt</div>
        <h1>Shkruaji administratës</h1>
        <p class="title-block-note">
          Për kualifikime, regjistrime ose probleme me hyrjen në sistem. Për të kontrolluar
          një certifikatë nuk duhet të na shkruash — <a href="verify.php">hap verifikimin</a>.
        </p>
      </div>
      <div class="title-block-fields">
        <div class="title-block-field">
          <span class="label">Përgjigje brenda</span>
          <span class="value">2 ditë pune</span>
        </div>
      </div>
    </div>
  </section>

  <!-- ====================================================== TRUPI ======== -->
  <section class="band band-tight">
    <div class="row g-4 g-lg-5">

      <!-- Të dhënat e kontaktit -->
      <div class="col-lg-4">
        <span class="label" style="display:block;padding-bottom:.5rem;border-bottom:1px solid var(--rule)">
          Të dhënat
        </span>

        <dl style="margin:0">
          <?php
          $contacts = [
            ['Adresa',   'Rruga Bilal Konxholli, Tiranë', null],
            ['Telefon',  '+355 69 877 8837', 'tel:+355698778837'],
            ['Email',    'officialqta@gmail.com', 'mailto:officialqta@gmail.com'],
            ['Orari',    'E hënë – E premte, 09:00–17:00', null],
          ];
          foreach ($contacts as $c): ?>
            <div style="padding:.8rem 0;border-bottom:1px solid var(--rule-hair)">
              <dt class="label" style="margin-bottom:.15rem"><?= h($c[0]) ?></dt>
              <dd style="margin:0;font-family:var(--font-record);font-size:var(--fs-md)">
                <?php if ($c[2]): ?>
                  <a href="<?= h($c[2]) ?>"><?= h($c[1]) ?></a>
                <?php else: ?>
                  <?= h($c[1]) ?>
                <?php endif; ?>
              </dd>
            </div>
          <?php endforeach; ?>
        </dl>

        <div class="mt-4" style="padding:.9rem 1rem;border:1px solid var(--rule);border-left:4px solid var(--ref);border-radius:var(--r-sm);background:var(--ref-wash)">
          <span class="label" style="margin-bottom:.2rem">Shënim</span>
          <p style="margin:0;font-size:var(--fs-sm)">
            Formulari më poshtë e përgatit mesazhin dhe e hap në programin tënd të email-it.
            Asgjë nuk ruhet në regjistër.
          </p>
        </div>
      </div>

      <!-- Formulari -->
      <div class="col-lg-8">
        <div class="leaf">
          <div class="leaf-head">
            <span class="ui-title">Përgatit mesazhin</span>
            <span class="label" data-contact-count>Të gjitha fushat me yll janë të detyrueshme</span>
          </div>

          <div class="leaf-body">
            <div class="progress mb-4" style="height:3px">
              <div class="progress-bar" data-contact-progress style="width:12%"></div>
            </div>

            <form id="contactForm" class="needs-validation" novalidate>
              <div class="row g-3">

                <div class="col-md-6">
                  <label class="label" for="full_name">Emër dhe mbiemër *</label>
                  <input class="input" id="full_name" name="full_name" type="text" required autocomplete="name">
                </div>

                <div class="col-md-6">
                  <label class="label" for="email">Email *</label>
                  <input class="input" id="email" name="email" type="email" required autocomplete="email">
                </div>

                <div class="col-md-6">
                  <label class="label" for="phone">Telefon</label>
                  <input class="input input-code" id="phone" name="phone" type="tel" autocomplete="tel">
                </div>

                <div class="col-md-6">
                  <label class="label" for="request_type">Lloji i kërkesës *</label>
                  <select class="select" id="request_type" name="request_type" required>
                    <option value="">Zgjidh llojin</option>
                    <option>Informacion për module</option>
                    <option>Regjistrim kursantësh</option>
                    <option>Problem me hyrjen</option>
                    <option>Verifikim certifikate</option>
                    <option>Bashkëpunim</option>
                    <option>Tjetër</option>
                  </select>
                </div>

                <div class="col-12">
                  <label class="label" for="subject">Subjekti *</label>
                  <input class="input" id="subject" name="subject" type="text" required>
                </div>

                <div class="col-12">
                  <label class="label" for="message">Mesazhi *</label>
                  <textarea class="textarea" id="message" name="message" rows="7" required></textarea>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2 pt-2">
                  <a class="btn btn-ink btn-lg" id="contactMailto" href="mailto:officialqta@gmail.com">
                    <i class="bi bi-envelope"></i>Hap në email
                  </a>
                  <a class="btn btn-lg" href="verify.php">Verifiko një certifikatë</a>
                </div>

              </div>
            </form>
          </div>
        </div>
      </div>

    </div>
  </section>

</main>

<?php
require_once __DIR__ . '/../shared/footer.php';
require_once __DIR__ . '/../shared/public_scripts.php';
?>
</body>
</html>
