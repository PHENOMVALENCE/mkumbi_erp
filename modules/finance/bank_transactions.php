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

$errors = [];
$success = '';

// Handle form submission for new transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_transaction') {
        // Validation
        if (empty($_POST['account_id'])) $errors[] = "Bank account is required";
        if (empty($_POST['transaction_date'])) $errors[] = "Transaction date is required";
        if (empty($_POST['transaction_type'])) $errors[] = "Transaction type is required";
        if (empty($_POST['amount']) || $_POST['amount'] <= 0) $errors[] = "Valid amount is required";
        if (empty($_POST['description'])) $errors[] = "Description is required";
        
        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                
                // Generate transaction number
                $year = date('Y', strtotime($_POST['transaction_date']));
                $type_prefix = $_POST['transaction_type'] === 'credit' ? 'DEP' : 'WTH';
                
                $count_sql = "SELECT COUNT(*) FROM bank_transactions 
                             WHERE company_id = ? AND transaction_type = ? AND YEAR(transaction_date) = ?";
                $count_stmt = $conn->prepare($count_sql);
                $count_stmt->execute([$company_id, $_POST['transaction_type'], $year]);
                $count = $count_stmt->fetchColumn() + 1;
                
                $transaction_number = $type_prefix . '-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                
                // Insert transaction
                $sql = "INSERT INTO bank_transactions (
                    company_id, bank_account_id, transaction_date, transaction_number,
                    transaction_type, amount, reference_number, description,
                    category, payment_method, reconciliation_status,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unreconciled', ?, NOW())";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $company_id,
                    $_POST['account_id'],
                    $_POST['transaction_date'],
                    $transaction_number,
                    $_POST['transaction_type'],
                    $_POST['amount'],
                    $_POST['reference_number'] ?? null,
                    $_POST['description'],
                    $_POST['category'] ?? null,
                    $_POST['payment_method'] ?? null,
                    $user_id
                ]);
                
                // Update bank account balance
                $amount = floatval($_POST['amount']);
                if ($_POST['transaction_type'] === 'credit') {
                    $update_sql = "UPDATE bank_accounts 
                                  SET current_balance = current_balance + ? 
                                  WHERE bank_account_id = ? AND company_id = ?";
                } else {
                    $update_sql = "UPDATE bank_accounts 
                                  SET current_balance = current_balance - ? 
                                  WHERE bank_account_id = ? AND company_id = ?";
                }
                
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->execute([$amount, $_POST['account_id'], $company_id]);
                
                $conn->commit();
                $success = "Transaction added successfully! Transaction Number: <strong>" . $transaction_number . "</strong>";
                
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Error adding transaction: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'reconcile') {
        try {
            $transaction_id = intval($_POST['transaction_id']);
            
            $sql = "UPDATE bank_transactions 
                   SET reconciliation_status = 'reconciled',
                       reconciled_by = ?,
                       reconciled_at = NOW()
                   WHERE transaction_id = ? AND company_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id, $transaction_id, $company_id]);
            
            $success = "Transaction reconciled successfully!";
        } catch (PDOException $e) {
            error_log("Error reconciling transaction: " . $e->getMessage());
            $errors[] = "Error reconciling transaction";
        }
    } elseif ($action === 'delete') {
        try {
            $conn->beginTransaction();
            
            $transaction_id = intval($_POST['transaction_id']);
            
            // Get transaction details
            $get_sql = "SELECT * FROM bank_transactions 
                       WHERE transaction_id = ? AND company_id = ?";
            $get_stmt = $conn->prepare($get_sql);
            $get_stmt->execute([$transaction_id, $company_id]);
            $transaction = $get_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($transaction) {
                // Reverse the balance change
                $amount = floatval($transaction['amount']);
                if ($transaction['transaction_type'] === 'credit') {
                    $update_sql = "UPDATE bank_accounts 
                                  SET current_balance = current_balance - ? 
                                  WHERE bank_account_id = ? AND company_id = ?";
                } else {
                    $update_sql = "UPDATE bank_accounts 
                                  SET current_balance = current_balance + ? 
                                  WHERE bank_account_id = ? AND company_id = ?";
                }
                
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->execute([$amount, $transaction['bank_account_id'], $company_id]);
                
                // Delete transaction
                $delete_sql = "DELETE FROM bank_transactions 
                              WHERE transaction_id = ? AND company_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->execute([$transaction_id, $company_id]);
                
                $conn->commit();
                $success = "Transaction deleted successfully!";
            }
            
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error deleting transaction: " . $e->getMessage());
            $errors[] = "Error deleting transaction";
        }
    }
}

// Fetch filter parameters
$account_filter = $_GET['account'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_clauses = ["bt.company_id = ?"];
$params = [$company_id];

if ($account_filter) {
    $where_clauses[] = "bt.bank_account_id = ?";
    $params[] = $account_filter;
}

if ($type_filter) {
    $where_clauses[] = "bt.transaction_type = ?";
    $params[] = $type_filter;
}

if ($status_filter) {
    $where_clauses[] = "bt.reconciliation_status = ?";
    $params[] = $status_filter;
}

if ($date_from) {
    $where_clauses[] = "bt.transaction_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "bt.transaction_date <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_clauses[] = "(bt.transaction_number LIKE ? OR bt.description LIKE ? OR bt.reference_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch transactions
try {
    $sql = "SELECT bt.*,
                   ba.account_name,
                   ba.bank_name,
                   ba.mobile_provider,
                   ba.account_category,
                   u.full_name as creator_name
            FROM bank_transactions bt
            JOIN bank_accounts ba ON bt.bank_account_id = ba.bank_account_id
            LEFT JOIN users u ON bt.created_by = u.user_id
            WHERE $where_sql
            ORDER BY bt.transaction_date DESC, bt.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
    $transactions = [];
}

// Fetch bank accounts for filter and form
try {
    $accounts_sql = "SELECT bank_account_id, account_name, bank_name, mobile_provider, 
                            account_category, current_balance
                     FROM bank_accounts
                     WHERE company_id = ? AND is_active = 1
                     ORDER BY is_default DESC, account_name";
    $accounts_stmt = $conn->prepare($accounts_sql);
    $accounts_stmt->execute([$company_id]);
    $accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accounts = [];
}

// Calculate statistics
$total_transactions = count($transactions);
$total_credits = array_sum(array_map(fn($t) => $t['transaction_type'] === 'credit' ? $t['amount'] : 0, $transactions));
$total_debits = array_sum(array_map(fn($t) => $t['transaction_type'] === 'debit' ? $t['amount'] : 0, $transactions));
$unreconciled = count(array_filter($transactions, fn($t) => $t['reconciliation_status'] === 'unreconciled'));

$page_title = 'Bank Transactions';
require_once '../../includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

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
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.warning { border-left-color: #ffc107; }

.stats-number {
    font-size: 2rem;
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

.filter-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.table-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.table-card .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.25rem 1.5rem;
    border: none;
}

.table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 1rem;
    white-space: nowrap;
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.transaction-number {
    font-weight: 600;
    color: #007bff;
}

.amount-credit {
    color: #28a745;
    font-weight: 600;
}

.amount-debit {
    color: #dc3545;
    font-weight: 600;
}

.badge-reconciled {
    background: #28a745;
    color: white;
}

.badge-unreconciled {
    background: #ffc107;
    color: #000;
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-exchange-alt text-primary me-2"></i>Bank Transactions
                </h1>
                <p class="text-muted small mb-0 mt-1">Track all bank account transactions</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                        <i class="fas fa-plus-circle me-1"></i> Add Transaction
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Errors:</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format($total_transactions); ?></div>
                    <div class="stats-label">Total Transactions</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format($total_credits / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Credits</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number">TSH <?php echo number_format($total_debits / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Debits</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($unreconciled); ?></div>
                    <div class="stats-label">Unreconciled</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Transaction #, description..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Account</label>
                    <select name="account" class="form-select">
                        <option value="">All Accounts</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['bank_account_id']; ?>" <?php echo $account_filter == $acc['bank_account_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Type</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="credit" <?php echo $type_filter === 'credit' ? 'selected' : ''; ?>>Credit (Deposit)</option>
                        <option value="debit" <?php echo $type_filter === 'debit' ? 'selected' : ''; ?>>Debit (Withdrawal)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="reconciled" <?php echo $status_filter === 'reconciled' ? 'selected' : ''; ?>>Reconciled</option>
                        <option value="unreconciled" <?php echo $status_filter === 'unreconciled' ? 'selected' : ''; ?>>Unreconciled</option>
                    </select>
                </div>
                <div class="col-md-1.5">
                    <label class="form-label small fw-bold">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-1.5">
                    <label class="form-label small fw-bold">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="bank_transactions.php" class="btn btn-secondary">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                        <i class="fas fa-plus me-1"></i> Add Transaction
                    </button>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="table-card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>All Transactions
                    <span class="badge bg-light text-dark ms-2"><?php echo number_format($total_transactions); ?> transactions</span>
                </h5>
            </div>
            <div class="table-responsive">
                <?php if (empty($transactions)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-exchange-alt fa-4x text-muted mb-3"></i>
                    <h4>No Transactions Found</h4>
                    <p class="text-muted">No transactions match your current filters</p>
                    <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                        <i class="fas fa-plus-circle me-1"></i> Add First Transaction
                    </button>
                </div>
                <?php else: ?>
                <table id="transactionsTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Transaction #</th>
                            <th>Account</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($trans['transaction_date'])); ?></td>
                            <td>
                                <span class="transaction-number"><?php echo htmlspecialchars($trans['transaction_number']); ?></span>
                            </td>
                            <td>
                                <?php 
                                if ($trans['account_category'] === 'bank') {
                                    echo htmlspecialchars($trans['bank_name'] . ' - ' . $trans['account_name']);
                                } else {
                                    echo htmlspecialchars($trans['mobile_provider'] . ' - ' . $trans['account_name']);
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($trans['transaction_type'] === 'credit'): ?>
                                    <span class="badge bg-success">Credit</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Debit</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?php echo $trans['transaction_type'] === 'credit' ? 'amount-credit' : 'amount-debit'; ?>">
                                    <?php echo $trans['transaction_type'] === 'credit' ? '+' : '-'; ?>
                                    TSH <?php echo number_format($trans['amount'], 2); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($trans['reference_number'] ?? 'N/A'); ?></td>
                            <td>
                                <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo htmlspecialchars($trans['description']); ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($trans['reconciliation_status'] === 'reconciled'): ?>
                                    <span class="badge badge-reconciled">Reconciled</span>
                                <?php else: ?>
                                    <span class="badge badge-unreconciled">Unreconciled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($trans['creator_name']); ?></small>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <?php if ($trans['reconciliation_status'] === 'unreconciled'): ?>
                                    <button type="button" class="btn btn-sm btn-success" onclick="reconcileTransaction(<?php echo $trans['transaction_id']; ?>)" title="Reconcile">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="deleteTransaction(<?php echo $trans['transaction_id']; ?>, '<?php echo htmlspecialchars($trans['transaction_number']); ?>')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>Add Bank Transaction
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_transaction">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Bank Account<span class="text-danger">*</span></label>
                            <select name="account_id" class="form-select" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?php echo $acc['bank_account_id']; ?>">
                                        <?php 
                                        if ($acc['account_category'] === 'bank') {
                                            echo htmlspecialchars($acc['bank_name'] . ' - ' . $acc['account_name']);
                                        } else {
                                            echo htmlspecialchars($acc['mobile_provider'] . ' - ' . $acc['account_name']);
                                        }
                                        ?>
                                        (Balance: TSH <?php echo number_format($acc['current_balance'], 2); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Transaction Date<span class="text-danger">*</span></label>
                            <input type="date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Transaction Type<span class="text-danger">*</span></label>
                            <select name="transaction_type" class="form-select" required>
                                <option value="">-- Select Type --</option>
                                <option value="credit">Credit (Deposit / Money In)</option>
                                <option value="debit">Debit (Withdrawal / Money Out)</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Amount (TSH)<span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" class="form-control" placeholder="e.g., CHQ123456, TRX789">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="">-- Select Method --</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="card">Card</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Category</label>
                            <input type="text" name="category" class="form-control" placeholder="e.g., Sales, Expenses, Loan, Investment">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Description<span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="3" required placeholder="Enter transaction details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script>
$(document).ready(function() {
    $('#transactionsTable').DataTable({
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        columnDefs: [
            { orderable: false, targets: 9 },
            { responsivePriority: 1, targets: 0 },
            { responsivePriority: 2, targets: 1 },
            { responsivePriority: 3, targets: 4 },
        ],
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12 col-md-6"B>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            {
                extend: 'excel',
                text: '<i class="fas fa-file-excel me-1"></i> Excel',
                className: 'btn btn-sm btn-success',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf me-1"></i> PDF',
                className: 'btn btn-sm btn-danger',
                orientation: 'landscape',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print me-1"></i> Print',
                className: 'btn btn-sm btn-info',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }
            }
        ]
    });
});

function reconcileTransaction(transactionId) {
    if (confirm('Mark this transaction as reconciled?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="reconcile">
            <input type="hidden" name="transaction_id" value="${transactionId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteTransaction(transactionId, transactionNumber) {
    if (confirm(`Delete transaction ${transactionNumber}? This will reverse the balance change. This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="transaction_id" value="${transactionId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<!-- Load Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php require_once '../../includes/footer.php'; ?>