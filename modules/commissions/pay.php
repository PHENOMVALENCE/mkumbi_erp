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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $conn->beginTransaction();
        
        if ($_POST['action'] === 'record_payment') {
            $commission_id = $_POST['commission_id'];
            
            // Validate commission
            $check_sql = "SELECT c.*, 
                                COALESCE(u.full_name, c.recipient_name) as recipient_display_name,
                                r.reservation_number,
                                cust.full_name as customer_name,
                                pr.project_name
                         FROM commissions c
                         LEFT JOIN users u ON c.user_id = u.user_id
                         JOIN reservations r ON c.reservation_id = r.reservation_id
                         JOIN customers cust ON r.customer_id = cust.customer_id
                         LEFT JOIN plots pl ON r.plot_id = pl.plot_id
                         LEFT JOIN projects pr ON pl.project_id = pr.project_id
                         WHERE c.commission_id = ? AND c.company_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->execute([$commission_id, $company_id]);
            $commission = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$commission) {
                throw new Exception("Commission not found");
            }
            
            if ($commission['payment_status'] !== 'approved') {
                throw new Exception("Commission must be approved before payment can be recorded");
            }
            
            $payment_amount = floatval($_POST['payment_amount']);
            
            if ($payment_amount <= 0) {
                throw new Exception("Payment amount must be greater than zero");
            }
            
            if ($payment_amount > $commission['balance']) {
                throw new Exception("Payment amount (TZS " . number_format($payment_amount, 2) . ") cannot exceed balance (TZS " . number_format($commission['balance'], 2) . ")");
            }
            
            // Generate payment number
            $year = date('Y');
            $count_sql = "SELECT COUNT(*) FROM commission_payments WHERE company_id = ? AND YEAR(payment_date) = ?";
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->execute([$company_id, $year]);
            $count = $count_stmt->fetchColumn() + 1;
            $payment_number = 'PAY-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
            
            // Insert payment
            $insert_sql = "INSERT INTO commission_payments (
                commission_id, company_id, payment_number, payment_date, payment_amount,
                payment_method, reference_number, bank_account_id, notes, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->execute([
                $commission_id,
                $company_id,
                $payment_number,
                $_POST['payment_date'],
                $payment_amount,
                $_POST['payment_method'],
                $_POST['reference_number'] ?? null,
                !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null,
                $_POST['notes'] ?? null,
                $user_id
            ]);
            
            // Update commission totals
            $new_total_paid = $commission['total_paid'] + $payment_amount;
            $new_balance = $commission['balance'] - $payment_amount;
            $new_status = ($new_balance <= 0.01) ? 'paid' : 'approved';
            
            $update_sql = "UPDATE commissions SET
                total_paid = ?, balance = ?, payment_status = ?,
                paid_by = ?, paid_at = NOW(), updated_at = NOW()
                WHERE commission_id = ? AND company_id = ?";
            
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->execute([
                $new_total_paid,
                $new_balance,
                $new_status,
                $user_id,
                $commission_id,
                $company_id
            ]);
            
            $conn->commit();
            $_SESSION['success'] = "Payment of TZS " . number_format($payment_amount, 2) . " recorded successfully! Payment Number: " . $payment_number;
            header("Location: payment_form.php?success=1");
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}

// Get commission_id from URL if provided
$selected_commission_id = $_GET['commission_id'] ?? null;
$selected_commission = null;

if ($selected_commission_id) {
    $comm_sql = "SELECT c.*, 
                        COALESCE(u.full_name, c.recipient_name) as recipient_display_name,
                        r.reservation_number,
                        cust.full_name as customer_name,
                        pr.project_name,
                        pl.plot_number
                 FROM commissions c
                 LEFT JOIN users u ON c.user_id = u.user_id
                 JOIN reservations r ON c.reservation_id = r.reservation_id
                 JOIN customers cust ON r.customer_id = cust.customer_id
                 LEFT JOIN plots pl ON r.plot_id = pl.plot_id
                 LEFT JOIN projects pr ON pl.project_id = pr.project_id
                 WHERE c.commission_id = ? AND c.company_id = ?";
    $comm_stmt = $conn->prepare($comm_sql);
    $comm_stmt->execute([$selected_commission_id, $company_id]);
    $selected_commission = $comm_stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch all approved commissions with balance
$commissions_sql = "SELECT c.commission_id, c.commission_number, c.commission_date,
                           c.entitled_amount, c.balance, c.total_paid,
                           COALESCE(u.full_name, c.recipient_name) as recipient_name,
                           r.reservation_number,
                           cust.full_name as customer_name,
                           pr.project_name
                    FROM commissions c
                    LEFT JOIN users u ON c.user_id = u.user_id
                    JOIN reservations r ON c.reservation_id = r.reservation_id
                    JOIN customers cust ON r.customer_id = cust.customer_id
                    LEFT JOIN plots pl ON r.plot_id = pl.plot_id
                    LEFT JOIN projects pr ON pl.project_id = pr.project_id
                    WHERE c.company_id = ? 
                    AND c.payment_status = 'approved' 
                    AND c.balance > 0
                    ORDER BY c.commission_date DESC";
$commissions_stmt = $conn->prepare($commissions_sql);
$commissions_stmt->execute([$company_id]);
$available_commissions = $commissions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch bank accounts
$banks_sql = "SELECT * FROM bank_accounts WHERE company_id = ? AND status = 'active' ORDER BY account_name";
$banks_stmt = $conn->prepare($banks_sql);
$banks_stmt->execute([$company_id]);
$bank_accounts = $banks_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Record Payment";
require_once '../../includes/header.php';
?>

<style>
.form-section {
    background: white;
    border-radius: 10px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #0d6efd;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
}

.commission-info-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.info-item {
    margin-bottom: 12px;
}

.info-label {
    font-size: 0.85rem;
    opacity: 0.9;
    font-weight: 500;
}

.info-value {
    font-size: 1.1rem;
    font-weight: 600;
}

.amount-display {
    background: rgba(255,255,255,0.2);
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
}

.required {
    color: #dc3545;
}

.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.payment-method-icon {
    font-size: 1.2rem;
    margin-right: 8px;
}

.quick-amount-btn {
    margin: 5px;
}

@media (max-width: 768px) {
    .form-section {
        padding: 15px;
    }
}
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-money-bill-wave text-success"></i> Record Commission Payment</h1>
            <p class="text-muted mb-0">Record a new payment for approved commissions</p>
        </div>
        <div>
            <a href="pay.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-list"></i> View All Payments
            </a>
            <a href="index.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left"></i> Back to Commissions
            </a>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (count($available_commissions) == 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-info-circle"></i> No approved commissions with outstanding balance available for payment.
            <br><a href="index.php" class="alert-link">Go to commissions list</a>
        </div>
    <?php else: ?>

    <form method="POST" action="" id="paymentForm">
        <input type="hidden" name="action" value="record_payment">
        
        <div class="row">
            <div class="col-lg-8">
                <!-- Commission Selection -->
                <div class="form-section">
                    <h5 class="section-title"><i class="fas fa-file-invoice"></i> Select Commission</h5>
                    
                    <div class="mb-3">
                        <label class="form-label">Commission to Pay <span class="required">*</span></label>
                        <select name="commission_id" id="commission_id" class="form-select" required onchange="loadCommissionDetails()">
                            <option value="">-- Select Commission --</option>
                            <?php foreach ($available_commissions as $comm): ?>
                                <option value="<?= $comm['commission_id'] ?>" 
                                        data-details='<?= json_encode($comm, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                                        <?= ($selected_commission && $selected_commission['commission_id'] == $comm['commission_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($comm['commission_number']) ?> - 
                                    <?= htmlspecialchars($comm['recipient_name']) ?> - 
                                    Balance: TZS <?= number_format($comm['balance'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Select the commission you want to record a payment for</small>
                    </div>
                </div>

                <!-- Payment Details -->
                <div class="form-section">
                    <h5 class="section-title"><i class="fas fa-money-check"></i> Payment Details</h5>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Date <span class="required">*</span></label>
                            <input type="date" name="payment_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Payment Amount (TZS) <span class="required">*</span></label>
                            <input type="number" name="payment_amount" id="payment_amount" 
                                   class="form-control" step="0.01" min="0.01" required
                                   placeholder="0.00">
                            <small class="text-muted">Maximum: <span id="max_amount">-</span></small>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Quick Amount Selection</label>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-primary quick-amount-btn" onclick="setFullAmount()">
                                    <i class="fas fa-coins"></i> Full Balance
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary quick-amount-btn" onclick="setHalfAmount()">
                                    <i class="fas fa-percent"></i> 50%
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary quick-amount-btn" onclick="setThirdAmount()">
                                    <i class="fas fa-divide"></i> 1/3
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary quick-amount-btn" onclick="setQuarterAmount()">
                                    <i class="fas fa-slash"></i> 1/4
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Payment Method <span class="required">*</span></label>
                            <select name="payment_method" id="payment_method" class="form-select" required onchange="toggleBankField()">
                                <option value="bank_transfer">
                                    <i class="fas fa-university payment-method-icon"></i> Bank Transfer
                                </option>
                                <option value="cash">
                                    <i class="fas fa-money-bill payment-method-icon"></i> Cash
                                </option>
                                <option value="mobile_money">
                                    <i class="fas fa-mobile-alt payment-method-icon"></i> Mobile Money
                                </option>
                                <option value="cheque">
                                    <i class="fas fa-money-check payment-method-icon"></i> Cheque
                                </option>
                            </select>
                        </div>

                        <div class="col-md-6" id="bank_account_field">
                            <label class="form-label">Bank Account</label>
                            <select name="bank_account_id" class="form-select">
                                <option value="">-- Select Bank Account --</option>
                                <?php foreach ($bank_accounts as $bank): ?>
                                    <option value="<?= $bank['account_id'] ?>">
                                        <?= htmlspecialchars($bank['account_name']) ?> - 
                                        <?= htmlspecialchars($bank['account_number']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Reference Number / Transaction ID</label>
                            <input type="text" name="reference_number" class="form-control" 
                                   placeholder="e.g., TRX123456, Cheque #789">
                            <small class="text-muted">Transaction reference, cheque number, or receipt number</small>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Payment Notes</label>
                            <textarea name="notes" class="form-control" rows="3" 
                                      placeholder="Add any additional notes about this payment..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="text-end">
                    <button type="button" class="btn btn-secondary me-2" onclick="window.location.href='index.php'">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-check-circle"></i> Record Payment
                    </button>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- Commission Info Card -->
                <div class="commission-info-card" id="commissionInfoCard" style="display: none;">
                    <h5 class="mb-3"><i class="fas fa-info-circle"></i> Commission Details</h5>
                    
                    <div class="info-item">
                        <div class="info-label">Commission Number</div>
                        <div class="info-value" id="info_commission_number">-</div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Recipient</div>
                        <div class="info-value" id="info_recipient">-</div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Project</div>
                        <div class="info-value" id="info_project">-</div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Customer</div>
                        <div class="info-value" id="info_customer">-</div>
                    </div>

                    <div class="amount-display">
                        <div class="row text-center">
                            <div class="col-6">
                                <small class="info-label">Entitled Amount</small>
                                <h5 id="info_entitled">TZS 0.00</h5>
                            </div>
                            <div class="col-6">
                                <small class="info-label">Already Paid</small>
                                <h5 id="info_paid">TZS 0.00</h5>
                            </div>
                        </div>
                        <hr style="border-color: rgba(255,255,255,0.3);">
                        <div class="text-center">
                            <small class="info-label">Outstanding Balance</small>
                            <h3 id="info_balance">TZS 0.00</h3>
                        </div>
                    </div>
                </div>

                <!-- Help Card -->
                <div class="card border-info">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="fas fa-question-circle"></i> Payment Instructions</h6>
                    </div>
                    <div class="card-body">
                        <ol class="small mb-0">
                            <li>Select the commission to pay</li>
                            <li>Enter payment date and amount</li>
                            <li>Choose payment method</li>
                            <li>Add reference number if applicable</li>
                            <li>Click "Record Payment" to save</li>
                        </ol>
                        <hr>
                        <p class="small mb-0 text-muted">
                            <i class="fas fa-info-circle"></i> 
                            You can make partial payments. The balance will be updated automatically.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?php endif; ?>
</div>

<script>
let currentCommissionData = null;

// Load commission details when selected
function loadCommissionDetails() {
    const select = document.getElementById('commission_id');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        currentCommissionData = JSON.parse(selectedOption.getAttribute('data-details'));
        displayCommissionInfo(currentCommissionData);
        document.getElementById('commissionInfoCard').style.display = 'block';
    } else {
        document.getElementById('commissionInfoCard').style.display = 'none';
        currentCommissionData = null;
    }
}

// Display commission information
function displayCommissionInfo(data) {
    document.getElementById('info_commission_number').textContent = data.commission_number;
    document.getElementById('info_recipient').textContent = data.recipient_name;
    document.getElementById('info_project').textContent = data.project_name || '-';
    document.getElementById('info_customer').textContent = data.customer_name;
    document.getElementById('info_entitled').textContent = 'TZS ' + parseFloat(data.entitled_amount).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('info_paid').textContent = 'TZS ' + parseFloat(data.total_paid || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('info_balance').textContent = 'TZS ' + parseFloat(data.balance).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('max_amount').textContent = 'TZS ' + parseFloat(data.balance).toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Set max for payment amount input
    document.getElementById('payment_amount').max = data.balance;
}

// Quick amount functions
function setFullAmount() {
    if (currentCommissionData) {
        document.getElementById('payment_amount').value = parseFloat(currentCommissionData.balance).toFixed(2);
    }
}

function setHalfAmount() {
    if (currentCommissionData) {
        document.getElementById('payment_amount').value = (parseFloat(currentCommissionData.balance) / 2).toFixed(2);
    }
}

function setThirdAmount() {
    if (currentCommissionData) {
        document.getElementById('payment_amount').value = (parseFloat(currentCommissionData.balance) / 3).toFixed(2);
    }
}

function setQuarterAmount() {
    if (currentCommissionData) {
        document.getElementById('payment_amount').value = (parseFloat(currentCommissionData.balance) / 4).toFixed(2);
    }
}

// Toggle bank account field
function toggleBankField() {
    const method = document.getElementById('payment_method').value;
    const bankField = document.getElementById('bank_account_field');
    
    if (method === 'bank_transfer') {
        bankField.style.display = 'block';
    } else {
        bankField.style.display = 'none';
    }
}

// Load commission if pre-selected
<?php if ($selected_commission): ?>
document.addEventListener('DOMContentLoaded', function() {
    loadCommissionDetails();
});
<?php endif; ?>

// Form validation
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('payment_amount').value);
    
    if (currentCommissionData && amount > parseFloat(currentCommissionData.balance)) {
        e.preventDefault();
        alert('Payment amount cannot exceed the outstanding balance!');
        return false;
    }
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Payment amount must be greater than zero!');
        return false;
    }
});

// Auto-dismiss alerts
setTimeout(function() {
    $('.alert').fadeOut();
}, 5000);
</script>

<?php require_once '../../includes/footer.php'; ?>