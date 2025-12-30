<?php
/**
 * Main Configuration & Bootstrap File
 * Works on ANY URL automatically
 * Save as: config.php or bootstrap.php
 */

defined('APP_ACCESS') or die('Direct access not permitted');

// ====================================================================
// 1. AUTO-DETECT BASE URL (MAGIC PART - WORKS EVERYWHERE)
// ====================================================================

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
            $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';

$host = $_SERVER['HTTP_HOST']; // e.g., localhost:8000, erp.company.com

// Get the folder path (e.g., /erp-app, /myproject, or empty if in root)
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptPath === '/' || $scriptPath === '\\') {
    $scriptPath = '';
}

// Final dynamic APP_URL - THIS IS THE KEY
define('APP_URL', rtrim($protocol . $host . $scriptPath, '/'));

// Physical path to project root (one level up from this file)
define('BASE_PATH', dirname(__DIR__));

// ====================================================================
// 2. DATABASE CONFIGURATION
// ====================================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'erp_system_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ====================================================================
// 3. APPLICATION SETTINGS
// ====================================================================

define('APP_NAME', 'ERP System');
define('APP_VERSION', '2.0.0');

define('UPLOAD_PATH', BASE_PATH . '/uploads/');
define('ASSETS_PATH', BASE_PATH . '/assets/');

// Session
define('SESSION_LIFETIME', 1800);        // 30 minutes
define('SESSION_NAME', 'ERP_SESSION');

// Security
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);       // 15 minutes
define('PASSWORD_MIN_LENGTH', 8);
define('CSRF_TOKEN_NAME', 'csrf_token');

// Timezone
date_default_timezone_set('Africa/Dar_es_Salaam');

// Error reporting - CHANGE TO 0 IN PRODUCTION!
error_reporting(E_ALL);
ini_set('display_errors', 1);  // Set to 0 on live server



// ====================================================================
// 4. DATABASE CLASS - Multi-tenant ready + Auto company isolation
// ====================================================================

class Database
{
    private static ?Database $instance = null;
    private PDO $conn;
    private ?int $company_id = null;

    private function __construct()
    {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,
        ];

        try {
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed: " . htmlspecialchars($e->getMessage()));
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }

    public function setCompanyId(?int $company_id): void
    {
        $this->company_id = $company_id;
    }

    public function getCompanyId(): ?int
    {
        return $this->company_id;
    }

    /** Raw query execution */
    public function query(string $sql, array $params = []): PDOStatement|false
    {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("DB Error: " . $e->getMessage() . " | SQL: $sql");
            return false;
        }
    }

    /** SELECT with automatic company_id filtering */
    public function select(string $table, array $where = [], $columns = '*'): array
    {
        if ($this->company_id !== null && !isset($where['company_id'])) {
            $where['company_id'] = $this->company_id;
        }

        $cols = is_array($columns) ? implode(', ', $columns) : $columns;
        $sql = "SELECT {$cols} FROM {$table}";

        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $val) {
                $conditions[] = "$key = :$key";
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $stmt = $this->query($sql, $where);
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** INSERT with automatic company_id */
    public function insert(string $table, array $data)
    {
        if ($this->company_id !== null) {
            $data['company_id'] = $this->company_id;
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->query($sql, $data);

        return $stmt ? $this->conn->lastInsertId() : false;
    }

    /** UPDATE with automatic company_id in WHERE */
    public function update(string $table, array $data, array $where): bool
    {
        if ($this->company_id !== null) {
            $where['company_id'] = $this->company_id;
        }

        $setParts = [];
        foreach ($data as $key => $val) {
            $setParts[] = "$key = :set_$key";
            $params["set_$key"] = $val;
        }

        $whereParts = [];
        foreach ($where as $key => $val) {
            $whereParts[] = "$key = :where_$key";
            $params["where_$key"] = $val;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $setParts) . 
               " WHERE " . implode(' AND ', $whereParts);

        $stmt = $this->query($sql, $params);
        return $stmt !== false;
    }

    /** DELETE with automatic company_id */
    public function delete(string $table, array $where): bool
    {
        if ($this->company_id !== null) {
            $where['company_id'] = $this->company_id;
        }

        $conditions = [];
        foreach ($where as $key => $val) {
            $conditions[] = "$key = :$key";
        }

        $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $conditions);
        $stmt = $this->query($sql, $where);
        return $stmt !== false;
    }
}

// Optional: Auto-include Auth class
// require_once __DIR__ . '/Auth.php';