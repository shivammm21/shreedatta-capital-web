<?php
// config/db.php
// Reusable MySQL connection using mysqli
// Usage in other PHP files:
//   require_once __DIR__ . '/config/db.php';
//   if (!$conn) { die('DB connection failed'); }

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = 'Shivam@123';
$DB_NAME = 'shreedatta';

$DEBUG_DB = isset($_GET['debug']) && ($_GET['debug'] === '1' || strtolower($_GET['debug']) === 'true');

// Create connection with intelligent fallbacks
mysqli_report(MYSQLI_REPORT_OFF);

function try_connect($host, $user, $pass, $db) {
    $m = new mysqli($host, $user, $pass, $db);
    return $m;
}

$attempts = [];

// Attempt 1: given host + given pass
$conn = try_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_errno) {
    $attempts[] = ['host' => $DB_HOST, 'user' => $DB_USER, 'pass_used' => ($DB_PASS !== '' ? 'set' : 'empty'), 'errno' => $conn->connect_errno, 'error' => $conn->connect_error];
    // Attempt 2: 127.0.0.1 + given pass (fix socket issues)
    $conn->close();
    $conn = try_connect('127.0.0.1', $DB_USER, $DB_PASS, $DB_NAME);
}

if ($conn->connect_errno) {
    $attempts[] = ['host' => '127.0.0.1', 'user' => $DB_USER, 'pass_used' => ($DB_PASS !== '' ? 'set' : 'empty'), 'errno' => $conn->connect_errno, 'error' => $conn->connect_error];
    // Attempt 3/4: if user is root, try empty password on both hosts (common XAMPP default)
    if ($DB_USER === 'root' && $DB_PASS !== '') {
        $conn->close();
        $conn = try_connect($DB_HOST, $DB_USER, '', $DB_NAME);
        if ($conn->connect_errno) {
            $attempts[] = ['host' => $DB_HOST, 'user' => $DB_USER, 'pass_used' => 'empty', 'errno' => $conn->connect_errno, 'error' => $conn->connect_error];
            $conn->close();
            $conn = try_connect('127.0.0.1', $DB_USER, '', $DB_NAME);
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
