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
$figures = ['registered' => 0, 'modules' => 0, 'passed' => 0];
try {
  $figures = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM students) AS registered,
      (SELECT COUNT(*) FROM courses)  AS modules,
      (SELECT COUNT(*) FROM course_group_students WHERE final_score >= 50) AS passed
  ")->fetch(PDO::FETCH_ASSOC) ?: $figures;
} catch (Throwable $e) {
  /* mbaj zerot */
}

/* ===== Modulet që certifikohen ===== */
$modules = [];
try {
  $modules = $pdo->query("
    SELECT code, name, hours
    FROM courses
    ORDER BY name ASC
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  /* pa listë */
}

$NAV_ACTIVE = 'home';
$pageTitle = 'Regjistri QTA — certifikime profesionale';
$pageDescription = 'Regjistri publik i certifikimeve profesionale të Qendrës së Trajnimeve të Avancuara. Verifiko një certifikatë me kod ose QR, pa llogari.';

require_once __DIR__ . '/../shared/public_head.php';
require_once __DIR__ . '/navbarMain.php';
?>
<main id="main" tabindex="-1">

  <section class="hero">
    <div class="wrap hero-grid">
      <div>
        <span class="hero-eyebrow"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>Qendra e Trajnimeve të Avancuara · Tiranë</span>
        <h1 class="hero-title">Regjistri i certifikimeve profesionale</h1>
        <p class="hero-lead">
          Këtu mbahen kualifikimet e punonjësve në zanatet e ndërtimit dhe në sigurinë në punë.
          Çdo certifikatë ka një kod unik — kushdo mund ta kontrollojë, pa llogari.
        </p>
        <div class="hero-actions">
          <a class="btn btn-primary btn-lg" href="verify.php">
            <i class="bi bi-qr-code-scan" aria-hidden="true"></i>Verifiko një certifikatë
          </a>
          <?php if ($currentUser): ?>
            <a class="btn btn-secondary btn-lg" href="<?= h($panelHref) ?>">Vazhdo te <?= h(mb_strtolower(qta_public_panel_label($role))) ?></a>
          <?php else: ?>
            <a class="btn btn-secondary btn-lg" href="selectProfile.php">Hyr në sistem</a>
          <?php endif; ?>
        </div>

        <ul class="figures" aria-label="Regjistri në shifra">
          <li><b><?= number_format((int)$figures['registered'], 0, ',', '.') ?></b><span>regjistrime</span></li>
          <li><b><?= number_format((int)$figures['modules'], 0, ',', '.') ?></b><span>module</span></li>
          <li><b><?= number_format((int)$figures['passed'], 0, ',', '.') ?></b><span>provime të kaluara</span></li>
        </ul>
      </div>

      <div class="verify-card">
        <h2 class="verify-card-title"><i class="bi bi-shield-check" aria-hidden="true"></i>Kontrollo një certifikatë</h2>
        <p>Shkruaj kodin që gjendet poshtë kodit QR në certifikatë.</p>
        <form method="get" action="verify.php" role="search" aria-label="Kontrollo një certifikatë">
          <label class="form-label" for="homeCode">Kodi i certifikatës</label>
          <div class="d-flex gap-2">
            <input class="form-control form-control-lg input-code" id="homeCode" name="t" type="text"
                   inputmode="text" autocomplete="off" autocapitalize="off" spellcheck="false"
                   placeholder="p.sh. 77d9f938…" required>
            <button class="btn btn-primary btn-lg" type="submit">Kontrollo</button>
          </div>
        </form>
        <div class="or-divider">ose</div>
        <a class="btn btn-secondary w-100" href="verify.php#skano">
          <i class="bi bi-camera" aria-hidden="true"></i>Skano kodin QR me kamerë
        </a>
      </div>
    </div>
  </section>

  <section class="band band-alt" id="si-funksionon" aria-labelledby="howTitle">
    <div class="wrap">
      <div class="band-head">
        <h2 id="howTitle">Si funksionon</h2>
        <p>Çdo kualifikim kalon të njëjtët katër hapa, me të njëjtat rregulla për të gjithë.</p>
      </div>
      <ol class="steps-grid">
        <li><h3>Regjistrimi</h3><p>Punonjësi regjistrohet vetë ose nga kompania që e dërgon. Merr një numër amze.</p></li>
        <li><h3>Trajnimi në grup</h3><p>Caktohet në një grup të modulit, me datë fillimi dhe mbarimi.</p></li>
        <li><h3>Provimi</h3><p>Në fund jepet provimi. Kalon kush merr 50 pikë ose më shumë.</p></li>
        <li><h3>Certifikata</h3><p>Certifikata lëshohet me kod unik dhe QR, që kontrollohet publikisht.</p></li>
      </ol>
    </div>
  </section>

  <section class="band" id="modulet" aria-labelledby="modTitle">
    <div class="wrap">
      <div class="band-head">
        <h2 id="modTitle">Modulet që certifikojmë</h2>
        <p><?= count($modules) ?> module në zanatet e ndërtimit dhe në sigurinë në punë.</p>
      </div>
      <?php if ($modules): ?>
        <ul class="module-grid">
          <?php foreach ($modules as $m): ?>
            <li class="module-item">
              <span class="module-code"><?= h((string)$m['code']) ?></span>
              <span class="module-name"><?= h((string)$m['name']) ?></span>
              <?php if ((int)($m['hours'] ?? 0) > 0): ?>
                <span class="module-hours"><?= (int)$m['hours'] ?> orë</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <?= qta_empty('Lista e moduleve nuk u ngarkua', 'Provo ta rifreskosh faqen. Nëse vazhdon, na kontakto.', 'bi-book') ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="band band-alt" aria-labelledby="whoTitle">
    <div class="wrap">
      <div class="band-head">
        <h2 id="whoTitle">Për kë është</h2>
        <p>Secili sheh vetëm atë që i duhet.</p>
      </div>
      <div class="audience-grid">
        <div class="audience">
          <span class="audience-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
          <h3>Kursanti</h3>
          <p>Sheh modulet e veta, datat e provimit, rezultatet dhe kodin QR të certifikatave.</p>
          <a href="selectProfile.php?role=student">Hyr si kursant</a>
        </div>
        <div class="audience">
          <span class="audience-icon"><i class="bi bi-building" aria-hidden="true"></i></span>
          <h3>Agjencia</h3>
          <p>Kompania ndjek punonjësit e saj: grupet, provimet dhe dokumentet.</p>
          <a href="selectProfile.php?role=agjencia">Hyr si agjenci</a>
        </div>
        <div class="audience">
          <span class="audience-icon"><i class="bi bi-cone-striped" aria-hidden="true"></i></span>
          <h3>Inspektori</h3>
          <p>Skanon QR-në në kantier dhe merr menjëherë përgjigjen: e vlefshme apo jo.</p>
          <a href="verify.php">Verifiko një certifikatë</a>
        </div>
        <div class="audience">
          <span class="audience-icon"><i class="bi bi-person-workspace" aria-hidden="true"></i></span>
          <h3>Stafi i QTA-së</h3>
          <p>Mban regjistrin, cakton grupet, shënon provimet dhe lëshon certifikatat.</p>
          <a href="selectProfile.php?role=staff">Hyr si staf</a>
        </div>
      </div>
    </div>
  </section>

  <section class="band">
    <div class="wrap">
      <div class="closing">
        <div>
          <h2>Ke një certifikatë për të kontrolluar?</h2>
          <p>Kontrolli është publik, i menjëhershëm dhe nuk kërkon llogari.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-primary btn-lg" href="verify.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i>Hap verifikimin</a>
          <a class="btn btn-secondary btn-lg" href="contact.php">Na kontakto</a>
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
