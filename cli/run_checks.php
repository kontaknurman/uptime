#!/usr/bin/env php
<?php
/**
 * Script to run uptime checks - Simplified Version
 * Usage: 
 * - CLI: php cli/run_checks.php
 * - Web: http://yoursite.com/cli/run_checks.php?key=your_secret_key
 * 
 * This script should be run every minute via cron:
 * * * * * * /usr/bin/php /path/to/uptime-monitor/cli/run_checks.php >> /var/log/uptime-checks.log 2>&1
 */

// Change to script directory
chdir(dirname(__DIR__));

// Security check for web access
$isCLI = php_sapi_name() === 'cli';
if (!$isCLI) {
    // Web access requires secret key
    $secretKey = 'nurman'; // Change this to your own secret
    $providedKey = $_GET['key'] ?? '';
    
    if (empty($providedKey) || !hash_equals($secretKey, $providedKey)) {
        http_response_code(403);
        die('Access denied. This endpoint requires a valid key parameter.');
    }
    
    // Set content type for web output
    header('Content-Type: text/plain');
    
    // Disable time limit for web requests
    set_time_limit(300); // 5 minutes max
}

require_once 'bootstrap.php';

/**
 * Output function that works for both CLI and web
 */
function output($message) {
    $timestamp = "[" . date('Y-m-d H:i:s') . "] ";
    echo $timestamp . $message . "\n";
    
    // Flush output immediately for web requests
    if (php_sapi_name() !== 'cli') {
        ob_flush();
        flush();
    }
}

try {
    output("=== UPTIME MONITOR CHECK RUNNER ===");
    output("Started " . ($isCLI ? "via CLI" : "via web interface"));
    
    // Initialize emailer if SMTP is configured
    $emailer = null;
    if (!empty($config['smtp']['host']) && !empty($config['smtp']['username'])) {
        $emailer = new Emailer($config);
        output("✅ SMTP configured - alerts will be sent");
    } else {
        output("⚠️  SMTP not configured - alerts will not be sent");
    }
    
    // Get checks due for execution (simple query)
    $dueChecks = $db->fetchAll(
        'SELECT id, name, url, next_run_at, interval_seconds FROM checks 
         WHERE enabled = 1 AND next_run_at <= NOW() 
         ORDER BY next_run_at ASC'
    );
    
    $checkCount = count($dueChecks);
    output("Found {$checkCount} checks due for execution");
    
    if ($checkCount > 0) {
        output("Checks to execute:");
        foreach ($dueChecks as $check) {
            $timeUntilRun = strtotime($check['next_run_at']) - time();
            $status = $timeUntilRun <= 0 ? "DUE NOW" : "in {$timeUntilRun}s";
            output("  • {$check['name']} - {$check['url']} ({$status})");
        }
        output("");
    }
    
    // Initialize and run checks
    $runner = new CheckRunner($db, $config, $emailer);
    
    output("Starting check execution...");
    $startTime = microtime(true);
    
    $executed = $runner->runDueChecks();
    
    $duration = round((microtime(true) - $startTime) * 1000);
    output("✅ Successfully executed {$executed} checks in {$duration}ms");
    
    // Show results of executed checks
    if ($executed > 0) {
        output("");
        output("Recent check results:");
        $recentResults = $db->fetchAll(
            "SELECT c.name, cr.http_status, cr.duration_ms, cr.is_up, cr.started_at, cr.error_message
             FROM check_results cr 
             JOIN checks c ON cr.check_id = c.id 
             WHERE cr.started_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
             ORDER BY cr.started_at DESC LIMIT 10"
        );
        
        foreach ($recentResults as $result) {
            $status = $result['is_up'] ? '✅ UP' : '❌ DOWN';
            $latency = $result['duration_ms'] . 'ms';
            $error = $result['error_message'] ? " | Error: " . substr($result['error_message'], 0, 50) : '';
            output("  • {$result['name']}: {$status} (HTTP {$result['http_status']}, {$latency}){$error}");
        }
    }
    
    // Cleanup old results
    output("");
    output("Cleaning up old results...");
    $cleanupSql = "DELETE FROM check_results WHERE started_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $cleaned = $db->query($cleanupSql)->rowCount();
    
    if ($cleaned > 0) {
        output("🧹 Cleaned up {$cleaned} old result records");
    } else {
        output("🧹 No old records to clean");
    }
    
    // Summary statistics
    output("");
    output("=== SYSTEM SUMMARY ===");
    
    $stats = [
        'total_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks"),
        'enabled_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1"),
        'up_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'UP'"),
        'down_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'DOWN'"),
        'open_incidents' => $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'OPEN'"),
        'total_results' => $db->fetchColumn("SELECT COUNT(*) FROM check_results"),
    ];
    
    output("📊 Total checks: {$stats['total_checks']} ({$stats['enabled_checks']} enabled)");
    output("📊 Status: {$stats['up_checks']} UP, {$stats['down_checks']} DOWN");
    output("🚨 Open incidents: {$stats['open_incidents']}");
    output("📋 Total results stored: {$stats['total_results']}");
    
    // Next check schedule
    output("");
    output("=== UPCOMING CHECKS ===");
    $nextChecks = $db->fetchAll(
        "SELECT name, next_run_at FROM checks WHERE enabled = 1 ORDER BY next_run_at ASC LIMIT 5"
    );
    
    foreach ($nextChecks as $check) {
        $nextTime = strtotime($check['next_run_at']);
        $timeUntil = $nextTime - time();
        
        if ($timeUntil <= 0) {
            $timeDisplay = "⏰ DUE NOW";
        } elseif ($timeUntil < 60) {
            $timeDisplay = "⏱️  in {$timeUntil}s";
        } elseif ($timeUntil < 3600) {
            $timeDisplay = "⏱️  in " . floor($timeUntil / 60) . "m";
        } else {
            $timeDisplay = "⏱️  in " . floor($timeUntil / 3600) . "h";
        }
        
        output("  • {$check['name']}: {$check['next_run_at']} {$timeDisplay}");
    }
    
    output("");
    output("✅ Check runner completed successfully!");
    output("Execution completed at: " . date('Y-m-d H:i:s T'));
    
} catch (Exception $e) {
    output("");
    output("❌ ERROR: " . $e->getMessage());
    output("Stack trace:");
    output($e->getTraceAsString());
    
    error_log("Check runner error: " . $e->getMessage());
    if (!$isCLI) {
        http_response_code(500);
    }
    exit(1);
}

if (!$isCLI) {
    output("");
    output("=== USEFUL LINKS ===");
    output("Dashboard: https://{$_SERVER['HTTP_HOST']}/dashboard.php");
    output("Reports: https://{$_SERVER['HTTP_HOST']}/reports.php");
    output("Run checks again: https://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}");
}

exit(0);