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

// ==================== DATE HANDLING ====================
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');

// ==================== GET COMPANY INFO ====================
$stmt = $conn->prepare("SELECT company_name FROM companies WHERE company_id = ?");
$stmt->execute([$company_id]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

// ==================== FETCH ACCOUNT BALANCES (LEAF ONLY) ====================
function getAccountBalances($conn, $company_id, $account_type) {
    $stmt = $conn->prepare("
        SELECT 
            account_id,
            account_code,
            account_name,
            account_category,
            parent_account_id,
            account_level,
            is_control_account,
            COALESCE(opening_balance, 0) as opening_balance,
            COALESCE(current_balance, 0) as current_balance
        FROM chart_of_accounts
        WHERE company_id = ? 
            AND account_type = ?
            AND is_active = 1
            AND is_control_account = 0
        ORDER BY account_code
    ");
    
    $stmt->execute([$company_id, $account_type]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ==================== GET ALL BALANCE SHEET ACCOUNTS ====================
$asset_accounts = getAccountBalances($conn, $company_id, 'asset');
$liability_accounts = getAccountBalances($conn, $company_id, 'liability');
$equity_accounts = getAccountBalances($conn, $company_id, 'equity');

// ==================== CALCULATE NET INCOME ====================
$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN account_type = 'revenue' AND is_control_account = 0 THEN current_balance ELSE 0 END), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN account_type = 'expense' AND is_control_account = 0 THEN current_balance ELSE 0 END), 0) as total_expenses
    FROM chart_of_accounts
    WHERE company_id = ?
        AND account_type IN ('revenue', 'expense')
        AND is_active = 1
");
$stmt->execute([$company_id]);
$income_data = $stmt->fetch(PDO::FETCH_ASSOC);

$total_revenue = $income_data['total_revenue'] ?? 0;
$total_expenses = $income_data['total_expenses'] ?? 0;
$net_income = $total_revenue - $total_expenses;

// ==================== ORGANIZE BY CATEGORY ====================
function organizeByCategory($accounts) {
    $categories = [];
    foreach ($accounts as $account) {
        // Skip accounts with zero balance
        if ($account['current_balance'] == 0) {
            continue;
        }
        
        $category = $account['account_category'] ?: 'Other';
        if (!isset($categories[$category])) {
            $categories[$category] = [
                'accounts' => [],
                'total' => 0
            ];
        }
        $categories[$category]['accounts'][] = $account;
        $categories[$category]['total'] += $account['current_balance'];
    }
    return $categories;
}

$asset_categories = organizeByCategory($asset_accounts);
$liability_categories = organizeByCategory($liability_accounts);
$equity_categories = organizeByCategory($equity_accounts);

// ==================== CALCULATE TOTALS ====================
$total_assets = array_sum(array_column($asset_accounts, 'current_balance'));
$total_liabilities = array_sum(array_column($liability_accounts, 'current_balance'));
$total_equity = array_sum(array_column($equity_accounts, 'current_balance')) + $net_income;
$total_liabilities_equity = $total_liabilities + $total_equity;

// Check if balanced
$is_balanced = abs($total_assets - $total_liabilities_equity) < 0.01;

$page_title = 'Balance Sheet';
require_once '../../includes/header.php';
?>

<style>
/* Professional Report Styling */
.report-header {
    background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%);
    color: white;
    padding: 2rem;
    border-radius: 8px 8px 0 0;
    margin-bottom: 0;
}

.report-card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    overflow: hidden;
}

.filter-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-bottom: 2px solid #e9ecef;
}

.balance-sheet-table {
    width: 100%;
    font-size: 0.9rem;
}

.balance-sheet-table thead {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.balance-sheet-table thead th {
    padding: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    color: #495057;
}

.balance-sheet-table tbody td {
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid #f0f0f0;
}

.account-row {
    transition: background-color 0.2s;
}

.account-row:hover {
    background-color: #f8f9fa;
}

.account-code {
    font-family: 'Courier New', monospace;
    color: #6c757d;
    font-size: 0.85rem;
}

.account-name {
    font-weight: 500;
    color: #2c3e50;
}

.amount-column {
    text-align: right;
    font-family: 'Courier New', monospace;
    font-weight: 500;
}

.section-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
}

.category-header {
    background: #e9ecef;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #495057;
}

.category-total {
    background: #f8f9fa;
    font-weight: 700;
    border-top: 2px solid #dee2e6;
    border-bottom: 2px solid #dee2e6;
}

.section-total {
    background: #e3f2fd;
    font-weight: 700;
    font-size: 1rem;
    border-top: 3px solid #2196f3;
    border-bottom: 3px solid #2196f3;
}

.grand-total {
    background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%);
    color: white;
    font-weight: 700;
    font-size: 1.1rem;
    border-top: 4px solid #1976d2;
}

.net-income-row {
    background: #fff3cd;
    font-weight: 600;
    border-top: 2px solid #ffc107;
    border-bottom: 2px solid #ffc107;
}

.balance-check {
    padding: 1rem;
    border-radius: 6px;
    font-weight: 600;
    text-align: center;
    margin: 1rem 0;
}

.balance-check.balanced {
    background: #d4edda;
    color: #155724;
    border: 2px solid #28a745;
}

.balance-check.unbalanced {
    background: #f8d7da;
    color: #721c24;
    border: 2px solid #dc3545;
}

.metric-card {
    background: white;
    border-radius: 6px;
    padding: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 4px solid;
    height: 100%;
}

.metric-card.assets { border-left-color: #28a745; }
.metric-card.liabilities { border-left-color: #dc3545; }
.metric-card.equity { border-left-color: #007bff; }
.metric-card.ratio { border-left-color: #ffc107; }

.metric-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    margin-bottom: 0.5rem;
    font-weight: 600;
}

.metric-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
    line-height: 1;
}

.indent-1 { padding-left: 1.5rem !important; }
.indent-2 { padding-left: 3rem !important; }
.indent-3 { padding-left: 4.5rem !important; }

@media print {
    .no-print {
        display: none !important;
    }
    
    .report-card {
        box-shadow: none;
    }
    
    .balance-sheet-table {
        font-size: 10pt;
    }
}
</style>

<div class="content-header no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">
                    <i class="fas fa-balance-scale me-2"></i>Balance Sheet
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <button onclick="window.print()" class="btn btn-primary btn-sm">
                    <i class="fas fa-print me-1"></i>Print Report
                </button>
                <button onclick="exportToExcel()" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i>Export Excel
                </button>
                <a href="income.php" class="btn btn-info btn-sm">
                    <i class="fas fa-chart-line me-1"></i>Income Statement
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    
    <!-- Key Metrics -->
    <div class="row g-3 mb-3 no-print">
        <div class="col-lg-3 col-md-6">
            <div class="metric-card assets">
                <div class="metric-label">Total Assets</div>
                <div class="metric-value">
                    <?= number_format($total_assets, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card liabilities">
                <div class="metric-label">Total Liabilities</div>
                <div class="metric-value">
                    <?= number_format($total_liabilities, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card equity">
                <div class="metric-label">Total Equity</div>
                <div class="metric-value">
                    <?= number_format($total_equity, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card ratio">
                <div class="metric-label">Current Ratio</div>
                <div class="metric-value">
                    <?= $total_liabilities > 0 ? number_format($total_assets / $total_liabilities, 2) : 'N/A' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Balance Check Alert -->
    <div class="balance-check <?= $is_balanced ? 'balanced' : 'unbalanced' ?> no-print">
        <?php if ($is_balanced): ?>
            <i class="fas fa-check-circle me-2"></i>
            ✓ Balance Sheet is Balanced: Assets (<?= number_format($total_assets, 0) ?>) = Liabilities + Equity (<?= number_format($total_liabilities_equity, 0) ?>)
        <?php else: ?>
            <i class="fas fa-exclamation-triangle me-2"></i>
            ✗ Balance Sheet NOT Balanced | Assets: <?= number_format($total_assets, 0) ?> | L+E: <?= number_format($total_liabilities_equity, 0) ?> | Difference: <?= number_format(abs($total_assets - $total_liabilities_equity), 0) ?> TSH
        <?php endif; ?>
    </div>

    <!-- Report Card -->
    <div class="report-card">
        
        <!-- Report Header -->
        <div class="report-header text-center">
            <h2 class="mb-2"><?= htmlspecialchars($company['company_name']) ?></h2>
            <h4 class="mb-1">BALANCE SHEET</h4>
            <p class="mb-0">
                As of <?= date('F j, Y', strtotime($as_of_date)) ?>
            </p>
        </div>

        <!-- Filter Section -->
        <div class="filter-section no-print">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-10">
                    <label class="form-label fw-semibold">As of Date</label>
                    <input type="date" name="as_of_date" class="form-control" 
                           value="<?= $as_of_date ?>" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-sync-alt me-1"></i>Generate
                    </button>
                </div>
            </form>
        </div>

        <!-- Balance Sheet Table -->
        <div class="table-responsive p-3">
            <table class="balance-sheet-table">
                <thead>
                    <tr>
                        <th style="width: 15%">Account Code</th>
                        <th style="width: 55%">Account Name</th>
                        <th style="width: 30%" class="text-end">Amount (TSH)</th>
                    </tr>
                </thead>
                <tbody>
                    
                    <!-- ==================== ASSETS ==================== -->
                    <tr class="section-header">
                        <td colspan="3">
                            <i class="fas fa-coins me-2"></i>ASSETS
                        </td>
                    </tr>
                    
                    <?php if (empty($asset_categories)): ?>
                        <tr class="account-row">
                            <td colspan="3" class="text-center text-muted" style="padding: 2rem;">
                                No asset accounts with balances
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($asset_categories as $category_name => $category_data): ?>
                            <tr class="category-header">
                                <td></td>
                                <td class="indent-1">
                                    <?= htmlspecialchars($category_name) ?>
                                </td>
                                <td></td>
                            </tr>
                            
                            <?php foreach ($category_data['accounts'] as $account): ?>
                                <tr class="account-row">
                                    <td class="account-code"><?= htmlspecialchars($account['account_code']) ?></td>
                                    <td class="account-name indent-2">
                                        <?= htmlspecialchars($account['account_name']) ?>
                                    </td>
                                    <td class="amount-column"><?= number_format($account['current_balance'], 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr class="category-total">
                                <td></td>
                                <td class="indent-1">Total <?= htmlspecialchars($category_name) ?></td>
                                <td class="amount-column"><?= number_format($category_data['total'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <tr class="section-total">
                        <td colspan="2" style="text-align: right; padding-right: 1rem;">
                            <strong>TOTAL ASSETS</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($total_assets, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="3" style="height: 2rem;"></td></tr>
                    
                    <!-- ==================== LIABILITIES ==================== -->
                    <tr class="section-header">
                        <td colspan="3">
                            <i class="fas fa-file-invoice-dollar me-2"></i>LIABILITIES
                        </td>
                    </tr>
                    
                    <?php if (empty($liability_categories)): ?>
                        <tr class="account-row">
                            <td colspan="3" class="text-center text-muted" style="padding: 2rem;">
                                No liability accounts with balances
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($liability_categories as $category_name => $category_data): ?>
                            <tr class="category-header">
                                <td></td>
                                <td class="indent-1">
                                    <?= htmlspecialchars($category_name) ?>
                                </td>
                                <td></td>
                            </tr>
                            
                            <?php foreach ($category_data['accounts'] as $account): ?>
                                <tr class="account-row">
                                    <td class="account-code"><?= htmlspecialchars($account['account_code']) ?></td>
                                    <td class="account-name indent-2">
                                        <?= htmlspecialchars($account['account_name']) ?>
                                    </td>
                                    <td class="amount-column"><?= number_format($account['current_balance'], 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr class="category-total">
                                <td></td>
                                <td class="indent-1">Total <?= htmlspecialchars($category_name) ?></td>
                                <td class="amount-column"><?= number_format($category_data['total'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <tr class="section-total">
                        <td colspan="2" style="text-align: right; padding-right: 1rem;">
                            <strong>TOTAL LIABILITIES</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($total_liabilities, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="3" style="height: 2rem;"></td></tr>
                    
                    <!-- ==================== EQUITY ==================== -->
                    <tr class="section-header">
                        <td colspan="3">
                            <i class="fas fa-chart-pie me-2"></i>EQUITY
                        </td>
                    </tr>
                    
                    <?php if (empty($equity_categories)): ?>
                        <tr class="account-row">
                            <td colspan="3" class="text-center text-muted" style="padding: 2rem;">
                                No equity accounts with balances
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($equity_categories as $category_name => $category_data): ?>
                            <tr class="category-header">
                                <td></td>
                                <td class="indent-1">
                                    <?= htmlspecialchars($category_name) ?>
                                </td>
                                <td></td>
                            </tr>
                            
                            <?php foreach ($category_data['accounts'] as $account): ?>
                                <tr class="account-row">
                                    <td class="account-code"><?= htmlspecialchars($account['account_code']) ?></td>
                                    <td class="account-name indent-2">
                                        <?= htmlspecialchars($account['account_name']) ?>
                                    </td>
                                    <td class="amount-column"><?= number_format($account['current_balance'], 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr class="category-total">
                                <td></td>
                                <td class="indent-1">Total <?= htmlspecialchars($category_name) ?></td>
                                <td class="amount-column"><?= number_format($category_data['total'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <!-- Net Income -->
                    <tr class="net-income-row">
                        <td></td>
                        <td class="indent-1">
                            <i class="fas fa-plus-circle me-2"></i>Net Income for Period
                        </td>
                        <td class="amount-column"><?= number_format($net_income, 0) ?></td>
                    </tr>
                    
                    <tr class="section-total">
                        <td colspan="2" style="text-align: right; padding-right: 1rem;">
                            <strong>TOTAL EQUITY</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($total_equity, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="3" style="height: 1rem;"></td></tr>
                    
                    <!-- TOTAL LIABILITIES & EQUITY -->
                    <tr class="grand-total">
                        <td colspan="2" style="text-align: right; padding-right: 1rem;">
                            <strong>TOTAL LIABILITIES & EQUITY</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($total_liabilities_equity, 0) ?></strong>
                        </td>
                    </tr>
                    
                </tbody>
            </table>
        </div>

        <!-- Report Footer -->
        <div class="p-3 border-top">
            <div class="row">
                <div class="col-md-8 text-muted small">
                    <p class="mb-1">
                        <strong>Notes:</strong>
                    </p>
                    <ul class="mb-0" style="font-size: 0.85rem;">
                        <li>All amounts are in Tanzanian Shillings (TSH)</li>
                        <li>Net Income for the period is included in Total Equity</li>
                        <li>Balances are as of <?= date('F j, Y', strtotime($as_of_date)) ?></li>
                        <li>Only leaf accounts (non-control accounts) are summed</li>
                    </ul>
                </div>
                <div class="col-md-4 text-end text-muted small">
                    <p class="mb-1">Generated: <?= date('F j, Y g:i A') ?></p>
                    <p class="mb-0">By: <?= htmlspecialchars($_SESSION['full_name']) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportToExcel() {
    let table = document.querySelector('.balance-sheet-table');
    let html = table.outerHTML;
    let blob = new Blob([html], {
        type: 'application/vnd.ms-excel'
    });
    let link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'balance_sheet_<?= date('Ymd') ?>.xls';
    link.click();
}
</script>

<?php require_once '../../includes/footer.php'; ?>