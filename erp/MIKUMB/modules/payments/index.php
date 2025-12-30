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
            COUNT(*) as total_payments,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_payments,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_payments,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_payments,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as total_approved_amount,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as total_pending_amount,
            COALESCE(SUM(CASE WHEN MONTH(payment_date) = MONTH(CURDATE()) 
                AND YEAR(payment_date) = YEAR(CURDATE()) 
                AND status = 'approved' THEN amount ELSE 0 END), 0) as this_month_amount,
            COALESCE(SUM(CASE WHEN DATE(payment_date) = CURDATE() 
                AND status = 'approved' THEN amount ELSE 0 END), 0) as today_amount
        FROM payments
        WHERE company_id = ? 
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure all stats are numeric
    $stats = array_map(function($val) {
        return $val !== null ? $val : 0;
    }, $stats);
    
} catch (PDOException $e) {
    error_log("Error fetching payment stats: " . $e->getMessage());
    $stats = [
        'total_payments' => 0,
        'approved_payments' => 0,
        'pending_payments' => 0,
        'cancelled_payments' => 0,
        'total_approved_amount' => 0,
        'total_pending_amount' => 0,
        'this_month_amount' => 0,
        'today_amount' => 0
    ];
}

// Build filter conditions
$where_conditions = ["p.company_id = ? "];
$params = [$company_id];

if (! empty($_GET['status'])) {
    $where_conditions[] = "p.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['payment_method'])) {
    $where_conditions[] = "p.payment_method = ?";
    $params[] = $_GET['payment_method'];
}

if (!empty($_GET['payment_type'])) {
    $where_conditions[] = "p.payment_type = ?";
    $params[] = $_GET['payment_type'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "p.payment_date >= ? ";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "p. payment_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(c.full_name LIKE ? OR p.payment_number LIKE ?  OR p.receipt_number LIKE ?  OR p.transaction_reference LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch payments
try {
    $payments_query = "
        SELECT 
            p.*,
            r.reservation_number,
            r.total_amount as reservation_total,
            c.customer_id,
            c.full_name as customer_name,
            COALESCE(c.phone, c.phone1) as phone,
            pl.plot_number,
            pl.block_number,
            pr.project_name,
            u.full_name as created_by_name,
            approver.full_name as approved_by_name
        FROM payments p
        INNER JOIN reservations r ON p. reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots pl ON r.plot_id = pl.plot_id
        INNER JOIN projects pr ON pl.project_id = pr.project_id
        LEFT JOIN users u ON p.created_by = u.user_id
        LEFT JOIN users approver ON p.approved_by = approver.user_id
        WHERE $where_clause
        ORDER BY p.payment_date DESC, p.created_at DESC
    ";
    $stmt = $conn->prepare($payments_query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching payments: " . $e->getMessage());
    $payments = [];
}

$page_title = 'Payments Management';
require_once '../../includes/header.php';
?>

<style>
. stats-card {
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

.stats-card. primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.info { border-left-color: #17a2b8; }
. stats-card.purple { border-left-color: #6f42c1; }
.stats-card.orange { border-left-color: #fd7e14; }

.stats-number {
    font-size: 1.75rem;
    font-weight:  700;
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

.payment-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size:  0.8rem;
    font-weight:  600;
}

.payment-badge.approved {
    background: #d4edda;
    color:  #155724;
}

. payment-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.payment-badge.cancelled {
    background: #f8d7da;
    color:  #721c24;
}

.method-badge {
    padding: 0.25rem 0.6rem;
    border-radius:  15px;
    font-size:  0.75rem;
    font-weight: 600;
    background: #e9ecef;
    color:  #495057;
}

.method-badge.cash {
    background: #d4edda;
    color:  #155724;
}

. method-badge.bank_transfer {
    background: #d1ecf1;
    color:  #0c5460;
}

.method-badge.mobile_money {
    background: #fff3cd;
    color:  #856404;
}

. payment-number {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size:  0.85rem;
}

.amount-highlight {
    font-weight: 700;
    font-size: 1.1rem;
    color: #28a745;
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
    border-radius:  50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.9rem;
    margin-right:  10px;
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
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-money-bill-wave text-success me-2"></i>Payments Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Track and manage all customer payments</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="schedule.php" class="btn btn-info me-2">
                        <i class="fas fa-calendar-alt me-1"></i> Payment Schedules
                    </a>
                    <a href="record.php" class="btn btn-success">
                        <i class="fas fa-plus-circle me-1"></i> Record Payment
                    </a>
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
                        <div class="fw-bold">TSH <?php echo number_format((float)$stats['today_amount'], 0); ?></div>
                        <small class="text-muted">Today's Collection</small>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-icon icon-primary">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div>
                        <div class="fw-bold">TSH <?php echo number_format((float)$stats['this_month_amount'], 0); ?></div>
                        <small class="text-muted">This Month</small>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-icon icon-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?php echo number_format((int)$stats['pending_payments']); ?></div>
                        <small class="text-muted">Pending Approval</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_payments']); ?></div>
                    <div class="stats-label">Total Payments</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['total_approved_amount'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Approved Amount</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['total_pending_amount'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Pending Amount</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format((int)$stats['approved_payments']); ?></div>
                    <div class="stats-label">Approved</div>
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
                           placeholder="Customer, payment #, receipt..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="approved" <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                        <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <option value="">All Methods</option>
                        <option value="cash" <?php echo (isset($_GET['payment_method']) && $_GET['payment_method'] == 'cash') ? 'selected' : ''; ?>>Cash</option>
                        <option value="bank_transfer" <?php echo (isset($_GET['payment_method']) && $_GET['payment_method'] == 'bank_transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                        <option value="mobile_money" <?php echo (isset($_GET['payment_method']) && $_GET['payment_method'] == 'mobile_money') ? 'selected' : ''; ?>>Mobile Money</option>
                        <option value="cheque" <?php echo (isset($_GET['payment_method']) && $_GET['payment_method'] == 'cheque') ? 'selected' : ''; ?>>Cheque</option>
                        <option value="card" <?php echo (isset($_GET['payment_method']) && $_GET['payment_method'] == 'card') ? 'selected' : ''; ?>>Card</option>
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
                <a href="index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-redo me-1"></i> Reset Filters
                </a>
            </div>
        </div>

        <!-- Payments Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover" id="paymentsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Payment #</th>
                            <th>Customer</th>
                            <th>Plot/Project</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Receipt #</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (! empty($payments)): ?>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td>
                                <i class="fas fa-calendar text-primary me-1"></i>
                                <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                                <div class="small text-muted">
                                    <?php echo date('h:i A', strtotime($payment['created_at'])); ?>
                                </div>
                            </td>
                            <td>
                                <span class="payment-number">
                                    <?php echo htmlspecialchars($payment['payment_number'] ?? 'N/A'); ?>
                                </span>
                                <div class="small text-muted">
                                    Res: <?php echo htmlspecialchars($payment['reservation_number']); ?>
                                </div>
                            </td>
                            <td>
                                <div class="customer-info">
                                    <div class="customer-avatar">
                                        <?php echo strtoupper(substr($payment['customer_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($payment['customer_name']); ?></div>
                                        <?php if (!empty($payment['phone'])): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($payment['phone']); ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="fw-bold">Plot <?php echo htmlspecialchars($payment['plot_number']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($payment['project_name']); ?></small>
                            </td>
                            <td>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format((float)$payment['amount'], 0); ?>
                                </span>
                                <?php if ($payment['tax_amount'] > 0): ?>
                                <div class="small text-muted">
                                    Tax: TSH <?php echo number_format((float)$payment['tax_amount'], 0); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="method-badge <?php echo $payment['payment_method']; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?>
                                </span>
                                <?php if (!empty($payment['bank_name'])): ?>
                                <div class="small text-muted mt-1">
                                    <?php echo htmlspecialchars($payment['bank_name']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($payment['transaction_reference'])): ?>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($payment['transaction_reference']); ?>
                                </small>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($payment['receipt_number'])): ?>
                                <span class="payment-number">
                                    <?php echo htmlspecialchars($payment['receipt_number']); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="payment-badge <?php echo $payment['status']; ?>">
                                    <?php echo ucfirst($payment['status']); ?>
                                </span>
                                <?php if ($payment['status'] == 'approved' && !empty($payment['approved_by_name'])): ?>
                                <div class="small text-muted mt-1">
                                    <i class="fas fa-user-check me-1"></i>
                                    <?php echo htmlspecialchars($payment['approved_by_name']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="view.php? id=<?php echo $payment['payment_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="print-receipt.php?id=<?php echo $payment['payment_id']; ?>" 
                                       class="btn btn-outline-secondary action-btn"
                                       title="Print Receipt"
                                       target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php if ($payment['status'] == 'pending'): ?>
                                    <a href="approve.php?id=<?php echo $payment['payment_id']; ?>" 
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
                    <?php if (! empty($payments)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="4" class="text-end">TOTALS:</th>
                            <th>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format((float)array_sum(array_column($payments, 'amount')), 0); ?>
                                </span>
                            </th>
                            <th colspan="5"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            
            <?php if (empty($payments)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                <p class="mb-2">No payments found.</p>
                <a href="record.php" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Record First Payment
                </a>
            </div>
            <?php endif; ?>
        </div>

    </div>
</section>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min. css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    <?php if (!empty($payments)): ?>
    // Only initialize DataTables if there are payments
    $('#paymentsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true,
        columnDefs: [
            { orderable: false, targets: -1 }
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search payments..."
        }
    });
    <?php endif; ?>
});
</script>

<?php 
require_once '../../includes/footer.php';
?>