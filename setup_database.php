<?php
/**
 * Database setup script - creates tables and initial data
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load classes manually
require_once 'lib/Database.php';

// Load configuration
if (!file_exists('config/config.php')) {
    die('Configuration file not found. Please create config/config.php first.');
}

$config = require 'config/config.php';

echo "<h2>Database Setup</h2>";

try {
    // Connect to database
    $db = Database::getInstance($config);
    echo "<p>✅ Connected to database: {$config['database']['name']}</p>";
    
    // Check which tables exist
    $existingTables = [];
    $result = $db->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $existingTables[] = $row[0];
    }
    
    echo "<h3>Existing Tables:</h3>";
    if (empty($existingTables)) {
        echo "<p>❌ No tables found</p>";
    } else {
        foreach ($existingTables as $table) {
            echo "<p>✅ {$table}</p>";
        }
    }
    
    $requiredTables = ['users', 'checks', 'check_results', 'incidents', 'migrations'];
    $missingTables = array_diff($requiredTables, $existingTables);
    
    if (!empty($missingTables)) {
        echo "<h3>Creating Missing Tables:</h3>";
        
        // Create users table
        if (in_array('users', $missingTables)) {
            $db->query("
                CREATE TABLE users (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            echo "<p>✅ Created users table</p>";
        }
        
        // Create checks table
        if (in_array('checks', $missingTables)) {
            $db->query("
                CREATE TABLE checks (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    name VARCHAR(255) NOT NULL,
                    url TEXT NOT NULL,
                    method ENUM('GET','POST') DEFAULT 'GET',
                    request_body TEXT NULL,
                    request_headers TEXT NULL,
                    expected_status INT DEFAULT 200,
                    expected_headers TEXT NULL,
                    interval_seconds INT NOT NULL,
                    timeout_seconds INT DEFAULT 30,
                    max_redirects INT DEFAULT 5,
                    alert_emails TEXT NULL,
                    enabled TINYINT(1) DEFAULT 1,
                    last_state ENUM('UP','DOWN') NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    next_run_at DATETIME NOT NULL,
                    INDEX idx_enabled_next_run (enabled, next_run_at),
                    INDEX idx_last_state (last_state)
                )
            ");
            echo "<p>✅ Created checks table</p>";
        }
        
        // Create check_results table
        if (in_array('check_results', $missingTables)) {
            $db->query("
                CREATE TABLE check_results (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    check_id INT NOT NULL,
                    started_at DATETIME NOT NULL,
                    ended_at DATETIME NOT NULL,
                    duration_ms INT NOT NULL,
                    http_status INT NOT NULL,
                    response_headers TEXT NULL,
                    body_sample TEXT NULL,
                    is_up TINYINT(1) NOT NULL,
                    error_message TEXT NULL,
                    FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE,
                    INDEX idx_check_started (check_id, started_at),
                    INDEX idx_started_at (started_at)
                )
            ");
            echo "<p>✅ Created check_results table</p>";
        }
        
        // Create incidents table
        if (in_array('incidents', $missingTables)) {
            $db->query("
                CREATE TABLE incidents (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    check_id INT NOT NULL,
                    started_at DATETIME NOT NULL,
                    ended_at DATETIME NULL,
                    opened_by_result_id INT NOT NULL,
                    closed_by_result_id INT NULL,
                    status ENUM('OPEN','CLOSED') DEFAULT 'OPEN',
                    FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE,
                    INDEX idx_status (status),
                    INDEX idx_check_status (check_id, status)
                )
            ");
            echo "<p>✅ Created incidents table</p>";
        }
        
        // Create migrations table
        if (in_array('migrations', $missingTables)) {
            $db->query("
                CREATE TABLE migrations (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    version VARCHAR(50) NOT NULL,
                    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            echo "<p>✅ Created migrations table</p>";
            
            // Insert initial migration record
            $db->insert('migrations', ['version' => '001_initial_schema']);
        }
    }
    
    // Create admin user if not exists
    $adminExists = $db->fetchOne('SELECT id FROM users WHERE email = ?', ['admin@example.com']);
    
    if (!$adminExists) {
        $passwordHash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 12]);
        $adminId = $db->insert('users', [
            'email' => 'admin@example.com',
            'password_hash' => $passwordHash
        ]);
        echo "<p>✅ Created admin user (ID: {$adminId})</p>";
    } else {
        echo "<p>ℹ️ Admin user already exists</p>";
    }
    
    // Verify table structure
    echo "<h3>Table Structure Verification:</h3>";
    
    $tableChecks = [
        'users' => ['id', 'email', 'password_hash', 'created_at'],
        'checks' => ['id', 'name', 'url', 'method', 'enabled', 'next_run_at'],
        'check_results' => ['id', 'check_id', 'started_at', 'http_status', 'is_up'],
        'incidents' => ['id', 'check_id', 'status', 'started_at']
    ];
    
    foreach ($tableChecks as $table => $requiredColumns) {
        try {
            $columns = $db->query("DESCRIBE {$table}")->fetchAll(PDO::FETCH_COLUMN);
            $missingColumns = array_diff($requiredColumns, $columns);
            
            if (empty($missingColumns)) {
                echo "<p>✅ {$table} table structure OK</p>";
            } else {
                echo "<p>⚠️ {$table} table missing columns: " . implode(', ', $missingColumns) . "</p>";
            }
        } catch (Exception $e) {
            echo "<p>❌ Error checking {$table} table: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<h3>Setup Complete!</h3>";
    echo "<p>✅ Database setup completed successfully</p>";
    echo "<p><a href='login.php'>Go to Login</a> | <a href='debug.php'>Run Debug</a></p>";
    
} catch (Exception $e) {
    echo "<h3>❌ Setup Failed</h3>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    echo "<p>Please check your database configuration and permissions.</p>";
}