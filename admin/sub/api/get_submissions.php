<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Include database connection
require_once '../../../asset/db/config.php';

try {
    // Get filter parameters from URL: prefer numeric category_id when provided, otherwise fall back to category name
    $categoryName = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    
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
    $resolvedFormDataId = null;
    if ($categoryId > 0 || $categoryName !== '') {
        // 1) Resolve the category name from forms_data to its numeric id
        $formsDataId = null;
        try {
            if ($categoryId > 0) {
                // If id is provided, trust it directly
                $formsDataId = $categoryId;
                $resolvedFormDataId = $formsDataId;
            } else {
            // Try preferred column name first
            $q = $pdo->prepare('SELECT id FROM `forms_data` WHERE TRIM(LOWER(`category`)) = TRIM(LOWER(?)) LIMIT 1');
            $q->execute([$categoryName]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && isset($r['id'])) {
                $formsDataId = (int)$r['id'];
                $resolvedFormDataId = $formsDataId;
            } else {
                // Fallback to legacy misspelled column
                $q2 = $pdo->prepare('SELECT id FROM `forms_data` WHERE TRIM(LOWER(`catogory`)) = TRIM(LOWER(?)) LIMIT 1');
                $q2->execute([$categoryName]);
                $r2 = $q2->fetch(PDO::FETCH_ASSOC);
                if ($r2 && isset($r2['id'])) {
                    $formsDataId = (int)$r2['id'];
                    $resolvedFormDataId = $formsDataId;
                } else {
                    // Additional fallback: LIKE-based lookup to handle extra spaces/variants
                    $like = "%" . $categoryName . "%";
                    try {
                        $q3 = $pdo->prepare('SELECT id FROM `forms_data` WHERE LOWER(`category`) LIKE LOWER(?) LIMIT 1');
                        $q3->execute([$like]);
                        $r3 = $q3->fetch(PDO::FETCH_ASSOC);
                        if ($r3 && isset($r3['id'])) {
                            $formsDataId = (int)$r3['id'];
                            $resolvedFormDataId = $formsDataId;
                        } else {
                            $q4 = $pdo->prepare('SELECT id FROM `forms_data` WHERE LOWER(`catogory`) LIKE LOWER(?) LIMIT 1');
                            $q4->execute([$like]);
                            $r4 = $q4->fetch(PDO::FETCH_ASSOC);
                            if ($r4 && isset($r4['id'])) {
                                $formsDataId = (int)$r4['id'];
                                $resolvedFormDataId = $formsDataId;
                            }
                        }
                    } catch (Throwable $eLike) {}
                }
            }
            }
        } catch (Throwable $eLookup) {
            $formsDataId = null;
        }

        
        if ($formsDataId !== null) {
            // 2) Fetch submissions that belong to this main category id
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        s.*,
                        f.form_name AS category_name
                    FROM `all-submissions` s
                    LEFT JOIN `forms_aggri` f ON s.forms_aggri_id = f.id
                    WHERE (s.`form_data_id` = ?) OR (f.`catogory_id` = ?)
                    ORDER BY s.`date_time` DESC
                ");
                $stmt->execute([$formsDataId, $formsDataId]);
            } catch (Throwable $eFilter) {
                // Fallback to legacy behavior if column doesn't exist: filter using forms_aggri.form_name prefix
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
            }
        } else {
            // Category name not found in forms_data: return empty
            echo json_encode([
                'success' => true,
                'submissions' => [],
                'count' => 0,
                'category' => $categoryName,
                'message' => 'Category not found'
            ]);
            exit;
        }
    } else {
        // No category specified: return all submissions (unchanged behavior)
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
        'resolved_form_data_id' => $resolvedFormDataId,
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