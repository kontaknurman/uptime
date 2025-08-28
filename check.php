<?php
/**
 * Check Details Page - Updated Design
 */

require_once 'bootstrap.php';

$auth->requireAuth();

$checkId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$timeRange = $_GET['range'] ?? '24h'; // 1h, 24h, 7d, 30d, 90d
$viewTab = $_GET['tab'] ?? 'overview'; // overview, incidents, results, config

if (!$checkId) {
    header('Location: /dashboard.php');
    exit;
}

// Get check details
$check = $db->fetchOne("SELECT * FROM checks WHERE id = ?", [$checkId]);

if (!$check) {
    header('Location: /dashboard.php');
    exit;
}

// Get categories for this check
$checkCategories = $db->fetchAll("
    SELECT cat.* 
    FROM check_categories cc
    JOIN categories cat ON cc.category_id = cat.id
    WHERE cc.check_id = ?
    ORDER BY cat.name
", [$checkId]);

// Get last 100 results for charts and display
$results = $db->fetchAll("
    SELECT * FROM check_results 
    WHERE check_id = ? 
    ORDER BY started_at DESC 
    LIMIT 100
", [$checkId]);

// Enhanced uptime statistics function
function getUptimeStats($db, $checkId, $hours) {
    $stats = $db->fetchOne("
        SELECT 
            COUNT(*) as total_checks,
            SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_checks,
            AVG(duration_ms) as avg_latency,
            MIN(duration_ms) as min_latency,
            MAX(duration_ms) as max_latency,
            STDDEV(duration_ms) as std_deviation
        FROM check_results
        WHERE check_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ", [$checkId, $hours]);
    
    // Calculate percentiles manually for MariaDB compatibility
    $percentiles = ['median_latency' => 0, 'p95_latency' => 0, 'p99_latency' => 0];
    
    if ($stats['total_checks'] > 0) {
        $latencies = $db->fetchAll("
            SELECT duration_ms 
            FROM check_results 
            WHERE check_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY duration_ms ASC
        ", [$checkId, $hours]);
        
        if (!empty($latencies)) {
            $count = count($latencies);
            $latencyValues = array_column($latencies, 'duration_ms');
            
            // Calculate median (P50)
            $medianIndex = floor($count / 2);
            $percentiles['median_latency'] = $count % 2 == 0 
                ? ($latencyValues[$medianIndex - 1] + $latencyValues[$medianIndex]) / 2
                : $latencyValues[$medianIndex];
            
            // Calculate P95
            $p95Index = floor($count * 0.95);
            $percentiles['p95_latency'] = $latencyValues[min($p95Index, $count - 1)];
            
            // Calculate P99
            $p99Index = floor($count * 0.99);
            $percentiles['p99_latency'] = $latencyValues[min($p99Index, $count - 1)];
        }
    }
    
    $stats = array_merge($stats, $percentiles);
    
    $uptime = $stats['total_checks'] > 0 ? 
        round(($stats['up_checks'] / $stats['total_checks']) * 100, 2) : 0;
    
    // Get response time distribution
    $distribution = $db->fetchAll("
        SELECT 
            CASE 
                WHEN duration_ms < 100 THEN '<100ms'
                WHEN duration_ms < 300 THEN '100-300ms'
                WHEN duration_ms < 1000 THEN '300ms-1s'
                WHEN duration_ms < 3000 THEN '1-3s'
                ELSE '>3s'
            END as bucket,
            COUNT(*) as count
        FROM check_results
        WHERE check_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        GROUP BY bucket
        ORDER BY 
            CASE bucket
                WHEN '<100ms' THEN 1
                WHEN '100-300ms' THEN 2
                WHEN '300ms-1s' THEN 3
                WHEN '1-3s' THEN 4
                ELSE 5
            END
    ", [$checkId, $hours]);
    
    return [
        'uptime' => $uptime,
        'total_checks' => $stats['total_checks'] ?: 0,
        'up_checks' => $stats['up_checks'] ?: 0,
        'down_checks' => ($stats['total_checks'] ?: 0) - ($stats['up_checks'] ?: 0),
        'avg_latency' => round($stats['avg_latency'] ?: 0),
        'min_latency' => round($stats['min_latency'] ?: 0),
        'max_latency' => round($stats['max_latency'] ?: 0),
        'std_deviation' => round($stats['std_deviation'] ?: 0),
        'median_latency' => round($stats['median_latency'] ?: 0),
        'p95_latency' => round($stats['p95_latency'] ?: 0),
        'p99_latency' => round($stats['p99_latency'] ?: 0),
        'distribution' => $distribution
    ];
}

// Get stats for multiple time ranges
$uptimeStats = [
    '1h' => getUptimeStats($db, $checkId, 1),
    '24h' => getUptimeStats($db, $checkId, 24),
    '7d' => getUptimeStats($db, $checkId, 24 * 7),
    '30d' => getUptimeStats($db, $checkId, 24 * 30),
    '90d' => getUptimeStats($db, $checkId, 24 * 90)
];

// Get all incidents with more details
$incidents = $db->fetchAll("
    SELECT i.*, 
           cr1.started_at as down_time,
           cr1.http_status as down_status_code,
           cr1.error_message as down_error,
           cr2.started_at as up_time,
           cr2.http_status as up_status_code,
           TIMESTAMPDIFF(SECOND, i.started_at, IFNULL(i.ended_at, NOW())) as duration_seconds
    FROM incidents i
    LEFT JOIN check_results cr1 ON i.opened_by_result_id = cr1.id
    LEFT JOIN check_results cr2 ON i.closed_by_result_id = cr2.id
    WHERE i.check_id = ?
    ORDER BY i.started_at DESC
    LIMIT 50
", [$checkId]);

// Get hourly data for the selected time range
$hoursToFetch = match($timeRange) {
    '1h' => 1,
    '7d' => 168,
    '30d' => 720,
    '90d' => 2160,
    default => 24
};

$hourlyData = $db->fetchAll("
    SELECT 
        DATE_FORMAT(started_at, '%Y-%m-%d %H:00:00') as hour,
        COUNT(*) as total,
        SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_count,
        AVG(duration_ms) as avg_latency,
        MAX(duration_ms) as max_latency,
        MIN(duration_ms) as min_latency
    FROM check_results
    WHERE check_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    GROUP BY DATE_FORMAT(started_at, '%Y-%m-%d %H:00:00')
    ORDER BY hour ASC
", [$checkId, $hoursToFetch]);

// Get status changes (for timeline)
$statusChanges = $db->fetchAll("
    SELECT * FROM check_results
    WHERE check_id = ?
    ORDER BY started_at DESC
    LIMIT 50
", [$checkId]);

// Filter only actual status changes by comparing with previous
$statusTimeline = [];
$prevStatus = null;
foreach ($statusChanges as $index => $change) {
    if ($index === 0 || $change['is_up'] != $prevStatus) {
        $statusTimeline[] = $change;
        $prevStatus = $change['is_up'];
    }
}

// Calculate current status duration
$currentStatusDuration = '';
if (!empty($results)) {
    $lastStatusChange = $db->fetchOne("
        SELECT started_at 
        FROM check_results 
        WHERE check_id = ? AND is_up != ?
        ORDER BY started_at DESC 
        LIMIT 1
    ", [$checkId, $results[0]['is_up']]);
    
    if ($lastStatusChange) {
        $duration = time() - strtotime($lastStatusChange['started_at']);
        $currentStatusDuration = $duration < 3600 ? round($duration / 60) . ' minutes' : 
                                ($duration < 86400 ? round($duration / 3600) . ' hours' : 
                                round($duration / 86400) . ' days');
    }
}

// Get response codes distribution
$responseCodesData = $db->fetchAll("
    SELECT 
        http_status,
        COUNT(*) as count
    FROM check_results
    WHERE check_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    GROUP BY http_status
    ORDER BY count DESC
", [$checkId, $hoursToFetch]);

// Start output
ob_start();
?>

<style>
    .tab-active {
        border-bottom: 3px solid #6b7280;
        color: #6b7280;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
    }
    .status-up {
        background: #10b981;
        color: white;
    }
    .status-down {
        background: #ef4444;
        color: white;
    }
    .metric-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        transition: transform 0.2s, box-shadow 0.2s;
        border: 1px solid #e5e7eb;
    }
    .metric-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 12px rgba(0,0,0,0.12);
    }
    .chart-container {
        position: relative;
        height: 250px;
    }
    .timeline-item {
        position: relative;
        padding-left: 40px;
        padding-bottom: 20px;
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 20px;
        bottom: 0;
        width: 2px;
        background: #e5e7eb;
    }
    .timeline-item:last-child::before {
        display: none;
    }
    .timeline-dot {
        position: absolute;
        left: 10px;
        top: 6px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: white;
        border: 3px solid;
    }
    .timeline-dot.up {
        border-color: #10b981;
    }
    .timeline-dot.down {
        border-color: #ef4444;
    }
    .response-chart {
        display: flex;
        align-items: flex-end;
        height: 150px;
        gap: 4px;
    }
    .response-bar {
        flex: 1;
        background: #6b7280;
        border-radius: 4px 4px 0 0;
        position: relative;
        cursor: pointer;
        transition: opacity 0.2s;
    }
    .response-bar:hover {
        opacity: 0.8;
    }
    .response-bar.down {
        background: #ef4444;
    }
    .category-pill {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-right: 6px;
        background-color: #f3f4f6;
        color: #6b7280;
        border: 1px solid #d1d5db;
    }
    .distribution-bar {
        display: flex;
        height: 30px;
        border-radius: 15px;
        overflow: hidden;
        background: #f3f4f6;
    }
    .distribution-segment {
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 12px;
        font-weight: 600;
    }
</style>

<div class="space-y-6">
    <!-- Header with Gray Theme -->
    <div class="bg-gradient-to-r from-slate-700 to-slate-600 rounded-xl p-6 text-white shadow-lg">
        <div class="flex justify-between items-start">
            <div class="flex-1">
                <div class="flex items-center gap-3 mb-3">
                    <h1 class="text-3xl font-bold"><?php echo htmlspecialchars($check['name']); ?></h1>
                    <?php if ($check['enabled']): ?>
                        <?php if ($check['last_state'] === 'UP'): ?>
                            <span class="status-badge status-up">
                                <span class="w-2 h-2 bg-white rounded-full mr-2 animate-pulse"></span>
                                OPERATIONAL
                            </span>
                        <?php elseif ($check['last_state'] === 'DOWN'): ?>
                            <span class="status-badge status-down">
                                <span class="w-2 h-2 bg-white rounded-full mr-2 animate-pulse"></span>
                                DOWN
                            </span>
                        <?php else: ?>
                            <span class="status-badge" style="background: #6b7280;">
                                UNKNOWN
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($currentStatusDuration): ?>
                            <span class="text-slate-200 text-sm">for <?php echo $currentStatusDuration; ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="status-badge" style="background: rgba(255,255,255,0.2);">
                            DISABLED
                        </span>
                    <?php endif; ?>
                </div>
                
                <!-- Categories -->
                <?php if (!empty($checkCategories)): ?>
                <div class="flex flex-wrap gap-2 mb-3">
                    <?php foreach ($checkCategories as $cat): ?>
                        <span class="category-pill" style="background: rgba(255,255,255,0.2); color: white; border-color: rgba(255,255,255,0.3);">
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div class="flex items-center gap-2 text-slate-200 mb-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                    </svg>
                    <a href="<?php echo htmlspecialchars($check['url']); ?>" target="_blank" class="hover:text-white transition-colors">
                        <?php echo htmlspecialchars($check['url']); ?>
                    </a>
                </div>
                
                <div class="flex flex-wrap gap-4 text-sm text-slate-200">
                    <span><strong>Method:</strong> <?php echo $check['method']; ?></span>
                    <span><strong>Expected:</strong> HTTP <?php echo $check['expected_status']; ?></span>
                    <span><strong>Interval:</strong> Every <?php echo $check['interval_seconds'] >= 3600 ? 
                        ($check['interval_seconds'] / 3600) . 'h' : 
                        ($check['interval_seconds'] / 60) . 'm'; ?></span>
                    <span><strong>Timeout:</strong> <?php echo $check['timeout_seconds']; ?>s</span>
                </div>
            </div>
            
            <div class="flex gap-2">
                <a href="/check_form.php?id=<?php echo $check['id']; ?>" 
                   class="px-4 py-2 bg-white/15 backdrop-blur border border-white/20 text-white rounded-lg hover:bg-white/25 transition-all">
                    Edit Check
                </a>
                <a href="/reports.php?check_id=<?php echo $check['id']; ?>" 
                   class="px-4 py-2 bg-white text-slate-600 rounded-lg hover:bg-slate-50 transition-all">
                    Generate Report
                </a>
                <a href="/checks.php" 
                   class="px-4 py-2 border border-white/30 text-white rounded-lg hover:bg-white/10 transition-all">
                    ← Back
                </a>
            </div>
        </div>
        
        <!-- Quick Stats in Header -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mt-6">
            <?php 
            $periods = ['1h' => 'Last Hour', '24h' => '24 Hours', '7d' => '7 Days', '30d' => '30 Days', '90d' => '90 Days'];
            foreach ($periods as $key => $label): 
                $stats = $uptimeStats[$key];
            ?>
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-3 hover:bg-white/20 transition-colors">
                <div class="text-slate-200 text-xs"><?php echo $label; ?></div>
                <div class="text-2xl font-bold <?php echo $stats['uptime'] >= 99.9 ? 'text-emerald-300' : 
                    ($stats['uptime'] >= 99 ? 'text-amber-300' : 'text-red-300'); ?>">
                    <?php echo $stats['uptime']; ?>%
                </div>
                <div class="text-slate-300 text-xs"><?php echo $stats['total_checks']; ?> checks</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="border-b border-gray-200">
            <nav class="flex space-x-8 px-6">
                <a href="?id=<?php echo $checkId; ?>&tab=overview" 
                   class="py-4 px-1 border-b-2 font-medium text-sm <?php echo $viewTab === 'overview' ? 'tab-active' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    Overview
                </a>
                <a href="?id=<?php echo $checkId; ?>&tab=incidents" 
                   class="py-4 px-1 border-b-2 font-medium text-sm <?php echo $viewTab === 'incidents' ? 'tab-active' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    Incidents <?php if (count(array_filter($incidents, fn($i) => $i['status'] === 'OPEN')) > 0): ?>
                        <span class="ml-2 px-2 py-1 text-xs bg-red-100 text-red-700 rounded-full">
                            <?php echo count(array_filter($incidents, fn($i) => $i['status'] === 'OPEN')); ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="?id=<?php echo $checkId; ?>&tab=results" 
                   class="py-4 px-1 border-b-2 font-medium text-sm <?php echo $viewTab === 'results' ? 'tab-active' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    Check Results
                </a>
                <a href="?id=<?php echo $checkId; ?>&tab=config" 
                   class="py-4 px-1 border-b-2 font-medium text-sm <?php echo $viewTab === 'config' ? 'tab-active' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    Configuration
                </a>
            </nav>
        </div>
        
        <div class="p-6">
            <?php if ($viewTab === 'overview'): ?>
                <!-- Overview Tab -->
                <!-- Time Range Selector -->
                <div class="flex gap-2 mb-6">
                    <?php foreach (['1h', '24h', '7d', '30d', '90d'] as $range): ?>
                        <a href="?id=<?php echo $checkId; ?>&tab=overview&range=<?php echo $range; ?>" 
                           class="px-4 py-2 rounded-lg <?php echo $timeRange === $range ? 
                               'bg-gray-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors">
                            <?php echo strtoupper($range); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                
                <!-- Response Time Chart -->
                <div class="mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Response Time Trend</h3>
                    <?php if (empty($hourlyData)): ?>
                        <div class="bg-gray-50 rounded-lg p-8 text-center">
                            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                            <p class="text-gray-500 text-lg">No data available for this time range</p>
                            <p class="text-gray-400 text-sm mt-2">Try selecting a different time period or wait for more check results</p>
                        </div>
                    <?php else: ?>
                        <div class="response-chart bg-gray-50 rounded-lg p-4">
                            <?php 
                            // Safely get max latency with fallback
                            $latencyValues = array_column($hourlyData, 'max_latency');
                            $maxLatency = !empty($latencyValues) ? max($latencyValues) : 1000;
                            if ($maxLatency <= 0) $maxLatency = 1000; // Prevent division by zero
                            
                            $chartData = array_slice($hourlyData, -48); // Show last 48 data points
                            foreach ($chartData as $data): 
                                $avgLatency = (float)($data['avg_latency'] ?? 0);
                                $height = $maxLatency > 0 ? ($avgLatency / $maxLatency) * 100 : 0;
                                $upCount = (int)($data['up_count'] ?? 0);
                                $total = (int)($data['total'] ?? 0);
                                $upPercent = $total > 0 ? ($upCount / $total) * 100 : 0;
                                $isDown = $upPercent < 100;
                            ?>
                            <div class="response-bar <?php echo $isDown ? 'down' : ''; ?>" 
                                 style="height: <?php echo max(2, $height); ?>%;"
                                 title="<?php echo date('M d H:i', strtotime($data['hour'])); ?> - Avg: <?php echo formatDuration($avgLatency); ?>, Uptime: <?php echo round($upPercent, 1); ?>%">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex justify-between mt-2 text-xs text-gray-500">
                            <span><?php echo !empty($chartData) ? date('M d H:i', strtotime($chartData[0]['hour'])) : ''; ?></span>
                            <span>Now</span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Performance Metrics Grid -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <?php $stats = $uptimeStats[$timeRange]; ?>
                    
                    <!-- Latency Distribution -->
                    <div class="metric-card">
                        <h4 class="font-semibold text-gray-900 mb-3">Response Time Distribution</h4>
                        <?php if (!empty($stats['distribution'])): ?>
                            <div class="space-y-2">
                                <?php foreach ($stats['distribution'] as $bucket): ?>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm text-gray-600"><?php echo $bucket['bucket']; ?></span>
                                        <div class="flex items-center gap-2">
                                            <div class="w-24 bg-gray-200 rounded-full h-2">
                                                <div class="h-2 rounded-full bg-gray-600" 
                                                     style="width: <?php echo ($bucket['count'] / $stats['total_checks']) * 100; ?>%"></div>
                                            </div>
                                            <span class="text-sm font-medium text-gray-700 w-12 text-right">
                                                <?php echo round(($bucket['count'] / $stats['total_checks']) * 100, 1); ?>%
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500 text-sm">No data available</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Percentile Metrics -->
                    <div class="metric-card">
                        <h4 class="font-semibold text-gray-900 mb-3">Response Time Percentiles</h4>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Median (P50)</span>
                                <span class="font-semibold"><?php echo formatDuration($stats['median_latency']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">P95</span>
                                <span class="font-semibold"><?php echo formatDuration($stats['p95_latency']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">P99</span>
                                <span class="font-semibold"><?php echo formatDuration($stats['p99_latency']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600">Std Deviation</span>
                                <span class="font-semibold"><?php echo formatDuration($stats['std_deviation']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Availability Stats -->
                    <div class="metric-card">
                        <h4 class="font-semibold text-gray-900 mb-3">Availability Summary</h4>
                        <div class="space-y-3">
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm text-gray-600">Uptime</span>
                                    <span class="font-semibold text-lg <?php echo $stats['uptime'] >= 99.9 ? 'text-green-600' : 
                                        ($stats['uptime'] >= 99 ? 'text-yellow-600' : 'text-red-600'); ?>">
                                        <?php echo $stats['uptime']; ?>%
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="h-2 rounded-full <?php echo $stats['uptime'] >= 99.9 ? 'bg-green-500' : 
                                        ($stats['uptime'] >= 99 ? 'bg-yellow-500' : 'bg-red-500'); ?>" 
                                         style="width: <?php echo $stats['uptime']; ?>%"></div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4 text-center">
                                <div class="bg-green-50 rounded-lg p-2">
                                    <div class="text-green-600 font-semibold"><?php echo $stats['up_checks']; ?></div>
                                    <div class="text-xs text-gray-600">Successful</div>
                                </div>
                                <div class="bg-red-50 rounded-lg p-2">
                                    <div class="text-red-600 font-semibold"><?php echo $stats['down_checks']; ?></div>
                                    <div class="text-xs text-gray-600">Failed</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Response Codes Distribution -->
                <?php if (!empty($responseCodesData)): ?>
                <div class="mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Response Codes Distribution</h3>
                    <div class="distribution-bar">
                        <?php 
                        $totalResponses = array_sum(array_column($responseCodesData, 'count'));
                        $colors = [
                            '200' => 'bg-green-500',
                            '201' => 'bg-green-400',
                            '204' => 'bg-green-600',
                            '301' => 'bg-blue-500',
                            '302' => 'bg-blue-400',
                            '400' => 'bg-yellow-500',
                            '401' => 'bg-orange-500',
                            '403' => 'bg-orange-600',
                            '404' => 'bg-red-400',
                            '500' => 'bg-red-500',
                            '502' => 'bg-red-600',
                            '503' => 'bg-red-700'
                        ];
                        
                        foreach ($responseCodesData as $code): 
                            $percentage = ($code['count'] / $totalResponses) * 100;
                            if ($percentage < 1) continue; // Skip very small segments
                            $bgColor = $colors[$code['http_status']] ?? 'bg-gray-500';
                        ?>
                        <div class="distribution-segment <?php echo $bgColor; ?>" 
                             style="width: <?php echo $percentage; ?>%;"
                             title="HTTP <?php echo $code['http_status']; ?>: <?php echo $code['count']; ?> (<?php echo round($percentage, 1); ?>%)">
                            <?php if ($percentage > 10): ?>
                                <?php echo $code['http_status']; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex flex-wrap gap-4 mt-3">
                        <?php foreach ($responseCodesData as $code): 
                            $percentage = ($code['count'] / $totalResponses) * 100;
                            $bgColor = $colors[$code['http_status']] ?? 'bg-gray-500';
                        ?>
                        <div class="flex items-center gap-2 text-sm">
                            <div class="w-3 h-3 rounded <?php echo $bgColor; ?>"></div>
                            <span class="text-gray-700">
                                HTTP <?php echo $code['http_status']; ?>: 
                                <strong><?php echo round($percentage, 1); ?>%</strong>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Status Timeline -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Status Changes Timeline</h3>
                    <div class="bg-gray-50 rounded-lg p-4 max-h-96 overflow-y-auto">
                        <?php if (empty($statusTimeline)): ?>
                            <p class="text-gray-500">No status changes recorded</p>
                        <?php else: ?>
                            <?php foreach ($statusTimeline as $change): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot <?php echo $change['is_up'] ? 'up' : 'down'; ?>"></div>
                                <div class="bg-white rounded-lg p-3 shadow-sm">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <span class="font-semibold <?php echo $change['is_up'] ? 'text-green-600' : 'text-red-600'; ?>">
                                                <?php echo $change['is_up'] ? 'Back Online' : 'Went Down'; ?>
                                            </span>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <?php echo date('M d, Y H:i:s', strtotime($change['started_at'])); ?>
                                            </p>
                                            <?php if (!$change['is_up'] && $change['error_message']): ?>
                                                <p class="text-xs text-red-600 mt-2">
                                                    <?php echo htmlspecialchars(substr($change['error_message'], 0, 100)); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-sm text-gray-500">
                                            <?php echo timeAgo($change['started_at']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($viewTab === 'incidents'): ?>
                <!-- Incidents Tab -->
                <div class="space-y-6">
                    <!-- Incidents Summary -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <div class="bg-red-50 rounded-lg p-4 border border-red-200">
                            <div class="text-red-600 font-semibold text-2xl">
                                <?php echo count(array_filter($incidents, fn($i) => $i['status'] === 'OPEN')); ?>
                            </div>
                            <div class="text-gray-600 text-sm">Active Incidents</div>
                        </div>
                        <div class="bg-green-50 rounded-lg p-4 border border-green-200">
                            <div class="text-green-600 font-semibold text-2xl">
                                <?php echo count(array_filter($incidents, fn($i) => $i['status'] === 'CLOSED')); ?>
                            </div>
                            <div class="text-gray-600 text-sm">Resolved</div>
                        </div>
                        <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
                            <div class="text-blue-600 font-semibold text-2xl">
                                <?php 
                                $totalDowntime = array_sum(array_column($incidents, 'duration_seconds'));
                                echo round($totalDowntime / 3600, 1); 
                                ?>h
                            </div>
                            <div class="text-gray-600 text-sm">Total Downtime</div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <div class="text-gray-600 font-semibold text-2xl">
                                <?php 
                                $avgDuration = count($incidents) > 0 ? 
                                    round($totalDowntime / count($incidents) / 60) : 0;
                                echo $avgDuration;
                                ?>m
                            </div>
                            <div class="text-gray-600 text-sm">Avg Duration</div>
                        </div>
                    </div>
                    
                    <!-- Incidents List -->
                    <?php if (empty($incidents)): ?>
                        <div class="text-center py-12 bg-gray-50 rounded-lg">
                            <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="text-gray-500 text-lg">No incidents recorded</p>
                            <p class="text-gray-400 text-sm mt-2">Your service has been running smoothly!</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($incidents as $incident): 
                                $duration = $incident['duration_seconds'];
                                $durationText = $duration < 3600 ? round($duration / 60) . ' minutes' : 
                                               round($duration / 3600, 1) . ' hours';
                            ?>
                            <div class="bg-white border border-gray-200 rounded-lg p-5 hover:shadow-md transition-shadow">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo 
                                                $incident['status'] === 'OPEN' ? 
                                                'bg-red-100 text-red-700' : 
                                                'bg-green-100 text-green-700'; ?>">
                                                <?php echo $incident['status']; ?>
                                            </span>
                                            <span class="text-gray-600">
                                                Duration: <strong><?php echo $durationText; ?></strong>
                                            </span>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <p class="text-sm text-gray-600 mb-1">Started</p>
                                                <p class="font-medium"><?php echo date('M d, Y H:i:s', strtotime($incident['started_at'])); ?></p>
                                                <?php if ($incident['down_error']): ?>
                                                    <p class="text-sm text-red-600 mt-2">
                                                        Error: <?php echo htmlspecialchars(substr($incident['down_error'], 0, 150)); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($incident['ended_at']): ?>
                                            <div>
                                                <p class="text-sm text-gray-600 mb-1">Resolved</p>
                                                <p class="font-medium"><?php echo date('M d, Y H:i:s', strtotime($incident['ended_at'])); ?></p>
                                            </div>
                                            <?php else: ?>
                                            <div>
                                                <p class="text-sm text-gray-600 mb-1">Status</p>
                                                <p class="font-medium text-red-600">Ongoing for <?php echo $durationText; ?></p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php elseif ($viewTab === 'results'): ?>
                <!-- Results Tab -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Response Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Response Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($results as $result): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php echo date('M d H:i:s', strtotime($result['started_at'])); ?>
                                    <span class="text-xs text-gray-500 block"><?php echo timeAgo($result['started_at']); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo 
                                        $result['is_up'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $result['is_up'] ? 'UP' : 'DOWN'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="font-mono <?php echo 
                                        $result['http_status'] >= 200 && $result['http_status'] < 300 ? 'text-green-600' : 
                                        ($result['http_status'] >= 400 ? 'text-red-600' : 'text-gray-900'); ?>">
                                        HTTP <?php echo $result['http_status'] ?: 'ERROR'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="font-mono <?php echo 
                                        $result['duration_ms'] < 300 ? 'text-green-600' : 
                                        ($result['duration_ms'] < 1000 ? 'text-yellow-600' : 'text-red-600'); ?>">
                                        <?php echo formatDuration($result['duration_ms']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <?php if ($result['error_message']): ?>
                                        <span class="text-red-600" title="<?php echo htmlspecialchars($result['error_message']); ?>">
                                            <?php echo htmlspecialchars(substr($result['error_message'], 0, 50)); ?>...
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <button onclick="viewResultDetails(<?php echo $result['id']; ?>)" 
                                            class="text-indigo-600 hover:text-indigo-900 font-medium">
                                        View Details
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Result Details Modal -->
                <div id="resultModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
                    <div class="flex items-center justify-center min-h-screen p-4">
                        <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                                <h3 class="text-lg font-semibold text-gray-900">Check Result Details</h3>
                                <button onclick="closeResultModal()" class="text-gray-400 hover:text-gray-600">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            <div class="px-6 py-4 overflow-y-auto max-h-[80vh]" id="resultModalContent">
                                <!-- Content will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($viewTab === 'config'): ?>
                <!-- Configuration Tab -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Request Configuration -->
                    <div class="metric-card">
                        <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                            </svg>
                            Request Configuration
                        </h4>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Method</dt>
                                <dd class="font-medium"><?php echo $check['method']; ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Timeout</dt>
                                <dd class="font-medium"><?php echo $check['timeout_seconds']; ?> seconds</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Max Redirects</dt>
                                <dd class="font-medium"><?php echo $check['max_redirects']; ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Check Interval</dt>
                                <dd class="font-medium">Every <?php echo $check['interval_seconds'] >= 3600 ? 
                                    ($check['interval_seconds'] / 3600) . ' hour(s)' : 
                                    ($check['interval_seconds'] / 60) . ' minute(s)'; ?></dd>
                            </div>
                            
                            <?php if (!empty($check['request_headers'])): ?>
                            <div class="pt-3 border-t">
                                <dt class="text-gray-600 mb-2">Request Headers</dt>
                                <dd class="bg-gray-100 rounded p-2 font-mono text-xs overflow-x-auto">
                                    <?php echo nl2br(htmlspecialchars($check['request_headers'])); ?>
                                </dd>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($check['request_body']) && $check['method'] !== 'GET'): ?>
                            <div class="pt-3 border-t">
                                <dt class="text-gray-600 mb-2">Request Body</dt>
                                <dd class="bg-gray-100 rounded p-2 font-mono text-xs overflow-x-auto">
                                    <?php echo htmlspecialchars(substr($check['request_body'], 0, 500)); ?>
                                    <?php echo strlen($check['request_body']) > 500 ? '...' : ''; ?>
                                </dd>
                            </div>
                            <?php endif; ?>
                        </dl>
                    </div>
                    
                    <!-- Validation Rules -->
                    <div class="metric-card">
                        <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Validation Rules
                        </h4>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Expected Status</dt>
                                <dd class="font-medium">HTTP <?php echo $check['expected_status']; ?></dd>
                            </div>
                            
                            <?php if (!empty($check['expected_headers'])): ?>
                            <div class="pt-3 border-t">
                                <dt class="text-gray-600 mb-2">Expected Headers</dt>
                                <dd class="bg-gray-100 rounded p-2 font-mono text-xs overflow-x-auto">
                                    <?php echo nl2br(htmlspecialchars($check['expected_headers'])); ?>
                                </dd>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($check['expected_body'])): ?>
                            <div class="pt-3 border-t">
                                <dt class="text-gray-600 mb-2">Expected Body Contains</dt>
                                <dd class="bg-gray-100 rounded p-2 font-mono text-xs overflow-x-auto">
                                    <?php echo htmlspecialchars(substr($check['expected_body'], 0, 500)); ?>
                                    <?php echo strlen($check['expected_body']) > 500 ? '...' : ''; ?>
                                </dd>
                            </div>
                            <?php endif; ?>
                        </dl>
                    </div>
                    
                    <!-- Alert Configuration -->
                    <div class="metric-card">
                        <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                            Alert Configuration
                        </h4>
                        
                        <?php if (!empty($check['alert_emails'])): ?>
                            <p class="text-sm text-gray-600 mb-3">Alert Recipients:</p>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach (explode(',', $check['alert_emails']) as $email): ?>
                                    <span class="px-3 py-1 bg-gray-100 text-gray-700 text-sm rounded-full border">
                                        <?php echo htmlspecialchars(trim($email)); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500 text-sm">No alert emails configured</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Metadata -->
                    <div class="metric-card">
                        <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Check Information
                        </h4>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Created</dt>
                                <dd class="font-medium"><?php echo date('M d, Y H:i', strtotime($check['created_at'])); ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Last Updated</dt>
                                <dd class="font-medium"><?php echo date('M d, Y H:i', strtotime($check['updated_at'])); ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Next Run</dt>
                                <dd class="font-medium"><?php echo date('M d, Y H:i:s', strtotime($check['next_run_at'])); ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Data Retention</dt>
                                <dd class="font-medium"><?php echo $check['keep_response_data'] ? 'Full Response' : 'Metrics Only'; ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Auto-refresh for overview tab
<?php if ($viewTab === 'overview'): ?>
setTimeout(function() {
    location.reload();
}, 30000); // Refresh every 30 seconds
<?php endif; ?>

// Result Details Modal Functions
function viewResultDetails(resultId) {
    // Show modal
    document.getElementById('resultModal').classList.remove('hidden');
    
    // Show loading state
    document.getElementById('resultModalContent').innerHTML = `
        <div class="flex items-center justify-center py-12">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            <span class="ml-3 text-gray-600">Loading details...</span>
        </div>
    `;
    
    // Fetch result details
    fetch(`get_result_details.php?id=${resultId}`)
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            if (data.success) {
                displayResultDetails(data.result);
            } else {
                document.getElementById('resultModalContent').innerHTML = `
                    <div class="text-center py-12">
                        <div class="text-red-500 text-xl mb-2">⚠️</div>
                        <p class="text-gray-600">Error loading result details</p>
                        <p class="text-gray-500 text-sm mt-2">${data.error || 'Unknown error occurred'}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            document.getElementById('resultModalContent').innerHTML = `
                <div class="text-center py-12">
                    <div class="text-red-500 text-xl mb-2">⚠️</div>
                    <p class="text-gray-600">Failed to load result details</p>
                    <p class="text-gray-500 text-sm mt-2">Error: ${error.message}</p>
                    <p class="text-gray-400 text-xs mt-2">Check browser console for more details</p>
                </div>
            `;
        });
}

function displayResultDetails(result) {
    const statusBadgeClass = result.is_up ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
    const statusText = result.is_up ? 'UP' : 'DOWN';
    
    let httpStatusClass = 'text-gray-900';
    if (result.http_status >= 200 && result.http_status < 300) {
        httpStatusClass = 'text-green-600';
    } else if (result.http_status >= 400) {
        httpStatusClass = 'text-red-600';
    }
    
    let durationClass = 'text-gray-900';
    if (result.duration_ms < 300) {
        durationClass = 'text-green-600';
    } else if (result.duration_ms < 1000) {
        durationClass = 'text-yellow-600';
    } else {
        durationClass = 'text-red-600';
    }
    
    const content = `
        <div class="space-y-6">
            <!-- Summary -->
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="text-sm font-medium text-gray-500">Status</label>
                        <div class="mt-1">
                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${statusBadgeClass}">
                                ${statusText}
                            </span>
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Response Code</label>
                        <div class="mt-1 font-mono text-sm ${httpStatusClass}">
                            HTTP ${result.http_status || 'ERROR'}
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Response Time</label>
                        <div class="mt-1 font-mono text-sm ${durationClass}">
                            ${formatDuration(result.duration_ms)}
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Timestamp</label>
                        <div class="mt-1 text-sm text-gray-900">
                            ${new Date(result.started_at).toLocaleString()}
                        </div>
                    </div>
                </div>
            </div>
            
            ${result.error_message ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.081 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                    Error Message
                </h4>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <pre class="text-sm text-red-800 whitespace-pre-wrap">${escapeHtml(result.error_message)}</pre>
                </div>
            </div>
            ` : ''}
            
            ${result.response_headers ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Response Headers
                </h4>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                    <pre class="text-sm text-gray-800 font-mono whitespace-pre-wrap overflow-x-auto">${escapeHtml(result.response_headers)}</pre>
                </div>
            </div>
            ` : ''}
            
            ${result.response_body ? `
            <div>
                <h4 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Response Body
                    <span class="ml-2 text-sm font-normal text-gray-500">(${result.response_body.length} characters)</span>
                </h4>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 max-h-96 overflow-y-auto">
                    <pre class="text-sm text-gray-800 font-mono whitespace-pre-wrap">${escapeHtml(result.response_body)}</pre>
                </div>
            </div>
            ` : ''}
            
            ${!result.response_headers && !result.response_body && !result.error_message ? `
            <div class="text-center py-8">
                <div class="text-gray-400 text-4xl mb-4">📄</div>
                <p class="text-gray-600 text-lg">No response data available</p>
                <p class="text-gray-500 text-sm mt-2">Response data was not stored for this result</p>
            </div>
            ` : ''}
        </div>
    `;
    
    document.getElementById('resultModalContent').innerHTML = content;
}

function closeResultModal() {
    document.getElementById('resultModal').classList.add('hidden');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDuration(ms) {
    if (ms < 1000) {
        return ms + 'ms';
    }
    return (ms / 1000).toFixed(2) + 's';
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('resultModal');
    if (event.target === modal) {
        closeResultModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeResultModal();
    }
});
</script>

<?php
$content = ob_get_clean();
renderTemplate(htmlspecialchars($check['name']) . ' - Monitoring Details', $content);
?>