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
$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');
$department_id = $_GET['department_id'] ?? '';

// Fetch payroll data
$payroll_data = [];
$total_gross = $total_deductions = $total_net = 0;

try {
    $query = "
        SELECT 
            pd.*,
            e.employee_number,
            u.full_name,
            d.department_name,
            p.payment_date,
            p.status as payroll_status
        FROM payroll_details pd
        INNER JOIN payroll p ON pd.payroll_id = p.payroll_id
        INNER JOIN employees e ON pd.employee_id = e.employee_id
        INNER JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE p.company_id = ?
        AND p.payroll_month = ?
        AND p.payroll_year = ?
    ";
    
    $params = [$company_id, $month, $year];
    
    if ($department_id) {
        $query .= " AND e.department_id = ?";
        $params[] = $department_id;
    }
    
    $query .= " ORDER BY d.department_name, u.full_name";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $payroll_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    foreach ($payroll_data as $row) {
        $total_gross += $row['gross_salary'];
        $total_deductions += $row['total_deductions'];
        $total_net += $row['net_salary'];
    }
    
} catch (Exception $e) {
    error_log("Payroll query error: " . $e->getMessage());
}

// Get departments for filter
$departments = [];
try {
    $stmt = $conn->prepare("SELECT department_id, department_name FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY department_name");
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Departments query error: " . $e->getMessage());
}

$page_title = 'Payroll Summary Report';
require_once '../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .content-wrapper { margin: 0 !important; }
    .table { font-size: 11px; }
}

.report-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    border-left: 4px solid;
}

.summary-card.primary { border-left-color: #007bff; }
.summary-card.success { border-left-color: #28a745; }
.summary-card.danger { border-left-color: #dc3545; }

.summary-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
}

.summary-label {
    font-size: 0.875rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table-professional {
    font-size: 0.9rem;
}

.table-professional thead th {
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    padding: 0.75rem 0.5rem;
    border-bottom: 2px solid #dee2e6;
}

.table-professional tbody td {
    padding: 0.65rem 0.5rem;
    vertical-align: middle;
}

.table-professional tbody tr:hover {
    background-color: #f8f9fa;
}

.department-header {
    background: #e9ecef;
    font-weight: 600;
    color: #495057;
}

.totals-row {
    background: #f8f9fa;
    font-weight: 700;
    border-top: 2px solid #dee2e6;
}
</style>

<div class="content-header no-print">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Payroll Summary Report</h1>
            </div>
            <div class="col-sm-6 text-end">
                <button onclick="window.print()" class="btn btn-primary btn-sm">
                    <i class="fas fa-print me-1"></i>Print Report
                </button>
                <button onclick="exportToExcel()" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i>Export Excel
                </button>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Reports
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Report Header -->
    <div class="report-header">
        <h2 class="mb-1">Payroll Summary Report</h2>
        <p class="mb-0">Period: <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></p>
        <p class="mb-0"><small>Generated: <?= date('d M Y, h:i A') ?></small></p>
    </div>

    <!-- Filters -->
    <div class="card no-print mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Month</label>
                    <select name="month" class="form-select form-select-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>>
                                <?= date('F', mktime(0,0,0,$m,1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Year</label>
                    <select name="year" class="form-select form-select-sm">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Department</label>
                    <select name="department_id" class="form-select form-select-sm">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" <?= $department_id == $dept['department_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card primary">
            <div class="summary-value">TSH <?= number_format($total_gross, 2) ?></div>
            <div class="summary-label">Total Gross Pay</div>
        </div>
        <div class="summary-card danger">
            <div class="summary-value">TSH <?= number_format($total_deductions, 2) ?></div>
            <div class="summary-label">Total Deductions</div>
        </div>
        <div class="summary-card success">
            <div class="summary-value">TSH <?= number_format($total_net, 2) ?></div>
            <div class="summary-label">Total Net Pay</div>
        </div>
        <div class="summary-card primary">
            <div class="summary-value"><?= count($payroll_data) ?></div>
            <div class="summary-label">Employees</div>
        </div>
    </div>

    <!-- Payroll Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($payroll_data)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No payroll data found for the selected period</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="payrollTable">
                        <thead>
                            <tr>
                                <th>Emp. No.</th>
                                <th>Employee Name</th>
                                <th>Department</th>
                                <th class="text-end">Basic Salary</th>
                                <th class="text-end">Allowances</th>
                                <th class="text-end">Overtime</th>
                                <th class="text-end">Bonus</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">Tax</th>
                                <th class="text-end">NSSF</th>
                                <th class="text-end">Loans</th>
                                <th class="text-end">Other</th>
                                <th class="text-end">Total Deductions</th>
                                <th class="text-end">Net Pay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $current_dept = '';
                            foreach ($payroll_data as $row): 
                                // Department header
                                if ($current_dept != $row['department_name']):
                                    $current_dept = $row['department_name'];
                            ?>
                                <tr class="department-header">
                                    <td colspan="14">
                                        <i class="fas fa-sitemap me-2"></i><?= htmlspecialchars($current_dept ?: 'No Department') ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            
                            <tr>
                                <td><?= htmlspecialchars($row['employee_number']) ?></td>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['department_name'] ?: '-') ?></td>
                                <td class="text-end"><?= number_format($row['basic_salary'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['allowances'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['overtime_pay'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['bonus'], 2) ?></td>
                                <td class="text-end fw-bold"><?= number_format($row['gross_salary'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['tax_amount'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['nssf_amount'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['loan_deduction'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['other_deductions'], 2) ?></td>
                                <td class="text-end fw-bold"><?= number_format($row['total_deductions'], 2) ?></td>
                                <td class="text-end fw-bold text-success"><?= number_format($row['net_salary'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <!-- Totals Row -->
                            <tr class="totals-row">
                                <td colspan="7" class="text-end">TOTALS:</td>
                                <td class="text-end"><?= number_format($total_gross, 2) ?></td>
                                <td colspan="4"></td>
                                <td class="text-end"><?= number_format($total_deductions, 2) ?></td>
                                <td class="text-end text-success"><?= number_format($total_net, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
function exportToExcel() {
    // Prepare data for Excel
    const data = [];
    
    // Add header with company info
    data.push(['PAYROLL SUMMARY REPORT']);
    data.push(['Period: <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>']);
    data.push(['Generated: <?= date('d M Y, h:i A') ?>']);
    data.push([]);
    
    // Add summary
    data.push(['SUMMARY']);
    data.push(['Total Gross Pay', 'TSH <?= number_format($total_gross, 2) ?>']);
    data.push(['Total Deductions', 'TSH <?= number_format($total_deductions, 2) ?>']);
    data.push(['Total Net Pay', 'TSH <?= number_format($total_net, 2) ?>']);
    data.push(['Number of Employees', '<?= count($payroll_data) ?>']);
    data.push([]);
    
    // Add table headers
    data.push([
        'Emp. No.', 'Employee Name', 'Department', 'Basic Salary', 'Allowances', 
        'Overtime', 'Bonus', 'Gross', 'Tax', 'NSSF', 'NHIF', 'Loans', 
        'Other Deductions', 'Total Deductions', 'Net Pay'
    ]);
    
    <?php foreach ($payroll_data as $row): ?>
    data.push([
        '<?= $row['employee_number'] ?>',
        '<?= addslashes($row['full_name']) ?>',
        '<?= addslashes($row['department_name'] ?: '-') ?>',
        <?= $row['basic_salary'] ?>,
        <?= $row['allowances'] ?>,
        <?= $row['overtime_pay'] ?>,
        <?= $row['bonus'] ?>,
        <?= $row['gross_salary'] ?>,
        <?= $row['tax_amount'] ?>,
        <?= $row['nssf_amount'] ?>,
        <?= $row['nhif_amount'] ?>,
        <?= $row['loan_deduction'] ?>,
        <?= $row['other_deductions'] ?>,
        <?= $row['total_deductions'] ?>,
        <?= $row['net_salary'] ?>
    ]);
    <?php endforeach; ?>
    
    // Add totals row
    data.push([]);
    data.push([
        '', '', '', '', '', '', 'TOTALS',
        <?= $total_gross ?>,
        '', '', '', '',
        <?= $total_deductions ?>,
        '',
        <?= $total_net ?>
    ]);
    
    // Create workbook
    const ws = XLSX.utils.aoa_to_sheet(data);
    
    // Set column widths
    ws['!cols'] = [
        {wch: 12}, {wch: 25}, {wch: 20}, {wch: 12}, {wch: 12},
        {wch: 12}, {wch: 12}, {wch: 15}, {wch: 12}, {wch: 12},
        {wch: 12}, {wch: 12}, {wch: 12}, {wch: 15}, {wch: 15}
    ];
    
    // Create workbook and add worksheet
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Payroll Summary');
    
    // Save file
    XLSX.writeFile(wb, 'Payroll_Report_<?= date('Y-m', mktime(0,0,0,$month,1,$year)) ?>.xlsx');
}
</script>

<?php require_once '../../includes/footer.php'; ?>