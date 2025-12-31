<?php
// asset/forms/api/download_template.php
// Downloads the template PDF for form submissions

try {
    // Get user ID from request (optional)
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    
    // Path to the template PDF
    $pdfPath = __DIR__ . '/../../images/template.pdf';
    
    if (!file_exists($pdfPath)) {
        http_response_code(404);
        echo 'PDF template not found';
        exit;
    }
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="submission_' . ($userId > 0 ? $userId : 'template') . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    // Output the PDF file
    readfile($pdfPath);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    echo 'Server error occurred';
    exit;
}
?>