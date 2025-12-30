<?php
define('APP_ACCESS', true);
session_start();

require_once 'config/database.php';
require_once 'config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

// Get comprehensive dashboard statistics from database
try {
    // ====================== REVENUE FROM SALES/RESERVATIONS ======================
    
    // Total Revenue from all approved payments
    $total_revenue = $conn->query("
        SELECT COALESCE(SUM(amount), 0) as total_revenue
        FROM payments 
        WHERE company_id = $company_id 
        AND status = 'approved'
    ")->fetch(PDO::FETCH_ASSOC)['total_revenue'];
    
    // Revenue this month from payments
    $revenue_this_month = $conn->query("
        SELECT COALESCE(SUM(amount), 0) as revenue_this_month
        FROM payments 
        WHERE company_id = $company_id 
        AND status = 'approved'
        AND MONTH(payment_date) = MONTH(CURDATE())
        AND YEAR(payment_date) = YEAR(CURDATE())
    ")->fetch(PDO::FETCH_ASSOC)['revenue_this_month'];
    
    // Revenue last month from payments
    $revenue_last_month = $conn->query("
        SELECT COALESCE(SUM(amount), 0) as revenue_last_month
        FROM payments 
        WHERE company_id = $company_id 
        AND status = 'approved'
        AND MONTH(payment_date) = MONTH(CURDATE()) - 1
        AND YEAR(payment_date) = YEAR(CURDATE())
    ")->fetch(PDO::FETCH_ASSOC)['revenue_last_month'];
    
    // Revenue by reservation status
    $revenue_by_status = $conn->query("
        SELECT 
            r.status,
            COUNT(r.reservation_id) as reservation_count,
            COALESCE(SUM(p.amount), 0) as total_revenue
        FROM reservations r
        LEFT JOIN payments p ON r.reservation_id = p.reservation_id AND p.status = 'approved'
        WHERE r.company_id = $company_id
        GROUP BY r.status
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== EXPENSES FROM DIRECT EXPENSES TABLE ======================
    
    // Total Expenses from direct_expenses
    $total_expenses = $conn->query("
        SELECT COALESCE(SUM(amount), 0) as total_expenses
        FROM direct_expenses 
        WHERE company_id = $company_id 
        AND status = 'paid'
    ")->fetch(PDO::FETCH_ASSOC)['total_expenses'];
    
    // This month's expenses
    $this_month_expenses = $conn->query("
        SELECT COALESCE(SUM(amount), 0) as monthly_expenses
        FROM direct_expenses 
        WHERE company_id = $company_id 
        AND status = 'paid'
        AND MONTH(expense_date) = MONTH(CURDATE())
        AND YEAR(expense_date) = YEAR(CURDATE())
    ")->fetch(PDO::FETCH_ASSOC)['monthly_expenses'];
    
    // Expenses by category
    $expenses_by_category = $conn->query("
        SELECT 
            ec.category_name,
            COALESCE(SUM(de.amount), 0) as total_amount,
            COUNT(de.expense_id) as expense_count
        FROM expense_categories ec
        LEFT JOIN direct_expenses de ON ec.category_id = de.category_id 
            AND de.company_id = $company_id 
            AND de.status = 'paid'
        WHERE ec.company_id = $company_id
        GROUP BY ec.category_id, ec.category_name
        HAVING total_amount > 0
        ORDER BY total_amount DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent expenses
    $recent_expenses = $conn->query("
        SELECT 
            de.expense_date,
            de.amount,
            de.description,
            de.payment_method,
            ec.category_name
        FROM direct_expenses de
        LEFT JOIN expense_categories ec ON de.category_id = ec.category_id
        WHERE de.company_id = $company_id 
        AND de.status = 'paid'
        ORDER BY de.expense_date DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== BANK ACCOUNT BALANCES ======================
    
    // Bank Account Balances
    $bank_accounts = $conn->query("
        SELECT 
            bank_account_id,
            account_name,
            account_number,
            bank_name,
            current_balance,
            currency_code,
            is_default
        FROM bank_accounts 
        WHERE company_id = $company_id 
        AND is_active = 1
        ORDER BY is_default DESC, current_balance DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Total Bank Balance
    $total_bank_balance = 0;
    foreach ($bank_accounts as $bank) {
        $total_bank_balance += $bank['current_balance'];
    }
    
    // Bank transactions (deposits and withdrawals)
    $bank_transactions_summary = $conn->query("
        SELECT 
            DATE_FORMAT(transaction_date, '%Y-%m') as month_year,
            SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as deposits,
            SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as withdrawals
        FROM bank_transactions 
        WHERE company_id = $company_id 
        AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY YEAR(transaction_date), MONTH(transaction_date)
        ORDER BY transaction_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== SALES/RESERVATION STATISTICS ======================
    
    // Plot statistics
    $plot_stats = $conn->query("
        SELECT 
            COUNT(*) as total_plots,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_plots,
            SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved_plots,
            SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_plots
        FROM plots 
        WHERE company_id = $company_id AND is_active = 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Customer statistics
    $customer_stats = $conn->query("
        SELECT 
            (SELECT COUNT(*) FROM customers WHERE company_id = $company_id AND is_active = 1) as total_customers,
            (SELECT COUNT(*) FROM reservations WHERE company_id = $company_id AND status = 'active') as active_reservations,
            (SELECT COUNT(*) FROM reservations WHERE company_id = $company_id AND status = 'completed') as completed_contracts
        FROM dual
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Revenue from completed reservations
    $completed_reservation_revenue = $conn->query("
        SELECT COALESCE(SUM(p.amount), 0) as completed_revenue
        FROM payments p
        INNER JOIN reservations r ON p.reservation_id = r.reservation_id
        WHERE p.company_id = $company_id 
        AND p.status = 'approved'
        AND r.status = 'completed'
    ")->fetch(PDO::FETCH_ASSOC)['completed_revenue'];
    
    // ====================== PAYMENT STATISTICS ======================
    
    // Overdue payments
    $overdue_stats = $conn->query("
        SELECT 
            COUNT(*) as overdue_payments,
            COALESCE(SUM(installment_amount), 0) as overdue_amount
        FROM payment_schedules 
        WHERE company_id = $company_id 
        AND is_paid = 0 
        AND due_date < CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Payment count this month
    $payment_count = $conn->query("
        SELECT COUNT(*) as total_payments_this_month
        FROM payments 
        WHERE company_id = $company_id 
        AND MONTH(payment_date) = MONTH(CURDATE())
        AND YEAR(payment_date) = YEAR(CURDATE())
        AND status = 'approved'
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Average payment amount
    $avg_payment = $conn->query("
        SELECT COALESCE(AVG(amount), 0) as avg_payment_amount
        FROM payments 
        WHERE company_id = $company_id 
        AND status = 'approved'
    ")->fetch(PDO::FETCH_ASSOC)['avg_payment_amount'];
    
    // ====================== PROJECT STATISTICS ======================
    $project_stats = $conn->query("
        SELECT 
            COUNT(*) as total_projects,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_projects
        FROM projects 
        WHERE company_id = $company_id AND is_active = 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    // ====================== COLLECTION RATE ======================
    $collection_rate_data = $conn->query("
        SELECT 
            COALESCE(SUM(ps.installment_amount), 0) as total_due,
            COALESCE(SUM(ps.paid_amount), 0) as total_collected
        FROM payment_schedules ps
        WHERE ps.company_id = $company_id
        AND ps.due_date <= CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);
    
    $collection_rate = $collection_rate_data['total_due'] > 0 
        ? ($collection_rate_data['total_collected'] / $collection_rate_data['total_due']) * 100 
        : 0;
    
    // ====================== FINANCIAL DATA FOR CHARTS ======================
    
    // Monthly revenue data from payments (last 6 months)
    $monthly_revenue = $conn->query("
        SELECT 
            DATE_FORMAT(payment_date, '%b') as month,
            DATE_FORMAT(payment_date, '%Y-%m') as month_year,
            COALESCE(SUM(amount), 0) as revenue,
            COUNT(*) as payment_count
        FROM payments 
        WHERE company_id = $company_id 
        AND status = 'approved'
        AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY YEAR(payment_date), MONTH(payment_date)
        ORDER BY payment_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly expenses data (last 6 months)
    $monthly_expenses = $conn->query("
        SELECT 
            DATE_FORMAT(expense_date, '%b') as month,
            DATE_FORMAT(expense_date, '%Y-%m') as month_year,
            COALESCE(SUM(amount), 0) as expenses,
            COUNT(*) as expense_count
        FROM direct_expenses 
        WHERE company_id = $company_id 
        AND status = 'paid'
        AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY YEAR(expense_date), MONTH(expense_date)
        ORDER BY expense_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== SALES BY PROJECT ======================
    $sales_by_project = $conn->query("
        SELECT 
            p.project_name,
            COUNT(DISTINCT r.reservation_id) as sales_count,
            COALESCE(SUM(pay.amount), 0) as total_revenue
        FROM projects p
        LEFT JOIN plots pl ON p.project_id = pl.project_id
        LEFT JOIN reservations r ON pl.plot_id = r.plot_id AND r.status IN ('active', 'completed')
        LEFT JOIN payments pay ON r.reservation_id = pay.reservation_id AND pay.status = 'approved'
        WHERE p.company_id = $company_id 
        AND p.is_active = 1
        GROUP BY p.project_id, p.project_name
        HAVING sales_count > 0 OR total_revenue > 0
        ORDER BY total_revenue DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== RECENT PAYMENTS ======================
    $recent_payments = $conn->query("
        SELECT 
            p.payment_date,
            p.amount,
            p.payment_method,
            p.status,
            CONCAT(c.first_name, ' ', c.last_name) as customer_name,
            CONCAT(pl.plot_number, ', Block ', COALESCE(pl.block_number, 'N/A')) as plot_info,
            pr.project_name
        FROM payments p
        INNER JOIN reservations r ON p.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots pl ON r.plot_id = pl.plot_id
        INNER JOIN projects pr ON pl.project_id = pr.project_id
        WHERE p.company_id = $company_id
        AND p.status = 'approved'
        ORDER BY p.payment_date DESC, p.created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== UPCOMING PAYMENTS ======================
    $upcoming_payments = $conn->query("
        SELECT 
            ps.due_date,
            ps.installment_amount,
            ps.installment_number,
            CONCAT(c.first_name, ' ', c.last_name) as customer_name,
            CONCAT(pl.plot_number, ', Block ', COALESCE(pl.block_number, 'N/A')) as plot_info,
            pr.project_name
        FROM payment_schedules ps
        INNER JOIN reservations r ON ps.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots pl ON r.plot_id = pl.plot_id
        INNER JOIN projects pr ON pl.project_id = pr.project_id
        WHERE ps.company_id = $company_id
        AND ps.is_paid = 0
        AND ps.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY ps.due_date ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== TOP CUSTOMERS ======================
    $top_customers = $conn->query("
        SELECT 
            CONCAT(c.first_name, ' ', c.last_name) as customer_name,
            COUNT(DISTINCT r.reservation_id) as total_purchases,
            COALESCE(SUM(p.amount), 0) as total_paid
        FROM customers c
        LEFT JOIN reservations r ON c.customer_id = r.customer_id
        LEFT JOIN payments p ON r.reservation_id = p.reservation_id AND p.status = 'approved'
        WHERE c.company_id = $company_id
        GROUP BY c.customer_id
        HAVING total_paid > 0
        ORDER BY total_paid DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== RECENT LEADS ======================
    $recent_leads = $conn->query("
        SELECT 
            full_name,
            phone,
            lead_source,
            lead_status,
            interested_in,
            DATE_FORMAT(created_at, '%Y-%m-%d') as created_date
        FROM leads
        WHERE company_id = $company_id
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== PENDING APPROVALS ======================
    $pending_approvals = $conn->query("
        SELECT 
            ar.request_number,
            ar.reference_type,
            ar.amount,
            DATE_FORMAT(ar.request_date, '%Y-%m-%d') as request_date,
            CONCAT(u.first_name, ' ', u.last_name) as requested_by_name,
            aw.workflow_name
        FROM approval_requests ar
        INNER JOIN approval_workflows aw ON ar.workflow_id = aw.workflow_id
        INNER JOIN users u ON ar.requested_by = u.user_id
        WHERE ar.company_id = $company_id
        AND ar.overall_status = 'pending'
        ORDER BY ar.request_date DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================== CALCULATE FINANCIAL METRICS ======================
    $net_profit = $total_revenue - $total_expenses;
    $profit_margin = $total_revenue > 0 ? ($net_profit / $total_revenue) * 100 : 0;
    $expense_ratio = $total_revenue > 0 ? ($total_expenses / $total_revenue) * 100 : 0;
    
} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    // Set default values if error occurs
    $total_revenue = 0;
    $revenue_this_month = 0;
    $revenue_last_month = 0;
    $revenue_by_status = [];
    $total_expenses = 0;
    $this_month_expenses = 0;
    $expenses_by_category = [];
    $recent_expenses = [];
    $bank_accounts = [];
    $total_bank_balance = 0;
    $bank_transactions_summary = [];
    $plot_stats = ['total_plots' => 0, 'available_plots' => 0, 'reserved_plots' => 0, 'sold_plots' => 0];
    $customer_stats = ['total_customers' => 0, 'active_reservations' => 0, 'completed_contracts' => 0];
    $completed_reservation_revenue = 0;
    $overdue_stats = ['overdue_payments' => 0, 'overdue_amount' => 0];
    $payment_count = ['total_payments_this_month' => 0];
    $avg_payment = 0;
    $project_stats = ['total_projects' => 0, 'active_projects' => 0];
    $collection_rate = 0;
    $monthly_revenue = [];
    $monthly_expenses = [];
    $sales_by_project = [];
    $recent_payments = [];
    $upcoming_payments = [];
    $top_customers = [];
    $recent_leads = [];
    $pending_approvals = [];
    $net_profit = 0;
    $profit_margin = 0;
    $expense_ratio = 0;
}

$page_title = 'Dashboard - Financial Overview';
require_once 'includes/header.php';
?>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- Include DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-tachometer-alt text-primary me-2"></i>Dashboard - Financial Overview
                </h1>
                <p class="text-muted small mb-0 mt-1">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        
        <!-- Quick Financial Summary -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0 bg-gradient-primary bg-opacity-10">
                    <div class="card-body p-4">
                        <div class="row align-items-center">
                            <div class="col-md-3 text-center mb-3 mb-md-0">
                                <h4 class="mb-0 fw-bold text-primary">Financial Summary</h4>
                                <small class="text-muted">Real-time from Sales & Expenses</small>
                            </div>
                            <div class="col-md-9">
                                <div class="row text-center">
                                    <div class="col  -4">
                                        <small class="text-muted d-block">Total Revenue</small>
                                        <h4 class="text-success mb-0">TSH <?php echo number_format($total_revenue); ?></h4>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted d-block">Total Expenses</small>
                                        <h4 class="text-danger mb-0">TSH <?php echo number_format($total_expenses); ?></h4>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted d-block">Net Profit</small>
                                        <h4 class="<?php echo $net_profit >= 0 ? 'text-success' : 'text-danger'; ?> mb-0">
                                            TSH <?php echo number_format($net_profit); ?>
                                        </h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue & Expenses Overview -->
        <div class="row g-3 mb-4">
            <!-- Revenue Section -->
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-money-bill-wave text-success me-2"></i>Revenue from Sales
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-6">
                                <div class="p-3 bg-light rounded text-center">
                                    <small class="text-muted d-block">This Month</small>
                                    <h4 class="text-success mb-0">TSH <?php echo number_format($revenue_this_month); ?></h4>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded text-center">
                                    <small class="text-muted d-block">Last Month</small>
                                    <h4 class="text-success mb-0">TSH <?php echo number_format($revenue_last_month); ?></h4>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Revenue by reservation status -->
                        <?php if (!empty($revenue_by_status)): ?>
                        <div class="mt-3">
                            <small class="text-muted d-block mb-2">Revenue by Reservation Status</small>
                            <?php foreach ($revenue_by_status as $status): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="badge bg-light text-dark"><?php echo ucfirst($status['status']); ?></span>
                                <div class="text-end">
                                    <small class="fw-semibold">TSH <?php echo number_format($status['total_revenue']); ?></small>
                                    <small class="text-muted d-block"><?php echo $status['reservation_count']; ?> reservations</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Completed reservations revenue -->
                        <div class="mt-4 p-3 bg-success bg-opacity-10 rounded">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">Completed Reservations Revenue:</small>
                                <h5 class="text-success mb-0">TSH <?php echo number_format($completed_reservation_revenue); ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Expenses Section -->
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-file-invoice-dollar text-danger me-2"></i>Expenses Summary
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="text-danger mb-0">TSH <?php echo number_format($total_expenses); ?></h3>
                            <span class="badge bg-danger">Total Paid Expenses</span>
                        </div>
                        
                        <div class="expense-breakdown">
                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                <small class="text-muted">This Month Expenses:</small>
                                <small class="fw-semibold text-danger">TSH <?php echo number_format($this_month_expenses); ?></small>
                            </div>
                            
                            <?php if (!empty($expenses_by_category)): ?>
                            <small class="text-muted d-block mt-3 mb-2">Expenses by Category:</small>
                            <?php foreach ($expenses_by_category as $expense): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="text-muted"><?php echo htmlspecialchars($expense['category_name']); ?></small>
                                <div class="text-end">
                                    <small class="fw-semibold text-danger">TSH <?php echo number_format($expense['total_amount']); ?></small>
                                    <small class="text-muted d-block"><?php echo $expense['expense_count']; ?> expenses</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Expense ratio -->
                        <?php if ($total_revenue > 0): ?>
                        <div class="mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="text-muted">Expense Ratio:</small>
                                <small class="fw-semibold"><?php echo number_format($expense_ratio, 1); ?>%</small>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-danger" 
                                     role="progressbar" 
                                     style="width: <?php echo min($expense_ratio, 100); ?>%"
                                     aria-valuenow="<?php echo $expense_ratio; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100"></div>
                            </div>
                            <small class="text-muted">Expenses / Revenue ratio</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bank Balances & Recent Transactions -->
        <div class="row g-3 mb-4">
            <!-- Bank Balances -->
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-university text-info me-2"></i>Bank Account Balances
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="text-info mb-0">TSH <?php echo number_format($total_bank_balance); ?></h3>
                            <span class="badge bg-info">Total Bank Balance</span>
                        </div>
                        
                        <div class="bank-accounts-list">
                            <?php if (!empty($bank_accounts)): ?>
                                <?php foreach ($bank_accounts as $bank): ?>
                                <div class="bank-account-item mb-2 p-2 border rounded">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="fw-semibold d-block"><?php echo htmlspecialchars($bank['bank_name']); ?></small>
                                            <small class="text-muted"><?php echo htmlspecialchars($bank['account_name']); ?> (<?php echo htmlspecialchars($bank['account_number']); ?>)</small>
                                        </div>
                                        <div class="text-end">
                                            <h6 class="mb-0 fw-bold"><?php echo $bank['currency_code']; ?> <?php echo number_format($bank['current_balance']); ?></h6>
                                            <?php if ($bank['is_default']): ?>
                                            <small class="badge bg-success">Default</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-university fa-3x mb-3"></i>
                                    <p class="small mb-0">No bank accounts configured</p>
                                    <a href="modules/settings/bank_accounts.php" class="btn btn-sm btn-outline-primary mt-2">
                                        <i class="fas fa-plus me-1"></i> Add Bank Account
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Payments -->
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-money-bill-wave text-success me-2"></i>Recent Payments
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="recent-payments-list">
                            <?php if (!empty($recent_payments)): ?>
                                <?php foreach ($recent_payments as $payment): ?>
                                <div class="recent-payment-item p-3 border-bottom">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($payment['customer_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($payment['plot_info']); ?></small>
                                            <br>
                                            <small class="text-muted"><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <h5 class="text-success mb-1">TSH <?php echo number_format($payment['amount']); ?></h5>
                                            <span class="badge bg-light text-dark"><?php echo ucfirst($payment['payment_method']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-3x mb-3"></i>
                                    <p class="small mb-0">No recent payments</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-white text-center border-top">
                            <a href="modules/payments/index.php" class="btn btn-sm btn-outline-primary">
                                View All Payments
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Expenses -->
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-receipt text-danger me-2"></i>Recent Expenses
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="recent-expenses-list">
                            <?php if (!empty($recent_expenses)): ?>
                                <?php foreach ($recent_expenses as $expense): ?>
                                <div class="recent-expense-item p-3 border-bottom">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($expense['description']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($expense['category_name']); ?></small>
                                            <br>
                                            <small class="text-muted"><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <h5 class="text-danger mb-1">TSH <?php echo number_format($expense['amount']); ?></h5>
                                            <span class="badge bg-light text-dark"><?php echo ucfirst($expense['payment_method']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-receipt fa-3x mb-3"></i>
                                    <p class="small mb-0">No recent expenses</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-white text-center border-top">
                            <a href="modules/expenses/index.php" class="btn btn-sm btn-outline-danger">
                                View All Expenses
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Metrics -->
        <div class="row g-3 mb-4">
            <!-- Plot Statistics -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card">
                    <div class="stats-icon bg-primary">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <div class="stats-content">
                        <h3 class="stats-number"><?php echo number_format($plot_stats['total_plots']); ?></h3>
                        <p class="stats-label">Total Plots</p>
                        <div class="stats-sub">
                            <span class="badge bg-success"><?php echo $plot_stats['available_plots']; ?> Available</span>
                            <span class="badge bg-info"><?php echo $plot_stats['reserved_plots']; ?> Reserved</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Statistics -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card">
                    <div class="stats-icon bg-warning">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stats-content">
                        <h3 class="stats-number"><?php echo number_format($customer_stats['total_customers']); ?></h3>
                        <p class="stats-label">Total Customers</p>
                        <div class="stats-sub">
                            <span class="badge bg-primary"><?php echo $customer_stats['active_reservations']; ?> Active</span>
                            <span class="badge bg-secondary"><?php echo $customer_stats['completed_contracts']; ?> Completed</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overdue Payments -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card">
                    <div class="stats-icon bg-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stats-content">
                        <h3 class="stats-number"><?php echo number_format($overdue_stats['overdue_payments']); ?></h3>
                        <p class="stats-label">Overdue Payments</p>
                        <div class="stats-sub">
                            <small class="text-danger">TSH <?php echo number_format($overdue_stats['overdue_amount']); ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Collection Rate -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card">
                    <div class="stats-icon bg-success">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stats-content">
                        <h3 class="stats-number"><?php echo number_format($collection_rate, 1); ?>%</h3>
                        <p class="stats-label">Collection Rate</p>
                        <div class="stats-sub">
                            <a href="modules/reports/collection.php" class="text-success text-decoration-none">
                                <i class="fas fa-chart-bar"></i> View Report
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue vs Expenses Chart -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line text-primary me-2"></i>Revenue vs Expenses (Last 6 Months)
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueExpenseChart" height="80"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Data Tables -->
        <div class="row g-3 mb-4">
            <!-- Upcoming Payments -->
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-alt text-info me-2"></i>Upcoming Payments (Next 30 Days)
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Due Date</th>
                                        <th>Customer</th>
                                        <th>Plot</th>
                                        <th>#</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($upcoming_payments)): ?>
                                        <?php foreach ($upcoming_payments as $payment): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?php echo date('d M', strtotime($payment['due_date'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['customer_name']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['plot_info']); ?></td>
                                            <td>#<?php echo $payment['installment_number']; ?></td>
                                            <td class="fw-semibold">TSH <?php echo number_format($payment['installment_amount']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">
                                                <i class="fas fa-calendar-check fa-2x mb-2 d-block"></i>
                                                No upcoming payments in the next 30 days
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Customers -->
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-trophy text-warning me-2"></i>Top Customers
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php if (!empty($top_customers)): ?>
                                <?php foreach ($top_customers as $index => $customer): 
                                    $rank_class = $index == 0 ? 'bg-warning text-dark' : 
                                                ($index == 1 ? 'bg-secondary text-white' : 
                                                ($index == 2 ? 'bg-info text-white' : 'bg-light text-dark'));
                                ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <span class="badge <?php echo $rank_class; ?> me-2" style="width: 24px; height: 24px; line-height: 24px;">
                                            <?php echo $index + 1; ?>
                                        </span>
                                        <div>
                                            <h6 class="mb-0 small"><?php echo htmlspecialchars($customer['customer_name']); ?></h6>
                                            <small class="text-muted"><?php echo $customer['total_purchases']; ?> purchase(s)</small>
                                        </div>
                                    </div>
                                    <span class="fw-semibold text-success">TSH <?php echo number_format($customer['total_paid']); ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="list-group-item text-center text-muted py-4">
                                    <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                    <small>No customer data available</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions & Recent Leads -->
        <div class="row g-3">
            <!-- Quick Actions -->
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-bolt text-primary me-2"></i>Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="modules/payments/record.php" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-money-bill-wave me-2"></i> Record Payment
                            </a>
                            <a href="modules/expenses/create.php" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-file-invoice-dollar me-2"></i> Record Expense
                            </a>
                            <a href="modules/sales/create.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-file-contract me-2"></i> New Reservation
                            </a>
                            <a href="modules/customers/create.php" class="btn btn-outline-warning btn-sm">
                                <i class="fas fa-user-plus me-2"></i> Add Customer
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Leads -->
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-user-friends text-primary me-2"></i>Recent Leads
                            </h5>
                            <a href="modules/marketing/leads.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye me-1"></i> View All
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Interested In</th>
                                        <th>Source</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recent_leads)): ?>
                                        <?php foreach ($recent_leads as $lead): 
                                            $status_badge = match($lead['lead_status']) {
                                                'new' => 'bg-primary',
                                                'contacted' => 'bg-info',
                                                'qualified' => 'bg-success',
                                                'converted' => 'bg-success',
                                                'lost' => 'bg-danger',
                                                default => 'bg-secondary'
                                            };
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($lead['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($lead['phone']); ?></td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?php echo htmlspecialchars($lead['interested_in']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo ucfirst($lead['lead_source']); ?></td>
                                            <td>
                                                <span class="badge <?php echo $status_badge; ?>">
                                                    <?php echo ucfirst($lead['lead_status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('d M', strtotime($lead['created_date'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                <i class="fas fa-user-friends fa-2x mb-2 d-block"></i>
                                                No recent leads found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.container-fluid -->
</section>

<script>
// Format data for charts
const monthlyRevenueData = <?php echo json_encode($monthly_revenue); ?>;
const monthlyExpenseData = <?php echo json_encode($monthly_expenses); ?>;

// Prepare data for revenue vs expense chart
const months = monthlyRevenueData.map(item => item.month);
const revenues = monthlyRevenueData.map(item => parseFloat(item.revenue) / 1000000); // Convert to millions

// Match expenses to the same months as revenue
const expenses = months.map(month => {
    const expenseItem = monthlyExpenseData.find(item => item.month === month);
    return expenseItem ? parseFloat(expenseItem.expenses) / 1000000 : 0;
});

// Revenue vs Expenses Chart
document.addEventListener('DOMContentLoaded', function() {
    const revenueExpenseCtx = document.getElementById('revenueExpenseChart').getContext('2d');
    
    if (months.length > 0) {
        new Chart(revenueExpenseCtx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Revenue (TSH Millions)',
                        data: revenues,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: 'rgb(40, 167, 69)',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Expenses (TSH Millions)',
                        data: expenses,
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: 'rgb(220, 53, 69)',
                        borderWidth: 1,
                        yAxisID: 'y'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += `TSH ${context.parsed.y.toFixed(1)}M`;
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Amount (TSH Millions)'
                        },
                        ticks: {
                            callback: function(value) {
                                return 'TSH ' + value.toFixed(0) + 'M';
                            }
                        }
                    }
                }
            }
        });
    }

    // Auto refresh dashboard every 5 minutes
    setInterval(() => {
        console.log('Refreshing dashboard data...');
        // You can implement AJAX refresh here
    }, 300000);
});

// Print functionality
function printDashboard() {
    window.print();
}
</script>

<style>
.stats-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    position: relative;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
}

.stats-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    color: white;
    font-size: 1.5rem;
}

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    color: #2c3e50;
}

.stats-label {
    color: #6c757d;
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.stats-sub {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.bank-account-item {
    background: #f8f9fa;
    transition: background 0.2s ease;
}

.bank-account-item:hover {
    background: #e9ecef;
}

.recent-payment-item, .recent-expense-item {
    transition: background 0.2s ease;
}

.recent-payment-item:hover, .recent-expense-item:hover {
    background: #f8f9fa;
}

.revenue-breakdown, .expense-breakdown {
    max-height: 200px;
    overflow-y: auto;
}

.revenue-breakdown::-webkit-scrollbar,
.expense-breakdown::-webkit-scrollbar {
    width: 4px;
}

.revenue-breakdown::-webkit-scrollbar-track,
.expense-breakdown::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.revenue-breakdown::-webkit-scrollbar-thumb,
.expense-breakdown::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 2px;
}

.card {
    border: none;
    border-radius: 12px;
}

.card-header {
    padding: 1rem 1.25rem;
    border-radius: 12px 12px 0 0 !important;
}

.table th {
    border-top: none;
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
}

.badge {
    font-size: 0.7rem;
    font-weight: 500;
}

.progress {
    border-radius: 4px;
}

.list-group-item {
    border: none;
    padding: 0.75rem 1rem;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.bg-gradient-primary.bg-opacity-10 {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
}

@media (max-width: 768px) {
    .stats-number {
        font-size: 1.5rem;
    }
    
    .stats-icon {
        width: 50px;
        height: 50px;
        font-size: 1.25rem;
    }
    
    .card-body {
        padding: 0.75rem;
    }
    
    .bank-account-item {
        padding: 0.5rem;
    }
    
    .bank-account-item h6 {
        font-size: 0.9rem;
    }
}

@media print {
    .btn, .card-header .float-sm-end, .quick-actions {
        display: none !important;
    }
    
    .stats-card, .card {
        break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    
    .bg-gradient-primary.bg-opacity-10 {
        background: none !important;
        border: 1px solid #ddd !important;
    }
}
</style>

<?php 
require_once 'includes/footer.php';
?>