<?php
/**
 * Web-accessible check runner
 * URL: http://yoursite.com/run_checks.php?key=your_secret_key
 */

require_once 'bootstrap.php';

// Security check - require secret key
$secretKey = 'nurman'; // Change this!
$providedKey = $_GET['key'] ?? '';

if (empty($providedKey) || !hash_equals($secretKey, $providedKey)) {
    http_response_code(403);
    die('Access denied. This endpoint requires a valid key parameter.');
}

// Set headers for plain text output
header('Content-Type: text/plain');
set_time_limit(300); // 5 minutes max

// Function to output with timestamp
function webOutput($message) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
    ob_flush();
    flush();
}

try {
    webOutput("=== UPTIME MONITOR CHECK RUNNER ===");
    webOutput("Started via web interface");
    
    // Check authentication
    if (!$auth->isLoggedIn()) {
        webOutput("WARNING: Not authenticated - running in anonymous mode");
    } else {
        $user = $auth->getCurrentUser();
        webOutput("Authenticated as: " . $user['email']);
    }
    
    // Initialize emailer if SMTP is configured
    $emailer = null;
    if (!empty($config['smtp']['host']) && !empty($config['smtp']['username'])) {
        $emailer = new Emailer($config);
        webOutput("✅ SMTP configured - alerts will be sent");
    } else {
        webOutput("⚠️  SMTP not configured - alerts will not be sent");
    }
    
    // Get checks due for execution - fix the query to actually find overdue checks
    $dueChecks = $db->fetchAll(
        'SELECT id, name, url, last_state, next_run_at, interval_seconds FROM checks WHERE enabled = 1 AND next_run_at <= NOW() ORDER BY next_run_at ASC'
    );
    
    webOutput("Found " . count($dueChecks) . " checks due for execution");
    
    // If no checks found by the query, let's debug why
    if (empty($dueChecks)) {
        webOutput("No checks found by due query. Checking all enabled checks...");
        
        $allEnabledChecks = $db->fetchAll(
            'SELECT id, name, url, next_run_at, interval_seconds, enabled FROM checks WHERE enabled = 1 ORDER BY next_run_at ASC'
        );
        
        webOutput("Found " . count($allEnabledChecks) . " enabled checks total:");
        
        $now = time();
        $forceRunIds = [];
        
        foreach ($allEnabledChecks as $check) {
            $nextTime = strtotime($check['next_run_at']);
            $overdueSeconds = $now - $nextTime;
            $overdueMinutes = floor($overdueSeconds / 60);
            
            if ($overdueSeconds > 0) {
                webOutput("  • OVERDUE: {$check['name']} - overdue by {$overdueMinutes}m");
                $forceRunIds[] = $check['id'];
            } else {
                webOutput("  • FUTURE: {$check['name']} - due in " . abs($overdueMinutes) . "m");
            }
        }
        
        // Force run overdue checks
        if (!empty($forceRunIds)) {
            webOutput("");
            webOutput("🔄 Force-running " . count($forceRunIds) . " overdue checks...");
            
            $placeholders = str_repeat('?,', count($forceRunIds) - 1) . '?';
            $dueChecks = $db->fetchAll(
                "SELECT id, name, url, last_state, next_run_at, interval_seconds FROM checks WHERE id IN ({$placeholders}) ORDER BY next_run_at ASC",
                $forceRunIds
            );
            
            webOutput("Force-selected " . count($dueChecks) . " checks for execution");
        }
    }
    
    if (!empty($dueChecks)) {
        webOutput("Checks to execute:");
        foreach ($dueChecks as $check) {
            $nextTime = strtotime($check['next_run_at']);
            $overdueMinutes = floor((time() - $nextTime) / 60);
            $status = $check['last_state'] ? "({$check['last_state']})" : "(NEW)";
            $overdueText = $overdueMinutes > 0 ? " - OVERDUE by {$overdueMinutes}m" : "";
            
            webOutput("  • {$check['name']} - {$check['url']} {$status}{$overdueText}");
        }
        webOutput("");
    }
    
    // Reset severely overdue checks before execution
    $resetCount = 0;
    foreach ($dueChecks as $check) {
        $timeSinceScheduled = time() - strtotime($check['next_run_at']);
        $overdueThreshold = $check['interval_seconds'] * 2;
        
        if ($timeSinceScheduled > $overdueThreshold) {
            $db->update('checks', [
                'next_run_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$check['id']]);
            
            $resetCount++;
            webOutput("🔄 Reset severely overdue check: {$check['name']} (was overdue by " . 
                     floor($timeSinceScheduled / 60) . " minutes)");
        }
    }
    
    if ($resetCount > 0) {
        webOutput("Reset {$resetCount} severely overdue checks");
        webOutput("");
    }
    
    // Initialize and run checks
    $runner = new CheckRunner($db, $config, $emailer);
    
    webOutput("Starting check execution...");
    $startTime = microtime(true);
    
    $executed = $runner->runDueChecks();
    
    $duration = round((microtime(true) - $startTime) * 1000);
    webOutput("✅ Successfully executed {$executed} checks in {$duration}ms");
    
    // Show results of executed checks
    if ($executed > 0) {
        webOutput("Recent check results:");
        $recentResults = $db->fetchAll(
            "SELECT c.name, cr.http_status, cr.duration_ms, cr.is_up, cr.started_at, cr.error_message
             FROM check_results cr 
             JOIN checks c ON cr.check_id = c.id 
             WHERE cr.started_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY cr.started_at DESC LIMIT 10"
        );
        
        foreach ($recentResults as $result) {
            $status = $result['is_up'] ? '✅ UP' : '❌ DOWN';
            $latency = $result['duration_ms'] . 'ms';
            $error = $result['error_message'] ? " | Error: " . substr($result['error_message'], 0, 50) : '';
            webOutput("  • {$result['name']}: {$status} (HTTP {$result['http_status']}, {$latency}){$error}");
        }
    }
    
    // Cleanup old results
    webOutput("");
    webOutput("Cleaning up old results...");
    $cleanupSql = "DELETE FROM check_results WHERE started_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $cleaned = $db->query($cleanupSql)->rowCount();
    
    if ($cleaned > 0) {
        webOutput(" Cleaned up {$cleaned} old result records");
    } else {
        webOutput("🧹 No old records to clean");
    }
    
    // Summary statistics
    webOutput("");
    webOutput("=== SYSTEM SUMMARY ===");
    
    $stats = [
        'total_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks"),
        'enabled_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1"),
        'up_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'UP'"),
        'down_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'DOWN'"),
        'open_incidents' => $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'OPEN'"),
        'total_results' => $db->fetchColumn("SELECT COUNT(*) FROM check_results"),
    ];
    
    webOutput("📊 Total checks: {$stats['total_checks']} ({$stats['enabled_checks']} enabled)");
    webOutput(" Status: {$stats['up_checks']} UP, {$stats['down_checks']} DOWN");
    webOutput("🚨 Open incidents: {$stats['open_incidents']}");
    webOutput("📋 Total results stored: {$stats['total_results']}");
    
    // Next check schedule
    webOutput("");
    webOutput("=== UPCOMING CHECKS ===");
    $nextChecks = $db->fetchAll(
        "SELECT name, next_run_at FROM checks WHERE enabled = 1 ORDER BY next_run_at ASC LIMIT 5"
    );
    
    foreach ($nextChecks as $check) {
        $nextTime = strtotime($check['next_run_at']);
        $timeUntil = $nextTime - time();
        
        if ($timeUntil <= 0) {
            $timeDisplay = "⏰ OVERDUE";
        } elseif ($timeUntil < 60) {
            $timeDisplay = "⏱️  in {$timeUntil}s";
        } elseif ($timeUntil < 3600) {
            $timeDisplay = "⏱️  in " . floor($timeUntil / 60) . "m";
        } else {
            $timeDisplay = "️  in " . floor($timeUntil / 3600) . "h";
        }
        
        webOutput("  • {$check['name']}: {$check['next_run_at']} {$timeDisplay}");
    }
    
    webOutput("");
    webOutput("✅ Check runner completed successfully!");
    webOutput("Execution completed at: " . date('Y-m-d H:i:s T'));
    
} catch (Exception $e) {
    webOutput("");
    webOutput(" ERROR: " . $e->getMessage());
    webOutput("Stack trace:");
    webOutput($e->getTraceAsString());
    
    error_log("Web check runner error: " . $e->getMessage());
    http_response_code(500);
}

webOutput("");
webOutput("=== USEFUL LINKS ===");
webOutput("Dashboard: https://{$_SERVER['HTTP_HOST']}/dashboard.php");
webOutput("Reports: https://{$_SERVER['HTTP_HOST']}/reports.php");
webOutput("Run checks again: https://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}");