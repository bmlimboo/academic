<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Report exporter for the Research Tracking Desk. Turns a report into a Word (.docx), PDF or Excel (.xlsx) file.
// No Composer packages are needed. Word and Excel need PHP's "zip" extension, PDF needs "mbstring" (both are on in XAMPP).
//
// A report is:  ['title' => '', 'subtitle' => '', 'sections' => [ ['key' => '', 'tab' => '', 'heading' => '', 'note' => '',
//                'cols' => ['A', 'B'], 'rows' => [[cell, cell], ...], 'hints' => [col index => tooltip]] ]]
// A cell is a plain value (text, int, float or null) or ['v' => value, 'c' => 'green'|'red'|'amber'|'gray'|'blue'] for a coloured cell.


const RX_NAVY = '1B2A4A';
const RX_COLORS = [   // fill, text
    'green' => ['DCF5E5', '17703B'], 'red' => ['FDE0E0', 'A32020'], 'amber' => ['FFF1D6', '8A5A00'],
    'gray' => ['EEF0F5', '6B7A99'], 'blue' => ['E3E8FF', '3340B5'],
];
// Helvetica character widths for ASCII 32..126 (per 1000 em), used to wrap text in the PDF
const RX_HW = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
    278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722,
    667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556,
    333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

// ------------------------------------------------------------------ small helpers
function rx_cell($c): array { return is_array($c) ? [$c['v'] ?? null, (string)($c['c'] ?? '')] : [$c, '']; }
function rx_txt($v, string $nullText = ''): string
{
    if ($v === null || $v === '') { return $nullText; }
    if (is_float($v)) { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); }
    return (string)$v;
}
function rx_clean(string $s): string { return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? ''; }
function rx_xml(string $s): string { return htmlspecialchars(rx_clean($s), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function rx_col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) { $s = chr(65 + ($i - 1) % 26) . $s; } return $s; }
function rx_rgb(string $hex): array { return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255]; }
function rx_isnum($v): bool { return is_int($v) || is_float($v); }
function rx_words(string $t): int { $m = 0; foreach (preg_split('/\s+/', $t) ?: [] as $w) { $m = max($m, mb_strlen($w)); } return $m; }

// Relative column widths from the content, scaled to $total
function rx_widths(array $cols, array $rows, float $total): array
{
    $w = [];
    foreach ($cols as $j => $c) {
        $max = 0; $word = rx_words((string)$c);
        foreach ($rows as $r) { [$v] = rx_cell($r[$j] ?? null); $t = rx_txt($v, '-'); $max = max($max, mb_strlen($t)); $word = max($word, rx_words($t)); }
        $w[$j] = max(4.0, $word + 1.0, min($max, 26) * 0.75);
    }
    $sum = array_sum($w) ?: 1.0;
    return array_map(fn($x) => $x / $sum * $total, $w);
}


// Width of text in points, using Helvetica metrics (used for PDF and Word column sizing)
function rx_tw(string $s, float $size, bool $bold = false): float
{
    $t = 0; for ($i = 0, $n = strlen($s); $i < $n; $i++) { $o = ord($s[$i]); $t += ($o >= 32 && $o <= 126) ? RX_HW[$o - 32] : 556; }
    return $t / 1000 * $size * ($bold ? 1.07 : 1.0);
}
// Column widths (points) that never split a word: every column gets at least the width of its longest word, the rest is shared by need.
// $fits is false when even the minimum widths are wider than $total (the caller then tries a smaller font).
function rx_fit(array $cols, array $rows, float $total, float $size, float $pad, bool &$fits): array
{
    $min = []; $pref = [];
    foreach ($cols as $j => $c) {
        $mn = 0.0; $pf = 0.0;
        foreach (preg_split('/\s+/', (string)$c) ?: [] as $w) { $mn = max($mn, rx_tw($w, $size, true)); }
        foreach ($rows as $r) {
            [$v, $col] = rx_cell($r[$j] ?? null); $t = rx_txt($v, '–'); $b = $col !== '';
            foreach (preg_split('/\s+/', $t) ?: [] as $w) { $mn = max($mn, rx_tw($w, $size, $b)); }
            $pf = max($pf, min(rx_tw($t, $size, $b), 170.0));
        }
        $min[$j] = $mn + 2 * $pad + 2.0; $pref[$j] = max($min[$j], $pf + 2 * $pad + 2.0);
    }
    $sm = array_sum($min); $sp = array_sum($pref); $fits = $sm <= $total;
    if (!$fits) { return array_map(fn($x) => $x / $sm * $total, $min); }
    if ($sp <= $total) { return array_map(fn($x) => $x + ($total - $sp) * $x / $sp, $pref); }
    $need = $total - $sm; $room = $sp - $sm;
    return array_map(fn($j) => $min[$j] + ($pref[$j] - $min[$j]) * $need / $room, array_keys($min));
}

// ------------------------------------------------------------------ Excel (.xlsx)
function rx_xlsx(array $r): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'rx'); $z = new ZipArchive();
    $z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $secs = array_values($r['sections']); $n = count($secs);
    $ct = $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = $head . '<workbook ' . $ns . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rel = $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $used = [];
    foreach ($secs as $i => $s) {
        $name = trim(mb_substr(preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', (string)($s['tab'] ?? $s['heading'] ?? 'Sheet')) ?? 'Sheet', 0, 31)) ?: 'Sheet';
        $base = $name; $k = 2; while (in_array(mb_strtolower($name), $used, true)) { $name = mb_substr($base, 0, 28) . ' ' . $k++; }
        $used[] = mb_strtolower($name);
        $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . rx_xml($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        $rel .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        $z->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', rx_xlsx_sheet($r, $s, $head, $ns));
    }
    $rel .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $z->addFromString('[Content_Types].xml', $ct . '</Types>');
    $z->addFromString('_rels/.rels', $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', $rel);
    $z->addFromString('xl/styles.xml', rx_xlsx_styles($head, $ns));
    $z->close();
    $data = (string)file_get_contents($tmp); @unlink($tmp);
    return $data;
}
function rx_xlsx_styles(string $head, string $ns): string
{
    $fonts = [['', '000000', 11], ['<b/>', '1B2A4A', 14], ['<b/>', 'FFFFFF', 11], ['<i/>', '6B7A99', 10], ['', '17703B', 11], ['', 'A32020', 11], ['', '8A5A00', 11], ['', '6B7A99', 11], ['', '3340B5', 11], ['<b/>', '1B2A4A', 12]];
    $fx = ''; foreach ($fonts as [$b, $c, $sz]) { $fx .= '<font>' . $b . '<sz val="' . $sz . '"/><color rgb="FF' . $c . '"/><name val="Calibri"/></font>'; }
    $fills = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
    foreach (['1B2A4A', 'DCF5E5', 'FDE0E0', 'FFF1D6', 'EEF0F5', 'E3E8FF'] as $c) { $fills .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $c . '"/><bgColor indexed="64"/></patternFill></fill>'; }
    $bd = '<border><left/><right/><top/><bottom/><diagonal/></border><border>' . implode('', array_map(fn($s) => "<$s style=\"thin\"><color rgb=\"FFBFC7DD\"/></$s>", ['left', 'right', 'top', 'bottom'])) . '<diagonal/></border>';
    $xf = fn(int $font, int $fill, int $border, string $align) => '<xf numFmtId="0" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1"' . ($align ? ' applyAlignment="1"><alignment ' . $align . '/></xf>' : '/>');
    $xfs = $xf(0, 0, 0, '') . $xf(1, 0, 0, '') . $xf(2, 2, 1, 'horizontal="center" vertical="center" wrapText="1"') . $xf(0, 0, 1, 'vertical="top" wrapText="1"')
        . $xf(4, 3, 1, 'horizontal="center" vertical="top" wrapText="1"') . $xf(5, 4, 1, 'horizontal="center" vertical="top" wrapText="1"')
        . $xf(6, 5, 1, 'horizontal="center" vertical="top" wrapText="1"') . $xf(7, 6, 1, 'horizontal="center" vertical="top" wrapText="1"')
        . $xf(8, 7, 1, 'horizontal="center" vertical="top" wrapText="1"') . $xf(3, 0, 0, '') . $xf(9, 0, 0, '')
        . $xf(0, 0, 1, 'horizontal="center" vertical="top" wrapText="1"');
    return $head . '<styleSheet ' . $ns . '><fonts count="' . count($fonts) . '">' . $fx . '</fonts><fills count="8">' . $fills . '</fills><borders count="2">' . $bd . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="12">' . $xfs . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}
function rx_xlsx_sheet(array $r, array $s, string $head, string $ns): string
{
    $xc = function (string $ref, $v, int $style): string {
        if ($v === null || $v === '') { return '<c r="' . $ref . '" s="' . $style . '"/>'; }
        if (rx_isnum($v)) { return '<c r="' . $ref . '" s="' . $style . '"><v>' . (is_float($v) ? rtrim(rtrim(sprintf('%.4F', $v), '0'), '.') : $v) . '</v></c>'; }
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . rx_xml((string)$v) . '</t></is></c>';
    };
    $styleOf = ['green' => 4, 'red' => 5, 'amber' => 6, 'gray' => 7, 'blue' => 8];
    $cols = $s['cols']; $rows = $s['rows']; $nc = count($cols);
    $data = '<row r="1">' . $xc('A1', $r['title'] . ' - ' . ($s['heading'] ?? ''), 1) . '</row>';
    $data .= '<row r="2">' . $xc('A2', $r['subtitle'] ?? '', 9) . '</row>';
    $data .= '<row r="3">' . $xc('A3', $s['note'] ?? '', 9) . '</row>';
    $h = '<row r="5" ht="32" customHeight="1">'; foreach ($cols as $j => $c) { $h .= $xc(rx_col($j) . '5', (string)$c, 2); } $data .= $h . '</row>';
    foreach ($rows as $i => $row) {
        $rn = $i + 6; $x = '<row r="' . $rn . '">';
        for ($j = 0; $j < $nc; $j++) { [$v, $c] = rx_cell($row[$j] ?? null); $x .= $xc(rx_col($j) . $rn, $v, $c !== '' ? ($styleOf[$c] ?? 3) : (rx_isnum($v) ? 11 : 3)); }
        $data .= $x . '</row>';
    }
    $w = rx_widths($cols, $rows, 140.0); $cx = '';
    foreach ($w as $j => $x) { $cx .= '<col min="' . ($j + 1) . '" max="' . ($j + 1) . '" width="' . round(max(8, min(55, $x * 1.1 + 2)), 1) . '" customWidth="1"/>'; }
    $last = rx_col(max(0, $nc - 1)) . (count($rows) + 5);
    return $head . '<worksheet ' . $ns . '><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/><cols>' . $cx . '</cols><sheetData>' . $data . '</sheetData>'
        . ($rows ? '<autoFilter ref="A5:' . $last . '"/>' : '') . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
}

// ------------------------------------------------------------------ Word (.docx)
function rx_docx(array $r): string
{
    $W = 15398;   // usable width in twips: A4 landscape with 1.27 cm margins
    $para = function (string $text, int $sz, bool $bold, string $color, bool $italic = false, int $before = 0, int $after = 60, bool $keep = false): string {
        return '<w:p><w:pPr>' . ($keep ? '<w:keepNext/>' : '') . '<w:spacing w:before="' . $before . '" w:after="' . $after . '"/></w:pPr><w:r><w:rPr>' . ($bold ? '<w:b/>' : '') . ($italic ? '<w:i/>' : '')
            . '<w:color w:val="' . $color . '"/><w:sz w:val="' . $sz . '"/></w:rPr><w:t xml:space="preserve">' . rx_xml($text) . '</w:t></w:r></w:p>';
    };
    $body = $para((string)$r['title'], 36, true, RX_NAVY, false, 0, 40) . $para((string)($r['subtitle'] ?? ''), 18, false, '6B7A99', false, 0, 160);
    foreach ($r['sections'] as $s) {
        $cols = $s['cols']; $rows = $s['rows']; $nc = count($cols);
        $body .= $para((string)($s['heading'] ?? ''), 26, true, RX_NAVY, false, 200, 60, true);
        if (!empty($s['note'])) { $body .= $para((string)$s['note'], 16, false, '6B7A99', true, 0, 80, true); }
        $pt = 9.0; $fits = false;
        do { $wp = rx_fit($cols, $rows, $W / 20, $pt, 3.5, $fits); if (!$fits && $pt > 5.5) { $pt -= 0.5; } } while (!$fits && $pt >= 5.5);
        $tw = array_map(fn($x) => (int)floor($x * 20), $wp);
        $sz = (int)round($pt * 2);
        $cell = function (string $text, int $w, ?string $fill, string $color, bool $bold, string $jc) use ($sz): string {
            $runs = []; foreach (explode("\n", $text) as $l) { $runs[] = '<w:t xml:space="preserve">' . rx_xml($l) . '</w:t>'; }
            return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($fill ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '') . '<w:vAlign w:val="center"/></w:tcPr>'
                . '<w:p><w:pPr><w:spacing w:before="20" w:after="20"/><w:jc w:val="' . $jc . '"/></w:pPr><w:r><w:rPr>' . ($bold ? '<w:b/>' : '') . '<w:color w:val="' . $color . '"/><w:sz w:val="' . $sz . '"/></w:rPr>'
                . implode('<w:br/>', $runs) . '</w:r></w:p></w:tc>';
        };
        $bc = fn(string $e) => '<w:' . $e . ' w:val="single" w:sz="4" w:space="0" w:color="BFC7DD"/>';
        $t = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($tw) . '" w:type="dxa"/><w:tblBorders>' . $bc('top') . $bc('left') . $bc('bottom') . $bc('right') . $bc('insideH') . $bc('insideV')
            . '</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($tw as $x) { $t .= '<w:gridCol w:w="' . $x . '"/>'; }
        $t .= '</w:tblGrid><w:tr><w:trPr><w:cantSplit/><w:tblHeader/></w:trPr>';
        foreach ($cols as $j => $c) { $t .= $cell((string)$c, $tw[$j], RX_NAVY, 'FFFFFF', true, 'center'); }
        $t .= '</w:tr>';
        foreach ($rows as $i => $row) {
            $t .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            for ($j = 0; $j < $nc; $j++) {
                [$v, $c] = rx_cell($row[$j] ?? null);
                $txt = rx_txt($v, '–');
                if ($c !== '' && isset(RX_COLORS[$c])) { $t .= $cell($txt, $tw[$j], RX_COLORS[$c][0], RX_COLORS[$c][1], true, 'center'); }
                else { $t .= $cell($txt, $tw[$j], $i % 2 ? 'F6F7FC' : null, '1F3560', false, rx_isnum($v) ? 'center' : 'left'); }
            }
            $t .= '</w:tr>';
        }
        if (!$rows) { $t .= '<w:tr>' . $cell('Nothing to show.', array_sum($tw), null, '6B7A99', false, 'left') . '</w:tr>'; }
        $body .= $t . '</w:tbl>' . $para('', 12, false, '000000', false, 0, 0);
    }
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $doc = $head . '<w:document ' . $w . '><w:body>' . $body . '<w:sectPr><w:footerReference w:type="default" r:id="rId2"/><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
        . '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="400" w:footer="400" w:gutter="0"/></w:sectPr></w:body></w:document>';
    $run = '<w:rPr><w:color w:val="6B7A99"/><w:sz w:val="16"/></w:rPr>';
    $ftr = $head . '<w:ftr ' . $w . '><w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r>' . $run . '<w:t xml:space="preserve">Page </w:t></w:r><w:fldSimple w:instr=" PAGE "><w:r>' . $run . '<w:t>1</w:t></w:r></w:fldSimple>'
        . '<w:r>' . $run . '<w:t xml:space="preserve"> of </w:t></w:r><w:fldSimple w:instr=" NUMPAGES "><w:r>' . $run . '<w:t>1</w:t></w:r></w:fldSimple></w:p></w:ftr>';
    $styles = $head . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="20"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style></w:styles>';
    $tmp = tempnam(sys_get_temp_dir(), 'rx'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>');
    $z->addFromString('_rels/.rels', $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/_rels/document.xml.rels', $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>');
    $z->addFromString('word/document.xml', $doc); $z->addFromString('word/styles.xml', $styles); $z->addFromString('word/footer1.xml', $ftr);
    $z->close();
    $data = (string)file_get_contents($tmp); @unlink($tmp);
    return $data;
}

// ------------------------------------------------------------------ PDF
function rx_pdf(array $r): string
{
    $PW = 842.0; $PH = 595.0; $ML = 36.0; $MT = 40.0; $MB = 44.0; $TW = $PW - 2 * $ML;
    $enc = fn(string $s): string => mb_convert_encoding(rx_clean($s), 'Windows-1252', 'UTF-8');
    $esc = fn(string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    $tw = fn(string $s, float $size, bool $bold): float => rx_tw($s, $size, $bold);
    $wrap = function (string $s, float $maxw, float $size, bool $bold) use ($tw): array {
        $lines = [];
        foreach (explode("\n", $s) as $para) {
            $cur = '';
            foreach (preg_split('/ +/', $para) ?: [''] as $word) {
                while (strlen($word) > 1 && $tw($word, $size, $bold) > $maxw) {
                    $k = strlen($word) - 1; while ($k > 1 && $tw(substr($word, 0, $k), $size, $bold) > $maxw) { $k--; }
                    if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                    $lines[] = substr($word, 0, $k); $word = substr($word, $k);
                }
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur !== '' && $tw($try, $size, $bold) > $maxw) { $lines[] = $cur; $cur = $word; } else { $cur = $try; }
            }
            $lines[] = $cur;
        }
        return $lines;
    };
    $pages = []; $c = ''; $y = $MT;
    $text = function (float $x, float $base, string $line, float $size, int $font, string $hex) use (&$c, $esc, $PH): void {
        [$a, $b, $d] = rx_rgb($hex);
        $c .= sprintf("BT /F%d %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n", $font, $size, $a, $b, $d, $x, $PH - $base, $esc($line));
    };
    $box = function (float $x, float $top, float $w, float $h, ?string $fill) use (&$c, $PH): void {
        if ($fill) { [$a, $b, $d] = rx_rgb($fill); $c .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $a, $b, $d, $x, $PH - $top - $h, $w, $h); }
        $c .= sprintf("0.749 0.780 0.867 RG 0.4 w %.2F %.2F %.2F %.2F re S\n", $x, $PH - $top - $h, $w, $h);
    };
    $newPage = function () use (&$pages, &$c, &$y, $MT): void { $pages[] = $c; $c = ''; $y = $MT; };

    $text($ML, $y + 16, $enc((string)$r['title']), 16, 2, RX_NAVY); $y += 24;
    foreach ($wrap($enc((string)($r['subtitle'] ?? '')), $TW, 9, false) as $l) { $text($ML, $y + 8, $l, 9, 1, '6B7A99'); $y += 12; }
    $y += 8;
    foreach ($r['sections'] as $s) {
        $cols = array_map(fn($x) => $enc((string)$x), $s['cols']); $rows = $s['rows']; $nc = count($cols);
        $size = $nc <= 7 ? 9.0 : 7.5; $pad = 3.2; $fits = false;
        do { $cw = rx_fit($s['cols'], $rows, $TW, $size, $pad, $fits); if (!$fits && $size > 5.0) { $size -= 0.5; } } while (!$fits && $size >= 5.0);
        $lead = $size * 1.22;
        $note = !empty($s['note']) ? $wrap($enc((string)$s['note']), $TW, 8, false) : [];
        $drawHeader = function () use (&$y, $cols, $cw, $size, $lead, $pad, $wrap, $box, $text, $ML, $nc): void {
            $lines = []; $h = 0.0;
            foreach ($cols as $j => $t) { $lines[$j] = $wrap($t, $cw[$j] - 2 * $pad, $size, true); $h = max($h, count($lines[$j]) * $lead + 2 * $pad); }
            $x = $ML;
            foreach ($cols as $j => $t) {
                $box($x, $y, $cw[$j], $h, RX_NAVY);
                foreach ($lines[$j] as $k => $l) { $text($x + $pad, $y + $pad + $size + $k * $lead - 1, $l, $size, 2, 'FFFFFF'); }
                $x += $cw[$j];
            }
            $y += $h;
        };
        if ($y + 22 + count($note) * 10 + 40 > $PH - $MB) { $newPage(); }
        $text($ML, $y + 12, $enc((string)($s['heading'] ?? '')), 12, 2, RX_NAVY); $y += 18;
        foreach ($note as $l) { $text($ML, $y + 7, $l, 8, 3, '6B7A99'); $y += 10; }
        $y += 4;
        $drawHeader();
        foreach ($rows as $i => $row) {
            $cells = []; $h = 0.0;
            for ($j = 0; $j < $nc; $j++) {
                [$v, $col] = rx_cell($row[$j] ?? null);
                $t = $enc(rx_txt($v, '–')); $isCol = $col !== '' && isset(RX_COLORS[$col]);
                $lines = $wrap($t, $cw[$j] - 2 * $pad, $size, $isCol);
                $cells[$j] = [$lines, $isCol ? RX_COLORS[$col][0] : ($i % 2 ? 'F6F7FC' : null), $isCol ? RX_COLORS[$col][1] : '1F3560', $isCol, $isCol || rx_isnum($v)];
                $h = max($h, count($lines) * $lead + 2 * $pad);
            }
            if ($y + $h > $PH - $MB) { $newPage(); $drawHeader(); }
            $x = $ML;
            foreach ($cells as $j => [$lines, $fill, $tc, $bold, $center]) {
                $box($x, $y, $cw[$j], $h, $fill);
                foreach ($lines as $k => $l) {
                    $lx = $center ? $x + ($cw[$j] - $tw($l, $size, $bold)) / 2 : $x + $pad;
                    $text($lx, $y + $pad + $size + $k * $lead - 1, $l, $size, $bold ? 2 : 1, $tc);
                }
                $x += $cw[$j];
            }
            $y += $h;
        }
        if (!$rows) { $text($ML, $y + 14, 'Nothing to show.', 9, 1, '6B7A99'); $y += 20; }
        $y += 16;
    }
    $pages[] = $c; $total = count($pages);
    $foot = $enc('Generated ' . date('d M Y, H:i'));
    foreach ($pages as $i => $content) {
        $c = ''; $text($ML, $PH - 24, $foot, 7, 1, '6B7A99'); $label = 'Page ' . ($i + 1) . ' of ' . $total;
        $text($PW - $ML - $tw($label, 7, false), $PH - 24, $label, 7, 1, '6B7A99'); $pages[$i] = $content . $c;
    }
    $o = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>', 5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>'];
    $kids = [];
    foreach ($pages as $i => $content) {
        $pid = 6 + 2 * $i; $cid = $pid + 1; $kids[] = $pid . ' 0 R';
        $o[$pid] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Contents ' . $cid . ' 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> >>';
        $o[$cid] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
    }
    $o[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($o);
    $pdf = "%PDF-1.4\n"; $off = [];
    foreach ($o as $id => $body) { $off[$id] = strlen($pdf); $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n"; }
    $x = strlen($pdf); $max = max(array_keys($o));
    $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $max; $id++) { $pdf .= sprintf("%010d 00000 n \n", $off[$id]); }
    return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $x . "\n%%EOF";
}

// ------------------------------------------------------------------ send a download
function rx_send(array $report, string $fmt, string $basename): void
{
    $fmt = strtolower($fmt);
    if (!in_array($fmt, ['docx', 'pdf', 'xlsx'], true)) { http_response_code(400); exit('Unknown file type.'); }
    if ($fmt !== 'pdf' && !class_exists('ZipArchive')) { http_response_code(500); exit('Word and Excel files need the PHP "zip" extension. Enable extension=zip in php.ini and restart Apache.'); }
    if ($fmt === 'pdf' && !function_exists('mb_convert_encoding')) { http_response_code(500); exit('PDF files need the PHP "mbstring" extension. Enable extension=mbstring in php.ini and restart Apache.'); }
    $data = ['docx' => 'rx_docx', 'pdf' => 'rx_pdf', 'xlsx' => 'rx_xlsx'][$fmt]($report);
    $types = ['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    $name = trim(preg_replace('/[^\p{L}\p{N}\-_ ]+/u', '', $basename) ?? 'report') . '.' . $fmt;
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: ' . $types[$fmt]);
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Cache-Control: private, no-store');
    echo $data; exit;
}

// ------------------------------------------------------------------ on-screen pieces (use e() and badge() from lib.php)
function rx_css(): string
{
    return '<style>.rxtable th,.rxtable td{padding:8px 9px;font-size:13px}.rxtable th{text-align:center}.rxtable td.c{text-align:center;white-space:nowrap}'
        . '.rxtabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 14px}.rxtabs a{padding:8px 14px;border-radius:10px;background:#eef0ff;font-weight:600}.rxtabs a:hover{text-decoration:none;filter:brightness(.97)}'
        . '.rxtabs a.on{background:var(--indigo);color:#fff}.rxdl{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:6px}.rxdl .lbl{color:var(--muted);font-size:13px;margin-right:2px}'
        . '.rxcheck{display:flex;gap:8px;align-items:center;font-weight:600;margin:0}.rxcheck input{width:auto;margin:0}</style>';
}
function rx_table_html(array $s): string
{
    $h = '<div class="tablewrap"><table class="rxtable"><thead><tr>';
    foreach ($s['cols'] as $j => $c) { $h .= '<th' . (isset($s['hints'][$j]) ? ' title="' . e($s['hints'][$j]) . '"' : '') . '>' . e((string)$c) . '</th>'; }
    $h .= '</tr></thead><tbody>';
    foreach ($s['rows'] as $row) {
        $h .= '<tr>';
        for ($j = 0; $j < count($s['cols']); $j++) {
            [$v, $col] = rx_cell($row[$j] ?? null); $t = rx_txt($v, '–');
            $h .= '<td' . (($col !== '' || rx_isnum($v)) ? ' class="c"' : '') . '>' . ($col !== '' ? badge($t, $col) : e($t)) . '</td>';
        }
        $h .= '</tr>';
    }
    if (!$s['rows']) { $h .= '<tr><td colspan="' . count($s['cols']) . '" class="empty">Nothing to show.</td></tr>'; }
    return $h . '</tbody></table></div>';
}
function rx_tabs(string $page, array $tabs, string $cur, array $keep): string
{
    $h = '<nav class="rxtabs">';
    foreach ($tabs as $key => $label) { $h .= '<a class="' . ($key === $cur ? 'on' : '') . '" href="' . e($page . '?' . http_build_query($keep + ['tab' => $key])) . '">' . e($label) . '</a>'; }
    return $h . '</nav>';
}
function rx_download_bar(string $page, string $tab, string $tabLabel, array $keep): string
{
    $link = fn(string $fmt, string $part, string $label, string $cls) => '<a class="btn small ' . $cls . '" href="' . e($page . '?' . http_build_query($keep + ['download' => $fmt, 'part' => $part])) . '">' . e($label) . '</a>';
    $fm = ['docx' => 'Word', 'pdf' => 'PDF', 'xlsx' => 'Excel'];
    $h = '<div class="rxdl"><span class="lbl">This table (' . e($tabLabel) . ')</span>';
    foreach ($fm as $f => $l) { $h .= $link($f, $tab, $l, ''); }
    $h .= '</div><div class="rxdl"><span class="lbl">Full report</span>';
    foreach ($fm as $f => $l) { $h .= $link($f, 'all', $l, 'ghost'); }
    return $h . '</div>';
}
