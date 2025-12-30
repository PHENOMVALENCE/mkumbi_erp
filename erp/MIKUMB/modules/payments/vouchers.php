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

// Fetch statistics
try {
    $stats_query = "
        SELECT 
            COUNT(*) as total_vouchers,
            SUM(CASE WHEN voucher_type = 'payment' THEN 1 ELSE 0 END) as payment_vouchers,
            SUM(CASE WHEN voucher_type = 'receipt' THEN 1 ELSE 0 END) as receipt_vouchers,
            SUM(CASE WHEN voucher_type = 'refund' THEN 1 ELSE 0 END) as refund_vouchers,
            SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved_vouchers,
            SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending_vouchers,
            COALESCE(SUM(CASE WHEN approval_status = 'approved' THEN amount ELSE 0 END), 0) as total_approved_amount,
            COALESCE(SUM(CASE WHEN approval_status = 'pending' THEN amount ELSE 0 END), 0) as total_pending_amount,
            COALESCE(SUM(CASE WHEN DATE(voucher_date) = CURDATE() AND approval_status = 'approved' THEN amount ELSE 0 END), 0) as today_amount
        FROM payment_vouchers
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching voucher stats: " . $e->getMessage());
    $stats = [
        'total_vouchers' => 0,
        'payment_vouchers' => 0,
        'receipt_vouchers' => 0,
        'refund_vouchers' => 0,
        'approved_vouchers' => 0,
        'pending_vouchers' => 0,
        'total_approved_amount' => 0,
        'total_pending_amount' => 0,
        'today_amount' => 0
    ];
}

// Build filter conditions
$where_conditions = ["pv.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['voucher_type'])) {
    $where_conditions[] = "pv.voucher_type = ?";
    $params[] = $_GET['voucher_type'];
}

if (!empty($_GET['approval_status'])) {
    $where_conditions[] = "pv.approval_status = ?";
    $params[] = $_GET['approval_status'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "pv.voucher_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "pv.voucher_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(pv.voucher_number LIKE ? OR c.full_name LIKE ? OR pv.transaction_reference LIKE ? OR pv.cheque_number LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch vouchers
try {
    $vouchers_query = "
        SELECT 
            pv.*,
            c.customer_id,
            c.full_name as customer_name,
            COALESCE(c.phone, c.phone1) as customer_phone,
            r.reservation_number,
            pl.plot_number,
            pl.block_number,
            pr.project_name,
            p.payment_number,
            p.payment_method,
            creator.full_name as created_by_name,
            approver.full_name as approved_by_name
        FROM payment_vouchers pv
        LEFT JOIN customers c ON pv.customer_id = c.customer_id
        LEFT JOIN reservations r ON pv.reservation_id = r.reservation_id
        LEFT JOIN plots pl ON r.plot_id = pl.plot_id
        LEFT JOIN projects pr ON pl.project_id = pr.project_id
        LEFT JOIN payments p ON pv.payment_id = p.payment_id
        LEFT JOIN users creator ON pv.created_by = creator.user_id
        LEFT JOIN users approver ON pv.approved_by = approver.user_id
        WHERE $where_clause
        ORDER BY pv.voucher_date DESC, pv.created_at DESC
    ";
    $stmt = $conn->prepare($vouchers_query);
    $stmt->execute($params);
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching vouchers: " . $e->getMessage());
    $vouchers = [];
}

$page_title = 'Payment Vouchers';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.purple { border-left-color: #6f42c1; }
.stats-card.orange { border-left-color: #fd7e14; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
}

.stats-label {
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.table-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.voucher-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.voucher-badge.payment {
    background: #d1ecf1;
    color: #0c5460;
}

.voucher-badge.receipt {
    background: #d4edda;
    color: #155724;
}

.voucher-badge.refund {
    background: #fff3cd;
    color: #856404;
}

.voucher-badge.adjustment {
    background: #e2e3e5;
    color: #383d41;
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
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

.status-badge.rejected {
    background: #f8d7da;
    color: #721c24;
}

.voucher-number {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
}

.amount-highlight {
    font-weight: 700;
    font-size: 1.1rem;
    color: #007bff;
}

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.customer-info {
    display: flex;
    align-items: center;
}

.customer-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.9rem;
    margin-right: 10px;
}

.quick-stats {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.quick-stat-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.quick-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.icon-success { background: #d4edda; color: #155724; }
.icon-warning { background: #fff3cd; color: #856404; }
.icon-primary { background: #cfe2ff; color: #084298; }
.icon-info { background: #d1ecf1; color: #0c5460; }

.type-icon {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
}

.type-icon.payment { background: #d1ecf1; color: #0c5460; }
.type-icon.receipt { background: #d4edda; color: #155724; }
.type-icon.refund { background: #fff3cd; color: #856404; }
.type-icon.adjustment { background: #e2e3e5; color: #383d41; }
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice text-primary me-2"></i>Payment Vouchers
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage payment vouchers and receipts</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Payments
                    </a>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#generateVoucherModal">
                        <i class="fas fa-plus-circle me-1"></i> Generate Voucher
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Quick Stats -->
        <div class="filter-card mb-4">
            <div class="quick-stats">
                <div class="quick-stat-item">
                    <div class="quick-stat-icon icon-success">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div>
                        <div class="fw-bold">TSH <?php echo number_format($stats['today_amount'], 0); ?></div>
                        <small class="text-muted">Today's Vouchers</small>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-icon icon-primary">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?php echo number_format((int)$stats['approved_vouchers']); ?></div>
                        <small class="text-muted">Approved</small>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-icon icon-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?php echo number_format((int)$stats['pending_vouchers']); ?></div>
                        <small class="text-muted">Pending Approval</small>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-icon icon-info">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?php echo number_format((int)$stats['total_vouchers']); ?></div>
                        <small class="text-muted">Total Vouchers</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format((int)$stats['payment_vouchers']); ?></div>
                    <div class="stats-label">Payment Vouchers</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['receipt_vouchers']); ?></div>
                    <div class="stats-label">Receipt Vouchers</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format((int)$stats['refund_vouchers']); ?></div>
                    <div class="stats-label">Refund Vouchers</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['total_approved_amount'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Approved Amount</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Voucher #, customer, reference..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Voucher Type</label>
                    <select name="voucher_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="payment" <?php echo (isset($_GET['voucher_type']) && $_GET['voucher_type'] == 'payment') ? 'selected' : ''; ?>>Payment</option>
                        <option value="receipt" <?php echo (isset($_GET['voucher_type']) && $_GET['voucher_type'] == 'receipt') ? 'selected' : ''; ?>>Receipt</option>
                        <option value="refund" <?php echo (isset($_GET['voucher_type']) && $_GET['voucher_type'] == 'refund') ? 'selected' : ''; ?>>Refund</option>
                        <option value="adjustment" <?php echo (isset($_GET['voucher_type']) && $_GET['voucher_type'] == 'adjustment') ? 'selected' : ''; ?>>Adjustment</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="approval_status" class="form-select">
                        <option value="">All Status</option>
                        <option value="approved" <?php echo (isset($_GET['approval_status']) && $_GET['approval_status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                        <option value="pending" <?php echo (isset($_GET['approval_status']) && $_GET['approval_status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="rejected" <?php echo (isset($_GET['approval_status']) && $_GET['approval_status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Date From</label>
                    <input type="date" 
                           name="date_from" 
                           class="form-control"
                           value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Date To</label>
                    <input type="date" 
                           name="date_to" 
                           class="form-control"
                           value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
            <div class="mt-2">
                <a href="vouchers.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-redo me-1"></i> Reset Filters
                </a>
            </div>
        </div>

        <!-- Vouchers Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover" id="vouchersTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Voucher #</th>
                            <th>Type</th>
                            <th>Customer</th>
                            <th>Reservation/Plot</th>
                            <th>Amount</th>
                            <th>Bank/Reference</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vouchers)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                <p class="mb-2">No vouchers found.</p>
                                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#generateVoucherModal">
                                    <i class="fas fa-plus me-1"></i> Generate First Voucher
                                </button>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($vouchers as $voucher): ?>
                        <tr>
                            <td>
                                <i class="fas fa-calendar text-primary me-1"></i>
                                <?php echo date('M d, Y', strtotime($voucher['voucher_date'])); ?>
                                <div class="small text-muted">
                                    <?php echo date('h:i A', strtotime($voucher['created_at'])); ?>
                                </div>
                            </td>
                            <td>
                                <span class="voucher-number">
                                    <?php echo htmlspecialchars($voucher['voucher_number']); ?>
                                </span>
                                <?php if (!empty($voucher['payment_number'])): ?>
                                <div class="small text-muted">
                                    Pay: <?php echo htmlspecialchars($voucher['payment_number']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="type-icon <?php echo $voucher['voucher_type']; ?> me-2">
                                        <?php
                                        $icons = [
                                            'payment' => 'fa-money-bill-wave',
                                            'receipt' => 'fa-receipt',
                                            'refund' => 'fa-undo',
                                            'adjustment' => 'fa-edit'
                                        ];
                                        ?>
                                        <i class="fas <?php echo $icons[$voucher['voucher_type']]; ?>"></i>
                                    </div>
                                    <div>
                                        <span class="voucher-badge <?php echo $voucher['voucher_type']; ?>">
                                            <?php echo ucfirst($voucher['voucher_type']); ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($voucher['customer_name'])): ?>
                                <div class="customer-info">
                                    <div class="customer-avatar">
                                        <?php echo strtoupper(substr($voucher['customer_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($voucher['customer_name']); ?></div>
                                        <?php if (!empty($voucher['customer_phone'])): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($voucher['customer_phone']); ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($voucher['reservation_number'])): ?>
                                <div class="fw-bold"><?php echo htmlspecialchars($voucher['reservation_number']); ?></div>
                                <small class="text-muted">
                                    Plot <?php echo htmlspecialchars($voucher['plot_number']); ?>
                                    <?php if (!empty($voucher['project_name'])): ?>
                                    - <?php echo htmlspecialchars($voucher['project_name']); ?>
                                    <?php endif; ?>
                                </small>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format($voucher['amount'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($voucher['bank_name'])): ?>
                                <div class="fw-bold"><?php echo htmlspecialchars($voucher['bank_name']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($voucher['account_number'])): ?>
                                <small class="text-muted">A/C: <?php echo htmlspecialchars($voucher['account_number']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($voucher['transaction_reference'])): ?>
                                <div class="small text-muted">
                                    <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($voucher['transaction_reference']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($voucher['cheque_number'])): ?>
                                <div class="small text-muted">
                                    <i class="fas fa-money-check"></i> <?php echo htmlspecialchars($voucher['cheque_number']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (empty($voucher['bank_name']) && empty($voucher['transaction_reference']) && empty($voucher['cheque_number'])): ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $voucher['approval_status']; ?>">
                                    <?php echo ucfirst($voucher['approval_status']); ?>
                                </span>
                                <?php if ($voucher['approval_status'] == 'approved' && !empty($voucher['approved_by_name'])): ?>
                                <div class="small text-muted mt-1">
                                    <i class="fas fa-user-check me-1"></i>
                                    <?php echo htmlspecialchars($voucher['approved_by_name']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="view-voucher.php?id=<?php echo $voucher['voucher_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (!empty($voucher['voucher_pdf_path'])): ?>
                                    <a href="../../<?php echo htmlspecialchars($voucher['voucher_pdf_path']); ?>" 
                                       class="btn btn-outline-secondary action-btn"
                                       title="Download PDF"
                                       target="_blank">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                    <?php else: ?>
                                    <a href="generate-voucher-pdf.php?id=<?php echo $voucher['voucher_id']; ?>" 
                                       class="btn btn-outline-secondary action-btn"
                                       title="Generate PDF"
                                       target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($voucher['approval_status'] == 'pending'): ?>
                                    <a href="approve-voucher.php?id=<?php echo $voucher['voucher_id']; ?>" 
                                       class="btn btn-outline-success action-btn"
                                       title="Approve">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($vouchers)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end">TOTALS:</th>
                            <th>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format(array_sum(array_column($vouchers, 'amount')), 0); ?>
                                </span>
                            </th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    </div>
</section>

<!-- Generate Voucher Modal -->
<div class="modal fade" id="generateVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>Generate Payment Voucher
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="generate-voucher.php" method="POST">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Select a payment to generate a voucher. The voucher will be created with the payment details.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Voucher Type *</label>
                        <select name="voucher_type" class="form-select" required>
                            <option value="">-- Select Type --</option>
                            <option value="payment">Payment Voucher</option>
                            <option value="receipt">Receipt Voucher</option>
                            <option value="refund">Refund Voucher</option>
                            <option value="adjustment">Adjustment Voucher</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Payment *</label>
                        <select name="payment_id" class="form-select" required id="paymentSelect">
                            <option value="">-- Select Payment --</option>
                            <?php
                            // Fetch payments without vouchers
                            $payments_query = "
                                SELECT 
                                    p.payment_id,
                                    p.payment_number,
                                    p.amount,
                                    p.payment_date,
                                    c.full_name as customer_name,
                                    r.reservation_number
                                FROM payments p
                                INNER JOIN reservations r ON p.reservation_id = r.reservation_id
                                INNER JOIN customers c ON r.customer_id = c.customer_id
                                WHERE p.company_id = ?
                                AND p.status = 'approved'
                                AND NOT EXISTS (
                                    SELECT 1 FROM payment_vouchers pv 
                                    WHERE pv.payment_id = p.payment_id
                                )
                                ORDER BY p.payment_date DESC
                                LIMIT 100
                            ";
                            $stmt = $conn->prepare($payments_query);
                            $stmt->execute([$company_id]);
                            $available_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($available_payments as $payment):
                            ?>
                            <option value="<?php echo $payment['payment_id']; ?>"
                                    data-amount="<?php echo $payment['amount']; ?>"
                                    data-customer="<?php echo htmlspecialchars($payment['customer_name']); ?>"
                                    data-reservation="<?php echo htmlspecialchars($payment['reservation_number']); ?>">
                                <?php echo htmlspecialchars($payment['payment_number']); ?> - 
                                <?php echo htmlspecialchars($payment['customer_name']); ?> - 
                                TSH <?php echo number_format($payment['amount'], 0); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Only approved payments without existing vouchers are shown</small>
                    </div>

                    <div id="paymentDetails" style="display: none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title fw-bold">Payment Details</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>Customer:</strong> <span id="detailCustomer"></span></p>
                                        <p class="mb-1"><strong>Reservation:</strong> <span id="detailReservation"></span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>Amount:</strong> <span id="detailAmount" class="text-success fw-bold"></span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Additional notes or description..."></textarea>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="autoApprove" name="auto_approve" value="1">
                        <label class="form-check-label" for="autoApprove">
                            Auto-approve this voucher
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i> Generate Voucher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    const tableBody = $('#vouchersTable tbody tr');
    const hasData = tableBody.length > 0 && !tableBody.first().find('td[colspan]').length;
    
    if (hasData) {
        $('#vouchersTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            columnDefs: [
                { orderable: false, targets: -1 }
            ],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search vouchers..."
            }
        });
    }

    // Payment selection handler
    $('#paymentSelect').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        
        if ($(this).val()) {
            const amount = selectedOption.data('amount');
            const customer = selectedOption.data('customer');
            const reservation = selectedOption.data('reservation');
            
            $('#detailCustomer').text(customer);
            $('#detailReservation').text(reservation);
            $('#detailAmount').text('TSH ' + parseFloat(amount).toLocaleString());
            
            $('#paymentDetails').slideDown();
        } else {
            $('#paymentDetails').slideUp();
        }
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>