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

    require_once __DIR__ . '/../db.php'; // provides $pdo

    $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    if ($categoryId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing category_id']);
        exit;
    }

    $pdo->beginTransaction();

    // 1) Find all sub-containers (forms_aggri) under this main category
    $formIds = [];
    $stmt = $pdo->prepare('SELECT id FROM `forms_aggri` WHERE `catogory_id` = ?');
    $stmt->execute([$categoryId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $formIds[] = (int)$row['id'];
    }

    // 2) Collect all submission ids tied to those forms
    $submissionIds = [];
    if (!empty($formIds)) {
        $in = implode(',', array_fill(0, count($formIds), '?'));
        $selSubs = $pdo->prepare("SELECT `id` FROM `all-submissions` WHERE `forms_aggri_id` IN ($in)");
        $selSubs->execute($formIds);
        while ($s = $selSubs->fetch(PDO::FETCH_ASSOC)) {
            $submissionIds[] = (int)$s['id'];
        }
    }

    // 3) Delete user_docs for those submissions
    if (!empty($submissionIds)) {
        $chunks = array_chunk($submissionIds, 999);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $delDocs = $pdo->prepare("DELETE FROM `user_docs` WHERE `user_id` IN ($placeholders)");
            $delDocs->execute($chunk);
        }
    }

    // 4) Delete all-submissions rows for the forms
    if (!empty($formIds)) {
        $in = implode(',', array_fill(0, count($formIds), '?'));
        $delSubs = $pdo->prepare("DELETE FROM `all-submissions` WHERE `forms_aggri_id` IN ($in)");
        $delSubs->execute($formIds);
    }

    // 5) Delete forms_aggri rows
    if (!empty($formIds)) {
        $in = implode(',', array_fill(0, count($formIds), '?'));
        $delForms = $pdo->prepare("DELETE FROM `forms_aggri` WHERE `id` IN ($in)");
        $delForms->execute($formIds);
    }

    // 6) Finally delete the main category itself
    $delCat = $pdo->prepare('DELETE FROM `forms_data` WHERE `id` = ?');
    $delCat->execute([$categoryId]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'deleted_forms' => count($formIds),
        'deleted_submissions' => count($submissionIds)
    ]);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $_) {}
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
    exit;
}
