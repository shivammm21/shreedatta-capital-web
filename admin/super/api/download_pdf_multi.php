<?php
// admin/super/api/download_pdf_multi.php
// Accepts CSV user_ids and renders a combined printable HTML with page breaks
try {
    $raw = isset($_GET['user_ids']) ? trim((string)$_GET['user_ids']) : '';
    if ($raw === '') { http_response_code(400); header('Content-Type: text/plain'); echo 'No user_ids provided'; exit; }
    $ids = array_values(array_filter(array_map(function($v){ return (int)trim($v); }, explode(',', $raw)), function($v){ return $v>0; }));
    if (!$ids) { http_response_code(400); header('Content-Type: text/plain'); echo 'Invalid user_ids'; exit; }

    require_once __DIR__ . '/../db.php';

    $stmt = $pdo->prepare("SELECT s.id, s.first_name, s.last_name, s.forms_aggri_id, s.mobileno, s.token_no, s.draw_name, s.date_time, s.language, f.form_name, f.languages, d.live_photo, d.front_addhar, d.back_addhar FROM `all-submissions` s LEFT JOIN `forms_aggri` f ON s.forms_aggri_id = f.id LEFT JOIN `user_docs` d ON s.id = d.user_id WHERE s.id = ? LIMIT 1");

    $pages = '';
    $first = true;
    foreach ($ids as $uid) {
        $stmt->execute([$uid]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) { continue; }

        $userName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        $formName = $u['form_name'] ?? 'Gold';
        $tokenNumbers = $u['token_no'] ?? '';
        $drawName = $u['draw_name'] ?? '';
        $userLanguage = $u['language'] ?? 'english';
        $languages = json_decode($u['languages'] ?? '{}', true) ?: [];
        $map = ['en'=>'english','hi'=>'hindi','mr'=>'marathi'];
        $lk = $map[$userLanguage] ?? 'english';
        $terms = $languages[$lk] ?? $languages['english'] ?? 'Terms and conditions not available';
        
        // Add section titles in different languages
        $sectionTitles = [
            'english' => "Shree Datta Capital - Terms and Conditions Agreement",
            'hindi' => "श्री दत्त कैपिटल - नियम और शर्तें समझौता",
            'marathi' => "श्री दत्त कॅपिटल - अटी व शर्ती करार"
        ];
        
        // Add different left alignment positions for each language section title
        $sectionTitlePositions = [
            'english' => 1800,  // Original value for English
            'hindi' => 5500,    // Hindi characters are typically wider and need more space
            'marathi' => 4500   // Marathi falls between English and Hindi in character density
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
        
        // Get language-specific values
        $sectionTitle = $sectionTitles[$lk] ?? $sectionTitles['english'];
        $sectionTitlePosition = $sectionTitlePositions[$lk] ?? $sectionTitlePositions['english'];
        $labels = $fieldLabels['english'];
        $termsHeading = $termsHeadings[$lk] ?? $termsHeadings['english'];
        
        // Language-specific pagination limits
        $maxTermsPerPageByLanguage = [
            'english' => 2100,  // Original value for English
            'hindi' => 5100,    // Hindi characters are typically wider and need more space
            'marathi' => 4700   // Marathi falls between English and Hindi in character density
        ];
        
        $maxTermsPerPage = $maxTermsPerPageByLanguage[$lk] ?? $maxTermsPerPageByLanguage['english'];
        $termsLength = strlen($terms);
        $needsTermsPageBreak = $termsLength > $maxTermsPerPage;
        
        // Split terms if needed for multi-page support
        $termsPages = [];
        if ($needsTermsPageBreak) {
            $termsLines = explode("\n", $terms);
            $currentPage = '';
            $currentLength = 0;
            
            foreach ($termsLines as $line) {
                $lineLength = strlen($line) + 1;
                $isNumberedItem = preg_match('/^\s*\d+\)/', $line);
                
                if ($currentLength + $lineLength > $maxTermsPerPage && $currentPage !== '') {
                    if ($isNumberedItem || $currentLength > $maxTermsPerPage * 0.8) {
                        $termsPages[] = trim($currentPage);
                        $currentPage = $line . "\n";
                        $currentLength = $lineLength;
                    } else {
                        $currentPage .= $line . "\n";
                        $currentLength += $lineLength;
                    }
                } else {
                    $currentPage .= $line . "\n";
                    $currentLength += $lineLength;
                }
            }
            
            if (trim($currentPage) !== '') {
                $termsPages[] = trim($currentPage);
            }
            
            if (empty($termsPages)) {
                $termsPages[] = $terms;
            }
        } else {
            $termsPages[] = $terms;
        }
        $rule10 = [
            'english' => " I, $userName, have read all the above terms and conditions and I agree to them.",
            'hindi' => " मैं, $userName, उपरोक्त सभी नियम और शर्तें पढ़कर उनसे सहमत हूं।",
            'marathi' => " मी, $userName, सर्व अटी व शर्ती वाचल्या आहेत व त्या मला मान्य आहेत."
        ];
        if ($termsPages[0] && $termsPages[0] !== 'Terms and conditions not available') { 
            $lastPageIndex = count($termsPages) - 1;
            $termsPages[$lastPageIndex] .= "\n" . ($rule10[$lk] ?? $rule10['english']); 
        }

        $hasPhoto = !empty($u['live_photo']);
        $photoData = $hasPhoto ? ('data:image/jpeg;base64,'.base64_encode($u['live_photo'])) : '';
        $hasFront = !empty($u['front_addhar']);
        $hasBack = !empty($u['back_addhar']);
        $frontData = $hasFront ? ('data:image/jpeg;base64,'.base64_encode($u['front_addhar'])) : '';
        $backData = $hasBack ? ('data:image/jpeg;base64,'.base64_encode($u['back_addhar'])) : '';

        $sep = $first ? '' : '<div style="page-break-before: always;"></div>';
        $first = false;

        $pages .= $sep.'
        <div class="page">
          <div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div>
          <div class="main-title">Shree Datta Capital Agreement</div>
          <div class="subtitle">'.htmlspecialchars($formName).' Agreement</div>
          <div class="divider"></div>
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
                        <td>' . htmlspecialchars((string)($u['id'] ?? '')) . '</td>
                    </tr>
                    <tr>
                        <td class="label">' . htmlspecialchars($labels['mobile']) . '</td>
                        <td>' . htmlspecialchars((string)($u['mobileno'] ?? '')) . '</td>
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
                </table>
            </div>
          <div class="image-spot">'.($photoData ? '<img src="'.$photoData.'" alt="User Photo" class="user-photo">' : 'User Photo<br>Not Available').'</div>
          <div class="terms">
            <h4>'.htmlspecialchars($termsHeading).'</h4>
            <p>'.nl2br(htmlspecialchars($termsPages[0])).'</p>
          </div>
          '.(count($termsPages) === 1 ? '<div class="signature-section"><div class="tick"></div><div class="username">'.htmlspecialchars($userName).'</div></div>' : '').'
        </div>';
        
        // Add additional terms pages if needed
        if (count($termsPages) > 1) {
            for ($i = 1; $i < count($termsPages); $i++) {
                $isLastTermsPage = ($i === count($termsPages) - 1);
                
                $pages .= '
        <div class="page">
          <div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div>
          <div class="terms" style="top: 30px; bottom: 120px; right: 30px; left: 30px; position: absolute;">
            <h4>'.htmlspecialchars($termsHeading).' (Continued)</h4>
            <p>'.nl2br(htmlspecialchars($termsPages[$i])).'</p>
          </div>
          '.($isLastTermsPage ? '<div class="signature-section"><div class="tick"></div><div class="username">'.htmlspecialchars($userName).'</div></div>' : '').'
        </div>';
            }
        }
        
        $pages .= ($hasFront ? '<div class="page"><div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div><div class="page-title">Aadhaar Card - Front</div><div class="aadhaar-container"><img class="aadhaar-image" src="'.$frontData.'"/></div></div>' : '<div class="page"><div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div><div class="page-title">Aadhaar Card - Front</div><div class="aadhaar-container"><div>Front Aadhaar Image<br>Not Available</div></div></div>').
        ($hasBack ? '<div class="page"><div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div><div class="page-title">Aadhaar Card - Back</div><div class="aadhaar-container"><img class="aadhaar-image" src="'.$backData.'"/></div></div>' : '<div class="page"><div class="watermark"><img class="watermark-img" src="../../../asset/images/Logo.png" alt="Logo"></div><div class="page-title">Aadhaar Card - Back</div><div class="aadhaar-container"><div>Back Aadhaar Image<br>Not Available</div></div></div>');
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Shree Datta Capital Agreements</title>
    <style>
      @media screen{.print-button{position:fixed;top:10px;right:10px;background:#007cba;color:#fff;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;z-index:1000;box-shadow:0 2px 5px rgba(0,0,0,.2)}.print-button:hover{background:#005a87}}
      @media print{.print-button{display:none}@page{margin:0;size:A4}.page{page-break-after:always}.page:last-child{page-break-after:auto}}
      :root{--divider-top:360px;--image-spot-top:180px;--terms-top:370px;--tick-size:40px;--username-font-size:15px;--watermark-opacity:.04;--watermark-size:1300px;--aadhaar-width:800px;--aadhaar-height:1000px;--aadhaar-top:100px;--aadhaar-left:50%;}
      body{margin:0;padding:20px;background:#f0f0f0;font-family:Arial,sans-serif}
      .page{width:210mm;height:297mm;background:#fff;margin:0 auto 20px;position:relative;box-shadow:0 0 20px rgba(0,0,0,.1);overflow:hidden;padding:20mm;box-sizing:border-box}
      .watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);opacity:var(--watermark-opacity);z-index:1;width:var(--watermark-size);height:var(--watermark-size);}
      .watermark-img{width:100%;height:100%;object-fit:contain;filter:none;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
      .main-title{position:absolute;top:50px;left:50%;transform:translateX(-50%);font-size:24px;font-weight:bold;color:#000;text-transform:uppercase;letter-spacing:2px;text-align:center;z-index:2;white-space:nowrap}
      .subtitle{position:absolute;top:90px;left:50%;transform:translateX(-50%);font-size:25px;color:#000;z-index:2}
      .divider{position:absolute;top:var(--divider-top);left:50%;transform:translateX(-50%);width:730px;height:2px;background:#000;z-index:2}
      .section-title{position:absolute;top:140px;left: ' . $sectionTitlePosition . ';transform:translateX(-50%);font-size:18px;font-weight:bold;color:#000;text-align:left;z-index:2}
      .user-info{position:absolute;top:210px;left:30px;width:500px;font-size:15px;z-index:2}
      .user-info table{width:100%;border-collapse:collapse}
      .user-info td{padding:4px 8px;vertical-align:top}
      .user-info .token-cell{max-width:350px;word-wrap:break-word;word-break:break-all}
      .image-spot{position:absolute;top:var(--image-spot-top);right:30px;width:140px;height:140px;border:2px solid #000;background:#f9f9f9;z-index:2;display:flex;align-items:center;justify-content:center;font-size:10px;color:#666;text-align:center;overflow:hidden}
      .user-photo{width:100%;height:100%;object-fit:cover}
      .terms{position:absolute;top:var(--terms-top);left:30px;right:30px;font-size:18px;line-height:1.3;z-index:2}
      .terms h4{font-size:20px;margin:0 0 10px 0;text-decoration:underline;font-weight:bold}
      .terms p{margin:5px 0;text-align:justify}
      .signature-section{position:absolute;top:990px;right:80px;left:87%;text-align:center;z-index:2}
      .tick{width:var(--tick-size);height:var(--tick-size);background-image:url("../../../asset/images/greentick.png");background-size:contain;background-repeat:no-repeat;background-position:center}
      .username{position:absolute;left:85%;transform:translateX(-50%);font-size:var(--username-font-size);font-weight:bold;color:#000;text-align:center;z-index:2;min-width:200px;letter-spacing:1px}
      .aadhaar-container{position:absolute;top:var(--aadhaar-top);left:var(--aadhaar-left);transform:translateX(-50%);width:var(--aadhaar-width);height:var(--aadhaar-height);z-index:2;display:flex;align-items:center;justify-content:center;font-size:16px;text-align:center;overflow:hidden}
      .aadhaar-image{width:100%;height:100%;object-fit:contain}
      .page-title{position:absolute;top:30px;left:50%;transform:translateX(-50%);font-size:28px;font-weight:bold;color:#000;text-align:center;z-index:2}
    </style>
    <script>function printDocument(){window.print();}</script>
    </head><body><button class="print-button" onclick="printDocument()">Print to PDF</button>'.$pages.'</body></html>';

    header('Content-Type: text/html; charset=UTF-8');
    echo $html; exit;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Server error occurred';
    exit;
}
