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

// ==================== OPERATING ACTIVITIES ====================
$operating_items = [];

// Cash received from customers (Plot Sales + Service Revenue)
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as cash_from_customers
        FROM payments 
        WHERE company_id = ? 
            AND status = 'approved'
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $cash_from_customers = $stmt->fetchColumn() ?? 0;
    if ($cash_from_customers > 0) {
        $operating_items[] = ['name' => 'Cash received from customers', 'amount' => $cash_from_customers];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Cash paid to suppliers (Creditors)
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount_paid), 0) as cash_to_suppliers
        FROM creditors 
        WHERE company_id = ? 
            AND updated_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $cash_to_suppliers = $stmt->fetchColumn() ?? 0;
    if ($cash_to_suppliers > 0) {
        $operating_items[] = ['name' => 'Cash paid to suppliers', 'amount' => -$cash_to_suppliers];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Cash paid to employees (Payroll)
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(pd.net_salary), 0) as cash_to_employees
        FROM payroll_details pd
        JOIN payroll p ON pd.payroll_id = p.payroll_id
        WHERE p.company_id = ? 
            AND pd.payment_status = 'paid'
            AND p.created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $cash_to_employees = $stmt->fetchColumn() ?? 0;
    if ($cash_to_employees > 0) {
        $operating_items[] = ['name' => 'Cash paid to employees', 'amount' => -$cash_to_employees];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Cash paid for commissions
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(commission_amount), 0) as cash_for_commissions
        FROM commissions 
        WHERE company_id = ? 
            AND payment_status = 'paid'
            AND updated_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $cash_for_commissions = $stmt->fetchColumn() ?? 0;
    if ($cash_for_commissions > 0) {
        $operating_items[] = ['name' => 'Cash paid for commissions', 'amount' => -$cash_for_commissions];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Cash paid for taxes
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(tax_amount), 0) as cash_for_taxes
        FROM tax_transactions 
        WHERE company_id = ? 
            AND status = 'paid'
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $cash_for_taxes = $stmt->fetchColumn() ?? 0;
    if ($cash_for_taxes > 0) {
        $operating_items[] = ['name' => 'Cash paid for taxes', 'amount' => -$cash_for_taxes];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Cash paid for operating expenses
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as cash_for_expenses
        FROM expenses 
        WHERE company_id = ? 
            AND status = 'approved'
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $cash_for_expenses = $stmt->fetchColumn() ?? 0;
    if ($cash_for_expenses > 0) {
        $operating_items[] = ['name' => 'Cash paid for operating expenses', 'amount' => -$cash_for_expenses];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

$net_cash_from_operating = array_sum(array_column($operating_items, 'amount'));

// ==================== INVESTING ACTIVITIES ====================
$investing_items = [];

// Cash paid for land purchases
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(land_purchase_price), 0) as land_purchases
        FROM projects 
        WHERE company_id = ? 
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $land_purchases = $stmt->fetchColumn() ?? 0;
    if ($land_purchases > 0) {
        $investing_items[] = ['name' => 'Purchase of land for development', 'amount' => -$land_purchases];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Cash paid for project development costs
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(cost_incurred), 0) as development_costs
        FROM project_costs 
        WHERE company_id = ? 
            AND status = 'approved'
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $development_costs = $stmt->fetchColumn() ?? 0;
    if ($development_costs > 0) {
        $investing_items[] = ['name' => 'Development and infrastructure costs', 'amount' => -$development_costs];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Cash paid for fixed assets
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(purchase_amount), 0) as fixed_assets
        FROM fixed_assets 
        WHERE company_id = ? 
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $fixed_assets = $stmt->fetchColumn() ?? 0;
    if ($fixed_assets > 0) {
        $investing_items[] = ['name' => 'Purchase of fixed assets', 'amount' => -$fixed_assets];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

$net_cash_from_investing = array_sum(array_column($investing_items, 'amount'));

// ==================== FINANCING ACTIVITIES ====================
$financing_items = [];

// Cash from customer deposits
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(down_payment), 0) as deposits_received
        FROM reservations 
        WHERE company_id = ? 
            AND status IN ('active', 'draft')
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $deposits_received = $stmt->fetchColumn() ?? 0;
    if ($deposits_received > 0) {
        $financing_items[] = ['name' => 'Customer deposits received', 'amount' => $deposits_received];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Cash from loans received
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(loan_amount), 0) as loans_received
        FROM loans 
        WHERE company_id = ? 
            AND status = 'active'
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $loans_received = $stmt->fetchColumn() ?? 0;
    if ($loans_received > 0) {
        $financing_items[] = ['name' => 'Proceeds from loans', 'amount' => $loans_received];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Cash paid for loan repayments
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(payment_amount), 0) as loan_payments
        FROM loan_payments 
        WHERE company_id = ? 
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $loan_payments = $stmt->fetchColumn() ?? 0;
    if ($loan_payments > 0) {
        $financing_items[] = ['name' => 'Loan repayments', 'amount' => -$loan_payments];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Owner contributions
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as owner_contributions
        FROM capital_contributions 
        WHERE company_id = ? 
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $owner_contributions = $stmt->fetchColumn() ?? 0;
    if ($owner_contributions > 0) {
        $financing_items[] = ['name' => 'Owner capital contributions', 'amount' => $owner_contributions];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

// Owner drawings
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as owner_drawings
        FROM drawings 
        WHERE company_id = ? 
            AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$company_id, $start_date, $end_date]);
    $owner_drawings = $stmt->fetchColumn() ?? 0;
    if ($owner_drawings > 0) {
        $financing_items[] = ['name' => 'Owner drawings', 'amount' => -$owner_drawings];
    }
} catch (PDOException $e) {
    // Table might not exist, skip
}

$net_cash_from_financing = array_sum(array_column($financing_items, 'amount'));

// ==================== CALCULATE TOTALS ====================
$net_increase_in_cash = $net_cash_from_operating + $net_cash_from_investing + $net_cash_from_financing;

// Get beginning cash balance
try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(current_balance), 0) as beginning_cash
        FROM bank_accounts 
        WHERE company_id = ? 
            AND is_active = 1
    ");
    $stmt->execute([$company_id]);
    $beginning_cash = $stmt->fetchColumn() ?? 0;
} catch (PDOException $e) {
    $beginning_cash = 0;
}

// Calculate ending cash
$ending_cash = $beginning_cash + $net_increase_in_cash;

$page_title = 'Cash Flow Statement';
require_once '../../includes/header.php';
?>

<style>
/* Professional Report Styling */
.report-header {
    background: linear-gradient(135deg, #00b4db 0%, #0083b0 100%);
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

.cashflow-table {
    width: 100%;
    font-size: 0.9rem;
}

.cashflow-table thead {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.cashflow-table thead th {
    padding: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    color: #495057;
}

.cashflow-table tbody td {
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid #f0f0f0;
}

.item-row {
    transition: background-color 0.2s;
}

.item-row:hover {
    background-color: #f8f9fa;
}

.item-name {
    font-weight: 500;
    color: #2c3e50;
    padding-left: 2rem;
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
    font-size: 0.85rem;
    letter-spacing: 0.5px;
    color: #495057;
}

.section-total {
    background: #f8f9fa;
    font-weight: 700;
    border-top: 2px solid #dee2e6;
    border-bottom: 2px solid #dee2e6;
}

.section-total.operating {
    background: #e3f2fd;
    border-top: 3px solid #2196f3;
    border-bottom: 3px solid #2196f3;
}

.section-total.investing {
    background: #fff3cd;
    border-top: 3px solid #ffc107;
    border-bottom: 3px solid #ffc107;
}

.section-total.financing {
    background: #d4edda;
    border-top: 3px solid #28a745;
    border-bottom: 3px solid #28a745;
}

.grand-total {
    background: linear-gradient(135deg, #00b4db 0%, #0083b0 100%);
    color: white;
    font-weight: 700;
    font-size: 1.1rem;
    border-top: 4px solid #006d91;
}

.cash-summary {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 1rem;
}

.metric-card {
    background: white;
    border-radius: 6px;
    padding: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 4px solid;
    height: 100%;
}

.metric-card.operating { border-left-color: #2196f3; }
.metric-card.investing { border-left-color: #ffc107; }
.metric-card.financing { border-left-color: #28a745; }
.metric-card.net { border-left-color: #00b4db; }

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
    
    .cashflow-table {
        font-size: 10pt;
    }
}
</style>

<div class="content-header no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">
                    <i class="fas fa-water me-2"></i>Cash Flow Statement
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
                <a href="balance.php" class="btn btn-warning btn-sm">
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
            <div class="metric-card operating">
                <div class="metric-label">Operating Activities</div>
                <div class="metric-value <?= $net_cash_from_operating >= 0 ? 'positive-value' : 'negative-value' ?>">
                    <?= number_format($net_cash_from_operating, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card investing">
                <div class="metric-label">Investing Activities</div>
                <div class="metric-value <?= $net_cash_from_investing >= 0 ? 'positive-value' : 'negative-value' ?>">
                    <?= number_format($net_cash_from_investing, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card financing">
                <div class="metric-label">Financing Activities</div>
                <div class="metric-value <?= $net_cash_from_financing >= 0 ? 'positive-value' : 'negative-value' ?>">
                    <?= number_format($net_cash_from_financing, 0) ?> TSH
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="metric-card net">
                <div class="metric-label">Net Change in Cash</div>
                <div class="metric-value <?= $net_increase_in_cash >= 0 ? 'positive-value' : 'negative-value' ?>">
                    <?= number_format($net_increase_in_cash, 0) ?> TSH
                </div>
            </div>
        </div>
    </div>

    <!-- Report Card -->
    <div class="report-card">
        
        <!-- Report Header -->
        <div class="report-header text-center">
            <h2 class="mb-2"><?= htmlspecialchars($company['company_name']) ?></h2>
            <h4 class="mb-1">CASH FLOW STATEMENT</h4>
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

        <!-- Cash Flow Table -->
        <div class="table-responsive p-3">
            <table class="cashflow-table">
                <thead>
                    <tr>
                        <th style="width: 70%">Description</th>
                        <th style="width: 30%" class="text-end">Amount (TSH)</th>
                    </tr>
                </thead>
                <tbody>
                    
                    <!-- OPERATING ACTIVITIES -->
                    <tr class="section-header">
                        <td colspan="2">
                            <i class="fas fa-cogs me-2"></i>CASH FLOWS FROM OPERATING ACTIVITIES
                        </td>
                    </tr>
                    
                    <tr class="item-row">
                        <td class="item-name">Net Income</td>
                        <td class="amount-column"><?= number_format($net_income, 0) ?></td>
                    </tr>
                    
                    <tr class="section-header" style="background: #f8f9fa;">
                        <td colspan="2" style="padding-left: 2rem; font-size: 0.8rem;">
                            Adjustments to reconcile net income to cash:
                        </td>
                    </tr>
                    
                    <?php if (empty($operating_items)): ?>
                        <tr class="item-row">
                            <td colspan="2" class="text-center text-muted" style="padding: 2rem;">
                                No operating cash flows for this period
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($operating_items as $item): ?>
                            <tr class="item-row">
                                <td class="item-name" style="padding-left: 3rem;"><?= htmlspecialchars($item['name']) ?></td>
                                <td class="amount-column <?= $item['amount'] >= 0 ? '' : 'text-danger' ?>">
                                    <?= number_format($item['amount'], 0) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <tr class="section-total operating">
                        <td style="text-align: right; padding-right: 1rem;">
                            <strong>NET CASH FROM OPERATING ACTIVITIES</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($net_cash_from_operating, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="2" style="height: 1rem;"></td></tr>
                    
                    <!-- INVESTING ACTIVITIES -->
                    <tr class="section-header">
                        <td colspan="2">
                            <i class="fas fa-chart-area me-2"></i>CASH FLOWS FROM INVESTING ACTIVITIES
                        </td>
                    </tr>
                    
                    <?php if (empty($investing_items)): ?>
                        <tr class="item-row">
                            <td colspan="2" class="text-center text-muted" style="padding: 2rem;">
                                No investing activities for this period
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($investing_items as $item): ?>
                            <tr class="item-row">
                                <td class="item-name"><?= htmlspecialchars($item['name']) ?></td>
                                <td class="amount-column <?= $item['amount'] >= 0 ? '' : 'text-danger' ?>">
                                    <?= number_format($item['amount'], 0) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <tr class="section-total investing">
                        <td style="text-align: right; padding-right: 1rem;">
                            <strong>NET CASH FROM INVESTING ACTIVITIES</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($net_cash_from_investing, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="2" style="height: 1rem;"></td></tr>
                    
                    <!-- FINANCING ACTIVITIES -->
                    <tr class="section-header">
                        <td colspan="2">
                            <i class="fas fa-hand-holding-usd me-2"></i>CASH FLOWS FROM FINANCING ACTIVITIES
                        </td>
                    </tr>
                    
                    <?php if (empty($financing_items)): ?>
                        <tr class="item-row">
                            <td colspan="2" class="text-center text-muted" style="padding: 2rem;">
                                No financing activities for this period
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($financing_items as $item): ?>
                            <tr class="item-row">
                                <td class="item-name"><?= htmlspecialchars($item['name']) ?></td>
                                <td class="amount-column <?= $item['amount'] >= 0 ? '' : 'text-danger' ?>">
                                    <?= number_format($item['amount'], 0) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <tr class="section-total financing">
                        <td style="text-align: right; padding-right: 1rem;">
                            <strong>NET CASH FROM FINANCING ACTIVITIES</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($net_cash_from_financing, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="2" style="height: 1.5rem;"></td></tr>
                    
                    <!-- NET CHANGE IN CASH -->
                    <tr class="grand-total">
                        <td style="text-align: right; padding-right: 1rem;">
                            <strong>NET INCREASE (DECREASE) IN CASH</strong>
                        </td>
                        <td class="amount-column">
                            <strong><?= number_format($net_increase_in_cash, 0) ?></strong>
                        </td>
                    </tr>
                    
                    <tr><td colspan="2" style="height: 1rem;"></td></tr>
                    
                    <!-- CASH BALANCE RECONCILIATION -->
                    <tr class="cash-summary">
                        <td style="padding-left: 2rem;">Cash at beginning of period</td>
                        <td class="amount-column"><?= number_format($beginning_cash, 0) ?></td>
                    </tr>
                    
                    <tr class="cash-summary">
                        <td style="padding-left: 2rem;">Cash at end of period</td>
                        <td class="amount-column"><?= number_format($ending_cash, 0) ?></td>
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
                        <li>This statement shows actual cash inflows and outflows</li>
                        <li>Prepared using the Direct Method</li>
                        <li>Negative amounts represent cash outflows</li>
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
    let table = document.querySelector('.cashflow-table');
    let html = table.outerHTML;
    let blob = new Blob([html], {
        type: 'application/vnd.ms-excel'
    });
    let link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'cashflow_statement_<?= date('Ymd') ?>.xls';
    link.click();
}
</script>

<?php require_once '../../includes/footer.php'; ?>