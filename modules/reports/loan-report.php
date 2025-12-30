<?php
/**
 * Employee Loan Report
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
$loan_type_filter = $_GET['loan_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$sql = "SELECT el.*, lt.type_name as loan_type_name, u_emp.full_name as employee_name, 
               e.employee_number, d.department_name,
               (SELECT SUM(total_paid) FROM loan_payments lp WHERE lp.loan_id = el.loan_id) as total_paid,
               (SELECT u2.full_name FROM users u2 WHERE u2.user_id = el.approved_by) as approver_name
        FROM employee_loans el
        JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
        JOIN employees e ON el.employee_id = e.employee_id
        JOIN users u_emp ON e.user_id = u_emp.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE el.company_id = ?";
$params = [$company_id];

if ($status_filter) {
    $sql .= " AND el.status = ?";
    $params[] = $status_filter;
}

if ($loan_type_filter) {
    $sql .= " AND el.loan_type_id = ?";
    $params[] = $loan_type_filter;
}

if ($date_from) {
    $sql .= " AND DATE(el.application_date) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $sql .= " AND DATE(el.application_date) <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY el.application_date DESC, el.loan_number";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'count' => count($loans),
    'loan_amount' => 0,
    'total_outstanding' => 0,
    'total_paid' => 0
];
foreach ($loans as $loan) {
    $totals['loan_amount'] += $loan['loan_amount'] ?? 0;
    $totals['total_outstanding'] += $loan['total_outstanding'] ?? 0;
    $totals['total_paid'] += $loan['total_paid'] ?? 0;
}

// Get loan types for filter
$lt_stmt = $conn->prepare("SELECT loan_type_id, type_name FROM loan_types WHERE company_id = ? AND is_active = 1 ORDER BY type_name");
$lt_stmt->execute([$company_id]);
$loan_types = $lt_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Employee Loan Report";
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
                    <h1><i class="fas fa-hand-holding-usd me-2"></i>Employee Loan Report</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Reports</a></li>
                        <li class="breadcrumb-item active">Loan Report</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <!-- Summary Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <h6 class="text-muted mb-2">Total Loans</h6>
                        <h3 class="mb-0"><?php echo number_format($totals['count']); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="border-left-color: #28a745;">
                        <h6 class="text-muted mb-2">Total Amount</h6>
                        <h3 class="mb-0"><?php echo formatCurrency($totals['loan_amount']); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="border-left-color: #ffc107;">
                        <h6 class="text-muted mb-2">Outstanding</h6>
                        <h3 class="mb-0"><?php echo formatCurrency($totals['total_outstanding']); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="border-left-color: #17a2b8;">
                        <h6 class="text-muted mb-2">Total Paid</h6>
                        <h3 class="mb-0"><?php echo formatCurrency($totals['total_paid']); ?></h3>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="PENDING" <?php echo $status_filter === 'PENDING' ? 'selected' : ''; ?>>Pending</option>
                            <option value="APPROVED" <?php echo $status_filter === 'APPROVED' ? 'selected' : ''; ?>>Approved</option>
                            <option value="DISBURSED" <?php echo $status_filter === 'DISBURSED' ? 'selected' : ''; ?>>Disbursed</option>
                            <option value="ACTIVE" <?php echo $status_filter === 'ACTIVE' ? 'selected' : ''; ?>>Active</option>
                            <option value="COMPLETED" <?php echo $status_filter === 'COMPLETED' ? 'selected' : ''; ?>>Completed</option>
                            <option value="REJECTED" <?php echo $status_filter === 'REJECTED' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Loan Type</label>
                        <select name="loan_type" class="form-select">
                            <option value="">All Types</option>
                            <?php foreach ($loan_types as $lt): ?>
                            <option value="<?php echo $lt['loan_type_id']; ?>" <?php echo $loan_type_filter == $lt['loan_type_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lt['type_name']); ?>
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
                                <th>Loan #</th>
                                <th>Employee</th>
                                <th>Loan Type</th>
                                <th>Amount</th>
                                <th>Interest Rate</th>
                                <th>Term</th>
                                <th>Outstanding</th>
                                <th>Paid</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($loans)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">No loans found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($loan['loan_number']); ?></code></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($loan['employee_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($loan['employee_number']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($loan['loan_type_name']); ?></td>
                                <td><?php echo formatCurrency($loan['loan_amount']); ?></td>
                                <td><?php echo number_format($loan['interest_rate'], 2); ?>%</td>
                                <td><?php echo $loan['repayment_period_months']; ?> months</td>
                                <td><strong><?php echo formatCurrency($loan['total_outstanding']); ?></strong></td>
                                <td><?php echo formatCurrency($loan['total_paid'] ?? 0); ?></td>
                                <td>
                                    <?php
                                    $status_class = [
                                        'PENDING' => 'warning',
                                        'APPROVED' => 'info',
                                        'DISBURSED' => 'primary',
                                        'ACTIVE' => 'success',
                                        'COMPLETED' => 'secondary',
                                        'REJECTED' => 'danger'
                                    ];
                                    $status = $loan['status'];
                                    $class = $status_class[$status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $class; ?> badge-status"><?php echo $status; ?></span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3">Totals</th>
                                <th><?php echo formatCurrency($totals['loan_amount']); ?></th>
                                <th colspan="2"></th>
                                <th><?php echo formatCurrency($totals['total_outstanding']); ?></th>
                                <th><?php echo formatCurrency($totals['total_paid']); ?></th>
                                <th colspan="2"></th>
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
