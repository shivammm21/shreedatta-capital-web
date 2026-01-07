<?php
// admin/super/api/download_pdf.php
// Generates complete PDF template with all sections

try {
    // Get user ID(s) from request. If CSV is provided, redirect to multi renderer.
    $rawUserId = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '';
    if ($rawUserId !== '' && strpos($rawUserId, ',') !== false) {
        $csv = preg_replace('/[^0-9,]/', '', $rawUserId); // sanitize
        header('Location: ./download_pdf_multi.php?user_ids=' . $csv);
        exit;
    }
    $userId = ($rawUserId !== '') ? (int)$rawUserId : 0;
    
    if ($userId <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid user ID';
        exit;
    }
    
    // Include database connection
    require_once '../db.php';
    
    // Fetch complete user data including photo and terms
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.first_name,
            s.last_name,
            s.forms_aggri_id,
            s.mobileno,
            s.token_no,
            s.draw_name,
            s.date_time,
            s.language,
            f.form_name,
            f.languages,
            d.live_photo,
            d.front_addhar,
            d.back_addhar
        FROM `all-submissions` s
        LEFT JOIN `forms_aggri` f ON s.forms_aggri_id = f.id
        LEFT JOIN `user_docs` d ON s.id = d.user_id
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
    $userLanguage = $userData['language'] ?? 'english'; // User's preferred language
    // Agreement Date from all-submissions in DD-MM-YYYY
    $agreementDate = $userData['date_time'] ? date('d-m-Y', strtotime($userData['date_time'])) : date('d-m-Y');
    // Localized label for Agreement Date
    $agreementLabelByLang = [
        'english' => 'Agreement Date :',
        'hindi'   => 'समझौते की तारीख :',
        'marathi' => 'करार दिनांक :',
    ];
    // jsonLanguageKey is resolved later, so initialize a default and adjust after resolution
    $agreementLabel = $agreementLabelByLang['english'];
    $formsAggriId = $userData['forms_aggri_id'] ?? 0;
    
    // Parse languages JSON and get terms for user's language
    $languages = json_decode($userData['languages'] ?? '{}', true) ?: [];
    
    // Map language codes to JSON keys
    $languageMap = [
        'en' => 'english',
        'hi' => 'hindi', 
        'mr' => 'marathi'
    ];
    
    // Get the correct language key for JSON
    $jsonLanguageKey = $languageMap[$userLanguage] ?? 'english';
    $agreementLabel = $agreementLabelByLang[$jsonLanguageKey] ?? $agreementLabelByLang['english'];
    
    // Get terms in user's language with fallbacks
    $termsConditions = $languages[$jsonLanguageKey] ?? $languages['english'] ?? 'Terms and conditions not available';
    
    // Add static 10th rule based on language
    $staticRule10 = [
        'english' => "I, " . $userName . ", have read all the above terms and conditions and I agree to them.",
        'hindi' => "मैं, " . $userName . ",  उपरोक्त सभी नियम और शर्तें पढ़कर उनसे सहमत हूं।",
        'marathi' => "मी, " . $userName . ",सर्व अटी व शर्ती वाचल्या आहेत व त्या मला मान्य आहेत."
    ];
    
    // Add section titles in different languages
    $sectionTitles = [
        'english' => "Shree Datta Capital - Terms and Conditions Agreement",
        'hindi' => "श्री दत्त कैपिटल - नियम और शर्तें समझौता",
        'marathi' => "श्री दत्त कॅपिटल - अटी व शर्ती करार"
    ];
    
    // Add different left alignment positions for each language section title
    $sectionTitlePositions = [
        'english' => '34%',    // Original position for English
        'hindi' => '24%',      // Slightly more right for Hindi
        'marathi' => '22%'     // Between English and Hindi for Marathi
    ];
    
    // Add field labels in different languages
    $fieldLabels = [
        'english' => [
            'name' => 'Name:',
            'userid' => 'User ID:',
            'mobile' => 'Mobile No:',
            'token' => 'Token number(s):',
            'draw' => 'Draw Name:',
            'category' => 'Draw Category:'
        ],
        'hindi' => [
            'name' => 'नाम:',
            'userid' => 'यूज़र आईडी:',
            'mobile' => 'मोबाइल नंबर:',
            'token' => 'टोकन संख्या:',
            'draw' => 'ड्रॉ का नाम:',
            'category' => 'ड्रॉ श्रेणी:'
        ],
        'marathi' => [
            'name' => 'नाव:',
            'userid' => 'यूजर आयडी:',
            'mobile' => 'मोबाईल क्र.:',
            'token' => 'टोकन क्रमांक:',
            'draw' => 'ड्रॉचे नाव:',
            'category' => 'ड्रॉ श्रेणी:'
        ]
    ];
    
    // Add terms heading in different languages
    $termsHeadings = [
        'english' => 'Terms and conditions',
        'hindi' => 'नियम और शर्तें',
        'marathi' => 'अटी व शर्ती'
    ];
    
    // Add error messages in different languages
    $errorMessages = [
        'english' => 'Terms and conditions not available',
        'hindi' => 'नियम और शर्तें उपलब्ध नहीं हैं',
        'marathi' => 'अटी व शर्ती उपलब्ध नाहीत'
    ];
    
    // Get the appropriate section title, position, labels, terms heading, error message, and 10th rule
    $sectionTitle = $sectionTitles[$jsonLanguageKey] ?? $sectionTitles['english'];
    $sectionTitlePosition = $sectionTitlePositions[$jsonLanguageKey] ?? $sectionTitlePositions['english'];
    $labels = $fieldLabels[$jsonLanguageKey] ?? $fieldLabels['english'];
    $termsHeading = $termsHeadings[$jsonLanguageKey] ?? $termsHeadings['english'];
    $errorMessage = $errorMessages[$jsonLanguageKey] ?? $errorMessages['english'];
    $rule10 = $staticRule10[$jsonLanguageKey] ?? $staticRule10['english'];
    
    // Combine database terms with static 10th rule
    if ($termsConditions && $termsConditions !== 'Terms and conditions not available') {
        $termsConditions = $termsConditions . "\n" . $rule10;
    }
    
    // Handle live photo
    $hasPhoto = !empty($userData['live_photo']);
    $photoData = '';
    if ($hasPhoto) {
        // Convert binary data to base64 for display
        $photoData = 'data:image/jpeg;base64,' . base64_encode($userData['live_photo']);
    }
    
    // Handle Aadhaar images
    $hasFrontAadhaar = !empty($userData['front_addhar']);
    $hasBackAadhaar = !empty($userData['back_addhar']);
    $frontAadhaarData = '';
    $backAadhaarData = '';
    
    if ($hasFrontAadhaar) {
        $frontAadhaarData = 'data:image/jpeg;base64,' . base64_encode($userData['front_addhar']);
    }
    
    if ($hasBackAadhaar) {
        $backAadhaarData = 'data:image/jpeg;base64,' . base64_encode($userData['back_addhar']);
    }
    
    // Check if token numbers are long and need line wrapping
    $tokenLength = strlen($tokenNumbers);
    $needsWrapping = $tokenLength > 30; // Adjust this threshold as needed
    
    // Dynamic positioning based on token length
    $userInfoHeight = $needsWrapping ? 200 : 140; // Increase height if wrapping needed
    $termsTopPosition = $needsWrapping ? 420 : 370; // Push terms down if wrapping needed
    $imageSpotTop = $needsWrapping ? 220 : 180; // Move image down if wrapping needed
    
    // Check if terms are too long for one page (estimate based on character count)
    $termsLength = strlen($termsConditions);
    
    // Language-specific pagination limits
    $maxTermsPerPageByLanguage = [
        'english' => 1800,  // Original value for English
        'hindi' => 5500,    // Hindi characters are typically wider and need more space
        'marathi' => 4500   // Marathi falls between English and Hindi in character density
    ];
    
    $maxLinesPerPage = 28; // Limit for lines per page
    
    $maxTermsPerPage = $maxTermsPerPageByLanguage[$jsonLanguageKey] ?? $maxTermsPerPageByLanguage['english'];
    $needsTermsPageBreak = $termsLength > $maxTermsPerPage;
    
    // Split terms if needed
    $termsPages = [];
    if ($needsTermsPageBreak) {
        // Split terms into chunks that fit on pages, preferring breaks at numbered items
        $termsLines = explode("\n", $termsConditions);
        $currentPage = '';
        $currentLength = 0;
        
        foreach ($termsLines as $line) {
            $lineLength = strlen($line) + 1; // +1 for newline
            
            // Check if this line starts a new numbered item (like "1)", "2)", etc.)
            $isNumberedItem = preg_match('/^\s*\d+\)/', $line);
            
            // If adding this line would exceed the limit, start a new page
            // Prefer to break at numbered items for better readability
            if ($currentLength + $lineLength > $maxTermsPerPage && $currentPage !== '') {
                // If this is a numbered item and we're close to the limit, break here
                if ($isNumberedItem || $currentLength > $maxTermsPerPage * 0.8) {
                    $termsPages[] = trim($currentPage);
                    $currentPage = $line . "\n";
                    $currentLength = $lineLength;
                } else {
                    // Add this line and continue
                    $currentPage .= $line . "\n";
                    $currentLength += $lineLength;
                }
            } else {
                $currentPage .= $line . "\n";
                $currentLength += $lineLength;
            }
        }
        
        // Add the last page if there's content
        if (trim($currentPage) !== '') {
            $termsPages[] = trim($currentPage);
        }
        
        // Ensure we have at least one page
        if (empty($termsPages)) {
            $termsPages[] = $termsConditions;
        }
    } else {
        // Single page of terms
        $termsPages[] = $termsConditions;
    }
    
    // Create complete template matching the original
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . htmlspecialchars($formName . ' Agreement') . '</title>
        <style>
            :root {
                /* MANUAL POSITIONING CONTROLS */
                --title-top: 50px;
                --title-font-size: 24px;
                --title-letter-spacing: 2px;
                
                --subtitle-top: 90px;
                --subtitle-font-size: 25px;
                
                --divider-top: 360px;
                
                --section-title-top: 160px;
                --section-title-font-size: 18px;
                
                --user-info-top: 185px;
                --user-info-font-size: 15px;
                
                --image-spot-top: ' . $imageSpotTop . 'px;
                --image-spot-right: 30px;
                --image-spot-size: 140px;
                
                --terms-top: ' . $termsTopPosition . 'px;
                --terms-font-size: 18px;
                
                --signature-top: 990px;
                --tick-size: 40px;
                --username-font-size: 15px;
                
                --watermark-opacity: 0.04;
                --watermark-size: 1300px;
                
                /* AADHAAR IMAGE CONTROLS */
                --aadhaar-width: 800px;
                --aadhaar-height: 1000px;
                --aadhaar-top: 100px;
                --aadhaar-left: 50%;
              
            }
            
            @media screen {
                body {
                    margin: 0;
                    padding: 20px;
                    background: #f0f0f0;
                    font-family: Arial, sans-serif;
                }
                
                .print-button {
                    position: fixed;
                    top: 10px;
                    right: 10px;
                    background: #007cba;
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 14px;
                    z-index: 1000;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                }
                .print-button:hover {
                    background: #005a87;
                }
            }
            
            @media print {
                body {
                    margin: 0;
                    padding: 0;
                    background: white;
                }
                .print-button { display: none; }
                .page { 
                    box-shadow: none;
                    margin: 0;
                    page-break-after: always;
                }
                .page:last-child {
                    page-break-after: auto;
                }
                @page { 
                    margin: 0; 
                    size: A4;
                }
            }
            
            /* A4 Page Container */
            .page {
                width: 210mm;
                height: 297mm;
                background: white;
                margin: 0 auto 20px auto;
                position: relative;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
                overflow: hidden;
                padding: 20mm;
                box-sizing: border-box;
            }
            
            /* Logo watermark background */
            .watermark {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                opacity: var(--watermark-opacity);
                z-index: 1;
                width: var(--watermark-size);
                height: var(--watermark-size);
            }
            .watermark-img {
                width: 100%;
                height: 100%;
                object-fit: contain;
                filter: none;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Main title */
            .main-title { 
                position: absolute;
                top: var(--title-top);
                left: 50%;
                transform: translateX(-50%);
                font-size: var(--title-font-size);
                font-weight: bold;
                color: #000;
                text-transform: uppercase;
                letter-spacing: var(--title-letter-spacing);
                text-align: center;
                z-index: 2;
                white-space: nowrap;
            }
            
            /* Subtitle */
            .subtitle { 
                position: absolute;
                top: var(--subtitle-top);
                left: 50%;
                transform: translateX(-50%);
                font-size: var(--subtitle-font-size);
                font-weight: normal;
                color: #000000ff;
                text-align: center;
                z-index: 2;
                white-space: nowrap;
            }
            
            /* Center divider line */
            .divider {
                position: absolute;
                top: var(--divider-top);
                left: 50%;
                transform: translateX(-50%);
                width: 730px;
                height: 2px;
                background: #000;
                z-index: 2;
            }
            
            /* Section title hidden per requirements */
            .section-title { display: none; }
            
            /* User info table */
            .user-info {
                position: absolute;
                top: var(--user-info-top);
                left: 30px;
                width: 500px;
                font-size: var(--user-info-font-size);
                z-index: 2;
            }
            
            .user-info table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .user-info td {
                padding: 4px 8px;
                vertical-align: top;
                font-weight: bold;
            }
            
            .user-info .token-cell {
                max-width: 350px;
                word-wrap: break-word;
                word-break: break-all;
            }
            
            /* Image spot */
            .image-spot {
                position: absolute;
                top: var(--image-spot-top);
                right: var(--image-spot-right);
                width: var(--image-spot-size);
                height: var(--image-spot-size);
                border: 2px solid #000;
                background: #f9f9f9;
                z-index: 2;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 10px;
                color: #666;
                text-align: center;
                overflow: hidden;
            }
            
            .user-photo {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            
            /* Terms and conditions */
            .terms {
                position: absolute;
                top: var(--terms-top);
                left: 30px;
                right: 30px;
                font-size: var(--terms-font-size);
                line-height: 1.3;
                z-index: 2;
            }
            
            .terms h4 {
                font-size: 20px;
                margin: 0 0 10px 0;
                text-decoration: underline;
                font-weight: bold;
            }
            
            .terms p {
                margin: 5px 0;
                text-align: justify;
                /* Remove height restriction to allow full page usage */
            }
            
            /* Signature section */
            .signature-section {
                position: absolute;
                top: var(--signature-top);
                right: 80px;
                left:87%;
                text-align: center;
                z-index: 2;
            }
            
            .tick { 
                width: var(--tick-size);
                height: var(--tick-size);
                background-image: url("../../../asset/images/greentick.png");
                background-size: contain;
                background-repeat: no-repeat;
                background-position: center;
            
            }
            
            .username { 
                 position: absolute;
                top: var(--username-top);
                left: 85%;
                transform: translateX(-50%);
                font-size: var(--username-font-size);
                font-weight: bold;
                color: #000;
                text-align: center;
                z-index: 2;
                padding-top: var(--username-padding-top);
                min-width: 200px;
                letter-spacing: 1px;
            }
            
            /* Aadhaar pages */
            .aadhaar-container {
                position: absolute;
                top: var(--aadhaar-top);
                left: var(--aadhaar-left);
                transform: translateX(-50%);
                width: var(--aadhaar-width);
                height: var(--aadhaar-height);
                z-index: 2;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
                text-align: center;
                overflow: hidden;
            }
            
            .aadhaar-image {
                width: 100%;
                height: 100%;
                object-fit: contain;
            }
            
            .page-title {
                position: absolute;
                top: 30px;
                left: 50%;
                transform: translateX(-50%);
                font-size: 28px;
                font-weight: bold;
                color: #000;
                text-align: center;
                z-index: 2;
            }
        </style>
        <script>
            function printDocument() {
                window.print();
            }
        </script>
    </head>
    <body>
        <button class="print-button" onclick="printDocument()">Print to PDF</button>
        
        <!-- A4 Page Container -->
        <div class="page">
            <!-- Logo watermark -->
            <div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div>
            
            <!-- Header section -->
            <div class="main-title">Shree Datta Capital Agreement</div>
            <div class="subtitle">' . htmlspecialchars($formName) . ' Agreement</div>
            
            <!-- Center divider line -->
            <div class="divider"></div>
            
            <!-- Section title -->
            <div class="section-title">' . htmlspecialchars($sectionTitle) . '</div>
            
            <!-- User info table -->
            <div class="user-info">
                <table>
                    <tr>
                        <td class="label">' . htmlspecialchars($labels['name']) . '</td>
                        <td>' . htmlspecialchars($userName) . '</td>
                    </tr>
                    <tr>
                        <td class="label">' . htmlspecialchars($labels['userid']) . '</td>
                        <td>' . htmlspecialchars('SDC-' . str_pad((string)($userData['id'] ?? ''), 5, '0', STR_PAD_LEFT)) . '</td>
                    </tr>
                    <tr>
                        <td class="label">' . htmlspecialchars($labels['mobile']) . '</td>
                        <td>' . htmlspecialchars((string)($userData['mobileno'] ?? '')) . '</td>
                    </tr>
                    <tr>
                        <td class="label">' . htmlspecialchars($labels['token']) . '</td>
                        <td class="token-cell">' . htmlspecialchars($tokenNumbers) . '</td>
                    </tr>
                    <tr>
                        <td class="label">' . htmlspecialchars($labels['draw']) . '</td>
                        <td>' . htmlspecialchars($drawName) . '</td>
                    </tr>
                    <tr>
                        <td class="label">' . htmlspecialchars($labels['category']) . '</td>
                        <td>' . htmlspecialchars($formName) . '</td>
                    </tr>
                    <tr>
                        <td class="label"><strong>' . htmlspecialchars($agreementLabel) . '</strong></td>
                        <td>' . htmlspecialchars($agreementDate) . '</td>
                    </tr>
                </table>
            </div>
            
            <!-- Image spot -->
            <div class="image-spot">
                ' . ($hasPhoto ? 
                    '<img src="' . $photoData . '" alt="User Photo" class="user-photo">' : 
                    'User Photo<br>Not Available'
                ) . '
            </div>
            
            <!-- Terms and conditions -->
            <div class="terms">
                <h4>' . htmlspecialchars($termsHeading) . '</h4>
                <p>' . nl2br(htmlspecialchars($termsPages[0])) . '</p>
            </div>
            
            ' . (count($termsPages) === 1 ? '
            <!-- Signature section (only if this is the only terms page) -->
            <div class="signature-section">
                <div class="tick"></div>
                <div class="username">' . htmlspecialchars($userName) . '</div>
            </div>' : '') . '
        </div>';
        
        // Add additional terms pages if needed
        if (count($termsPages) > 1) {
            for ($i = 1; $i < count($termsPages); $i++) {
                $isLastTermsPage = ($i === count($termsPages) - 1);
                
                $html .= '
        
        <!-- Terms Continuation Page ' . ($i + 1) . ' -->
        <div class="page">
            <!-- Logo watermark -->
            <div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div>
            
            <!-- Terms and conditions continuation -->
            <div class="terms" style="top: 30px; bottom: 120px; right: 30px; left: 30px; position: absolute;">
                <h4>' . htmlspecialchars($termsHeading) . ' (Continued)</h4>
                <p>' . nl2br(htmlspecialchars($termsPages[$i])) . '</p>
            </div>
            
            ' . ($isLastTermsPage ? '
            <!-- Signature section (only on the last terms page) -->
            <div class="signature-section">
                <div class="tick"></div>
                <div class="username">' . htmlspecialchars($userName) . '</div>
            </div>' : '') . '
        </div>';
            }
        }
        
        $html .= '
        
        <!-- Page 2: Front Aadhaar -->
        ' . ($hasFrontAadhaar ? '
        <div class="page">
            <div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div>
            <div class="page-title">Aadhaar Card - Front</div>
            <div class="aadhaar-container">
                <img src="' . $frontAadhaarData . '" alt="Front Aadhaar" class="aadhaar-image">
            </div>
        </div>
        ' : '
        <div class="page">
            <div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div>
            <div class="page-title">Aadhaar Card - Front</div>
            <div class="aadhaar-container">
                <div>Front Aadhaar Image<br>Not Available</div>
            </div>
        </div>
        ') . '
        
        <!-- Page 3: Back Aadhaar -->
        ' . ($hasBackAadhaar ? '
        <div class="page">
            <div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div>
            <div class="page-title">Aadhaar Card - Back</div>
            <div class="aadhaar-container">
                <img src="' . $backAadhaarData . '" alt="Back Aadhaar" class="aadhaar-image">
            </div>
        </div>
        ' : '
        <div class="page">
            <div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div>
            <div class="page-title">Aadhaar Card - Back</div>
            <div class="aadhaar-container">
                <div>Back Aadhaar Image<br>Not Available</div>
            </div>
        </div>
        ') . '
    </body>
    </html>';
    
    // Output as HTML that opens in browser
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
    
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Database error: ' . $e->getMessage();
    exit;
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Server error occurred';
    exit;
}
?>