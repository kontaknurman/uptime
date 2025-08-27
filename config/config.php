<?php
/**
 * Configuration file for Uptime Monitor
 */

return [
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'name' => $_ENV['DB_NAME'] ?? ' ',
        'user' => $_ENV['DB_USER'] ?? ' ',
        'pass' => $_ENV['DB_PASS'] ?? ' ',
        'charset' => 'utf8mb4'
    ],
    
    'smtp' => [
        'host' => $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com',
        'port' => $_ENV['SMTP_PORT'] ?? 587,
        'username' => $_ENV['SMTP_USER'] ?? '',
        'password' => $_ENV['SMTP_PASS'] ?? '',
        'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? 'tls', // tls, ssl, or ''
        'from_email' => $_ENV['FROM_EMAIL'] ?? 'uptime@example.com',
        'from_name' => $_ENV['FROM_NAME'] ?? 'Uptime Monitor'
    ],
    
    'app' => [
        'timezone' => $_ENV['TIMEZONE'] ?? 'Asia/Jakarta',
        'base_url' => $_ENV['BASE_URL'] ?? 'https://test2.audiensi.com',
        'session_lifetime' => 3600, // 1 hour
        'max_body_sample' => 2048, // bytes
        'default_timeout' => 30,
        'max_redirects' => 5
    ],
    
    'security' => [
        'csrf_lifetime' => 1800, // 30 minutes
        'bcrypt_cost' => 12,
        'max_login_attempts' => 5,
        'lockout_duration' => 900 // 15 minutes
    ]
];
