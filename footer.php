<?php
declare(strict_types=1);

/**
 * QTA Footer (shared partial)
 * Përdor: require_once __DIR__ . '/footer.php';
 *
 * Shënim: Stilet injektohen vetëm herën e parë (me një guard konstant).
 */
if (!defined('QTA_FOOTER_CSS')): define('QTA_FOOTER_CSS', true); ?>
  <style id="qta-footer-style">
    :root{
      /* fallback nëse nuk ekzistojnë nga tema kryesore */
      --g1:#0ea5e9; --g2:#2563eb; --g3:#4f46e5;
    }
    footer.qta-footer{
      color:#e5e7eb;
      background:
        radial-gradient(900px 320px at 90% -5%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, var(--g1) 0%, var(--g2) 55%, var(--g3) 100%);
      position: relative;
      overflow: hidden;
    }
    .qta-footer .glass{
      background:rgba(0,0,0,.35);
      border:1px solid rgba(255,255,255,.18);
      backdrop-filter: blur(10px);
      border-radius:1rem;
      padding:1.25rem;
    }
    .qta-footer a{ color:#ffffff; opacity:.95; text-decoration: none; }
    .qta-footer a:hover{ opacity:1; text-decoration: underline; }
    .qta-footer .icon-link i{ font-size:1.1rem; }
    .qta-footer hr{ border-color:rgba(255,255,255,.35); opacity:.25; }
    .qta-footer .muted{ opacity:.9; }
    .qta-footer .list-unstyled li{ margin:.3rem 0; }

    /* Back to top */
    .qta-backtop{
      position: fixed; right:16px; bottom:16px; z-index: 1030;
      opacity:0; pointer-events:none; transition: .2s ease;
    }
    .qta-backtop.show{ opacity:1; pointer-events:auto; }
  </style>
<?php endif; ?>

<!-- Footer -->
<footer class="qta-footer py-5">
  <div class="container">
    <div class="row gy-4">
      <div class="col-lg-4">
        <div class="glass h-100">
          <h5 class="mb-2">Qendra e Trajnimeve të Avancuara</h5>
          <p class="small muted mb-3">Ofrimi i edukimit me cilësi të lartë për profesionistët e të nesërmes.</p>
          <div class="d-flex gap-3">
            <a class="icon-link" href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
            <a class="icon-link" href="#" aria-label="Twitter"><i class="bi bi-twitter"></i></a>
            <a class="icon-link" href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
            <a class="icon-link" href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="glass h-100">
          <h5 class="mb-2">Lidhje të shpejta</h5>
          <ul class="list-unstyled small mb-0">
            <li><a href="index.php">Kryefaqja</a></li>
            <li><a href="aboutus.php">Rreth Nesh</a></li>
            <li><a href="#">Kurset</a></li>
            <li><a href="contact.html">Kontakt</a></li>
            <li><a href="verify.php">Verifiko Certifikatën</a></li>
          </ul>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="glass h-100">
          <h5 class="mb-2">Na gjeni këtu</h5>
          <p class="small mb-1"><i class="bi bi-geo-alt me-2"></i>Rruga Bilal Konxholli, Tiranë</p>
          <p class="small mb-1"><i class="bi bi-telephone me-2"></i>+355 69 877 8837</p>
          <p class="small mb-0"><i class="bi bi-envelope me-2"></i>officialqta@gmail.com</p>
        </div>
      </div>
    </div>

    <hr class="my-4">
    <p class="text-center small mb-0">&copy; <?= date('Y') ?> Qendra e Trajnimeve të Avancuara. Të gjitha të drejtat e rezervuara.</p>
  </div>
</footer>

<!-- Back-to-top button (opsional) -->
<button type="button" class="btn btn-light btn-sm rounded-3 shadow qta-backtop" id="qtaBackTop" aria-label="Kthehu në krye">
  <i class="bi bi-arrow-up"></i>
</button>

<script>
  // Shfaq butonin "back to top" pas një scroll-i të vogël
  (function(){
    const btn = document.getElementById('qtaBackTop');
    if(!btn) return;
    const onScroll = () => {
      if (window.scrollY > 280) btn.classList.add('show');
      else btn.classList.remove('show');
    };
    window.addEventListener('scroll', onScroll, { passive:true });
    btn.addEventListener('click', () => window.scrollTo({top:0, behavior:'smooth'}));
    onScroll();
  })();
</script>
