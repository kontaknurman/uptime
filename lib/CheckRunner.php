<?php
/// lib/CheckRunner.php

class CheckRunner {
    private $db;
    private $config;
    private $emailer;
    private $maxParallel = 10;
    
    public function __construct(Database $db, array $config, ?Emailer $emailer = null) {
        $this->db = $db;
        $this->config = $config;
        $this->emailer = $emailer;
        
        if (isset($config['monitoring']['max_parallel_checks'])) {
            $this->maxParallel = min($config['monitoring']['max_parallel_checks'], 50);
        }
        
        // Log initialization
        error_log("CheckRunner: Initialized with " . ($emailer ? "emailer configured" : "NO emailer"));
    }

    public function runDueChecks(): int {
        // Simple query: run checks when current time >= next_run_at
        $checks = $this->db->fetchAll(
            "SELECT * FROM checks WHERE enabled = 1 AND next_run_at <= NOW() ORDER BY next_run_at ASC"
        );

        if (empty($checks)) {
            return 0;
        }

        error_log("CheckRunner: Found " . count($checks) . " checks due for execution");

        // Process checks in batches for parallel execution
        $executed = 0;
        $batches = array_chunk($checks, $this->maxParallel);
        
        foreach ($batches as $batch) {
            $batchResults = $this->executeChecksBatch($batch);
            $executed += count(array_filter($batchResults, fn($r) => $r === true));
        }

        return $executed;
    }

    private function executeChecksBatch(array $checks): array {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $checkMap = [];
        
        // Initialize all curl handles
        foreach ($checks as $check) {
            $ch = $this->prepareCurlHandle($check);
            if ($ch !== false) {
                curl_multi_add_handle($multiHandle, $ch);
                $curlHandles[] = $ch;
                $checkMap[(int)$ch] = $check;
            }
        }
        
        // Execute all handles in parallel
        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
        
        // Process results
        $results = [];
        foreach ($curlHandles as $ch) {
            $check = $checkMap[(int)$ch];
            
            try {
                $httpResult = $this->processCurlResult($ch, $check);
                $this->storeCheckResult($check, $httpResult);
                $results[$check['id']] = true;
                
            } catch (Exception $e) {
                error_log("Check {$check['id']} failed: " . $e->getMessage());
                $results[$check['id']] = false;
                
                // Still update next_run_at even if check failed
                $this->updateNextRunTime($check['id'], $check['interval_seconds']);
            }
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multiHandle);
        return $results;
    }

    private function prepareCurlHandle(array $check) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $check["url"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $check["timeout_seconds"],
            CURLOPT_MAXREDIRS => $check["max_redirects"],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => "UptimeMonitor/1.0",
            CURLOPT_HEADER => true, // Get headers in response
            CURLOPT_NOBODY => false,
            CURLOPT_CUSTOMREQUEST => $check["method"],
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
        ]);
        
        // Add custom headers if specified
        $headers = [];
        if (!empty($check['request_headers'])) {
            $headers = array_filter(array_map('trim', explode("\n", $check['request_headers'])));
        }
        
        // Add request body (if specified) - works for any HTTP method
        if (!empty($check['request_body'])) {
            // Decode HTML entities that might have been stored in database
            $requestBody = html_entity_decode($check['request_body'], ENT_QUOTES, 'UTF-8');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
            
            // Automatically add Content-Length header if not already specified
            $hasContentLength = false;
            foreach ($headers as $header) {
                if (stripos($header, 'content-length:') === 0) {
                    $hasContentLength = true;
                    break;
                }
            }
            
            if (!$hasContentLength) {
                $headers[] = 'Content-Length: ' . strlen($requestBody);
            }
        }
        
        // Set headers if any exist
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        return $ch;
    }

    private function processCurlResult($ch, array $check): array {
        $response = curl_multi_getcontent($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        
        // Separate headers and body
        $responseHeaders = '';
        $responseBody = '';
        
        if ($response !== false && $headerSize > 0) {
            $responseHeaders = substr($response, 0, $headerSize);
            $responseBody = substr($response, $headerSize);
        } elseif ($response !== false) {
            $responseBody = $response;
        }
        
        return [
            "http_status" => $httpStatus ?: 0,
            "response_headers" => $responseHeaders,
            "response_body" => $responseBody,
            "error" => $error ?: null,
            "duration_ms" => (int)($totalTime * 1000)
        ];
    }

    private function storeCheckResult(array $check, array $result): void {
        $startedAt = date("Y-m-d H:i:s");
        $endedAt = date("Y-m-d H:i:s");
        
        // Enhanced validation logic
        $isUp = $this->validateCheckResult($check, $result);
        
        // Determine what data to store based on settings and result
        $shouldKeepFullData = $this->shouldKeepFullData($check, $isUp);
        
        // Generate comprehensive error message for DOWN checks
        $errorMessage = null;
        if (!$isUp) {
            $errorMessage = $this->generateErrorMessage($check, $result);
        } else {
            // Still include cURL errors even for UP checks (like redirects with errors)
            $errorMessage = $result["error"] ?? null;
        }
        
        $dataToStore = [
            "check_id" => $check["id"],
            "started_at" => $startedAt,
            "ended_at" => $endedAt,
            "duration_ms" => $result["duration_ms"],
            "http_status" => $result["http_status"],
            "is_up" => $isUp ? 1 : 0,
            "error_message" => $errorMessage
        ];
        
        if ($shouldKeepFullData) {
            // Store full response data
            $dataToStore["response_headers"] = $this->truncateText($result["response_headers"], 65535);
            $dataToStore["body_sample"] = $this->truncateText($result["response_body"], 65535);
        } else {
            // Store minimal data (headers as JSON, limited body)
            $dataToStore["response_headers"] = "{}";
            $dataToStore["body_sample"] = $this->truncateText($result["response_body"], 1000);
        }
        
        // Store result
        $resultId = $this->db->insert("check_results", $dataToStore);
        
        error_log("CheckRunner: Stored result {$resultId} for check {$check['id']} - Status: " . ($isUp ? "UP" : "DOWN"));
        
        // Handle state changes and incidents
        $this->handleStateChange($check, $isUp, $resultId);
        
        // Update check next run time and last state
        $this->updateNextRunTime($check["id"], $check["interval_seconds"]);
        $this->updateCheckState($check["id"], $isUp ? "UP" : "DOWN");
    }

    /**
     * Enhanced validation with body content checking
     */
    private function validateCheckResult(array $check, array $result): bool {
        // 1. Check HTTP Status
        if ($result["http_status"] != $check["expected_status"]) {
            return false;
        }
        
        // 2. Check Expected Headers (if specified)
        if (!empty($check["expected_headers"])) {
            if (!$this->validateHeaders($check["expected_headers"], $result["response_headers"])) {
                return false;
            }
        }
        
        // 3. Check Expected Body (if specified)
        if (!empty($check["expected_body"])) {
            if (!$this->validateBodyContent($check["expected_body"], $result["response_body"])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Validate expected headers against actual headers
     */
    private function validateHeaders(string $expectedHeaders, string $actualHeaders): bool {
        $expectedLines = array_filter(array_map('trim', explode("\n", $expectedHeaders)));
        
        foreach ($expectedLines as $expected) {
            // Check if it's a regex pattern
            if (preg_match('/^(.+?):\s*\/(.+)\/$/', $expected, $matches)) {
                $headerName = $matches[1];
                $pattern = $matches[2];
                
                // Extract the actual header value
                if (preg_match('/^' . preg_quote($headerName, '/') . ':\s*(.+)$/mi', $actualHeaders, $headerMatch)) {
                    if (!preg_match('/' . $pattern . '/', $headerMatch[1])) {
                        return false;
                    }
                } else {
                    return false; // Header not found
                }
            } else {
                // Simple substring check
                if (stripos($actualHeaders, $expected) === false) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Validate expected body content
     */
    private function validateBodyContent(string $expectedContent, string $actualBody): bool {
        // Decode HTML entities that might have been stored
        $expectedContent = html_entity_decode($expectedContent, ENT_QUOTES, 'UTF-8');
        
        // Check if it's a regex pattern (surrounded by /)
        if (preg_match('/^\/(.+)\/[a-z]*$/i', trim($expectedContent), $matches)) {
            return preg_match('/' . $matches[1] . '/', $actualBody) === 1;
        }
        
        // Otherwise, do a simple substring check
        return stripos($actualBody, $expectedContent) !== false;
    }

    /**
     * Determine if full response data should be kept
     */
    private function shouldKeepFullData(array $check, bool $isUp): bool {
        // Always keep full data for DOWN results (for debugging)
        if (!$isUp) {
            return true;
        }
        
        // Check if keep_response_data is enabled for this check
        return !empty($check["keep_response_data"]);
    }

    /**
     * Truncate text to specified length
     */
    private function truncateText(string $text, int $maxLength): string {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        
        return substr($text, 0, $maxLength - 20) . "\n\n[TRUNCATED...]";
    }

    /**
     * Generate comprehensive error message for DOWN checks
     */
    private function generateErrorMessage(array $check, array $result): string {
        $errors = [];
        
        // 1. cURL Error (connection issues, timeouts, etc.)
        if (!empty($result["error"])) {
            $errors[] = "Connection Error: " . $result["error"];
        }
        
        // 2. HTTP Status Error
        if ($result["http_status"] != $check["expected_status"]) {
            $expectedStatus = $check["expected_status"];
            $actualStatus = $result["http_status"];
            
            if ($actualStatus == 0) {
                $errors[] = "HTTP Error: No response received (connection failed)";
            } else {
                $statusText = $this->getHttpStatusText($actualStatus);
                $errors[] = "HTTP Status Error: Expected {$expectedStatus}, got {$actualStatus} ({$statusText})";
            }
        }
        
        // 3. Expected Headers Error
        if (!empty($check["expected_headers"])) {
            if (!$this->validateHeaders($check["expected_headers"], $result["response_headers"])) {
                $expectedHeaders = html_entity_decode($check["expected_headers"], ENT_QUOTES, 'UTF-8');
                $errors[] = "Header Validation Failed: Expected headers not found - " . str_replace("\n", ", ", $expectedHeaders);
            }
        }
        
        // 4. Expected Body Content Error
        if (!empty($check["expected_body"])) {
            if (!$this->validateBodyContent($check["expected_body"], $result["response_body"])) {
                $expectedBody = html_entity_decode($check["expected_body"], ENT_QUOTES, 'UTF-8');
                $errors[] = "Body Validation Failed: Expected content not found - '" . substr($expectedBody, 0, 100) . "'";
            }
        }
        
        // 5. If no specific errors found but check is down, create a generic error
        if (empty($errors)) {
            if ($result["http_status"] == 0) {
                $errors[] = "Connection failed: Unable to reach the server";
            } else {
                $errors[] = "Check failed: Unknown validation error occurred";
            }
        }
        
        return implode(" | ", $errors);
    }
    
    /**
     * Get human-readable HTTP status text
     */
    private function getHttpStatusText(int $statusCode): string {
        $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            301 => 'Moved Permanently',
            302 => 'Found',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout'
        ];
        
        return $statusTexts[$statusCode] ?? 'Unknown Status';
    }

    private function updateNextRunTime(int $checkId, int $intervalSeconds): void {
        $nextRunAt = date("Y-m-d H:i:s", time() + $intervalSeconds);
        $this->db->update("checks", [
            "next_run_at" => $nextRunAt
        ], "id = ?", [$checkId]);
    }

    private function updateCheckState(int $checkId, string $state): void {
        $this->db->update("checks", [
            "last_state" => $state
        ], "id = ?", [$checkId]);
    }

    private function handleStateChange(array $check, bool $isUp, int $resultId): void {
        $currentState = $isUp ? 'UP' : 'DOWN';
        $previousState = $check['last_state'];
        
        // Log state changes for debugging
        error_log("CheckRunner: Check {$check['id']} ({$check['name']}): State change from '{$previousState}' to '{$currentState}'");
        
        // Handle state transitions
        if ($currentState === 'DOWN') {
            if ($previousState === 'UP') {
                // State changed from UP to DOWN - open new incident (will send alert)
                error_log("CheckRunner: Check {$check['id']} transitioned from UP to DOWN - opening incident");
                $this->openIncident($check, $resultId);
            } elseif ($previousState === 'DOWN') {
                // Still DOWN - no new alert needed
                error_log("CheckRunner: Check {$check['id']} still DOWN - no new alert");
            } elseif ($previousState === null) {
                // First check and it's DOWN - open incident (will send alert)
                error_log("CheckRunner: Check {$check['id']} first check is DOWN - opening incident");
                $this->openIncident($check, $resultId);
            }
        } elseif ($currentState === 'UP') {
            if ($previousState === 'DOWN') {
                // State changed from DOWN to UP - close incident (will send recovery alert)
                error_log("CheckRunner: Check {$check['id']} recovered from DOWN to UP - closing incident");
                $this->closeIncident($check, $resultId);
            } elseif ($previousState === null) {
                // First check and it's UP - no action needed
                error_log("CheckRunner: Check {$check['id']} first check is UP - no action needed");
            }
        }
    }

    private function openIncident(array $check, int $resultId): void {
        try {
            // Check if there's already an open incident for this check
            $openIncident = $this->db->fetchOne(
                "SELECT id FROM incidents WHERE check_id = ? AND status = 'OPEN'",
                [$check['id']]
            );

            if (!$openIncident) {
                // Create new incident
                $incidentId = $this->db->insert("incidents", [
                    "check_id" => $check['id'],
                    "started_at" => date("Y-m-d H:i:s"),
                    "opened_by_result_id" => $resultId,
                    "status" => "OPEN"
                ]);

                error_log("CheckRunner: Created new incident ID {$incidentId} for check {$check['id']}");
                // Send DOWN alert for new incident
                $this->sendAlert($check, 'DOWN', $incidentId);
            } else {
                error_log("CheckRunner: Check {$check['id']} already has open incident ID {$openIncident['id']}");
            }
        } catch (Exception $e) {
            error_log("CheckRunner: Failed to create incident for check {$check['id']}: " . $e->getMessage());
        }
    }

    private function closeIncident(array $check, int $resultId): void {
        try {
            // Close all open incidents for this check
            $updated = $this->db->update("incidents", [
                "ended_at" => date("Y-m-d H:i:s"),
                "closed_by_result_id" => $resultId,
                "status" => "CLOSED"
            ], "check_id = ? AND status = 'OPEN'", [$check['id']]);

            if ($updated > 0) {
                error_log("CheckRunner: Closed {$updated} incident(s) for check {$check['id']}");
                // Send recovery alert when incidents closed
                $this->sendAlert($check, 'RECOVERY', $resultId);
            } else {
                error_log("CheckRunner: No open incidents found to close for check {$check['id']}");
            }
        } catch (Exception $e) {
            error_log("CheckRunner: Failed to close incident for check {$check['id']}: " . $e->getMessage());
        }
    }

    private function sendAlert(array $check, string $type, int $resultId): void {
        // Early return if no emailer configured
        if (!$this->emailer) {
            error_log("CheckRunner: No emailer configured - skipping alert for check {$check['id']}");
            return;
        }
        
        // Early return if no alert emails configured
        if (empty($check['alert_emails'])) {
            error_log("CheckRunner: No alert emails configured for check {$check['id']} ({$check['name']})");
            return;
        }

        // Parse and validate email addresses
        $emails = array_filter(array_map('trim', explode(',', $check['alert_emails'])));
        if (empty($emails)) {
            error_log("CheckRunner: No valid emails after parsing for check {$check['id']}");
            return;
        }
        
        error_log("CheckRunner: Preparing to send {$type} alert for check {$check['id']} ({$check['name']}) to " . count($emails) . " recipient(s): " . implode(', ', $emails));

        // Build email subject and body
        $subject = "[Uptime Monitor] {$type}: {$check['name']}";
        
        $body = "========================================\n";
        $body .= "UPTIME MONITOR ALERT\n";
        $body .= "========================================\n\n";
        $body .= "Check Name: {$check['name']}\n";
        $body .= "URL: {$check['url']}\n";
        $body .= "Status: {$type}\n";
        $body .= "Time: " . date('Y-m-d H:i:s T') . "\n";
        $body .= "========================================\n\n";
        
        if ($type === 'DOWN') {
            $body .= "⚠️ Your service appears to be DOWN.\n\n";
            $body .= "We detected that your monitored endpoint is not responding as expected.\n";
            $body .= "We'll continue monitoring and notify you when it's back online.\n\n";
            
            // Add check configuration details
            $body .= "Check Configuration:\n";
            $body .= "- Method: {$check['method']}\n";
            $body .= "- Expected Status: {$check['expected_status']}\n";
            $body .= "- Check Interval: " . ($check['interval_seconds'] / 60) . " minute(s)\n";
            
            // Add validation details if configured
            if (!empty($check['expected_body'])) {
                $body .= "- Expected Body: \"" . substr($check['expected_body'], 0, 50) . "...\"\n";
            }
            if (!empty($check['expected_headers'])) {
                $headers = str_replace("\n", ", ", substr($check['expected_headers'], 0, 50));
                $body .= "- Expected Headers: {$headers}...\n";
            }
        } else {
            $body .= "✅ Your service is back ONLINE!\n\n";
            $body .= "Good news! Your monitored endpoint is responding normally again.\n";
            $body .= "The service has recovered and is now operational.\n";
        }
        
        $body .= "\n========================================\n";
        $body .= "View Full Details:\n";
        $body .= "{$this->config['app']['base_url']}/check.php?id={$check['id']}\n";
        $body .= "========================================\n\n";
        $body .= "This is an automated message from Uptime Monitor.\n";
        $body .= "To modify alert settings, please login to your dashboard.\n";

        // Send to each email address
        $successCount = 0;
        $failureCount = 0;
        $failedEmails = [];
        
        foreach ($emails as $email) {
            try {
                error_log("CheckRunner: Attempting to send {$type} alert to {$email} for check {$check['id']}");
                
                $result = $this->emailer->send($email, $subject, $body);
                
                if ($result === true) {
                    $successCount++;
                    error_log("CheckRunner: ✅ Successfully sent {$type} alert to {$email} for check {$check['id']}");
                } else {
                    $failureCount++;
                    $failedEmails[] = $email;
                    error_log("CheckRunner: ❌ Failed to send {$type} alert to {$email} for check {$check['id']} - send() returned false");
                }
                
            } catch (Exception $e) {
                $failureCount++;
                $failedEmails[] = $email;
                error_log("CheckRunner: ❌ Exception sending alert to {$email}: " . $e->getMessage());
            }
        }
        
        // Log final summary
        if ($successCount > 0 && $failureCount == 0) {
            error_log("CheckRunner: ✅ Alert summary for check {$check['id']}: All {$successCount} email(s) sent successfully");
        } elseif ($successCount == 0 && $failureCount > 0) {
            error_log("CheckRunner: ❌ Alert summary for check {$check['id']}: All {$failureCount} email(s) failed to send");
        } else {
            error_log("CheckRunner: ⚠️ Alert summary for check {$check['id']}: {$successCount} sent, {$failureCount} failed");
        }
        
        if (!empty($failedEmails)) {
            error_log("CheckRunner: Failed recipients: " . implode(', ', $failedEmails));
        }
    }
}
?>