<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/exports/inc/students_pdf.php';
$mode = $argv[1];
$path = $argv[2];
$headers = ['Nr. Amzës', 'Emër', 'Atësi', 'Mbiemër', 'Nr. Personal', 'Datëlindja',
    'Vendlindja', 'Arsimi', 'Kursi', 'Datat e kursit', 'Gjinia', 'Tel.'];
$data = [];
$count = $mode === 'empty' ? 0 : ($mode === 'long' ? 2 : 2758);
for ($i = 1; $i <= $count; ++$i) {
    $data[] = [(string)$i, 'Emër test', 'Atësi', 'Mbiemër', 'TEST' . str_pad((string)$i, 7, '0', STR_PAD_LEFT),
        '01-01-2000', 'Tiranë', '4 - Arsimi i mesëm', 'Hidroizolues',
        '01-01-2026 - 01-03-2026', 'Mashkull', '0000000000'];
}
if ($mode === 'long') {
    $data[0][6] = str_repeat('ëç', 250);
    $data[0][8] = implode("\n", array_map(static fn(int $i) => 'Rreshti' . $i, range(1, 160)));
}
$progress = [];
$started = microtime(true);
$pdf = qta_students_pdf($headers, $data, static function (int $completed, int $total) use (&$progress): void {
    $progress[] = [$completed, $total];
});
file_put_contents($path, $pdf);
echo json_encode(['seconds' => round(microtime(true) - $started, 2),
    'peak_mb' => memory_get_peak_usage(true) / 1048576, 'progress' => $progress]);
