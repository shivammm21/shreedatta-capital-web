<?php
// test-db.php
// Quick connectivity check. Visit this file in your browser.

require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

echo json_encode([
    'status' => 'ok',
    'message' => 'Database connected successfully',
    'server_info' => $conn->server_info,
    'host_info' => $conn->host_info,
]);

?>
