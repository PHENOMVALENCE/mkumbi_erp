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

// Get payroll ID
$payroll_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($payroll_id <= 0) {
    $_SESSION['error_message'] = 'Invalid payroll ID';
    header('Location: payroll.php');
    exit;
}

// Fetch payroll header
try {
    $header_query = "
        SELECT 
            p.*,
            c.company_name,
            c.tax_identification_number as company_tin,
            c.phone as company_phone,
            c.email as company_email,
            c.physical_address as company_address,
            c.logo_path
        FROM payroll p
        INNER JOIN companies c ON p.company_id = c.company_id
        WHERE p.payroll_id = ? AND p.company_id = ?
    ";
    
    $stmt = $conn->prepare($header_query);
    $stmt->execute([$payroll_id, $company_id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payroll) {
        $_SESSION['error_message'] = 'Payroll not found';
        header('Location: payroll.php');
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching payroll: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error loading payroll';
    header('Location: payroll.php');
    exit;
}

$period_name = date('F Y', mktime(0, 0, 0, $payroll['payroll_month'], 1, $payroll['payroll_year']));

// Fetch payroll details
try {
    $details_query = "
        SELECT 
            pd.*,
            e.employee_number,
            u.full_name,
            d.department_name,
            pos.position_title
        FROM payroll_details pd
        INNER JOIN employees e ON pd.employee_id = e.employee_id
        INNER JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions pos ON e.position_id = pos.position_id
        WHERE pd.payroll_id = ?
        ORDER BY d.department_name, u.full_name
    ";
    
    $stmt = $conn->prepare($details_query);
    $stmt->execute([$payroll_id]);
    $payroll_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching payroll details: " . $e->getMessage());
    $payroll_details = [];
}

// Calculate totals
$totals = [
    'count' => count($payroll_details),
    'basic' => 0,
    'allowances' => 0,
    'overtime' => 0,
    'bonus' => 0,
    'gross' => 0,
    'tax' => 0,
    'nssf' => 0,
    'nhif' => 0,
    'loans' => 0,
    'other' => 0,
    'deductions' => 0,
    'net' => 0
];

foreach ($payroll_details as $detail) {
    $totals['basic'] += $detail['basic_salary'];
    $totals['allowances'] += $detail['allowances'];
    $totals['overtime'] += $detail['overtime_pay'];
    $totals['bonus'] += $detail['bonus'];
    $totals['gross'] += $detail['gross_salary'];
    $totals['tax'] += $detail['tax_amount'];
    $totals['nssf'] += $detail['nssf_amount'];
    $totals['nhif'] += $detail['nhif_amount'];
    $totals['loans'] += $detail['loan_deduction'];
    $totals['other'] += $detail['other_deductions'];
    $totals['deductions'] += $detail['total_deductions'];
    $totals['net'] += $detail['net_salary'];
}

// Group by department for summary
$dept_summary = [];
foreach ($payroll_details as $detail) {
    $dept = $detail['department_name'] ?? 'No Department';
    if (!isset($dept_summary[$dept])) {
        $dept_summary[$dept] = [
            'count' => 0,
            'gross' => 0,
            'deductions' => 0,
            'net' => 0
        ];
    }
    $dept_summary[$dept]['count']++;
    $dept_summary[$dept]['gross'] += $detail['gross_salary'];
    $dept_summary[$dept]['deductions'] += $detail['total_deductions'];
    $dept_summary[$dept]['net'] += $detail['net_salary'];
}

$page_title = 'Payroll Report';
require_once '../../includes/header.php';
?>

<style>
.report-container {
    max-width: 1200px;
    margin: 0 auto;
}

.report-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 8px 32px rgba(102, 126, 234, 0.3);
}

.summary-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.summary-item {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
    text-align: center;
}

.summary-item .label {
    font-size: 0.85rem;
    color: #6c757d;
    text-transform: uppercase;
    margin-bottom: 0.5rem;
}

.summary-item .value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #212529;
}

.summary-item.gross .value {
    color: #007bff;
}

.summary-item.deduction .value {
    color: #dc3545;
}

.summary-item.net .value {
    color: #28a745;
}

.table-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.table {
    margin-bottom: 0;
    font-size: 0.9rem;
}

.table thead {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
}

.table thead th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    color: #495057;
    padding: 0.75rem 0.5rem;
    border-bottom: 2px solid #dee2e6;
}

.table tbody td {
    padding: 0.75rem 0.5rem;
    vertical-align: middle;
}

.table tfoot {
    background: #e9ecef;
    font-weight: 700;
}

@media print {
    .content-header, .btn, .no-print {
        display: none !important;
    }
    
    .report-container {
        max-width: 100%;
    }
    
    .table {
        font-size: 8pt;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4 no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-alt text-primary me-2"></i>
                    Payroll Report
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Comprehensive payroll report for <?php echo $period_name; ?>
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print me-1"></i> Print Report
                    </button>
                    <a href="export-payroll.php?id=<?php echo $payroll_id; ?>" class="btn btn-success">
                        <i class="fas fa-file-excel me-1"></i> Export Excel
                    </a>
                    <a href="payroll.php?month=<?php echo $payroll['payroll_month']; ?>&year=<?php echo $payroll['payroll_year']; ?>" 
                       class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        <div class="report-container">
            
            <!-- Report Header -->
            <div class="report-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h2 class="mb-2"><?php echo htmlspecialchars($payroll['company_name']); ?></h2>
                        <h3 class="mb-0">Payroll Report - <?php echo $period_name; ?></h3>
                        <p class="mb-0 mt-2 opacity-75">
                            Generated on <?php echo date('F j, Y g:i A'); ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="badge bg-light text-dark px-3 py-2" style="font-size: 1rem;">
                            Status: <strong><?php echo ucfirst($payroll['status']); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="summary-card">
                <h5 class="mb-3 fw-bold">
                    <i class="fas fa-chart-bar me-2"></i>Payroll Summary
                </h5>
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="label">Employees</div>
                        <div class="value"><?php echo number_format($totals['count']); ?></div>
                    </div>
                    <div class="summary-item gross">
                        <div class="label">Gross Payroll</div>
                        <div class="value">TSH <?php echo number_format($totals['gross'], 0); ?></div>
                    </div>
                    <div class="summary-item deduction">
                        <div class="label">Total Deductions</div>
                        <div class="value">TSH <?php echo number_format($totals['deductions'], 0); ?></div>
                    </div>
                    <div class="summary-item net">
                        <div class="label">Net Payroll</div>
                        <div class="value">TSH <?php echo number_format($totals['net'], 0); ?></div>
                    </div>
                </div>
            </div>

            <!-- Department Summary -->
            <?php if (count($dept_summary) > 1): ?>
            <div class="summary-card">
                <h5 class="mb-3 fw-bold">
                    <i class="fas fa-building me-2"></i>Department Summary
                </h5>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th class="text-center">Employees</th>
                                <th class="text-end">Gross Salary</th>
                                <th class="text-end">Deductions</th>
                                <th class="text-end">Net Salary</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dept_summary as $dept => $summary): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dept); ?></td>
                                <td class="text-center"><?php echo $summary['count']; ?></td>
                                <td class="text-end">TSH <?php echo number_format($summary['gross'], 0); ?></td>
                                <td class="text-end">TSH <?php echo number_format($summary['deductions'], 0); ?></td>
                                <td class="text-end fw-bold">TSH <?php echo number_format($summary['net'], 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detailed Payroll Table -->
            <div class="table-container">
                <div class="p-3 bg-light border-bottom">
                    <h5 class="mb-0 fw-bold">
                        <i class="fas fa-list me-2"></i>Detailed Payroll Breakdown
                    </h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="12%">Employee</th>
                                <th width="10%">Department</th>
                                <th width="8%" class="text-end">Basic</th>
                                <th width="7%" class="text-end">Allow.</th>
                                <th width="7%" class="text-end">O.Time</th>
                                <th width="7%" class="text-end">Bonus</th>
                                <th width="8%" class="text-end">Gross</th>
                                <th width="7%" class="text-end">Tax</th>
                                <th width="6%" class="text-end">NSSF</th>
                                <th width="6%" class="text-end">NHIF</th>
                                <th width="7%" class="text-end">Deduc.</th>
                                <th width="10%" class="text-end">Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $row = 1;
                            foreach ($payroll_details as $detail): 
                            ?>
                            <tr>
                                <td><?php echo $row++; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($detail['full_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($detail['employee_number']); ?></small>
                                </td>
                                <td><small><?php echo htmlspecialchars($detail['department_name'] ?? 'N/A'); ?></small></td>
                                <td class="text-end"><?php echo number_format($detail['basic_salary'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($detail['allowances'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($detail['overtime_pay'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($detail['bonus'], 0); ?></td>
                                <td class="text-end fw-semibold text-primary"><?php echo number_format($detail['gross_salary'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($detail['tax_amount'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($detail['nssf_amount'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($detail['nhif_amount'], 0); ?></td>
                                <td class="text-end fw-semibold text-danger"><?php echo number_format($detail['total_deductions'], 0); ?></td>
                                <td class="text-end fw-bold text-success"><?php echo number_format($detail['net_salary'], 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3">TOTALS (<?php echo $totals['count']; ?> employees)</th>
                                <th class="text-end"><?php echo number_format($totals['basic'], 0); ?></th>
                                <th class="text-end"><?php echo number_format($totals['allowances'], 0); ?></th>
                                <th class="text-end"><?php echo number_format($totals['overtime'], 0); ?></th>
                                <th class="text-end"><?php echo number_format($totals['bonus'], 0); ?></th>
                                <th class="text-end text-primary"><?php echo number_format($totals['gross'], 0); ?></th>
                                <th class="text-end"><?php echo number_format($totals['tax'], 0); ?></th>
                                <th class="text-end"><?php echo number_format($totals['nssf'], 0); ?></th>
                                <th class="text-end"><?php echo number_format($totals['nhif'], 0); ?></th>
                                <th class="text-end text-danger"><?php echo number_format($totals['deductions'], 0); ?></th>
                                <th class="text-end text-success"><?php echo number_format($totals['net'], 0); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Report Footer -->
            <div class="summary-card text-center">
                <p class="text-muted mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    This is a system-generated report. For any discrepancies, please contact the HR department.
                </p>
            </div>

        </div>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>