<?php
/*
 * Writes the synthetic bank statement PDFs for the receipt import
 * (import2.php) into tests/reference/fixtures/import/. Minimal uncompressed
 * PDF 1.4 files, one Helvetica text line per entry (WinAnsi bytes).
 * Deterministic: rerunning reproduces the committed files.
 *   php tests/reference/fixtures/make-pdf.php
 */
function reference_pdf($lines)
{
    $content = "BT\n/F1 11 Tf\n";
    $y = 800;
    foreach ($lines as $line) {
        $text = str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), iconv('UTF-8', 'CP1252', $line));
        $content .= "1 0 0 1 50 $y Tm\n($text) Tj\n";
        $y -= 16;
    }
    $content .= "ET\n";
    $objects = array(
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
        "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream",
    );
    $pdf = "%PDF-1.4\n";
    $offsets = array();
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

$directory = __DIR__ . '/import';
$files = array(
    // One booking id and one date: matched to voucher 220 (fixtures/extra.sql).
    'statement-single.pdf' => array('Synthetic Bank Beleg', 'Lastschrift BG/000000220', 'Buchungsdatum 15.03.2024'),
    // Cover sheet: interest line (voucher 221) and account fee (voucher 222).
    'statement-cover.pdf' => array(
        'Synthetic Bank Kontoauszug vom 31.03.2024',
        'IBAN AT606000050110117300',
        '31.03 Zinsen HABEN BG/000000221',
        '30.06 Entgelt für Kontoführung',
        'Neuer Kontostand zu Ihren Gunsten 1.000,00',
    ),
    'statement-noid.pdf' => array('Synthetic Bank Information', 'Keine Buchung'),
    'statement-twodates.pdf' => array('Beleg BG/000000999', 'von 01.01.2024', 'bis 02.01.2024', 'am 03.01.2024'),
    // A plain receipt for the voucher upload and download (new.php, file.php).
    'receipt.pdf' => array('Synthetic receipt', 'Betrag 12,34 EUR'),
);
foreach ($files as $name => $lines) {
    file_put_contents("$directory/$name", reference_pdf($lines));
}
echo 'wrote ' . count($files) . " PDF files\n";
