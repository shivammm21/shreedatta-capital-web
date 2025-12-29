<?php
// admin/super/api/category_list.php
// Returns all category names from forms_data for SUPER users
session_start();
header('Content-Type: application/json');

try {
    $u = $_SESSION['user'] ?? null;
    if (!$u || empty($u['logged_in']) || strtoupper((string)($u['type'] ?? '')) !== 'SUPER') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    require_once __DIR__ . '/../db.php'; // $pdo

    $names = [];
    try {
        $stmt = $pdo->query('SELECT `category` AS name FROM `forms_data` WHERE `category` IS NOT NULL AND TRIM(`category`) <> ""');
        while ($row = $stmt->fetch()) {
            $names[] = (string)$row['name'];
        }
    } catch (Throwable $e1) {
        try {
            $stmt2 = $pdo->query('SELECT `catogory` AS name FROM `forms_data` WHERE `catogory` IS NOT NULL AND TRIM(`catogory`) <> ""');
            while ($row2 = $stmt2->fetch()) {
                $names[] = (string)$row2['name'];
            }
        } catch (Throwable $e2) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Database error']);
            exit;
        }
    }

    // Unique, trimmed, preserve order
    $clean = [];
    $seen = [];
    foreach ($names as $n) {
        $t = trim($n);
        if ($t === '') continue;
        $k = mb_strtolower($t);
        if (!isset($seen[$k])) { $seen[$k] = true; $clean[] = $t; }
    }

    echo json_encode(['ok' => true, 'categories' => $clean]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
