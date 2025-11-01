<?php
// asset/forms/api/delete_user.php
// Deletes a user and related records (AttachDoc, TokenTable) by user ID
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_once __DIR__ . '/../../../config/db.php'; // $conn (mysqli)

// Accept either JSON body { id } or form-encoded id
$raw = file_get_contents('php://input');
$input = [];
if ($raw) {
  $tmp = json_decode($raw, true);
  if (is_array($tmp)) $input = $tmp;
}
$id = isset($input['id']) ? (int)$input['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid user id']);
  exit;
}

try {
  $conn->begin_transaction();

  // First, get user details to construct PDF filename before deletion
  $stmt0 = $conn->prepare('SELECT FirstName, LastName, drawName FROM usertable WHERE ID = ?');
  if (!$stmt0) throw new Exception('Prepare failed');
  $stmt0->bind_param('i', $id);
  if (!$stmt0->execute()) throw new Exception('Execute failed');
  $result = $stmt0->get_result();
  $user = $result->fetch_assoc();
  $stmt0->close();

  // Delete from attachdoc
  $stmt1 = $conn->prepare('DELETE FROM attachdoc WHERE userID = ?');
  if (!$stmt1) throw new Exception('Prepare failed');
  $stmt1->bind_param('i', $id);
  if (!$stmt1->execute()) throw new Exception('Execute failed');
  $stmt1->close();

  // Delete from tokentable
  $stmt2 = $conn->prepare('DELETE FROM tokentable WHERE userID = ?');
  if (!$stmt2) throw new Exception('Prepare failed');
  $stmt2->bind_param('i', $id);
  if (!$stmt2->execute()) throw new Exception('Execute failed');
  $stmt2->close();

  // Delete from usertable
  $stmt3 = $conn->prepare('DELETE FROM usertable WHERE ID = ?');
  if (!$stmt3) throw new Exception('Prepare failed');
  $stmt3->bind_param('i', $id);
  if (!$stmt3->execute()) throw new Exception('Execute failed');
  $stmt3->close();

  // Delete associated PDF file if user data was found
  if ($user) {
    $firstName = trim($user['FirstName']);
    $lastName = trim($user['LastName']);
    $drawName = trim($user['drawName']);
    
    // Create safe filename using same logic as register.php
    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $firstName . '_' . $lastName);
    $safeName = preg_replace('/_{2,}/', '_', $safeName);
    $safeName = trim($safeName, '_');
    
    $safeDraw = preg_replace('/[^A-Za-z0-9_-]+/', '-', $drawName);
    $safeDraw = preg_replace('/[-_]{2,}/', '-', $safeDraw);
    $safeDraw = trim($safeDraw, '-_');
    if ($safeDraw === '') $safeDraw = 'draw';
    
    $pdfFileName = $safeName . '_' . $safeDraw . '.pdf';
    // Match register.php: store PDFs under project root 'pdffiles' (beside 'asset')
    $projectRoot = dirname(__DIR__, 3); // from asset/forms/api -> project root
    $pdfFilePath = $projectRoot . '/pdffiles/' . $pdfFileName;
    
    // Delete PDF file if it exists
    if (file_exists($pdfFilePath)) {
      @unlink($pdfFilePath);
    }
  }

  $conn->commit();
  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Delete failed']);
}
