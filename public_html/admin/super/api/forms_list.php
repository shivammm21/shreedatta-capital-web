<?php
// admin/super/api/forms_list.php
// Lists all forms (forms_aggri) for a given parent category name
// GET params: category (string, required)

session_start();
header('Content-Type: application/json');

try {
    $u = $_SESSION['user'] ?? null;
    if (!$u || empty($u['logged_in']) || strtoupper((string)($u['type'] ?? '')) !== 'SUPER') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $categoryName = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
    if ($categoryName === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Category is required']);
        exit;
    }

    require_once __DIR__ . '/../db.php'; // $pdo

    // Find category id by name
    $categoryId = null;
    try {
        $q = $pdo->prepare('SELECT id FROM forms_data WHERE TRIM(LOWER(`category`)) = TRIM(LOWER(?)) LIMIT 1');
        $q->execute([$categoryName]);
        $row = $q->fetch();
        if ($row) $categoryId = (int)$row['id'];
    } catch (Throwable $e1) {
        try {
            $q2 = $pdo->prepare('SELECT id FROM forms_data WHERE TRIM(LOWER(`catogory`)) = TRIM(LOWER(?)) LIMIT 1');
            $q2->execute([$categoryName]);
            $row2 = $q2->fetch();
            if ($row2) $categoryId = (int)$row2['id'];
        } catch (Throwable $e2) {}
    }

    if ($categoryId === null) {
        echo json_encode(['ok' => true, 'forms' => []]);
        exit;
    }

    // Fetch forms
    $st = $pdo->prepare('SELECT id, catogory_id, draw_names, languages, form_name, form_link, startDate, endDate FROM forms_aggri WHERE catogory_id = ? ORDER BY id DESC');
    $st->execute([$categoryId]);
    $forms = [];
    while ($r = $st->fetch()) {
        $forms[] = [
            'id' => (int)$r['id'],
            'catogory_id' => (int)$r['catogory_id'],
            'draw_names' => json_decode($r['draw_names'] ?? '[]', true) ?: [],
            'languages' => json_decode($r['languages'] ?? '{}', true) ?: ['english'=>'','hindi'=>'','marathi'=>''],
            'form_name' => (string)($r['form_name'] ?? ''),
            'form_link' => (string)($r['form_link'] ?? ''),
            'startDate' => (string)($r['startDate'] ?? ''),
            'endDate' => (string)($r['endDate'] ?? ''),
        ];
    }

    echo json_encode(['ok' => true, 'forms' => $forms]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
