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

$errors = [];
$success = '';

// Get selected bank account
$selected_account_id = $_GET['account_id'] ?? null;

// Fetch bank accounts
try {
    $stmt = $conn->prepare("
        SELECT bank_account_id, account_name, account_number, bank_name, current_balance
        FROM bank_accounts
        WHERE company_id = ? AND is_active = 1
        ORDER BY is_default DESC, account_name
    ");
    $stmt->execute([$company_id]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$selected_account_id && !empty($accounts)) {
        $selected_account_id = $accounts[0]['bank_account_id'];
    }
} catch (PDOException $e) {
    error_log("Error fetching accounts: " . $e->getMessage());
    $accounts = [];
}

// Get account details
$account_details = null;
if ($selected_account_id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM bank_accounts WHERE bank_account_id = ? AND company_id = ?");
        $stmt->execute([$selected_account_id, $company_id]);
        $account_details = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching account details: " . $e->getMessage());
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        if (empty($_POST['transaction_date'])) {
            $errors[] = "Transaction date is required";
        }
        if (empty($_POST['transaction_type'])) {
            $errors[] = "Transaction type is required";
        }
        if (empty($_POST['amount']) || floatval($_POST['amount']) <= 0) {
            $errors[] = "Valid amount is required";
        }
        
        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                
                $amount = floatval($_POST['amount']);
                $transaction_type = $_POST['transaction_type'];
                
                if ($action === 'create') {
                    // Insert transaction
                    $sql = "INSERT INTO bank_transactions (
                        company_id, bank_account_id, transaction_date, value_date,
                        transaction_type, amount, description, reference_number,
                        cheque_number, is_reconciled
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $company_id,
                        $selected_account_id,
                        $_POST['transaction_date'],
                        $_POST['value_date'] ?? $_POST['transaction_date'],
                        $transaction_type,
                        $amount,
                        $_POST['description'] ?? null,
                        $_POST['reference_number'] ?? null,
                        $_POST['cheque_number'] ?? null,
                        0
                    ]);
                    
                    // Update account balance
                    if ($transaction_type === 'credit') {
                        $stmt = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE bank_account_id = ?");
                        $stmt->execute([$amount, $selected_account_id]);
                    } else {
                        $stmt = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE bank_account_id = ?");
                        $stmt->execute([$amount, $selected_account_id]);
                    }
                    
                    $success = "Transaction added successfully!";
                } else {
                    // Get old transaction to reverse balance
                    $stmt = $conn->prepare("SELECT transaction_type, amount FROM bank_transactions WHERE bank_transaction_id = ?");
                    $stmt->execute([$_POST['transaction_id']]);
                    $old_trans = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Reverse old transaction
                    if ($old_trans['transaction_type'] === 'credit') {
                        $stmt = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE bank_account_id = ?");
                        $stmt->execute([$old_trans['amount'], $selected_account_id]);
                    } else {
                        $stmt = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE bank_account_id = ?");
                        $stmt->execute([$old_trans['amount'], $selected_account_id]);
                    }
                    
                    // Update transaction
                    $sql = "UPDATE bank_transactions SET 
                        transaction_date = ?, value_date = ?, transaction_type = ?,
                        amount = ?, description = ?, reference_number = ?, cheque_number = ?
                        WHERE bank_transaction_id = ? AND company_id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $_POST['transaction_date'],
                        $_POST['value_date'] ?? $_POST['transaction_date'],
                        $transaction_type,
                        $amount,
                        $_POST['description'] ?? null,
                        $_POST['reference_number'] ?? null,
                        $_POST['cheque_number'] ?? null,
                        $_POST['transaction_id'],
                        $company_id
                    ]);
                    
                    // Apply new transaction
                    if ($transaction_type === 'credit') {
                        $stmt = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE bank_account_id = ?");
                        $stmt->execute([$amount, $selected_account_id]);
                    } else {
                        $stmt = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE bank_account_id = ?");
                        $stmt->execute([$amount, $selected_account_id]);
                    }
                    
                    $success = "Transaction updated successfully!";
                }
                
                $conn->commit();
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Error saving transaction: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        try {
            $conn->beginTransaction();
            
            // Get transaction to reverse balance
            $stmt = $conn->prepare("SELECT transaction_type, amount, bank_account_id FROM bank_transactions WHERE bank_transaction_id = ? AND company_id = ?");
            $stmt->execute([$_POST['transaction_id'], $company_id]);
            $trans = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($trans) {
                // Reverse transaction
                if ($trans['transaction_type'] === 'credit') {
                    $stmt = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE bank_account_id = ?");
                    $stmt->execute([$trans['amount'], $trans['bank_account_id']]);
                } else {
                    $stmt = $conn->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE bank_account_id = ?");
                    $stmt->execute([$trans['amount'], $trans['bank_account_id']]);
                }
                
                // Delete transaction
                $stmt = $conn->prepare("DELETE FROM bank_transactions WHERE bank_transaction_id = ? AND company_id = ?");
                $stmt->execute([$_POST['transaction_id'], $company_id]);
                
                $success = "Transaction deleted successfully!";
            }
            
            $conn->commit();
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error deleting transaction: " . $e->getMessage());
            $errors[] = "Error deleting transaction";
        }
    }
}

// Build filter conditions
$where_conditions = ["bt.company_id = ?", "bt.bank_account_id = ?"];
$params = [$company_id, $selected_account_id];

if (!empty($_GET['transaction_type'])) {
    $where_conditions[] = "bt.transaction_type = ?";
    $params[] = $_GET['transaction_type'];
}

if (!empty($_GET['reconciled'])) {
    $where_conditions[] = "bt.is_reconciled = ?";
    $params[] = $_GET['reconciled'] == 'yes' ? 1 : 0;
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "bt.transaction_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "bt.transaction_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(bt.description LIKE ? OR bt.reference_number LIKE ? OR bt.cheque_number LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch transactions
$transactions = [];
$stats = [
    'total_transactions' => 0,
    'total_credits' => 0,
    'total_debits' => 0,
    'reconciled_count' => 0,
    'unreconciled_count' => 0
];

if ($selected_account_id) {
    try {
        $stmt = $conn->prepare("
            SELECT bt.*,
                   p.payment_number, p.remarks as payment_remarks
            FROM bank_transactions bt
            LEFT JOIN payments p ON bt.reconciled_with_payment_id = p.payment_id
            WHERE $where_clause
            ORDER BY bt.transaction_date DESC, bt.created_at DESC
        ");
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate statistics
        foreach ($transactions as $transaction) {
            $stats['total_transactions']++;
            if ($transaction['transaction_type'] === 'credit') {
                $stats['total_credits'] += $transaction['amount'];
            } else {
                $stats['total_debits'] += $transaction['amount'];
            }
            if ($transaction['is_reconciled']) {
                $stats['reconciled_count']++;
            } else {
                $stats['unreconciled_count']++;
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching transactions: " . $e->getMessage());
    }
}

$page_title = 'Bank Transactions';
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
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.warning { border-left-color: #ffc107; }

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

.account-header-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.account-balance {
    font-size: 2rem;
    font-weight: 700;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.transaction-row {
    background: white;
    padding: 1rem;
    margin-bottom: 0.5rem;
    border-radius: 8px;
    border-left: 4px solid;
    transition: all 0.2s;
}

.transaction-row:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transform: translateX(4px);
}

.transaction-row.credit {
    border-left-color: #28a745;
}

.transaction-row.debit {
    border-left-color: #dc3545;
}

.transaction-row.reconciled {
    opacity: 0.7;
    background: #f8f9fa;
}

.amount-credit {
    color: #28a745;
    font-weight: 700;
    font-size: 1.1rem;
}

.amount-debit {
    color: #dc3545;
    font-weight: 700;
    font-size: 1.1rem;
}

.reconciled-badge {
    background: #28a745;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.unreconciled-badge {
    background: #ffc107;
    color: #000;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
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
                <p class="text-muted small mb-0 mt-1">View and manage bank account transactions</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="bank_accounts.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-university me-1"></i> Accounts
                    </a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#transactionModal">
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
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Account Selection -->
        <form method="GET" class="mb-3">
            <div class="row align-items-end g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Select Bank Account</label>
                    <select name="account_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Select Account...</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo $account['bank_account_id']; ?>"
                                    <?php echo $selected_account_id == $account['bank_account_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($account['account_name']); ?> - 
                                <?php echo htmlspecialchars($account['account_number']); ?> -
                                TSH <?php echo number_format($account['current_balance'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>

        <?php if ($selected_account_id && $account_details): ?>

        <!-- Account Header -->
        <div class="account-header-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-1">
                        <i class="fas fa-university me-2"></i>
                        <?php echo htmlspecialchars($account_details['account_name']); ?>
                    </h3>
                    <p class="mb-0 opacity-75">
                        <?php echo htmlspecialchars($account_details['bank_name']); ?> - 
                        <?php echo htmlspecialchars($account_details['account_number']); ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="opacity-75 small">Current Balance</div>
                    <div class="account-balance">
                        TSH <?php echo number_format($account_details['current_balance'], 2); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format($stats['total_transactions']); ?></div>
                    <div class="stats-label">Total Transactions</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format($stats['total_credits']); ?></div>
                    <div class="stats-label">Total Credits</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number">TSH <?php echo number_format($stats['total_debits']); ?></div>
                    <div class="stats-label">Total Debits</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($stats['unreconciled_count']); ?></div>
                    <div class="stats-label">Unreconciled</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <input type="hidden" name="account_id" value="<?php echo $selected_account_id; ?>">
                
                <div class="col-md-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Description, reference..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Type</label>
                    <select name="transaction_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="credit" <?php echo (isset($_GET['transaction_type']) && $_GET['transaction_type'] == 'credit') ? 'selected' : ''; ?>>Credit</option>
                        <option value="debit" <?php echo (isset($_GET['transaction_type']) && $_GET['transaction_type'] == 'debit') ? 'selected' : ''; ?>>Debit</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Reconciled</label>
                    <select name="reconciled" class="form-select">
                        <option value="">All</option>
                        <option value="yes" <?php echo (isset($_GET['reconciled']) && $_GET['reconciled'] == 'yes') ? 'selected' : ''; ?>>Yes</option>
                        <option value="no" <?php echo (isset($_GET['reconciled']) && $_GET['reconciled'] == 'no') ? 'selected' : ''; ?>>No</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $_GET['date_from'] ?? ''; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $_GET['date_to'] ?? ''; ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Transactions List -->
        <?php if (empty($transactions)): ?>
        <div class="text-center py-5">
            <i class="fas fa-exchange-alt fa-4x text-muted mb-3"></i>
            <h4>No Transactions Found</h4>
            <p class="text-muted">Start by adding your first transaction</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#transactionModal">
                <i class="fas fa-plus-circle me-1"></i> Add Transaction
            </button>
        </div>
        <?php else: ?>
            <?php foreach ($transactions as $transaction): ?>
            <div class="transaction-row <?php echo $transaction['transaction_type']; ?> <?php echo $transaction['is_reconciled'] ? 'reconciled' : ''; ?>">
                <div class="row align-items-center">
                    <div class="col-md-2">
                        <div class="text-muted small">
                            <i class="fas fa-calendar me-1"></i>
                            <?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?>
                        </div>
                        <?php if (!empty($transaction['value_date']) && $transaction['value_date'] != $transaction['transaction_date']): ?>
                        <div class="text-muted small">
                            Value: <?php echo date('M d, Y', strtotime($transaction['value_date'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <div class="fw-bold"><?php echo htmlspecialchars($transaction['description'] ?? 'No description'); ?></div>
                        <?php if (!empty($transaction['reference_number'])): ?>
                        <div class="text-muted small">Ref: <?php echo htmlspecialchars($transaction['reference_number']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($transaction['cheque_number'])): ?>
                        <div class="text-muted small">Cheque: <?php echo htmlspecialchars($transaction['cheque_number']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2 text-end">
                        <div class="amount-<?php echo $transaction['transaction_type']; ?>">
                            <?php echo $transaction['transaction_type'] == 'credit' ? '+' : '-'; ?>
                            TSH <?php echo number_format($transaction['amount'], 2); ?>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <?php if ($transaction['is_reconciled']): ?>
                        <span class="reconciled-badge">
                            <i class="fas fa-check me-1"></i>Reconciled
                        </span>
                        <?php if (!empty($transaction['payment_number'])): ?>
                        <div class="text-muted small mt-1">
                            <?php echo htmlspecialchars($transaction['payment_number']); ?>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="unreconciled-badge">Unreconciled</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2 text-end">
                        <div class="btn-group btn-group-sm">
                            <?php if (!$transaction['is_reconciled']): ?>
                            <button type="button" 
                                    class="btn btn-outline-primary"
                                    onclick="editTransaction(<?php echo htmlspecialchars(json_encode($transaction)); ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" 
                                    class="btn btn-outline-danger"
                                    onclick="deleteTransaction(<?php echo $transaction['bank_transaction_id']; ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php else: ?>
        <div class="text-center py-5">
            <i class="fas fa-university fa-4x text-muted mb-3"></i>
            <h4>Select a Bank Account</h4>
            <p class="text-muted">Choose a bank account to view transactions</p>
        </div>
        <?php endif; ?>

    </div>
</section>

<!-- Add/Edit Transaction Modal -->
<div class="modal fade" id="transactionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">
                    <i class="fas fa-exchange-alt me-2"></i>Add Transaction
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="transactionForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="transaction_id" id="transaction_id">
                    <input type="hidden" name="account_id" value="<?php echo $selected_account_id; ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Transaction Date <span class="text-danger">*</span></label>
                            <input type="date" name="transaction_date" id="transaction_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Value Date</label>
                            <input type="date" name="value_date" id="value_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Transaction Type <span class="text-danger">*</span></label>
                            <select name="transaction_type" id="transaction_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="credit">Credit (Money In)</option>
                                <option value="debit">Debit (Money Out)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="amount" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" id="reference_number" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cheque Number</label>
                            <input type="text" name="cheque_number" id="cheque_number" class="form-control">
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

<script>
function editTransaction(transaction) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Transaction';
    document.getElementById('formAction').value = 'update';
    document.getElementById('transaction_id').value = transaction.bank_transaction_id;
    document.getElementById('transaction_date').value = transaction.transaction_date;
    document.getElementById('value_date').value = transaction.value_date || '';
    document.getElementById('transaction_type').value = transaction.transaction_type;
    document.getElementById('amount').value = transaction.amount;
    document.getElementById('description').value = transaction.description || '';
    document.getElementById('reference_number').value = transaction.reference_number || '';
    document.getElementById('cheque_number').value = transaction.cheque_number || '';
    
    const modal = new bootstrap.Modal(document.getElementById('transactionModal'));
    modal.show();
}

function deleteTransaction(transactionId) {
    if (confirm('Are you sure you want to delete this transaction?')) {
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

// Reset form when modal is closed
document.getElementById('transactionModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('transactionForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-exchange-alt me-2"></i>Add Transaction';
    document.getElementById('formAction').value = 'create';
    document.getElementById('transaction_id').value = '';
    document.getElementById('transaction_date').value = '<?php echo date('Y-m-d'); ?>';
});
</script>

<?php 
require_once '../../includes/footer.php';
?>