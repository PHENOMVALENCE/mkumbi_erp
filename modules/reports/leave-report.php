<?php
/**
 * Leave Report
 * Mkumbi Investments ERP System
 */

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

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$leave_type_filter = $_GET['leave_type'] ?? '';
$department_filter = $_GET['department'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$sql = "SELECT la.*, lt.leave_type_name, u_emp.full_name as employee_name,
               e.employee_number, d.department_name,
               (SELECT u2.full_name FROM users u2 WHERE u2.user_id = la.approved_by) as approver_name
        FROM leave_applications la
        JOIN leave_types lt ON la.leave_type_id = lt.leave_type_id
        JOIN employees e ON la.employee_id = e.employee_id
        JOIN users u_emp ON e.user_id = u_emp.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE la.company_id = ?";
$params = [$company_id];

if ($status_filter) {
    $sql .= " AND la.status = ?";
    $params[] = $status_filter;
}

if ($leave_type_filter) {
    $sql .= " AND la.leave_type_id = ?";
    $params[] = $leave_type_filter;
}

if ($department_filter) {
    $sql .= " AND e.department_id = ?";
    $params[] = $department_filter;
}

if ($date_from) {
    $sql .= " AND DATE(la.start_date) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $sql .= " AND DATE(la.start_date) <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY la.start_date DESC, la.leave_id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'count' => count($leaves),
    'total_days' => 0
];
foreach ($leaves as $leave) {
    $totals['total_days'] += $leave['days_requested'] ?? 0;
}

// Get leave types for filter
$lt_stmt = $conn->prepare("SELECT leave_type_id, leave_type_name FROM leave_types WHERE company_id = ? AND is_active = 1 ORDER BY leave_type_name");
$lt_stmt->execute([$company_id]);
$leave_types = $lt_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filter
$dept_stmt = $conn->prepare("SELECT department_id, department_name FROM departments WHERE company_id = ? ORDER BY department_name");
$dept_stmt->execute([$company_id]);
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Leave Report";
require_once '../../includes/header.php';
?>

<style>
    .report-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }
    .filter-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 20px;
        margin-bottom: 20px;
    }
    .stats-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        border-left: 4px solid #667eea;
    }
    .report-table {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .badge-status {
        padding: 6px 12px;
        border-radius: 20px;
        font-weight: 500;
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-calendar-times me-2"></i>Leave Report</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Reports</a></li>
                        <li class="breadcrumb-item active">Leave Report</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <!-- Summary Stats -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card">
                        <h6 class="text-muted mb-2">Total Applications</h6>
                        <h3 class="mb-0"><?php echo number_format($totals['count']); ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="border-left-color: #28a745;">
                        <h6 class="text-muted mb-2">Total Days</h6>
                        <h3 class="mb-0"><?php echo number_format($totals['total_days']); ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="border-left-color: #ffc107;">
                        <h6 class="text-muted mb-2">Average Days</h6>
                        <h3 class="mb-0"><?php echo $totals['count'] > 0 ? number_format($totals['total_days'] / $totals['count'], 1) : 0; ?></h3>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Leave Type</label>
                        <select name="leave_type" class="form-select">
                            <option value="">All Types</option>
                            <?php foreach ($leave_types as $lt): ?>
                            <option value="<?php echo $lt['leave_type_id']; ?>" <?php echo $leave_type_filter == $lt['leave_type_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lt['leave_type_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>" <?php echo $department_filter == $dept['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Filter</button>
                    </div>
                </form>
            </div>

            <!-- Report Table -->
            <div class="report-table">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Leave Type</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Days</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Approved By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($leaves)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No leave applications found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($leave['employee_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($leave['employee_number']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($leave['leave_type_name']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($leave['start_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($leave['end_date'])); ?></td>
                                <td><strong><?php echo $leave['days_requested']; ?></strong></td>
                                <td><?php echo htmlspecialchars($leave['department_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php
                                    $status_class = [
                                        'pending' => 'warning',
                                        'approved' => 'success',
                                        'rejected' => 'danger',
                                        'cancelled' => 'secondary'
                                    ];
                                    $status = strtolower($leave['status']);
                                    $class = $status_class[$status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $class; ?> badge-status"><?php echo ucfirst($status); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($leave['approver_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4">Totals</th>
                                <th><?php echo number_format($totals['total_days']); ?></th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Export Options -->
            <div class="mt-3 text-end">
                <button onclick="window.print()" class="btn btn-outline-primary"><i class="fas fa-print me-2"></i>Print</button>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-success"><i class="fas fa-file-csv me-2"></i>Export CSV</a>
            </div>
        </div>
    </section>
</div>

<?php require_once '../../includes/footer.php'; ?>
