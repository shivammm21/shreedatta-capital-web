<?php
// admin/super/api/forms_get.php
// Return a single forms_aggri record by its numeric id
// GET: id (required)

header('Content-Type: application/json');

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid id']);
        exit;
    }

    require_once __DIR__ . '/../db.php'; // $pdo

    $st = $pdo->prepare('SELECT id, catogory_id, draw_names, languages, form_name, form_link FROM forms_aggri WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'form' => [
            'id' => (int)$r['id'],
            'catogory_id' => (int)$r['catogory_id'],
            'draw_names' => json_decode($r['draw_names'] ?? '[]', true) ?: [],
            'languages' => json_decode($r['languages'] ?? '{}', true) ?: ['english'=>'','hindi'=>'','marathi'=>''],
            'form_name' => (string)($r['form_name'] ?? ''),
            'form_link' => (string)($r['form_link'] ?? ''),
        ],
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
