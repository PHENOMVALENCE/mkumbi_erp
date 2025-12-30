<?php
define('APP_ACCESS', true);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

$errors = [];
$success = [];

// Handle payroll generation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_payroll') {
    $conn->beginTransaction();
    
    try {
        $payroll_month = intval($_POST['payroll_month']);
        $payroll_year = intval($_POST['payroll_year']);
        $payment_date = $_POST['payment_date'];
        $selected_employees = $_POST['employees'] ?? [];
        
        // Validate inputs
        if (empty($payroll_month) || empty($payroll_year) || empty($payment_date)) {
            $errors[] = "Month, year, and payment date are required";
        } elseif (empty($selected_employees)) {
            $errors[] = "Please select at least one employee";
        } else {
            // Check if payroll already exists for this period
            $check_sql = "SELECT payroll_id FROM payroll 
                         WHERE company_id = ? AND payroll_month = ? AND payroll_year = ? 
                         AND status != 'cancelled'";
            $stmt = $conn->prepare($check_sql);
            $stmt->execute([$company_id, $payroll_month, $payroll_year]);
            
            if ($stmt->fetch()) {
                $errors[] = "Payroll for this period already exists";
            } else {
                // Insert payroll header with DRAFT status (pending approval)
                $insert_payroll_sql = "INSERT INTO payroll 
                                      (company_id, payroll_month, payroll_year, payment_date, 
                                       status, created_by, created_at)
                                      VALUES (?, ?, ?, ?, 'draft', ?, NOW())";
                
                $stmt = $conn->prepare($insert_payroll_sql);
                $stmt->execute([$company_id, $payroll_month, $payroll_year, $payment_date, $_SESSION['user_id']]);
                
                $payroll_id = $conn->lastInsertId();
                
                // Insert payroll details for each selected employee
                $insert_detail_sql = "INSERT INTO payroll_details 
                                     (payroll_id, employee_id, basic_salary, allowances, 
                                      overtime_pay, bonus, tax_amount, nssf_amount, 
                                      nhif_amount, loan_deduction, other_deductions, 
                                      payment_status, created_at)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
                
                $stmt = $conn->prepare($insert_detail_sql);
                
                foreach ($selected_employees as $employee_id) {
                    $basic_salary = floatval($_POST['basic_salary_' . $employee_id] ?? 0);
                    $allowances = floatval($_POST['allowances_' . $employee_id] ?? 0);
                    $overtime_pay = floatval($_POST['overtime_' . $employee_id] ?? 0);
                    $bonus = floatval($_POST['bonus_' . $employee_id] ?? 0);
                    $tax_amount = floatval($_POST['tax_' . $employee_id] ?? 0);
                    $nssf_amount = floatval($_POST['nssf_' . $employee_id] ?? 0);
                    $nhif_amount = floatval($_POST['nhif_' . $employee_id] ?? 0);
                    $loan_deduction = floatval($_POST['loan_' . $employee_id] ?? 0);
                    $other_deductions = floatval($_POST['other_deductions_' . $employee_id] ?? 0);
                    
                    $stmt->execute([
                        $payroll_id,
                        $employee_id,
                        $basic_salary,
                        $allowances,
                        $overtime_pay,
                        $bonus,
                        $tax_amount,
                        $nssf_amount,
                        $nhif_amount,
                        $loan_deduction,
                        $other_deductions
                    ]);
                }
                
                $conn->commit();
                
                $month_name = date('F', mktime(0, 0, 0, $payroll_month, 1));
                $success[] = "Payroll for <strong>$month_name $payroll_year</strong> generated successfully! Waiting for approval.";
            }
        }
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $errors[] = "Error generating payroll: " . $e->getMessage();
    }
}

// Fetch active employees with their salary information
try {
    $employees_sql = "SELECT 
                        e.employee_id,
                        e.employee_number,
                        e.basic_salary,
                        e.allowances,
                        u.full_name,
                        u.email,
                        u.phone1 as phone,
                        d.department_name,
                        p.position_title,
                        e.employment_status,
                        e.bank_name,
                        e.account_number
                     FROM employees e
                     INNER JOIN users u ON e.user_id = u.user_id
                     LEFT JOIN departments d ON e.department_id = d.department_id
                     LEFT JOIN positions p ON e.position_id = p.position_id
                     WHERE e.company_id = ? 
                     AND e.employment_status = 'active'
                     AND e.is_active = 1
                     ORDER BY u.full_name ASC";
    
    $stmt = $conn->prepare($employees_sql);
    $stmt->execute([$company_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $employees = [];
    $errors[] = "Error fetching employees: " . $e->getMessage();
}

// Fetch recent payroll records
try {
    $payroll_sql = "SELECT 
                        pr.payroll_id,
                        pr.payroll_month,
                        pr.payroll_year,
                        pr.payment_date,
                        pr.status,
                        pr.created_at,
                        COUNT(pd.payroll_detail_id) as employee_count,
                        SUM(pd.gross_salary) as total_gross,
                        SUM(pd.total_deductions) as total_deductions,
                        SUM(pd.net_salary) as total_net,
                        u.full_name as created_by_name
                    FROM payroll pr
                    LEFT JOIN payroll_details pd ON pr.payroll_id = pd.payroll_id
                    LEFT JOIN users u ON pr.created_by = u.user_id
                    WHERE pr.company_id = ?
                    GROUP BY pr.payroll_id
                    ORDER BY pr.payroll_year DESC, pr.payroll_month DESC
                    LIMIT 10";
    
    $stmt = $conn->prepare($payroll_sql);
    $stmt->execute([$company_id]);
    $payroll_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $payroll_records = [];
}

$page_title = 'Generate Payroll';
require_once '../../includes/header.php';
?>

<style>
.form-card {
    background: white;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.employee-card {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
    border-left: 4px solid #667eea;
}

.employee-card:hover {
    background: #e9ecef;
}

.salary-input {
    width: 100%;
    max-width: 150px;
}

.calculated-field {
    background-color: #e9ecef;
    font-weight: bold;
}

.table-container {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    overflow: hidden;
    margin-top: 30px;
}

.badge-draft {
    background: #ffc107;
    color: #000;
}

.badge-processed {
    background: #28a745;
    color: #fff;
}

.badge-paid {
    background: #17a2b8;
    color: #fff;
}

.badge-cancelled {
    background: #dc3545;
    color: #fff;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-money-check-alt"></i> Generate Payroll</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="../approvals/pending.php">Approvals</a></li>
                    <li class="breadcrumb-item active">Generate Payroll</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-ban"></i> Error!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-check-circle"></i> Success!</h5>
            <ul class="mb-0">
                <?php foreach ($success as $msg): ?>
                    <li><?php echo $msg; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (empty($employees)): ?>
        <div class="alert alert-warning">
            <h5><i class="fas fa-exclamation-triangle"></i> No Active Employees</h5>
            <p class="mb-0">There are no active employees to generate payroll. Please add employees first.</p>
        </div>
        <?php else: ?>

        <div class="form-card">
            <form method="POST" id="payrollForm">
                <input type="hidden" name="action" value="generate_payroll">

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Note:</strong> Payroll will be submitted for approval before processing payments.
                </div>

                <!-- Payroll Period Section -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Payroll Month <span class="text-danger">*</span></label>
                        <select name="payroll_month" class="form-select" required>
                            <option value="">-- Select Month --</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo (date('n') == $m) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Payroll Year <span class="text-danger">*</span></label>
                        <select name="payroll_year" class="form-select" required>
                            <option value="">-- Select Year --</option>
                            <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo (date('Y') == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="fas fa-users"></i> Employees (<?php echo count($employees); ?>)</h5>
                    <div>
                        <button type="button" class="btn btn-sm btn-primary" onclick="selectAll()">
                            <i class="fas fa-check-double"></i> Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="deselectAll()">
                            <i class="fas fa-times"></i> Deselect All
                        </button>
                    </div>
                </div>

                <!-- Employees List -->
                <div id="employeesList">
                    <?php foreach ($employees as $employee): ?>
                    <div class="employee-card">
                        <div class="row align-items-center">
                            <div class="col-md-1">
                                <div class="form-check">
                                    <input class="form-check-input employee-checkbox" type="checkbox" 
                                           name="employees[]" value="<?php echo $employee['employee_id']; ?>" 
                                           id="emp_<?php echo $employee['employee_id']; ?>"
                                           onchange="toggleEmployeeInputs(<?php echo $employee['employee_id']; ?>)">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-check-label fw-bold" for="emp_<?php echo $employee['employee_id']; ?>">
                                    <?php echo htmlspecialchars($employee['full_name']); ?>
                                </label>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($employee['employee_number']); ?></small>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($employee['department_name'] ?? 'No Department'); ?></small>
                            </div>

                            <div class="col-md-8">
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <label class="form-label small">Basic Salary</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm salary-input" 
                                               name="basic_salary_<?php echo $employee['employee_id']; ?>"
                                               id="basic_<?php echo $employee['employee_id']; ?>"
                                               value="<?php echo $employee['basic_salary']; ?>"
                                               onchange="calculateSalary(<?php echo $employee['employee_id']; ?>)"
                                               disabled>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">Allowances</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm salary-input" 
                                               name="allowances_<?php echo $employee['employee_id']; ?>"
                                               id="allowances_<?php echo $employee['employee_id']; ?>"
                                               value="<?php echo $employee['allowances']; ?>"
                                               onchange="calculateSalary(<?php echo $employee['employee_id']; ?>)"
                                               disabled>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">Overtime</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm salary-input" 
                                               name="overtime_<?php echo $employee['employee_id']; ?>"
                                               id="overtime_<?php echo $employee['employee_id']; ?>"
                                               value="0"
                                               onchange="calculateSalary(<?php echo $employee['employee_id']; ?>)"
                                               disabled>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">Bonus</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm salary-input" 
                                               name="bonus_<?php echo $employee['employee_id']; ?>"
                                               id="bonus_<?php echo $employee['employee_id']; ?>"
                                               value="0"
                                               onchange="calculateSalary(<?php echo $employee['employee_id']; ?>)"
                                               disabled>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small">Gross Salary</label>
                                        <input type="text" class="form-control form-control-sm calculated-field" 
                                               id="gross_<?php echo $employee['employee_id']; ?>"
                                               value="<?php echo number_format($employee['basic_salary'] + $employee['allowances'], 2); ?>"
                                               readonly>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">Tax (PAYE)</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm salary-input" 
                                               name="tax_<?php echo $employee['employee_id']; ?>"
                                               id="tax_<?php echo $employee['employee_id']; ?>"
                                               value="0"
                                               onchange="calculateSalary(<?php echo $employee['employee_id']; ?>)"
                                               disabled>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">NSSF</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm salary-input" 
                                               name="nssf_<?php echo $employee['employee_id']; ?>"
                                               id="nssf_<?php echo $employee['employee_id']; ?>"
                                               value="0"
                                               onchange="calculateSalary(<?php echo $employee['employee_id']; ?>)"
                                               disabled>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">NHIF</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm salary-input" 
                                               name="nhif_<?php echo $employee['employee_id']; ?>"
                                               id="nhif_<?php echo $employee['employee_id']; ?>"
                                               value="0"
                                               onchange="calculateSalary(<?php echo $employee['employee_id']; ?>)"
                                               disabled>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">Loan</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm salary-input" 
                                               name="loan_<?php echo $employee['employee_id']; ?>"
                                               id="loan_<?php echo $employee['employee_id']; ?>"
                                               value="0"
                                               onchange="calculateSalary(<?php echo $employee['employee_id']; ?>)"
                                               disabled>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">Other Deductions</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm salary-input" 
                                               name="other_deductions_<?php echo $employee['employee_id']; ?>"
                                               id="other_deductions_<?php echo $employee['employee_id']; ?>"
                                               value="0"
                                               onchange="calculateSalary(<?php echo $employee['employee_id']; ?>)"
                                               disabled>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small fw-bold">Net Salary</label>
                                        <input type="text" class="form-control form-control-sm calculated-field text-success" 
                                               id="net_<?php echo $employee['employee_id']; ?>"
                                               value="<?php echo number_format($employee['basic_salary'] + $employee['allowances'], 2); ?>"
                                               readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Summary Section -->
                <div class="alert alert-success mt-3" id="payrollSummary" style="display: none;">
                    <h6 class="fw-bold"><i class="fas fa-calculator"></i> Payroll Summary</h6>
                    <div class="row">
                        <div class="col-md-3">
                            <p class="mb-1"><strong>Selected Employees:</strong> <span id="summary_count">0</span></p>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-1"><strong>Total Gross:</strong> <span id="summary_gross">TZS 0</span></p>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-1"><strong>Total Deductions:</strong> <span id="summary_deductions">TZS 0</span></p>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-1"><strong>Total Net:</strong> <span id="summary_net" class="text-success">TZS 0</span></p>
                        </div>
                    </div>
                </div>

                <div class="text-end mt-4">
                    <a href="../approvals/pending.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Submit for Approval
                    </button>
                </div>
            </form>
        </div>

        <?php endif; ?>

        <!-- Recent Payroll Records -->
        <?php if (!empty($payroll_records)): ?>
        <div class="table-container mt-4">
            <div class="p-3 border-bottom">
                <h5 class="mb-0"><i class="fas fa-history"></i> Recent Payroll Records</h5>
            </div>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Employees</th>
                        <th>Gross Amount</th>
                        <th>Deductions</th>
                        <th>Net Amount</th>
                        <th>Payment Date</th>
                        <th>Status</th>
                        <th>Created By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payroll_records as $record): ?>
                    <tr>
                        <td>
                            <strong>
                                <?php echo date('F Y', mktime(0, 0, 0, $record['payroll_month'], 1, $record['payroll_year'])); ?>
                            </strong>
                        </td>
                        <td><?php echo $record['employee_count']; ?> employees</td>
                        <td>TZS <?php echo number_format($record['total_gross'], 2); ?></td>
                        <td class="text-danger">TZS <?php echo number_format($record['total_deductions'], 2); ?></td>
                        <td class="text-success"><strong>TZS <?php echo number_format($record['total_net'], 2); ?></strong></td>
                        <td><?php echo date('M d, Y', strtotime($record['payment_date'])); ?></td>
                        <td>
                            <?php
                            $status_classes = [
                                'draft' => 'badge-draft',
                                'processed' => 'badge-processed',
                                'paid' => 'badge-paid',
                                'cancelled' => 'badge-cancelled'
                            ];
                            $badge_class = $status_classes[$record['status']] ?? 'badge-secondary';
                            ?>
                            <span class="badge <?php echo $badge_class; ?>">
                                <?php echo strtoupper($record['status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($record['created_by_name']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleEmployeeInputs(employeeId) {
    const checkbox = document.getElementById('emp_' + employeeId);
    const inputs = [
        'basic_' + employeeId,
        'allowances_' + employeeId,
        'overtime_' + employeeId,
        'bonus_' + employeeId,
        'tax_' + employeeId,
        'nssf_' + employeeId,
        'nhif_' + employeeId,
        'loan_' + employeeId,
        'other_deductions_' + employeeId
    ];
    
    inputs.forEach(function(inputId) {
        const input = document.getElementById(inputId);
        if (input) {
            input.disabled = !checkbox.checked;
        }
    });
    
    if (checkbox.checked) {
        calculateSalary(employeeId);
    }
    
    updateSummary();
}

function calculateSalary(employeeId) {
    const basic = parseFloat(document.getElementById('basic_' + employeeId).value) || 0;
    const allowances = parseFloat(document.getElementById('allowances_' + employeeId).value) || 0;
    const overtime = parseFloat(document.getElementById('overtime_' + employeeId).value) || 0;
    const bonus = parseFloat(document.getElementById('bonus_' + employeeId).value) || 0;
    
    const gross = basic + allowances + overtime + bonus;
    document.getElementById('gross_' + employeeId).value = gross.toFixed(2);
    
    const tax = parseFloat(document.getElementById('tax_' + employeeId).value) || 0;
    const nssf = parseFloat(document.getElementById('nssf_' + employeeId).value) || 0;
    const nhif = parseFloat(document.getElementById('nhif_' + employeeId).value) || 0;
    const loan = parseFloat(document.getElementById('loan_' + employeeId).value) || 0;
    const otherDeductions = parseFloat(document.getElementById('other_deductions_' + employeeId).value) || 0;
    
    const totalDeductions = tax + nssf + nhif + loan + otherDeductions;
    const net = gross - totalDeductions;
    
    document.getElementById('net_' + employeeId).value = net.toFixed(2);
    
    updateSummary();
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.employee-checkbox');
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = true;
        const employeeId = checkbox.value;
        toggleEmployeeInputs(employeeId);
    });
}

function deselectAll() {
    const checkboxes = document.querySelectorAll('.employee-checkbox');
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = false;
        const employeeId = checkbox.value;
        toggleEmployeeInputs(employeeId);
    });
}

function updateSummary() {
    const checkboxes = document.querySelectorAll('.employee-checkbox:checked');
    let count = 0;
    let totalGross = 0;
    let totalNet = 0;
    
    checkboxes.forEach(function(checkbox) {
        const employeeId = checkbox.value;
        const gross = parseFloat(document.getElementById('gross_' + employeeId).value) || 0;
        const net = parseFloat(document.getElementById('net_' + employeeId).value) || 0;
        
        count++;
        totalGross += gross;
        totalNet += net;
    });
    
    const totalDeductions = totalGross - totalNet;
    
    if (count > 0) {
        document.getElementById('summary_count').textContent = count;
        document.getElementById('summary_gross').textContent = 'TZS ' + totalGross.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_deductions').textContent = 'TZS ' + totalDeductions.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_net').textContent = 'TZS ' + totalNet.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('payrollSummary').style.display = 'block';
    } else {
        document.getElementById('payrollSummary').style.display = 'none';
    }
}

// Auto-dismiss alerts
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        bsAlert.close();
    });
}, 5000);
</script>