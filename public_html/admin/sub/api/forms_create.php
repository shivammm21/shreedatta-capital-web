<?php
// admin/super/api/forms_create.php
// Creates a new form metadata row for a category in forms_aggri.
// - Finds category by name (category/catogory) and uses its id as catogory_id
// - Stores: form_name (varchar), draw_names (JSON), languages (JSON), form_link (optional)
// - Updates forms_data.`forms-aggri` to the current count of forms under that category

session_start();
header('Content-Type: application/json');

try {
    $u = $_SESSION['user'] ?? null;
    $role = strtoupper((string)($u['type'] ?? ''));
    if (!$u || empty($u['logged_in']) || !in_array($role, ['SUB','SUPER'], true)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    // Inputs
    $categoryName = isset($_POST['category']) ? trim((string)$_POST['category']) : '';
    $formName = isset($_POST['form_name']) ? trim((string)$_POST['form_name']) : '';
    $drawNamesRaw = isset($_POST['draw_names']) ? $_POST['draw_names'] : '[]';
    $languagesRaw = isset($_POST['languages']) ? $_POST['languages'] : '{}';
    $formLink = isset($_POST['form_link']) ? trim((string)$_POST['form_link']) : '';
    $startDate = isset($_POST['startDate']) ? trim((string)$_POST['startDate']) : '';
    $endDate = isset($_POST['endDate']) ? trim((string)$_POST['endDate']) : '';

    if ($categoryName === '' || $formName === '' || $startDate === '' || $endDate === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Category, form name, start date and end date are required']);
        exit;
    }

    // Basic date validation (YYYY-MM-DD)
    $isValidDate = function($d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y,$m,$day] = array_map('intval', explode('-', $d));
        return checkdate($m,$day,$y);
    };
    if (!$isValidDate($startDate) || !$isValidDate($endDate)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD']);
        exit;
    }

    // Normalize JSON inputs
    $drawNames = [];
    if (is_array($drawNamesRaw)) { $drawNames = array_values(array_filter(array_map('trim', $drawNamesRaw), function($v){return $v!=='';})); }
    else {
        $tmp = json_decode((string)$drawNamesRaw, true);
        if (is_array($tmp)) $drawNames = array_values(array_filter(array_map('trim', $tmp), function($v){return $v!=='';}));
    }
    $languages = [ 'english' => '', 'hindi' => '', 'marathi' => '' ];
    $tmpLang = json_decode((string)$languagesRaw, true);
    if (is_array($tmpLang)) {
        $languages['english'] = (string)($tmpLang['english'] ?? '');
        $languages['hindi'] = (string)($tmpLang['hindi'] ?? '');
        $languages['marathi'] = (string)($tmpLang['marathi'] ?? '');
    }

    require_once __DIR__ . '/../db.php'; // $pdo

    // Find category id by name (case-insensitive match)
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
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Category not found']);
        exit;
    }

    // Insert into forms_aggri
    $ins = $pdo->prepare('INSERT INTO forms_aggri (`catogory_id`, `draw_names`, `languages`, `form_name`, `form_link`, `startDate`, `endDate`) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([
        $categoryId,
        json_encode(array_values($drawNames), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        json_encode($languages, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $formName,
        $formLink,
        $startDate,
        $endDate,
    ]);
    $newId = (int)$pdo->lastInsertId();

    // Generate a reversible 10-character token that contains only the ID:
    // token = [lenChar][pad...][base62(id)]
    // - lenChar encodes the length L of base62(id)
    // - pad is HMAC-derived characters to make total length 10
    // - last L chars are base62(id)
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $base62 = function(int $n) use ($alphabet): string {
        if ($n === 0) return '0';
        $s = '';
        while ($n > 0) {
            $s = $alphabet[$n % 62] . $s;
            $n = intdiv($n, 62);
        }
        return $s;
    };
    $id62 = $base62($newId);
    $L = strlen($id62); // should be <= 9 for practical ranges
    if ($L > 9) { // very large IDs fallback: keep last 9 chars
        $id62 = substr($id62, -9);
        $L = 9;
    }
    // Encode L into one visible char (0..9 -> '0'..'9', but we use '1'..'9' for 1..9; for 0 treat as '0')
    $lenChar = ($L >= 0 && $L <= 9) ? (string)$L : '9';
    // Build pad from HMAC so it looks random but is deterministic for this id
    $secret = 'shreedatta_token_secret'; // consider moving to config/env later
    $hmac = hash_hmac('sha256', $id62, $secret, true);
    $padLen = 10 - 1 - $L;
    $pad = '';
    for ($i = 0; $i < $padLen; $i++) {
        $pad .= $alphabet[ord($hmac[$i]) % 62];
    }
    $token = $lenChar . $pad . $id62;
    // Store token in form_link column
    try {
        $upTok = $pdo->prepare('UPDATE forms_aggri SET form_link = ? WHERE id = ?');
        $upTok->execute([$token, $newId]);
    } catch (Throwable $etok) {
        // If update fails, continue without blocking the creation
    }

    echo json_encode(['ok' => true, 'id' => $newId, 'catogory_id' => $categoryId, 'token' => $token]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
