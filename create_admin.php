<?php
/**
 * Script to create admin user - run this once to create/reset admin password
 */

require_once 'bootstrap.php';

echo "<h2>Create Admin User</h2>";

// Check if admin user exists
$existingUser = $db->fetchOne('SELECT id, email FROM users WHERE email = ?', ['admin@example.com']);

if ($existingUser) {
    echo "<p>Admin user exists: " . $existingUser['email'] . " (ID: " . $existingUser['id'] . ")</p>";
    
    // Update password
    $newPasswordHash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 12]);
    $updated = $db->update('users', 
        ['password_hash' => $newPasswordHash], 
        'email = ?', 
        ['admin@example.com']
    );
    
    if ($updated) {
        echo "<p>✅ Password updated successfully!</p>";
    } else {
        echo "<p>❌ Failed to update password</p>";
    }
    
} else {
    // Create new admin user
    $passwordHash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 12]);
    
    try {
        $userId = $db->insert('users', [
            'email' => 'admin@example.com',
            'password_hash' => $passwordHash
        ]);
        
        echo "<p>✅ Admin user created successfully! ID: {$userId}</p>";
        
    } catch (Exception $e) {
        echo "<p>❌ Failed to create admin user: " . $e->getMessage() . "</p>";
    }
}

// Verify the password hash works
echo "<h3>Password Verification Test:</h3>";
$user = $db->fetchOne('SELECT password_hash FROM users WHERE email = ?', ['admin@example.com']);

if ($user) {
    $testPassword = 'Admin@123';
    $isValid = password_verify($testPassword, $user['password_hash']);
    
    echo "<p>Password hash: " . $user['password_hash'] . "</p>";
    echo "<p>Test password '{$testPassword}': " . ($isValid ? '✅ Valid' : '❌ Invalid') . "</p>";
    
    // Show bcrypt cost
    $info = password_get_info($user['password_hash']);
    echo "<p>Hash algorithm: " . $info['algo'] . ", Cost: " . ($info['options']['cost'] ?? 'N/A') . "</p>";
} else {
    echo "<p>❌ No user found</p>";
}

echo "<hr>";
echo "<p><strong>Login Details:</strong></p>";
echo "<ul>";
echo "<li>Email: admin@example.com</li>";
echo "<li>Password: Admin@123</li>";
echo "</ul>";

echo "<p><a href='login.php'>Go to Login Page</a></p>";