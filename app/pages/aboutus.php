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

/* Shifrat e institucionit — nga regjistri, jo nga marketingu. */
$figures = ['certified' => 0, 'modules' => 0, 'groups' => 0];
try {
  $figures = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM students)      AS certified,
      (SELECT COUNT(*) FROM courses)       AS modules,
      (SELECT COUNT(*) FROM course_groups) AS groups
  ")->fetch(PDO::FETCH_ASSOC) ?: $figures;
} catch (Throwable $e) {
  /* mbaj zerot */
}

$NAV_ACTIVE = 'about';
$pageTitle = 'Institucioni — Regjistri QTA';
$pageDescription = 'Qendra e Trajnimeve të Avancuara: çfarë certifikon, si e bën dhe si kontrollohet publikisht.';

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main class="wrap">

  <!-- ====================================================== KOKA ========= -->
  <section style="padding-top:2rem">
    <div class="protocol-line">
      <span>Republika e Shqipërisë</span>
      <span class="sep">·</span>
      <span>Tiranë</span>
      <span class="sep">·</span>
      <span>Institucion i akredituar</span>
    </div>

    <div class="title-block">
      <div class="title-block-main">
        <div class="title-block-eyebrow">Institucioni</div>
        <h1>Qendra e Trajnimeve të Avancuara</h1>
        <p class="title-block-note">
          Kualifikojmë dhe certifikojmë punonjës në zanatet e ndërtimit dhe në sigurinë e
          shëndetit në punë. Çdo certifikatë hyn në një regjistër që kontrollohet publikisht.
        </p>
      </div>
      <div class="title-block-fields">
        <div class="title-block-field">
          <span class="label">Selia</span>
          <span class="value">Tiranë</span>
        </div>
      </div>
    </div>

    <div class="tally">
      <div class="tally-cell">
        <span class="label">Të regjistruar</span>
        <span class="tally-value"><?= number_format((int)$figures['certified']) ?></span>
      </div>
      <div class="tally-cell">
        <span class="label">Module</span>
        <span class="tally-value"><?= number_format((int)$figures['modules']) ?></span>
      </div>
      <div class="tally-cell">
        <span class="label">Grupe</span>
        <span class="tally-value"><?= number_format((int)$figures['groups']) ?></span>
      </div>
    </div>
  </section>

  <!-- ====================================================== ÇFARË BËJMË == -->
  <section class="band band-rule" id="si-punojme">
    <div class="band-head">
      <h2>Çfarë bëjmë</h2>
    </div>

    <div class="row g-4">
      <div class="col-lg-7">
        <p class="prose">
          Puna jonë nis kur një punonjës ose një kompani kërkon një kualifikim, dhe mbaron kur
          certifikata hyn në regjistër me kodin e vet. Midis tyre ka një procedurë të njëjtë
          për të gjithë: caktimi në grup, trajnimi, provimi dhe procesverbali.
        </p>
        <p class="prose">
          Nuk mbajmë të dhëna më shumë se ç'duhet. Verifikimi publik tregon vetëm atë që
          nevojitet për të konfirmuar se një kualifikim është i vërtetë dhe në fuqi — asgjë më tepër.
        </p>
      </div>

      <div class="col-lg-5">
        <dl style="margin:0;border-top:1px solid var(--rule)">
          <?php
          $facts = [
            ['Fusha', 'Zanate ndërtimi dhe siguri në punë'],
            ['Dëshmia', 'Certifikatë me kod unik dhe QR'],
            ['Kontrolli', 'Publik, pa llogari, i menjëhershëm'],
            ['Regjistri', 'Çdo ndryshim shënohet dhe i atribuohet'],
          ];
          foreach ($facts as $f): ?>
            <div style="display:grid;grid-template-columns:7rem 1fr;gap:1rem;padding:.7rem 0;border-bottom:1px solid var(--rule-hair)">
              <dt class="label" style="padding-top:.15rem"><?= h($f[0]) ?></dt>
              <dd style="margin:0;font-family:var(--font-record);font-size:var(--fs-md)"><?= h($f[1]) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </div>
    </div>
  </section>

  <!-- ====================================================== PARIME ======= -->
  <section class="band band-rule">
    <div class="band-head">
      <h2>Si e mbajmë regjistrin</h2>
      <span class="label">Katër parime</span>
    </div>

    <div class="steps">
      <?php
      /* Sekuencë e vërtetë: secili parim varet nga i mëparshmi. */
      $principles = [
        ['Një procedurë për të gjithë', 'I njëjti rrugëtim për çdo kursant, pavarësisht kush e dërgon. Kjo e bën certifikatën të krahasueshme.'],
        ['Çdo veprim ka autor', 'Regjistrimi, nota dhe mbyllja e grupit shënohen me përdoruesin që i kreu dhe me kohën.'],
        ['Të dhëna minimale', 'Faqja e verifikimit tregon vetëm sa duhet për të konfirmuar vlefshmërinë.'],
        ['Kontroll i hapur', 'Kushdo mund ta kontrollojë një certifikatë — punëdhënësi, inspektori ose vetë punonjësi.'],
      ];
      foreach ($principles as $pr): ?>
        <div class="step">
          <div>
            <h3><?= h($pr[0]) ?></h3>
            <p><?= h($pr[1]) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ====================================================== MBYLLJA ====== -->
  <section class="band band-tight">
    <div class="closing">
      <div>
        <h2>Keni pyetje për një kualifikim?</h2>
        <p>Shkruani administratës ose hyni në panelin tuaj sipas rolit.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-ink btn-lg" href="contact.php">Na shkruani</a>
        <a class="btn btn-lg" href="<?= h($loginHref) ?>"><?= h($loginText) ?></a>
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
