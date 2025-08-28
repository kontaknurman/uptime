<?php
/**
 * AJAX endpoint to fetch check result details
 */

require_once 'bootstrap.php';

// Set JSON content type
header('Content-Type: application/json');

// Require authentication
$auth->requireAuth();

// Check if this is a GET request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get and validate result ID
$resultId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$resultId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Result ID is required']);
    exit;
}

try {
    // Fetch the result details with all fields
    $result = $db->fetchOne("
        SELECT 
            cr.*,
            c.name as check_name,
            c.url as check_url
        FROM check_results cr
        JOIN checks c ON cr.check_id = c.id
        WHERE cr.id = ?
    ", [$resultId]);
    
    if (!$result) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Result not found']);
        exit;
    }
    
    // Security check - ensure user has access to this result
    // You might want to add additional permission checks here based on your auth system
    
    // Format the response - using correct column names from database schema
    $response = [
        'success' => true,
        'result' => [
            'id' => (int)$result['id'],
            'check_id' => (int)$result['check_id'],
            'check_name' => $result['check_name'],
            'check_url' => $result['check_url'],
            'started_at' => $result['started_at'],
            'ended_at' => $result['ended_at'],
            'duration_ms' => (int)$result['duration_ms'],
            'is_up' => (bool)$result['is_up'],
            'http_status' => $result['http_status'] ? (int)$result['http_status'] : null,
            'error_message' => $result['error_message'],
            'response_headers' => $result['response_headers'],
            'response_body' => $result['body_sample'], // Note: using body_sample as response_body for consistency
            'body_sample' => $result['body_sample']
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error fetching result details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>