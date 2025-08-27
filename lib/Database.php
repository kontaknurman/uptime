<?php

class Database {
    private static $instance = null;
    private $pdo;
    private $config;

    private function __construct($config) {
        $this->config = $config['database'];
        $this->connect();
    }

    public static function getInstance($config = null): self {
        if (self::$instance === null) {
            if (!$config) {
                throw new Exception('Database config required for first initialization');
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    private function connect(): void {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['name'],
            $this->config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'
        ];

        try {
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], $options);
            
            // CRITICAL FIX: Synchronize MySQL timezone with PHP timezone
            $phpTimezone = date_default_timezone_get();
            $mysqlTimezone = $this->convertPhpTimezoneToMysql($phpTimezone);
            
            // Set MySQL session timezone to match PHP
            $this->pdo->exec("SET time_zone = '{$mysqlTimezone}'");
            
            error_log("Database connected with timezone sync: PHP={$phpTimezone}, MySQL={$mysqlTimezone}");
            
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }

    /**
     * Convert PHP timezone to MySQL timezone format
     */
    private function convertPhpTimezoneToMysql(string $phpTimezone): string {
        try {
            // Get current offset for the timezone
            $datetime = new DateTime('now', new DateTimeZone($phpTimezone));
            $offset = $datetime->format('P'); // Returns format like +07:00
            return $offset;
        } catch (Exception $e) {
            // Fallback to UTC if timezone conversion fails
            error_log("Timezone conversion failed for {$phpTimezone}, using UTC");
            return '+00:00';
        }
    }

    public function query(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $errorMsg = 'Query failed: ' . $e->getMessage() . ' | SQL: ' . $sql . ' | Params: ' . json_encode($params);
            error_log($errorMsg);
            
            // More specific error messages
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                throw new Exception('Database table does not exist. Please run the database setup.');
            } elseif (strpos($e->getMessage(), 'Unknown column') !== false) {
                throw new Exception('Database schema is outdated. Please update your database structure.');
            } else {
                throw new Exception('Database query failed: ' . $e->getMessage());
            }
        }
    }

    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    public function fetchColumn(string $sql, array $params = []) {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $setClause = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        $params = array_merge(array_values($data), $whereParams);
        
        return $this->query($sql, $params)->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return $this->query($sql, $params)->rowCount();
    }

    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool {
        return $this->pdo->commit();
    }

    public function rollback(): bool {
        return $this->pdo->rollback();
    }

    /**
     * Debug method to check current timezones
     */
    public function getTimezoneInfo(): array {
        $phpTimezone = date_default_timezone_get();
        $phpTime = date('Y-m-d H:i:s');
        
        $mysqlInfo = $this->fetchOne("SELECT NOW() as mysql_time, @@session.time_zone as mysql_timezone");
        
        return [
            'php_timezone' => $phpTimezone,
            'php_time' => $phpTime,
            'mysql_timezone' => $mysqlInfo['mysql_timezone'],
            'mysql_time' => $mysqlInfo['mysql_time']
        ];
    }
}