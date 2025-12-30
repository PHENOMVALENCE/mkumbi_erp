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

// ==================== DATE RANGE HANDLING ====================
$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// ==================== GET COMPANY INFO ====================
$stmt = $conn->prepare("SELECT company_name FROM companies WHERE company_id = ?");
$stmt->execute([$company_id]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

// ==================== FETCH REVENUE/EXPENSE ACCOUNTS (LEAF ONLY) ====================
function getAccountBalance($conn, $company_id, $account_type) {
    $stmt = $conn->prepare("
        SELECT 
            account_id,
            account_code,
            account_name,
            account_category,
            parent_account_id,
            account_level,
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

// ==================== GET FINANCIAL DATA ====================
$revenue_accounts = getAccountBalance($conn, $company_id, 'revenue');
$expense_accounts = getAccountBalance($conn, $company_id, 'expense');

// Calculate totals
$total_revenue = array_sum(array_column($revenue_accounts, 'current_balance'));
$total_expenses = array_sum(array_column($expense_accounts, 'current_balance'));
$gross_profit = $total_revenue - $total_expenses;
$net_income = $gross_profit;

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

$revenue_categories = organizeByCategory($revenue_accounts);
$expense_categories = organizeByCategory($expense_accounts);

$page_title = 'Income Statement';
require_once '../../includes/header.php';
?>

<style>
/* Professional Report Styling */
.report-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

.statement-table {
    width: 100%;
    font-size: 0.9rem;
}

.statement-table thead {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.statement-table thead th {
    padding: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    color: #495057;
}

.statement-table tbody td {
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
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 700;
    font-size: 1.1rem;
    border-top: 4px solid #5a67d8;
}

.metric-card {
    background: white;
    border-radius: 6px;
    padding: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 4px solid;
    height: 100%;
}

.metric-card.revenue { border-left-color: #28a745; }
.metric-card.expense { border-left-color: #dc3545; }
.metric-card.profit { border-left-color: #007bff; }
.metric-card.margin { border-left-color: #ffc107; }

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

.positive-value { color: #28a745; }
.negative-value { color: #dc3545; }

@media print {
    .no-print {
        display: none !important;
    }
    
    .report-card {
        box-shadow: none;
    }
    
    .statement-table {
        font-size: 10pt;
    }
}
</style>

<div class="content-header no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">
                    <i class="fas fa-chart-line me-2"></i>Income Statement
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <button onclick="window.print()" class="btn btn-primary btn-sm">
                    <i class="fas fa-print me-1"></i>Print Report
                </button>
                <button onclick="exportToExcel()" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i>Export Excel
                </button>
                <a href="balance.php" class="btn btn-info btn-sm">
                    <i class="fas fa-balance-scale me-1"></i>Balance Sheet
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    
    <!-- Key Metrics -->
    <div class="row g-3 mb-3 no-print">
        <div class="col-lg-3 col-md-6">
            <div class="metric-card revenue">
                <div class="metric-label">Total Revenue</div>
                <div class="metric-value">
                    <?= number_format($total_revenue, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card expense">
                <div class="metric-label">Total Expenses</div>
                <div class="metric-value">
                    <?= number_format($total_expenses, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card profit">
                <div class="metric-label">Net Income</div>
                <div class="metric-value <?= $net_income >= 0 ? 'positive-value' : 'negative-value' ?>">
                    <?= number_format($net_income, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card margin">
                <div class="metric-label">Profit Margin</div>
                <div class="metric-value">
                    <?= $total_revenue > 0 ? number_format(($net_income / $total_revenue) * 100, 1) : 0 ?>%
                </div>
            </div>
        </div>
    </div>

    <!-- Report Card -->
    <div class="report-card">
        
        <!-- Report Header -->
        <div class="report-header text-center">
            <h2 class="mb-2"><?= htmlspecialchars($company['company_name']) ?></h2>
            <h4 class="mb-1">INCOME STATEMENT</h4>
            <p class="mb-0">
                For the Period: <?= date('F j, Y', strtotime($start_date)) ?> to <?= date('F j, Y', strtotime($end_date)) ?>
            </p>
        </div>

        <!-- Filter Section -->
        <div class="filter-section no-print">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" name="start_date" class="form-control" 
                           value="<?= $start_date ?>" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" name="end_date" class="form-control" 
                           value="<?= $end_date ?>" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-sync-alt me-1"></i>Generate
                    </button>
                </div>
            </form>
        </div>

        <!-- Statement Table -->
        <div class="table-responsive p-3">
            <table class="statement-table">
                <thead>
                    <tr>
                        <th style="width: 15%">Account Code</th>
                        <th style="width: 55%">Account Name</th>
                        <th style="width: 15%" class="text-end">Amount (TSH)</th>
                        <th style="width: 15%" class="text-end">Subtotal (TSH)</th>
                    </tr>
                </thead>
                <tbody>
                    
                    <!-- REVENUE SECTION -->
                    <tr class="category-header">
                        <td colspan="4">
                            <i class="fas fa-dollar-sign me-2"></i>REVENUE
                        </td>
                    </tr>
                    
                    <?php if (empty($revenue_categories)): ?>
                        <tr class="account-row">
                            <td colspan="4" class="text-center text-muted" style="padding: 2rem;">
                                No revenue recorded for this period
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($revenue_categories as $category_name => $category_data): ?>
                            <tr class="account-row">
                                <td class="account-code"></td>
                                <td class="account-name" style="padding-left: 1.5rem;">
                                    <strong><?= htmlspecialchars($category_name) ?></strong>
                                </td>
                                <td></td>
                                <td></td>
                            </tr>
                            
                            <?php foreach ($category_data['accounts'] as $account): ?>
                                <tr class="account-row">
                                    <td class="account-code"><?= htmlspecialchars($account['account_code']) ?></td>
                                    <td class="account-name" style="padding-left: 3rem;">
                                        <?= htmlspecialchars($account['account_name']) ?>
                                    </td>
                                    <td class="amount-column"><?= number_format($account['current_balance'], 0) ?></td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr class="category-total">
                                <td></td>
                                <td style="padding-left: 1.5rem;">Total <?= htmlspecialchars($category_name) ?></td>
                                <td></td>
                                <td class="amount-column"><?= number_format($category_data['total'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <tr class="section-total">
                        <td colspan="3" style="text-align: right; padding-right: 1rem;">
                            <strong>TOTAL REVENUE</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($total_revenue, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="4" style="height: 1.5rem;"></td></tr>
                    
                    <!-- EXPENSES SECTION -->
                    <tr class="category-header">
                        <td colspan="4">
                            <i class="fas fa-money-bill-wave me-2"></i>EXPENSES
                        </td>
                    </tr>
                    
                    <?php if (empty($expense_categories)): ?>
                        <tr class="account-row">
                            <td colspan="4" class="text-center text-muted" style="padding: 2rem;">
                                No expenses recorded for this period
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expense_categories as $category_name => $category_data): ?>
                            <tr class="account-row">
                                <td class="account-code"></td>
                                <td class="account-name" style="padding-left: 1.5rem;">
                                    <strong><?= htmlspecialchars($category_name) ?></strong>
                                </td>
                                <td></td>
                                <td></td>
                            </tr>
                            
                            <?php foreach ($category_data['accounts'] as $account): ?>
                                <tr class="account-row">
                                    <td class="account-code"><?= htmlspecialchars($account['account_code']) ?></td>
                                    <td class="account-name" style="padding-left: 3rem;">
                                        <?= htmlspecialchars($account['account_name']) ?>
                                    </td>
                                    <td class="amount-column"><?= number_format($account['current_balance'], 0) ?></td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr class="category-total">
                                <td></td>
                                <td style="padding-left: 1.5rem;">Total <?= htmlspecialchars($category_name) ?></td>
                                <td></td>
                                <td class="amount-column"><?= number_format($category_data['total'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <tr class="section-total">
                        <td colspan="3" style="text-align: right; padding-right: 1rem;">
                            <strong>TOTAL EXPENSES</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($total_expenses, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="4" style="height: 1.5rem;"></td></tr>
                    
                    <!-- NET INCOME -->
                    <tr class="grand-total">
                        <td colspan="3" style="text-align: right; padding-right: 1rem;">
                            <strong>NET INCOME (LOSS)</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($net_income, 0) ?></strong>
                        </td>
                    </tr>
                    
                </tbody>
            </table>
        </div>

        <!-- Report Footer -->
        <div class="p-3 border-top text-muted small">
            <div class="row">
                <div class="col-6">
                    Generated: <?= date('F j, Y g:i A') ?>
                </div>
                <div class="col-6 text-end">
                    <?= htmlspecialchars($_SESSION['full_name']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportToExcel() {
    let table = document.querySelector('.statement-table');
    let html = table.outerHTML;
    let blob = new Blob([html], {
        type: 'application/vnd.ms-excel'
    });
    let link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'income_statement_<?= date('Ymd') ?>.xls';
    link.click();
}
</script>

<?php require_once '../../includes/footer.php'; ?>