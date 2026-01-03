<?php
// form/api/register.php
// Accepts public form submission and stores into:
// - all_submissions: (forms_aggri_id, first_name, last_name, token_no, draw_name)
// - user_docs: (user_id -> all_submissions.id, live_photo, front_addhar, back_addhar)
// Expects:
// POST fields: firstName, lastName, draw, lang, agreementText, photoData (data URL), tokens[] (array), token (10-char link token)
// Files: aadFront, aadBack

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    // Token -> forms_aggri_id
    $token = isset($_GET['token']) && $_GET['token'] !== '' ? (string)$_GET['token'] : (isset($_POST['token']) ? (string)$_POST['token'] : '');
    $formsAggriId = 0;
    $decodeIdFromToken = function(string $t): int {
        if (strlen($t) !== 10) return 0;
        $lenChar = $t[0];
        if ($lenChar < '0' || $lenChar > '9') return 0;
        $L = (int)$lenChar;
        if ($L < 1 || $L > 9) return 0;
        $id62 = substr($t, 10 - $L, $L);
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $map = [];
        for ($i = 0; $i < strlen($alphabet); $i++) { $map[$alphabet[$i]] = $i; }
        $id = 0;
        for ($i = 0; $i < strlen($id62); $i++) {
            $ch = $id62[$i];
            if (!isset($map[$ch])) return 0;
            $id = $id * 62 + $map[$ch];
        }
        return (int)$id;
    };
    if ($token !== '') {
        $formsAggriId = $decodeIdFromToken($token);
    }

    if ($formsAggriId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing token']);
        exit;
    }

    // Inputs
    $first = trim((string)($_POST['firstName'] ?? ''));
    $last = trim((string)($_POST['lastName'] ?? ''));
    $draw = trim((string)($_POST['draw'] ?? ''));
    $mobile = preg_replace('/\D+/', '', (string)($_POST['mobileNo'] ?? ''));
    $lang = trim((string)($_POST['lang'] ?? ''));
    $tokens = $_POST['tokens'] ?? [];
    if (!is_array($tokens)) { $tokens = []; }
    $tokenNo = implode(',', array_values(array_filter(array_map(function($v){ return trim((string)$v); }, $tokens), function($v){ return $v !== ''; })));

    // Required minimal validation
    if ($first === '' || $last === '' || $draw === '' || $tokenNo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
        exit;
    }
    // Basic mobile validation (expects 10 digits)
    if ($mobile === '' || !preg_match('/^\d{10}$/', $mobile)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid mobile number']);
        exit;
    }

    // Files/data
    $photoDataUrl = (string)($_POST['photoData'] ?? '');
    $livePhoto = '';
    if (strpos($photoDataUrl, 'data:image') === 0) {
        $parts = explode(',', $photoDataUrl, 2);
        if (count($parts) === 2) {
            $livePhoto = base64_decode(str_replace(' ', '+', $parts[1]));
        }
    }
    $front = '';
    if (isset($_FILES['aadFront']) && is_uploaded_file($_FILES['aadFront']['tmp_name'])) {
        $front = file_get_contents($_FILES['aadFront']['tmp_name']);
    }
    $back = '';
    if (isset($_FILES['aadBack']) && is_uploaded_file($_FILES['aadBack']['tmp_name'])) {
        $back = file_get_contents($_FILES['aadBack']['tmp_name']);
    }

    // DB
    require_once __DIR__ . '/../../asset/db/config.php'; // provides $pdo

    // Validate that forms_aggri_id exists
    $stmt = $pdo->prepare('SELECT id FROM forms_aggri WHERE id = ? LIMIT 1');
    $stmt->execute([$formsAggriId]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid form ID from token']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        // Look up parent category id (forms_data.id) from forms_aggri.catogory_id
        $formDataId = 0;
        try {
            $q = $pdo->prepare('SELECT `catogory_id` FROM `forms_aggri` WHERE `id` = ? LIMIT 1');
            $q->execute([$formsAggriId]);
            $row = $q->fetch();
            if ($row && isset($row['catogory_id'])) {
                $formDataId = (int)$row['catogory_id'];
            }
        } catch (Throwable $eLookup) {
            // If lookup fails, keep $formDataId as 0 and continue
        }

        // Insert into `all-submissions` (note: hyphenated table name) including `language` column
        // Prefer inserting `form_data_id` as well; fall back if column is missing
        try {
            // Preferred: includes form_data_id, mobile number and language
            $ins = $pdo->prepare('INSERT INTO `all-submissions` (`forms_aggri_id`, `form_data_id`, `first_name`, `last_name`, `mobileno`, `language`, `token_no`, `draw_name`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$formsAggriId, $formDataId, $first, $last, $mobile, $lang, $tokenNo, $draw]);
        } catch (Throwable $e1) {
            try {
                // Without form_data_id, but with mobile + language
                $ins = $pdo->prepare('INSERT INTO `all-submissions` (`forms_aggri_id`, `first_name`, `last_name`, `mobileno`, `language`, `token_no`, `draw_name`) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $ins->execute([$formsAggriId, $first, $last, $mobile, $lang, $tokenNo, $draw]);
            } catch (Throwable $e2) {
                try {
                    // Without language but with mobile
                    $ins = $pdo->prepare('INSERT INTO `all-submissions` (`forms_aggri_id`, `first_name`, `last_name`, `mobileno`, `token_no`, `draw_name`) VALUES (?, ?, ?, ?, ?, ?)');
                    $ins->execute([$formsAggriId, $first, $last, $mobile, $tokenNo, $draw]);
                } catch (Throwable $e3) {
                    // Final fallback: legacy schema without mobile or language or form_data_id
                    $ins = $pdo->prepare('INSERT INTO `all-submissions` (`forms_aggri_id`, `first_name`, `last_name`, `token_no`, `draw_name`) VALUES (?, ?, ?, ?, ?)');
                    $ins->execute([$formsAggriId, $first, $last, $tokenNo, $draw]);
                }
            }
        }
        $userId = (int)$pdo->lastInsertId();

        // Insert docs (handle possible column name typos in schema)
        try {
            $ins2 = $pdo->prepare('INSERT INTO user_docs (`user_id`, `live_photo`, `front_addhar`, `back_addhar`) VALUES (?, ?, ?, ?)');
            $ins2->bindParam(1, $userId, PDO::PARAM_INT);
            $ins2->bindParam(2, $livePhoto, PDO::PARAM_LOB);
            $ins2->bindParam(3, $front, PDO::PARAM_LOB);
            $ins2->bindParam(4, $back, PDO::PARAM_LOB);
            $ins2->execute();
        } catch (Throwable $eDocs1) {
            // Fallback to columns with double-d spelling if present
            $ins2b = $pdo->prepare('INSERT INTO user_docs (`user_id`, `live_photo`, `front_adddhar`, `back_adddhar`) VALUES (?, ?, ?, ?)');
            $ins2b->bindParam(1, $userId, PDO::PARAM_INT);
            $ins2b->bindParam(2, $livePhoto, PDO::PARAM_LOB);
            $ins2b->bindParam(3, $front, PDO::PARAM_LOB);
            $ins2b->bindParam(4, $back, PDO::PARAM_LOB);
            $ins2b->execute();
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'userID' => $userId, 'message' => 'Saved']);
    } catch (Throwable $dbE) {
        $pdo->rollBack();
        http_response_code(500);
        $detail = '';
        if (isset($_GET['debug']) || isset($_POST['debug'])) { 
            $detail = $dbE->getMessage() . ' | File: ' . $dbE->getFile() . ' | Line: ' . $dbE->getLine(); 
        }
        echo json_encode(['ok' => false, 'error' => 'Database error', 'detail' => $detail]);
        exit;
    }
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
