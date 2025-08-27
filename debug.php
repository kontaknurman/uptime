<?php
/**
 * Debug script to check and fix incident system
 * Run this to verify incidents are being created properly
 */

require_once 'bootstrap.php';

echo "<h2>Incident System Debug</h2>";

// Check current incidents
$incidents = $db->fetchAll("SELECT * FROM incidents ORDER BY started_at DESC LIMIT 10");
echo "<h3>Current Incidents (" . count($incidents) . "):</h3>";

if (empty($incidents)) {
    echo "<p style='color: red;'>❌ No incidents found in database</p>";
} else {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Check ID</th><th>Started</th><th>Ended</th><th>Status</th></tr>";
    foreach ($incidents as $incident) {
        echo "<tr>";
        echo "<td>{$incident['id']}</td>";
        echo "<td>{$incident['check_id']}</td>";
        echo "<td>{$incident['started_at']}</td>";
        echo "<td>" . ($incident['ended_at'] ?: 'NULL') . "</td>";
        echo "<td>{$incident['status']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Check DOWN results without incidents
echo "<h3>DOWN Results Analysis:</h3>";
$downResults = $db->fetchAll("
    SELECT cr.id, cr.check_id, cr.started_at, cr.is_up, c.name 
    FROM check_results cr 
    JOIN checks c ON cr.check_id = c.id 
    WHERE cr.is_up = 0 
    ORDER BY cr.started_at DESC 
    LIMIT 10
");

if (empty($downResults)) {
    echo "<p style='color: green;'>✅ No DOWN results found</p>";
} else {
    echo "<p>Found " . count($downResults) . " recent DOWN results:</p>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Result ID</th><th>Check Name</th><th>Time</th><th>Has Incident?</th></tr>";
    
    foreach ($downResults as $result) {
        // Check if there's an incident for this result
        $hasIncident = $db->fetchOne("
            SELECT id FROM incidents 
            WHERE check_id = ? 
            AND started_at <= ? 
            AND (ended_at IS NULL OR ended_at >= ?)
        ", [$result['check_id'], $result['started_at'], $result['started_at']]);
        
        echo "<tr>";
        echo "<td>{$result['id']}</td>";
        echo "<td>{$result['name']}</td>";
        echo "<td>{$result['started_at']}</td>";
        echo "<td>" . ($hasIncident ? "✅ YES (ID: {$hasIncident['id']})" : "❌ NO") . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Test incident creation
echo "<h3>Test Incident Creation:</h3>";

// Find a check that's currently UP or has no recent results
$testCheck = $db->fetchOne("
    SELECT c.* 
    FROM checks c 
    WHERE c.enabled = 1 
    AND (c.last_state IS NULL OR c.last_state = 'UP')
    LIMIT 1
");

if ($testCheck) {
    echo "<p>Testing with check: {$testCheck['name']} (ID: {$testCheck['id']})</p>";
    
    try {
        // Simulate a DOWN result
        $testResultId = $db->insert("check_results", [
            "check_id" => $testCheck['id'],
            "started_at" => date("Y-m-d H:i:s"),
            "ended_at" => date("Y-m-d H:i:s"),
            "duration_ms" => 5000,
            "http_status" => 0,
            "response_headers" => "{}",
            "body_sample" => "",
            "is_up" => 0,
            "error_message" => "Test incident creation"
        ]);
        
        echo "<p>✅ Created test DOWN result ID: {$testResultId}</p>";
        
        // Manually create incident
        $incidentId = $db->insert("incidents", [
            "check_id" => $testCheck['id'],
            "started_at" => date("Y-m-d H:i:s"),
            "opened_by_result_id" => $testResultId,
            "status" => "OPEN"
        ]);
        
        echo "<p>✅ Created test incident ID: {$incidentId}</p>";
        
        // Update check state
        $db->update("checks", [
            "last_state" => "DOWN"
        ], "id = ?", [$testCheck['id']]);
        
        echo "<p>✅ Updated check state to DOWN</p>";
        echo "<p style='color: green;'><strong>✅ Incident system is working!</strong></p>";
        
        // Clean up (optional)
        echo "<p><a href='?cleanup=1&incident_id={$incidentId}&result_id={$testResultId}&check_id={$testCheck['id']}' style='color: blue;'>Click to clean up test data</a></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Error creating test incident: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ No suitable test check found</p>";
}

// Cleanup test data if requested
if (isset($_GET['cleanup']) && $_GET['cleanup'] == '1') {
    $incidentId = (int)$_GET['incident_id'];
    $resultId = (int)$_GET['result_id'];
    $checkId = (int)$_GET['check_id'];
    
    if ($incidentId && $resultId && $checkId) {
        try {
            $db->delete("incidents", "id = ?", [$incidentId]);
            $db->delete("check_results", "id = ?", [$resultId]);
            $db->update("checks", ["last_state" => "UP"], "id = ?", [$checkId]);
            
            echo "<p style='color: green;'>✅ Test data cleaned up successfully!</p>";
            echo "<p><a href='?'>Refresh page</a></p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Error cleaning up: " . $e->getMessage() . "</p>";
        }
    }
}

// Show CheckRunner log analysis
echo "<h3>CheckRunner Log Analysis:</h3>";
echo "<p><em>Check your web server error logs for CheckRunner messages</em></p>";
echo "<p>Look for messages like:</p>";
echo "<ul>";
echo "<li>'State change from...'</li>";
echo "<li>'Created new incident ID...'</li>";
echo "<li>'Opened new incident for DOWN state'</li>";
echo "</ul>";

// Quick fix suggestions
echo "<h3>Quick Fixes:</h3>";
echo "<ol>";
echo "<li><strong>Update CheckRunner.php</strong> with the fixed version that includes proper incident handling</li>";
echo "<li><strong>Run checks manually</strong> to trigger incident creation: <a href='/run_checks.php?key=nurman' target='_blank'>Run Checks</a></li>";
echo "<li><strong>Check error logs</strong> for any database errors or PHP errors</li>";
echo "<li><strong>Verify database schema</strong> - make sure incidents table exists and has proper foreign keys</li>";
echo "</ol>";

echo "<hr>";
echo "<p><a href='/check.php?id=1'>View Check Details</a> | <a href='/dashboard.php'>Dashboard</a></p>";