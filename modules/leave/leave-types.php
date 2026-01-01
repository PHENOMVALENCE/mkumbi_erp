<?php
/**
 * Leave Types Management - Complete CRUD Operations
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
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_data = null;

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
        
        // Check for duplicate name (excluding current record if editing)
        $check_sql = "SELECT COUNT(*) as count FROM leave_types 
                      WHERE company_id = ? AND leave_type_name = ? AND leave_type_id != ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->execute([$company_id, $leave_type_name, $leave_type_id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors[] = "A leave type with this name already exists.";
        }
        
        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                
                if ($action === 'add') {
                    // CREATE operation
                    $sql = "INSERT INTO leave_types (company_id, leave_type_name, leave_code, days_per_year, is_paid, is_active, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$company_id, $leave_type_name, $leave_code, $days_per_year, $is_paid, $is_active]);
                    $new_id = $conn->lastInsertId();
                    
                    logAudit($conn, $company_id, $user_id, 'create', 'leave', 'leave_types', $new_id, null, [
                        'leave_type_name' => $leave_type_name,
                        'days_per_year' => $days_per_year,
                        'is_paid' => $is_paid
                    ]);
                    
                    $success = "Leave type added successfully.";
                } else {
                    // UPDATE operation
                    $old_sql = "SELECT * FROM leave_types WHERE leave_type_id = ? AND company_id = ?";
                    $old_stmt = $conn->prepare($old_sql);
                    $old_stmt->execute([$leave_type_id, $company_id]);
                    $old_data = $old_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $sql = "UPDATE leave_types 
                            SET leave_type_name = ?, leave_code = ?, days_per_year = ?, is_paid = ?, is_active = ?, updated_at = NOW()
                            WHERE leave_type_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$leave_type_name, $leave_code, $days_per_year, $is_paid, $is_active, $leave_type_id, $company_id]);
                    
                    logAudit($conn, $company_id, $user_id, 'update', 'leave', 'leave_types', $leave_type_id, 
                             ['leave_type_name' => $old_data['leave_type_name'], 'days_per_year' => $old_data['days_per_year']],
                             ['leave_type_name' => $leave_type_name, 'days_per_year' => $days_per_year]
                    );
                    
                    $success = "Leave type updated successfully.";
                }
                
                $conn->commit();
                $_SESSION['success_message'] = $success;
                header('Location: leave-types.php');
                exit;
                
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Leave type error: " . $e->getMessage());
                $errors[] = "An error occurred. Please try again.";
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log("Leave type error: " . $e->getMessage());
                $errors[] = "An error occurred. Please try again.";
            }
        }
    }
    
    if ($action === 'delete') {
        // DELETE operation
        $leave_type_id = (int)$_POST['leave_type_id'];
        
        // Check if leave type is in use
        $check_sql = "SELECT COUNT(*) as count FROM leave_applications WHERE leave_type_id = ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->execute([$leave_type_id]);
        
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $_SESSION['error_message'] = "Cannot delete leave type that has applications. Deactivate it instead.";
        } else {
            try {
                $conn->beginTransaction();
                
                // Get data before deletion for audit
                $old_sql = "SELECT * FROM leave_types WHERE leave_type_id = ? AND company_id = ?";
                $old_stmt = $conn->prepare($old_sql);
                $old_stmt->execute([$leave_type_id, $company_id]);
                $old_data = $old_stmt->fetch(PDO::FETCH_ASSOC);
                
                $sql = "DELETE FROM leave_types WHERE leave_type_id = ? AND company_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$leave_type_id, $company_id]);
                
                logAudit($conn, $company_id, $user_id, 'delete', 'leave', 'leave_types', $leave_type_id, 
                         $old_data, null
                );
                
                $conn->commit();
                $_SESSION['success_message'] = "Leave type deleted successfully.";
                
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Leave type delete error: " . $e->getMessage());
                $_SESSION['error_message'] = "An error occurred. Please try again.";
            }
        }
        
        header('Location: leave-types.php');
        exit;
    }
}

// Fetch leave type for editing
if ($edit_id) {
    $sql = "SELECT * FROM leave_types WHERE leave_type_id = ? AND company_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$edit_id, $company_id]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$edit_data) {
        $_SESSION['error_message'] = "Leave type not found.";
        header('Location: leave-types.php');
        exit;
    }
}

// Fetch all leave types
$sql = "SELECT * FROM leave_types WHERE company_id = ? ORDER BY is_active DESC, leave_type_name";
$stmt = $conn->prepare($sql);
$stmt->execute([$company_id]);
$leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count leave applications by type
$app_counts = [];
$count_sql = "SELECT leave_type_id, COUNT(*) as count FROM leave_applications WHERE company_id = ? GROUP BY leave_type_id";
$stmt = $conn->prepare($count_sql);
$stmt->execute([$company_id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $app_counts[$row['leave_type_id']] = $row['count'];
}

$page_title = "Leave Types Management";
require_once '../../includes/header.php';
?>

<style>
    .type-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 20px;
        margin-bottom: 20px;
    }
    .form-card {
        background: white;
        border-radius: 15px;
        padding: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        margin-bottom: 20px;
    }
    .type-item {
        padding: 15px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .type-item:last-child {
        border-bottom: none;
    }
    .type-info {
        flex: 1;
    }
    .type-days {
        font-size: 1.5rem;
        font-weight: 700;
        color: #007bff;
    }
    .type-actions {
        text-align: right;
    }
    .badge-paid {
        background: #d4edda;
        color: #155724;
    }
    .badge-unpaid {
        background: #f8d7da;
        color: #721c24;
    }
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-cog text-primary me-2"></i>
                    Leave Types Management
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Configure and manage leave types
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

        <div class="row">
            <div class="col-lg-8">
                <!-- Leave Types List -->
                <div class="type-card">
                    <h5 class="mb-4"><i class="fas fa-list me-2"></i>Leave Types</h5>
                    
                    <?php if (empty($leave_types)): ?>
                    <p class="text-center text-muted py-4">No leave types configured yet.</p>
                    <?php else: ?>
                    <div class="type-item" style="background: #f8f9fa; font-weight: 600; border-radius: 8px 8px 0 0;">
                        <div class="type-info">Name</div>
                        <div style="width: 100px; text-align: center;">Days</div>
                        <div style="width: 100px; text-align: center;">Type</div>
                        <div style="width: 100px; text-align: center;">Status</div>
                        <div class="type-actions" style="width: 120px;">Actions</div>
                    </div>
                    
                    <?php foreach ($leave_types as $lt): ?>
                    <div class="type-item">
                        <div class="type-info">
                            <div class="fw-bold"><?php echo htmlspecialchars($lt['leave_type_name']); ?></div>
                            <small class="text-muted">Code: <?php echo htmlspecialchars($lt['leave_code'] ?? 'N/A'); ?></small><br>
                            <small class="text-muted">
                                <?php echo isset($app_counts[$lt['leave_type_id']]) ? $app_counts[$lt['leave_type_id']] : 0; ?> applications
                            </small>
                        </div>
                        <div style="width: 100px; text-align: center;">
                            <span class="badge bg-primary fs-6"><?php echo $lt['days_per_year']; ?></span>
                        </div>
                        <div style="width: 100px; text-align: center;">
                            <span class="badge <?php echo $lt['is_paid'] ? 'badge-paid' : 'badge-unpaid'; ?>">
                                <?php echo $lt['is_paid'] ? 'Paid' : 'Unpaid'; ?>
                            </span>
                        </div>
                        <div style="width: 100px; text-align: center;">
                            <?php echo $lt['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?>
                        </div>
                        <div class="type-actions" style="width: 120px;">
                            <a href="?edit=<?php echo $lt['leave_type_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="" method="POST" style="display:inline;" onsubmit="return confirm('Delete this leave type?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="leave_type_id" value="<?php echo $lt['leave_type_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" <?php echo isset($app_counts[$lt['leave_type_id']]) ? 'disabled title="Has applications"' : 'title="Delete"'; ?>>
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Form Section -->
            <div class="col-lg-4">
                <div class="form-card">
                    <h5 class="mb-4">
                        <i class="fas fa-<?php echo $edit_data ? 'edit' : 'plus'; ?> me-2"></i>
                        <?php echo $edit_data ? 'Edit Leave Type' : 'Add New Leave Type'; ?>
                    </h5>

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

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="<?php echo $edit_data ? 'edit' : 'add'; ?>">
                        <?php if ($edit_data): ?>
                        <input type="hidden" name="leave_type_id" value="<?php echo $edit_data['leave_type_id']; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Leave Type Name <span class="text-danger">*</span></label>
                            <input type="text" name="leave_type_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_data['leave_type_name'] ?? $_POST['leave_type_name'] ?? ''); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Leave Code</label>
                            <input type="text" name="leave_code" class="form-control" 
                                   value="<?php echo htmlspecialchars($edit_data['leave_code'] ?? $_POST['leave_code'] ?? ''); ?>"
                                   placeholder="e.g., AL, SL, MC">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Days Per Year <span class="text-danger">*</span></label>
                            <input type="number" name="days_per_year" class="form-control" min="0" step="1"
                                   value="<?php echo $edit_data['days_per_year'] ?? $_POST['days_per_year'] ?? '20'; ?>" required>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_paid" class="form-check-input" id="is_paid"
                                       <?php echo ($edit_data && $edit_data['is_paid']) || (!$edit_data && isset($_POST['is_paid'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_paid">
                                    Paid Leave
                                </label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" id="is_active"
                                       <?php echo (!$edit_data || ($edit_data && $edit_data['is_active'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-<?php echo $edit_data ? 'save' : 'plus'; ?> me-2"></i>
                                <?php echo $edit_data ? 'Update Leave Type' : 'Add Leave Type'; ?>
                            </button>
                            <?php if ($edit_data): ?>
                            <a href="leave-types.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel Edit
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Info Box -->
                <div class="form-card">
                    <h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2 text-info"></i>Information</h6>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-check me-2"></i>Create different leave types for various purposes<br>
                        <i class="fas fa-check me-2"></i>Set daily entitlements for each type<br>
                        <i class="fas fa-check me-2"></i>Mark as paid/unpaid for payroll processing<br>
                        <i class="fas fa-check me-2"></i>Deactivate instead of deleting to preserve history
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
