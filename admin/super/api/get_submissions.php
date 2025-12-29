<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Include database connection
require_once '../../../asset/db/config.php';

try {
    // Get category parameter from URL
    $categoryName = isset($_GET['category']) ? trim($_GET['category']) : '';
    
    // Check if tables exist
    $submissionsTableCheck = $pdo->query("SHOW TABLES LIKE 'all-submissions'");
    $formsTableCheck = $pdo->query("SHOW TABLES LIKE 'forms_aggri'");
    
    if ($submissionsTableCheck->rowCount() == 0) {
        echo json_encode([
            'success' => true,
            'submissions' => [],
            'count' => 0,
            'message' => 'No submissions table found'
        ]);
        exit;
    }
    
    if ($formsTableCheck->rowCount() == 0) {
        echo json_encode([
            'success' => true,
            'submissions' => [],
            'count' => 0,
            'message' => 'No forms_aggri table found'
        ]);
        exit;
    }
    
    // Build the query based on whether category is specified
    if (!empty($categoryName)) {
        // Filter submissions by category - get submissions for forms that start with the category name
        $stmt = $pdo->prepare("
            SELECT 
                s.*,
                f.form_name as category_name
            FROM `all-submissions` s
            LEFT JOIN `forms_aggri` f ON s.forms_aggri_id = f.id
            WHERE LOWER(f.form_name) LIKE LOWER(CONCAT(?, '%'))
            ORDER BY s.date_time DESC
        ");
        $stmt->execute([$categoryName]);
    } else {
        // Get all submissions if no category specified
        $stmt = $pdo->prepare("
            SELECT 
                s.*,
                f.form_name as category_name
            FROM `all-submissions` s
            LEFT JOIN `forms_aggri` f ON s.forms_aggri_id = f.id
            ORDER BY s.date_time DESC
        ");
        $stmt->execute();
    }
    
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle null or empty results
    if (!$submissions) {
        $submissions = [];
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'submissions' => $submissions,
        'count' => count($submissions),
        'category' => $categoryName,
        'message' => count($submissions) > 0 ? 'Submissions loaded successfully' : 'No submissions found for this category'
    ]);
    
} catch (PDOException $e) {
    // Return error response for database issues
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'submissions' => [],
        'count' => 0,
        'message' => 'Database connection error. Please check your database configuration.',
        'error' => $e->getMessage() // Add error details for debugging
    ]);
} catch (Exception $e) {
    // Return general error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'submissions' => [],
        'count' => 0,
        'message' => 'Server error occurred while fetching submissions.',
        'error' => $e->getMessage() // Add error details for debugging
    ]);
}
?>