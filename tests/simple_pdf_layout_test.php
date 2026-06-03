<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/simple_pdf.php';

$pdf = new SimplePdfDocument();
$pdf->setHeader('Layout Regression Report', 'Oversized tables should stay inside printable page bounds');

$pdf->addHeading('Oversized table fixture');
$longCell = str_repeat('This cell contains enough report text to exceed a printable page if it is not capped and wrapped safely. ', 120);
$pdf->addTable(
    ['Questionnaire', 'Narrative detail', 'Status'],
    [
        ['Annual competency assessment', $longCell, 'Submitted'],
        ['Follow-up assessment', 'Short row after the oversized entry.', 'Approved'],
    ],
    [24, 58, 18]
);

$output = $pdf->output();
if (!str_starts_with($output, '%PDF-1.4')) {
    fwrite(STDERR, "Expected SimplePdfDocument to emit a PDF 1.4 document.\n");
    exit(1);
}

preg_match_all('/q [0-9.]+ [0-9.]+ [0-9.]+ RG [0-9.]+ w [0-9.]+ (-?[0-9.]+) [0-9.]+ [0-9.]+ re S Q/', $output, $matches);
if (empty($matches[1])) {
    fwrite(STDERR, "Expected table outline rectangles in generated PDF output.\n");
    exit(1);
}

foreach ($matches[1] as $yCoordinate) {
    if ((float)$yCoordinate < 59.5) {
        fwrite(STDERR, "Table rectangle extended into the footer or below the page margin.\n");
        exit(1);
    }
}

if (strpos($output, '…') === false) {
    fwrite(STDERR, "Expected oversized cell text to be truncated with an ellipsis.\n");
    exit(1);
}

echo "Simple PDF layout tests passed.\n";
