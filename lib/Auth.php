<?php

class Auth {
    private $db;
    private $config;
    
    public function __construct(Database $db, array $config) {
        $this->db = $db;
        $this->config = $config;
    }

    public function login(string $email, string $password): bool {
        $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            return false;
        }

        $user = $this->db->fetchOne(
            'SELECT id, email, password_hash FROM users WHERE email = ?',
            [$email]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['csrf_token'] = $this->generateCsrfToken();
        $_SESSION['login_time'] = time();

        return true;
    }

    public function logout(): void {
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();
    }

    public function isLoggedIn(): bool {
        if (!isset($_SESSION['user_id'], $_SESSION['login_time'])) {
            return false;
        }

        // Check session timeout
        if (time() - $_SESSION['login_time'] > $this->config['app']['session_lifetime']) {
            $this->logout();
            return false;
        }

        return true;
    }

    public function requireAuth(): void {
        if (!$this->isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }

    public function generateCsrfToken(): string {
        return bin2hex(random_bytes(32));
    }

    public function validateCsrfToken(string $token): bool {
        return isset($_SESSION['csrf_token']) && 
               hash_equals($_SESSION['csrf_token'], $token);
    }

    public function getCsrfToken(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $this->generateCsrfToken();
        }
        return $_SESSION['csrf_token'];
    }

    public function getCurrentUser(): ?array {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT id, email FROM users WHERE id = ?',
            [$_SESSION['user_id']]
        );
    }

    public function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => $this->config['security']['bcrypt_cost']
        ]);
    }

    public static function sanitizeInput(string $input): string {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    public static function validateEmail(string $email): ?string {
        $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
        return $email ?: null;
    }

    public static function validateUrl(string $url): ?string {
        $url = filter_var(trim($url), FILTER_VALIDATE_URL);
        if ($url && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
            return $url;
        }
        return null;
    }
}