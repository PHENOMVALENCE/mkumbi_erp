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

// Get filter parameters
$customer_id = $_GET['customer_id'] ?? null;
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;
$search = $_GET['search'] ?? '';

// Fetch customers for dropdown
try {
    $customers_sql = "SELECT customer_id, full_name as customer_name, 
                             phone, phone1, email
                     FROM customers 
                     WHERE company_id = ? AND is_active = 1 
                     ORDER BY full_name";
    $customers_stmt = $conn->prepare($customers_sql);
    $customers_stmt->execute([$company_id]);
    $customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching customers: " . $e->getMessage());
    $customers = [];
}

// Build query to get customer balances and transaction summary from ACTUAL tables
$balance_sql = "SELECT 
    c.customer_id,
    c.full_name as customer_name,
    COALESCE(c.phone, c.phone1) as phone,
    c.email,
    COALESCE(SUM(r.total_amount), 0) as total_debits,
    COALESCE(SUM(p.amount), 0) as total_credits,
    COALESCE(SUM(r.total_amount) - SUM(COALESCE(p.amount, 0)), 0) as current_balance,
    COUNT(DISTINCT p.payment_id) as transaction_count,
    MAX(p.payment_date) as last_payment_date,
    COUNT(DISTINCT r.reservation_id) as reservation_count
FROM customers c
LEFT JOIN reservations r ON c.customer_id = r.customer_id 
    AND r.company_id = c.company_id 
    AND r.is_active = 1
LEFT JOIN payments p ON r.reservation_id = p.reservation_id 
    AND p.status = 'approved'
WHERE c.company_id = ? AND c.is_active = 1";

$params = [$company_id];

if ($customer_id) {
    $balance_sql .= " AND c.customer_id = ?";
    $params[] = $customer_id;
}

if ($date_from) {
    $balance_sql .= " AND ct.transaction_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $balance_sql .= " AND ct.transaction_date <= ?";
    $params[] = $date_to;
}

if ($search) {
    $balance_sql .= " AND (c.full_name LIKE ? OR c.phone LIKE ? OR c.phone1 LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$balance_sql .= " GROUP BY c.customer_id, c.full_name, c.phone, c.phone1, c.email
                  HAVING current_balance != 0 OR transaction_count > 0
                  ORDER BY current_balance DESC";

try {
    $stmt = $conn->prepare($balance_sql);
    $stmt->execute($params);
    $customer_balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching customer balances: " . $e->getMessage());
    $customer_balances = [];
}

// Calculate statistics
$total_customers = count($customer_balances);
$total_receivables = array_sum(array_column($customer_balances, 'current_balance'));
$customers_with_balance = count(array_filter($customer_balances, function($c) { 
    return $c['current_balance'] > 0; 
}));
$total_transactions = array_sum(array_column($customer_balances, 'transaction_count'));

$page_title = 'Customer Statements';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 0.875rem 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid;
    height: 100%;
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.warning { border-left-color: #ffc107; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.15rem;
    line-height: 1;
}

.stats-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #6c757d;
    font-weight: 600;
}

.table-professional {
    font-size: 0.85rem;
}

.table-professional thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #495057;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.3px;
    padding: 0.65rem 0.5rem;
    white-space: nowrap;
}

.table-professional tbody td {
    padding: 0.65rem 0.5rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.table-professional tbody tr:hover {
    background-color: #f8f9fa;
}

.action-btn {
    padding: 0.3rem 0.6rem;
    font-size: 0.75rem;
    border-radius: 3px;
    margin-right: 0.2rem;
    white-space: nowrap;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.filter-card {
    background: #fff;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">Customer Statements</h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="index.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-arrow-left me-1"></i>Back to Customers
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Statistics Cards -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($total_customers) ?></div>
                <div class="stats-label">Active Customers</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($total_receivables, 0) ?></div>
                <div class="stats-label">Total Receivables (TZS)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($customers_with_balance) ?></div>
                <div class="stats-label">Customers w/ Balance</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($total_transactions) ?></div>
                <div class="stats-label">Total Transactions</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['customer_id'] ?>"
                                <?= ($customer_id == $customer['customer_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($customer['customer_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control" 
                       value="<?= htmlspecialchars($date_from) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control" 
                       value="<?= htmlspecialchars($date_to) ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" 
                       placeholder="Customer name or phone..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-search me-1"></i>Filter
                    </button>
                    <a href="statements.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Customer Statements Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($customer_balances)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-invoice fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No customer transactions found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="statementsTable">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Contact</th>
                                <th class="text-end">Total Debits</th>
                                <th class="text-end">Total Credits</th>
                                <th class="text-end">Current Balance</th>
                                <th class="text-center">Transactions</th>
                                <th>Last Payment</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customer_balances as $customer): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($customer['customer_name']) ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($customer['phone']): ?>
                                            <div><i class="fas fa-phone me-1"></i><?= htmlspecialchars($customer['phone']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($customer['email']): ?>
                                            <div><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($customer['email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-danger" style="font-weight: 600;">
                                            <?= number_format($customer['total_debits'], 0) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-success" style="font-weight: 600;">
                                            <?= number_format($customer['total_credits'], 0) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <strong class="<?= $customer['current_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= number_format($customer['current_balance'], 0) ?>
                                        </strong>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?= $customer['transaction_count'] ?></span>
                                    </td>
                                    <td>
                                        <?php if ($customer['last_payment_date']): ?>
                                            <small><?= date('M d, Y', strtotime($customer['last_payment_date'])) ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">No payments</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-center">
                                            <a href="view_statement.php?customer_id=<?= $customer['customer_id'] ?>" 
                                               class="btn btn-sm btn-outline-primary action-btn" 
                                               title="View Full Statement">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-info action-btn" 
                                                    onclick="printStatement(<?= $customer['customer_id'] ?>)"
                                                    title="Print Statement">
                                                <i class="fas fa-print"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- DataTables -->
                <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
                <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

                <script>
                $(document).ready(function() {
                    $('#statementsTable').DataTable({
                        pageLength: 25,
                        order: [[4, 'desc']],
                        columnDefs: [
                            { targets: [2,3,4], className: 'text-end' },
                            { targets: [5,7], className: 'text-center', orderable: false }
                        ]
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function printStatement(customerId) {
    window.open('print_statement.php?customer_id=' + customerId, '_blank');
}
</script>

<?php require_once '../../includes/footer.php'; ?>