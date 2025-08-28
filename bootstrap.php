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
        .status-up { @apply bg-green-100 text-green-800; }
        .status-down { @apply bg-red-100 text-red-800; }
        .badge { @apply px-2 py-1 rounded-full text-xs font-medium; }
        
        /* Custom animations */
        @keyframes pulse-green {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        @keyframes pulse-red {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .pulse-green {
            animation: pulse-green 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        .pulse-red {
            animation: pulse-red 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        /* Gradient background for header */
        .header-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        /* Glass morphism effect */
        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        
        /* Nav link hover effect */
        .nav-link {
            position: relative;
            transition: color 0.3s ease;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -8px;
            left: 50%;
            background-color: #fff;
            transition: all 0.3s ease;
            transform: translateX(-50%);
        }
        .nav-link:hover::after,
        .nav-link.active::after {
            width: 100%;
        }
        .nav-link.active {
            color: #fff !important;
            font-weight: 600;
        }
    </style>
</head>
<body class=\"bg-gray-50\">
    <!-- Enhanced Header -->
    <header class=\"header-gradient shadow-lg\">
        <div class=\"max-w-7xl mx-auto px-4 sm:px-6 lg:px-8\">
            <div class=\"flex justify-between items-center py-4\">
                <!-- Logo and Brand -->
                <div class=\"flex items-center space-x-4\">
                    <div class=\"flex items-center space-x-3\">
                        <!-- Logo Icon -->
                        <div class=\"bg-white/20 backdrop-blur rounded-lg p-2\">
                            <svg class=\"w-8 h-8 text-white\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" 
                                      d=\"M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z\">
                                </path>
                            </svg>
                        </div>
                        <div>
                            <h1 class=\"text-xl font-bold text-white\">Uptime Monitor</h1>
                            <p class=\"text-xs text-purple-100\">System Health Dashboard</p>
                        </div>
                    </div>
                </div>
                
                " . ($user ? "
                <!-- Navigation -->
                <nav class=\"hidden md:flex items-center space-x-8\">
                    <a href=\"/dashboard.php\" class=\"nav-link text-white/80 hover:text-white " . ($currentPage === 'dashboard' ? 'active' : '') . "\">
                        <span class=\"flex items-center space-x-2\">
                            <svg class=\"w-5 h-5\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6\"></path>
                            </svg>
                            <span>Dashboard</span>
                        </span>
                    </a>
                    <a href=\"/checks.php\" class=\"nav-link text-white/80 hover:text-white " . ($currentPage === 'checks' ? 'active' : '') . "\">
                        <span class=\"flex items-center space-x-2\">
                            <svg class=\"w-5 h-5\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4\"></path>
                            </svg>
                            <span>Checks</span>
                        </span>
                    </a>
                    <a href=\"/categories.php\" class=\"nav-link text-white/80 hover:text-white " . ($currentPage === 'categories' ? 'active' : '') . "\">
                        <span class=\"flex items-center space-x-2\">
                            <svg class=\"w-5 h-5\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z\"></path>
                            </svg>
                            <span>Categories</span>
                        </span>
                    </a>
                    <a href=\"/reports.php\" class=\"nav-link text-white/80 hover:text-white " . ($currentPage === 'reports' ? 'active' : '') . "\">
                        <span class=\"flex items-center space-x-2\">
                            <svg class=\"w-5 h-5\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z\"></path>
                            </svg>
                            <span>Reports</span>
                        </span>
                    </a>
                </nav>
                
                <!-- Right side: Stats and User -->
                <div class=\"flex items-center space-x-6\">
                    <!-- Quick Stats -->
                    <div class=\"hidden lg:flex items-center space-x-4 text-white/90\">
                        " . (!empty($stats) ? "
                        <div class=\"flex items-center space-x-2\">
                            <div class=\"w-2 h-2 rounded-full " . ($stats['checks_down'] > 0 ? 'bg-red-400 pulse-red' : 'bg-green-400 pulse-green') . "\"></div>
                            <span class=\"text-sm font-medium\">{$stats['checks_up']}/{$stats['total_checks']} UP</span>
                        </div>
                        " . ($stats['checks_down'] > 0 ? "
                        <div class=\"flex items-center space-x-2\">
                            <span class=\"text-sm bg-red-500 text-white px-2 py-1 rounded-full font-medium\">{$stats['checks_down']} DOWN</span>
                        </div>
                        " : "") . 
                        ($stats['open_incidents'] > 0 ? "
                        <div class=\"flex items-center space-x-2\">
                            <span class=\"text-sm bg-yellow-500 text-white px-2 py-1 rounded-full font-medium\">{$stats['open_incidents']} Incidents</span>
                        </div>
                        " : "") : "") . "
                    </div>
                    
                    <!-- User Menu -->
                    <div class=\"relative group\">
                        <button class=\"flex items-center space-x-3 bg-white/20 backdrop-blur rounded-lg px-3 py-2 hover:bg-white/30 transition\">
                            <div class=\"w-8 h-8 bg-white/30 rounded-full flex items-center justify-center\">
                                <svg class=\"w-5 h-5 text-white\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                    <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z\"></path>
                                </svg>
                            </div>
                            <span class=\"text-sm text-white font-medium hidden md:block\">{$user['email']}</span>
                            <svg class=\"w-4 h-4 text-white/60\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M19 9l-7 7-7-7\"></path>
                            </svg>
                        </button>
                        
                        <!-- Dropdown Menu -->
                        <div class=\"absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg py-2 invisible group-hover:visible opacity-0 group-hover:opacity-100 transition-all duration-200 z-50\">
                            <div class=\"px-4 py-2 border-b border-gray-100\">
                                <p class=\"text-xs text-gray-500\">Signed in as</p>
                                <p class=\"text-sm font-medium text-gray-900 truncate\">{$user['email']}</p>
                            </div>
                            <a href=\"/profile.php\" class=\"block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100\">
                                <span class=\"flex items-center space-x-2\">
                                    <svg class=\"w-4 h-4\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                        <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z\"></path>
                                    </svg>
                                    <span>Profile Settings</span>
                                </span>
                            </a>
                            <a href=\"/settings.php\" class=\"block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100\">
                                <span class=\"flex items-center space-x-2\">
                                    <svg class=\"w-4 h-4\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                        <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z\"></path>
                                        <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M15 12a3 3 0 11-6 0 3 3 0 016 0z\"></path>
                                    </svg>
                                    <span>System Settings</span>
                                </span>
                            </a>
                            <div class=\"border-t border-gray-100 mt-2 pt-2\">
                                <a href=\"/logout.php\" class=\"block px-4 py-2 text-sm text-red-600 hover:bg-red-50\">
                                    <span class=\"flex items-center space-x-2\">
                                        <svg class=\"w-4 h-4\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                                            <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1\"></path>
                                        </svg>
                                        <span>Logout</span>
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                " : "
                <!-- Not logged in -->
                <div class=\"text-white\">
                    <a href=\"/login.php\" class=\"bg-white/20 backdrop-blur text-white px-4 py-2 rounded-lg hover:bg-white/30 transition\">
                        Login
                    </a>
                </div>
                ") . "
            </div>
            
            <!-- Mobile menu button -->
            <div class=\"md:hidden flex items-center\">
                <button id=\"mobile-menu-button\" class=\"text-white hover:text-white/80\">
                    <svg class=\"w-6 h-6\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                        <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M4 6h16M4 12h16M4 18h16\"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <!-- Mobile Navigation -->
        <div id=\"mobile-menu\" class=\"md:hidden hidden bg-white/10 backdrop-blur\">
            <nav class=\"px-4 py-3 space-y-2\">
                <a href=\"/dashboard.php\" class=\"block text-white/80 hover:text-white py-2\">Dashboard</a>
                <a href=\"/checks.php\" class=\"block text-white/80 hover:text-white py-2\">Checks</a>
                <a href=\"/categories.php\" class=\"block text-white/80 hover:text-white py-2\">Categories</a>
                <a href=\"/reports.php\" class=\"block text-white/80 hover:text-white py-2\">Reports</a>
            </nav>
        </div>
    </header>
    
    <!-- Sub-header with breadcrumb or status -->
    " . (!empty($stats) && $user ? "
    <div class=\"bg-white border-b border-gray-200\">
        <div class=\"max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2\">
            <div class=\"flex justify-between items-center text-sm\">
                <div class=\"flex items-center space-x-2 text-gray-600\">
                    <svg class=\"w-4 h-4\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">
                        <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z\"></path>
                    </svg>
                    <span>System Status: " . ($stats['checks_down'] > 0 ? 
                        '<span class="text-red-600 font-medium">Issues Detected</span>' : 
                        '<span class="text-green-600 font-medium">All Systems Operational</span>') . "</span>
                </div>
                <div class=\"text-gray-500\">
                    Last updated: <span id=\"last-updated\">" . date('H:i:s') . "</span>
                </div>
            </div>
        </div>
    </div>
    " : "") . "
    
    <main class=\"max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8\">
        {$content}
    </main>
    
    <!-- Footer -->
    <footer class=\"mt-auto bg-white border-t border-gray-200\">
        <div class=\"max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4\">
            <div class=\"flex justify-between items-center text-sm text-gray-600\">
                <div>
                    &copy; " . date('Y') . " Uptime Monitor. All rights reserved.
                </div>
                <div class=\"flex items-center space-x-4\">
                    <a href=\"/docs\" class=\"hover:text-gray-900\">Documentation</a>
                    <a href=\"/api\" class=\"hover:text-gray-900\">API</a>
                    <a href=\"/support\" class=\"hover:text-gray-900\">Support</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // CSRF token for AJAX requests
        window.csrfToken = '{$csrfToken}';
        
        // Mobile menu toggle
        document.getElementById('mobile-menu-button')?.addEventListener('click', function() {
            const menu = document.getElementById('mobile-menu');
            menu.classList.toggle('hidden');
        });
        
        // Auto-refresh functionality
        function setupAutoRefresh() {
            if (window.location.pathname === '/dashboard.php') {
                setTimeout(() => {
                    window.location.reload();
                }, 60000); // Refresh every minute
            }
            
            // Update last updated time
            setInterval(() => {
                const now = new Date();
                const timeStr = now.toTimeString().split(' ')[0];
                const elem = document.getElementById('last-updated');
                if (elem) elem.textContent = timeStr;
            }, 1000);
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