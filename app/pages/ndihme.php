<?php
declare(strict_types=1);
session_start();

/**
 * ndihme.php — Qendra e ndihmës.
 * Me llogari: udhëzimet e rolit brenda portalit. Pa llogari: udhëzimet publike
 * (verifikimi, hyrja) në faqen publike.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../shared/help.php';

$pdo = getPDO();

/* Kush po e shikon */
$role = 'public';
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
  $st = $pdo->prepare("SELECT u.id, u.full_name, u.email, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id LIMIT 1");
  $st->execute([':id' => $_SESSION['user_id']]);
  $currentUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($currentUser) $role = strtolower((string)$currentUser['role_name']);
}
$isApp = in_array($role, ['administrator', 'editor', 'agjencia', 'student'], true);

/* Temat për këtë rol, me "Hapat e parë" në krye */
$topics = array_filter(qta_help_topics(), static fn($t) => in_array($role, $t['roles'] ?? [], true));
if (!$isApp) {
  $topics = ['login' => [
    'title' => 'Si të hyj në portal',
    'intro' => 'Portali ka katër lloje llogarish. Secila hyn me një të dhënë tjetër.',
    'steps' => [
      ['Stafi i QTA', 'Hyn me email-in e punës dhe fjalëkalimin.'],
      ['Agjencitë', 'Hyjnë me NIPT-in e kompanisë (10 shenja) dhe fjalëkalimin që u dha QTA.'],
      ['Kursantët', 'Hyjnë me numrin personal të letërnjoftimit dhe fjalëkalimin.'],
      ['Harrove fjalëkalimin?', 'Te faqja e hyrjes shtyp "Harrova fjalëkalimin" dhe ndiq udhëzimet, ose na kontakto.'],
    ],
    'tips' => ['Pas hyrjes së parë, ndrysho fjalëkalimin te "Profili im".'],
  ]] + $topics;
}

$glossary = [
  'Kursant'            => 'Personi që ndjek një trajnim në QTA.',
  'Nr. i amzës (AMZË)' => 'Numri i regjistrimit të një kursanti në një modul. Një person mund të ketë disa, një për çdo modul.',
  'Modul'              => 'Trajnimi për një zanat ose temë, p.sh. "Saldator me hark elektrik", me orët e veta.',
  'Grup'               => 'Deri në 10 kursantë që ndjekin të njëjtin modul në të njëjtat data.',
  'Provimi dhe pikët'  => 'Çdo kursant ka datën e vet të provimit. Kalon me 50 pikë e lart (nga 100).',
  'Grup i mbyllur'     => 'Grup me provime të përfunduara. Ndryshimet pas mbylljes kërkojnë konfirmim, sepse dokumentet mund të jenë lëshuar.',
  'Agjenci'            => 'Kompania që dërgon punonjësit e saj në trajnim. Hyn me NIPT dhe sheh vetëm punonjësit e vet.',
  'NIPT'               => 'Numri i identifikimit të biznesit: një shkronjë, 8 shifra dhe një shkronjë, p.sh. L42202012A.',
  'Numri personal'     => 'Numri në letërnjoftim. Kursantët hyjnë në portal me të.',
  'Kodi QR'            => 'Kodi që vërteton certifikatat e një personi. Kushdo e skanon me telefon, pa llogari.',
  'Lejo ndryshimet'    => 'Butoni që hap redaktimin e të dhënave. "Mbyll ndryshimet" e mbyll sërish.',
];
if (!$isApp || in_array($role, ['agjencia', 'student'], true)) {
  unset($glossary['Grup i mbyllur'], $glossary['Lejo ndryshimet']);
}

$pageTitle = 'Ndihmë';
$pageDescription = 'Udhëzime të thjeshta për portalin e Regjistrit QTA.';

if ($isApp) {
  $NAV_ACTIVE = 'help';
  if ($role === 'administrator')   require __DIR__ . '/inc/navbar.php';
  elseif ($role === 'editor')      require __DIR__ . '/inc/navbar4.php';
  elseif ($role === 'agjencia')    require __DIR__ . '/inc/navbar2.php';
  else                             require __DIR__ . '/inc/navbar3.php';
  require __DIR__ . '/../shared/app_head.php';
} else {
  require_once __DIR__ . '/../shared/public_ui.php';
  $pageTitle = 'Ndihmë — Regjistri QTA';
  $NAV_ACTIVE = 'help';
  require_once __DIR__ . '/../shared/public_head.php';
  require_once __DIR__ . '/navbarMain.php';
}
?>

<main <?= $isApp ? 'class="app-main"' : 'class="wrap help-public"' ?> id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title">Ndihmë</h1>
      <p class="page-lead">Udhëzime të shkurtra për çdo pjesë të portalit. Zgjidh një temë ose kërko me një fjalë.</p>
    </div>
  </header>

  <div class="search-field is-lg help-search mb-4">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input class="form-control form-control-lg" type="search" id="helpQ" placeholder="P.sh. fjalëkalimi, provimi, grup, QR…" aria-label="Kërko në udhëzime" autocomplete="off">
  </div>
  <p class="text-muted small" id="helpNone" hidden>Asnjë udhëzim nuk përmban këtë fjalë. Provo një fjalë tjetër ose <a href="contact.php">na shkruaj</a>.</p>

  <div class="help-layout">
    <nav class="help-toc" aria-label="Temat e ndihmës">
      <span class="eyebrow">Temat</span>
      <ul>
        <?php foreach ($topics as $key => $t): ?>
          <li data-help-link="<?= h((string)$key) ?>"><a href="#<?= h((string)$key) ?>"><?= h((string)$t['title']) ?></a></li>
        <?php endforeach; ?>
        <li data-help-link="fjalorth"><a href="#fjalorth">Fjalorth</a></li>
      </ul>
    </nav>

    <div class="help-content">
      <?php foreach ($topics as $key => $t): ?>
        <section class="help-topic panel" id="<?= h((string)$key) ?>" data-help-topic="<?= h((string)$key) ?>" aria-labelledby="ht-<?= h((string)$key) ?>">
          <h2 class="section-title mb-3" id="ht-<?= h((string)$key) ?>"><?= h((string)$t['title']) ?></h2>
          <?php qta_help_render_body($t); ?>
        </section>
      <?php endforeach; ?>

      <section class="help-topic panel" id="fjalorth" data-help-topic="fjalorth" aria-labelledby="ht-fjalorth">
        <h2 class="section-title mb-3" id="ht-fjalorth">Fjalorth</h2>
        <dl class="kv">
          <?php foreach ($glossary as $term => $meaning): ?>
            <dt><?= h($term) ?></dt><dd><?= h($meaning) ?></dd>
          <?php endforeach; ?>
        </dl>
      </section>

      <div class="callout mt-4">
        <span class="callout-icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
        <div class="callout-body">
          <span class="callout-title">Nuk e gjete përgjigjen?</span>
          <span class="callout-text">Na shkruani dhe ju përgjigjemi në ditët e punës.</span>
        </div>
        <a class="btn btn-secondary" href="contact.php">Na kontaktoni</a>
      </div>
    </div>
  </div>
</main>

<?php if ($isApp): ?>
  <?php require __DIR__ . '/../shared/app_scripts.php'; ?>
<?php else: ?>
  <?php require_once __DIR__ . '/../shared/footer.php'; ?>
  <?php require_once __DIR__ . '/../shared/public_scripts.php'; ?>
<?php endif; ?>
<script>
(function () {
  /* Kërkimi në udhëzime: fsheh temat që nuk e përmbajnë fjalën */
  var input = document.getElementById('helpQ');
  var none = document.getElementById('helpNone');
  if (!input) return;
  var topics = Array.prototype.slice.call(document.querySelectorAll('[data-help-topic]'));
  var norm = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  var texts = topics.map(function (t) { return norm(t.textContent); });
  var timer = null;
  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      var q = norm(input.value.trim());
      var shown = 0;
      topics.forEach(function (t, i) {
        var hit = q === '' || texts[i].indexOf(q) !== -1;
        t.hidden = !hit;
        var link = document.querySelector('[data-help-link="' + t.getAttribute('data-help-topic') + '"]');
        if (link) link.hidden = !hit;
        if (hit) shown++;
      });
      none.hidden = shown !== 0;
    }, 120);
  });
  /* Tema e hapur nga paneli "Si funksionon?" theksohet shkurt */
  if (location.hash) {
    var target = document.getElementById(location.hash.slice(1));
    if (target && target.classList.contains('help-topic')) {
      target.classList.add('is-focused');
      setTimeout(function () { target.classList.remove('is-focused'); }, 2200);
    }
  }
})();
</script>
</body>
</html>
