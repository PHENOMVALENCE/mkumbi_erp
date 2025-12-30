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

// Check if form submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    header('Location: payroll.php');
    exit;
}

// Get payroll ID and action
$payroll_id = isset($_POST['payroll_id']) ? (int)$_POST['payroll_id'] : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($payroll_id <= 0 || !in_array($action, ['process', 'mark_paid', 'cancel'])) {
    $_SESSION['error_message'] = 'Invalid payroll ID or action';
    header('Location: payroll.php');
    exit;
}

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Verify payroll exists and belongs to company
    $check_query = "
        SELECT payroll_id, status, payroll_month, payroll_year 
        FROM payroll 
        WHERE payroll_id = ? AND company_id = ?
    ";
    $stmt = $conn->prepare($check_query);
    $stmt->execute([$payroll_id, $company_id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payroll) {
        throw new Exception('Payroll not found');
    }
    
    $current_status = $payroll['status'];
    $new_status = '';
    $payment_date = null;
    
    // Determine new status based on action and current status
    switch ($action) {
        case 'process':
            if ($current_status !== 'draft') {
                throw new Exception('Only draft payroll can be processed');
            }
            $new_status = 'processed';
            break;
            
        case 'mark_paid':
            if (!in_array($current_status, ['draft', 'processed'])) {
                throw new Exception('Only draft or processed payroll can be marked as paid');
            }
            $new_status = 'paid';
            $payment_date = date('Y-m-d');
            
            // Get payment information from POST
            $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'bank_transfer';
            $payment_reference = isset($_POST['payment_reference']) ? trim($_POST['payment_reference']) : '';
            
            // Update payroll header with payment info
            $update_header = "
                UPDATE payroll 
                SET status = ?, 
                    payment_date = ?,
                    payment_method = ?,
                    payment_reference = ?,
                    updated_at = NOW(),
                    updated_by = ?
                WHERE payroll_id = ? AND company_id = ?
            ";
            $stmt = $conn->prepare($update_header);
            $stmt->execute([
                $new_status,
                $payment_date,
                $payment_method,
                $payment_reference,
                $_SESSION['user_id'],
                $payroll_id,
                $company_id
            ]);
            
            // Update all payroll details payment status
            $update_details = "
                UPDATE payroll_details 
                SET payment_status = 'paid',
                    payment_date = ?
                WHERE payroll_id = ?
            ";
            $stmt = $conn->prepare($update_details);
            $stmt->execute([$payment_date, $payroll_id]);
            
            $conn->commit();
            
            $_SESSION['success_message'] = 'Payroll marked as paid successfully';
            header('Location: payroll.php?month=' . $payroll['payroll_month'] . '&year=' . $payroll['payroll_year']);
            exit;
            
        case 'cancel':
            if ($current_status === 'paid') {
                throw new Exception('Paid payroll cannot be cancelled');
            }
            $new_status = 'cancelled';
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    // Update payroll status (for process and cancel actions)
    if ($action !== 'mark_paid') {
        $update_query = "
            UPDATE payroll 
            SET status = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE payroll_id = ? AND company_id = ?
        ";
        $stmt = $conn->prepare($update_query);
        $stmt->execute([$new_status, $_SESSION['user_id'], $payroll_id, $company_id]);
    }
    
    // Commit transaction
    $conn->commit();
    
    // Set success message based on action
    switch ($action) {
        case 'process':
            $_SESSION['success_message'] = 'Payroll processed successfully. You can now mark it as paid.';
            break;
        case 'cancel':
            $_SESSION['success_message'] = 'Payroll cancelled successfully';
            break;
    }
    
    // Redirect back to payroll page
    header('Location: payroll.php?month=' . $payroll['payroll_month'] . '&year=' . $payroll['payroll_year']);
    exit;
    
} catch (Exception $e) {
    // Rollback on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Error processing payroll: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    header('Location: payroll.php');
    exit;
}
?>