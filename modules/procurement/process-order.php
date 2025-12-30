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
    header('Location: orders.php');
    exit;
}

try {
    $conn->beginTransaction();

    // Validate required fields
    if (empty($_POST['po_date']) || empty($_POST['delivery_date']) || empty($_POST['supplier_id'])) {
        throw new Exception('Please fill in all required fields.');
    }

    // Validate items
    if (empty($_POST['items']) || !is_array($_POST['items'])) {
        throw new Exception('Please add at least one item to the order.');
    }

    // Determine status
    $status = isset($_POST['save_as_draft']) && $_POST['save_as_draft'] == '1' ? 'draft' : 'submitted';

    // Generate PO number
    $year = date('Y');
    $prefix = 'PO';
    
    $number_query = "SELECT MAX(CAST(SUBSTRING(po_number, LENGTH(?)+2) AS UNSIGNED)) as max_num 
                     FROM purchase_orders 
                     WHERE company_id = ? 
                     AND po_number LIKE ? 
                     AND YEAR(po_date) = ?";
    $stmt = $conn->prepare($number_query);
    $stmt->execute([$prefix, $company_id, $prefix . '-' . $year . '-%', $year]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_num = ($result['max_num'] ?? 0) + 1;
    $po_number = sprintf('%s-%s-%04d', $prefix, $year, $next_num);

    // Calculate total amount
    $total_amount = 0;
    foreach ($_POST['items'] as $item) {
        if (!empty($item['quantity']) && !empty($item['unit_price'])) {
            $total_amount += floatval($item['quantity']) * floatval($item['unit_price']);
        }
    }

    // Insert purchase order
    $insert_order = "
        INSERT INTO purchase_orders (
            company_id,
            po_number,
            po_date,
            supplier_id,
            requisition_id,
            total_amount,
            delivery_date,
            payment_terms,
            delivery_instructions,
            notes,
            status,
            created_by,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";

    $stmt = $conn->prepare($insert_order);
    $stmt->execute([
        $company_id,
        $po_number,
        $_POST['po_date'],
        $_POST['supplier_id'],
        !empty($_POST['requisition_id']) ? $_POST['requisition_id'] : null,
        $total_amount,
        $_POST['delivery_date'],
        $_POST['payment_terms'] ?? 'net_30',
        $_POST['delivery_instructions'] ?? null,
        $_POST['notes'] ?? null,
        $status,
        $user_id
    ]);

    $purchase_order_id = $conn->lastInsertId();

    // Insert order items
    $insert_item = "
        INSERT INTO purchase_order_items (
            purchase_order_id,
            item_description,
            quantity,
            unit,
            unit_price,
            total_price,
            specifications
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($insert_item);

    foreach ($_POST['items'] as $item) {
        if (empty($item['description']) || empty($item['quantity']) || empty($item['unit_price'])) {
            continue; // Skip incomplete items
        }

        $quantity = floatval($item['quantity']);
        $unit_price = floatval($item['unit_price']);
        $total_price = $quantity * $unit_price;

        $stmt->execute([
            $purchase_order_id,
            $item['description'],
            $quantity,
            $item['unit'] ?? 'pcs',
            $unit_price,
            $total_price,
            $item['specifications'] ?? null
        ]);
    }

    // If linked to requisition, update requisition status
    if (!empty($_POST['requisition_id'])) {
        $update_req = "UPDATE purchase_requisitions SET status = 'ordered' WHERE requisition_id = ?";
        $stmt = $conn->prepare($update_req);
        $stmt->execute([$_POST['requisition_id']]);
    }

    $conn->commit();

    $_SESSION['success_message'] = "Purchase order {$po_number} has been " . 
                                     ($status == 'draft' ? 'saved as draft' : 'created') . 
                                     " successfully!";
    header('Location: view-order.php?id=' . $purchase_order_id);
    exit;

} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error creating purchase order: " . $e->getMessage());
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    header('Location: create-order.php');
    exit;
}
?>