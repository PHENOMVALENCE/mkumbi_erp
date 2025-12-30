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

$payment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$success = isset($_GET['success']) ? $_GET['success'] : '';

if (!$payment_id) {
    header('Location: index.php');
    exit;
}

// Fetch payment details
try {
    $payment_query = "
        SELECT 
            p.*,
            r.reservation_id,
            r.reservation_number,
            r.total_amount as reservation_total,
            r.down_payment,
            r.payment_periods,
            c.customer_id,
            c.full_name as customer_name,
            c.email as customer_email,
            COALESCE(c.phone, c.phone1) as customer_phone,
            c.national_id,
            c.street_address,
            c.region,
            c.district,
            pl.plot_id,
            pl.plot_number,
            pl.block_number,
            pl.area,
            pl.selling_price as plot_price,
            pr.project_id,
            pr.project_name,
            pr.project_code,
            creator.full_name as created_by_name,
            approver.full_name as approved_by_name,
            COALESCE(payments_total.total_paid, 0) as total_paid,
            (r.total_amount - COALESCE(payments_total.total_paid, 0)) as remaining_balance
        FROM payments p
        INNER JOIN reservations r ON p.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots pl ON r.plot_id = pl.plot_id
        INNER JOIN projects pr ON pl.project_id = pr.project_id
        LEFT JOIN users creator ON p.created_by = creator.user_id
        LEFT JOIN users approver ON p.approved_by = approver.user_id
        LEFT JOIN (
            SELECT reservation_id, SUM(amount) as total_paid
            FROM payments
            WHERE status = 'approved'
            GROUP BY reservation_id
        ) payments_total ON r.reservation_id = payments_total.reservation_id
        WHERE p.payment_id = ? AND p.company_id = ?
    ";
    $stmt = $conn->prepare($payment_query);
    $stmt->execute([$payment_id, $company_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching payment: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

// Fetch all payments for this reservation
try {
    $all_payments_query = "
        SELECT 
            p.*,
            u.full_name as created_by_name
        FROM payments p
        LEFT JOIN users u ON p.created_by = u.user_id
        WHERE p.reservation_id = ? AND p.company_id = ?
        ORDER BY p.payment_date DESC, p.created_at DESC
    ";
    $stmt = $conn->prepare($all_payments_query);
    $stmt->execute([$payment['reservation_id'], $company_id]);
    $all_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching all payments: " . $e->getMessage());
    $all_payments = [];
}

// Calculate payment progress
$payment_percentage = $payment['reservation_total'] > 0 ? 
    ($payment['total_paid'] / $payment['reservation_total']) * 100 : 0;

$page_title = 'Payment Details - ' . ($payment['payment_number'] ?? 'N/A');
require_once '../../includes/header.php';
?>

<style>
.detail-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 25px;
    font-size: 0.9rem;
    font-weight: 600;
}

.status-badge.approved {
    background: #d4edda;
    color: #155724;
}

.status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.status-badge.cancelled {
    background: #f8d7da;
    color: #721c24;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.info-item {
    display: flex;
    flex-direction: column;
}

.info-label {
    font-size: 0.8rem;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.info-value {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
}

.payment-header {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
}

.payment-number {
    font-family: 'Courier New', monospace;
    font-size: 1.5rem;
    font-weight: 700;
    background: rgba(255,255,255,0.2);
    padding: 0.5rem 1rem;
    border-radius: 8px;
    display: inline-block;
}

.amount-display {
    font-size: 3rem;
    font-weight: 700;
    margin: 1rem 0;
}

.customer-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 2rem;
    margin-right: 1.5rem;
}

.customer-info {
    display: flex;
    align-items: center;
}

.progress-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin: 1.5rem 0;
}

.progress-bar-custom {
    height: 30px;
    border-radius: 15px;
    font-weight: 700;
}

.timeline-item {
    padding: 1rem;
    border-left: 3px solid #007bff;
    margin-left: 1rem;
    position: relative;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -8px;
    top: 1.5rem;
    width: 13px;
    height: 13px;
    border-radius: 50%;
    background: #007bff;
}

.timeline-item.approved::before {
    background: #28a745;
}

.timeline-item.cancelled::before {
    background: #dc3545;
}

.method-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    background: #e9ecef;
    color: #495057;
}

.method-badge.cash {
    background: #d4edda;
    color: #155724;
}

.method-badge.bank_transfer {
    background: #d1ecf1;
    color: #0c5460;
}

.method-badge.mobile_money {
    background: #fff3cd;
    color: #856404;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
}

.section-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.icon-primary { background: #cfe2ff; color: #084298; }
.icon-success { background: #d4edda; color: #155724; }
.icon-info { background: #d1ecf1; color: #0c5460; }
.icon-warning { background: #fff3cd; color: #856404; }

.payment-row {
    padding: 0.75rem;
    border-bottom: 1px solid #e9ecef;
}

.payment-row:hover {
    background: #f8f9fa;
}

.payment-row.current {
    background: #e7f3ff;
    border-left: 4px solid #007bff;
}

/* NEW STYLES FOR ACCOUNT DETAILS */
.account-section {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-left: 4px solid #007bff;
    padding: 1.5rem;
    border-radius: 8px;
    margin-top: 1.5rem;
}

.account-item {
    background: white;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 0.75rem;
    border-left: 3px solid #28a745;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.account-item:last-child {
    margin-bottom: 0;
}

.account-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.account-value {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    font-family: 'Courier New', monospace;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .detail-card {
        box-shadow: none;
        border: 1px solid #dee2e6;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4 no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-receipt text-success me-2"></i>Payment Details
                </h1>
                <p class="text-muted small mb-0 mt-1">Complete payment information</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button onclick="window.print()" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                    <a href="index.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Payments
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <strong>Success!</strong> <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Payment Header -->
        <div class="payment-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="payment-number mb-3">
                        <?php echo htmlspecialchars($payment['payment_number'] ?? 'N/A'); ?>
                    </div>
                    <div class="amount-display">
                        TSH <?php echo number_format($payment['amount'], 0); ?>
                    </div>
                    <div class="d-flex align-items-center gap-3 mt-3">
                        <span class="status-badge <?php echo $payment['status']; ?>">
                            <?php echo ucfirst($payment['status']); ?>
                        </span>
                        <span class="method-badge <?php echo $payment['payment_method']; ?>">
                            <i class="fas fa-wallet me-1"></i>
                            <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?>
                        </span>
                    </div>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <div class="text-white-50 small">PAYMENT DATE</div>
                    <div class="h4 mb-0">
                        <i class="fas fa-calendar-alt me-2"></i>
                        <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                    </div>
                    <?php if (!empty($payment['receipt_number'])): ?>
                    <div class="mt-3">
                        <div class="text-white-50 small">RECEIPT NUMBER</div>
                        <div class="h5 mb-0"><?php echo htmlspecialchars($payment['receipt_number']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">

                <!-- Customer Information -->
                <div class="detail-card">
                    <div class="section-header">
                        <div class="section-icon icon-primary">
                            <i class="fas fa-user"></i>
                        </div>
                        <h4 class="mb-0 fw-bold">Customer Information</h4>
                    </div>
                    
                    <div class="customer-info mb-4">
                        <div class="customer-avatar">
                            <?php echo strtoupper(substr($payment['customer_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <h3 class="mb-1"><?php echo htmlspecialchars($payment['customer_name']); ?></h3>
                            <div class="text-muted">
                                <?php if (!empty($payment['customer_phone'])): ?>
                                <div><i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($payment['customer_phone']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($payment['customer_email'])): ?>
                                <div><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($payment['customer_email']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="info-grid">
                        <?php if (!empty($payment['national_id'])): ?>
                        <div class="info-item">
                            <span class="info-label">National ID</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['national_id']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['street_address'])): ?>
                        <div class="info-item">
                            <span class="info-label">Address</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['street_address']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['region'])): ?>
                        <div class="info-item">
                            <span class="info-label">Region</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['region']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['district'])): ?>
                        <div class="info-item">
                            <span class="info-label">District</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['district']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Plot & Reservation Details -->
                <div class="detail-card">
                    <div class="section-header">
                        <div class="section-icon icon-info">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                        <h4 class="mb-0 fw-bold">Plot & Reservation Details</h4>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Reservation Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['reservation_number']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Plot Number</span>
                            <span class="info-value">Plot <?php echo htmlspecialchars($payment['plot_number']); ?></span>
                        </div>
                        <?php if (!empty($payment['block_number'])): ?>
                        <div class="info-item">
                            <span class="info-label">Block Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['block_number']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <span class="info-label">Plot Area</span>
                            <span class="info-value"><?php echo number_format($payment['area'], 2); ?> m²</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Project</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['project_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Project Code</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['project_code']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Payment Details -->
                <div class="detail-card">
                    <div class="section-header">
                        <div class="section-icon icon-success">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h4 class="mb-0 fw-bold">Payment Details</h4>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Payment Type</span>
                            <span class="info-value"><?php echo ucwords(str_replace('_', ' ', $payment['payment_type'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Payment Method</span>
                            <span class="info-value"><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Amount</span>
                            <span class="info-value text-success">TSH <?php echo number_format($payment['amount'], 0); ?></span>
                        </div>
                        <?php if (!empty($payment['tax_amount']) && $payment['tax_amount'] > 0): ?>
                        <div class="info-item">
                            <span class="info-label">Tax Amount</span>
                            <span class="info-value">TSH <?php echo number_format($payment['tax_amount'], 0); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['bank_name'])): ?>
                        <div class="info-item">
                            <span class="info-label">Bank Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['bank_name']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['account_number'])): ?>
                        <div class="info-item">
                            <span class="info-label">Account Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['account_number']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['transaction_reference'])): ?>
                        <div class="info-item">
                            <span class="info-label">Transaction Reference</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['transaction_reference']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($payment['voucher_number'])): ?>
                        <div class="info-item">
                            <span class="info-label">Voucher Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($payment['voucher_number']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- NEW: Account & Transaction Details Section -->
                    <?php if ($payment['payment_method'] != 'cash'): ?>
                    <div class="account-section">
                        <h6 class="fw-bold mb-3">
                            <i class="fas fa-university me-2"></i>Account & Transaction Information
                        </h6>
                        
                        <?php if (!empty($payment['bank_name'])): ?>
                        <div class="account-item">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <span class="account-label">
                                        <i class="fas fa-university me-1"></i>Client Bank
                                    </span>
                                </div>
                                <div class="col-md-8">
                                    <span class="account-value"><?php echo htmlspecialchars($payment['bank_name']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($payment['account_number'])): ?>
                        <div class="account-item">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <span class="account-label">
                                        <i class="fas fa-credit-card me-1"></i>Client Account Number
                                    </span>
                                </div>
                                <div class="col-md-8">
                                    <span class="account-value"><?php echo htmlspecialchars($payment['account_number']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($payment['account_name'])): ?>
                        <div class="account-item">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <span class="account-label">
                                        <i class="fas fa-user-tag me-1"></i>Account Name
                                    </span>
                                </div>
                                <div class="col-md-8">
                                    <span class="account-value"><?php echo htmlspecialchars($payment['account_name']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($payment['transaction_reference'])): ?>
                        <div class="account-item">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <span class="account-label">
                                        <i class="fas fa-hashtag me-1"></i>Transaction Reference
                                    </span>
                                </div>
                                <div class="col-md-8">
                                    <span class="account-value"><?php echo htmlspecialchars($payment['transaction_reference']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($payment['payment_method'] == 'cheque' && !empty($payment['cheque_number'])): ?>
                        <div class="account-item">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <span class="account-label">
                                        <i class="fas fa-money-check me-1"></i>Cheque Number
                                    </span>
                                </div>
                                <div class="col-md-8">
                                    <span class="account-value"><?php echo htmlspecialchars($payment['cheque_number']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($payment['payment_method'] == 'mobile_money' && !empty($payment['mobile_number'])): ?>
                        <div class="account-item">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <span class="account-label">
                                        <i class="fas fa-mobile-alt me-1"></i>Mobile Number
                                    </span>
                                </div>
                                <div class="col-md-8">
                                    <span class="account-value"><?php echo htmlspecialchars($payment['mobile_number']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Company Receiving Account -->
                        <?php if ($payment['status'] == 'approved'): ?>
                        <hr class="my-3">
                        <h6 class="fw-bold mb-3 text-primary">
                            <i class="fas fa-building me-2"></i>Company Receiving Account
                        </h6>
                        
                        <div class="account-item" style="border-left-color: #007bff;">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <span class="account-label">
                                        <i class="fas fa-university me-1"></i>Company Bank
                                    </span>
                                </div>
                                <div class="col-md-8">
                                    <span class="account-value">
                                        <?php echo !empty($payment['company_bank_name']) ? htmlspecialchars($payment['company_bank_name']) : 'CRDB Bank'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="account-item" style="border-left-color: #007bff;">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <span class="account-label">
                                        <i class="fas fa-credit-card me-1"></i>Company Account
                                    </span>
                                </div>
                                <div class="col-md-8">
                                    <span class="account-value">
                                        <?php echo !empty($payment['company_account_number']) ? htmlspecialchars($payment['company_account_number']) : '****-****-****'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-success mt-3 mb-0">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Verified:</strong> Payment deposited to company account
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($payment['remarks'])): ?>
                    <div class="mt-4">
                        <span class="info-label d-block mb-2">Remarks</span>
                        <div class="bg-light p-3 rounded">
                            <?php echo nl2br(htmlspecialchars($payment['remarks'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- All Payments for this Reservation -->
                <div class="detail-card">
                    <div class="section-header">
                        <div class="section-icon icon-warning">
                            <i class="fas fa-history"></i>
                        </div>
                        <h4 class="mb-0 fw-bold">Payment History</h4>
                    </div>
                    
                    <?php foreach ($all_payments as $hist_payment): ?>
                    <div class="payment-row <?php echo ($hist_payment['payment_id'] == $payment_id) ? 'current' : ''; ?>">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <div class="fw-bold"><?php echo htmlspecialchars($hist_payment['payment_number'] ?? 'N/A'); ?></div>
                                <small class="text-muted">
                                    <?php echo date('M d, Y', strtotime($hist_payment['payment_date'])); ?>
                                </small>
                            </div>
                            <div class="col-md-3">
                                <span class="method-badge <?php echo $hist_payment['payment_method']; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $hist_payment['payment_method'])); ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <div class="fw-bold text-success">TSH <?php echo number_format($hist_payment['amount'], 0); ?></div>
                            </div>
                            <div class="col-md-3 text-end">
                                <span class="status-badge <?php echo $hist_payment['status']; ?>">
                                    <?php echo ucfirst($hist_payment['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>

            <div class="col-lg-4">

                <!-- Payment Progress -->
                <div class="detail-card">
                    <h5 class="fw-bold mb-3">
                        <i class="fas fa-chart-line me-2 text-primary"></i>Payment Progress
                    </h5>
                    
                    <div class="progress-section">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="fw-bold">Total Paid</span>
                            <span class="fw-bold text-success">TSH <?php echo number_format($payment['total_paid'], 0); ?></span>
                        </div>
                        <div class="progress mb-2" style="height: 25px;">
                            <div class="progress-bar bg-success progress-bar-striped" 
                                 role="progressbar" 
                                 style="width: <?php echo min($payment_percentage, 100); ?>%"
                                 aria-valuenow="<?php echo $payment_percentage; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <?php echo number_format($payment_percentage, 1); ?>%
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Contract Amount:</span>
                                <span class="fw-bold">TSH <?php echo number_format($payment['reservation_total'], 0); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Amount Paid:</span>
                                <span class="fw-bold text-success">TSH <?php echo number_format($payment['total_paid'], 0); ?></span>
                            </div>
                            <div class="d-flex justify-content-between pt-2 border-top">
                                <span class="fw-bold">Balance:</span>
                                <span class="fw-bold text-danger">TSH <?php echo number_format($payment['remaining_balance'], 0); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Information -->
                <div class="detail-card">
                    <h5 class="fw-bold mb-3">
                        <i class="fas fa-info-circle me-2 text-info"></i>System Information
                    </h5>
                    
                    <?php if (!empty($payment['created_at'])): ?>
                    <div class="timeline-item <?php echo $payment['status']; ?>">
                        <div class="small text-muted">Created</div>
                        <div class="fw-bold"><?php echo date('M d, Y h:i A', strtotime($payment['created_at'])); ?></div>
                        <?php if (!empty($payment['created_by_name'])): ?>
                        <div class="small text-muted">By: <?php echo htmlspecialchars($payment['created_by_name']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($payment['status'] == 'approved' && !empty($payment['approved_at'])): ?>
                    <div class="timeline-item approved mt-3">
                        <div class="small text-muted">Approved</div>
                        <div class="fw-bold"><?php echo date('M d, Y h:i A', strtotime($payment['approved_at'])); ?></div>
                        <?php if (!empty($payment['approved_by_name'])): ?>
                        <div class="small text-muted">By: <?php echo htmlspecialchars($payment['approved_by_name']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($payment['updated_at']) && !empty($payment['created_at']) && $payment['updated_at'] != $payment['created_at']): ?>
                    <div class="timeline-item mt-3">
                        <div class="small text-muted">Last Updated</div>
                        <div class="fw-bold"><?php echo date('M d, Y h:i A', strtotime($payment['updated_at'])); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($payment['is_reconciled']) && !empty($payment['reconciliation_date'])): ?>
                    <div class="timeline-item approved mt-3">
                        <div class="small text-muted">Reconciled</div>
                        <div class="fw-bold"><?php echo date('M d, Y', strtotime($payment['reconciliation_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="detail-card no-print">
                    <h5 class="fw-bold mb-3">
                        <i class="fas fa-bolt me-2 text-warning"></i>Quick Actions
                    </h5>
                    
                    <div class="d-grid gap-2">
                        <a href="print-receipt.php?id=<?php echo $payment_id; ?>" 
                           class="btn btn-outline-primary"
                           target="_blank">
                            <i class="fas fa-print me-2"></i>Print Receipt
                        </a>
                        
                        <a href="../sales/view.php?id=<?php echo $payment['reservation_id']; ?>" 
                           class="btn btn-outline-info">
                            <i class="fas fa-handshake me-2"></i>View Reservation
                        </a>
                        
                        <a href="../customers/view.php?id=<?php echo $payment['customer_id']; ?>" 
                           class="btn btn-outline-secondary">
                            <i class="fas fa-user me-2"></i>View Customer
                        </a>
                        
                        <?php if ($payment['remaining_balance'] > 0): ?>
                        <a href="record.php?reservation_id=<?php echo $payment['reservation_id']; ?>" 
                           class="btn btn-success">
                            <i class="fas fa-plus-circle me-2"></i>Record Another Payment
                        </a>
                        <?php endif; ?>

                        <?php if ($payment['status'] == 'pending'): ?>
                        <hr>
                        <button class="btn btn-success" onclick="approvePayment()">
                            <i class="fas fa-check-circle me-2"></i>Approve Payment
                        </button>
                        <button class="btn btn-danger" onclick="cancelPayment()">
                            <i class="fas fa-times-circle me-2"></i>Cancel Payment
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

    </div>
</section>

<script>
function approvePayment() {
    if (confirm('Are you sure you want to approve this payment?')) {
        window.location.href = 'approve.php?id=<?php echo $payment_id; ?>&action=approve';
    }
}

function cancelPayment() {
    if (confirm('Are you sure you want to cancel this payment? This action cannot be undone.')) {
        window.location.href = 'approve.php?id=<?php echo $payment_id; ?>&action=cancel';
    }
}
</script>

<?php 
require_once '../../includes/footer.php';
?>