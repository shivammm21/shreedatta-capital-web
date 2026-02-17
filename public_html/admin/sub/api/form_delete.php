<?php
header('Content-Type: application/json');

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
    if ($formId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing form_id']);
        exit;
    }

    $pdo->beginTransaction();

    // 1) Delete user_docs rows for users linked to this form (via all-submissions)
    $userIds = [];
    $sel = $pdo->prepare('SELECT `id` FROM `all-submissions` WHERE `forms_aggri_id` = ?');
    $sel->execute([$formId]);
    while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
        $userIds[] = (int)$row['id'];
    }

    if (!empty($userIds)) {
        // chunk to avoid max params
        $chunks = array_chunk($userIds, 999);
        foreach ($chunks as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $delDocs = $pdo->prepare("DELETE FROM `user_docs` WHERE `user_id` IN ($in)");
            $delDocs->execute($chunk);
        }
    }

    // 2) Delete all-submissions rows for this form
    $delSubs = $pdo->prepare('DELETE FROM `all-submissions` WHERE `forms_aggri_id` = ?');
    $delSubs->execute([$formId]);

    // 3) Delete the form itself
    $delForm = $pdo->prepare('DELETE FROM `forms_aggri` WHERE `id` = ?');
    $delForm->execute([$formId]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'deleted_users' => count($userIds)]);
    exit;
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $__) {} }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
    exit;
}
