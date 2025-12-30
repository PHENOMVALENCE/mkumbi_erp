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

// Fetch current user data
$stmt = $conn->prepare("
    SELECT u.*, c.company_name, c.company_code
    FROM users u
    INNER JOIN companies c ON u.company_id = c.company_id
    WHERE u.user_id = ? AND u.company_id = ?
");
$stmt->execute([$user_id, $company_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error_message'] = 'User not found';
    header('Location: ../../erp/dashboard.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        if (isset($_POST['update_profile'])) {
            // Validate required fields
            if (empty($_POST['first_name']) || empty($_POST['last_name']) || empty($_POST['email'])) {
                throw new Exception('First name, last name, and email are required');
            }
            
            // Check if email is already used by another user
            $email_check = $conn->prepare("
                SELECT user_id FROM users 
                WHERE email = ? AND user_id != ? AND company_id = ?
            ");
            $email_check->execute([$_POST['email'], $user_id, $company_id]);
            
            if ($email_check->rowCount() > 0) {
                throw new Exception('Email address is already in use by another user');
            }
            
            // Handle profile picture upload
            $profile_picture = $user['profile_picture'];
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../uploads/profiles/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $file_type = $_FILES['profile_picture']['type'];
                
                if (!in_array($file_type, $allowed_types)) {
                    throw new Exception('Invalid file type. Only JPG, PNG, and GIF are allowed');
                }
                
                if ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) { // 2MB limit
                    throw new Exception('File size must be less than 2MB');
                }
                
                $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                $file_name = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
                $target_file = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
                    // Delete old profile picture
                    if ($profile_picture && file_exists('../../' . $profile_picture)) {
                        unlink('../../' . $profile_picture);
                    }
                    $profile_picture = 'uploads/profiles/' . $file_name;
                }
            }
            
            // Update profile - FIXED COLUMN NAMES
            $update_stmt = $conn->prepare("
                UPDATE users SET
                    first_name = ?,
                    last_name = ?,
                    email = ?,
                    phone1 = ?,
                    street_address = ?,
                    region = ?,
                    profile_picture = ?,
                    updated_at = NOW()
                WHERE user_id = ? AND company_id = ?
            ");
            
            $update_stmt->execute([
                $_POST['first_name'],
                $_POST['last_name'],
                $_POST['email'],
                $_POST['phone1'] ?? null,
                $_POST['address'] ?? null,
                $_POST['region'] ?? null,
                $profile_picture,
                $user_id,
                $company_id
            ]);
            
            // Update session variables
            $_SESSION['full_name'] = $_POST['first_name'] . ' ' . $_POST['last_name'];
            $_SESSION['email'] = $_POST['email'];
            
            $conn->commit();
            $_SESSION['success_message'] = 'Profile updated successfully!';
            header('Location: profile.php');
            exit;
            
        } elseif (isset($_POST['change_password'])) {
            // Validate password fields
            if (empty($_POST['current_password']) || empty($_POST['new_password']) || empty($_POST['confirm_password'])) {
                throw new Exception('All password fields are required');
            }
            
            // Verify current password (plain text comparison based on your Auth class)
            if ($_POST['current_password'] !== $user['password_hash']) {
                throw new Exception('Current password is incorrect');
            }
            
            // Validate new password
            if ($_POST['new_password'] !== $_POST['confirm_password']) {
                throw new Exception('New passwords do not match');
            }
            
            if (strlen($_POST['new_password']) < 6) {
                throw new Exception('New password must be at least 6 characters long');
            }
            
            // Update password (plain text as per your system)
            $password_stmt = $conn->prepare("
                UPDATE users SET
                    password_hash = ?,
                    updated_at = NOW()
                WHERE user_id = ? AND company_id = ?
            ");
            
            $password_stmt->execute([
                $_POST['new_password'],
                $user_id,
                $company_id
            ]);
            
            $conn->commit();
            $_SESSION['success_message'] = 'Password changed successfully!';
            header('Location: profile.php');
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Refresh user data after update
$stmt = $conn->prepare("
    SELECT u.*, c.company_name, c.company_code
    FROM users u
    INNER JOIN companies c ON u.company_id = c.company_id
    WHERE u.user_id = ? AND u.company_id = ?
");
$stmt->execute([$user_id, $company_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user's roles - FIXED QUERY
$user_roles = [];
try {
    $roles_stmt = $conn->prepare("
        SELECT sr.role_name
        FROM user_roles ur
        INNER JOIN system_roles sr ON ur.role_id = sr.role_id
        WHERE ur.user_id = ? AND ur.company_id = ?
    ");
    $roles_stmt->execute([$user_id, $company_id]);
    $user_roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If system_roles table doesn't exist or has different structure, ignore
    $user_roles = [];
}

// Get last login information
$last_login = null;
try {
    $login_stmt = $conn->prepare("
        SELECT attempt_time, ip_address
        FROM login_attempts
        WHERE username = ? AND is_successful = 1
        ORDER BY attempt_time DESC
        LIMIT 1, 1
    ");
    $login_stmt->execute([$user['username']]);
    $last_login = $login_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If login_attempts table doesn't exist, ignore
    $last_login = null;
}

$page_title = 'My Profile';
require_once '../../includes/header.php';
?>

<style>
.profile-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.profile-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px 12px 0 0;
    text-align: center;
}

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid white;
    object-fit: cover;
    margin: 0 auto;
    display: block;
    background: white;
}

.profile-name {
    margin-top: 1rem;
    font-size: 1.5rem;
    font-weight: 600;
}

.profile-role {
    opacity: 0.9;
    font-size: 1rem;
}

.info-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.info-label {
    font-weight: 600;
    color: #6b7280;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    color: #111827;
    font-size: 1rem;
    margin-top: 0.25rem;
}

.role-badge {
    display: inline-block;
    padding: 0.375rem 0.75rem;
    background: #e0e7ff;
    color: #4338ca;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 500;
    margin-right: 0.5rem;
    margin-bottom: 0.5rem;
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
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.btn {
    border-radius: 8px;
    padding: 0.625rem 1.5rem;
    font-weight: 500;
}

.section-title {
    font-weight: 600;
    color: #111827;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e5e7eb;
}

.profile-stats {
    display: flex;
    justify-content: space-around;
    padding: 1.5rem 0;
    border-top: 1px solid rgba(255,255,255,0.2);
    margin-top: 1rem;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
}

.stat-label {
    font-size: 0.875rem;
    opacity: 0.9;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-circle text-primary me-2"></i>My Profile
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage your account settings and preferences</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="../../erp/dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
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
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Profile Overview -->
            <div class="col-lg-4">
                <div class="card profile-card">
                    <div class="profile-header">
                        <?php if ($user['profile_picture']): ?>
                        <img src="../../<?php echo htmlspecialchars($user['profile_picture']); ?>" 
                             alt="Profile Picture" class="profile-avatar">
                        <?php else: ?>
                        <div class="profile-avatar d-flex align-items-center justify-content-center">
                            <i class="fas fa-user fa-3x text-muted"></i>
                        </div>
                        <?php endif; ?>
                        
                        <div class="profile-name">
                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                        </div>
                        <div class="profile-role">
                            @<?php echo htmlspecialchars($user['username']); ?>
                        </div>
                        
                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-value">
                                    <?php echo $user['is_admin'] ? 'Admin' : 'User'; ?>
                                </div>
                                <div class="stat-label">Role Level</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </div>
                                <div class="stat-label">Status</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <h6 class="section-title">Account Information</h6>
                        
                        <div class="mb-3">
                            <div class="info-label">Company</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($user['company_name']); ?>
                                <small class="text-muted">(<?php echo htmlspecialchars($user['company_code']); ?>)</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="info-label">Email</div>
                            <div class="info-value">
                                <i class="fas fa-envelope me-2 text-muted"></i>
                                <?php echo htmlspecialchars($user['email']); ?>
                            </div>
                        </div>
                        
                        <?php if ($user['phone1']): ?>
                        <div class="mb-3">
                            <div class="info-label">Phone</div>
                            <div class="info-value">
                                <i class="fas fa-phone me-2 text-muted"></i>
                                <?php echo htmlspecialchars($user['phone1']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <div class="info-label">Member Since</div>
                            <div class="info-value">
                                <i class="fas fa-calendar me-2 text-muted"></i>
                                <?php echo date('F d, Y', strtotime($user['created_at'])); ?>
                            </div>
                        </div>
                        
                        <?php if ($last_login): ?>
                        <div class="mb-3">
                            <div class="info-label">Last Login</div>
                            <div class="info-value">
                                <i class="fas fa-clock me-2 text-muted"></i>
                                <?php echo date('M d, Y h:i A', strtotime($last_login['attempt_time'])); ?>
                                <br>
                                <small class="text-muted">IP: <?php echo htmlspecialchars($last_login['ip_address']); ?></small>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($user_roles)): ?>
                        <div class="mb-3">
                            <div class="info-label">Assigned Roles</div>
                            <div class="mt-2">
                                <?php foreach ($user_roles as $role): ?>
                                <span class="role-badge">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Edit Forms -->
            <div class="col-lg-8">
                <!-- Update Profile Form -->
                <div class="card info-card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-edit me-2 text-primary"></i>Edit Profile Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="update_profile" value="1">
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="first_name" 
                                           value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="last_name" 
                                           value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone1" 
                                           value="<?php echo htmlspecialchars($user['phone1'] ?? ''); ?>" 
                                           placeholder="e.g., 0712345678">
                                </div>
                                
                                <div class="col-md-12">
                                    <label class="form-label">Region</label>
                                    <input type="text" class="form-control" name="region" 
                                           value="<?php echo htmlspecialchars($user['region'] ?? ''); ?>" 
                                           placeholder="e.g., Dar es Salaam">
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" name="address" rows="2" 
                                              placeholder="Street address or location"><?php echo htmlspecialchars($user['street_address'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label">Profile Picture</label>
                                    <input type="file" class="form-control" name="profile_picture" 
                                           accept="image/jpeg,image/jpg,image/png,image/gif">
                                    <small class="text-muted">Supported formats: JPG, PNG, GIF (Max 2MB)</small>
                                    <?php if ($user['profile_picture']): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Current: 
                                            <a href="../../<?php echo htmlspecialchars($user['profile_picture']); ?>" target="_blank">
                                                View current picture
                                            </a>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Change Password Form -->
                <div class="card info-card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-key me-2 text-warning"></i>Change Password
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="changePasswordForm">
                            <input type="hidden" name="change_password" value="1">
                            
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">New Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" name="new_password" 
                                           id="new_password" required minlength="6">
                                    <small class="text-muted">At least 6 characters</small>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" name="confirm_password" 
                                           id="confirm_password" required>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-lock me-2"></i>Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</section>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Password confirmation validation
document.getElementById('changePasswordForm')?.addEventListener('submit', function(e) {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        alert('New passwords do not match!');
        return false;
    }
    
    if (newPassword.length < 6) {
        e.preventDefault();
        alert('New password must be at least 6 characters long!');
        return false;
    }
});

// Profile picture preview
document.querySelector('input[name="profile_picture"]')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        if (file.size > 2 * 1024 * 1024) {
            alert('File size must be less than 2MB');
            this.value = '';
        }
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>