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
  if (file_exists($tcpdfPath)) {
    $pdfDebug['tcpdf_found'] = true;
    require_once $tcpdfPath;
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

    // Create PDF (A4 portrait)
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Shree Datta Capital');
    $pdf->SetTitle(ucfirst($drawCategory) . ' Draw Agreement');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->setFontSubsetting(true); // better Unicode rendering for Hindi/Marathi
    // Hint TCPDF about language and charset
    $pdfLang = $lang === 'hi' ? 'hi' : ($lang === 'mr' ? 'mr' : 'en');
    $l = [
      'a_meta_charset' => 'UTF-8',
      'a_meta_language' => $pdfLang,
      'w_page' => 'page'
    ];
    $pdf->setLanguageArray($l);
    $pdf->AddPage();

    // Header area (single line below header only)
    // No top line – keep clean header like sample

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

    // Header with logo (left) and big red title similar to sample
    $yStart = $pdf->GetY();
    $logoPath = __DIR__ . '/../../images/Logo.png'; // asset/images/Logo.png
    $imgBottom = $yStart;
    if (file_exists($logoPath)) {
      // Draw logo with increased size (35mm height) and positioned closer to text
      try {
        $pdf->Image($logoPath, 20, $yStart, 0, 45, '', '', '', true); // Increased left margin to 40 and height to 45
        if (method_exists($pdf, 'getImageRBY')) { $imgBottom = max($imgBottom, $pdf->getImageRBY()); }
      } catch (Exception $e) {}
    }
    // Title next to logo - positioned with more left margin
    $pdf->SetXY(60, $yStart + 8);
    $pdf->SetTextColor(154, 52, 18); // dark orange-red
    $pdf->SetFont($fontFamily, 'B', 38);
    $pdf->Cell(0, 14, 'Shree Datta Capital', 0, 1, 'L');
    $pdf->SetX(60);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont($fontFamily, '', 26);
    $pdf->Cell(0, 12, ucfirst($drawCategory) . ' Draw Agreement', 0, 1, 'L');
    // Move below the tallest of logo/title and draw bottom line
    $titleBottomY = $pdf->GetY();
    $afterHeaderY = max($imgBottom, $titleBottomY) + 2;
    $pdf->SetY($afterHeaderY);
    $pdf->Line(15, $afterHeaderY, 195, $afterHeaderY); // bottom line
    $pdf->Ln(4);

    // Place live camera photo on the right of the details block (passport-style)
    // Use in-memory image data via '@' prefix supported by TCPDF
    $photoX = 150; $photoY = $pdf->GetY() + 2; $photoW = 40; $photoH = 40; // increased height to 50mm
    try { $pdf->Image('@' . $camImage, $photoX, $photoY, $photoW, $photoH, '', '', '', true); } catch (Exception $e) {}

    // Details block on left
    $pdf->SetFont($fontFamily, '', 13);
    $tokStr = implode(', ', $tokens);
    
    // Ensure proper encoding for all text fields
    $fullName = trim($first . ' ' . $last);
    if (!mb_check_encoding($fullName, 'UTF-8')) {
      $fullName = mb_convert_encoding($fullName, 'UTF-8', 'auto');
    }
    if (!mb_check_encoding($drawName, 'UTF-8')) {
      $drawName = mb_convert_encoding($drawName, 'UTF-8', 'auto');
    }
    
    $htmlDetails = ''
      . '<b>Name :</b> ' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '<br/><br/>'
      . '<b>Token number(s):</b> ' . htmlspecialchars($tokStr, ENT_QUOTES, 'UTF-8') . '<br/><br/>'
      . '<b>Draw Name:</b> ' . htmlspecialchars($drawName, ENT_QUOTES, 'UTF-8') . '<br/><br/>'
      . '<b>Draw Category:</b> ' . htmlspecialchars(ucfirst($drawCategory), ENT_QUOTES, 'UTF-8') . ' Draw<br/>';
    $pdf->writeHTMLCell(130, '', 15, $photoY, $htmlDetails, 0, 1, false, true, 'L', true);

    // Agreement text (if provided) - render exactly as provided with preserved line breaks
    if ($agreementText !== '') {
      $pdf->Ln(15); // Increased top margin to move agreement closer to green tick
      
      // Ensure proper font for the agreement text
      $pdf->SetFont($fontFamily, '', 12);
      
      // Clean and prepare the agreement text
      $agreementHtml = $agreementText;
      
      // If the agreement doesn't contain HTML tags, preserve newlines
      if (!preg_match('/<\w|<\//', $agreementHtml)) {
        $agreementHtml = nl2br($agreementHtml);
      }
      
      // Ensure proper UTF-8 encoding
      if (!mb_check_encoding($agreementHtml, 'UTF-8')) {
        $agreementHtml = mb_convert_encoding($agreementHtml, 'UTF-8', 'auto');
      }
      
      // For Hindi/Marathi, we might need to set text direction
      if ($needsDevanagari) {
        $pdf->setRTL(false); // Devanagari is LTR
      }
      
      $pdf->writeHTMLCell(0, 0, '', '', $agreementHtml, 0, 1, false, true, 'L', true);
    }

    // Footer with green tick and user name at bottom right
    $pdf->Ln(6);
    
    // Position green tick at bottom right
    $greenTickPath = __DIR__ . '/../../images/greentick.png';
    $pageHeight = $pdf->getPageHeight();
    $bottomMargin = 25; // 25mm from bottom
    $rightMargin = 25;  // 25mm from right
    $tickSize = 15;     // 15mm size for green tick
    
    $tickX = $pdf->getPageWidth() - $rightMargin - $tickSize;
    $tickY = $pageHeight - $bottomMargin - $tickSize;
    
    if (file_exists($greenTickPath)) {
      try {
        $pdf->Image($greenTickPath, $tickX, $tickY, $tickSize, $tickSize, '', '', '', true);
      } catch (Exception $e) {}
    }
    
    // User name below the green tick
    $pdf->SetXY($tickX - 10, $tickY + $tickSize + 2);
    $pdf->SetFont($fontFamily, 'B', 10);
    $pdf->SetTextColor(0, 0, 0);
    $userName = trim($first . ' ' . $last);
    
    // Ensure proper UTF-8 encoding for user name
    if (!mb_check_encoding($userName, 'UTF-8')) {
      $userName = mb_convert_encoding($userName, 'UTF-8', 'auto');
    }
    
    $pdf->Cell($tickSize + 20, 5, $userName, 0, 1, 'C');

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
    $frontY = $pdf->GetY();
    
    // Position for back image (below front image)
    $backY = $frontY + $imageHeight + $imageGap + 15; // 15mm for label space
    
    // Display Aadhaar Front image
    
    try {
      // Add image with auto-fit and maintain aspect ratio
      $pdf->Image('@' . $aadFrontData, $imageX, $frontY, $imageWidth, 0, '', '', '', true, 300, '', false, false, 0, 'CM', false, false);
    } catch (Exception $e) {
      // If image fails, show placeholder
      $pdf->Rect($imageX, $frontY, $imageWidth, $imageHeight);
      $pdf->SetXY($imageX, $frontY + ($imageHeight/2));
      $pdf->Cell($imageWidth, 8, 'Aadhaar Front Image', 0, 0, 'C');
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
