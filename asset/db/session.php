<?php
// asset/db/session.php
// Returns current session status and suggested redirect
session_start();
header('Content-Type: application/json');

$user = isset($_SESSION['user']) ? $_SESSION['user'] : null;
if (!$user || empty($user['logged_in'])) {
    echo json_encode(['ok' => false]);
    exit;
}

$role = strtoupper((string)($user['type'] ?? ''));
$redirect = '/index.html';
if ($role === 'SUPER') {
    $redirect = '/admin/super/index.html';
} else if ($role === 'SUB' || $role === 'SUBADMIN' || $role === 'SUB-ADMIN') {
    $redirect = '/admin/sub/index.html';
}

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'type' => (string)($user['type'] ?? ''),
        'login_time' => (string)($user['login_time'] ?? ''),
        'remember' => (bool)($user['remember'] ?? false),
    ],
    'redirect' => $redirect,
]);
exit;
