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
    header('Location: requisitions.php');
    exit;
}

try {
    $conn->beginTransaction();

    // Validate required fields
    if (empty($_POST['requisition_date']) || empty($_POST['required_date']) || empty($_POST['purpose'])) {
        throw new Exception('Please fill in all required fields.');
    }

    // Validate items
    if (empty($_POST['items']) || !is_array($_POST['items'])) {
        throw new Exception('Please add at least one item to the requisition.');
    }

    // Determine status
    $status = isset($_POST['save_as_draft']) && $_POST['save_as_draft'] == '1' ? 'draft' : 'submitted';

    // Generate requisition number
    $year = date('Y');
    $prefix = 'PR';
    
    $number_query = "SELECT MAX(CAST(SUBSTRING(requisition_number, LENGTH(?)+2) AS UNSIGNED)) as max_num 
                     FROM purchase_requisitions 
                     WHERE company_id = ? 
                     AND requisition_number LIKE ? 
                     AND YEAR(requisition_date) = ?";
    $stmt = $conn->prepare($number_query);
    $stmt->execute([$prefix, $company_id, $prefix . '-' . $year . '-%', $year]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_num = ($result['max_num'] ?? 0) + 1;
    $requisition_number = sprintf('%s-%s-%04d', $prefix, $year, $next_num);

    // Insert requisition
    $insert_requisition = "
        INSERT INTO purchase_requisitions (
            company_id,
            requisition_number,
            requisition_date,
            required_date,
            department_id,
            requested_by,
            purpose,
            special_instructions,
            priority,
            status,
            created_by,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";

    $stmt = $conn->prepare($insert_requisition);
    $stmt->execute([
        $company_id,
        $requisition_number,
        $_POST['requisition_date'],
        $_POST['required_date'],
        !empty($_POST['department_id']) ? $_POST['department_id'] : null,
        $user_id,
        $_POST['purpose'],
        $_POST['special_instructions'] ?? null,
        $_POST['priority'] ?? 'normal',
        $status,
        $user_id
    ]);

    $requisition_id = $conn->lastInsertId();

    // Insert requisition items
    $insert_item = "
        INSERT INTO requisition_items (
            requisition_id,
            item_description,
            quantity,
            unit,
            estimated_unit_price,
            specifications
        ) VALUES (?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($insert_item);

    foreach ($_POST['items'] as $item) {
        if (empty($item['description']) || empty($item['quantity'])) {
            continue; // Skip items without description or quantity
        }

        $stmt->execute([
            $requisition_id,
            $item['description'],
            $item['quantity'],
            $item['unit'] ?? 'pcs',
            !empty($item['unit_price']) ? $item['unit_price'] : 0,
            $item['specifications'] ?? null
        ]);
    }

    $conn->commit();

    $_SESSION['success_message'] = "Purchase requisition {$requisition_number} has been " . 
                                     ($status == 'draft' ? 'saved as draft' : 'submitted') . 
                                     " successfully!";
    header('Location: view-requisition.php?id=' . $requisition_id);
    exit;

} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error creating requisition: " . $e->getMessage());
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    header('Location: create-requisition.php');
    exit;
}
?>