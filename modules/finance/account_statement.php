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

// Get account ID from URL
$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

if (!$account_id) {
    $_SESSION['error_message'] = "Invalid account ID";
    header('Location: bank_accounts.php');
    exit;
}

// Date filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$transaction_type = isset($_GET['transaction_type']) ? $_GET['transaction_type'] : 'all';

// Fetch account details
try {
    $stmt = $conn->prepare("
        SELECT * FROM bank_accounts 
        WHERE bank_account_id = ? AND company_id = ?
    ");
    $stmt->execute([$account_id, $company_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        $_SESSION['error_message'] = "Account not found";
        header('Location: bank_accounts.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching account: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading account";
    header('Location: bank_accounts.php');
    exit;
}

// Fetch all movements (bank transactions + payments)
try {
    // First, let's check if there are ANY payments for this account
    $debug_query = "SELECT COUNT(*) as payment_count, 
                           MIN(payment_date) as earliest_payment,
                           MAX(payment_date) as latest_payment
                    FROM payments 
                    WHERE to_account_id = ? AND company_id = ? AND status = 'approved'";
    $debug_stmt = $conn->prepare($debug_query);
    $debug_stmt->execute([$account_id, $company_id]);
    $payment_info = $debug_stmt->fetch(PDO::FETCH_ASSOC);
    $payment_count = $payment_info['payment_count'];
    
    error_log("DEBUG: Account ID: $account_id, Company ID: $company_id");
    error_log("DEBUG: Total approved payments for this account: $payment_count");
    error_log("DEBUG: Earliest payment: " . $payment_info['earliest_payment']);
    error_log("DEBUG: Latest payment: " . $payment_info['latest_payment']);
    error_log("DEBUG: Date range filter: $start_date to $end_date");
    
    // Check bank transactions
    $debug_bt = "SELECT COUNT(*) as bt_count FROM bank_transactions WHERE company_id = ? AND (from_account_id = ? OR to_account_id = ?)";
    $debug_bt_stmt = $conn->prepare($debug_bt);
    $debug_bt_stmt->execute([$company_id, $account_id, $account_id]);
    $bt_count = $debug_bt_stmt->fetchColumn();
    error_log("DEBUG: Total bank transactions for this account: $bt_count");
    
    $query = "
        SELECT * FROM (
            -- Bank Transactions
            SELECT 
                t.bank_transaction_id as id,
                'bank_transaction' as source_type,
                t.transaction_date as movement_date,
                t.transaction_type as type_name,
                t.description,
                t.reference_number,
                t.amount,
                CASE 
                    WHEN t.to_account_id = ? THEN 'incoming'
                    WHEN t.from_account_id = ? THEN 'outgoing'
                    ELSE 'internal'
                END as direction,
                from_acc.account_name as from_account_name,
                from_acc.bank_name as from_bank_name,
                from_acc.mobile_provider as from_mobile_provider,
                to_acc.account_name as to_account_name,
                to_acc.bank_name as to_bank_name,
                to_acc.mobile_provider as to_mobile_provider,
                u.first_name as created_by_first,
                u.last_name as created_by_last,
                t.created_at,
                NULL as customer_name,
                NULL as payment_method,
                NULL as plot_number,
                NULL as payment_number,
                NULL as reservation_number
            FROM bank_transactions t
            LEFT JOIN bank_accounts from_acc ON t.from_account_id = from_acc.bank_account_id
            LEFT JOIN bank_accounts to_acc ON t.to_account_id = to_acc.bank_account_id
            LEFT JOIN users u ON t.created_by = u.user_id
            WHERE t.company_id = ?
            AND (t.from_account_id = ? OR t.to_account_id = ?)
            
            UNION ALL
            
            -- Payments received (incoming to this account)
            SELECT 
                p.payment_id as id,
                'payment' as source_type,
                p.payment_date as movement_date,
                CONCAT('Payment - ', COALESCE(p.payment_type, 'payment')) as type_name,
                COALESCE(p.remarks, '') as description,
                COALESCE(p.transaction_reference, '') as reference_number,
                p.amount,
                'incoming' as direction,
                CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, '')) as from_account_name,
                NULL as from_bank_name,
                NULL as from_mobile_provider,
                ba.account_name as to_account_name,
                ba.bank_name as to_bank_name,
                ba.mobile_provider as to_mobile_provider,
                u.first_name as created_by_first,
                u.last_name as created_by_last,
                p.created_at,
                CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.middle_name, ''), ' ', COALESCE(c.last_name, '')) as customer_name,
                p.payment_method,
                COALESCE(pl.plot_number, '') as plot_number,
                p.payment_number,
                r.reservation_number
            FROM payments p
            INNER JOIN reservations r ON p.reservation_id = r.reservation_id
            INNER JOIN customers c ON r.customer_id = c.customer_id
            LEFT JOIN plots pl ON r.plot_id = pl.plot_id
            LEFT JOIN bank_accounts ba ON p.to_account_id = ba.bank_account_id
            LEFT JOIN users u ON p.created_by = u.user_id
            WHERE p.company_id = ?
            AND p.to_account_id = ?
            AND p.status = 'approved'
        ) as all_movements
        WHERE 1=1";
    
    // Add date filters AFTER the union
    if ($start_date) {
        $query .= " AND DATE(movement_date) >= '$start_date'";
    }
    if ($end_date) {
        $query .= " AND DATE(movement_date) <= '$end_date'";
    }
    
    // Add transaction type filter
    if ($transaction_type === 'incoming') {
        $query .= " AND direction = 'incoming'";
    } elseif ($transaction_type === 'outgoing') {
        $query .= " AND direction = 'outgoing'";
    }
    
    $query .= " ORDER BY movement_date ASC, created_at ASC";
    
    error_log("DEBUG: Full query: " . $query);
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        $account_id,  // for bank_transaction incoming check
        $account_id,  // for bank_transaction outgoing check
        $company_id,  // for bank_transactions company filter
        $account_id,  // for bank_transactions from_account
        $account_id,  // for bank_transactions to_account
        $company_id,  // for payments company filter
        $account_id   // for payments to_account
    ]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("DEBUG: Total transactions/payments returned: " . count($transactions));
    if (count($transactions) > 0) {
        error_log("DEBUG: First transaction: " . print_r($transactions[0], true));
    } else {
        error_log("DEBUG: No transactions returned after date filtering");
    }
    
} catch (PDOException $e) {
    error_log("Error fetching movements: " . $e->getMessage());
    error_log("DEBUG: SQL Error: " . $e->getMessage());
    $transactions = [];
}

// Calculate opening balance (all movements before start date)
try {
    $opening_query = "
        SELECT 
            COALESCE(SUM(CASE WHEN movement_type = 'incoming' THEN amount ELSE 0 END), 0) as total_in,
            COALESCE(SUM(CASE WHEN movement_type = 'outgoing' THEN amount ELSE 0 END), 0) as total_out
        FROM (
            -- Bank transactions incoming
            SELECT amount, 'incoming' as movement_type
            FROM bank_transactions
            WHERE company_id = ?
            AND to_account_id = ?
            AND DATE(transaction_date) < ?
            
            UNION ALL
            
            -- Bank transactions outgoing
            SELECT amount, 'outgoing' as movement_type
            FROM bank_transactions
            WHERE company_id = ?
            AND from_account_id = ?
            AND DATE(transaction_date) < ?
            
            UNION ALL
            
            -- Payments incoming (approved only)
            SELECT amount, 'incoming' as movement_type
            FROM payments
            WHERE company_id = ?
            AND to_account_id = ?
            AND status = 'approved'
            AND DATE(payment_date) < ?
        ) as all_movements
    ";
    
    $stmt = $conn->prepare($opening_query);
    $stmt->execute([
        $company_id, $account_id, $start_date,  // Bank transactions incoming
        $company_id, $account_id, $start_date,  // Bank transactions outgoing
        $company_id, $account_id, $start_date   // Payments incoming
    ]);
    $opening_balance_calc = $stmt->fetch(PDO::FETCH_ASSOC);
    $opening_balance = $account['opening_balance'] + $opening_balance_calc['total_in'] - $opening_balance_calc['total_out'];
} catch (PDOException $e) {
    error_log("Error calculating opening balance: " . $e->getMessage());
    $opening_balance = $account['opening_balance'];
}

// Calculate totals
$total_incoming = 0;
$total_outgoing = 0;
$running_balance = $opening_balance;

foreach ($transactions as &$transaction) {
    if ($transaction['direction'] === 'incoming') {
        $total_incoming += $transaction['amount'];
        $running_balance += $transaction['amount'];
    } else {
        $total_outgoing += $transaction['amount'];
        $running_balance -= $transaction['amount'];
    }
    $transaction['running_balance'] = $running_balance;
}
unset($transaction);

$closing_balance = $running_balance;

$page_title = 'Account Statement - ' . $account['account_name'];
require_once '../../includes/header.php';
?>

<style>
.statement-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 16px rgba(102, 126, 234, 0.3);
}

.account-info {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.balance-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
}

.balance-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.balance-card.opening { border-left-color: #6c757d; }
.balance-card.incoming { border-left-color: #28a745; }
.balance-card.outgoing { border-left-color: #dc3545; }
.balance-card.closing { border-left-color: #007bff; }

.balance-amount {
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0.5rem 0;
}

.balance-label {
    font-size: 0.875rem;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.statement-table {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.statement-table table {
    margin-bottom: 0;
}

.statement-table thead th {
    background: #2c3e50;
    color: white;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
    border: none;
    padding: 1rem;
}

.statement-table tbody tr {
    transition: background 0.2s;
}

.statement-table tbody tr:hover {
    background: #f8f9fa;
}

.statement-table td {
    padding: 1rem;
    vertical-align: middle;
}

.amount-incoming {
    color: #28a745;
    font-weight: 700;
    font-family: 'Courier New', monospace;
}

.amount-outgoing {
    color: #dc3545;
    font-weight: 700;
    font-family: 'Courier New', monospace;
}

.amount-balance {
    color: #007bff;
    font-weight: 700;
    font-family: 'Courier New', monospace;
}

.badge-direction {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-incoming {
    background: #d4edda;
    color: #155724;
}

.badge-outgoing {
    background: #f8d7da;
    color: #721c24;
}

.filter-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.print-hide {
    display: block;
}

@media print {
    .print-hide {
        display: none !important;
    }
    
    .statement-header {
        background: white !important;
        color: black !important;
        border: 2px solid #000;
        box-shadow: none !important;
    }
    
    .statement-table {
        box-shadow: none !important;
        border: 1px solid #000;
    }
    
    .statement-table thead th {
        background: #000 !important;
        color: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4 print-hide">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice-dollar text-primary me-2"></i>Account Statement
                </h1>
                <p class="text-muted small mb-0 mt-1">View all incoming and outgoing transactions</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button onclick="window.print()" class="btn btn-secondary me-2">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                    <a href="bank_accounts.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Accounts
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Statement Header -->
        <div class="statement-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-2">
                        <?php if ($account['account_category'] === 'bank'): ?>
                            <i class="fas fa-university me-2"></i>
                            <?php echo htmlspecialchars($account['bank_name']); ?>
                        <?php else: ?>
                            <i class="fas fa-mobile-alt me-2"></i>
                            <?php echo htmlspecialchars($account['mobile_provider']); ?>
                        <?php endif; ?>
                    </h2>
                    <h4 class="mb-2"><?php echo htmlspecialchars($account['account_name']); ?></h4>
                    <p class="mb-0">
                        <?php if ($account['account_category'] === 'bank'): ?>
                            Account Number: <strong><?php echo htmlspecialchars($account['account_number']); ?></strong>
                            <?php if ($account['branch_name']): ?>
                                | Branch: <?php echo htmlspecialchars($account['branch_name']); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            Mobile Number: <strong><?php echo htmlspecialchars($account['mobile_number']); ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="h5 mb-1">Statement Period</div>
                    <div class="h3 mb-0">
                        <?php echo date('d M Y', strtotime($start_date)); ?> - 
                        <?php echo date('d M Y', strtotime($end_date)); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card print-hide">
            <form method="GET" class="row g-3">
                <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
                
                <div class="col-md-3">
                    <label class="form-label fw-bold">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-bold">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-bold">Transaction Type</label>
                    <select name="transaction_type" class="form-select">
                        <option value="all" <?php echo $transaction_type === 'all' ? 'selected' : ''; ?>>All Transactions</option>
                        <option value="incoming" <?php echo $transaction_type === 'incoming' ? 'selected' : ''; ?>>Incoming Only</option>
                        <option value="outgoing" <?php echo $transaction_type === 'outgoing' ? 'selected' : ''; ?>>Outgoing Only</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                </div>
            </form>
            
            <!-- DEBUG INFO -->
            <?php if (isset($payment_count) || isset($bt_count)): ?>
            <div class="alert alert-info mt-3">
                <strong>🔍 Debug Information:</strong><br>
                <strong>Account:</strong> ID <?php echo $account_id; ?> | Company ID <?php echo $company_id; ?><br>
                <strong>Data Found:</strong><br>
                &nbsp;&nbsp;• Approved Payments: <?php echo $payment_count ?? 0; ?><br>
                <?php if (isset($payment_info) && $payment_count > 0): ?>
                    &nbsp;&nbsp;• Payment Date Range: <?php echo $payment_info['earliest_payment']; ?> to <?php echo $payment_info['latest_payment']; ?><br>
                <?php endif; ?>
                &nbsp;&nbsp;• Bank Transactions: <?php echo $bt_count ?? 0; ?><br>
                <strong>Filter Applied:</strong> <?php echo $start_date; ?> to <?php echo $end_date; ?><br>
                <strong>Results:</strong> <?php echo count($transactions); ?> movements found<br>
                <?php if (count($transactions) > 0): ?>
                    <strong>✅ Sample:</strong> <?php echo $transactions[0]['source_type']; ?> | 
                    <?php echo $transactions[0]['movement_date']; ?> | 
                    TZS <?php echo number_format($transactions[0]['amount'], 2); ?>
                <?php elseif ($payment_count > 0 && isset($payment_info)): ?>
                    <strong>⚠️ Issue:</strong> Payment exists (<?php echo $payment_info['earliest_payment']; ?>) but is outside your date filter (<?php echo $start_date; ?> to <?php echo $end_date; ?>)
                    <br><strong>💡 Solution:</strong> Adjust the date range to include <?php echo date('M Y', strtotime($payment_info['earliest_payment'])); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Balance Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="balance-card opening">
                    <div class="balance-label">Opening Balance</div>
                    <div class="balance-amount">
                        <?php echo htmlspecialchars($account['currency']); ?> 
                        <?php echo number_format($opening_balance, 2); ?>
                    </div>
                    <small class="text-muted">As of <?php echo date('d M Y', strtotime($start_date)); ?></small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="balance-card incoming">
                    <div class="balance-label">Total Incoming</div>
                    <div class="balance-amount text-success">
                        + <?php echo htmlspecialchars($account['currency']); ?> 
                        <?php echo number_format($total_incoming, 2); ?>
                    </div>
                    <small class="text-muted"><?php echo count(array_filter($transactions, fn($t) => $t['direction'] === 'incoming')); ?> transactions</small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="balance-card outgoing">
                    <div class="balance-label">Total Outgoing</div>
                    <div class="balance-amount text-danger">
                        - <?php echo htmlspecialchars($account['currency']); ?> 
                        <?php echo number_format($total_outgoing, 2); ?>
                    </div>
                    <small class="text-muted"><?php echo count(array_filter($transactions, fn($t) => $t['direction'] === 'outgoing')); ?> transactions</small>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="balance-card closing">
                    <div class="balance-label">Closing Balance</div>
                    <div class="balance-amount text-primary">
                        <?php echo htmlspecialchars($account['currency']); ?> 
                        <?php echo number_format($closing_balance, 2); ?>
                    </div>
                    <small class="text-muted">As of <?php echo date('d M Y', strtotime($end_date)); ?></small>
                </div>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="statement-table">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 100px;">Date</th>
                        <th style="width: 100px;">Type</th>
                        <th>Description</th>
                        <th>From Account</th>
                        <th>To Account</th>
                        <th class="text-end" style="width: 150px;">Incoming</th>
                        <th class="text-end" style="width: 150px;">Outgoing</th>
                        <th class="text-end" style="width: 150px;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Opening Balance Row -->
                    <tr style="background: #f8f9fa; font-weight: 600;">
                        <td colspan="5"><strong>OPENING BALANCE</strong></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end amount-balance">
                            <?php echo number_format($opening_balance, 2); ?>
                        </td>
                    </tr>
                    
                    <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                            No transactions found for the selected period
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td>
                                <div style="font-size: 0.85rem;">
                                    <?php echo date('d M Y', strtotime($transaction['movement_date'])); ?>
                                </div>
                                <small class="text-muted"><?php echo date('H:i', strtotime($transaction['created_at'])); ?></small>
                            </td>
                            <td>
                                <span class="badge-direction <?php echo $transaction['direction'] === 'incoming' ? 'badge-incoming' : 'badge-outgoing'; ?>">
                                    <?php echo $transaction['direction'] === 'incoming' ? '↓ IN' : '↑ OUT'; ?>
                                </span>
                                <?php if ($transaction['source_type'] === 'payment'): ?>
                                    <div><small class="badge bg-success mt-1">PAYMENT</small></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 600;">
                                    <?php echo htmlspecialchars($transaction['type_name']); ?>
                                </div>
                                
                                <?php if ($transaction['source_type'] === 'payment'): ?>
                                    <?php if (!empty($transaction['payment_number'])): ?>
                                        <div><small class="text-primary"><strong>Pay #:</strong> <?php echo htmlspecialchars($transaction['payment_number']); ?></small></div>
                                    <?php endif; ?>
                                    <?php if (!empty($transaction['customer_name'])): ?>
                                        <div><small class="text-info"><strong>From:</strong> <?php echo htmlspecialchars(trim($transaction['customer_name'])); ?></small></div>
                                    <?php endif; ?>
                                    <?php if (!empty($transaction['reservation_number'])): ?>
                                        <div><small class="text-muted"><strong>Res:</strong> <?php echo htmlspecialchars($transaction['reservation_number']); ?></small></div>
                                    <?php endif; ?>
                                    <?php if (!empty($transaction['plot_number'])): ?>
                                        <div><small class="text-muted"><strong>Plot:</strong> <?php echo htmlspecialchars($transaction['plot_number']); ?></small></div>
                                    <?php endif; ?>
                                    <?php if (!empty($transaction['payment_method'])): ?>
                                        <div><small class="text-success"><strong>Method:</strong> <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($transaction['payment_method']))); ?></small></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if ($transaction['description']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($transaction['description']); ?></small>
                                <?php endif; ?>
                                <?php if ($transaction['reference_number']): ?>
                                    <div><small class="text-muted"><strong>Ref:</strong> <?php echo htmlspecialchars($transaction['reference_number']); ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($transaction['from_account_name']): ?>
                                    <?php if ($transaction['source_type'] === 'payment'): ?>
                                        <div style="font-size: 0.9rem; font-weight: 600; color: #17a2b8;">
                                            Customer Payment
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars(trim($transaction['from_account_name'])); ?></small>
                                    <?php else: ?>
                                        <div style="font-size: 0.9rem;">
                                            <?php 
                                            if ($transaction['from_bank_name']) {
                                                echo htmlspecialchars($transaction['from_bank_name']);
                                            } elseif ($transaction['from_mobile_provider']) {
                                                echo htmlspecialchars($transaction['from_mobile_provider']);
                                            }
                                            ?>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($transaction['from_account_name']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">External</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($transaction['to_account_name']): ?>
                                    <div style="font-size: 0.9rem;">
                                        <?php 
                                        if ($transaction['to_bank_name']) {
                                            echo htmlspecialchars($transaction['to_bank_name']);
                                        } elseif ($transaction['to_mobile_provider']) {
                                            echo htmlspecialchars($transaction['to_mobile_provider']);
                                        }
                                        ?>
                                    </div>
                                    <small class="text-muted"><?php echo htmlspecialchars($transaction['to_account_name']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">External</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($transaction['direction'] === 'incoming'): ?>
                                    <span class="amount-incoming">
                                        + <?php echo number_format($transaction['amount'], 2); ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($transaction['direction'] === 'outgoing'): ?>
                                    <span class="amount-outgoing">
                                        - <?php echo number_format($transaction['amount'], 2); ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <span class="amount-balance">
                                    <?php echo number_format($transaction['running_balance'], 2); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <!-- Closing Balance Row -->
                    <tr style="background: #f8f9fa; font-weight: 700; border-top: 3px double #000;">
                        <td colspan="5"><strong>CLOSING BALANCE</strong></td>
                        <td class="text-end amount-incoming">
                            + <?php echo number_format($total_incoming, 2); ?>
                        </td>
                        <td class="text-end amount-outgoing">
                            - <?php echo number_format($total_outgoing, 2); ?>
                        </td>
                        <td class="text-end amount-balance" style="font-size: 1.1rem;">
                            <?php echo number_format($closing_balance, 2); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Statement Footer -->
        <div class="mt-4 text-center text-muted" style="font-size: 0.85rem;">
            <p class="mb-1">
                <i class="fas fa-info-circle me-1"></i>
                This statement shows all transactions for the period from 
                <strong><?php echo date('d M Y', strtotime($start_date)); ?></strong> to 
                <strong><?php echo date('d M Y', strtotime($end_date)); ?></strong>
            </p>
            <p class="mb-0">
                Generated on <?php echo date('d M Y H:i:s'); ?>
                <?php if (!empty($_SESSION['first_name']) && !empty($_SESSION['last_name'])): ?>
                    by <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                <?php endif; ?>
            </p>
        </div>

    </div>
</section>

<?php 
require_once '../../includes/footer.php';
?>