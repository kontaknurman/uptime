<?php
/**
 * Profile Management Page - Secure Implementation
 * Based on existing database schema and Auth class
 */

require_once 'bootstrap.php';

// Require authentication
$auth->requireAuth();

// Initialize variables
$successMessage = '';
$errorMessage = '';
$errors = [];

// Get current user data from database
$userId = $_SESSION['user_id'];
$currentUser = $db->fetchOne(
    "SELECT id, email, name, created_at FROM users WHERE id = ?",
    [$userId]
);

if (!$currentUser) {
    // User not found, destroy session and redirect
    $auth->logout();
    header('Location: /login.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token is already validated in bootstrap.php
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_profile':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            // Validation
            if (empty($name)) {
                $errors[] = 'Name is required';
            } elseif (strlen($name) > 100) {
                $errors[] = 'Name must be 100 characters or less';
            }
            
            if (empty($email)) {
                $errors[] = 'Email is required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address';
            } elseif (strlen($email) > 255) {
                $errors[] = 'Email must be 255 characters or less';
            } else {
                // Check if email is already taken by another user
                $existingUser = $db->fetchOne(
                    "SELECT id FROM users WHERE email = ? AND id != ?",
                    [$email, $userId]
                );
                if ($existingUser) {
                    $errors[] = 'Email address is already in use by another account';
                }
            }
            
            if (empty($errors)) {
                try {
                    $db->update('users', [
                        'name' => $name,
                        'email' => $email
                    ], 'id = ?', [$userId]);
                    
                    // Update session
                    $_SESSION['user_email'] = $email;
                    
                    // Refresh current user data
                    $currentUser['name'] = $name;
                    $currentUser['email'] = $email;
                    
                    $successMessage = 'Profile updated successfully!';
                    
                } catch (Exception $e) {
                    error_log("Profile update error: " . $e->getMessage());
                    $errors[] = 'Failed to update profile. Please try again.';
                }
            }
            break;
            
        case 'change_password':
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            // Validation
            if (empty($currentPassword)) {
                $errors[] = 'Current password is required';
            }
            
            if (empty($newPassword)) {
                $errors[] = 'New password is required';
            } elseif (strlen($newPassword) < 6) {
                $errors[] = 'New password must be at least 6 characters long';
            } elseif (strlen($newPassword) > 255) {
                $errors[] = 'New password is too long';
            }
            
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'New password and confirmation do not match';
            }
            
            if (empty($errors)) {
                // Verify current password
                $user = $db->fetchOne(
                    "SELECT password_hash FROM users WHERE id = ?",
                    [$userId]
                );
                
                if (!password_verify($currentPassword, $user['password_hash'])) {
                    $errors[] = 'Current password is incorrect';
                } else {
                    // Update password
                    try {
                        $newPasswordHash = $auth->hashPassword($newPassword);
                        
                        $db->update('users', [
                            'password_hash' => $newPasswordHash
                        ], 'id = ?', [$userId]);
                        
                        $successMessage = 'Password changed successfully!';
                        
                    } catch (Exception $e) {
                        error_log("Password update error: " . $e->getMessage());
                        $errors[] = 'Failed to update password. Please try again.';
                    }
                }
            }
            break;
    }
}

// Get user statistics
$userStats = [];
try {
    $userStats = [
        'total_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks") ?: 0,
        'active_checks' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1") ?: 0,
        'checks_up' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'UP'") ?: 0,
        'checks_down' => $db->fetchColumn("SELECT COUNT(*) FROM checks WHERE enabled = 1 AND last_state = 'DOWN'") ?: 0,
        'total_incidents' => $db->fetchColumn("SELECT COUNT(*) FROM incidents") ?: 0,
        'open_incidents' => $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'OPEN'") ?: 0
    ];
} catch (Exception $e) {
    error_log("Error fetching user stats: " . $e->getMessage());
    // Stats will remain empty array if query fails
}

// Calculate account age
$accountAge = '';
if ($currentUser['created_at']) {
    $created = new DateTime($currentUser['created_at']);
    $now = new DateTime();
    $diff = $created->diff($now);
    
    if ($diff->y > 0) {
        $accountAge = $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
    } elseif ($diff->m > 0) {
        $accountAge = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
    } else {
        $accountAge = $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
    }
}

// Start output buffering
ob_start();
?>

<style>
    .glass {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.18);
    }
    .gradient-text {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .stat-card {
        transition: all 0.3s ease;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
</style>

<div class="space-y-6">
    <!-- Success/Error Messages -->
    <?php if ($successMessage): ?>
    <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded-lg">
        <div class="flex">
            <svg class="w-5 h-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            <p class="ml-3 text-green-700"><?php echo htmlspecialchars($successMessage); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
    <div class="bg-red-50 border-l-4 border-red-400 p-4 rounded-lg">
        <div class="flex">
            <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <div class="ml-3">
                <?php foreach ($errors as $error): ?>
                    <p class="text-red-700"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
    <div class="bg-red-50 border-l-4 border-red-400 p-4 rounded-lg">
        <p class="text-red-700"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Profile Header -->
    <div class="glass rounded-lg shadow-lg overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-indigo-600 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-white">Profile Settings</h1>
                    <p class="text-purple-100 mt-2">Manage your account information and security</p>
                </div>
                <div class="bg-white/20 backdrop-blur rounded-full p-4">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                </div>
            </div>
            
            <!-- User Info -->
            <div class="mt-6 flex flex-wrap gap-4 text-sm text-purple-100">
                <span class="flex items-center">
                    <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"></path>
                        <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"></path>
                    </svg>
                    <?php echo htmlspecialchars($currentUser['email']); ?>
                </span>
                <span class="flex items-center">
                    <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"></path>
                    </svg>
                    Member for <?php echo $accountAge; ?>
                </span>
            </div>
        </div>
        
        <!-- Statistics Grid -->
        <div class="p-6 bg-gray-50">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="stat-card bg-white rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-gray-800"><?php echo $userStats['total_checks']; ?></div>
                    <div class="text-xs text-gray-500">Total Checks</div>
                </div>
                <div class="stat-card bg-white rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-green-600"><?php echo $userStats['active_checks']; ?></div>
                    <div class="text-xs text-gray-500">Active</div>
                </div>
                <div class="stat-card bg-white rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-green-600"><?php echo $userStats['checks_up']; ?></div>
                    <div class="text-xs text-gray-500">Up</div>
                </div>
                <div class="stat-card bg-white rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-red-600"><?php echo $userStats['checks_down']; ?></div>
                    <div class="text-xs text-gray-500">Down</div>
                </div>
                <div class="stat-card bg-white rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-orange-600"><?php echo $userStats['total_incidents']; ?></div>
                    <div class="text-xs text-gray-500">Total Incidents</div>
                </div>
                <div class="stat-card bg-white rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-red-600"><?php echo $userStats['open_incidents']; ?></div>
                    <div class="text-xs text-gray-500">Open</div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- Update Profile Form -->
        <div class="glass rounded-lg shadow-sm p-6">
            <div class="flex items-center mb-4">
                <div class="bg-gradient-to-r from-blue-500 to-cyan-600 rounded-lg p-2 mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                </div>
                <h2 class="text-lg font-semibold text-gray-800">Profile Information</h2>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $auth->getCsrfToken(); ?>">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="<?php echo htmlspecialchars($currentUser['name'] ?? ''); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                    <input type="email" id="email" name="email" required maxlength="255"
                           value="<?php echo htmlspecialchars($currentUser['email']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <button type="submit" 
                        class="w-full px-4 py-2 bg-gradient-to-r from-blue-600 to-cyan-600 text-white rounded-lg hover:from-blue-700 hover:to-cyan-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                    Update Profile
                </button>
            </form>
        </div>
        
        <!-- Change Password Form -->
        <div class="glass rounded-lg shadow-sm p-6">
            <div class="flex items-center mb-4">
                <div class="bg-gradient-to-r from-orange-500 to-red-600 rounded-lg p-2 mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <h2 class="text-lg font-semibold text-gray-800">Change Password</h2>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $auth->getCsrfToken(); ?>">
                <input type="hidden" name="action" value="change_password">
                
                <div class="mb-4">
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                </div>
                
                <div class="mb-4">
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="6"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                    <p class="text-xs mt-1" id="password-strength"></p>
                </div>
                
                <div class="mb-4">
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                    <p class="text-xs text-red-500 mt-1 hidden" id="password-match">Passwords do not match</p>
                </div>
                
                <button type="submit" 
                        class="w-full px-4 py-2 bg-gradient-to-r from-orange-600 to-red-600 text-white rounded-lg hover:from-orange-700 hover:to-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 transition duration-200">
                    Change Password
                </button>
            </form>
        </div>
    </div>

    <!-- Account Information Card -->
    <div class="glass rounded-lg shadow-sm p-6">
        <div class="flex items-center mb-4">
            <div class="bg-gradient-to-r from-green-500 to-teal-600 rounded-lg p-2 mr-3">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-800">Account Information</h3>
        </div>
        
        <div class="grid md:grid-cols-3 gap-4">
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-600 mb-1">User ID</p>
                <p class="font-medium text-gray-800">#<?php echo htmlspecialchars($userId); ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-600 mb-1">Account Created</p>
                <p class="font-medium text-gray-800"><?php echo date('M d, Y', strtotime($currentUser['created_at'])); ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-600 mb-1">Session Started</p>
                <p class="font-medium text-gray-800"><?php echo date('H:i', $_SESSION['login_time']); ?></p>
            </div>
        </div>
        
        <div class="mt-4 pt-4 border-t border-gray-200">
            <a href="/logout.php" 
               class="inline-flex items-center px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition duration-200">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Sign Out
            </a>
        </div>
    </div>
</div>

<script>
// Password strength indicator
document.getElementById('new_password')?.addEventListener('input', function(e) {
    const password = e.target.value;
    const indicator = document.getElementById('password-strength');
    let strength = 0;
    let message = '';
    
    if (password.length >= 6) strength++;
    if (password.length >= 10) strength++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    if (password.length === 0) {
        indicator.textContent = '';
        indicator.className = 'text-xs mt-1';
    } else if (strength < 2) {
        indicator.textContent = 'Weak password';
        indicator.className = 'text-xs mt-1 text-red-500';
    } else if (strength < 4) {
        indicator.textContent = 'Moderate password';
        indicator.className = 'text-xs mt-1 text-yellow-600';
    } else {
        indicator.textContent = 'Strong password';
        indicator.className = 'text-xs mt-1 text-green-600';
    }
});

// Password match validation
document.getElementById('confirm_password')?.addEventListener('input', function(e) {
    const newPwd = document.getElementById('new_password').value;
    const confirmPwd = e.target.value;
    const matchIndicator = document.getElementById('password-match');
    
    if (confirmPwd && newPwd !== confirmPwd) {
        matchIndicator.classList.remove('hidden');
        e.target.setCustomValidity('Passwords do not match');
    } else {
        matchIndicator.classList.add('hidden');
        e.target.setCustomValidity('');
    }
});

// Clear password fields on successful submission
if (<?php echo $successMessage && strpos($successMessage, 'Password') !== false ? 'true' : 'false'; ?>) {
    document.getElementById('current_password').value = '';
    document.getElementById('new_password').value = '';
    document.getElementById('confirm_password').value = '';
}
</script>

<?php
$content = ob_get_clean();

// Render using the template from bootstrap.php
renderTemplate('Profile Settings', $content);
?>