<?php
// database.php
// PDO connection to MySQL (XAMPP default). Change credentials if needed.
declare(strict_types=1);

function getPDO(): PDO {
    $dbHost = getenv('QTA_DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('QTA_DB_NAME') ?: 'qta_db';
    $dbUser = getenv('QTA_DB_USER') ?: 'root';
    $dbPass = getenv('QTA_DB_PASSWORD');
    $dbPass = $dbPass === false ? '' : $dbPass;
    $charset = 'utf8mb4';
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=$charset";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, $dbUser, $dbPass, $options);
    } catch (PDOException $e) {
        $incident = bin2hex(random_bytes(6));
        error_log(sprintf('[QTA DB %s] %s', $incident, $e->getMessage()));
        http_response_code(503);
        exit('Shërbimi nuk mund të lidhet me databazën. Provo përsëri më vonë. Referenca: ' . $incident);
    }
}
