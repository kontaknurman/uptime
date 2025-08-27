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
           (SELECT COUNT(*) FROM check_results cr WHERE cr.check_id = c.id AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) as total_count_24h,
           (SELECT COUNT(*) FROM check_results cr WHERE cr.check_id = c.id AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND cr.is_up = 0) as failures_24h,
           (SELECT COUNT(*) FROM incidents i WHERE i.check_id = c.id AND i.status = 'OPEN') as open_incidents
    FROM checks c 
    ORDER BY c.name ASC
";

$checks = $db->fetchAll($checksQuery);

// Get system-wide failure statistics
$failureStats = [
    'failures_today' => $db->fetchColumn(
        "SELECT COUNT(*) FROM check_results 
         WHERE is_up = 0 AND DATE(started_at) = CURDATE()"
    ) ?: 0,
    
    'failures_24h' => $db->fetchColumn(
        "SELECT COUNT(*) FROM check_results 
         WHERE is_up = 0 AND started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
    ) ?: 0,
    
    'failures_7d' => $db->fetchColumn(
        "SELECT COUNT(*) FROM check_results 
         WHERE is_up = 0 AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    ) ?: 0,
    
    'avg_failures_per_day' => $db->fetchColumn(
        "SELECT AVG(daily_failures) FROM (
            SELECT DATE(started_at) as date, COUNT(*) as daily_failures
            FROM check_results 
            WHERE is_up = 0 AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(started_at)
         ) as daily_stats"
    ) ?: 0,
    
    'total_incidents' => $db->fetchColumn("SELECT COUNT(*) FROM incidents") ?: 0,
    'open_incidents' => $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'OPEN'") ?: 0,
    'closed_incidents' => $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'CLOSED'") ?: 0
];

// Get recent failures (last 20)
$recentFailures = $db->fetchAll(
    "SELECT cr.*, c.name as check_name, c.url as check_url
     FROM check_results cr
     JOIN checks c ON cr.check_id = c.id
     WHERE cr.is_up = 0
     ORDER BY cr.started_at DESC
     LIMIT 20"
);

// Get failure trends by HTTP status
$failuresByStatus = $db->fetchAll(
    "SELECT 
        http_status,
        COUNT(*) as count,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM check_results WHERE is_up = 0 AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 1) as percentage
     FROM check_results 
     WHERE is_up = 0 AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY http_status 
     ORDER BY count DESC
     LIMIT 10"
);

// Get most problematic checks
$problematicChecks = $db->fetchAll(
    "SELECT 
        c.id,
        c.name,
        c.url,
        COUNT(cr.id) as failure_count,
        ROUND(COUNT(cr.id) * 100.0 / NULLIF((SELECT COUNT(*) FROM check_results cr2 WHERE cr2.check_id = c.id AND cr2.started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 0), 1) as failure_rate,
        MAX(cr.started_at) as last_failure
     FROM checks c
     JOIN check_results cr ON c.id = cr.check_id
     WHERE cr.is_up = 0 AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY c.id, c.name, c.url
     HAVING failure_count > 0
     ORDER BY failure_count DESC
     LIMIT 10"
);

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
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
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
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-2xl font-bold text-orange-600">' . $failureStats['failures_24h'] . '</div>
            <div class="text-sm text-gray-600">FAILURES 24H</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-2xl font-bold text-purple-600">' . $failureStats['open_incidents'] . '</div>
            <div class="text-sm text-gray-600">OPEN INCIDENTS</div>
        </div>
    </div>

    <!-- Failure Analytics Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Failure Statistics -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">Failure Statistics</h2>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Failures Today</span>
                        <span class="font-semibold text-red-600">' . $failureStats['failures_today'] . '</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Failures Last 24h</span>
                        <span class="font-semibold text-red-600">' . $failureStats['failures_24h'] . '</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Failures Last 7 days</span>
                        <span class="font-semibold text-red-600">' . $failureStats['failures_7d'] . '</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Average per day</span>
                        <span class="font-semibold text-orange-600">' . round($failureStats['avg_failures_per_day'], 1) . '</span>
                    </div>
                    <div class="border-t pt-4 mt-4">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Total Incidents</span>
                            <span class="font-semibold text-gray-800">' . $failureStats['total_incidents'] . '</span>
                        </div>
                        <div class="flex justify-between items-center mt-2">
                            <span class="text-sm text-gray-600">Open Incidents</span>
                            <span class="font-semibold text-red-600">' . $failureStats['open_incidents'] . '</span>
                        </div>
                        <div class="flex justify-between items-center mt-2">
                            <span class="text-sm text-gray-600">Resolved Incidents</span>
                            <span class="font-semibold text-green-600">' . $failureStats['closed_incidents'] . '</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Failure by HTTP Status -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">Failures by HTTP Status (7 days)</h2>
            </div>
            <div class="p-6">';

if (empty($failuresByStatus)) {
    $content .= '<p class="text-gray-500 text-center py-4">No failures in the last 7 days! 🎉</p>';
} else {
    foreach ($failuresByStatus as $statusFailure) {
        $statusCode = $statusFailure['http_status'];
        $statusClass = 'bg-gray-100';
        $statusText = 'Unknown';
        
        if ($statusCode == 0) {
            $statusClass = 'bg-red-100 text-red-800';
            $statusText = 'Connection Error';
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            $statusClass = 'bg-yellow-100 text-yellow-800';
            $statusText = 'Client Error';
        } elseif ($statusCode >= 500) {
            $statusClass = 'bg-red-100 text-red-800';
            $statusText = 'Server Error';
        } else {
            $statusText = 'HTTP ' . $statusCode;
        }
        
        $content .= '
                <div class="flex justify-between items-center py-2">
                    <div class="flex items-center space-x-3">
                        <span class="badge ' . $statusClass . '">' . $statusCode . '</span>
                        <span class="text-sm text-gray-600">' . $statusText . '</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <span class="font-semibold">' . $statusFailure['count'] . '</span>
                        <span class="text-sm text-gray-500">(' . $statusFailure['percentage'] . '%)</span>
                    </div>
                </div>';
    }
}

$content .= '
            </div>
        </div>
    </div>

    <!-- Most Problematic Checks -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Most Problematic Checks (7 days)</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Check</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Failures</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Failure Rate</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Failure</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">';

if (empty($problematicChecks)) {
    $content .= '
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                            <div class="text-green-600 text-2xl mb-2">🎉</div>
                            No problematic checks found in the last 7 days!
                        </td>
                    </tr>';
} else {
    foreach ($problematicChecks as $problem) {
        $failureRateClass = 'text-green-600';
        if ($problem['failure_rate'] > 10) $failureRateClass = 'text-yellow-600';
        if ($problem['failure_rate'] > 25) $failureRateClass = 'text-red-600';
        
        $content .= '
                    <tr>
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900">' . htmlspecialchars($problem['name']) . '</div>
                            <div class="text-sm text-gray-500">' . htmlspecialchars($problem['url']) . '</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-lg font-semibold text-red-600">' . $problem['failure_count'] . '</span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-semibold ' . $failureRateClass . '">' . ($problem['failure_rate'] ?: '0') . '%</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . timeAgo($problem['last_failure']) . '</td>
                        <td class="px-6 py-4 text-sm">
                            <a href="/check.php?id=' . $problem['id'] . '" class="text-indigo-600 hover:text-indigo-900">View Details</a>
                        </td>
                    </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Failures -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Recent Failures</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Check</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">HTTP Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Duration</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">';

if (empty($recentFailures)) {
    $content .= '
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                            <div class="text-green-600 text-2xl mb-2">✅</div>
                            No recent failures! All systems are running smoothly.
                        </td>
                    </tr>';
} else {
    foreach ($recentFailures as $failure) {
        $httpStatusClass = 'bg-gray-100 text-gray-800';
        if ($failure['http_status'] == 0) {
            $httpStatusClass = 'bg-red-100 text-red-800';
        } elseif ($failure['http_status'] >= 400 && $failure['http_status'] < 500) {
            $httpStatusClass = 'bg-yellow-100 text-yellow-800';
        } elseif ($failure['http_status'] >= 500) {
            $httpStatusClass = 'bg-red-100 text-red-800';
        }
        
        $content .= '
                    <tr>
                        <td class="px-6 py-4 text-sm text-gray-900">
                            <div>' . date('M j, H:i', strtotime($failure['started_at'])) . '</div>
                            <div class="text-xs text-gray-500">' . timeAgo($failure['started_at']) . '</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900">' . htmlspecialchars($failure['check_name']) . '</div>
                            <div class="text-sm text-gray-500">' . htmlspecialchars(substr($failure['check_url'], 0, 50)) . '...</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="badge ' . $httpStatusClass . '">' . $failure['http_status'] . '</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-red-600">
                            ' . ($failure['error_message'] ? 
                                htmlspecialchars(substr($failure['error_message'], 0, 40)) . '...' : 
                                'No error message') . '
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . formatDuration($failure['duration_ms']) . '</td>
                        <td class="px-6 py-4 text-sm">
                            <a href="/check.php?id=' . $failure['check_id'] . '" class="text-indigo-600 hover:text-indigo-900">View Check</a>
                        </td>
                    </tr>';
    }
}

$content .= '
                </tbody>
            </table>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Failures 24h</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Avg Latency</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Next Run</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">';

if (empty($checks)) {
    $content .= '
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-500">
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
        
        $incidentBadge = $check['open_incidents'] > 0 ? 
            '<br><span class="badge bg-red-100 text-red-800 mt-1">' . $check['open_incidents'] . ' incident(s)</span>' : '';
        
        $failureBadge = '';
        if ($check['failures_24h'] > 0) {
            $failureClass = $check['failures_24h'] > 5 ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800';
            $failureBadge = '<span class="badge ' . $failureClass . '">' . $check['failures_24h'] . '</span>';
        } else {
            $failureBadge = '<span class="text-green-600">0</span>';
        }
        
        $content .= '
                    <tr>
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900">' . htmlspecialchars($check['name']) . '</div>
                            <div class="text-sm text-gray-500">' . htmlspecialchars($check['url']) . '</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="badge ' . $statusClass . '">' . $check['status'] . '</span>
                            ' . $enabledBadge . $incidentBadge . '
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . $check['uptime_24h'] . '%</td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . $failureBadge . '</td>
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