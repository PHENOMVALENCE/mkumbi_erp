<?php
define('APP_ACCESS', true);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

$errors = [];
$success = '';

// Fetch active reservations with payment stage details
try {
    $reservations_sql = "SELECT 
        r.reservation_id,
        r.reservation_number,
        r.total_amount,
        r.down_payment,
        r.down_payment_paid,
        r.down_payment_balance,
        r.payment_stage,
        r.installment_amount,
        r.installments_paid_count,
        r.payment_periods,
        r.last_installment_date,
        c.full_name as customer_name,
        c.phone as customer_phone,
        pl.plot_number,
        pr.project_name,
        
        -- Calculate total paid overall
        COALESCE((SELECT SUM(amount) FROM payments p2 
                  WHERE p2.reservation_id = r.reservation_id 
                  AND p2.company_id = r.company_id
                  AND p2.status = 'approved'), 0) as total_paid,
        
        -- Calculate current installment balance if in installment stage
        CASE 
            WHEN r.payment_stage LIKE '%installment%' THEN
                r.installment_amount - 
                (COALESCE((SELECT SUM(amount) FROM payments p3 
                          WHERE p3.reservation_id = r.reservation_id 
                          AND p3.company_id = r.company_id
                          AND p3.payment_stage = 'installment' 
                          AND p3.status = 'approved'), 0) - 
                (r.installments_paid_count * r.installment_amount))
            ELSE 0
        END as current_installment_balance,
        
        -- Overall remaining balance
        (r.total_amount - COALESCE((SELECT SUM(amount) FROM payments p4 
                                    WHERE p4.reservation_id = r.reservation_id 
                                    AND p4.company_id = r.company_id
                                    AND p4.status = 'approved'), 0)) as overall_balance
        
    FROM reservations r
    JOIN customers c ON r.customer_id = c.customer_id AND r.company_id = c.company_id
    JOIN plots pl ON r.plot_id = pl.plot_id AND r.company_id = pl.company_id
    JOIN projects pr ON pl.project_id = pr.project_id AND pl.company_id = pr.company_id
    WHERE r.status = 'active'
    AND r.company_id = ?
    AND r.payment_stage != 'completed'
    ORDER BY 
        CASE 
            WHEN r.payment_stage LIKE '%down_payment%' THEN 1
            WHEN r.payment_stage LIKE '%installment%' THEN 2
            ELSE 3
        END,
        r.reservation_date DESC";
    
    $stmt = $conn->prepare($reservations_sql);
    $stmt->execute([$company_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Error fetching reservations: " . $e->getMessage();
    $reservations = [];
}

// Fetch company accounts (bank + mobile money)
try {
    $accounts_sql = "SELECT bank_account_id, account_name, account_category, 
                            bank_name, mobile_provider, account_number, mobile_number,
                            current_balance
                     FROM bank_accounts 
                     WHERE company_id = ? AND is_active = 1 
                     ORDER BY is_default DESC, account_category, account_name";
    $accounts_stmt = $conn->prepare($accounts_sql);
    $accounts_stmt->execute([$company_id]);
    $accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $bank_accounts = array_filter($accounts, fn($acc) => $acc['account_category'] === 'bank');
    $mobile_accounts = array_filter($accounts, fn($acc) => $acc['account_category'] === 'mobile_money');
    
} catch (PDOException $e) {
    $accounts = [];
    $bank_accounts = [];
    $mobile_accounts = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validation
    if (empty($_POST['reservation_id'])) $errors[] = "Reservation is required";
    if (empty($_POST['payment_date'])) $errors[] = "Payment date is required";
    if (empty($_POST['amount'])) $errors[] = "Amount is required";
    if (empty($_POST['payment_method'])) $errors[] = "Payment method is required";
    
    $payment_method = $_POST['payment_method'] ?? '';
    
    // Payment method specific validation
    if ($payment_method === 'cash' && empty($_POST['received_by'])) {
        $errors[] = "Received by is required for cash payment";
    }
    if ($payment_method === 'cheque') {
        if (empty($_POST['cheque_number'])) $errors[] = "Cheque number is required";
        if (empty($_POST['cheque_bank'])) $errors[] = "Cheque bank is required";
        if (empty($_POST['cheque_date'])) $errors[] = "Cheque date is required";
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Get reservation details
            $res_sql = "SELECT * FROM reservations WHERE reservation_id = ? AND company_id = ?";
            $res_stmt = $conn->prepare($res_sql);
            $res_stmt->execute([$_POST['reservation_id'], $company_id]);
            $reservation = $res_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reservation) {
                throw new Exception("Reservation not found");
            }
            
            $amount = floatval($_POST['amount']);
            
            // Determine payment stage and calculate details
            if (strpos($reservation['payment_stage'], 'down_payment') !== false) {
                // DOWN PAYMENT STAGE
                $payment_stage = 'down_payment';
                $installment_number = null;
                $expected_amount = $reservation['down_payment_balance'];
                $stage_balance_before = $reservation['down_payment_balance'];
                $stage_balance_after = max(0, $stage_balance_before - $amount);
                $is_partial = ($stage_balance_after > 0) ? 1 : 0;
                
            } else {
                // INSTALLMENT STAGE
                $payment_stage = 'installment';
                
                // Get total paid in installments so far
                $inst_paid_sql = "SELECT COALESCE(SUM(amount), 0) FROM payments 
                                 WHERE reservation_id = ? AND company_id = ?
                                 AND payment_stage = 'installment' 
                                 AND status = 'approved'";
                $inst_paid_stmt = $conn->prepare($inst_paid_sql);
                $inst_paid_stmt->execute([$reservation['reservation_id'], $company_id]);
                $total_inst_paid = floatval($inst_paid_stmt->fetchColumn());
                
                // Calculate which installment we're on
                $installments_completed = floor($total_inst_paid / $reservation['installment_amount']);
                $paid_in_current = $total_inst_paid - ($installments_completed * $reservation['installment_amount']);
                
                $current_installment = $installments_completed + 1;
                $installment_number = $current_installment;
                $expected_amount = $reservation['installment_amount'];
                $stage_balance_before = $expected_amount - $paid_in_current;
                $stage_balance_after = max(0, $stage_balance_before - $amount);
                $is_partial = ($stage_balance_after > 0) ? 1 : 0;
            }
            
            // Generate payment and receipt numbers
            $payment_year = date('Y', strtotime($_POST['payment_date']));
            $payment_count_sql = "SELECT COUNT(*) FROM payments WHERE company_id = ? AND YEAR(payment_date) = ?";
            $payment_count_stmt = $conn->prepare($payment_count_sql);
            $payment_count_stmt->execute([$company_id, $payment_year]);
            $payment_count = $payment_count_stmt->fetchColumn() + 1;
            
            $payment_number = 'PAY-' . $payment_year . '-' . str_pad($payment_count, 4, '0', STR_PAD_LEFT);
            $receipt_number = 'REC-' . $payment_year . '-' . str_pad($payment_count, 4, '0', STR_PAD_LEFT);
            
            // Prepare payment method specific data
            $bank_name = null;
            $depositor_name = null;
            $deposit_bank = null;
            $deposit_account = null;
            $transfer_from_bank = null;
            $transfer_from_account = null;
            $mobile_money_provider = null;
            $mobile_money_number = null;
            $mobile_money_name = null;
            $to_account_id = !empty($_POST['to_account_id']) ? intval($_POST['to_account_id']) : null;
            $cash_transaction_id = null;
            $cheque_transaction_id = null;
            
            switch ($payment_method) {
                case 'bank_transfer':
                    $transfer_from_bank = $_POST['client_bank_name'] ?? null;
                    $transfer_from_account = $_POST['client_account_number'] ?? null;
                    $bank_name = $_POST['client_account_name'] ?? null;
                    break;
                
                case 'bank_deposit':
                    $deposit_bank = $_POST['deposit_bank'] ?? null;
                    $deposit_account = $_POST['deposit_account'] ?? null;
                    $depositor_name = $_POST['depositor_name'] ?? null;
                    break;
                
                case 'mobile_money':
                    $mobile_money_provider = $_POST['mobile_money_provider'] ?? null;
                    $mobile_money_number = $_POST['mobile_money_number'] ?? null;
                    $mobile_money_name = $_POST['mobile_money_name'] ?? null;
                    break;
                
                case 'cash':
                    $cash_count_sql = "SELECT COUNT(*) FROM cash_transactions WHERE company_id = ? AND YEAR(transaction_date) = ?";
                    $cash_count_stmt = $conn->prepare($cash_count_sql);
                    $cash_count_stmt->execute([$company_id, $payment_year]);
                    $cash_count = $cash_count_stmt->fetchColumn() + 1;
                    $cash_number = 'CASH-' . $payment_year . '-' . str_pad($cash_count, 4, '0', STR_PAD_LEFT);
                    
                    $cash_sql = "INSERT INTO cash_transactions (company_id, transaction_date, transaction_number, amount, transaction_type, received_by, remarks, created_by) VALUES (?, ?, ?, ?, 'receipt', ?, ?, ?)";
                    $cash_stmt = $conn->prepare($cash_sql);
                    $cash_stmt->execute([
                        $company_id,
                        $_POST['payment_date'],
                        $cash_number,
                        $amount,
                        $_POST['received_by'],
                        ucfirst(str_replace('_', ' ', $payment_stage)) . ' payment for ' . $reservation['reservation_number'],
                        $_SESSION['user_id']
                    ]);
                    
                    $cash_transaction_id = $conn->lastInsertId();
                    break;
                
                case 'cheque':
                    $cheque_sql = "INSERT INTO cheque_transactions (company_id, cheque_number, cheque_date, bank_name, branch_name, amount, payee_name, status, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)";
                    $cheque_stmt = $conn->prepare($cheque_sql);
                    $cheque_stmt->execute([
                        $company_id,
                        $_POST['cheque_number'],
                        $_POST['cheque_date'],
                        $_POST['cheque_bank'],
                        $_POST['cheque_branch'] ?? null,
                        $amount,
                        $_POST['cheque_payee'] ?? null,
                        ucfirst(str_replace('_', ' ', $payment_stage)) . ' payment for ' . $reservation['reservation_number'],
                        $_SESSION['user_id']
                    ]);
                    
                    $cheque_transaction_id = $conn->lastInsertId();
                    $bank_name = $_POST['cheque_bank'];
                    break;
            }
            
            // Insert payment record with stage tracking
            $payment_sql = "INSERT INTO payments (
                company_id, reservation_id, payment_date, payment_number, amount,
                payment_stage, installment_number, expected_amount,
                receipt_number, is_partial, stage_balance_before, stage_balance_after,
                payment_method, bank_name, transaction_reference,
                depositor_name, deposit_bank, deposit_account,
                transfer_from_bank, transfer_from_account,
                mobile_money_provider, mobile_money_number, mobile_money_name,
                to_account_id,
                cash_transaction_id, cheque_transaction_id,
                remarks, status, submitted_by, submitted_at, created_by, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?,
                ?, ?,
                ?, 'pending_approval', ?, NOW(), ?, NOW()
            )";
            
            $payment_stmt = $conn->prepare($payment_sql);
            $payment_stmt->execute([
                $company_id,
                $_POST['reservation_id'],
                $_POST['payment_date'],
                $payment_number,
                $amount,
                $payment_stage,
                $installment_number,
                $expected_amount,
                $receipt_number,
                $is_partial,
                $stage_balance_before,
                $stage_balance_after,
                $payment_method,
                $bank_name,
                $_POST['transaction_reference'] ?? null,
                $depositor_name,
                $deposit_bank,
                $deposit_account,
                $transfer_from_bank,
                $transfer_from_account,
                $mobile_money_provider,
                $mobile_money_number,
                $mobile_money_name,
                $to_account_id,
                $cash_transaction_id,
                $cheque_transaction_id,
                $_POST['remarks'] ?? ucfirst(str_replace('_', ' ', $payment_stage)) . ' payment for ' . $reservation['reservation_number'],
                $_SESSION['user_id'],
                $_SESSION['user_id']
            ]);
            
            $payment_id = $conn->lastInsertId();
            
            // Update cash/cheque transaction with payment_id
            if ($cash_transaction_id) {
                $update_cash = $conn->prepare("UPDATE cash_transactions SET payment_id = ? WHERE cash_transaction_id = ?");
                $update_cash->execute([$payment_id, $cash_transaction_id]);
            }
            
            if ($cheque_transaction_id) {
                $update_cheque = $conn->prepare("UPDATE cheque_transactions SET payment_id = ? WHERE cheque_transaction_id = ?");
                $update_cheque->execute([$payment_id, $cheque_transaction_id]);
            }
            
            $conn->commit();
            
            $stage_text = ($payment_stage === 'down_payment') ? 
                'Down Payment' : 
                'Installment #' . $installment_number;
            
            $_SESSION['success'] = "Payment recorded successfully!<br>" .
                "<strong>Amount:</strong> TZS " . number_format($amount, 2) . "<br>" .
                "<strong>Payment Number:</strong> " . $payment_number . "<br>" .
                "<strong>Receipt Number:</strong> " . $receipt_number . "<br>" .
                "<strong>Stage:</strong> " . $stage_text . 
                ($is_partial ? " (Partial Payment)" : " (Complete)") . "<br>" .
                "Pending manager approval.";
            
            header("Location: record.php");
            exit;
            
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = "Error: " . $e->getMessage();
            error_log("Payment recording error: " . $e->getMessage());
        }
    }
}

// Handle session success message
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

$page_title = 'Record Payment';
require_once '../../includes/header.php';
?>

<style>
.reservation-card{background:white;border-radius:10px;padding:20px;margin-bottom:15px;box-shadow:0 2px 8px rgba(0,0,0,0.08);border-left:4px solid #007bff;cursor:pointer;transition:all 0.3s;position:relative}
.reservation-card:hover{transform:translateX(5px);box-shadow:0 4px 12px rgba(0,0,0,0.12)}
.reservation-card.selected{border-left-color:#28a745;background:#f0fff4}
.stage-badge{display:inline-block;padding:6px 12px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:10px}
.stage-badge.down-payment{background:#dc3545;color:white}
.stage-badge.installment{background:#ffc107;color:#000}
.stage-badge.almost-done{background:#28a745;color:white}
.progress-container{margin:15px 0}
.progress{height:25px;border-radius:12px;background:#e9ecef}
.progress-bar{display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:12px;border-radius:12px}
.stage-info{background:#f8f9fa;padding:12px;border-radius:8px;margin-top:10px}
.stage-info-row{display:flex;justify-content:space-between;margin-bottom:5px;font-size:13px}
.stage-info-row:last-child{margin-bottom:0}
.stage-info-label{color:#6c757d}
.stage-info-value{font-weight:700}
.payment-form{background:white;border-radius:10px;padding:25px;box-shadow:0 2px 12px rgba(0,0,0,0.1)}
.form-section{margin-bottom:25px;padding-bottom:20px;border-bottom:2px solid #e9ecef}
.form-section:last-child{border-bottom:none}
.form-section-title{font-size:16px;font-weight:700;color:#333;margin-bottom:15px;display:flex;align-items:center}
.payment-method-fields{display:none;margin-top:15px;padding:15px;background:#f8f9fa;border-radius:8px;border-left:4px solid #007bff}
.payment-method-fields.active{display:block}
.client-bank-section{background:#e7f3ff;padding:15px;border-radius:8px;border:1px solid #b3d9ff;margin-bottom:15px}
.company-account-section{background:#e8f5e9;padding:15px;border-radius:8px;border:1px solid #a5d6a7}
.current-stage-display{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;padding:20px;border-radius:10px;margin-bottom:20px}
.current-stage-display h4{color:white;margin-bottom:15px}
.current-stage-display .stage-detail{margin-bottom:10px}
</style>

<div class="content-header">
<div class="container-fluid">
<div class="row mb-2">
<div class="col-sm-6"><h1><i class="fas fa-money-bill-wave"></i> Record Payment</h1></div>
<div class="col-sm-6"><ol class="breadcrumb float-sm-right"><li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li><li class="breadcrumb-item"><a href="index.php">Payments</a></li><li class="breadcrumb-item active">Record</li></ol></div>
</div>
</div>
</div>

<section class="content">
<div class="container-fluid">

<?php if (!empty($errors)): ?>
<div class="alert alert-danger alert-dismissible">
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
<h5><i class="fas fa-ban"></i> Errors!</h5>
<ul class="mb-0">
<?php foreach ($errors as $error): ?>
<li><?php echo htmlspecialchars($error); ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible">
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
<i class="fas fa-check-circle"></i> <?php echo $success; ?>
</div>
<?php endif; ?>

<div class="alert alert-info">
<i class="fas fa-info-circle"></i>
<strong>Payment Stages:</strong> Payments are tracked by stages (Down Payment or Installments). You can pay any amount - partial or full. Once approved, the system automatically updates payment stages and moves to the next stage when complete.
</div>

<div class="row">
<div class="col-md-4">
<h5 class="mb-3"><i class="fas fa-list"></i> Active Reservations</h5>

<?php if (empty($reservations)): ?>
<div class="alert alert-warning">
<i class="fas fa-exclamation-triangle"></i> No active reservations with outstanding balance found.
</div>
<?php else: ?>
<?php foreach ($reservations as $res): 
    // Determine current stage details
    if (strpos($res['payment_stage'], 'down_payment') !== false) {
        $stage = 'DOWN PAYMENT';
        $stage_class = 'down-payment';
        $stage_icon = 'fa-arrow-down';
        $needed = $res['down_payment_balance'];
        $paid_in_stage = $res['down_payment_paid'];
        $total_in_stage = $res['down_payment'];
        $progress = ($total_in_stage > 0) ? ($paid_in_stage / $total_in_stage * 100) : 0;
    } else {
        $current_installment = $res['installments_paid_count'] + 1;
        $stage = "INSTALLMENT #$current_installment";
        $stage_class = ($current_installment >= $res['payment_periods'] - 2) ? 'almost-done' : 'installment';
        $stage_icon = 'fa-list-ol';
        $needed = $res['current_installment_balance'];
        $paid_in_stage = $res['installment_amount'] - $needed;
        $total_in_stage = $res['installment_amount'];
        $progress = ($total_in_stage > 0) ? ($paid_in_stage / $total_in_stage * 100) : 0;
    }
    
    $overall_progress = ($res['total_amount'] > 0) ? (($res['total_paid'] / $res['total_amount']) * 100) : 0;
?>

<div class="reservation-card" onclick='selectReservation(<?php echo json_encode($res, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
    <h6 class="mb-2"><strong><?php echo htmlspecialchars($res['reservation_number']); ?></strong></h6>
    <p class="mb-1 small"><i class="fas fa-user"></i> <?php echo htmlspecialchars($res['customer_name']); ?></p>
    <p class="mb-2 small"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($res['project_name']); ?> - Plot <?php echo htmlspecialchars($res['plot_number']); ?></p>
    
    <span class="stage-badge <?php echo $stage_class; ?>">
        <i class="fas <?php echo $stage_icon; ?>"></i> <?php echo $stage; ?>
    </span>
    
    <div class="progress-container">
        <div class="progress">
            <div class="progress-bar bg-success" style="width:<?php echo min(100, $progress); ?>%">
                <?php echo number_format($progress, 1); ?>%
            </div>
        </div>
    </div>
    
    <div class="stage-info">
        <div class="stage-info-row">
            <span class="stage-info-label">Paid:</span>
            <span class="stage-info-value">TZS <?php echo number_format($paid_in_stage, 2); ?></span>
        </div>
        <div class="stage-info-row">
            <span class="stage-info-label">Balance:</span>
            <span class="stage-info-value">TZS <?php echo number_format($needed, 2); ?></span>
        </div>
        <div class="stage-info-row">
            <span class="stage-info-label">Expected:</span>
            <span class="stage-info-value">TZS <?php echo number_format($total_in_stage, 2); ?></span>
        </div>
    </div>
    
    <?php if (strpos($res['payment_stage'], 'installment') !== false): ?>
    <div class="mt-2">
        <small class="text-muted">
            <i class="fas fa-chart-line"></i> Overall: <?php echo $res['installments_paid_count']; ?> of <?php echo $res['payment_periods']; ?> installments (<?php echo number_format($overall_progress, 1); ?>%)
        </small>
    </div>
    <?php endif; ?>
</div>

<?php endforeach; ?>
<?php endif; ?>

</div>

<div class="col-md-8">
<div class="payment-form">
<h5 class="mb-4"><i class="fas fa-money-check-alt"></i> Payment Details</h5>

<form method="POST" id="paymentForm">
<input type="hidden" name="reservation_id" id="reservation_id" required>
<input type="hidden" name="to_account_id" id="hidden_to_account_id" value="">

<div id="currentStageDisplay" style="display:none" class="current-stage-display">
    <h4 id="stageTitle">Current Stage</h4>
    <div class="stage-detail">
        <strong>Balance Needed:</strong> <span id="stageBalance">TZS 0.00</span>
    </div>
    <div class="stage-detail">
        <strong>You can pay:</strong> Any amount (partial or full payment accepted)
    </div>
</div>

<div class="form-section">
<div class="form-section-title"><i class="fas fa-info-circle me-2"></i>Selected Reservation</div>
<div id="selectedReservationInfo">
<p class="text-muted">Please select a reservation from the list</p>
</div>
</div>

<div class="form-section">
<div class="form-section-title"><i class="fas fa-calendar me-2"></i>Payment Information</div>
<div class="row">
<div class="col-md-6 mb-3">
<label class="form-label">Payment Date<span class="text-danger">*</span></label>
<input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
</div>
<div class="col-md-6 mb-3">
<label class="form-label">Amount (TZS)<span class="text-danger">*</span></label>
<input type="number" name="amount" id="amount" class="form-control" step="0.01" required placeholder="Enter any amount">
<small class="form-text text-muted">You can pay any amount - partial or full</small>
</div>
<div class="col-md-12 mb-3">
<label class="form-label">Payment Method<span class="text-danger">*</span></label>
<select name="payment_method" id="payment_method" class="form-control" required>
<option value="">-- Select Method --</option>
<option value="cash">Cash</option>
<option value="bank_transfer">Bank Transfer</option>
<option value="bank_deposit">Bank Deposit</option>
<option value="mobile_money">Mobile Money</option>
<option value="cheque">Cheque</option>
</select>
</div>
<div class="col-md-12 mb-3">
<label class="form-label">Transaction Reference</label>
<input type="text" name="transaction_reference" class="form-control" placeholder="Optional">
</div>
</div>
</div>

<!-- Cash Fields -->
<div id="cash_fields" class="payment-method-fields">
<h6 class="mb-3"><i class="fas fa-money-bill-wave me-2"></i> Cash Payment Details</h6>
<div class="row">
<div class="col-md-12">
<div class="form-group">
<label>Received By<span class="text-danger">*</span></label>
<input type="text" name="received_by" class="form-control" placeholder="Name of person receiving cash">
</div>
</div>
</div>
</div>

<!-- Bank Transfer -->
<div id="transfer_fields" class="payment-method-fields">
<h6 class="mb-3"><i class="fas fa-exchange-alt me-2"></i> Bank Transfer Details</h6>

<div class="client-bank-section">
<h6 class="mb-3 text-primary"><i class="fas fa-user me-2"></i>Client Bank Account (From)</h6>
<div class="row">
<div class="col-md-4">
<div class="form-group">
<label>Bank Name</label>
<input type="text" name="client_bank_name" class="form-control" placeholder="e.g., CRDB Bank">
</div>
</div>
<div class="col-md-4">
<div class="form-group">
<label>Account Number</label>
<input type="text" name="client_account_number" class="form-control" placeholder="e.g., 0150123456789">
</div>
</div>
<div class="col-md-4">
<div class="form-group">
<label>Account Name</label>
<input type="text" name="client_account_name" class="form-control" placeholder="Account holder name">
</div>
</div>
</div>
</div>

<div class="company-account-section">
<h6 class="mb-3 text-success"><i class="fas fa-building me-2"></i>Company Bank Account (To)</h6>
<div class="row">
<div class="col-md-12">
<div class="form-group">
<label>Receive To Account</label>
<select id="transfer_to_account" class="form-control account-selector">
<option value="">-- Select Company Bank Account (Optional) --</option>
<?php foreach ($bank_accounts as $account): ?>
<option value="<?php echo $account['bank_account_id']; ?>">
<?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_name']); ?>
| A/C: <?php echo htmlspecialchars($account['account_number']); ?>
| Balance: TZS <?php echo number_format($account['current_balance'], 2); ?>
</option>
<?php endforeach; ?>
</select>
<small class="form-text text-muted">Payment amount will be added to this account upon approval</small>
</div>
</div>
</div>
</div>
</div>

<!-- Bank Deposit -->
<div id="deposit_fields" class="payment-method-fields">
<h6 class="mb-3"><i class="fas fa-building me-2"></i> Bank Deposit Details</h6>
<div class="row">
<div class="col-md-12">
<div class="form-group">
<label>Deposit To Account (Company)</label>
<select id="deposit_to_account" class="form-control account-selector">
<option value="">-- Select Company Bank Account (Optional) --</option>
<?php foreach ($bank_accounts as $account): ?>
<option value="<?php echo $account['bank_account_id']; ?>">
<?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_name']); ?>
| A/C: <?php echo htmlspecialchars($account['account_number']); ?>
| Balance: TZS <?php echo number_format($account['current_balance'], 2); ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="col-md-6">
<div class="form-group">
<label>Depositor Name</label>
<input type="text" name="depositor_name" class="form-control" placeholder="Name of person making deposit">
</div>
</div>
<div class="col-md-6">
<div class="form-group">
<label>Deposit Bank</label>
<input type="text" name="deposit_bank" class="form-control" placeholder="e.g., CRDB Bank">
</div>
</div>
<div class="col-md-12">
<div class="form-group">
<label>Deposit Slip Number</label>
<input type="text" name="deposit_account" class="form-control" placeholder="Deposit slip reference number">
</div>
</div>
</div>
</div>

<!-- Mobile Money -->
<div id="mobile_money_fields" class="payment-method-fields">
<h6 class="mb-3"><i class="fas fa-mobile-alt me-2"></i> Mobile Money Details</h6>
<div class="row">
<div class="col-md-4">
<div class="form-group">
<label>Provider</label>
<select name="mobile_money_provider" class="form-control">
<option value="">-- Select Provider --</option>
<option value="M-Pesa">M-Pesa (Vodacom)</option>
<option value="Tigo Pesa">Tigo Pesa</option>
<option value="Airtel Money">Airtel Money</option>
<option value="Halopesa">Halopesa (Halotel)</option>
<option value="T-Pesa">T-Pesa (TTCL)</option>
</select>
</div>
</div>
<div class="col-md-4">
<div class="form-group">
<label>Mobile Money Number</label>
<input type="text" name="mobile_money_number" class="form-control" placeholder="e.g., 0755123456">
</div>
</div>
<div class="col-md-4">
<div class="form-group">
<label>Account Holder Name</label>
<input type="text" name="mobile_money_name" class="form-control" placeholder="Name registered on mobile money">
</div>
</div>
<div class="col-md-12">
<div class="form-group">
<label>Receive To Account (Company)</label>
<select id="mobile_to_account" class="form-control account-selector">
<option value="">-- Select Company Mobile Money Account (Optional) --</option>
<?php foreach ($mobile_accounts as $account): ?>
<option value="<?php echo $account['bank_account_id']; ?>">
<?php echo htmlspecialchars($account['mobile_provider'] . ' - ' . $account['account_name']); ?>
| <?php echo htmlspecialchars($account['mobile_number']); ?>
| Balance: TZS <?php echo number_format($account['current_balance'], 2); ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
</div>
</div>

<!-- Cheque -->
<div id="cheque_fields" class="payment-method-fields">
<h6 class="mb-3"><i class="fas fa-money-check me-2"></i> Cheque Details</h6>
<div class="row">
<div class="col-md-4">
<div class="form-group">
<label>Cheque Number<span class="text-danger">*</span></label>
<input type="text" name="cheque_number" class="form-control" placeholder="e.g., 123456">
</div>
</div>
<div class="col-md-4">
<div class="form-group">
<label>Cheque Date<span class="text-danger">*</span></label>
<input type="date" name="cheque_date" class="form-control">
</div>
</div>
<div class="col-md-4">
<div class="form-group">
<label>Cheque Bank<span class="text-danger">*</span></label>
<input type="text" name="cheque_bank" class="form-control" placeholder="e.g., CRDB Bank">
</div>
</div>
<div class="col-md-6">
<div class="form-group">
<label>Branch Name</label>
<input type="text" name="cheque_branch" class="form-control" placeholder="Bank branch">
</div>
</div>
<div class="col-md-6">
<div class="form-group">
<label>Payee Name</label>
<input type="text" name="cheque_payee" class="form-control" placeholder="Name on cheque">
</div>
</div>
</div>
</div>

<div class="form-section">
<label class="form-label">Remarks</label>
<textarea name="remarks" class="form-control" rows="3"></textarea>
</div>

<button type="submit" class="btn btn-primary btn-lg">
<i class="fas fa-check"></i> Submit Payment for Approval
</button>
</form>

</div>
</div>

</div>

</div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const accountSelectors = document.querySelectorAll('.account-selector');
    accountSelectors.forEach(function(selector) {
        selector.addEventListener('change', function() {
            const selectedAccountId = this.value;
            document.getElementById('hidden_to_account_id').value = selectedAccountId;
        });
    });
});

function selectReservation(res) {
    document.getElementById('reservation_id').value = res.reservation_id;
    
    // Determine current stage
    let stageTitle, stageBalance, stageIcon;
    if (res.payment_stage.includes('down_payment')) {
        stageTitle = '💰 DOWN PAYMENT STAGE';
        stageBalance = res.down_payment_balance;
        stageIcon = 'fa-arrow-down';
    } else {
        const currentInst = res.installments_paid_count + 1;
        stageTitle = '📋 INSTALLMENT #' + currentInst + ' of ' + res.payment_periods;
        stageBalance = res.current_installment_balance;
        stageIcon = 'fa-list-ol';
    }
    
    // Show current stage
    document.getElementById('currentStageDisplay').style.display = 'block';
    document.getElementById('stageTitle').innerHTML = '<i class="fas ' + stageIcon + '"></i> ' + stageTitle;
    document.getElementById('stageBalance').textContent = 'TZS ' + parseFloat(stageBalance).toLocaleString('en-US', {minimumFractionDigits: 2});
    
    // Set suggested amount (but allow any amount)
    document.getElementById('amount').value = parseFloat(stageBalance).toFixed(2);
    document.getElementById('amount').placeholder = 'Enter any amount (TZS ' + parseFloat(stageBalance).toLocaleString() + ' needed)';
    
    const info = `
        <div class="alert alert-success">
        <h6><strong>${res.reservation_number}</strong></h6>
        <p class="mb-1"><strong>Customer:</strong> ${res.customer_name}</p>
        <p class="mb-1"><strong>Plot:</strong> ${res.project_name} - Plot ${res.plot_number}</p>
        <p class="mb-1"><strong>Total Amount:</strong> TZS ${parseFloat(res.total_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</p>
        <p class="mb-1"><strong>Total Paid:</strong> TZS ${parseFloat(res.total_paid).toLocaleString('en-US', {minimumFractionDigits: 2})}</p>
        <p class="mb-0"><strong>Overall Balance:</strong> TZS ${parseFloat(res.overall_balance).toLocaleString('en-US', {minimumFractionDigits: 2})}</p>
        </div>
    `;
    
    document.getElementById('selectedReservationInfo').innerHTML = info;
    
    document.querySelectorAll('.reservation-card').forEach(card => card.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
}

document.getElementById('payment_method').addEventListener('change', function() {
    document.querySelectorAll('.payment-method-fields').forEach(field => field.classList.remove('active'));
    
    document.getElementById('hidden_to_account_id').value = '';
    document.querySelectorAll('.account-selector').forEach(s => s.value = '');
    
    const method = this.value;
    if (method === 'cash') document.getElementById('cash_fields').classList.add('active');
    if (method === 'bank_transfer') document.getElementById('transfer_fields').classList.add('active');
    if (method === 'bank_deposit') document.getElementById('deposit_fields').classList.add('active');
    if (method === 'mobile_money') document.getElementById('mobile_money_fields').classList.add('active');
    if (method === 'cheque') document.getElementById('cheque_fields').classList.add('active');
});
</script>

<?php require_once '../../includes/footer.php'; ?>