<?php
/**
 * Leave Approvals Management
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
if (!hasPermission($conn, $user_id, ['MANAGER', 'HR_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
    $_SESSION['error_message'] = "You don't have permission to access this page.";
    header('Location: index.php');
    exit;
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_leaves'] ?? [];
    
    if (!empty($selected_ids) && in_array($action, ['approve', 'reject'])) {
        try {
            $conn->beginTransaction();
            
            $status = $action === 'approve' ? 'approved' : 'rejected';
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            $update_sql = "UPDATE leave_applications 
                           SET status = ?, approved_by = ?, approved_at = NOW()
                           WHERE leave_id IN ($placeholders) AND company_id = ? AND status = 'pending'";
            
            $params = array_merge([$status, $user_id], $selected_ids, [$company_id]);
            $stmt = $conn->prepare($update_sql);
            $stmt->execute($params);
            
            $affected = $stmt->rowCount();
            $conn->commit();
            
            $_SESSION['success_message'] = "$affected leave request(s) have been " . ($action === 'approve' ? 'approved' : 'rejected') . ".";
        } catch (PDOException $e) {
            $conn->rollBack();
            $_SESSION['error_message'] = "An error occurred during bulk processing.";
        }
        
        header('Location: approvals.php');
        exit;
    }
}

// Fetch filter parameters
$filter_status = $_GET['status'] ?? 'pending';
$filter_department = $_GET['department'] ?? '';
$filter_leave_type = $_GET['leave_type'] ?? '';

// Build query
$where = ["la.company_id = ?"];
$params = [$company_id];

if ($filter_status !== 'all') {
    $where[] = "la.status = ?";
    $params[] = $filter_status;
}

if ($filter_department) {
    $where[] = "e.department_id = ?";
    $params[] = $filter_department;
}

if ($filter_leave_type) {
    $where[] = "la.leave_type_id = ?";
    $params[] = $filter_leave_type;
}

$where_clause = implode(' AND ', $where);

// Fetch leave applications
$sql = "SELECT la.*, lt.leave_type_name, lt.is_paid,
               u.full_name as employee_name, u.email as employee_email,
               d.department_name, p.position_title,
               approver.full_name as approved_by_name
        FROM leave_applications la
        JOIN leave_types lt ON la.leave_type_id = lt.leave_type_id
        JOIN employees e ON la.employee_id = e.employee_id
        JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        LEFT JOIN users approver ON la.approved_by = approver.user_id
        WHERE $where_clause
        ORDER BY 
            CASE WHEN la.status = 'pending' THEN 0 ELSE 1 END,
            la.application_date DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch departments and leave types for filters
$departments = $conn->prepare("SELECT department_id, department_name FROM departments WHERE company_id = ? ORDER BY department_name");
$departments->execute([$company_id]);
$departments = $departments->fetchAll(PDO::FETCH_ASSOC);

$leave_types = $conn->prepare("SELECT leave_type_id, leave_type_name FROM leave_types WHERE company_id = ? AND is_active = 1");
$leave_types->execute([$company_id]);
$leave_types = $leave_types->fetchAll(PDO::FETCH_ASSOC);

// Count statistics
$stats_sql = "SELECT 
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count,
    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_count
FROM leave_applications WHERE company_id = ?";
$stmt = $conn->prepare($stats_sql);
$stmt->execute([$company_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$page_title = "Leave Approvals";
require_once '../../includes/header.php';
?>

<style>
    .approval-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .filter-bar {
        background: #f8f9fa;
        padding: 20px;
        border-bottom: 1px solid #eee;
    }
    .stats-row {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
    }
    .stat-badge {
        padding: 10px 20px;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .stat-badge:hover {
        transform: translateY(-2px);
    }
    .stat-badge.pending {
        background: #fff3cd;
        color: #856404;
    }
    .stat-badge.approved {
        background: #d4edda;
        color: #155724;
    }
    .stat-badge.rejected {
        background: #f8d7da;
        color: #721c24;
    }
    .stat-badge.active {
        box-shadow: 0 0 0 3px rgba(0,123,255,0.5);
    }
    .leave-row {
        transition: background 0.2s;
    }
    .leave-row:hover {
        background: #f8f9fa;
    }
    .action-buttons .btn {
        padding: 5px 12px;
    }
    .bulk-actions {
        background: #e3f2fd;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        display: none;
    }
    .bulk-actions.show {
        display: block;
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-check-double me-2"></i>Leave Approvals</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Leave</a></li>
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
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Stats Badges -->
            <div class="stats-row">
                <a href="?status=pending" class="stat-badge pending <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-hourglass-half me-2"></i>
                    Pending <strong><?php echo $stats['pending_count']; ?></strong>
                </a>
                <a href="?status=approved" class="stat-badge approved <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle me-2"></i>
                    Approved <strong><?php echo $stats['approved_count']; ?></strong>
                </a>
                <a href="?status=rejected" class="stat-badge rejected <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">
                    <i class="fas fa-times-circle me-2"></i>
                    Rejected <strong><?php echo $stats['rejected_count']; ?></strong>
                </a>
                <a href="?status=all" class="stat-badge <?php echo $filter_status === 'all' ? 'active' : ''; ?>" style="background:#e2e3e5;color:#383d41;">
                    <i class="fas fa-list me-2"></i>
                    All
                </a>
            </div>

            <div class="approval-card">
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" class="row g-3 align-items-end">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        
                        <div class="col-md-3">
                            <label class="form-label small">Department</label>
                            <select name="department" class="form-select">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>"
                                        <?php echo $filter_department == $dept['department_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label small">Leave Type</label>
                            <select name="leave_type" class="form-select">
                                <option value="">All Types</option>
                                <?php foreach ($leave_types as $lt): ?>
                                <option value="<?php echo $lt['leave_type_id']; ?>"
                                        <?php echo $filter_leave_type == $lt['leave_type_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($lt['leave_type_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                        </div>
                        
                        <div class="col-md-2">
                            <a href="approvals.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Bulk Actions -->
                <form method="POST" id="bulkForm">
                    <div class="bulk-actions" id="bulkActions">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><strong id="selectedCount">0</strong> item(s) selected</span>
                            <div>
                                <button type="submit" name="bulk_action" value="approve" class="btn btn-success me-2">
                                    <i class="fas fa-check me-2"></i>Approve Selected
                                </button>
                                <button type="submit" name="bulk_action" value="reject" class="btn btn-danger">
                                    <i class="fas fa-times me-2"></i>Reject Selected
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <?php if ($filter_status === 'pending'): ?>
                                    <th width="40">
                                        <input type="checkbox" id="selectAll" class="form-check-input">
                                    </th>
                                    <?php endif; ?>
                                    <th>Employee</th>
                                    <th>Leave Type</th>
                                    <th>Period</th>
                                    <th>Days</th>
                                    <th>Reason</th>
                                    <th>Applied On</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="<?php echo $filter_status === 'pending' ? 9 : 8; ?>" class="text-center py-5">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No leave applications found.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($applications as $app): ?>
                                <tr class="leave-row">
                                    <?php if ($filter_status === 'pending'): ?>
                                    <td>
                                        <input type="checkbox" name="selected_leaves[]" 
                                               value="<?php echo $app['leave_id']; ?>"
                                               class="form-check-input leave-checkbox"
                                               <?php echo $app['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <strong><?php echo htmlspecialchars($app['employee_name']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($app['department_name'] ?? 'No Dept'); ?>
                                            <?php if ($app['position_title']): ?> • <?php echo htmlspecialchars($app['position_title']); ?><?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $app['is_paid'] ? 'info' : 'secondary'; ?>">
                                            <?php echo htmlspecialchars($app['leave_type_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('M d', strtotime($app['start_date'])); ?> - 
                                        <?php echo date('M d, Y', strtotime($app['end_date'])); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $app['total_days']; ?></span>
                                    </td>
                                    <td>
                                        <span title="<?php echo htmlspecialchars($app['reason']); ?>">
                                            <?php echo htmlspecialchars(substr($app['reason'], 0, 40)); ?>
                                            <?php echo strlen($app['reason']) > 40 ? '...' : ''; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($app['application_date'])); ?></td>
                                    <td><?php echo getStatusBadge($app['status']); ?></td>
                                    <td class="action-buttons">
                                        <a href="view.php?id=<?php echo $app['leave_id']; ?>" 
                                           class="btn btn-sm btn-outline-info" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($app['status'] === 'pending'): ?>
                                        <a href="process.php?id=<?php echo $app['leave_id']; ?>&action=approve" 
                                           class="btn btn-sm btn-success" title="Approve"
                                           onclick="return confirm('Approve this leave request?');">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-danger" title="Reject"
                                                data-bs-toggle="modal" data-bs-target="#rejectModal"
                                                data-leave-id="<?php echo $app['leave_id']; ?>"
                                                data-employee="<?php echo htmlspecialchars($app['employee_name']); ?>">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

        </div>
    </section>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="process.php" method="POST">
                <input type="hidden" name="leave_id" id="rejectLeaveId">
                <input type="hidden" name="action" value="reject">
                
                <div class="modal-header">
                    <h5 class="modal-title">Reject Leave Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>You are about to reject the leave request from <strong id="rejectEmployee"></strong>.</p>
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required
                                  placeholder="Please provide a reason for rejecting this request..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-2"></i>Reject Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.leave-checkbox:not(:disabled)');
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    
    function updateBulkActions() {
        const checked = document.querySelectorAll('.leave-checkbox:checked').length;
        selectedCount.textContent = checked;
        bulkActions.classList.toggle('show', checked > 0);
    }
    
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
            });
            updateBulkActions();
        });
    }
    
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkActions);
    });
    
    // Reject modal
    const rejectModal = document.getElementById('rejectModal');
    if (rejectModal) {
        rejectModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('rejectLeaveId').value = button.dataset.leaveId;
            document.getElementById('rejectEmployee').textContent = button.dataset.employee;
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
