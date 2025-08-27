<?php
/**
 * Debug script to test why DOWN alerts aren't being sent
 */

require_once 'bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>";
echo "=== UPTIME MONITOR ALERT DEBUG ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Check SMTP Configuration
echo "1. SMTP CONFIGURATION CHECK\n";
echo str_repeat('-', 50) . "\n";
$smtp = $config['smtp'] ?? [];
echo "Host: " . ($smtp['host'] ?? 'NOT SET') . "\n";
echo "Port: " . ($smtp['port'] ?? 'NOT SET') . "\n";
echo "Username: " . ($smtp['username'] ?? 'NOT SET') . "\n";
echo "Password: " . (empty($smtp['password']) ? 'NOT SET' : '***SET***') . "\n";
echo "From Email: " . ($smtp['from_email'] ?? 'NOT SET') . "\n";

$smtp_ok = !empty($smtp['host']) && !empty($smtp['username']) && !empty($smtp['password']);
echo "Status: " . ($smtp_ok ? "✅ CONFIGURED" : "❌ NOT CONFIGURED") . "\n\n";

// 2. Check if Emailer is being initialized
echo "2. EMAILER INITIALIZATION CHECK\n";
echo str_repeat('-', 50) . "\n";

// Test Emailer instantiation
try {
    $testEmailer = new Emailer($config);
    echo "✅ Emailer class instantiated successfully\n";
    
    // Test the emailer
    $testResult = $testEmailer->test();
    echo "Connection: " . ($testResult['connection'] ? '✅' : '❌') . "\n";
    echo "Authentication: " . ($testResult['authentication'] ? '✅' : '❌') . "\n";
    if (!empty($testResult['errors'])) {
        echo "Errors: " . implode(', ', $testResult['errors']) . "\n";
    }
} catch (Exception $e) {
    echo "❌ Emailer instantiation failed: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. Check for checks with alert emails
echo "3. CHECKS WITH ALERT EMAILS\n";
echo str_repeat('-', 50) . "\n";

$checks_with_emails = $db->fetchAll(
    "SELECT id, name, url, alert_emails, enabled, last_state 
     FROM checks 
     WHERE alert_emails IS NOT NULL AND alert_emails != ''"
);

if (empty($checks_with_emails)) {
    echo "❌ NO CHECKS HAVE ALERT EMAILS CONFIGURED!\n";
    echo "This is why you're not getting emails!\n";
} else {
    echo "Found " . count($checks_with_emails) . " check(s) with alert emails:\n\n";
    foreach ($checks_with_emails as $check) {
        echo "ID: {$check['id']}\n";
        echo "Name: {$check['name']}\n";
        echo "URL: {$check['url']}\n";
        echo "Alert Emails: {$check['alert_emails']}\n";
        echo "Enabled: " . ($check['enabled'] ? 'Yes' : 'No') . "\n";
        echo "Last State: " . ($check['last_state'] ?: 'NULL') . "\n";
        echo "\n";
    }
}

// 4. Check recent DOWN results
echo "4. RECENT DOWN EVENTS (Last 24 hours)\n";
echo str_repeat('-', 50) . "\n";

$down_events = $db->fetchAll(
    "SELECT cr.*, c.name, c.alert_emails 
     FROM check_results cr
     JOIN checks c ON cr.check_id = c.id
     WHERE cr.is_up = 0 
     AND cr.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
     ORDER BY cr.started_at DESC
     LIMIT 10"
);

if (empty($down_events)) {
    echo "No DOWN events in the last 24 hours\n";
} else {
    echo "Found " . count($down_events) . " DOWN event(s):\n\n";
    foreach ($down_events as $event) {
        echo "Check: {$event['name']}\n";
        echo "Time: {$event['started_at']}\n";
        echo "HTTP Status: {$event['http_status']}\n";
        echo "Alert Emails: " . ($event['alert_emails'] ?: 'NONE') . "\n";
        echo "Should have sent alert: " . (!empty($event['alert_emails']) ? 'YES' : 'NO') . "\n";
        echo "\n";
    }
}

// 5. Test CheckRunner with a specific check
echo "5. SIMULATE DOWN ALERT TEST\n";
echo str_repeat('-', 50) . "\n";

// Find or create a test check
$test_check = $db->fetchOne(
    "SELECT * FROM checks WHERE name LIKE '%test%' LIMIT 1"
);

if (!$test_check) {
    // Create a test check
    echo "Creating test check...\n";
    $test_check_id = $db->insert('checks', [
        'name' => 'Alert Test Check',
        'url' => 'https://this-will-fail-99999.com',
        'method' => 'GET',
        'expected_status' => 200,
        'interval_seconds' => 300,
        'timeout_seconds' => 10,
        'alert_emails' => $smtp['username'] ?? 'test@example.com', // Use SMTP username as test email
        'enabled' => 1,
        'last_state' => 'UP', // Set to UP so transition to DOWN triggers alert
        'next_run_at' => date('Y-m-d H:i:s')
    ]);
    
    $test_check = $db->fetchOne("SELECT * FROM checks WHERE id = ?", [$test_check_id]);
    echo "Created test check ID: {$test_check_id}\n";
}

echo "\nTest Check Details:\n";
echo "ID: {$test_check['id']}\n";
echo "Name: {$test_check['name']}\n";
echo "URL: {$test_check['url']}\n";
echo "Alert Emails: {$test_check['alert_emails']}\n";
echo "Last State: " . ($test_check['last_state'] ?: 'NULL') . "\n";

// 6. Run the check manually
echo "\n6. RUNNING CHECK MANUALLY\n";
echo str_repeat('-', 50) . "\n";

// Initialize CheckRunner
$emailer = null;
if ($smtp_ok) {
    try {
        $emailer = new Emailer($config);
        echo "✅ Emailer initialized for CheckRunner\n";
    } catch (Exception $e) {
        echo "❌ Failed to initialize Emailer: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Emailer NOT initialized (SMTP not configured)\n";
}

$runner = new CheckRunner($db, $config, $emailer);

// Execute the specific check
echo "\nExecuting check ID {$test_check['id']}...\n";

// Make sure it's set to UP first (to trigger DOWN alert)
$db->update('checks', ['last_state' => 'UP'], 'id = ?', [$test_check['id']]);

// Use reflection to call private method (for testing)
$reflection = new ReflectionClass($runner);
$method = $reflection->getMethod('executeChecksBatch');
$method->setAccessible(true);

$results = $method->invoke($runner, [$test_check]);

echo "Check executed. Result: " . (isset($results[$test_check['id']]) && $results[$test_check['id']] ? 'Success' : 'Failed') . "\n";

// 7. Check error logs
echo "\n7. CHECKING PHP ERROR LOG\n";
echo str_repeat('-', 50) . "\n";

// Get last 20 lines from error log
$error_log = ini_get('error_log');
if ($error_log && file_exists($error_log)) {
    $lines = array_slice(file($error_log), -20);
    echo "Last 20 lines from error log:\n\n";
    foreach ($lines as $line) {
        if (stripos($line, 'CheckRunner') !== false || stripos($line, 'Emailer') !== false) {
            echo trim($line) . "\n";
        }
    }
} else {
    echo "Could not read error log\n";
}

// 8. Direct email test
echo "\n8. DIRECT EMAIL SEND TEST\n";
echo str_repeat('-', 50) . "\n";

if ($smtp_ok && $emailer) {
    $test_email = $_GET['email'] ?? $smtp['username'];
    echo "Sending test email to: {$test_email}\n";
    
    try {
        $result = $emailer->send(
            $test_email,
            "[Uptime Monitor] DOWN: Test Alert",
            "This is a test DOWN alert.\n\nIf you receive this, email alerts are working!"
        );
        
        echo $result ? "✅ Email sent successfully!\n" : "❌ Email send failed\n";
    } catch (Exception $e) {
        echo "❌ Exception: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Cannot test - SMTP not configured or Emailer not initialized\n";
}

// 9. Check if run_checks.php is being executed
echo "\n9. CHECK RUNNER EXECUTION\n";
echo str_repeat('-', 50) . "\n";

$last_check_run = $db->fetchOne(
    "SELECT MAX(started_at) as last_run, COUNT(*) as total 
     FROM check_results 
     WHERE started_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
);

if ($last_check_run && $last_check_run['total'] > 0) {
    echo "✅ Checks are running\n";
    echo "Last run: {$last_check_run['last_run']}\n";
    echo "Checks in last hour: {$last_check_run['total']}\n";
} else {
    echo "❌ No checks have run in the last hour!\n";
    echo "Make sure cron job is configured or run manually:\n";
    echo "php cli/run_checks.php\n";
}

// 10. Final diagnosis
echo "\n10. DIAGNOSIS SUMMARY\n";
echo str_repeat('-', 50) . "\n";

$issues = [];

if (!$smtp_ok) {
    $issues[] = "SMTP not configured properly";
}

if (empty($checks_with_emails)) {
    $issues[] = "No checks have alert emails configured";
}

if (!$emailer) {
    $issues[] = "Emailer not being initialized in CheckRunner";
}

if ($last_check_run && $last_check_run['total'] == 0) {
    $issues[] = "Checks not running (cron issue?)";
}

if (empty($issues)) {
    echo "✅ Everything appears configured correctly\n";
    echo "\nPossible issues:\n";
    echo "- Emails going to spam\n";
    echo "- SMTP2GO blocking/queuing\n";
    echo "- State not transitioning (already DOWN)\n";
} else {
    echo "❌ Issues found:\n";
    foreach ($issues as $issue) {
        echo "- {$issue}\n";
    }
}

echo "\n=== NEXT STEPS ===\n";
echo "1. Make sure at least one check has alert emails configured\n";
echo "2. Ensure the check transitions from UP to DOWN (not already DOWN)\n";
echo "3. Run: php cli/run_checks.php\n";
echo "4. Check SMTP2GO dashboard for email status\n";
echo "5. Check spam folder\n";

echo "\n=== TEST URL ===\n";
echo "To test with your email: ?email=your-email@example.com\n";

echo "</pre>";

// Add a button to force run checks
if (!isset($_GET['run'])) {
    echo '<form method="get">';
    echo '<input type="hidden" name="run" value="1">';
    echo '<button type="submit" style="padding: 10px 20px; background: #4CAF50; color: white; border: none; cursor: pointer;">Run Checks Now</button>';
    echo '</form>';
} else {
    echo '<div style="padding: 10px; background: #fffacd;">';
    echo 'Running checks now... Check the output above for results.';
    echo '</div>';
    
    // Run checks
    system("php cli/run_checks.php 2>&1");
}
?>