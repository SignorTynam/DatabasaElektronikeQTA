<?php
// database.php
// PDO connection to MySQL (XAMPP default). Change credentials if needed.
declare(strict_types=1);

function getPDO(): PDO {
    $dbHost = '127.0.0.1';
    $dbName = 'qta_db';
    $dbUser = 'root';
    $dbPass = ''; // XAMPP default is empty; change if you set a password
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
        // In production, log error and show a generic message.
        exit('Database connection failed: ' . $e->getMessage());
    }
}
