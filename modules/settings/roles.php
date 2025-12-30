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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'create_role') {
            // Validate required fields
            if (empty($_POST['role_name'])) {
                throw new Exception('Role name is required');
            }
            if (empty($_POST['role_code'])) {
                throw new Exception('Role code is required');
            }
            
            // Check if role code exists
            $check = $conn->prepare("SELECT role_id FROM system_roles WHERE role_code = ?");
            $check->execute([$_POST['role_code']]);
            if ($check->fetch()) {
                throw new Exception('Role code already exists');
            }
            
            // Insert role
            $stmt = $conn->prepare("
                INSERT INTO system_roles (
                    role_name, role_code, description, is_system_role, created_at
                ) VALUES (?, ?, ?, 0, NOW())
            ");
            
            $stmt->execute([
                $_POST['role_name'],
                $_POST['role_code'],
                $_POST['description'] ?? null
            ]);
            
            $role_id = $conn->lastInsertId();
            
            // Assign permissions if provided
            if (!empty($_POST['permissions'])) {
                $perm_stmt = $conn->prepare("
                    INSERT INTO role_permissions (role_id, permission_id) 
                    VALUES (?, ?)
                ");
                foreach ($_POST['permissions'] as $permission_id) {
                    $perm_stmt->execute([$role_id, $permission_id]);
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Role created successfully']);
            
        } elseif ($_POST['action'] === 'update_role') {
            $role_id = $_POST['role_id'];
            
            // Check if it's a system role
            $check = $conn->prepare("SELECT is_system_role FROM system_roles WHERE role_id = ?");
            $check->execute([$role_id]);
            $role = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$role) {
                throw new Exception('Role not found');
            }
            
            if ($role['is_system_role']) {
                throw new Exception('Cannot modify system roles');
            }
            
            // Check if role code exists for other roles
            $check = $conn->prepare("SELECT role_id FROM system_roles WHERE role_code = ? AND role_id != ?");
            $check->execute([$_POST['role_code'], $role_id]);
            if ($check->fetch()) {
                throw new Exception('Role code already exists');
            }
            
            // Update role
            $stmt = $conn->prepare("
                UPDATE system_roles SET 
                    role_name = ?, 
                    role_code = ?, 
                    description = ?
                WHERE role_id = ?
            ");
            
            $stmt->execute([
                $_POST['role_name'],
                $_POST['role_code'],
                $_POST['description'] ?? null,
                $role_id
            ]);
            
            // Update permissions
            if (isset($_POST['permissions'])) {
                // Delete existing permissions
                $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $stmt->execute([$role_id]);
                
                // Insert new permissions
                if (!empty($_POST['permissions'])) {
                    $perm_stmt = $conn->prepare("
                        INSERT INTO role_permissions (role_id, permission_id) 
                        VALUES (?, ?)
                    ");
                    foreach ($_POST['permissions'] as $permission_id) {
                        $perm_stmt->execute([$role_id, $permission_id]);
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
            
        } elseif ($_POST['action'] === 'delete_role') {
            $role_id = $_POST['role_id'];
            
            // Check if it's a system role
            $check = $conn->prepare("SELECT is_system_role FROM system_roles WHERE role_id = ?");
            $check->execute([$role_id]);
            $role = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$role) {
                throw new Exception('Role not found');
            }
            
            if ($role['is_system_role']) {
                throw new Exception('Cannot delete system roles');
            }
            
            // Check if role is assigned to users
            $check = $conn->prepare("SELECT COUNT(*) as count FROM user_roles WHERE role_id = ?");
            $check->execute([$role_id]);
            $result = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('Cannot delete role that is assigned to users. Please unassign users first.');
            }
            
            // Delete role permissions
            $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$role_id]);
            
            // Delete role
            $stmt = $conn->prepare("DELETE FROM system_roles WHERE role_id = ?");
            $stmt->execute([$role_id]);
            
            echo json_encode(['success' => true, 'message' => 'Role deleted successfully']);
            
        } elseif ($_POST['action'] === 'get_role') {
            $role_id = $_POST['role_id'];
            
            $stmt = $conn->prepare("
                SELECT r.*, GROUP_CONCAT(rp.permission_id) as permission_ids
                FROM system_roles r
                LEFT JOIN role_permissions rp ON r.role_id = rp.role_id
                WHERE r.role_id = ?
                GROUP BY r.role_id
            ");
            $stmt->execute([$role_id]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$role) {
                throw new Exception('Role not found');
            }
            
            // Convert permission_ids to array
            $role['permissions'] = $role['permission_ids'] ? explode(',', $role['permission_ids']) : [];
            
            echo json_encode(['success' => true, 'role' => $role]);
            
        } elseif ($_POST['action'] === 'create_permission') {
            // Validate required fields
            if (empty($_POST['module_name']) || empty($_POST['permission_code']) || empty($_POST['permission_name'])) {
                throw new Exception('All fields are required');
            }
            
            // Check if permission code exists
            $check = $conn->prepare("SELECT permission_id FROM permissions WHERE permission_code = ?");
            $check->execute([$_POST['permission_code']]);
            if ($check->fetch()) {
                throw new Exception('Permission code already exists');
            }
            
            // Insert permission
            $stmt = $conn->prepare("
                INSERT INTO permissions (
                    module_name, permission_code, permission_name, description
                ) VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $_POST['module_name'],
                $_POST['permission_code'],
                $_POST['permission_name'],
                $_POST['description'] ?? null
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Permission created successfully']);
            
        } elseif ($_POST['action'] === 'update_permission') {
            $permission_id = $_POST['permission_id'];
            
            // Check if permission code exists for other permissions
            $check = $conn->prepare("SELECT permission_id FROM permissions WHERE permission_code = ? AND permission_id != ?");
            $check->execute([$_POST['permission_code'], $permission_id]);
            if ($check->fetch()) {
                throw new Exception('Permission code already exists');
            }
            
            // Update permission
            $stmt = $conn->prepare("
                UPDATE permissions SET 
                    module_name = ?,
                    permission_code = ?, 
                    permission_name = ?, 
                    description = ?
                WHERE permission_id = ?
            ");
            
            $stmt->execute([
                $_POST['module_name'],
                $_POST['permission_code'],
                $_POST['permission_name'],
                $_POST['description'] ?? null,
                $permission_id
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Permission updated successfully']);
            
        } elseif ($_POST['action'] === 'delete_permission') {
            $permission_id = $_POST['permission_id'];
            
            // Check if permission is assigned to roles
            $check = $conn->prepare("SELECT COUNT(*) as count FROM role_permissions WHERE permission_id = ?");
            $check->execute([$permission_id]);
            $result = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('Cannot delete permission that is assigned to roles. Please unassign from roles first.');
            }
            
            // Delete permission
            $stmt = $conn->prepare("DELETE FROM permissions WHERE permission_id = ?");
            $stmt->execute([$permission_id]);
            
            echo json_encode(['success' => true, 'message' => 'Permission deleted successfully']);
            
        } elseif ($_POST['action'] === 'get_permission') {
            $permission_id = $_POST['permission_id'];
            
            $stmt = $conn->prepare("SELECT * FROM permissions WHERE permission_id = ?");
            $stmt->execute([$permission_id]);
            $permission = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$permission) {
                throw new Exception('Permission not found');
            }
            
            echo json_encode(['success' => true, 'permission' => $permission]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch all roles with user counts
try {
    $stmt = $conn->query("
        SELECT 
            r.*,
            COUNT(DISTINCT ur.user_id) as user_count,
            COUNT(DISTINCT rp.permission_id) as permission_count
        FROM system_roles r
        LEFT JOIN user_roles ur ON r.role_id = ur.role_id
        LEFT JOIN role_permissions rp ON r.role_id = rp.role_id
        GROUP BY r.role_id
        ORDER BY r.is_system_role DESC, r.role_name ASC
    ");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all permissions grouped by module
    $permissions_stmt = $conn->query("
        SELECT * FROM permissions 
        ORDER BY module_name, permission_name
    ");
    $all_permissions = $permissions_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group permissions by module
    $permissions_by_module = [];
    foreach ($all_permissions as $perm) {
        $permissions_by_module[$perm['module_name']][] = $perm;
    }
    
    // Get role statistics
    $stats = $conn->query("
        SELECT 
            COUNT(DISTINCT r.role_id) as total_roles,
            COUNT(DISTINCT CASE WHEN r.is_system_role = 1 THEN r.role_id END) as system_roles,
            COUNT(DISTINCT CASE WHEN r.is_system_role = 0 THEN r.role_id END) as custom_roles,
            COUNT(DISTINCT p.permission_id) as total_permissions
        FROM system_roles r
        CROSS JOIN permissions p
    ")->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Error fetching roles: " . $e->getMessage();
    $roles = [];
    $all_permissions = [];
    $permissions_by_module = [];
    $stats = ['total_roles' => 0, 'system_roles' => 0, 'custom_roles' => 0, 'total_permissions' => 0];
}

$page_title = 'Roles & Permissions';
require_once '../../includes/header.php';
?>

<style>
.role-card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: transform 0.2s, box-shadow 0.2s;
    height: 100%;
}

.role-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.role-card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 12px 12px 0 0;
    padding: 1.5rem;
}

.role-card.system-role .role-card-header {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.stats-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
}

.stats-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin-bottom: 1rem;
}

.permission-group {
    background: #f9fafb;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.permission-group-header {
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #3b82f6;
}

.permission-checkbox {
    padding: 0.5rem;
    background: #fff;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
    margin-bottom: 0.5rem;
    transition: all 0.2s;
}

.permission-checkbox:hover {
    background: #f3f4f6;
    border-color: #3b82f6;
}

.form-check-input:checked {
    background-color: #3b82f6;
    border-color: #3b82f6;
}

.modal-content {
    border-radius: 16px;
    border: none;
}

.modal-header {
    border-radius: 16px 16px 0 0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-bottom: none;
}

.modal-title {
    font-weight: 700;
}

.nav-pills .nav-link {
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.2s;
}

.nav-pills .nav-link.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.badge-system {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    padding: 4px 12px;
    border-radius: 12px;
    font-weight: 600;
}

.badge-custom {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    padding: 4px 12px;
    border-radius: 12px;
    font-weight: 600;
}

.form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 0.625rem 0.875rem;
}

.form-control:focus, .form-select:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.btn {
    border-radius: 8px;
    padding: 0.625rem 1.25rem;
    font-weight: 500;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-shield text-primary me-2"></i>Roles & Permissions
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage system roles and their permissions</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#permissionModal" onclick="resetPermissionForm()">
                        <i class="fas fa-key me-2"></i>Add Permission
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#roleModal" onclick="resetRoleForm()">
                        <i class="fas fa-plus me-2"></i>Add Role
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($stats['total_roles']); ?></h3>
                        <p class="text-muted mb-0">Total Roles</p>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-danger bg-opacity-10 text-danger">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($stats['system_roles']); ?></h3>
                        <p class="text-muted mb-0">System Roles</p>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-info bg-opacity-10 text-info">
                            <i class="fas fa-user-tag"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($stats['custom_roles']); ?></h3>
                        <p class="text-muted mb-0">Custom Roles</p>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-key"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($stats['total_permissions']); ?></h3>
                        <p class="text-muted mb-0">Total Permissions</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabs Navigation -->
        <ul class="nav nav-pills mb-4" id="rolesTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="roles-tab" data-bs-toggle="pill" data-bs-target="#roles" type="button" role="tab">
                    <i class="fas fa-users-cog me-2"></i>Roles
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="permissions-tab" data-bs-toggle="pill" data-bs-target="#permissions" type="button" role="tab">
                    <i class="fas fa-key me-2"></i>Permissions
                </button>
            </li>
        </ul>
        
        <!-- Tab Content -->
        <div class="tab-content" id="rolesTabContent">
            
            <!-- Roles Tab -->
            <div class="tab-pane fade show active" id="roles" role="tabpanel">
                <div class="row g-4">
                    <?php if (empty($roles)): ?>
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body text-center py-5 text-muted">
                                <i class="fas fa-users-cog fa-3x mb-3 d-block"></i>
                                <p class="mb-0">No roles found</p>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                        <?php foreach ($roles as $role): ?>
                        <div class="col-xl-4 col-md-6">
                            <div class="card role-card <?php echo $role['is_system_role'] ? 'system-role' : ''; ?>">
                                <div class="role-card-header">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5 class="mb-1 fw-bold">
                                                <?php echo htmlspecialchars($role['role_name']); ?>
                                            </h5>
                                            <small class="opacity-75">
                                                <i class="fas fa-code me-1"></i><?php echo htmlspecialchars($role['role_code']); ?>
                                            </small>
                                        </div>
                                        <?php if ($role['is_system_role']): ?>
                                            <span class="badge badge-system">
                                                <i class="fas fa-lock me-1"></i>System
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-custom">
                                                <i class="fas fa-edit me-1"></i>Custom
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if ($role['description']): ?>
                                    <p class="text-muted small mb-3">
                                        <?php echo htmlspecialchars($role['description']); ?>
                                    </p>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <i class="fas fa-users text-primary me-2"></i>
                                            <strong><?php echo number_format($role['user_count']); ?></strong>
                                            <small class="text-muted">Users</small>
                                        </div>
                                        <div>
                                            <i class="fas fa-key text-success me-2"></i>
                                            <strong><?php echo number_format($role['permission_count']); ?></strong>
                                            <small class="text-muted">Permissions</small>
                                        </div>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewRoleDetails(<?php echo $role['role_id']; ?>)">
                                            <i class="fas fa-eye me-2"></i>View Details
                                        </button>
                                        <?php if (!$role['is_system_role']): ?>
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-success" onclick="editRole(<?php echo $role['role_id']; ?>)">
                                                <i class="fas fa-edit me-2"></i>Edit
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteRole(<?php echo $role['role_id']; ?>, '<?php echo htmlspecialchars($role['role_name']); ?>')">
                                                <i class="fas fa-trash me-2"></i>Delete
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Permissions Tab -->
            <div class="tab-pane fade" id="permissions" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <div class="row align-items-center">
                            <div class="col">
                                <h5 class="mb-0">
                                    <i class="fas fa-key me-2"></i>All Permissions
                                </h5>
                            </div>
                            <div class="col-auto">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white border-end-0">
                                        <i class="fas fa-search text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0" id="searchPermissions" placeholder="Search permissions...">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="permissionsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Module</th>
                                        <th>Permission Code</th>
                                        <th>Permission Name</th>
                                        <th>Description</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($all_permissions)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">
                                            <i class="fas fa-key fa-3x mb-3 d-block"></i>
                                            <p class="mb-0">No permissions found</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($all_permissions as $permission): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?php echo htmlspecialchars($permission['module_name']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($permission['permission_code']); ?></code>
                                            </td>
                                            <td class="fw-semibold">
                                                <?php echo htmlspecialchars($permission['permission_name']); ?>
                                            </td>
                                            <td class="text-muted small">
                                                <?php echo htmlspecialchars($permission['description'] ?? '-'); ?>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-2 justify-content-center">
                                                    <button class="btn btn-sm btn-outline-primary" 
                                                            onclick="editPermission(<?php echo $permission['permission_id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger" 
                                                            onclick="deletePermission(<?php echo $permission['permission_id']; ?>, '<?php echo htmlspecialchars($permission['permission_name']); ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
        
    </div>
</section>

<!-- Role Modal -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roleModalTitle">
                    <i class="fas fa-plus me-2"></i>Add New Role
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="roleForm">
                <div class="modal-body">
                    <input type="hidden" name="role_id" id="role_id">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" id="roleFormAction" value="create_role">
                    
                    <!-- Basic Information -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-info-circle me-2"></i>Basic Information
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Role Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="role_name" id="role_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Role Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="role_code" id="role_code" required 
                                       placeholder="e.g., SALES_MANAGER">
                                <small class="text-muted">Uppercase, underscores only</small>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" id="role_description" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Permissions -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-key me-2"></i>Assign Permissions
                        </h6>
                        <div id="permissionsContainer">
                            <?php if (!empty($permissions_by_module)): ?>
                                <?php foreach ($permissions_by_module as $module => $perms): ?>
                                <div class="permission-group">
                                    <div class="permission-group-header">
                                        <i class="fas fa-layer-group me-2"></i><?php echo htmlspecialchars($module); ?>
                                        <button type="button" class="btn btn-sm btn-link float-end" 
                                                onclick="toggleModulePermissions('<?php echo htmlspecialchars($module); ?>')">
                                            Select All
                                        </button>
                                    </div>
                                    <div class="row g-2">
                                        <?php foreach ($perms as $perm): ?>
                                        <div class="col-md-6">
                                            <div class="permission-checkbox">
                                                <div class="form-check">
                                                    <input class="form-check-input module-<?php echo htmlspecialchars($module); ?>" 
                                                           type="checkbox" name="permissions[]" 
                                                           value="<?php echo $perm['permission_id']; ?>" 
                                                           id="perm_<?php echo $perm['permission_id']; ?>">
                                                    <label class="form-check-label" for="perm_<?php echo $perm['permission_id']; ?>">
                                                        <strong><?php echo htmlspecialchars($perm['permission_name']); ?></strong>
                                                        <?php if ($perm['description']): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($perm['description']); ?></small>
                                                        <?php endif; ?>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>No permissions available. Please create permissions first.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Role
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Permission Modal -->
<div class="modal fade" id="permissionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="permissionModalTitle">
                    <i class="fas fa-key me-2"></i>Add New Permission
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="permissionForm">
                <div class="modal-body">
                    <input type="hidden" name="permission_id" id="permission_id">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" id="permissionFormAction" value="create_permission">
                    
                    <div class="mb-3">
                        <label class="form-label">Module Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="module_name" id="module_name" required>
                        <small class="text-muted">e.g., Projects, Sales, Finance</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Permission Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="permission_code" id="permission_code" required>
                        <small class="text-muted">e.g., projects.create, sales.view</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Permission Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="permission_name" id="permission_name" required>
                        <small class="text-muted">e.g., Create Projects, View Sales</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="permission_description" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Save Permission
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Role Details Modal -->
<div class="modal fade" id="roleDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2"></i>Role Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="roleDetailsContent">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Search permissions
document.getElementById('searchPermissions').addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const tableRows = document.querySelectorAll('#permissionsTable tbody tr');
    
    tableRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Reset role form
function resetRoleForm() {
    document.getElementById('roleForm').reset();
    document.getElementById('role_id').value = '';
    document.getElementById('roleFormAction').value = 'create_role';
    document.getElementById('roleModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Add New Role';
    
    // Uncheck all permissions
    document.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
        checkbox.checked = false;
    });
}

// Reset permission form
function resetPermissionForm() {
    document.getElementById('permissionForm').reset();
    document.getElementById('permission_id').value = '';
    document.getElementById('permissionFormAction').value = 'create_permission';
    document.getElementById('permissionModalTitle').innerHTML = '<i class="fas fa-key me-2"></i>Add New Permission';
}

// Edit role
function editRole(roleId) {
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_role',
            role_id: roleId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const role = response.role;
                
                document.getElementById('role_id').value = role.role_id;
                document.getElementById('roleFormAction').value = 'update_role';
                document.getElementById('roleModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Role';
                
                document.getElementById('role_name').value = role.role_name;
                document.getElementById('role_code').value = role.role_code;
                document.getElementById('role_description').value = role.description || '';
                
                // Check permissions
                document.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
                    checkbox.checked = role.permissions.includes(checkbox.value);
                });
                
                const modal = new bootstrap.Modal(document.getElementById('roleModal'));
                modal.show();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Error loading role data');
        }
    });
}

// Save role
document.getElementById('roleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Error saving role');
        }
    });
});

// Delete role
function deleteRole(roleId, roleName) {
    if (confirm(`Are you sure you want to delete the role "${roleName}"?`)) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'delete_role',
                role_id: roleId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('Error deleting role');
            }
        });
    }
}

// View role details
function viewRoleDetails(roleId) {
    $('#roleDetailsContent').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>');
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_role',
            role_id: roleId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const role = response.role;
                let html = `
                    <div class="mb-3">
                        <h6 class="fw-bold">Role Name:</h6>
                        <p>${role.role_name}</p>
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold">Role Code:</h6>
                        <code>${role.role_code}</code>
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold">Description:</h6>
                        <p>${role.description || 'No description'}</p>
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold">Type:</h6>
                        ${role.is_system_role == 1 ? '<span class="badge bg-danger">System Role</span>' : '<span class="badge bg-info">Custom Role</span>'}
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold">Assigned Permissions:</h6>
                `;
                
                if (role.permissions.length > 0) {
                    html += '<ul class="list-group">';
                    // This would require fetching permission details
                    role.permissions.forEach(permId => {
                        html += `<li class="list-group-item">Permission ID: ${permId}</li>`;
                    });
                    html += '</ul>';
                } else {
                    html += '<p class="text-muted">No permissions assigned</p>';
                }
                
                html += '</div>';
                $('#roleDetailsContent').html(html);
                
                const modal = new bootstrap.Modal(document.getElementById('roleDetailsModal'));
                modal.show();
            } else {
                $('#roleDetailsContent').html('<div class="alert alert-danger">Error loading role details</div>');
            }
        },
        error: function() {
            $('#roleDetailsContent').html('<div class="alert alert-danger">Error loading role details</div>');
        }
    });
}

// Edit permission
function editPermission(permissionId) {
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_permission',
            permission_id: permissionId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const perm = response.permission;
                
                document.getElementById('permission_id').value = perm.permission_id;
                document.getElementById('permissionFormAction').value = 'update_permission';
                document.getElementById('permissionModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Permission';
                
                document.getElementById('module_name').value = perm.module_name;
                document.getElementById('permission_code').value = perm.permission_code;
                document.getElementById('permission_name').value = perm.permission_name;
                document.getElementById('permission_description').value = perm.description || '';
                
                const modal = new bootstrap.Modal(document.getElementById('permissionModal'));
                modal.show();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Error loading permission data');
        }
    });
}

// Save permission
document.getElementById('permissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Error saving permission');
        }
    });
});

// Delete permission
function deletePermission(permissionId, permissionName) {
    if (confirm(`Are you sure you want to delete the permission "${permissionName}"?`)) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'delete_permission',
                permission_id: permissionId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('Error deleting permission');
            }
        });
    }
}

// Toggle all permissions for a module
function toggleModulePermissions(module) {
    const checkboxes = document.querySelectorAll('.module-' + module);
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = !allChecked;
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>