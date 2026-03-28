<?php
declare(strict_types=1);

/**
 * QTA Footer (shared partial) — Light / Slim / Semi-transparent
 * Përdor: require_once __DIR__ . '/footer.php';
 *
 * CSS injektohet vetëm 1 herë (guard).
 */
if (!defined('QTA_FOOTER_CSS')): define('QTA_FOOTER_CSS', true); ?>
<style id="qta-footer-style">
  /* ==========================================================
     QTA FOOTER — CSS i izoluar (vetëm brenda footer.qta-footer)
     ========================================================== */
  footer.qta-footer{
    --qf-bg: rgba(255,255,255,.80);
    --qf-bd: rgba(15,23,42,.10);
    --qf-text:#0f172a;
    --qf-muted:#64748b;
    --qf-primary:#2563eb;

    position: relative;
    color: var(--qf-text);
    background: var(--qf-bg);
    border-top: 1px solid var(--qf-bd);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 -18px 40px rgba(2,6,23,.06);
    overflow: hidden;
  }

  /* një vijë e hollë “accent” sipër, shumë diskrete */
  footer.qta-footer::before{
    content:"";
    position:absolute; left:0; right:0; top:0;
    height:2px;
    background: linear-gradient(90deg, rgba(37,99,235,.0), rgba(37,99,235,.35), rgba(79,70,229,.25), rgba(14,165,233,.18), rgba(37,99,235,.0));
    pointer-events:none;
  }

  footer.qta-footer .qf-title{
    font-weight: 900;
    letter-spacing: .2px;
    margin-bottom: .25rem;
  }
  footer.qta-footer .qf-muted{
    color: var(--qf-muted);
  }

  /* “box” shumë i thjeshtë, jo glass i rëndë */
  footer.qta-footer .qf-box{
    background: rgba(255,255,255,.55);
    border: 1px solid rgba(15,23,42,.08);
    border-radius: 14px;
    padding: 1rem 1.05rem;
  }

  footer.qta-footer a{
    color: var(--qf-text);
    text-decoration: none;
  }
  footer.qta-footer a:hover{
    text-decoration: underline;
  }

  footer.qta-footer .qf-links li{ margin: .35rem 0; }
  footer.qta-footer .qf-links a{
    color: rgba(15,23,42,.85);
    font-weight: 700;
  }
  footer.qta-footer .qf-links a:hover{
    color: var(--qf-text);
  }

  footer.qta-footer .qf-meta{
    border-top: 1px solid rgba(15,23,42,.10);
    margin-top: 1.25rem;
    padding-top: 1rem;
  }

  /* social icons — minimal, “human” */
  footer.qta-footer .qf-social a{
    width: 38px; height: 38px;
    display:inline-flex; align-items:center; justify-content:center;
    border-radius: 9999px;
    border: 1px solid rgba(15,23,42,.12);
    background: rgba(255,255,255,.70);
    transition: background .12s ease, transform .12s ease, border-color .12s ease;
  }
  footer.qta-footer .qf-social a:hover{
    background: rgba(255,255,255,.92);
    border-color: rgba(37,99,235,.22);
    transform: translateY(-1px);
    text-decoration:none;
  }
  footer.qta-footer .qf-social i{ font-size: 1.05rem; }

  /* Back to top — i qetë, jo agresiv */
  .qta-backtop{
    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 1030;
    opacity: 0;
    pointer-events: none;
    transition: .18s ease;
    background: rgba(255,255,255,.92);
    border: 1px solid rgba(15,23,42,.12);
    box-shadow: 0 10px 28px rgba(2,6,23,.10);
  }
  .qta-backtop.show{ opacity: 1; pointer-events: auto; }
  .qta-backtop:hover{ transform: translateY(-1px); }

  /* mobile */
  @media (max-width: 575.98px){
    footer.qta-footer .qf-box{ padding: .95rem; }
  }
</style>
<?php endif; ?>

<footer class="qta-footer py-5">
  <div class="container">
    <div class="row gy-4">

      <!-- Brand / About -->
      <div class="col-lg-4">
        <div class="qf-box h-100">
          <div class="d-flex align-items-center gap-2 mb-2">
            <img src="image/logoPNG2.png" alt="QTA" style="height:28px;width:auto;">
            <div class="qf-title mb-0">Qendra e Trajnimeve të Avancuara</div>
          </div>
          <div class="qf-muted small mb-3">
            Portal për menaxhim kursesh, grupeve dhe verifikim certifikatash me procese të standardizuara.
          </div>

          <div class="d-flex gap-2 qf-social">
            <a href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
            <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
            <a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
            <a href="#" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
          </div>
        </div>
      </div>

      <!-- Quick links -->
      <div class="col-lg-4">
        <div class="qf-box h-100">
          <div class="qf-title">Lidhje të shpejta</div>
          <ul class="list-unstyled qf-links small mb-0">
            <li><a href="index.php">Kryefaqja</a></li>
            <li><a href="aboutus.php">Rreth nesh</a></li>
            <li><a href="verify.php">Verifiko certifikatën</a></li>
            <li><a href="contact.php">Kontakt</a></li>
            <!-- nëse ke një faqe reale, ndrysho link-un -->
            <li><a href="selectProfile.php">Hyr / Zgjidh profilin</a></li>
          </ul>
        </div>
      </div>

      <!-- Contact -->
      <div class="col-lg-4">
        <div class="qf-box h-100">
          <div class="qf-title">Kontakt</div>

          <div class="small mb-2">
            <div class="qf-muted"><i class="bi bi-geo-alt me-2"></i>Adresa</div>
            <div class="fw-semibold">Rruga Bilal Konxholli, Tiranë</div>
          </div>

          <div class="small mb-2">
            <div class="qf-muted"><i class="bi bi-telephone me-2"></i>Telefon</div>
            <div class="fw-semibold"><a href="tel:+355698778837">+355 69 877 8837</a></div>
          </div>

          <div class="small">
            <div class="qf-muted"><i class="bi bi-envelope me-2"></i>Email</div>
            <div class="fw-semibold"><a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a></div>
          </div>
        </div>
      </div>

    </div>

    <div class="qf-meta d-flex flex-column flex-md-row gap-2 align-items-md-center justify-content-between">
      <div class="small qf-muted">
        &copy; <?= date('Y') ?> Qendra e Trajnimeve të Avancuara. Të gjitha të drejtat e rezervuara.
      </div>
      <div class="small">
        <a class="qf-muted me-3" href="#">Privatësia</a>
        <a class="qf-muted" href="#">Kushtet</a>
      </div>
    </div>
  </div>
</footer>

<button type="button" class="btn btn-sm rounded-3 qta-backtop" id="qtaBackTop" aria-label="Kthehu në krye">
  <i class="bi bi-arrow-up"></i>
</button>

<script>
  (function(){
    const btn = document.getElementById('qtaBackTop');
    if(!btn) return;

    const onScroll = () => {
      if (window.scrollY > 280) btn.classList.add('show');
      else btn.classList.remove('show');
    };

    window.addEventListener('scroll', onScroll, { passive:true });
    btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    onScroll();
  })();
</script>
