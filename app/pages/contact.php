<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);

/* Kontaktet e zyrës — një vend i vetëm për t'i ndryshuar. */
$office = [
  'phone'      => '+355 69 877 8837',
  'phone_href' => 'tel:+355698778837',
  'email'      => 'officialqta@gmail.com',
  'address'    => 'Rruga Bilal Konxholli, Tiranë',
  'map_href'   => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Rruga Bilal Konxholli, Tiranë'),
  'hours'      => 'E hënë – e premte, 09:00–17:00',
];

/* A jemi të hapur tani? (ora e Tiranës; festat nuk llogariten) */
$now  = new DateTimeImmutable('now', new DateTimeZone('Europe/Tirane'));
$dow  = (int)$now->format('N');           // 1 = e hënë … 7 = e diel
$mins = (int)$now->format('G') * 60 + (int)$now->format('i');
$isWorkday = $dow <= 5;
$isOpen = $isWorkday && $mins >= 9 * 60 && $mins < 17 * 60;
if ($isOpen) {
  $openText = 'Jemi të hapur tani · deri në 17:00';
} elseif ($isWorkday && $mins < 9 * 60) {
  $openText = 'Jemi të mbyllur · hapemi sot në 09:00';
} elseif ($dow <= 4) {
  $openText = 'Jemi të mbyllur · hapemi nesër në 09:00';
} else {
  $openText = 'Jemi të mbyllur · hapemi të hënën në 09:00';
}

$topics = ['Kurse dhe data', 'Regjistrim', 'Hyrja në portal', 'Certifikatë', 'Tjetër'];

$NAV_ACTIVE = 'contact';
$pageTitle = 'Kontakt — Regjistri QTA';
$pageDescription = 'Telefononi, shkruani ose ejani në zyrën e QTA-së në Tiranë. Përgjigjemi brenda dy ditëve pune.';
$pageBodyClass = 'page-contact';
$pageScripts = [qta_asset('app/assets/js/contact-ui.js')];

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main id="main" tabindex="-1">

  <!-- Kreu: pyetja e vizitorit dhe mënyrat për të na gjetur -->
  <section class="contact-hero">
    <div class="wrap">
      <div class="contact-hero-head">
        <div>
          <p class="hero-eyebrow"><i class="bi bi-chat-dots" aria-hidden="true"></i>Kontakt</p>
          <h1 class="page-title">Si mund t'ju ndihmojmë?</h1>
          <p class="page-lead">Telefononi, na shkruani ose ejani në zyrë. Për pyetjet me shkrim përgjigjemi brenda dy ditëve pune.</p>
        </div>
        <p class="open-status<?= $isOpen ? ' is-open' : '' ?>" role="status"><?= h($openText) ?></p>
      </div>

      <ul class="reach-grid" aria-label="Mënyrat për të na kontaktuar">
        <li class="reach">
          <span class="reach-icon" aria-hidden="true"><i class="bi bi-telephone"></i></span>
          <p class="reach-label">Telefononi</p>
          <a class="reach-value" href="<?= h($office['phone_href']) ?>"><?= h($office['phone']) ?></a>
          <p class="reach-note"><?= h($office['hours']) ?></p>
          <span class="reach-cta" aria-hidden="true">Telefono tani<i class="bi bi-arrow-right"></i></span>
        </li>
        <li class="reach">
          <span class="reach-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
          <p class="reach-label">Shkruani</p>
          <a class="reach-value" href="mailto:<?= h($office['email']) ?>"><?= h($office['email']) ?></a>
          <p class="reach-note">Përgjigjemi brenda 2 ditëve pune.</p>
          <div class="reach-actions">
            <span class="reach-cta" aria-hidden="true">Shkruaj email<i class="bi bi-arrow-right"></i></span>
            <button class="btn btn-ghost btn-sm reach-copy" type="button" data-copy="<?= h($office['email']) ?>" data-copy-message="Adresa u kopjua.">
              <i class="bi bi-copy" aria-hidden="true"></i>Kopjo adresën
            </button>
          </div>
        </li>
        <li class="reach">
          <span class="reach-icon" aria-hidden="true"><i class="bi bi-geo-alt"></i></span>
          <p class="reach-label">Ejani në zyrë</p>
          <a class="reach-value" href="<?= h($office['map_href']) ?>" target="_blank" rel="noopener"><?= h($office['address']) ?><span class="visually-hidden"> (hapet harta në një skedë të re)</span></a>
          <p class="reach-note">Merrni me vete kartën e identitetit.</p>
          <span class="reach-cta" aria-hidden="true">Hap në hartë<i class="bi bi-box-arrow-up-right"></i></span>
        </li>
      </ul>
    </div>
  </section>

  <!-- Përgjigje të shpejta: shumë pyetje nuk kanë nevojë të presin -->
  <section class="band band-alt" aria-labelledby="faqTitle">
    <div class="wrap faq-layout">
      <div class="band-head">
        <h2 id="faqTitle">Përgjigje të shpejta</h2>
        <p>Shumica e pyetjeve zgjidhen menjëherë, pa pritur përgjigje.</p>
      </div>
      <div class="faq-list">
        <details class="faq">
          <summary><span>Si e kontrolloj nëse një certifikatë është e vërtetë?</span><i class="bi bi-plus-lg faq-icon" aria-hidden="true"></i></summary>
          <div class="faq-body">
            <p>Nuk ju duhet llogari. Hapni faqen e verifikimit dhe skanoni kodin QR të certifikatës me kamerën e telefonit, ose shkruani kodin që është poshtë tij.</p>
            <a class="btn btn-secondary btn-sm" href="verify.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i>Verifiko certifikatë</a>
          </div>
        </details>
        <details class="faq">
          <summary><span>Kam harruar fjalëkalimin</span><i class="bi bi-plus-lg faq-icon" aria-hidden="true"></i></summary>
          <div class="faq-body">
            <p>Për siguri, fjalëkalimin e rivendos vetëm QTA. Na telefononi; kursantët mund të vijnë edhe në zyrë me kartën e identitetit. Pas hyrjes, ndryshojeni te "Profili im".</p>
          </div>
        </details>
        <details class="faq">
          <summary><span>Si regjistrohem në një kurs?</span><i class="bi bi-plus-lg faq-icon" aria-hidden="true"></i></summary>
          <div class="faq-body">
            <p>Punonjësit regjistrohen zakonisht përmes kompanisë (agjencisë) ku punojnë. Mund të vini edhe vetë në zyrë ose të na telefononi. Kurset dhe orët i gjeni në kryefaqe.</p>
            <a class="btn btn-secondary btn-sm" href="index.php#kurset"><i class="bi bi-journal-text" aria-hidden="true"></i>Shiko kurset</a>
          </div>
        </details>
        <details class="faq">
          <summary><span>Kur është provimi dhe si i mësoj pikët?</span><i class="bi bi-plus-lg faq-icon" aria-hidden="true"></i></summary>
          <div class="faq-body">
            <p>Data e provimit caktohet pasi mbaron mësimi i grupit. Kursantët e shohin datën dhe pikët te "Faqja ime" pasi hyjnë në portal; agjencitë e shohin te "Punonjësit tanë".</p>
            <a class="btn btn-secondary btn-sm" href="selectProfile.php"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Hyr në portal</a>
          </div>
        </details>
      </div>
    </div>
  </section>

  <!-- Mesazh me shkrim -->
  <section class="wrap contact-write" aria-labelledby="writeTitle">
    <div class="write-intro">
      <h2 id="writeTitle">Na shkruani</h2>
      <p class="lead-text">Tregoni shkurt çfarë ju duhet. Ne e përgatisim email-in — ju vetëm e dërgoni.</p>
      <ol class="write-steps">
        <li>Plotësoni tre fusha</li>
        <li>Shtypni "Përgatit email-in"</li>
        <li>Dërgojeni nga programi juaj i email-it</li>
      </ol>
      <p class="text-muted small mb-0">Nuk keni program email-i në këtë pajisje? Shtypni "Kopjo mesazhin" dhe ngjiteni ku të doni — email, WhatsApp ose SMS. Asgjë nuk ruhet në regjistër.</p>
    </div>

    <form class="panel write-form" id="contactForm" novalidate data-contact-email="<?= h($office['email']) ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="full_name">Emri dhe mbiemri</label>
          <input class="form-control form-control-lg" id="full_name" name="full_name" type="text" required autocomplete="name">
          <p class="invalid-feedback">Shkruani emrin tuaj.</p>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="reply_to">Email-i ose telefoni</label>
          <input class="form-control form-control-lg" id="reply_to" name="reply_to" type="text" required autocomplete="email" aria-describedby="replyHelp">
          <p class="form-text" id="replyHelp">Që t'ju përgjigjemi.</p>
          <p class="invalid-feedback">Shkruani një email (p.sh. emri@shembull.com) ose një numër telefoni.</p>
        </div>

        <fieldset class="col-12 topic-field">
          <legend class="form-label">Për çfarë bëhet fjalë?</legend>
          <div class="topic-chips">
            <?php foreach ($topics as $i => $t): ?>
              <label class="topic-chip">
                <input type="radio" name="topic" value="<?= h($t) ?>" <?= $i === 0 ? 'required' : '' ?>>
                <span><?= h($t) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="invalid-feedback">Zgjidhni një temë.</p>
        </fieldset>

        <div class="col-12">
          <label class="form-label" for="message">Mesazhi</label>
          <textarea class="form-control" id="message" name="message" rows="5" required
                    placeholder="P.sh. Jemi 6 punonjës dhe na duhet kursi &quot;Punime në lartësi&quot; në tetor."></textarea>
          <p class="invalid-feedback">Shkruani mesazhin.</p>
        </div>

        <div class="col-12 write-actions">
          <button class="btn btn-primary btn-lg" type="submit">
            <i class="bi bi-envelope-arrow-up" aria-hidden="true"></i>Përgatit email-in
          </button>
          <button class="btn btn-secondary btn-lg" type="button" data-contact-copy>
            <i class="bi bi-copy" aria-hidden="true"></i>Kopjo mesazhin
          </button>
        </div>
      </div>
    </form>
  </section>
</main>

<?php
require_once __DIR__ . '/../shared/footer.php';
require_once __DIR__ . '/../shared/public_scripts.php';
?>
</body>
</html>
