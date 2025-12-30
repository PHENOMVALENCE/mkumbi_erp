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

// Only Super Admins and Company Admins can manage workflows
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$is_super_admin = isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] == 1;

if (!$is_admin && !$is_super_admin) {
    $_SESSION['error'] = "Access denied. Only administrators can manage approval workflows.";
    header("Location: pending.php");
    exit;
}

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_workflow') {
        $workflow_name = trim($_POST['workflow_name']);
        $workflow_code = strtoupper(trim($_POST['workflow_code']));
        $module_name = trim($_POST['module_name']);
        $applies_to = $_POST['applies_to'];
        $min_amount = floatval($_POST['min_amount'] ?? 0);
        $max_amount = !empty($_POST['max_amount']) ? floatval($_POST['max_amount']) : null;
        $auto_approve_below = !empty($_POST['auto_approve_below']) ? floatval($_POST['auto_approve_below']) : null;
        
        if (empty($workflow_name) || empty($workflow_code)) {
            $errors[] = "Workflow name and code are required";
        } else {
            try {
                $insert_sql = "INSERT INTO approval_workflows 
                              (company_id, workflow_name, workflow_code, module_name, applies_to, 
                               min_amount, max_amount, auto_approve_below, is_active, created_by)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->execute([
                    $company_id, $workflow_name, $workflow_code, $module_name,
                    $applies_to, $min_amount, $max_amount, $auto_approve_below,
                    $_SESSION['user_id']
                ]);
                
                $success = "Workflow created successfully!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $errors[] = "Workflow code already exists";
                } else {
                    $errors[] = "Error: " . $e->getMessage();
                }
            }
        }
    }
    
    if ($action === 'add_level') {
        $workflow_id = intval($_POST['workflow_id']);
        $level_number = intval($_POST['level_number']);
        $level_name = trim($_POST['level_name']);
        $approver_type = $_POST['approver_type'];
        $role_id = !empty($_POST['role_id']) ? intval($_POST['role_id']) : null;
        $user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
        
        try {
            $insert_sql = "INSERT INTO approval_levels 
                          (workflow_id, level_number, level_name, approver_type, role_id, user_id)
                          VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([$workflow_id, $level_number, $level_name, $approver_type, $role_id, $user_id]);
            
            $success = "Approval level added successfully!";
        } catch (PDOException $e) {
            $errors[] = "Error adding level: " . $e->getMessage();
        }
    }
    
    if ($action === 'toggle_workflow') {
        $workflow_id = intval($_POST['workflow_id']);
        $is_active = intval($_POST['is_active']);
        
        try {
            $update_sql = "UPDATE approval_workflows SET is_active = ? WHERE workflow_id = ? AND company_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([$is_active, $workflow_id, $company_id]);
            
            $success = "Workflow " . ($is_active ? "activated" : "deactivated") . " successfully!";
        } catch (PDOException $e) {
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

// Fetch workflows
try {
    $workflows_sql = "SELECT w.*, 
                            COUNT(l.approval_level_id) as level_count,
                            u.full_name as created_by_name
                     FROM approval_workflows w
                     LEFT JOIN approval_levels l ON w.workflow_id = l.workflow_id
                     LEFT JOIN users u ON w.created_by = u.user_id
                     WHERE w.company_id = ?
                     GROUP BY w.workflow_id
                     ORDER BY w.created_at DESC";
    
    $stmt = $conn->prepare($workflows_sql);
    $stmt->execute([$company_id]);
    $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $workflows = [];
    $errors[] = "Error fetching workflows: " . $e->getMessage();
}

// Fetch roles for dropdown
try {
    $roles_sql = "SELECT role_id, role_name FROM system_roles WHERE is_system_role = 1 ORDER BY role_name";
    $roles_stmt = $conn->prepare($roles_sql);
    $roles_stmt->execute();
    $roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $roles = [];
}

// Fetch users for dropdown
try {
    $users_sql = "SELECT user_id, full_name, email FROM users 
                  WHERE company_id = ? AND is_active = 1 ORDER BY full_name";
    $users_stmt = $conn->prepare($users_sql);
    $users_stmt->execute([$company_id]);
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

$page_title = 'Approval Workflows';
require_once '../../includes/header.php';
?>

<style>
.workflow-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.1);
    margin-bottom: 25px;
    overflow: hidden;
    border-left: 5px solid #667eea;
}

.workflow-card.inactive {
    opacity: 0.6;
    border-left-color: #6c757d;
}

.workflow-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.workflow-body {
    padding: 25px;
}

.workflow-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.info-box {
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
    border-left: 3px solid #667eea;
}

.info-label {
    font-size: 11px;
    color: #6c757d;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 14px;
    color: #212529;
    font-weight: 600;
}

.level-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 15px;
    background: #e7f3ff;
    border-left: 4px solid #0066cc;
    border-radius: 6px;
    margin-bottom: 10px;
    font-size: 14px;
}

.level-number {
    background: #0066cc;
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.status-toggle {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}

.status-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 34px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #28a745;
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.form-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    padding: 25px;
    margin-bottom: 25px;
}

.form-section-title {
    font-size: 18px;
    font-weight: 700;
    color: #333;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #667eea;
}

.btn-create {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    font-weight: 700;
}

.btn-add-level {
    background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    color: white;
    border: none;
    padding: 10px 25px;
    border-radius: 8px;
    font-weight: 700;
}

.active-badge {
    background: #28a745;
    color: white;
    padding: 6px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 700;
}

.inactive-badge {
    background: #6c757d;
    color: white;
    padding: 6px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 700;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}

.empty-state i {
    font-size: 80px;
    color: #dee2e6;
    margin-bottom: 20px;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-project-diagram"></i> Approval Workflows</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="pending.php">Approvals</a></li>
                    <li class="breadcrumb-item active">Workflows</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <h5><i class="fas fa-ban"></i> Errors!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Create Workflow Form -->
        <div class="form-card">
            <div class="form-section-title">
                <i class="fas fa-plus-circle"></i> Create New Workflow
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_workflow">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Workflow Name <span style="color: red;">*</span></label>
                            <input type="text" name="workflow_name" class="form-control" required
                                   placeholder="e.g., Payment Approval Workflow">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Workflow Code <span style="color: red;">*</span></label>
                            <input type="text" name="workflow_code" class="form-control" required
                                   placeholder="e.g., PAY_APPROVAL_01">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Module Name</label>
                            <input type="text" name="module_name" class="form-control" 
                                   placeholder="e.g., Sales, Finance" value="Sales">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Applies To</label>
                            <select name="applies_to" class="form-control">
                                <option value="payment">Payment</option>
                                <option value="purchase_order">Purchase Order</option>
                                <option value="refund">Refund</option>
                                <option value="contract">Contract</option>
                                <option value="expense">Expense</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Minimum Amount (TZS)</label>
                            <input type="number" name="min_amount" class="form-control" 
                                   step="0.01" value="0">
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Maximum Amount (TZS)</label>
                            <input type="number" name="max_amount" class="form-control" 
                                   step="0.01" placeholder="Leave empty for unlimited">
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Auto-Approve Below (TZS)</label>
                            <input type="number" name="auto_approve_below" class="form-control" 
                                   step="0.01" placeholder="Optional">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-create">
                    <i class="fas fa-plus"></i> Create Workflow
                </button>
            </form>
        </div>

        <!-- Existing Workflows -->
        <h4 style="margin-bottom: 20px; font-weight: 700;">
            <i class="fas fa-list"></i> Existing Workflows (<?php echo count($workflows); ?>)
        </h4>

        <?php if (empty($workflows)): ?>
        <div class="workflow-card">
            <div class="empty-state">
                <i class="fas fa-project-diagram"></i>
                <h4>No Workflows Created</h4>
                <p>Create your first approval workflow using the form above.</p>
            </div>
        </div>
        <?php else: ?>

        <?php foreach ($workflows as $workflow): ?>
        <div class="workflow-card <?php echo $workflow['is_active'] ? '' : 'inactive'; ?>">
            <div class="workflow-header">
                <div>
                    <h5 style="margin: 0 0 5px 0; font-weight: 700;">
                        <?php echo htmlspecialchars($workflow['workflow_name']); ?>
                    </h5>
                    <div style="font-size: 14px; opacity: 0.9;">
                        Code: <?php echo htmlspecialchars($workflow['workflow_code']); ?> | 
                        Module: <?php echo htmlspecialchars($workflow['module_name']); ?>
                    </div>
                </div>
                <div>
                    <?php if ($workflow['is_active']): ?>
                        <span class="active-badge"><i class="fas fa-check-circle"></i> Active</span>
                    <?php else: ?>
                        <span class="inactive-badge"><i class="fas fa-ban"></i> Inactive</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="workflow-body">
                <div class="workflow-info">
                    <div class="info-box">
                        <div class="info-label">Applies To</div>
                        <div class="info-value">
                            <?php echo ucwords(str_replace('_', ' ', $workflow['applies_to'])); ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Amount Range</div>
                        <div class="info-value">
                            TZS <?php echo number_format($workflow['min_amount'], 0); ?> - 
                            <?php echo $workflow['max_amount'] ? 'TZS ' . number_format($workflow['max_amount'], 0) : 'Unlimited'; ?>
                        </div>
                    </div>

                    <?php if ($workflow['auto_approve_below']): ?>
                    <div class="info-box">
                        <div class="info-label">Auto-Approve Below</div>
                        <div class="info-value">
                            TZS <?php echo number_format($workflow['auto_approve_below'], 0); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="info-box">
                        <div class="info-label">Approval Levels</div>
                        <div class="info-value">
                            <?php echo $workflow['level_count']; ?> level(s)
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Created By</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($workflow['created_by_name'] ?? 'System'); ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Created At</div>
                        <div class="info-value">
                            <?php echo date('M d, Y', strtotime($workflow['created_at'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Approval Levels -->
                <?php
                try {
                    $levels_sql = "SELECT l.*, r.role_name, u.full_name as user_name
                                  FROM approval_levels l
                                  LEFT JOIN system_roles r ON l.role_id = r.role_id
                                  LEFT JOIN users u ON l.user_id = u.user_id
                                  WHERE l.workflow_id = ?
                                  ORDER BY l.level_number";
                    $levels_stmt = $conn->prepare($levels_sql);
                    $levels_stmt->execute([$workflow['workflow_id']]);
                    $levels = $levels_stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $levels = [];
                }
                ?>

                <?php if (!empty($levels)): ?>
                <div style="margin-top: 20px;">
                    <h6 style="font-weight: 700; margin-bottom: 15px;">
                        <i class="fas fa-layer-group"></i> Approval Levels
                    </h6>
                    <?php foreach ($levels as $level): ?>
                    <div class="level-badge">
                        <span class="level-number"><?php echo $level['level_number']; ?></span>
                        <div>
                            <strong><?php echo htmlspecialchars($level['level_name']); ?></strong><br>
                            <small style="color: #6c757d;">
                                <?php if ($level['approver_type'] === 'role'): ?>
                                    <i class="fas fa-users"></i> Role: <?php echo htmlspecialchars($level['role_name']); ?>
                                <?php elseif ($level['approver_type'] === 'user'): ?>
                                    <i class="fas fa-user"></i> User: <?php echo htmlspecialchars($level['user_name']); ?>
                                <?php else: ?>
                                    <i class="fas fa-user-shield"></i> Any Manager
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Actions -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e9ecef; display: flex; justify-content: space-between; align-items: center;">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="toggle_workflow">
                        <input type="hidden" name="workflow_id" value="<?php echo $workflow['workflow_id']; ?>">
                        <input type="hidden" name="is_active" value="<?php echo $workflow['is_active'] ? 0 : 1; ?>">
                        
                        <label class="status-toggle">
                            <input type="checkbox" <?php echo $workflow['is_active'] ? 'checked' : ''; ?>
                                   onchange="this.form.submit()">
                            <span class="slider"></span>
                        </label>
                        <span style="margin-left: 10px; font-weight: 600;">
                            <?php echo $workflow['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </form>

                    <button type="button" class="btn btn-add-level" 
                            onclick="showAddLevelModal(<?php echo $workflow['workflow_id']; ?>, '<?php echo htmlspecialchars($workflow['workflow_name']); ?>')">
                        <i class="fas fa-plus"></i> Add Level
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>

    </div>
</section>

<!-- Add Level Modal -->
<div class="modal fade" id="addLevelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i> Add Approval Level
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_level">
                    <input type="hidden" name="workflow_id" id="modal_workflow_id">
                    
                    <div class="alert alert-info">
                        <strong>Workflow:</strong> <span id="modal_workflow_name"></span>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Level Number <span style="color: red;">*</span></label>
                                <input type="number" name="level_number" class="form-control" 
                                       min="1" required placeholder="e.g., 1, 2, 3">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Level Name <span style="color: red;">*</span></label>
                                <input type="text" name="level_name" class="form-control" 
                                       required placeholder="e.g., Manager Approval">
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Approver Type <span style="color: red;">*</span></label>
                                <select name="approver_type" id="approver_type" class="form-control" required>
                                    <option value="">-- Select Type --</option>
                                    <option value="role">By Role</option>
                                    <option value="user">Specific User</option>
                                    <option value="any_manager">Any Manager</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-12" id="role_selector" style="display: none;">
                            <div class="form-group">
                                <label>Select Role</label>
                                <select name="role_id" class="form-control">
                                    <option value="">-- Select Role --</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['role_id']; ?>">
                                            <?php echo htmlspecialchars($role['role_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-12" id="user_selector" style="display: none;">
                            <div class="form-group">
                                <label>Select User</label>
                                <select name="user_id" class="form-control">
                                    <option value="">-- Select User --</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['user_id']; ?>">
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                            (<?php echo htmlspecialchars($user['email']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-add-level">
                        <i class="fas fa-plus"></i> Add Level
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAddLevelModal(workflowId, workflowName) {
    document.getElementById('modal_workflow_id').value = workflowId;
    document.getElementById('modal_workflow_name').textContent = workflowName;
    $('#addLevelModal').modal('show');
}

document.getElementById('approver_type').addEventListener('change', function() {
    const roleSelector = document.getElementById('role_selector');
    const userSelector = document.getElementById('user_selector');
    
    roleSelector.style.display = 'none';
    userSelector.style.display = 'none';
    
    if (this.value === 'role') {
        roleSelector.style.display = 'block';
    } else if (this.value === 'user') {
        userSelector.style.display = 'block';
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>