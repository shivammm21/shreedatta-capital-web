<?php
// admin/super/api/category_rename.php
// Rename an existing category in `forms_data`.
// Requires SUPER session. Prevents duplicates (case-insensitive).

session_start();
header('Content-Type: application/json');

try {
    // Auth check (SUPER only)
    $u = $_SESSION['user'] ?? null;
    if (!$u || empty($u['logged_in']) || strtoupper((string)($u['type'] ?? '')) !== 'SUPER') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    $old = isset($_POST['old']) ? trim((string)$_POST['old']) : '';
    $new = isset($_POST['new']) ? trim((string)$_POST['new']) : '';

    if ($old === '' || $new === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Both old and new category names are required']);
        exit;
    }

    // DB connection
    require_once __DIR__ . '/../db.php'; // provides $pdo

    // Check duplicate for new name
    $exists = false;
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) AS c FROM `forms_data` WHERE TRIM(LOWER(`category`)) = TRIM(LOWER(?))");
        $chk->execute([$new]);
        $row = $chk->fetch();
        $exists = (int)($row['c'] ?? 0) > 0;
    } catch (Throwable $e1) {
        try {
            $chk2 = $pdo->prepare("SELECT COUNT(*) AS c FROM `forms_data` WHERE TRIM(LOWER(`catogory`)) = TRIM(LOWER(?))");
            $chk2->execute([$new]);
            $row2 = $chk2->fetch();
            $exists = (int)($row2['c'] ?? 0) > 0;
        } catch (Throwable $e2) {}
    }
    if ($exists) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Already added this category']);
        exit;
    }

    // Attempt update using preferred column, then legacy
    $affected = 0;
    try {
        $upd = $pdo->prepare("UPDATE `forms_data` SET `category` = ? WHERE TRIM(LOWER(`category`)) = TRIM(LOWER(?))");
        $upd->execute([$new, $old]);
        $affected = $upd->rowCount();
    } catch (Throwable $e3) {
        try {
            $upd2 = $pdo->prepare("UPDATE `forms_data` SET `catogory` = ? WHERE TRIM(LOWER(`catogory`)) = TRIM(LOWER(?))");
            $upd2->execute([$new, $old]);
            $affected = $upd2->rowCount();
        } catch (Throwable $e4) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Database error while renaming category']);
            exit;
        }
    }

    if ($affected === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Category not found']);
        exit;
    }

    echo json_encode(['ok' => true, 'old' => $old, 'new' => $new]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
