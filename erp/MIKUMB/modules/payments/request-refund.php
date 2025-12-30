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
$success = [];

// Handle refund request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_refund') {
    $conn->beginTransaction();
    
    try {
        $payment_id = intval($_POST['payment_id']);
        $refund_amount = floatval($_POST['refund_amount']);
        $refund_reason = $_POST['refund_reason'];
        $refund_method = $_POST['refund_method'];
        $detailed_reason = trim($_POST['detailed_reason']);
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $penalty_amount = floatval($_POST['penalty_amount'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Validate inputs
        if (empty($payment_id) || empty($refund_amount) || empty($refund_reason) || empty($detailed_reason)) {
            $errors[] = "Payment, amount, reason, and detailed explanation are required";
        } elseif ($refund_amount <= 0) {
            $errors[] = "Refund amount must be greater than zero";
        } else {
            // Get payment details and verify reservation is cancelled
            $payment_sql = "SELECT p.*, r.reservation_id, r.customer_id, r.plot_id, r.status as reservation_status
                           FROM payments p
                           INNER JOIN reservations r ON p.reservation_id = r.reservation_id
                           WHERE p.payment_id = ? AND p.company_id = ?";
            $stmt = $conn->prepare($payment_sql);
            $stmt->execute([$payment_id, $company_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                $errors[] = "Payment not found";
            } elseif ($payment['reservation_status'] !== 'cancelled') {
                $errors[] = "Refunds can only be requested for cancelled reservations";
            } elseif ($refund_amount > $payment['amount']) {
                $errors[] = "Refund amount cannot exceed payment amount (TZS " . number_format($payment['amount'], 2) . ")";
            } else {
                // Generate refund number
                $refund_number = 'REF-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Insert refund request with PENDING status
                $insert_sql = "INSERT INTO refunds 
                              (company_id, reservation_id, customer_id, plot_id, refund_number, 
                               refund_date, refund_reason, original_payment_id, original_amount, 
                               refund_amount, penalty_amount, refund_method, bank_name, 
                               account_number, detailed_reason, remarks, 
                               status, created_by, created_at)
                              VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->execute([
                    $company_id,
                    $payment['reservation_id'],
                    $payment['customer_id'],
                    $payment['plot_id'],
                    $refund_number,
                    $refund_reason,
                    $payment_id,
                    $payment['amount'],
                    $refund_amount,
                    $penalty_amount,
                    $refund_method,
                    $bank_name,
                    $account_number,
                    $detailed_reason,
                    $remarks,
                    $_SESSION['user_id']
                ]);
                
                $conn->commit();
                
                $success[] = "Refund request submitted successfully! Refund Number: <strong>$refund_number</strong>. Waiting for approval.";
            }
        }
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $errors[] = "Error submitting refund request: " . $e->getMessage();
    }
}

// Fetch ONLY CANCELLED RESERVATIONS with payments that can be refunded
try {
    $payments_sql = "SELECT 
                        p.payment_id,
                        p.payment_number,
                        p.payment_date,
                        p.amount,
                        p.payment_method,
                        r.reservation_number,
                        r.status as reservation_status,
                        c.full_name as customer_name,
                        c.phone as customer_phone,
                        c.email as customer_email,
                        pl.plot_number,
                        pr.project_name,
                        rc.cancellation_date,
                        rc.cancellation_reason,
                        rc.detailed_reason as cancellation_details,
                        COALESCE(SUM(rf.refund_amount), 0) as total_refunded
                     FROM payments p
                     INNER JOIN reservations r ON p.reservation_id = r.reservation_id
                     INNER JOIN customers c ON r.customer_id = c.customer_id
                     INNER JOIN plots pl ON r.plot_id = pl.plot_id
                     INNER JOIN projects pr ON pl.project_id = pr.project_id
                     LEFT JOIN reservation_cancellations rc ON r.reservation_id = rc.reservation_id
                     LEFT JOIN refunds rf ON p.payment_id = rf.original_payment_id 
                         AND rf.status IN ('approved', 'processed')
                     WHERE r.status = 'cancelled'
                     AND p.company_id = ?
                     GROUP BY p.payment_id
                     HAVING (p.amount - COALESCE(SUM(rf.refund_amount), 0)) > 0
                     ORDER BY rc.cancellation_date DESC, p.payment_date DESC";
    
    $stmt = $conn->prepare($payments_sql);
    $stmt->execute([$company_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $payments = [];
    $errors[] = "Error fetching payments: " . $e->getMessage();
}

$page_title = 'Request Refund';
require_once '../../includes/header.php';
?>

<style>
.form-card {
    background: white;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.cancelled-reservation {
    background-color: #fff3cd;
    border-left: 4px solid #dc3545;
    padding: 10px;
    margin-bottom: 10px;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-undo"></i> Request Refund</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="../approvals/pending.php">Approvals</a></li>
                    <li class="breadcrumb-item active">Request Refund</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-ban"></i> Error!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-check-circle"></i> Success!</h5>
            <ul class="mb-0">
                <?php foreach ($success as $msg): ?>
                    <li><?php echo $msg; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (empty($payments)): ?>
        <div class="alert alert-info">
            <h5><i class="fas fa-info-circle"></i> No Cancelled Reservations</h5>
            <p class="mb-0">There are currently no cancelled reservations with payments available for refund. Refunds can only be requested for cancelled reservations.</p>
        </div>
        <?php else: ?>

        <div class="alert alert-warning cancelled-reservation">
            <h6 class="fw-bold"><i class="fas fa-exclamation-triangle"></i> Important Notice</h6>
            <p class="mb-0">Refunds are only available for <strong>CANCELLED RESERVATIONS</strong>. All payments listed below are from cancelled reservations.</p>
        </div>

        <div class="form-card">
            <form method="POST" id="requestRefundForm">
                <input type="hidden" name="action" value="request_refund">

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Note:</strong> Refund request will be submitted for approval before processing.
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Select Cancelled Reservation Payment <span class="text-danger">*</span></label>
                    <select name="payment_id" id="payment_id" class="form-select" required onchange="loadPaymentInfo()">
                        <option value="">-- Select Payment from Cancelled Reservation --</option>
                        <?php foreach ($payments as $payment): ?>
                            <option value="<?php echo $payment['payment_id']; ?>" 
                                    data-amount="<?php echo $payment['amount']; ?>"
                                    data-refunded="<?php echo $payment['total_refunded']; ?>"
                                    data-payment-number="<?php echo htmlspecialchars($payment['payment_number']); ?>"
                                    data-customer="<?php echo htmlspecialchars($payment['customer_name']); ?>"
                                    data-customer-phone="<?php echo htmlspecialchars($payment['customer_phone'] ?? 'N/A'); ?>"
                                    data-customer-email="<?php echo htmlspecialchars($payment['customer_email'] ?? 'N/A'); ?>"
                                    data-project="<?php echo htmlspecialchars($payment['project_name']); ?>"
                                    data-plot="<?php echo htmlspecialchars($payment['plot_number']); ?>"
                                    data-cancelled-date="<?php echo $payment['cancellation_date'] ? date('M d, Y', strtotime($payment['cancellation_date'])) : 'N/A'; ?>"
                                    data-cancellation-reason="<?php echo htmlspecialchars($payment['detailed_reason'] ?? $payment['cancellation_reason'] ?? 'Not specified'); ?>">
                                <?php echo htmlspecialchars($payment['payment_number']); ?> - 
                                <?php echo htmlspecialchars($payment['customer_name']); ?> - 
                                Plot <?php echo htmlspecialchars($payment['plot_number']); ?> - 
                                TZS <?php echo number_format($payment['amount'] - $payment['total_refunded'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Payment Details Display -->
                <div id="paymentDetails" style="display: none;" class="alert alert-light mb-3 border-danger">
                    <h6 class="fw-bold text-danger"><i class="fas fa-ban"></i> Cancelled Reservation Details</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Customer:</strong> <span id="detail_customer"></span></p>
                            <p class="mb-1"><strong>Phone:</strong> <span id="detail_phone"></span></p>
                            <p class="mb-1"><strong>Email:</strong> <span id="detail_email"></span></p>
                            <p class="mb-1"><strong>Project:</strong> <span id="detail_project"></span></p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Plot:</strong> <span id="detail_plot"></span></p>
                            <p class="mb-1"><strong>Cancelled Date:</strong> <span id="detail_cancelled_date" class="text-danger"></span></p>
                            <p class="mb-1"><strong>Cancellation Reason:</strong> <span id="detail_cancellation_reason"></span></p>
                            <p class="mb-1"><strong>Available Amount:</strong> <span id="detail_available" class="text-success fw-bold"></span></p>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Refund Amount <span class="text-danger">*</span></label>
                            <input type="number" name="refund_amount" id="refund_amount" class="form-control" 
                                   step="0.01" min="0" required placeholder="0.00" onkeyup="calculateNet()">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Penalty/Cancellation Fee</label>
                            <input type="number" name="penalty_amount" id="penalty_amount" class="form-control" 
                                   step="0.01" min="0" value="0" placeholder="0.00" onkeyup="calculateNet()">
                            <small class="text-muted">Deduction from refund (e.g., cancellation fee)</small>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Refund Reason <span class="text-danger">*</span></label>
                            <select name="refund_reason" id="refund_reason" class="form-select" required>
                                <option value="">-- Select Reason --</option>
                                <option value="cancellation" selected>Cancellation</option>
                                <option value="overpayment">Overpayment</option>
                                <option value="plot_unavailable">Plot Unavailable</option>
                                <option value="customer_request">Customer Request</option>
                                <option value="dispute">Dispute</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Refund Method <span class="text-danger">*</span></label>
                            <select name="refund_method" id="refund_method" class="form-select" required onchange="toggleBankFields()">
                                <option value="">-- Select Method --</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="cash">Cash</option>
                                <option value="mobile_money">Mobile Money (M-PESA, Tigo Pesa, Airtel Money)</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-12" id="bankFields" style="display: none;">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Bank Name / Mobile Network</label>
                                    <input type="text" name="bank_name" id="bank_name" class="form-control" placeholder="e.g., CRDB, NMB, M-PESA, Tigo Pesa">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Account Number / Phone Number</label>
                                    <input type="text" name="account_number" id="account_number" class="form-control" placeholder="Account or phone number">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Detailed Explanation <span class="text-danger">*</span></label>
                            <textarea name="detailed_reason" class="form-control" rows="3" required
                                      placeholder="Provide detailed explanation for this refund request..."></textarea>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Additional Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"
                                      placeholder="Any additional notes..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Net Refund Summary -->
                <div id="refundSummary" style="display: none;" class="alert alert-success">
                    <h6 class="fw-bold"><i class="fas fa-calculator"></i> Refund Summary</h6>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Refund Amount:</span>
                        <strong id="summary_refund">TZS 0</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Penalty/Cancellation Fee:</span>
                        <strong class="text-danger" id="summary_penalty">TZS 0</strong>
                    </div>
                    <div class="d-flex justify-content-between border-top pt-2">
                        <span class="fw-bold">Net Refund Amount:</span>
                        <strong class="text-success" id="summary_net">TZS 0</strong>
                    </div>
                </div>

                <div class="text-end">
                    <a href="../approvals/pending.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Submit Refund Request
                    </button>
                </div>
            </form>
        </div>

        <?php endif; ?>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let selectedPaymentAmount = 0;
let selectedRefundedAmount = 0;

function loadPaymentInfo() {
    const select = document.getElementById('payment_id');
    const selectedOption = select.options[select.selectedIndex];
    const detailsDiv = document.getElementById('paymentDetails');
    
    if (select.value) {
        selectedPaymentAmount = parseFloat(selectedOption.dataset.amount);
        selectedRefundedAmount = parseFloat(selectedOption.dataset.refunded);
        const availableAmount = selectedPaymentAmount - selectedRefundedAmount;
        
        document.getElementById('detail_customer').textContent = selectedOption.dataset.customer;
        document.getElementById('detail_phone').textContent = selectedOption.dataset.customerPhone;
        document.getElementById('detail_email').textContent = selectedOption.dataset.customerEmail;
        document.getElementById('detail_project').textContent = selectedOption.dataset.project;
        document.getElementById('detail_plot').textContent = 'Plot ' + selectedOption.dataset.plot;
        document.getElementById('detail_cancelled_date').textContent = selectedOption.dataset.cancelledDate;
        document.getElementById('detail_cancellation_reason').textContent = selectedOption.dataset.cancellationReason;
        document.getElementById('detail_available').textContent = 'TZS ' + availableAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
        
        detailsDiv.style.display = 'block';
        
        // Set max refund amount
        document.getElementById('refund_amount').max = availableAmount;
    } else {
        detailsDiv.style.display = 'none';
        selectedPaymentAmount = 0;
        selectedRefundedAmount = 0;
    }
}

function toggleBankFields() {
    const method = document.getElementById('refund_method').value;
    const bankFields = document.getElementById('bankFields');
    
    if (method === 'bank_transfer' || method === 'cheque' || method === 'mobile_money') {
        bankFields.style.display = 'block';
    } else {
        bankFields.style.display = 'none';
    }
}

function calculateNet() {
    const refundAmount = parseFloat(document.getElementById('refund_amount').value) || 0;
    const penaltyAmount = parseFloat(document.getElementById('penalty_amount').value) || 0;
    const availableAmount = selectedPaymentAmount - selectedRefundedAmount;
    
    if (refundAmount > 0) {
        if (refundAmount > availableAmount) {
            alert('Refund amount cannot exceed available amount: TZS ' + availableAmount.toLocaleString());
            document.getElementById('refund_amount').value = availableAmount.toFixed(2);
            return;
        }
        
        const netAmount = refundAmount - penaltyAmount;
        
        if (netAmount < 0) {
            alert('Penalty cannot exceed refund amount');
            document.getElementById('penalty_amount').value = '0';
            return;
        }
        
        document.getElementById('summary_refund').textContent = 'TZS ' + refundAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_penalty').textContent = 'TZS ' + penaltyAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_net').textContent = 'TZS ' + netAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
        
        document.getElementById('refundSummary').style.display = 'block';
    } else {
        document.getElementById('refundSummary').style.display = 'none';
    }
}

// Auto-dismiss alerts
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        bsAlert.close();
    });
}, 5000);
</script>