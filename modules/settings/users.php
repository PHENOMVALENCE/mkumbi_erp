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
        if ($_POST['action'] === 'create_user') {
            // Validate required fields
            $required = ['username', 'email', 'password', 'first_name', 'last_name', 'phone1'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
                }
            }
            
            // Check if username exists
            $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND company_id = ?");
            $check->execute([$_POST['username'], $company_id]);
            if ($check->fetch()) {
                throw new Exception('Username already exists');
            }
            
            // Check if email exists
            $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND company_id = ?");
            $check->execute([$_POST['email'], $company_id]);
            if ($check->fetch()) {
                throw new Exception('Email already exists');
            }
            
            // Store password as plain text (NOT RECOMMENDED FOR PRODUCTION!)
            $password = $_POST['password'];
            
            // Insert user
            $stmt = $conn->prepare("
                INSERT INTO users (
                    company_id, username, email, password_hash, 
                    first_name, middle_name, last_name, 
                    phone1, phone2, gender, date_of_birth, national_id,
                    region, district, ward, village, street_address,
                    is_active, is_admin, is_super_admin, can_get_commission,
                    created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, 
                    ?, ?, ?, 
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, NOW()
                )
            ");
            
            $stmt->execute([
                $company_id,
                $_POST['username'],
                $_POST['email'],
                $password, // Plain text password
                $_POST['first_name'],
                $_POST['middle_name'] ?? null,
                $_POST['last_name'],
                $_POST['phone1'],
                $_POST['phone2'] ?? null,
                $_POST['gender'] ?? null,
                $_POST['date_of_birth'] ?? null,
                $_POST['national_id'] ?? null,
                $_POST['region'] ?? null,
                $_POST['district'] ?? null,
                $_POST['ward'] ?? null,
                $_POST['village'] ?? null,
                $_POST['street_address'] ?? null,
                isset($_POST['is_active']) ? 1 : 0,
                isset($_POST['is_admin']) ? 1 : 0,
                isset($_POST['is_super_admin']) ? 1 : 0,
                isset($_POST['can_get_commission']) ? 1 : 0,
                $_SESSION['user_id']
            ]);
            
            $user_id = $conn->lastInsertId();
            
            // Assign roles if provided
            if (!empty($_POST['roles'])) {
                $role_stmt = $conn->prepare("
                    INSERT INTO user_roles (user_id, role_id, assigned_by, assigned_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                foreach ($_POST['roles'] as $role_id) {
                    $role_stmt->execute([$user_id, $role_id, $_SESSION['user_id']]);
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'User created successfully']);
            
        } elseif ($_POST['action'] === 'update_user') {
            $user_id = $_POST['user_id'];
            
            // Check if username exists for other users
            $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND company_id = ? AND user_id != ?");
            $check->execute([$_POST['username'], $company_id, $user_id]);
            if ($check->fetch()) {
                throw new Exception('Username already exists');
            }
            
            // Check if email exists for other users
            $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND company_id = ? AND user_id != ?");
            $check->execute([$_POST['email'], $company_id, $user_id]);
            if ($check->fetch()) {
                throw new Exception('Email already exists');
            }
            
            // Update user
            $sql = "
                UPDATE users SET 
                    username = ?, email = ?, 
                    first_name = ?, middle_name = ?, last_name = ?,
                    phone1 = ?, phone2 = ?, gender = ?, date_of_birth = ?, national_id = ?,
                    region = ?, district = ?, ward = ?, village = ?, street_address = ?,
                    is_active = ?, is_admin = ?, is_super_admin = ?, can_get_commission = ?,
                    updated_at = NOW()
                WHERE user_id = ? AND company_id = ?
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $_POST['username'],
                $_POST['email'],
                $_POST['first_name'],
                $_POST['middle_name'] ?? null,
                $_POST['last_name'],
                $_POST['phone1'],
                $_POST['phone2'] ?? null,
                $_POST['gender'] ?? null,
                $_POST['date_of_birth'] ?? null,
                $_POST['national_id'] ?? null,
                $_POST['region'] ?? null,
                $_POST['district'] ?? null,
                $_POST['ward'] ?? null,
                $_POST['village'] ?? null,
                $_POST['street_address'] ?? null,
                isset($_POST['is_active']) ? 1 : 0,
                isset($_POST['is_admin']) ? 1 : 0,
                isset($_POST['is_super_admin']) ? 1 : 0,
                isset($_POST['can_get_commission']) ? 1 : 0,
                $user_id,
                $company_id
            ]);
            
            // Update password if provided (plain text)
            if (!empty($_POST['password'])) {
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ? AND company_id = ?");
                $stmt->execute([$_POST['password'], $user_id, $company_id]);
            }
            
            // Update roles
            if (isset($_POST['roles'])) {
                // Delete existing roles
                $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                // Insert new roles
                if (!empty($_POST['roles'])) {
                    $role_stmt = $conn->prepare("
                        INSERT INTO user_roles (user_id, role_id, assigned_by, assigned_at) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    foreach ($_POST['roles'] as $role_id) {
                        $role_stmt->execute([$user_id, $role_id, $_SESSION['user_id']]);
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'User updated successfully']);
            
        } elseif ($_POST['action'] === 'delete_user') {
            $user_id = $_POST['user_id'];
            
            // Prevent deleting self
            if ($user_id == $_SESSION['user_id']) {
                throw new Exception('Cannot delete your own account');
            }
            
            // Check if user belongs to company
            $check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND company_id = ?");
            $check->execute([$user_id, $company_id]);
            if (!$check->fetch()) {
                throw new Exception('User not found');
            }
            
            // Soft delete - just deactivate
            $stmt = $conn->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE user_id = ? AND company_id = ?");
            $stmt->execute([$user_id, $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'User deactivated successfully']);
            
        } elseif ($_POST['action'] === 'toggle_status') {
            $user_id = $_POST['user_id'];
            
            // Prevent toggling self
            if ($user_id == $_SESSION['user_id']) {
                throw new Exception('Cannot change your own status');
            }
            
            $stmt = $conn->prepare("
                UPDATE users 
                SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() 
                WHERE user_id = ? AND company_id = ?
            ");
            $stmt->execute([$user_id, $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'User status updated']);
            
        } elseif ($_POST['action'] === 'reset_password') {
            $user_id = $_POST['user_id'];
            $new_password = $_POST['new_password'];
            
            if (strlen($new_password) < 6) {
                throw new Exception('Password must be at least 6 characters');
            }
            
            // Store as plain text
            $stmt = $conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ? AND company_id = ?");
            $stmt->execute([$new_password, $user_id, $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
            
        } elseif ($_POST['action'] === 'get_user') {
            $user_id = $_POST['user_id'];
            
            $stmt = $conn->prepare("
                SELECT u.*, GROUP_CONCAT(ur.role_id) as role_ids
                FROM users u
                LEFT JOIN user_roles ur ON u.user_id = ur.user_id
                WHERE u.user_id = ? AND u.company_id = ?
                GROUP BY u.user_id
            ");
            $stmt->execute([$user_id, $company_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Convert role_ids to array
            $user['roles'] = $user['role_ids'] ? explode(',', $user['role_ids']) : [];
            
            echo json_encode(['success' => true, 'user' => $user]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch all users with their roles
try {
    $stmt = $conn->prepare("
        SELECT 
            u.*,
            GROUP_CONCAT(DISTINCT sr.role_name SEPARATOR ', ') as roles,
            creator.full_name as created_by_name
        FROM users u
        LEFT JOIN user_roles ur ON u.user_id = ur.user_id
        LEFT JOIN system_roles sr ON ur.role_id = sr.role_id
        LEFT JOIN users creator ON u.created_by = creator.user_id
        WHERE u.company_id = ?
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute([$company_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all available roles
    $roles_stmt = $conn->query("SELECT * FROM system_roles ORDER BY role_name");
    $available_roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user statistics
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_users,
            SUM(CASE WHEN is_admin = 1 THEN 1 ELSE 0 END) as admin_users
        FROM users 
        WHERE company_id = $company_id
    ")->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Error fetching users: " . $e->getMessage();
    $users = [];
    $available_roles = [];
    $stats = ['total_users' => 0, 'active_users' => 0, 'inactive_users' => 0, 'admin_users' => 0];
}

$page_title = 'User Management';
require_once '../../includes/header.php';
?>

<!-- Rest of the HTML code remains exactly the same -->
<!-- I'm keeping all the HTML, CSS, and JavaScript exactly as it was in your original file -->

<style>
.user-avatar-large {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 1rem;
}

.user-card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: transform 0.2s, box-shadow 0.2s;
}

.user-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
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

.role-badge {
    font-size: 11px;
    padding: 4px 10px;
    border-radius: 12px;
    font-weight: 500;
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

.table-actions {
    display: flex;
    gap: 0.5rem;
}

.checkbox-group {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}

.form-check {
    padding: 0.75rem;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    transition: all 0.2s;
}

.form-check:hover {
    background: #f3f4f6;
    border-color: #3b82f6;
}

.form-check-input:checked {
    background-color: #3b82f6;
    border-color: #3b82f6;
}

.user-status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-users text-primary me-2"></i>User Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage system users, roles and permissions</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="resetUserForm()">
                        <i class="fas fa-user-plus me-2"></i>Add New User
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
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($stats['total_users']); ?></h3>
                        <p class="text-muted mb-0">Total Users</p>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($stats['active_users']); ?></h3>
                        <p class="text-muted mb-0">Active Users</p>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-danger bg-opacity-10 text-danger">
                            <i class="fas fa-user-times"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($stats['inactive_users']); ?></h3>
                        <p class="text-muted mb-0">Inactive Users</p>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($stats['admin_users']); ?></h3>
                        <p class="text-muted mb-0">Admin Users</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Users Table -->
        <div class="card shadow-sm">
            <div class="card-header bg-white border-bottom">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>All Users
                        </h5>
                    </div>
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" id="searchInput" placeholder="Search users...">
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="usersTable">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Roles</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fas fa-users fa-3x mb-3 d-block"></i>
                                    <p class="mb-0">No users found</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar bg-primary bg-opacity-10 text-primary me-3" 
                                                 style="width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                                                <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                <?php if ($user['is_admin']): ?>
                                                    <small class="badge bg-warning">Admin</small>
                                                <?php endif; ?>
                                                <?php if ($user['is_super_admin']): ?>
                                                    <small class="badge bg-danger">Super Admin</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-monospace"><?php echo htmlspecialchars($user['username']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['phone1']); ?></td>
                                    <td>
                                        <?php if ($user['roles']): ?>
                                            <?php 
                                            $roles = explode(', ', $user['roles']);
                                            foreach ($roles as $role): 
                                            ?>
                                                <span class="role-badge badge bg-info me-1"><?php echo htmlspecialchars($role); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">No roles</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="user-status-badge bg-success bg-opacity-10 text-success">
                                                <i class="fas fa-check-circle me-1"></i>Active
                                            </span>
                                        <?php else: ?>
                                            <span class="user-status-badge bg-danger bg-opacity-10 text-danger">
                                                <i class="fas fa-times-circle me-1"></i>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="table-actions justify-content-center">
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="editUser(<?php echo $user['user_id']; ?>)" 
                                                    title="Edit User">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-info" 
                                                    onclick="resetUserPassword(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')" 
                                                    title="Reset Password">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-sm btn-outline-warning" 
                                                    onclick="toggleUserStatus(<?php echo $user['user_id']; ?>, <?php echo $user['is_active']; ?>)" 
                                                    title="Toggle Status">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')" 
                                                    title="Delete User">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
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
</section>

<!-- User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">
                    <i class="fas fa-user-plus me-2"></i>Add New User
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="userForm">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="user_id">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" id="formAction" value="create_user">
                    
                    <!-- Account Information -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-user-lock me-2"></i>Account Information
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" id="username" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" id="email" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Password <span class="text-danger" id="passwordRequired">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility()">
                                        <i class="fas fa-eye" id="passwordIcon"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Min 6 characters. Leave blank to keep current password when editing.</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Personal Information -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-user me-2"></i>Personal Information
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" id="first_name" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" id="middle_name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" id="last_name" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Gender</label>
                                <select class="form-select" name="gender" id="gender">
                                    <option value="">Select Gender</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" id="date_of_birth">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">National ID</label>
                                <input type="text" class="form-control" name="national_id" id="national_id">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Phone 1 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="phone1" id="phone1" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Phone 2</label>
                                <input type="text" class="form-control" name="phone2" id="phone2">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Address Information -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-map-marker-alt me-2"></i>Address Information
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Region</label>
                                <input type="text" class="form-control" name="region" id="region">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">District</label>
                                <input type="text" class="form-control" name="district" id="district">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Ward</label>
                                <input type="text" class="form-control" name="ward" id="ward">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Village</label>
                                <input type="text" class="form-control" name="village" id="village">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Street Address</label>
                                <textarea class="form-control" name="street_address" id="street_address" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Roles & Permissions -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-user-shield me-2"></i>Roles & Permissions
                        </h6>
                        <div class="checkbox-group" id="rolesContainer">
                            <?php foreach ($available_roles as $role): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="roles[]" 
                                       value="<?php echo $role['role_id']; ?>" 
                                       id="role_<?php echo $role['role_id']; ?>">
                                <label class="form-check-label fw-semibold" for="role_<?php echo $role['role_id']; ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                    <?php if ($role['description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($role['description']); ?></small>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- System Permissions -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-cogs me-2"></i>System Permissions
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                    <label class="form-check-label fw-semibold" for="is_active">
                                        <i class="fas fa-check-circle text-success me-1"></i>Active Account
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_admin" id="is_admin">
                                    <label class="form-check-label fw-semibold" for="is_admin">
                                        <i class="fas fa-user-shield text-warning me-1"></i>Admin User
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_super_admin" id="is_super_admin">
                                    <label class="form-check-label fw-semibold" for="is_super_admin">
                                        <i class="fas fa-crown text-danger me-1"></i>Super Admin
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="can_get_commission" id="can_get_commission">
                                    <label class="form-check-label fw-semibold" for="can_get_commission">
                                        <i class="fas fa-percent text-info me-1"></i>Can Get Commission
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-key me-2"></i>Reset Password
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="resetPasswordForm">
                <div class="modal-body">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="reset_user_id">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        You are about to reset password for: <strong id="reset_user_name"></strong>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="new_password" id="new_password" required minlength="6">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleResetPasswordVisibility()">
                                <i class="fas fa-eye" id="resetPasswordIcon"></i>
                            </button>
                        </div>
                        <small class="text-muted">Minimum 6 characters required</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-key me-2"></i>Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Search functionality
document.getElementById('searchInput').addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const tableRows = document.querySelectorAll('#usersTable tbody tr');
    
    tableRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Reset form
function resetUserForm() {
    document.getElementById('userForm').reset();
    document.getElementById('user_id').value = '';
    document.getElementById('formAction').value = 'create_user';
    document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-plus me-2"></i>Add New User';
    document.getElementById('password').required = true;
    document.getElementById('passwordRequired').style.display = 'inline';
    
    // Uncheck all role checkboxes
    document.querySelectorAll('input[name="roles[]"]').forEach(checkbox => {
        checkbox.checked = false;
    });
}

// Edit user
function editUser(userId) {
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_user',
            user_id: userId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const user = response.user;
                
                // Fill form fields
                document.getElementById('user_id').value = user.user_id;
                document.getElementById('formAction').value = 'update_user';
                document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-edit me-2"></i>Edit User';
                document.getElementById('password').required = false;
                document.getElementById('passwordRequired').style.display = 'none';
                
                // Account info
                document.getElementById('username').value = user.username;
                document.getElementById('email').value = user.email;
                
                // Personal info
                document.getElementById('first_name').value = user.first_name;
                document.getElementById('middle_name').value = user.middle_name || '';
                document.getElementById('last_name').value = user.last_name;
                document.getElementById('gender').value = user.gender || '';
                document.getElementById('date_of_birth').value = user.date_of_birth || '';
                document.getElementById('national_id').value = user.national_id || '';
                document.getElementById('phone1').value = user.phone1;
                document.getElementById('phone2').value = user.phone2 || '';
                
                // Address info
                document.getElementById('region').value = user.region || '';
                document.getElementById('district').value = user.district || '';
                document.getElementById('ward').value = user.ward || '';
                document.getElementById('village').value = user.village || '';
                document.getElementById('street_address').value = user.street_address || '';
                
                // Permissions
                document.getElementById('is_active').checked = user.is_active == 1;
                document.getElementById('is_admin').checked = user.is_admin == 1;
                document.getElementById('is_super_admin').checked = user.is_super_admin == 1;
                document.getElementById('can_get_commission').checked = user.can_get_commission == 1;
                
                // Roles
                document.querySelectorAll('input[name="roles[]"]').forEach(checkbox => {
                    checkbox.checked = user.roles.includes(checkbox.value);
                });
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('userModal'));
                modal.show();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Error loading user data');
        }
    });
}

// Save user
document.getElementById('userForm').addEventListener('submit', function(e) {
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
            alert('Error saving user');
        }
    });
});

// Toggle user status
function toggleUserStatus(userId, currentStatus) {
    const action = currentStatus ? 'deactivate' : 'activate';
    if (confirm(`Are you sure you want to ${action} this user?`)) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'toggle_status',
                user_id: userId
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
                alert('Error toggling user status');
            }
        });
    }
}

// Delete user
function deleteUser(userId, userName) {
    if (confirm(`Are you sure you want to deactivate ${userName}? This will set the user as inactive.`)) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'delete_user',
                user_id: userId
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
                alert('Error deleting user');
            }
        });
    }
}

// Reset password
function resetUserPassword(userId, userName) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_user_name').textContent = userName;
    document.getElementById('new_password').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
    modal.show();
}

document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
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
                bootstrap.Modal.getInstance(document.getElementById('resetPasswordModal')).hide();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function() {
            alert('Error resetting password');
        }
    });
});

// Toggle password visibility
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const passwordIcon = document.getElementById('passwordIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.classList.remove('fa-eye');
        passwordIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        passwordIcon.classList.remove('fa-eye-slash');
        passwordIcon.classList.add('fa-eye');
    }
}

function toggleResetPasswordVisibility() {
    const passwordInput = document.getElementById('new_password');
    const passwordIcon = document.getElementById('resetPasswordIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.classList.remove('fa-eye');
        passwordIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        passwordIcon.classList.remove('fa-eye-slash');
        passwordIcon.classList.add('fa-eye');
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>