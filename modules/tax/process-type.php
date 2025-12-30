<?php
define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    header('Location: add-type.php');
    exit;
}

try {
    // Get input data
    $tax_name = trim($_POST['tax_name'] ?? '');
    $tax_code = strtoupper(trim($_POST['tax_code'] ?? '')); // Uppercase for consistency
    $tax_rate = (float)($_POST['tax_rate'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $company_id = $_SESSION['company_id'];
    $created_by = $_SESSION['user_id'] ?? 1;

    // Validation
    $errors = [];
    if (empty($tax_name)) $errors[] = 'Tax name is required';
    if (empty($tax_code)) $errors[] = 'Tax code is required';
    if (!preg_match('/^[A-Z0-9\-_]+$/', $tax_code)) $errors[] = 'Tax code can only contain letters, numbers, hyphens, and underscores';
    if ($tax_rate < 0 || $tax_rate > 100) $errors[] = 'Tax rate must be between 0 and 100%';
    if (strlen($tax_code) > 20) $errors[] = 'Tax code cannot exceed 20 characters';

    if (!empty($errors)) {
        $_SESSION['error_message'] = implode('<br>', $errors);
        header('Location: add-type.php');
        exit;
    }

    // Check for duplicate tax code
    $check_stmt = $conn->prepare("SELECT tax_type_id FROM tax_types WHERE tax_code = ? AND company_id = ?");
    $check_stmt->execute([$tax_code, $company_id]);
    
    if ($check_stmt->fetch()) {
        $_SESSION['error_message'] = "Tax code '$tax_code' already exists";
        header('Location: add-type.php');
        exit;
    }

    // Insert tax type - SAFE for any table structure
    $query = "
        INSERT INTO tax_types (
            company_id, 
            tax_name, 
            tax_code, 
            description, 
            tax_rate, 
            is_active, 
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($query);
    $success = $stmt->execute([
        $company_id,
        $tax_name,
        $tax_code,
        $description ?: null,
        $tax_rate,
        $is_active,
        $created_by
    ]);

    if ($success) {
        $_SESSION['success_message'] = "✅ Tax type '$tax_name' ($tax_code) created successfully!";
        header('Location: types.php');
        exit;
    } else {
        throw new Exception('Failed to create tax type');
    }

} catch (PDOException $e) {
    error_log("Tax type creation error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Database error: ' . $e->getMessage();
    header('Location: add-type.php');
    exit;
} catch (Exception $e) {
    error_log("Tax type creation error: " . $e->getMessage());
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: add-type.php');
    exit;
}
?>