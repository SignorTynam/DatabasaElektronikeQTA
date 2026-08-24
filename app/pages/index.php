<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/public_ui.php';

$pdo = getPDO();
$currentUser = qta_public_current_user($pdo);
$role = qta_public_role($currentUser);
$panelHref = qta_public_panel_href($role);

/* ===== Shifrat e regjistrit — të vërteta, jo dekorative ===== */
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

/* ===== Indeksi i moduleve — përmbajtja e vërtetë e regjistrit ===== */
$modules = [];
try {
  $modules = $pdo->query("
    SELECT code, name
    FROM courses
    ORDER BY code ASC
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  /* pa indeks */
}

$NAV_ACTIVE = 'home';
$pageTitle = 'Regjistri QTA — certifikime profesionale';
$pageDescription = 'Regjistri publik i certifikimeve profesionale të Qendrës së Trajnimeve të Avancuara. Verifiko një certifikatë me kod ose QR.';

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main>

  <!-- ================================================== KAPAKU ========= -->
  <section class="cover">
    <div class="wrap">
      <div class="cover-grid">

        <div>
          <div class="protocol-line">
            <span>Republika e Shqipërisë</span>
            <span class="sep">·</span>
            <span>Tiranë</span>
            <span class="sep">·</span>
            <span>Regjistër publik</span>
            <span class="sep">·</span>
            <span><b><?= h(date('Y')) ?></b></span>
          </div>

          <h1>Regjistri i certifikimeve profesionale</h1>

          <p class="prose-lead">
            Këtu regjistrohen kualifikimet e punonjësve në zanatet e ndërtimit dhe në sigurinë e
            shëndetit në punë. Çdo certifikatë mban një kod unik dhe kontrollohet publikisht —
            pa llogari dhe pa kërkesë.
          </p>

          <div class="cover-actions">
            <a class="btn btn-ink btn-lg" href="verify.php">
              <i class="bi bi-patch-check"></i>Verifiko një certifikatë
            </a>
            <a class="btn btn-lg" href="#modulet">Shih modulet</a>
          </div>
        </div>

        <div>
          <!-- Veprimi që publiku vjen të bëjë vërtet, pikërisht në kapak. -->
          <form class="check-box" method="get" action="verify.php">
            <span class="label">Kontroll i shpejtë</span>
            <div class="check-row">
              <input class="input input-code" type="text" name="t" inputmode="latin"
                     placeholder="Kodi i certifikatës" aria-label="Kodi i certifikatës">
              <button class="btn btn-ink" type="submit">Kontrollo</button>
            </div>
            <p class="check-note">
              Kodin e gjen nën QR-in e certifikatës. Mund të skanosh edhe drejtpërdrejt te
              <a href="verify.php">faqja e verifikimit</a>.
            </p>
          </form>

          <div class="tally mt-3 mb-0">
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
        </div>

      </div>
    </div>
  </section>

  <!-- ================================================== MODULET ======== -->
  <section class="band band-rule" id="modulet">
    <div class="wrap">
      <div class="band-head">
        <h2>Indeksi i moduleve</h2>
        <span class="label"><?= count($modules) ?> zëra</span>
      </div>

      <?php if ($modules): ?>
        <ul class="index-list">
          <?php foreach ($modules as $m): ?>
            <li class="index-row">
              <span class="code"><?= h((string)$m['code']) ?></span>
              <span class="name"><?= h((string)$m['name']) ?></span>
              <span class="dots" aria-hidden="true"></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="blank">
          <span class="blank-title">Indeksi nuk u ngarkua</span>
          <span class="blank-note">Provo ta rifreskosh faqen. Nëse vazhdon, na shkruaj.</span>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ================================================== PROCEDURA ====== -->
  <section class="band band-rule" id="procedura">
    <div class="wrap">
      <div class="band-head">
        <h2>Procedura</h2>
        <span class="label">Katër hapa</span>
      </div>

      <div class="steps">
        <?php
        /* Numërimi këtu mban informacion: hapat ndodhin në këtë rend, gjithnjë. */
        $steps = [
          ['Regjistrimi', 'Kursanti regjistrohet vetë ose nëpërmjet kompanisë që e dërgon. Të dhënat hyjnë në regjistër me numër amze.'],
          ['Grupi dhe trajnimi', 'Kursanti caktohet në një grup me datë nisjeje dhe mbarimi, sipas modulit të zgjedhur.'],
          ['Provimi', 'Në fund të modulit mbahet provimi dhe rezultati shënohet në procesverbal.'],
          ['Certifikata', 'Certifikata lëshohet me kod unik dhe QR. Nga ai çast kontrollohet publikisht nga kushdo.'],
        ];
        foreach ($steps as $s): ?>
          <div class="step">
            <div>
              <h3><?= h($s[0]) ?></h3>
              <p><?= h($s[1]) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ================================================== PËR KË ========= -->
  <section class="band band-rule">
    <div class="wrap">
      <div class="band-head">
        <h2>Kush e përdor</h2>
      </div>

      <div class="audience">
        <div class="audience-cell">
          <span class="label">Punonjësi</span>
          <h3>Kursanti</h3>
          <p>Sheh modulet e ndjekura, datat e grupit dhe certifikatat e veta në një vend.</p>
        </div>
        <div class="audience-cell">
          <span class="label">Punëdhënësi</span>
          <h3>Kompania</h3>
          <p>Regjistron punonjësit në module, ndjek grupet dhe merr dokumentet e nevojshme.</p>
        </div>
        <div class="audience-cell">
          <span class="label">Kontrolli</span>
          <h3>Inspektori</h3>
          <p>Skanon QR-in te kantieri dhe merr përgjigje të menjëhershme për vlefshmërinë.</p>
        </div>
        <div class="audience-cell">
          <span class="label">Institucioni</span>
          <h3>Stafi i QTA</h3>
          <p>Mban regjistrin, cakton grupet, shënon provimet dhe lëshon certifikatat.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ================================================== MBYLLJA ======== -->
  <section class="band band-tight">
    <div class="wrap">
      <div class="closing">
        <div>
          <h2>Ke një certifikatë për të kontrolluar?</h2>
          <p>Kontrolli është publik, i menjëhershëm dhe nuk kërkon llogari.</p>
        </div>
        <a class="btn btn-ink btn-lg" href="verify.php">
          <i class="bi bi-patch-check"></i>Hap verifikimin
        </a>
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
