<?php
/**
 * Loan Process Handler
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

$action = $_POST['action'] ?? '';
$loan_id = (int)($_POST['loan_id'] ?? 0);

// Verify loan exists and belongs to company
$sql = "SELECT el.*, e.employee_id, e.user_id as employee_user_id, u.full_name as employee_name, u.email
        FROM employee_loans el
        JOIN employees e ON el.employee_id = e.employee_id
        JOIN users u ON e.user_id = u.user_id
        WHERE el.loan_id = ? AND el.company_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$loan_id, $company_id]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    $_SESSION['error_message'] = "Loan not found.";
    header('Location: approvals.php');
    exit;
}

try {
    switch ($action) {
        case 'approve':
            // Check if the loan applicant is an admin
            $applicant_is_admin = isAdmin($conn, $loan['employee_user_id'] ?? 0);
            
            // Access control: Admin approves employee loans, Management approves admin and super admin loans
            $is_admin = isAdmin($conn, $user_id);
            $is_management = isManagement($conn, $user_id);
            
            if ($applicant_is_admin) {
                // Admin or Super Admin loan - only management can approve
                if (!$is_management) {
                    throw new Exception("You don't have permission to approve admin or super admin loan requests. Only management can approve these loans.");
                }
            } else {
                // Employee loan - only admin can approve
                if (!$is_admin) {
                    throw new Exception("You don't have permission to approve employee loan requests. Only admin can approve employee loans.");
                }
            }
            
            if (strtolower($loan['status']) !== 'pending') {
                throw new Exception("Only pending loans can be approved.");
            }
            
            $comments = sanitize($_POST['comments'] ?? '');
            
            $sql = "UPDATE employee_loans 
                    SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ?
                    WHERE loan_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id, $comments, $loan_id]);
            
            logAudit($conn, $company_id, $user_id, 'approve', 'loans', 'employee_loans', $loan_id, 
                     ['status' => 'pending'], ['status' => 'approved']);
            
            // TODO: Send notification to employee
            
            $_SESSION['success_message'] = "Loan approved successfully.";
            break;
            
        case 'reject':
            // Check if the loan applicant is an admin
            $applicant_is_admin = isAdmin($conn, $loan['employee_user_id'] ?? 0);
            
            // Access control: Admin rejects employee loans, Management rejects admin and super admin loans
            $is_admin = isAdmin($conn, $user_id);
            $is_management = isManagement($conn, $user_id);
            
            if ($applicant_is_admin) {
                // Admin or Super Admin loan - only management can reject
                if (!$is_management) {
                    throw new Exception("You don't have permission to reject admin or super admin loan requests. Only management can reject these loans.");
                }
            } else {
                // Employee loan - only admin can reject
                if (!$is_admin) {
                    throw new Exception("You don't have permission to reject employee loan requests. Only admin can reject employee loans.");
                }
            }
            
            if (strtolower($loan['status']) !== 'pending') {
                throw new Exception("Only pending loans can be rejected.");
            }
            
            $rejection_reason = sanitize($_POST['rejection_reason'] ?? '');
            if (empty($rejection_reason)) {
                throw new Exception("Rejection reason is required.");
            }
            
            $sql = "UPDATE employee_loans 
                    SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?
                    WHERE loan_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id, $rejection_reason, $loan_id]);
            
            logAudit($conn, $company_id, $user_id, 'reject', 'loans', 'employee_loans', $loan_id,
                     ['status' => 'pending'], ['status' => 'rejected', 'reason' => $rejection_reason]);
            
            $_SESSION['success_message'] = "Loan application rejected.";
            break;
            
        case 'disburse':
            // Check permission
            if (!hasPermission($conn, $user_id, ['FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to disburse loans.");
            }
            
            if (strtolower($loan['status']) !== 'approved') {
                throw new Exception("Only approved loans can be disbursed.");
            }
            
            $disbursement_date = $_POST['disbursement_date'] ?? date('Y-m-d');
            $disbursement_method = sanitize($_POST['payment_method'] ?? 'bank_transfer');
            $disbursement_reference = sanitize($_POST['payment_reference'] ?? '');
            $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
            
            $conn->beginTransaction();
            
            // Update loan status
            $sql = "UPDATE employee_loans 
                    SET status = 'disbursed', disbursement_date = ?,
                        disbursement_method = ?, disbursement_reference = ?, bank_account_id = ?
                    WHERE loan_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$disbursement_date, $disbursement_method, $disbursement_reference, $bank_account_id, $loan_id]);
            
            // Update repayment schedule due dates based on disbursement date
            $sql = "SELECT * FROM loan_repayment_schedule WHERE loan_id = ? ORDER BY installment_number";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$loan_id]);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($schedules as $i => $schedule) {
                $due_date = date('Y-m-d', strtotime($disbursement_date . ' +' . ($i + 1) . ' months'));
                $sql = "UPDATE loan_repayment_schedule SET due_date = ? WHERE schedule_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$due_date, $schedule['schedule_id']]);
            }
            
            // Set next payment date
            $next_payment_date = date('Y-m-d', strtotime($disbursement_date . ' +1 month'));
            $sql = "UPDATE employee_loans SET next_payment_date = ? WHERE loan_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$next_payment_date, $loan_id]);
            
            // ========== CASH FLOW INTEGRATION: Record disbursement transaction ==========
            $loan_amount = (float)$loan['loan_amount'];
            $employee_name = $loan['employee_name'] ?? 'Employee';
            
            if ($disbursement_method === 'bank_transfer' && $bank_account_id) {
                // Record bank transaction (debit/withdrawal)
                $year = date('Y', strtotime($disbursement_date));
                $count_sql = "SELECT COUNT(*) FROM bank_transactions 
                             WHERE company_id = ? AND transaction_type = 'debit' AND YEAR(transaction_date) = ?";
                $count_stmt = $conn->prepare($count_sql);
                $count_stmt->execute([$company_id, $year]);
                $count = $count_stmt->fetchColumn() + 1;
                $transaction_number = 'WTH-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                
                $bank_sql = "INSERT INTO bank_transactions (
                    company_id, bank_account_id, transaction_date, value_date, transaction_type,
                    amount, description, reference_number, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'debit', ?, ?, ?, ?, NOW())";
                
                $bank_stmt = $conn->prepare($bank_sql);
                $bank_stmt->execute([
                    $company_id,
                    $bank_account_id,
                    $disbursement_date,
                    $disbursement_date,
                    $loan_amount,
                    "Loan disbursement to {$employee_name} - {$loan['loan_number']}",
                    $disbursement_reference ?: $loan['loan_number'],
                    $user_id
                ]);
                
                // Update bank account balance
                $update_balance_sql = "UPDATE bank_accounts 
                                      SET current_balance = current_balance - ? 
                                      WHERE bank_account_id = ? AND company_id = ?";
                $update_balance_stmt = $conn->prepare($update_balance_sql);
                $update_balance_stmt->execute([$loan_amount, $bank_account_id, $company_id]);
                
            } elseif ($disbursement_method === 'cash') {
                // Record cash transaction (payment)
                $year = date('Y', strtotime($disbursement_date));
                $count_sql = "SELECT COUNT(*) FROM cash_transactions 
                             WHERE company_id = ? AND transaction_type = 'payment' AND YEAR(transaction_date) = ?";
                $count_stmt = $conn->prepare($count_sql);
                $count_stmt->execute([$company_id, $year]);
                $count = $count_stmt->fetchColumn() + 1;
                $transaction_number = 'CASH-PAY-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
                
                $cash_sql = "INSERT INTO cash_transactions (
                    company_id, transaction_date, transaction_number, reference_type,
                    amount, transaction_type, received_by, remarks, created_by, created_at
                ) VALUES (?, ?, ?, 'loan_disbursement', ?, 'payment', ?, ?, ?, NOW())";
                
                $cash_stmt = $conn->prepare($cash_sql);
                $cash_stmt->execute([
                    $company_id,
                    $disbursement_date,
                    $transaction_number,
                    $loan_amount,
                    $employee_name,
                    "Loan disbursement - {$loan['loan_number']}",
                    $user_id
                ]);
            }
            // ========== END CASH FLOW INTEGRATION ==========
            
            $conn->commit();
            
            logAudit($conn, $company_id, $user_id, 'disburse', 'loans', 'employee_loans', $loan_id,
                     ['status' => 'approved'], ['status' => 'disbursed', 'date' => $disbursement_date]);
            
            $_SESSION['success_message'] = "Loan disbursed successfully. Repayments start from " . date('M Y', strtotime($next_payment_date));
            break;
            
        case 'record_payment':
            // Check permission
            if (!hasPermission($conn, $user_id, ['FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to record payments.");
            }
            
            if (!in_array($loan['status'], ['disbursed', 'active'])) {
                throw new Exception("Can only record payments for disbursed or active loans.");
            }
            
            $payment_amount = (float)$_POST['payment_amount'];
            $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
            $payment_method = sanitize($_POST['payment_method'] ?? 'SALARY_DEDUCTION');
            $payment_reference = sanitize($_POST['payment_reference'] ?? '');
            
            if ($payment_amount <= 0) {
                throw new Exception("Payment amount must be greater than zero.");
            }
            
            if ($payment_amount > $loan['total_outstanding']) {
                throw new Exception("Payment amount cannot exceed outstanding balance.");
            }
            
            $conn->beginTransaction();
            
            // Get next pending installment
            $sql = "SELECT * FROM loan_repayment_schedule 
                    WHERE loan_id = ? AND payment_status = 'pending' 
                    ORDER BY installment_number LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$loan_id]);
            $installment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Allocate payment to principal and interest
            $interest_portion = min($payment_amount, $loan['interest_outstanding']);
            $principal_portion = $payment_amount - $interest_portion;
            $total_paid = $principal_portion + $interest_portion;
            
            // Record payment
            $sql = "INSERT INTO loan_payments (
                        loan_id, schedule_id, payment_date, principal_paid, interest_paid,
                        total_paid, payment_method, payment_reference, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $loan_id, $installment ? $installment['schedule_id'] : null,
                $payment_date, $principal_portion, $interest_portion, $total_paid,
                $payment_method, $payment_reference, $user_id
            ]);
            
            // Update loan outstanding balances
            $new_principal = max(0, $loan['principal_outstanding'] - $principal_portion);
            $new_interest = max(0, $loan['interest_outstanding'] - $interest_portion);
            $new_total = $new_principal + $new_interest;
            
            $new_status = $new_total <= 0 ? 'completed' : ($loan['status'] === 'disbursed' ? 'active' : $loan['status']);
            
            $sql = "UPDATE employee_loans 
                    SET principal_outstanding = ?, interest_outstanding = ?, total_outstanding = ?,
                        status = ?, last_payment_date = ?, total_paid = total_paid + ?
                    WHERE loan_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$new_principal, $new_interest, $new_total, $new_status, $payment_date, $total_paid, $loan_id]);
            
            // Update installment status if fully paid
            if ($installment && $total_paid >= $installment['total_amount']) {
                $sql = "UPDATE loan_repayment_schedule SET payment_status = 'paid', payment_date = ?, paid_amount = ? WHERE schedule_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$payment_date, $total_paid, $installment['schedule_id']]);
            } elseif ($installment && $total_paid > 0) {
                $sql = "UPDATE loan_repayment_schedule SET payment_status = 'partial', paid_amount = ? WHERE schedule_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$total_paid, $installment['schedule_id']]);
            }
            
            // Update remaining_balance and monthly_installment fields (matching schema)
            $sql = "UPDATE employee_loans 
                    SET remaining_balance = ?, monthly_installment = ?
                    WHERE loan_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$new_total, $loan['monthly_deduction'], $loan_id]);
            
            // ========== CASH FLOW INTEGRATION: Record repayment transaction ==========
            $employee_name = $loan['employee_name'] ?? 'Employee';
            $payment_method_lower = strtolower($payment_method);
            
            // Handle different payment methods
            if ($payment_method_lower === 'bank_transfer' || $payment_method_lower === 'bank') {
                // If bank account specified, record bank transaction (credit/deposit)
                if (!empty($loan['bank_account_id'])) {
                    $year = date('Y', strtotime($payment_date));
                    $count_sql = "SELECT COUNT(*) FROM bank_transactions 
                                 WHERE company_id = ? AND transaction_type = 'credit' AND YEAR(transaction_date) = ?";
                    $count_stmt = $conn->prepare($count_sql);
                    $count_stmt->execute([$company_id, $year]);
                    $count = $count_stmt->fetchColumn() + 1;
                    $transaction_number = 'DEP-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                    
                    $bank_sql = "INSERT INTO bank_transactions (
                        company_id, bank_account_id, transaction_date, value_date, transaction_type,
                        amount, description, reference_number, created_by, created_at
                    ) VALUES (?, ?, ?, ?, 'credit', ?, ?, ?, ?, NOW())";
                    
                    $bank_stmt = $conn->prepare($bank_sql);
                    $bank_stmt->execute([
                        $company_id,
                        $loan['bank_account_id'],
                        $payment_date,
                        $payment_date,
                        $payment_amount,
                        "Loan repayment from {$employee_name} - {$loan['loan_number']}",
                        $payment_reference ?: $loan['loan_number'],
                        $user_id
                    ]);
                    
                    // Update bank account balance
                    $update_balance_sql = "UPDATE bank_accounts 
                                          SET current_balance = current_balance + ? 
                                          WHERE bank_account_id = ? AND company_id = ?";
                    $update_balance_stmt = $conn->prepare($update_balance_sql);
                    $update_balance_stmt->execute([$payment_amount, $loan['bank_account_id'], $company_id]);
                }
            } elseif ($payment_method_lower === 'cash') {
                // Record cash transaction (receipt)
                $year = date('Y', strtotime($payment_date));
                $count_sql = "SELECT COUNT(*) FROM cash_transactions 
                             WHERE company_id = ? AND transaction_type = 'receipt' AND YEAR(transaction_date) = ?";
                $count_stmt = $conn->prepare($count_sql);
                $count_stmt->execute([$company_id, $year]);
                $count = $count_stmt->fetchColumn() + 1;
                $transaction_number = 'CASH-REC-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
                
                $cash_sql = "INSERT INTO cash_transactions (
                    company_id, transaction_date, transaction_number, reference_type,
                    amount, transaction_type, received_by, remarks, created_by, created_at
                ) VALUES (?, ?, ?, 'loan_repayment', ?, 'receipt', ?, ?, ?, NOW())";
                
                $cash_stmt = $conn->prepare($cash_sql);
                $cash_stmt->execute([
                    $company_id,
                    $payment_date,
                    $transaction_number,
                    $payment_amount,
                    $employee_name,
                    "Loan repayment - {$loan['loan_number']}",
                    $user_id
                ]);
            }
            // Note: Salary deduction doesn't create immediate cash flow transaction
            // It will be handled during payroll processing
            // ========== END CASH FLOW INTEGRATION ==========
            
            $conn->commit();
            
            logAudit($conn, $company_id, $user_id, 'payment', 'loans', 'loan_payments', 
                     $conn->lastInsertId(), null, ['amount' => $payment_amount]);
            
            $_SESSION['success_message'] = "Payment of " . formatCurrency($payment_amount) . " recorded successfully.";
            break;
            
        case 'cancel':
            // Employee can cancel their own pending loan
            $employee = getEmployeeByUserId($conn, $user_id, $company_id);
            
            if (!$employee || $loan['employee_id'] != $employee['employee_id']) {
                if (!hasPermission($conn, $user_id, ['HR_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                    throw new Exception("You can only cancel your own loan applications.");
                }
            }
            
            if (strtolower($loan['status']) !== 'pending') {
                throw new Exception("Only pending loan applications can be cancelled.");
            }
            
            $sql = "UPDATE employee_loans SET status = 'cancelled' WHERE loan_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$loan_id, $company_id]);
            
            logAudit($conn, $company_id, $user_id, 'cancel', 'loans', 'employee_loans', $loan_id);
            
            $_SESSION['success_message'] = "Loan application cancelled.";
            break;
            
        case 'update':
            // Update loan application (only for pending loans)
            $employee = getEmployeeByUserId($conn, $user_id, $company_id);
            $is_owner = $employee && $loan['employee_id'] == $employee['employee_id'];
            $is_hr = hasPermission($conn, $user_id, ['HR_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN']);
            
            if (!$is_owner && !$is_hr) {
                throw new Exception("You don't have permission to edit this loan application.");
            }
            
            if (strtolower($loan['status']) !== 'pending') {
                throw new Exception("Only pending loan applications can be edited.");
            }
            
            $loan_amount = (float)$_POST['loan_amount'];
            $loan_term_months = (int)$_POST['loan_term_months'];
            $purpose = sanitize($_POST['purpose']);
            $guarantor1_id = !empty($_POST['guarantor1_id']) ? (int)$_POST['guarantor1_id'] : null;
            $guarantor2_id = !empty($_POST['guarantor2_id']) ? (int)$_POST['guarantor2_id'] : null;
            
            if ($loan_amount <= 0) {
                throw new Exception("Loan amount must be greater than zero.");
            }
            if ($loan_term_months < 1) {
                throw new Exception("Loan term must be at least 1 month.");
            }
            
            $conn->beginTransaction();
            
            // Get loan type details
            $sql = "SELECT * FROM loan_types WHERE loan_type_id = ? AND company_id = ? AND is_active = 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$loan['loan_type_id'], $company_id]);
            $loan_type = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan_type) {
                throw new Exception("Invalid loan type.");
            }
            
            // Recalculate monthly deduction
            $monthly_interest = ($loan_amount * $loan_type['interest_rate'] / 100) / 12;
            $monthly_principal = $loan_amount / $loan_term_months;
            $monthly_deduction = $monthly_principal + $monthly_interest;
            $total_repayable = $monthly_deduction * $loan_term_months;
            
            // Update loan
            $sql = "UPDATE employee_loans 
                    SET loan_amount = ?, repayment_period_months = ?, monthly_installment = ?, 
                        monthly_deduction = ?, remaining_balance = ?, principal_outstanding = ?,
                        interest_outstanding = ?, total_outstanding = ?, purpose = ?,
                        guarantor1_id = ?, guarantor2_id = ?
                    WHERE loan_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $loan_amount, $loan_term_months, $monthly_deduction, $monthly_deduction,
                $total_repayable, $loan_amount, ($total_repayable - $loan_amount), $total_repayable,
                $purpose, $guarantor1_id, $guarantor2_id, $loan_id, $company_id
            ]);
            
            // Delete old schedule and create new one
            $sql = "DELETE FROM loan_repayment_schedule WHERE loan_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$loan_id]);
            
            $due_date = date('Y-m-d', strtotime('+1 month'));
            $balance = $loan_amount;
            for ($i = 1; $i <= $loan_term_months; $i++) {
                $principal = $monthly_principal;
                $interest = $monthly_interest;
                $total = $monthly_deduction;
                $balance -= $principal;
                
                $sql = "INSERT INTO loan_repayment_schedule (
                            loan_id, installment_number, due_date, principal_amount, interest_amount,
                            total_amount, balance_outstanding
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $loan_id, $i, $due_date, $principal, $interest, $total, max(0, $balance)
                ]);
                
                $due_date = date('Y-m-d', strtotime($due_date . ' +1 month'));
            }
            
            $conn->commit();
            
            logAudit($conn, $company_id, $user_id, 'update', 'loans', 'employee_loans', $loan_id,
                     null, ['amount' => $loan_amount, 'term' => $loan_term_months]);
            
            $_SESSION['success_message'] = "Loan application updated successfully.";
            break;
            
        case 'delete':
            // Delete loan application (only pending, rejected, or cancelled loans)
            if (!hasPermission($conn, $user_id, ['HR_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
                throw new Exception("You don't have permission to delete loan applications.");
            }
            
            if (!in_array(strtolower($loan['status']), ['pending', 'rejected', 'cancelled'])) {
                throw new Exception("Only pending, rejected, or cancelled loans can be deleted.");
            }
            
            $conn->beginTransaction();
            
            // Delete repayment schedule
            $sql = "DELETE FROM loan_repayment_schedule WHERE loan_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$loan_id]);
            
            // Delete loan
            $sql = "DELETE FROM employee_loans WHERE loan_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$loan_id, $company_id]);
            
            $conn->commit();
            
            logAudit($conn, $company_id, $user_id, 'delete', 'loans', 'employee_loans', $loan_id);
            
            $_SESSION['success_message'] = "Loan application deleted successfully.";
            break;
            
        default:
            throw new Exception("Invalid action.");
    }
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['error_message'] = $e->getMessage();
}

// Redirect based on action
$redirect = $_POST['redirect'] ?? 'approvals.php';
header('Location: ' . $redirect);
exit;
