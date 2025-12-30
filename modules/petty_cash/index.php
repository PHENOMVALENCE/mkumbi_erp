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

// ==================== FILTERS ====================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$custodian_filter = isset($_GET['custodian_id']) ? (int)$_GET['custodian_id'] : 0;
$category_filter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

// ==================== STATISTICS ====================
try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_transactions,
            COALESCE(SUM(CASE WHEN transaction_type = 'replenishment' THEN amount ELSE 0 END), 0) as total_replenishments,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expenses,
            COALESCE(SUM(CASE WHEN transaction_type = 'return' THEN amount ELSE 0 END), 0) as total_returns,
            COALESCE(SUM(CASE 
                WHEN transaction_type = 'replenishment' THEN amount
                WHEN transaction_type = 'expense' THEN -amount
                WHEN transaction_type = 'return' THEN amount
                ELSE 0 
            END), 0) as current_balance
        FROM petty_cash_transactions 
        WHERE company_id = ?
        AND transaction_date BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $date_from, $date_to]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'total_transactions' => (int)($result['total_transactions'] ?? 0),
        'total_replenishments' => (float)($result['total_replenishments'] ?? 0),
        'total_expenses' => (float)($result['total_expenses'] ?? 0),
        'total_returns' => (float)($result['total_returns'] ?? 0),
        'current_balance' => (float)($result['current_balance'] ?? 0)
    ];
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
    $stats = [
        'total_transactions' => 0,
        'total_replenishments' => 0,
        'total_expenses' => 0,
        'total_returns' => 0,
        'current_balance' => 0
    ];
}

// ==================== FETCH CUSTODIANS ====================
$custodians = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT u.user_id, u.full_name, d.department_name,
               COUNT(pc.transaction_id) as transaction_count
        FROM users u
        LEFT JOIN employees e ON u.user_id = e.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN petty_cash_transactions pc ON u.user_id = pc.custodian_id AND pc.company_id = ?
        WHERE u.company_id = ? AND u.is_active = 1
        GROUP BY u.user_id, u.full_name, d.department_name
        HAVING transaction_count > 0 OR u.user_id = ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$company_id, $company_id, $user_id]);
    $custodians = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Custodians fetch error: " . $e->getMessage());
}

// ==================== FETCH CATEGORIES ====================
$categories = [];
try {
    $stmt = $conn->prepare("
        SELECT category_id, category_name, category_code,
               (SELECT COUNT(*) FROM petty_cash_transactions 
                WHERE category_id = pc.category_id AND company_id = ?) as usage_count
        FROM petty_cash_categories pc
        WHERE company_id = ? AND is_active = 1
        ORDER BY category_name
    ");
    $stmt->execute([$company_id, $company_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Categories fetch error: " . $e->getMessage());
}

// ==================== BUILD QUERY ====================
$where_conditions = ["pc.company_id = ?"];
$params = [$company_id];

if ($search) {
    $where_conditions[] = "(pc.reference_number LIKE ? OR pc.description LIKE ? OR pc.payee LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($custodian_filter) {
    $where_conditions[] = "pc.custodian_id = ?";
    $params[] = $custodian_filter;
}

if ($category_filter) {
    $where_conditions[] = "pc.category_id = ?";
    $params[] = $category_filter;
}

if ($type_filter) {
    $where_conditions[] = "pc.transaction_type = ?";
    $params[] = $type_filter;
}

$where_conditions[] = "pc.transaction_date BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;

$where_clause = implode(' AND ', $where_conditions);

// ==================== FETCH TRANSACTIONS ====================
$transactions = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            pc.*,
            c.category_name,
            c.category_code,
            u.full_name as custodian_name,
            created_user.full_name as created_by_name,
            approved_user.full_name as approved_by_name
        FROM petty_cash_transactions pc
        LEFT JOIN petty_cash_categories c ON pc.category_id = c.category_id
        LEFT JOIN users u ON pc.custodian_id = u.user_id
        LEFT JOIN users created_user ON pc.created_by = created_user.user_id
        LEFT JOIN users approved_user ON pc.approved_by = approved_user.user_id
        WHERE $where_clause
        ORDER BY pc.transaction_date DESC, pc.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Transactions fetch error: " . $e->getMessage());
}

$page_title = 'Petty Cash Management';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 0.875rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #007bff;
    height: 100%;
}

.stats-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
    line-height: 1.2;
}

.stats-label {
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.filter-card {
    background: #fff;
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.table-container {
    background: #fff;
    border-radius: 6px;
    padding: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.transaction-table {
    font-size: 0.85rem;
    margin-bottom: 0;
}

.transaction-table th {
    background: #f8f9fa;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.3px;
    padding: 0.75rem 0.5rem;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
}

.transaction-table td {
    padding: 0.6rem 0.5rem;
    vertical-align: middle;
}

.transaction-table tbody tr:hover {
    background-color: #f8f9fa;
}

.type-badge {
    display: inline-block;
    padding: 0.3rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.type-badge.replenishment {
    background: #d4edda;
    color: #155724;
}

.type-badge.expense {
    background: #f8d7da;
    color: #721c24;
}

.type-badge.return {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge {
    display: inline-block;
    padding: 0.3rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.status-badge.approved {
    background: #d4edda;
    color: #155724;
}

.status-badge.rejected {
    background: #f8d7da;
    color: #721c24;
}

.amount-positive {
    color: #28a745;
    font-weight: 700;
}

.amount-negative {
    color: #dc3545;
    font-weight: 700;
}

.amount-neutral {
    color: #17a2b8;
    font-weight: 700;
}

.reference-number {
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: #495057;
}

.btn-action {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 3px;
}

.quick-actions {
    display: flex;
    gap: 0.25rem;
    flex-wrap: wrap;
}

@media (max-width: 768px) {
    .stats-value {
        font-size: 1.25rem;
    }
    
    .filter-card .row {
        row-gap: 0.5rem;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-wallet me-2"></i>Petty Cash Management
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="add.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i>New Transaction
                </a>
                <a href="reconciliation.php" class="btn btn-info btn-sm">
                    <i class="fas fa-balance-scale me-1"></i>Reconciliation
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Statistics -->
    <div class="row mb-3 g-2">
        <div class="col-md-3 col-6">
            <div class="stats-card" style="border-left-color: #007bff;">
                <div class="stats-value"><?= $stats['total_transactions'] ?></div>
                <div class="stats-label">Total Transactions</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stats-card" style="border-left-color: #28a745;">
                <div class="stats-value"><?= number_format($stats['total_replenishments'], 0) ?></div>
                <div class="stats-label">Replenishments (TSH)</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stats-card" style="border-left-color: #dc3545;">
                <div class="stats-value"><?= number_format($stats['total_expenses'], 0) ?></div>
                <div class="stats-label">Expenses (TSH)</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stats-card" style="border-left-color: #17a2b8;">
                <div class="stats-value"><?= number_format($stats['current_balance'], 0) ?></div>
                <div class="stats-label">Current Balance (TSH)</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" id="filterForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Reference, description, payee..." value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Custodian</label>
                    <select name="custodian_id" class="form-select form-select-sm">
                        <option value="">All Custodians</option>
                        <?php foreach ($custodians as $custodian): ?>
                        <option value="<?= $custodian['user_id'] ?>" <?= $custodian_filter == $custodian['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($custodian['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Category</label>
                    <select name="category_id" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['category_id'] ?>" <?= $category_filter == $category['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['category_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="replenishment" <?= $type_filter == 'replenishment' ? 'selected' : '' ?>>Replenishment</option>
                        <option value="expense" <?= $type_filter == 'expense' ? 'selected' : '' ?>>Expense</option>
                        <option value="return" <?= $type_filter == 'return' ? 'selected' : '' ?>>Return</option>
                    </select>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" 
                           value="<?= $date_from ?>">
                </div>
                
                <div class="col-md-1">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" 
                           value="<?= $date_to ?>">
                </div>
                
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Transactions Table -->
    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover transaction-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Payee/From</th>
                        <th>Custodian</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                            <p class="text-muted mb-0">No transactions found</p>
                            <small class="text-muted">Try adjusting your filters or add a new transaction</small>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $txn): ?>
                        <tr>
                            <td style="white-space: nowrap;">
                                <?= date('d M Y', strtotime($txn['transaction_date'])) ?>
                            </td>
                            <td>
                                <span class="reference-number"><?= htmlspecialchars($txn['reference_number']) ?></span>
                            </td>
                            <td>
                                <span class="type-badge <?= $txn['transaction_type'] ?>">
                                    <?= ucfirst($txn['transaction_type']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($txn['category_name']): ?>
                                    <small><?= htmlspecialchars($txn['category_name']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?= htmlspecialchars($txn['description']) ?>
                                </div>
                            </td>
                            <td>
                                <?= htmlspecialchars($txn['payee'] ?: '-') ?>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($txn['custodian_name']) ?></small>
                            </td>
                            <td class="text-end">
                                <?php
                                $amount_class = 'amount-neutral';
                                $amount_sign = '';
                                if ($txn['transaction_type'] == 'replenishment') {
                                    $amount_class = 'amount-positive';
                                    $amount_sign = '+';
                                } elseif ($txn['transaction_type'] == 'expense') {
                                    $amount_class = 'amount-negative';
                                    $amount_sign = '-';
                                } elseif ($txn['transaction_type'] == 'return') {
                                    $amount_class = 'amount-positive';
                                    $amount_sign = '+';
                                }
                                ?>
                                <span class="<?= $amount_class ?>">
                                    <?= $amount_sign ?><?= number_format($txn['amount'], 2) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= $txn['approval_status'] ?>">
                                    <?= ucfirst($txn['approval_status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="quick-actions">
                                    <a href="view.php?id=<?= $txn['transaction_id'] ?>" 
                                       class="btn btn-info btn-action" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($txn['approval_status'] == 'pending'): ?>
                                    <a href="edit.php?id=<?= $txn['transaction_id'] ?>" 
                                       class="btn btn-warning btn-action" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($txn['receipt_path']): ?>
                                    <a href="<?= htmlspecialchars($txn['receipt_path']) ?>" 
                                       target="_blank" class="btn btn-secondary btn-action" title="View Receipt">
                                        <i class="fas fa-file-invoice"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($transactions)): ?>
                <tfoot>
                    <tr style="background: #f8f9fa; font-weight: 700;">
                        <td colspan="7" class="text-end">Summary for Period:</td>
                        <td class="text-end">
                            <div class="amount-positive">+<?= number_format($stats['total_replenishments'], 2) ?></div>
                            <div class="amount-negative">-<?= number_format($stats['total_expenses'], 2) ?></div>
                            <div style="border-top: 2px solid #dee2e6; margin-top: 0.25rem; padding-top: 0.25rem;">
                                <?= number_format($stats['current_balance'], 2) ?>
                            </div>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        
        <?php if (!empty($transactions)): ?>
        <div class="mt-3 text-muted" style="font-size: 0.75rem;">
            <i class="fas fa-info-circle me-1"></i>
            Showing <?= count($transactions) ?> transaction(s) from <?= date('d M Y', strtotime($date_from)) ?> 
            to <?= date('d M Y', strtotime($date_to)) ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>