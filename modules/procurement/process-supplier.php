<?php
define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();  // ✅ CORRECT: $conn is PDO object
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    header('Location: suppliers.php');
    exit;
}

try {
    $conn->beginTransaction();

    // Validate required fields
    $required_fields = ['supplier_name', 'contact_person', 'phone', 'email', 'city', 'country'];
    $errors = [];
    
    foreach ($required_fields as $field) {
        if (empty(trim($_POST[$field] ?? ''))) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }

    if (!filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email address is required';
    }

    if (!empty($errors)) {
        throw new Exception(implode(', ', $errors));
    }

    // Check for duplicate supplier name
    $check_query = "SELECT supplier_id FROM suppliers WHERE company_id = ? AND LOWER(supplier_name) = LOWER(?) AND is_active = 1";
    $stmt = $conn->prepare($check_query);
    $stmt->execute([$company_id, trim($_POST['supplier_name'])]);
    
    if ($stmt->fetch()) {
        throw new Exception('A supplier with this name already exists');
    }

    // Generate supplier code if empty
    $supplier_code = !empty(trim($_POST['supplier_code'] ?? '')) ? trim($_POST['supplier_code']) : generateSupplierCode($_POST['supplier_name']);

    // Get available columns dynamically
    $available_columns = [];
    $result = $conn->query("SHOW COLUMNS FROM suppliers");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $available_columns[] = $row['Field'];
    }

    // Build INSERT columns and values
    $columns = ['company_id'];
    $values = [$company_id];
    
    // Required fields
    $required_fields_map = [
        'supplier_name' => trim($_POST['supplier_name']),
        'contact_person' => trim($_POST['contact_person']),
        'phone' => trim($_POST['phone']),
        'email' => trim($_POST['email']),
        'city' => trim($_POST['city']),
        'country' => trim($_POST['country'])
    ];

    foreach ($required_fields_map as $column => $value) {
        if (in_array($column, $available_columns)) {
            $columns[] = $column;
            $values[] = $value;
        }
    }

    // Optional fields with safe defaults
    $optional_fields = [
        'supplier_code' => $supplier_code,
        'supplier_type' => $_POST['supplier_type'] ?? 'other',
        'category' => trim($_POST['category'] ?? ''),
        'tin_number' => trim($_POST['tin_number'] ?? ''),
        'contact_title' => trim($_POST['contact_title'] ?? ''),
        'alternative_phone' => trim($_POST['alternative_phone'] ?? ''),
        'website' => trim($_POST['website'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'region' => trim($_POST['region'] ?? ''),
        'bank_name' => trim($_POST['bank_name'] ?? ''),
        'bank_account' => trim($_POST['bank_account'] ?? ''),
        'account_name' => trim($_POST['account_name'] ?? ''),
        'swift_code' => trim($_POST['swift_code'] ?? ''),
        'payment_terms' => $_POST['payment_terms'] ?? 'net_30',
        'credit_limit' => !empty($_POST['credit_limit']) ? (float)$_POST['credit_limit'] : 0.00,
        'lead_time_days' => !empty($_POST['lead_time_days']) ? (int)$_POST['lead_time_days'] : 0,
        'rating' => !empty($_POST['rating']) ? (int)$_POST['rating'] : 3,
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0,
        'created_by' => $user_id
    ];

    foreach ($optional_fields as $column => $value) {
        if (in_array($column, $available_columns)) {
            $columns[] = $column;
            $values[] = $value;
        }
    }

    // Build and execute INSERT query
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $insert_query = "INSERT INTO suppliers (`" . implode('`, `', $columns) . "`) VALUES ($placeholders)";
    
    // Log for debugging (remove in production)
    error_log("Supplier INSERT Query: " . $insert_query);
    error_log("Values count: " . count($values));

    $stmt = $conn->prepare($insert_query);
    $success = $stmt->execute($values);

    if (!$success) {
        throw new Exception('Failed to execute database query');
    }

    $supplier_id = $conn->lastInsertId();
    $conn->commit();

    $_SESSION['success_message'] = "✅ Supplier '{$_POST['supplier_name']}' added successfully! (ID: {$supplier_id})";
    header('Location: suppliers.php');
    exit;

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("🚨 PDO Error adding supplier: " . $e->getMessage());
    error_log("SQL Query: " . ($insert_query ?? 'N/A'));
    error_log("Values: " . print_r($values ?? [], true));
    
    $_SESSION['error_message'] = "Database Error: " . $e->getMessage();
    header('Location: add-supplier.php');
    exit;
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("🚨 Error adding supplier: " . $e->getMessage());
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    header('Location: add-supplier.php');
    exit;
}

function generateSupplierCode($name) {
    $words = explode(' ', trim($name));
    $code = '';
    foreach ($words as $word) {
        if (strlen(trim($word)) > 0) {
            $code .= strtoupper(substr(trim($word), 0, 3));
        }
    }
    $code = substr($code, 0, 8);
    return $code . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
}
?>