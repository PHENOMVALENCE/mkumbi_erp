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

// Get employee ID from URL
$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$employee_id) {
    $_SESSION['error_message'] = "Invalid employee ID";
    header('Location: index.php');
    exit;
}

// Fetch employee details
try {
    $query = "
        SELECT 
            e.*,
            u.username,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.email,
            u.phone1,
            u.phone2,
            u.profile_picture,
            u.gender,
            u.date_of_birth,
            u.national_id,
            u.region,
            u.district,
            u.ward,
            u.village,
            u.street_address
        FROM employees e
        INNER JOIN users u ON e.user_id = u.user_id
        WHERE e.employee_id = ? AND e.company_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$employee_id, $company_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        $_SESSION['error_message'] = "Employee not found";
        header('Location: index.php');
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching employee: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading employee details";
    header('Location: index.php');
    exit;
}

// Fetch departments
$departments = [];
try {
    $dept_query = "SELECT department_id, department_name FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY department_name ASC";
    $stmt = $conn->prepare($dept_query);
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($departments)) {
        $all_dept_query = "SELECT department_id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC";
        $stmt = $conn->prepare($all_dept_query);
        $stmt->execute();
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching departments: " . $e->getMessage());
}

// Fetch positions
$positions = [];
try {
    $pos_query = "
        SELECT p.position_id, p.position_title, p.department_id, d.department_name 
        FROM positions p
        LEFT JOIN departments d ON p.department_id = d.department_id
        WHERE p.company_id = ? AND p.is_active = 1 
        ORDER BY d.department_name, p.position_title
    ";
    $stmt = $conn->prepare($pos_query);
    $stmt->execute([$company_id]);
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching positions: " . $e->getMessage());
}

// Handle form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate required fields
    $required_fields = ['first_name', 'last_name', 'email', 'employee_number', 'hire_date', 'employment_type', 'basic_salary'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucwords(str_replace('_', ' ', $field)) . " is required";
        }
    }
    
    // Email validation
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email address is required";
    }
    
    // Check if email already exists (excluding current user)
    if (!empty($_POST['email'])) {
        try {
            $check_query = "SELECT user_id FROM users WHERE email = ? AND company_id = ? AND user_id != ?";
            $stmt = $conn->prepare($check_query);
            $stmt->execute([$_POST['email'], $company_id, $employee['user_id']]);
            if ($stmt->fetch()) {
                $errors[] = "Email address already exists";
            }
        } catch (PDOException $e) {
            error_log("Email check error: " . $e->getMessage());
        }
    }
    
    // Check if employee number already exists (excluding current employee)
    if (!empty($_POST['employee_number'])) {
        try {
            $check_query = "SELECT employee_id FROM employees WHERE employee_number = ? AND company_id = ? AND employee_id != ?";
            $stmt = $conn->prepare($check_query);
            $stmt->execute([$_POST['employee_number'], $company_id, $employee_id]);
            if ($stmt->fetch()) {
                $errors[] = "Employee number already exists";
            }
        } catch (PDOException $e) {
            error_log("Employee number check error: " . $e->getMessage());
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Update user information
            $user_query = "
                UPDATE users SET
                    email = ?,
                    first_name = ?,
                    middle_name = ?,
                    last_name = ?,
                    phone1 = ?,
                    phone2 = ?,
                    region = ?,
                    district = ?,
                    ward = ?,
                    village = ?,
                    street_address = ?,
                    gender = ?,
                    date_of_birth = ?,
                    national_id = ?,
                    updated_at = NOW()
                WHERE user_id = ? AND company_id = ?
            ";
            
            $stmt = $conn->prepare($user_query);
            $stmt->execute([
                $_POST['email'],
                trim($_POST['first_name']),
                trim($_POST['middle_name'] ?? ''),
                trim($_POST['last_name']),
                trim($_POST['phone1'] ?? ''),
                trim($_POST['phone2'] ?? ''),
                trim($_POST['region'] ?? ''),
                trim($_POST['district'] ?? ''),
                trim($_POST['ward'] ?? ''),
                trim($_POST['village'] ?? ''),
                trim($_POST['street_address'] ?? ''),
                $_POST['gender'] ?? null,
                $_POST['date_of_birth'] ?? null,
                trim($_POST['national_id'] ?? ''),
                $employee['user_id'],
                $company_id
            ]);
            
            // Calculate salary
            $basic_salary = (float)$_POST['basic_salary'];
            $allowances = (float)($_POST['allowances'] ?? 0);
            
            // Update employee information
            $employee_query = "
                UPDATE employees SET
                    employee_number = ?,
                    department_id = ?,
                    position_id = ?,
                    hire_date = ?,
                    confirmation_date = ?,
                    employment_type = ?,
                    contract_end_date = ?,
                    employment_status = ?,
                    basic_salary = ?,
                    allowances = ?,
                    bank_name = ?,
                    account_number = ?,
                    bank_branch = ?,
                    nssf_number = ?,
                    tin_number = ?,
                    emergency_contact_name = ?,
                    emergency_contact_phone = ?,
                    emergency_contact_relationship = ?,
                    updated_at = NOW()
                WHERE employee_id = ? AND company_id = ?
            ";
            
            $stmt = $conn->prepare($employee_query);
            $stmt->execute([
                trim($_POST['employee_number']),
                !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
                !empty($_POST['position_id']) ? (int)$_POST['position_id'] : null,
                $_POST['hire_date'],
                !empty($_POST['confirmation_date']) ? $_POST['confirmation_date'] : null,
                $_POST['employment_type'],
                !empty($_POST['contract_end_date']) ? $_POST['contract_end_date'] : null,
                $_POST['employment_status'] ?? 'active',
                $basic_salary,
                $allowances,
                trim($_POST['bank_name'] ?? ''),
                trim($_POST['account_number'] ?? ''),
                trim($_POST['bank_branch'] ?? ''),
                trim($_POST['nssf_number'] ?? ''),
                trim($_POST['tin_number'] ?? ''),
                trim($_POST['emergency_contact_name'] ?? ''),
                trim($_POST['emergency_contact_phone'] ?? ''),
                trim($_POST['emergency_contact_relationship'] ?? ''),
                $employee_id,
                $company_id
            ]);
            
            $conn->commit();
            
            $_SESSION['success_message'] = "✅ Employee '{$_POST['first_name']} {$_POST['last_name']}' updated successfully!";
            header('Location: view-employee.php?id=' . $employee_id);
            exit;
            
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Error updating employee: " . $e->getMessage());
            $errors[] = "Database error occurred. Please try again.";
        }
    }
}

// Merge POST data with existing employee data for form repopulation
$form_data = array_merge($employee, $_POST ?? []);

$page_title = 'Edit Employee - ' . htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']);
require_once '../../includes/header.php';
?>

<style>
.form-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
    transition: all 0.3s ease;
}

.form-section:hover {
    box-shadow: 0 6px 25px rgba(0,0,0,0.12);
}

.form-section h5 {
    color: #007bff;
    font-weight: 700;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
}

.required-indicator {
    color: #dc3545;
    font-weight: bold;
}

.form-help-text {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

#contract_end_group {
    display: none;
}

.employee-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.employee-photo-small {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid white;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-edit text-primary me-2"></i>
                    Edit Employee
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Update employee information
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="view-employee.php?id=<?php echo $employee_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-eye me-1"></i> View Details
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Employee Header -->
        <div class="employee-header">
            <div class="row align-items-center">
                <div class="col-auto">
                    <?php if (!empty($employee['profile_picture'])): ?>
                    <img src="../../<?php echo htmlspecialchars($employee['profile_picture']); ?>" 
                         alt="Photo" class="employee-photo-small">
                    <?php else: ?>
                    <div class="employee-photo-small bg-white text-primary d-flex align-items-center justify-content-center" 
                         style="font-size: 1.5rem; font-weight: 700;">
                        <?php echo strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col">
                    <h4 class="mb-1">
                        <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>
                    </h4>
                    <div>
                        <strong>Employee #:</strong> <?php echo htmlspecialchars($employee['employee_number']); ?>
                        <span class="ms-3"><strong>Email:</strong> <?php echo htmlspecialchars($employee['email']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Validation Errors:</h5>
            <ul class="mb-0 mt-3">
                <?php foreach ($errors as $error): ?>
                <li><i class="fas fa-times-circle me-2"></i><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="employeeForm">
            
            <!-- Personal Information -->
            <div class="form-section">
                <h5><i class="fas fa-user me-2"></i>Personal Information</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">First Name <span class="required-indicator">*</span></label>
                        <input type="text" name="first_name" class="form-control" required 
                               value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>"
                               placeholder="e.g., John">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['middle_name'] ?? ''); ?>"
                               placeholder="e.g., Michael">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name <span class="required-indicator">*</span></label>
                        <input type="text" name="last_name" class="form-control" required
                               value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>"
                               placeholder="e.g., Doe">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="required-indicator">*</span></label>
                        <input type="email" name="email" class="form-control" required
                               value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                               placeholder="employee@company.com">
                        <div class="form-help-text">Used for system login</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone 1</label>
                        <input type="tel" name="phone1" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['phone1'] ?? ''); ?>"
                               placeholder="+255 712 345 678">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone 2</label>
                        <input type="tel" name="phone2" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['phone2'] ?? ''); ?>"
                               placeholder="+255 713 987 654">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">Select Gender</option>
                            <option value="male" <?php echo ($form_data['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($form_data['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo ($form_data['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['date_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">National ID</label>
                        <input type="text" name="national_id" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['national_id'] ?? ''); ?>"
                               placeholder="e.g., 19991234-56789-01-2023">
                    </div>
                </div>
            </div>

            <!-- Address Information with CSV Location -->
            <div class="form-section" style="border-left-color: #28a745;">
                <h5 style="color: #28a745;"><i class="fas fa-map-marker-alt me-2"></i>Address Information</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Region</label>
                        <select name="region" id="region" class="form-select" onchange="loadDistricts()">
                            <option value="">Select Region</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">District</label>
                        <select name="district" id="district" class="form-select" onchange="loadWards()">
                            <option value="">Select District</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ward</label>
                        <select name="ward" id="ward" class="form-select" onchange="loadStreets()">
                            <option value="">Select Ward</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Street/Village</label>
                        <select name="village" id="village" class="form-select">
                            <option value="">Select Street</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Full Address</label>
                        <textarea name="street_address" class="form-control" rows="2" 
                                  placeholder="Complete address details..."><?php echo htmlspecialchars($form_data['street_address'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Employment Details -->
            <div class="form-section" style="border-left-color: #17a2b8;">
                <h5 style="color: #17a2b8;"><i class="fas fa-briefcase me-2"></i>Employment Details</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Employee Number <span class="required-indicator">*</span></label>
                        <input type="text" name="employee_number" class="form-control" required
                               value="<?php echo htmlspecialchars($form_data['employee_number'] ?? ''); ?>"
                               placeholder="e.g., EMP001">
                        <div class="form-help-text">Unique employee identifier</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-select" id="department_id">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>"
                                    <?php echo ($form_data['department_id'] ?? '') == $dept['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Position</label>
                        <select name="position_id" class="form-select" id="position_id">
                            <option value="">Select Position</option>
                            <?php foreach ($positions as $pos): ?>
                            <option value="<?php echo $pos['position_id']; ?>" 
                                    data-department="<?php echo $pos['department_id']; ?>"
                                    <?php echo ($form_data['position_id'] ?? '') == $pos['position_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pos['position_title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Hire Date <span class="required-indicator">*</span></label>
                        <input type="date" name="hire_date" class="form-control" required
                               value="<?php echo htmlspecialchars($form_data['hire_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Confirmation Date</label>
                        <input type="date" name="confirmation_date" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['confirmation_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Employment Type <span class="required-indicator">*</span></label>
                        <select name="employment_type" class="form-select" required id="employment_type">
                            <option value="">Select Type</option>
                            <option value="permanent" <?php echo ($form_data['employment_type'] ?? '') === 'permanent' ? 'selected' : ''; ?>>Permanent</option>
                            <option value="contract" <?php echo ($form_data['employment_type'] ?? '') === 'contract' ? 'selected' : ''; ?>>Contract</option>
                            <option value="casual" <?php echo ($form_data['employment_type'] ?? '') === 'casual' ? 'selected' : ''; ?>>Casual</option>
                            <option value="intern" <?php echo ($form_data['employment_type'] ?? '') === 'intern' ? 'selected' : ''; ?>>Intern</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="contract_end_group">
                        <label class="form-label">Contract End Date</label>
                        <input type="date" name="contract_end_date" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['contract_end_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Employment Status</label>
                        <select name="employment_status" class="form-select">
                            <option value="active" <?php echo ($form_data['employment_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="suspended" <?php echo ($form_data['employment_status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="terminated" <?php echo ($form_data['employment_status'] ?? '') === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                            <option value="resigned" <?php echo ($form_data['employment_status'] ?? '') === 'resigned' ? 'selected' : ''; ?>>Resigned</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Salary Information -->
            <div class="form-section" style="border-left-color: #ffc107;">
                <h5 style="color: #ff9800;"><i class="fas fa-money-bill-wave me-2"></i>Salary Information</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Basic Salary (TSH) <span class="required-indicator">*</span></label>
                        <input type="number" name="basic_salary" class="form-control" required min="0" step="1000"
                               value="<?php echo htmlspecialchars($form_data['basic_salary'] ?? ''); ?>"
                               placeholder="e.g., 500000">
                        <div class="form-help-text">Monthly basic salary</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Allowances (TSH)</label>
                        <input type="number" name="allowances" class="form-control" min="0" step="1000"
                               value="<?php echo htmlspecialchars($form_data['allowances'] ?? '0'); ?>"
                               placeholder="e.g., 100000">
                        <div class="form-help-text">Transport, housing, medical, etc.</div>
                    </div>
                </div>
            </div>

            <!-- Tax & Social Security Information -->
            <div class="form-section" style="border-left-color: #6f42c1;">
                <h5 style="color: #6f42c1;"><i class="fas fa-id-card me-2"></i>Tax & Social Security Information</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">NSSF Number</label>
                        <input type="text" name="nssf_number" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['nssf_number'] ?? ''); ?>"
                               placeholder="Enter NSSF registration number">
                        <div class="form-help-text">National Social Security Fund registration number</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">TIN Number</label>
                        <input type="text" name="tin_number" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['tin_number'] ?? ''); ?>"
                               placeholder="Enter TIN number">
                        <div class="form-help-text">Tax Identification Number</div>
                    </div>
                </div>
            </div>

            <!-- Bank Information -->
            <div class="form-section" style="border-left-color: #20c997;">
                <h5 style="color: #20c997;"><i class="fas fa-university me-2"></i>Bank Information</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Bank Name</label>
                        <select name="bank_name" class="form-select">
                            <option value="">Select Bank</option>
                            <option value="CRDB Bank" <?php echo ($form_data['bank_name'] ?? '') === 'CRDB Bank' ? 'selected' : ''; ?>>CRDB Bank</option>
                            <option value="NMB Bank" <?php echo ($form_data['bank_name'] ?? '') === 'NMB Bank' ? 'selected' : ''; ?>>NMB Bank</option>
                            <option value="NBC Bank" <?php echo ($form_data['bank_name'] ?? '') === 'NBC Bank' ? 'selected' : ''; ?>>NBC Bank</option>
                            <option value="Exim Bank" <?php echo ($form_data['bank_name'] ?? '') === 'Exim Bank' ? 'selected' : ''; ?>>Exim Bank</option>
                            <option value="Azania Bank" <?php echo ($form_data['bank_name'] ?? '') === 'Azania Bank' ? 'selected' : ''; ?>>Azania Bank</option>
                            <option value="Other" <?php echo ($form_data['bank_name'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Account Number</label>
                        <input type="text" name="account_number" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['account_number'] ?? ''); ?>"
                               placeholder="e.g., 1234567890">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Bank Branch</label>
                        <input type="text" name="bank_branch" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['bank_branch'] ?? ''); ?>"
                               placeholder="e.g., Samora Avenue">
                    </div>
                </div>
            </div>

            <!-- Emergency Contact -->
            <div class="form-section" style="border-left-color: #dc3545;">
                <h5 style="color: #dc3545;"><i class="fas fa-phone-alt me-2"></i>Emergency Contact</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Contact Name</label>
                        <input type="text" name="emergency_contact_name" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['emergency_contact_name'] ?? ''); ?>"
                               placeholder="e.g., Jane Doe">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contact Phone</label>
                        <input type="tel" name="emergency_contact_phone" class="form-control"
                               value="<?php echo htmlspecialchars($form_data['emergency_contact_phone'] ?? ''); ?>"
                               placeholder="+255 712 345 678">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Relationship</label>
                        <select name="emergency_contact_relationship" class="form-select">
                            <option value="">Select Relationship</option>
                            <option value="spouse" <?php echo ($form_data['emergency_contact_relationship'] ?? '') === 'spouse' ? 'selected' : ''; ?>>Spouse</option>
                            <option value="parent" <?php echo ($form_data['emergency_contact_relationship'] ?? '') === 'parent' ? 'selected' : ''; ?>>Parent</option>
                            <option value="sibling" <?php echo ($form_data['emergency_contact_relationship'] ?? '') === 'sibling' ? 'selected' : ''; ?>>Sibling</option>
                            <option value="child" <?php echo ($form_data['emergency_contact_relationship'] ?? '') === 'child' ? 'selected' : ''; ?>>Child</option>
                            <option value="other" <?php echo ($form_data['emergency_contact_relationship'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-section" style="border-left-color: #6c757d; background: #f8f9fa;">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> Changes will be saved immediately. Make sure all information is correct before submitting.
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <a href="view-employee.php?id=<?php echo $employee_id; ?>" class="btn btn-outline-secondary btn-lg px-4 me-2">
                            <i class="fas fa-times me-2"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                            <i class="fas fa-save me-2"></i> Update Employee
                        </button>
                    </div>
                </div>
            </div>

        </form>

    </div>
</section>

<script>
// Store existing location values
const existingLocation = {
    region: "<?php echo htmlspecialchars($employee['region'] ?? ''); ?>",
    district: "<?php echo htmlspecialchars($employee['district'] ?? ''); ?>",
    ward: "<?php echo htmlspecialchars($employee['ward'] ?? ''); ?>",
    village: "<?php echo htmlspecialchars($employee['village'] ?? ''); ?>"
};

document.addEventListener('DOMContentLoaded', function() {
    // Load regions on page load
    loadRegions();
    
    // Show/hide contract end date based on employment type
    const employmentType = document.getElementById('employment_type');
    const contractEndGroup = document.getElementById('contract_end_group');
    
    function toggleContractEnd() {
        if (employmentType.value === 'contract') {
            contractEndGroup.style.display = 'block';
        } else {
            contractEndGroup.style.display = 'none';
        }
    }
    
    employmentType.addEventListener('change', toggleContractEnd);
    toggleContractEnd();
    
    // Department → Position Filtering
    const departmentSelect = document.getElementById('department_id');
    const positionSelect = document.getElementById('position_id');
    const allPositions = Array.from(positionSelect.options).filter(opt => opt.value !== '');
    
    departmentSelect.addEventListener('change', function() {
        const selectedDeptId = this.value;
        const currentPositionId = positionSelect.value;
        
        positionSelect.innerHTML = '<option value="">Select Position</option>';
        
        if (!selectedDeptId) {
            allPositions.forEach(option => {
                positionSelect.appendChild(option.cloneNode(true));
            });
        } else {
            allPositions.forEach(option => {
                if (option.dataset.department == selectedDeptId) {
                    positionSelect.appendChild(option.cloneNode(true));
                }
            });
        }
        
        // Restore selected position if it's still in the filtered list
        if (currentPositionId) {
            const posOption = positionSelect.querySelector(`option[value="${currentPositionId}"]`);
            if (posOption) {
                posOption.selected = true;
            }
        }
    });
});

// Load regions from CSV
async function loadRegions() {
    try {
        const response = await fetch('get_locations.php?action=get_regions');
        const result = await response.json();
        
        if (result.success) {
            const regionSelect = document.getElementById('region');
            regionSelect.innerHTML = '<option value="">Select Region</option>';
            
            result.data.forEach(region => {
                const option = document.createElement('option');
                option.value = region.name;
                option.textContent = region.name;
                option.setAttribute('data-region-code', region.code);
                
                // Pre-select existing region
                if (region.name === existingLocation.region) {
                    option.selected = true;
                }
                
                regionSelect.appendChild(option);
            });
            
            // Load districts if region is selected
            if (existingLocation.region) {
                await loadDistricts(true);
            }
        }
    } catch (error) {
        console.error('Error loading regions:', error);
    }
}

// Load districts based on selected region
async function loadDistricts(isInitial = false) {
    const regionSelect = document.getElementById('region');
    const districtSelect = document.getElementById('district');
    const wardSelect = document.getElementById('ward');
    const villageSelect = document.getElementById('village');
    
    const selectedRegion = regionSelect.value;
    
    districtSelect.innerHTML = '<option value="">Select District</option>';
    wardSelect.innerHTML = '<option value="">Select Ward</option>';
    villageSelect.innerHTML = '<option value="">Select Street</option>';
    
    if (!selectedRegion) return;
    
    try {
        const response = await fetch(`get_locations.php?action=get_districts&region=${encodeURIComponent(selectedRegion)}`);
        const result = await response.json();
        
        if (result.success) {
            result.data.forEach(district => {
                const option = document.createElement('option');
                option.value = district.name;
                option.textContent = district.name;
                option.setAttribute('data-district-code', district.code);
                
                // Pre-select existing district
                if (isInitial && district.name === existingLocation.district) {
                    option.selected = true;
                }
                
                districtSelect.appendChild(option);
            });
            
            // Load wards if district is selected
            if (isInitial && existingLocation.district) {
                await loadWards(true);
            }
        }
    } catch (error) {
        console.error('Error loading districts:', error);
    }
}

// Load wards based on selected district
async function loadWards(isInitial = false) {
    const regionSelect = document.getElementById('region');
    const districtSelect = document.getElementById('district');
    const wardSelect = document.getElementById('ward');
    const villageSelect = document.getElementById('village');
    
    const selectedRegion = regionSelect.value;
    const selectedDistrict = districtSelect.value;
    
    wardSelect.innerHTML = '<option value="">Select Ward</option>';
    villageSelect.innerHTML = '<option value="">Select Street</option>';
    
    if (!selectedRegion || !selectedDistrict) return;
    
    try {
        const response = await fetch(`get_locations.php?action=get_wards&region=${encodeURIComponent(selectedRegion)}&district=${encodeURIComponent(selectedDistrict)}`);
        const result = await response.json();
        
        if (result.success) {
            result.data.forEach(ward => {
                const option = document.createElement('option');
                option.value = ward.name;
                option.textContent = ward.name;
                option.setAttribute('data-ward-code', ward.code);
                
                // Pre-select existing ward
                if (isInitial && ward.name === existingLocation.ward) {
                    option.selected = true;
                }
                
                wardSelect.appendChild(option);
            });
            
            // Load streets if ward is selected
            if (isInitial && existingLocation.ward) {
                await loadStreets(true);
            }
        }
    } catch (error) {
        console.error('Error loading wards:', error);
    }
}

// Load streets based on selected ward
async function loadStreets(isInitial = false) {
    const regionSelect = document.getElementById('region');
    const districtSelect = document.getElementById('district');
    const wardSelect = document.getElementById('ward');
    const villageSelect = document.getElementById('village');
    
    const selectedRegion = regionSelect.value;
    const selectedDistrict = districtSelect.value;
    const selectedWard = wardSelect.value;
    
    villageSelect.innerHTML = '<option value="">Select Street</option>';
    
    if (!selectedRegion || !selectedDistrict || !selectedWard) return;
    
    try {
        const response = await fetch(`get_locations.php?action=get_streets&region=${encodeURIComponent(selectedRegion)}&district=${encodeURIComponent(selectedDistrict)}&ward=${encodeURIComponent(selectedWard)}`);
        const result = await response.json();
        
        if (result.success) {
            result.data.forEach(street => {
                const option = document.createElement('option');
                option.value = street.name;
                option.textContent = street.name;
                
                // Pre-select existing street
                if (isInitial && street.name === existingLocation.village) {
                    option.selected = true;
                }
                
                villageSelect.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading streets:', error);
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>