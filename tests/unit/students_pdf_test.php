<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/exports/inc/students_pdf.php';

t_case('student PDF: Unicode, newlines and unbroken text wrap without losing characters', static function (): void {
    $measure = static fn(string $text): float => (float)mb_strlen($text, 'UTF-8');
    t_eq(['Emër', 'Atësi', 'Tiranë'], qta_students_pdf_lines("Emër Atësi\nTiranë", 6, $measure), 'words and explicit newlines preserved');
    $text = str_repeat('ëç', 125);
    $lines = qta_students_pdf_lines($text, 11, $measure);
    t_eq($text, implode('', $lines), 'long UTF-8 values preserve every character');
    t_ok(max(array_map(static fn($line) => mb_strlen($line, 'UTF-8'), $lines)) <= 11, 'unbroken values fit the cell');
    t_eq([''], qta_students_pdf_lines('', 10, $measure), 'empty cells retain a line');
});

t_case('student PDF: full register fits 64 MB, repeats headers and reports completed rows', static function (): void {
    foreach (['large', 'long', 'empty'] as $mode) {
        $path = sys_get_temp_dir() . '/qta-students-test-' . bin2hex(random_bytes(8)) . '.pdf';
        try {
            $proc = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', __DIR__ . '/../fixtures/students_pdf.php', $mode, $path],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            t_eq(0, proc_close($proc), $mode . ': renderer completed under 64 MB: ' . $stderr);
            $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            $pdf = file_get_contents($path);
            t_ok(str_starts_with($pdf, '%PDF-') && str_ends_with(trim($pdf), '%%EOF'), $mode . ': complete PDF');
            $pages = preg_match_all('~/Type /Page\b~', $pdf);
            t_ok($mode === 'empty' ? $pages === 1 : $pages > 1, $mode . ': paginated without blank trailing pages');
            // Inspect the actual PDF content streams, not just the renderer callback.
            preg_match_all('~/Filter /FlateDecode\s*/Length (\d+) >>\s*stream\r?\n~', $pdf, $streams, PREG_OFFSET_CAPTURE);
            $content = '';
            foreach ($streams[0] as $index => [$prefix, $position]) {
                $content .= gzuncompress(substr($pdf, $position + strlen($prefix), (int)$streams[1][$index][0]));
            }
            preg_match_all('~\x00T\x00E\x00S\x00T((?:\x00[0-9]){7})~', $content, $ids);
            $actualIds = array_map(static fn($id) => mb_convert_encoding($id, 'UTF-8', 'UTF-16BE'), $ids[1]);
            $total = $mode === 'empty' ? 0 : ($mode === 'long' ? 2 : 2758);
            $expectedIds = $total ? array_map(static fn($id) => str_pad((string)$id, 7, '0', STR_PAD_LEFT), range(1, $total)) : [];
            t_eq($expectedIds, $actualIds, $mode . ': every student appears exactly once and in order');
            t_eq($pages, substr_count($content, mb_convert_encoding('Nr. Amzës', 'UTF-16BE', 'UTF-8')), $mode . ': header repeats on every page');
            if ($mode === 'long') {
                $allLines = true;
                for ($i = 1; $i <= 160; ++$i) {
                    $allLines = $allLines && str_contains($content, mb_convert_encoding('Rreshti' . $i, 'UTF-16BE', 'UTF-8'));
                }
                t_ok($allLines, 'a cell taller than one page retains all its lines');
            }
            t_eq([0, $total], $result['progress'][0], $mode . ': progress starts at zero completed rows');
            t_eq([$total, $total], $result['progress'][count($result['progress']) - 1], $mode . ': progress reaches every row');
            if ($mode === 'large') {
                t_ok(count($result['progress']) > 100, 'large export reports incremental row progress');
                t_ok($result['peak_mb'] < 64, 'large export remains below the memory cap');
            }
        } finally { if (is_file($path)) unlink($path); }
    }
});
