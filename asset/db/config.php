<?php
// asset/db/config.php
// Database connection configuration
// Update these constants to match your local database credentials

$DB_HOST = 'localhost';
$DB_NAME = 'u497309930_shreedatta'; // change if different
$DB_USER = 'root';                  // change if you use another user
$DB_PASS = '';                      // change if your MySQL has a password
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection failed: ' . htmlspecialchars($e->getMessage());
    exit;
}
