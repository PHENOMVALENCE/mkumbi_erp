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

$page_title = 'Reports Hub';
require_once '../../includes/header.php';
?>

<style>
.report-category {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border-left: 4px solid;
}

.report-category.financial { border-left-color: #007bff; }
.report-category.sales { border-left-color: #28a745; }
.report-category.operations { border-left-color: #17a2b8; }
.report-category.hr { border-left-color: #ffc107; }
.report-category.analytics { border-left-color: #6f42c1; }

.category-header {
    display: flex;
    align-items: center;
    margin-bottom: 1.25rem;
}

.category-icon {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-right: 1rem;
}

.category-icon.financial { background: #e7f3ff; color: #007bff; }
.category-icon.sales { background: #d4edda; color: #28a745; }
.category-icon.operations { background: #d1ecf1; color: #17a2b8; }
.category-icon.hr { background: #fff3cd; color: #ffc107; }
.category-icon.analytics { background: #e7e3f1; color: #6f42c1; }

.category-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.category-subtitle {
    font-size: 0.875rem;
    color: #6c757d;
    margin: 0;
}

.report-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}

.report-card {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 1rem;
    transition: all 0.2s;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    display: block;
}

.report-card:hover {
    background: #fff;
    border-color: #dee2e6;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.report-card-icon {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.75rem;
    font-size: 1.1rem;
}

.report-card-icon.primary { background: #e7f3ff; color: #007bff; }
.report-card-icon.success { background: #d4edda; color: #28a745; }
.report-card-icon.info { background: #d1ecf1; color: #17a2b8; }
.report-card-icon.warning { background: #fff3cd; color: #ffc107; }
.report-card-icon.danger { background: #f8d7da; color: #dc3545; }

.report-card-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.report-card-desc {
    font-size: 0.8rem;
    color: #6c757d;
    line-height: 1.4;
}

.quick-stats {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 8px;
    padding: 2rem;
    color: #fff;
    margin-bottom: 2rem;
}

.quick-stats h3 {
    font-size: 1.5rem;
    margin-bottom: 1.5rem;
    font-weight: 600;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1.5rem;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.875rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Reports Hub</h1>
            </div>
            <div class="col-sm-6 text-end">
                <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print This Page
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Quick Stats Overview -->
    <div class="quick-stats">
        <h3><i class="fas fa-chart-line me-2"></i>Quick Overview</h3>
        <div class="stats-grid">
            <?php
            try {
                // Total Projects
                $stmt = $conn->prepare("SELECT COUNT(*) as total FROM projects WHERE company_id = ? AND is_active = 1");
                $stmt->execute([$company_id]);
                $projects = $stmt->fetch()['total'];

                // Total Plots
                $stmt = $conn->prepare("SELECT COUNT(*) as total FROM plots WHERE company_id = ?");
                $stmt->execute([$company_id]);
                $plots = $stmt->fetch()['total'];

                // Active Reservations
                $stmt = $conn->prepare("SELECT COUNT(*) as total FROM reservations WHERE company_id = ? AND status = 'active'");
                $stmt->execute([$company_id]);
                $reservations = $stmt->fetch()['total'];

                // Total Revenue This Month
                $stmt = $conn->prepare("
                    SELECT COALESCE(SUM(amount), 0) as total 
                    FROM payments 
                    WHERE company_id = ? 
                    AND status = 'approved'
                    AND MONTH(payment_date) = MONTH(CURRENT_DATE)
                    AND YEAR(payment_date) = YEAR(CURRENT_DATE)
                ");
                $stmt->execute([$company_id]);
                $revenue = $stmt->fetch()['total'];

                // Pending Payments
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as total 
                    FROM payment_schedules 
                    WHERE company_id = ? 
                    AND payment_status = 'unpaid'
                    AND due_date <= CURRENT_DATE
                ");
                $stmt->execute([$company_id]);
                $pending = $stmt->fetch()['total'];

                // Total Employees
                $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees WHERE company_id = ? AND is_active = 1");
                $stmt->execute([$company_id]);
                $employees = $stmt->fetch()['total'];
            } catch (Exception $e) {
                $projects = $plots = $reservations = $revenue = $pending = $employees = 0;
            }
            ?>
            <div class="stat-item">
                <div class="stat-value"><?= number_format($projects) ?></div>
                <div class="stat-label">Active Projects</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= number_format($plots) ?></div>
                <div class="stat-label">Total Plots</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= number_format($reservations) ?></div>
                <div class="stat-label">Active Sales</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= number_format($revenue/1000000, 1) ?>M</div>
                <div class="stat-label">Revenue (MTD)</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= number_format($pending) ?></div>
                <div class="stat-label">Overdue Payments</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= number_format($employees) ?></div>
                <div class="stat-label">Employees</div>
            </div>
        </div>
    </div>

    <!-- Financial Reports -->
    <div class="report-category financial">
        <div class="category-header">
            <div class="category-icon financial">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div>
                <h2 class="category-title">Financial Reports</h2>
                <p class="category-subtitle">Accounting, statements, and financial analysis</p>
            </div>
        </div>
        <div class="report-grid">
            <a href="income.php" class="report-card">
                <div class="report-card-icon primary">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="report-card-title">Income Statement</div>
                <div class="report-card-desc">Revenue, expenses, and profit analysis</div>
            </a>
            
            <a href="balance.php" class="report-card">
                <div class="report-card-icon success">
                    <i class="fas fa-balance-scale"></i>
                </div>
                <div class="report-card-title">Balance Sheet</div>
                <div class="report-card-desc">Assets, liabilities, and equity position</div>
            </a>
            
            <a href="cashflow.php" class="report-card">
                <div class="report-card-icon info">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="report-card-title">Cash Flow Statement</div>
                <div class="report-card-desc">Operating, investing, and financing activities</div>
            </a>
            
            <a href="trial-balance.php" class="report-card">
                <div class="report-card-icon warning">
                    <i class="fas fa-calculator"></i>
                </div>
                <div class="report-card-title">Trial Balance</div>
                <div class="report-card-desc">Debit and credit balances verification</div>
            </a>
            
            <a href="tax-report.php" class="report-card">
                <div class="report-card-icon danger">
                    <i class="fas fa-percent"></i>
                </div>
                <div class="report-card-title">Tax Report</div>
                <div class="report-card-desc">VAT, withholding tax, and tax summary</div>
            </a>
            
            <a href="budget-variance.php" class="report-card">
                <div class="report-card-icon primary">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="report-card-title">Budget vs Actual</div>
                <div class="report-card-desc">Budget variance analysis</div>
            </a>
        </div>
    </div>

    <!-- Sales & Revenue Reports -->
    <div class="report-category sales">
        <div class="category-header">
            <div class="category-icon sales">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <div>
                <h2 class="category-title">Sales & Revenue Reports</h2>
                <p class="category-subtitle">Sales performance, commissions, and customer analysis</p>
            </div>
        </div>
        <div class="report-grid">
            <a href="sales-summary.php" class="report-card">
                <div class="report-card-icon success">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="report-card-title">Sales Summary</div>
                <div class="report-card-desc">Total sales by project, period, and status</div>
            </a>
            
            <a href="revenue-analysis.php" class="report-card">
                <div class="report-card-icon primary">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="report-card-title">Revenue Analysis</div>
                <div class="report-card-desc">Revenue trends and projections</div>
            </a>
            
            <a href="commission-report.php" class="report-card">
                <div class="report-card-icon warning">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="report-card-title">Commission Report</div>
                <div class="report-card-desc">Sales commissions and payouts</div>
            </a>
            
            <a href="customer-aging.php" class="report-card">
                <div class="report-card-icon danger">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="report-card-title">Customer Aging</div>
                <div class="report-card-desc">Accounts receivable aging analysis</div>
            </a>
            
            <a href="collection-report.php" class="report-card">
                <div class="report-card-icon info">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="report-card-title">Collection Report</div>
                <div class="report-card-desc">Payment collection performance</div>
            </a>
            
            <a href="sales-by-user.php" class="report-card">
                <div class="report-card-icon success">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="report-card-title">Sales by User</div>
                <div class="report-card-desc">Individual sales performance</div>
            </a>
        </div>
    </div>

    <!-- Operations Reports -->
    <div class="report-category operations">
        <div class="category-header">
            <div class="category-icon operations">
                <i class="fas fa-cogs"></i>
            </div>
            <div>
                <h2 class="category-title">Operations Reports</h2>
                <p class="category-subtitle">Projects, plots, inventory, and operational metrics</p>
            </div>
        </div>
        <div class="report-grid">
            <a href="project-performance.php" class="report-card">
                <div class="report-card-icon info">
                    <i class="fas fa-project-diagram"></i>
                </div>
                <div class="report-card-title">Project Performance</div>
                <div class="report-card-desc">Project costs, revenue, and profitability</div>
            </a>
            
            <a href="plot-inventory.php" class="report-card">
                <div class="report-card-icon primary">
                    <i class="fas fa-map-marked-alt"></i>
                </div>
                <div class="report-card-title">Plot Inventory</div>
                <div class="report-card-desc">Available, reserved, and sold plots</div>
            </a>
            
            <a href="title-deed-status.php" class="report-card">
                <div class="report-card-icon warning">
                    <i class="fas fa-certificate"></i>
                </div>
                <div class="report-card-title">Title Deed Status</div>
                <div class="report-card-desc">Processing stages and completion rates</div>
            </a>
            
            <a href="payment-schedule.php" class="report-card">
                <div class="report-card-icon success">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="report-card-title">Payment Schedules</div>
                <div class="report-card-desc">Expected vs actual payment receipts</div>
            </a>
            
            <a href="creditor-report.php" class="report-card">
                <div class="report-card-icon danger">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="report-card-title">Creditor Report</div>
                <div class="report-card-desc">Accounts payable and creditor aging</div>
            </a>
            
            <a href="service-requests.php" class="report-card">
                <div class="report-card-icon info">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="report-card-title">Service Requests</div>
                <div class="report-card-desc">Land services performance</div>
            </a>
        </div>
    </div>

    <!-- HR & Payroll Reports -->
    <div class="report-category hr">
        <div class="category-header">
            <div class="category-icon hr">
                <i class="fas fa-users"></i>
            </div>
            <div>
                <h2 class="category-title">HR & Payroll Reports</h2>
                <p class="category-subtitle">Employee data, attendance, payroll, and leave management</p>
            </div>
        </div>
        <div class="report-grid">
            <a href="payroll-summary.php" class="report-card">
                <div class="report-card-icon warning">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <div class="report-card-title">Payroll Summary</div>
                <div class="report-card-desc">Salaries, deductions, and net pay</div>
            </a>
            
            <a href="employee-list.php" class="report-card">
                <div class="report-card-icon primary">
                    <i class="fas fa-id-card"></i>
                </div>
                <div class="report-card-title">Employee Master List</div>
                <div class="report-card-desc">Complete employee directory</div>
            </a>
            
            <a href="attendance-report.php" class="report-card">
                <div class="report-card-icon success">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="report-card-title">Attendance Report</div>
                <div class="report-card-desc">Daily attendance and overtime</div>
            </a>
            
            <a href="leave-report.php" class="report-card">
                <div class="report-card-icon info">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="report-card-title">Leave Report</div>
                <div class="report-card-desc">Leave applications and balances</div>
            </a>
            
            <a href="loan-report.php" class="report-card">
                <div class="report-card-icon danger">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="report-card-title">Loan Report</div>
                <div class="report-card-desc">Employee loans and repayments</div>
            </a>
            
            <a href="statutory-deductions.php" class="report-card">
                <div class="report-card-icon warning">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="report-card-title">Statutory Deductions</div>
                <div class="report-card-desc">NSSF, PAYE, and other deductions</div>
            </a>
        </div>
    </div>

    <!-- Analytics & Insights -->
    <div class="report-category analytics">
        <div class="category-header">
            <div class="category-icon analytics">
                <i class="fas fa-chart-area"></i>
            </div>
            <div>
                <h2 class="category-title">Analytics & Insights</h2>
                <p class="category-subtitle">Business intelligence and trend analysis</p>
            </div>
        </div>
        <div class="report-grid">
            <a href="executive-dashboard.php" class="report-card">
                <div class="report-card-icon primary">
                    <i class="fas fa-tachometer-alt"></i>
                </div>
                <div class="report-card-title">Executive Dashboard</div>
                <div class="report-card-desc">High-level business overview</div>
            </a>
            
            <a href="sales-forecast.php" class="report-card">
                <div class="report-card-icon success">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="report-card-title">Sales Forecast</div>
                <div class="report-card-desc">Projected sales and revenue trends</div>
            </a>
            
            <a href="customer-analytics.php" class="report-card">
                <div class="report-card-icon info">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div class="report-card-title">Customer Analytics</div>
                <div class="report-card-desc">Customer behavior and segmentation</div>
            </a>
            
            <a href="profitability-analysis.php" class="report-card">
                <div class="report-card-icon warning">
                    <i class="fas fa-funnel-dollar"></i>
                </div>
                <div class="report-card-title">Profitability Analysis</div>
                <div class="report-card-desc">Profit margins by project and product</div>
            </a>
            
            <a href="kpi-dashboard.php" class="report-card">
                <div class="report-card-icon danger">
                    <i class="fas fa-bullseye"></i>
                </div>
                <div class="report-card-title">KPI Dashboard</div>
                <div class="report-card-desc">Key performance indicators tracking</div>
            </a>
            
            <a href="trend-analysis.php" class="report-card">
                <div class="report-card-icon primary">
                    <i class="fas fa-chart-area"></i>
                </div>
                <div class="report-card-title">Trend Analysis</div>
                <div class="report-card-desc">Historical trends and patterns</div>
            </a>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>