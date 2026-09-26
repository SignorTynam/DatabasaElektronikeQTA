<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);
$role = qta_public_role($currentUser);
$loginHref = $currentUser ? qta_public_panel_href($role) : 'selectProfile.php';
$loginText = $currentUser ? 'Vazhdo te ' . mb_strtolower(qta_public_panel_label($role)) : 'Hyr në sistem';

/* Shifrat e institucionit — nga regjistri, jo nga marketingu. */
$figures = ['registered' => 0, 'modules' => 0, 'groups' => 0];
try {
  $figures = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM students)      AS registered,
      (SELECT COUNT(*) FROM courses)       AS modules,
      (SELECT COUNT(*) FROM course_groups) AS `groups`
  ")->fetch(PDO::FETCH_ASSOC) ?: $figures;
} catch (Throwable $e) {
  /* mbaj zerot */
}

$NAV_ACTIVE = 'about';
$pageTitle = 'Rreth nesh — Regjistri QTA';
$pageDescription = 'Qendra e Trajnimeve të Avancuara: çfarë certifikon, si e bën dhe si kontrollohet publikisht.';

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main id="main" tabindex="-1">

  <section class="wrap page-intro">
    <span class="hero-eyebrow"><i class="bi bi-buildings" aria-hidden="true"></i>Rreth nesh · Tiranë</span>
    <h1 class="page-title">Qendra e Trajnimeve të Avancuara</h1>
    <p class="page-lead">
      Kualifikojmë dhe certifikojmë punonjës në zanatet e ndërtimit dhe në sigurinë në punë.
      Çdo certifikatë hyn në një regjistër që mund ta kontrollojë kushdo.
    </p>
    <ul class="figures" aria-label="Regjistri në shifra">
      <li><b><?= number_format((int)$figures['registered'], 0, ',', '.') ?></b><span>regjistrime</span></li>
      <li><b><?= number_format((int)$figures['modules'], 0, ',', '.') ?></b><span>kurse</span></li>
      <li><b><?= number_format((int)$figures['groups'], 0, ',', '.') ?></b><span>grupe trajnimi</span></li>
    </ul>
  </section>

  <section class="band band-alt" aria-labelledby="whatTitle">
    <div class="wrap">
      <div class="row g-4 g-lg-5 align-items-start">
        <div class="col-lg-7">
          <div class="band-head mb-4">
            <h2 id="whatTitle">Çfarë bëjmë</h2>
          </div>
          <p class="lead-text">
            Puna jonë nis kur një punonjës ose një kompani kërkon një kualifikim, dhe mbaron kur
            certifikata hyn në regjistër me kodin e vet. Midis tyre ka një procedurë të njëjtë
            për të gjithë: caktimi në grup, trajnimi, provimi dhe procesverbali.
          </p>
          <p class="lead-text mb-0">
            Nuk mbajmë më shumë të dhëna se ç'duhet. Verifikimi publik tregon vetëm atë që
            nevojitet për të konfirmuar se një kualifikim është i vërtetë.
          </p>
        </div>
        <div class="col-lg-5">
          <div class="panel">
            <dl class="kv">
              <dt>Fusha</dt><dd>Zanate ndërtimi dhe siguri në punë</dd>
              <dt>Dëshmia</dt><dd>Certifikatë me kod unik dhe QR</dd>
              <dt>Kontrolli</dt><dd>Publik, pa llogari, i menjëhershëm</dd>
              <dt>Regjistri</dt><dd>Çdo ndryshim shënohet me emrin e personit që e bëri</dd>
            </dl>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="band" aria-labelledby="howTitle">
    <div class="wrap">
      <div class="band-head">
        <h2 id="howTitle">Si e mbajmë regjistrin</h2>
        <p>Katër parime që e bëjnë certifikatën të besueshme.</p>
      </div>
      <ol class="steps-grid">
        <li><h3>Një procedurë për të gjithë</h3><p>I njëjti rrugëtim për çdo kursant, pavarësisht kush e dërgon.</p></li>
        <li><h3>Çdo veprim ka autor</h3><p>Regjistrimi, nota dhe mbyllja e grupit shënohen me personin dhe kohën.</p></li>
        <li><h3>Të dhëna minimale</h3><p>Verifikimi tregon vetëm sa duhet për të konfirmuar vlefshmërinë.</p></li>
        <li><h3>Kontroll i hapur</h3><p>Punëdhënësi, inspektori ose vetë punonjësi mund ta kontrollojnë.</p></li>
      </ol>
    </div>
  </section>

  <section class="band">
    <div class="wrap">
      <div class="closing">
        <div>
          <h2>Keni pyetje për një kualifikim?</h2>
          <p>Na shkruani ose hyni në llogarinë tuaj.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-primary btn-lg" href="contact.php"><i class="bi bi-envelope" aria-hidden="true"></i>Na kontaktoni</a>
          <a class="btn btn-secondary btn-lg" href="<?= h($loginHref) ?>"><?= h($loginText) ?></a>
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
