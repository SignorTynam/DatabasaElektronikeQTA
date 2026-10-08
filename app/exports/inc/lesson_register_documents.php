<?php
declare(strict_types=1);

/**
 * lesson_register_documents.php — Vizatimi i "Regjistrit të orëve të mësimit"
 * në PDF (Dompdf) dhe Word (PHPWord), nga modeli i qta_lesson_register_build().
 *
 * Të dy formatet vizatojnë të njëjtat faqe, me të njëjtat përmasa (pt) nga
 * qta_lr_geometry(): faqja tek është regjistri i prezencës, faqja çift datat dhe
 * temat e modulit. Stili është ai i formularit të printuar: bardhë e zi, vija të
 * holla, pa ngjyra dhe pa elemente të faqes së internetit.
 *
 * Kërkon autoload-in e Composer-it (dompdf/dompdf, phpoffice/phpword).
 */

require_once __DIR__ . '/../../shared/lesson_register.php';
require_once __DIR__ . '/../../shared/document_generation.php';

/** Shenjë e përkohshme për tab-in në Word (zëvendësohet me <w:tab/>). */
const QTA_LR_DOCX_TAB = "\u{E000}";
/** Ngjyra-shenjë e qelizës "Nr./Dt." në Word: i shtohet vija diagonale. */
const QTA_LR_DOCX_CORNER = '000001';

/* ================================================================= PDF */

if (!function_exists('qta_lr_pdf_fonts')) {
  /**
   * Përgatit Carlito-n (shkronjat e Calibri-t, licencë OFL) për Dompdf në një
   * dosje të përkohshme të shkrueshme: kopja e TTF-së dhe metrikat (.ufm) krijohen
   * një herë. Kthen dosjen, ose null kur diçka mungon — atëherë PDF-ja përdor
   * DejaVu Sans, që vjen me Dompdf.
   */
  function qta_lr_pdf_fonts(): ?string
  {
    $src = __DIR__ . '/../fonts';
    $names = ['Carlito-Regular', 'Carlito-Bold'];
    $sig = '';
    foreach ($names as $name) {
      $ttf = $src . '/' . $name . '.ttf';
      if (!is_file($ttf)) {
        return null;
      }
      $sig .= $name . filesize($ttf) . filemtime($ttf);
    }
    $dir = rtrim(sys_get_temp_dir(), '\\/') . '/qta-dompdf-fonts-' . substr(md5($sig), 0, 10);
    try {
      if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
      }
      foreach ($names as $name) {
        $dst = $dir . '/' . $name;
        if (!is_file($dst . '.ttf')) {
          $tmp = $dst . '.ttf.' . bin2hex(random_bytes(4));
          if (!@copy($src . '/' . $name . '.ttf', $tmp) || !@rename($tmp, $dst . '.ttf')) {
            @unlink($tmp);
            return null;
          }
        }
        if (!is_file($dst . '.ufm')) {
          $tmp = $dst . '.ufm.' . bin2hex(random_bytes(4));
          $font = \FontLib\Font::load($dst . '.ttf');
          if (!$font) {
            return null;
          }
          $font->parse();
          $font->saveAdobeFontMetrics($tmp);
          $font->close();
          if (!is_file($tmp) || !@rename($tmp, $dst . '.ufm')) {
            @unlink($tmp);
            return null;
          }
        }
      }
    } catch (Throwable $e) {
      error_log('[QTA regjistri] shkronjat Carlito nuk u përgatitën: ' . $e->getMessage());
      return null;
    }
    return $dir;
  }

  /** A mund të shkruhet teksti me shkronjat bazë të PDF-së (Windows-1252)? */
  function qta_lr_pdf_is_cp1252(string $s): bool
  {
    return mb_convert_encoding(mb_convert_encoding($s, 'Windows-1252', 'UTF-8'), 'UTF-8', 'Windows-1252') === $s;
  }

  function qta_lr_pt(float $v): string
  {
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . 'pt';
  }

  /**
   * HTML-ja e dokumentit për Dompdf.
   *
   * Dompdf e mat qelizën pa kufirin (0,5 pt) dhe pa mbushjen, dhe lartësinë e një
   * rreshti teksti e llogarit si line-height × lartësia e fontit. Prandaj këtu
   * gjerësitë dhe lartësitë zbresin kufirin, mbushjet janë brenda div-eve, dhe
   * çdo line-height pjesëtohet me lartësinë e fontit — që rreshtat dhe kolonat të
   * dalin me të njëjtat përmasa si në Word.
   *
   * @param array{sans:string,k:float,f_sans:float,f_times:float,f_serif:float} $font
   *        k = sa zvogëlohen shkronjat kur mungon Carlito (DejaVu Sans është më e gjerë)
   */
  function qta_lr_pdf_html(array $model, array $font): string
  {
    $g = $model['geometry'];
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $pt = 'qta_lr_pt';
    $k = $font['k'];
    $fs = static fn(float $size): string => qta_lr_pt($size * $k);
    /* Rreshti i tekstit: sa lartësia natyrore e fontit, por jo më shumë se vendi që ka. */
    $lh = static fn(float $size, float $room = 1000.0): string => qta_lr_pt(min($size * $k * $font['f_sans'], $room) / $font['f_sans']);
    $hb = static fn(float $h): string => qta_lr_pt($h - 0.5);
    $wb = static fn(float $w, float $pad = 0.0): string => qta_lr_pt($w - 0.5 - 2 * $pad);

    $small = $g['att_small_size'];
    $pad = $g['top_cell_pad'];

    $css = '
      @page { size: A4 portrait; margin: ' . $pt($g['margin']) . '; }
      body { margin: 0; padding: 0; font-family: ' . $font['sans'] . '; color: #000; font-size: ' . $fs(11) . '; line-height: ' . $lh(11) . '; }
      .lr-page { page-break-after: always; }
      .lr-page.is-last { page-break-after: auto; }
      table { border-collapse: collapse; }
      td { border: 0.5pt solid #000; padding: 0; }

      .att .c0 { width: ' . $wb($g['att_first_col']) . '; }
      .att .cd { width: ' . $wb($g['att_date_col']) . '; }
      .att td { font-weight: bold; vertical-align: middle; }
      .att .r-title td { height: ' . $hb($g['att_title_h']) . '; text-align: center; font-size: ' . $fs($g['att_title_size']) . '; line-height: ' . $lh($g['att_title_size'], $g['att_title_h'] - 0.5) . '; }
      .att .r-label td.lbl { height: ' . $hb($g['att_label_h']) . '; font-size: ' . $fs($g['att_label_size']) . '; line-height: ' . $lh($g['att_label_size'], $g['att_label_h'] - 0.5) . '; border-bottom: none; }
      .att .r-label td.lbl div { padding-left: 3pt; }
      .att .r-months td { height: ' . $hb($g['att_months_h']) . '; font-size: ' . $fs($small) . '; line-height: ' . $lh($small, $g['att_months_h'] - 0.5) . '; border-top: none; text-align: left; }
      .att .r-dates td { height: ' . $hb($g['att_dates_h']) . '; font-size: ' . $fs($small) . '; line-height: ' . $lh($small, $g['att_dates_h'] - 0.5) . '; text-align: center; }
      .att td.k1, .att td.k3 { font-size: ' . $fs($small) . '; line-height: ' . $lh($small) . '; }
      .att td.k1 { vertical-align: top; text-align: left; border-bottom: none; }
      .att td.k1 div { padding: 1pt 0 0 2.5pt; }
      .att td.k2 { border-top: none; border-bottom: none; }
      .att td.k3 { vertical-align: bottom; text-align: right; border-top: none; }
      .att td.k3 div { padding: 0 2.5pt 1.5pt 0; }
      .att .r-body td { height: ' . $hb($g['att_row_h']) . '; font-size: ' . $fs($g['att_number_size']) . '; line-height: ' . $lh($g['att_number_size'], $g['att_row_h'] - 0.5) . '; }
      .att .r-body td.c0 div { padding-right: 4.5pt; text-align: right; }

      .top .t1 { width: ' . $wb($g['top_cols'][0], $pad) . '; text-align: center; }
      .top .t2 { width: ' . $wb($g['top_cols'][1], $pad) . '; text-align: left; word-wrap: break-word; }
      .top .t3 { width: ' . $wb($g['top_cols'][2], $pad) . '; }
      .top td { padding: 0 ' . $pt($pad) . '; vertical-align: middle; font-size: ' . $fs($g['top_text_size']) . '; line-height: ' . qta_lr_pt($g['top_line_h'] / $font['f_sans']) . '; }
      .top .r-head td { height: ' . $hb($g['top_head_h']) . '; text-align: center; font-weight: bold; line-height: ' . $lh($g['top_text_size'], $g['top_head_h'] - 0.5) . '; }
      .top .r-fill td { height: ' . $hb($g['top_row_min']) . '; }
      .heading { margin: 0 0 ' . $pt($g['heading_after']) . ' 0; padding: 0; font-weight: bold; }
      .heading .note { font-weight: normal; }

      .cap { width: ' . $pt($g['content_w']) . '; margin-top: ' . $pt($g['caption_gap']) . '; }
      .cap td { border: none; white-space: nowrap; font-size: ' . $fs($g['caption_size']) . '; line-height: ' . qta_lr_pt($g['caption_line'] / $font['f_sans']) . '; }
      .cap .r { text-align: right; }
    ';

    $html = '<!DOCTYPE html><html lang="sq"><head><meta charset="UTF-8"><title>' . $e($model['title']) . '</title><style>' . $css . '</style></head><body>';
    $last = count($model['pages']);
    foreach ($model['pages'] as $p) {
      $html .= '<div class="lr-page' . ($p['number'] === $last ? ' is-last' : '') . '">';
      $html .= $p['kind'] === 'attendance' ? qta_lr_pdf_attendance($p, $g, $e, $font) : qta_lr_pdf_topics($p, $g, $e, $font);
      $html .= '<table class="cap"><tr><td class="l">' . $e($p['caption']['left']) . '</td><td class="r">' . $e($p['caption']['right']) . '</td></tr></table>';
      $html .= '</div>';
    }
    return $html . '</body></html>';
  }

  function qta_lr_pdf_attendance(array $p, array $g, callable $e, array $font): string
  {
    $cols = QTA_LR_MAX_COLUMNS;
    /* Si në Word, ku faqet tek pas së parës nisin me një paragraf 1 pt ("faqe e re para"). */
    $h = '<table class="att"' . ($p['number'] > 1 ? ' style="margin-top: 1pt"' : '') . '>';
    $h .= '<tr class="r-title"><td colspan="' . ($cols + 1) . '">Regjistër për orët e mësimit</td></tr>';
    /* Qeliza "Nr./Dt." zë tre rreshtat e kokës: këtu është tre qeliza pa vija mes tyre
       (rowspan-i i Dompdf-së i zgjat rreshtat); diagonalja vizatohet në qta_lr_render_pdf(). */
    $h .= '<tr class="r-label"><td class="c0 k1"><div>Nr.</div></td>'
      . '<td class="lbl" colspan="' . $cols . '"><div>Muaji:</div></td></tr>';
    $h .= '<tr class="r-months"><td class="c0 k2"></td>';
    foreach ($p['months'] as $s) {
      $h .= '<td colspan="' . $s['span'] . '"><div style="padding-left: ' . qta_lr_pt(qta_lr_month_pad($s['label'], $g)) . '">' . $e($s['label']) . '</div></td>';
    }
    $h .= '</tr><tr class="r-dates"><td class="c0 k3"><div>Dt.</div></td>';
    foreach ($p['columns'] as $c) {
      $h .= '<td class="cd">' . ($c === null ? '' : $e($c['day'])) . '</td>';
    }
    $h .= '</tr>';
    /* Më shumë se 35 kursantë (s'ndodh me kufirin prej 10): rreshtat ngushtohen që të zënë në faqe. */
    $rowStyle = '';
    if (abs($p['row_height'] - $g['att_row_h']) > 0.001) {
      $line = min($g['att_number_size'] * $font['k'] * $font['f_sans'], $p['row_height'] - 0.5);
      $rowStyle = ' style="height: ' . qta_lr_pt($p['row_height'] - 0.5) . '; line-height: ' . qta_lr_pt($line / $font['f_sans']) . '"';
    }
    $blank = str_repeat('<td class="cd"></td>', $cols);
    for ($i = 1; $i <= $p['row_count']; $i++) {
      $h .= '<tr class="r-body"><td class="c0"' . $rowStyle . '><div>' . ($i <= $p['numbered'] ? $i : '') . '</div></td>' . $blank . '</tr>';
    }
    return $h . '</table>';
  }

  function qta_lr_pdf_topics(array $p, array $g, callable $e, array $font): string
  {
    /* Titulli si te modeli: Times New Roman Bold; DejaVu Serif vetëm për shkronja jashtë Windows-1252. */
    $times = qta_lr_pdf_is_cp1252($p['heading'] . ' (vazhdim)');
    $size = $p['heading_size'] * ($times ? 1.0 : 0.87);
    $line = $p['heading_size'] * $g['heading_line_ratio'];
    $h = '<p class="heading" style="font-family: ' . ($times ? 'times' : '\'DejaVu Serif\'') . '; font-size: ' . qta_lr_pt($size)
      . '; line-height: ' . qta_lr_pt($line / ($times ? $font['f_times'] : $font['f_serif'])) . '">'
      . $e($p['heading']) . ($p['heading_note'] !== '' ? ' <span class="note">(' . $e($p['heading_note']) . ')</span>' : '') . '</p>';
    $h .= '<table class="top"><tr class="r-head"><td class="t1">Data</td><td class="t2">Tema</td><td class="t3">Shënime</td></tr>';
    foreach ($p['rows'] as $r) {
      $h .= '<tr><td class="t1" style="height: ' . qta_lr_pt($r['height'] - 0.5) . '">' . $e($r['date_label']) . '</td>'
        . '<td class="t2">' . $e($r['topic_title']) . '</td><td class="t3"></td></tr>';
    }
    $h .= str_repeat('<tr class="r-fill"><td class="t1"></td><td class="t2"></td><td class="t3"></td></tr>', $p['fillers']);
    return $h . '</table>';
  }

  /** Hapësira majtas e numrit të muajit, që të bjerë mbi mes të kolonës së datës së parë. */
  function qta_lr_month_pad(string $label, array $g): float
  {
    $w = qta_lr_text_width($label, $g['att_small_size']);
    return max(0.5, round(($g['att_date_col'] - $w) / 2, 2));
  }

  /**
   * PDF-ja e regjistrit. Kthen ['bytes' => …, 'pages' => numri i faqeve të vizatuara].
   */
  function qta_lr_render_pdf(array $model): array
  {
    $fontDir = qta_lr_pdf_fonts();
    $tmp = rtrim(sys_get_temp_dir(), '\\/');
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('tempDir', $tmp);
    $options->set('fontDir', $fontDir ?? $tmp);
    $options->set('fontCache', $fontDir ?? $tmp);
    /* Lartësia e një rreshti = line-height × lartësia e fontit, pa shtesën 10% të Dompdf-së. */
    $options->set('fontHeightRatio', 1.0);

    $dompdf = new \Dompdf\Dompdf($options);
    $fm = $dompdf->getFontMetrics();
    $font = ['sans' => '"DejaVu Sans", sans-serif', 'k' => 0.86, 'family' => 'DejaVu Sans'];
    if ($fontDir !== null) {
      $fm->setFontFamily('carlito', [
        'normal' => $fontDir . '/Carlito-Regular',
        'bold' => $fontDir . '/Carlito-Bold',
      ]);
      $font = ['sans' => 'carlito, "DejaVu Sans", sans-serif', 'k' => 1.0, 'family' => 'carlito'];
    }
    $height = static function (string $family, string $variant) use ($fm): float {
      $file = $fm->getFont($family, $variant);
      return $file ? (float)$fm->getFontHeight($file, 1.0) : 1.2;
    };
    $font['f_sans'] = $height($font['family'], 'normal');
    $font['f_times'] = $height('times', 'bold');
    $font['f_serif'] = $height('DejaVu Serif', 'bold');

    /* Vija diagonale e qelizës "Nr./Dt.": nga këndi poshtë-majtas i qelizës "Dt." te këndi
       lart-djathtas i qelizës "Nr.". Edhe fundi i rreshtit të poshtëm të çdo faqeje, për
       kontrollin e faqosjes. */
    $bottom = 0.0;
    $topRight = null;
    $dompdf->setCallbacks([[
      'event' => 'end_frame',
      'f' => static function ($frame, $canvas) use (&$bottom, &$topRight): void {
        $node = $frame->get_node();
        $class = ' ' . ($node instanceof \DOMElement ? $node->getAttribute('class') : '') . ' ';
        if ($node->nodeName === 'td' && str_contains($class, ' k1 ')) {
          $b = $frame->get_border_box();
          $topRight = [$b['x'] + $b['w'], $b['y']];
        } elseif ($node->nodeName === 'td' && str_contains($class, ' k3 ') && $topRight !== null) {
          $b = $frame->get_border_box();
          $canvas->line($b['x'], $b['y'] + $b['h'], $topRight[0], $topRight[1], [0, 0, 0], 0.5);
          $topRight = null;
        } elseif ($node->nodeName === 'table' && str_contains($class, ' cap ')) {
          $b = $frame->get_border_box();
          $bottom = max($bottom, $b['y'] + $b['h']);
        }
      },
    ]]);

    $dompdf->loadHtml(qta_lr_pdf_html($model, $font), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->addInfo('Title', $model['title']);
    $dompdf->addInfo('Author', 'QTA');
    $dompdf->addInfo('Creator', 'QTA — Regjistri i orëve të mësimit');
    qta_export_progress(70, 'Po përpunohet regjistri PDF.');
    $dompdf->render();
    qta_export_progress(85, 'Regjistri PDF u krijua. Po ruhet skedari.');

    return [
      'bytes' => (string)$dompdf->output(),
      'pages' => (int)$dompdf->getCanvas()->get_page_count(),
      'font' => $font['family'],
      /* Sa poshtë arriti përmbajtja më e gjatë (pt nga lart); kufiri është 841,89 − 72. */
      'bottom' => round($bottom, 2),
    ];
  }
}

/* ================================================================ Word */

if (!function_exists('qta_lr_render_docx')) {
  function qta_lr_tw(float $pt): int
  {
    return (int)round($pt * 20);
  }

  /**
   * Word-i i regjistrit (.docx i vërtetë, Office Open XML), i ruajtur në $path.
   * Një seksion A4 vertikal; çdo faqe e re nis me "faqe e re para" në paragrafin
   * e parë, pa ndërprerje seksionesh dhe pa faqe bosh.
   */
  function qta_lr_render_docx(array $model, string $path): void
  {
    $g = $model['geometry'];
    \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);

    $word = new \PhpOffice\PhpWord\PhpWord();
    $word->setDefaultFontName('Calibri');
    $word->setDefaultFontSize(11);
    $word->setDefaultParagraphStyle(['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1.0]);
    $info = $word->getDocInfo();
    $info->setTitle($model['title']);
    $info->setCreator('QTA');
    $info->setLastModifiedBy('QTA');
    $info->setSubject('Regjistri i orëve të mësimit');

    $margin = qta_lr_tw($g['margin']);
    /* A4 me numra të plotë (twip), jo me presjet dhjetore që shkruan 'paperSize' => 'A4'. */
    $section = $word->addSection([
      'pageSizeW' => 11906, 'pageSizeH' => 16838, 'orientation' => 'portrait',
      'marginTop' => $margin, 'marginBottom' => $margin, 'marginLeft' => $margin, 'marginRight' => $margin,
      'headerHeight' => 0, 'footerHeight' => 0,
    ]);

    foreach ($model['pages'] as $i => $p) {
      qta_export_progress(40 + (int)(25 * $i / max(1, count($model['pages']))), 'Po përgatitet faqja ' . ($i + 1) . ' nga ' . count($model['pages']) . '.');
      if ($p['kind'] === 'attendance') {
        if ($i > 0) {
          /* Paragraf i vogël (1 pt) që e çon tabelën në faqe të re. */
          $section->addText('', ['size' => 1], ['pageBreakBefore' => true, 'spacing' => 20, 'spacingLineRule' => 'exact']);
        }
        qta_lr_docx_attendance($section, $p, $g);
      } else {
        qta_lr_docx_topics($section, $p, $g);
      }
      $cap = $section->addTextRun([
        'spaceBefore' => qta_lr_tw($g['caption_gap']),
        'tabs' => [new \PhpOffice\PhpWord\Style\Tab('right', qta_lr_tw($g['content_w']))],
        'keepLines' => true,
      ]);
      $capFont = ['size' => $g['caption_size']];
      $cap->addText($p['caption']['left'], $capFont);
      $cap->addText(QTA_LR_DOCX_TAB . $p['caption']['right'], $capFont);
    }

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007');
    qta_export_progress(80, 'Po ruhet regjistri Word.');
    $writer->save($path);
    qta_lr_docx_finish($path);
  }

  function qta_lr_docx_attendance($section, array $p, array $g): void
  {
    $cols = QTA_LR_MAX_COLUMNS;
    $c0 = qta_lr_tw($g['att_first_col']);
    $cd = qta_lr_tw($g['att_date_col']);
    $width = $c0 + $cols * $cd;
    $table = $section->addTable([
      'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED,
      'width' => $width, 'unit' => 'dxa',
      'borderSize' => 4, 'borderColor' => '000000',
      'cellMarginTop' => 0, 'cellMarginBottom' => 0, 'cellMarginLeft' => 0, 'cellMarginRight' => 0,
      'columnWidths' => array_merge([$c0], array_fill(0, $cols, $cd)),
    ]);
    $exact = static fn(float $pt) => [qta_lr_tw($pt), ['exactHeight' => true, 'cantSplit' => true]];
    $center = ['alignment' => 'center'];
    $bold = static fn(float $size): array => ['bold' => true, 'size' => $size];
    $small = $g['att_small_size'];

    /* Titulli */
    [$h, $rs] = $exact($g['att_title_h']);
    $table->addRow($h, $rs);
    $table->addCell($width, ['gridSpan' => $cols + 1, 'valign' => 'center'])
      ->addText('Regjistër për orët e mësimit', $bold($g['att_title_size']), $center);

    /* "Muaji:" dhe qeliza "Nr./Dt." (e bashkuar në tre rreshta, me diagonale) */
    [$h, $rs] = $exact($g['att_label_h']);
    $table->addRow($h, $rs);
    $corner = $table->addCell($c0, [
      'vMerge' => 'restart', 'valign' => 'top',
      'borderTopSize' => 4, 'borderTopColor' => QTA_LR_DOCX_CORNER, 'borderTopStyle' => 'single',
    ]);
    $cornerH = $g['att_label_h'] + $g['att_months_h'] + $g['att_dates_h'];
    $line = $small * 1.2207;
    $corner->addText('Nr.', $bold($small), ['alignment' => 'left', 'spaceBefore' => 20, 'indentation' => ['left' => 50]]);
    $corner->addText('Dt.', $bold($small), ['alignment' => 'right', 'spaceBefore' => qta_lr_tw($cornerH - 1.0 - 2 * $line - 1.5), 'indentation' => ['right' => 50]]);
    $table->addCell($cols * $cd, [
      'gridSpan' => $cols, 'valign' => 'center',
      'borderBottomSize' => 0, 'borderBottomColor' => '000000', 'borderBottomStyle' => 'nil',
    ])->addText('Muaji:', $bold($g['att_label_size']), ['indentation' => ['left' => 60]]);

    /* Numrat e muajve, mbi datën e parë të çdo muaji */
    [$h, $rs] = $exact($g['att_months_h']);
    $table->addRow($h, $rs);
    $table->addCell($c0, ['vMerge' => 'continue']);
    foreach ($p['months'] as $s) {
      $table->addCell($s['span'] * $cd, [
        'gridSpan' => $s['span'], 'valign' => 'center',
        'borderTopSize' => 0, 'borderTopColor' => '000000', 'borderTopStyle' => 'nil',
      ])->addText($s['label'], $bold($small), ['indentation' => ['left' => qta_lr_tw(qta_lr_month_pad($s['label'], $g))]]);
    }

    /* Ditët e muajit */
    [$h, $rs] = $exact($g['att_dates_h']);
    $table->addRow($h, $rs);
    $table->addCell($c0, ['vMerge' => 'continue']);
    foreach ($p['columns'] as $c) {
      $cell = $table->addCell($cd, ['valign' => 'center']);
      if ($c !== null) {
        $cell->addText($c['day'], $bold($small), $center);
      }
    }

    /* Rreshtat e kursantëve: 1…N sipas Listës emërore, pastaj rreshta bosh */
    [$h, $rs] = $exact($p['row_height']);
    $numFont = $bold($g['att_number_size']);
    for ($i = 1; $i <= $p['row_count']; $i++) {
      $table->addRow($h, $rs);
      $cell = $table->addCell($c0, ['valign' => 'center']);
      if ($i <= $p['numbered']) {
        $cell->addText((string)$i, $numFont, ['alignment' => 'right', 'indentation' => ['right' => 90]]);
      }
      for ($j = 0; $j < $cols; $j++) {
        $table->addCell($cd);
      }
    }
  }

  function qta_lr_docx_topics($section, array $p, array $g): void
  {
    $heading = $section->addTextRun([
      'pageBreakBefore' => true,
      'spaceAfter' => qta_lr_tw($g['heading_after']),
      'keepNext' => true,
    ]);
    $hf = ['name' => 'Times New Roman', 'size' => $p['heading_size']];
    $heading->addText($p['heading'], $hf + ['bold' => true]);
    if ($p['heading_note'] !== '') {
      $heading->addText(' (' . $p['heading_note'] . ')', $hf);
    }

    [$w1, $w2, $w3] = array_map('qta_lr_tw', $g['top_cols']);
    $pad = qta_lr_tw($g['top_cell_pad']);
    $table = $section->addTable([
      'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED,
      'width' => $w1 + $w2 + $w3, 'unit' => 'dxa',
      'borderSize' => 4, 'borderColor' => '000000',
      'cellMarginTop' => 0, 'cellMarginBottom' => 0, 'cellMarginLeft' => $pad, 'cellMarginRight' => $pad,
      'columnWidths' => [$w1, $w2, $w3],
    ]);
    $mid = ['valign' => 'center'];
    $center = ['alignment' => 'center'];

    $table->addRow(qta_lr_tw($g['top_head_h']), ['exactHeight' => true, 'cantSplit' => true, 'tblHeader' => true]);
    foreach ([[$w1, 'Data'], [$w2, 'Tema'], [$w3, 'Shënime']] as [$w, $label]) {
      $table->addCell($w, $mid)->addText($label, ['bold' => true], $center);
    }
    foreach ($p['rows'] as $r) {
      /* Lartësia "të paktën": e llogaritur me rezervë, që teksti i temës të zërë gjithmonë.
         Word-i i shton kësaj lartësie vijën (0,5 pt), prandaj ajo zbritet këtu. */
      $table->addRow(qta_lr_tw($r['height'] - 0.5), ['cantSplit' => true]);
      $table->addCell($w1, $mid)->addText($r['date_label'], [], $center);
      $table->addCell($w2, $mid)->addText($r['topic_title']);
      $table->addCell($w3, $mid);
    }
    for ($i = 0; $i < $p['fillers']; $i++) {
      $table->addRow(qta_lr_tw($g['top_row_min']), ['exactHeight' => true, 'cantSplit' => true]);
      $table->addCell($w1);
      $table->addCell($w2);
      $table->addCell($w3);
    }
  }

  /**
   * Plotëson XML-në që PHPWord nuk e shkruan vetë: vijën diagonale (w:tr2bl) të
   * qelizës "Nr./Dt." dhe tab-in e rreshtit poshtë tabelës (w:tab).
   */
  function qta_lr_docx_finish(string $path): void
  {
    $zip = new \ZipArchive();
    if ($zip->open($path) !== true) {
      throw new RuntimeException('Dokumenti Word nuk u hap për plotësim.');
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
      $zip->close();
      throw new RuntimeException('Dokumenti Word nuk ka word/document.xml.');
    }
    $diag = '<w:tr2bl w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
    $xml = preg_replace_callback('#<w:tcBorders>((?:(?!</w:tcBorders>).)*?w:color="' . QTA_LR_DOCX_CORNER . '"(?:(?!</w:tcBorders>).)*)</w:tcBorders>#s',
      static fn(array $m): string => '<w:tcBorders>' . str_replace('w:color="' . QTA_LR_DOCX_CORNER . '"', 'w:color="000000"', $m[1]) . $diag . '</w:tcBorders>',
      $xml, -1, $corners);
    $xml = str_replace(QTA_LR_DOCX_TAB, '</w:t><w:tab/><w:t xml:space="preserve">', $xml, $tabs);
    if ($corners < 1 || $tabs < 1 || str_contains($xml, 'w:color="' . QTA_LR_DOCX_CORNER . '"')) {
      $zip->close();
      throw new RuntimeException('Plotësimi i dokumentit Word nuk gjeti vendet e pritura.');
    }
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
  }
}
