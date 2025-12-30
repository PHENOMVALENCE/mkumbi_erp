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
        if ($_POST['action'] === 'update_company') {
            // Validate required fields
            if (empty($_POST['company_name'])) {
                throw new Exception('Company name is required');
            }
            
            // Handle logo upload
            $logo_path = null;
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $file_type = $_FILES['logo']['type'];
                
                if (!in_array($file_type, $allowed_types)) {
                    throw new Exception('Invalid file type. Only JPG, PNG, and GIF are allowed.');
                }
                
                $max_size = 5 * 1024 * 1024; // 5MB
                if ($_FILES['logo']['size'] > $max_size) {
                    throw new Exception('File size too large. Maximum 5MB allowed.');
                }
                
                // Create upload directory if it doesn't exist
                $upload_dir = '../../assets/img/companies/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Generate unique filename
                $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $file_name = 'company_' . $company_id . '_' . time() . '.' . $file_extension;
                $target_file = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_file)) {
                    $logo_path = 'assets/img/companies/' . $file_name;
                    
                    // Delete old logo if exists
                    $old_logo = $conn->prepare("SELECT logo_path FROM companies WHERE company_id = ?");
                    $old_logo->execute([$company_id]);
                    $old_logo_data = $old_logo->fetch(PDO::FETCH_ASSOC);
                    
                    if ($old_logo_data && $old_logo_data['logo_path'] && file_exists('../../' . $old_logo_data['logo_path'])) {
                        unlink('../../' . $old_logo_data['logo_path']);
                    }
                } else {
                    throw new Exception('Failed to upload logo');
                }
            }
            
            // Update company information
            $sql = "
                UPDATE companies SET 
                    company_name = ?,
                    registration_number = ?,
                    tax_identification_number = ?,
                    email = ?,
                    phone = ?,
                    mobile = ?,
                    website = ?,
                    physical_address = ?,
                    postal_address = ?,
                    city = ?,
                    region = ?,
                    country = ?,
                    primary_color = ?,
                    secondary_color = ?,
                    fiscal_year_start = ?,
                    fiscal_year_end = ?,
                    currency_code = ?,
                    date_format = ?,
                    timezone = ?,
                    updated_at = NOW()
            ";
            
            $params = [
                $_POST['company_name'],
                $_POST['registration_number'] ?? null,
                $_POST['tax_identification_number'] ?? null,
                $_POST['email'] ?? null,
                $_POST['phone'] ?? null,
                $_POST['mobile'] ?? null,
                $_POST['website'] ?? null,
                $_POST['physical_address'] ?? null,
                $_POST['postal_address'] ?? null,
                $_POST['city'] ?? null,
                $_POST['region'] ?? null,
                $_POST['country'] ?? 'Tanzania',
                $_POST['primary_color'] ?? '#007bff',
                $_POST['secondary_color'] ?? '#6c757d',
                $_POST['fiscal_year_start'] ?? null,
                $_POST['fiscal_year_end'] ?? null,
                $_POST['currency_code'] ?? 'TZS',
                $_POST['date_format'] ?? 'Y-m-d',
                $_POST['timezone'] ?? 'Africa/Dar_es_Salaam'
            ];
            
            if ($logo_path) {
                $sql .= ", logo_path = ?";
                $params[] = $logo_path;
            }
            
            $sql .= " WHERE company_id = ?";
            $params[] = $company_id;
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Company information updated successfully',
                'logo_path' => $logo_path
            ]);
            
        } elseif ($_POST['action'] === 'update_subscription') {
            // Update subscription information
            $stmt = $conn->prepare("
                UPDATE companies SET 
                    subscription_plan = ?,
                    subscription_start_date = ?,
                    subscription_end_date = ?,
                    max_users = ?,
                    updated_at = NOW()
                WHERE company_id = ?
            ");
            
            $stmt->execute([
                $_POST['subscription_plan'],
                $_POST['subscription_start_date'] ?? null,
                $_POST['subscription_end_date'] ?? null,
                $_POST['max_users'] ?? 5,
                $company_id
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Subscription updated successfully']);
            
        } elseif ($_POST['action'] === 'delete_logo') {
            // Delete logo
            $stmt = $conn->prepare("SELECT logo_path FROM companies WHERE company_id = ?");
            $stmt->execute([$company_id]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($company && $company['logo_path']) {
                $logo_file = '../../' . $company['logo_path'];
                if (file_exists($logo_file)) {
                    unlink($logo_file);
                }
                
                $stmt = $conn->prepare("UPDATE companies SET logo_path = NULL WHERE company_id = ?");
                $stmt->execute([$company_id]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Logo deleted successfully']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch company information
try {
    $stmt = $conn->prepare("
        SELECT c.*,
            (SELECT COUNT(*) FROM users WHERE company_id = c.company_id) as total_users,
            (SELECT COUNT(*) FROM users WHERE company_id = c.company_id AND is_active = 1) as active_users
        FROM companies c
        WHERE c.company_id = ?
    ");
    $stmt->execute([$company_id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        throw new Exception('Company not found');
    }
    
    // Get company statistics
    $stats = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM projects WHERE company_id = ? AND is_active = 1) as total_projects,
            (SELECT COUNT(*) FROM plots WHERE company_id = ? AND is_active = 1) as total_plots,
            (SELECT COUNT(*) FROM customers WHERE company_id = ? AND is_active = 1) as total_customers,
            (SELECT COUNT(*) FROM reservations WHERE company_id = ? AND status IN ('active', 'completed')) as total_sales
    ");
    $stats->execute([$company_id, $company_id, $company_id, $company_id]);
    $company_stats = $stats->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Error fetching company data: " . $e->getMessage();
    $company = null;
    $company_stats = ['total_projects' => 0, 'total_plots' => 0, 'total_customers' => 0, 'total_sales' => 0];
}

$page_title = 'Company Profile';
require_once '../../includes/header.php';
?>

<style>
.company-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.company-logo-container {
    width: 120px;
    height: 120px;
    border-radius: 16px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    position: relative;
    overflow: hidden;
}

.company-logo-container img {
    max-width: 100%;
    max-height: 100%;
    object-fit: cover;
}

.company-logo-placeholder {
    font-size: 48px;
    color: #9ca3af;
}

.logo-upload-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s;
    cursor: pointer;
}

.company-logo-container:hover .logo-upload-overlay {
    opacity: 1;
}

.stats-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
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

.nav-pills .nav-link {
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.2s;
}

.nav-pills .nav-link.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

.color-picker-wrapper {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.color-preview {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    border: 2px solid #e5e7eb;
}

.subscription-badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 14px;
}

.info-row {
    padding: 1rem;
    border-bottom: 1px solid #f3f4f6;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #6b7280;
    min-width: 200px;
}

.info-value {
    color: #1f2937;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-building text-primary me-2"></i>Company Profile
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage your company information and settings</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button class="btn btn-outline-primary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
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
        
        <?php if ($company): ?>
        
        <!-- Company Header -->
        <div class="company-header mb-4">
            <div class="row align-items-center">
                <div class="col-auto">
                    <div class="company-logo-container" id="logoContainer">
                        <?php if ($company['logo_path'] && file_exists('../../' . $company['logo_path'])): ?>
                            <img src="../../<?php echo htmlspecialchars($company['logo_path']); ?>" alt="Company Logo" id="companyLogoImg">
                        <?php else: ?>
                            <i class="fas fa-building company-logo-placeholder"></i>
                        <?php endif; ?>
                        <div class="logo-upload-overlay" onclick="document.getElementById('logoUpload').click()">
                            <i class="fas fa-camera fa-2x text-white"></i>
                        </div>
                    </div>
                    <input type="file" id="logoUpload" name="logo" accept="image/*" style="display: none;" onchange="uploadLogo(this)">
                    <?php if ($company['logo_path']): ?>
                    <button class="btn btn-sm btn-danger mt-2 w-100" onclick="deleteLogo()">
                        <i class="fas fa-trash me-1"></i>Remove Logo
                    </button>
                    <?php endif; ?>
                </div>
                <div class="col">
                    <h2 class="mb-2 fw-bold"><?php echo htmlspecialchars($company['company_name']); ?></h2>
                    <p class="mb-2 opacity-90">
                        <i class="fas fa-code me-2"></i>Company Code: <strong><?php echo htmlspecialchars($company['company_code']); ?></strong>
                    </p>
                    <?php if ($company['registration_number']): ?>
                    <p class="mb-2 opacity-90">
                        <i class="fas fa-id-card me-2"></i>Registration: <?php echo htmlspecialchars($company['registration_number']); ?>
                    </p>
                    <?php endif; ?>
                    <?php if ($company['tax_identification_number']): ?>
                    <p class="mb-0 opacity-90">
                        <i class="fas fa-receipt me-2"></i>TIN: <?php echo htmlspecialchars($company['tax_identification_number']); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="col-auto text-end">
                    <div class="mb-3">
                        <span class="subscription-badge <?php 
                            echo match($company['subscription_plan']) {
                                'trial' => 'bg-warning text-dark',
                                'basic' => 'bg-info text-white',
                                'professional' => 'bg-success text-white',
                                'enterprise' => 'bg-primary text-white',
                                default => 'bg-secondary text-white'
                            };
                        ?>">
                            <i class="fas fa-crown me-1"></i><?php echo strtoupper($company['subscription_plan']); ?>
                        </span>
                    </div>
                    <p class="mb-1 opacity-90">
                        <i class="fas fa-users me-2"></i><?php echo $company['active_users']; ?> / <?php echo $company['max_users']; ?> Users
                    </p>
                    <?php if ($company['subscription_end_date']): ?>
                    <p class="mb-0 opacity-90">
                        <i class="fas fa-calendar me-2"></i>Expires: <?php echo date('M d, Y', strtotime($company['subscription_end_date'])); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($company_stats['total_projects']); ?></h3>
                        <p class="text-muted mb-0">Active Projects</p>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($company_stats['total_plots']); ?></h3>
                        <p class="text-muted mb-0">Total Plots</p>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($company_stats['total_customers']); ?></h3>
                        <p class="text-muted mb-0">Total Customers</p>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="stats-icon bg-info bg-opacity-10 text-info">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($company_stats['total_sales']); ?></h3>
                        <p class="text-muted mb-0">Total Sales</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabs Navigation -->
        <ul class="nav nav-pills mb-4" id="companyTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="info-tab" data-bs-toggle="pill" data-bs-target="#info" type="button" role="tab">
                    <i class="fas fa-info-circle me-2"></i>Basic Information
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="contact-tab" data-bs-toggle="pill" data-bs-target="#contact" type="button" role="tab">
                    <i class="fas fa-address-book me-2"></i>Contact Details
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="settings-tab" data-bs-toggle="pill" data-bs-target="#settings" type="button" role="tab">
                    <i class="fas fa-cog me-2"></i>System Settings
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="subscription-tab" data-bs-toggle="pill" data-bs-target="#subscription" type="button" role="tab">
                    <i class="fas fa-crown me-2"></i>Subscription
                </button>
            </li>
        </ul>
        
        <!-- Tab Content -->
        <div class="tab-content" id="companyTabContent">
            
            <!-- Basic Information Tab -->
            <div class="tab-pane fade show active" id="info" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-building me-2"></i>Basic Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <form id="basicInfoForm">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="action" value="update_company">
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="company_name" 
                                           value="<?php echo htmlspecialchars($company['company_name']); ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Registration Number</label>
                                    <input type="text" class="form-control" name="registration_number" 
                                           value="<?php echo htmlspecialchars($company['registration_number'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Tax Identification Number (TIN)</label>
                                    <input type="text" class="form-control" name="tax_identification_number" 
                                           value="<?php echo htmlspecialchars($company['tax_identification_number'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Country</label>
                                    <input type="text" class="form-control" name="country" 
                                           value="<?php echo htmlspecialchars($company['country']); ?>">
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Contact Details Tab -->
            <div class="tab-pane fade" id="contact" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-address-book me-2"></i>Contact Details
                        </h5>
                    </div>
                    <div class="card-body">
                        <form id="contactForm">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="action" value="update_company">
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo htmlspecialchars($company['email'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone" 
                                           value="<?php echo htmlspecialchars($company['phone'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Mobile Number</label>
                                    <input type="text" class="form-control" name="mobile" 
                                           value="<?php echo htmlspecialchars($company['mobile'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Website</label>
                                    <input type="url" class="form-control" name="website" 
                                           value="<?php echo htmlspecialchars($company['website'] ?? ''); ?>" 
                                           placeholder="https://www.example.com">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">City</label>
                                    <input type="text" class="form-control" name="city" 
                                           value="<?php echo htmlspecialchars($company['city'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Region</label>
                                    <input type="text" class="form-control" name="region" 
                                           value="<?php echo htmlspecialchars($company['region'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Physical Address</label>
                                    <textarea class="form-control" name="physical_address" rows="3"><?php echo htmlspecialchars($company['physical_address'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Postal Address</label>
                                    <textarea class="form-control" name="postal_address" rows="3"><?php echo htmlspecialchars($company['postal_address'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- System Settings Tab -->
            <div class="tab-pane fade" id="settings" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-cog me-2"></i>System Settings
                        </h5>
                    </div>
                    <div class="card-body">
                        <form id="settingsForm">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="action" value="update_company">
                            
                            <div class="row g-4">
                                <!-- Brand Colors -->
                                <div class="col-12">
                                    <h6 class="fw-bold text-primary mb-3">
                                        <i class="fas fa-palette me-2"></i>Brand Colors
                                    </h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Primary Color</label>
                                            <div class="color-picker-wrapper">
                                                <input type="color" class="form-control" name="primary_color" id="primary_color"
                                                       value="<?php echo htmlspecialchars($company['primary_color']); ?>" 
                                                       style="width: 80px;">
                                                <input type="text" class="form-control" id="primary_color_text"
                                                       value="<?php echo htmlspecialchars($company['primary_color']); ?>" readonly>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label">Secondary Color</label>
                                            <div class="color-picker-wrapper">
                                                <input type="color" class="form-control" name="secondary_color" id="secondary_color"
                                                       value="<?php echo htmlspecialchars($company['secondary_color']); ?>" 
                                                       style="width: 80px;">
                                                <input type="text" class="form-control" id="secondary_color_text"
                                                       value="<?php echo htmlspecialchars($company['secondary_color']); ?>" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Fiscal Year -->
                                <div class="col-12">
                                    <h6 class="fw-bold text-primary mb-3">
                                        <i class="fas fa-calendar me-2"></i>Fiscal Year
                                    </h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Fiscal Year Start</label>
                                            <input type="date" class="form-control" name="fiscal_year_start" 
                                                   value="<?php echo htmlspecialchars($company['fiscal_year_start'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label">Fiscal Year End</label>
                                            <input type="date" class="form-control" name="fiscal_year_end" 
                                                   value="<?php echo htmlspecialchars($company['fiscal_year_end'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Regional Settings -->
                                <div class="col-12">
                                    <h6 class="fw-bold text-primary mb-3">
                                        <i class="fas fa-globe me-2"></i>Regional Settings
                                    </h6>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Currency Code</label>
                                            <select class="form-select" name="currency_code">
                                                <option value="TZS" <?php echo $company['currency_code'] == 'TZS' ? 'selected' : ''; ?>>TZS - Tanzanian Shilling</option>
                                                <option value="USD" <?php echo $company['currency_code'] == 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                                <option value="EUR" <?php echo $company['currency_code'] == 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                                <option value="GBP" <?php echo $company['currency_code'] == 'GBP' ? 'selected' : ''; ?>>GBP - British Pound</option>
                                                <option value="KES" <?php echo $company['currency_code'] == 'KES' ? 'selected' : ''; ?>>KES - Kenyan Shilling</option>
                                                <option value="UGX" <?php echo $company['currency_code'] == 'UGX' ? 'selected' : ''; ?>>UGX - Ugandan Shilling</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label">Date Format</label>
                                            <select class="form-select" name="date_format">
                                                <option value="Y-m-d" <?php echo $company['date_format'] == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD (2025-01-31)</option>
                                                <option value="d/m/Y" <?php echo $company['date_format'] == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY (31/01/2025)</option>
                                                <option value="m/d/Y" <?php echo $company['date_format'] == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY (01/31/2025)</option>
                                                <option value="d-M-Y" <?php echo $company['date_format'] == 'd-M-Y' ? 'selected' : ''; ?>>DD-MMM-YYYY (31-Jan-2025)</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label">Timezone</label>
                                            <select class="form-select" name="timezone">
                                                <option value="Africa/Dar_es_Salaam" <?php echo $company['timezone'] == 'Africa/Dar_es_Salaam' ? 'selected' : ''; ?>>Africa/Dar es Salaam (EAT)</option>
                                                <option value="Africa/Nairobi" <?php echo $company['timezone'] == 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi (EAT)</option>
                                                <option value="Africa/Kampala" <?php echo $company['timezone'] == 'Africa/Kampala' ? 'selected' : ''; ?>>Africa/Kampala (EAT)</option>
                                                <option value="Africa/Lagos" <?php echo $company['timezone'] == 'Africa/Lagos' ? 'selected' : ''; ?>>Africa/Lagos (WAT)</option>
                                                <option value="UTC" <?php echo $company['timezone'] == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Subscription Tab -->
            <div class="tab-pane fade" id="subscription" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-crown me-2"></i>Subscription Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="info-row d-flex justify-content-between">
                                    <span class="info-label">Current Plan:</span>
                                    <span class="info-value">
                                        <span class="subscription-badge <?php 
                                            echo match($company['subscription_plan']) {
                                                'trial' => 'bg-warning text-dark',
                                                'basic' => 'bg-info text-white',
                                                'professional' => 'bg-success text-white',
                                                'enterprise' => 'bg-primary text-white',
                                                default => 'bg-secondary text-white'
                                            };
                                        ?>">
                                            <?php echo strtoupper($company['subscription_plan']); ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="info-row d-flex justify-content-between">
                                    <span class="info-label">Max Users:</span>
                                    <span class="info-value fw-bold"><?php echo $company['max_users']; ?></span>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="info-row d-flex justify-content-between">
                                    <span class="info-label">Current Users:</span>
                                    <span class="info-value fw-bold"><?php echo $company['total_users']; ?></span>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="info-row d-flex justify-content-between">
                                    <span class="info-label">Active Users:</span>
                                    <span class="info-value fw-bold text-success"><?php echo $company['active_users']; ?></span>
                                </div>
                            </div>
                            
                            <?php if ($company['subscription_start_date']): ?>
                            <div class="col-md-6">
                                <div class="info-row d-flex justify-content-between">
                                    <span class="info-label">Start Date:</span>
                                    <span class="info-value"><?php echo date('M d, Y', strtotime($company['subscription_start_date'])); ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($company['subscription_end_date']): ?>
                            <div class="col-md-6">
                                <div class="info-row d-flex justify-content-between">
                                    <span class="info-label">End Date:</span>
                                    <span class="info-value"><?php echo date('M d, Y', strtotime($company['subscription_end_date'])); ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <hr class="my-4">
                        
                        <form id="subscriptionForm">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="action" value="update_subscription">
                            
                            <h6 class="fw-bold mb-3">Update Subscription</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Subscription Plan</label>
                                    <select class="form-select" name="subscription_plan">
                                        <option value="trial" <?php echo $company['subscription_plan'] == 'trial' ? 'selected' : ''; ?>>Trial</option>
                                        <option value="basic" <?php echo $company['subscription_plan'] == 'basic' ? 'selected' : ''; ?>>Basic</option>
                                        <option value="professional" <?php echo $company['subscription_plan'] == 'professional' ? 'selected' : ''; ?>>Professional</option>
                                        <option value="enterprise" <?php echo $company['subscription_plan'] == 'enterprise' ? 'selected' : ''; ?>>Enterprise</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Max Users</label>
                                    <input type="number" class="form-control" name="max_users" 
                                           value="<?php echo $company['max_users']; ?>" min="1">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" class="form-control" name="subscription_start_date" 
                                           value="<?php echo htmlspecialchars($company['subscription_start_date'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">End Date</label>
                                    <input type="date" class="form-control" name="subscription_end_date" 
                                           value="<?php echo htmlspecialchars($company['subscription_end_date'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Subscription
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
        </div>
        
        <?php endif; ?>
        
    </div>
</section>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Color picker sync
document.getElementById('primary_color').addEventListener('input', function() {
    document.getElementById('primary_color_text').value = this.value;
});

document.getElementById('secondary_color').addEventListener('input', function() {
    document.getElementById('secondary_color_text').value = this.value;
});

// Upload logo
function uploadLogo(input) {
    if (input.files && input.files[0]) {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'update_company');
        formData.append('logo', input.files[0]);
        formData.append('company_name', '<?php echo addslashes($company['company_name']); ?>');
        
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
                alert('Error uploading logo');
            }
        });
    }
}

// Delete logo
function deleteLogo() {
    if (confirm('Are you sure you want to delete the company logo?')) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'delete_logo'
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
                alert('Error deleting logo');
            }
        });
    }
}

// Save basic info
document.getElementById('basicInfoForm').addEventListener('submit', function(e) {
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
            alert('Error saving information');
        }
    });
});

// Save contact info
document.getElementById('contactForm').addEventListener('submit', function(e) {
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
            alert('Error saving contact information');
        }
    });
});

// Save settings
document.getElementById('settingsForm').addEventListener('submit', function(e) {
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
            alert('Error saving settings');
        }
    });
});

// Save subscription
document.getElementById('subscriptionForm').addEventListener('submit', function(e) {
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
            alert('Error saving subscription');
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>