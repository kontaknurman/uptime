<?php
// lib/Emailer.php
class Emailer {
    private $config;
    private $debug = false; // Set to true for detailed logging
    
    public function __construct(array $config) {
        $this->config = $config['smtp'];
        // Enable debug mode if in development
        $this->debug = (isset($config['app']['debug']) && $config['app']['debug'] === true);
    }

    public function send(string $to, string $subject, string $body): bool {
        // Validate email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Emailer: Invalid email address: {$to}");
            return false;
        }

        // Simple SMTP implementation with better error handling
        try {
            $socket = $this->connectToSMTP();
            if (!$socket) {
                error_log("Emailer: Failed to connect to SMTP server");
                return false;
            }

            // Send EHLO
            $this->sendSMTPCommand($socket, "EHLO localhost");
            
            // Handle STARTTLS for TLS encryption
            if ($this->config['encryption'] === 'tls') {
                $this->sendSMTPCommand($socket, "STARTTLS");
                
                // Enable crypto
                $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$crypto) {
                    error_log("Emailer: Failed to enable TLS encryption");
                    throw new Exception("Failed to enable TLS encryption");
                }
                
                // Send EHLO again after STARTTLS
                $this->sendSMTPCommand($socket, "EHLO localhost");
            }

            // Authentication
            if (!empty($this->config['username'])) {
                try {
                    $this->sendSMTPCommand($socket, "AUTH LOGIN");
                    $this->sendSMTPCommand($socket, base64_encode($this->config['username']));
                    $this->sendSMTPCommand($socket, base64_encode($this->config['password']));
                } catch (Exception $e) {
                    error_log("Emailer: Authentication failed - " . $e->getMessage());
                    throw new Exception("SMTP authentication failed");
                }
            }

            // Send email envelope
            $this->sendSMTPCommand($socket, "MAIL FROM: <{$this->config['from_email']}>");
            $this->sendSMTPCommand($socket, "RCPT TO: <{$to}>");
            $this->sendSMTPCommand($socket, "DATA");

            // Build and send email content
            $email = $this->buildEmailMessage($to, $subject, $body);
            fwrite($socket, $email . "\r\n.\r\n");
            
            // Get response after sending data
            $response = fgets($socket);
            if (strpos($response, '250') !== 0) {
                error_log("Emailer: Failed to send message. Response: " . trim($response));
                throw new Exception("Failed to send message");
            }

            // Quit
            $this->sendSMTPCommand($socket, "QUIT");
            fclose($socket);

            // Only log successful sends in debug mode or for important subjects
            if ($this->debug || strpos($subject, 'DOWN') !== false || strpos($subject, 'RECOVERY') !== false) {
                error_log("Emailer: Sent alert to {$to} - Subject: {$subject}");
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Emailer: Email send failed - ' . $e->getMessage());
            if (isset($socket) && is_resource($socket)) {
                fwrite($socket, "QUIT\r\n");
                fclose($socket);
            }
            return false;
        }
    }

    private function connectToSMTP() {
        // Create stream context with timeout
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        // Determine connection string
        if ($this->config['encryption'] === 'ssl') {
            $host = 'ssl://' . $this->config['host'];
        } else {
            $host = $this->config['host'];
        }

        if ($this->debug) {
            error_log("Emailer: Connecting to {$host}:{$this->config['port']}");
        }

        // Connect with timeout
        $socket = @stream_socket_client(
            "{$host}:{$this->config['port']}", 
            $errno, 
            $errstr, 
            30, 
            STREAM_CLIENT_CONNECT, 
            $context
        );

        if (!$socket) {
            error_log("Emailer: SMTP connection failed to {$host}:{$this->config['port']} - {$errstr} (Error {$errno})");
            return false;
        }

        // Set timeout for socket operations
        stream_set_timeout($socket, 30);

        // Read greeting
        $response = fgets($socket);
        if (strpos($response, '220') !== 0) {
            error_log("Emailer: Invalid SMTP greeting: " . trim($response));
            fclose($socket);
            return false;
        }

        if ($this->debug) {
            error_log("Emailer: Connected successfully");
        }
        
        return $socket;
    }

    private function sendSMTPCommand($socket, string $command): string {
        // Only log in debug mode (except for errors)
        if ($this->debug) {
            $logCommand = $command;
            if (strpos($command, 'AUTH') === false && strlen($command) < 100) {
                error_log("Emailer: >> " . trim($command));
            } elseif (strpos($command, 'AUTH LOGIN') === 0) {
                error_log("Emailer: >> AUTH LOGIN");
            } else {
                error_log("Emailer: >> [CREDENTIALS]");
            }
        }
        
        fwrite($socket, $command . "\r\n");
        $response = fgets($socket);
        
        if ($this->debug) {
            error_log("Emailer: << " . trim($response));
        }
        
        // Check for success response codes
        $successCodes = ['220', '221', '235', '250', '334', '354'];
        $responseCode = substr($response, 0, 3);
        
        if (!in_array($responseCode, $successCodes)) {
            // Always log errors
            error_log("Emailer: SMTP command failed. Response: " . trim($response));
            throw new Exception("SMTP command failed. Response: " . trim($response));
        }
        
        return $response;
    }

    private function buildEmailMessage(string $to, string $subject, string $body): string {
        $headers = [];
        
        // From header with name if provided
        if (!empty($this->config['from_name'])) {
            $headers[] = "From: {$this->config['from_name']} <{$this->config['from_email']}>";
        } else {
            $headers[] = "From: {$this->config['from_email']}";
        }
        
        $headers[] = "To: {$to}";
        $headers[] = "Subject: {$subject}";
        $headers[] = "Date: " . date('r');
        $headers[] = "Message-ID: <" . md5(uniqid(rand(), true)) . "@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: 8bit";
        $headers[] = "X-Mailer: Uptime Monitor";
        $headers[] = "X-Priority: 3";

        // Build complete message
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        
        return $message;
    }
    
    /**
     * Test method to verify SMTP configuration
     */
    public function test(): array {
        $results = [
            'config_valid' => false,
            'connection' => false,
            'authentication' => false,
            'errors' => []
        ];
        
        // Check configuration
        if (empty($this->config['host']) || empty($this->config['username']) || empty($this->config['password'])) {
            $results['errors'][] = "Incomplete SMTP configuration";
            return $results;
        }
        
        $results['config_valid'] = true;
        
        // Test connection
        try {
            $socket = $this->connectToSMTP();
            if ($socket) {
                $results['connection'] = true;
                
                // Test authentication
                try {
                    $this->sendSMTPCommand($socket, "EHLO localhost");
                    
                    if ($this->config['encryption'] === 'tls') {
                        $this->sendSMTPCommand($socket, "STARTTLS");
                        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                        $this->sendSMTPCommand($socket, "EHLO localhost");
                    }
                    
                    $this->sendSMTPCommand($socket, "AUTH LOGIN");
                    $this->sendSMTPCommand($socket, base64_encode($this->config['username']));
                    $this->sendSMTPCommand($socket, base64_encode($this->config['password']));
                    
                    $results['authentication'] = true;
                    
                    $this->sendSMTPCommand($socket, "QUIT");
                    fclose($socket);
                    
                } catch (Exception $e) {
                    $results['errors'][] = "Authentication failed: " . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $results['errors'][] = "Connection failed: " . $e->getMessage();
        }
        
        return $results;
    }
}
?>