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

$error = '';
$success = '';

// ==================== HANDLE BATCH APPROVAL ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_action'])) {
    try {
        $conn->beginTransaction();
        
        $action = $_POST['batch_action'];
        $transaction_ids = $_POST['transaction_ids'] ?? [];
        
        if (empty($transaction_ids)) {
            throw new Exception("Please select at least one transaction");
        }
        
        $placeholders = str_repeat('?,', count($transaction_ids) - 1) . '?';
        $params = array_merge([$user_id], $transaction_ids, [$company_id]);
        
        if ($action === 'approve') {
            $stmt = $conn->prepare("
                UPDATE petty_cash_transactions 
                SET approval_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE transaction_id IN ($placeholders)
                AND company_id = ?
                AND approval_status = 'pending'
            ");
            $stmt->execute($params);
            $count = $stmt->rowCount();
            $success = "Successfully approved $count transaction(s)";
            
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("
                UPDATE petty_cash_transactions 
                SET approval_status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE transaction_id IN ($placeholders)
                AND company_id = ?
                AND approval_status = 'pending'
            ");
            $stmt->execute($params);
            $count = $stmt->rowCount();
            $success = "Successfully rejected $count transaction(s)";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
        error_log("Batch approval error: " . $e->getMessage());
    }
}

// ==================== HANDLE SINGLE APPROVAL ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_action'])) {
    try {
        $conn->beginTransaction();
        
        $action = $_POST['single_action'];
        $transaction_id = (int)$_POST['transaction_id'];
        $approval_notes = trim($_POST['approval_notes']);
        
        if ($action === 'approve') {
            $stmt = $conn->prepare("
                UPDATE petty_cash_transactions 
                SET approval_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    approval_notes = ?
                WHERE transaction_id = ?
                AND company_id = ?
                AND approval_status = 'pending'
            ");
            $stmt->execute([$user_id, $approval_notes, $transaction_id, $company_id]);
            $success = "Transaction approved successfully";
            
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("
                UPDATE petty_cash_transactions 
                SET approval_status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW(),
                    approval_notes = ?
                WHERE transaction_id = ?
                AND company_id = ?
                AND approval_status = 'pending'
            ");
            $stmt->execute([$user_id, $approval_notes, $transaction_id, $company_id]);
            $success = "Transaction rejected";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
        error_log("Single approval error: " . $e->getMessage());
    }
}

// ==================== FILTERS ====================
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$custodian_filter = isset($_GET['custodian_id']) ? (int)$_GET['custodian_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

// ==================== STATISTICS ====================
try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_pending,
            COALESCE(SUM(CASE WHEN transaction_type = 'replenishment' THEN amount ELSE 0 END), 0) as pending_replenishments,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as pending_expenses,
            COALESCE(SUM(amount), 0) as total_pending_amount
        FROM petty_cash_transactions 
        WHERE company_id = ? AND approval_status = 'pending'
    ");
    $stmt->execute([$company_id]);
    $pending_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_approved,
            COALESCE(SUM(amount), 0) as total_approved_amount
        FROM petty_cash_transactions 
        WHERE company_id = ? AND approval_status = 'approved'
        AND DATE(approved_at) = CURDATE()
    ");
    $stmt->execute([$company_id]);
    $today_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'total_pending' => (int)($pending_stats['total_pending'] ?? 0),
        'pending_replenishments' => (float)($pending_stats['pending_replenishments'] ?? 0),
        'pending_expenses' => (float)($pending_stats['pending_expenses'] ?? 0),
        'total_pending_amount' => (float)($pending_stats['total_pending_amount'] ?? 0),
        'approved_today' => (int)($today_stats['total_approved'] ?? 0),
        'approved_today_amount' => (float)($today_stats['total_approved_amount'] ?? 0)
    ];
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
    $stats = [
        'total_pending' => 0,
        'pending_replenishments' => 0,
        'pending_expenses' => 0,
        'total_pending_amount' => 0,
        'approved_today' => 0,
        'approved_today_amount' => 0
    ];
}

// ==================== FETCH CUSTODIANS ====================
$custodians = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT u.user_id, u.full_name
        FROM users u
        INNER JOIN petty_cash_transactions pc ON u.user_id = pc.custodian_id
        WHERE pc.company_id = ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$company_id]);
    $custodians = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Custodians fetch error: " . $e->getMessage());
}

// ==================== BUILD QUERY ====================
$where_conditions = ["pc.company_id = ?"];
$params = [$company_id];

$where_conditions[] = "pc.approval_status = ?";
$params[] = $status_filter;

if ($type_filter) {
    $where_conditions[] = "pc.transaction_type = ?";
    $params[] = $type_filter;
}

if ($custodian_filter) {
    $where_conditions[] = "pc.custodian_id = ?";
    $params[] = $custodian_filter;
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
            custodian.full_name as custodian_name,
            dept.department_name as custodian_department,
            created_user.full_name as created_by_name,
            approved_user.full_name as approved_by_name
        FROM petty_cash_transactions pc
        LEFT JOIN petty_cash_categories c ON pc.category_id = c.category_id
        LEFT JOIN users custodian ON pc.custodian_id = custodian.user_id
        LEFT JOIN employees emp ON custodian.user_id = emp.user_id
        LEFT JOIN departments dept ON emp.department_id = dept.department_id
        LEFT JOIN users created_user ON pc.created_by = created_user.user_id
        LEFT JOIN users approved_user ON pc.approved_by = approved_user.user_id
        WHERE $where_clause
        ORDER BY pc.transaction_date DESC, pc.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Transactions fetch error: " . $e->getMessage());
}

$page_title = 'Petty Cash Approvals';
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

.batch-actions-bar {
    background: #e7f3ff;
    border: 2px solid #007bff;
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
    display: none;
}

.batch-actions-bar.active {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.approval-card {
    background: #fff;
    border-radius: 6px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #ffc107;
    transition: all 0.2s;
}

.approval-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.approval-card.selected {
    border-left-color: #007bff;
    background: #e7f3ff;
}

.card-header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
}

.card-info {
    flex: 1;
}

.card-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.reference-number {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    font-size: 1.1rem;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.type-badge {
    display: inline-block;
    padding: 0.3rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
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

.detail-row {
    display: flex;
    gap: 2rem;
    flex-wrap: wrap;
    margin-bottom: 0.75rem;
}

.detail-item {
    flex: 0 0 auto;
}

.detail-label {
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.detail-value {
    font-size: 0.85rem;
    color: #2c3e50;
    font-weight: 500;
}

.amount-display {
    font-size: 1.5rem;
    font-weight: 700;
}

.amount-display.positive {
    color: #28a745;
}

.amount-display.negative {
    color: #dc3545;
}

.quick-approve-form {
    display: flex;
    gap: 0.5rem;
    align-items: flex-start;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e9ecef;
}

.status-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
    border-bottom: 2px solid #e9ecef;
}

.status-tab {
    padding: 0.75rem 1.5rem;
    border: none;
    background: none;
    font-weight: 600;
    color: #6c757d;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
}

.status-tab:hover {
    color: #007bff;
}

.status-tab.active {
    color: #007bff;
    border-bottom-color: #007bff;
}

@media (max-width: 768px) {
    .card-header-row {
        flex-direction: column;
    }
    
    .card-actions {
        width: 100%;
    }
    
    .detail-row {
        flex-direction: column;
        gap: 0.5rem;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-check-circle me-2"></i>Petty Cash Approvals
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row mb-3 g-2">
        <div class="col-md-3 col-6">
            <div class="stats-card" style="border-left-color: #ffc107;">
                <div class="stats-value"><?= $stats['total_pending'] ?></div>
                <div class="stats-label">Pending Approval</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stats-card" style="border-left-color: #dc3545;">
                <div class="stats-value"><?= number_format($stats['pending_expenses'], 0) ?></div>
                <div class="stats-label">Pending Expenses (TSH)</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stats-card" style="border-left-color: #28a745;">
                <div class="stats-value"><?= $stats['approved_today'] ?></div>
                <div class="stats-label">Approved Today</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stats-card" style="border-left-color: #17a2b8;">
                <div class="stats-value"><?= number_format($stats['total_pending_amount'], 0) ?></div>
                <div class="stats-label">Total Pending Amount (TSH)</div>
            </div>
        </div>
    </div>

    <!-- Status Tabs -->
    <div class="status-tabs">
        <button class="status-tab <?= $status_filter == 'pending' ? 'active' : '' ?>" 
                onclick="window.location.href='?status=pending'">
            <i class="fas fa-clock me-1"></i>Pending (<?= $stats['total_pending'] ?>)
        </button>
        <button class="status-tab <?= $status_filter == 'approved' ? 'active' : '' ?>" 
                onclick="window.location.href='?status=approved'">
            <i class="fas fa-check me-1"></i>Approved
        </button>
        <button class="status-tab <?= $status_filter == 'rejected' ? 'active' : '' ?>" 
                onclick="window.location.href='?status=rejected'">
            <i class="fas fa-times me-1"></i>Rejected
        </button>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" id="filterForm">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">Transaction Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="replenishment" <?= $type_filter == 'replenishment' ? 'selected' : '' ?>>Replenishment</option>
                        <option value="expense" <?= $type_filter == 'expense' ? 'selected' : '' ?>>Expense</option>
                        <option value="return" <?= $type_filter == 'return' ? 'selected' : '' ?>>Return</option>
                    </select>
                </div>
                
                <div class="col-md-3">
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
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">From Date</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= $date_from ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label" style="font-size: 0.85rem; font-weight: 600;">To Date</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= $date_to ?>">
                </div>
                
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Batch Actions Bar -->
    <?php if ($status_filter == 'pending'): ?>
    <form method="POST" id="batchActionsForm">
        <div class="batch-actions-bar" id="batchActionsBar">
            <div class="flex-fill">
                <strong id="selectedCount">0</strong> transaction(s) selected
            </div>
            <button type="submit" name="batch_action" value="approve" class="btn btn-success btn-sm"
                    onclick="return confirm('Are you sure you want to approve the selected transactions?')">
                <i class="fas fa-check-circle me-1"></i>Approve Selected
            </button>
            <button type="submit" name="batch_action" value="reject" class="btn btn-danger btn-sm"
                    onclick="return confirm('Are you sure you want to reject the selected transactions?')">
                <i class="fas fa-times-circle me-1"></i>Reject Selected
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelection()">
                <i class="fas fa-times me-1"></i>Clear
            </button>
        </div>
    <?php endif; ?>

        <!-- Transactions List -->
        <?php if (empty($transactions)): ?>
            <div class="text-center py-5">
                <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                <h5 class="text-muted">No transactions found</h5>
                <p class="text-muted">
                    <?php if ($status_filter == 'pending'): ?>
                        All transactions have been reviewed
                    <?php else: ?>
                        Try adjusting your filters
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($transactions as $txn): ?>
            <div class="approval-card" data-transaction-id="<?= $txn['transaction_id'] ?>">
                <div class="card-header-row">
                    <div class="card-info">
                        <div class="reference-number">
                            <?php if ($status_filter == 'pending'): ?>
                            <input type="checkbox" name="transaction_ids[]" value="<?= $txn['transaction_id'] ?>" 
                                   class="form-check-input me-2 transaction-checkbox">
                            <?php endif; ?>
                            <?= htmlspecialchars($txn['reference_number']) ?>
                        </div>
                        <div>
                            <span class="type-badge <?= $txn['transaction_type'] ?>">
                                <?= ucfirst($txn['transaction_type']) ?>
                            </span>
                            <span class="ms-2 text-muted" style="font-size: 0.85rem;">
                                <?= date('d M Y', strtotime($txn['transaction_date'])) ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-actions">
                        <a href="view.php?id=<?= $txn['transaction_id'] ?>" class="btn btn-info btn-sm">
                            <i class="fas fa-eye me-1"></i>View
                        </a>
                        <?php if ($txn['receipt_path']): ?>
                        <a href="<?= htmlspecialchars($txn['receipt_path']) ?>" target="_blank" class="btn btn-secondary btn-sm">
                            <i class="fas fa-file-invoice me-1"></i>Receipt
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-item" style="flex: 1; min-width: 300px;">
                        <div class="detail-label">Description</div>
                        <div class="detail-value"><?= htmlspecialchars($txn['description']) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Amount</div>
                        <div class="amount-display <?= $txn['transaction_type'] == 'replenishment' ? 'positive' : 'negative' ?>">
                            <?= $txn['transaction_type'] == 'replenishment' ? '+' : '-' ?>TSH <?= number_format($txn['amount'], 2) ?>
                        </div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-item">
                        <div class="detail-label">Custodian</div>
                        <div class="detail-value">
                            <?= htmlspecialchars($txn['custodian_name']) ?>
                            <?php if ($txn['custodian_department']): ?>
                                <small class="text-muted">(<?= htmlspecialchars($txn['custodian_department']) ?>)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($txn['category_name']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Category</div>
                        <div class="detail-value"><?= htmlspecialchars($txn['category_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($txn['payee']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Payee</div>
                        <div class="detail-value"><?= htmlspecialchars($txn['payee']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <div class="detail-label">Created By</div>
                        <div class="detail-value"><?= htmlspecialchars($txn['created_by_name']) ?></div>
                    </div>
                </div>

                <?php if ($status_filter == 'pending'): ?>
                <!-- Quick Approval Form -->
                <form method="POST" class="quick-approve-form">
                    <input type="hidden" name="transaction_id" value="<?= $txn['transaction_id'] ?>">
                    <textarea name="approval_notes" class="form-control form-control-sm" rows="1" 
                              placeholder="Optional notes..."></textarea>
                    <button type="submit" name="single_action" value="approve" class="btn btn-success btn-sm"
                            onclick="return confirm('Approve this transaction?')">
                        <i class="fas fa-check me-1"></i>Approve
                    </button>
                    <button type="submit" name="single_action" value="reject" class="btn btn-danger btn-sm"
                            onclick="return confirm('Reject this transaction?')">
                        <i class="fas fa-times me-1"></i>Reject
                    </button>
                </form>
                <?php else: ?>
                <!-- Approval Info -->
                <div class="detail-row" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e9ecef;">
                    <div class="detail-item">
                        <div class="detail-label"><?= ucfirst($txn['approval_status']) ?> By</div>
                        <div class="detail-value"><?= htmlspecialchars($txn['approved_by_name']) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><?= ucfirst($txn['approval_status']) ?> At</div>
                        <div class="detail-value"><?= date('d M Y H:i', strtotime($txn['approved_at'])) ?></div>
                    </div>
                    <?php if ($txn['approval_notes']): ?>
                    <div class="detail-item" style="flex: 1;">
                        <div class="detail-label">Notes</div>
                        <div class="detail-value"><?= htmlspecialchars($txn['approval_notes']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php if ($status_filter == 'pending'): ?>
    </form>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.transaction-checkbox');
    const batchActionsBar = document.getElementById('batchActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    const approvalCards = document.querySelectorAll('.approval-card');
    
    // Update batch actions bar
    function updateBatchActions() {
        const checked = document.querySelectorAll('.transaction-checkbox:checked');
        if (checked.length > 0) {
            batchActionsBar.classList.add('active');
            selectedCount.textContent = checked.length;
        } else {
            batchActionsBar.classList.remove('active');
        }
        
        // Update card styling
        approvalCards.forEach(card => {
            const checkbox = card.querySelector('.transaction-checkbox');
            if (checkbox && checkbox.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        });
    }
    
    // Checkbox change handler
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBatchActions);
    });
    
    // Clear selection
    window.clearSelection = function() {
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updateBatchActions();
    };
    
    // Select all (Ctrl/Cmd + A in form)
    document.getElementById('batchActionsForm')?.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
            e.preventDefault();
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateBatchActions();
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>