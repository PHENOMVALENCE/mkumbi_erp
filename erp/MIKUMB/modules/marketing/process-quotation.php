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
    $_SESSION['error_message'] = 'Invalid request method.';
    header('Location: quotations.php');
    exit;
}

try {
    $conn->beginTransaction();

    // Validate required fields
    if (empty($_POST['quote_date']) || empty($_POST['valid_until'])) {
        throw new Exception('Please fill in quote date and valid until fields.');
    }

    // Validate customer or lead selection
    if (empty($_POST['customer_id']) && empty($_POST['lead_id'])) {
        throw new Exception('Please select either a customer or a lead.');
    }

    // Validate items
    if (empty($_POST['items']) || !is_array($_POST['items'])) {
        throw new Exception('Please add at least one item to the quotation.');
    }

    // Determine status
    $status = isset($_POST['save_as_draft']) && $_POST['save_as_draft'] == '1' ? 'draft' : 'sent';

    // ✅ FIXED: Generate correct quotation number (QTN prefix)
    $year = date('Y');
    $prefix = 'QTN'; // Changed from 'QT' to 'QTN'
    
    $number_query = "
        SELECT MAX(CAST(SUBSTRING(quotation_number, LENGTH(?) + 2) AS UNSIGNED)) as max_num 
        FROM quotations 
        WHERE company_id = ? 
        AND quotation_number LIKE ? 
        AND YEAR(quote_date) = ?
    ";
    $stmt = $conn->prepare($number_query);
    $stmt->execute([$prefix, $company_id, $prefix . '-' . $year . '-%', $year]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_num = ($result['max_num'] ?? 0) + 1;
    $quotation_number = sprintf('%s-%s-%04d', $prefix, $year, $next_num);

    // Calculate totals
    $subtotal = 0;
    $valid_items = [];
    
    foreach ($_POST['items'] as $item_index => $item) {
        $description = trim($item['description'] ?? '');
        $quantity = floatval($item['quantity'] ?? 0);
        $unit_price = floatval($item['unit_price'] ?? 0);
        
        if (!empty($description) && $quantity > 0 && $unit_price > 0) {
            $item_total = $quantity * $unit_price;
            $subtotal += $item_total;
            
            $valid_items[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit' => trim($item['unit'] ?? 'unit'),
                'unit_price' => $unit_price,
                'total_price' => $item_total,
                'details' => trim($item['details'] ?? '')
            ];
        }
    }

    if (empty($valid_items)) {
        throw new Exception('Please add at least one valid item with description, quantity, and price.');
    }

    $discount_amount = 0; // Can be added later
    $tax_amount = 0; // Can be calculated later
    $total_amount = $subtotal - $discount_amount + $tax_amount;

    // ✅ FIXED: Correct column names for quotations table
    $insert_quotation = "
        INSERT INTO quotations (
            company_id,
            quotation_number,
            quote_date,
            valid_until,
            customer_id,
            lead_id,
            subtotal,
            discount_amount,
            tax_amount,
            total_amount,
            terms_conditions,
            notes,
            status,
            is_active,
            created_by,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
    ";

    $stmt = $conn->prepare($insert_quotation);
    $result = $stmt->execute([
        $company_id,
        $quotation_number,
        $_POST['quote_date'],
        $_POST['valid_until'],
        !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null,
        !empty($_POST['lead_id']) ? (int)$_POST['lead_id'] : null,
        $subtotal,
        $discount_amount,
        $tax_amount,
        $total_amount,
        trim($_POST['terms_conditions'] ?? ''),
        trim($_POST['notes'] ?? ''),
        $status,
        $user_id
    ]);

    if (!$result) {
        throw new Exception('Failed to create quotation record.');
    }

    $quotation_id = $conn->lastInsertId();

    // ✅ FIXED: Correct column names for quotation_items table
    $insert_item = "
        INSERT INTO quotation_items (
            quotation_id,
            description,
            quantity,
            unit,
            unit_price,
            total_price,
            details
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($insert_item);

    foreach ($valid_items as $item) {
        $stmt->execute([
            $quotation_id,
            $item['description'],
            $item['quantity'],
            $item['unit'],
            $item['unit_price'],
            $item['total_price'],
            $item['details'] ?: null
        ]);
    }

    // Update lead status if quotation is sent (not draft)
    if (!empty($_POST['lead_id']) && $status == 'sent') {
        $update_lead = "
            UPDATE leads 
            SET status = 'proposal', 
                updated_at = NOW() 
            WHERE lead_id = ? 
            AND company_id = ? 
            AND status NOT IN ('converted', 'lost')
        ";
        $lead_stmt = $conn->prepare($update_lead);
        $lead_stmt->execute([$_POST['lead_id'], $company_id]);
    }

    $conn->commit();

    $_SESSION['success_message'] = "Quotation <strong>{$quotation_number}</strong> has been " . 
                                  ($status == 'draft' ? 'saved as draft' : 'created successfully') . 
                                  "! Total: <strong>TSH " . number_format($total_amount, 0) . "</strong>";

    // Redirect to view quotation
    header('Location: view-quotation.php?id=' . $quotation_id);
    exit;

} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error creating quotation: " . $e->getMessage());
    error_log("Full error: " . print_r($_POST, true));
    $_SESSION['error_message'] = "Error creating quotation: " . $e->getMessage();
    header('Location: create-quotation.php');
    exit;
}
?>