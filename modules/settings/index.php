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
$user_id = $_SESSION['user_id'];

// Fetch statistics
try {
    // Users statistics
    $users_stats = $conn->prepare("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
            SUM(CASE WHEN is_admin = 1 THEN 1 ELSE 0 END) as admin_users,
            SUM(CASE WHEN DATE(last_login_at) = CURDATE() THEN 1 ELSE 0 END) as today_logins
        FROM users 
        WHERE company_id = ?
    ");
    $users_stats->execute([$company_id]);
    $users = $users_stats->fetch(PDO::FETCH_ASSOC);
    
    // Roles statistics
    $roles_stats = $conn->prepare("
        SELECT 
            COUNT(*) as total_roles,
            SUM(CASE WHEN is_system_role = 1 THEN 1 ELSE 0 END) as system_roles
        FROM system_roles
    ");
    $roles_stats->execute();
    $roles = $roles_stats->fetch(PDO::FETCH_ASSOC);
    
    // Permissions statistics
    $permissions_stats = $conn->prepare("SELECT COUNT(*) as total_permissions FROM permissions");
    $permissions_stats->execute();
    $permissions = $permissions_stats->fetch(PDO::FETCH_ASSOC);
    
    // Company information
    $company_info = $conn->prepare("SELECT * FROM companies WHERE company_id = ?");
    $company_info->execute([$company_id]);
    $company = $company_info->fetch(PDO::FETCH_ASSOC);
    
    // System activity (last 7 days) - Check if audit_trail table exists
    try {
        $activity_stats = $conn->prepare("
            SELECT 
                COUNT(*) as total_activities,
                COUNT(DISTINCT user_id) as active_users,
                COUNT(DISTINCT DATE(created_at)) as active_days
            FROM audit_logs 
            WHERE company_id = ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $activity_stats->execute([$company_id]);
        $activity = $activity_stats->fetch(PDO::FETCH_ASSOC);
        
        // Recent activities
        $recent_activities = $conn->prepare("
            SELECT 
                a.*,
                u.first_name,
                u.last_name
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE a.company_id = ?
            ORDER BY a.created_at DESC
            LIMIT 10
        ");
        $recent_activities->execute([$company_id]);
        $activities = $recent_activities->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If audit_logs table doesn't exist, set default values
        $activity = ['total_activities' => 0, 'active_users' => 0, 'active_days' => 0];
        $activities = [];
    }
    
} catch (PDOException $e) {
    $error_message = "Error fetching data: " . $e->getMessage();
    $users = ['total_users' => 0, 'active_users' => 0, 'admin_users' => 0, 'today_logins' => 0];
    $roles = ['total_roles' => 0, 'system_roles' => 0];
    $permissions = ['total_permissions' => 0];
    $company = null;
    $activity = ['total_activities' => 0, 'active_users' => 0, 'active_days' => 0];
    $activities = [];
}

$page_title = 'Settings';
require_once '../../includes/header.php';
?>

<style>
.settings-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.stats-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
    height: 100%;
}

.stats-card:hover {
    transform: translateY(-4px);
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

.settings-menu-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
    cursor: pointer;
    height: 100%;
}

.settings-menu-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    border-left: 4px solid #667eea;
}

.settings-menu-icon {
    width: 70px;
    height: 70px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    margin-bottom: 1rem;
}

.activity-item {
    padding: 1rem;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.2s;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-item:hover {
    background: #f9fafb;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.badge-custom {
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 500;
    font-size: 12px;
}

.quick-link {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    transition: all 0.2s;
    text-decoration: none;
    display: block;
    margin-bottom: 0.5rem;
}

.quick-link:hover {
    background: #f9fafb;
    border-color: #667eea;
    transform: translateX(4px);
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.card-header {
    background: #fff;
    border-bottom: 2px solid #f3f4f6;
    border-radius: 12px 12px 0 0 !important;
    padding: 1.25rem 1.5rem;
}

.system-health-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 8px;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.company-logo-small {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    object-fit: cover;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-cog text-primary me-2"></i>Settings & Configuration
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage system settings, users, and integrations</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <span class="system-health-indicator bg-success"></span>
                    <span class="text-muted small">System Status: <strong class="text-success">Healthy</strong></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Company Overview -->
        <?php if ($company): ?>
        <div class="settings-header mb-4">
            <div class="row align-items-center">
                <div class="col-auto">
                    <?php if ($company['logo_path'] && file_exists('../../' . $company['logo_path'])): ?>
                        <img src="../../<?php echo htmlspecialchars($company['logo_path']); ?>" 
                             alt="Company Logo" class="company-logo-small bg-white p-2">
                    <?php else: ?>
                        <div class="company-logo-small bg-white d-flex align-items-center justify-content-center">
                            <i class="fas fa-building text-muted"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col">
                    <h3 class="mb-1 fw-bold"><?php echo htmlspecialchars($company['company_name']); ?></h3>
                    <p class="mb-0 opacity-90">
                        <i class="fas fa-code me-2"></i><?php echo htmlspecialchars($company['company_code']); ?>
                        <span class="mx-2">•</span>
                        <i class="fas fa-crown me-2"></i><?php echo strtoupper($company['subscription_plan']); ?> Plan
                    </p>
                </div>
                <div class="col-auto">
                    <a href="company.php" class="btn btn-light">
                        <i class="fas fa-edit me-2"></i>Edit Company Profile
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($users['total_users']); ?></h3>
                        <p class="text-muted mb-2">Total Users</p>
                        <div class="small">
                            <span class="text-success"><i class="fas fa-circle me-1" style="font-size: 8px;"></i><?php echo $users['active_users']; ?> Active</span>
                            <span class="mx-2">•</span>
                            <span class="text-info"><?php echo $users['admin_users']; ?> Admins</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($roles['total_roles']); ?></h3>
                        <p class="text-muted mb-2">User Roles</p>
                        <div class="small">
                            <span class="text-primary"><i class="fas fa-circle me-1" style="font-size: 8px;"></i><?php echo $roles['system_roles']; ?> System</span>
                            <span class="mx-2">•</span>
                            <span class="text-warning"><?php echo $roles['total_roles'] - $roles['system_roles']; ?> Custom</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-key"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($permissions['total_permissions']); ?></h3>
                        <p class="text-muted mb-2">Permissions</p>
                        <div class="small text-muted">
                            <i class="fas fa-shield-alt me-1"></i>Security & Access Control
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-info bg-opacity-10 text-info">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($activity['total_activities']); ?></h3>
                        <p class="text-muted mb-2">Activities (7 days)</p>
                        <div class="small">
                            <span class="text-success"><i class="fas fa-user me-1"></i><?php echo $activity['active_users']; ?> Users</span>
                            <span class="mx-2">•</span>
                            <span class="text-info"><?php echo $activity['active_days']; ?> Days</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row g-4">
            <!-- Settings Menu -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-sliders-h me-2"></i>Settings Modules
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <!-- Company Settings -->
                            <div class="col-md-6">
                                <div class="settings-menu-card" onclick="location.href='company.php'">
                                    <div class="card-body">
                                        <div class="settings-menu-icon bg-primary bg-opacity-10 text-primary">
                                            <i class="fas fa-building"></i>
                                        </div>
                                        <h5 class="fw-bold mb-2">Company Profile</h5>
                                        <p class="text-muted small mb-0">Manage company information, logo, branding, and system preferences</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- User Management -->
                            <div class="col-md-6">
                                <div class="settings-menu-card" onclick="location.href='users.php'">
                                    <div class="card-body">
                                        <div class="settings-menu-icon bg-success bg-opacity-10 text-success">
                                            <i class="fas fa-users-cog"></i>
                                        </div>
                                        <h5 class="fw-bold mb-2">User Management</h5>
                                        <p class="text-muted small mb-0">Add, edit, and manage user accounts and access permissions</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Roles & Permissions -->
                            <div class="col-md-6">
                                <div class="settings-menu-card" onclick="location.href='roles.php'">
                                    <div class="card-body">
                                        <div class="settings-menu-icon bg-warning bg-opacity-10 text-warning">
                                            <i class="fas fa-user-shield"></i>
                                        </div>
                                        <h5 class="fw-bold mb-2">Roles & Permissions</h5>
                                        <p class="text-muted small mb-0">Configure user roles and granular access permissions</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Integrations -->
                            <div class="col-md-6">
                                <div class="settings-menu-card" onclick="location.href='integrations.php'">
                                    <div class="card-body">
                                        <div class="settings-menu-icon bg-info bg-opacity-10 text-info">
                                            <i class="fas fa-plug"></i>
                                        </div>
                                        <h5 class="fw-bold mb-2">Integrations</h5>
                                        <p class="text-muted small mb-0">Connect third-party services and API integrations</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Backup & Restore -->
                            <div class="col-md-6">
                                <div class="settings-menu-card">
                                    <div class="card-body">
                                        <div class="settings-menu-icon bg-danger bg-opacity-10 text-danger">
                                            <i class="fas fa-database"></i>
                                        </div>
                                        <h5 class="fw-bold mb-2">Backup & Restore</h5>
                                        <p class="text-muted small mb-0">Create backups and restore system data</p>
                                        <span class="badge bg-secondary mt-2">Coming Soon</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Email Templates -->
                            <div class="col-md-6">
                                <div class="settings-menu-card">
                                    <div class="card-body">
                                        <div class="settings-menu-icon bg-purple bg-opacity-10 text-purple">
                                            <i class="fas fa-envelope"></i>
                                        </div>
                                        <h5 class="fw-bold mb-2">Email Templates</h5>
                                        <p class="text-muted small mb-0">Customize email notifications and templates</p>
                                        <span class="badge bg-secondary mt-2">Coming Soon</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Audit Trail -->
                            <div class="col-md-6">
                                <div class="settings-menu-card" onclick="location.href='../audit_logs/'">
                                    <div class="card-body">
                                        <div class="settings-menu-icon bg-dark bg-opacity-10 text-dark">
                                            <i class="fas fa-history"></i>
                                        </div>
                                        <h5 class="fw-bold mb-2">Audit Trail</h5>
                                        <p class="text-muted small mb-0">View system activity logs and user actions</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- System Settings -->
                            <div class="col-md-6">
                                <div class="settings-menu-card">
                                    <div class="card-body">
                                        <div class="settings-menu-icon bg-secondary bg-opacity-10 text-secondary">
                                            <i class="fas fa-tools"></i>
                                        </div>
                                        <h5 class="fw-bold mb-2">System Settings</h5>
                                        <p class="text-muted small mb-0">Advanced system configuration and maintenance</p>
                                        <span class="badge bg-secondary mt-2">Coming Soon</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-bolt me-2"></i>Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <a href="users.php?action=create" class="quick-link">
                            <i class="fas fa-user-plus me-2 text-primary"></i>Add New User
                        </a>
                        <a href="roles.php?action=create" class="quick-link">
                            <i class="fas fa-shield-alt me-2 text-success"></i>Create Role
                        </a>
                        <a href="company.php#settings-tab" class="quick-link">
                            <i class="fas fa-palette me-2 text-warning"></i>Update Branding
                        </a>
                        <a href="integrations.php?action=connect" class="quick-link">
                            <i class="fas fa-plug me-2 text-info"></i>Connect Service
                        </a>
                        <a href="../audit_logs/" class="quick-link">
                            <i class="fas fa-clipboard-list me-2 text-danger"></i>View Activity Log
                        </a>
                    </div>
                </div>
                
                <!-- System Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle me-2"></i>System Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted small">PHP Version</span>
                                <strong class="small"><?php echo phpversion(); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted small">Database</span>
                                <strong class="small">MySQL/MariaDB</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted small">Timezone</span>
                                <strong class="small"><?php echo $company['timezone'] ?? 'Africa/Dar_es_Salaam'; ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted small">Currency</span>
                                <strong class="small"><?php echo $company['currency_code'] ?? 'TZS'; ?></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted small">Date Format</span>
                                <strong class="small"><?php echo $company['date_format'] ?? 'Y-m-d'; ?></strong>
                            </div>
                        </div>
                        <hr>
                        <div class="text-center">
                            <small class="text-muted">
                                <i class="fas fa-shield-alt me-1"></i>System secured with SSL
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-clock me-2"></i>Recent Activity
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (count($activities) > 0): ?>
                            <?php foreach (array_slice($activities, 0, 5) as $act): ?>
                            <div class="activity-item">
                                <div class="d-flex align-items-center">
                                    <div class="activity-icon bg-primary bg-opacity-10 text-primary me-3">
                                        <i class="fas <?php 
                                            echo match($act['action_type'] ?? 'other') {
                                                'create' => 'fa-plus',
                                                'update' => 'fa-edit',
                                                'delete' => 'fa-trash',
                                                'login' => 'fa-sign-in-alt',
                                                'logout' => 'fa-sign-out-alt',
                                                default => 'fa-circle'
                                            };
                                        ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-1 small fw-bold">
                                            <?php echo htmlspecialchars(($act['first_name'] ?? '') . ' ' . ($act['last_name'] ?? 'System')); ?>
                                        </p>
                                        <p class="mb-1 small text-muted">
                                            <?php echo ucfirst($act['action_type'] ?? 'action'); ?> 
                                            <?php echo htmlspecialchars($act['module_name'] ?? 'item'); ?>
                                        </p>
                                        <p class="mb-0 small text-muted">
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo date('M d, H:i', strtotime($act['created_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="p-3 text-center">
                                <a href="../audit_logs/" class="btn btn-sm btn-outline-primary">
                                    View All Activity
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-inbox fa-2x mb-3"></i>
                                <p class="mb-0">No recent activity</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</section>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php require_once '../../includes/footer.php'; ?>