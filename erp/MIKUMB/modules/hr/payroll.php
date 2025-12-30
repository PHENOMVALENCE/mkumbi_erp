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

// Get selected month/year (default to current)
$selected_month = $_GET['month'] ?? date('n');
$selected_year = $_GET['year'] ?? date('Y');

// Fetch payroll statistics
$stats = [
    'total_employees' => 0,
    'gross_payroll' => 0,
    'total_deductions' => 0,
    'net_payroll' => 0,
    'processed_count' => 0
];

try {
    $stats_query = "
        SELECT 
            COUNT(DISTINCT pd.employee_id) as total_employees,
            SUM(pd.gross_salary) as gross_payroll,
            SUM(pd.total_deductions) as total_deductions,
            SUM(pd.net_salary) as net_payroll,
            SUM(CASE WHEN pd.payment_status = 'paid' THEN 1 ELSE 0 END) as processed_count
        FROM payroll_details pd
        INNER JOIN payroll p ON pd.payroll_id = p.payroll_id
        WHERE p.company_id = ? 
        AND p.payroll_month = ? 
        AND p.payroll_year = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id, $selected_month, $selected_year]);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stats_result && $stats_result['total_employees']) {
        $stats = [
            'total_employees' => (int)$stats_result['total_employees'],
            'gross_payroll' => (float)$stats_result['gross_payroll'],
            'total_deductions' => (float)$stats_result['total_deductions'],
            'net_payroll' => (float)$stats_result['net_payroll'],
            'processed_count' => (int)$stats_result['processed_count']
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching payroll stats: " . $e->getMessage());
}

// Fetch payroll records
$payroll_records = [];
$payroll_id = null;

try {
    // First get payroll header
    $payroll_query = "
        SELECT * FROM payroll 
        WHERE company_id = ? 
        AND payroll_month = ? 
        AND payroll_year = ?
    ";
    $stmt = $conn->prepare($payroll_query);
    $stmt->execute([$company_id, $selected_month, $selected_year]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($payroll) {
        $payroll_id = $payroll['payroll_id'];
        
        // Fetch payroll details
        $details_query = "
            SELECT 
                pd.*,
                e.employee_number,
                u.full_name,
                u.profile_picture,
                d.department_name,
                p.position_title
            FROM payroll_details pd
            INNER JOIN employees e ON pd.employee_id = e.employee_id
            INNER JOIN users u ON e.user_id = u.user_id
            LEFT JOIN departments d ON e.department_id = d.department_id
            LEFT JOIN positions p ON e.position_id = p.position_id
            WHERE pd.payroll_id = ?
            ORDER BY u.full_name ASC
        ";
        $stmt = $conn->prepare($details_query);
        $stmt->execute([$payroll_id]);
        $payroll_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Error fetching payroll records: " . $e->getMessage());
}

$page_title = 'Payroll';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
    transition: all 0.3s ease;
}

.stats-card:hover {
    transform: translateY(-3px);
}

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
}

.stats-label {
    font-size: 0.8rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.month-selector-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #28a745;
}

.table-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.table thead {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
}

.table thead th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    color: #495057;
    padding: 1rem 0.5rem;
    border-bottom: 2px solid #dee2e6;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.employee-photo {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.salary-amount {
    font-weight: 700;
    font-family: 'SF Mono', monospace;
}

.salary-amount.gross {
    color: #007bff;
}

.salary-amount.deduction {
    color: #dc3545;
}

.salary-amount.net {
    color: #28a745;
    font-size: 1.05rem;
}

.payment-status-badge {
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.payment-status-badge.paid {
    background: #d4edda;
    color: #155724;
}

.payment-status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.payroll-status-badge {
    padding: 0.5rem 1rem;
    border-radius: 25px;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
}

.payroll-status-badge.draft {
    background: #e9ecef;
    color: #495057;
}

.payroll-status-badge.processed {
    background: #cce5ff;
    color: #004085;
}

.payroll-status-badge.paid {
    background: #d4edda;
    color: #155724;
}

.breakdown-tooltip {
    cursor: help;
    border-bottom: 1px dotted #6c757d;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-money-bill-wave text-success me-2"></i>
                    Payroll Management
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Monthly payroll processing and salary payments
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <?php if ($payroll_id): ?>
                        <?php if ($payroll['status'] === 'draft'): ?>
                        <a href="process-payroll.php?id=<?php echo $payroll_id; ?>" class="btn btn-success">
                            <i class="fas fa-check-circle me-1"></i> Process Payroll
                        </a>
                        <?php endif; ?>
                        <a href="edit-payroll.php?id=<?php echo $payroll_id; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                    <?php else: ?>
                    <a href="generate-payroll.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> Generate Payroll
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="stats-number"><?php echo number_format($stats['total_employees']); ?></div>
                    <div class="stats-label">Employees</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stats-number">TSH <?php echo number_format($stats['gross_payroll'], 0); ?></div>
                    <div class="stats-label">Gross Payroll</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <div class="stats-number">TSH <?php echo number_format($stats['total_deductions'], 0); ?></div>
                    <div class="stats-label">Total Deductions</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                    <div class="stats-number">TSH <?php echo number_format($stats['net_payroll'], 0); ?></div>
                    <div class="stats-label">Net Payroll</div>
                </div>
            </div>
        </div>

        <!-- Month/Year Selector -->
        <div class="month-selector-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-4">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-calendar me-1"></i> Select Month
                    </label>
                    <select name="month" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($selected_month == $m) ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label fw-semibold">Year</label>
                    <select name="year" class="form-select">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i> View
                    </button>
                </div>
                <div class="col-lg-2 col-md-3">
                    <a href="?" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-calendar-day me-1"></i> Current Month
                    </a>
                </div>
                <div class="col-lg-3 col-md-12">
                    <?php if ($payroll_id): ?>
                    <div class="d-flex align-items-center">
                        <span class="me-2">Status:</span>
                        <span class="payroll-status-badge <?php echo strtolower($payroll['status']); ?>">
                            <?php echo ucfirst($payroll['status']); ?>
                        </span>
                        <?php if ($payroll['payment_date']): ?>
                        <small class="ms-2 text-muted">
                            Paid: <?php echo date('M j, Y', strtotime($payroll['payment_date'])); ?>
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info mb-0 py-2">
                        <i class="fas fa-info-circle me-1"></i>
                        No payroll generated for this period
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Payroll Table -->
        <?php if (empty($payroll_records)): ?>
        <div class="table-container">
            <div class="empty-state text-center py-5">
                <i class="fas fa-money-bill-wave fa-5x text-muted mb-3" style="opacity: 0.3;"></i>
                <h4 class="mb-3">No Payroll Records</h4>
                <p class="lead mb-4">
                    Payroll has not been generated for 
                    <strong><?php echo date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)); ?></strong>
                </p>
                <a href="generate-payroll.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" 
                   class="btn btn-primary btn-lg">
                    <i class="fas fa-plus-circle me-2"></i> Generate Payroll for This Period
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th width="4%">#</th>
                            <th width="5%">Photo</th>
                            <th width="15%">Employee</th>
                            <th width="10%">Basic</th>
                            <th width="8%">Allowances</th>
                            <th width="7%">Overtime</th>
                            <th width="7%">Bonus</th>
                            <th width="9%">Gross</th>
                            <th width="10%">Deductions</th>
                            <th width="11%">Net Salary</th>
                            <th width="8%">Status</th>
                            <th width="6%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $row_num = 1;
                        foreach ($payroll_records as $record): 
                        ?>
                        <tr>
                            <td class="text-muted"><?php echo $row_num++; ?></td>
                            <td>
                                <?php if (!empty($record['profile_picture'])): ?>
                                <img src="../../<?php echo htmlspecialchars($record['profile_picture']); ?>" 
                                     alt="Photo" class="employee-photo">
                                <?php else: ?>
                                <div class="employee-photo bg-secondary text-white d-flex align-items-center justify-content-center">
                                    <?php echo strtoupper(substr($record['full_name'], 0, 2)); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($record['full_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($record['employee_number']); ?></small>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($record['department_name'] ?? ''); ?></small>
                            </td>
                            <td>
                                <span class="salary-amount">
                                    <?php echo number_format($record['basic_salary'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="salary-amount">
                                    <?php echo number_format($record['allowances'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="salary-amount">
                                    <?php echo number_format($record['overtime_pay'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="salary-amount">
                                    <?php echo number_format($record['bonus'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="salary-amount gross">
                                    <?php echo number_format($record['gross_salary'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="breakdown-tooltip salary-amount deduction" 
                                      title="Tax: <?php echo number_format($record['tax_amount'], 0); ?> | NSSF: <?php echo number_format($record['nssf_amount'], 0); ?> | NHIF: <?php echo number_format($record['nhif_amount'], 0); ?> | Loans: <?php echo number_format($record['loan_deduction'], 0); ?> | Other: <?php echo number_format($record['other_deductions'], 0); ?>">
                                    <?php echo number_format($record['total_deductions'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="salary-amount net">
                                    TSH <?php echo number_format($record['net_salary'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="payment-status-badge <?php echo strtolower($record['payment_status']); ?>">
                                    <?php echo ucfirst($record['payment_status']); ?>
                                </span>
                                <?php if ($record['payment_date']): ?>
                                <br><small class="text-muted"><?php echo date('M j', strtotime($record['payment_date'])); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="view-payslip.php?id=<?php echo $record['payroll_detail_id']; ?>" 
                                       class="btn btn-outline-primary" title="View Payslip">
                                        <i class="fas fa-file-invoice"></i>
                                    </a>
                                    <a href="print-payslip.php?id=<?php echo $record['payroll_detail_id']; ?>" 
                                       class="btn btn-outline-secondary" title="Print" target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="3" class="text-end">TOTALS:</td>
                            <td><?php echo number_format(array_sum(array_column($payroll_records, 'basic_salary')), 0); ?></td>
                            <td><?php echo number_format(array_sum(array_column($payroll_records, 'allowances')), 0); ?></td>
                            <td><?php echo number_format(array_sum(array_column($payroll_records, 'overtime_pay')), 0); ?></td>
                            <td><?php echo number_format(array_sum(array_column($payroll_records, 'bonus')), 0); ?></td>
                            <td class="text-primary"><?php echo number_format($stats['gross_payroll'], 0); ?></td>
                            <td class="text-danger"><?php echo number_format($stats['total_deductions'], 0); ?></td>
                            <td class="text-success">TSH <?php echo number_format($stats['net_payroll'], 0); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <div class="p-3 border-top bg-light">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Payroll Period:</strong>
                        <?php echo date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)); ?>
                        <span class="ms-3">
                            <strong>Processed:</strong> <?php echo $stats['processed_count']; ?> / <?php echo $stats['total_employees']; ?>
                        </span>
                    </div>
                    <div class="col-md-6 text-end">
                        <a href="payroll-report.php?id=<?php echo $payroll_id; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-file-pdf me-1"></i> Generate Report
                        </a>
                        <a href="export-payroll.php?id=<?php echo $payroll_id; ?>" class="btn btn-sm btn-outline-success">
                            <i class="fas fa-file-excel me-1"></i> Export Excel
                        </a>
                        <?php if ($payroll['status'] === 'processed' && $stats['processed_count'] < $stats['total_employees']): ?>
                        <a href="mark-paid.php?id=<?php echo $payroll_id; ?>" class="btn btn-sm btn-success">
                            <i class="fas fa-check me-1"></i> Mark All as Paid
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</section>

<script>
// Initialize tooltips for deduction breakdown
document.addEventListener('DOMContentLoaded', function() {
    const tooltips = document.querySelectorAll('.breakdown-tooltip');
    tooltips.forEach(el => {
        new bootstrap.Tooltip(el);
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>