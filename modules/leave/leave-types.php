<?php
/**
 * Leave Types Management
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
if (!hasPermission($conn, $user_id, ['HR_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
    $_SESSION['error_message'] = "You don't have permission to manage leave types.";
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $leave_type_id = (int)($_POST['leave_type_id'] ?? 0);
        $leave_type_name = sanitize($_POST['leave_type_name']);
        $leave_code = sanitize($_POST['leave_code']);
        $days_per_year = (int)$_POST['days_per_year'];
        $is_paid = isset($_POST['is_paid']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validation
        if (empty($leave_type_name)) {
            $errors[] = "Leave type name is required.";
        }
        
        if ($days_per_year < 0) {
            $errors[] = "Days per year must be 0 or greater.";
        }
        
        // Check for duplicate name
        $check_sql = "SELECT COUNT(*) as count FROM leave_types 
                      WHERE company_id = ? AND leave_type_name = ? AND leave_type_id != ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->execute([$company_id, $leave_type_name, $leave_type_id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors[] = "A leave type with this name already exists.";
        }
        
        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $sql = "INSERT INTO leave_types (company_id, leave_type_name, leave_code, days_per_year, is_paid, is_active)
                            VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$company_id, $leave_type_name, $leave_code, $days_per_year, $is_paid, $is_active]);
                    $success = "Leave type added successfully.";
                } else {
                    $sql = "UPDATE leave_types 
                            SET leave_type_name = ?, leave_code = ?, days_per_year = ?, is_paid = ?, is_active = ?
                            WHERE leave_type_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$leave_type_name, $leave_code, $days_per_year, $is_paid, $is_active, $leave_type_id, $company_id]);
                    $success = "Leave type updated successfully.";
                }
                
                logAudit($conn, $company_id, $user_id, $action === 'add' ? 'create' : 'update', 'leave', 'leave_types', 
                         $leave_type_id ?: $conn->lastInsertId(), null, ['leave_type_name' => $leave_type_name]);
                
            } catch (PDOException $e) {
                error_log("Leave type error: " . $e->getMessage());
                $errors[] = "An error occurred. Please try again.";
            }
        }
    }
    
    if ($action === 'delete') {
        $leave_type_id = (int)$_POST['leave_type_id'];
        
        // Check if leave type is in use
        $check_sql = "SELECT COUNT(*) as count FROM leave_applications WHERE leave_type_id = ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->execute([$leave_type_id]);
        
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors[] = "Cannot delete leave type that has applications.";
        } else {
            $sql = "DELETE FROM leave_types WHERE leave_type_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$leave_type_id, $company_id]);
            $success = "Leave type deleted successfully.";
            
            logAudit($conn, $company_id, $user_id, 'delete', 'leave', 'leave_types', $leave_type_id);
        }
    }
}

// Fetch leave types
$sql = "SELECT lt.*, 
               (SELECT COUNT(*) FROM leave_applications la WHERE la.leave_type_id = lt.leave_type_id) as usage_count
        FROM leave_types lt
        WHERE lt.company_id = ?
        ORDER BY lt.leave_type_name";
$stmt = $conn->prepare($sql);
$stmt->execute([$company_id]);
$leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Manage Leave Types";
require_once '../../includes/header.php';
?>

<style>
    .types-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .type-row {
        padding: 20px;
        border-bottom: 1px solid #eee;
        transition: background 0.2s;
    }
    .type-row:hover {
        background: #f8f9fa;
    }
    .type-row:last-child {
        border-bottom: none;
    }
    .type-badge {
        display: inline-block;
        padding: 5px 15px;
        border-radius: 20px;
        font-weight: 600;
    }
    .type-badge.paid {
        background: #d4edda;
        color: #155724;
    }
    .type-badge.unpaid {
        background: #f8d7da;
        color: #721c24;
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-cogs me-2"></i>Leave Types</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Leave</a></li>
                        <li class="breadcrumb-item active">Leave Types</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-12">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTypeModal">
                        <i class="fas fa-plus-circle me-2"></i>Add Leave Type
                    </button>
                </div>
            </div>

            <div class="types-card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Leave Type</th>
                                <th>Code</th>
                                <th>Days/Year</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Usage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($leave_types)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No leave types configured. Add your first leave type.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($leave_types as $lt): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($lt['leave_type_name']); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo htmlspecialchars($lt['leave_code'] ?: 'N/A'); ?></code>
                                </td>
                                <td>
                                    <span class="badge bg-primary fs-6"><?php echo $lt['days_per_year']; ?></span>
                                </td>
                                <td>
                                    <span class="type-badge <?php echo $lt['is_paid'] ? 'paid' : 'unpaid'; ?>">
                                        <?php echo $lt['is_paid'] ? 'Paid' : 'Unpaid'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($lt['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-muted"><?php echo $lt['usage_count']; ?> applications</span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary edit-btn"
                                            data-id="<?php echo $lt['leave_type_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($lt['leave_type_name']); ?>"
                                            data-code="<?php echo htmlspecialchars($lt['leave_code']); ?>"
                                            data-days="<?php echo $lt['days_per_year']; ?>"
                                            data-paid="<?php echo $lt['is_paid']; ?>"
                                            data-active="<?php echo $lt['is_active']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($lt['usage_count'] == 0): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this leave type?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="leave_type_id" value="<?php echo $lt['leave_type_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
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

<!-- Add/Edit Modal -->
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="leave_type_id" id="leaveTypeId" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Leave Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Leave Type Name <span class="text-danger">*</span></label>
                        <input type="text" name="leave_type_name" id="leaveTypeName" class="form-control" required
                               placeholder="e.g., Annual Leave, Sick Leave">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Leave Code</label>
                        <input type="text" name="leave_code" id="leaveCode" class="form-control"
                               placeholder="e.g., AL, SL, ML">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Days Per Year <span class="text-danger">*</span></label>
                        <input type="number" name="days_per_year" id="daysPerYear" class="form-control" 
                               min="0" value="0" required>
                        <small class="text-muted">Set to 0 for unlimited or as-needed leave</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_paid" id="isPaid" checked>
                            <label class="form-check-label" for="isPaid">Paid Leave</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" checked>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('addTypeModal');
    const editBtns = document.querySelectorAll('.edit-btn');
    
    // Reset modal on open
    modal.addEventListener('show.bs.modal', function(event) {
        if (!event.relatedTarget.classList.contains('edit-btn')) {
            document.getElementById('formAction').value = 'add';
            document.getElementById('modalTitle').textContent = 'Add Leave Type';
            document.getElementById('leaveTypeId').value = '0';
            document.getElementById('leaveTypeName').value = '';
            document.getElementById('leaveCode').value = '';
            document.getElementById('daysPerYear').value = '0';
            document.getElementById('isPaid').checked = true;
            document.getElementById('isActive').checked = true;
        }
    });
    
    // Edit button click
    editBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('formAction').value = 'edit';
            document.getElementById('modalTitle').textContent = 'Edit Leave Type';
            document.getElementById('leaveTypeId').value = this.dataset.id;
            document.getElementById('leaveTypeName').value = this.dataset.name;
            document.getElementById('leaveCode').value = this.dataset.code;
            document.getElementById('daysPerYear').value = this.dataset.days;
            document.getElementById('isPaid').checked = this.dataset.paid === '1';
            document.getElementById('isActive').checked = this.dataset.active === '1';
            
            new bootstrap.Modal(modal).show();
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
