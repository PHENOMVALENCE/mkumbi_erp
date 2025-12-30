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
            COUNT(*) as total_refunds,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_refunds,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_refunds,
            SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed_refunds,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_refunds,
            COALESCE(SUM(CASE WHEN status = 'processed' THEN net_refund_amount ELSE 0 END), 0) as total_refunded,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN net_refund_amount ELSE 0 END), 0) as pending_amount,
            COALESCE(SUM(penalty_amount), 0) as total_penalties,
            COALESCE(SUM(CASE WHEN DATE(refund_date) = CURDATE() THEN net_refund_amount ELSE 0 END), 0) as today_refunds
        FROM refunds
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching refund stats: " . $e->getMessage());
    $stats = [
        'total_refunds' => 0,
        'pending_refunds' => 0,
        'approved_refunds' => 0,
        'processed_refunds' => 0,
        'rejected_refunds' => 0,
        'total_refunded' => 0,
        'pending_amount' => 0,
        'total_penalties' => 0,
        'today_refunds' => 0
    ];
}

// Build filter conditions
$where_conditions = ["r.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['status'])) {
    $where_conditions[] = "r.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['refund_reason'])) {
    $where_conditions[] = "r.refund_reason = ?";
    $params[] = $_GET['refund_reason'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "r.refund_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "r.refund_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(r.refund_number LIKE ? OR c.full_name LIKE ? OR res.reservation_number LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch refunds
try {
    $refunds_query = "
        SELECT 
            r.*,
            c.customer_id,
            c.full_name as customer_name,
            COALESCE(c.phone, c.phone1) as customer_phone,
            c.email as customer_email,
            res.reservation_number,
            res.total_amount as reservation_total,
            pl.plot_number,
            pl.block_number,
            pr.project_name,
            p.payment_number,
            p.payment_date,
            p.amount as payment_amount,
            creator.full_name as created_by_name,
            approver.full_name as approved_by_name,
            processor.full_name as processed_by_name
        FROM refunds r
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN reservations res ON r.reservation_id = res.reservation_id
        LEFT JOIN plots pl ON r.plot_id = pl.plot_id
        LEFT JOIN projects pr ON pl.project_id = pr.project_id
        LEFT JOIN payments p ON r.original_payment_id = p.payment_id
        LEFT JOIN users creator ON r.created_by = creator.user_id
        LEFT JOIN users approver ON r.approved_by = approver.user_id
        LEFT JOIN users processor ON r.processed_by = processor.user_id
        WHERE $where_clause
        ORDER BY r.refund_date DESC, r.created_at DESC
    ";
    $stmt = $conn->prepare($refunds_query);
    $stmt->execute($params);
    $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching refunds: " . $e->getMessage());
    $refunds = [];
}

$page_title = 'Refunds Management';
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
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.info { border-left-color: #17a2b8; }

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

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.status-badge.approved {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.processed {
    background: #d4edda;
    color: #155724;
}

.status-badge.rejected {
    background: #f8d7da;
    color: #721c24;
}

.reason-badge {
    padding: 0.25rem 0.6rem;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 600;
    background: #e9ecef;
    color: #495057;
}

.reason-badge.cancellation {
    background: #fff3cd;
    color: #856404;
}

.reason-badge.overpayment {
    background: #d1ecf1;
    color: #0c5460;
}

.reason-badge.plot_unavailable {
    background: #f8d7da;
    color: #721c24;
}

.refund-number {
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
    color: #dc3545;
}

.amount-success {
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
.icon-danger { background: #f8d7da; color: #721c24; }
.icon-info { background: #d1ecf1; color: #0c5460; }
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-undo text-danger me-2"></i>Refunds Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage payment refunds and cancellations</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Payments
                    </a>
                    <a href="request-refund.php" class="btn btn-danger">
                        <i class="fas fa-plus-circle me-1"></i> Request Refund
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
                    <div class="quick-stat-icon icon-danger">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div>
                        <div class="fw-bold">TSH <?php echo number_format((float)$stats['today_refunds'], 0); ?></div>
                        <small class="text-muted">Today's Refunds</small>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-icon icon-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?php echo number_format((int)$stats['pending_refunds']); ?></div>
                        <small class="text-muted">Pending Approval</small>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-icon icon-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?php echo number_format((int)$stats['processed_refunds']); ?></div>
                        <small class="text-muted">Processed</small>
                    </div>
                </div>
                <div class="quick-stat-item">
                    <div class="quick-stat-icon icon-info">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div>
                        <div class="fw-bold">TSH <?php echo number_format((float)$stats['total_penalties'], 0); ?></div>
                        <small class="text-muted">Total Penalties</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_refunds']); ?></div>
                    <div class="stats-label">Total Refunds</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['total_refunded'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Refunded</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['pending_amount'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Pending Amount</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format((int)$stats['rejected_refunds']); ?></div>
                    <div class="stats-label">Rejected</div>
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
                           placeholder="Refund #, customer, reservation..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                        <option value="processed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'processed') ? 'selected' : ''; ?>>Processed</option>
                        <option value="rejected" <?php echo (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Reason</label>
                    <select name="refund_reason" class="form-select">
                        <option value="">All Reasons</option>
                        <option value="cancellation" <?php echo (isset($_GET['refund_reason']) && $_GET['refund_reason'] == 'cancellation') ? 'selected' : ''; ?>>Cancellation</option>
                        <option value="overpayment" <?php echo (isset($_GET['refund_reason']) && $_GET['refund_reason'] == 'overpayment') ? 'selected' : ''; ?>>Overpayment</option>
                        <option value="plot_unavailable" <?php echo (isset($_GET['refund_reason']) && $_GET['refund_reason'] == 'plot_unavailable') ? 'selected' : ''; ?>>Plot Unavailable</option>
                        <option value="customer_request" <?php echo (isset($_GET['refund_reason']) && $_GET['refund_reason'] == 'customer_request') ? 'selected' : ''; ?>>Customer Request</option>
                        <option value="dispute" <?php echo (isset($_GET['refund_reason']) && $_GET['refund_reason'] == 'dispute') ? 'selected' : ''; ?>>Dispute</option>
                        <option value="other" <?php echo (isset($_GET['refund_reason']) && $_GET['refund_reason'] == 'other') ? 'selected' : ''; ?>>Other</option>
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
                <a href="refunds.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-redo me-1"></i> Reset Filters
                </a>
            </div>
        </div>

        <!-- Refunds Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover" id="refundsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Refund #</th>
                            <th>Customer</th>
                            <th>Reservation/Plot</th>
                            <th>Reason</th>
                            <th>Original Amount</th>
                            <th>Penalty</th>
                            <th>Net Refund</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($refunds)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                <p class="mb-2">No refunds found.</p>
                                <a href="request-refund.php" class="btn btn-danger btn-sm">
                                    <i class="fas fa-plus me-1"></i> Request First Refund
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($refunds as $refund): ?>
                        <tr>
                            <td>
                                <i class="fas fa-calendar text-danger me-1"></i>
                                <?php echo date('M d, Y', strtotime($refund['refund_date'])); ?>
                                <div class="small text-muted">
                                    <?php echo date('h:i A', strtotime($refund['created_at'])); ?>
                                </div>
                            </td>
                            <td>
                                <span class="refund-number">
                                    <?php echo htmlspecialchars($refund['refund_number']); ?>
                                </span>
                                <?php if (!empty($refund['reservation_number'])): ?>
                                <div class="small text-muted">
                                    Res: <?php echo htmlspecialchars($refund['reservation_number']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="customer-info">
                                    <div class="customer-avatar">
                                        <?php echo strtoupper(substr($refund['customer_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($refund['customer_name']); ?></div>
                                        <?php if (!empty($refund['customer_phone'])): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($refund['customer_phone']); ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($refund['plot_number'])): ?>
                                <div class="fw-bold">Plot <?php echo htmlspecialchars($refund['plot_number']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($refund['project_name']); ?></small>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="reason-badge <?php echo $refund['refund_reason']; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $refund['refund_reason'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format($refund['original_amount'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($refund['penalty_amount'] > 0): ?>
                                <span class="text-danger fw-bold">
                                    TSH <?php echo number_format($refund['penalty_amount'], 0); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="amount-highlight amount-success">
                                    TSH <?php echo number_format($refund['net_refund_amount'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $refund['status']; ?>">
                                    <?php echo ucfirst($refund['status']); ?>
                                </span>
                                <?php if ($refund['status'] == 'processed' && !empty($refund['processed_by_name'])): ?>
                                <div class="small text-muted mt-1">
                                    <i class="fas fa-user-check me-1"></i>
                                    <?php echo htmlspecialchars($refund['processed_by_name']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="view-refund.php?id=<?php echo $refund['refund_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($refund['status'] == 'pending'): ?>
                                    <a href="approve-refund.php?id=<?php echo $refund['refund_id']; ?>" 
                                       class="btn btn-outline-success action-btn"
                                       title="Approve">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($refund['status'] == 'approved'): ?>
                                    <a href="process-refund.php?id=<?php echo $refund['refund_id']; ?>" 
                                       class="btn btn-outline-info action-btn"
                                       title="Process Payment">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($refunds)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end">TOTALS:</th>
                            <th>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format(array_sum(array_column($refunds, 'original_amount')), 0); ?>
                                </span>
                            </th>
                            <th>
                                <span class="text-danger fw-bold">
                                    TSH <?php echo number_format(array_sum(array_column($refunds, 'penalty_amount')), 0); ?>
                                </span>
                            </th>
                            <th>
                                <span class="amount-highlight amount-success">
                                    TSH <?php echo number_format(array_sum(array_column($refunds, 'net_refund_amount')), 0); ?>
                                </span>
                            </th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    </div>
</section>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    const tableBody = $('#refundsTable tbody tr');
    const hasData = tableBody.length > 0 && !tableBody.first().find('td[colspan]').length;
    
    if (hasData) {
        $('#refundsTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            columnDefs: [
                { orderable: false, targets: -1 }
            ],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search refunds..."
            }
        });
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>