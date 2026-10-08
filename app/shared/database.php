<?php
// database.php
// PDO connection to MySQL (XAMPP default). Change credentials if needed.
declare(strict_types=1);

require_once __DIR__ . '/request.php';

/** Times all transactions, including legacy endpoints that do not use qta_tx(). */
class QtaPDO extends PDO
{
    private ?float $transactionStarted = null;

    public function beginTransaction(): bool
    {
        $started = microtime(true);
        $ok = parent::beginTransaction();
        if ($ok) $this->transactionStarted = $started;
        return $ok;
    }

    private function recordTransaction(): void
    {
        if ($this->transactionStarted !== null) {
            $GLOBALS['qta_transaction_ms'] = ($GLOBALS['qta_transaction_ms'] ?? 0)
                + (microtime(true) - $this->transactionStarted) * 1000;
            $this->transactionStarted = null;
        }
    }

    public function commit(): bool
    {
        $ok = parent::commit();
        if ($ok) $this->recordTransaction();
        return $ok;
    }

    public function rollBack(): bool
    {
        $ok = parent::rollBack();
        if ($ok) $this->recordTransaction();
        return $ok;
    }
}

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
        PDO::ATTR_TIMEOUT => 5,
    ];

    try {
        $pdo = new QtaPDO($dsn, $dbUser, $dbPass, $options);
        $lockWait = max(1, min(15, (int)(getenv('QTA_DB_LOCK_WAIT_SECONDS') ?: 8)));
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $lockWait);
        $pdo->exec('SET SESSION lock_wait_timeout = ' . $lockWait);
        return $pdo;
    } catch (PDOException $e) {
        $incident = qta_request_exception($e);
        http_response_code(503);
        $message = 'Shërbimi nuk mund të lidhet me databazën. Provo përsëri më vonë. Referenca: ' . $incident;
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json; charset=UTF-8');
            exit(json_encode(['ok' => false, 'error' => $message, 'request_id' => $incident], JSON_UNESCAPED_UNICODE));
        }
        exit($message);
    }
}
