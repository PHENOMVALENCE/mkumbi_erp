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
$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$show_zero_balances = isset($_GET['show_zero_balances']) ? true : false;
$account_level = $_GET['account_level'] ?? 'all'; // all, 1, 2, 3, 4

// Fetch trial balance data
function getTrialBalance($conn, $company_id, $start_date, $end_date, $show_zero_balances, $account_level) {
    $where_conditions = ["a.company_id = ?"];
    $params = [$company_id];
    
    // Filter by account level
    if ($account_level !== 'all') {
        $where_conditions[] = "a.account_level = ?";
        $params[] = $account_level;
    }
    
    // Only show detail accounts (level 4) by default for cleaner view
    if ($account_level === 'all') {
        $where_conditions[] = "a.is_control_account = 0";
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $query = "
        SELECT 
            a.account_id,
            a.account_code,
            a.account_name,
            a.account_type,
            a.account_category,
            a.account_level,
            a.is_control_account,
            a.opening_balance,
            a.current_balance,
            parent.account_code as parent_code,
            parent.account_name as parent_name,
            
            -- Calculate period debits and credits from journal entries
            COALESCE(SUM(CASE 
                WHEN jel.debit_amount > 0 AND je.journal_date BETWEEN ? AND ? 
                THEN jel.debit_amount 
                ELSE 0 
            END), 0) as period_debit,
            
            COALESCE(SUM(CASE 
                WHEN jel.credit_amount > 0 AND je.journal_date BETWEEN ? AND ? 
                THEN jel.credit_amount 
                ELSE 0 
            END), 0) as period_credit
            
        FROM chart_of_accounts a
        LEFT JOIN chart_of_accounts parent ON a.parent_account_id = parent.account_id
        LEFT JOIN journal_entry_lines jel ON a.account_id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_id = je.journal_id 
            AND je.status = 'posted' 
            AND je.company_id = ?
        WHERE $where_clause
        GROUP BY a.account_id, a.account_code, a.account_name, a.account_type, 
                 a.account_category, a.account_level, a.is_control_account,
                 a.opening_balance, a.current_balance, parent.account_code, parent.account_name
        ORDER BY a.account_code ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute(array_merge([$start_date, $end_date, $start_date, $end_date, $company_id], $params));
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate balances
    $trial_balance = [];
    $totals = [
        'total_debit' => 0,
        'total_credit' => 0,
        'opening_debit' => 0,
        'opening_credit' => 0,
        'closing_debit' => 0,
        'closing_credit' => 0
    ];
    
    foreach ($accounts as $account) {
        $opening_balance = (float)$account['opening_balance'];
        $period_debit = (float)$account['period_debit'];
        $period_credit = (float)$account['period_credit'];
        
        // Calculate closing balance based on account type
        $closing_balance = $opening_balance;
        
        // For assets and expenses: Debit increases, Credit decreases
        if (in_array($account['account_type'], ['asset', 'expense'])) {
            $closing_balance = $opening_balance + $period_debit - $period_credit;
        }
        // For liabilities, equity, and revenue: Credit increases, Debit decreases
        else {
            $closing_balance = $opening_balance + $period_credit - $period_debit;
        }
        
        // Use current_balance if no journal entries (for accounts populated from other tables)
        if ($period_debit == 0 && $period_credit == 0 && $account['current_balance'] > 0) {
            $closing_balance = (float)$account['current_balance'];
        }
        
        // Determine debit/credit columns
        $opening_debit = 0;
        $opening_credit = 0;
        $closing_debit = 0;
        $closing_credit = 0;
        
        // Opening Balance
        if (in_array($account['account_type'], ['asset', 'expense'])) {
            $opening_debit = abs($opening_balance);
        } else {
            $opening_credit = abs($opening_balance);
        }
        
        // Closing Balance
        if ($closing_balance > 0) {
            if (in_array($account['account_type'], ['asset', 'expense'])) {
                $closing_debit = $closing_balance;
            } else {
                $closing_credit = $closing_balance;
            }
        } elseif ($closing_balance < 0) {
            // Negative balance (opposite side)
            if (in_array($account['account_type'], ['asset', 'expense'])) {
                $closing_credit = abs($closing_balance);
            } else {
                $closing_debit = abs($closing_balance);
            }
        }
        
        // Skip zero balances if requested
        if (!$show_zero_balances && $opening_balance == 0 && $closing_balance == 0 && $period_debit == 0 && $period_credit == 0) {
            continue;
        }
        
        $trial_balance[] = [
            'account_id' => $account['account_id'],
            'account_code' => $account['account_code'],
            'account_name' => $account['account_name'],
            'account_type' => $account['account_type'],
            'account_category' => $account['account_category'],
            'account_level' => $account['account_level'],
            'is_control_account' => $account['is_control_account'],
            'parent_code' => $account['parent_code'],
            'parent_name' => $account['parent_name'],
            'opening_debit' => $opening_debit,
            'opening_credit' => $opening_credit,
            'period_debit' => $period_debit,
            'period_credit' => $period_credit,
            'closing_debit' => $closing_debit,
            'closing_credit' => $closing_credit,
            'closing_balance' => $closing_balance
        ];
        
        // Add to totals
        $totals['opening_debit'] += $opening_debit;
        $totals['opening_credit'] += $opening_credit;
        $totals['total_debit'] += $period_debit;
        $totals['total_credit'] += $period_credit;
        $totals['closing_debit'] += $closing_debit;
        $totals['closing_credit'] += $closing_credit;
    }
    
    return ['accounts' => $trial_balance, 'totals' => $totals];
}

$result = getTrialBalance($conn, $company_id, $start_date, $end_date, $show_zero_balances, $account_level);
$trial_balance = $result['accounts'];
$totals = $result['totals'];

// Check if trial balance is balanced
$is_balanced = abs($totals['closing_debit'] - $totals['closing_credit']) < 0.01;
$difference = $totals['closing_debit'] - $totals['closing_credit'];

$page_title = 'Trial Balance';
require_once '../../includes/header.php';
?>

<style>
.trial-balance-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.trial-balance-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.account-code {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-weight: 600;
    font-size: 0.875rem;
}

.amount-debit {
    color: #dc3545;
    font-weight: 600;
}

.amount-credit {
    color: #28a745;
    font-weight: 600;
}

.balance-status {
    padding: 0.5rem 1rem;
    border-radius: 8px;
    display: inline-block;
    font-weight: 600;
}

.balance-status.balanced {
    background: #d4edda;
    color: #155724;
}

.balance-status.unbalanced {
    background: #f8d7da;
    color: #721c24;
}

.level-indicator {
    display: inline-block;
    width: 24px;
    height: 24px;
    line-height: 24px;
    text-align: center;
    border-radius: 50%;
    font-size: 0.7rem;
    font-weight: 600;
    margin-right: 8px;
}

.level-1 { background: #007bff; color: white; }
.level-2 { background: #6c757d; color: white; }
.level-3 { background: #17a2b8; color: white; }
.level-4 { background: #28a745; color: white; }

.hierarchy-indent-1 { padding-left: 0; }
.hierarchy-indent-2 { padding-left: 20px; }
.hierarchy-indent-3 { padding-left: 40px; }
.hierarchy-indent-4 { padding-left: 60px; }

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}

.stat-box {
    background: rgba(255,255,255,0.2);
    padding: 1rem;
    border-radius: 8px;
    backdrop-filter: blur(10px);
}

.stat-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
}

.control-badge {
    background: #ffc107;
    color: #000;
    padding: 0.2rem 0.5rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
}

@media print {
    .no-print { display: none !important; }
    .trial-balance-header { background: #667eea !important; }
    .trial-balance-table { box-shadow: none !important; }
}
</style>

<div class="content-header mb-4 no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-balance-scale text-primary me-2"></i>Trial Balance
                </h1>
                <p class="text-muted small mb-0 mt-1">Verify accounting equation balance</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                    <button class="btn btn-info" onclick="exportToExcel()">
                        <i class="fas fa-file-excel me-1"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <!-- Header -->
        <div class="trial-balance-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2 class="mb-0">Trial Balance Report</h2>
                    <p class="mb-0 mt-2">
                        <strong>Period:</strong> <?php echo date('M d, Y', strtotime($start_date)); ?> 
                        to <?php echo date('M d, Y', strtotime($end_date)); ?>
                    </p>
                    <p class="mb-0">
                        <strong>Generated:</strong> <?php echo date('F d, Y h:i A'); ?>
                    </p>
                </div>
                <div class="col-md-6 text-end">
                    <div class="balance-status <?php echo $is_balanced ? 'balanced' : 'unbalanced'; ?>">
                        <?php if ($is_balanced): ?>
                            <i class="fas fa-check-circle me-2"></i>BALANCED
                        <?php else: ?>
                            <i class="fas fa-exclamation-triangle me-2"></i>OUT OF BALANCE
                            <br><small>Difference: TSH <?php echo number_format(abs($difference), 2); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-label">Opening Debits</div>
                    <div class="stat-value">TSH <?php echo number_format($totals['opening_debit']/1000000, 2); ?>M</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Opening Credits</div>
                    <div class="stat-value">TSH <?php echo number_format($totals['opening_credit']/1000000, 2); ?>M</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Period Debits</div>
                    <div class="stat-value">TSH <?php echo number_format($totals['total_debit']/1000000, 2); ?>M</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Period Credits</div>
                    <div class="stat-value">TSH <?php echo number_format($totals['total_credit']/1000000, 2); ?>M</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Closing Debits</div>
                    <div class="stat-value">TSH <?php echo number_format($totals['closing_debit']/1000000, 2); ?>M</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Closing Credits</div>
                    <div class="stat-value">TSH <?php echo number_format($totals['closing_credit']/1000000, 2); ?>M</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card no-print">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Account Level</label>
                    <select name="account_level" class="form-select">
                        <option value="all" <?php echo $account_level == 'all' ? 'selected' : ''; ?>>All Levels</option>
                        <option value="1" <?php echo $account_level == '1' ? 'selected' : ''; ?>>Level 1</option>
                        <option value="2" <?php echo $account_level == '2' ? 'selected' : ''; ?>>Level 2</option>
                        <option value="3" <?php echo $account_level == '3' ? 'selected' : ''; ?>>Level 3</option>
                        <option value="4" <?php echo $account_level == '4' ? 'selected' : ''; ?>>Level 4</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Show Zero Balances</label>
                    <select name="show_zero_balances" class="form-select">
                        <option value="0" <?php echo !$show_zero_balances ? 'selected' : ''; ?>>No</option>
                        <option value="1" <?php echo $show_zero_balances ? 'selected' : ''; ?>>Yes</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                </div>
            </form>
        </div>

        <!-- Trial Balance Table -->
        <div class="trial-balance-table">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0" id="trialBalanceTable">
                    <thead class="table-dark">
                        <tr>
                            <th width="10%" class="text-center">Code</th>
                            <th width="30%">Account Name</th>
                            <th width="10%" class="text-end">Opening Debit</th>
                            <th width="10%" class="text-end">Opening Credit</th>
                            <th width="10%" class="text-end">Period Debit</th>
                            <th width="10%" class="text-end">Period Credit</th>
                            <th width="10%" class="text-end">Closing Debit</th>
                            <th width="10%" class="text-end">Closing Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trial_balance)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No accounts found for the selected period.</p>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($trial_balance as $account): ?>
                            <tr>
                                <td class="text-center">
                                    <span class="account-code"><?php echo $account['account_code']; ?></span>
                                </td>
                                <td class="hierarchy-indent-<?php echo $account['account_level']; ?>">
                                    <span class="level-indicator level-<?php echo $account['account_level']; ?>">
                                        <?php echo $account['account_level']; ?>
                                    </span>
                                    <strong><?php echo htmlspecialchars($account['account_name']); ?></strong>
                                    <?php if ($account['is_control_account']): ?>
                                    <span class="control-badge ms-1">CTRL</span>
                                    <?php endif; ?>
                                    <?php if ($account['parent_name']): ?>
                                    <br><small class="text-muted">↳ <?php echo htmlspecialchars($account['parent_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($account['opening_debit'] > 0): ?>
                                    <span class="amount-debit"><?php echo number_format($account['opening_debit'], 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($account['opening_credit'] > 0): ?>
                                    <span class="amount-credit"><?php echo number_format($account['opening_credit'], 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($account['period_debit'] > 0): ?>
                                    <span class="amount-debit"><?php echo number_format($account['period_debit'], 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($account['period_credit'] > 0): ?>
                                    <span class="amount-credit"><?php echo number_format($account['period_credit'], 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($account['closing_debit'] > 0): ?>
                                    <span class="amount-debit"><?php echo number_format($account['closing_debit'], 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($account['closing_credit'] > 0): ?>
                                    <span class="amount-credit"><?php echo number_format($account['closing_credit'], 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr style="font-size: 1.1rem;">
                            <td colspan="2" class="text-end">TOTAL:</td>
                            <td class="text-end">
                                <span class="amount-debit">TSH <?php echo number_format($totals['opening_debit'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="amount-credit">TSH <?php echo number_format($totals['opening_credit'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="amount-debit">TSH <?php echo number_format($totals['total_debit'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="amount-credit">TSH <?php echo number_format($totals['total_credit'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="amount-debit">TSH <?php echo number_format($totals['closing_debit'], 2); ?></span>
                            </td>
                            <td class="text-end">
                                <span class="amount-credit">TSH <?php echo number_format($totals['closing_credit'], 2); ?></span>
                            </td>
                        </tr>
                        <?php if (!$is_balanced): ?>
                        <tr class="table-danger">
                            <td colspan="6" class="text-end">DIFFERENCE:</td>
                            <td colspan="2" class="text-end">
                                TSH <?php echo number_format(abs($difference), 2); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        </div>

        <?php if (!$is_balanced): ?>
        <div class="alert alert-danger mt-3">
            <h5 class="alert-heading">
                <i class="fas fa-exclamation-triangle me-2"></i>Trial Balance Out of Balance
            </h5>
            <p class="mb-0">
                The trial balance shows a difference of <strong>TSH <?php echo number_format(abs($difference), 2); ?></strong>.
                This indicates there may be errors in journal entries or account postings that need to be reviewed.
            </p>
        </div>
        <?php endif; ?>

        <div class="alert alert-info mt-3">
            <h6 class="mb-2"><i class="fas fa-info-circle me-2"></i>Understanding the Trial Balance:</h6>
            <ul class="mb-0">
                <li><strong>Opening Balance:</strong> Account balance at the start of the period</li>
                <li><strong>Period Movements:</strong> Debits and credits during the selected period</li>
                <li><strong>Closing Balance:</strong> Account balance at the end of the period</li>
                <li><strong>Debit Accounts:</strong> Assets and Expenses (shown in red)</li>
                <li><strong>Credit Accounts:</strong> Liabilities, Equity, and Revenue (shown in green)</li>
                <li><strong>Balanced:</strong> Total Debits should equal Total Credits</li>
            </ul>
        </div>

    </div>
</section>

<script>
function exportToExcel() {
    const table = document.getElementById('trialBalanceTable');
    const ws = XLSX.utils.table_to_sheet(table);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Trial Balance');
    
    const filename = 'Trial_Balance_<?php echo date('Y-m-d'); ?>.xlsx';
    XLSX.writeFile(wb, filename);
}

// Load SheetJS library for Excel export
if (typeof XLSX === 'undefined') {
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    document.head.appendChild(script);
}
</script>

<?php require_once '../../includes/footer.php'; ?>