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

// Get account ID and date range
$account_id = $_GET['account_id'] ?? null;
$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Fetch all accounts for dropdown
try {
    $stmt = $conn->prepare("
        SELECT account_id, account_code, account_name, account_type
        FROM chart_of_accounts
        WHERE company_id = ? AND is_active = 1
        ORDER BY account_code
    ");
    $stmt->execute([$company_id]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$account_id && !empty($accounts)) {
        $account_id = $accounts[0]['account_id'];
    }
} catch (PDOException $e) {
    $accounts = [];
}

// Get account details
$account_details = null;
if ($account_id) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM chart_of_accounts 
            WHERE account_id = ? AND company_id = ?
        ");
        $stmt->execute([$account_id, $company_id]);
        $account_details = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching account: " . $e->getMessage());
    }
}

// Fetch ledger entries
$ledger_entries = [];
$opening_balance = 0;
$running_balance = 0;
$period_debits = 0;
$period_credits = 0;

if ($account_id && $account_details) {
    try {
        // Get opening balance
        $opening_balance = $account_details['opening_balance'];
        $running_balance = $opening_balance;
        
        // Fetch transactions
        $stmt = $conn->prepare("
            SELECT 
                je.journal_id,
                je.journal_number,
                je.journal_date,
                je.journal_type,
                je.description as journal_description,
                je.reference_number,
                jel.line_number,
                jel.description as line_description,
                jel.debit_amount,
                jel.credit_amount
            FROM journal_entry_lines jel
            INNER JOIN journal_entries je ON jel.journal_id = je.journal_id
            WHERE jel.account_id = ?
            AND je.company_id = ?
            AND je.status = 'posted'
            AND je.journal_date BETWEEN ? AND ?
            ORDER BY je.journal_date, je.journal_id, jel.line_number
        ");
        $stmt->execute([$account_id, $company_id, $date_from, $date_to]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate running balances
        foreach ($transactions as $transaction) {
            $debit = floatval($transaction['debit_amount']);
            $credit = floatval($transaction['credit_amount']);
            
            $running_balance += ($debit - $credit);
            
            $period_debits += $debit;
            $period_credits += $credit;
            
            $transaction['running_balance'] = $running_balance;
            $ledger_entries[] = $transaction;
        }
    } catch (PDOException $e) {
        error_log("Error fetching ledger: " . $e->getMessage());
    }
}

$closing_balance = $running_balance;
$net_movement = $period_debits - $period_credits;

$page_title = 'General Ledger';
require_once '../../includes/header.php';
?>

<style>
.ledger-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

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

.ledger-table {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.journal-number {
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: #007bff;
}

.balance-debit {
    color: #dc3545;
    font-weight: 600;
}

.balance-credit {
    color: #28a745;
    font-weight: 600;
}

.running-balance {
    font-weight: 700;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
}

.transaction-row {
    transition: all 0.2s;
}

.transaction-row:hover {
    background: #f8f9fa;
}

.opening-balance-row {
    background: #e9ecef;
    font-weight: 700;
}

.closing-balance-row {
    background: #e9ecef;
    font-weight: 700;
    border-top: 3px solid #dee2e6;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.account-type-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.account-type-badge.asset { background: #d4edda; color: #155724; }
.account-type-badge.liability { background: #f8d7da; color: #721c24; }
.account-type-badge.equity { background: #e7d5f5; color: #5a2a78; }
.account-type-badge.revenue { background: #d1ecf1; color: #0c5460; }
.account-type-badge.expense { background: #fff3cd; color: #856404; }

@media print {
    .no-print {
        display: none !important;
    }
    
    .ledger-table {
        box-shadow: none;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4 no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-book-open text-primary me-2"></i>General Ledger
                </h1>
                <p class="text-muted small mb-0 mt-1">Detailed account transactions</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="accounts.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-book me-1"></i> Chart of Accounts
                    </a>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Account Selection and Filters -->
        <div class="filter-card no-print">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Select Account</label>
                    <select name="account_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Select an account...</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo $account['account_id']; ?>"
                                    <?php echo $account_id == $account['account_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($account['account_code']); ?> - 
                                <?php echo htmlspecialchars($account['account_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">From Date</label>
                    <input type="date" name="date_from" class="form-control" 
                           value="<?php echo $date_from; ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">To Date</label>
                    <input type="date" name="date_to" class="form-control" 
                           value="<?php echo $date_to; ?>" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-sync me-1"></i> Refresh
                    </button>
                </div>
            </form>
        </div>

        <?php if ($account_id && $account_details): ?>

        <!-- Account Header -->
        <div class="ledger-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="mb-2">
                        <span class="account-type-badge <?php echo $account_details['account_type']; ?>">
                            <?php echo ucfirst($account_details['account_type']); ?>
                        </span>
                    </div>
                    <h2 class="mb-1">
                        <?php echo htmlspecialchars($account_details['account_code']); ?> - 
                        <?php echo htmlspecialchars($account_details['account_name']); ?>
                    </h2>
                    <?php if (!empty($account_details['account_category'])): ?>
                    <p class="mb-0 opacity-75">
                        Category: <?php echo htmlspecialchars($account_details['account_category']); ?>
                    </p>
                    <?php endif; ?>
                    <p class="mb-0 opacity-75 small">
                        Period: <?php echo date('M d, Y', strtotime($date_from)); ?> - 
                        <?php echo date('M d, Y', strtotime($date_to)); ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="opacity-75 mb-1">Current Balance</div>
                    <div style="font-size: 2rem; font-weight: 700;">
                        TSH <?php echo number_format(abs($closing_balance), 2); ?>
                    </div>
                    <div class="opacity-75 small">
                        <?php echo $closing_balance >= 0 ? 'Debit' : 'Credit'; ?> Balance
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4 no-print">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number">TSH <?php echo number_format(abs($opening_balance), 2); ?></div>
                    <div class="stats-label">Opening Balance</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format($period_debits, 2); ?></div>
                    <div class="stats-label">Total Debits</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number">TSH <?php echo number_format($period_credits, 2); ?></div>
                    <div class="stats-label">Total Credits</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number">TSH <?php echo number_format(abs($closing_balance), 2); ?></div>
                    <div class="stats-label">Closing Balance</div>
                </div>
            </div>
        </div>

        <!-- Ledger Table -->
        <div class="ledger-table">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 10%;">Date</th>
                            <th style="width: 12%;">Journal #</th>
                            <th style="width: 33%;">Description</th>
                            <th class="text-end" style="width: 15%;">Debit</th>
                            <th class="text-end" style="width: 15%;">Credit</th>
                            <th class="text-end" style="width: 15%;">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Opening Balance -->
                        <tr class="opening-balance-row">
                            <td><?php echo date('M d, Y', strtotime($date_from)); ?></td>
                            <td colspan="2">Opening Balance</td>
                            <td class="text-end">-</td>
                            <td class="text-end">-</td>
                            <td class="text-end">
                                <span class="running-balance <?php echo $opening_balance >= 0 ? 'balance-debit' : 'balance-credit'; ?>">
                                    <?php echo number_format(abs($opening_balance), 2); ?>
                                </span>
                            </td>
                        </tr>

                        <?php if (empty($ledger_entries)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                No transactions found for this period
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($ledger_entries as $entry): ?>
                            <tr class="transaction-row">
                                <td>
                                    <small><?php echo date('M d, Y', strtotime($entry['journal_date'])); ?></small>
                                </td>
                                <td>
                                    <a href="journal_view.php?id=<?php echo $entry['journal_id']; ?>" 
                                       class="journal-number"
                                       title="View Journal Entry">
                                        <?php echo htmlspecialchars($entry['journal_number']); ?>
                                    </a>
                                    <div>
                                        <span class="badge bg-info" style="font-size: 0.7rem;">
                                            <?php echo ucfirst($entry['journal_type']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($entry['journal_description']); ?></div>
                                    <?php if (!empty($entry['line_description'])): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($entry['line_description']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['reference_number'])): ?>
                                        <div><small class="text-muted">Ref: <?php echo htmlspecialchars($entry['reference_number']); ?></small></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($entry['debit_amount'] > 0): ?>
                                        <span class="balance-debit"><?php echo number_format($entry['debit_amount'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($entry['credit_amount'] > 0): ?>
                                        <span class="balance-credit"><?php echo number_format($entry['credit_amount'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <span class="running-balance <?php echo $entry['running_balance'] >= 0 ? 'balance-debit' : 'balance-credit'; ?>">
                                        <?php echo number_format(abs($entry['running_balance']), 2); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Closing Balance -->
                        <tr class="closing-balance-row">
                            <td><?php echo date('M d, Y', strtotime($date_to)); ?></td>
                            <td colspan="2">Closing Balance</td>
                            <td class="text-end balance-debit">
                                <?php echo number_format($period_debits, 2); ?>
                            </td>
                            <td class="text-end balance-credit">
                                <?php echo number_format($period_credits, 2); ?>
                            </td>
                            <td class="text-end">
                                <span class="running-balance <?php echo $closing_balance >= 0 ? 'balance-debit' : 'balance-credit'; ?>">
                                    <?php echo number_format(abs($closing_balance), 2); ?>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Summary -->
            <div class="row mt-4 border-top pt-3">
                <div class="col-md-6">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Opening Balance:</span>
                        <strong>TSH <?php echo number_format(abs($opening_balance), 2); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Debits:</span>
                        <strong class="balance-debit">TSH <?php echo number_format($period_debits, 2); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Credits:</span>
                        <strong class="balance-credit">TSH <?php echo number_format($period_credits, 2); ?></strong>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Net Movement:</span>
                        <strong class="<?php echo $net_movement >= 0 ? 'balance-debit' : 'balance-credit'; ?>">
                            TSH <?php echo number_format(abs($net_movement), 2); ?>
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2 fs-5">
                        <span>Closing Balance:</span>
                        <strong class="<?php echo $closing_balance >= 0 ? 'balance-debit' : 'balance-credit'; ?>">
                            TSH <?php echo number_format(abs($closing_balance), 2); ?>
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Transactions:</span>
                        <strong><?php echo count($ledger_entries); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Info -->
        <div class="text-center text-muted mt-4">
            <small>
                Generated on <?php echo date('F d, Y \a\t H:i'); ?> | 
                <?php echo count($ledger_entries); ?> transactions listed
            </small>
        </div>

        <?php else: ?>
        <div class="text-center py-5">
            <i class="fas fa-book-open fa-4x text-muted mb-3"></i>
            <h4>Select an Account</h4>
            <p class="text-muted">Choose an account from the dropdown above to view its ledger</p>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php 
require_once '../../includes/footer.php';
?>