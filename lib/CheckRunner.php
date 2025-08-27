<?php

class CheckRunner {
    private $db;
    private $config;
    private $emailer;
    private $maxParallel = 10; // Process 10 checks simultaneously
    
    public function __construct(Database $db, array $config, ?Emailer $emailer = null) {
        $this->db = $db;
        $this->config = $config;
        $this->emailer = $emailer;
        
        // Allow configuration of parallel connections
        if (isset($config['monitoring']['max_parallel_checks'])) {
            $this->maxParallel = min($config['monitoring']['max_parallel_checks'], 50);
        }
    }

    public function runDueChecks(): int {
        // Debug: Check current time and timezone
        error_log("CheckRunner: Current time = " . date('Y-m-d H:i:s') . " (timestamp: " . time() . ")");
        
        $checks = $this->db->fetchAll(
            "SELECT * FROM checks WHERE enabled = 1 AND next_run_at <= NOW() ORDER BY next_run_at ASC"
        );
        
        error_log("CheckRunner: Found " . count($checks) . " checks with normal query");
        
        // If no checks found, let's debug and force-run overdue checks
        if (empty($checks)) {
            // Get all enabled checks to see what's wrong
            $allChecks = $this->db->fetchAll('SELECT id, name, next_run_at, enabled FROM checks WHERE enabled = 1');
            error_log("CheckRunner: Total enabled checks = " . count($allChecks));
            
            $now = time();
            $forceDue = [];
            
            foreach ($allChecks as $check) {
                $nextTime = strtotime($check['next_run_at']);
                $overdue = $now - $nextTime;
                error_log("CheckRunner: Check {$check['id']} '{$check['name']}' - next_run_at: {$check['next_run_at']} (timestamp: {$nextTime}), overdue by: {$overdue}s");
                
                if ($overdue > 0) {
                    $forceDue[] = $check['id'];
                    error_log("CheckRunner: Adding check {$check['id']} to force-run list");
                }
            }
            
            // Force run overdue checks
            if (!empty($forceDue)) {
                error_log("CheckRunner: Force-running " . count($forceDue) . " overdue checks");
                $placeholders = str_repeat('?,', count($forceDue) - 1) . '?';
                $checks = $this->db->fetchAll(
                    "SELECT * FROM checks WHERE id IN ({$placeholders}) ORDER BY next_run_at ASC",
                    $forceDue
                );
                error_log("CheckRunner: Force-selected " . count($checks) . " checks");
            }
        }

        if (empty($checks)) {
            error_log("CheckRunner: No checks to execute after all attempts");
            return 0;
        }

        // Process checks in batches for parallel execution
        $executed = 0;
        $batches = array_chunk($checks, $this->maxParallel);
        
        foreach ($batches as $batch) {
            $batchResults = $this->executeChecksBatch($batch);
            $executed += count(array_filter($batchResults, fn($r) => $r === true));
        }

        error_log("CheckRunner: Completed execution of {$executed} checks");
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
                // Wait for activity
                curl_multi_select($multiHandle, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
        
        // Process results
        $results = [];
        foreach ($curlHandles as $ch) {
            $check = $checkMap[(int)$ch];
            
            try {
                // Get the result from curl handle
                $httpResult = $this->processCurlResult($ch, $check);
                
                // Store result and update check
                $this->storeCheckResult($check, $httpResult);
                $results[$check['id']] = true;
                
                error_log("CheckRunner: Successfully executed check {$check['id']}");
            } catch (Exception $e) {
                error_log("CheckRunner: Check {$check['id']} failed: " . $e->getMessage());
                $results[$check['id']] = false;
                
                // Still update next_run_at even if check failed
                $nextRunAt = date("Y-m-d H:i:s", time() + $check["interval_seconds"]);
                $this->db->update("checks", [
                    "next_run_at" => $nextRunAt
                ], "id = ?", [$check["id"]]);
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
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
            CURLOPT_CUSTOMREQUEST => $check["method"],
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
        ]);
        
        // Add custom headers if specified
        if (!empty($check['request_headers'])) {
            $headers = array_filter(array_map('trim', explode("\n", $check['request_headers'])));
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
        }
        
        // Add request body for POST
        if ($check['method'] === 'POST' && !empty($check['request_body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $check['request_body']);
        }
        
        return $ch;
    }

    private function processCurlResult($ch, array $check): array {
        $body = curl_multi_getcontent($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $error = curl_error($ch);
        
        return [
            "http_status" => $httpStatus ?: 0,
            "response_headers" => "{}",
            "body_sample" => $body !== false ? substr($body, 0, 1000) : "",
            "error" => $error ?: null,
            "duration_ms" => (int)($totalTime * 1000)
        ];
    }

    private function storeCheckResult(array $check, array $result): void {
        $startedAt = date("Y-m-d H:i:s");
        $endedAt = date("Y-m-d H:i:s");
        
        error_log("Check ID {$check['id']} completed: HTTP {$result['http_status']}, {$result['duration_ms']}ms");
        
        // Evaluate if check is UP or DOWN
        $isUp = ($result["http_status"] == $check["expected_status"]);
        
        // Store result
        $resultId = $this->db->insert("check_results", [
            "check_id" => $check["id"],
            "started_at" => $startedAt,
            "ended_at" => $endedAt,
            "duration_ms" => $result["duration_ms"],
            "http_status" => $result["http_status"],
            "response_headers" => $result["response_headers"],
            "body_sample" => $result["body_sample"],
            "is_up" => $isUp ? 1 : 0,
            "error_message" => $result["error"] ?? null
        ]);
        
        error_log("Check ID {$check['id']} result stored with ID {$resultId}, status: " . ($isUp ? "UP" : "DOWN"));
        
        // Update check next run time and last state
        $nextRunAt = date("Y-m-d H:i:s", time() + $check["interval_seconds"]);
        $updateResult = $this->db->update("checks", [
            "next_run_at" => $nextRunAt,
            "last_state" => $isUp ? "UP" : "DOWN"
        ], "id = ?", [$check["id"]]);
        
        error_log("Check ID {$check['id']} updated: next_run_at = {$nextRunAt}, affected rows: {$updateResult}");
    }

    // Keep the old method for backward compatibility or single check execution
    private function executeCheck(array $check): void {
        $startTime = microtime(true);
        $startedAt = date("Y-m-d H:i:s");

        error_log("Executing check ID {$check['id']}: {$check['name']} ({$check['url']})");

        // Execute HTTP request
        $result = $this->performHttpCheck($check);
        
        $endTime = microtime(true);
        $endedAt = date("Y-m-d H:i:s");
        $durationMs = (int)(($endTime - $startTime) * 1000);

        error_log("Check ID {$check['id']} completed: HTTP {$result['http_status']}, {$durationMs}ms");

        // Evaluate if check is UP or DOWN
        $isUp = ($result["http_status"] == $check["expected_status"]);

        // Store result
        $resultId = $this->db->insert("check_results", [
            "check_id" => $check["id"],
            "started_at" => $startedAt,
            "ended_at" => $endedAt,
            "duration_ms" => $durationMs,
            "http_status" => $result["http_status"],
            "response_headers" => $result["response_headers"],
            "body_sample" => $result["body_sample"],
            "is_up" => $isUp ? 1 : 0,
            "error_message" => $result["error"] ?? null
        ]);

        error_log("Check ID {$check['id']} result stored with ID {$resultId}, status: " . ($isUp ? "UP" : "DOWN"));

        // Update check next run time and last state
        $nextRunAt = date("Y-m-d H:i:s", time() + $check["interval_seconds"]);
        $updateResult = $this->db->update("checks", [
            "next_run_at" => $nextRunAt,
            "last_state" => $isUp ? "UP" : "DOWN"
        ], "id = ?", [$check["id"]]);

        error_log("Check ID {$check['id']} updated: next_run_at = {$nextRunAt}, affected rows: {$updateResult}");
    }

    private function performHttpCheck(array $check): array {
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
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
            CURLOPT_CUSTOMREQUEST => $check["method"]
        ]);

        $body = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            "http_status" => $httpStatus ?: 0,
            "response_headers" => "{}",
            "body_sample" => $body !== false ? substr($body, 0, 1000) : "",
            "error" => $error ?: null
        ];
    }
}