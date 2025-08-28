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
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
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
 * Helper function for time ago display
 */
function timeAgo($datetime) {
    if (empty($datetime)) return 'Never';
    
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time / 60) . 'm ago';
    if ($time < 86400) return floor($time / 3600) . 'h ago';
    if ($time < 2592000) return floor($time / 86400) . 'd ago';
    
    return date('M j, Y', strtotime($datetime));
}

/**
 * Format duration in milliseconds to human readable
 */
function formatDuration($ms) {
    if ($ms < 1000) return $ms . 'ms';
    if ($ms < 10000) return number_format($ms / 1000, 1) . 's';
    return number_format($ms / 1000, 0) . 's';
}

/**
 * Helper function to render HTML template
 */
function renderTemplate(string $title, string $content, bool $requireAuth = true): void {
    global $auth, $config, $db;
    
    if ($requireAuth) {
        $auth->requireAuth();
    }
    
    $user = $auth->getCurrentUser();
    $csrfToken = $auth->getCsrfToken();
    
    // Get current page for active nav highlighting
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
    
    // Get system statistics for header display
    $stats = [];
    if ($user) {
        try {
            $stats = [
                'total_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1") ?: 0,
                'checks_up' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'UP'") ?: 0,
                'checks_down' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'DOWN'") ?: 0,
                'open_incidents' => $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'OPEN'") ?: 0,
            ];
        } catch (Exception $e) {
            // Ignore errors if tables don't exist
        }
    }
    
    echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>{$title} - Uptime Monitor</title>
    <script src=\"https://cdn.tailwindcss.com\"></script>
    <style>
        /* Facebook-style navigation */
        .nav-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            position: relative;
        }
        .nav-icon:hover {
            background-color: #f0f2f5;
            transform: scale(1.05);
        }
        .nav-icon.active {
            background-color: #e7f3ff;
            color: #1877f2;
        }
        .nav-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #e41e3f;
            color: white;
            border-radius: 50%;
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: bold;
            padding: 0 4px;
        }
        .status-up { @apply bg-green-100 text-green-800; }
        .status-down { @apply bg-red-100 text-red-800; }
        .badge { @apply px-2 py-1 rounded-full text-xs font-medium; }
    </style>
</head>
<body class=\"bg-gray-50\">
    
    " . ($user ? "
    <!-- Facebook-style Navigation Bar -->
    <nav class=\"bg-white shadow-sm border-b border-gray-200 sticky top-0 z-50\">
        <div class=\"max-w-7xl mx-auto px-4\">
            <div class=\"flex items-center justify-between h-14\">
                
                <!-- Left Section: Logo + Search -->
                <div class=\"flex items-center space-x-4\">
                    <!-- Logo -->
                    <div class=\"flex items-center space-x-3\">
                        <div class=\"w-8 h-8 bg-gray-600 rounded-lg flex items-center justify-center\">
                            <svg class=\"w-5 h-5 text-white\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z\"/>
                            </svg>
                        </div>
                        <span class=\"font-semibold text-gray-900 text-lg hidden sm:block\">Uptime Monitor</span>
                    </div>
                    
                    <!-- Search Bar -->
                    <div class=\"hidden md:block\">
                        <div class=\"relative\">
                            <input type=\"text\" placeholder=\"Search checks...\" 
                                   class=\"w-64 pl-10 pr-4 py-2 bg-gray-100 border-0 rounded-full text-sm focus:outline-none focus:bg-white focus:ring-2 focus:ring-gray-300\">
                            <svg class=\"absolute left-3 top-2.5 w-4 h-4 text-gray-400\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z\"/>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <!-- Center Section: Main Navigation Icons -->
                <div class=\"hidden md:flex items-center space-x-2\">
                    <!-- Dashboard -->
                    <a href=\"/dashboard.php\" class=\"nav-icon " . ($currentPage === 'dashboard' ? 'active' : '') . "\" title=\"Dashboard\">
                        <svg class=\"w-6 h-6\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                            <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6\"/>
                        </svg>
                    </a>
                    
                    <!-- Checks -->
                    <a href=\"/checks.php\" class=\"nav-icon " . ($currentPage === 'checks' ? 'active' : '') . "\" title=\"Checks\">
                        <svg class=\"w-6 h-6\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                            <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4\"/>
                        </svg>
                    </a>
                    
                    <!-- Categories -->
                    <a href=\"/categories.php\" class=\"nav-icon " . ($currentPage === 'categories' ? 'active' : '') . "\" title=\"Categories\">
                        <svg class=\"w-6 h-6\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                            <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z\"/>
                        </svg>
                    </a>
                    
                    <!-- Reports -->
                    <a href=\"/reports.php\" class=\"nav-icon " . ($currentPage === 'reports' ? 'active' : '') . "\" title=\"Reports\">
                        <svg class=\"w-6 h-6\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                            <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z\"/>
                        </svg>
                        " . ($stats['checks_up'] > 0 ? "<span class=\"nav-badge\">{$stats['checks_up']}</span>" : '') . "
                    </a>
                </div>
                
                <!-- Right Section: Status indicators + User menu -->
                <div class=\"flex items-center space-x-3\">
                    <!-- Quick Stats -->
                    <div class=\"hidden lg:flex items-center space-x-2 text-sm\">
                        " . ($stats['checks_up'] > 0 ? "
                        <span class=\"flex items-center space-x-1 bg-green-100 text-green-700 px-2 py-1 rounded-full\">
                            <div class=\"w-2 h-2 bg-green-500 rounded-full\"></div>
                            <span class=\"font-medium\">{$stats['checks_up']} UP</span>
                        </span>
                        " : '') . "
                        " . ($stats['checks_down'] > 0 ? "
                        <span class=\"flex items-center space-x-1 bg-red-100 text-red-700 px-2 py-1 rounded-full\">
                            <div class=\"w-2 h-2 bg-red-500 rounded-full\"></div>
                            <span class=\"font-medium\">{$stats['checks_down']} DOWN</span>
                        </span>
                        " : '') . "
                        " . ($stats['open_incidents'] > 0 ? "
                        <span class=\"flex items-center space-x-1 bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full\">
                            <svg class=\"w-3 h-3\" fill=\"currentColor\" viewBox=\"0 0 20 20\">
                                <path fill-rule=\"evenodd\" d=\"M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z\" clip-rule=\"evenodd\"/>
                            </svg>
                            <span class=\"font-medium\">{$stats['open_incidents']}</span>
                        </span>
                        " : '') . "
                    </div>
                    
                    <!-- Notifications Icon -->
                    <div class=\"nav-icon\" title=\"Notifications\">
                        <svg class=\"w-6 h-6 text-gray-600\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                            <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M15 17h5l-5-5V9a6 6 0 10-12 0v3l-5 5h5m6 0a3 3 0 01-6 0\"/>
                        </svg>
                        " . ($stats['open_incidents'] > 0 ? "<span class=\"nav-badge\">{$stats['open_incidents']}</span>" : '') . "
                    </div>
                    
                    <!-- User Menu -->
                    <div class=\"relative group\">
                        <div class=\"flex items-center space-x-2 cursor-pointer\">
                            <div class=\"w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center\">
                                <svg class=\"w-5 h-5 text-gray-600\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                    <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z\"/>
                                </svg>
                            </div>
                            <svg class=\"w-4 h-4 text-gray-400 hidden md:block\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M19 9l-7 7-7-7\"/>
                            </svg>
                        </div>
                        
                        <!-- Dropdown Menu -->
                        <div class=\"absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-lg py-2 invisible group-hover:visible opacity-0 group-hover:opacity-100 transition-all duration-200 z-50\">
                            <div class=\"px-4 py-3 border-b border-gray-100\">
                                <p class=\"text-xs text-gray-500\">Signed in as</p>
                                <p class=\"text-sm font-medium text-gray-900 truncate\">{$user['email']}</p>
                            </div>
                            <a href=\"/profile.php\" class=\"flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50\">
                                <svg class=\"w-4 h-4 mr-3\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                    <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z\"/>
                                </svg>
                                Profile
                            </a>
                            <a href=\"/settings.php\" class=\"flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50\">
                                <svg class=\"w-4 h-4 mr-3\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                    <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z\"/>
                                    <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M15 12a3 3 0 11-6 0 3 3 0 016 0z\"/>
                                </svg>
                                Settings
                            </a>
                            <div class=\"border-t border-gray-100 mt-2 pt-2\">
                                <a href=\"/logout.php\" class=\"flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50\">
                                    <svg class=\"w-4 h-4 mr-3\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                        <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1\"/>
                                    </svg>
                                    Sign out
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Mobile menu button -->
                    <div class=\"md:hidden\">
                        <button id=\"mobile-menu-button\" class=\"nav-icon\">
                            <svg class=\"w-6 h-6 text-gray-600\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M4 6h16M4 12h16M4 18h16\"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Mobile Navigation -->
            <div id=\"mobile-menu\" class=\"md:hidden hidden bg-white border-t border-gray-200\">
                <nav class=\"px-4 py-3 space-y-2\">
                    <a href=\"/dashboard.php\" class=\"block py-2 text-gray-700 hover:text-gray-900 " . ($currentPage === 'dashboard' ? 'font-semibold text-gray-900' : '') . "\">Dashboard</a>
                    <a href=\"/checks.php\" class=\"block py-2 text-gray-700 hover:text-gray-900 " . ($currentPage === 'checks' ? 'font-semibold text-gray-900' : '') . "\">Checks</a>
                    <a href=\"/categories.php\" class=\"block py-2 text-gray-700 hover:text-gray-900 " . ($currentPage === 'categories' ? 'font-semibold text-gray-900' : '') . "\">Categories</a>
                    <a href=\"/reports.php\" class=\"block py-2 text-gray-700 hover:text-gray-900 " . ($currentPage === 'reports' ? 'font-semibold text-gray-900' : '') . "\">Reports</a>
                </nav>
            </div>
        </div>
    </nav>
    
    " : "") . "
    
    <!-- Page Content -->
    <main class=\"" . ($user ? 'pt-4' : 'min-h-screen flex items-center justify-center') . "\">
        <div class=\"" . ($user ? 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8' : 'w-full') . "\">
            {$content}
        </div>
    </main>
    
    <!-- Mobile menu toggle script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (mobileMenuButton && mobileMenu) {
                mobileMenuButton.addEventListener('click', function() {
                    mobileMenu.classList.toggle('hidden');
                });
            }
        });
    </script>
</body>
</html>";
}
?>