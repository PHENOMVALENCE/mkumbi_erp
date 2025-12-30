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
$user_id = $_SESSION['user_id'];

/**
 * Update project plot counts
 */
function updateProjectCounts($conn, $plot_id, $company_id) {
    try {
        $project_sql = "SELECT project_id FROM plots WHERE plot_id = ? AND company_id = ?";
        $project_stmt = $conn->prepare($project_sql);
        $project_stmt->execute([$plot_id, $company_id]);
        $project_id = $project_stmt->fetchColumn();
        
        if ($project_id) {
            $counts_sql = "SELECT 
                              SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                              SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved,
                              SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold
                          FROM plots WHERE project_id = ? AND company_id = ?";
            
            $counts_stmt = $conn->prepare($counts_sql);
            $counts_stmt->execute([$project_id, $company_id]);
            $counts = $counts_stmt->fetch(PDO::FETCH_ASSOC);
            
            $update_project_sql = "UPDATE projects 
                                  SET available_plots = ?,
                                      reserved_plots = ?,
                                      sold_plots = ?,
                                      updated_at = NOW() 
                                  WHERE project_id = ? AND company_id = ?";
            
            $update_project_stmt = $conn->prepare($update_project_sql);
            $update_project_stmt->execute([
                $counts['available'] ?? 0,
                $counts['reserved'] ?? 0,
                $counts['sold'] ?? 0,
                $project_id,
                $company_id
            ]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating project counts: " . $e->getMessage());
        return false;
    }
}

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $record_id = intval($_POST['record_id']);
    $approval_type = $_POST['approval_type'];
    
    try {
        $conn->beginTransaction();
        
        if ($action === 'approve') {
            
            if ($approval_type === 'payment') {
                
                // Get payment details with reservation
                $payment_sql = "SELECT p.*, r.* 
                                FROM payments p
                                JOIN reservations r ON p.reservation_id = r.reservation_id AND p.company_id = r.company_id
                                WHERE p.payment_id = ? AND p.company_id = ?";
                $payment_stmt = $conn->prepare($payment_sql);
                $payment_stmt->execute([$record_id, $company_id]);
                $payment = $payment_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$payment) {
                    throw new Exception("Payment not found");
                }
                
                // 1. Update payment status
                $update_payment = "UPDATE payments 
                                  SET status = 'approved',
                                      approved_by = ?,
                                      approved_at = NOW(),
                                      receipt_generated_at = NOW()
                                  WHERE payment_id = ? AND company_id = ?";
                $stmt = $conn->prepare($update_payment);
                $stmt->execute([$user_id, $record_id, $company_id]);
                
                // 2. Credit account if selected
                if (!empty($payment['to_account_id'])) {
                    $update_account = "UPDATE bank_accounts 
                                      SET current_balance = current_balance + ?,
                                          updated_at = NOW()
                                      WHERE bank_account_id = ? AND company_id = ?";
                    $acc_stmt = $conn->prepare($update_account);
                    $acc_stmt->execute([
                        $payment['amount'],
                        $payment['to_account_id'],
                        $company_id
                    ]);
                }
                
                // 3. Update reservation payment stage
                if ($payment['payment_stage'] === 'down_payment') {
                    
                    // DOWN PAYMENT STAGE
                    $new_paid = $payment['down_payment_paid'] + $payment['amount'];
                    $new_balance = $payment['down_payment'] - $new_paid;
                    
                    if ($new_balance <= 0) {
                        // Down payment complete! Move to installments
                        $new_stage = 'paying_installments';
                        $message_detail = "Down payment COMPLETED! Customer can now start paying installments.";
                    } else {
                        $new_stage = 'paying_down_payment';
                        $message_detail = "Down payment in progress. Balance: TZS " . number_format($new_balance, 2);
                    }
                    
                    $update_res = "UPDATE reservations 
                                  SET down_payment_paid = ?,
                                      down_payment_balance = ?,
                                      payment_stage = ?
                                  WHERE reservation_id = ? AND company_id = ?";
                    $res_stmt = $conn->prepare($update_res);
                    $res_stmt->execute([
                        $new_paid,
                        max(0, $new_balance),
                        $new_stage,
                        $payment['reservation_id'],
                        $company_id
                    ]);
                    
                } else {
                    
                    // INSTALLMENT STAGE
                    // Get total paid in installments so far (including this one)
                    $total_inst_paid_sql = "SELECT COALESCE(SUM(amount), 0) 
                                           FROM payments 
                                           WHERE reservation_id = ? 
                                           AND company_id = ?
                                           AND payment_stage = 'installment' 
                                           AND status = 'approved'";
                    $total_inst_stmt = $conn->prepare($total_inst_paid_sql);
                    $total_inst_stmt->execute([$payment['reservation_id'], $company_id]);
                    $total_inst_paid = floatval($total_inst_stmt->fetchColumn());
                    
                    // Calculate installments complete
                    $installments_complete = floor($total_inst_paid / $payment['installment_amount']);
                    
                    if ($installments_complete >= $payment['payment_periods']) {
                        // All installments paid! COMPLETE!
                        $new_stage = 'completed';
                        
                        $update_res_status = "UPDATE reservations 
                                             SET status = 'completed', 
                                                 completed_at = NOW(),
                                                 installments_paid_count = ?,
                                                 last_installment_date = NOW(),
                                                 payment_stage = ?
                                             WHERE reservation_id = ? AND company_id = ?";
                        $res_status_stmt = $conn->prepare($update_res_status);
                        $res_status_stmt->execute([
                            $installments_complete,
                            $new_stage,
                            $payment['reservation_id'],
                            $company_id
                        ]);
                        
                        // Update plot to SOLD
                        $update_plot = "UPDATE plots 
                                       SET status = 'sold', updated_at = NOW() 
                                       WHERE plot_id = ? AND company_id = ?";
                        $plot_stmt = $conn->prepare($update_plot);
                        $plot_stmt->execute([$payment['plot_id'], $company_id]);
                        
                        updateProjectCounts($conn, $payment['plot_id'], $company_id);
                        
                        $message_detail = "ALL PAYMENTS COMPLETE! Reservation completed and plot marked as SOLD.";
                        
                    } else {
                        $new_stage = 'paying_installments';
                        
                        $update_res = "UPDATE reservations 
                                      SET installments_paid_count = ?,
                                          last_installment_date = NOW(),
                                          payment_stage = ?
                                      WHERE reservation_id = ? AND company_id = ?";
                        $res_stmt = $conn->prepare($update_res);
                        $res_stmt->execute([
                            $installments_complete,
                            $new_stage,
                            $payment['reservation_id'],
                            $company_id
                        ]);
                        
                        $message_detail = "Installment #" . $installments_complete . " completed. " . 
                                        ($payment['payment_periods'] - $installments_complete) . 
                                        " installments remaining.";
                    }
                }
                
                $conn->commit();
                
                $_SESSION['success'] = "<strong>Payment Approved Successfully!</strong><br>" .
                    "Amount: TZS " . number_format($payment['amount'], 2) . "<br>" .
                    ($payment['to_account_id'] ? "Account credited successfully<br>" : "") .
                    $message_detail;
                
            }
            
        } elseif ($action === 'reject') {
            
            $rejection_reason = $_POST['rejection_reason'] ?? 'No reason provided';
            
            if ($approval_type === 'payment') {
                
                $update_sql = "UPDATE payments 
                              SET status = 'rejected',
                                  rejected_by = ?,
                                  rejected_at = NOW(),
                                  rejection_reason = ?
                              WHERE payment_id = ? AND company_id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->execute([$user_id, $rejection_reason, $record_id, $company_id]);
                
                $conn->commit();
                $_SESSION['success'] = "Payment rejected: " . htmlspecialchars($rejection_reason);
                
            }
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
        error_log("Approval error: " . $e->getMessage());
    }
    
    header("Location: pending.php");
    exit;
}

// Fetch pending payments with stage information
try {
    $payments_sql = "SELECT p.*,
                            r.reservation_number,
                            r.total_amount as reservation_total,
                            r.payment_stage as current_payment_stage,
                            r.down_payment,
                            r.down_payment_paid,
                            r.down_payment_balance,
                            r.installment_amount,
                            r.installments_paid_count,
                            r.payment_periods,
                            c.full_name as customer_name,
                            pl.plot_number,
                            pr.project_name,
                            ba.account_name as to_account_name,
                            ba.bank_name as to_bank_name,
                            u.full_name as submitted_by_name,
                            DATEDIFF(NOW(), p.submitted_at) as days_pending,
                            (r.total_amount - COALESCE((SELECT SUM(amount) FROM payments 
                              WHERE reservation_id = r.reservation_id AND company_id = r.company_id 
                              AND status = 'approved'), 0)) as current_outstanding
                     FROM payments p
                     JOIN reservations r ON p.reservation_id = r.reservation_id AND p.company_id = r.company_id
                     JOIN customers c ON r.customer_id = c.customer_id AND r.company_id = c.company_id
                     JOIN plots pl ON r.plot_id = pl.plot_id AND r.company_id = pl.company_id
                     JOIN projects pr ON pl.project_id = pr.project_id AND pl.company_id = pr.company_id
                     LEFT JOIN bank_accounts ba ON p.to_account_id = ba.bank_account_id AND p.company_id = ba.company_id
                     LEFT JOIN users u ON p.submitted_by = u.user_id
                     WHERE p.company_id = ? AND p.status = 'pending_approval'
                     ORDER BY p.submitted_at ASC";
    
    $payments_stmt = $conn->prepare($payments_sql);
    $payments_stmt->execute([$company_id]);
    $pending_payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $pending_payments = [];
    error_log("Error fetching payments: " . $e->getMessage());
}

$page_title = 'Pending Approvals';
require_once '../../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<style>
.stats-card{background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.1);transition:all .3s}
.stats-card:hover{transform:translateY(-5px);box-shadow:0 5px 15px rgba(0,0,0,.15)}
.stats-card .number{font-size:36px;font-weight:700;color:#667eea}
.stats-card .label{font-size:14px;color:#6c757d;text-transform:uppercase}
.badge-pending{background:#fff3cd;color:#856404;padding:4px 8px;border-radius:20px;font-size:11px}
.badge-urgent{background:#f8d7da;color:#721c24;padding:4px 8px;border-radius:20px;font-size:11px;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
.table-container{background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.workflow-box{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:20px;border-radius:10px;margin-bottom:20px}
.workflow-box h5{color:#fff;margin-bottom:15px}
.workflow-box ul{margin:0;padding-left:20px}
.workflow-box li{margin-bottom:8px}
.info-badge{background:#e7f3ff;color:#004085;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:600}
.btn-approve{background:#28a745;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:13px}
.btn-approve:hover{background:#218838;color:#fff}
.btn-reject{background:#dc3545;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:13px}
.btn-reject:hover{background:#c82333;color:#fff}
.stage-info-box{background:#f8f9fa;padding:10px;border-radius:6px;margin-top:5px;border-left:3px solid #007bff}
.stage-info-box.down-payment{border-left-color:#dc3545}
.stage-info-box.installment{border-left-color:#ffc107}
</style>

<div class="content-header">
<div class="container-fluid">
<div class="row mb-2">
<div class="col-sm-6"><h1><i class="fas fa-check-double"></i> Pending Approvals</h1></div>
<div class="col-sm-6">
<ol class="breadcrumb float-sm-right">
<li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
<li class="breadcrumb-item active">Approvals</li>
</ol>
</div>
</div>
</div>
</div>

<section class="content">
<div class="container-fluid">

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
<i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show">
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
<i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<!-- Workflow Information -->
<div class="workflow-box">
<h5><i class="fas fa-info-circle"></i> Payment Approval Workflow</h5>
<p style="margin-bottom:10px">When you approve a payment, the following happens automatically:</p>
<ul>
<li><strong>Payment Status:</strong> Changes to "Approved"</li>
<li><strong>Account Balance:</strong> Payment amount is added to the selected company account (if selected)</li>
<li><strong>Receipt:</strong> Receipt number is finalized and timestamped</li>
<li><strong>Payment Stage Updates:</strong>
<ul>
<li>Down Payment: Updates paid amount and balance. Moves to installments when complete.</li>
<li>Installments: Increments installment count. Completes reservation when all paid.</li>
</ul>
</li>
<li><strong>Plot Status:</strong> Updates to SOLD when reservation is fully paid</li>
<li><strong>Project Counts:</strong> Recalculates available/reserved/sold plot counts</li>
</ul>
</div>

<!-- Statistics -->
<div class="row mb-4">
<div class="col-lg-3 col-6">
<div class="stats-card">
<div class="number"><?php echo count($pending_payments); ?></div>
<div class="label">Pending Payments</div>
</div>
</div>
</div>

<!-- Payments Table -->
<div class="table-container">
<h5 class="mb-3"><i class="fas fa-money-bill-wave"></i> Pending Payments</h5>

<?php if (empty($pending_payments)): ?>
<p class="text-center text-muted" style="padding:40px">
<i class="fas fa-check-circle fa-3x mb-3"></i><br>
No pending payments
</p>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th>Submitted</th>
<th>Payment #</th>
<th>Customer / Reservation</th>
<th>Stage</th>
<th>Amount</th>
<th>To Account</th>
<th>Receipt #</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($pending_payments as $payment): 
    // Determine stage display
    if ($payment['payment_stage'] === 'down_payment') {
        $stage_display = '💰 Down Payment';
        $stage_class = 'down-payment';
        $stage_detail = 'Paid: TZS ' . number_format($payment['down_payment_paid'], 2) . 
                       ' / TZS ' . number_format($payment['down_payment'], 2);
    } else {
        $inst_num = $payment['installment_number'] ?? ($payment['installments_paid_count'] + 1);
        $stage_display = '📋 Installment #' . $inst_num;
        $stage_class = 'installment';
        $stage_detail = $payment['installments_paid_count'] . ' of ' . 
                       $payment['payment_periods'] . ' completed';
    }
?>
<tr>
<td>
<?php echo date('d M Y', strtotime($payment['submitted_at'])); ?>
<?php if ($payment['days_pending'] > 3): ?>
<br><span class="badge-urgent">Urgent!</span>
<?php endif; ?>
</td>
<td><strong><?php echo htmlspecialchars($payment['payment_number']); ?></strong></td>
<td>
<?php echo htmlspecialchars($payment['customer_name']); ?><br>
<small class="text-muted"><?php echo htmlspecialchars($payment['reservation_number']); ?></small>
</td>
<td>
<strong><?php echo $stage_display; ?></strong>
<div class="stage-info-box <?php echo $stage_class; ?>">
<small><?php echo $stage_detail; ?></small>
<?php if ($payment['is_partial']): ?>
<br><small><i class="fas fa-info-circle"></i> Partial Payment</small>
<?php endif; ?>
</div>
</td>
<td>
<strong>TZS <?php echo number_format($payment['amount'], 2); ?></strong>
<?php if ($payment['expected_amount']): ?>
<br><small class="text-muted">Expected: TZS <?php echo number_format($payment['expected_amount'], 2); ?></small>
<?php endif; ?>
</td>
<td>
<?php if ($payment['to_account_name']): ?>
<?php echo htmlspecialchars($payment['to_bank_name'] . ' - ' . $payment['to_account_name']); ?>
<br><span class="info-badge">Will be credited</span>
<?php else: ?>
<span class="text-muted">No account</span>
<?php endif; ?>
</td>
<td>
<small><?php echo htmlspecialchars($payment['receipt_number']); ?></small><br>
<small class="text-muted">Pending</small>
</td>
<td>
<button class="btn btn-sm btn-info" onclick='viewPaymentDetails(<?php echo json_encode($payment, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
<i class="fas fa-eye"></i>
</button>
<button class="btn btn-sm btn-approve" onclick="approvePayment(<?php echo $payment['payment_id']; ?>, '<?php echo htmlspecialchars($payment['payment_number'], ENT_QUOTES); ?>', '<?php echo $stage_display; ?>')">
<i class="fas fa-check"></i>
</button>
<button class="btn btn-sm btn-reject" onclick="rejectPayment(<?php echo $payment['payment_id']; ?>, '<?php echo htmlspecialchars($payment['payment_number'], ENT_QUOTES); ?>')">
<i class="fas fa-times"></i>
</button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

</div>

</div>
</section>

<!-- Payment Details Modal -->
<div class="modal fade" id="paymentDetailsModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header bg-info text-white">
<h5 class="modal-title"><i class="fas fa-file-invoice"></i> Payment Details</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" id="paymentDetailsContent">
<!-- Content populated by JS -->
</div>
</div>
</div>
</div>

<!-- Approval Modal -->
<div class="modal fade" id="approvalModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-success text-white">
<h5 class="modal-title"><i class="fas fa-check-circle"></i> Approve Payment</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST">
<div class="modal-body">
<input type="hidden" name="action" value="approve">
<input type="hidden" name="record_id" id="approval_record_id">
<input type="hidden" name="approval_type" value="payment">

<div class="alert alert-info">
<i class="fas fa-info-circle"></i> You are about to approve <strong id="approvalPaymentNumber"></strong>
</div>

<div id="approvalStageInfo" class="alert alert-warning">
<!-- Populated by JS -->
</div>

<h6>What will happen:</h6>
<ul id="approvalActionsList">
<!-- Populated by JS -->
</ul>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-success">
<i class="fas fa-check"></i> Confirm Approval
</button>
</div>
</form>
</div>
</div>
</div>

<!-- Rejection Modal -->
<div class="modal fade" id="rejectionModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-danger text-white">
<h5 class="modal-title"><i class="fas fa-times-circle"></i> Reject Payment</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST">
<div class="modal-body">
<input type="hidden" name="action" value="reject">
<input type="hidden" name="record_id" id="rejection_record_id">
<input type="hidden" name="approval_type" value="payment">

<div class="alert alert-warning">
<i class="fas fa-exclamation-triangle"></i> You are about to reject <strong id="rejectionPaymentNumber"></strong>
</div>

<div class="mb-3">
<label class="form-label">Rejection Reason <span class="text-danger">*</span></label>
<textarea name="rejection_reason" class="form-control" rows="4" required placeholder="Please provide a detailed reason for rejection..."></textarea>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-danger">
<i class="fas fa-times"></i> Confirm Rejection
</button>
</div>
</form>
</div>
</div>
</div>

<script>
if (typeof bootstrap === 'undefined') {
    console.error('Bootstrap not loaded!');
}

function viewPaymentDetails(data) {
    let stageInfo = '';
    if (data.payment_stage === 'down_payment') {
        stageInfo = `
            <div class="alert alert-danger">
                <h6>💰 Down Payment Stage</h6>
                <p><strong>Currently Paid:</strong> TZS ${parseFloat(data.down_payment_paid || 0).toLocaleString()}</p>
                <p><strong>Total Required:</strong> TZS ${parseFloat(data.down_payment || 0).toLocaleString()}</p>
                <p><strong>Balance After Approval:</strong> TZS ${parseFloat((data.down_payment || 0) - (data.down_payment_paid || 0) - data.amount).toLocaleString()}</p>
            </div>
        `;
    } else {
        const instNum = data.installment_number || (data.installments_paid_count + 1);
        stageInfo = `
            <div class="alert alert-warning">
                <h6>📋 Installment #${instNum} of ${data.payment_periods}</h6>
                <p><strong>Completed:</strong> ${data.installments_paid_count} installments</p>
                <p><strong>Expected Amount:</strong> TZS ${parseFloat(data.installment_amount || 0).toLocaleString()}</p>
                ${data.is_partial ? '<p><i class="fas fa-info-circle"></i> This is a partial payment</p>' : ''}
            </div>
        `;
    }

    let html = `
        ${stageInfo}
        <div class="row">
            <div class="col-md-6">
                <h6>Payment Information</h6>
                <table class="table table-sm">
                    <tr><td><strong>Payment Number:</strong></td><td>${data.payment_number}</td></tr>
                    <tr><td><strong>Receipt Number:</strong></td><td>${data.receipt_number}</td></tr>
                    <tr><td><strong>Amount:</strong></td><td>TZS ${parseFloat(data.amount).toLocaleString()}</td></tr>
                    <tr><td><strong>Payment Date:</strong></td><td>${data.payment_date}</td></tr>
                    <tr><td><strong>Method:</strong></td><td>${data.payment_method}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6>Customer & Reservation</h6>
                <table class="table table-sm">
                    <tr><td><strong>Customer:</strong></td><td>${data.customer_name}</td></tr>
                    <tr><td><strong>Reservation:</strong></td><td>${data.reservation_number}</td></tr>
                    <tr><td><strong>Plot:</strong></td><td>${data.plot_number}</td></tr>
                    <tr><td><strong>Project:</strong></td><td>${data.project_name}</td></tr>
                </table>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <h6>Account Information</h6>
                <div class="alert alert-info">
                    ${data.to_account_name ? 
                        `<strong>Will be credited to:</strong> ${data.to_bank_name} - ${data.to_account_name}` : 
                        'No account selected for this payment'
                    }
                </div>
            </div>
        </div>
    `;

    document.getElementById('paymentDetailsContent').innerHTML = html;

    try {
        var modalElement = document.getElementById('paymentDetailsModal');
        var modal = new bootstrap.Modal(modalElement);
        modal.show();
    } catch (error) {
        console.error('Error opening modal:', error);
    }
}

function approvePayment(paymentId, paymentNumber, stage) {
    document.getElementById('approval_record_id').value = paymentId;
    document.getElementById('approvalPaymentNumber').textContent = paymentNumber;
    
    document.getElementById('approvalStageInfo').innerHTML = `<strong>Stage:</strong> ${stage}`;
    
    let actions = `
        <li>Payment status will change to <strong>Approved</strong></li>
        <li>Receipt number will be finalized</li>
        <li>Payment amount will be added to the selected account (if any)</li>
        <li>Payment stage will be updated automatically</li>
        <li>If stage is complete, system will move to next stage</li>
        <li>If all payments complete, reservation will be marked COMPLETED and plot will be marked SOLD</li>
    `;
    
    document.getElementById('approvalActionsList').innerHTML = actions;
    
    try {
        var modalElement = document.getElementById('approvalModal');
        var modal = new bootstrap.Modal(modalElement);
        modal.show();
    } catch (error) {
        console.error('Error opening approval modal:', error);
    }
}

function rejectPayment(paymentId, paymentNumber) {
    document.getElementById('rejection_record_id').value = paymentId;
    document.getElementById('rejectionPaymentNumber').textContent = paymentNumber;
    
    try {
        var modalElement = document.getElementById('rejectionModal');
        var modal = new bootstrap.Modal(modalElement);
        modal.show();
    } catch (error) {
        console.error('Error opening rejection modal:', error);
    }
}

// Auto-dismiss alerts
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert-success, .alert-danger');
    alerts.forEach(function(alert) {
        if (!alert.classList.contains('workflow-box')) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 500);
        }
    });
}, 5000);
</script>

<?php require_once '../../includes/footer.php'; ?>