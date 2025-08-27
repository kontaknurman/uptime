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

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$httpStatusFilter = $_GET['http_status'] ?? '';
$dateFromFilter = $_GET['date_from'] ?? '';
$dateToFilter = $_GET['date_to'] ?? '';
$limitFilter = (int)($_GET['limit'] ?? 50);

// Build WHERE clause for results
$whereClauses = ['check_id = ?'];
$params = [$checkId];

if ($statusFilter === 'up') {
    $whereClauses[] = 'is_up = 1';
} elseif ($statusFilter === 'down') {
    $whereClauses[] = 'is_up = 0';
}

if (!empty($httpStatusFilter)) {
    $whereClauses[] = 'http_status = ?';
    $params[] = (int)$httpStatusFilter;
}

if (!empty($dateFromFilter)) {
    $whereClauses[] = 'started_at >= ?';
    $params[] = $dateFromFilter . ' 00:00:00';
}

if (!empty($dateToFilter)) {
    $whereClauses[] = 'started_at <= ?';
    $params[] = $dateToFilter . ' 23:59:59';
}

$whereClause = implode(' AND ', $whereClauses);

// Get filtered results
$recentResults = $db->fetchAll(
    "SELECT * FROM check_results 
     WHERE {$whereClause} 
     ORDER BY started_at DESC 
     LIMIT {$limitFilter}",
    $params
);

// Get total count for pagination info
$totalResults = $db->fetchColumn(
    "SELECT COUNT(*) FROM check_results WHERE {$whereClause}",
    $params
);

// Get distinct HTTP status codes for filter dropdown
$httpStatuses = $db->fetchAll(
    'SELECT DISTINCT http_status FROM check_results WHERE check_id = ? ORDER BY http_status ASC',
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
<style>
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fefefe;
    margin: 2% auto;
    padding: 20px;
    border: none;
    border-radius: 8px;
    width: 90%;
    max-width: 1000px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover,
.close:focus {
    color: #000;
    text-decoration: none;
}

.response-content {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 4px;
    padding: 12px;
    font-family: monospace;
    font-size: 12px;
    white-space: pre-wrap;
    max-height: 300px;
    overflow-y: auto;
}

.status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.http-200 { background-color: #d1fae5; color: #065f46; }
.http-300 { background-color: #fef3c7; color: #92400e; }
.http-400 { background-color: #fecaca; color: #991b1b; }
.http-500 { background-color: #fca5a5; color: #7f1d1d; }
.http-0 { background-color: #f3f4f6; color: #374151; }
</style>

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

    <!-- Results Filter Section -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Check Results</h2>
        </div>
        
        <!-- Filter Form -->
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4 items-end">
                <input type="hidden" name="id" value="' . $checkId . '">
                
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select id="status" name="status" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="all"' . ($statusFilter === 'all' ? ' selected' : '') . '>All Status</option>
                        <option value="up"' . ($statusFilter === 'up' ? ' selected' : '') . '>UP Only</option>
                        <option value="down"' . ($statusFilter === 'down' ? ' selected' : '') . '>DOWN Only</option>
                    </select>
                </div>
                
                <div>
                    <label for="http_status" class="block text-sm font-medium text-gray-700">HTTP Status</label>
                    <select id="http_status" name="http_status" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">All HTTP Status</option>';

foreach ($httpStatuses as $status) {
    $selected = $httpStatusFilter == $status['http_status'] ? ' selected' : '';
    $content .= '<option value="' . $status['http_status'] . '"' . $selected . '>' . $status['http_status'] . '</option>';
}

$content .= '
                    </select>
                </div>
                
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700">From Date</label>
                    <input type="date" id="date_from" name="date_from" 
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                           value="' . htmlspecialchars($dateFromFilter) . '">
                </div>
                
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700">To Date</label>
                    <input type="date" id="date_to" name="date_to" 
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                           value="' . htmlspecialchars($dateToFilter) . '">
                </div>
                
                <div>
                    <label for="limit" class="block text-sm font-medium text-gray-700">Show</label>
                    <select id="limit" name="limit" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="25"' . ($limitFilter === 25 ? ' selected' : '') . '>25 results</option>
                        <option value="50"' . ($limitFilter === 50 ? ' selected' : '') . '>50 results</option>
                        <option value="100"' . ($limitFilter === 100 ? ' selected' : '') . '>100 results</option>
                        <option value="200"' . ($limitFilter === 200 ? ' selected' : '') . '>200 results</option>
                    </select>
                </div>
                
                <div class="flex space-x-2">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                        Filter
                    </button>
                    <a href="/check.php?id=' . $checkId . '" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                        Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Results Summary -->
        <div class="px-6 py-3 bg-blue-50 border-b border-gray-200">
            <p class="text-sm text-blue-800">
                <strong>Showing ' . count($recentResults) . ' of ' . $totalResults . ' results</strong>
                ' . ($statusFilter !== 'all' ? "• Filtered by status: " . strtoupper($statusFilter) : '') . '
                ' . (!empty($httpStatusFilter) ? "• HTTP status: " . $httpStatusFilter : '') . '
                ' . (!empty($dateFromFilter) || !empty($dateToFilter) ? "• Date range applied" : '') . '
            </p>
        </div>
        
        <!-- Results Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">HTTP Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Latency</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">';

if (empty($recentResults)) {
    $content .= '
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No results found with current filters</td>
                    </tr>';
} else {
    foreach ($recentResults as $result) {
        $httpStatusClass = 'http-' . substr($result['http_status'], 0, 1) . '00';
        if ($result['http_status'] == 0) $httpStatusClass = 'http-0';
        
        $hasResponseData = !empty($result['response_headers']) || !empty($result['body_sample']);
        
        $content .= '
                    <tr>
                        <td class="px-6 py-4 text-sm text-gray-900">
                            <div>' . date('M j, Y H:i:s', strtotime($result['started_at'])) . '</div>
                            <div class="text-xs text-gray-500">' . timeAgo($result['started_at']) . '</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="badge ' . ($result['is_up'] ? 'status-up' : 'status-down') . '">
                                ' . ($result['is_up'] ? 'UP' : 'DOWN') . '
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="status-badge ' . $httpStatusClass . '">' . $result['http_status'] . '</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">' . formatDuration($result['duration_ms']) . '</td>
                        <td class="px-6 py-4 text-sm text-red-600">' . 
                            ($result['error_message'] ? htmlspecialchars(substr($result['error_message'], 0, 30)) . '...' : '-') . '</td>
                        <td class="px-6 py-4 text-sm">
                            ' . ($hasResponseData ? 
                                '<button onclick="showResultDetails(' . $result['id'] . ')" 
                                         class="text-indigo-600 hover:text-indigo-900 font-medium">
                                    View Details
                                 </button>' : 
                                '<span class="text-gray-400">No data</span>') . '
                        </td>
                    </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Result Details Modal -->
<div id="resultModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <div id="modalContent">
            <div class="text-center py-4">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                <p class="mt-2 text-gray-600">Loading result details...</p>
            </div>
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

// Modal functions
function showResultDetails(resultId) {
    const modal = document.getElementById("resultModal");
    const modalContent = document.getElementById("modalContent");
    
    modal.style.display = "block";
    modalContent.innerHTML = `
        <div class="text-center py-4">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            <p class="mt-2 text-gray-600">Loading result details...</p>
        </div>
    `;
    
    // Fetch result details via AJAX
    fetch(`/get_result_details.php?id=${resultId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayResultDetails(data.result);
            } else {
                modalContent.innerHTML = `
                    <div class="text-center py-8">
                        <div class="text-red-600 text-xl mb-2">⚠️</div>
                        <p class="text-red-600">Error loading result details</p>
                        <p class="text-gray-500 text-sm mt-2">${data.error || "Unknown error"}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            modalContent.innerHTML = `
                <div class="text-center py-8">
                    <div class="text-red-600 text-xl mb-2">⚠️</div>
                    <p class="text-red-600">Network error loading details</p>
                    <p class="text-gray-500 text-sm mt-2">${error.message}</p>
                </div>
            `;
        });
}

function displayResultDetails(result) {
    const modalContent = document.getElementById("modalContent");
    const statusClass = result.is_up ? "status-up" : "status-down";
    const httpClass = "http-" + result.http_status.toString().substr(0, 1) + "00";
    
    modalContent.innerHTML = `
        <h2 class="text-xl font-bold text-gray-900 mb-4">Check Result Details</h2>
        
        <!-- Summary Info -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-sm text-gray-600">Status</div>
                <div class="font-semibold">
                    <span class="badge ${statusClass}">${result.is_up ? "UP" : "DOWN"}</span>
                </div>
            </div>
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-sm text-gray-600">HTTP Code</div>
                <div class="font-semibold">
                    <span class="status-badge ${httpClass}">${result.http_status}</span>
                </div>
            </div>
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-sm text-gray-600">Response Time</div>
                <div class="font-semibold">${result.duration_ms}ms</div>
            </div>
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-sm text-gray-600">Checked At</div>
                <div class="font-semibold text-sm">${new Date(result.started_at).toLocaleString()}</div>
            </div>
        </div>
        
        ${result.error_message ? `
        <div class="mb-6">
            <h3 class="text-lg font-medium text-gray-900 mb-2">Error Message</h3>
            <div class="response-content bg-red-50 border-red-200 text-red-800">
                ${result.error_message}
            </div>
        </div>
        ` : ""}
        
        ${result.response_headers ? `
        <div class="mb-6">
            <h3 class="text-lg font-medium text-gray-900 mb-2">Response Headers</h3>
            <div class="response-content">
                ${result.response_headers}
            </div>
        </div>
        ` : ""}
        
        ${result.body_sample ? `
        <div class="mb-6">
            <h3 class="text-lg font-medium text-gray-900 mb-2">Response Body</h3>
            <div class="response-content">
                ${result.body_sample}
            </div>
        </div>
        ` : ""}
        
        ${!result.response_headers && !result.body_sample ? `
        <div class="text-center py-8">
            <div class="text-gray-400 text-xl mb-2">📄</div>
            <p class="text-gray-600">No response data available</p>
            <p class="text-gray-500 text-sm mt-2">Response data was not stored for this result</p>
        </div>
        ` : ""}
    `;
}

function closeModal() {
    document.getElementById("resultModal").style.display = "none";
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById("resultModal");
    if (event.target === modal) {
        closeModal();
    }
}

// Quick filter presets
function setQuickFilter(preset) {
    const form = document.querySelector("form");
    const statusSelect = document.getElementById("status");
    const dateFromInput = document.getElementById("date_from");
    const dateToInput = document.getElementById("date_to");
    
    const today = new Date();
    
    switch(preset) {
        case "today":
            dateFromInput.value = today.toISOString().split("T")[0];
            dateToInput.value = today.toISOString().split("T")[0];
            break;
        case "yesterday":
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            dateFromInput.value = yesterday.toISOString().split("T")[0];
            dateToInput.value = yesterday.toISOString().split("T")[0];
            break;
        case "week":
            const weekAgo = new Date(today);
            weekAgo.setDate(weekAgo.getDate() - 7);
            dateFromInput.value = weekAgo.toISOString().split("T")[0];
            dateToInput.value = today.toISOString().split("T")[0];
            break;
        case "errors":
            statusSelect.value = "down";
            break;
    }
    
    form.submit();
}
</script>';

renderTemplate('Check Details', $content);