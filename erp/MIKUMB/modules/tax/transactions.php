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
            COUNT(*) as total_transactions,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'filed' THEN 1 ELSE 0 END) as filed_count,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            COALESCE(SUM(tax_amount), 0) as total_tax,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN tax_amount ELSE 0 END), 0) as pending_tax,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN tax_amount ELSE 0 END), 0) as paid_tax
        FROM tax_transactions
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching tax transaction stats: " . $e->getMessage());
    $stats = [
        'total_transactions' => 0,
        'pending_count' => 0,
        'filed_count' => 0,
        'paid_count' => 0,
        'total_tax' => 0,
        'pending_tax' => 0,
        'paid_tax' => 0
    ];
}

// Build filter conditions
$where_conditions = ["tt.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['tax_type'])) {
    $where_conditions[] = "tt.tax_type_id = ?";
    $params[] = (int)$_GET['tax_type'];
}

if (!empty($_GET['transaction_type'])) {
    $where_conditions[] = "tt.transaction_type = ?";
    $params[] = $_GET['transaction_type'];
}

if (!empty($_GET['status'])) {
    $where_conditions[] = "tt.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "tt.transaction_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "tt.transaction_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(tt.transaction_number LIKE ? OR tt.invoice_number LIKE ? OR tt.description LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch transactions
try {
    $query = "
        SELECT 
            tt.*,
            tx.tax_name,
            tx.tax_code,
            tx.tax_rate,
            c.full_name as customer_name,
            s.supplier_name,
            u.full_name as created_by_name
        FROM tax_transactions tt
        INNER JOIN tax_types tx ON tt.tax_type_id = tx.tax_type_id
        LEFT JOIN customers c ON tt.customer_id = c.customer_id
        LEFT JOIN suppliers s ON tt.supplier_id = s.supplier_id
        LEFT JOIN users u ON tt.created_by = u.user_id
        WHERE $where_clause
        ORDER BY tt.transaction_date DESC, tt.created_at DESC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching tax transactions: " . $e->getMessage());
    $transactions = [];
}

// Fetch tax types for filter
try {
    $tax_types_query = "SELECT tax_type_id, tax_name, tax_code FROM tax_types WHERE company_id = ? AND is_active = 1 ORDER BY tax_name";
    $stmt = $conn->prepare($tax_types_query);
    $stmt->execute([$company_id]);
    $tax_types_filter = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tax_types_filter = [];
}

$page_title = 'Tax Transactions';
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
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 1.5rem;
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

.transaction-number {
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

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.pending { background: #fff3cd; color: #856404; }
.status-badge.filed { background: #cfe2ff; color: #084298; }
.status-badge.paid { background: #d4edda; color: #155724; }
.status-badge.cancelled { background: #f8d7da; color: #721c24; }

.type-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.type-badge.sales { background: #d4edda; color: #155724; }
.type-badge.purchase { background: #cfe2ff; color: #084298; }
.type-badge.payroll { background: #e7e7ff; color: #3d3d99; }
.type-badge.withholding { background: #fff3cd; color: #856404; }
.type-badge.other { background: #e9ecef; color: #495057; }

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    opacity: 0.5;
    margin-bottom: 1rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-receipt text-info me-2"></i>Tax Transactions
                </h1>
                <p class="text-muted small mb-0 mt-1">Track and manage tax collections and payments</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="types.php" class="btn btn-outline-primary me-2">
                        <i class="fas fa-percentage me-1"></i> Tax Types
                    </a>
                    <a href="reports.php" class="btn btn-outline-success me-2">
                        <i class="fas fa-chart-bar me-1"></i> Reports
                    </a>
                    <a href="add-transaction.php" class="btn btn-info">
                        <i class="fas fa-plus-circle me-1"></i> New Transaction
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_transactions']); ?></div>
                    <div class="stats-label">Total Transactions</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format((int)$stats['pending_count']); ?></div>
                    <div class="stats-label">Pending</div>
                    <small class="text-muted">TSH <?php echo number_format((float)$stats['pending_tax'] / 1000000, 1); ?>M</small>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format((int)$stats['filed_count']); ?></div>
                    <div class="stats-label">Filed</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['paid_count']); ?></div>
                    <div class="stats-label">Paid</div>
                    <small class="text-muted">TSH <?php echo number_format((float)$stats['paid_tax'] / 1000000, 1); ?>M</small>
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
                           placeholder="Transaction #, invoice #..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Tax Type</label>
                    <select name="tax_type" class="form-select">
                        <option value="">All Types</option>
                        <?php foreach ($tax_types_filter as $tax): ?>
                        <option value="<?php echo $tax['tax_type_id']; ?>" 
                                <?php echo (isset($_GET['tax_type']) && $_GET['tax_type'] == $tax['tax_type_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tax['tax_code']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Type</label>
                    <select name="transaction_type" class="form-select">
                        <option value="">All</option>
                        <option value="sales" <?php echo (isset($_GET['transaction_type']) && $_GET['transaction_type'] == 'sales') ? 'selected' : ''; ?>>Sales</option>
                        <option value="purchase" <?php echo (isset($_GET['transaction_type']) && $_GET['transaction_type'] == 'purchase') ? 'selected' : ''; ?>>Purchase</option>
                        <option value="payroll" <?php echo (isset($_GET['transaction_type']) && $_GET['transaction_type'] == 'payroll') ? 'selected' : ''; ?>>Payroll</option>
                        <option value="withholding" <?php echo (isset($_GET['transaction_type']) && $_GET['transaction_type'] == 'withholding') ? 'selected' : ''; ?>>Withholding</option>
                        <option value="other" <?php echo (isset($_GET['transaction_type']) && $_GET['transaction_type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="filed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'filed') ? 'selected' : ''; ?>>Filed</option>
                        <option value="paid" <?php echo (isset($_GET['status']) && $_GET['status'] == 'paid') ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div class="col-md-12 text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="transactions.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="table-card">
            <div class="card-header d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2 text-info"></i>
                    Tax Transactions (<?php echo number_format(count($transactions)); ?>)
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="transactionsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Transaction #</th>
                            <th>Tax Type</th>
                            <th>Type</th>
                            <th>Party</th>
                            <th>Taxable Amount</th>
                            <th>Tax Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="9" class="empty-state">
                                <i class="fas fa-receipt"></i>
                                <h5>No tax transactions found</h5>
                                <p class="mb-3">Start tracking tax collections and payments</p>
                                <a href="add-transaction.php" class="btn btn-info btn-lg">
                                    <i class="fas fa-plus me-2"></i>Record First Transaction
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td>
                                <i class="fas fa-calendar text-info me-1"></i>
                                <?php echo date('M d, Y', strtotime($trans['transaction_date'])); ?>
                            </td>
                            <td>
                                <span class="transaction-number">
                                    <?php echo htmlspecialchars($trans['transaction_number']); ?>
                                </span>
                                <?php if (!empty($trans['invoice_number'])): ?>
                                <br><small class="text-muted">Inv: <?php echo htmlspecialchars($trans['invoice_number']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($trans['tax_code']); ?></strong>
                                <br><small class="text-muted"><?php echo number_format((float)$trans['tax_rate'], 2); ?>%</small>
                            </td>
                            <td>
                                <span class="type-badge <?php echo $trans['transaction_type']; ?>">
                                    <?php echo ucfirst($trans['transaction_type']); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if (!empty($trans['customer_name'])) {
                                    echo '<i class="fas fa-user text-success me-1"></i>' . htmlspecialchars($trans['customer_name']);
                                } elseif (!empty($trans['supplier_name'])) {
                                    echo '<i class="fas fa-truck text-primary me-1"></i>' . htmlspecialchars($trans['supplier_name']);
                                } else {
                                    echo '<span class="text-muted">-</span>';
                                }
                                ?>
                            </td>
                            <td>
                                TSH <?php echo number_format((float)$trans['taxable_amount'], 2); ?>
                            </td>
                            <td>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format((float)$trans['tax_amount'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $trans['status']; ?>">
                                    <?php echo ucfirst($trans['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="view-transaction.php?id=<?php echo $trans['tax_transaction_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($trans['status'] == 'pending'): ?>
                                    <a href="edit-transaction.php?id=<?php echo $trans['tax_transaction_id']; ?>" 
                                       class="btn btn-outline-warning action-btn"
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($transactions)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end">TOTALS:</th>
                            <th>TSH <?php echo number_format(array_sum(array_column($transactions, 'taxable_amount')), 2); ?></th>
                            <th>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format(array_sum(array_column($transactions, 'tax_amount')), 2); ?>
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
    const table = $('#transactionsTable');
    const hasData = table.find('tbody tr').length > 0 && 
                   !table.find('tbody tr td[colspan]').length;
    
    if (hasData) {
        table.DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            columnDefs: [
                { orderable: false, targets: -1 }
            ]
        });
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>