<?php
define('APP_ACCESS', true);
session_start();

require_once __DIR__ . '/config/database.php';

// Store user info before clearing session
$user_id = $_SESSION['user_id'] ?? null;
$company_id = $_SESSION['company_id'] ?? null;
$session_id = session_id();

// Log the logout action if user is logged in
if ($user_id && $company_id) {
    try {
        $db = Database::getInstance();
        $db->setCompanyId($company_id);
        $conn = $db->getConnection();
        
        // Calculate session duration
        $session_start = $_SESSION['login_time'] ?? null;
        $session_duration = null;
        if ($session_start) {
            $session_duration = time() - $session_start;
        }
        
        // Log the logout activity (if activity_logs table exists)
        try {
            $log_stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    company_id, user_id, action, module, 
                    description, ip_address, user_agent, created_at
                ) VALUES (?, ?, 'logout', 'authentication', ?, ?, ?, NOW())
            ");
            
            $description = "User logged out successfully";
            if ($session_duration) {
                $hours = floor($session_duration / 3600);
                $minutes = floor(($session_duration % 3600) / 60);
                $description .= " (Session duration: {$hours}h {$minutes}m)";
            }
            
            $log_stmt->execute([
                $company_id,
                $user_id,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
        } catch (PDOException $e) {
            // Ignore if activity_logs table doesn't exist
        }
        
        // Update user's last activity
        $update_stmt = $conn->prepare("
            UPDATE users 
            SET last_activity = NOW()
            WHERE user_id = ? AND company_id = ?
        ");
        $update_stmt->execute([$user_id, $company_id]);
        
        // Clear login attempts
        $clear_stmt = $conn->prepare("
            DELETE FROM login_attempts 
            WHERE username = (SELECT username FROM users WHERE user_id = ?)
        ");
        $clear_stmt->execute([$user_id]);
        
    } catch (PDOException $e) {
        // Silent fail - don't prevent logout if logging fails
        error_log("Logout logging error: " . $e->getMessage());
    }
}

// Store logout message before destroying session
$logout_message = "You have been successfully logged out.";
$was_logged_in = isset($_SESSION['user_id']);

// Unset all session variables
$_SESSION = array();

// Delete the session cookie
if (isset($_COOKIE[session_name()])) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Also clear any remember me cookies if they exist
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

if (isset($_COOKIE['remember_user'])) {
    setcookie('remember_user', '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Start a new session to show logout message
if ($was_logged_in) {
    session_start();
    $_SESSION['logout_message'] = $logout_message;
    $_SESSION['logout_success'] = true;
}

// Clear browser cache headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Redirect to login page - use relative path
header('Location: login.php');
exit;
?>