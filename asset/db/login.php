<?php
// asset/db/login.php
session_start();
require_once __DIR__ . '/config.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$wantsJson = false;
// Determine if the request expects JSON (AJAX)
if (!empty($_GET['ajax']) && $_GET['ajax'] == '1') { $wantsJson = true; }
if (!$wantsJson && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') { $wantsJson = true; }
if (!$wantsJson && isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) { $wantsJson = true; }

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';
$remember = !empty($_POST['remember']);

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Username and password are required.';
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
        exit;
    } else {
        header('Location: /shreedatta-capital-web/index.html?error=1');
        exit;
    }
}

try {
    // Fetch user by username
    $stmt = $pdo->prepare('SELECT id, username, password, type FROM admin WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row) {
        $_SESSION['login_error'] = 'Invalid username or password.';
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
            exit;
        } else {
            header('Location: /shreedatta-capital-web/index.html?error=1');
            exit;
        }
    }

    // NOTE: in your screenshot the password is stored as plain text (admin123)
    // For production, use password_hash/password_verify.
    $isValid = hash_equals((string)$row['password'], (string)$password);

    if (!$isValid) {
        $_SESSION['login_error'] = 'Invalid username or password.';
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
            exit;
        } else {
            header('Location: /shreedatta-capital-web/index.html?error=1');
            exit;
        }
    }

    // Authenticated
    $_SESSION['user'] = [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'type' => $row['type'],
        'logged_in' => true,
        'login_time' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'remember' => $remember,
    ];

    // Determine redirect target based on type
    $redirect = '/shreedatta-capital-web/index.html';
    $role = strtoupper((string)$row['type']);
    if ($role === 'SUPER') {
        $redirect = '/shreedatta-capital-web/admin/super/index.html';
    } else if ($role === 'SUB' || $role === 'SUBADMIN' || $role === 'SUB-ADMIN') {
        $redirect = '/shreedatta-capital-web/admin/sub/index.html';
    }
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'redirect' => $redirect]);
        exit;
    } else {
        header('Location: ' . $redirect);
        exit;
    }

} catch (Throwable $e) {
    if ($wantsJson) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Login failed']);
    } else {
        http_response_code(500);
        echo 'Login failed: ' . htmlspecialchars($e->getMessage());
    }
    exit;
}
