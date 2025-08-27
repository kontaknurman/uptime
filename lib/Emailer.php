<?php

class Emailer {
    private $config;
    
    public function __construct(array $config) {
        $this->config = $config['smtp'];
    }

    public function send(string $to, string $subject, string $body): bool {
        // Validate email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Simple SMTP implementation
        try {
            $socket = $this->connectToSMTP();
            if (!$socket) {
                return false;
            }

            $this->sendSMTPCommand($socket, "EHLO localhost");
            
            if ($this->config['encryption'] === 'tls') {
                $this->sendSMTPCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->sendSMTPCommand($socket, "EHLO localhost");
            }

            if (!empty($this->config['username'])) {
                $this->sendSMTPCommand($socket, "AUTH LOGIN");
                $this->sendSMTPCommand($socket, base64_encode($this->config['username']));
                $this->sendSMTPCommand($socket, base64_encode($this->config['password']));
            }

            $this->sendSMTPCommand($socket, "MAIL FROM: <{$this->config['from_email']}>");
            $this->sendSMTPCommand($socket, "RCPT TO: <{$to}>");
            $this->sendSMTPCommand($socket, "DATA");

            // Email headers and body
            $email = $this->buildEmailMessage($to, $subject, $body);
            fwrite($socket, $email . "\r\n.\r\n");
            $response = fgets($socket);

            $this->sendSMTPCommand($socket, "QUIT");
            fclose($socket);

            return strpos($response, '250') === 0;
            
        } catch (Exception $e) {
            error_log('Email send failed: ' . $e->getMessage());
            return false;
        }
    }

    private function connectToSMTP() {
        $context = stream_context_create();
        
        if ($this->config['encryption'] === 'ssl') {
            $host = 'ssl://' . $this->config['host'];
        } else {
            $host = $this->config['host'];
        }

        $socket = @stream_socket_client(
            "{$host}:{$this->config['port']}", 
            $errno, 
            $errstr, 
            30, 
            STREAM_CLIENT_CONNECT, 
            $context
        );

        if (!$socket) {
            error_log("SMTP connection failed: {$errstr} ({$errno})");
            return false;
        }

        // Read greeting
        $response = fgets($socket);
        if (strpos($response, '220') !== 0) {
            fclose($socket);
            return false;
        }

        return $socket;
    }

    private function sendSMTPCommand($socket, string $command): string {
        fwrite($socket, $command . "\r\n");
        $response = fgets($socket);
        
        // Check for success response codes
        $successCodes = ['220', '221', '235', '250', '354'];
        $responseCode = substr($response, 0, 3);
        
        if (!in_array($responseCode, $successCodes)) {
            throw new Exception("SMTP command failed: {$command}. Response: {$response}");
        }
        
        return $response;
    }

    private function buildEmailMessage(string $to, string $subject, string $body): string {
        $boundary = md5(uniqid());
        $headers = [];
        
        $headers[] = "From: {$this->config['from_name']} <{$this->config['from_email']}>";
        $headers[] = "To: {$to}";
        $headers[] = "Subject: {$subject}";
        $headers[] = "Date: " . date('r');
        $headers[] = "Message-ID: <" . md5(uniqid()) . "@" . $_SERVER['HTTP_HOST'] . ">";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: 8bit";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
}