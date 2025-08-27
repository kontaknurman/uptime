<?php
/**
 * AJAX endpoint to get detailed check result data
 * File: get_result_details.php
 */

require_once 'bootstrap.php';

// Set JSON response headers
header('Content-Type: application/json');

try {
    // Security: Require authentication
    $auth->requireAuth();
    
    $resultId = (int)($_GET['id'] ?? 0);
    
    if ($resultId <= 0) {
        throw new Exception('Invalid result ID');
    }
    
    // Get result details with check info for security validation
    $result = $db->fetchOne(
        'SELECT cr.*, c.name as check_name, c.url as check_url
         FROM check_results cr 
         JOIN checks c ON cr.check_id = c.id 
         WHERE cr.id = ?',
        [$resultId]
    );
    
    if (!$result) {
        throw new Exception('Result not found');
    }
    
    // Format the response data
    $responseData = [
        'id' => $result['id'],
        'check_id' => $result['check_id'],
        'check_name' => $result['check_name'],
        'check_url' => $result['check_url'],
        'started_at' => $result['started_at'],
        'ended_at' => $result['ended_at'],
        'duration_ms' => (int)$result['duration_ms'],
        'http_status' => (int)$result['http_status'],
        'is_up' => (bool)$result['is_up'],
        'error_message' => $result['error_message'],
        'response_headers' => $result['response_headers'],
        'body_sample' => $result['body_sample']
    ];
    
    // Clean up data for display
    if (empty($responseData['response_headers']) || $responseData['response_headers'] === '{}') {
        $responseData['response_headers'] = null;
    } else {
        // Pretty format headers if they exist
        $responseData['response_headers'] = htmlspecialchars($responseData['response_headers']);
    }
    
    if (empty($responseData['body_sample'])) {
        $responseData['body_sample'] = null;
    } else {
        // Truncate very long responses for display
        if (strlen($responseData['body_sample']) > 10000) {
            $responseData['body_sample'] = substr($responseData['body_sample'], 0, 10000) . "\n\n[Response truncated for display...]";
        }
        $responseData['body_sample'] = htmlspecialchars($responseData['body_sample']);
    }
    
    if (empty($responseData['error_message'])) {
        $responseData['error_message'] = null;
    } else {
        $responseData['error_message'] = htmlspecialchars($responseData['error_message']);
    }
    
    // Success response
    echo json_encode([
        'success' => true,
        'result' => $responseData
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
    
    // Log the error for debugging
    error_log('Result details API error: ' . $e->getMessage());
}