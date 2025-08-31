<?php
// login.php - authenticate admin against database
// Expects POST with JSON or form fields: username, password

header('Content-Type: application/json');
$debug = isset($_GET['debug']) && ($_GET['debug'] === '1' || strtolower($_GET['debug']) === 'true');

// Minimal error handlers to convert fatals/notices to JSON in dev
if ($debug) {
    ini_set('display_errors', '0');
}
set_error_handler(function($severity, $message, $file, $line) use ($debug) {
    // Respect error_reporting level
    if (!(error_reporting() & $severity)) {
        return false;
    }
    http_response_code(500);
    error_log("PHP Error [$severity] $message in $file:$line");
    echo json_encode(['ok' => false, 'error' => 'Server error', 'debug' => $debug ? ['type' => 'php_error', 'message' => $message, 'line' => $line] : null]);
    exit;
});
set_exception_handler(function($ex) use ($debug) {
    http_response_code(500);
    error_log('Exception: ' . $ex->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error', 'debug' => $debug ? ['type' => 'exception', 'message' => $ex->getMessage()] : null]);
    exit;
});
// Allow only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed', 'debug' => $debug ? ['method' => $_SERVER['REQUEST_METHOD']] : null]);
    exit;
}

// Parse input (JSON or form-encoded)
$raw = file_get_contents('php://input');
$data = [];
if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) $data = $json;
}
if (!$data) {
    $data = $_POST ?: [];
}

$username = isset($data['username']) ? trim((string)$data['username']) : '';
$password = isset($data['password']) ? (string)$data['password'] : '';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Username and password are required', 'debug' => $debug ? ['have_username' => $username !== '', 'have_password' => $password !== ''] : null]);
    exit;
}

require_once __DIR__ . '/config/db.php'; // provides $conn (mysqli)

// NOTE: This checks plaintext passwords as requested. For production, store and verify password hashes.

// Look up user by username only
$sql = 'SELECT ID as id, username, password FROM admin WHERE username = ? LIMIT 1';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    error_log('Prepare failed: ' . $conn->error);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'debug' => $debug ? ['stage' => 'prepare_failed'] : null]);
    exit;
}
$stmt->bind_param('s', $username);
$execOk = $stmt->execute();
if ($execOk === false) {
    http_response_code(500);
    $err = $stmt->error;
    error_log('Execute failed: ' . $err);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'debug' => $debug ? ['stage' => 'execute_failed', 'stmt_error' => $err, 'sqlstate' => $stmt->sqlstate] : null]);
    $stmt->close();
    exit;
}

// Try get_result; if unavailable or returns null, use bind_result
$row = null;
if (method_exists($stmt, 'get_result')) {
    $result = $stmt->get_result();
    if ($result) {
        $row = $result->fetch_assoc();
    }
}
if ($row === null) {
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($res_id, $res_username, $res_password);
        if ($stmt->fetch()) {
            $row = [
                'id' => $res_id,
                'username' => $res_username,
                'password' => $res_password,
            ];
        }
    } else if ($stmt->errno) {
        http_response_code(500);
        error_log('Statement error: ' . $stmt->error);
        echo json_encode(['ok' => false, 'error' => 'Server error', 'debug' => $debug ? ['stage' => 'store_or_fetch_failed', 'stmt_error' => $stmt->error, 'sqlstate' => $stmt->sqlstate] : null]);
        $stmt->close();
        exit;
    }
}
$stmt->close();

if (!$row) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid username or password', 'debug' => $debug ? ['user_found' => false] : null]);
    exit;
}

// Support either plaintext or hashed passwords
$stored = trim((string)$row['password']);
$dbUsername = trim((string)$row['username']);
$ok = false;
if ($stored !== '') {
    if (password_get_info($stored)['algo']) {
        // hashed
        $ok = password_verify($password, $stored);
    } else {
        // plaintext
        $ok = hash_equals($stored, $password);
    }
}

if (!$ok) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid username or password', 'debug' => $debug ? ['user_found' => true, 'password_match' => false] : null]);
    exit;
}

// Optional: session auth flag
@session_start();
$_SESSION['auth'] = 1;
$_SESSION["admin_username"] = $dbUsername;

echo json_encode(['ok' => true, 'message' => 'Login successful', 'debug' => $debug ? ['user_found' => true, 'password_match' => true] : null]);
