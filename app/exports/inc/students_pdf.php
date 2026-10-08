<?php
declare(strict_types=1);

/** Wrap Unicode text to the printable cell width, including long unbroken values. */
function qta_students_pdf_lines(string $text, float $width, callable $measure): array
{
    $lines = [];
    foreach (preg_split('/\R/u', $text) ?: [''] as $paragraph) {
        $line = '';
        foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($measure($candidate) <= $width) { $line = $candidate; continue; }
            if ($line !== '') { $lines[] = $line; $line = ''; }
            while ($measure($word) > $width) {
                // Find a fitting UTF-8 prefix without splitting a character or dropping text.
                $low = 1;
                $high = mb_strlen($word, 'UTF-8');
                while ($low < $high) {
                    $mid = (int)ceil(($low + $high) / 2);
                    if ($measure(mb_substr($word, 0, $mid, 'UTF-8')) <= $width) $low = $mid;
                    else $high = $mid - 1;
                }
                $lines[] = mb_substr($word, 0, $low, 'UTF-8');
                $word = mb_substr($word, $low, null, 'UTF-8');
            }
            $line = $word;
        }
        $lines[] = $line;
    }
    return $lines;
}

/**
 * Draw the register directly on the existing Dompdf canvas. A single HTML table
 * with thousands of rows creates tens of thousands of layout frames and exhausts
 * shared-host memory. Here only one wrapped row is laid out at a time.
 *
 * @param callable(int, int): void|null $progress Completed rows and total rows.
 */
function qta_students_pdf(array $headers, array $data, ?callable $progress = null): string
{
    if (count($headers) !== 12) throw new InvalidArgumentException('Regjistri kërkon 12 kolona.');
    $options = new \Dompdf\Options();
    $options->setIsFontSubsettingEnabled(true);
    $dompdf = new \Dompdf\Dompdf($options);
    $canvas = new \Dompdf\Adapter\CPDF('A3', 'landscape', $dompdf);
    $dompdf->setCanvas($canvas);
    $fonts = $dompdf->getFontMetrics();
    $fonts->setCanvas($canvas);
    $normal = $fonts->getFont('DejaVu Sans', 'normal');
    $bold = $fonts->getFont('DejaVu Sans', 'bold');
    $size = 8.25; // The previous print stylesheet used 11 CSS pixels.
    $lineHeight = 11.5;
    $padding = 4.5;
    $margin = 28.0;
    $bottom = $canvas->get_height() - $margin;
    $weights = [52, 76, 76, 86, 96, 76, 100, 100, 128, 126, 60, 72];
    $tableWidth = $canvas->get_width() - 2 * $margin;
    $widths = array_map(static fn($weight) => $tableWidth * $weight / array_sum($weights), $weights);
    // Retain the existing neutral PDF table palette (independent of screen theme).
    $border = [189 / 255, 181 / 255, 164 / 255];
    $headerFill = [235 / 255, 232 / 255, 223 / 255];
    $wrap = static function (array $row, string $font) use ($widths, $canvas, $size, $padding): array {
        $cells = [];
        foreach ($widths as $column => $width) {
            $cells[] = qta_students_pdf_lines((string)($row[$column] ?? ''), $width - 2 * $padding,
                static fn(string $text): float => $canvas->get_text_width($text, $font, $size));
        }
        return $cells;
    };
    $draw = static function (array $cells, float $y, bool $isHeader = false) use (
        $canvas, $widths, $margin, $size, $lineHeight, $padding, $normal, $bold, $border, $headerFill
    ): float {
        $height = max(array_map('count', $cells)) * $lineHeight + 2 * $padding;
        $x = $margin;
        foreach ($cells as $column => $lines) {
            $width = $widths[$column];
            if ($isHeader) $canvas->filled_rectangle($x, $y, $width, $height, $headerFill);
            $canvas->rectangle($x, $y, $width, $height, $border, 0.5);
            foreach ($lines as $index => $text) {
                $canvas->text($x + $padding, $y + $padding + $index * $lineHeight,
                    $text, $isHeader ? $bold : $normal, $size);
            }
            $x += $width;
        }
        return $y + $height;
    };
    $headerCells = $wrap($headers, $bold);
    $startPage = static function (bool $next) use ($canvas, $draw, $headerCells, $margin): float {
        if ($next) $canvas->new_page();
        return $draw($headerCells, $margin, true);
    };
    $y = $startPage(false);
    $pageBodyHeight = $bottom - $y;
    $total = count($data);
    if ($progress) $progress(0, $total);
    $completed = 0;
    foreach ($data as $row) {
        $cells = $wrap($row, $normal);
        $lines = max(array_map('count', $cells));
        $height = $lines * $lineHeight + 2 * $padding;
        if ($height <= $pageBodyHeight && $y + $height > $bottom) $y = $startPage(true);
        $offset = 0;
        do {
            $capacity = (int)floor(($bottom - $y - 2 * $padding) / $lineHeight);
            if ($capacity < 1) { $y = $startPage(true); continue; }
            $take = min($capacity, $lines - $offset);
            $slice = array_map(static fn(array $cell) => array_slice($cell, $offset, $take), $cells);
            $y = $draw($slice, $y);
            $offset += $take;
            if ($offset < $lines) $y = $startPage(true);
        } while ($offset < $lines);
        ++$completed;
        if ($progress && ($completed % 25 === 0 || $completed === $total)) $progress($completed, $total);
    }
    $canvas->page_text($margin, $canvas->get_height() - 19,
        'Faqja {PAGE_NUM} / {PAGE_COUNT}', $normal, $size);
    return $canvas->output();
}
