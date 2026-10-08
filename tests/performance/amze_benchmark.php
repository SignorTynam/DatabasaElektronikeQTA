<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('QTA_TEST_DB') !== '1') exit('Requires CLI and QTA_TEST_DB=1.');
require_once __DIR__ . '/../../app/shared/database.php';
$pdo = getPDO();
if (!str_contains((string)$pdo->query('SELECT DATABASE()')->fetchColumn(), 'test')) exit('Requires test database.');
$pdo->exec('CREATE TEMPORARY TABLE qta_amze_bench (id INT PRIMARY KEY AUTO_INCREMENT, nr_amze VARCHAR(100) NOT NULL UNIQUE, amze_numeric BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(nr_amze AS UNSIGNED)) STORED, INDEX ix_numeric(amze_numeric)) ENGINE=InnoDB');
$results = [];
$last = 0;
foreach ([100, 1000, 10000] as $volume) {
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO qta_amze_bench(nr_amze) VALUES(?)');
    for ($i = $last + 1; $i <= $volume; $i++) $insert->execute([(string)$i]);
    $pdo->commit(); $last = $volume;
    $nums = range(max(1, $volume - 199), $volume);
    $ph = implode(',', array_fill(0, count($nums), '?'));
    $single = $pdo->prepare('SELECT id FROM qta_amze_bench WHERE CAST(nr_amze AS UNSIGNED)=? LIMIT 1');
    $batchSql = "SELECT id,CAST(nr_amze AS UNSIGNED) AS amze FROM qta_amze_bench WHERE CAST(nr_amze AS UNSIGNED) IN ($ph) ORDER BY id";
    $indexedSql = "SELECT id,amze_numeric AS amze FROM qta_amze_bench WHERE amze_numeric IN ($ph) ORDER BY id";
    $batch = $pdo->prepare($batchSql);
    $indexed = $pdo->prepare($indexedSql);
    $times = [];
    foreach (['legacy_n_selects', 'batch_one_scan', 'future_indexed_numeric'] as $kind) {
        $samples = [];
        for ($r = 0; $r < 5; $r++) {
            $start = microtime(true);
            if ($kind === 'legacy_n_selects') foreach ($nums as $n) { $single->execute([$n]); $single->fetchColumn(); }
            else { $q = $kind === 'batch_one_scan' ? $batch : $indexed; $q->execute($nums); $q->fetchAll(); }
            $samples[] = (microtime(true) - $start) * 1000;
        }
        sort($samples); $times[$kind . '_median_ms'] = round($samples[2], 2);
    }
    $explain = $pdo->prepare('EXPLAIN ' . $batchSql); $explain->execute($nums);
    $plan = $explain->fetch();
    $explainIndex = $pdo->prepare('EXPLAIN ' . $indexedSql); $explainIndex->execute($nums);
    $indexPlan = $explainIndex->fetch();
    $results[] = ['students' => $volume, 'requested' => count($nums), 'legacy_queries' => count($nums), 'batch_queries' => 1,
        'explain_cast' => array_intersect_key($plan, array_flip(['type', 'key', 'rows', 'Extra'])),
        'explain_indexed_candidate' => array_intersect_key($indexPlan, array_flip(['type', 'key', 'rows', 'Extra']))] + $times;
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
