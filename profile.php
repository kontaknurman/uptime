<?php
/**
 * User Profile Management Page
 * Uses the existing template design from bootstrap.php
 */

require_once 'bootstrap.php';

// Require authentication
$auth->requireAuth();

// Get current user data
$userId = $_SESSION['user_id'];
$currentUser = $db->fetchOne(
    "SELECT id, email, name FROM users WHERE id = ?",
    [$userId]
);

if (!$currentUser) {
    session_destroy();
    header('Location: /login.php');
    exit;
}

// Initialize variables
$successMessage = '';
$errorMessage = '';
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF is already validated in bootstrap.php
    
    // Verify current password for all actions
    $currentPassword = $_POST['current_password'] ?? '';
    $user = $db->fetchOne(
        "SELECT password_hash FROM users WHERE id = ?",
        [$userId]
    );
    
    if (!password_verify($currentPassword, $user['password_hash'])) {
        $errorMessage = 'Current password is incorrect.';
    } else {
        // Process based on action
        switch ($action) {
            case 'update_profile':
                // Update name and email
                $newName = trim($_POST['name'] ?? '');
                $newEmail = trim($_POST['email'] ?? '');
                
                // Validate inputs
                if (empty($newName)) {
                    $errors[] = 'Name is required.';
                } elseif (strlen($newName) > 100) {
                    $errors[] = 'Name must be 100 characters or less.';
                }
                
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Please enter a valid email address.';
                } else {
                    // Check if email is already in use by another user
                    $existingUser = $db->fetchOne(
                        "SELECT id FROM users WHERE email = ? AND id != ?",
                        [$newEmail, $userId]
                    );
                    if ($existingUser) {
                        $errors[] = 'This email address is already in use.';
                    }
                }
                
                if (empty($errors)) {
                    try {
                        $db->update('users', [
                            'name' => $newName,
                            'email' => $newEmail
                        ], 'id = ?', [$userId]);
                        
                        // Update session
                        $_SESSION['user_email'] = $newEmail;
                        
                        $successMessage = 'Profile updated successfully!';
                        $currentUser['name'] = $newName;
                        $currentUser['email'] = $newEmail;
                    } catch (Exception $e) {
                        $errorMessage = 'Failed to update profile. Please try again.';
                    }
                } else {
                    $errorMessage = implode(' ', $errors);
                }
                break;
                
            case 'change_password':
                // Change password
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                // Validate password
                if (strlen($newPassword) < 8) {
                    $errors[] = 'Password must be at least 8 characters long.';
                }
                if ($newPassword !== $confirmPassword) {
                    $errors[] = 'New passwords do not match.';
                }
                if ($newPassword === $currentPassword) {
                    $errors[] = 'New password must be different from current password.';
                }
                
                if (empty($errors)) {
                    try {
                        $newHash = $auth->hashPassword($newPassword);
                        $db->update('users', [
                            'password_hash' => $newHash
                        ], 'id = ?', [$userId]);
                        
                        $successMessage = 'Password changed successfully!';
                    } catch (Exception $e) {
                        $errorMessage = 'Failed to change password. Please try again.';
                    }
                } else {
                    $errorMessage = implode(' ', $errors);
                }
                break;
        }
    }
}

// Start output buffering for content
ob_start();
?>

<div class="container mx-auto px-4 py-6 max-w-7xl">
    <!-- Page Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-800 flex items-center">
            <svg class="w-6 h-6 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
            </svg>
            Account Settings
        </h2>
        <p class="text-gray-600 mt-1">Manage your profile information and security settings</p>
    </div>

    <?php if ($successMessage): ?>
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg flex items-center">
            <svg class="w-5 h-5 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span class="text-green-800"><?php echo htmlspecialchars($successMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center">
            <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span class="text-red-800"><?php echo htmlspecialchars($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-2">
        <!-- Profile Information Card -->
        <div class="glass rounded-lg shadow-sm p-6">
            <div class="flex items-center mb-4">
                <div class="bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg p-2 mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Profile Information</h3>
            </div>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($auth->getCsrfToken()); ?>">
                
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                        Full Name
                    </label>
                    <div class="relative">
                        <input type="text" 
                               id="name" 
                               name="name" 
                               value="<?php echo htmlspecialchars($currentUser['name'] ?? ''); ?>"
                               maxlength="100"
                               required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        <svg class="absolute right-3 top-3 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                    </div>
                </div>
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                        Email Address
                    </label>
                    <div class="relative">
                        <input type="email" 
                               id="email" 
                               name="email" 
                               value="<?php echo htmlspecialchars($currentUser['email']); ?>"
                               required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        <svg class="absolute right-3 top-3 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                </div>
                
                <div>
                    <label for="current_password_profile" class="block text-sm font-medium text-gray-700 mb-1">
                        Current Password
                    </label>
                    <div class="relative">
                        <input type="password" 
                               id="current_password_profile" 
                               name="current_password" 
                               required
                               placeholder="Enter current password to confirm"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        <svg class="absolute right-3 top-3 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Required to save changes</p>
                </div>
                
                <button type="submit" 
                        class="w-full px-4 py-2 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-lg hover:from-blue-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 transition duration-200">
                    Update Profile
                </button>
            </form>
        </div>

        <!-- Change Password Card -->
        <div class="glass rounded-lg shadow-sm p-6">
            <div class="flex items-center mb-4">
                <div class="bg-gradient-to-r from-orange-500 to-red-600 rounded-lg p-2 mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Security Settings</h3>
            </div>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($auth->getCsrfToken()); ?>">
                
                <div>
                    <label for="current_password_pwd" class="block text-sm font-medium text-gray-700 mb-1">
                        Current Password
                    </label>
                    <input type="password" 
                           id="current_password_pwd" 
                           name="current_password" 
                           required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                </div>
                
                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">
                        New Password
                    </label>
                    <input type="password" 
                           id="new_password" 
                           name="new_password" 
                           minlength="8"
                           required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    <p class="text-xs mt-1" id="password-strength">Minimum 8 characters</p>
                </div>
                
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                        Confirm New Password
                    </label>
                    <input type="password" 
                           id="confirm_password" 
                           name="confirm_password" 
                           minlength="8"
                           required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
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
    <div class="mt-6 glass rounded-lg shadow-sm p-6">
        <div class="flex items-center mb-4">
            <div class="bg-gradient-to-r from-green-500 to-teal-600 rounded-lg p-2 mr-3">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-800">Account Information</h3>
        </div>
        
        <div class="grid md:grid-cols-2 gap-4">
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-600 mb-1">User ID</p>
                <p class="font-medium text-gray-800">#<?php echo htmlspecialchars($userId); ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-600 mb-1">Email Status</p>
                <p class="font-medium text-green-600">Verified</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-600 mb-1">Session Started</p>
                <p class="font-medium text-gray-800"><?php echo date('M d, Y H:i', $_SESSION['login_time']); ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-600 mb-1">Session Expires</p>
                <p class="font-medium text-gray-800"><?php echo date('M d, Y H:i', $_SESSION['login_time'] + $config['app']['session_lifetime']); ?></p>
            </div>
        </div>
    </div>
</div>

<script>
// Password strength indicator
document.getElementById('new_password').addEventListener('input', function(e) {
    const password = e.target.value;
    const indicator = document.getElementById('password-strength');
    let strength = 0;
    
    if (password.length >= 8) strength++;
    if (password.length >= 12) strength++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    if (strength < 2) {
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
document.getElementById('confirm_password').addEventListener('input', function(e) {
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
</script>

<?php
$content = ob_get_clean();

// Render using the template
renderTemplate('Profile', $content);
?>