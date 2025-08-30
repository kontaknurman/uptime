<?php
/**
 * All Checks Page - List View Only
 */

require_once 'bootstrap.php';

$auth->requireAuth();

// Handle actions - keeping all original functionality
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'delete':
            $checkId = (int)($_POST['check_id'] ?? 0);
            if ($checkId) {
                try {
                    $db->delete('checks', 'id = ?', [$checkId]);
                    $message = '<div class="mb-4 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg">
                        <div class="flex">
                            <svg class="w-5 h-5 text-green-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <p class="text-green-800 font-medium">Check deleted successfully.</p>
                        </div>
                    </div>';
                } catch (Exception $e) {
                    $message = '<div class="mb-4 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg">
                        <div class="flex">
                            <svg class="w-5 h-5 text-red-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            <p class="text-red-800 font-medium">Failed to delete check: ' . htmlspecialchars($e->getMessage()) . '</p>
                        </div>
                    </div>';
                }
            }
            break;
            
        case 'toggle':
            $checkId = (int)($_POST['check_id'] ?? 0);
            if ($checkId) {
                $check = $db->fetchOne("SELECT enabled FROM checks WHERE id = ?", [$checkId]);
                if ($check) {
                    $newStatus = $check['enabled'] ? 0 : 1;
                    $db->update('checks', ['enabled' => $newStatus], 'id = ?', [$checkId]);
                    $statusText = $newStatus ? 'enabled' : 'disabled';
                    $message = '<div class="mb-4 p-4 bg-blue-50 border-l-4 border-blue-500 rounded-lg">
                        <div class="flex">
                            <svg class="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                            <p class="text-blue-800 font-medium">Check ' . $statusText . ' successfully.</p>
                        </div>
                    </div>';
                }
            }
            break;
            
        case 'bulk_action':
            $selectedChecks = $_POST['selected_checks'] ?? [];
            $bulkAction = $_POST['bulk_action_type'] ?? '';
            
            if (!empty($selectedChecks) && !empty($bulkAction)) {
                $count = 0;
                foreach ($selectedChecks as $checkId) {
                    $checkId = (int)$checkId;
                    if ($bulkAction === 'enable') {
                        $db->update('checks', ['enabled' => 1], 'id = ?', [$checkId]);
                        $count++;
                    } elseif ($bulkAction === 'disable') {
                        $db->update('checks', ['enabled' => 0], 'id = ?', [$checkId]);
                        $count++;
                    } elseif ($bulkAction === 'delete') {
                        $db->delete('checks', 'id = ?', [$checkId]);
                        $count++;
                    }
                }
                $message = '<div class="mb-4 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg">
                    <p class="text-green-800 font-medium">Bulk action completed for ' . $count . ' checks.</p>
                </div>';
            }
            break;
            
        case 'create_category':
            header('Content-Type: application/json');
            
            $response = ['success' => false];
            
            $name = trim($_POST['name'] ?? '');
            $color = $_POST['color'] ?? '#6B7280';
            $icon = $_POST['icon'] ?? 'folder';
            $description = trim($_POST['description'] ?? '');
            
            if (empty($name)) {
                $response['error'] = 'Category name is required';
            } else if (strlen($name) > 50) {
                $response['error'] = 'Category name must be less than 50 characters';
            } else {
                if (!preg_match('/^#[0-9A-F]{6}$/i', $color)) {
                    $color = '#6B7280';
                }
                
                try {
                    $existing = $db->fetchOne('SELECT id FROM categories WHERE name = ?', [$name]);
                    if ($existing) {
                        $response['error'] = 'Category with this name already exists';
                    } else {
                        $categoryId = $db->insert('categories', [
                            'name' => $name,
                            'color' => $color,
                            'icon' => $icon,
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
}

// Get all categories with enhanced stats
$categories = [];
try {
    $categories = $db->fetchAll("
        SELECT c.*, 
               COALESCE(active_stats.active_checks_count, 0) as active_checks_count,
               COALESCE(down_stats.down_checks_count, 0) as down_checks_count,
               NULL as avg_uptime_24h
        FROM categories c
        LEFT JOIN (
            SELECT category_id, COUNT(DISTINCT check_id) as active_checks_count
            FROM (
                SELECT cc.category_id, cc.check_id
                FROM check_categories cc 
                JOIN checks ch ON cc.check_id = ch.id 
                WHERE ch.enabled = 1
                UNION
                SELECT ch.category_id, ch.id as check_id
                FROM checks ch 
                WHERE ch.category_id IS NOT NULL AND ch.enabled = 1
            ) combined_active
            GROUP BY category_id
        ) active_stats ON c.id = active_stats.category_id
        LEFT JOIN (
            SELECT category_id, COUNT(DISTINCT check_id) as down_checks_count
            FROM (
                SELECT cc.category_id, cc.check_id
                FROM check_categories cc 
                JOIN checks ch ON cc.check_id = ch.id 
                WHERE ch.enabled = 1 AND ch.last_state = 'DOWN'
                UNION
                SELECT ch.category_id, ch.id as check_id
                FROM checks ch 
                WHERE ch.category_id IS NOT NULL AND ch.enabled = 1 AND ch.last_state = 'DOWN'
            ) combined_down
            GROUP BY category_id
        ) down_stats ON c.id = down_stats.category_id
        ORDER BY c.name ASC
    ");
} catch (Exception $e) {
    error_log("Categories query failed: " . $e->getMessage());
    // Fallback to simple query
    try {
        $categories = $db->fetchAll("SELECT * FROM categories ORDER BY name ASC");
        // Set default values for missing stats
        foreach ($categories as &$category) {
            $category['active_checks_count'] = 0;
            $category['down_checks_count'] = 0;
        }
        unset($category);
    } catch (Exception $e2) {
        error_log("Simple categories query also failed: " . $e2->getMessage());
    }
}

// Get filters
$statusFilter = $_GET['status'] ?? 'all';
$searchFilter = trim($_GET['search'] ?? '');
$intervalFilter = $_GET['interval'] ?? 'all';
$categoryFilter = $_GET['categories'] ?? [];

// Ensure categoryFilter is an array
if (!is_array($categoryFilter)) {
    $categoryFilter = [$categoryFilter];
}
$categoryFilter = array_filter(array_map('trim', $categoryFilter));

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

if (!empty($categoryFilter)) {
    if (in_array('all', $categoryFilter)) {
        // If 'all' is selected, don't filter by category
    } elseif (in_array('uncategorized', $categoryFilter)) {
        if (count($categoryFilter) === 1) {
            // Only uncategorized selected - checks that have no category in either table
            $whereClauses[] = "(c.category_id IS NULL AND NOT EXISTS (SELECT 1 FROM check_categories WHERE check_id = c.id))";
        } else {
            // Uncategorized + other categories
            $categoryIds = array_filter($categoryFilter, fn($cat) => $cat !== 'uncategorized' && is_numeric($cat));
            if (!empty($categoryIds)) {
                $placeholders = str_repeat('?,', count($categoryIds) - 1) . '?';
                $whereClauses[] = "(
                    (c.category_id IS NULL AND NOT EXISTS (SELECT 1 FROM check_categories WHERE check_id = c.id))
                    OR c.category_id IN ($placeholders)
                    OR EXISTS (SELECT 1 FROM check_categories WHERE check_id = c.id AND category_id IN ($placeholders))
                )";
                $params = array_merge($params, $categoryIds, $categoryIds);
            } else {
                $whereClauses[] = "(c.category_id IS NULL AND NOT EXISTS (SELECT 1 FROM check_categories WHERE check_id = c.id))";
            }
        }
    } else {
        // Only specific categories selected
        $categoryIds = array_filter($categoryFilter, 'is_numeric');
        if (!empty($categoryIds)) {
            $placeholders = str_repeat('?,', count($categoryIds) - 1) . '?';
            $whereClauses[] = "(c.category_id IN ($placeholders) OR EXISTS (SELECT 1 FROM check_categories WHERE check_id = c.id AND category_id IN ($placeholders)))";
            $params = array_merge($params, $categoryIds, $categoryIds);
        }
    }
}

$whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get checks with enhanced data
$checksQuery = "
    SELECT c.*, 
           GROUP_CONCAT(DISTINCT cat.name ORDER BY cat.name SEPARATOR ', ') as category_names,
           GROUP_CONCAT(DISTINCT cat.color ORDER BY cat.name SEPARATOR ',') as category_colors,
           GROUP_CONCAT(DISTINCT cat.icon ORDER BY cat.name SEPARATOR ',') as category_icons,
           GROUP_CONCAT(DISTINCT cat.id ORDER BY cat.name SEPARATOR ',') as category_ids
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

// Enhanced statistics for each check
foreach ($checks as &$check) {
    try {
        // 24h stats
        $stats = $db->fetchOne(
            "SELECT 
                COUNT(*) as total_count_24h,
                SUM(CASE WHEN is_up = 1 THEN 1 ELSE 0 END) as up_count_24h,
                AVG(duration_ms) as avg_latency_24h,
                MAX(duration_ms) as max_latency_24h,
                MIN(duration_ms) as min_latency_24h,
                (SELECT COUNT(*) FROM check_results WHERE check_id = ? AND is_up = 0 AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as failures_24h
            FROM check_results 
            WHERE check_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$check['id'], $check['id']]
        );
        
        $check['up_count_24h'] = $stats['up_count_24h'] ?? 0;
        $check['total_count_24h'] = $stats['total_count_24h'] ?? 0;
        $check['avg_latency_24h'] = $stats['avg_latency_24h'] ?? 0;
        $check['max_latency_24h'] = $stats['max_latency_24h'] ?? 0;
        $check['min_latency_24h'] = $stats['min_latency_24h'] ?? 0;
        $check['failures_24h'] = $stats['failures_24h'] ?? 0;
        
        // Get open incidents
        $check['open_incidents'] = $db->fetchColumn(
            "SELECT COUNT(*) FROM incidents WHERE check_id = ? AND status = 'OPEN'",
            [$check['id']]
        ) ?: 0;
        
        // Get last check time
        $lastCheck = $db->fetchOne(
            "SELECT started_at, is_up FROM check_results WHERE check_id = ? ORDER BY started_at DESC LIMIT 1",
            [$check['id']]
        );
        $check['last_check_time'] = $lastCheck ? $lastCheck['started_at'] : null;
        
    } catch (Exception $e) {
        $check['up_count_24h'] = 0;
        $check['total_count_24h'] = 0;
        $check['avg_latency_24h'] = 0;
        $check['max_latency_24h'] = 0;
        $check['min_latency_24h'] = 0;
        $check['failures_24h'] = 0;
        $check['open_incidents'] = 0;
        $check['last_check_time'] = null;
    }
}
unset($check);

// Calculate enhanced metrics
$totalChecks = count($checks);
$enabledChecks = count(array_filter($checks, fn($c) => $c['enabled'] == 1));
$upChecks = count(array_filter($checks, fn($c) => $c['last_state'] === 'UP'));
$downChecks = count(array_filter($checks, fn($c) => $c['last_state'] === 'DOWN'));
$totalIncidents = array_sum(array_column($checks, 'open_incidents'));
$avgUptime = $enabledChecks > 0 ? round(($upChecks / $enabledChecks) * 100, 1) : 0;

// Calculate uptime and next run for display
foreach ($checks as $key => $check) {
    $checks[$key]['uptime_24h'] = $check['total_count_24h'] > 0 ? 
        round(($check['up_count_24h'] / $check['total_count_24h']) * 100, 1) : 0;
    $checks[$key]['avg_latency_24h'] = $check['avg_latency_24h'] ? round($check['avg_latency_24h']) : 0;
    
    $nextRun = strtotime($check['next_run_at']);
    $now = time();
    $secondsUntilNext = max(0, $nextRun - $now);
    
    if ($secondsUntilNext < 60) {
        $checks[$key]['next_run_display'] = $secondsUntilNext . 's';
    } else {
        $checks[$key]['next_run_display'] = floor($secondsUntilNext / 60) . 'm';
    }
    
    // Time since last check
    if ($check['last_check_time']) {
        $checks[$key]['last_check_ago'] = timeAgo($check['last_check_time']);
    } else {
        $checks[$key]['last_check_ago'] = 'Never';
    }
}

// Count uncategorized - checks that have no category in either the old or new structure
$uncategorizedCount = $db->fetchColumn("
    SELECT COUNT(*) FROM checks c 
    WHERE c.category_id IS NULL 
    AND NOT EXISTS (SELECT 1 FROM check_categories WHERE check_id = c.id)
") ?: 0;

// Start output buffering
ob_start();
?>

<style>
    .gradient-bg {
        background: linear-gradient(135deg, #6b7280 0%, #9ca3af 100%);
    }
    .card-hover {
        transition: all 0.3s ease;
        border: 1px solid #e5e7eb;
    }
    .card-hover:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        border-color: #6b7280;
    }
    .status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 8px;
    }
    .status-dot.up {
        background: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
    }
    .status-dot.down {
        background: #ef4444;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
    }
    .category-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 8px;
        border-radius: 16px;
        font-size: 11px;
        font-weight: 500;
        margin: 2px;
        background-color: #f3f4f6;
        color: #6b7280;
        border: 1px solid #d1d5db;
    }
    .btn-primary {
        background: #6b7280;
        border-color: #6b7280;
    }
    .btn-primary:hover {
        background: #5b6370;
        border-color: #5b6370;
    }
    .btn-secondary {
        background: #f9fafb;
        border-color: #d1d5db;
        color: #374151;
    }
    .btn-secondary:hover {
        background: #f3f4f6;
        border-color: #d1d5db;
    }
    
    /* Facebook-style navigation */
    .facebook-nav {
        background: #ffffff;
        border-bottom: 1px solid #d0d7de;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    .nav-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f0f2f5;
        transition: background-color 0.2s;
    }
    .nav-icon:hover {
        background: #e4e6ea;
    }
    .nav-icon.active {
        background: #e7f3ff;
        color: #1877f2;
    }
</style>

<div class="space-y-6">
    <?php echo $message; ?>
    
    <!-- Header with Better Gray Gradient -->
    <div class="bg-gradient-to-r from-slate-700 to-slate-600 rounded-xl p-6 text-white shadow-lg">
        <div class="flex justify-between items-start">
            <div>
                <h1 class="text-3xl font-bold mb-2">Monitoring Dashboard</h1>
                <p class="text-slate-200 opacity-90">Manage and monitor all your endpoints in real-time</p>
            </div>
            <div class="flex gap-3">
                <button onclick="openQuickCategoryModal()" 
                        class="px-5 py-2.5 bg-white/15 backdrop-blur border border-white/20 text-white rounded-lg hover:bg-white/25 transition-all flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                    </svg>
                    Add Category
                </button>
                <a href="/check_form.php" 
                   class="px-5 py-2.5 bg-white text-slate-600 rounded-lg hover:bg-slate-50 transition-all flex items-center gap-2 font-medium shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add New Check
                </a>
            </div>
        </div>
        
        <!-- Stats Cards with Subtle Colors -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-6">
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold"><?php echo $totalChecks; ?></div>
                <div class="text-slate-200 text-sm opacity-90">Total Checks</div>
            </div>
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold"><?php echo $enabledChecks; ?></div>
                <div class="text-slate-200 text-sm opacity-90">Active</div>
            </div>
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold text-emerald-300"><?php echo $upChecks; ?></div>
                <div class="text-slate-200 text-sm opacity-90">Up</div>
            </div>
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold text-red-300"><?php echo $downChecks; ?></div>
                <div class="text-slate-200 text-sm opacity-90">Down</div>
            </div>
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold text-amber-300"><?php echo $totalIncidents; ?></div>
                <div class="text-slate-200 text-sm opacity-90">Incidents</div>
            </div>
            <div class="bg-white/15 backdrop-blur border border-white/20 rounded-lg p-4 hover:bg-white/20 transition-colors">
                <div class="text-3xl font-bold text-blue-300"><?php echo $avgUptime; ?>%</div>
                <div class="text-slate-200 text-sm opacity-90">Avg Uptime</div>
            </div>
        </div>
    </div>

    <!-- Category Overview Cards with Facebook-style design -->
    <?php if (!empty($categories)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Categories Overview</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            
            <!-- All Categories Card -->
            <a href="?categories[]=all<?php echo $statusFilter !== 'all' ? '&status=' . $statusFilter : ''; ?><?php echo $searchFilter ? '&search=' . urlencode($searchFilter) : ''; ?><?php echo $intervalFilter !== 'all' ? '&interval=' . $intervalFilter : ''; ?>" 
               class="card-hover bg-gray-100 rounded-lg p-4 <?php echo in_array('all', $categoryFilter) ? 'ring-2 ring-gray-500 bg-gray-200' : ''; ?>">
                <div class="text-center">
                    <div class="w-12 h-12 mx-auto mb-2 rounded-lg bg-gray-200 flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <div class="font-semibold text-gray-900 text-sm">All Categories</div>
                    <div class="text-xs text-gray-500 mt-1"><?php echo array_sum(array_column($categories, 'active_checks_count')); ?> checks</div>
                </div>
            </a>
            
            <!-- Category Cards -->
            <?php foreach ($categories as $category): ?>
            <a href="javascript:void(0);" onclick="toggleCategory(<?php echo $category['id']; ?>)"
               class="card-hover bg-white rounded-lg p-4 category-card <?php echo in_array((string)$category['id'], $categoryFilter) ? 'ring-2 ring-gray-500 bg-gray-50' : ''; ?>">
                <div class="text-center">
                    <div class="w-12 h-12 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                        <div class="w-6 h-6 rounded text-xl flex items-center justify-center text-gray-600">
                            <?php 
                            $icons = [
                                'api' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/></svg>',
                                'globe' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM4.332 8.027a6.012 6.012 0 011.912-2.706C6.512 5.73 6.974 6 7.5 6A1.5 1.5 0 019 7.5V8a2 2 0 004 0 2 2 0 011.523-1.943A5.977 5.977 0 0116 10c0 .34-.028.675-.083 1H15a2 2 0 00-2 2v2.197A5.973 5.973 0 0110 16v-2a2 2 0 00-2-2 2 2 0 01-2-2 2 2 0 00-1.668-1.973z" clip-rule="evenodd"/></svg>',
                                'database' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/><path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/><path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/></svg>',
                                'external-link' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/></svg>',
                                'activity' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 0l-2 2a1 1 0 101.414 1.414L8 10.414l1.293 1.293a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
                                'folder' => '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>'
                            ];
                            echo $icons[$category['icon']] ?? $icons['folder'];
                            ?>
                        </div>
                    </div>
                    <div class="font-semibold text-gray-900 text-sm"><?php echo htmlspecialchars($category['name']); ?></div>
                    <div class="text-xs text-gray-500 mt-1"><?php echo $category['active_checks_count']; ?> checks</div>
                    <?php if ($category['down_checks_count'] > 0): ?>
                        <div class="text-xs text-red-500 font-medium mt-1 flex items-center justify-center">
                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <?php echo $category['down_checks_count']; ?> down
                        </div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            
            <!-- Uncategorized Card -->
            <?php if ($uncategorizedCount > 0): ?>
            <a href="javascript:void(0);" onclick="toggleCategory('uncategorized')" 
               class="card-hover bg-white rounded-lg p-4 category-card <?php echo in_array('uncategorized', $categoryFilter) ? 'ring-2 ring-gray-500 bg-gray-50' : ''; ?>">
                <div class="text-center">
                    <div class="w-12 h-12 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                        <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1H8a3 3 0 00-3 3v1.5a1.5 1.5 0 01-3 0V6z" clip-rule="evenodd"/>
                            <path d="M6 12a2 2 0 012-2h8a2 2 0 012 2v2a2 2 0 01-2 2H2h2a2 2 0 002-2v-2z"/>
                        </svg>
                    </div>
                    <div class="font-semibold text-gray-700 text-sm">Uncategorized</div>
                    <div class="text-xs text-gray-500 mt-1"><?php echo $uncategorizedCount; ?> checks</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters with Facebook-style design -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <form method="GET" class="space-y-4">
            <div class="flex flex-wrap gap-3">
                <div class="flex-1 min-w-[250px]">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($searchFilter); ?>"
                           placeholder="Search by name or URL..." 
                           class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                </div>
                
                <!-- Multi-Select Category Filter -->
                <div class="relative">
                    <div class="relative">
                        <button type="button" id="categoryDropdown" 
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 bg-white flex items-center justify-between min-w-[200px] text-left hover:bg-gray-50 transition-colors"
                                onclick="toggleCategoryDropdown(event)">
                            <span id="categoryDropdownText" class="text-gray-700">
                                <?php 
                                if (empty($categoryFilter) || in_array('all', $categoryFilter)) {
                                    echo 'All Categories';
                                } else {
                                    $selectedNames = [];
                                    foreach ($categories as $cat) {
                                        if (in_array((string)$cat['id'], $categoryFilter)) {
                                            $selectedNames[] = $cat['name'];
                                        }
                                    }
                                    if (in_array('uncategorized', $categoryFilter)) {
                                        $selectedNames[] = 'Uncategorized';
                                    }
                                    echo count($selectedNames) > 2 ? count($selectedNames) . ' categories selected' : implode(', ', $selectedNames);
                                }
                                ?>
                            </span>
                            <svg class="w-4 h-4 ml-2 transform transition-transform text-gray-400" id="categoryDropdownArrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        
                        <div id="categoryDropdownMenu" class="hidden absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                            <div class="p-2">
                                <label class="flex items-center space-x-2 p-2 hover:bg-gray-50 rounded cursor-pointer">
                                    <input type="checkbox" name="categories[]" value="all" 
                                           <?php echo (empty($categoryFilter) || in_array('all', $categoryFilter)) ? 'checked' : ''; ?>
                                           class="rounded border-gray-300 text-gray-600 category-checkbox focus:ring-gray-500"
                                           onchange="handleCategoryChange(this)">
                                    <span class="text-sm font-medium text-gray-700">All Categories</span>
                                </label>
                                
                                <?php if (!empty($categories)): ?>
                                    <?php foreach ($categories as $category): ?>
                                    <label class="flex items-center space-x-2 p-2 hover:bg-gray-50 rounded cursor-pointer">
                                        <input type="checkbox" name="categories[]" value="<?php echo $category['id']; ?>"
                                               <?php echo in_array((string)$category['id'], $categoryFilter) ? 'checked' : ''; ?>
                                               class="rounded border-gray-300 text-gray-600 category-checkbox focus:ring-gray-500"
                                               onchange="handleCategoryChange(this)">
                                        <span class="w-3 h-3 rounded-full flex-shrink-0 border border-gray-300 bg-gray-200"></span>
                                        <span class="text-sm text-gray-700"><?php echo htmlspecialchars($category['name']); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-2 text-sm text-gray-500">No categories available</div>
                                <?php endif; ?>
                                
                                <?php if ($uncategorizedCount > 0): ?>
                                <label class="flex items-center space-x-2 p-2 hover:bg-gray-50 rounded cursor-pointer">
                                    <input type="checkbox" name="categories[]" value="uncategorized"
                                           <?php echo in_array('uncategorized', $categoryFilter) ? 'checked' : ''; ?>
                                           class="rounded border-gray-300 text-gray-600 category-checkbox focus:ring-gray-500"
                                           onchange="handleCategoryChange(this)">
                                    <span class="text-sm text-gray-700">Uncategorized (<?php echo $uncategorizedCount; ?>)</span>
                                </label>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <select name="status" class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 bg-white text-gray-700">
                    <option value="all">All Status</option>
                    <option value="up" <?php echo $statusFilter === 'up' ? 'selected' : ''; ?>>Up</option>
                    <option value="down" <?php echo $statusFilter === 'down' ? 'selected' : ''; ?>>Down</option>
                    <option value="enabled" <?php echo $statusFilter === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                    <option value="disabled" <?php echo $statusFilter === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                </select>
                
                <select name="interval" class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 bg-white text-gray-700">
                    <option value="all">All Intervals</option>
                    <option value="60" <?php echo $intervalFilter === '60' ? 'selected' : ''; ?>>1 minute</option>
                    <option value="300" <?php echo $intervalFilter === '300' ? 'selected' : ''; ?>>5 minutes</option>
                    <option value="900" <?php echo $intervalFilter === '900' ? 'selected' : ''; ?>>15 minutes</option>
                    <option value="3600" <?php echo $intervalFilter === '3600' ? 'selected' : ''; ?>>1 hour</option>
                    <option value="86400" <?php echo $intervalFilter === '86400' ? 'selected' : ''; ?>>1 day</option>
                </select>
                
                <button type="submit" class="px-6 py-2.5 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors font-medium">
                    Apply Filters
                </button>
            </div>
            
            <!-- Selected Categories Display -->
            <?php if (!empty($categoryFilter) && !in_array('all', $categoryFilter)): ?>
            <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-200">
                <span class="text-sm text-gray-600">Selected categories:</span>
                <?php foreach ($categoryFilter as $catId): ?>
                    <?php if ($catId === 'uncategorized'): ?>
                        <span class="category-badge">
                            Uncategorized
                        </span>
                    <?php else: ?>
                        <?php 
                        $cat = array_filter($categories, fn($c) => $c['id'] == $catId);
                        if (!empty($cat)):
                            $cat = array_values($cat)[0];
                        ?>
                            <span class="category-badge">
                                <span class="w-2 h-2 rounded-full mr-1" style="background-color: <?php echo $cat['color']; ?>"></span>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Bulk Actions -->
            <div class="flex items-center gap-3 pt-3 border-t border-gray-200">
                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-gray-600 focus:ring-gray-500" onchange="toggleAllCheckboxes()">
                <label for="selectAll" class="text-sm text-gray-700">Select All</label>
                
                <select id="bulkActionType" class="px-3 py-1.5 border border-gray-300 rounded text-sm bg-white text-gray-700">
                    <option value="">Bulk Actions...</option>
                    <option value="enable">Enable Selected</option>
                    <option value="disable">Disable Selected</option>
                    <option value="delete">Delete Selected</option>
                </select>
                
                <button type="button" onclick="executeBulkAction()" class="px-4 py-1.5 bg-gray-600 text-white rounded text-sm hover:bg-gray-700 transition-colors">
                    Execute
                </button>
            </div>
        </form>
    </div>

    <!-- List View -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="w-12 px-6 py-3">
                            <input type="checkbox" id="selectAllTable" class="rounded text-purple-600" onchange="toggleAllCheckboxes()">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Check Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categories</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Uptime (24h)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Response</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last/Next Check</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($checks)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                <div class="text-lg mb-2">No checks found</div>
                                <a href="/check_form.php" class="text-purple-600 hover:text-purple-800">Add your first check →</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($checks as $check): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <input type="checkbox" name="selected_checks[]" value="<?php echo $check['id']; ?>" class="check-select rounded text-purple-600">
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($check['name']); ?></div>
                                <div class="text-xs text-gray-500 truncate max-w-xs"><?php echo htmlspecialchars($check['url']); ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-1">
                                    <?php if (empty($check['category_names'])): ?>
                                        <span class="category-badge" style="background-color: #6B728020; color: #6B7280;">
                                            Uncategorized
                                        </span>
                                    <?php else: ?>
                                        <?php 
                                        $categoryNames = explode(', ', $check['category_names']);
                                        $categoryColors = explode(',', $check['category_colors']);
                                        foreach ($categoryNames as $i => $catName): 
                                            $color = $categoryColors[$i] ?? '#6B7280';
                                        ?>
                                            <span class="category-badge" style="background-color: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                                <?php echo htmlspecialchars($catName); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($check['enabled']): ?>
                                    <div class="flex items-center">
                                        <span class="status-dot <?php echo strtolower($check['last_state'] ?? 'unknown'); ?>"></span>
                                        <span class="text-sm font-medium <?php echo $check['last_state'] === 'UP' ? 'text-green-600' : 'text-red-600'; ?>">
                                            <?php echo $check['last_state'] ?? 'UNKNOWN'; ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-sm text-gray-400">Disabled</span>
                                <?php endif; ?>
                                
                                <?php if ($check['open_incidents'] > 0): ?>
                                    <div class="text-xs text-red-600 mt-1">⚠️ <?php echo $check['open_incidents']; ?> incident(s)</div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-semibold <?php echo $check['uptime_24h'] >= 99 ? 'text-green-600' : ($check['uptime_24h'] >= 95 ? 'text-yellow-600' : 'text-red-600'); ?>">
                                    <?php echo $check['uptime_24h']; ?>%
                                </div>
                                <div class="w-16 bg-gray-200 rounded-full h-2 mt-1">
                                    <div class="h-2 rounded-full <?php echo $check['uptime_24h'] >= 99 ? 'bg-green-500' : ($check['uptime_24h'] >= 95 ? 'bg-yellow-500' : 'bg-red-500'); ?>" 
                                         style="width: <?php echo $check['uptime_24h']; ?>%"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900"><?php echo formatDuration($check['avg_latency_24h']); ?></div>
                                <div class="text-xs text-gray-500">
                                    <?php echo formatDuration($check['min_latency_24h']); ?> - <?php echo formatDuration($check['max_latency_24h']); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">↓ <?php echo $check['last_check_ago']; ?></div>
                                <div class="text-xs text-gray-500">↑ <?php echo $check['next_run_display']; ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="relative">
                                    <button class="p-2 hover:bg-gray-100 rounded-full transition-colors" onclick="showActionModal(<?php echo $check['id']; ?>, '<?php echo htmlspecialchars(addslashes($check['name'])); ?>', event)">
                                        <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Floating Action Modal (Facebook-style) -->
<div id="actionModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-64 transform transition-all">
        <div class="p-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900" id="actionModalTitle">Actions</h3>
            <p class="text-sm text-gray-500" id="actionModalSubtitle">Choose an action for this check</p>
        </div>
        
        <div class="py-2">
            <button onclick="viewCheck()" class="flex items-center w-full px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                </div>
                <div class="flex-1 text-left">
                    <div class="font-medium">View Check</div>
                    <div class="text-xs text-gray-500">See check details and history</div>
                </div>
            </button>
            
            <button onclick="editCheck()" class="flex items-center w-full px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                </div>
                <div class="flex-1 text-left">
                    <div class="font-medium">Edit Check</div>
                    <div class="text-xs text-gray-500">Modify check settings</div>
                </div>
            </button>
            
            <button onclick="reportCheck()" class="flex items-center w-full px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="flex-1 text-left">
                    <div class="font-medium">View Report</div>
                    <div class="text-xs text-gray-500">Generate performance report</div>
                </div>
            </button>
            
            <div class="border-t border-gray-100 mt-2"></div>
            
            <button onclick="deleteCheckModal()" class="flex items-center w-full px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition-colors">
                <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1-1H8a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <div class="flex-1 text-left">
                    <div class="font-medium">Delete Check</div>
                    <div class="text-xs text-gray-500">Permanently remove this check</div>
                </div>
            </button>
        </div>
        
        <div class="p-4 border-t border-gray-200">
            <button onclick="closeActionModal()" class="w-full px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- Enhanced Quick Category Modal -->
<div id="quickCategoryModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-2xl rounded-xl bg-white">
        <div class="mt-3">
            <h3 class="text-xl font-bold text-gray-900 mb-4">Create New Category</h3>
            
            <form id="quickCategoryForm" onsubmit="saveQuickCategory(event)">
                <div class="space-y-4">
                    <div>
                        <label for="quickCategoryName" class="block text-sm font-medium text-gray-700 mb-1">Category Name</label>
                        <input type="text" id="quickCategoryName" name="name" required maxlength="50"
                               placeholder="e.g., Production APIs"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label for="quickCategoryIcon" class="block text-sm font-medium text-gray-700 mb-1">Icon</label>
                        <select id="quickCategoryIcon" name="icon" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="folder">📁 Folder</option>
                            <option value="api">🔌 API</option>
                            <option value="globe"> Website</option>
                            <option value="database"> Database</option>
                            <option value="external-link">🔗 External</option>
                            <option value="activity"> Monitoring</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="quickCategoryColor" class="block text-sm font-medium text-gray-700 mb-1">Color</label>
                        <div class="grid grid-cols-6 gap-2">
                            <?php 
                            $colors = ['#8B5CF6', '#3B82F6', '#10B981', '#EF4444', '#F59E0B', '#EC4899', '#6B7280', '#14B8A6'];
                            foreach ($colors as $color): 
                            ?>
                            <label class="cursor-pointer">
                                <input type="radio" name="color" value="<?php echo $color; ?>" class="sr-only color-radio">
                                <div class="w-10 h-10 rounded-lg border-2 border-gray-300 color-option" 
                                     style="background-color: <?php echo $color; ?>;" 
                                     data-color="<?php echo $color; ?>"></div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div>
                        <label for="quickCategoryDesc" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                        <textarea id="quickCategoryDesc" name="description" rows="2"
                                  placeholder="Brief description of this category..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"></textarea>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="closeQuickCategoryModal()" 
                            class="px-5 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-5 py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 text-white rounded-lg hover:from-purple-700 hover:to-blue-700 transition-all">
                        Create Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// All original functions preserved
function deleteCheck(id, name) {
    if (confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
        const form = document.createElement("form");
        form.method = "POST";
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo $auth->getCsrfToken(); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="check_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

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
    formData.append("csrf_token", "<?php echo $auth->getCsrfToken(); ?>");
    
    fetch("checks.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert("Category created successfully!");
            window.location.reload();
        } else {
            alert("Error: " + (data.error || "Failed to create category"));
        }
    })
    .catch(error => {
        alert("Error: " + error);
    });
}

// Bulk action functions
function toggleAllCheckboxes() {
    const selectAll = document.getElementById('selectAll') || document.getElementById('selectAllTable');
    const checkboxes = document.querySelectorAll('.check-select');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
}

function executeBulkAction() {
    const action = document.getElementById('bulkActionType').value;
    if (!action) {
        alert('Please select a bulk action');
        return;
    }
    
    const selected = document.querySelectorAll('.check-select:checked');
    if (selected.length === 0) {
        alert('Please select at least one check');
        return;
    }
    
    if (confirm(`Are you sure you want to ${action} ${selected.length} check(s)?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        let html = `
            <input type="hidden" name="csrf_token" value="<?php echo $auth->getCsrfToken(); ?>">
            <input type="hidden" name="action" value="bulk_action">
            <input type="hidden" name="bulk_action_type" value="${action}">
        `;
        
        selected.forEach(cb => {
            html += `<input type="hidden" name="selected_checks[]" value="${cb.value}">`;
        });
        
        form.innerHTML = html;
        document.body.appendChild(form);
        form.submit();
    }
}

// Enhanced color picker
document.querySelectorAll('.color-option').forEach(option => {
    option.addEventListener('click', function() {
        document.querySelectorAll('.color-option').forEach(opt => {
            opt.classList.remove('ring-4', 'ring-purple-500');
        });
        this.classList.add('ring-4', 'ring-purple-500');
        this.previousElementSibling.checked = true;
    });
});

// Initialize first color as selected
if (document.querySelector('.color-radio')) {
    document.querySelector('.color-radio').checked = true;
    document.querySelector('.color-option').classList.add('ring-4', 'ring-purple-500');
}

// Multi-select category functions
function toggleCategoryDropdown(event) {
    event.preventDefault();
    event.stopPropagation();
    
    const menu = document.getElementById('categoryDropdownMenu');
    const arrow = document.getElementById('categoryDropdownArrow');
    
    if (menu.classList.contains('hidden')) {
        menu.classList.remove('hidden');
        arrow.style.transform = 'rotate(180deg)';
    } else {
        menu.classList.add('hidden');
        arrow.style.transform = 'rotate(0deg)';
    }
}

// Action menu functions - Facebook-style modal
let currentCheckId = null;
let currentCheckName = null;

function showActionModal(checkId, checkName, event) {
    event.preventDefault();
    event.stopPropagation();
    
    currentCheckId = checkId;
    currentCheckName = checkName;
    
    document.getElementById('actionModalSubtitle').textContent = `Choose an action for "${checkName}"`;
    document.getElementById('actionModal').classList.remove('hidden');
    
    // Prevent body scrolling
    document.body.style.overflow = 'hidden';
}

function closeActionModal() {
    document.getElementById('actionModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    currentCheckId = null;
    currentCheckName = null;
}

function viewCheck() {
    if (currentCheckId) {
        window.location.href = `/check.php?id=${currentCheckId}`;
    }
    closeActionModal();
}

function editCheck() {
    if (currentCheckId) {
        window.location.href = `/check_form.php?id=${currentCheckId}`;
    }
    closeActionModal();
}

function reportCheck() {
    if (currentCheckId) {
        window.location.href = `/reports.php?check_id=${currentCheckId}`;
    }
    closeActionModal();
}

function deleteCheckModal() {
    if (currentCheckId && currentCheckName) {
        closeActionModal();
        deleteCheck(currentCheckId, currentCheckName);
    }
}

// Close modal when clicking outside or pressing Escape
document.addEventListener('click', function(event) {
    const modal = document.getElementById('actionModal');
    const modalContent = modal.querySelector('div');
    
    if (event.target === modal) {
        closeActionModal();
    }
    
    const dropdown = document.getElementById('categoryDropdown');
    const menu = document.getElementById('categoryDropdownMenu');
    const arrow = document.getElementById('categoryDropdownArrow');
    
    // Close category dropdown
    if (dropdown && menu && !dropdown.contains(event.target) && !menu.contains(event.target)) {
        menu.classList.add('hidden');
        if (arrow) {
            arrow.style.transform = 'rotate(0deg)';
        }
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeActionModal();
    }
});

function handleCategoryChange(checkbox) {
    const allCheckbox = document.querySelector('input[name="categories[]"][value="all"]');
    const otherCheckboxes = document.querySelectorAll('input[name="categories[]"]:not([value="all"])');
    
    if (checkbox.value === 'all') {
        if (checkbox.checked) {
            // Uncheck all other categories when "All" is selected
            otherCheckboxes.forEach(cb => cb.checked = false);
        }
    } else {
        if (checkbox.checked) {
            // Uncheck "All" when any specific category is selected
            allCheckbox.checked = false;
        } else {
            // If no categories are selected, check "All"
            const anyChecked = Array.from(otherCheckboxes).some(cb => cb.checked);
            if (!anyChecked) {
                allCheckbox.checked = true;
            }
        }
    }
    
    updateCategoryDropdownText();
}

function updateCategoryDropdownText() {
    const allCheckbox = document.querySelector('input[name="categories[]"][value="all"]');
    const otherCheckboxes = document.querySelectorAll('input[name="categories[]"]:not([value="all"])');
    const dropdownText = document.getElementById('categoryDropdownText');
    
    if (allCheckbox.checked) {
        dropdownText.textContent = '📂 All Categories';
        return;
    }
    
    const selectedCategories = Array.from(otherCheckboxes)
        .filter(cb => cb.checked)
        .map(cb => {
            const label = cb.closest('label').querySelector('span:last-child');
            return label ? label.textContent.trim() : cb.value;
        });
    
    if (selectedCategories.length === 0) {
        dropdownText.textContent = '📂 Select Categories';
    } else if (selectedCategories.length > 2) {
        dropdownText.textContent = `${selectedCategories.length} categories selected`;
    } else {
        dropdownText.textContent = selectedCategories.join(', ');
    }
}

function toggleCategory(categoryId) {
    const checkbox = document.querySelector(`input[name="categories[]"][value="${categoryId}"]`);
    if (checkbox) {
        checkbox.checked = !checkbox.checked;
        handleCategoryChange(checkbox);
        
        // Submit the form to apply the filter
        setTimeout(() => {
            document.querySelector('form').submit();
        }, 100);
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('categoryDropdown');
    const menu = document.getElementById('categoryDropdownMenu');
    const arrow = document.getElementById('categoryDropdownArrow');
    
    if (dropdown && menu && !dropdown.contains(event.target) && !menu.contains(event.target)) {
        menu.classList.add('hidden');
        if (arrow) {
            arrow.style.transform = 'rotate(0deg)';
        }
    }
});

// Stop dropdown menu clicks from closing the dropdown
document.addEventListener('DOMContentLoaded', function() {
    const menu = document.getElementById('categoryDropdownMenu');
    if (menu) {
        menu.addEventListener('click', function(event) {
            event.stopPropagation();
        });
    }
    
    updateCategoryDropdownText();
});
</script>

<?php
$content = ob_get_clean();
renderTemplate('All Checks', $content);
?>