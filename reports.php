<?php
require_once 'bootstrap.php';

$exportFormat = $_GET['export'] ?? null;
$checkId = $_GET['check_id'] ?? null;
$categoryFilter = $_GET['category'] ?? 'all';
$view = $_GET['view'] ?? '90days'; // today, date, 7days, 30days, 90days
$specificDate = $_GET['date'] ?? date('Y-m-d');

// Get all categories for filter dropdown
$categories = [];
try {
    $categories = $db->fetchAll("
        SELECT c.*, 
               COUNT(DISTINCT ch.id) as active_checks_count
        FROM categories c
        LEFT JOIN checks ch ON c.id = ch.category_id AND ch.enabled = 1
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
} catch (Exception $e) {
    // Categories table might not exist yet
    error_log("Categories query failed: " . $e->getMessage());
}

// Determine date range based on view
switch($view) {
    case 'today':
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        $days = 1;
        $isHourlyView = true;
        break;
    case 'date':
        $startDate = $specificDate;
        $endDate = $specificDate;
        $days = 1;
        $isHourlyView = true;
        break;
    case '7days':
        $days = 7;
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');
        $isHourlyView = false;
        break;
    case '30days':
        $days = 30;
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');
        $isHourlyView = false;
        break;
    case '90days':
    default:
        $days = 90;
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d');
        $isHourlyView = false;
        break;
}

// Build WHERE clause for category filter
$whereClause = '';
$whereParams = [];

if ($categoryFilter !== 'all') {
    if ($categoryFilter === 'uncategorized') {
        $whereClause = 'WHERE category_id IS NULL';
    } else {
        $whereClause = 'WHERE category_id = ?';
        $whereParams[] = (int)$categoryFilter;
    }
}

// Get checks based on category filter
$checksQuery = 'SELECT id, name, url, category_id FROM checks ' . $whereClause . ' ORDER BY name';
$allChecks = $db->fetchAll($checksQuery, $whereParams);

// Handle CSV export
if ($exportFormat === 'csv') {
    $whereClauses = ['cr.started_at >= ? AND cr.started_at <= ?'];
    $params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
    
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

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="uptime-report-' . $startDate . '-to-' . $endDate . '.csv"');
    
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

// Get hourly data for single day view
function getHourlyData($db, $checkId, $date) {
    $data = [];
    
    for ($hour = 0; $hour < 24; $hour++) {
        $hourStr = str_pad($hour, 2, '0', STR_PAD_LEFT);
        $startTime = $date . ' ' . $hourStr . ':00:00';
        $endTime = $date . ' ' . $hourStr . ':59:59';
        
        $hourData = $db->fetchOne("
            SELECT 
                COUNT(*) as total_checks,
                SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_checks,
                SUM(CASE WHEN is_up = 0 THEN 1 ELSE 0 END) as down_checks,
                AVG(duration_ms) as avg_latency,
                MAX(duration_ms) as max_latency,
                MIN(CASE WHEN is_up = 0 THEN 1 ELSE 0 END) as had_downtime
            FROM check_results
            WHERE check_id = ?
                AND started_at >= ?
                AND started_at <= ?
        ", [$checkId, $startTime, $endTime]);
        
        $status = 'none';
        $uptime = 0;
        
        if ($hourData['total_checks'] > 0) {
            $uptime = ($hourData['up_checks'] / $hourData['total_checks']) * 100;
            
            if ($hourData['down_checks'] > 0) {
                $status = 'down';
            } elseif ($hourData['avg_latency'] > 1000) {
                $status = 'slow';
            } else {
                $status = 'up';
            }
        }
        
        $data[] = [
            'hour' => $hour,
            'time' => $hourStr . ':00',
            'status' => $status,
            'uptime' => round($uptime, 2),
            'total_checks' => $hourData['total_checks'] ?? 0,
            'down_checks' => $hourData['down_checks'] ?? 0,
            'avg_latency' => round($hourData['avg_latency'] ?? 0)
        ];
    }
    
    return $data;
}

// Get daily data for multi-day view
function getDailyData($db, $checkId, $days) {
    $data = [];
    $today = new DateTime();
    
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = (clone $today)->modify("-{$i} days");
        $dateStr = $date->format('Y-m-d');
        
        $dayData = $db->fetchOne("
            SELECT 
                COUNT(*) as total_checks,
                SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_checks,
                SUM(CASE WHEN is_up = 0 THEN 1 ELSE 0 END) as down_checks,
                AVG(duration_ms) as avg_latency,
                MAX(duration_ms) as max_latency
            FROM check_results
            WHERE check_id = ?
                AND DATE(started_at) = ?
        ", [$checkId, $dateStr]);
        
        $status = 'none';
        $uptime = 0;
        
        if ($dayData['total_checks'] > 0) {
            $uptime = ($dayData['up_checks'] / $dayData['total_checks']) * 100;
            
            if ($dayData['down_checks'] > 0) {
                $status = 'down';
            } elseif ($dayData['avg_latency'] > 1000) {
                $status = 'slow';
            } else {
                $status = 'up';
            }
        }
        
        $data[] = [
            'date' => $dateStr,
            'status' => $status,
            'uptime' => round($uptime, 2),
            'total_checks' => $dayData['total_checks'] ?? 0,
            'down_checks' => $dayData['down_checks'] ?? 0,
            'avg_latency' => round($dayData['avg_latency'] ?? 0)
        ];
    }
    
    return $data;
}

// Calculate overall statistics
function calculateOverallStats($db, $checkId, $startDate, $endDate) {
    return $db->fetchOne("
        SELECT 
            COUNT(*) as total_checks,
            SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_checks,
            AVG(duration_ms) as avg_latency,
            MIN(duration_ms) as min_latency,
            MAX(duration_ms) as max_latency,
            COUNT(DISTINCT DATE(started_at)) as days_monitored
        FROM check_results
        WHERE check_id = ?
            AND started_at >= ?
            AND started_at <= ?
    ", [$checkId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
}

// Get recent incidents for display
function getRecentIncidents($db, $checkId, $date) {
    return $db->fetchAll("
        SELECT i.*, 
               cr1.started_at as down_time,
               cr2.started_at as up_time
        FROM incidents i
        LEFT JOIN check_results cr1 ON i.opened_by_result_id = cr1.id
        LEFT JOIN check_results cr2 ON i.closed_by_result_id = cr2.id
        WHERE i.check_id = ?
            AND DATE(i.started_at) = ?
        ORDER BY i.started_at DESC
        LIMIT 5
    ", [$checkId, $date]);
}

// Get category info for display
$currentCategoryName = 'All Categories';
if ($categoryFilter !== 'all') {
    if ($categoryFilter === 'uncategorized') {
        $currentCategoryName = 'Uncategorized';
    } else {
        $categoryInfo = $db->fetchOne('SELECT name FROM categories WHERE id = ?', [$categoryFilter]);
        if ($categoryInfo) {
            $currentCategoryName = $categoryInfo['name'];
        }
    }
}

$content = '
<style>
    .uptime-bar {
        display: flex;
        gap: 1px;
        height: 40px;
        margin: 8px 0;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .uptime-segment {
        flex: 1;
        min-width: 2px;
        cursor: pointer;
        transition: transform 0.2s, opacity 0.2s;
        position: relative;
    }
    
    .uptime-segment:hover {
        transform: scaleY(1.2);
        z-index: 10;
        opacity: 0.9;
    }
    
    .uptime-segment.up {
        background-color: #10b981;
    }
    
    .uptime-segment.down {
        background-color: #ef4444;
    }
    
    .uptime-segment.slow {
        background-color: #f59e0b;
    }
    
    .uptime-segment.none {
        background-color: #e5e7eb;
    }
    
    .status-legend {
        display: flex;
        gap: 20px;
        margin: 10px 0;
        font-size: 14px;
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .legend-color {
        width: 16px;
        height: 16px;
        border-radius: 2px;
    }
    
    .check-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .check-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    
    .check-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }
    
    .check-url {
        font-size: 14px;
        color: #6b7280;
        margin-top: 2px;
    }
    
    .uptime-percentage {
        font-size: 24px;
        font-weight: bold;
    }
    
    .uptime-high { color: #10b981; }
    .uptime-medium { color: #f59e0b; }
    .uptime-low { color: #ef4444; }
    
    .timeline-labels {
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        color: #6b7280;
        margin-top: 5px;
    }
    
    .hour-labels {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: #9ca3af;
        margin-top: 5px;
    }
    
    .tooltip {
        position: absolute;
        background: #1f2937;
        color: white;
        padding: 10px;
        border-radius: 6px;
        font-size: 12px;
        pointer-events: none;
        z-index: 1000;
        display: none;
        box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        max-width: 250px;
    }
    
    .current-status {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 500;
    }
    
    .status-operational {
        background-color: #d1fae5;
        color: #065f46;
    }
    
    .status-degraded {
        background-color: #fed7aa;
        color: #92400e;
    }
    
    .status-down {
        background-color: #fee2e2;
        color: #991b1b;
    }
    
    .view-selector {
        display: flex;
        gap: 8px;
    }
    
    .view-btn {
        padding: 6px 12px;
        border: 1px solid #d1d5db;
        background: white;
        color: #6b7280;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 14px;
    }
    
    .view-btn:hover {
        border-color: #9ca3af;
        color: #374151;
    }
    
    .view-btn.active {
        background: #4f46e5;
        color: white;
        border-color: #4f46e5;
    }
    
    .incident-list {
        margin-top: 10px;
        padding: 10px;
        background: #f9fafb;
        border-radius: 6px;
        font-size: 13px;
    }
    
    .incident-item {
        padding: 5px 0;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .incident-item:last-child {
        border-bottom: none;
    }
</style>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Uptime Report</h1>
            <p class="text-gray-600 mt-1">';

// Display appropriate subtitle based on view and category
if ($categoryFilter !== 'all') {
    $content .= 'Category: ' . htmlspecialchars($currentCategoryName) . ' • ';
}

if ($view === 'today') {
    $content .= 'Today\'s hourly performance - ' . date('F j, Y');
} elseif ($view === 'date') {
    $content .= 'Hourly performance for ' . date('F j, Y', strtotime($specificDate));
} else {
    $content .= 'Monitor performance over the past ' . $days . ' days';
}

$content .= '</p>
        </div>
        <div class="flex gap-3 items-center">
            <input type="date" id="specificDate" value="' . $specificDate . '" 
                   onchange="viewSpecificDate(this.value)"
                   class="px-3 py-2 border border-gray-300 rounded-md"
                   max="' . date('Y-m-d') . '">
            <a href="?view=' . $view . '&category=' . $categoryFilter . '&export=csv" 
               class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                Export CSV
            </a>
        </div>
    </div>

    <!-- Category Filter -->
    <div class="bg-white p-4 rounded-lg shadow">
        <form method="GET" class="flex gap-4 items-end">
            <input type="hidden" name="view" value="' . $view . '">
            ' . ($view === 'date' ? '<input type="hidden" name="date" value="' . $specificDate . '">' : '') . '
            
            <div class="flex-1">
                <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Filter by Category</label>
                <select id="category" name="category" onchange="this.form.submit()"
                        class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="all"' . ($categoryFilter === 'all' ? ' selected' : '') . '>All Categories</option>';

if (!empty($categories)) {
    foreach ($categories as $category) {
        $content .= '<option value="' . $category['id'] . '"' . 
                    ($categoryFilter == $category['id'] ? ' selected' : '') . '>' . 
                    htmlspecialchars($category['name']) . 
                    ' (' . $category['active_checks_count'] . ' checks)</option>';
    }
}

// Count uncategorized checks
$uncategorizedCount = $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE category_id IS NULL AND enabled = 1") ?: 0;
if ($uncategorizedCount > 0 || $categoryFilter === 'uncategorized') {
    $content .= '<option value="uncategorized"' . ($categoryFilter === 'uncategorized' ? ' selected' : '') . '>Uncategorized (' . $uncategorizedCount . ' checks)</option>';
}

$content .= '
                </select>
            </div>
            
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                Apply Filter
            </button>
        </form>
    </div>

    <!-- View Selector Buttons -->
    <div class="view-selector">
        <button class="view-btn ' . ($view === 'today' ? 'active' : '') . '" 
                onclick="changeView(\'today\')">Today</button>
        <button class="view-btn ' . ($view === '7days' ? 'active' : '') . '" 
                onclick="changeView(\'7days\')">Last 7 Days</button>
        <button class="view-btn ' . ($view === '30days' ? 'active' : '') . '" 
                onclick="changeView(\'30days\')">Last 30 Days</button>
        <button class="view-btn ' . ($view === '90days' ? 'active' : '') . '" 
                onclick="changeView(\'90days\')">Last 90 Days</button>
    </div>

    <!-- Status Legend -->
    <div class="status-legend">
        <div class="legend-item">
            <div class="legend-color" style="background-color: #10b981;"></div>
            <span>Operational</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background-color: #f59e0b;"></div>
            <span>Slow Response (>1s)</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background-color: #ef4444;"></div>
            <span>Downtime</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background-color: #e5e7eb;"></div>
            <span>No Data</span>
        </div>
    </div>';

// Display message if no checks in selected category
if (empty($allChecks)) {
    $content .= '
    <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded">
        <p>No checks found in the selected category. Please select a different category or <a href="/check_form.php" class="text-yellow-900 underline">add a new check</a>.</p>
    </div>';
} else {
    // Get category info for each check
    $checkCategories = [];
    if (!empty($allChecks)) {
        $checkIds = array_column($allChecks, 'id');
        if (!empty($checkIds)) {
            $placeholders = str_repeat('?,', count($checkIds) - 1) . '?';
            $categoryData = $db->fetchAll("
                SELECT c.id, c.category_id, cat.name as category_name, cat.color 
                FROM checks c
                LEFT JOIN categories cat ON c.category_id = cat.id
                WHERE c.id IN ({$placeholders})
            ", $checkIds);
            
            foreach ($categoryData as $catData) {
                $checkCategories[$catData['id']] = $catData;
            }
        }
    }

    // Display uptime visualization for each check
    foreach ($allChecks as $check) {
        if ($isHourlyView) {
            $uptimeData = getHourlyData($db, $check['id'], $startDate);
        } else {
            $uptimeData = getDailyData($db, $check['id'], $days);
        }
        
        $stats = calculateOverallStats($db, $check['id'], $startDate, $endDate);
        
        $overallUptime = $stats['total_checks'] > 0 
            ? round(($stats['up_checks'] / $stats['total_checks']) * 100, 2) 
            : 0;
        
        // Determine uptime color class
        $uptimeClass = 'uptime-high';
        if ($overallUptime < 95) $uptimeClass = 'uptime-medium';
        if ($overallUptime < 90) $uptimeClass = 'uptime-low';
        
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
        
        // Get incidents for the period
        $incidents = getRecentIncidents($db, $check['id'], $startDate);
        
        $incidentText = '';
        if ($view === 'today' || $view === 'date') {
            $dateLabel = $view === 'today' ? 'today' : 'this day';
            if (count($incidents) > 0) {
                $openIncidents = array_filter($incidents, fn($i) => $i['status'] === 'OPEN');
                if (count($openIncidents) > 0) {
                    $incidentText = '<span class="text-red-600 text-sm">⚠️ ' . count($openIncidents) . ' active incident(s)</span>';
                } else {
                    $incidentText = '<span class="text-yellow-600 text-sm"> ' . count($incidents) . ' resolved incident(s) ' . $dateLabel . '</span>';
                }
            } else {
                $incidentText = '<span class="text-green-600 text-sm">✓ No downtime recorded ' . $dateLabel . '</span>';
            }
        }
        
        // Get category badge
        $categoryBadge = '';
        if (isset($checkCategories[$check['id']])) {
            $catInfo = $checkCategories[$check['id']];
            if (!empty($catInfo['category_name'])) {
                $categoryBadge = '
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ml-2" 
                      style="background-color: ' . ($catInfo['color'] ?? '#6B7280') . '20; 
                             color: ' . ($catInfo['color'] ?? '#6B7280') . ';">
                    ' . htmlspecialchars($catInfo['category_name']) . '
                </span>';
            }
        }
        
        $content .= '
        <div class="check-card">
            <div class="check-header">
                <div>
                    <div class="flex items-center">
                        <div class="check-title">' . htmlspecialchars($check['name']) . '</div>
                        ' . $categoryBadge . '
                    </div>
                    <div class="check-url">' . htmlspecialchars($check['url']) . '</div>
                </div>
                <div class="text-right">
                    <span class="current-status ' . $statusClass . '">' . $currentStatus . '</span>
                    <div class="uptime-percentage ' . $uptimeClass . ' mt-2">' . $overallUptime . '%</div>
                    <div class="text-xs text-gray-500">uptime</div>
                </div>
            </div>
            
            <div class="uptime-bar" data-check-id="' . $check['id'] . '">';
        
        if ($isHourlyView) {
            // Hourly view
            foreach ($uptimeData as $hour) {
                $tooltip = '<strong>' . $hour['time'] . ':00 - ' . $hour['time'] . ':59</strong><br>';
                $tooltip .= 'Uptime: ' . $hour['uptime'] . '%<br>';
                $tooltip .= 'Checks: ' . $hour['total_checks'] . '<br>';
                if ($hour['down_checks'] > 0) {
                    $tooltip .= '<span style="color:#ef4444">Failures: ' . $hour['down_checks'] . '</span><br>';
                }
                if ($hour['avg_latency'] > 0) {
                    $latencyColor = $hour['avg_latency'] > 1000 ? '#f59e0b' : '#10b981';
                    $tooltip .= '<span style="color:' . $latencyColor . '">Avg: ' . $hour['avg_latency'] . 'ms</span>';
                }
                
                $content .= '<div class="uptime-segment ' . $hour['status'] . '" 
                                  data-hour="' . $hour['hour'] . '" 
                                  data-tooltip="' . htmlspecialchars($tooltip) . '"></div>';
            }
        } else {
            // Daily view
            foreach ($uptimeData as $day) {
                $tooltip = '<strong>' . date('M j, Y', strtotime($day['date'])) . '</strong><br>';
                $tooltip .= 'Uptime: ' . $day['uptime'] . '%<br>';
                $tooltip .= 'Checks: ' . $day['total_checks'] . '<br>';
                if ($day['down_checks'] > 0) {
                    $tooltip .= '<span style="color:#ef4444">Failures: ' . $day['down_checks'] . '</span><br>';
                }
                if ($day['avg_latency'] > 0) {
                    $latencyColor = $day['avg_latency'] > 1000 ? '#f59e0b' : '#10b981';
                    $tooltip .= '<span style="color:' . $latencyColor . '">Avg: ' . $day['avg_latency'] . 'ms</span>';
                }
                
                $content .= '<div class="uptime-segment ' . $day['status'] . '" 
                                  data-date="' . $day['date'] . '" 
                                  data-tooltip="' . htmlspecialchars($tooltip) . '"></div>';
            }
        }
        
        $content .= '
            </div>';
        
        // Add timeline labels
        if ($isHourlyView) {
            $content .= '
            <div class="hour-labels">
                <span>00:00</span>
                <span>06:00</span>
                <span>12:00</span>
                <span>18:00</span>
                <span>23:59</span>
            </div>';
        } else {
            $content .= '
            <div class="timeline-labels">
                <span>' . $days . ' days ago</span>
                <span>Today</span>
            </div>';
        }
        
        // Display period stats and incidents
        $content .= '
            <div class="mt-3 flex justify-between items-center">
                <div class="text-sm text-gray-600">';
        
        if ($stats['total_checks'] > 0) {
            $content .= '
                    <strong>Period Stats:</strong> 
                    ' . $stats['total_checks'] . ' checks • 
                    Avg: ' . round($stats['avg_latency']) . 'ms • 
                    Min: ' . round($stats['min_latency']) . 'ms • 
                    Max: ' . round($stats['max_latency']) . 'ms';
        } else {
            $content .= 'No data available for this period';
        }
        
        $content .= '
                </div>
                <div>' . $incidentText . '</div>
            </div>';
        
        // Show incident details for single day views
        if (($isHourlyView && count($incidents) > 0)) {
            $content .= '
            <div class="incident-list">
                <div class="font-semibold text-gray-700 mb-2">Incident Details:</div>';
            
            foreach ($incidents as $incident) {
                $duration = 'Ongoing';
                if ($incident['ended_at']) {
                    $start = strtotime($incident['started_at']);
                    $end = strtotime($incident['ended_at']);
                    $diff = $end - $start;
                    $duration = $diff < 3600 ? floor($diff / 60) . ' minutes' : floor($diff / 3600) . ' hours';
                }
                
                $content .= '
                <div class="incident-item">
                    <span class="text-red-600">↓</span> 
                    ' . date('H:i', strtotime($incident['down_time'])) . ' - ';
                
                if ($incident['up_time']) {
                    $content .= '<span class="text-green-600">↑</span> ' . date('H:i', strtotime($incident['up_time']));
                    $content .= ' <span class="text-gray-500">(' . $duration . ')</span>';
                } else {
                    $content .= '<span class="text-yellow-600">Ongoing</span>';
                }
                
                $content .= '
                </div>';
            }
            
            $content .= '
            </div>';
        }
        
        $content .= '
        </div>';
    }
}

$content .= '
</div>

<div class="tooltip" id="tooltip"></div>

<script>
function changeView(view) {
    const params = new URLSearchParams(window.location.search);
    params.set("view", view);
    if (view !== "date") {
        params.delete("date");
    }
    window.location.href = "?" + params.toString();
}

function viewSpecificDate(date) {
    const params = new URLSearchParams(window.location.search);
    params.set("view", "date");
    params.set("date", date);
    window.location.href = "?" + params.toString();
}

// Tooltip functionality
document.querySelectorAll(".uptime-segment").forEach(segment => {
    segment.addEventListener("mouseenter", function(e) {
        const tooltip = document.getElementById("tooltip");
        const html = this.getAttribute("data-tooltip");
        
        if (html) {
            tooltip.innerHTML = html;
            tooltip.style.display = "block";
            
            const rect = this.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();
            
            // Position tooltip above the bar
            let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
            let top = rect.top - tooltipRect.height - 10;
            
            // Adjust if tooltip goes off screen
            if (left < 10) left = 10;
            if (left + tooltipRect.width > window.innerWidth - 10) {
                left = window.innerWidth - tooltipRect.width - 10;
            }
            if (top < 10) {
                top = rect.bottom + 10; // Show below if no space above
            }
            
            tooltip.style.left = left + "px";
            tooltip.style.top = top + "px";
        }
    });
    
    segment.addEventListener("mouseleave", function() {
        document.getElementById("tooltip").style.display = "none";
    });
    
    segment.addEventListener("click", function() {
        const checkId = this.closest(".uptime-bar").getAttribute("data-check-id");
        const date = this.getAttribute("data-date");
        const hour = this.getAttribute("data-hour");
        
        if (date) {
            // For daily view, go to that specific date in hourly view
            const params = new URLSearchParams(window.location.search);
            params.set("view", "date");
            params.set("date", date);
            window.location.href = "?" + params.toString();
        } else if (hour !== null) {
            // For hourly view, go to check details with time filter
            window.location.href = "/check.php?id=" + checkId + "&date=' . $startDate . '&hour=" + hour;
        }
    });
});

// Auto-refresh for today view
' . ($view === 'today' ? '
setTimeout(function() {
    if (window.location.href.indexOf("view=today") > -1) {
        window.location.reload();
    }
}, 60000); // Refresh every minute for today view
' : '') . '
</script>';

renderTemplate('Uptime Report', $content);