<?php
/**
 * Emailer class using SMTP2GO API
 * Secure implementation with proper error handling and validation
 */
class Emailer {
    private $config;
    private $apiUrl = 'https://api.smtp2go.com/v3/email/send';
    private $debug = false;
    
    public function __construct(array $config) {
        $this->config = $config['smtp2go'] ?? [];
        $this->debug = (isset($config['app']['debug']) && $config['app']['debug'] === true);
        
        // Validate configuration
        if (empty($this->config['api_key'])) {
            throw new Exception("SMTP2GO API key not configured");
        }
    }

    /**
     * Send email using SMTP2GO API
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body Email body (plain text)
     * @return bool Success status
     */
    public function send(string $to, string $subject, string $body): bool {
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Emailer: Invalid email address: {$to}");
            return false;
        }

        // Sanitize inputs to prevent injection
        $subject = $this->sanitizeHeader($subject);
        
        try {
            // Prepare API request data
            $data = [
                'api_key' => $this->config['api_key'],
                'to' => [$to],
                'sender' => $this->config['from_email'],
                'subject' => $subject,
                'text_body' => $body,
                'custom_headers' => [
                    [
                        'header' => 'X-Mailer',
                        'value' => 'Uptime Monitor'
                    ],
                    [
                        'header' => 'X-Priority',
                        'value' => '3'
                    ]
                ]
            ];

            // Add sender name if configured
            if (!empty($this->config['from_name'])) {
                $data['sender'] = "{$this->config['from_name']} <{$this->config['from_email']}>";
            }

            // Make API request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->apiUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                error_log("Emailer: CURL error - {$curlError}");
                return false;
            }

            $responseData = json_decode($response, true);

            if ($httpCode === 200 && isset($responseData['data']['succeeded']) && $responseData['data']['succeeded'] > 0) {
                if ($this->debug) {
                    error_log("Emailer: Email sent successfully to {$to}");
                    if (isset($responseData['data']['email_id'])) {
                        error_log("Emailer: Email ID: " . $responseData['data']['email_id']);
                    }
                }
                return true;
            } else {
                $errorMsg = $responseData['data']['error'] ?? 'Unknown error';
                error_log("Emailer: Failed to send email. HTTP {$httpCode}. Error: {$errorMsg}");
                return false;
            }
            
        } catch (Exception $e) {
            error_log("Emailer: Exception - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Test API connectivity and authentication
     * @return array Test results
     */
    public function test(): array {
        $results = [
            'config_valid' => false,
            'connection' => false,
            'authentication' => false,
            'errors' => []
        ];
        
        // Check configuration
        if (empty($this->config['api_key']) || empty($this->config['from_email'])) {
            $results['errors'][] = "Incomplete SMTP2GO configuration";
            return $results;
        }
        
        $results['config_valid'] = true;
        
        // Test API connection and authentication using a simple email validation test
        try {
            // Use the email/send endpoint with validate_only flag for testing
            $testData = [
                'api_key' => $this->config['api_key'],
                'to' => ['test@example.com'],
                'sender' => $this->config['from_email'],
                'subject' => 'API Test',
                'text_body' => 'Test',
                'test_mode' => true  // This prevents actual sending
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->apiUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($testData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                $results['errors'][] = "Connection failed: " . $curlError;
                return $results;
            }
            
            $results['connection'] = true;
            
            // Check authentication based on response
            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                if (isset($responseData['data'])) {
                    $results['authentication'] = true;
                } else {
                    $results['errors'][] = "API response format unexpected";
                }
            } elseif ($httpCode === 401) {
                $results['errors'][] = "Invalid API key - authentication failed";
            } elseif ($httpCode === 403) {
                $results['errors'][] = "API key valid but lacks permissions";
            } else {
                $responseData = json_decode($response, true);
                $errorMsg = $responseData['data']['error'] ?? "Unknown error (HTTP {$httpCode})";
                $results['errors'][] = "API error: " . $errorMsg;
            }
            
        } catch (Exception $e) {
            $results['errors'][] = "Exception: " . $e->getMessage();
        }
        
        return $results;
    }

    /**
     * Sanitize header values to prevent injection
     * @param string $value Header value
     * @return string Sanitized value
     */
    private function sanitizeHeader(string $value): string {
        // Remove line breaks and null bytes
        $value = str_replace(["\r", "\n", "\0", "\t"], '', $value);
        // Limit length for security
        return substr(trim($value), 0, 998);
    }

    /**
     * Send batch emails efficiently
     * @param array $recipients Array of email addresses
     * @param string $subject Email subject
     * @param string $body Email body
     * @return array Results with success/failure for each recipient
     */
    public function sendBatch(array $recipients, string $subject, string $body): array {
        $results = [];
        
        // Validate all recipients first
        $validRecipients = [];
        foreach ($recipients as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validRecipients[] = $email;
            } else {
                $results[$email] = false;
                error_log("Emailer: Invalid email in batch: {$email}");
            }
        }
        
        if (empty($validRecipients)) {
            return $results;
        }
        
        // SMTP2GO supports up to 50 recipients per request
        $chunks = array_chunk($validRecipients, 50);
        
        foreach ($chunks as $chunk) {
            try {
                $data = [
                    'api_key' => $this->config['api_key'],
                    'to' => $chunk,
                    'sender' => $this->config['from_email'],
                    'subject' => $this->sanitizeHeader($subject),
                    'text_body' => $body
                ];
                
                if (!empty($this->config['from_name'])) {
                    $data['sender'] = "{$this->config['from_name']} <{$this->config['from_email']}>";
                }
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->apiUrl,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json'
                    ],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_TIMEOUT => 30
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    foreach ($chunk as $email) {
                        $results[$email] = true;
                    }
                } else {
                    foreach ($chunk as $email) {
                        $results[$email] = false;
                    }
                }
                
            } catch (Exception $e) {
                error_log("Emailer: Batch send exception - " . $e->getMessage());
                foreach ($chunk as $email) {
                    $results[$email] = false;
                }
            }
        }
        
        return $results;
    }
}
?>