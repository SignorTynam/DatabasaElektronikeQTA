
<?php
session_start();
require_once __DIR__ . '/database.php';
$pdo = getPDO();

/* 1) Nëse je i loguar, lexo përdoruesin aktual */
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email, r.name AS role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    $currentUser = $stmt->fetch() ?: null;
}

require_once __DIR__ . '/navbarMain.php';
?>

<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Kontakt – Qendra e Trajnimeve të Avancuara (QTA)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="icon" type="image/svg+xml" href="image/logoPNG - Copy.png">
  <style>
    :root{
      --g1:#0ea5e9; --g2:#2563eb; --g3:#4f46e5;
      --bg:#f5f7fb; --glass:rgba(255,255,255,.85); --glass-b:rgba(255,255,255,.55);
      --muted:#667085; --ring:#e6efff; --shadow:0 18px 40px rgba(2,6,23,.12);
    }
    body{ background:var(--bg); }

    /* Navbar */
    .navbar-brand img{ height:30px; }

    /* Hero */
    .hero{
      position:relative; color:#fff; overflow:hidden;
      background:
        radial-gradient(1200px 420px at 10% -20%, rgba(37,99,235,.25), rgba(37,99,235,0) 60%),
        radial-gradient(900px 320px at 90% -10%, rgba(99,102,241,.22), rgba(99,102,241,0) 55%),
        linear-gradient(135deg, var(--g1) 0%, var(--g2) 55%, var(--g3) 100%);
      padding:72px 0 68px;
    }
    .hero .chip{ background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.28); }
    .hero-blob{
      position:absolute; right:-140px; bottom:-160px; width:480px; height:480px; border-radius:50%;
      background:radial-gradient(circle at 40% 40%, rgba(255,255,255,.32), transparent 60%);
      filter:blur(30px); opacity:.8;
    }
    .glass{
      background:var(black); border:1px solid var(--glass-b);
      border-radius:1rem; box-shadow:var(--shadow);
    }

    /* Cards / sections */
    .card{ border:none; border-radius:1rem; box-shadow:var(--shadow); }
    .info-tile .icon{
      width:54px; height:54px; border-radius:.9rem; display:flex; align-items:center; justify-content:center;
      background:#eef2ff; color:#3355ff;
    }
    .copy-btn{ border-radius:.6rem; }

    .map-wrap{ border-radius:1rem; overflow:hidden; box-shadow:var(--shadow); border:1px solid #eef2ff; }

    .small-muted{ color:var(--muted); }

    /* Floating labels tweak */
    .form-floating>.form-control, .form-floating>.form-select{
      border-radius:.8rem; border:1px solid #e5e7eb;
    }
    .form-control:focus, .form-select:focus{
      border-color:#0d6efd; box-shadow:0 0 0 .25rem rgba(13,110,253,.15);
    }

    /* CTA stripe */
    .cta{
      background:linear-gradient(90deg, #e0e7ff, #eff6ff);
      border:1px solid #e5e7eb; border-radius:1rem;
    }
  </style>
</head>
<body>

<!-- Hero -->
<section class="hero">
  <div class="hero-blob"></div>
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <span class="badge chip rounded-pill mb-2">Lidhuni me ne</span>
        <h1 class="display-5 fw-bold mb-2">Na Kontaktoni</h1>
        <p class="lead mb-0">Jemi këtu për çdo pyetje rreth kurseve, regjistrimeve dhe verifikimit të certifikatave.</p>
      </div>
      <div class="col-lg-5">
        <div class="glass p-3 p-md-4">
          <div class="d-flex align-items-center">
            <div class="info-tile icon me-3"><i class="bi bi-headset fs-4"></i></div>
            <div>
              <div class="fw-semibold">Mbështetje brenda orarit zyrtar</div>
              <div class="small text-white-50">E Hënë–E Premte · 08:00–18:00 · E Shtunë · 09:00–14:00</div>
            </div>
          </div>
          <div class="d-flex gap-2 mt-3">
            <a class="btn btn-light text-primary" href="mailto:officialqta@gmail.com"><i class="bi bi-envelope me-1"></i>Email</a>
            <a class="btn btn-outline-light" href="tel:+355698778837"><i class="bi bi-telephone me-1"></i>+355 69 877 8837</a>
            <a class="btn btn-outline-light" href="verify.php"><i class="bi bi-qr-code-scan me-1"></i>Verifiko Certifikatën</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Content -->
<section class="py-5">
  <div class="container">
    <div class="row g-4 g-xl-5">
      <!-- Form -->
      <div class="col-lg-7">
        <div class="card">
          <div class="card-body p-4 p-md-5">
            <h2 class="fw-bold mb-1">Dërgo një mesazh</h2>
            <p class="small-muted mb-4">Plotësoni fushat më poshtë dhe ne do t’ju kontaktojmë së shpejti.</p>

            <form id="contactForm" class="needs-validation" novalidate>
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="form-floating">
                    <input type="text" class="form-control" id="firstName" placeholder="Emri" required>
                    <label for="firstName">Emri</label>
                    <div class="invalid-feedback">Ju lutem shkruani emrin.</div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="form-floating">
                    <input type="text" class="form-control" id="lastName" placeholder="Mbiemri" required>
                    <label for="lastName">Mbiemri</label>
                    <div class="invalid-feedback">Ju lutem shkruani mbiemrin.</div>
                  </div>
                </div>
                <div class="col-12">
                  <div class="form-floating">
                    <input type="email" class="form-control" id="email" placeholder="email@shembull.com" required>
                    <label for="email">Email</label>
                    <div class="invalid-feedback">Ju lutem shkruani një email të vlefshëm.</div>
                  </div>
                </div>
                <div class="col-12">
                  <div class="form-floating">
                    <select class="form-select" id="subject" required>
                      <option value="" selected disabled>Zgjidhni një opsion</option>
                      <option value="admission">Informacione për regjistrim</option>
                      <option value="courses">Informacione për kurse</option>
                      <option value="technical">Problem teknik</option>
                      <option value="other">Tjetër</option>
                    </select>
                    <label for="subject">Subjekti</label>
                    <div class="invalid-feedback">Ju lutem zgjidhni një subjekt.</div>
                  </div>
                </div>
                <div class="col-12">
                  <div class="form-floating">
                    <textarea class="form-control" placeholder="Mesazhi juaj" id="message" style="height: 140px" required></textarea>
                    <label for="message">Mesazhi</label>
                    <div class="invalid-feedback">Ju lutem shkruani mesazhin.</div>
                  </div>
                </div>
              </div>

              <div class="d-flex align-items-center gap-2 mt-4">
                <button class="btn btn-primary px-4" type="submit">
                  <i class="bi bi-send me-1"></i>Dërgo mesazhin
                </button>
                <a id="mailtoLink" class="btn btn-outline-secondary" href="#">
                  <i class="bi bi-envelope-open me-1"></i>Dërgo me email
                </a>
              </div>
              <div class="small-muted mt-3"><i class="bi bi-shield-check me-1"></i>Të dhënat tuaja përdoren vetëm për t’ju përgjigjur.</div>
            </form>
          </div>
        </div>
      </div>

      <!-- Info -->
      <div class="col-lg-5">
        <div class="card mb-4">
          <div class="card-body p-4">
            <h3 class="fw-bold mb-3">Informacion kontakti</h3>

            <div class="d-flex align-items-start info-tile mb-3">
              <div class="icon me-3"><i class="bi bi-geo-alt fs-5"></i></div>
              <div>
                <div class="fw-semibold">Adresa</div>
                <div class="small-muted">Rruga Bilal Konxholli, Tiranë</div>
              </div>
            </div>

            <div class="d-flex align-items-start info-tile mb-3">
              <div class="icon me-3" style="background:#ecfdf5;color:#12b981;"><i class="bi bi-telephone fs-5"></i></div>
              <div class="w-100">
                <div class="fw-semibold d-flex align-items-center justify-content-between">
                  <span>Telefoni</span>
                  <button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="+355698778837"><i class="bi bi-clipboard"></i></button>
                </div>
                <a href="tel:+355698778837" class="text-decoration-none">+355 69 877 8837</a>
              </div>
            </div>

            <div class="d-flex align-items-start info-tile mb-3">
              <div class="icon me-3" style="background:#fff1f2;color:#ef4444;"><i class="bi bi-envelope fs-5"></i></div>
              <div class="w-100">
                <div class="fw-semibold d-flex align-items-center justify-content-between">
                  <span>Email</span>
                  <button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="officialqta@gmail.com"><i class="bi bi-clipboard"></i></button>
                </div>
                <a href="mailto:officialqta@gmail.com" class="text-decoration-none">officialqta@gmail.com</a>
              </div>
            </div>

            <div class="d-flex align-items-start info-tile">
              <div class="icon me-3"><i class="bi bi-clock-history fs-5"></i></div>
              <div>
                <div class="fw-semibold">Orari i punës</div>
                <div class="small-muted">E Hënë–E Premte: 08:00–18:00 · E Shtunë: 09:00–14:00 · E Diel: Mbyllur</div>
              </div>
            </div>
          </div>
        </div>

        <div class="map-wrap">
          <iframe
            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d11975.486952909885!2d19.818733!3d41.327546!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zNDHCsDE5JzM5LjIiTiAxOcKwNDknMDcuNCJF!5e0!3m2!1sen!2s!4v1644269999999!5m2!1sen!2s"
            width="100%" height="360" style="border:0;" loading="lazy" allowfullscreen="" referrerpolicy="no-referrer-when-downgrade">
          </iframe>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="py-5 bg-white">
  <div class="container">
    <h2 class="text-center fw-bold mb-4">Pyetjet e shpeshta</h2>
    <p class="text-center small-muted mb-5">Gjeni përgjigje të menjëhershme për temat më të zakonshme.</p>
    <div class="row justify-content-center">
      <div class="col-lg-10">
        <div class="accordion" id="faq">
          <div class="accordion-item">
            <h2 class="accordion-header" id="f1">
              <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#c1">
                Si mund të regjistrohem si student?
              </button>
            </h2>
            <div id="c1" class="accordion-collapse collapse show" data-bs-parent="#faq">
              <div class="accordion-body">
                Plotësoni formularin e aplikimit online ose vizitoni zyrat tona. Do t’ju kërkohen dokumentet
                dhe pagesa e tarifës së aplikimit. Pas verifikimit, do të merrni qasje në portalin e studentit.
              </div>
            </div>
          </div>

          <div class="accordion-item">
            <h2 class="accordion-header" id="f2">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c2">
                Cilat janë metodat e pagesës të pranuara?
              </button>
            </h2>
            <div id="c2" class="accordion-collapse collapse" data-bs-parent="#faq">
              <div class="accordion-body">
                Pranojmë para në dorë, karta bankare, transfertë bankare dhe platforma të miratuara të pagesave elektronike.
              </div>
            </div>
          </div>

          <div class="accordion-item">
            <h2 class="accordion-header" id="f3">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c3">
                A ofroni kurse online?
              </button>
            </h2>
            <div id="c3" class="accordion-collapse collapse" data-bs-parent="#faq">
              <div class="accordion-body">
                Po. Shumë module ofrohen online përmes platformës sonë e-learning, me fleksibilitet të plotë në orare dhe materiale.
              </div>
            </div>
          </div>

          <div class="accordion-item">
            <h2 class="accordion-header" id="f4">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c4">
                Si verifikohet një certifikatë?
              </button>
            </h2>
            <div id="c4" class="accordion-collapse collapse" data-bs-parent="#faq">
              <div class="accordion-body">
                Për çdo certifikatë ka një QR me token unik. Skanoni në <a href="verify.php">verify.php</a> dhe krahasoni emrin.
                Nëse nuk përputhet me dokumentin fizik, njoftoni menjëherë <a href="mailto:officialqta@gmail.com">officialqta@gmail.com</a>.
              </div>
            </div>
          </div>
        </div>        
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="py-4">
  <div class="container">
    <div class="cta d-flex flex-column flex-md-row align-items-center justify-content-between p-3 p-md-4">
      <div class="d-flex align-items-center">
        <div class="me-3 d-none d-md-block"><i class="bi bi-qr-code-scan fs-2 text-primary"></i></div>
        <div>
          <div class="fw-semibold">Verifikoni certifikatat në sekonda</div>
          <div class="small-muted">Skanoni QR dhe shihni të dhënat bazike të kursantit.</div>
        </div>
      </div>
      <a href="verify.php" class="btn btn-primary mt-3 mt-md-0"><i class="bi bi-shield-check me-1"></i> Verifiko tani</a>
    </div>
  </div>
</section>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="toastOK" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body"><i class="bi bi-check2-circle me-1"></i> Mesazhi u dërgua! Do t’ju përgjigjemi së shpejti.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Front-end validation + "fake submit" toast (replace with real backend later)
  (function(){
    const form = document.getElementById('contactForm');
    const mailto = document.getElementById('mailtoLink');
    const toastEl = document.getElementById('toastOK');
    const toast = new bootstrap.Toast(toastEl, { delay: 3000 });

    // Build mailto fallback from form values
    function updateMailto(){
      const fn = document.getElementById('firstName').value.trim();
      const ln = document.getElementById('lastName').value.trim();
      const email = document.getElementById('email').value.trim();
      const subj = document.getElementById('subject').value || 'Tjetër';
      const msg  = document.getElementById('message').value.trim();
      const subject = encodeURIComponent(`[Kontakt] ${subj} – ${fn} ${ln}`);
      const body = encodeURIComponent(`Emri: ${fn} ${ln}\nEmail: ${email}\n\nMesazhi:\n${msg}`);
      mailto.href = `mailto:officialqta@gmail.com?subject=${subject}&body=${body}`;
    }

    ['firstName','lastName','email','subject','message'].forEach(id=>{
      document.getElementById(id).addEventListener('input', updateMailto);
      document.getElementById(id).addEventListener('change', updateMailto);
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      form.classList.add('was-validated');
      if (!form.checkValidity()) return;
      // Here you can POST to your backend (fetch) — for now show toast
      toast.show();
      form.reset();
      form.classList.remove('was-validated');
      updateMailto();
    });

    updateMailto();

    // Copy buttons
    document.querySelectorAll('.copy-btn').forEach(btn=>{
      btn.addEventListener('click', async ()=>{
        try{
          await navigator.clipboard.writeText(btn.dataset.copy);
          btn.innerHTML = '<i class="bi bi-clipboard-check"></i>';
          setTimeout(()=> btn.innerHTML = '<i class="bi bi-clipboard"></i>', 1400);
        }catch(e){}
      });
    });
  })();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

</body>
</html>
