<?php
/**
 * Dashboard - Modern UI
 */

require_once 'bootstrap.php';

$auth->requireAuth();

// Get overall statistics
$totalChecks = $db->fetchColumn("SELECT COUNT(*) FROM checks") ?: 0;
$enabledChecks = $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1") ?: 0;
$upChecks = $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE last_state = 'UP' AND enabled = 1") ?: 0;
$downChecks = $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE last_state = 'DOWN' AND enabled = 1") ?: 0;
$totalIncidents = $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'OPEN'") ?: 0;
$avgUptime = $enabledChecks > 0 ? round(($upChecks / $enabledChecks) * 100, 1) : 0;

// Get recent checks with enhanced data
$recentChecks = $db->fetchAll("
    SELECT c.*, cat.name as category_name, cat.color as category_color,
           (SELECT COUNT(*) FROM check_results cr WHERE cr.check_id = c.id AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as checks_24h,
           (SELECT COUNT(*) FROM check_results cr WHERE cr.check_id = c.id AND cr.is_up = 1 AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as up_checks_24h,
           (SELECT AVG(duration_ms) FROM check_results cr WHERE cr.check_id = c.id AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as avg_latency_24h,
           (SELECT COUNT(*) FROM incidents WHERE check_id = c.id AND status = 'OPEN') as open_incidents
    FROM checks c
    LEFT JOIN categories cat ON c.category_id = cat.id
    WHERE c.enabled = 1
    ORDER BY c.updated_at DESC, c.name ASC
    LIMIT 10
");

// Calculate uptime for each check
foreach ($recentChecks as &$check) {
    $check['uptime_24h'] = $check['checks_24h'] > 0 ? 
        round(($check['up_checks_24h'] / $check['checks_24h']) * 100, 1) : 0;
    $check['avg_latency_24h'] = $check['avg_latency_24h'] ? round($check['avg_latency_24h']) : 0;
    
    // Calculate next run time
    $nextRun = strtotime($check['next_run_at']);
    $now = time();
    $secondsUntilNext = max(0, $nextRun - $now);
    
    if ($secondsUntilNext < 60) {
        $check['next_run_display'] = $secondsUntilNext . 's';
    } else {
        $check['next_run_display'] = floor($secondsUntilNext / 60) . 'm';
    }
}
unset($check);

// Get recent incidents
$recentIncidents = $db->fetchAll("
    SELECT i.*, c.name as check_name, c.url as check_url
    FROM incidents i
    JOIN checks c ON i.check_id = c.id
    ORDER BY i.started_at DESC
    LIMIT 5
");

// Get category overview
$categoryStats = $db->fetchAll("
    SELECT c.*, 
           COUNT(DISTINCT ch.id) as total_checks,
           SUM(CASE WHEN ch.enabled = 1 THEN 1 ELSE 0 END) as active_checks,
           SUM(CASE WHEN ch.last_state = 'UP' AND ch.enabled = 1 THEN 1 ELSE 0 END) as up_checks,
           SUM(CASE WHEN ch.last_state = 'DOWN' AND ch.enabled = 1 THEN 1 ELSE 0 END) as down_checks
    FROM categories c
    LEFT JOIN checks ch ON c.id = ch.category_id
    GROUP BY c.id
    HAVING total_checks > 0
    ORDER BY c.name ASC
    LIMIT 6
");

// Get success message if redirected from form
$successMessage = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'created':
            $successMessage = '<div class="mb-4 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg">
                <p class="text-green-800 font-medium">✅ Check created successfully!</p>
            </div>';
            break;
        case 'updated':
            $successMessage = '<div class="mb-4 p-4 bg-blue-50 border-l-4 border-blue-500 rounded-lg">
                <p class="text-blue-800 font-medium">✅ Check updated successfully!</p>
            </div>';
            break;
    }
}

ob_start();
?>

<style>
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
    .status-dot.unknown {
        background: #6b7280;
        box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.2);
    }
    .gradient-bg {
        background: linear-gradient(135deg, #6b7280 0%, #9ca3af 100%);
    }
    .category-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 8px;
        border-radius: 16px;
        font-size: 11px;
        font-weight: 500;
        margin: 2px;
    }
</style>

<div class="space-y-6">
    <?php echo $successMessage; ?>
    
    <!-- Header -->
    <div class="bg-gradient-to-r from-slate-700 to-slate-600 rounded-xl p-6 text-white shadow-lg">
        <div class="flex justify-between items-start">
            <div>
                <h1 class="text-3xl font-bold mb-2">Dashboard</h1>
                <p class="text-slate-200 opacity-90">Real-time monitoring overview and system health</p>
            </div>
            <div class="flex gap-3">
                <a href="/reports.php" 
                   class="px-5 py-2.5 bg-white/15 backdrop-blur border border-white/20 text-white rounded-lg hover:bg-white/25 transition-all flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    View Reports
                </a>
                <a href="/check_form.php" 
                   class="px-5 py-2.5 bg-white text-slate-600 rounded-lg hover:bg-slate-50 transition-all flex items-center gap-2 font-medium shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add New Check
                </a>
            </div>
        </div>
        
        <!-- Stats Cards -->
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

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Recent Checks -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-semibold text-gray-900">Recent Checks</h2>
                    <a href="/checks.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium">View All →</a>
                </div>
                
                <?php if (empty($recentChecks)): ?>
                    <div class="text-center py-12 bg-gray-50 rounded-lg">
                        <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        <p class="text-gray-500 text-lg">No checks configured</p>
                        <p class="text-gray-400 text-sm mt-2">Add your first monitoring check to get started</p>
                        <a href="/check_form.php" class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Add First Check
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($recentChecks as $check): ?>
                        <div class="card-hover bg-white border rounded-lg p-4 hover:shadow-md transition-all">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3 flex-1 min-w-0">
                                    <span class="status-dot <?php echo strtolower($check['last_state'] ?? 'unknown'); ?>"></span>
                                    
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <h3 class="text-sm font-medium text-gray-900 truncate">
                                                <?php echo htmlspecialchars($check['name']); ?>
                                            </h3>
                                            <?php if ($check['category_name']): ?>
                                                <span class="category-badge" 
                                                      style="background-color: <?php echo $check['category_color']; ?>20; color: <?php echo $check['category_color']; ?>;">
                                                    <?php echo htmlspecialchars($check['category_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($check['url']); ?></p>
                                    </div>
                                </div>
                                
                                <div class="flex items-center gap-4 text-right">
                                    <div class="text-center">
                                        <div class="text-sm font-semibold <?php echo $check['uptime_24h'] >= 99 ? 'text-green-600' : ($check['uptime_24h'] >= 95 ? 'text-yellow-600' : 'text-red-600'); ?>">
                                            <?php echo $check['uptime_24h']; ?>%
                                        </div>
                                        <div class="text-xs text-gray-500">24h uptime</div>
                                    </div>
                                    
                                    <div class="text-center">
                                        <div class="text-sm font-medium text-gray-900"><?php echo formatDuration($check['avg_latency_24h']); ?></div>
                                        <div class="text-xs text-gray-500">avg latency</div>
                                    </div>
                                    
                                    <div class="text-center">
                                        <div class="text-sm font-medium text-gray-900"><?php echo $check['next_run_display']; ?></div>
                                        <div class="text-xs text-gray-500">next check</div>
                                    </div>
                                    
                                    <a href="/check.php?id=<?php echo $check['id']; ?>" 
                                       class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            </div>
                            
                            <?php if ($check['open_incidents'] > 0): ?>
                                <div class="mt-2 flex items-center gap-1 text-xs text-red-600">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                    </svg>
                                    <?php echo $check['open_incidents']; ?> open incident<?php echo $check['open_incidents'] > 1 ? 's' : ''; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="space-y-6">
            
            <!-- Quick Actions -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
                <div class="space-y-3">
                    <a href="/check_form.php" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                        </div>
                        <div>
                            <div class="font-medium text-gray-900">Add New Check</div>
                            <div class="text-sm text-gray-500">Monitor a new endpoint</div>
                        </div>
                    </a>
                    
                    <a href="/categories.php" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="font-medium text-gray-900">Manage Categories</div>
                            <div class="text-sm text-gray-500">Organize your checks</div>
                        </div>
                    </a>
                    
                    <a href="/reports.php" class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                        <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="font-medium text-gray-900">View Reports</div>
                            <div class="text-sm text-gray-500">Analyze performance data</div>
                        </div>
                    </a>
                </div>
            </div>
            
            <!-- Category Overview -->
            <?php if (!empty($categoryStats)): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-900">Categories</h2>
                    <a href="/categories.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Manage →</a>
                </div>
                
                <div class="space-y-3">
                    <?php foreach ($categoryStats as $category): ?>
                    <div class="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center" 
                                 style="background-color: <?php echo $category['color']; ?>20;">
                                <div class="w-4 h-4" style="color: <?php echo $category['color']; ?>;">
                                    <?php 
                                    $icons = [
                                        'api' => '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/></svg>',
                                        'globe' => '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM4.332 8.027a6.012 6.012 0 011.912-2.706C6.512 5.73 6.974 6 7.5 6A1.5 1.5 0 019 7.5V8a2 2 0 004 0 2 2 0 011.523-1.943A5.977 5.977 0 0116 10c0 .34-.028.675-.083 1H15a2 2 0 00-2 2v2.197A5.973 5.973 0 0110 16v-2a2 2 0 00-2-2 2 2 0 01-2-2 2 2 0 00-1.668-1.973z" clip-rule="evenodd"/></svg>',
                                        'database' => '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/><path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/><path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/></svg>',
                                        'external-link' => '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/></svg>',
                                        'activity' => '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 0l-2 2a1 1 0 101.414 1.414L8 10.414l1.293 1.293a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
                                        'folder' => '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>'
                                    ];
                                    echo $icons[$category['icon']] ?? $icons['folder'];
                                    ?>
                                </div>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($category['name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo $category['active_checks']; ?> checks</div>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <div class="text-xs text-green-600 font-medium"><?php echo $category['up_checks']; ?></div>
                            <div class="text-xs text-gray-300">/</div>
                            <div class="text-xs text-red-600 font-medium"><?php echo $category['down_checks']; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Recent Incidents -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-900">Recent Incidents</h2>
                    <span class="text-sm text-gray-500"><?php echo count($recentIncidents); ?> total</span>
                </div>
                
                <?php if (empty($recentIncidents)): ?>
                    <div class="text-center py-8">
                        <div class="w-12 h-12 mx-auto mb-3 text-green-400">
                            <svg fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <p class="text-sm text-gray-500">No recent incidents</p>
                        <p class="text-xs text-gray-400 mt-1">All systems operational</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($recentIncidents as $incident): ?>
                        <div class="border-l-4 border-red-400 pl-4 py-2">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-sm font-medium text-gray-900 truncate">
                                        <?php echo htmlspecialchars($incident['check_name']); ?>
                                    </h4>
                                    <p class="text-xs text-gray-500 truncate">
                                        <?php echo htmlspecialchars($incident['check_url']); ?>
                                    </p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $incident['status'] === 'OPEN' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'; ?>">
                                            <?php echo $incident['status']; ?>
                                        </span>
                                        <span class="text-xs text-gray-500">
                                            <?php echo timeAgo($incident['started_at']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>
    </div>
    
    <!-- System Health Overview -->
    <?php if ($enabledChecks > 0): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">System Health Overview</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <!-- Overall Health -->
            <div class="text-center">
                <div class="w-20 h-20 mx-auto mb-3 rounded-full flex items-center justify-center text-2xl font-bold text-white" 
                     style="background-color: <?php echo $avgUptime >= 99 ? '#10b981' : ($avgUptime >= 95 ? '#f59e0b' : '#ef4444'); ?>;">
                    <?php echo $avgUptime; ?>%
                </div>
                <div class="font-medium text-gray-900">Overall Health</div>
                <div class="text-sm text-gray-500">System-wide uptime</div>
            </div>
            
            <!-- Response Time -->
            <div class="text-center">
                <?php 
                $avgResponseTime = $db->fetchColumn("
                    SELECT AVG(duration_ms) 
                    FROM check_results 
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ") ?: 0;
                ?>
                <div class="w-20 h-20 mx-auto mb-3 rounded-full bg-blue-500 flex items-center justify-center text-white">
                    <div class="text-center">
                        <div class="text-lg font-bold"><?php echo formatDuration($avgResponseTime); ?></div>
                    </div>
                </div>
                <div class="font-medium text-gray-900">Avg Response</div>
                <div class="text-sm text-gray-500">Last 24 hours</div>
            </div>
            
            <!-- Check Frequency -->
            <div class="text-center">
                <?php 
                $totalChecks24h = $db->fetchColumn("
                    SELECT COUNT(*) 
                    FROM check_results 
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ") ?: 0;
                ?>
                <div class="w-20 h-20 mx-auto mb-3 rounded-full bg-purple-500 flex items-center justify-center text-white">
                    <div class="text-center">
                        <div class="text-lg font-bold"><?php echo number_format($totalChecks24h); ?></div>
                    </div>
                </div>
                <div class="font-medium text-gray-900">Checks Run</div>
                <div class="text-sm text-gray-500">Last 24 hours</div>
            </div>
            
            <!-- Success Rate -->
            <div class="text-center">
                <?php 
                $successfulChecks = $db->fetchColumn("
                    SELECT COUNT(*) 
                    FROM check_results 
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND is_up = 1
                ") ?: 0;
                $successRate = $totalChecks24h > 0 ? round(($successfulChecks / $totalChecks24h) * 100, 1) : 0;
                ?>
                <div class="w-20 h-20 mx-auto mb-3 rounded-full flex items-center justify-center text-white text-lg font-bold"
                     style="background-color: <?php echo $successRate >= 99 ? '#10b981' : ($successRate >= 95 ? '#f59e0b' : '#ef4444'); ?>;">
                    <?php echo $successRate; ?>%
                </div>
                <div class="font-medium text-gray-900">Success Rate</div>
                <div class="text-sm text-gray-500">Last 24 hours</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Auto-refresh dashboard every 30 seconds
setInterval(() => {
    location.reload();
}, 30000);

// Add smooth transitions
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.card-hover');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
});
</script>

<?php
$content = ob_get_clean();
renderTemplate('Dashboard', $content);
?>