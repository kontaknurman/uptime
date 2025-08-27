<?php
/**
 * Direct SMTP Test - No CSRF Required
 * This bypasses authentication for testing purposes
 */

// Load config directly without bootstrap (to avoid CSRF)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load configuration directly
if (!file_exists('config/config.php')) {
    die('Config file not found');
}
$config = require 'config/config.php';

// Set timezone
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// HTML header
?>
<!DOCTYPE html>
<html>
<head>
    <title>Direct SMTP Test</title>
    <script src='https://cdn.tailwindcss.com'></script>
</head>
<body class='bg-gray-100 p-8'>
<div class='max-w-5xl mx-auto bg-white rounded-lg shadow p-6'>
<h1 class='text-2xl font-bold mb-6'>🔧 Direct SMTP Test (No Auth Required)</h1>

<?php
// Process test if email provided
$test_email = $_REQUEST['email'] ?? '';
$action = $_REQUEST['action'] ?? '';

if (empty($test_email)) {
    // Show form
    ?>
    <form method="get" class="space-y-4">
        <div>
            <label class="block text-sm font-medium mb-2">Test Email Address:</label>
            <input type="email" name="email" required 
                   class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                   placeholder="your-email@example.com"
                   value="<?php echo htmlspecialchars($config['smtp']['username'] ?? ''); ?>">
        </div>
        <div class="flex space-x-3">
            <button type="submit" name="action" value="quick" 
                    class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                Quick Test
            </button>
            <button type="submit" name="action" value="detailed" 
                    class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                Detailed Test
            </button>
        </div>
    </form>
    
    <div class="mt-8 p-4 bg-yellow-50 border border-yellow-200 rounded">
        <h2 class="font-bold mb-2">⚠️ Current Configuration:</h2>
        <table class="w-full text-sm">
            <tr><td class="py-1 font-medium">SMTP Host:</td><td><?php echo $config['smtp']['host'] ?? 'NOT SET'; ?></td></tr>
            <tr><td class="py-1 font-medium">Port:</td><td><?php echo $config['smtp']['port'] ?? 'NOT SET'; ?></td></tr>
            <tr><td class="py-1 font-medium">Username:</td><td><?php echo $config['smtp']['username'] ?? 'NOT SET'; ?></td></tr>
            <tr><td class="py-1 font-medium">From Email:</td><td><?php echo $config['smtp']['from_email'] ?? 'NOT SET'; ?></td></tr>
            <tr><td class="py-1 font-medium">Password Set:</td><td><?php echo !empty($config['smtp']['password']) ? '✅ Yes' : '❌ No'; ?></td></tr>
        </table>
    </div>
    <?php
} else {
    // Run test
    echo "<div class='mb-4 p-3 bg-blue-50 border border-blue-200 rounded'>";
    echo "Testing email delivery to: <strong>" . htmlspecialchars($test_email) . "</strong>";
    echo "</div>";
    
    $smtp = $config['smtp'];
    
    if ($action === 'quick') {
        // Quick test - just try to send
        ?>
        <div class="p-4 bg-gray-50 rounded">
            <h2 class="font-bold mb-3">Quick Email Send Test</h2>
            <pre class="text-sm">
<?php
        try {
            // Simple SMTP send
            $result = send_test_email($smtp, $test_email);
            
            if ($result['success']) {
                echo "✅ Email sent successfully!\n";
                echo "Queue ID: " . ($result['queue_id'] ?? 'N/A') . "\n";
                echo "\n📧 Check your inbox and spam folder\n";
            } else {
                echo "❌ Failed to send email\n";
                echo "Error: " . $result['error'] . "\n";
            }
        } catch (Exception $e) {
            echo "❌ Exception: " . $e->getMessage() . "\n";
        }
?>
            </pre>
        </div>
        <?php
    } else {
        // Detailed test with full diagnostics
        ?>
        <div class="space-y-4">
            
            <!-- Connection Test -->
            <div class="p-4 border rounded">
                <h2 class="font-bold mb-3">1. Connection Test</h2>
                <pre class="text-sm bg-gray-50 p-2 rounded">
<?php
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        $host = $smtp['encryption'] === 'ssl' ? 'ssl://' . $smtp['host'] : $smtp['host'];
        echo "Connecting to {$host}:{$smtp['port']}...\n";
        
        $fp = @stream_socket_client(
            "{$host}:{$smtp['port']}", 
            $errno, 
            $errstr, 
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$fp) {
            echo "❌ Connection failed: {$errstr} (Error {$errno})\n";
        } else {
            echo "✅ Connected successfully\n";
            
            // Get greeting
            $greeting = fgets($fp, 515);
            echo "Server: " . trim($greeting) . "\n";
            fclose($fp);
        }
?>
                </pre>
            </div>
            
            <!-- DNS Check -->
            <div class="p-4 border rounded">
                <h2 class="font-bold mb-3">2. DNS/SPF Check</h2>
                <?php
                $domain = 'audiensi.com';
                $txt_records = @dns_get_record($domain, DNS_TXT);
                $has_spf = false;
                
                if ($txt_records) {
                    foreach ($txt_records as $record) {
                        if (strpos($record['txt'], 'v=spf1') !== false) {
                            $has_spf = true;
                            echo "<div class='p-2 bg-green-50 rounded'>";
                            echo "✅ SPF found: <code class='text-xs'>" . htmlspecialchars($record['txt']) . "</code>";
                            echo "</div>";
                            break;
                        }
                    }
                }
                
                if (!$has_spf) {
                    echo "<div class='p-3 bg-red-50 text-red-600 rounded'>";
                    echo "<strong>❌ No SPF record!</strong><br>";
                    echo "Add this TXT record to audiensi.com DNS:<br>";
                    echo "<code class='bg-white px-2 py-1 rounded block mt-2'>v=spf1 include:_spf.smtp2go.com ~all</code>";
                    echo "</div>";
                }
                ?>
            </div>
            
            <!-- Full Send Test -->
            <div class="p-4 border rounded">
                <h2 class="font-bold mb-3">3. Send Test Email</h2>
                <pre class="text-sm bg-gray-50 p-2 rounded">
<?php
        $result = send_test_email($smtp, $test_email, true);
        
        if ($result['success']) {
            echo "✅ EMAIL SENT SUCCESSFULLY!\n";
            if (isset($result['queue_id'])) {
                echo "Queue ID: {$result['queue_id']}\n";
            }
            echo "\nCheck:\n";
            echo "1. Your inbox\n";
            echo "2. Spam/Junk folder\n";
            echo "3. SMTP2GO Activity log\n";
        } else {
            echo "❌ Failed: " . $result['error'] . "\n";
        }
?>
                </pre>
            </div>
            
        </div>
        <?php
    }
    
    // Show next steps
    ?>
    <div class="mt-6 p-4 bg-green-50 border border-green-200 rounded">
        <h2 class="font-bold mb-3">📋 Next Steps:</h2>
        <ol class="list-decimal list-inside space-y-2">
            <li><strong>Check SMTP2GO Dashboard:</strong>
                <a href="https://app.smtp2go.com" target="_blank" class="text-blue-600 underline">
                    https://app.smtp2go.com
                </a> → Reports → Activity
            </li>
            <li><strong>Look for delivery status:</strong>
                <ul class="ml-6 mt-1 text-sm">
                    <li>✅ Sent = Check spam folder</li>
                    <li>⚠️ Bounced = Email rejected</li>
                    <li>❌ Blocked = SMTP2GO blocked it</li>
                </ul>
            </li>
            <?php if (!isset($has_spf) || !$has_spf): ?>
            <li class="text-red-600"><strong>Add SPF record to DNS (CRITICAL)</strong></li>
            <?php endif; ?>
        </ol>
    </div>
    
    <div class="mt-4">
        <a href="?email=" class="text-blue-600 hover:underline">← Test another email</a>
    </div>
    <?php
}

// Helper function to send test email
function send_test_email($smtp, $to_email, $verbose = false) {
    $result = ['success' => false, 'error' => '', 'queue_id' => null];
    
    try {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        $host = $smtp['encryption'] === 'ssl' ? 'ssl://' . $smtp['host'] : $smtp['host'];
        
        $fp = @stream_socket_client(
            "{$host}:{$smtp['port']}", 
            $errno, 
            $errstr, 
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$fp) {
            $result['error'] = "Connection failed: {$errstr}";
            return $result;
        }
        
        // Helper to read response
        $read_response = function($fp) {
            $response = '';
            while ($line = fgets($fp, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) == ' ') break;
            }
            return $response;
        };
        
        // Read greeting
        $greeting = fgets($fp, 515);
        if ($verbose) echo "<<< " . trim($greeting) . "\n";
        
        // EHLO
        fwrite($fp, "EHLO localhost\r\n");
        if ($verbose) echo ">>> EHLO localhost\n";
        $read_response($fp);
        
        // STARTTLS if needed
        if ($smtp['encryption'] === 'tls') {
            fwrite($fp, "STARTTLS\r\n");
            if ($verbose) echo ">>> STARTTLS\n";
            $response = fgets($fp, 515);
            if ($verbose) echo "<<< " . trim($response) . "\n";
            
            if (strpos($response, '220') === 0) {
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($verbose) echo "[TLS Enabled]\n";
                
                fwrite($fp, "EHLO localhost\r\n");
                if ($verbose) echo ">>> EHLO localhost\n";
                $read_response($fp);
            }
        }
        
        // AUTH LOGIN
        fwrite($fp, "AUTH LOGIN\r\n");
        if ($verbose) echo ">>> AUTH LOGIN\n";
        $response = fgets($fp, 515);
        if ($verbose) echo "<<< " . trim($response) . "\n";
        
        if (strpos($response, '334') !== 0) {
            $result['error'] = "AUTH LOGIN not supported";
            fclose($fp);
            return $result;
        }
        
        // Username
        fwrite($fp, base64_encode($smtp['username']) . "\r\n");
        if ($verbose) echo ">>> [USERNAME]\n";
        $response = fgets($fp, 515);
        if ($verbose) echo "<<< " . trim($response) . "\n";
        
        // Password
        fwrite($fp, base64_encode($smtp['password']) . "\r\n");
        if ($verbose) echo ">>> [PASSWORD]\n";
        $response = fgets($fp, 515);
        if ($verbose) echo "<<< " . trim($response) . "\n";
        
        if (strpos($response, '235') !== 0) {
            $result['error'] = "Authentication failed: " . trim($response);
            fclose($fp);
            return $result;
        }
        
        if ($verbose) echo "✅ Authenticated\n\n";
        
        // MAIL FROM
        fwrite($fp, "MAIL FROM: <{$smtp['from_email']}>\r\n");
        if ($verbose) echo ">>> MAIL FROM: <{$smtp['from_email']}>\n";
        $response = fgets($fp, 515);
        if ($verbose) echo "<<< " . trim($response) . "\n";
        
        // RCPT TO
        fwrite($fp, "RCPT TO: <{$to_email}>\r\n");
        if ($verbose) echo ">>> RCPT TO: <{$to_email}>\n";
        $response = fgets($fp, 515);
        if ($verbose) echo "<<< " . trim($response) . "\n";
        
        // DATA
        fwrite($fp, "DATA\r\n");
        if ($verbose) echo ">>> DATA\n";
        $response = fgets($fp, 515);
        if ($verbose) echo "<<< " . trim($response) . "\n";
        
        // Email content
        $timestamp = date('Y-m-d H:i:s');
        $message_id = md5(uniqid(rand(), true));
        
        $email = "From: Uptime Monitor <{$smtp['from_email']}>\r\n";
        $email .= "To: {$to_email}\r\n";
        $email .= "Subject: Test Email - {$timestamp}\r\n";
        $email .= "Date: " . date('r') . "\r\n";
        $email .= "Message-ID: <{$message_id}@{$_SERVER['HTTP_HOST']}>\r\n";
        $email .= "MIME-Version: 1.0\r\n";
        $email .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $email .= "\r\n";
        $email .= "This is a test email from Uptime Monitor.\r\n\r\n";
        $email .= "Timestamp: {$timestamp}\r\n";
        $email .= "Server: {$_SERVER['HTTP_HOST']}\r\n";
        $email .= "SMTP Host: {$smtp['host']}\r\n\r\n";
        $email .= "If you received this, your email configuration is working!\r\n";
        
        fwrite($fp, $email . "\r\n.\r\n");
        if ($verbose) echo ">>> [EMAIL CONTENT]\n>>> .\n";
        
        $response = fgets($fp, 515);
        if ($verbose) echo "<<< " . trim($response) . "\n";
        
        if (strpos($response, '250') === 0) {
            $result['success'] = true;
            
            // Extract queue ID
            if (preg_match('/queued as ([A-Z0-9]+)/i', $response, $matches)) {
                $result['queue_id'] = $matches[1];
            }
        } else {
            $result['error'] = "Email rejected: " . trim($response);
        }
        
        // QUIT
        fwrite($fp, "QUIT\r\n");
        if ($verbose) echo "\n>>> QUIT\n";
        fclose($fp);
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}
?>

<div class="mt-8 p-4 bg-blue-50 border border-blue-200 rounded">
    <h2 class="font-bold mb-2">ℹ️ Important Notes:</h2>
    <ul class="list-disc list-inside space-y-1 text-sm">
        <li>This test bypasses authentication for debugging purposes only</li>
        <li>SPF record is <strong>required</strong> for email delivery</li>
        <li>Check SMTP2GO dashboard for delivery status</li>
        <li>Emails may take 1-2 minutes to arrive</li>
    </ul>
</div>

</div>
</body>
</html>