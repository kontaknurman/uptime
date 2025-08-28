<?php
/**
 * Universal check runner - Works from both CLI and Web
 * CLI: php cli/run_checks.php
 * Web: https://yoursite.com/run_checks.php?key=nurman
 */

// Detect if running from web or CLI
$isWeb = php_sapi_name() !== 'cli';
$baseDir = $isWeb ? __DIR__ : __DIR__ . '/..';

// Load bootstrap
require_once $baseDir . '/bootstrap.php';

// Web access security check
if ($isWeb) {
    $secretKey = 'nurman'; // Change this to something more secure!
    $providedKey = $_GET['key'] ?? '';
    
    if (empty($providedKey) || !hash_equals($secretKey, $providedKey)) {
        http_response_code(403);
        die('Access denied. This endpoint requires a valid key parameter.');
    }
    
    // Set headers for plain text output
    header('Content-Type: text/plain; charset=UTF-8');
    ob_implicit_flush(true);
    ob_end_flush();
}

// Set execution time limit
set_time_limit(300); // 5 minutes

// Output function that works for both CLI and Web
function output($message) {
    global $isWeb;
    
    $timestamp = date('Y-m-d H:i:s');
    $output = "[{$timestamp}] {$message}\n";
    
    if ($isWeb) {
        echo $output;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    } else {
        echo $output;
    }
}

// Main execution
try {
    output("=== UPTIME MONITOR CHECK RUNNER ===");
    output("Started via " . ($isWeb ? "web interface" : "CLI"));
    
    // Initialize emailer with SMTP2GO if configured
    $emailer = null;
    $emailerStatus = "disabled";
    
    // Check for SMTP2GO configuration first
    if (!empty($config['smtp2go']['api_key']) && $config['smtp2go']['api_key'] !== 'api-YOUR_API_KEY_HERE') {
        try {
            $emailer = new Emailer($config);
            output("Testing SMTP2GO connection...");
            
            $testResult = $emailer->test();
            if ($testResult['authentication']) {
                $emailerStatus = "SMTP2GO";
                output("✅ SMTP2GO API configured and authenticated - alerts enabled");
            } else {
                output("⚠️ SMTP2GO API key invalid - alerts disabled");
                if (!empty($testResult['errors'])) {
                    foreach ($testResult['errors'] as $error) {
                        output("  Error: {$error}");
                    }
                }
                $emailer = null;
            }
        } catch (Exception $e) {
            output("⚠️ Failed to initialize SMTP2GO: " . $e->getMessage());
            $emailer = null;
        }
    } else {
        output("⚠️ SMTP2GO not configured - alerts disabled");
        output("  To enable: Add your API key to config/config.php");
    }
    
    // Get checks due for execution
    output("");
    output("Checking for due checks...");
    
    $dueChecks = $db->fetchAll(
        'SELECT * FROM checks 
         WHERE enabled = 1 AND next_run_at <= NOW() 
         ORDER BY next_run_at ASC'
    );
    
    output("Found " . count($dueChecks) . " check(s) due for execution");
    
    if (!empty($dueChecks)) {
        output("");
        output("Checks to execute:");
        foreach ($dueChecks as $check) {
            $nextTime = strtotime($check['next_run_at']);
            $timeDiff = time() - $nextTime;
            $overdue = $timeDiff > 0 ? "overdue by " . $timeDiff . "s" : "due now";
            $alerts = !empty($check['alert_emails']) ? " [Alerts: ON]" : " [Alerts: OFF]";
            
            output("  • {$check['name']} - {$check['url']} ({$overdue}){$alerts}");
        }
    }
    
    // Check if any checks have alert emails configured
    if ($emailer) {
        $checksWithAlerts = $db->fetchOne(
            "SELECT COUNT(*) as count FROM checks 
             WHERE enabled = 1 AND alert_emails IS NOT NULL AND alert_emails != ''"
        );
        
        if ($checksWithAlerts && $checksWithAlerts['count'] > 0) {
            output("");
            output("📧 {$checksWithAlerts['count']} check(s) have alert emails configured");
        } else {
            output("");
            output("⚠️ No checks have alert emails configured - no alerts will be sent");
        }
    }
    
    // Initialize CheckRunner
    $runner = new CheckRunner($db, $config, $emailer);
    
    output("");
    output("Starting check execution...");
    $startTime = microtime(true);
    
    // Run the checks using the correct method
    $executed = $runner->runDueChecks();
    
    $duration = round((microtime(true) - $startTime) * 1000);
    output("✅ Executed {$executed} check(s) in {$duration}ms");
    
    // Show results of executed checks
    if ($executed > 0) {
        output("");
        output("Check results:");
        
        $recentResults = $db->fetchAll(
            "SELECT c.name, c.url, cr.http_status, cr.duration_ms, cr.is_up, 
                    cr.started_at, cr.error_message
             FROM check_results cr 
             JOIN checks c ON cr.check_id = c.id 
             WHERE cr.started_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
             ORDER BY cr.started_at DESC 
             LIMIT 10"
        );
        
        foreach ($recentResults as $result) {
            $status = $result['is_up'] ? '✅ UP' : '❌ DOWN';
            $latency = $result['duration_ms'] . 'ms';
            $httpCode = $result['http_status'] > 0 ? "HTTP {$result['http_status']}" : "No response";
            
            $message = "  • {$result['name']}: {$status} ({$httpCode}, {$latency})";
            
            if (!$result['is_up'] && $result['error_message']) {
                $error = substr($result['error_message'], 0, 80);
                $message .= "\n    Error: {$error}";
            }
            
            output($message);
        }
    }
    
    // Check for open incidents
    output("");
    output("=== INCIDENT STATUS ===");
    
    $openIncidents = $db->fetchAll(
        "SELECT i.*, c.name as check_name, c.alert_emails 
         FROM incidents i 
         JOIN checks c ON i.check_id = c.id 
         WHERE i.status = 'OPEN' 
         ORDER BY i.started_at DESC"
    );
    
    if (empty($openIncidents)) {
        output("✅ No open incidents");
    } else {
        output("🚨 " . count($openIncidents) . " open incident(s):");
        foreach ($openIncidents as $incident) {
            $duration = time() - strtotime($incident['started_at']);
            $durationStr = $duration < 3600 ? 
                floor($duration / 60) . " minutes" : 
                floor($duration / 3600) . " hours";
            
            $alertStatus = !empty($incident['alert_emails']) ? "alerts sent" : "no alerts";
            output("  • {$incident['check_name']} - DOWN for {$durationStr} ({$alertStatus})");
        }
    }
    
    // Cleanup old results (optional)
    if ($isWeb || !empty($_GET['cleanup'])) {
        output("");
        output("Cleaning up old results...");
        
        $cleaned = $db->query(
            "DELETE FROM check_results 
             WHERE started_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->rowCount();
        
        if ($cleaned > 0) {
            output("🧹 Cleaned {$cleaned} old result records");
        }
    }
    
    // Summary statistics
    output("");
    output("=== SYSTEM SUMMARY ===");
    
    $stats = [
        'total' => $db->fetchColumn("SELECT COUNT(*) FROM checks"),
        'enabled' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1"),
        'up' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'UP'"),
        'down' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'DOWN'"),
        'incidents' => $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'OPEN'"),
    ];
    
    output("📊 Checks: {$stats['total']} total, {$stats['enabled']} enabled");
    output("📊 Status: {$stats['up']} UP, {$stats['down']} DOWN");
    output("🚨 Open incidents: {$stats['incidents']}");
    output("📧 Alert system: {$emailerStatus}");
    
    // Show next scheduled checks
    output("");
    output("=== NEXT SCHEDULED CHECKS ===");
    
    $nextChecks = $db->fetchAll(
        "SELECT name, url, next_run_at, interval_seconds 
         FROM checks 
         WHERE enabled = 1 
         ORDER BY next_run_at ASC 
         LIMIT 5"
    );
    
    foreach ($nextChecks as $check) {
        $nextTime = strtotime($check['next_run_at']);
        $timeUntil = $nextTime - time();
        
        if ($timeUntil <= 0) {
            $timeStr = "DUE NOW";
        } elseif ($timeUntil < 60) {
            $timeStr = "in {$timeUntil}s";
        } elseif ($timeUntil < 3600) {
            $timeStr = "in " . floor($timeUntil / 60) . "m";
        } else {
            $timeStr = "in " . floor($timeUntil / 3600) . "h";
        }
        
        output("  • {$check['name']}: {$timeStr}");
    }
    
    output("");
    output("✅ Check runner completed successfully!");
    output("Completed at: " . date('Y-m-d H:i:s T'));
    
    // Web-specific output
    if ($isWeb) {
        output("");
        output("=== USEFUL LINKS ===");
        $baseUrl = "https://{$_SERVER['HTTP_HOST']}";
        output("Dashboard: {$baseUrl}/dashboard.php");
        output("Test Alerts: {$baseUrl}/test_alerts.php");
        output("Test SMTP2GO: {$baseUrl}/test_smtp2go.php");
        output("Run again: {$baseUrl}{$_SERVER['REQUEST_URI']}");
        
        output("");
        output("=== CRON SETUP ===");
        output("To automate checks, add this to crontab:");
        output("*/1 * * * * curl -s '{$baseUrl}/run_checks.php?key={$secretKey}' > /dev/null");
    }
    
    // Exit with appropriate code
    exit(0);
    
} catch (Exception $e) {
    output("");
    output("❌ FATAL ERROR: " . $e->getMessage());
    output("Stack trace:");
    output($e->getTraceAsString());
    
    error_log("Check runner error: " . $e->getMessage());
    
    if ($isWeb) {
        http_response_code(500);
    }
    
    exit(2);
}