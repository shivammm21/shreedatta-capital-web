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

    $items = [];
    try {
        $stmt = $pdo->query('SELECT `id`, `category` AS name FROM `forms_data` WHERE `category` IS NOT NULL AND TRIM(`category`) <> ""');
        while ($row = $stmt->fetch()) {
            // Count sub forms for this main category
            $cnt = 0;
            try {
                $cStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM `forms_aggri` WHERE `catogory_id` = ?');
                $cStmt->execute([$row['id']]);
                $cRow = $cStmt->fetch();
                if ($cRow && isset($cRow['c'])) $cnt = (int)$cRow['c'];
            } catch (Throwable $eCnt) { $cnt = 0; }
            $items[] = [ 'id' => (int)$row['id'], 'name' => (string)$row['name'], 'sub_count' => $cnt ];
        }
    } catch (Throwable $e1) {
        try {
            $stmt2 = $pdo->query('SELECT `id`, `catogory` AS name FROM `forms_data` WHERE `catogory` IS NOT NULL AND TRIM(`catogory`) <> ""');
            while ($row2 = $stmt2->fetch()) {
                $cnt = 0;
                try {
                    $cStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM `forms_aggri` WHERE `catogory_id` = ?');
                    $cStmt->execute([$row2['id']]);
                    $cRow = $cStmt->fetch();
                    if ($cRow && isset($cRow['c'])) $cnt = (int)$cRow['c'];
                } catch (Throwable $eCnt2) { $cnt = 0; }
                $items[] = [ 'id' => (int)$row2['id'], 'name' => (string)$row2['name'], 'sub_count' => $cnt ];
            }
        } catch (Throwable $e2) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Database error']);
            exit;
        }
    }

    // Unique by lowercase name, preserve order
    $clean = [];
    $seen = [];
    foreach ($items as $it) {
        $t = trim((string)($it['name'] ?? ''));
        if ($t === '') continue;
        $k = mb_strtolower($t);
        if (!isset($seen[$k])) { $seen[$k] = true; $clean[] = [ 'id' => (int)$it['id'], 'name' => $t, 'sub_count' => (int)($it['sub_count'] ?? 0) ]; }
    }

    echo json_encode(['ok' => true, 'categories' => $clean]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
