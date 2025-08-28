<?php
require_once 'bootstrap.php';

// Handle check deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $checkId = (int)($_POST['check_id'] ?? 0);
    if ($checkId > 0) {
        try {
            $db->delete('checks', 'id = ?', [$checkId]);
            $message = '<div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded mb-4">Check deleted successfully!</div>';
        } catch (Exception $e) {
            $message = '<div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded mb-4">Error deleting check: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_checks'])) {
    $action = $_POST['bulk_action'];
    $selectedIds = array_map('intval', $_POST['selected_checks']);
    $count = 0;
    
    if (!empty($selectedIds) && in_array($action, ['enable', 'disable', 'delete'])) {
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        
        try {
            if ($action === 'enable') {
                $count = $db->query("UPDATE checks SET enabled = 1 WHERE id IN ({$placeholders})", $selectedIds)->rowCount();
                $message = '<div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded mb-4">Enabled ' . $count . ' check(s)!</div>';
            } elseif ($action === 'disable') {
                $count = $db->query("UPDATE checks SET enabled = 0 WHERE id IN ({$placeholders})", $selectedIds)->rowCount();
                $message = '<div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded mb-4">Disabled ' . $count . ' check(s)!</div>';
            } elseif ($action === 'delete') {
                $count = $db->query("DELETE FROM checks WHERE id IN ({$placeholders})", $selectedIds)->rowCount();
                $message = '<div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded mb-4">Deleted ' . $count . ' check(s)!</div>';
            }
        } catch (Exception $e) {
            $message = '<div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded mb-4">Bulk action failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Handle AJAX category creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_category') {
    header('Content-Type: application/json');
    
    $response = ['success' => false];
    
    $name = Auth::sanitizeInput($_POST['name'] ?? '');
    $color = $_POST['color'] ?? '#6B7280';
    $description = Auth::sanitizeInput($_POST['description'] ?? '');
    
    if (empty($name)) {
        $response['error'] = 'Category name is required';
    } else if (strlen($name) > 50) {
        $response['error'] = 'Category name must be less than 50 characters';
    } else {
        if (!preg_match('/^#[0-9A-F]{6}$/i', $color)) {
            $color = '#6B7280';
        }
        
        try {
            // Check if category already exists
            $existing = $db->fetchOne('SELECT id FROM categories WHERE name = ?', [$name]);
            if ($existing) {
                $response['error'] = 'Category with this name already exists';
            } else {
                $categoryId = $db->insert('categories', [
                    'name' => $name,
                    'color' => $color,
                    'icon' => 'folder',
                    'description' => $description
                ]);
                $response['success'] = true;
                $response['id'] = $categoryId;
                $response['name'] = $name;
            }
        } catch (Exception $e) {
            $response['error'] = 'Database error: ' . $e->getMessage();
        }
    }
    
    echo json_encode($response);
    exit;
}

// Get all categories for display (updated query for multi-category support)
$categories = [];
try {
    $categories = $db->fetchAll("
        SELECT c.*, 
               COUNT(DISTINCT cc.check_id) as active_checks_count,
               (SELECT COUNT(DISTINCT cc2.check_id) 
                FROM check_categories cc2 
                JOIN checks ch ON cc2.check_id = ch.id 
                WHERE cc2.category_id = c.id AND ch.last_state = 'DOWN' AND ch.enabled = 1) as down_checks_count
        FROM categories c
        LEFT JOIN check_categories cc ON c.id = cc.category_id
        LEFT JOIN checks ch ON cc.check_id = ch.id AND ch.enabled = 1
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
} catch (Exception $e) {
    // Categories table might not exist yet
    error_log("Categories query failed: " . $e->getMessage());
}

// Get filters
$statusFilter = $_GET['status'] ?? 'all';
$searchFilter = trim($_GET['search'] ?? '');
$intervalFilter = $_GET['interval'] ?? 'all';
$categoryFilter = $_GET['category'] ?? 'all';

// Build WHERE clause
$whereClauses = [];
$params = [];

if ($statusFilter === 'up') {
    $whereClauses[] = "c.last_state = 'UP'";
} elseif ($statusFilter === 'down') {
    $whereClauses[] = "c.last_state = 'DOWN'";
} elseif ($statusFilter === 'enabled') {
    $whereClauses[] = "c.enabled = 1";
} elseif ($statusFilter === 'disabled') {
    $whereClauses[] = "c.enabled = 0";
}

if (!empty($searchFilter)) {
    $whereClauses[] = "(c.name LIKE ? OR c.url LIKE ?)";
    $params[] = "%{$searchFilter}%";
    $params[] = "%{$searchFilter}%";
}

if ($intervalFilter !== 'all') {
    $whereClauses[] = "c.interval_seconds = ?";
    $params[] = (int)$intervalFilter;
}

if ($categoryFilter !== 'all') {
    if ($categoryFilter === 'uncategorized') {
        $whereClauses[] = "NOT EXISTS (SELECT 1 FROM check_categories WHERE check_id = c.id)";
    } else {
        $whereClauses[] = "EXISTS (SELECT 1 FROM check_categories WHERE check_id = c.id AND category_id = ?)";
        $params[] = (int)$categoryFilter;
    }
}

$whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get checks with multiple categories
$checksQuery = "
    SELECT c.*, 
           GROUP_CONCAT(DISTINCT cat.name ORDER BY cat.name SEPARATOR ', ') as category_names,
           GROUP_CONCAT(DISTINCT cat.color ORDER BY cat.name SEPARATOR ',') as category_colors,
           GROUP_CONCAT(DISTINCT cat.icon ORDER BY cat.name) as category_icons
    FROM checks c 
    LEFT JOIN check_categories cc ON c.id = cc.check_id
    LEFT JOIN categories cat ON cc.category_id = cat.id
    {$whereClause} 
    GROUP BY c.id
    ORDER BY c.name ASC";

try {
    $checks = $db->fetchAll($checksQuery, $params);
} catch (Exception $e) {
    $checks = [];
    error_log("Checks query failed: " . $e->getMessage());
}

// Add statistics for each check individually
foreach ($checks as &$check) {
    try {
        // Get 24h stats for this specific check
        $stats = $db->fetchOne(
            "SELECT 
                COUNT(*) as total_count_24h,
                SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_count_24h,
                AVG(duration_ms) as avg_latency_24h
            FROM check_results 
            WHERE check_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$check['id']]
        );
        
        $check['up_count_24h'] = $stats['up_count_24h'] ?? 0;
        $check['total_count_24h'] = $stats['total_count_24h'] ?? 0;
        $check['avg_latency_24h'] = $stats['avg_latency_24h'] ?? 0;
        
        // Get open incidents count
        $check['open_incidents'] = $db->fetchColumn(
            "SELECT COUNT(*) FROM incidents WHERE check_id = ? AND status = 'OPEN'",
            [$check['id']]
        ) ?: 0;
        
    } catch (Exception $e) {
        // Default values if queries fail
        $check['up_count_24h'] = 0;
        $check['total_count_24h'] = 0;
        $check['avg_latency_24h'] = 0;
        $check['open_incidents'] = 0;
    }
}
unset($check); // Break the reference

// Calculate uptime percentages and display data
foreach ($checks as $key => $check) {
    $checks[$key]['uptime_24h'] = $check['total_count_24h'] > 0 ? 
        round(($check['up_count_24h'] / $check['total_count_24h']) * 100, 1) : 0;
    $checks[$key]['avg_latency_24h'] = $check['avg_latency_24h'] ? round($check['avg_latency_24h']) : 0;
    
    // Calculate next run countdown
    $nextRun = strtotime($check['next_run_at']);
    $now = time();
    $secondsUntilNext = max(0, $nextRun - $now);
    
    if ($secondsUntilNext < 60) {
        $checks[$key]['next_run_display'] = $secondsUntilNext . 's';
    } else {
        $checks[$key]['next_run_display'] = floor($secondsUntilNext / 60) . 'm';
    }
}

$content = '
<div class="space-y-6">
    ' . ($message ?? '') . '
    
    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-900">All Checks</h1>
        <div class="flex space-x-3">
            <button onclick="openQuickCategoryModal()" 
                    class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700">
                Add Category
            </button>
            <a href="/check_form.php" 
               class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                Add New Check
            </a>
        </div>
    </div>';

// Display categories overview if they exist
if (!empty($categories)) {
    $content .= '
    <!-- Categories Overview -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">';
    
    // Add "All" category card
    $totalChecks = array_sum(array_column($categories, 'active_checks_count'));
    $totalDown = array_sum(array_column($categories, 'down_checks_count'));
    
    $content .= '
        <a href="?category=all' . 
            ($statusFilter !== 'all' ? '&status=' . $statusFilter : '') . 
            ($searchFilter ? '&search=' . htmlspecialchars($searchFilter) : '') . 
            ($intervalFilter !== 'all' ? '&interval=' . $intervalFilter : '') . '" 
           class="bg-white p-4 rounded-lg shadow hover:shadow-md transition-shadow ' . 
           ($categoryFilter === 'all' ? 'ring-2 ring-indigo-500' : '') . '">
            <div class="text-center">
                <div class="text-2xl mb-1">📊</div>
                <div class="font-semibold text-gray-900">All</div>
                <div class="text-sm text-gray-600">' . $totalChecks . ' checks</div>
                ' . ($totalDown > 0 ? '<div class="text-xs text-red-600 mt-1">' . $totalDown . ' down</div>' : '') . '
            </div>
        </a>';
    
    foreach ($categories as $category) {
        $isActive = $categoryFilter == $category['id'];
        $downBadge = $category['down_checks_count'] > 0 
            ? '<div class="text-xs text-red-600 mt-1">' . $category['down_checks_count'] . ' down</div>' 
            : '';
        
        $content .= '
        <a href="?category=' . $category['id'] . 
            ($statusFilter !== 'all' ? '&status=' . $statusFilter : '') . 
            ($searchFilter ? '&search=' . htmlspecialchars($searchFilter) : '') . 
            ($intervalFilter !== 'all' ? '&interval=' . $intervalFilter : '') . '" 
           class="bg-white p-4 rounded-lg shadow hover:shadow-md transition-shadow ' . 
           ($isActive ? 'ring-2 ring-indigo-500' : '') . '">
            <div class="text-center">
                <div class="w-10 h-10 mx-auto mb-2 rounded-full flex items-center justify-center" 
                     style="background-color: ' . $category['color'] . '20;">
                    <div class="text-xl" style="color: ' . $category['color'] . ';">●</div>
                </div>
                <div class="font-semibold text-gray-900 text-sm">' . htmlspecialchars($category['name']) . '</div>
                <div class="text-xs text-gray-600">' . $category['active_checks_count'] . ' checks</div>
                ' . $downBadge . '
            </div>
        </a>';
    }
    
    // Add uncategorized option
    $uncategorizedCount = $db->fetchColumn("
        SELECT COUNT(*) FROM checks c 
        WHERE NOT EXISTS (SELECT 1 FROM check_categories WHERE check_id = c.id) 
        AND c.enabled = 1
    ") ?: 0;
    if ($uncategorizedCount > 0 || $categoryFilter === 'uncategorized') {
        $content .= '
        <a href="?category=uncategorized' . 
            ($statusFilter !== 'all' ? '&status=' . $statusFilter : '') . 
            ($searchFilter ? '&search=' . htmlspecialchars($searchFilter) : '') . 
            ($intervalFilter !== 'all' ? '&interval=' . $intervalFilter : '') . '" 
           class="bg-white p-4 rounded-lg shadow hover:shadow-md transition-shadow ' . 
           ($categoryFilter === 'uncategorized' ? 'ring-2 ring-indigo-500' : '') . '">
            <div class="text-center">
                <div class="text-2xl mb-1">📁</div>
                <div class="font-semibold text-gray-900">No Category</div>
                <div class="text-sm text-gray-600">' . $uncategorizedCount . ' checks</div>
            </div>
        </a>';
    }
    
    $content .= '
    </div>';
}

// Keep the rest of your existing UI exactly the same
$content .= '
    <!-- Filters -->
    <div class="bg-white p-4 rounded-lg shadow">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label for="category" class="block text-sm font-medium text-gray-700">Category</label>
                <select id="category" name="category" 
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="all"' . ($categoryFilter === 'all' ? ' selected' : '') . '>All Categories</option>';

if (!empty($categories)) {
    foreach ($categories as $category) {
        $content .= '<option value="' . $category['id'] . '"' . 
                    ($categoryFilter == $category['id'] ? ' selected' : '') . '>' . 
                    htmlspecialchars($category['name']) . '</option>';
    }
}

$content .= '
                    <option value="uncategorized"' . ($categoryFilter === 'uncategorized' ? ' selected' : '') . '>Uncategorized</option>
                </select>
            </div>
            
            <div>
                <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                <select id="status" name="status" 
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="all"' . ($statusFilter === 'all' ? ' selected' : '') . '>All Status</option>
                    <option value="up"' . ($statusFilter === 'up' ? ' selected' : '') . '>UP Only</option>
                    <option value="down"' . ($statusFilter === 'down' ? ' selected' : '') . '>DOWN Only</option>
                    <option value="enabled"' . ($statusFilter === 'enabled' ? ' selected' : '') . '>Enabled Only</option>
                    <option value="disabled"' . ($statusFilter === 'disabled' ? ' selected' : '') . '>Disabled Only</option>
                </select>
            </div>
            
            <div>
                <label for="interval" class="block text-sm font-medium text-gray-700">Interval</label>
                <select id="interval" name="interval" 
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="all"' . ($intervalFilter === 'all' ? ' selected' : '') . '>All Intervals</option>
                    <option value="60"' . ($intervalFilter === '60' ? ' selected' : '') . '>1 Minute</option>
                    <option value="300"' . ($intervalFilter === '300' ? ' selected' : '') . '>5 Minutes</option>
                    <option value="3600"' . ($intervalFilter === '3600' ? ' selected' : '') . '>1 Hour</option>
                    <option value="86400"' . ($intervalFilter === '86400' ? ' selected' : '') . '>1 Day</option>
                </select>
            </div>
            
            <div>
                <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
                <input type="text" id="search" name="search" 
                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                       placeholder="Search by name or URL" value="' . htmlspecialchars($searchFilter) . '">
            </div>
            
            <div class="flex items-end">
                <button type="submit" 
                        class="w-full px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                    Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Checks Table - Keep existing structure -->
    <form method="POST" id="checksForm">
        <input type="hidden" name="csrf_token" value="' . $auth->getCsrfToken() . '">
        
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h2 class="text-lg font-medium text-gray-900">
                    Checks (' . count($checks) . ' found)
                </h2>
                
                <div class="flex items-center space-x-3">
                    <select name="bulk_action" class="px-3 py-2 border border-gray-300 rounded-md">
                        <option value="">Bulk Actions</option>
                        <option value="enable">Enable Selected</option>
                        <option value="disable">Disable Selected</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="button" onclick="submitBulkAction()" 
                            class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
                        Apply
                    </button>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                <input type="checkbox" id="selectAll" onchange="toggleAllChecks()">
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name/URL</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uptime 24h</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Avg Latency</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Interval</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Next Run</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">';

if (empty($checks)) {
    $content .= '
                        <tr>
                            <td colspan="9" class="px-6 py-8 text-center text-gray-500">
                                No checks found. <a href="/check_form.php" class="text-indigo-600 hover:text-indigo-800">Add your first check</a>
                            </td>
                        </tr>';
} else {
    foreach ($checks as $check) {
        $statusClass = match($check['last_state']) {
            'UP' => 'status-up',
            'DOWN' => 'status-down',
            default => 'bg-gray-100 text-gray-800'
        };
        
        $enabledBadge = $check['enabled'] ? 
            '<span class="badge bg-green-100 text-green-800">Enabled</span>' : 
            '<span class="badge bg-gray-100 text-gray-800">Disabled</span>';
        
        $intervalDisplay = match($check['interval_seconds']) {
            60 => '1m',
            300 => '5m',
            3600 => '1h',
            86400 => '1d',
            default => floor($check['interval_seconds'] / 60) . 'm'
        };
        
        $incidentBadge = $check['open_incidents'] > 0 ? 
            '<span class="badge bg-red-100 text-red-800 ml-2">' . $check['open_incidents'] . ' incident(s)</span>' : '';
        
        // Category badges - now supports multiple categories
        $categoryBadge = '';
        if (!empty($check['category_names'])) {
            $catNames = explode(', ', $check['category_names']);
            $catColors = explode(',', $check['category_colors']);
            
            foreach ($catNames as $i => $catName) {
                $color = trim($catColors[$i] ?? '#6B7280');
                $categoryBadge .= '
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium mr-1" 
                          style="background-color: ' . $color . '20; color: ' . $color . ';">
                        ' . htmlspecialchars($catName) . '
                    </span>';
            }
        } else {
            $categoryBadge = '<span class="text-gray-400 text-sm">-</span>';
        }
        
        $content .= '
                        <tr>
                            <td class="px-6 py-4">
                                <input type="checkbox" name="selected_checks[]" value="' . $check['id'] . '" class="check-item">
                            </td>
                            <td class="px-6 py-4">
                                ' . $categoryBadge . '
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-900">' . htmlspecialchars($check['name']) . '</div>
                                <div class="text-sm text-gray-500">' . htmlspecialchars($check['url']) . '</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="badge ' . $statusClass . '">' . ($check['last_state'] ?: 'NEW') . '</span>
                                ' . $enabledBadge . $incidentBadge . '
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">' . $check['uptime_24h'] . '%</td>
                            <td class="px-6 py-4 text-sm text-gray-900">' . formatDuration($check['avg_latency_24h']) . '</td>
                            <td class="px-6 py-4 text-sm text-gray-900">' . $intervalDisplay . '</td>
                            <td class="px-6 py-4 text-sm text-gray-900">' . $check['next_run_display'] . '</td>
                            <td class="px-6 py-4 text-sm space-x-2">
                                <a href="/check.php?id=' . $check['id'] . '" class="text-indigo-600 hover:text-indigo-900">View</a>
                                <a href="/check_form.php?id=' . $check['id'] . '" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                                <button type="button" onclick="deleteCheck(' . $check['id'] . ', \'' . htmlspecialchars($check['name'], ENT_QUOTES) . '\')" 
                                        class="text-red-600 hover:text-red-900">Delete</button>
                            </td>
                        </tr>';
    }
}

// Keep all the existing JavaScript and modal exactly the same
$content .= '
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</div>

<script>
function toggleAllChecks() {
    const selectAll = document.getElementById("selectAll");
    const checkItems = document.querySelectorAll(".check-item");
    checkItems.forEach(item => item.checked = selectAll.checked);
}

function submitBulkAction() {
    const form = document.getElementById("checksForm");
    const bulkAction = form.bulk_action.value;
    const selectedItems = document.querySelectorAll(".check-item:checked");
    
    if (!bulkAction) {
        alert("Please select a bulk action");
        return;
    }
    
    if (selectedItems.length === 0) {
        alert("Please select at least one check");
        return;
    }
    
    let confirmMsg = `Are you sure you want to ${bulkAction} ${selectedItems.length} check(s)?`;
    if (bulkAction === "delete") {
        confirmMsg = `Are you sure you want to DELETE ${selectedItems.length} check(s)? This action cannot be undone.`;
    }
    
    if (confirm(confirmMsg)) {
        form.submit();
    }
}

function deleteCheck(id, name) {
    if (confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
        const form = document.createElement("form");
        form.method = "POST";
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="' . $auth->getCsrfToken() . '">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="check_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Quick Category Modal Functions
function openQuickCategoryModal() {
    document.getElementById("quickCategoryModal").classList.remove("hidden");
    document.getElementById("quickCategoryName").focus();
}

function closeQuickCategoryModal() {
    document.getElementById("quickCategoryModal").classList.add("hidden");
    document.getElementById("quickCategoryForm").reset();
}

function saveQuickCategory(event) {
    event.preventDefault();
    
    const formData = new FormData(document.getElementById("quickCategoryForm"));
    formData.append("action", "create_category");
    formData.append("csrf_token", "' . $auth->getCsrfToken() . '");
    
    fetch("checks.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert("Category created successfully!");
            closeQuickCategoryModal();
            window.location.reload();
        } else {
            alert("Error: " + (data.error || "Failed to create category"));
        }
    })
    .catch(error => {
        alert("Error creating category: " + error);
    });
    
    return false;
}

// Close modal when clicking outside
document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById("quickCategoryModal");
    if (modal) {
        modal.addEventListener("click", function(e) {
            if (e.target === this) {
                closeQuickCategoryModal();
            }
        });
    }
});
</script>

<!-- Quick Add Category Modal -->
<div id="quickCategoryModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Quick Add Category</h3>
            <button onclick="closeQuickCategoryModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <form id="quickCategoryForm" onsubmit="return saveQuickCategory(event)">
            <div class="space-y-4">
                <div>
                    <label for="quickCategoryName" class="block text-sm font-medium text-gray-700">Category Name *</label>
                    <input type="text" id="quickCategoryName" name="name" required maxlength="50"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                           placeholder="e.g., Production, API, Database">
                </div>
                
                <div>
                    <label for="quickCategoryColor" class="block text-sm font-medium text-gray-700">Color</label>
                    <div class="mt-1 flex items-center space-x-2">
                        <input type="color" id="quickCategoryColor" name="color" value="#6B7280"
                               class="h-10 w-20 border border-gray-300 rounded cursor-pointer">
                        <select onchange="document.getElementById(\'quickCategoryColor\').value = this.value"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select preset...</option>
                            <option value="#10B981" style="color: #10B981;">● Green</option>
                            <option value="#3B82F6" style="color: #3B82F6;">● Blue</option>
                            <option value="#8B5CF6" style="color: #8B5CF6;">● Purple</option>
                            <option value="#EC4899" style="color: #EC4899;">● Pink</option>
                            <option value="#EF4444" style="color: #EF4444;">● Red</option>
                            <option value="#F59E0B" style="color: #F59E0B;">● Orange</option>
                            <option value="#14B8A6" style="color: #14B8A6;">● Teal</option>
                            <option value="#6B7280" style="color: #6B7280;">● Gray</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label for="quickCategoryDesc" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea id="quickCategoryDesc" name="description" rows="2"
                              class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                              placeholder="Optional description"></textarea>
                </div>
            </div>
            
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeQuickCategoryModal()" 
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                    Create Category
                </button>
            </div>
        </form>
        
        <div class="mt-4 pt-4 border-t border-gray-200">
            <p class="text-sm text-gray-600">
                Need more options? 
                <a href="/categories.php" class="text-indigo-600 hover:text-indigo-800">Go to Category Management</a>
            </p>
        </div>
    </div>
</div>';

renderTemplate('All Checks', $content);