<?php
/**
 * Petty Cash Process Handler
 * Mkumbi Investments ERP System
 */

define('APP_ACCESS', true);
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

$db = Database::getInstance();
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'approve':
            if (!hasPermission($conn, $user_id, ['FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to approve requests.");
            }
            
            $transaction_id = (int)$_POST['transaction_id'];
            $comments = sanitize($_POST['comments'] ?? '');
            
            // Get transaction
            $sql = "SELECT pct.*, pca.petty_cash_id, pca.current_balance
                    FROM petty_cash_transactions pct
                    JOIN petty_cash_accounts pca ON pct.petty_cash_id = pca.petty_cash_id
                    WHERE pct.transaction_id = ? AND pca.company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$transaction_id, $company_id]);
            $txn = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$txn) {
                throw new Exception("Transaction not found.");
            }
            if ($txn['status'] !== 'pending') {
                throw new Exception("Transaction already processed.");
            }
            if ($txn['amount'] > $txn['current_balance']) {
                throw new Exception("Insufficient balance in petty cash account.");
            }
            
            $conn->beginTransaction();
            
            // Update transaction
            $sql = "UPDATE petty_cash_transactions 
                    SET status = 'approved', approved_by = ?, approved_at = NOW(), comments = ?
                    WHERE transaction_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id, $comments, $transaction_id]);
            
            // Deduct from account balance
            $sql = "UPDATE petty_cash_accounts SET current_balance = current_balance - ? WHERE petty_cash_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$txn['amount'], $txn['petty_cash_id']]);
            
            $conn->commit();
            
            logAudit($conn, $company_id, $user_id, 'approve', 'petty_cash', 'petty_cash_transactions', 
                     $transaction_id, ['status' => 'pending'], ['status' => 'approved']);
            
            $_SESSION['success_message'] = "Request approved and disbursed.";
            break;
            
        case 'reject':
            if (!hasPermission($conn, $user_id, ['FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to reject requests.");
            }
            
            $transaction_id = (int)$_POST['transaction_id'];
            $rejection_reason = sanitize($_POST['rejection_reason'] ?? '');
            
            if (empty($rejection_reason)) {
                throw new Exception("Rejection reason is required.");
            }
            
            // Verify transaction
            $sql = "SELECT pct.* FROM petty_cash_transactions pct
                    JOIN petty_cash_accounts pca ON pct.petty_cash_id = pca.petty_cash_id
                    WHERE pct.transaction_id = ? AND pca.company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$transaction_id, $company_id]);
            $txn = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$txn || $txn['status'] !== 'pending') {
                throw new Exception("Invalid transaction or already processed.");
            }
            
            $sql = "UPDATE petty_cash_transactions 
                    SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?
                    WHERE transaction_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id, $rejection_reason, $transaction_id]);
            
            logAudit($conn, $company_id, $user_id, 'reject', 'petty_cash', 'petty_cash_transactions', 
                     $transaction_id, null, ['reason' => $rejection_reason]);
            
            $_SESSION['success_message'] = "Request rejected.";
            break;
            
        case 'replenish':
            if (!hasPermission($conn, $user_id, ['FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to replenish accounts.");
            }
            
            $petty_cash_id = (int)$_POST['petty_cash_id'];
            $amount = (float)$_POST['amount'];
            $description = sanitize($_POST['description'] ?? 'Account Replenishment');
            $source = sanitize($_POST['source'] ?? '');
            
            if ($amount <= 0) {
                throw new Exception("Amount must be greater than zero.");
            }
            
            // Verify account
            $sql = "SELECT * FROM petty_cash_accounts WHERE petty_cash_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$petty_cash_id, $company_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) {
                throw new Exception("Account not found.");
            }
            
            $new_balance = $account['current_balance'] + $amount;
            if ($new_balance > $account['maximum_limit']) {
                throw new Exception("Replenishment would exceed maximum balance limit.");
            }
            
            $conn->beginTransaction();
            
            $reference = generateReference('PCR', $conn, $company_id, 'petty_cash_transactions', 'transaction_number');
            $employee = getEmployeeByUserId($conn, $user_id, $company_id);
            $balance_before = $account['current_balance'];
            $balance_after = $balance_before + $amount;
            
            // Insert transaction
            $sql = "INSERT INTO petty_cash_transactions (
                        petty_cash_id, transaction_number, transaction_type, transaction_date,
                        amount, description, created_by, status, approved_by, approved_at,
                        balance_before, balance_after
                    ) VALUES (?, ?, 'replenishment', CURDATE(), ?, ?, ?, 'approved', ?, NOW(), ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$petty_cash_id, $reference, $amount, $description, 
                           $employee ? $employee['employee_id'] : null, $user_id, 
                           $balance_before, $balance_after]);
            
            // Update account balance
            $sql = "UPDATE petty_cash_accounts SET current_balance = current_balance + ? WHERE petty_cash_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$amount, $petty_cash_id]);
            
            $conn->commit();
            
            logAudit($conn, $company_id, $user_id, 'replenish', 'petty_cash', 'petty_cash_accounts', 
                     $petty_cash_id, null, ['amount' => $amount, 'reference' => $reference]);
            
            $_SESSION['success_message'] = "Account replenished with " . formatCurrency($amount) . ". Ref: " . $reference;
            header('Location: index.php');
            exit;
            
        case 'add_account':
        case 'edit_account':
            if (!hasPermission($conn, $user_id, ['FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to manage accounts.");
            }
            
            $petty_cash_id = (int)($_POST['petty_cash_id'] ?? 0);
            $account_name = sanitize($_POST['account_name']);
            $account_code = sanitize($_POST['account_code']);
            $maximum_limit = (float)$_POST['maximum_limit'];
            $transaction_limit = (float)$_POST['transaction_limit'];
            $custodian_id = !empty($_POST['custodian_id']) ? (int)$_POST['custodian_id'] : null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($account_name)) {
                throw new Exception("Account name is required.");
            }
            if ($maximum_limit <= 0) {
                throw new Exception("Maximum balance must be greater than zero.");
            }
            
            if ($action === 'add_account') {
                $initial_balance = (float)($_POST['initial_balance'] ?? 0);
                if ($initial_balance > $maximum_limit) {
                    throw new Exception("Initial balance cannot exceed maximum balance.");
                }
                
                $sql = "INSERT INTO petty_cash_accounts (
                            company_id, account_name, account_code, maximum_limit, 
                            current_balance, transaction_limit, custodian_id, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$company_id, $account_name, $account_code, $maximum_limit, 
                               $initial_balance, $transaction_limit, $custodian_id, $is_active]);
                
                $_SESSION['success_message'] = "Account created successfully.";
            } else {
                $sql = "UPDATE petty_cash_accounts 
                        SET account_name = ?, account_code = ?, maximum_limit = ?, 
                            transaction_limit = ?, custodian_id = ?, is_active = ?
                        WHERE petty_cash_id = ? AND company_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$account_name, $account_code, $maximum_limit, 
                               $transaction_limit, $custodian_id, $is_active, $petty_cash_id, $company_id]);
                
                $_SESSION['success_message'] = "Account updated successfully.";
            }
            
            header('Location: accounts.php');
            exit;
            
        case 'cancel':
            $transaction_id = (int)$_POST['transaction_id'];
            $employee = getEmployeeByUserId($conn, $user_id, $company_id);
            
            // Verify ownership
            $sql = "SELECT pct.* FROM petty_cash_transactions pct
                    JOIN petty_cash_accounts pca ON pct.petty_cash_id = pca.petty_cash_id
                    WHERE pct.transaction_id = ? AND pca.company_id = ? AND pct.created_by = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$transaction_id, $company_id, $employee['employee_id']]);
            $txn = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$txn || $txn['status'] !== 'PENDING') {
                throw new Exception("Cannot cancel this request.");
            }
            
            $sql = "UPDATE petty_cash_transactions SET status = 'CANCELLED' WHERE transaction_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$transaction_id]);
            
            $_SESSION['success_message'] = "Request cancelled.";
            header('Location: my-requests.php');
            exit;
            
        default:
            throw new Exception("Invalid action.");
    }
    
    header('Location: approvals.php');
    exit;
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}
