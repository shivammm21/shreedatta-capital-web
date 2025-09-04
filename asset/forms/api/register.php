<?php
// asset/forms/api/register.php
// Registers a user from any form (bike/cash/gold) and stores attachments and tokens.
// Expects multipart/form-data with fields:
//  - firstName, lastName, draw (drawName), drawCategory (bike|cash|gold), lang
//  - agreementText (optional - full text user agreed to)
//  - photoData (data URL base64 image from camera) [required]
//  - aadFront (file), aadBack (file) [required]
//  - tokens[] (one or more token numbers)

header('Content-Type: application/json');
$debug = isset($_GET['debug']) && ($_GET['debug'] === '1' || strtolower($_GET['debug']) === 'true');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_once __DIR__ . '/../../../config/db.php'; // provides $conn (mysqli)
// Ensure correct timezone for stored timestamps
date_default_timezone_set('Asia/Kolkata');

function fail($msg, $code = 400, $extra = []) {
  http_response_code($code);
  echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
  exit;
}

// Helpers
function read_uploaded_image($file, $allowTypes = ['image/jpeg','image/png','image/webp']) {
  if (!isset($file) || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
    return [null, null, 'Upload error'];
  }
  $type = mime_content_type($file['tmp_name']);
  if (!in_array($type, $allowTypes, true)) {
    return [null, null, 'Invalid image type'];
  }
  // Size limit 5MB
  if (filesize($file['tmp_name']) > 5 * 1024 * 1024) {
    return [null, null, 'File too large'];
  }
  $data = file_get_contents($file['tmp_name']);
  return [$data, $type, null];
}

function read_data_url_image($dataUrl) {
  if (!$dataUrl || !preg_match('#^data:(image\/(?:jpeg|png|webp));base64,(.+)$#', $dataUrl, $m)) {
    return [null, null, 'Invalid photo data'];
  }
  $type = $m[1];
  $bin = base64_decode($m[2], true);
  if ($bin === false) return [null, null, 'Invalid base64'];
  // Size limit 5MB
  if (strlen($bin) > 5 * 1024 * 1024) return [null, null, 'Photo too large'];
  return [$bin, $type, null];
}

// Gather inputs
$first = trim((string)($_POST['firstName'] ?? ''));
$last  = trim((string)($_POST['lastName'] ?? ''));
$drawName = trim((string)($_POST['draw'] ?? ''));
$drawCategory = trim((string)($_POST['drawCategory'] ?? ''));
$lang = trim((string)($_POST['lang'] ?? 'en'));
$agreementText = trim((string)($_POST['agreementText'] ?? ''));
$photoData = (string)($_POST['photoData'] ?? '');
$tokens = $_POST['tokens'] ?? [];
if (!is_array($tokens)) $tokens = [$tokens];
$tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));

if ($first === '' || $last === '' || $drawName === '' || $drawCategory === '' || $photoData === '' || empty($tokens)) {
  fail('Missing required fields', 400, [
    'debug' => $debug ? [
      'first' => $first !== '', 'last' => $last !== '', 'draw' => $drawName !== '', 'cat' => $drawCategory !== '', 'hasPhoto' => $photoData !== '', 'tokensCount' => count($tokens)
    ] : null
  ]);
}

list($camImage, $camType, $errCam) = read_data_url_image($photoData);
if ($errCam) fail($errCam, 400);
list($aadFrontData, $aadFrontType, $errF) = read_uploaded_image($_FILES['aadFront'] ?? null);
if ($errF) fail('Aadhaar front: ' . $errF, 400);
list($aadBackData, $aadBackType, $errB) = read_uploaded_image($_FILES['aadBack'] ?? null);
if ($errB) fail('Aadhaar back: ' . $errB, 400);

// Insert into userTable
$now = date('Y-m-d H:i:s');
$sqlUser = 'INSERT INTO userTable (FirstName, LastName, drawName, drawCategory, language, agreement, dateAndTime) VALUES (?,?,?,?,?,?,?)';
$stmt = $conn->prepare($sqlUser);
if (!$stmt) fail('DB prepare failed (user)', 500);
$stmt->bind_param('sssssss', $first, $last, $drawName, $drawCategory, $lang, $agreementText, $now);
if (!$stmt->execute()) fail('DB execute failed (user)', 500, ['debug' => $debug ? ['err' => $stmt->error, 'sqlstate' => $stmt->sqlstate] : null]);
$userId = $stmt->insert_id;
$stmt->close();

// Insert attachments (photo, aadFront, aadBack) into AttachDoc
$sqlDoc = 'INSERT INTO AttachDoc (image, imageType, userID) VALUES (?,?,?)';
$stmtDoc = $conn->prepare($sqlDoc);
if (!$stmtDoc) fail('DB prepare failed (doc)', 500);
$stmtDoc->bind_param('bsi', $null, $type, $uid);

// For each image, send using send_long_data
$docs = [
  [$camImage, $camType],
  [$aadFrontData, $aadFrontType],
  [$aadBackData, $aadBackType],
];
foreach ($docs as [$blob, $typeVal]) {
  $null = null; // placeholder for blob
  $type = $typeVal;
  $uid = $userId;
  // bind again for safety (mysqli requires bind before send_long_data)
  $stmtDoc->bind_param('bsi', $null, $type, $uid);
  $stmtDoc->send_long_data(0, $blob);
  if (!$stmtDoc->execute()) {
    $stmtDoc->close();
    fail('DB execute failed (doc)', 500, ['debug' => $debug ? ['err' => $conn->error] : null]);
  }
}
$stmtDoc->close();

// Insert tokens
$sqlTok = 'INSERT INTO TokenTable (tokenNumber, userID) VALUES (?,?)';
$stmtTok = $conn->prepare($sqlTok);
if (!$stmtTok) fail('DB prepare failed (token)', 500);
foreach ($tokens as $tok) {
  $stmtTok->bind_param('si', $tok, $userId);
  if (!$stmtTok->execute()) {
    $stmtTok->close();
    fail('DB execute failed (token)', 500);
  }
}
$stmtTok->close();

// Done
// Attempt to generate PDF using TCPDF
$pdfUrl = null;
$pdfDebug = [
  'tcpdf_found' => false,
  'pdf_dir' => null,
  'file_name' => null,
  'fs_path' => null,
  'web_path' => null,
  'saved' => false,
];
try {
  $tcpdfPath = __DIR__ . '/tcpdf/tcpdf.php';
  $fpdiPath = __DIR__ . '/FPDI-2.3.7/src/autoload.php';
  
  if (file_exists($tcpdfPath)) {
    $pdfDebug['tcpdf_found'] = true;
    require_once $tcpdfPath;
    
    // Load FPDI for PDF template support
    if (file_exists($fpdiPath)) {
      require_once $fpdiPath;
      $pdfDebug['fpdi_found'] = true;
    } else {
      $pdfDebug['fpdi_found'] = false;
      $pdfDebug['fpdi_error'] = 'FPDI not found at: ' . $fpdiPath;
    }
    // Ensure pdf directory exists
    $pdfDir = __DIR__ . '/../pdfFiles'; // -> asset/forms/pdfFiles
    if (!is_dir($pdfDir)) {
      $mk = @mkdir($pdfDir, 0775, true);
      if (!$mk) { $pdfDebug['mkdir_error'] = 'Failed to create directory'; }
    }
    $pdfDebug['dir_exists'] = is_dir($pdfDir);
    $pdfDebug['dir_writable'] = is_writable($pdfDir);
    if (!$pdfDebug['dir_writable']) { @chmod($pdfDir, 0775); $pdfDebug['dir_writable_after_chmod'] = is_writable($pdfDir); }
    // Sanitize draw name for filename: trim, replace invalid with hyphen, collapse and trim separators
    $safeDraw = trim((string)$drawName);
    $safeDraw = preg_replace('/[^A-Za-z0-9_-]+/', '-', $safeDraw);
    $safeDraw = preg_replace('/[-_]{2,}/', '-', $safeDraw); // collapse repeats
    $safeDraw = trim($safeDraw, '-_');
    if ($safeDraw === '') { $safeDraw = 'draw'; }
    // Create safe filename: firstName_lastName_drawName.pdf
    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $first . '_' . $last);
    $safeName = preg_replace('/_{2,}/', '_', $safeName);
    $safeName = trim($safeName, '_');
    $fileName = $safeName . '_' . $safeDraw . '.pdf';
    $pdfFsPath = $pdfDir . '/' . $fileName;
    $pdfDebug['pdf_dir'] = $pdfDir;
    $pdfDebug['file_name'] = $fileName;
    $pdfDebug['fs_path'] = $pdfFsPath;

    // Build absolute URL for response (derive correct web base path)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDirWeb = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/'); // e.g. /shreedatta-capital-web/asset/forms/api
    $formsDirWeb = rtrim(dirname($scriptDirWeb), '/'); // -> /shreedatta-capital-web/asset/forms
    $webPath = $formsDirWeb . '/pdfFiles/' . $fileName; // -> /shreedatta-capital-web/asset/forms/pdfFiles/<file>
    $pdfUrl = $scheme . '://' . $host . $webPath;
    $pdfDebug['web_path'] = $webPath;

    // Select template based on language and category (gold uses "<lang> gold.pdf", others use normal)
    $imagesDir = __DIR__ . '/../../images/';
    $langKey = ($lang === 'hi') ? 'hindi' : (($lang === 'mr') ? 'marathi' : 'english');
    $isGold = (strtolower((string)$drawCategory) === 'gold');
    $templateBaseName = $langKey . ($isGold ? ' gold' : '') . '.pdf';
    $templateFile = $imagesDir . $templateBaseName;
    
    // Check if template exists and FPDI is available - REQUIRED for template-only approach
    if (!isset($pdfDebug['fpdi_found']) || !$pdfDebug['fpdi_found']) {
      throw new Exception('FPDI library not found - required for template-based PDF generation');
    }
    if (!file_exists($templateFile)) {
      throw new Exception('Template file not found: ' . $templateFile);
    }
    
    $pdfDebug['template_used'] = basename($templateFile);

    // Create PDF using ONLY template-based approach with FPDI + TCPDF
    $pdf = new setasign\Fpdi\Tcpdf\Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Shree Datta Capital');
    //$pdf->SetTitle(ucfirst($drawCategory) . ' Draw Agreement');
    $pdf->setFontSubsetting(true);
    
    // Set language
    $pdfLang = $lang === 'hi' ? 'hi' : ($lang === 'mr' ? 'mr' : 'en');
    $l = [
      'a_meta_charset' => 'UTF-8',
      'a_meta_language' => $pdfLang,
      'w_page' => 'page'
    ];
    $pdf->setLanguageArray($l);
    
    // Import template
    $pageCount = $pdf->setSourceFile($templateFile);
    $templateId = $pdf->importPage(1);
    $pdf->AddPage();
    $pdf->useTemplate($templateId);
    $pdfDebug['template_import_success'] = true;
    
    // Disable margins and auto page break for precise positioning
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);

    // Resolve a font that supports Devanagari (Hindi/Marathi)
    $fontFamily = 'dejavusans'; // Default to DejaVu Sans which has better Unicode support
    
    // Check if we need Devanagari support based on language
    $needsDevanagari = ($lang === 'hi' || $lang === 'mr');
    
    if ($needsDevanagari) {
      // Try to use a font with Devanagari support
      // First try freeserif which should support Devanagari
      if (file_exists(K_PATH_FONTS . 'freeserif.php') || file_exists(K_PATH_FONTS . 'freeserif.z')) {
        $fontFamily = 'freeserif';
      } else {
        // Try to auto-register a bundled custom font if present
        $customRegular = __DIR__ . '/fonts/NotoSansDevanagari-Regular.ttf';
        if (file_exists($customRegular)) {
          $fontFamily = TCPDF_FONTS::addTTFfont($customRegular, 'TrueTypeUnicode', '', 96);
          // Optionally register bold/italic if available
          $customBold = __DIR__ . '/fonts/NotoSansDevanagari-Bold.ttf';
          if (file_exists($customBold)) { TCPDF_FONTS::addTTFfont($customBold, 'TrueTypeUnicode', '', 96); }
          $customItalic = __DIR__ . '/fonts/NotoSansDevanagari-Italic.ttf';
          if (file_exists($customItalic)) { TCPDF_FONTS::addTTFfont($customItalic, 'TrueTypeUnicode', '', 96); }
        } else {
          // Fallback to dejavusans which has some Devanagari support
          $fontFamily = 'dejavusans';
        }
      }
    }

    // Prepare user data
    $tokStr = implode(', ', $tokens);
    $fullName = trim($first . ' ' . $last);
    
    // Ensure proper UTF-8 encoding
    if (!mb_check_encoding($fullName, 'UTF-8')) {
      $fullName = mb_convert_encoding($fullName, 'UTF-8', 'auto');
    }
    if (!mb_check_encoding($drawName, 'UTF-8')) {
      $drawName = mb_convert_encoding($drawName, 'UTF-8', 'auto');
    }

    // Template-based approach: ONLY add user-specific data to existing template
    // The template already contains: header, logo, labels, terms & conditions
    
    // Set font for user data
    $pdf->SetFont($fontFamily, '', 12);
    $pdf->SetTextColor(0, 0, 0);
    
    // 1. Add document title (if not in template) - positioned below the main header
    $pdf->SetXY(73, 18); // Position for document title
    $pdf->SetTextColor(0, 0, 0);
    // Use a constant Latin font for the English title across all templates
    $prevFontFamily = $fontFamily; // remember current font for body text
    $pdf->SetFont('helvetica', 'B', 16);
    $documentTitle = ucfirst($drawCategory) . ' Draw Agreement';
    $pdf->Cell(0, 12, $documentTitle, 0, 1, 'L');
    // Restore previous font for the rest of the content
    $pdf->SetFont($prevFontFamily, '', 12);
    
    // 2. Create user details section with DYNAMIC labels and positioning (NON-TEMPLATE)
    // Set transparency (0 = fully transparent, 1 = fully opaque)
$pdf->SetAlpha(0); // completely transparent

// Now draw with "fill" turned on
$pdf->SetFillColor(255, 255, 255); // White
$pdf->Rect(5, 53, 130, 60, 'F');
$pdf->SetAlpha(1);
// Reset alpha back to normal for next content
    // Cover the template's existing form fields area with white rectangle (but preserve photo area)
     // Cover existing form area in template (width reduced to 130 to avoid photo area)
    
    // 3. Add user photo (positioned in the blank space on right side) - AFTER covering template
    $photoX = 160; // Adjust based on template blank space
    $photoY = 53;  // Adjust based on template blank space
    $photoW = 35;  // Photo width
    $photoH = 35;  // Photo height
    
    try { 
      $pdf->Image('@' . $camImage, $photoX, $photoY, $photoW, $photoH, '', '', '', true); 
    } catch (Exception $e) {
      $pdfDebug['photo_error'] = $e->getMessage();
    }
    
    // Starting positions for the form fields
    $currentY = 53; // Starting Y position for first field
    $lineHeight = 7; // Height between lines
    $maxWidth = 110; // Maximum width before wrapping (leave space for photo)
    $labelX = 15; // X position for labels
    $valueX = 65; // X position for values (after labels)
    
    // Set fonts
    $labelFont = $fontFamily;
    $valueFont = $fontFamily;
    
    // 1. NAME FIELD with label
    $pdf->SetFont($labelFont, 'B', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($labelX, $currentY);
    $pdf->Cell(0, 6, 'Name :', 0, 0, 'L');
    
    // Name value
    $pdf->SetFont($valueFont, '', 12);
    $pdf->SetXY($valueX, $currentY);
    $nameWidth = $pdf->GetStringWidth($fullName);
    
    if ($nameWidth > $maxWidth) {
      // Name is too long, use multi-line
      $pdf->writeHTMLCell($maxWidth, 0, $valueX, $currentY, $fullName, 0, 1, false, true, 'L', true);
      $currentY += ceil($nameWidth / $maxWidth) * 8; // Adjust Y for next field
    } else {
      // Name fits on one line
      $pdf->Cell(0, 6, $fullName, 0, 0, 'L');
      $currentY += $lineHeight;
    }
    
    // 2. TOKEN NUMBERS FIELD with label (most critical for dynamic sizing)
    $pdf->SetFont($labelFont, 'B', 12);
    $pdf->SetXY($labelX, $currentY);
    $pdf->Cell(0, 6, 'Token number(s):', 0, 0, 'L');
    
    // Token values with optimized multi-line layout
    $pdf->SetFont($valueFont, '', 12);
    
    // Calculate token layout parameters
    $tokenWidth = 9; // Approximate width per 2-digit token (without comma/space)
    $separatorWidth = 1; // Width for comma
    $totalTokenWidth = $tokenWidth + $separatorWidth; // ~10mm per token including separator
    
    // First row: starts after label (X=70), can fit tokens until maxWidth (120mm from X=70)
    $firstRowStartX = $valueX; // X=70
    $firstRowMaxWidth = $maxWidth; // 120mm available width
    $firstRowTokens = floor($firstRowMaxWidth / $totalTokenWidth); // ~12-13 tokens
    
    // Subsequent rows: start from label position (X=20), extend to where first row ended
    $subsequentRowStartX = $labelX; // X=20 (same as label)
    $subsequentRowMaxWidth = $firstRowMaxWidth + ($valueX - $labelX); // 120 + (70-20) = 170mm
    $subsequentRowTokens = floor($subsequentRowMaxWidth / $totalTokenWidth); // ~17 tokens
    
    if (count($tokens) <= $firstRowTokens) {
      // All tokens fit on first row
      $pdf->SetXY($firstRowStartX, $currentY);
      $pdf->Cell(0, 6, $tokStr, 0, 0, 'L');
      $currentY += $lineHeight;
    } else {
      // Multi-line approach needed
      $tokenChunks = [];
      $remainingTokens = $tokens;
      
      // First chunk: up to 13 tokens for first row
      $firstChunk = array_splice($remainingTokens, 0, $firstRowTokens);
      $tokenChunks[] = ['tokens' => $firstChunk, 'startX' => $firstRowStartX];
      
      // Subsequent chunks: up to 17 tokens per row, starting from X=20
      while (!empty($remainingTokens)) {
        $chunk = array_splice($remainingTokens, 0, $subsequentRowTokens);
        $tokenChunks[] = ['tokens' => $chunk, 'startX' => $subsequentRowStartX];
      }
      
      // Render each chunk
      foreach ($tokenChunks as $chunkIndex => $chunkData) {
        $chunkStr = implode(', ', $chunkData['tokens']);
        $pdf->SetXY($chunkData['startX'], $currentY + ($chunkIndex * 7));
        $pdf->Cell(0, 6, $chunkStr, 0, 0, 'L');
      }
      
      // Adjust Y position for next field
      $currentY += (count($tokenChunks) * 7);
    }
    
    // 3. DRAW NAME FIELD with label
    $pdf->SetFont($labelFont, 'B', 12);
    $pdf->SetXY($labelX, $currentY);
    $pdf->Cell(0, 6, 'Draw Name:', 0, 0, 'L');
    
    // Draw name value
    $pdf->SetFont($valueFont, '', 12);
    $pdf->SetXY($valueX, $currentY);
    $drawNameWidth = $pdf->GetStringWidth($drawName);
    
    if ($drawNameWidth > $maxWidth) {
      // Draw name is too long, use multi-line
      $pdf->writeHTMLCell($maxWidth, 0, $valueX, $currentY, $drawName, 0, 1, false, true, 'L', true);
      $currentY += ceil($drawNameWidth / $maxWidth) * 8;
    } else {
      // Draw name fits on one line
      $pdf->Cell(0, 6, $drawName, 0, 0, 'L');
      $currentY += $lineHeight;
    }
    
    // 4. DRAW CATEGORY FIELD with label
    $drawCategoryText = ucfirst($drawCategory) . ' Draw';
    $pdf->SetFont($labelFont, 'B', 12);
    $pdf->SetXY($labelX, $currentY);
    $pdf->Cell(0, 6, 'Draw Category:', 0, 0, 'L');
    
    // Draw category value
    $pdf->SetFont($valueFont, '', 12);
    $pdf->SetXY($valueX, $currentY);
    $categoryWidth = $pdf->GetStringWidth($drawCategoryText);
    
    if ($categoryWidth > $maxWidth) {
      // Category is too long, use multi-line
      $pdf->writeHTMLCell($maxWidth, 0, $valueX, $currentY, $drawCategoryText, 0, 1, false, true, 'L', true);
      $currentY += ceil($categoryWidth / $maxWidth) * 8;
    } else {
      // Category fits on one line
      $pdf->Cell(0, 6, $drawCategoryText, 0, 0, 'L');
      $currentY += $lineHeight;
    }
   
    // Store the final Y position for potential use in agreement positioning
    $finalFieldY = $currentY + 10; // Add some padding after fields
    
    // 4. Add user name in the agreement underscore area (point 8) with DYNAMIC positioning
    $pdf->SetFont($fontFamily, 'B', 12);
    
    // Calculate dynamic agreement position based on where fields ended
    // Allow per-language control of the minimum Y while keeping English at 233
    $agreementYOverrides = [
      'en' => 233, // keep as-is for English templates
      'hi' => 214.5, // adjust this value for Hindi templates if needed
      'mr' => 221, // adjust this value for Marathi templates if needed
    ];
    $langKeyShort = ($lang === 'hi') ? 'hi' : (($lang === 'mr') ? 'mr' : 'en');
    $minAgreementY = (int)($agreementYOverrides[$langKeyShort] ?? 233); // Minimum Y position for agreement (from template)
    $dynamicAgreementY = max($minAgreementY, $finalFieldY + 20); // At least 20mm below last field
    
    // Position where the underscore appears in the agreement text
    $agreementNameX = 26; // X position of underscore in template
    $agreementNameY = $dynamicAgreementY; // Dynamic Y position based on content
    
    $pdf->SetXY($agreementNameX, $agreementNameY);
    
    // Add the name with proper comma formatting
    // Template should read: "8) I, [NAME], have read all the above terms..."
    $nameWithComma = $fullName . ',';
    $nameWidth = $pdf->GetStringWidth($nameWithComma);
    
    // Draw the name with comma to ensure proper punctuation
    $pdf->Cell($nameWidth, 6, $nameWithComma, 0, 0, 'L');
    
    // Store debug info about dynamic positioning
    $pdfDebug['dynamic_positioning'] = [
      'final_field_y' => $finalFieldY,
      'min_agreement_y' => $minAgreementY,
      'actual_agreement_y' => $dynamicAgreementY,
      'token_count' => count($tokens),
      'tokens_width' => $pdf->GetStringWidth($tokStr)
    ];
    
    // 5. Add green tick and user name at bottom right (if not in template)
    $greenTickPath = __DIR__ . '/../../images/greentick.png';
    $pageHeight = $pdf->getPageHeight();
    $bottomMargin = 25;
    $rightMargin = 25;
    $tickSize = 15;
    
    $tickX = $pdf->getPageWidth() - $rightMargin - $tickSize;
    $tickY = $pageHeight - $bottomMargin - $tickSize;
    
    // Add green tick
    if (file_exists($greenTickPath)) {
      try {
        $pdf->Image($greenTickPath, $tickX, $tickY, $tickSize, $tickSize, '', '', '', true);
      } catch (Exception $e) {
        $pdfDebug['tick_error'] = $e->getMessage();
      }
    }
    
    // Add user name below green tick
    $pdf->SetXY($tickX - 10, $tickY + $tickSize + 2);
    $pdf->SetFont($fontFamily, 'B', 10);
    $pdf->SetTextColor(0, 0, 0);
    $userName = trim('Name: '.$first . ' ' . $last);
    
    // Ensure proper UTF-8 encoding for user name
    if (!mb_check_encoding($userName, 'UTF-8')) {
      $userName = mb_convert_encoding($userName, 'UTF-8', 'auto');
    }
    
    $pdf->Cell(40, 5, $userName, 0, 1, 'C');



    // Add second page for Aadhaar images
    $pdf->AddPage();
    
    // Calculate image dimensions for Aadhaar cards (standard ratio is 1:0.63 width:height)
    $pageWidth = $pdf->getPageWidth();
    $pageHeight = $pdf->getPageHeight();
    $margin = 20; // Increased margin for better spacing
    $availableWidth = $pageWidth - (2 * $margin);
    
    // Calculate maximum width and height for Aadhaar cards
    $maxWidth = $availableWidth * 0.9; // 90% of available width
    $maxHeight = ($pageHeight / 2) - 40; // Half page height minus some space for headers/footers
    
    // Standard Aadhaar card ratio (1:0.63)
    $aadhaarRatio = 0.63; // height/width ratio
    
    // Calculate dimensions that fit within maxWidth and maxHeight while maintaining aspect ratio
    $imageWidth = min($maxWidth, $maxHeight / $aadhaarRatio);
    $imageHeight = $imageWidth * $aadhaarRatio;
    
    // Center the images horizontally
    $imageX = ($pageWidth - $imageWidth) / 2;
    $imageGap = 20; // Gap between front and back images
    
    // Center the images horizontally
    $imageX = ($pageWidth - $imageWidth) / 2;
    
    // Position for front image (top)
    $frontY = $pdf->GetY()+20;
    
    // Display Aadhaar Front image
    try {
      // Add image with auto-fit and maintain aspect ratio
      $pdf->Image('@' . $aadFrontData, $imageX, $frontY, $imageWidth, 0, '', '', '', true, 300, '', false, false, 0, 'CM', false, false);
      // Get actual height of the rendered front image
      $actualFrontHeight = $pdf->getImageRBY() - $frontY;
    } catch (Exception $e) {
      // If image fails, show placeholder
      $pdf->Rect($imageX, $frontY, $imageWidth, $imageHeight);
      $pdf->SetXY($imageX, $frontY + ($imageHeight/2));
      $pdf->Cell($imageWidth, 8, 'Aadhaar Front Image', 0, 0, 'C');
      $actualFrontHeight = $imageHeight;
    }
    
    // Calculate position for back image
    $backY = $frontY + $actualFrontHeight + $imageGap + 20;
    
    // Check if back image will fit on current page
    $remainingPageHeight = $pageHeight - $backY - $margin;
    $estimatedBackHeight = $imageHeight; // Use estimated height for safety
    
    // If back image won't fit, move to next page
    if ($remainingPageHeight < $estimatedBackHeight) {
      $pdf->AddPage();
      $backY = $pdf->GetY() + 20; // Start from top of new page with margin
    }
    
    // Display Aadhaar Back image
    try {
      // Add image with auto-fit and maintain aspect ratio
      $pdf->Image('@' . $aadBackData, $imageX, $backY, $imageWidth, 0, '', '', '', true, 300, '', false, false, 0, 'CM', false, false);
    } catch (Exception $e) {
      // If image fails, show placeholder
      $pdf->Rect($imageX, $backY, $imageWidth, $imageHeight);
      $pdf->SetXY($imageX, $backY + ($imageHeight/2));
      $pdf->Cell($imageWidth, 8, 'Aadhaar Back Image', 0, 0, 'C');
    }

    // Save to filesystem (suppress TCPDF direct output to avoid corrupting JSON)
    ob_start();
    $pdf->Output($pdfFsPath, 'F');
    $tcpdfOut = ob_get_clean();
    if (!empty($tcpdfOut)) { $pdfDebug['tcpdf_output'] = trim($tcpdfOut); }
    if (file_exists($pdfFsPath)) { $pdfDebug['saved'] = true; }
  }
} catch (Throwable $e) {
  // If anything fails, continue without PDF
  $pdfUrl = null;
}

http_response_code(201);
echo json_encode([
  'ok' => true,
  'userID' => $userId,
  'message' => 'Registration saved',
  'pdfUrl' => $pdfUrl,
  'debug' => $debug ? ['pdf' => $pdfDebug] : null,
]);
