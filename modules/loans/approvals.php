<?php
/**
 * Loan Approvals Management
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

// Check permission
if (!hasPermission($conn, $user_id, ['HR_OFFICER', 'FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
    $_SESSION['error_message'] = "You don't have permission to manage loan approvals.";
    header('Location: index.php');
    exit;
}

// Filter parameters
$status_filter = $_GET['status'] ?? 'pending';
$department_filter = $_GET['department'] ?? '';
$loan_type_filter = $_GET['loan_type'] ?? '';

// Build query
$sql = "SELECT el.*, COALESCE(lt.type_name, lt.loan_type_name) as loan_type_name, u.full_name as employee_name, e.employee_number,
               e.basic_salary, d.department_name,
               (SELECT u2.full_name FROM users u2 WHERE u2.user_id = el.approved_by) as approver_name
        FROM employee_loans el
        JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
        JOIN employees e ON el.employee_id = e.employee_id
        JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE el.company_id = ?";
$params = [$company_id];

if ($status_filter && strtolower($status_filter) !== 'all') {
    $sql .= " AND el.status = ?";
    $params[] = strtolower($status_filter);
}
if ($department_filter) {
    $sql .= " AND e.department_id = ?";
    $params[] = $department_filter;
}
if ($loan_type_filter) {
    $sql .= " AND el.loan_type_id = ?";
    $params[] = $loan_type_filter;
}

$sql .= " ORDER BY el.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments and loan types for filters
$dept_stmt = $conn->prepare("SELECT department_id, department_name FROM departments WHERE company_id = ? ORDER BY department_name");
$dept_stmt->execute([$company_id]);
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

$loan_types_stmt = $conn->prepare("SELECT loan_type_id, COALESCE(type_name, loan_type_name) as loan_type_name FROM loan_types WHERE company_id = ? ORDER BY COALESCE(type_name, loan_type_name)");
$loan_types_stmt->execute([$company_id]);
$loan_types = $loan_types_stmt->fetchAll(PDO::FETCH_ASSOC);

// Count by status
$counts = [];
$count_sql = "SELECT status, COUNT(*) as count FROM employee_loans WHERE company_id = ? GROUP BY status";
$stmt = $conn->prepare($count_sql);
$stmt->execute([$company_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $counts[$row['status']] = $row['count'];
}

$page_title = "Loan Approvals";
require_once '../../includes/header.php';
?>

<style>
    .filter-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 20px;
        margin-bottom: 20px;
    }
    .status-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    .status-tab {
        padding: 10px 20px;
        border-radius: 25px;
        text-decoration: none;
        color: #6c757d;
        background: #f8f9fa;
        transition: all 0.2s;
    }
    .status-tab:hover { background: #e9ecef; color: #495057; }
    .status-tab.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    .status-tab .badge {
        margin-left: 5px;
        font-size: 0.75rem;
    }
    .loan-table {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .loan-table th {
        background: #f8f9fa;
        border-bottom: 2px solid #dee2e6;
        font-weight: 600;
    }
    .loan-table tbody tr:hover { background: #f8f9fe; }
    .loan-amount { font-size: 1.1rem; font-weight: 600; color: #667eea; }
    .action-buttons .btn { padding: 5px 15px; }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-clipboard-check me-2"></i>Loan Approvals</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                        <li class="breadcrumb-item active">Approvals</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Status Tabs -->
            <div class="status-tabs">
                <a href="?status=all" class="status-tab <?php echo strtolower($status_filter) === 'all' ? 'active' : ''; ?>">
                    All <span class="badge bg-secondary"><?php echo array_sum($counts); ?></span>
                </a>
                <a href="?status=pending" class="status-tab <?php echo strtolower($status_filter) === 'pending' ? 'active' : ''; ?>">
                    Pending <span class="badge bg-warning"><?php echo $counts['pending'] ?? 0; ?></span>
                </a>
                <a href="?status=approved" class="status-tab <?php echo strtolower($status_filter) === 'approved' ? 'active' : ''; ?>">
                    Approved <span class="badge bg-success"><?php echo $counts['approved'] ?? 0; ?></span>
                </a>
                <a href="?status=disbursed" class="status-tab <?php echo strtolower($status_filter) === 'disbursed' ? 'active' : ''; ?>">
                    Disbursed <span class="badge bg-info"><?php echo $counts['disbursed'] ?? 0; ?></span>
                </a>
                <a href="?status=active" class="status-tab <?php echo strtolower($status_filter) === 'active' ? 'active' : ''; ?>">
                    Active <span class="badge bg-primary"><?php echo $counts['active'] ?? 0; ?></span>
                </a>
                <a href="?status=rejected" class="status-tab <?php echo strtolower($status_filter) === 'rejected' ? 'active' : ''; ?>">
                    Rejected <span class="badge bg-danger"><?php echo $counts['rejected'] ?? 0; ?></span>
                </a>
                <a href="?status=completed" class="status-tab <?php echo strtolower($status_filter) === 'completed' ? 'active' : ''; ?>">
                    Completed <span class="badge bg-secondary"><?php echo $counts['completed'] ?? 0; ?></span>
                </a>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    <div class="col-md-4">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['department_id']; ?>" <?php echo $department_filter == $d['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Loan Type</label>
                        <select name="loan_type" class="form-select">
                            <option value="">All Types</option>
                            <?php foreach ($loan_types as $lt): ?>
                            <option value="<?php echo $lt['loan_type_id']; ?>" <?php echo $loan_type_filter == $lt['loan_type_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lt['loan_type_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-2"></i>Filter
                        </button>
                        <a href="approvals.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Loans Table -->
            <div class="loan-table">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Employee</th>
                                <th>Loan Type</th>
                                <th>Amount</th>
                                <th>Term</th>
                                <th>Monthly</th>
                                <th>Applied</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($loans)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No loan applications found.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($loan['loan_number']); ?></strong>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($loan['employee_name']); ?></strong>
                                    <small class="d-block text-muted">
                                        <?php echo htmlspecialchars($loan['employee_number']); ?> | 
                                        <?php echo htmlspecialchars($loan['department_name'] ?? 'N/A'); ?>
                                    </small>
                                </td>
                                <td><?php echo htmlspecialchars($loan['loan_type_name']); ?></td>
                                <td class="loan-amount"><?php echo formatCurrency($loan['loan_amount']); ?></td>
                                <td><?php echo $loan['repayment_period_months']; ?> months</td>
                                <td><?php echo formatCurrency($loan['monthly_deduction']); ?></td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($loan['application_date'] ?? $loan['created_at'])); ?>
                                </td>
                                <td><?php echo getStatusBadge($loan['status']); ?></td>
                                <td class="action-buttons">
                                    <a href="view.php?id=<?php echo $loan['loan_id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (strtolower($loan['status']) === 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-success approve-btn" 
                                            data-id="<?php echo $loan['loan_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($loan['employee_name']); ?>"
                                            data-amount="<?php echo formatCurrency($loan['loan_amount']); ?>">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger reject-btn"
                                            data-id="<?php echo $loan['loan_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($loan['employee_name']); ?>">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php elseif (strtolower($loan['status']) === 'approved'): ?>
                                    <button type="button" class="btn btn-sm btn-info disburse-btn"
                                            data-id="<?php echo $loan['loan_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($loan['employee_name']); ?>"
                                            data-amount="<?php echo formatCurrency($loan['loan_amount']); ?>">
                                        <i class="fas fa-money-bill-wave"></i> Disburse
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </section>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="process.php">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="loan_id" id="approveLoanId">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Approve Loan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Approve loan application for <strong id="approveEmployeeName"></strong>?</p>
                    <p>Amount: <strong id="approveAmount"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Comments (optional)</label>
                        <textarea name="comments" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Approve Loan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="process.php">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="loan_id" id="rejectLoanId">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Loan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Reject loan application for <strong id="rejectEmployeeName"></strong>?</p>
                    <div class="mb-3">
                        <label class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required
                                  placeholder="Please provide a reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-2"></i>Reject Loan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Disburse Modal -->
<div class="modal fade" id="disburseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="process.php">
                <input type="hidden" name="action" value="disburse">
                <input type="hidden" name="loan_id" id="disburseLoanId">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Disburse Loan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Disburse loan to <strong id="disburseEmployeeName"></strong>?</p>
                    <p>Amount: <strong id="disburseAmount"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Disbursement Date</label>
                        <input type="date" name="disbursement_date" class="form-control" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="BANK_TRANSFER">Bank Transfer</option>
                            <option value="CASH">Cash</option>
                            <option value="CHEQUE">Cheque</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reference Number</label>
                        <input type="text" name="payment_reference" class="form-control" 
                               placeholder="Transaction/Cheque number">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-check me-2"></i>Confirm Disbursement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Approve button
    document.querySelectorAll('.approve-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('approveLoanId').value = this.dataset.id;
            document.getElementById('approveEmployeeName').textContent = this.dataset.name;
            document.getElementById('approveAmount').textContent = this.dataset.amount;
            new bootstrap.Modal(document.getElementById('approveModal')).show();
        });
    });
    
    // Reject button
    document.querySelectorAll('.reject-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('rejectLoanId').value = this.dataset.id;
            document.getElementById('rejectEmployeeName').textContent = this.dataset.name;
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        });
    });
    
    // Disburse button
    document.querySelectorAll('.disburse-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('disburseLoanId').value = this.dataset.id;
            document.getElementById('disburseEmployeeName').textContent = this.dataset.name;
            document.getElementById('disburseAmount').textContent = this.dataset.amount;
            new bootstrap.Modal(document.getElementById('disburseModal')).show();
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
