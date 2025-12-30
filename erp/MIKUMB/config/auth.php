<?php
/**
 * Authentication Class (Plain Text Passwords)
 * NO password hashing - for testing/demo only!
 */

defined('APP_ACCESS') or die('Direct access not permitted');

class Auth
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * User Login - Plain text password comparison
     */
    public function login(string $username, string $password): array
    {
        // Block if too many failed attempts
        if (!$this->checkLoginAttempts($username)) {
            return [
                'success' => false,
                'message' => 'Too many failed attempts. Please try again in 15 minutes.'
            ];
        }

        // Fetch user with company info
        $sql = "SELECT u.*, c.company_name, c.company_code 
                FROM users u 
                INNER JOIN companies c ON u.company_id = c.company_id 
                WHERE u.username = :username 
                  AND u.is_active = 1 
                LIMIT 1";

        $stmt = $this->db->query($sql, ['username' => $username]);
        $user = $stmt ? $stmt->fetch() : null;

        // Compare password in PLAIN TEXT
        if (!$user || $password !== $user['password_hash']) {  // ← plain text field
            $this->recordLoginAttempt($username, false);
            return [
                'success' => false,
                'message' => 'Invalid username or password'
            ];
        }

        // Success → clear failed attempts
        $this->clearLoginAttempts($username);
        $this->recordLoginAttempt($username, true);

        // Regenerate session ID
        session_regenerate_id(true);

        // Store user data in session
        $_SESSION['user_id']       = $user['user_id'];
        $_SESSION['username']       = $user['username'];
        $_SESSION['full_name']     = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['email']         = $user['email'];
        $_SESSION['company_id']    = $user['company_id'];
        $_SESSION['company_name']  = $user['company_name'];
        $_SESSION['company_code']  = $user['company_code'];
        $_SESSION['is_admin']      = ($user['is_admin'] == 1);
        $_SESSION['logged_in']     = true;
        $_SESSION['last_activity'] = time();

        // Set company context
        $this->db->setCompanyId($user['company_id']);

        // Load permissions
        $_SESSION['permissions'] = $this->getUserPermissions($user['user_id']);

        return [
            'success' => true,
            'message' => 'Login successful',
            'user'    => [
                'username'     => $user['username'],
                'full_name'    => $_SESSION['full_name'],
                'company_name' => $user['company_name']
            ]
        ];
    }

    /**
     * User Logout
     */
    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn(): bool
    {
        if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }

        if (isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
            $this->logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * Force login
     */
   public function requireLogin(): void
{
    if (!$this->isLoggedIn()) {
        header('Location: /MIKUMB/login.php?timeout=1');
        exit;
    }
}


    /**
     * Check permission
     */
    public function hasPermission(string $permission): bool
    {
        if (empty($_SESSION['permissions'])) return false;
        if (!empty($_SESSION['is_admin'])) return true;
        return in_array($permission, $_SESSION['permissions']);
    }

    // ─────────────────────────────────────────────────────────────
    // Rest of methods stay exactly the same (permissions, attempts, etc.)
    // ─────────────────────────────────────────────────────────────

    private function getUserPermissions(int $user_id): array
    {
        $sql = "SELECT DISTINCT p.permission_code 
                FROM user_roles ur
                INNER JOIN role_permissions rp ON ur.role_id = rp.role_id
                INNER JOIN permissions p ON rp.permission_id = p.permission_id
                WHERE ur.user_id = :user_id";

        $stmt = $this->db->query($sql, ['user_id' => $user_id]);
        $permissions = [];

        if ($stmt) {
            while ($row = $stmt->fetch()) {
                $permissions[] = $row['permission_code'];
            }
        }
        return $permissions;
    }

    private function checkLoginAttempts(string $username): bool
    {
        $sql = "SELECT COUNT(*) as attempts 
                FROM login_attempts 
                WHERE username = :username AND success = 0 
                  AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";

        $stmt = $this->db->query($sql, ['username' => $username]);
        $row = $stmt ? $stmt->fetch() : null;

        return !$row || $row['attempts'] < LOGIN_MAX_ATTEMPTS;
    }

    private function recordLoginAttempt(string $username, bool $success): void
    {
        $this->db->query(
            "INSERT INTO login_attempts (username, ip_address, user_agent, success, attempt_time) 
             VALUES (:username, :ip, :agent, :success, NOW())",
            [
                'username' => $username,
                'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                'agent'    => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'success'  => $success ? 1 : 0
            ]
        );
    }

    private function clearLoginAttempts(string $username): void
    {
        $this->db->query("DELETE FROM login_attempts WHERE username = :username", [
            'username' => $username
        ]);
    }
}