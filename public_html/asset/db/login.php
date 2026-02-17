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
        header('Location: /index.html?error=1');
        exit;
    }
}

try {
    // Fetch user by username and password (plaintext storage). If you later hash passwords,
    // change this to fetch by username only and verify with password_verify().
    $stmt = $pdo->prepare('SELECT id, username, password, type FROM admin WHERE username = ? AND password = ? LIMIT 1');
    $stmt->execute([$username, $password]);
    $row = $stmt->fetch();

    if (!$row) {
        $_SESSION['login_error'] = 'Invalid username or password.';
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
            exit;
        } else {
            header('Location: /index.html?error=1');
            exit;
        }
    }

    // Since selection is by username+password above, no additional password check needed.
    // For production, switch to hashed passwords.
    // Authenticated
    // Regenerate session ID to prevent fixation
    if (function_exists('session_regenerate_id')) {
        @session_regenerate_id(true);
    }

    // Apply "remember me" by extending the session cookie lifetime
    // Note: session cookie params must be set after session_start; we can refresh cookie here
    $cookieParams = session_get_cookie_params();
    if ($remember) {
        $expire = time() + (60 * 60 * 24 * 30); // 30 days
    } else {
        $expire = 0; // session cookie (until browser close)
    }
    // Re-set the cookie with desired expiry
    setcookie(session_name(), session_id(), [
        'expires' => $expire,
        'path' => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => (bool)($cookieParams['secure'] ?? false),
        'httponly' => (bool)($cookieParams['httponly'] ?? true),
        'samesite' => 'Lax',
    ]);
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
    $redirect = '/index.html';
    $role = strtoupper((string)$row['type']);
    if ($role === 'SUPER') {
        $redirect = '/admin/super/index.html';
    } else if ($role === 'SUB' || $role === 'SUBADMIN' || $role === 'SUB-ADMIN') {
        $redirect = '/admin/sub/index.html';
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
