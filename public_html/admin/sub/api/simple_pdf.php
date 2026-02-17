<?php
// Simple PDF generation without external libraries
// This creates a basic PDF structure

function createSimplePDF($content, $filename) {
    // Basic PDF structure
    $pdf = "%PDF-1.4\n";
    $pdf .= "1 0 obj\n";
    $pdf .= "<<\n";
    $pdf .= "/Type /Catalog\n";
    $pdf .= "/Pages 2 0 R\n";
    $pdf .= ">>\n";
    $pdf .= "endobj\n";
    
    $pdf .= "2 0 obj\n";
    $pdf .= "<<\n";
    $pdf .= "/Type /Pages\n";
    $pdf .= "/Kids [3 0 R]\n";
    $pdf .= "/Count 1\n";
    $pdf .= ">>\n";
    $pdf .= "endobj\n";
    
    $pdf .= "3 0 obj\n";
    $pdf .= "<<\n";
    $pdf .= "/Type /Page\n";
    $pdf .= "/Parent 2 0 R\n";
    $pdf .= "/MediaBox [0 0 612 792]\n";
    $pdf .= "/Contents 4 0 R\n";
    $pdf .= "/Resources <<\n";
    $pdf .= "/Font <<\n";
    $pdf .= "/F1 5 0 R\n";
    $pdf .= ">>\n";
    $pdf .= ">>\n";
    $pdf .= ">>\n";
    $pdf .= "endobj\n";
    
    // Content stream
    $stream = "BT\n";
    $stream .= "/F1 12 Tf\n";
    $stream .= "50 750 Td\n";
    $stream .= "(" . $content . ") Tj\n";
    $stream .= "ET\n";
    
    $pdf .= "4 0 obj\n";
    $pdf .= "<<\n";
    $pdf .= "/Length " . strlen($stream) . "\n";
    $pdf .= ">>\n";
    $pdf .= "stream\n";
    $pdf .= $stream;
    $pdf .= "endstream\n";
    $pdf .= "endobj\n";
    
    $pdf .= "5 0 obj\n";
    $pdf .= "<<\n";
    $pdf .= "/Type /Font\n";
    $pdf .= "/Subtype /Type1\n";
    $pdf .= "/BaseFont /Helvetica\n";
    $pdf .= ">>\n";
    $pdf .= "endobj\n";
    
    $pdf .= "xref\n";
    $pdf .= "0 6\n";
    $pdf .= "0000000000 65535 f \n";
    $pdf .= "0000000009 65535 n \n";
    $pdf .= "0000000074 65535 n \n";
    $pdf .= "0000000120 65535 n \n";
    $pdf .= "0000000179 65535 n \n";
    $pdf .= "0000000364 65535 n \n";
    $pdf .= "trailer\n";
    $pdf .= "<<\n";
    $pdf .= "/Size 6\n";
    $pdf .= "/Root 1 0 R\n";
    $pdf .= ">>\n";
    $pdf .= "startxref\n";
    $pdf .= "492\n";
    $pdf .= "%%EOF\n";
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    echo $pdf;
}

// Test the simple PDF
if (isset($_GET['test'])) {
    createSimplePDF("Hello World! This is a test PDF.", "test.pdf");
    exit;
}
?>