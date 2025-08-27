<?php
require_once 'bootstrap.php';

// Check if database tables exist
try {
    $db->query("SELECT 1 FROM checks LIMIT 1");
} catch (Exception $e) {
    // Redirect to setup if tables don't exist
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        header('Location: /setup_database.php');
        exit;
    }
}

// Show success messages
$message = '';
if (isset($_GET['updated'])) {
    $message = '<div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded mb-4">Check updated successfully!</div>';
} elseif (isset($_GET['created'])) {
    $message = '<div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded mb-4">New check created successfully!</div>';
}

// Get all checks with their latest status
$checksQuery = "
    SELECT c.*, 
           CASE WHEN c.last_state = 'UP' THEN 'UP' 
                WHEN c.last_state = 'DOWN' THEN 'DOWN' 
                ELSE 'UNKNOWN' END as status,
           (SELECT AVG(duration_ms) FROM check_results cr WHERE cr.check_id = c.id AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) as avg_latency_24h,
           (SELECT COUNT(*) FROM check_results cr WHERE cr.check_id = c.id AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND cr.is_up = 1) as up_count_24h,
           (SELECT COUNT(*) FROM check_results cr WHERE cr.check_id = c.id AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) as total_count_24h
    FROM checks c 
    ORDER BY c.name ASC
";

$checks = $db->fetchAll($checksQuery);

// Calculate uptime percentages and format data
foreach ($checks as $key => $check) {
    $checks[$key]['uptime_24h'] = $check['total_count_24h'] > 0 ? 
        round(($check['up_count_24h'] / $check['total_count_24h']) * 100, 1) : 0;
    
    $checks[$key]['avg_latency_24h'] = $check['avg_latency_24h'] ? 
        round($check['avg_latency_24h']) : 0;
    
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
    <div class="flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
        <div class="flex space-x-3">
            <a href="/check_form.php" 
               class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                Add Check
            </a>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-2xl font-bold text-green-600">' . count(array_filter($checks, fn($c) => $c['status'] === 'UP')) . '</div>
            <div class="text-sm text-gray-600">UP</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-2xl font-bold text-red-600">' . count(array_filter($checks, fn($c) => $c['status'] === 'DOWN')) . '</div>
            <div class="text-sm text-gray-600">DOWN</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-2xl font-bold text-yellow-600">' . count(array_filter($checks, fn($c) => $c['enabled'] == 0)) . '</div>
            <div class="text-sm text-gray-600">DISABLED</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-2xl font-bold text-blue-600">' . count($checks) . '</div>
            <div class="text-sm text-gray-600">TOTAL</div>
        </div>
    </div>

    <!-- Checks Table -->
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">All Checks</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uptime 24h</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Avg Latency</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Next Run</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">';

if (empty($checks)) {
    $content .= '
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                            No checks configured. <a href="/check_form.php" class="text-indigo-600 hover:text-indigo-800">Add your first check</a>
                        </td>
                    </tr>';
} else {
    foreach ($checks as $check) {
        $statusClass = match($check['status']) {
            'UP' => 'status-up',
            'DOWN' => 'status-down',
            default => 'bg-gray-100 text-gray-800'
        };
        
        $enabledBadge = $check['enabled'] ? 
            '<span class="badge bg-green-100 text-green-800">Enabled</span>' : 
            '<span class="badge bg-gray-100 text-gray-800">Disabled</span>';
        
        $content .= '
                    <tr>
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900">' . htmlspecialchars($check['name']) . '</div>
                            <div class="text-sm text-gray-500">' . htmlspecialchars($check['url']) . '</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="badge ' . $statusClass . '">' . $check['status'] . '</span>
                            ' . $enabledBadge . '
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . $check['uptime_24h'] . '%</td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . formatDuration($check['avg_latency_24h']) . '</td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . $check['next_run_display'] . '</td>
                        <td class="px-6 py-4 text-sm space-x-2">
                            <a href="/check.php?id=' . $check['id'] . '" class="text-indigo-600 hover:text-indigo-900">View</a>
                            <a href="/check_form.php?id=' . $check['id'] . '" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                        </td>
                    </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>
</div>';

renderTemplate('Dashboard', $content);