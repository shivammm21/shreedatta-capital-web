<?php
// admin/sub/auth.php
session_start();
header('Content-Type: application/json');

$u = isset($_SESSION['user']) ? $_SESSION['user'] : null;
$role = strtoupper((string)($u['type'] ?? ''));
if (!$u || empty($u['logged_in']) || !in_array($role, ['SUB','SUBADMIN','SUB-ADMIN'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => (int)($u['id'] ?? 0),
        'username' => (string)($u['username'] ?? ''),
        'type' => (string)($u['type'] ?? ''),
        'login_time' => (string)($u['login_time'] ?? ''),
        'ip' => (string)($u['ip'] ?? ''),
        'remember' => (bool)($u['remember'] ?? false),
    ],
]);
exit;
