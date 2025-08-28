<?php
/**
 * Check Form - Add/Edit Checks with Multi-Category Support
 */

require_once 'bootstrap.php';

$auth->requireAuth();

$checkId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$check = null;
$selectedCategories = [];

// Load check data if editing
if ($checkId) {
    $check = $db->fetchOne("SELECT * FROM checks WHERE id = ?", [$checkId]);
    if (!$check) {
        header('Location: /dashboard.php');
        exit;
    }
    
    // Get selected categories for this check
    $selectedCategories = $db->fetchAll(
        "SELECT category_id FROM check_categories WHERE check_id = ?",
        [$checkId]
    );
    $selectedCategories = array_column($selectedCategories, 'category_id');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate inputs
    $name = trim($_POST['name'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $method = $_POST['method'] ?? 'GET';
    $expectedStatus = (int)($_POST['expected_status'] ?? 200);
    $expectedHeaders = trim($_POST['expected_headers'] ?? '');
    $expectedBody = trim($_POST['expected_body'] ?? '');
    $requestHeaders = trim($_POST['request_headers'] ?? '');
    $requestBody = trim($_POST['request_body'] ?? '');
    $timeoutSeconds = (int)($_POST['timeout_seconds'] ?? 30);
    $intervalSeconds = (int)($_POST['interval_seconds'] ?? 300);
    $maxRedirects = (int)($_POST['max_redirects'] ?? 5);
    $alertEmails = trim($_POST['alert_emails'] ?? '');
    $keepResponseData = isset($_POST['keep_response_data']) ? 1 : 0;
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $categoryIds = $_POST['categories'] ?? [];
    
    $errors = [];
    
    if (empty($name)) {
        $errors[] = 'Name is required';
    }
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $errors[] = 'Valid URL is required';
    }
    
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'])) {
        $errors[] = 'Invalid HTTP method';
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            $checkData = [
                'name' => $name,
                'url' => $url,
                'method' => $method,
                'expected_status' => $expectedStatus,
                'expected_headers' => $expectedHeaders,
                'expected_body' => $expectedBody,
                'request_headers' => $requestHeaders,
                'request_body' => $requestBody,
                'timeout_seconds' => $timeoutSeconds,
                'interval_seconds' => $intervalSeconds,
                'max_redirects' => $maxRedirects,
                'alert_emails' => $alertEmails,
                'keep_response_data' => $keepResponseData,
                'enabled' => $enabled
            ];
            
            if ($checkId) {
                // Update existing check
                $db->update('checks', $checkData, 'id = ?', [$checkId]);
                
                // Delete existing category associations
                $db->query("DELETE FROM check_categories WHERE check_id = ?", [$checkId]);
            } else {
                // Create new check
                $checkData['next_run_at'] = date('Y-m-d H:i:s');
                $checkId = $db->insert('checks', $checkData);
            }
            
            // Insert new category associations
            if (!empty($categoryIds)) {
                foreach ($categoryIds as $categoryId) {
                    if (is_numeric($categoryId) && $categoryId > 0) {
                        $db->insert('check_categories', [
                            'check_id' => $checkId,
                            'category_id' => (int)$categoryId
                        ]);
                    }
                }
            }
            
            $db->commit();
            
            header('Location: /dashboard.php?success=' . ($check ? 'updated' : 'created'));
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            $errors[] = 'Failed to save check: ' . $e->getMessage();
        }
    }
}

// Get all categories
$categories = $db->fetchAll("SELECT * FROM categories ORDER BY name");

// Prepare form data
$formData = $check ?: [
    'name' => $_POST['name'] ?? '',
    'url' => $_POST['url'] ?? '',
    'method' => $_POST['method'] ?? 'GET',
    'expected_status' => $_POST['expected_status'] ?? 200,
    'expected_headers' => $_POST['expected_headers'] ?? '',
    'expected_body' => $_POST['expected_body'] ?? '',
    'request_headers' => $_POST['request_headers'] ?? '',
    'request_body' => $_POST['request_body'] ?? '',
    'timeout_seconds' => $_POST['timeout_seconds'] ?? 30,
    'interval_seconds' => $_POST['interval_seconds'] ?? 300,
    'max_redirects' => $_POST['max_redirects'] ?? 5,
    'alert_emails' => $_POST['alert_emails'] ?? '',
    'keep_response_data' => $_POST['keep_response_data'] ?? 0,
    'enabled' => $_POST['enabled'] ?? 1
];

// If form was submitted, use posted categories
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedCategories = $_POST['categories'] ?? [];
}

// Interval options
$intervalOptions = [
    60 => '1 minute',
    300 => '5 minutes',
    900 => '15 minutes',
    1800 => '30 minutes',
    3600 => '1 hour',
    21600 => '6 hours',
    43200 => '12 hours',
    86400 => '1 day'
];

ob_start();
?>

<div class="container mx-auto px-4 py-6 max-w-4xl">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-800 flex items-center">
            <svg class="w-6 h-6 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <?php echo $checkId ? 'Edit Check' : 'Add New Check'; ?>
        </h2>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <?php foreach ($errors as $error): ?>
                <p class="text-red-800"><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($auth->getCsrfToken()); ?>">
        
        <!-- Basic Information -->
        <div class="glass rounded-lg shadow-sm p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
            
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Check Name</label>
                    <input type="text" id="name" name="name" required
                           value="<?php echo htmlspecialchars($formData['name']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                
                <div>
                    <label for="url" class="block text-sm font-medium text-gray-700 mb-1">URL</label>
                    <input type="url" id="url" name="url" required placeholder="https://example.com"
                           value="<?php echo htmlspecialchars($formData['url']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                
                <div>
                    <label for="method" class="block text-sm font-medium text-gray-700 mb-1">Method</label>
                    <select id="method" name="method"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        <?php foreach (['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'] as $m): ?>
                            <option value="<?php echo $m; ?>" <?php echo $formData['method'] === $m ? 'selected' : ''; ?>>
                                <?php echo $m; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="interval_seconds" class="block text-sm font-medium text-gray-700 mb-1">Check Interval</label>
                    <select id="interval_seconds" name="interval_seconds"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        <?php foreach ($intervalOptions as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $formData['interval_seconds'] == $value ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Categories (Multi-Select) -->
        <div class="glass rounded-lg shadow-sm p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Categories</h3>
            <p class="text-sm text-gray-600 mb-3">Select one or more categories for this check</p>
            
            <div class="grid gap-2 md:grid-cols-3 lg:grid-cols-4">
                <?php foreach ($categories as $category): ?>
                    <label class="flex items-center space-x-2 p-2 rounded hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" 
                               name="categories[]" 
                               value="<?php echo $category['id']; ?>"
                               <?php echo in_array($category['id'], $selectedCategories) ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <span class="flex items-center">
                            <span class="w-3 h-3 rounded-full mr-2" 
                                  style="background-color: <?php echo htmlspecialchars($category['color']); ?>"></span>
                            <?php echo htmlspecialchars($category['name']); ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            
            <?php if (empty($categories)): ?>
                <p class="text-gray-500 text-sm">No categories available. 
                    <a href="/categories.php" class="text-purple-600 hover:underline">Create one first</a>
                </p>
            <?php endif; ?>
        </div>

        <!-- Validation Rules -->
        <div class="glass rounded-lg shadow-sm p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Validation Rules</h3>
            
            <div class="space-y-4">
                <div>
                    <label for="expected_status" class="block text-sm font-medium text-gray-700 mb-1">
                        Expected Status Code
                    </label>
                    <input type="number" id="expected_status" name="expected_status" 
                           value="<?php echo htmlspecialchars($formData['expected_status']); ?>"
                           min="100" max="599" placeholder="200"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                
                <div>
                    <label for="expected_headers" class="block text-sm font-medium text-gray-700 mb-1">
                        Expected Response Headers (Optional)
                    </label>
                    <textarea id="expected_headers" name="expected_headers" rows="3"
                              placeholder="Content-Type: application/json&#10;X-Custom-Header: value"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"><?php echo htmlspecialchars($formData['expected_headers']); ?></textarea>
                    <p class="text-xs text-gray-500 mt-1">One header per line. Use regex: Header-Name: /pattern/</p>
                </div>
                
                <div>
                    <label for="expected_body" class="block text-sm font-medium text-gray-700 mb-1">
                        Expected Response Body (Optional)
                    </label>
                    <textarea id="expected_body" name="expected_body" rows="3"
                              placeholder="Text to find in response or /regex pattern/"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"><?php echo htmlspecialchars($formData['expected_body']); ?></textarea>
                    <p class="text-xs text-gray-500 mt-1">Text to search for or regex pattern</p>
                </div>
            </div>
        </div>

        <!-- Request Configuration -->
        <div class="glass rounded-lg shadow-sm p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Request Configuration</h3>
            
            <div class="space-y-4">
                <div>
                    <label for="request_headers" class="block text-sm font-medium text-gray-700 mb-1">
                        Custom Request Headers (Optional)
                    </label>
                    <textarea id="request_headers" name="request_headers" rows="3"
                              placeholder="Authorization: Bearer token&#10;X-API-Key: your-key"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"><?php echo htmlspecialchars($formData['request_headers']); ?></textarea>
                </div>
                
                <div id="request-body-field" style="display: <?php echo $formData['method'] !== 'GET' ? 'block' : 'none'; ?>">
                    <label for="request_body" class="block text-sm font-medium text-gray-700 mb-1">
                        Request Body (Optional)
                    </label>
                    <textarea id="request_body" name="request_body" rows="4"
                              placeholder='{"key": "value"}'
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"><?php echo htmlspecialchars($formData['request_body']); ?></textarea>
                </div>
                
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="timeout_seconds" class="block text-sm font-medium text-gray-700 mb-1">
                            Timeout (seconds)
                        </label>
                        <input type="number" id="timeout_seconds" name="timeout_seconds" 
                               value="<?php echo htmlspecialchars($formData['timeout_seconds']); ?>"
                               min="1" max="300"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    
                    <div>
                        <label for="max_redirects" class="block text-sm font-medium text-gray-700 mb-1">
                            Max Redirects
                        </label>
                        <input type="number" id="max_redirects" name="max_redirects" 
                               value="<?php echo htmlspecialchars($formData['max_redirects']); ?>"
                               min="0" max="10"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts & Settings -->
        <div class="glass rounded-lg shadow-sm p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Alerts & Settings</h3>
            
            <div class="space-y-4">
                <div>
                    <label for="alert_emails" class="block text-sm font-medium text-gray-700 mb-1">
                        Alert Emails (Optional)
                    </label>
                    <input type="text" id="alert_emails" name="alert_emails"
                           placeholder="email1@example.com, email2@example.com"
                           value="<?php echo htmlspecialchars($formData['alert_emails']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <p class="text-xs text-gray-500 mt-1">Comma-separated email addresses</p>
                </div>
                
                <div class="space-y-2">
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="keep_response_data" value="1"
                               <?php echo $formData['keep_response_data'] ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <span class="text-sm font-medium text-gray-700">Keep full response data</span>
                    </label>
                    <p class="text-xs text-gray-500 ml-6">Store complete response headers and body (uses more storage)</p>
                </div>
                
                <div class="space-y-2">
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="enabled" value="1"
                               <?php echo $formData['enabled'] ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <span class="text-sm font-medium text-gray-700">Enable this check</span>
                    </label>
                    <p class="text-xs text-gray-500 ml-6">When enabled, check runs automatically</p>
                </div>
            </div>
        </div>

        <!-- Submit Buttons -->
        <div class="flex justify-end space-x-3">
            <a href="/dashboard.php" 
               class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" 
                    class="px-4 py-2 bg-gradient-to-r from-purple-600 to-blue-600 text-white rounded-lg hover:from-purple-700 hover:to-blue-700">
                <?php echo $checkId ? 'Update Check' : 'Create Check'; ?>
            </button>
        </div>
    </form>
</div>

<script>
// Toggle request body field based on method
document.getElementById('method').addEventListener('change', function() {
    const requestBodyField = document.getElementById('request-body-field');
    requestBodyField.style.display = this.value !== 'GET' ? 'block' : 'none';
});
</script>

<?php
$content = ob_get_clean();
renderTemplate($checkId ? 'Edit Check' : 'Add Check', $content);
?>