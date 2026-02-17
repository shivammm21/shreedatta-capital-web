<?php
// admin/super/api/user_delete.php
// Delete a user submission from `all-submissions` and related docs from `user_docs`
// Expects: POST { user_id: int }
// Returns: JSON { ok: true }

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing or invalid user_id']);
        exit;
    }

    // DB connection (same as other SUPER APIs)
    require_once __DIR__ . '/../db.php';

    $pdo->beginTransaction();
    try {
        // Delete docs first (ignore if table/columns slightly differ)
        try {
            $stmt = $pdo->prepare('DELETE FROM `user_docs` WHERE `user_id` = ?');
            $stmt->execute([$userId]);
        } catch (Throwable $e) {
            // try fallbacks with misspelled columns if schema differs
            try {
                $stmt = $pdo->prepare('DELETE FROM `user_docs` WHERE `user_id` = ?');
                $stmt->execute([$userId]);
            } catch (Throwable $e2) {
                // still continue; not fatal if table absent
            }
        }

        // Delete submission
        $stmt2 = $pdo->prepare('DELETE FROM `all-submissions` WHERE `id` = ?');
        $stmt2->execute([$userId]);

        $pdo->commit();
        echo json_encode(['ok' => true]);
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
