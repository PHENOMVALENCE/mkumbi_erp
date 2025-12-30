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

// Get selected bank account and filters
$selected_account_id = $_GET['account_id'] ?? null;
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$transaction_type = $_GET['type'] ?? 'all'; // all, money_in, money_out

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

// Handle reconciliation toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'toggle_reconcile') {
        try {
            $transaction_type_param = $_POST['transaction_type'];
            $transaction_id = $_POST['transaction_id'];
            $is_reconciled = $_POST['is_reconciled'];
            
            $conn->beginTransaction();
            
            // Update based on transaction type
            switch ($transaction_type_param) {
                case 'payment':
                    $stmt = $conn->prepare("UPDATE payments SET is_reconciled = ?, reconciled_at = " . ($is_reconciled ? "NOW()" : "NULL") . " WHERE payment_id = ?");
                    $stmt->execute([$is_reconciled, $transaction_id]);
                    break;
                    
                case 'expense_claim':
                    $stmt = $conn->prepare("UPDATE expense_claims SET is_reconciled = ?, reconciled_at = " . ($is_reconciled ? "NOW()" : "NULL") . " WHERE claim_id = ?");
                    $stmt->execute([$is_reconciled, $transaction_id]);
                    break;
                    
                case 'direct_expense':
                    $stmt = $conn->prepare("UPDATE direct_expenses SET is_reconciled = ?, reconciled_at = " . ($is_reconciled ? "NOW()" : "NULL") . " WHERE expense_id = ?");
                    $stmt->execute([$is_reconciled, $transaction_id]);
                    break;
                    
                case 'commission':
                    $stmt = $conn->prepare("UPDATE commissions SET is_reconciled = ?, reconciled_at = " . ($is_reconciled ? "NOW()" : "NULL") . " WHERE commission_id = ?");
                    $stmt->execute([$is_reconciled, $transaction_id]);
                    break;
                    
                case 'refund':
                    $stmt = $conn->prepare("UPDATE refunds SET is_reconciled = ?, reconciled_at = " . ($is_reconciled ? "NOW()" : "NULL") . " WHERE refund_id = ?");
                    $stmt->execute([$is_reconciled, $transaction_id]);
                    break;
                    
                case 'payroll':
                    $stmt = $conn->prepare("UPDATE payroll_details SET is_reconciled = ?, reconciled_at = " . ($is_reconciled ? "NOW()" : "NULL") . " WHERE payroll_detail_id = ?");
                    $stmt->execute([$is_reconciled, $transaction_id]);
                    break;
            }
            
            $conn->commit();
            $success = $is_reconciled ? "Transaction marked as reconciled" : "Transaction marked as unreconciled";
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error toggling reconciliation: " . $e->getMessage());
            $errors[] = "Error updating reconciliation status";
        }
    }
}

// Fetch all transactions (Money IN and Money OUT)
$all_transactions = [];
$summary = [
    'total_money_in' => 0,
    'total_money_out' => 0,
    'reconciled_in' => 0,
    'reconciled_out' => 0,
    'unreconciled_in' => 0,
    'unreconciled_out' => 0,
    'count_in' => 0,
    'count_out' => 0,
    'count_reconciled' => 0,
    'count_unreconciled' => 0
];

if ($selected_account_id) {
    try {
        // MONEY IN - Customer Payments
        $stmt = $conn->prepare("
            SELECT 
                'payment' as transaction_type,
                'money_in' as flow_type,
                p.payment_id as id,
                p.payment_number as reference,
                p.payment_date as transaction_date,
                p.amount,
                p.payment_method,
                p.transaction_reference,
                p.is_reconciled,
                p.reconciled_at,
                CONCAT('Payment from ', c.full_name, ' for ', r.reservation_number) as description,
                c.full_name as related_party,
                'Customer Payment' as category
            FROM payments p
            INNER JOIN reservations r ON p.reservation_id = r.reservation_id
            INNER JOIN customers c ON r.customer_id = c.customer_id
            WHERE p.company_id = ? 
            AND p.status = 'approved'
            AND p.payment_date BETWEEN ? AND ?
        ");
        $stmt->execute([$company_id, $date_from, $date_to]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_transactions = array_merge($all_transactions, $payments);
        
        // MONEY OUT - Expense Claims
        $stmt = $conn->prepare("
            SELECT 
                'expense_claim' as transaction_type,
                'money_out' as flow_type,
                ec.claim_id as id,
                ec.claim_number as reference,
                ec.paid_at as transaction_date,
                ec.total_amount as amount,
                ec.payment_method,
                ec.payment_reference as transaction_reference,
                COALESCE(ec.is_reconciled, 0) as is_reconciled,
                ec.reconciled_at,
                CONCAT('Expense claim by ', e.full_name) as description,
                e.full_name as related_party,
                'Expense Claim' as category
            FROM expense_claims ec
            INNER JOIN employees e ON ec.employee_id = e.employee_id
            WHERE ec.company_id = ?
            AND ec.status = 'paid'
            AND ec.paid_at BETWEEN ? AND ?
        ");
        $stmt->execute([$company_id, $date_from, $date_to]);
        $expense_claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_transactions = array_merge($all_transactions, $expense_claims);
        
        // MONEY OUT - Direct Expenses
        $stmt = $conn->prepare("
            SELECT 
                'direct_expense' as transaction_type,
                'money_out' as flow_type,
                de.expense_id as id,
                de.expense_number as reference,
                de.paid_at as transaction_date,
                de.total_amount as amount,
                de.payment_method,
                de.payment_reference as transaction_reference,
                COALESCE(de.is_reconciled, 0) as is_reconciled,
                de.reconciled_at,
                de.description,
                de.vendor_name as related_party,
                'Direct Expense' as category
            FROM direct_expenses de
            WHERE de.company_id = ?
            AND de.status = 'paid'
            AND de.paid_at BETWEEN ? AND ?
        ");
        $stmt->execute([$company_id, $date_from, $date_to]);
        $direct_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_transactions = array_merge($all_transactions, $direct_expenses);
        
        // MONEY OUT - Commission Payments
        $stmt = $conn->prepare("
            SELECT 
                'commission' as transaction_type,
                'money_out' as flow_type,
                c.commission_id as id,
                CONCAT('COMM-', c.commission_id) as reference,
                c.paid_date as transaction_date,
                c.commission_amount as amount,
                c.payment_method,
                c.payment_reference as transaction_reference,
                COALESCE(c.is_reconciled, 0) as is_reconciled,
                c.reconciled_at,
                CONCAT('Sales commission to ', c.recipient_name) as description,
                c.recipient_name as related_party,
                'Commission' as category
            FROM commissions c
            WHERE c.company_id = ?
            AND c.payment_status = 'paid'
            AND c.paid_date BETWEEN ? AND ?
        ");
        $stmt->execute([$company_id, $date_from, $date_to]);
        $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_transactions = array_merge($all_transactions, $commissions);
        
        // MONEY OUT - Refunds
        $stmt = $conn->prepare("
            SELECT 
                'refund' as transaction_type,
                'money_out' as flow_type,
                r.refund_id as id,
                r.refund_number as reference,
                r.processed_at as transaction_date,
                r.net_refund_amount as amount,
                r.refund_method as payment_method,
                r.transaction_reference,
                COALESCE(r.is_reconciled, 0) as is_reconciled,
                r.reconciled_at,
                CONCAT('Refund to ', c.full_name) as description,
                c.full_name as related_party,
                'Refund' as category
            FROM refunds r
            INNER JOIN reservations res ON r.reservation_id = res.reservation_id
            INNER JOIN customers c ON res.customer_id = c.customer_id
            WHERE r.company_id = ?
            AND r.status = 'processed'
            AND r.processed_at BETWEEN ? AND ?
        ");
        $stmt->execute([$company_id, $date_from, $date_to]);
        $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_transactions = array_merge($all_transactions, $refunds);
        
        // MONEY OUT - Payroll/Salaries
        $stmt = $conn->prepare("
            SELECT 
                'payroll' as transaction_type,
                'money_out' as flow_type,
                pd.payroll_detail_id as id,
                CONCAT('PAY-', p.payroll_month, '-', p.payroll_year, '-', e.employee_number) as reference,
                p.payment_date as transaction_date,
                pd.net_salary as amount,
                'bank_transfer' as payment_method,
                pd.payment_reference as transaction_reference,
                COALESCE(pd.is_reconciled, 0) as is_reconciled,
                pd.reconciled_at,
                CONCAT('Salary for ', e.full_name, ' - ', DATE_FORMAT(CONCAT(p.payroll_year, '-', p.payroll_month, '-01'), '%M %Y')) as description,
                e.full_name as related_party,
                'Salary' as category
            FROM payroll_details pd
            INNER JOIN payroll p ON pd.payroll_id = p.payroll_id
            INNER JOIN employees e ON pd.employee_id = e.employee_id
            WHERE p.company_id = ?
            AND p.status IN ('processed', 'paid')
            AND p.payment_date BETWEEN ? AND ?
        ");
        $stmt->execute([$company_id, $date_from, $date_to]);
        $payroll = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_transactions = array_merge($all_transactions, $payroll);
        
        // Sort by date descending
        usort($all_transactions, function($a, $b) {
            return strtotime($b['transaction_date']) - strtotime($a['transaction_date']);
        });
        
        // Calculate summary
        foreach ($all_transactions as $trans) {
            $amount = (float)$trans['amount'];
            $is_reconciled = (int)$trans['is_reconciled'];
            
            if ($trans['flow_type'] === 'money_in') {
                $summary['total_money_in'] += $amount;
                $summary['count_in']++;
                if ($is_reconciled) {
                    $summary['reconciled_in'] += $amount;
                } else {
                    $summary['unreconciled_in'] += $amount;
                }
            } else {
                $summary['total_money_out'] += $amount;
                $summary['count_out']++;
                if ($is_reconciled) {
                    $summary['reconciled_out'] += $amount;
                } else {
                    $summary['unreconciled_out'] += $amount;
                }
            }
            
            if ($is_reconciled) {
                $summary['count_reconciled']++;
            } else {
                $summary['count_unreconciled']++;
            }
        }
        
        // Apply filters
        if ($transaction_type === 'money_in') {
            $all_transactions = array_filter($all_transactions, function($t) {
                return $t['flow_type'] === 'money_in';
            });
        } elseif ($transaction_type === 'money_out') {
            $all_transactions = array_filter($all_transactions, function($t) {
                return $t['flow_type'] === 'money_out';
            });
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching transactions: " . $e->getMessage());
    }
}

$page_title = 'Bank Reconciliation';
require_once '../../includes/header.php';
?>

<style>
/* Professional Modern Design */
:root {
    --primary-color: #2563eb;
    --success-color: #10b981;
    --danger-color: #ef4444;
    --warning-color: #f59e0b;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-900: #111827;
}

.page-container {
    background: var(--gray-50);
    min-height: 100vh;
    padding: 2rem 1rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-left: 4px solid;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.stat-card.blue { border-left-color: var(--primary-color); }
.stat-card.green { border-left-color: var(--success-color); }
.stat-card.red { border-left-color: var(--danger-color); }
.stat-card.orange { border-left-color: var(--warning-color); }

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--gray-900);
    line-height: 1;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.875rem;
    color: var(--gray-600);
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.stat-subtext {
    font-size: 0.75rem;
    color: var(--gray-600);
    margin-top: 0.5rem;
}

.filter-card {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.transactions-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
}

.card-header {
    background: linear-gradient(135deg, var(--primary-color) 0%, #1e40af 100%);
    color: white;
    padding: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-title {
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-body {
    padding: 0;
}

.transaction-row {
    display: grid;
    grid-template-columns: 80px 150px 1fr 200px 150px 180px 120px 80px;
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    align-items: center;
    transition: background 0.2s;
}

.transaction-row:hover {
    background: var(--gray-50);
}

.transaction-row.header {
    background: var(--gray-100);
    font-weight: 600;
    color: var(--gray-700);
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.transaction-row.header:hover {
    background: var(--gray-100);
}

.flow-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
}

.flow-badge.in {
    background: #d1fae5;
    color: #065f46;
}

.flow-badge.out {
    background: #fee2e2;
    color: #991b1b;
}

.category-badge {
    display: inline-block;
    padding: 0.25rem 0.625rem;
    background: var(--gray-100);
    color: var(--gray-700);
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 500;
}

.amount-in {
    color: var(--success-color);
    font-weight: 700;
    font-size: 1rem;
}

.amount-out {
    color: var(--danger-color);
    font-weight: 700;
    font-size: 1rem;
}

.reconcile-toggle {
    position: relative;
    display: inline-block;
    width: 52px;
    height: 28px;
}

.reconcile-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: var(--gray-300);
    transition: 0.3s;
    border-radius: 28px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
}

input:checked + .toggle-slider {
    background-color: var(--success-color);
}

input:checked + .toggle-slider:before {
    transform: translateX(24px);
}

.reconcile-status {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.reconcile-status.reconciled {
    background: #d1fae5;
    color: #065f46;
}

.reconcile-status.unreconciled {
    background: #fef3c7;
    color: #92400e;
}

.reference-text {
    font-family: 'Courier New', monospace;
    font-size: 0.875rem;
    color: var(--gray-700);
    font-weight: 500;
}

.description-text {
    color: var(--gray-600);
    font-size: 0.875rem;
    line-height: 1.4;
}

.party-text {
    color: var(--gray-900);
    font-weight: 500;
    font-size: 0.875rem;
}

.date-text {
    color: var(--gray-600);
    font-size: 0.875rem;
}

.method-text {
    font-size: 0.75rem;
    color: var(--gray-600);
    text-transform: capitalize;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--gray-600);
}

.empty-state-icon {
    font-size: 4rem;
    opacity: 0.3;
    margin-bottom: 1rem;
}

.empty-state-text {
    font-size: 1.125rem;
    margin-bottom: 0.5rem;
}

.filter-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.filter-tab {
    padding: 0.625rem 1.25rem;
    border-radius: 8px;
    border: 2px solid var(--gray-200);
    background: white;
    color: var(--gray-700);
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.filter-tab:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
}

.filter-tab.active {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: white;
}

@media (max-width: 1200px) {
    .transaction-row {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }
    
    .transaction-row.header {
        display: none;
    }
}
</style>

<div class="page-container">
    <div class="container-fluid">
        
        <!-- Page Header -->
        <div class="mb-4">
            <h1 class="mb-1" style="color: var(--gray-900); font-weight: 700; font-size: 2rem;">
                <i class="fas fa-sync-alt" style="color: var(--primary-color);"></i>
                Bank Reconciliation
            </h1>
            <p style="color: var(--gray-600); margin: 0;">Comprehensive view of all money transactions</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
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

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" id="filterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" style="font-weight: 600; color: var(--gray-700);">
                            <i class="fas fa-university me-1"></i>Bank Account
                        </label>
                        <select name="account_id" class="form-select" onchange="this.form.submit()">
                            <option value="">All Accounts</option>
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?php echo $account['bank_account_id']; ?>"
                                        <?php echo $selected_account_id == $account['bank_account_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['account_name']); ?> 
                                    (<?php echo htmlspecialchars($account['account_number']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" style="font-weight: 600; color: var(--gray-700);">
                            <i class="fas fa-calendar me-1"></i>From Date
                        </label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>" onchange="this.form.submit()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" style="font-weight: 600; color: var(--gray-700);">
                            <i class="fas fa-calendar me-1"></i>To Date
                        </label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>" onchange="this.form.submit()">
                    </div>
                    <div class="col-md-3">
                        <div class="filter-tabs">
                            <button type="button" class="filter-tab <?php echo $transaction_type === 'all' ? 'active' : ''; ?>" 
                                    onclick="setFilter('all')">All</button>
                            <button type="button" class="filter-tab <?php echo $transaction_type === 'money_in' ? 'active' : ''; ?>" 
                                    onclick="setFilter('money_in')">Money In</button>
                            <button type="button" class="filter-tab <?php echo $transaction_type === 'money_out' ? 'active' : ''; ?>" 
                                    onclick="setFilter('money_out')">Money Out</button>
                        </div>
                        <input type="hidden" name="type" id="typeInput" value="<?php echo $transaction_type; ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-1"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($selected_account_id): ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-value"><?php echo count($all_transactions); ?></div>
                <div class="stat-label">Total Transactions</div>
                <div class="stat-subtext">
                    <?php echo $summary['count_in']; ?> in • <?php echo $summary['count_out']; ?> out
                </div>
            </div>
            
            <div class="stat-card green">
                <div class="stat-value">TSH <?php echo number_format($summary['total_money_in'] / 1000, 0); ?>K</div>
                <div class="stat-label">Money In</div>
                <div class="stat-subtext">
                    Reconciled: TSH <?php echo number_format($summary['reconciled_in'] / 1000, 0); ?>K
                </div>
            </div>
            
            <div class="stat-card red">
                <div class="stat-value">TSH <?php echo number_format($summary['total_money_out'] / 1000, 0); ?>K</div>
                <div class="stat-label">Money Out</div>
                <div class="stat-subtext">
                    Reconciled: TSH <?php echo number_format($summary['reconciled_out'] / 1000, 0); ?>K
                </div>
            </div>
            
            <div class="stat-card green">
                <div class="stat-value"><?php echo $summary['count_reconciled']; ?></div>
                <div class="stat-label">Reconciled</div>
                <div class="stat-subtext">
                    <?php echo count($all_transactions) > 0 ? round(($summary['count_reconciled'] / count($all_transactions)) * 100) : 0; ?>% complete
                </div>
            </div>
            
            <div class="stat-card orange">
                <div class="stat-value"><?php echo $summary['count_unreconciled']; ?></div>
                <div class="stat-label">Unreconciled</div>
                <div class="stat-subtext">
                    TSH <?php echo number_format(($summary['unreconciled_in'] + $summary['unreconciled_out']) / 1000, 0); ?>K pending
                </div>
            </div>
            
            <div class="stat-card <?php echo ($summary['total_money_in'] - $summary['total_money_out']) >= 0 ? 'green' : 'red'; ?>">
                <div class="stat-value">TSH <?php echo number_format(abs($summary['total_money_in'] - $summary['total_money_out']) / 1000, 0); ?>K</div>
                <div class="stat-label">Net Cash Flow</div>
                <div class="stat-subtext">
                    <?php echo ($summary['total_money_in'] - $summary['total_money_out']) >= 0 ? 'Positive' : 'Negative'; ?>
                </div>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="transactions-card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-list"></i>
                    All Transactions (<?php echo count($all_transactions); ?>)
                </h3>
                <div style="font-size: 0.875rem;">
                    <?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?>
                </div>
            </div>
            
            <div class="card-body">
                <?php if (empty($all_transactions)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <div class="empty-state-text">No transactions found</div>
                        <p style="font-size: 0.875rem; color: var(--gray-600);">
                            Try adjusting your filters or date range
                        </p>
                    </div>
                <?php else: ?>
                    <!-- Header Row -->
                    <div class="transaction-row header">
                        <div>Type</div>
                        <div>Reference</div>
                        <div>Description</div>
                        <div>Party</div>
                        <div>Amount</div>
                        <div>Date</div>
                        <div>Category</div>
                        <div>Status</div>
                    </div>
                    
                    <!-- Data Rows -->
                    <?php foreach ($all_transactions as $trans): ?>
                    <div class="transaction-row">
                        <div>
                            <span class="flow-badge <?php echo $trans['flow_type'] === 'money_in' ? 'in' : 'out'; ?>">
                                <i class="fas fa-arrow-<?php echo $trans['flow_type'] === 'money_in' ? 'down' : 'up'; ?>"></i>
                                <?php echo $trans['flow_type'] === 'money_in' ? 'Money In' : 'Money Out'; ?>
                            </span>
                        </div>
                        
                        <div>
                            <div class="reference-text"><?php echo htmlspecialchars($trans['reference']); ?></div>
                            <?php if ($trans['payment_method']): ?>
                                <div class="method-text">
                                    <i class="fas fa-credit-card"></i>
                                    <?php echo str_replace('_', ' ', $trans['payment_method']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <div class="description-text"><?php echo htmlspecialchars($trans['description']); ?></div>
                            <?php if ($trans['transaction_reference']): ?>
                                <div style="font-size: 0.75rem; color: var(--gray-600); margin-top: 0.25rem;">
                                    Ref: <?php echo htmlspecialchars($trans['transaction_reference']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <div class="party-text">
                                <i class="fas fa-user" style="color: var(--gray-600);"></i>
                                <?php echo htmlspecialchars($trans['related_party']); ?>
                            </div>
                        </div>
                        
                        <div>
                            <div class="<?php echo $trans['flow_type'] === 'money_in' ? 'amount-in' : 'amount-out'; ?>">
                                <?php echo $trans['flow_type'] === 'money_in' ? '+' : '-'; ?>
                                TSH <?php echo number_format($trans['amount'], 2); ?>
                            </div>
                        </div>
                        
                        <div>
                            <div class="date-text">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('M d, Y', strtotime($trans['transaction_date'])); ?>
                            </div>
                        </div>
                        
                        <div>
                            <span class="category-badge"><?php echo htmlspecialchars($trans['category']); ?></span>
                        </div>
                        
                        <div>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="toggle_reconcile">
                                <input type="hidden" name="transaction_type" value="<?php echo $trans['transaction_type']; ?>">
                                <input type="hidden" name="transaction_id" value="<?php echo $trans['id']; ?>">
                                <input type="hidden" name="is_reconciled" value="<?php echo $trans['is_reconciled'] ? '0' : '1'; ?>">
                                
                                <label class="reconcile-toggle">
                                    <input type="checkbox" 
                                           <?php echo $trans['is_reconciled'] ? 'checked' : ''; ?>
                                           onchange="this.form.submit()">
                                    <span class="toggle-slider"></span>
                                </label>
                            </form>
                            
                            <?php if ($trans['reconciled_at']): ?>
                                <div style="font-size: 0.625rem; color: var(--gray-600); margin-top: 0.25rem;">
                                    <?php echo date('M d, g:i A', strtotime($trans['reconciled_at'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <div class="empty-state" style="background: white; border-radius: 16px; padding: 4rem;">
            <div class="empty-state-icon">
                <i class="fas fa-university"></i>
            </div>
            <div class="empty-state-text">Select a Bank Account</div>
            <p style="font-size: 0.875rem; color: var(--gray-600);">
                Choose a bank account above to view all transactions
            </p>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
function setFilter(type) {
    document.getElementById('typeInput').value = type;
    document.getElementById('filterForm').submit();
}
</script>

<?php 
require_once '../../includes/footer.php';
?>