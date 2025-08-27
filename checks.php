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

// Get filters
$statusFilter = $_GET['status'] ?? 'all';
$searchFilter = trim($_GET['search'] ?? '');
$intervalFilter = $_GET['interval'] ?? 'all';

// Build WHERE clause
$whereClauses = [];
$params = [];

if ($statusFilter === 'up') {
    $whereClauses[] = "last_state = 'UP'";
} elseif ($statusFilter === 'down') {
    $whereClauses[] = "last_state = 'DOWN'";
} elseif ($statusFilter === 'enabled') {
    $whereClauses[] = "enabled = 1";
} elseif ($statusFilter === 'disabled') {
    $whereClauses[] = "enabled = 0";
}

if (!empty($searchFilter)) {
    $whereClauses[] = "(name LIKE ? OR url LIKE ?)";
    $params[] = "%{$searchFilter}%";
    $params[] = "%{$searchFilter}%";
}

if ($intervalFilter !== 'all') {
    $whereClauses[] = "interval_seconds = ?";
    $params[] = (int)$intervalFilter;
}

$whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get checks with a simple DISTINCT query to avoid duplicates
$checksQuery = "SELECT DISTINCT c.* FROM checks c {$whereClause} ORDER BY c.created_at DESC, c.id DESC";

try {
    $checks = $db->fetchAll($checksQuery, $params);
    
    // Remove any duplicate IDs just in case
    $uniqueChecks = [];
    $seenIds = [];
    
    foreach ($checks as $check) {
        if (!in_array($check['id'], $seenIds)) {
            $uniqueChecks[] = $check;
            $seenIds[] = $check['id'];
        }
    }
    
    $checks = $uniqueChecks;
    
} catch (Exception $e) {
    $checks = [];
    error_log("Checks query failed: " . $e->getMessage());
}

// Add statistics for each check individually to avoid duplication
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
            <a href="/check_form.php" 
               class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                Add New Check
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white p-4 rounded-lg shadow">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
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

    <!-- Checks Table -->
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
                            <td colspan="8" class="px-6 py-8 text-center text-gray-500">
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
        
        $content .= '
                        <tr>
                            <td class="px-6 py-4">
                                <input type="checkbox" name="selected_checks[]" value="' . $check['id'] . '" class="check-item">
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
</script>';

renderTemplate('All Checks', $content);