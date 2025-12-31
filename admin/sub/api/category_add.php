<?php
// admin/super/api/category_add.php
// Create a new category row in `forms_data` with category name and empty `forms-aggri`.
// Requires SUPER session.

session_start();
header('Content-Type: application/json');

try {
    // Auth check (SUPER only)
    $u = isset($_SESSION['user']) ? $_SESSION['user'] : null;
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

    $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Category name is required']);
        exit;
    }

    // DB
    require_once __DIR__ . '/../db.php'; // includes asset/db/config.php and provides $pdo

    // Prefer column `category`; if not present, fall back to legacy `catogory`.
    $insertedId = null;
    $formsAggriVal = '';

    // Duplicate check (case-insensitive, trimmed)
    $exists = false;
    try {
        $sqlChk = "SELECT COUNT(*) AS c FROM `forms_data` WHERE TRIM(LOWER(`category`)) = TRIM(LOWER(?))";
        $stChk = $pdo->prepare($sqlChk);
        $stChk->execute([$name]);
        $rowChk = $stChk->fetch();
        $exists = (int)($rowChk['c'] ?? 0) > 0;
    } catch (Throwable $eChk1) {
        // Fallback to legacy column name
        try {
            $sqlChk2 = "SELECT COUNT(*) AS c FROM `forms_data` WHERE TRIM(LOWER(`catogory`)) = TRIM(LOWER(?))";
            $stChk2 = $pdo->prepare($sqlChk2);
            $stChk2->execute([$name]);
            $rowChk2 = $stChk2->fetch();
            $exists = (int)($rowChk2['c'] ?? 0) > 0;
        } catch (Throwable $eChk2) {
            // if both checks fail, let insert attempt handle errors
        }
    }

    if ($exists) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Already added this category']);
        exit;
    }

    try {
        $sql = "INSERT INTO `forms_data` (`category`, `forms-aggri`) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $formsAggriVal]);
        $insertedId = (int)$pdo->lastInsertId();
    } catch (Throwable $e1) {
        // Fallback to legacy misspelled column names if needed
        try {
            $sql2 = "INSERT INTO `forms_data` (`catogory`, `forms-aggri`) VALUES (?, ?)";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute([$name, $formsAggriVal]);
            $insertedId = (int)$pdo->lastInsertId();
        } catch (Throwable $e2) {
            // Additional fallback: try inserting without forms-aggri column if it doesn't exist
            try {
                $sql3 = "INSERT INTO `forms_data` (`category`) VALUES (?)";
                $stmt3 = $pdo->prepare($sql3);
                $stmt3->execute([$name]);
                $insertedId = (int)$pdo->lastInsertId();
            } catch (Throwable $e3) {
                try {
                    $sql4 = "INSERT INTO `forms_data` (`catogory`) VALUES (?)";
                    $stmt4 = $pdo->prepare($sql4);
                    $stmt4->execute([$name]);
                    $insertedId = (int)$pdo->lastInsertId();
                } catch (Throwable $e4) {
                    http_response_code(500);
                    echo json_encode(['ok' => false, 'error' => 'Database error while adding category', 'detail' => $e1->getMessage() . ' | ' . $e2->getMessage() . ' | ' . $e3->getMessage() . ' | ' . $e4->getMessage()]);
                    exit;
                }
            }
        }
    }

    echo json_encode(['ok' => true, 'id' => $insertedId, 'name' => $name]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
