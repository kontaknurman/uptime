<?php
/**
 * Configuration file for Uptime Monitor
 * Updated to use SMTP2GO API instead of direct SMTP
 */

return [
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'name' => $_ENV['DB_NAME'] ?? 'test2',
        'user' => $_ENV['DB_USER'] ?? 'test2',
        'pass' => $_ENV['DB_PASS'] ?? '!KNvM9mI',
        'charset' => 'utf8mb4'
    ],
    
    // SMTP2GO API Configuration
    'smtp2go' => [
        'api_key' => $_ENV['SMTP2GO_API_KEY'] ?? 'api-4B9734B2831', // Replace with your SMTP2GO API key
        'from_email' => $_ENV['FROM_EMAIL'] ?? 'alert@audiensi.com',
        'from_name' => $_ENV['FROM_NAME'] ?? 'Audiensi Notif'
    ],
    
    
    'app' => [
        'timezone' => $_ENV['TIMEZONE'] ?? 'Asia/Jakarta',
        'base_url' => $_ENV['BASE_URL'] ?? 'https://test2.com',
        'session_lifetime' => 3600000, // 1 hour
        'max_body_sample' => 2048, // bytes
        'default_timeout' => 30,
        'max_redirects' => 5,
        'debug' => false // Set to true for detailed logging
    ],
    
    'security' => [
        'csrf_lifetime' => 1800, // 30 minutes
        'bcrypt_cost' => 12,
        'max_login_attempts' => 5,
        'lockout_duration' => 900, // 15 minutes
    ],
    
    'monitoring' => [
        'max_parallel_checks' => 25, // Process up to 25 checks simultaneously
        'batch_size' => 300, // Maximum checks to load at once
        'max_execution_time' => 300 // 5 minutes max execution time
    ]

];
