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
    $safeDraw = preg_replace('/[^A-Za-z0-9_-]+/', '-', $drawName);
    if ($safeDraw === '' ) { $safeDraw = 'draw'; }
    $fileName = $userId . '_' . $safeDraw . '.pdf';
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

    // Create PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Shree Datta Capital');
    $pdf->SetTitle(ucfirst($drawCategory) . ' Draw Agreement');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    // Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Shree Datta Capital', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 14);
    $pdf->Cell(0, 8, ucfirst($drawCategory) . ' Draw Agreement', 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(4);

    // Details
    $pdf->SetFont('helvetica', '', 12);
    $tokStr = implode(', ', $tokens);
    $htmlDetails = '<b>Name:</b> ' . htmlspecialchars($first . ' ' . $last) . '<br/>' .
                   '<b>Token number(s):</b> ' . htmlspecialchars($tokStr) . '<br/>' .
                   '<b>Draw Name:</b> ' . htmlspecialchars($drawName) . '<br/>' .
                   '<b>Draw Category:</b> ' . htmlspecialchars(ucfirst($drawCategory)) . '<br/>' .
                   '<b>Submitted at:</b> ' . htmlspecialchars($now);
    $pdf->writeHTML($htmlDetails, true, false, true, false, '');

    // Agreement text (if provided)
    if ($agreementText !== '') {
      $pdf->Ln(3);
      $pdf->SetFont('helvetica', '', 11);
      $pdf->writeHTML('<b>Agreement:</b><br/>' . nl2br(htmlspecialchars($agreementText)), true, false, true, false, '');
    }

    // Footer note
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 6, 'This is a system-generated document.', 0, 1, 'C');

    // Save to filesystem
    $pdf->Output($pdfFsPath, 'F');
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
