<?php
/**
 * Configuration file for Uptime Monitor
 */

return [
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'name' => $_ENV['DB_NAME'] ?? 'test2_audiensi',
        'user' => $_ENV['DB_USER'] ?? 'test2_audiensi',
        'pass' => $_ENV['DB_PASS'] ?? '!KNvM9mIOfv8r!i5',
        'charset' => 'utf8mb4'
    ],
    
    'smtp' => [
        'host' => $_ENV['SMTP_HOST'] ?? 'mail.smtp2go.com',
        'port' => $_ENV['SMTP_PORT'] ?? 587,
        'username' => $_ENV['SMTP_USER'] ?? 'audiensi.com',
        'password' => $_ENV['SMTP_PASS'] ?? 'ujZwa4wwMGplexZl',
        'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? 'tls', // tls, ssl, or ''
        'from_email' => $_ENV['FROM_EMAIL'] ?? 'alert@audiensi.com',
        'from_name' => $_ENV['FROM_NAME'] ?? 'Audiensi Notif'
    ],
    
    'app' => [
        'timezone' => $_ENV['TIMEZONE'] ?? 'Asia/Jakarta',
        'base_url' => $_ENV['BASE_URL'] ?? 'https://test2.audiensi.com',
        'session_lifetime' => 3600000, // 1 hour
        'max_body_sample' => 2048, // bytes
        'default_timeout' => 30,
        'max_redirects' => 5
    ],
    
    'security' => [
        'csrf_lifetime' => 1800, // 30 minutes
        'bcrypt_cost' => 12,
        'max_login_attempts' => 5,
        'lockout_duration' => 900 // 15 minutes
    ],
    
    'monitoring' => [
        'max_parallel_checks' => 25, // Process up to 10 checks simultaneously
        'batch_size' => 300, // Maximum checks to load at once
        'max_execution_time' => 300 // 5 minutes max execution time
    ]
];