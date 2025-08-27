<?php
/**
 * Bootstrap file for Uptime Monitor application
 */

// Error reporting - Enable for debugging, disable in production
error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 0 in production
ini_set('log_errors', 1);

// Check if running from CLI
$isCLI = php_sapi_name() === 'cli';

// Start session only for web requests
if (!$isCLI) {
    // Session security settings
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Load environment variables if .env file exists
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && substr($line, 0, 1) !== '#') {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Load configuration
if (!file_exists(__DIR__ . '/config/config.php')) {
    die('Configuration file not found. Please copy config/config.php.example to config/config.php and update settings.');
}

$config = require __DIR__ . '/config/config.php';

// Set timezone
date_default_timezone_set($config['app']['timezone']);

// Autoload classes
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/lib/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    } else {
        throw new Exception("Class file not found: {$file}");
    }
});

// Initialize database
try {
    $db = Database::getInstance($config);
} catch (Exception $e) {
    error_log('Database initialization failed: ' . $e->getMessage());
    if (ini_get('display_errors')) {
        die('Database connection failed: ' . $e->getMessage());
    } else {
        http_response_code(500);
        die('Database connection failed. Please check configuration.');
    }
}

// Initialize authentication (only for web requests)
if (!$isCLI) {
    $auth = new Auth($db, $config);

    // Security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // CSRF protection for POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!$auth->validateCsrfToken($token)) {
            http_response_code(403);
            die('CSRF token validation failed');
        }
    }
}

/**
 * Helper function to render HTML template
 */
function renderTemplate(string $title, string $content, bool $requireAuth = true): void {
    global $auth, $config;
    
    if ($requireAuth) {
        $auth->requireAuth();
    }
    
    $user = $auth->getCurrentUser();
    $csrfToken = $auth->getCsrfToken();
    
    echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>{$title} - Uptime Monitor</title>
    <script src=\"https://cdn.tailwindcss.com\"></script>
    <style>
        .status-up { @apply bg-green-100 text-green-800; }
        .status-down { @apply bg-red-100 text-red-800; }
        .badge { @apply px-2 py-1 rounded-full text-xs font-medium; }
    </style>
</head>
<body class=\"bg-gray-50\">
    <nav class=\"bg-white shadow-sm border-b\">
        <div class=\"max-w-7xl mx-auto px-4 sm:px-6 lg:px-8\">
            <div class=\"flex justify-between items-center py-4\">
                <div class=\"flex items-center space-x-4\">
                    <h1 class=\"text-xl font-bold text-gray-900\">Uptime Monitor</h1>
                    " . ($user ? "
                    <nav class=\"flex space-x-4\">
                        <a href=\"/dashboard.php\" class=\"text-gray-600 hover:text-gray-900\">Dashboard</a>
                        <a href=\"/checks.php\" class=\"text-gray-600 hover:text-gray-900\">Checks</a>
                        <a href=\"/reports.php\" class=\"text-gray-600 hover:text-gray-900\">Reports</a>
                    </nav>
                    " : "") . "
                </div>
                " . ($user ? "
                <div class=\"flex items-center space-x-4\">
                    <span class=\"text-sm text-gray-600\">{$user['email']}</span>
                    <a href=\"/logout.php\" class=\"text-sm text-red-600 hover:text-red-800\">Logout</a>
                </div>
                " : "") . "
            </div>
        </div>
    </nav>
    
    <main class=\"max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8\">
        {$content}
    </main>

    <script>
        // CSRF token for AJAX requests
        window.csrfToken = '{$csrfToken}';
        
        // Auto-refresh functionality
        function setupAutoRefresh() {
            if (window.location.pathname === '/dashboard.php') {
                setTimeout(() => {
                    window.location.reload();
                }, 60000); // Refresh every minute
            }
        }
        
        setupAutoRefresh();
    </script>
</body>
</html>";
}

/**
 * Helper function to redirect
 */
function redirect(string $url): void {
    header("Location: {$url}");
    exit;
}

/**
 * Helper function to format duration
 */
function formatDuration(int $ms): string {
    if ($ms < 1000) {
        return $ms . 'ms';
    }
    return round($ms / 1000, 2) . 's';
}

/**
 * Helper function to format relative time
 */
function timeAgo(string $datetime): string {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}