<?php
// asset/forms/api/list_users.php
// Returns all users with counts per draw category for the dashboard
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php'; // $conn (mysqli)

date_default_timezone_set('Asia/Kolkata');

try {
  $users = [];
  $sql = "SELECT ID, FirstName, LastName, drawName, drawCategory, dateAndTime FROM usertable ORDER BY ID DESC";
  if (!($res = $conn->query($sql))) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB query failed']);
    exit;
  }
  while ($row = $res->fetch_assoc()) {
    $users[] = [
      'id' => (int)$row['ID'],
      'firstName' => $row['FirstName'],
      'lastName' => $row['LastName'],
      'drawName' => $row['drawName'],
      'drawCategory' => $row['drawCategory'],
      'dateAndTime' => $row['dateAndTime'], // MySQL DATETIME string
    ];
  }
  $res->free();

  // Counts
  $counts = [ 'gold' => 0, 'cash' => 0, 'bike' => 0, 'total' => 0 ];
  foreach ($users as $u) {
    $cat = strtolower(trim($u['drawCategory']));
    if (isset($counts[$cat])) $counts[$cat]++;
    $counts['total']++;
  }

  echo json_encode(['ok' => true, 'users' => $users, 'counts' => $counts]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Server error']);
}
