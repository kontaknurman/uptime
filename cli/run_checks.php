#!/usr/bin/env php
<?php
/**
 * Script to run uptime checks
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
    output("Starting check runner");
    
    // Initialize emailer if SMTP is configured
    $emailer = null;
    if (!empty($config['smtp']['host']) && !empty($config['smtp']['username'])) {
        $emailer = new Emailer($config);
        output("SMTP configured - alerts will be sent");
    } else {
        output("WARNING: SMTP not configured - alerts will not be sent");
    }
    
    // Initialize check runner
    $runner = new CheckRunner($db, $config, $emailer);
    
    // Get list of due checks before execution
    $dueChecks = $db->fetchAll(
        'SELECT id, name, url FROM checks WHERE enabled = 1 AND next_run_at <= NOW() ORDER BY next_run_at ASC'
    );
    
    if (empty($dueChecks)) {
        output("No checks due for execution");
    } else {
        output("Found " . count($dueChecks) . " checks due for execution:");
        foreach ($dueChecks as $check) {
            output("  - {$check['name']} ({$check['url']})");
        }
    }
    
    // Run due checks
    $executed = $runner->runDueChecks();
    
    output("Successfully executed {$executed} checks");
    
    // Clean up old results (keep last 7 days)
    $cleanupSql = "DELETE FROM check_results WHERE started_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $cleaned = $db->query($cleanupSql)->rowCount();
    
    if ($cleaned > 0) {
        output("Cleaned up {$cleaned} old result records");
    }
    
    // Show summary statistics
    $totalChecks = $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1");
    $upChecks = $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'UP'");
    $downChecks = $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'DOWN'");
    $openIncidents = $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'OPEN'");
    
    output("Summary: {$totalChecks} total checks, {$upChecks} UP, {$downChecks} DOWN, {$openIncidents} open incidents");
    
    // Show next check times
    $nextChecks = $db->fetchAll(
        "SELECT name, next_run_at FROM checks WHERE enabled = 1 ORDER BY next_run_at ASC LIMIT 5"
    );
    
    if (!empty($nextChecks)) {
        output("Next checks due:");
        foreach ($nextChecks as $check) {
            $timeUntil = strtotime($check['next_run_at']) - time();
            $timeDisplay = $timeUntil > 0 ? "in " . floor($timeUntil / 60) . "m" : "overdue";
            output("  - {$check['name']}: {$check['next_run_at']} ({$timeDisplay})");
        }
    }
    
    output("Check runner completed successfully");
    
} catch (Exception $e) {
    $errorMsg = "ERROR: " . $e->getMessage();
    output($errorMsg);
    error_log($errorMsg);
    
    if (!$isCLI) {
        http_response_code(500);
    }
    exit(1);
}

if (!$isCLI) {
    echo "\n---\nExecution completed at " . date('Y-m-d H:i:s') . "\n";
    echo "You can bookmark this URL for manual check execution:\n";
    echo "https://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}\n";
}

exit(0);
