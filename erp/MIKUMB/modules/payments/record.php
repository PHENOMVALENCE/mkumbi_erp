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

// Fetch active reservations (not cancelled)
try {
    $reservations_sql = "SELECT 
                            r.reservation_id,
                            r.reservation_number,
                            r.reservation_date,
                            r.total_amount,
                            r.down_payment,
                            r.payment_periods,
                            r.installment_amount,
                            c.customer_id,
                            c.full_name as customer_name,
                            c.phone,
                            pl.plot_number,
                            pl.block_number,
                            pr.project_name,
                            COALESCE(SUM(p.amount), 0) as total_paid,
                            (r.total_amount - COALESCE(SUM(p.amount), 0)) as balance
                         FROM reservations r
                         INNER JOIN customers c ON r.customer_id = c.customer_id
                         INNER JOIN plots pl ON r.plot_id = pl.plot_id
                         INNER JOIN projects pr ON pl.project_id = pr.project_id
                         LEFT JOIN payments p ON r.reservation_id = p.reservation_id 
                            AND p.status IN ('approved', 'pending_approval')
                         WHERE r.company_id = ?
                         AND r.status IN ('active', 'draft')
                         GROUP BY r.reservation_id
                         HAVING balance > 0
                         ORDER BY r.reservation_date DESC";
    
    $stmt = $conn->prepare($reservations_sql);
    $stmt->execute([$company_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $reservations = [];
    $errors[] = "Error fetching reservations: " . $e->getMessage();
}

// AJAX: Get reservation details
if (isset($_GET['action']) && $_GET['action'] === 'get_reservation' && isset($_GET['reservation_id'])) {
    $reservation_id = intval($_GET['reservation_id']);
    
    try {
        $details_sql = "SELECT 
                            r.*,
                            c.full_name as customer_name,
                            c.phone,
                            c.email,
                            pl.plot_number,
                            pl.block_number,
                            pl.area,
                            pr.project_name,
                            COALESCE(SUM(p.amount), 0) as total_paid,
                            (r.total_amount - COALESCE(SUM(p.amount), 0)) as balance_due
                        FROM reservations r
                        INNER JOIN customers c ON r.customer_id = c.customer_id
                        INNER JOIN plots pl ON r.plot_id = pl.plot_id
                        INNER JOIN projects pr ON pl.project_id = pr.project_id
                        LEFT JOIN payments p ON r.reservation_id = p.reservation_id 
                            AND p.status IN ('approved', 'pending_approval')
                        WHERE r.reservation_id = ? AND r.company_id = ?
                        GROUP BY r.reservation_id";
        
        $stmt = $conn->prepare($details_sql);
        $stmt->execute([$reservation_id, $company_id]);
        $details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get payment schedule
        $schedule_sql = "SELECT * FROM payment_schedules 
                        WHERE reservation_id = ? 
                        ORDER BY installment_number";
        $schedule_stmt = $conn->prepare($schedule_sql);
        $schedule_stmt->execute([$reservation_id]);
        $schedule = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get payment history
        $history_sql = "SELECT 
                            payment_id,
                            payment_number,
                            payment_date,
                            amount,
                            payment_method,
                            status,
                            created_at
                        FROM payments
                        WHERE reservation_id = ?
                        ORDER BY payment_date DESC, created_at DESC";
        $history_stmt = $conn->prepare($history_sql);
        $history_stmt->execute([$reservation_id]);
        $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'details' => $details,
            'schedule' => $schedule,
            'history' => $history
        ]);
        exit;
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validation
    if (empty($_POST['reservation_id'])) $errors[] = "Reservation is required";
    if (empty($_POST['payment_date'])) $errors[] = "Payment date is required";
    if (empty($_POST['amount'])) $errors[] = "Payment amount is required";
    if (empty($_POST['payment_method'])) $errors[] = "Payment method is required";
    
    $amount = floatval($_POST['amount']);
    if ($amount <= 0) {
        $errors[] = "Payment amount must be greater than zero";
    }
    
    // Check if amount exceeds balance
    if (!empty($_POST['reservation_id'])) {
        $reservation_id = intval($_POST['reservation_id']);
        
        $check_balance_sql = "SELECT 
                                r.total_amount,
                                COALESCE(SUM(p.amount), 0) as total_paid,
                                (r.total_amount - COALESCE(SUM(p.amount), 0)) as balance
                             FROM reservations r
                             LEFT JOIN payments p ON r.reservation_id = p.reservation_id 
                                AND p.status IN ('approved', 'pending_approval')
                             WHERE r.reservation_id = ? AND r.company_id = ?
                             GROUP BY r.reservation_id";
        
        $check_stmt = $conn->prepare($check_balance_sql);
        $check_stmt->execute([$reservation_id, $company_id]);
        $balance_info = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($balance_info) {
            if ($amount > $balance_info['balance']) {
                $errors[] = "Payment amount (TZS " . number_format($amount, 2) . ") exceeds outstanding balance (TZS " . number_format($balance_info['balance'], 2) . ")";
            }
        } else {
            $errors[] = "Reservation not found";
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Generate payment number
            $year = date('Y', strtotime($_POST['payment_date']));
            $count_sql = "SELECT COUNT(*) FROM payments 
                         WHERE company_id = ? AND YEAR(payment_date) = ?";
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->execute([$company_id, $year]);
            $count = $count_stmt->fetchColumn() + 1;
            $payment_number = 'PAY-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            
            // Determine payment type
            $payment_type = $_POST['payment_type'] ?? 'installment';
            
            // Insert payment with PENDING_APPROVAL status
            $insert_sql = "INSERT INTO payments (
                company_id, reservation_id, payment_date, payment_number, amount,
                payment_method, payment_type, bank_name, account_number, 
                transaction_reference, remarks, 
                status, submitted_by, submitted_at, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', ?, NOW(), ?)";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                $company_id,
                $_POST['reservation_id'],
                $_POST['payment_date'],
                $payment_number,
                $amount,
                $_POST['payment_method'],
                $payment_type,
                $_POST['bank_name'] ?? null,
                $_POST['account_number'] ?? null,
                $_POST['transaction_reference'] ?? null,
                $_POST['remarks'] ?? null,
                $_SESSION['user_id'],
                $_SESSION['user_id']
            ]);
            
            $payment_id = $conn->lastInsertId();
            
            // Log submission action
            $log_sql = "INSERT INTO payment_approvals 
                       (payment_id, company_id, action, action_by, comments, previous_status, new_status)
                       VALUES (?, ?, 'submitted', ?, 'Payment submitted for approval', null, 'pending_approval')";
            $log_stmt = $conn->prepare($log_sql);
            $log_stmt->execute([$payment_id, $company_id, $_SESSION['user_id']]);
            
            $conn->commit();
            
            $_SESSION['success'] = "Payment recorded successfully! Payment Number: " . $payment_number . ". Awaiting manager approval.";
            header("Location: record.php");
            exit;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title = 'Record Payment';
require_once '../../includes/header.php';
?>

<style>
.payment-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.1);
    padding: 25px;
    margin-bottom: 25px;
}

.section-title {
    font-size: 18px;
    font-weight: 700;
    color: #333;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 3px solid #667eea;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 25px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-size: 13px;
    opacity: 0.9;
}

.info-value {
    font-size: 15px;
    font-weight: 700;
}

.schedule-table {
    width: 100%;
    margin-top: 15px;
}

.schedule-table th {
    background: #f8f9fa;
    padding: 12px;
    text-align: left;
    font-weight: 700;
    font-size: 13px;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
}

.schedule-table td {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
}

.schedule-table tr:hover {
    background: #f8f9fa;
}

.status-paid {
    background: #d4edda;
    color: #155724;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}

.status-unpaid {
    background: #fff3cd;
    color: #856404;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}

.status-pending {
    background: #ffc107;
    color: #856404;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}

.status-overdue {
    background: #f8d7da;
    color: #721c24;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}

.payment-history {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-top: 20px;
}

.history-item {
    background: white;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 10px;
    border-left: 4px solid #667eea;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.btn-submit {
    background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    border: none;
    padding: 12px 40px;
    font-size: 16px;
    font-weight: 700;
    border-radius: 8px;
    color: white;
    transition: all 0.3s;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
    color: white;
}

.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.required {
    color: #dc3545;
    margin-left: 3px;
}

.pending-notice {
    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.pending-notice i {
    font-size: 32px;
}

.loading-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    justify-content: center;
    align-items: center;
}

.spinner {
    border: 5px solid #f3f3f3;
    border-top: 5px solid #667eea;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-dollar-sign"></i> Record Payment</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="../sales/index.php">Sales</a></li>
                    <li class="breadcrumb-item active">Record Payment</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <h5><i class="fas fa-ban"></i> Errors!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Notice -->
        <div class="pending-notice">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Important Notice:</strong> All payments require manager or admin approval before they are applied to the customer's account. 
                The payment will be in <strong>PENDING APPROVAL</strong> status until reviewed.
            </div>
        </div>

        <form method="POST" id="paymentForm">
            
            <!-- Step 1: Select Reservation -->
            <div class="payment-card">
                <div class="section-title">
                    <i class="fas fa-file-invoice"></i>
                    <span>Step 1: Select Reservation</span>
                </div>

                <div class="form-group">
                    <label>Select Reservation <span class="required">*</span></label>
                    <select name="reservation_id" id="reservation_id" class="form-control" required>
                        <option value="">-- Choose Reservation --</option>
                        <?php foreach ($reservations as $res): ?>
                            <option value="<?php echo $res['reservation_id']; ?>"
                                    data-customer="<?php echo htmlspecialchars($res['customer_name']); ?>"
                                    data-phone="<?php echo htmlspecialchars($res['phone']); ?>"
                                    data-plot="<?php echo htmlspecialchars($res['plot_number']); ?>"
                                    data-project="<?php echo htmlspecialchars($res['project_name']); ?>">
                                <?php echo htmlspecialchars($res['reservation_number']); ?> - 
                                <?php echo htmlspecialchars($res['customer_name']); ?> - 
                                Plot <?php echo htmlspecialchars($res['plot_number']); ?> 
                                (Balance: TZS <?php echo number_format($res['balance'], 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="reservationInfo" style="display: none;">
                    <div class="info-card">
                        <h5 style="margin-bottom: 15px; font-weight: 700;">
                            <i class="fas fa-info-circle"></i> Reservation Details
                        </h5>
                        <div class="info-row">
                            <span class="info-label">Customer:</span>
                            <span class="info-value" id="infoCustomer">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Phone:</span>
                            <span class="info-value" id="infoPhone">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Plot:</span>
                            <span class="info-value" id="infoPlot">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Project:</span>
                            <span class="info-value" id="infoProject">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Total Amount:</span>
                            <span class="info-value" id="infoTotal">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Total Paid:</span>
                            <span class="info-value" id="infoPaid">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Outstanding Balance:</span>
                            <span class="info-value" id="infoBalance" style="font-size: 18px; color: #ffc107;">-</span>
                        </div>
                    </div>

                    <!-- Payment Schedule -->
                    <div id="paymentSchedule" style="display: none;">
                        <h6 style="font-weight: 700; margin-bottom: 10px;">
                            <i class="fas fa-calendar-alt"></i> Payment Schedule
                        </h6>
                        <div style="overflow-x: auto;">
                            <table class="schedule-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Paid Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="scheduleBody">
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <div id="paymentHistorySection" style="display: none;">
                        <h6 style="font-weight: 700; margin-top: 20px; margin-bottom: 10px;">
                            <i class="fas fa-history"></i> Payment History
                        </h6>
                        <div class="payment-history" id="paymentHistory">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Payment Details -->
            <div class="payment-card" id="paymentDetailsCard" style="display: none;">
                <div class="section-title">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Step 2: Payment Details</span>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Payment Date <span class="required">*</span></label>
                            <input type="date" name="payment_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Payment Amount (TZS) <span class="required">*</span></label>
                            <input type="number" name="amount" id="amount" class="form-control" 
                                   step="0.01" min="0" required placeholder="0.00">
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Payment Type</label>
                            <select name="payment_type" class="form-control">
                                <option value="installment">Installment</option>
                                <option value="full_payment">Full Payment</option>
                                <option value="down_payment">Down Payment</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Payment Method <span class="required">*</span></label>
                            <select name="payment_method" id="payment_method" class="form-control" required>
                                <option value="">-- Select Method --</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="cheque">Cheque</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6" id="bankFields" style="display: none;">
                        <div class="form-group">
                            <label>Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" 
                                   placeholder="e.g., CRDB Bank">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Account Number</label>
                            <input type="text" name="account_number" class="form-control" 
                                   placeholder="Customer's account number (optional)">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Transaction Reference</label>
                            <input type="text" name="transaction_reference" class="form-control" 
                                   placeholder="e.g., TRX123456789">
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Remarks / Notes</label>
                            <textarea name="remarks" class="form-control" rows="3" 
                                      placeholder="Additional notes about this payment..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e9ecef;">
                    <a href="../sales/index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-submit float-right">
                        <i class="fas fa-check"></i> Submit for Approval
                    </button>
                </div>
            </div>

        </form>

    </div>
</section>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const reservationSelect = document.getElementById('reservation_id');
    const reservationInfo = document.getElementById('reservationInfo');
    const paymentDetailsCard = document.getElementById('paymentDetailsCard');
    const paymentMethod = document.getElementById('payment_method');
    const bankFields = document.getElementById('bankFields');
    const loadingOverlay = document.getElementById('loadingOverlay');
    
    // Show/hide bank fields
    paymentMethod.addEventListener('change', function() {
        if (this.value === 'bank_transfer' || this.value === 'cheque') {
            bankFields.style.display = 'block';
        } else {
            bankFields.style.display = 'none';
        }
    });
    
    // Load reservation details
    reservationSelect.addEventListener('change', function() {
        const reservationId = this.value;
        
        if (!reservationId) {
            reservationInfo.style.display = 'none';
            paymentDetailsCard.style.display = 'none';
            return;
        }
        
        loadingOverlay.style.display = 'flex';
        
        fetch(`record.php?action=get_reservation&reservation_id=${reservationId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const details = data.details;
                    
                    // Update info card
                    document.getElementById('infoCustomer').textContent = details.customer_name;
                    document.getElementById('infoPhone').textContent = details.phone;
                    document.getElementById('infoPlot').textContent = 
                        `Plot ${details.plot_number}${details.block_number ? ` (Block ${details.block_number})` : ''}`;
                    document.getElementById('infoProject').textContent = details.project_name;
                    document.getElementById('infoTotal').textContent = 
                        'TZS ' + parseFloat(details.total_amount).toLocaleString('en-US', {minimumFractionDigits: 2});
                    document.getElementById('infoPaid').textContent = 
                        'TZS ' + parseFloat(details.total_paid).toLocaleString('en-US', {minimumFractionDigits: 2});
                    document.getElementById('infoBalance').textContent = 
                        'TZS ' + parseFloat(details.balance_due).toLocaleString('en-US', {minimumFractionDigits: 2});
                    
                    // Update payment schedule
                    if (data.schedule && data.schedule.length > 0) {
                        const scheduleBody = document.getElementById('scheduleBody');
                        scheduleBody.innerHTML = '';
                        
                        data.schedule.forEach(item => {
                            const statusClass = item.is_paid ? 'status-paid' : 
                                              item.payment_status === 'pending_approval' ? 'status-pending' :
                                              item.is_overdue ? 'status-overdue' : 'status-unpaid';
                            const statusText = item.is_paid ? 'PAID' : 
                                             item.payment_status === 'pending_approval' ? 'PENDING' :
                                             item.is_overdue ? 'OVERDUE' : 'UNPAID';
                            
                            const row = `
                                <tr>
                                    <td>${item.installment_number}</td>
                                    <td>${formatDate(item.due_date)}</td>
                                    <td>TZS ${parseFloat(item.installment_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                    <td>TZS ${parseFloat(item.paid_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                    <td><span class="${statusClass}">${statusText}</span></td>
                                </tr>
                            `;
                            scheduleBody.innerHTML += row;
                        });
                        
                        document.getElementById('paymentSchedule').style.display = 'block';
                    }
                    
                    // Update payment history
                    if (data.history && data.history.length > 0) {
                        const historyDiv = document.getElementById('paymentHistory');
                        historyDiv.innerHTML = '';
                        
                        data.history.forEach(payment => {
                            const statusClass = payment.status === 'approved' ? 'status-paid' :
                                              payment.status === 'pending_approval' ? 'status-pending' :
                                              'status-overdue';
                            const statusText = payment.status === 'approved' ? 'APPROVED' :
                                             payment.status === 'pending_approval' ? 'PENDING APPROVAL' :
                                             payment.status.toUpperCase();
                            
                            const item = `
                                <div class="history-item">
                                    <div>
                                        <strong>${payment.payment_number}</strong><br>
                                        <small style="color: #6c757d;">
                                            ${formatDate(payment.payment_date)} | 
                                            ${payment.payment_method.replace('_', ' ').toUpperCase()}
                                        </small>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong style="font-size: 16px;">
                                            TZS ${parseFloat(payment.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}
                                        </strong><br>
                                        <span class="${statusClass}">${statusText}</span>
                                    </div>
                                </div>
                            `;
                            historyDiv.innerHTML += item;
                        });
                        
                        document.getElementById('paymentHistorySection').style.display = 'block';
                    }
                    
                    reservationInfo.style.display = 'block';
                    paymentDetailsCard.style.display = 'block';
                    
                    // Scroll to payment details
                    paymentDetailsCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                
                loadingOverlay.style.display = 'none';
            })
            .catch(error => {
                console.error('Error:', error);
                loadingOverlay.style.display = 'none';
                alert('Error loading reservation details');
            });
    });
    
    function formatDate(dateString) {
        const date = new Date(dateString);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return `${months[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>