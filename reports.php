<?php
/**
 * Uptime Reports Page - Simplified Version
 */

require_once 'bootstrap.php';

$auth->requireAuth();

// Get parameters
$checkId = isset($_GET['check_id']) ? (int)$_GET['check_id'] : 0;
$categoryFilter = $_GET['category'] ?? 'all';
$days = (int)($_GET['days'] ?? 90);
$view = $_GET['view'] ?? '90d';
$exportFormat = $_GET['export'] ?? '';

// Handle different time ranges
switch($view) {
    case '1h':
        $startDate = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $endDate = date('Y-m-d H:i:s');
        $days = 0;
        break;
    case 'today':
        $startDate = date('Y-m-d 00:00:00');
        $endDate = date('Y-m-d 23:59:59');
        $days = 1;
        break;
    case '7d':
        $days = 7;
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');
        break;
    case '30d':
        $days = 30;
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');
        break;
    case '90d':
        $days = 90;
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');
        break;
    default:
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$days} days"));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
}

// Get all categories for filter
$categories = $db->fetchAll("SELECT * FROM categories ORDER BY name ASC");

// Enhanced uptime color logic function
function getUptimeColorClass($uptime) {
    if ($uptime <= 50) return 'text-red-600';
    if ($uptime < 99) return 'text-orange-500';
    return 'text-green-600';
}

function getUptimeBackgroundClass($uptime) {
    if ($uptime <= 50) return 'bg-red-500';
    if ($uptime < 99) return 'bg-orange-500';
    return 'bg-green-500';
}

function getUptimeSegmentClass($uptime) {
    if ($uptime <= 50) return 'uptime-down';
    if ($uptime < 99) return 'uptime-degraded';
    return 'uptime-up';
}

// Get checks based on filters
$whereClauses = ['c.enabled = 1'];
$whereParams = [];

if ($checkId) {
    $whereClauses[] = 'c.id = ?';
    $whereParams[] = $checkId;
} elseif ($categoryFilter !== 'all') {
    if ($categoryFilter === 'uncategorized') {
        $whereClauses[] = 'c.category_id IS NULL';
    } else {
        $whereClauses[] = 'c.category_id = ?';
        $whereParams[] = (int)$categoryFilter;
    }
}

$whereClause = implode(' AND ', $whereClauses);
$checksQuery = "SELECT c.*, cat.name as category_name, cat.color as category_color 
                FROM checks c 
                LEFT JOIN categories cat ON c.category_id = cat.id 
                WHERE {$whereClause} 
                ORDER BY c.name";
$allChecks = $db->fetchAll($checksQuery, $whereParams);

// Handle CSV export
if ($exportFormat === 'csv') {
    $whereClauses = [];
    $params = [];
    
    if ($view === '1h') {
        $whereClauses[] = 'cr.started_at >= ? AND cr.started_at <= ?';
        $params[] = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $params[] = date('Y-m-d H:i:s');
    } elseif ($view === 'today') {
        $whereClauses[] = 'cr.started_at >= ? AND cr.started_at <= ?';
        $params[] = date('Y-m-d 00:00:00');
        $params[] = date('Y-m-d 23:59:59');
    } else {
        $whereClauses[] = 'cr.started_at >= ? AND cr.started_at <= ?';
        $params[] = $startDate . ' 00:00:00';
        $params[] = $endDate . ' 23:59:59';
    }
    
    if ($checkId) {
        $whereClauses[] = 'cr.check_id = ?';
        $params[] = $checkId;
    } elseif ($categoryFilter !== 'all') {
        if ($categoryFilter === 'uncategorized') {
            $whereClauses[] = 'c.category_id IS NULL';
        } else {
            $whereClauses[] = 'c.category_id = ?';
            $params[] = (int)$categoryFilter;
        }
    }
    
    $whereClauseExport = implode(' AND ', $whereClauses);
    
    $results = $db->fetchAll("
        SELECT c.name as check_name, c.url,
               cat.name as category_name,
               cr.started_at, cr.ended_at, cr.duration_ms, 
               cr.http_status, cr.is_up, cr.error_message
        FROM check_results cr 
        JOIN checks c ON cr.check_id = c.id 
        LEFT JOIN categories cat ON c.category_id = cat.id
        WHERE {$whereClauseExport}
        ORDER BY cr.started_at ASC
    ", $params);

    $filename = 'uptime-report-' . ($view === '1h' ? 'last-hour' : ($view === 'today' ? 'today' : $startDate . '-to-' . $endDate)) . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Check Name', 'Category', 'URL', 'Started At', 'Ended At', 'Duration (ms)', 'HTTP Status', 'Is Up', 'Error']);
    
    foreach ($results as $row) {
        fputcsv($output, [
            $row['check_name'],
            $row['category_name'] ?: 'Uncategorized',
            $row['url'],
            $row['started_at'],
            $row['ended_at'],
            $row['duration_ms'],
            $row['http_status'],
            $row['is_up'] ? 'Yes' : 'No',
            $row['error_message']
        ]);
    }
    
    fclose($output);
    exit;
}

// Helper function to get hourly uptime data
function getHourlyData($db, $checkId, $startDateTime, $endDateTime) {
    $data = [];
    
    if (strpos($startDateTime, ' ') === false) {
        $startDateTime = $startDateTime . ' 00:00:00';
        $endDateTime = $endDateTime . ' 23:59:59';
    }
    
    $start = new DateTime($startDateTime);
    $end = new DateTime($endDateTime);
    
    $diffHours = $end->diff($start)->h + ($end->diff($start)->days * 24);
    $interval = $diffHours <= 1 ? 'PT5M' : 'PT1H';
    
    $current = clone $start;
    while ($current <= $end) {
        $nextPeriod = clone $current;
        $nextPeriod->add(new DateInterval($interval));
        
        $periodData = $db->fetchOne("
            SELECT 
                COUNT(*) as total_checks,
                SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_checks,
                SUM(CASE WHEN is_up = 0 THEN 1 ELSE 0 END) as down_checks,
                AVG(duration_ms) as avg_latency
            FROM check_results
            WHERE check_id = ?
                AND started_at >= ?
                AND started_at < ?
        ", [$checkId, $current->format('Y-m-d H:i:s'), $nextPeriod->format('Y-m-d H:i:s')]);
        
        $uptime = $periodData['total_checks'] > 0 
            ? round(($periodData['up_checks'] / $periodData['total_checks']) * 100, 1)
            : 100;
        
        $data[] = [
            'datetime' => $current->format('Y-m-d H:i:s'),
            'period' => $interval === 'PT5M' ? $current->format('H:i') : $current->format('H:00'),
            'uptime' => $uptime,
            'total_checks' => (int)$periodData['total_checks'],
            'up_checks' => (int)$periodData['up_checks'],
            'down_checks' => (int)$periodData['down_checks'],
            'avg_latency' => round($periodData['avg_latency'] ?: 0),
            'status' => getUptimeSegmentClass($uptime)
        ];
        
        $current = $nextPeriod;
    }
    
    return $data;
}

// Helper function to get daily uptime data
function getDailyData($db, $checkId, $days) {
    $data = [];
    
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        
        $dayData = $db->fetchOne("
            SELECT 
                COUNT(*) as total_checks,
                SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_checks,
                SUM(CASE WHEN is_up = 0 THEN 1 ELSE 0 END) as down_checks,
                AVG(duration_ms) as avg_latency
            FROM check_results
            WHERE check_id = ?
                AND DATE(started_at) = ?
        ", [$checkId, $date]);
        
        $uptime = $dayData['total_checks'] > 0 
            ? round(($dayData['up_checks'] / $dayData['total_checks']) * 100, 1)
            : 100;
        
        $data[] = [
            'date' => $date,
            'uptime' => $uptime,
            'total_checks' => (int)$dayData['total_checks'],
            'up_checks' => (int)$dayData['up_checks'],
            'down_checks' => (int)$dayData['down_checks'],
            'avg_latency' => round($dayData['avg_latency'] ?: 0),
            'status' => getUptimeSegmentClass($uptime)
        ];
    }
    
    return $data;
}

// Helper function to calculate overall stats
function calculateOverallStats($db, $checkId, $startDate, $endDate) {
    return $db->fetchOne("
        SELECT 
            COUNT(*) as total_checks,
            SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_checks,
            SUM(CASE WHEN is_up = 0 THEN 1 ELSE 0 END) as down_checks,
            AVG(duration_ms) as avg_latency,
            MIN(duration_ms) as min_latency,
            MAX(duration_ms) as max_latency
        FROM check_results
        WHERE check_id = ?
            AND started_at >= ? 
            AND started_at <= ?
    ", [$checkId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
}

// Get recent incidents function
function getRecentIncidents($db, $checkId, $startDate) {
    return $db->fetchAll("
        SELECT * FROM incidents 
        WHERE check_id = ? 
            AND started_at >= ? 
        ORDER BY started_at DESC 
        LIMIT 5
    ", [$checkId, $startDate]);
}

// Start output
ob_start();
?>

<style>
    .uptime-segment {
        height: 40px;
        flex: 1;
        margin: 0 1px;
        border-radius: 4px;
        cursor: pointer;
        transition: opacity 0.2s, transform 0.1s;
        display: inline-block;
    }
    .uptime-segment:hover {
        opacity: 0.8;
        transform: scaleY(1.1);
    }
    .uptime-up { background: #10b981 !important; }
    .uptime-degraded { background: #f97316 !important; }
    .uptime-down { background: #ef4444 !important; }
    .uptime-no-data { background: #e5e7eb !important; }
    
    .uptime-bar {
        display: flex;
        gap: 1px;
        background: #f3f4f6;
        border-radius: 8px;
        padding: 4px;
        margin: 16px 0;
    }
    
    .time-filter-tab {
        padding: 8px 16px;
        border-radius: 8px;
        border: 1px solid #d1d5db;
        background: white;
        color: #6b7280;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        font-weight: 500;
        display: inline-block;
    }
    .time-filter-tab:hover {
        border-color: #6b7280;
        color: #374151;
    }
    .time-filter-tab.active {
        background: #6b7280 !important;
        color: white !important;
        border-color: #6b7280;
    }
</style>

<div class="space-y-6">
    <!-- Header with Gray Theme matching check.php -->
    <div class="bg-gradient-to-r from-slate-700 to-slate-600 rounded-xl p-6 text-white shadow-lg">
        <div class="flex justify-between items-start">
            <div class="flex-1">
                <h1 class="text-3xl font-bold mb-3">Uptime Reports</h1>
                <p class="text-slate-200 text-lg">
                    <?php if ($checkId): 
                        $selectedCheck = $db->fetchOne("SELECT name FROM checks WHERE id = ?", [$checkId]);
                        if ($selectedCheck):
                    ?>
                        Detailed performance report for: <strong><?php echo htmlspecialchars($selectedCheck['name']); ?></strong>
                    <?php else: ?>
                        Check not found or disabled
                    <?php endif; ?>
                    <?php elseif ($categoryFilter !== 'all'): 
                        if ($categoryFilter === 'uncategorized') {
                            $categoryText = 'Uncategorized';
                        } else {
                            $selectedCategory = null;
                            foreach ($categories as $cat) {
                                if ($cat['id'] == $categoryFilter) {
                                    $selectedCategory = $cat;
                                    break;
                                }
                            }
                            $categoryText = $selectedCategory ? $selectedCategory['name'] : 'Selected Category';
                        }
                    ?>
                        Performance overview for: <strong><?php echo htmlspecialchars($categoryText); ?></strong>
                    <?php else: ?>
                        System-wide performance overview for the past <?php echo $days; ?> days
                    <?php endif; ?>
                </p>
                
                <div class="flex flex-wrap gap-4 mt-4 text-sm text-slate-200">
                    <span><strong>Period:</strong> 
                        <?php 
                        if ($view === '1h') {
                            echo 'Last 1 hour';
                        } elseif ($view === 'today') {
                            echo 'Today (' . date('M d, Y') . ')';
                        } else {
                            echo date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate));
                        }
                        ?>
                    </span>
                    <span><strong>Total Checks:</strong> <?php echo count($allChecks); ?></span>
                </div>
            </div>
            
            <div class="flex gap-2">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                   class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Export CSV
                </a>
                <a href="/dashboard.php" 
                   class="px-4 py-2 border border-white/30 text-white rounded-lg hover:bg-white/10 transition-all flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                    Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Filters and Controls -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Time Range Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Time Range</label>
                <div class="flex gap-2 flex-wrap">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => '1h'])); ?>" 
                       class="time-filter-tab <?php echo $view === '1h' ? 'active' : ''; ?>">1H</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'today'])); ?>" 
                       class="time-filter-tab <?php echo $view === 'today' ? 'active' : ''; ?>">Today</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => '7d', 'days' => 7])); ?>" 
                       class="time-filter-tab <?php echo $view === '7d' ? 'active' : ''; ?>">7D</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => '30d', 'days' => 30])); ?>" 
                       class="time-filter-tab <?php echo $view === '30d' ? 'active' : ''; ?>">30D</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => '90d', 'days' => 90])); ?>" 
                       class="time-filter-tab <?php echo $view === '90d' ? 'active' : ''; ?>">90D</a>
                </div>
            </div>
            
            <!-- Category Filter -->
            <div>
                <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Filter by Category</label>
                <select id="category" name="category" 
                        onchange="this.form.submit()"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="all" <?php echo $categoryFilter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                    <option value="uncategorized" <?php echo $categoryFilter === 'uncategorized' ? 'selected' : ''; ?>>Uncategorized</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" 
                                <?php echo $categoryFilter == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Custom Date Range -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Custom Range</label>
                <div class="flex flex-col sm:flex-row gap-2">
                    <input type="date" id="start-date" name="start_date" value="<?php echo $startDate; ?>" 
                           class="w-full sm:flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
                    <input type="date" id="end-date" name="end_date" value="<?php echo $endDate; ?>" 
                           class="w-full sm:flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
                    <button type="submit" name="view" value="custom"
                            class="w-full sm:w-auto px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm whitespace-nowrap">
                        Apply
                    </button>
                </div>
            </div>
        </form>
        
        <!-- Active Filters Display -->
        <?php if ($categoryFilter !== 'all'): ?>
        <div class="mt-4 pt-4 border-t border-gray-200">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm text-gray-600">Active filters:</span>
                
                <?php 
                if ($categoryFilter === 'uncategorized') {
                    $categoryText = 'Uncategorized';
                } else {
                    $selectedCategory = null;
                    foreach ($categories as $cat) {
                        if ($cat['id'] == $categoryFilter) {
                            $selectedCategory = $cat;
                            break;
                        }
                    }
                    $categoryText = $selectedCategory ? $selectedCategory['name'] : 'Selected Category';
                }
                ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    Category: <?php echo htmlspecialchars($categoryText); ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['category' => 'all'])); ?>" 
                       class="ml-2 text-green-600 hover:text-green-800">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </a>
                </span>
                
                <a href="/reports.php" class="text-sm text-gray-500 hover:text-gray-700 underline">Clear all filters</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Checks Display -->
    <div class="space-y-6">
        <?php 
        // Define global view type
        $isHourlyView = ($view === '1h' || $view === 'today');
        ?>
        
        <?php if (empty($allChecks)): ?>
            <div class="text-center py-12 bg-gray-50 rounded-lg">
                <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                <p class="text-gray-500 text-lg">No checks found</p>
                <p class="text-gray-400 text-sm mt-2">
                    <?php 
                    if ($categoryFilter !== 'all') {
                        $categoryText = $categoryFilter === 'uncategorized' ? 'this category' : 'selected category';
                        echo "No checks in {$categoryText}. ";
                    } else {
                        echo "No checks configured. ";
                    }
                    ?>
                    <a href="/check_form.php" class="text-blue-600 underline">
                        <?php echo ($categoryFilter !== 'all') ? 'Add a new check' : 'Add your first check'; ?>
                    </a>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($allChecks as $check): 
                // Determine which data function to use based on view
                if ($isHourlyView) {
                    $uptimeData = getHourlyData($db, $check['id'], $startDate, $endDate);
                } else {
                    $uptimeData = getDailyData($db, $check['id'], $days);
                }
                
                $stats = calculateOverallStats($db, $check['id'], $startDate, $endDate);
                
                $overallUptime = $stats['total_checks'] > 0 
                    ? round(($stats['up_checks'] / $stats['total_checks']) * 100, 2) 
                    : 100;
                
                // Get current status
                $lastCheck = $db->fetchOne("
                    SELECT is_up, duration_ms, started_at 
                    FROM check_results 
                    WHERE check_id = ? 
                    ORDER BY started_at DESC 
                    LIMIT 1
                ", [$check['id']]);
                
                $currentStatus = 'Unknown';
                $statusClass = 'status-operational';
                
                if ($lastCheck) {
                    if (!$lastCheck['is_up']) {
                        $currentStatus = 'Down';
                        $statusClass = 'status-down';
                    } elseif ($lastCheck['duration_ms'] > 1000) {
                        $currentStatus = 'Degraded';
                        $statusClass = 'status-degraded';
                    } else {
                        $currentStatus = 'Operational';
                        $statusClass = 'status-operational';
                    }
                }
                
                $incidents = getRecentIncidents($db, $check['id'], $startDate);
            ?>
            
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-2">
                            <h3 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($check['name']); ?></h3>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold uppercase <?php echo $statusClass === 'status-operational' ? 'bg-green-100 text-green-700' : ($statusClass === 'status-degraded' ? 'bg-orange-100 text-orange-700' : 'bg-red-100 text-red-700'); ?>">
                                <?php echo $currentStatus; ?>
                            </span>
                            
                            <?php if ($check['category_name']): ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold mr-2 border" 
                                      style="background-color: <?php echo $check['category_color']; ?>20; color: <?php echo $check['category_color']; ?>; border-color: <?php echo $check['category_color']; ?>40;">
                                    <?php echo htmlspecialchars($check['category_name']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($check['url']); ?></p>
                    </div>
                    
                    <div class="flex items-center gap-6">
                        <!-- Uptime Circle -->
                        <div class="w-32 h-32 rounded-full flex items-center justify-center flex-col text-white font-bold" style="background-color: <?php echo $overallUptime <= 50 ? '#ef4444' : ($overallUptime < 99 ? '#f97316' : '#10b981'); ?>;">
                            <div class="text-2xl font-bold"><?php echo $overallUptime; ?>%</div>
                            <div class="text-xs opacity-90">uptime</div>
                        </div>
                        
                        <!-- Actions -->
                        <div class="flex gap-2">
                            <a href="/check.php?id=<?php echo $check['id']; ?>" 
                               class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-all text-sm">
                                View Details
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Uptime Timeline -->
                <div class="uptime-bar" data-check-id="<?php echo $check['id']; ?>">
                    <?php foreach ($uptimeData as $dataPoint): 
                        if ($isHourlyView) {
                            $tooltip = ($view === '1h' ? 'Time: ' : 'Hour: ') . $dataPoint['period'] . '\\n';
                            $tooltip .= 'Uptime: ' . $dataPoint['uptime'] . '%\\n';
                            $tooltip .= 'Checks: ' . $dataPoint['total_checks'];
                            if ($dataPoint['down_checks'] > 0) {
                                $tooltip .= '\\nFailures: ' . $dataPoint['down_checks'];
                            }
                            if ($dataPoint['avg_latency'] > 0) {
                                $tooltip .= '\\nAvg: ' . $dataPoint['avg_latency'] . 'ms';
                            }
                            $dataAttr = 'data-datetime="' . $dataPoint['datetime'] . '"';
                        } else {
                            $tooltip = date('M j, Y', strtotime($dataPoint['date'])) . '\\n';
                            $tooltip .= 'Uptime: ' . $dataPoint['uptime'] . '%\\n';
                            $tooltip .= 'Checks: ' . $dataPoint['total_checks'];
                            if ($dataPoint['down_checks'] > 0) {
                                $tooltip .= '\\nFailures: ' . $dataPoint['down_checks'];
                            }
                            if ($dataPoint['avg_latency'] > 0) {
                                $tooltip .= '\\nAvg: ' . $dataPoint['avg_latency'] . 'ms';
                            }
                            $dataAttr = 'data-date="' . $dataPoint['date'] . '"';
                        }
                    ?>
                        <div class="uptime-segment <?php echo $dataPoint['status']; ?>" 
                             <?php echo $dataAttr; ?>
                             title="<?php echo $tooltip; ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Timeline Labels -->
                <div class="flex justify-between text-xs text-gray-500 mt-2">
                    <?php if ($view === '1h'): ?>
                        <span>1 hour ago</span>
                        <span>Now</span>
                    <?php elseif ($view === 'today'): ?>
                        <span>00:00</span>
                        <span>23:59</span>
                    <?php else: ?>
                        <span><?php echo $days; ?> days ago</span>
                        <span>Today</span>
                    <?php endif; ?>
                </div>
                
                <!-- Stats Grid -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm hover:shadow-md transition-shadow">
                        <div class="text-2xl font-bold <?php echo getUptimeColorClass($overallUptime); ?>">
                            <?php echo $overallUptime; ?>%
                        </div>
                        <div class="text-sm text-gray-600">Overall Uptime</div>
                        <div class="text-xs text-gray-500"><?php echo $stats['total_checks']; ?> total checks</div>
                    </div>
                    
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm hover:shadow-md transition-shadow">
                        <div class="text-2xl font-bold text-green-600"><?php echo $stats['up_checks']; ?></div>
                        <div class="text-sm text-gray-600">Successful</div>
                        <div class="text-xs text-gray-500">checks passed</div>
                    </div>
                    
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm hover:shadow-md transition-shadow">
                        <div class="text-2xl font-bold text-red-600"><?php echo $stats['down_checks']; ?></div>
                        <div class="text-sm text-gray-600">Failed</div>
                        <div class="text-xs text-gray-500">checks failed</div>
                    </div>
                    
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm hover:shadow-md transition-shadow">
                        <div class="text-2xl font-bold text-blue-600"><?php echo formatDuration($stats['avg_latency'] ?: 0); ?></div>
                        <div class="text-sm text-gray-600">Avg Response</div>
                        <div class="text-xs text-gray-500">
                            Min: <?php echo formatDuration($stats['min_latency'] ?: 0); ?> | 
                            Max: <?php echo formatDuration($stats['max_latency'] ?: 0); ?>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Incidents -->
                <?php if (!empty($incidents)): ?>
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <h4 class="text-sm font-semibold text-gray-900 mb-3">Recent Incidents (<?php echo count($incidents); ?>)</h4>
                    <div class="space-y-2">
                        <?php foreach ($incidents as $incident): 
                            $duration = '';
                            if ($incident['ended_at']) {
                                $start = strtotime($incident['started_at']);
                                $end = strtotime($incident['ended_at']);
                                $diff = $end - $start;
                                $duration = $diff < 3600 ? floor($diff / 60) . 'min' : floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'min';
                            } else {
                                $duration = 'Ongoing';
                            }
                        ?>
                            <div class="flex justify-between items-center text-sm">
                                <div>
                                    <span class="text-red-600">●</span>
                                    <span class="text-gray-700 ml-2"><?php echo date('M j, H:i', strtotime($incident['started_at'])); ?></span>
                                    <?php if ($incident['ended_at']): ?>
                                        <span class="text-gray-500">- <?php echo date('H:i', strtotime($incident['ended_at'])); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-gray-600"><?php echo $duration; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Enhanced tooltip handling for uptime segments
document.querySelectorAll('.uptime-segment').forEach(segment => {
    segment.addEventListener('click', function() {
        const checkId = this.closest('.uptime-bar').getAttribute('data-check-id');
        const date = this.getAttribute('data-date');
        const datetime = this.getAttribute('data-datetime');
        
        if (date && checkId) {
            window.location.href = `/check.php?id=${checkId}&tab=overview&range=24h&date=${date}`;
        } else if (datetime && checkId) {
            const dateOnly = datetime.split(' ')[0];
            window.location.href = `/check.php?id=${checkId}&tab=overview&range=1h&date=${dateOnly}`;
        }
    });
    
    segment.addEventListener('mouseenter', function() {
        this.style.transform = 'scaleY(1.2)';
        this.style.zIndex = '10';
    });
    
    segment.addEventListener('mouseleave', function() {
        this.style.transform = 'scaleY(1)';
        this.style.zIndex = '1';
    });
});

// Auto-refresh for current data
setInterval(() => {
    const urlParams = new URLSearchParams(window.location.search);
    const view = urlParams.get('view') || '90d';
    
    if (['1h', 'today', '7d'].includes(view)) {
        const refreshInterval = view === '1h' ? 30000 : view === 'today' ? 60000 : 300000;
        setTimeout(() => location.reload(), refreshInterval);
    }
}, 0);
</script>

<?php
$content = ob_get_clean();
renderTemplate('Uptime Reports', $content);
?>