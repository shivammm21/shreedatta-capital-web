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

  // Delete from AttachDoc
  $stmt1 = $conn->prepare('DELETE FROM AttachDoc WHERE userID = ?');
  if (!$stmt1) throw new Exception('Prepare failed');
  $stmt1->bind_param('i', $id);
  if (!$stmt1->execute()) throw new Exception('Execute failed');
  $stmt1->close();

  // Delete from TokenTable
  $stmt2 = $conn->prepare('DELETE FROM TokenTable WHERE userID = ?');
  if (!$stmt2) throw new Exception('Prepare failed');
  $stmt2->bind_param('i', $id);
  if (!$stmt2->execute()) throw new Exception('Execute failed');
  $stmt2->close();

  // Delete from userTable
  $stmt3 = $conn->prepare('DELETE FROM userTable WHERE ID = ?');
  if (!$stmt3) throw new Exception('Prepare failed');
  $stmt3->bind_param('i', $id);
  if (!$stmt3->execute()) throw new Exception('Execute failed');
  $stmt3->close();

  $conn->commit();
  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Delete failed']);
}
