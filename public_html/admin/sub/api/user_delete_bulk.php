<?php
// admin/sub/api/user_delete_bulk.php
// Bulk delete user submissions from `all-submissions` and related docs from `user_docs`
// Expects: POST { user_ids: string CSV or JSON array }
// Returns: JSON { ok: true, deleted: N }

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    // Parse IDs from CSV or JSON
    $raw = $_POST['user_ids'] ?? '';
    $ids = [];
    if (is_string($raw) && $raw !== '') {
        // Try JSON first
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $ids = $decoded;
        } else {
            // CSV fallback
            $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            $ids = $parts ?: [];
        }
    } elseif (isset($_POST['user_id'])) {
        $ids = [$_POST['user_id']];
    }

    // Normalize to unique positive integers
    $ids = array_values(array_unique(array_filter(array_map(function ($v) {
        return (int)$v;
    }, $ids), function ($n) { return $n > 0; })));

    if (count($ids) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No valid user IDs provided']);
        exit;
    }

    require_once __DIR__ . '/../db.php';

    $pdo->beginTransaction();
    try {
        // Delete related docs first
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM `user_docs` WHERE `user_id` IN ($in)");
            $stmt->execute($ids);
        } catch (Throwable $eDocs) {
            // Non-fatal if table missing
        }

        // Delete submissions
        $in2 = implode(',', array_fill(0, count($ids), '?'));
        $stmt2 = $pdo->prepare("DELETE FROM `all-submissions` WHERE `id` IN ($in2)");
        $stmt2->execute($ids);

        $pdo->commit();
        echo json_encode(['ok' => true, 'deleted' => count($ids)]);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error', 'detail' => $e->getMessage()]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
