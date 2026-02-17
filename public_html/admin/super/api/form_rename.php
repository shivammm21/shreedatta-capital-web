<?php
header('Content-Type: application/json');

// Auth: rely on existing session like other super APIs
session_start();
try {
    $u = $_SESSION['user'] ?? null;
    if (!$u || empty($u['logged_in']) || strtoupper((string)($u['type'] ?? '')) !== 'SUPER') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    require_once __DIR__ . '/../db.php'; // $pdo

    $formId = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
    $newName = isset($_POST['form_name']) ? trim((string)$_POST['form_name']) : '';

    if ($formId <= 0 || $newName === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing form_id or form_name']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE `forms_aggri` SET `form_name` = ? WHERE `id` = ?');
    $stmt->execute([$newName, $formId]);

    echo json_encode(['ok' => true]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
    exit;
}
