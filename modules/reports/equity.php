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

// ==================== GET EQUITY ACCOUNTS ====================
$stmt = $conn->prepare("
    SELECT 
        account_code,
        account_name,
        COALESCE(opening_balance, 0) as opening_balance,
        COALESCE(current_balance, 0) as current_balance
    FROM chart_of_accounts
    WHERE company_id = ?
        AND account_type = 'equity'
        AND is_control_account = 0
        AND is_active = 1
    ORDER BY account_code
");
$stmt->execute([$company_id]);
$equity_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get account IDs for specific equity accounts
$equity_map = [];
foreach ($equity_accounts as $acc) {
    $equity_map[$acc['account_code']] = $acc;
}

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
$net_income = ($income_data['total_revenue'] ?? 0) - ($income_data['total_expenses'] ?? 0);

// ==================== GET BEGINNING BALANCES ====================
// Share Capital
$beginning_share_capital = isset($equity_map['3110']) ? $equity_map['3110']['opening_balance'] : 0;
$ending_share_capital = isset($equity_map['3110']) ? $equity_map['3110']['current_balance'] : 0;

// Retained Earnings
$beginning_retained_earnings = isset($equity_map['3120']) ? $equity_map['3120']['opening_balance'] : 0;
$ending_retained_earnings = isset($equity_map['3120']) ? $equity_map['3120']['current_balance'] : 0;

// Current Year Earnings
$beginning_current_year = isset($equity_map['3130']) ? $equity_map['3130']['opening_balance'] : 0;
$ending_current_year = isset($equity_map['3130']) ? $equity_map['3130']['current_balance'] : 0;

// ==================== GET MOVEMENTS DURING PERIOD ====================

// Capital Contributions (with error handling)
$capital_contributions = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as capital_contributions
        FROM capital_contributions
        WHERE company_id = ?
            AND created_at BETWEEN ? AND ?
            AND status = 'approved'
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $capital_contributions = $stmt->fetchColumn() ?? 0;
} catch (PDOException $e) {
    // Table doesn't exist, use 0
    $capital_contributions = 0;
}

// Owner Drawings (with error handling)
$owner_drawings = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as owner_drawings
        FROM drawings
        WHERE company_id = ?
            AND created_at BETWEEN ? AND ?
            AND status = 'approved'
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $owner_drawings = $stmt->fetchColumn() ?? 0;
} catch (PDOException $e) {
    // Table doesn't exist, use 0
    $owner_drawings = 0;
}

// Dividends Paid (with error handling)
$dividends_paid = 0;
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as dividends_paid
        FROM dividends
        WHERE company_id = ?
            AND created_at BETWEEN ? AND ?
            AND status = 'paid'
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $dividends_paid = $stmt->fetchColumn() ?? 0;
} catch (PDOException $e) {
    // Table doesn't exist, use 0
    $dividends_paid = 0;
}

// ==================== CALCULATE TOTALS ====================
// Beginning Total Equity
$beginning_total_equity = $beginning_share_capital + $beginning_retained_earnings + $beginning_current_year;

// Changes during period
$total_capital_contributions = $capital_contributions;
$total_drawings = $owner_drawings + $dividends_paid;

// Ending Total Equity
$ending_total_equity = $ending_share_capital + $ending_retained_earnings + $ending_current_year + $net_income;

// Verify calculation
$calculated_ending = $beginning_total_equity + $total_capital_contributions - $total_drawings + $net_income;

$page_title = 'Statement of Changes in Equity';
require_once '../../includes/header.php';
?>

<style>
/* Professional Report Styling */
.report-header {
    background: linear-gradient(135deg, #8e44ad 0%, #9b59b6 100%);
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

.equity-table {
    width: 100%;
    font-size: 0.9rem;
}

.equity-table thead {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.equity-table thead th {
    padding: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    color: #495057;
}

.equity-table tbody td {
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid #f0f0f0;
}

.item-row {
    transition: background-color 0.2s;
}

.item-row:hover {
    background-color: #f8f9fa;
}

.description-column {
    font-weight: 500;
    color: #2c3e50;
}

.amount-column {
    text-align: right;
    font-family: 'Courier New', monospace;
    font-weight: 500;
}

.section-header {
    background: #e9ecef;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #495057;
}

.subtotal-row {
    background: #f8f9fa;
    font-weight: 600;
    border-top: 1px solid #dee2e6;
}

.total-row {
    background: #e3f2fd;
    font-weight: 700;
    border-top: 2px solid #2196f3;
    border-bottom: 2px solid #2196f3;
}

.grand-total {
    background: linear-gradient(135deg, #8e44ad 0%, #9b59b6 100%);
    color: white;
    font-weight: 700;
    font-size: 1.1rem;
    border-top: 4px solid #7d3c98;
}

.metric-card {
    background: white;
    border-radius: 6px;
    padding: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 4px solid;
    height: 100%;
}

.metric-card.beginning { border-left-color: #6c757d; }
.metric-card.contributions { border-left-color: #28a745; }
.metric-card.drawings { border-left-color: #dc3545; }
.metric-card.ending { border-left-color: #8e44ad; }

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
    
    .equity-table {
        font-size: 10pt;
    }
}
</style>

<div class="content-header no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">
                    <i class="fas fa-chart-pie me-2"></i>Statement of Changes in Equity
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
                <a href="income.php" class="btn btn-warning btn-sm">
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
            <div class="metric-card beginning">
                <div class="metric-label">Beginning Equity</div>
                <div class="metric-value">
                    <?= number_format($beginning_total_equity, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card contributions">
                <div class="metric-label">Net Income</div>
                <div class="metric-value <?= $net_income >= 0 ? 'positive-value' : 'negative-value' ?>">
                    <?= number_format($net_income, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card drawings">
                <div class="metric-label">Contributions & Drawings</div>
                <div class="metric-value">
                    <?= number_format($total_capital_contributions - $total_drawings, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card ending">
                <div class="metric-label">Ending Equity</div>
                <div class="metric-value">
                    <?= number_format($ending_total_equity, 0) ?> TSH
                </div>
            </div>
        </div>
    </div>

    <!-- Report Card -->
    <div class="report-card">
        
        <!-- Report Header -->
        <div class="report-header text-center">
            <h2 class="mb-2"><?= htmlspecialchars($company['company_name']) ?></h2>
            <h4 class="mb-1">STATEMENT OF CHANGES IN EQUITY</h4>
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

        <!-- Equity Table -->
        <div class="table-responsive p-3">
            <table class="equity-table">
                <thead>
                    <tr>
                        <th style="width: 40%">Description</th>
                        <th style="width: 15%" class="text-end">Share Capital</th>
                        <th style="width: 15%" class="text-end">Retained Earnings</th>
                        <th style="width: 15%" class="text-end">Current Year</th>
                        <th style="width: 15%" class="text-end">Total Equity</th>
                    </tr>
                </thead>
                <tbody>
                    
                    <!-- BALANCE AT BEGINNING -->
                    <tr class="total-row">
                        <td>
                            <strong>Balance at <?= date('F j, Y', strtotime($start_date)) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($beginning_share_capital, 0) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($beginning_retained_earnings, 0) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($beginning_current_year, 0) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($beginning_total_equity, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="5" style="height: 1rem;"></td></tr>
                    
                    <!-- CHANGES DURING PERIOD -->
                    <tr class="section-header">
                        <td colspan="5">
                            <i class="fas fa-exchange-alt me-2"></i>CHANGES DURING THE PERIOD
                        </td>
                    </tr>
                    
                    <!-- Net Income for Period -->
                    <tr class="item-row">
                        <td class="description-column" style="padding-left: 2rem;">Net income for the period</td>
                        <td class="amount-column">-</td>
                        <td class="amount-column">-</td>
                        <td class="amount-column"><?= number_format($net_income, 0) ?></td>
                        <td class="amount-column"><?= number_format($net_income, 0) ?></td>
                    </tr>
                    
                    <!-- Capital Contributions -->
                    <?php if ($capital_contributions > 0): ?>
                    <tr class="item-row">
                        <td class="description-column" style="padding-left: 2rem;">Owner capital contributions</td>
                        <td class="amount-column"><?= number_format($capital_contributions, 0) ?></td>
                        <td class="amount-column">-</td>
                        <td class="amount-column">-</td>
                        <td class="amount-column"><?= number_format($capital_contributions, 0) ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- Owner Drawings -->
                    <?php if ($owner_drawings > 0): ?>
                    <tr class="item-row">
                        <td class="description-column" style="padding-left: 2rem;">Owner drawings</td>
                        <td class="amount-column">-</td>
                        <td class="amount-column">-</td>
                        <td class="amount-column">(<?= number_format($owner_drawings, 0) ?>)</td>
                        <td class="amount-column">(<?= number_format($owner_drawings, 0) ?>)</td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- Dividends Paid -->
                    <?php if ($dividends_paid > 0): ?>
                    <tr class="item-row">
                        <td class="description-column" style="padding-left: 2rem;">Dividends paid</td>
                        <td class="amount-column">-</td>
                        <td class="amount-column">(<?= number_format($dividends_paid, 0) ?>)</td>
                        <td class="amount-column">-</td>
                        <td class="amount-column">(<?= number_format($dividends_paid, 0) ?>)</td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- Transfer from Current Year to Retained Earnings (if applicable) -->
                    <?php if ($beginning_current_year != 0): ?>
                    <tr class="item-row">
                        <td class="description-column" style="padding-left: 2rem;">Prior year earnings transferred</td>
                        <td class="amount-column">-</td>
                        <td class="amount-column"><?= number_format($beginning_current_year, 0) ?></td>
                        <td class="amount-column">(<?= number_format($beginning_current_year, 0) ?>)</td>
                        <td class="amount-column">-</td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if ($capital_contributions == 0 && $owner_drawings == 0 && $dividends_paid == 0 && $beginning_current_year == 0): ?>
                    <tr class="item-row">
                        <td colspan="5" class="text-center text-muted" style="padding: 1rem;">
                            No additional equity transactions for this period
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr><td colspan="5" style="height: 1rem;"></td></tr>
                    
                    <!-- TOTAL CHANGES -->
                    <tr class="subtotal-row">
                        <td style="padding-left: 1rem;">
                            <strong>Total changes during the period</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($capital_contributions, 0) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($beginning_current_year - $dividends_paid, 0) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($net_income - $owner_drawings - $beginning_current_year, 0) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($capital_contributions + $net_income - $total_drawings, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="5" style="height: 1.5rem;"></td></tr>
                    
                    <!-- BALANCE AT END -->
                    <tr class="grand-total">
                        <td>
                            <strong>Balance at <?= date('F j, Y', strtotime($end_date)) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($ending_share_capital + $capital_contributions, 0) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($ending_retained_earnings + $beginning_current_year - $dividends_paid, 0) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($net_income - $owner_drawings - $beginning_current_year, 0) ?></strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($ending_total_equity, 0) ?></strong>
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
                        <li>This statement shows movement in equity accounts during the period</li>
                        <li>Net income is calculated from revenue and expense accounts</li>
                        <li>Beginning balance is at start of period: <?= date('F j, Y', strtotime($start_date)) ?></li>
                        <li>Ending balance is at end of period: <?= date('F j, Y', strtotime($end_date)) ?></li>
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
    let table = document.querySelector('.equity-table');
    let html = table.outerHTML;
    let blob = new Blob([html], {
        type: 'application/vnd.ms-excel'
    });
    let link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'statement_of_equity_<?= date('Ymd') ?>.xls';
    link.click();
}
</script>

<?php require_once '../../includes/footer.php'; ?>