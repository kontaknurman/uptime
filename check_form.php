<?php
require_once 'bootstrap.php';

$checkId = $_GET['id'] ?? null;
$check = null;
$errors = [];

// Get all categories for dropdown
$categories = [];
try {
    $categories = $db->fetchAll('SELECT id, name, color FROM categories ORDER BY name ASC');
} catch (Exception $e) {
    error_log("Categories query failed: " . $e->getMessage());
}

// Load existing check for editing
if ($checkId) {
    $check = $db->fetchOne('SELECT * FROM checks WHERE id = ?', [$checkId]);
    if (!$check) {
        redirect('/dashboard.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $name = Auth::sanitizeInput($_POST['name'] ?? '');
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $url = Auth::validateUrl($_POST['url'] ?? '');
    $method = in_array($_POST['method'] ?? '', ['GET', 'POST']) ? $_POST['method'] : 'GET';
    $requestBody = $_POST['request_body'] ?? '';
    $requestHeaders = Auth::sanitizeInput($_POST['request_headers'] ?? '');
    $expectedStatus = (int)($_POST['expected_status'] ?? 200);
    $expectedHeaders = Auth::sanitizeInput($_POST['expected_headers'] ?? '');
    $expectedBody = $_POST['expected_body'] ?? ''; // NEW
    $intervalSeconds = (int)($_POST['interval_seconds'] ?? 300);
    $timeoutSeconds = (int)($_POST['timeout_seconds'] ?? 30);
    $maxRedirects = (int)($_POST['max_redirects'] ?? 5);
    $alertEmails = Auth::sanitizeInput($_POST['alert_emails'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $keepResponseData = isset($_POST['keep_response_data']) ? 1 : 0; // NEW

    // Validation
    if (empty($name)) $errors[] = 'Name is required';
    if (!$url) $errors[] = 'Valid URL is required';
    if ($expectedStatus < 100 || $expectedStatus > 599) $errors[] = 'Invalid HTTP status code';
    if ($intervalSeconds < 60) $errors[] = 'Interval must be at least 60 seconds';
    if ($timeoutSeconds < 1 || $timeoutSeconds > 300) $errors[] = 'Timeout must be between 1-300 seconds';

    // Validate alert emails
    if (!empty($alertEmails)) {
        $emails = array_map('trim', explode(',', $alertEmails));
        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid email address: {$email}";
            }
        }
    }

    if (empty($errors)) {
        $data = [
            'name' => $name,
            'category_id' => $categoryId,
            'url' => $url,
            'method' => $method,
            'request_body' => $requestBody,
            'request_headers' => $requestHeaders,
            'expected_status' => $expectedStatus,
            'expected_headers' => $expectedHeaders,
            'expected_body' => $expectedBody, // NEW
            'interval_seconds' => $intervalSeconds,
            'timeout_seconds' => $timeoutSeconds,
            'max_redirects' => $maxRedirects,
            'alert_emails' => $alertEmails,
            'enabled' => $enabled,
            'keep_response_data' => $keepResponseData // NEW
        ];

        try {
            if ($checkId) {
                // Update existing check
                $updated = $db->update('checks', $data, 'id = ?', [$checkId]);
                if ($updated > 0) {
                    header('Location: /dashboard.php?updated=1');
                    exit;
                } else {
                    $errors[] = 'No changes were made or check not found';
                }
            } else {
                // Create new check
                $data['next_run_at'] = date('Y-m-d H:i:s');
                $newId = $db->insert('checks', $data);
                header('Location: /dashboard.php?created=' . $newId);
                exit;
            }
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
            error_log('Check form error: ' . $e->getMessage());
        }
    }
}

// Default values - ensure no null values
$formData = $check ?: [
    'name' => '',
    'category_id' => null,
    'url' => '',
    'method' => 'GET',
    'request_body' => '',
    'request_headers' => '',
    'expected_status' => 200,
    'expected_headers' => '',
    'expected_body' => '', // NEW
    'interval_seconds' => 300,
    'timeout_seconds' => 30,
    'max_redirects' => 5,
    'alert_emails' => '',
    'enabled' => 1,
    'keep_response_data' => 0 // NEW
];

// Ensure all form data values are strings (not null)
foreach ($formData as $key => $value) {
    if ($value === null && !in_array($key, ['category_id', 'keep_response_data'])) {
        $formData[$key] = '';
    }
}

$intervalOptions = [
    60 => '1 minute',
    300 => '5 minutes',
    3600 => '1 hour',
    86400 => '1 day'
];

$title = $checkId ? 'Edit Check' : 'Add Check';

$content = '
<div class="max-w-4xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-900">' . $title . '</h1>
        <a href="/dashboard.php" class="text-gray-600 hover:text-gray-900">← Back to Dashboard</a>
    </div>

    ' . (!empty($errors) ? 
        '<div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded mb-4">
            <ul class="list-disc list-inside space-y-1">' . 
                implode('', array_map(fn($e) => '<li>' . htmlspecialchars($e) . '</li>', $errors)) . 
            '</ul>
        </div>' : '') . '

    <form method="POST" class="bg-white shadow rounded-lg p-6 space-y-6">
        <input type="hidden" name="csrf_token" value="' . $auth->getCsrfToken() . '">

        <!-- Basic Info Section -->
        <div class="border-b border-gray-200 pb-4">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Check Name *</label>
                    <input type="text" id="name" name="name" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                           value="' . htmlspecialchars($formData['name'] ?? '') . '">
                </div>
                
                <div>
                    <label for="category_id" class="block text-sm font-medium text-gray-700">Category</label>
                    <select id="category_id" name="category_id" 
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">';

$content .= '<option value="">No Category</option>';

if (!empty($categories)) {
    foreach ($categories as $category) {
        $selected = ($formData['category_id'] == $category['id']) ? ' selected' : '';
        $content .= '<option value="' . $category['id'] . '"' . $selected . ' data-color="' . $category['color'] . '">' . 
                    htmlspecialchars($category['name']) . '</option>';
    }
}

$content .= '
                    </select>
                </div>
            </div>
        </div>

        <!-- HTTP Configuration Section -->
        <div class="border-b border-gray-200 pb-4">
            <h3 class="text-lg font-medium text-gray-900 mb-4">HTTP Configuration</h3>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="url" class="block text-sm font-medium text-gray-700">Target URL *</label>
                    <input type="url" id="url" name="url" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                           value="' . htmlspecialchars($formData['url'] ?? '') . '">
                </div>
                
                <div>
                    <label for="method" class="block text-sm font-medium text-gray-700">HTTP Method</label>
                    <select id="method" name="method" 
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="GET"' . ($formData['method'] === 'GET' ? ' selected' : '') . '>GET</option>
                        <option value="POST"' . ($formData['method'] === 'POST' ? ' selected' : '') . '>POST</option>
                    </select>
                </div>
            </div>

            <div id="request-body-field" class="mt-4" style="display: ' . ($formData['method'] === 'POST' ? 'block' : 'none') . '">
                <label for="request_body" class="block text-sm font-medium text-gray-700">Request Body (Optional)</label>
                <textarea id="request_body" name="request_body" rows="4"
                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 font-mono text-sm"
                          placeholder="Enter request body data...">' . htmlspecialchars($formData['request_body'] ?? '', ENT_NOQUOTES) . '</textarea>
                <p class="mt-1 text-sm text-gray-500">
                    <strong>Examples:</strong><br>
                    • JSON: <code>{"key": "value", "status": "test"}</code><br>
                    • Form Data: <code>username=test&password=123</code><br>
                    • XML: <code>&lt;request&gt;&lt;action&gt;test&lt;/action&gt;&lt;/request&gt;</code><br>
                    • Plain Text: Any raw text content
                </p>
            </div>

            <div class="mt-4">
                <label for="request_headers" class="block text-sm font-medium text-gray-700">Request Headers (Optional)</label>
                <textarea id="request_headers" name="request_headers" rows="3"
                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 font-mono text-sm"
                          placeholder="One header per line...">' . htmlspecialchars($formData['request_headers'] ?? '') . '</textarea>
                <p class="mt-1 text-sm text-gray-500">
                    <strong>Examples:</strong><br>
                    • <code>Content-Type: application/json</code><br>
                    • <code>Authorization: Bearer your-token</code><br>
                    • <code>X-API-Key: your-api-key</code>
                </p>
            </div>
        </div>

        <!-- Response Validation Section -->
        <div class="border-b border-gray-200 pb-4">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Response Validation</h3>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="expected_status" class="block text-sm font-medium text-gray-700">Expected HTTP Status</label>
                    <input type="number" id="expected_status" name="expected_status" min="100" max="599"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                           value="' . $formData['expected_status'] . '">
                    <p class="mt-1 text-sm text-gray-500">Usually 200 for successful requests</p>
                </div>
                
                <div>
                    <label for="interval_seconds" class="block text-sm font-medium text-gray-700">Check Interval</label>
                    <select id="interval_seconds" name="interval_seconds" 
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">';

foreach ($intervalOptions as $seconds => $label) {
    $selected = $formData['interval_seconds'] == $seconds ? ' selected' : '';
    $content .= "<option value=\"{$seconds}\"{$selected}>{$label}</option>";
}

$content .= '
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <label for="expected_headers" class="block text-sm font-medium text-gray-700">Expected Response Headers (Optional)</label>
                <textarea id="expected_headers" name="expected_headers" rows="3"
                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                          placeholder="One header per line: Key: Value">' . htmlspecialchars($formData['expected_headers'] ?? '') . '</textarea>
                <p class="mt-1 text-sm text-gray-500">Example: Content-Type: application/json</p>
            </div>

            <!-- NEW: Expected Response Body -->
            <div class="mt-4">
                <label for="expected_body" class="block text-sm font-medium text-gray-700">Expected Response Body (Optional)</label>
                <textarea id="expected_body" name="expected_body" rows="3"
                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                          placeholder="Text that must be present in the response body">' . htmlspecialchars($formData['expected_body'] ?? '', ENT_NOQUOTES) . '</textarea>
                <p class="mt-1 text-sm text-gray-500">
                    <strong>Contains Check:</strong> Enter text that must exist in the response body.<br>
                    Example: Enter "success" to check if response contains the word "success"<br>
                    <em>If specified, the response body must contain this text for the check to be UP</em>
                </p>
            </div>
        </div>

        <!-- Advanced Settings Section -->
        <div class="border-b border-gray-200 pb-4">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Advanced Settings</h3>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="timeout_seconds" class="block text-sm font-medium text-gray-700">Timeout (seconds)</label>
                    <input type="number" id="timeout_seconds" name="timeout_seconds" min="1" max="300"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                           value="' . $formData['timeout_seconds'] . '">
                    <p class="mt-1 text-sm text-gray-500">Maximum time to wait for response</p>
                </div>
                
                <div>
                    <label for="max_redirects" class="block text-sm font-medium text-gray-700">Max Redirects</label>
                    <input type="number" id="max_redirects" name="max_redirects" min="0" max="10"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                           value="' . $formData['max_redirects'] . '">
                    <p class="mt-1 text-sm text-gray-500">Follow HTTP redirects up to this limit</p>
                </div>
            </div>

            <!-- NEW: Keep Response Data Option -->
            <div class="mt-4">
                <div class="flex items-center">
                    <input type="checkbox" id="keep_response_data" name="keep_response_data" value="1" 
                           ' . ($formData['keep_response_data'] ? 'checked' : '') . '
                           class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                    <label for="keep_response_data" class="ml-2 block text-sm text-gray-900">
                        <strong>Keep Full Response Data</strong>
                    </label>
                </div>
                <p class="mt-1 ml-6 text-sm text-gray-500">
                    When enabled, full response headers and body will be stored in the database for all check results.<br>
                    When disabled, full data is only stored for DOWN/ERROR results (to help with debugging).<br>
                    <em>Note: Enabling this will increase database storage usage</em>
                </p>
            </div>
        </div>

        <!-- Notifications Section -->
        <div class="border-b border-gray-200 pb-4">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Notifications</h3>
            
            <div>
                <label for="alert_emails" class="block text-sm font-medium text-gray-700">Alert Email Recipients</label>
                <input type="text" id="alert_emails" name="alert_emails"
                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                       placeholder="email1@example.com, email2@example.com"
                       value="' . htmlspecialchars($formData['alert_emails'] ?? '') . '">
                <p class="mt-1 text-sm text-gray-500">Comma-separated email addresses to notify on DOWN/RECOVERY events</p>
            </div>
        </div>

        <!-- Status Section -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 mb-4">Status</h3>
            
            <div class="flex items-center">
                <input type="checkbox" id="enabled" name="enabled" value="1" 
                       ' . ($formData['enabled'] ? 'checked' : '') . '
                       class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                <label for="enabled" class="ml-2 block text-sm text-gray-900">
                    <strong>Enable this check</strong>
                </label>
            </div>
            <p class="mt-1 ml-6 text-sm text-gray-500">When enabled, this check will run automatically according to the specified interval</p>
        </div>

        <div class="flex justify-end space-x-3 pt-4">
            <a href="/dashboard.php" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" 
                    class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                ' . ($checkId ? 'Update' : 'Create') . ' Check
            </button>
        </div>
    </form>
</div>

<script>
// Enhanced JavaScript for better form handling
document.getElementById("method").addEventListener("change", function() {
    const requestBodyField = document.getElementById("request-body-field");
    const requestBodyTextarea = document.getElementById("request_body");
    
    if (this.value === "POST") {
        requestBodyField.style.display = "block";
        requestBodyTextarea.focus();
    } else {
        requestBodyField.style.display = "none";
    }
});

// Visual feedback for category selection
document.getElementById("category_id").addEventListener("change", function() {
    const selectedOption = this.options[this.selectedIndex];
    const color = selectedOption.getAttribute("data-color");
    if (color) {
        this.style.borderColor = color;
        this.style.boxShadow = `0 0 0 1px ${color}`;
    } else {
        this.style.borderColor = "#D1D5DB";
        this.style.boxShadow = "none";
    }
});

// Enhanced form validation and visual feedback
document.addEventListener("DOMContentLoaded", function() {
    const expectedBodyField = document.getElementById("expected_body");
    const requestBodyField = document.getElementById("request_body");
    const keepDataField = document.getElementById("keep_response_data");
    const urlField = document.getElementById("url");
    
    // Visual feedback for expected body field
    expectedBodyField.addEventListener("input", function() {
        if (this.value.trim()) {
            this.style.borderColor = "#10B981";
            this.style.backgroundColor = "#F0FDF4";
        } else {
            this.style.borderColor = "#D1D5DB";
            this.style.backgroundColor = "white";
        }
    });
    
    // Visual feedback for request body field with content type detection
    requestBodyField.addEventListener("input", function() {
        const value = this.value.trim();
        if (value) {
            // Try to detect content type and provide visual feedback
            if (value.startsWith("{") && value.endsWith("}")) {
                this.style.borderColor = "#3B82F6"; // Blue for JSON
                this.title = "Detected: JSON content";
            } else if (value.startsWith("<") && value.endsWith(">")) {
                this.style.borderColor = "#8B5CF6"; // Purple for XML
                this.title = "Detected: XML content";
            } else if (value.includes("=") && value.includes("&")) {
                this.style.borderColor = "#F59E0B"; // Orange for form data
                this.title = "Detected: Form data";
            } else {
                this.style.borderColor = "#6B7280"; // Gray for plain text
                this.title = "Plain text content";
            }
            this.style.backgroundColor = "#FEFCE8";
        } else {
            this.style.borderColor = "#D1D5DB";
            this.style.backgroundColor = "white";
            this.title = "";
        }
    });
    
    // URL validation with visual feedback
    urlField.addEventListener("blur", function() {
        const url = this.value.trim();
        if (url) {
            try {
                new URL(url);
                this.style.borderColor = "#10B981";
                this.style.backgroundColor = "#F0FDF4";
            } catch (e) {
                this.style.borderColor = "#EF4444";
                this.style.backgroundColor = "#FEF2F2";
            }
        } else {
            this.style.borderColor = "#D1D5DB";
            this.style.backgroundColor = "white";
        }
    });
    
    // Initialize visual states
    if (expectedBodyField.value.trim()) {
        expectedBodyField.style.borderColor = "#10B981";
        expectedBodyField.style.backgroundColor = "#F0FDF4";
    }
    
    if (requestBodyField.value.trim()) {
        requestBodyField.dispatchEvent(new Event("input"));
    }
    
    if (urlField.value.trim()) {
        urlField.dispatchEvent(new Event("blur"));
    }
});
</script>';

renderTemplate($title, $content);