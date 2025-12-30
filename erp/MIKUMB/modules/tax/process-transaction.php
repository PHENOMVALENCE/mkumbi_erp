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
$user_id = $_SESSION['user_id'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    header('Location: add-transaction.php');
    exit;
}

// Sanitize and validate inputs
$transaction_number = trim($_POST['transaction_number'] ?? '');
$transaction_date = trim($_POST['transaction_date'] ?? '');
$transaction_type = trim($_POST['transaction_type'] ?? '');
$tax_type_id = (int)($_POST['tax_type_id'] ?? 0);
$taxable_amount = (float)($_POST['taxable_amount'] ?? 0);
$tax_amount = (float)($_POST['tax_amount'] ?? 0);
$invoice_number = trim($_POST['invoice_number'] ?? '');
$customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
$supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
$description = trim($_POST['description'] ?? '');
$remarks = trim($_POST['remarks'] ?? '');
$status = trim($_POST['status'] ?? 'pending');
$payment_date = !empty($_POST['payment_date']) ? trim($_POST['payment_date']) : null;

// Validation
$errors = [];

if (empty($transaction_number)) $errors[] = 'Transaction number is required';
if (empty($transaction_date)) $errors[] = 'Transaction date is required';
if (!in_array($transaction_type, ['sales', 'purchase', 'payroll', 'withholding', 'other'])) $errors[] = 'Invalid transaction type';
if ($tax_type_id <= 0) $errors[] = 'Valid tax type is required';
if ($taxable_amount <= 0) $errors[] = 'Taxable amount must be greater than 0';
if ($tax_amount <= 0) $errors[] = 'Tax amount must be greater than 0';

if (!empty($errors)) {
    $_SESSION['error_message'] = 'Validation Error: ' . implode(', ', $errors);
    header('Location: add-transaction.php');
    exit;
}

try {
    $conn->beginTransaction();

    // 🔥 DYNAMIC COLUMN INSERT - Works with ANY table structure
    $required_columns = [
        'company_id', 'transaction_number', 'transaction_date', 'transaction_type', 
        'tax_type_id', 'taxable_amount', 'tax_amount'
    ];
    $optional_columns = [
        'total_amount', 'customer_id', 'supplier_id', 'invoice_number', 
        'description', 'status', 'payment_date', 'remarks', 'created_by'
    ];

    // Get available columns
    $available_columns = [];
    $result = $conn->query("SHOW COLUMNS FROM tax_transactions");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $available_columns[] = $row['Field'];
    }

    // Build INSERT columns
    $insert_columns = array_intersect($required_columns, $available_columns);
    $values = [
        $company_id, $transaction_number, $transaction_date, $transaction_type,
        $tax_type_id, $taxable_amount, $tax_amount
    ];

    // Add optional columns if they exist
    $optional_values = [];
    if (in_array('total_amount', $available_columns)) {
        $insert_columns[] = 'total_amount';
        $optional_values[] = $taxable_amount + $tax_amount;
    }
    
    if (in_array('customer_id', $available_columns) && $customer_id) {
        $insert_columns[] = 'customer_id';
        $optional_values[] = $customer_id;
    }
    
    if (in_array('supplier_id', $available_columns) && $supplier_id) {
        $insert_columns[] = 'supplier_id';
        $optional_values[] = $supplier_id;
    }
    
    if (in_array('invoice_number', $available_columns) && !empty($invoice_number)) {
        $insert_columns[] = 'invoice_number';
        $optional_values[] = $invoice_number;
    }
    
    if (in_array('description', $available_columns) && !empty($description)) {
        $insert_columns[] = 'description';
        $optional_values[] = $description;
    }
    
    if (in_array('status', $available_columns)) {
        $insert_columns[] = 'status';
        $optional_values[] = $status;
    }
    
    if (in_array('payment_date', $available_columns) && !empty($payment_date)) {
        $insert_columns[] = 'payment_date';
        $optional_values[] = $payment_date;
    }
    
    if (in_array('remarks', $available_columns) && !empty($remarks)) {
        $insert_columns[] = 'remarks';
        $optional_values[] = $remarks;
    }
    
    if (in_array('created_by', $available_columns)) {
        $insert_columns[] = 'created_by';
        $optional_values[] = $user_id;
    }

    $values = array_merge($values, $optional_values);
    $placeholders = implode(',', array_fill(0, count($insert_columns), '?'));

    // Check for duplicate transaction number
    $check_query = "SELECT tax_transaction_id FROM tax_transactions WHERE company_id = ? AND transaction_number = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->execute([$company_id, $transaction_number]);
    
    if ($check_stmt->fetch()) {
        throw new Exception("Transaction number '$transaction_number' already exists");
    }

    // Verify tax type exists
    $tax_check = "SELECT tax_type_id FROM tax_types WHERE company_id = ? AND tax_type_id = ? AND is_active = 1";
    $tax_stmt = $conn->prepare($tax_check);
    $tax_stmt->execute([$company_id, $tax_type_id]);
    
    if (!$tax_stmt->fetch()) {
        throw new Exception('Invalid or inactive tax type selected');
    }

    // Insert transaction
    $query = "INSERT INTO tax_transactions (`" . implode('`, `', $insert_columns) . "`) VALUES ($placeholders)";
    
    // Log for debugging
    error_log("Tax transaction query: " . $query);
    error_log("Values count: " . count($values));
    
    $stmt = $conn->prepare($query);
    $success = $stmt->execute($values);

    if (!$success) {
        throw new Exception('Failed to execute insert query');
    }

    $tax_transaction_id = $conn->lastInsertId();
    $conn->commit();

    // Success!
    $_SESSION['success_message'] = "✅ Tax transaction <strong>$transaction_number</strong> recorded successfully!<br>
        <small>Tax Amount: TSH " . number_format($tax_amount, 2) . " | Date: $transaction_date</small>";
    
    header('Location: transactions.php');
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("🚨 Tax Transaction Error: " . $e->getMessage());
    error_log("SQL Query: " . ($query ?? 'N/A'));
    error_log("Values: " . print_r($values ?? [], true));
    
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    header('Location: add-transaction.php');
    exit;
}
?>