<?php
// admin/super/api/token_decode.php
// Decodes the 10-char token format generated in forms_create.php back to the numeric ID
// GET param: token

header('Content-Type: application/json');

try {
    $token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
    if ($token === '' || strlen($token) !== 10) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid token']);
        exit;
    }

    $lenChar = $token[0];
    if ($lenChar < '0' || $lenChar > '9') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid token']);
        exit;
    }
    $L = (int)$lenChar;
    if ($L <= 0 || $L > 9) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid token length']);
        exit;
    }

    $id62 = substr($token, 10 - $L, $L);
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $map = [];
    for ($i = 0; $i < strlen($alphabet); $i++) { $map[$alphabet[$i]] = $i; }

    $id = 0;
    for ($i = 0; $i < strlen($id62); $i++) {
        $ch = $id62[$i];
        if (!isset($map[$ch])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid token chars']);
            exit;
        }
        $id = $id * 62 + $map[$ch];
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
