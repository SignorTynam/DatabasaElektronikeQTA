<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zgjedhja e Profilit - Qendra e Trajnimeve</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .card { transition: transform 0.3s, box-shadow 0.3s; border: none; border-radius: 10px; }
        .card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .profile-icon { font-size: 3rem; margin-bottom: 1rem; }
        .form-control:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); }
        .btn-primary { background-color: #0d6efd; border: none; padding: 10px 20px; }
        .btn-primary:hover { background-color: #0b5ed7; }
    </style>
</head>
<body>
    <!-- Navbar (i paprekur) -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="image/logoPNG2.png" alt="Logo" height="30" class="d-inline-block align-text-top me-2">
                Qendra e Trajnimeve të Avancuara (QTA)
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.html">Kryefaqja</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">Rreth nesh</a></li>
                    <li class="nav-item"><a class="nav-link" href="contact.html">Kontakt</a></li>
                    <li class="nav-item active"><a class="btn btn-primary ms-2" href="selectProfile.php" role="button">Hyr</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="text-center mb-5">
            <h1 class="display-5 fw-bold">Zgjidhni profilin tuaj</h1>
            <p class="lead text-muted">Qasja në sistem varet nga roli juaj. Ju lutem zgjidhni profilin përkatës.</p>
            <?php
            session_start();
            if (!empty($_SESSION['login_error'])) {
                echo '<div class="alert alert-danger">'.htmlspecialchars($_SESSION['login_error']).'</div>';
                unset($_SESSION['login_error']);
            }
            ?>
        </div>

        <div class="row g-4">
            <!-- Administrator -->
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body text-center p-4">
                        <div class="profile-icon text-primary">
                            <!-- svg icon -->
                        </div>
                        <h3 class="card-title">Administrator</h3>
                        <p class="card-text">Qasje e plotë në të gjitha funksionet e sistemit të menaxhimit.</p>

                        <form class="mt-4" action="login_handler.php" method="post" autocomplete="off">
                            <input type="hidden" name="role" value="administrator">
                            <div class="mb-3 text-start">
                                <label for="adminEmail" class="form-label">Email</label>
                                <input type="email" class="form-control" id="adminEmail" name="identifier" placeholder="shembull@email.com" required>
                            </div>
                            <div class="mb-3 text-start">
                                <label for="adminPassword" class="form-label">Fjalëkalimi</label>
                                <input type="password" class="form-control" id="adminPassword" name="password" placeholder="Shkruani fjalëkalimin" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Hyr si Administrator</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Agjencia -->
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body text-center p-4">
                        <div class="profile-icon text-success"></div>
                        <h3 class="card-title">Agjencia</h3>
                        <p class="card-text">Qasje në sistem për agjencitë partnerë të qendrës sonë.</p>

                        <form class="mt-4" action="login_handler.php" method="post" autocomplete="off">
                            <input type="hidden" name="role" value="agjencia">
                            <div class="mb-3 text-start">
                                <label for="agencyNIPT" class="form-label">NIPT</label>
                                <input type="text" class="form-control" id="agencyNIPT" name="identifier" placeholder="Shkruani NIPT-n e agjencisë" required>
                            </div>
                            <div class="mb-3 text-start">
                                <label for="agencyPassword" class="form-label">Fjalëkalimi</label>
                                <input type="password" class="form-control" id="agencyPassword" name="password" placeholder="Shkruani fjalëkalimin" required>
                            </div>
                            <button type="submit" class="btn btn-success w-100">Hyr si Agjencia</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Student -->
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body text-center p-4">
                        <div class="profile-icon text-info"></div>
                        <h3 class="card-title">Student</h3>
                        <p class="card-text">Qasje në portofolin personal dhe të dhënat e studentit.</p>

                        <form class="mt-4" action="login_handler.php" method="post" autocomplete="off">
                            <input type="hidden" name="role" value="student">
                            <div class="mb-3 text-start">
                                <label for="studentPersonalId" class="form-label">Numri Personal</label>
                                <input type="text" class="form-control" id="studentPersonalId" name="identifier" placeholder="Shkruani numrin personal" required>
                            </div>
                            <div class="mb-3 text-start">
                                <label for="studentPassword" class="form-label">Fjalëkalimi</label>
                                <input type="password" class="form-control" id="studentPassword" name="password" placeholder="Shkruani fjalëkalimin" required>
                            </div>
                            <button type="submit" class="btn btn-info w-100 text-white">Hyr si Student</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mt-5">
            <p class="text-muted">Keni harruar fjalëkalimin? <a href="#">Rivendosni këtu</a></p>
            <p class="text-muted">Nuk keni llogari? <a href="#">Regjistrohuni si student i ri</a></p>
        </div>
    </div>

    <footer class="bg-dark text-white py-5">
        <div class="container">
            <div class="row">
                <!-- footer content (si më sipër) -->
            </div>
            <hr class="my-4 bg-light">
            <p class="text-center mb-0">&copy; 2025 Qendra e Trajnimeve të Avancuara. Të gjitha të drejtat e rezervuara.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>