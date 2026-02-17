<?php
// admin/super/api/get_user_data.php
// Debug endpoint to view user data from all three tables

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    // Get user ID from request
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        exit;
    }
    
    // Include database connection
    require_once '../db.php';
    
    // Fetch user data from all three tables with JOINs
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.forms_aggri_id,
            s.first_name,
            s.last_name,
            s.language,
            s.token_no,
            s.draw_name,
            s.date_time,
            f.form_name,
            f.draw_names,
            f.languages,
            f.form_link,
            f.catogory_id,
            d.user_id as doc_user_id,
            CASE WHEN d.live_photo IS NOT NULL THEN 'YES' ELSE 'NO' END as has_live_photo,
            CASE WHEN d.front_aadhar IS NOT NULL THEN 'YES' ELSE 'NO' END as has_front_aadhar,
            CASE WHEN d.back_aadhar IS NOT NULL THEN 'YES' ELSE 'NO' END as has_back_aadhar,
            CASE WHEN d.live_photo IS NOT NULL THEN LENGTH(d.live_photo) ELSE 0 END as live_photo_size,
            CASE WHEN d.front_aadhar IS NOT NULL THEN LENGTH(d.front_aadhar) ELSE 0 END as front_aadhar_size,
            CASE WHEN d.back_aadhar IS NOT NULL THEN LENGTH(d.back_aadhar) ELSE 0 END as back_aadhar_size
        FROM `all-submissions` s
        LEFT JOIN `forms_aggri` f ON s.forms_aggri_id = f.id
        LEFT JOIN `user_docs` d ON s.id = d.user_id
        WHERE s.id = ?
        LIMIT 1
    ");
    
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        echo json_encode(['success' => false, 'message' => 'User data not found']);
        exit;
    }
    
    // Parse JSON fields
    $drawNames = json_decode($userData['draw_names'] ?? '[]', true) ?: [];
    $languages = json_decode($userData['languages'] ?? '{}', true) ?: [];
    
    // Prepare structured response
    $response = [
        'success' => true,
        'user_id' => $userId,
        'raw_data' => $userData,
        'structured_data' => [
            'user_info' => [
                'id' => $userData['id'],
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'full_name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
                'language' => $userData['language'],
                'submission_date' => $userData['date_time'],
                'formatted_date' => $userData['date_time'] ? date('d/m/Y H:i:s', strtotime($userData['date_time'])) : ''
            ],
            'form_info' => [
                'forms_aggri_id' => $userData['forms_aggri_id'],
                'catogory_id' => $userData['catogory_id'],
                'form_name' => $userData['form_name'],
                'draw_name' => $userData['draw_name'],
                'available_draws' => $drawNames,
                'form_link' => $userData['form_link']
            ],
            'tokens' => [
                'token_string' => $userData['token_no'],
                'token_array' => explode(',', $userData['token_no'] ?? ''),
                'token_count' => count(explode(',', $userData['token_no'] ?? ''))
            ],
            'documents' => [
                'has_live_photo' => $userData['has_live_photo'],
                'has_front_aadhar' => $userData['has_front_aadhar'],
                'has_back_aadhar' => $userData['has_back_aadhar'],
                'live_photo_size' => $userData['live_photo_size'],
                'front_aadhar_size' => $userData['front_aadhar_size'],
                'back_aadhar_size' => $userData['back_aadhar_size']
            ],
            'terms_conditions' => [
                'english' => $languages['english'] ?? '',
                'hindi' => $languages['hindi'] ?? '',
                'marathi' => $languages['marathi'] ?? ''
            ]
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred',
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>