<?php
define('APP_ACCESS', true);
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

$page_title = "Leave Balance";
require_once '../../includes/header.php';
?>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-chart-bar text-primary me-2"></i>
                    Leave Balance Report
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    View leave balances and entitlements
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Leave
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

<?php
// Get current fiscal/leave year
$settings_query = "SELECT setting_value FROM system_settings WHERE company_id = ? AND setting_key = 'leave_year_start'";
$settings_stmt = $conn->prepare($settings_query);
$settings_stmt->execute([$company_id]);
$settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

$leave_year_start = isset($settings['setting_value']) ? intval($settings['setting_value']) : 1;
$current_month = date('n');
$current_year = date('Y');

// Calculate fiscal year
if ($current_month >= $leave_year_start) {
    $fiscal_year = $current_year;
} else {
    $fiscal_year = $current_year - 1;
}

// Get filter parameters
$filter_employee = isset($_GET['employee']) ? intval($_GET['employee']) : 0;
$filter_department = isset($_GET['department']) ? intval($_GET['department']) : 0;

// Get leave balances - Calculate from leave_applications
// Get all employees with their leave types and entitlements
$base_query = "SELECT e.employee_id, u.full_name, e.employee_number, d.department_name,
                 lt.leave_type_id, lt.leave_type_name, lt.days_per_year as entitled_days
          FROM employees e
          JOIN users u ON e.user_id = u.user_id
          LEFT JOIN departments d ON e.department_id = d.department_id
          CROSS JOIN leave_types lt
          WHERE e.company_id = ? AND e.is_active = 1 AND lt.company_id = ? AND lt.is_active = 1";

$base_params = [$company_id, $company_id];

if ($filter_employee) {
    $base_query .= " AND e.employee_id = ?";
    $base_params[] = $filter_employee;
}

if ($filter_department) {
    $base_query .= " AND e.department_id = ?";
    $base_params[] = $filter_department;
}

$base_query .= " ORDER BY u.full_name, lt.leave_type_name";

$stmt = $conn->prepare($base_query);
$stmt->execute($base_params);
$employee_leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate used days for each employee and leave type
$balances = [];
foreach ($employee_leave_types as $elt) {
    $used_query = "SELECT COALESCE(SUM(total_days), 0) as used_days
                   FROM leave_applications
                   WHERE employee_id = ? AND leave_type_id = ? 
                   AND company_id = ? AND status = 'approved'
                   AND YEAR(start_date) = ?";
    $used_stmt = $conn->prepare($used_query);
    $used_stmt->execute([$elt['employee_id'], $elt['leave_type_id'], $company_id, $fiscal_year]);
    $used_result = $used_stmt->fetch(PDO::FETCH_ASSOC);
    
    $used_days = floatval($used_result['used_days'] ?? 0);
    $entitled_days = floatval($elt['entitled_days']);
    $balance = $entitled_days - $used_days;
    
    $balances[] = [
        'employee_id' => $elt['employee_id'],
        'full_name' => $elt['full_name'],
        'employee_number' => $elt['employee_number'],
        'department_name' => $elt['department_name'],
        'leave_type_name' => $elt['leave_type_name'],
        'entitled_days' => $entitled_days,
        'used_days' => $used_days,
        'carried_forward' => 0, // Can be calculated if needed
        'balance' => $balance
    ];
}

// Get employees for filter
$emp_query = "SELECT e.employee_id, u.full_name, e.employee_number 
              FROM employees e
              JOIN users u ON e.user_id = u.user_id
              WHERE e.company_id = ? AND e.is_active = 1 
              ORDER BY u.full_name";
$emp_stmt = $conn->prepare($emp_query);
$emp_stmt->execute([$company_id]);
$employees = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filter
$dept_query = "SELECT department_id, department_name FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY department_name";
$dept_stmt = $conn->prepare($dept_query);
$dept_stmt->execute([$company_id]);
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

        <div class="row mb-4">
            <div class="col-md-12">
                <p class="text-muted"><strong>Fiscal Year:</strong> <?php echo $fiscal_year; ?></p>
            </div>
        </div>

    <!-- Filters -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Employee</label>
                    <select name="employee" class="form-select">
                        <option value="">All Employees</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['employee_id']; ?>" <?php echo $filter_employee == $emp['employee_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['full_name'] . ' (' . $emp['employee_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select name="department" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>" <?php echo $filter_department == $dept['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Balance Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Leave Type</th>
                        <th>Entitled Days</th>
                        <th>Used Days</th>
                        <th>Carried Forward</th>
                        <th>Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($balances) > 0): ?>
                        <?php 
                        $last_employee = '';
                        foreach ($balances as $balance): 
                        ?>
                            <tr>
                                <td>
                                    <?php 
                                    if ($balance['full_name'] != $last_employee) {
                                        echo htmlspecialchars($balance['full_name']);
                                        $last_employee = $balance['full_name'];
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($balance['department_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($balance['leave_type_name']); ?></td>
                                <td class="text-center"><?php echo number_format($balance['entitled_days'] ?? 0, 1); ?></td>
                                <td class="text-center"><?php echo number_format($balance['used_days'] ?? 0, 1); ?></td>
                                <td class="text-center"><?php echo number_format($balance['carried_forward'] ?? 0, 1); ?></td>
                                <td class="text-center">
                                    <strong><?php echo number_format($balance['balance'] ?? 0, 1); ?> days</strong>
                                </td>
                                <td>
                                    <?php 
                                    $remaining = floatval($balance['balance'] ?? 0);
                                    if ($remaining > 0) {
                                        $status = 'success';
                                        $text = 'Available';
                                    } elseif ($remaining == 0) {
                                        $status = 'warning';
                                        $text = 'Exhausted';
                                    } else {
                                        $status = 'danger';
                                        $text = 'Overdrawn';
                                    }
                                    ?>
                                    <span class="badge bg-<?php echo $status; ?>"><?php echo $text; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No leave balance records found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Export Options -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <button class="btn btn-outline-primary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print Report
                    </button>
                    <button class="btn btn-outline-success">
                        <i class="fas fa-download me-2"></i>Export Excel
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
