<?php
// config/db.php
// Reusable MySQL connection using mysqli
// Usage in other PHP files:
//   require_once __DIR__ . '/config/db.php';
//   if (!$conn) { die('DB connection failed'); }

$DB_HOST = 'localhost';
$DB_USER = 'u497309930_shreedatta_cap';
$DB_PASS = '#L7rPX9Tsd';
$DB_NAME = 'u497309930_shreedatta';
// Optional: change if your provider uses a non-standard port
$DB_PORT = 3306;

$DEBUG_DB = isset($_GET['debug']) && ($_GET['debug'] === '1' || strtolower($_GET['debug']) === 'true');

// Create connection with intelligent fallbacks
mysqli_report(MYSQLI_REPORT_OFF);

function try_connect($host, $user, $pass, $db, $port = 3306) {
    $m = new mysqli($host, $user, $pass, $db, $port);
    return $m;
}

$attempts = [];

// Attempt 1: given host + given pass
$conn = try_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($conn->connect_errno) {
    $attempts[] = ['host' => $DB_HOST, 'port' => $DB_PORT, 'user' => $DB_USER, 'pass_used' => ($DB_PASS !== '' ? 'set' : 'empty'), 'errno' => $conn->connect_errno, 'error' => $conn->connect_error];
    // Attempt 2: Only if host is local, try 127.0.0.1 to switch from socket to TCP
    $isLocalHost = in_array($DB_HOST, ['localhost', '127.0.0.1'], true);
    if ($isLocalHost) {
        if (@$conn->thread_id) { $conn->close(); }
        $conn = try_connect('127.0.0.1', $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    }
}

if ($conn->connect_errno) {
    // Record the second attempt only if we made it (i.e., local host case)
    if (isset($isLocalHost) && $isLocalHost) {
        $attempts[] = ['host' => '127.0.0.1', 'port' => $DB_PORT, 'user' => $DB_USER, 'pass_used' => ($DB_PASS !== '' ? 'set' : 'empty'), 'errno' => $conn->connect_errno, 'error' => $conn->connect_error];
    }
    // Attempt 3/4: if user is root, try empty password on both hosts (common XAMPP default)
    if ($DB_USER === 'root' && $DB_PASS !== '') {
        if (@$conn->thread_id) { $conn->close(); }
        $conn = try_connect($DB_HOST, $DB_USER, '', $DB_NAME, $DB_PORT);
        if ($conn->connect_errno) {
            $attempts[] = ['host' => $DB_HOST, 'port' => $DB_PORT, 'user' => $DB_USER, 'pass_used' => 'empty', 'errno' => $conn->connect_errno, 'error' => $conn->connect_error];
            if (@$conn->thread_id) { $conn->close(); }
            if (isset($isLocalHost) && $isLocalHost) {
                $conn = try_connect('127.0.0.1', $DB_USER, '', $DB_NAME, $DB_PORT);
            }
        }
    }
}

if ($conn->connect_errno) {
    http_response_code(500);
    error_log('DB connection failed after attempts: ' . json_encode($attempts));
    if ($DEBUG_DB) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Database connection error',
            'debug' => [
                'attempts' => $attempts,
                'db' => $DB_NAME,
                'host' => $DB_HOST,
                'port' => $DB_PORT,
            ],
        ]);
    } else {
        echo 'Database connection error.';
    }
    exit;
}

// Set charset
if (!$conn->set_charset('utf8mb4')) {
    error_log('Error setting charset: ' . $conn->error);
}

?>
