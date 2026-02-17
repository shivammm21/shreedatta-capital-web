<?php
// admin/super/api/generate_pdf.php
// Generates dynamic PDF with user data using TCPDF

// Simple PDF generation without external libraries
class SimplePDF {
    private $content = '';
    private $title = '';
    
    public function __construct($title = 'Document') {
        $this->title = $title;
    }
    
    public function addContent($html) {
        $this->content .= $html;
    }
    
    public function output($filename = 'document.pdf') {
        // For now, we'll create a simple HTML to PDF conversion
        // In production, you'd use a proper PDF library
        
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars($this->title) . '</title>
            <style>
                @page { margin: 20mm; }
                body { 
                    font-family: "DejaVu Sans", Arial, sans-serif; 
                    font-size: 12px; 
                    line-height: 1.4;
                    margin: 0;
                    padding: 0;
                }
                .header { 
                    text-align: center; 
                    margin-bottom: 30px; 
                    border-bottom: 2px solid #333;
                    padding-bottom: 15px;
                }
                .title { 
                    font-size: 20px; 
                    font-weight: bold; 
                    margin-bottom: 5px; 
                    color: #333;
                }
                .subtitle { 
                    font-size: 16px; 
                    margin-bottom: 20px; 
                    color: #666;
                }
                .content { margin: 20px 0; }
                .user-info { 
                    background: #f9f9f9;
                    padding: 15px;
                    border: 1px solid #ddd;
                    margin: 20px 0;
                }
                .user-info strong { color: #333; }
                .terms { 
                    border: 2px solid #333; 
                    padding: 20px; 
                    margin: 20px 0;
                    background: #fff;
                }
                .terms h4 { 
                    margin-top: 0; 
                    color: #333;
                    border-bottom: 1px solid #ccc;
                    padding-bottom: 10px;
                }
                .terms p { margin: 10px 0; }
                .signature-section {
                    margin-top: 40px;
                    text-align: center;
                }
                .tick { 
                    font-size: 60px; 
                    color: #28a745; 
                    margin: 20px 0;
                    text-align: center;
                }
                .username { 
                    text-align: center; 
                    font-weight: bold; 
                    font-size: 16px;
                    margin-top: 15px;
                    border-top: 2px solid #333;
                    padding-top: 10px;
                    display: inline-block;
                    min-width: 200px;
                }
            </style>
        </head>
        <body>' . $this->content . '</body>
        </html>';
        
        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        // For now, we'll use wkhtmltopdf or similar tool
        // Since we don't have external tools, let's use a simple approach
        
        // Create a temporary HTML file
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.html';
        file_put_contents($tempFile, $html);
        
        // Try to use wkhtmltopdf if available, otherwise return HTML
        $pdfFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
        
        // Check if wkhtmltopdf is available
        $wkhtmltopdf = 'wkhtmltopdf';
        $command = $wkhtmltopdf . ' --page-size A4 --margin-top 20mm --margin-bottom 20mm --margin-left 15mm --margin-right 15mm "' . $tempFile . '" "' . $pdfFile . '" 2>&1';
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($pdfFile)) {
            // PDF generated successfully
            header('Content-Length: ' . filesize($pdfFile));
            readfile($pdfFile);
            unlink($tempFile);
            unlink($pdfFile);
        } else {
            // Fallback: return HTML
            unlink($tempFile);
            header('Content-Type: text/html; charset=UTF-8');
            echo $html;
        }
    }
}

try {
    // Get user ID from request
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    
    if ($userId <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid user ID';
        exit;
    }
    
    // Include database connection
    require_once '../db.php';
    
    // Fetch user data from all-submissions and forms_aggri tables
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.first_name,
            s.last_name,
            s.forms_aggri_id,
            s.token_no,
            s.draw_name,
            s.date_time,
            f.form_name
        FROM `all-submissions` s
        LEFT JOIN `forms_aggri` f ON s.forms_aggri_id = f.id
        WHERE s.id = ?
        LIMIT 1
    ");
    
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'User data not found';
        exit;
    }
    
    // Prepare data
    $userName = trim($userData['first_name'] . ' ' . $userData['last_name']);
    $formName = $userData['form_name'] ?? 'Gold';
    $tokenNumbers = $userData['token_no'] ?? '';
    $drawName = $userData['draw_name'] ?? '';
    $submissionDate = $userData['date_time'] ? date('d/m/Y', strtotime($userData['date_time'])) : date('d/m/Y');
    
    $title = "Shree Datta Capital Agreement";
    $subtitle = $formName . " Agreement";
    
    // Create PDF
    $pdf = new SimplePDF($title);
    
    $content = '
    <div class="header">
        <div class="title">' . htmlspecialchars($title) . '</div>
        <div class="subtitle">' . htmlspecialchars($subtitle) . '</div>
    </div>
    
    <div class="content">
        <h3>Shree Datta Capital - Terms and Conditions Agreement</h3>
        
        <div class="user-info">
            <strong>Name:</strong> ' . htmlspecialchars($userName) . '<br>
            <strong>Token number(s):</strong> ' . htmlspecialchars($tokenNumbers) . '<br>
            <strong>Date:</strong> ' . htmlspecialchars($submissionDate) . '<br>
            <strong>Draw Category:</strong> ' . htmlspecialchars($formName) . '
        </div>
        
        <div class="terms">
            <h4>Terms and conditions</h4>
            <p>1) To become a member of <strong>Shree Datta Capital</strong> draw, it is mandatory to pay Rs. 1000/- membership fee and book your number.</p>
            <p>2) Your membership fee is non-refundable and non-transferable to become a member of Shree Datta Capital.</p>
            <p>3) Your membership fee must be deposited 1 day before the draw.</p>
            <p>4) Your membership fee should be sent in cash form or via the statement sent to you. If you send the membership fee via online transfer, your membership and your number will be removed.</p>
            <p>5) You will not be able to exit the draw within 21 months. If you exit the draw before 21 months for any reason, your deposited amount will be received in the 22nd month after the draw is completed.</p>
            <p>6) If you are sending your membership fee from another number, it is mandatory to inform us before sending. If you send from another number along with a different name, your membership fee will be considered as not deposited and your number will be removed.</p>
            <p>7) Please note that if you do not complete the 21 month draw period you will not be able to participate in any other draw.</p>
            <p>8) Each winning member will be awarded a 22 carat 2 gram gold coin after the draw.</p>
            <p>9) After the completion of the 21 month period, the remaining members will be given 5 cash reward of Rs. 22,000/- instead of the gold gift.</p>
            <p>10) I, <strong>' . htmlspecialchars($userName) . '</strong>, have read and understood the terms and conditions and I agree to them.</p>
        </div>
        
        <div class="signature-section">
            <div class="tick">✓</div>
            <div class="username">' . htmlspecialchars($userName) . '</div>
        </div>
    </div>';
    
    $pdf->addContent($content);
    $pdf->output('agreement_' . $userName . '_' . $userId . '.pdf');
    
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Database error: ' . $e->getMessage();
    exit;
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Server error occurred: ' . $e->getMessage();
    exit;
}
?>