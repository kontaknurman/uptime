<?php
require_once 'bootstrap.php';

$checkId = $_GET['id'] ?? null;
if (!$checkId) {
    redirect('/dashboard.php');
}

$check = $db->fetchOne('SELECT * FROM checks WHERE id = ?', [$checkId]);
if (!$check) {
    redirect('/dashboard.php');
}

// Get recent results (last 50)
$recentResults = $db->fetchAll(
    'SELECT * FROM check_results WHERE check_id = ? ORDER BY started_at DESC LIMIT 50',
    [$checkId]
);

// Get incident history
$incidents = $db->fetchAll(
    'SELECT i.*, 
            open_result.started_at as incident_started,
            close_result.started_at as incident_ended
     FROM incidents i 
     LEFT JOIN check_results open_result ON i.opened_by_result_id = open_result.id
     LEFT JOIN check_results close_result ON i.closed_by_result_id = close_result.id
     WHERE i.check_id = ? 
     ORDER BY i.started_at DESC LIMIT 20',
    [$checkId]
);

// Calculate uptime stats for different periods
$periods = [
    '24h' => 'DATE_SUB(NOW(), INTERVAL 1 DAY)',
    '7d' => 'DATE_SUB(NOW(), INTERVAL 7 DAY)',
    '30d' => 'DATE_SUB(NOW(), INTERVAL 30 DAY)'
];

$uptimeStats = [];
foreach ($periods as $period => $sql) {
    $stats = $db->fetchOne(
        "SELECT 
            COUNT(*) as total_checks,
            SUM(is_up) as up_checks,
            AVG(duration_ms) as avg_latency
         FROM check_results 
         WHERE check_id = ? AND started_at >= {$sql}",
        [$checkId]
    );
    
    $uptimeStats[$period] = [
        'uptime' => $stats['total_checks'] > 0 ? 
            round(($stats['up_checks'] / $stats['total_checks']) * 100, 1) : 0,
        'avg_latency' => $stats['avg_latency'] ? round($stats['avg_latency']) : 0,
        'total_checks' => $stats['total_checks']
    ];
}

$content = '
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <div class="flex items-center space-x-2">
                <h1 class="text-2xl font-bold text-gray-900">' . htmlspecialchars($check['name']) . '</h1>
                <span class="badge ' . ($check['last_state'] === 'UP' ? 'status-up' : 'status-down') . '">
                    ' . ($check['last_state'] ?: 'UNKNOWN') . '
                </span>
                ' . ($check['enabled'] ? '<span class="badge bg-green-100 text-green-800">Enabled</span>' : 
                    '<span class="badge bg-gray-100 text-gray-800">Disabled</span>') . '
            </div>
            <p class="text-gray-600">' . htmlspecialchars($check['url']) . '</p>
        </div>
        <div class="flex space-x-3">
            <a href="/check_form.php?id=' . $check['id'] . '" 
               class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Edit</a>
            <a href="/dashboard.php" class="text-gray-600 hover:text-gray-900">← Back</a>
        </div>
    </div>

    <!-- Uptime Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-2xl font-bold text-blue-600">' . $uptimeStats['24h']['uptime'] . '%</div>
            <div class="text-sm text-gray-600">24h Uptime (' . $uptimeStats['24h']['total_checks'] . ' checks)</div>
            <div class="text-xs text-gray-500">Avg: ' . formatDuration($uptimeStats['24h']['avg_latency']) . '</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-2xl font-bold text-blue-600">' . $uptimeStats['7d']['uptime'] . '%</div>
            <div class="text-sm text-gray-600">7d Uptime (' . $uptimeStats['7d']['total_checks'] . ' checks)</div>
            <div class="text-xs text-gray-500">Avg: ' . formatDuration($uptimeStats['7d']['avg_latency']) . '</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-2xl font-bold text-blue-600">' . $uptimeStats['30d']['uptime'] . '%</div>
            <div class="text-sm text-gray-600">30d Uptime (' . $uptimeStats['30d']['total_checks'] . ' checks)</div>
            <div class="text-xs text-gray-500">Avg: ' . formatDuration($uptimeStats['30d']['avg_latency']) . '</div>
        </div>
    </div>

    <!-- Latency Chart -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-lg font-medium text-gray-900 mb-4">Response Time (Last 24 hours)</h2>
        <canvas id="latencyChart" width="800" height="200"></canvas>
    </div>

    <!-- Recent Incidents -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Recent Incidents</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Started</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ended</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Duration</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">';

if (empty($incidents)) {
    $content .= '
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-gray-500">No incidents recorded</td>
                    </tr>';
} else {
    foreach ($incidents as $incident) {
        $duration = '';
        if ($incident['ended_at']) {
            $start = strtotime($incident['started_at']);
            $end = strtotime($incident['ended_at']);
            $diff = $end - $start;
            $duration = $diff < 3600 ? floor($diff / 60) . 'm' : floor($diff / 3600) . 'h';
        }
        
        $content .= '
                    <tr>
                        <td class="px-6 py-4 text-sm text-gray-900">' . date('M j, H:i', strtotime($incident['started_at'])) . '</td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . 
                            ($incident['ended_at'] ? date('M j, H:i', strtotime($incident['ended_at'])) : 'Ongoing') . '</td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . $duration . '</td>
                        <td class="px-6 py-4">
                            <span class="badge ' . ($incident['status'] === 'OPEN' ? 'status-down' : 'bg-gray-100 text-gray-800') . '">
                                ' . $incident['status'] . '
                            </span>
                        </td>
                    </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Results -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Recent Check Results</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">HTTP Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Latency</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">';

if (empty($recentResults)) {
    $content .= '
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">No results yet</td>
                    </tr>';
} else {
    foreach (array_slice($recentResults, 0, 20) as $result) {
        $content .= '
                    <tr>
                        <td class="px-6 py-4 text-sm text-gray-900">' . timeAgo($result['started_at']) . '</td>
                        <td class="px-6 py-4">
                            <span class="badge ' . ($result['is_up'] ? 'status-up' : 'status-down') . '">
                                ' . ($result['is_up'] ? 'UP' : 'DOWN') . '
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . $result['http_status'] . '</td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . formatDuration($result['duration_ms']) . '</td>
                        <td class="px-6 py-4 text-sm text-red-600">' . 
                            ($result['error_message'] ? htmlspecialchars(substr($result['error_message'], 0, 50)) . '...' : '-') . '</td>
                    </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Simple canvas chart for latency
const canvas = document.getElementById("latencyChart");
const ctx = canvas.getContext("2d");
const chartData = ' . json_encode(array_map(function($r) {
    return ['time' => $r['started_at'], 'latency' => $r['duration_ms'], 'up' => $r['is_up']];
}, array_reverse(array_slice($recentResults, 0, 24)))) . ';

// Draw simple line chart
if (chartData.length > 0) {
    const maxLatency = Math.max(...chartData.map(d => d.latency));
    const width = canvas.width - 60;
    const height = canvas.height - 40;
    
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = "#e5e7eb";
    ctx.lineWidth = 1;
    
    // Draw grid
    for (let i = 0; i <= 5; i++) {
        const y = 20 + (height / 5) * i;
        ctx.beginPath();
        ctx.moveTo(40, y);
        ctx.lineTo(width + 40, y);
        ctx.stroke();
    }
    
    // Draw data line
    if (chartData.length > 1) {
        ctx.strokeStyle = "#3b82f6";
        ctx.lineWidth = 2;
        ctx.beginPath();
        
        chartData.forEach((point, index) => {
            const x = 40 + (width / (chartData.length - 1)) * index;
            const y = height + 20 - (point.latency / maxLatency) * height;
            
            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
            
            // Mark down points
            if (!point.up) {
                ctx.fillStyle = "#ef4444";
                ctx.fillRect(x - 2, y - 2, 4, 4);
            }
        });
        
        ctx.stroke();
    }
    
    // Labels
    ctx.fillStyle = "#6b7280";
    ctx.font = "12px sans-serif";
    ctx.fillText("0ms", 5, height + 25);
    ctx.fillText(maxLatency + "ms", 5, 25);
}
</script>';

renderTemplate('Check Details', $content);