<?php
// admin/super/api/form_update.php
// Update a single sub-container (forms_aggri) row
// POST: form_id (int) [required]
//       form_name (string)
//       draw_names (JSON array of strings)
//       languages (JSON object with english/hindi/marathi)

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']);
        exit;
    }

    $id = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Invalid form_id']);
        exit;
    }

    $form_name = isset($_POST['form_name']) ? trim((string)$_POST['form_name']) : '';
    $draw_names_raw = isset($_POST['draw_names']) ? (string)$_POST['draw_names'] : '[]';
    $languages_raw = isset($_POST['languages']) ? (string)$_POST['languages'] : '{}';

    $draw_names = json_decode($draw_names_raw, true);
    if (!is_array($draw_names)) { $draw_names = []; }
    // sanitize draw names
    $draw_names = array_values(array_filter(array_map(function($v){ return trim((string)$v); }, $draw_names), function($v){ return $v !== ''; }));

    $languages = json_decode($languages_raw, true);
    if (!is_array($languages)) { $languages = []; }
    $languages = array_merge(['english'=>'','hindi'=>'','marathi'=>''], $languages);

    require_once __DIR__ . '/../db.php';

    $sql = 'UPDATE forms_aggri SET form_name = :name, draw_names = :draws, languages = :langs WHERE id = :id LIMIT 1';
    $st = $pdo->prepare($sql);
    $ok = $st->execute([
        ':name' => $form_name,
        ':draws' => json_encode($draw_names, JSON_UNESCAPED_UNICODE),
        ':langs' => json_encode($languages, JSON_UNESCAPED_UNICODE),
        ':id' => $id,
    ]);

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Failed to update']);
        exit;
    }

    echo json_encode(['ok'=>true]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error']);
    exit;
}
