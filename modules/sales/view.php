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

// Get reservation ID from URL
$reservation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$reservation_id) {
    $_SESSION['error'] = "Invalid reservation ID";
    header('Location: index.php');
    exit;
}

// Fetch reservation details with related data
try {
    $reservation_sql = "SELECT r.*,
                               c.full_name as customer_name,
                               c.phone as customer_phone,
                               c.email as customer_email,
                               c.national_id as customer_national_id,
                               c.address as customer_address,
                               p.plot_number,
                               p.block_number,
                               p.area as plot_area,
                               p.price_per_sqm,
                               p.selling_price,
                               p.status as plot_status,
                               pr.project_name,
                               pr.physical_location as project_location,
                               u.full_name as created_by_name
                        FROM reservations r
                        LEFT JOIN customers c ON r.customer_id = c.customer_id
                        LEFT JOIN plots p ON r.plot_id = p.plot_id
                        LEFT JOIN projects pr ON p.project_id = pr.project_id
                        LEFT JOIN users u ON r.created_by = u.user_id
                        WHERE r.reservation_id = ? AND r.company_id = ?";
    
    $reservation_stmt = $conn->prepare($reservation_sql);
    $reservation_stmt->execute([$reservation_id, $company_id]);
    $reservation = $reservation_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        $_SESSION['error'] = "Reservation not found";
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching reservation: " . $e->getMessage());
    $_SESSION['error'] = "Error loading reservation details";
    header('Location: index.php');
    exit;
}

// Fetch payment history with all payment method details
try {
    $payments_sql = "SELECT p.*,
                            u.full_name as received_by_name
                     FROM payments p
                     LEFT JOIN users u ON p.created_by = u.user_id
                     WHERE p.reservation_id = ? AND p.company_id = ?
                     ORDER BY p.payment_date DESC";
    
    $payments_stmt = $conn->prepare($payments_sql);
    $payments_stmt->execute([$reservation_id, $company_id]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching payments: " . $e->getMessage());
    $payments = [];
}

// Calculate payment statistics
$total_paid = 0;
foreach ($payments as $payment) {
    if ($payment['status'] === 'approved') {
        $total_paid += $payment['amount'];
    }
}

$total_amount = floatval($reservation['total_amount']);
$outstanding_balance = $total_amount - $total_paid;
$payment_progress = $total_amount > 0 ? ($total_paid / $total_amount) * 100 : 0;

// Helper function to get payment method details
function getPaymentMethodDetails($payment) {
    $method = $payment['payment_method'];
    $details = [];
    
    switch($method) {
        case 'cash':
            $details[] = 'Cash Payment';
            break;
            
        case 'bank_transfer':
            if ($payment['transfer_from_bank']) {
                $details[] = '<strong>From:</strong> ' . htmlspecialchars($payment['transfer_from_bank']);
                if ($payment['transfer_from_account']) {
                    $details[] = 'A/C: ' . htmlspecialchars($payment['transfer_from_account']);
                }
            }
            if ($payment['transfer_to_bank']) {
                $details[] = '<strong>To:</strong> ' . htmlspecialchars($payment['transfer_to_bank']);
                if ($payment['transfer_to_account']) {
                    $details[] = 'A/C: ' . htmlspecialchars($payment['transfer_to_account']);
                }
            }
            break;
            
        case 'bank_deposit':
            if ($payment['deposit_bank']) {
                $details[] = '<strong>Bank:</strong> ' . htmlspecialchars($payment['deposit_bank']);
            }
            if ($payment['deposit_account']) {
                $details[] = '<strong>Account:</strong> ' . htmlspecialchars($payment['deposit_account']);
            }
            if ($payment['depositor_name']) {
                $details[] = '<strong>Depositor:</strong> ' . htmlspecialchars($payment['depositor_name']);
            }
            break;
            
        case 'mobile_money':
            if ($payment['mobile_money_provider']) {
                $details[] = '<strong>Provider:</strong> ' . htmlspecialchars($payment['mobile_money_provider']);
            }
            if ($payment['mobile_money_number']) {
                $details[] = '<strong>Number:</strong> ' . htmlspecialchars($payment['mobile_money_number']);
            }
            if ($payment['mobile_money_name']) {
                $details[] = '<strong>Name:</strong> ' . htmlspecialchars($payment['mobile_money_name']);
            }
            break;
            
        case 'cheque':
            if ($payment['bank_name']) {
                $details[] = '<strong>Bank:</strong> ' . htmlspecialchars($payment['bank_name']);
            }
            break;
    }
    
    if ($payment['transaction_reference']) {
        $details[] = '<strong>Ref:</strong> ' . htmlspecialchars($payment['transaction_reference']);
    }
    
    return implode(' | ', $details);
}

$page_title = 'Reservation Details - ' . $reservation['reservation_number'];
require_once '../../includes/header.php';
?>

<style>
.gradient-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
}

.gradient-header h2 {
    margin: 0;
    font-size: 1.75rem;
    font-weight: 700;
}

.gradient-header .reservation-number {
    font-size: 2.5rem;
    font-weight: 800;
    margin-bottom: 0.5rem;
}

.gradient-header .customer-info {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 255, 255, 0.2);
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
    height: 100%;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.12);
}

.stat-card .stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 1rem;
}

.stat-card.total .stat-icon {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.stat-card.paid .stat-icon {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: white;
}

.stat-card.outstanding .stat-icon {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
}

.stat-card.progress .stat-icon {
    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
    color: white;
}

.stat-label {
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
    margin-top: 0.25rem;
}

.nav-tabs-custom {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.nav-tabs {
    border-bottom: 2px solid #e9ecef;
    padding: 0 1rem;
}

.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
    padding: 1rem 1.5rem;
    font-weight: 500;
    transition: all 0.3s;
    position: relative;
}

.nav-tabs .nav-link:hover {
    color: #667eea;
    background: transparent;
}

.nav-tabs .nav-link.active {
    color: #667eea;
    background: transparent;
    border: none;
}

.nav-tabs .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    border-radius: 3px 3px 0 0;
}

.info-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.info-section-header {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
}

.info-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f3f5;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    flex: 0 0 180px;
    font-weight: 600;
    color: #495057;
}

.info-value {
    flex: 1;
    color: #6c757d;
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.completed {
    background: #cce5ff;
    color: #004085;
}

.status-badge.cancelled {
    background: #f8d7da;
    color: #721c24;
}

.status-badge.draft {
    background: #fff3cd;
    color: #856404;
}

.status-badge.pending_approval {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.approved {
    background: #d4edda;
    color: #155724;
}

.status-badge.rejected {
    background: #f8d7da;
    color: #721c24;
}

.payment-timeline {
    position: relative;
    padding-left: 2rem;
}

.payment-timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}

.payment-item {
    position: relative;
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    transition: all 0.3s;
}

.payment-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border-color: #667eea;
}

.payment-item::before {
    content: '';
    position: absolute;
    left: -24px;
    top: 20px;
    width: 16px;
    height: 16px;
    background: white;
    border: 3px solid #28a745;
    border-radius: 50%;
    box-shadow: 0 0 0 4px #f8f9fa;
}

.payment-item.pending_approval::before {
    border-color: #17a2b8;
}

.payment-item.pending::before {
    border-color: #ffc107;
}

.payment-item.rejected::before,
.payment-item.cancelled::before {
    border-color: #dc3545;
}

.progress-bar-custom {
    height: 30px;
    border-radius: 15px;
    background: #e9ecef;
    overflow: hidden;
    position: relative;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #11998e 0%, #38ef7d 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 0.85rem;
    transition: width 0.6s ease;
}

.quick-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.action-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s;
    text-decoration: none;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.action-btn.primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.action-btn.success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: white;
}

.action-btn.warning {
    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
    color: white;
}

.action-btn.danger {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.3;
}

.payment-method-details {
    background: #f8f9fa;
    padding: 0.75rem;
    border-radius: 6px;
    margin-top: 0.5rem;
    font-size: 0.875rem;
    line-height: 1.6;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice text-primary me-2"></i>Reservation Details
                </h1>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Gradient Header -->
        <div class="gradient-header">
            <div class="reservation-number">
                <?php echo htmlspecialchars($reservation['reservation_number']); ?>
            </div>
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h2>
                        <i class="fas fa-map-marked-alt me-2"></i>
                        Plot <?php echo htmlspecialchars($reservation['plot_number']); ?>
                        <?php if ($reservation['block_number']): ?>
                            (Block <?php echo htmlspecialchars($reservation['block_number']); ?>)
                        <?php endif; ?>
                    </h2>
                    <p class="mb-0 mt-2">
                        <i class="fas fa-project-diagram me-2"></i>
                        <?php echo htmlspecialchars($reservation['project_name']); ?>
                        - <?php echo htmlspecialchars($reservation['project_location']); ?>
                    </p>
                </div>
                <div class="text-end">
                    <span class="status-badge <?php echo strtolower(str_replace(' ', '_', $reservation['status'])); ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $reservation['status'])); ?>
                    </span>
                </div>
            </div>
            
            <div class="customer-info">
                <div class="row">
                    <div class="col-md-8">
                        <h5 class="mb-2">
                            <i class="fas fa-user me-2"></i>
                            <?php echo htmlspecialchars($reservation['customer_name']); ?>
                        </h5>
                        <div class="d-flex gap-3 flex-wrap">
                            <?php if ($reservation['customer_phone']): ?>
                                <span>
                                    <i class="fas fa-phone me-1"></i>
                                    <a href="tel:<?php echo htmlspecialchars($reservation['customer_phone']); ?>" 
                                       class="text-white text-decoration-none">
                                        <?php echo htmlspecialchars($reservation['customer_phone']); ?>
                                    </a>
                                </span>
                            <?php endif; ?>
                            <?php if ($reservation['customer_email']): ?>
                                <span>
                                    <i class="fas fa-envelope me-1"></i>
                                    <a href="mailto:<?php echo htmlspecialchars($reservation['customer_email']); ?>" 
                                       class="text-white text-decoration-none">
                                        <?php echo htmlspecialchars($reservation['customer_email']); ?>
                                    </a>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="opacity-75">Reservation Date</div>
                        <div class="h5 mb-0">
                            <?php echo date('d M Y', strtotime($reservation['reservation_date'])); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card total">
                    <div class="stat-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-label">Total Amount</div>
                    <div class="stat-value">
                        TZS <?php echo number_format($total_amount, 2); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card paid">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-label">Total Paid</div>
                    <div class="stat-value">
                        TZS <?php echo number_format($total_paid, 2); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card outstanding">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="stat-label">Outstanding</div>
                    <div class="stat-value">
                        TZS <?php echo number_format($outstanding_balance, 2); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card progress">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-label">Progress</div>
                    <div class="stat-value">
                        <?php echo number_format($payment_progress, 1); ?>%
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Progress Bar -->
        <div class="info-section">
            <div class="info-section-header">
                <i class="fas fa-tasks me-2 text-primary"></i>
                Payment Progress
            </div>
            <div class="progress-bar-custom">
                <div class="progress-fill" style="width: <?php echo min(100, $payment_progress); ?>%;">
                    <?php echo number_format($payment_progress, 1); ?>% Complete
                </div>
            </div>
            <div class="d-flex justify-content-between mt-2 text-muted small">
                <span>TZS 0.00</span>
                <span>TZS <?php echo number_format($total_amount, 2); ?></span>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="info-section">
            <div class="info-section-header">
                <i class="fas fa-bolt me-2 text-warning"></i>
                Quick Actions
            </div>
            <div class="quick-actions">
                <a href="edit.php?id=<?php echo $reservation_id; ?>" class="action-btn primary">
                    <i class="fas fa-edit"></i> Edit Reservation
                </a>
                <a href="../payments/create.php?reservation_id=<?php echo $reservation_id; ?>" class="action-btn success">
                    <i class="fas fa-plus-circle"></i> Add Payment
                </a>
                <a href="print.php?id=<?php echo $reservation_id; ?>" target="_blank" class="action-btn warning">
                    <i class="fas fa-print"></i> Print Receipt
                </a>
                <?php if ($reservation['status'] !== 'cancelled'): ?>
                <a href="cancel.php?id=<?php echo $reservation_id; ?>" 
                   class="action-btn danger"
                   onclick="return confirm('Are you sure you want to cancel this reservation?');">
                    <i class="fas fa-ban"></i> Cancel Reservation
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="nav-tabs-custom">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" data-bs-toggle="tab" href="#overview">
                        <i class="fas fa-info-circle"></i> Overview
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#payments">
                        <i class="fas fa-money-bill-wave"></i> Payment History
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#plot">
                        <i class="fas fa-map-marked-alt"></i> Plot Details
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#customer">
                        <i class="fas fa-user"></i> Customer Details
                    </a>
                </li>
            </ul>
        </div>

        <!-- Tab Content -->
        <div class="tab-content">
            
            <!-- Overview Tab -->
            <div class="tab-pane fade show active" id="overview">
                <div class="row">
                    <div class="col-md-6">
                        <!-- Reservation Information -->
                        <div class="info-section">
                            <div class="info-section-header">
                                <i class="fas fa-file-contract me-2 text-primary"></i>
                                Reservation Information
                            </div>
                            <div class="info-row">
                                <div class="info-label">Reservation Number:</div>
                                <div class="info-value">
                                    <strong><?php echo htmlspecialchars($reservation['reservation_number']); ?></strong>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Reservation Date:</div>
                                <div class="info-value">
                                    <?php echo date('d M Y', strtotime($reservation['reservation_date'])); ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Status:</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo strtolower(str_replace(' ', '_', $reservation['status'])); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $reservation['status'])); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Title Holder:</div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($reservation['title_holder_name'] ?: 'Same as customer'); ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Created By:</div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($reservation['created_by_name'] ?? 'N/A'); ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Created At:</div>
                                <div class="info-value">
                                    <?php echo date('d M Y H:i', strtotime($reservation['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <!-- Payment Terms -->
                        <div class="info-section">
                            <div class="info-section-header">
                                <i class="fas fa-money-bill-wave me-2 text-success"></i>
                                Payment Terms
                            </div>
                            <div class="info-row">
                                <div class="info-label">Total Amount:</div>
                                <div class="info-value">
                                    <strong>TZS <?php echo number_format($reservation['total_amount'], 2); ?></strong>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Down Payment:</div>
                                <div class="info-value">
                                    TZS <?php echo number_format($reservation['down_payment'], 2); ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Remaining Balance:</div>
                                <div class="info-value">
                                    TZS <?php echo number_format($reservation['total_amount'] - $reservation['down_payment'], 2); ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Payment Periods:</div>
                                <div class="info-value">
                                    <?php echo $reservation['payment_periods']; ?> installments
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Installment Amount:</div>
                                <div class="info-value">
                                    TZS <?php echo number_format($reservation['installment_amount'], 2); ?>
                                </div>
                            </div>
                            <?php if ($reservation['discount_percentage'] > 0 || $reservation['discount_amount'] > 0): ?>
                            <div class="info-row">
                                <div class="info-label">Discount:</div>
                                <div class="info-value">
                                    <?php echo number_format($reservation['discount_percentage'], 2); ?>% 
                                    (TZS <?php echo number_format($reservation['discount_amount'], 2); ?>)
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment History Tab -->
            <div class="tab-pane fade" id="payments">
                <div class="info-section">
                    <div class="info-section-header">
                        <i class="fas fa-history me-2 text-success"></i>
                        Payment History (<?php echo count($payments); ?> payments)
                    </div>

                    <?php if (empty($payments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-receipt"></i>
                            <p class="mt-3">No payments recorded yet</p>
                            <a href="../payments/create.php?reservation_id=<?php echo $reservation_id; ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-plus-circle me-2"></i>Add First Payment
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="payment-timeline">
                            <?php foreach ($payments as $payment): ?>
                                <div class="payment-item <?php echo strtolower($payment['status']); ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-2">
                                            <div class="text-muted small">Payment #</div>
                                            <div class="fw-bold">
                                                <?php echo htmlspecialchars($payment['payment_number']); ?>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="text-muted small">Date</div>
                                            <div><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="text-muted small">Amount</div>
                                            <div class="fw-bold text-success">
                                                TZS <?php echo number_format($payment['amount'], 2); ?>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="text-muted small">Method</div>
                                            <div><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="text-muted small">Status</div>
                                            <div>
                                                <span class="status-badge <?php echo strtolower($payment['status']); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $payment['status'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-md-2 text-end">
                                            <a href="../payments/view.php?id=<?php echo $payment['payment_id']; ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <!-- Payment Method Details -->
                                    <?php 
                                    $methodDetails = getPaymentMethodDetails($payment);
                                    if ($methodDetails): 
                                    ?>
                                    <div class="payment-method-details">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <?php echo $methodDetails; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($payment['remarks']): ?>
                                        <div class="mt-2 pt-2 border-top">
                                            <small class="text-muted">
                                                <i class="fas fa-comment me-1"></i>
                                                <?php echo htmlspecialchars($payment['remarks']); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Plot Details Tab -->
            <div class="tab-pane fade" id="plot">
                <div class="info-section">
                    <div class="info-section-header">
                        <i class="fas fa-map-marked-alt me-2 text-info"></i>
                        Plot Information
                    </div>
                    <div class="info-row">
                        <div class="info-label">Project:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($reservation['project_name']); ?></strong>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Location:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($reservation['project_location']); ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Plot Number:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($reservation['plot_number']); ?></strong>
                        </div>
                    </div>
                    <?php if ($reservation['block_number']): ?>
                    <div class="info-row">
                        <div class="info-label">Block Number:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($reservation['block_number']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">Plot Area:</div>
                        <div class="info-value">
                            <?php echo number_format($reservation['plot_area'], 2); ?> m²
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Price per m²:</div>
                        <div class="info-value">
                            TZS <?php echo number_format($reservation['price_per_sqm'], 2); ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Base Selling Price:</div>
                        <div class="info-value">
                            TZS <?php echo number_format($reservation['selling_price'], 2); ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Plot Status:</div>
                        <div class="info-value">
                            <span class="status-badge <?php echo strtolower($reservation['plot_status']); ?>">
                                <?php echo ucfirst($reservation['plot_status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Details Tab -->
            <div class="tab-pane fade" id="customer">
                <div class="info-section">
                    <div class="info-section-header">
                        <i class="fas fa-user me-2 text-warning"></i>
                        Customer Information
                    </div>
                    <div class="info-row">
                        <div class="info-label">Full Name:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($reservation['customer_name']); ?></strong>
                        </div>
                    </div>
                    <?php if ($reservation['customer_phone']): ?>
                    <div class="info-row">
                        <div class="info-label">Phone:</div>
                        <div class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($reservation['customer_phone']); ?>">
                                <?php echo htmlspecialchars($reservation['customer_phone']); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($reservation['customer_email']): ?>
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value">
                            <a href="mailto:<?php echo htmlspecialchars($reservation['customer_email']); ?>">
                                <?php echo htmlspecialchars($reservation['customer_email']); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($reservation['customer_national_id']): ?>
                    <div class="info-row">
                        <div class="info-label">National ID:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($reservation['customer_national_id']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($reservation['customer_address']): ?>
                    <div class="info-row">
                        <div class="info-label">Address:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($reservation['customer_address']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">Actions:</div>
                        <div class="info-value">
                            <a href="../customers/view.php?id=<?php echo $reservation['customer_id']; ?>" 
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye me-1"></i>View Full Profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</section>

<?php 
require_once '../../includes/footer.php';
?>