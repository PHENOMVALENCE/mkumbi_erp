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
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: types.php');
    exit;
}

try {
    $conn->beginTransaction();

    // Validate required fields
    if (empty($_POST['service_name']) || empty($_POST['service_category'])) {
        throw new Exception('Please fill in all required fields.');
    }

    // Check for duplicate service name
    $check_query = "SELECT service_type_id FROM service_types WHERE company_id = ? AND service_name = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->execute([$company_id, $_POST['service_name']]);
    if ($stmt->fetch()) {
        throw new Exception('A service type with this name already exists.');
    }

    // Generate service code if empty
    $service_code = $_POST['service_code'] ?? '';
    if (empty($service_code)) {
        $words = explode(' ', $_POST['service_name']);
        $code = '';
        for ($i = 0; $i < min(count($words), 3); $i++) {
            $word = trim($words[$i]);
            if ($word) {
                $code .= substr($word, 0, 3);
            }
        }
        $random_num = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
        $service_code = strtoupper(substr($code, 0, 10) . $random_num);
    }

    // Insert service type
    $insert_service = "
        INSERT INTO service_types (
            company_id,
            service_code,
            service_name,
            service_category,
            description,
            base_price,
            price_unit,
            estimated_duration_days,
            is_active,
            created_at,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ";

    $stmt = $conn->prepare($insert_service);
    $stmt->execute([
        $company_id,
        $service_code,
        $_POST['service_name'],
        $_POST['service_category'],
        $_POST['description'] ?? null,
        !empty($_POST['base_price']) ? floatval($_POST['base_price']) : null,
        $_POST['price_unit'] ?? null,
        !empty($_POST['estimated_duration_days']) ? intval($_POST['estimated_duration_days']) : null,
        isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0,
        $user_id
    ]);

    $service_type_id = $conn->lastInsertId();

    $conn->commit();

    $_SESSION['success_message'] = "Service type '{$_POST['service_name']}' has been created successfully!";
    header('Location: types.php');
    exit;

} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error creating service type: " . $e->getMessage());
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    header('Location: add-type.php');
    exit;
}
?>