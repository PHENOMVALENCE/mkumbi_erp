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

// Fetch departments
$departments = [];
try {
    $dept_query = "SELECT department_id, department_name FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY department_name ASC";
    $stmt = $conn->prepare($dept_query);
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no departments for this company, get all active departments
    if (empty($departments)) {
        $all_dept_query = "SELECT department_id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC";
        $stmt = $conn->prepare($all_dept_query);
        $stmt->execute();
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching departments: " . $e->getMessage());
    $departments = [
        ['department_id' => 1, 'department_name' => 'Human Resources'],
        ['department_id' => 2, 'department_name' => 'Finance'],
        ['department_id' => 3, 'department_name' => 'Information Technology'],
        ['department_id' => 4, 'department_name' => 'Operations'],
        ['department_id' => 5, 'department_name' => 'Sales'],
        ['department_id' => 6, 'department_name' => 'Marketing'],
        ['department_id' => 7, 'department_name' => 'Procurement']
    ];
}

// Fetch positions
$positions = [];
$positions_by_department = [];
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
    
    // Create positions by department mapping
    foreach ($positions as $pos) {
        $dept_id = $pos['department_id'] ?? 0;
        if (!isset($positions_by_department[$dept_id])) {
            $positions_by_department[$dept_id] = [];
        }
        $positions_by_department[$dept_id][] = $pos;
    }
} catch (PDOException $e) {
    error_log("Error fetching positions: " . $e->getMessage());
    $positions = [
        ['position_id' => 1, 'position_title' => 'HR Manager', 'department_id' => 1],
        ['position_id' => 2, 'position_title' => 'Accountant', 'department_id' => 2],
        ['position_id' => 3, 'position_title' => 'IT Support', 'department_id' => 3],
        ['position_id' => 4, 'position_title' => 'Operations Manager', 'department_id' => 4],
        ['position_id' => 5, 'position_title' => 'Sales Executive', 'department_id' => 5]
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
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
    
    // Check if email already exists
    if (!empty($_POST['email'])) {
        try {
            $check_query = "SELECT user_id FROM users WHERE email = ? AND company_id = ?";
            $stmt = $conn->prepare($check_query);
            $stmt->execute([$_POST['email'], $company_id]);
            if ($stmt->fetch()) {
                $errors[] = "Email address already exists";
            }
        } catch (PDOException $e) {
            error_log("Email check error: " . $e->getMessage());
        }
    }
    
    // Check if employee number already exists
    if (!empty($_POST['employee_number'])) {
        try {
            $check_query = "SELECT employee_id FROM employees WHERE employee_number = ? AND company_id = ?";
            $stmt = $conn->prepare($check_query);
            $stmt->execute([$_POST['employee_number'], $company_id]);
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
            
            // Generate secure password
            $default_password = bin2hex(random_bytes(8));
            $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
            
            // Generate username from email
            $username = explode('@', $_POST['email'])[0];
            $base_username = $username;
            $counter = 1;
            
            // Ensure unique username
            while (true) {
                $check_query = "SELECT user_id FROM users WHERE username = ?";
                $stmt = $conn->prepare($check_query);
                $stmt->execute([$username]);
                if (!$stmt->fetch()) break;
                $username = $base_username . $counter++;
            }
            
            // Insert user
            $user_query = "
                INSERT INTO users (
                    company_id, username, email, password_hash,
                    first_name, middle_name, last_name,
                    phone1, phone2, region, district, ward, village,
                    street_address, gender, date_of_birth, national_id,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $conn->prepare($user_query);
            $stmt->execute([
                $company_id,
                $username,
                $_POST['email'],
                $hashed_password,
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
                $_SESSION['user_id'] ?? 1
            ]);
            
            $user_id = $conn->lastInsertId();
            
            // Calculate salary
            $basic_salary = (float)$_POST['basic_salary'];
            $allowances = (float)($_POST['allowances'] ?? 0);
            
            // Insert employee
            $employee_query = "
                INSERT INTO employees (
                    company_id, user_id, employee_number,
                    department_id, position_id,
                    hire_date, confirmation_date, employment_type,
                    contract_end_date, basic_salary, allowances,
                    bank_name, account_number, bank_branch,
                    nssf_number, tin_number,
                    emergency_contact_name, emergency_contact_phone, emergency_contact_relationship,
                    employment_status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $conn->prepare($employee_query);
            $stmt->execute([
                $company_id,
                $user_id,
                trim($_POST['employee_number']),
                !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
                !empty($_POST['position_id']) ? (int)$_POST['position_id'] : null,
                $_POST['hire_date'],
                !empty($_POST['confirmation_date']) ? $_POST['confirmation_date'] : null,
                $_POST['employment_type'],
                !empty($_POST['contract_end_date']) ? $_POST['contract_end_date'] : null,
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
                'active',
                $_SESSION['user_id'] ?? 1
            ]);
            
            $conn->commit();
            
            $_SESSION['success_message'] = "✅ Employee '{$_POST['first_name']} {$_POST['last_name']}' added successfully!<br>
                <strong>Login Details:</strong><br>
                Username: <code>$username</code><br>
                Password: <code>$default_password</code><br>
                <small class='text-muted'>Password must be changed on first login</small>";
            header('Location: employees.php');
            exit;
            
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Error adding employee: " . $e->getMessage());
            $errors[] = "Database error occurred. Please try again.";
        }
    }
}

$page_title = 'Add Employee';
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
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-plus text-primary me-2"></i>
                    Add New Employee
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Complete employee information
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Employees
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

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
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                               placeholder="e.g., John">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>"
                               placeholder="e.g., Michael">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name <span class="required-indicator">*</span></label>
                        <input type="text" name="last_name" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                               placeholder="e.g., Doe">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="required-indicator">*</span></label>
                        <input type="email" name="email" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               placeholder="employee@company.com">
                        <div class="form-help-text">Used for system login</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone 1</label>
                        <input type="tel" name="phone1" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['phone1'] ?? ''); ?>"
                               placeholder="+255 712 345 678">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone 2</label>
                        <input type="tel" name="phone2" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['phone2'] ?? ''); ?>"
                               placeholder="+255 713 987 654">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">Select Gender</option>
                            <option value="male" <?php echo ($_POST['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($_POST['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo ($_POST['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">National ID</label>
                        <input type="text" name="national_id" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['national_id'] ?? ''); ?>"
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
                                  placeholder="Complete address details..."><?php echo htmlspecialchars($_POST['street_address'] ?? ''); ?></textarea>
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
                               value="<?php echo htmlspecialchars($_POST['employee_number'] ?? ''); ?>"
                               placeholder="e.g., EMP001">
                        <div class="form-help-text">Unique employee identifier</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-select" id="department_id">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>"
                                    <?php echo ($_POST['department_id'] ?? '') == $dept['department_id'] ? 'selected' : ''; ?>>
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
                                    <?php echo ($_POST['position_id'] ?? '') == $pos['position_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pos['position_title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Hire Date <span class="required-indicator">*</span></label>
                        <input type="date" name="hire_date" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['hire_date'] ?? date('Y-m-d')); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Confirmation Date</label>
                        <input type="date" name="confirmation_date" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['confirmation_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Employment Type <span class="required-indicator">*</span></label>
                        <select name="employment_type" class="form-select" required id="employment_type">
                            <option value="">Select Type</option>
                            <option value="permanent" <?php echo ($_POST['employment_type'] ?? '') === 'permanent' ? 'selected' : ''; ?>>Permanent</option>
                            <option value="contract" <?php echo ($_POST['employment_type'] ?? '') === 'contract' ? 'selected' : ''; ?>>Contract</option>
                            <option value="casual" <?php echo ($_POST['employment_type'] ?? '') === 'casual' ? 'selected' : ''; ?>>Casual</option>
                            <option value="intern" <?php echo ($_POST['employment_type'] ?? '') === 'intern' ? 'selected' : ''; ?>>Intern</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="contract_end_group" style="display: none;">
                        <label class="form-label">Contract End Date</label>
                        <input type="date" name="contract_end_date" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['contract_end_date'] ?? ''); ?>">
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
                               value="<?php echo htmlspecialchars($_POST['basic_salary'] ?? ''); ?>"
                               placeholder="e.g., 500000">
                        <div class="form-help-text">Monthly basic salary</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Allowances (TSH)</label>
                        <input type="number" name="allowances" class="form-control" min="0" step="1000"
                               value="<?php echo htmlspecialchars($_POST['allowances'] ?? '0'); ?>"
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
                               value="<?php echo htmlspecialchars($_POST['nssf_number'] ?? ''); ?>"
                               placeholder="Enter NSSF registration number">
                        <div class="form-help-text">National Social Security Fund registration number</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">TIN Number</label>
                        <input type="text" name="tin_number" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['tin_number'] ?? ''); ?>"
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
                            <option value="CRDB Bank" <?php echo ($_POST['bank_name'] ?? '') === 'CRDB Bank' ? 'selected' : ''; ?>>CRDB Bank</option>
                            <option value="NMB Bank" <?php echo ($_POST['bank_name'] ?? '') === 'NMB Bank' ? 'selected' : ''; ?>>NMB Bank</option>
                            <option value="NBC Bank" <?php echo ($_POST['bank_name'] ?? '') === 'NBC Bank' ? 'selected' : ''; ?>>NBC Bank</option>
                            <option value="Exim Bank" <?php echo ($_POST['bank_name'] ?? '') === 'Exim Bank' ? 'selected' : ''; ?>>Exim Bank</option>
                            <option value="Azania Bank" <?php echo ($_POST['bank_name'] ?? '') === 'Azania Bank' ? 'selected' : ''; ?>>Azania Bank</option>
                            <option value="Other" <?php echo ($_POST['bank_name'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Account Number</label>
                        <input type="text" name="account_number" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['account_number'] ?? ''); ?>"
                               placeholder="e.g., 1234567890">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Bank Branch</label>
                        <input type="text" name="bank_branch" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['bank_branch'] ?? ''); ?>"
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
                               value="<?php echo htmlspecialchars($_POST['emergency_contact_name'] ?? ''); ?>"
                               placeholder="e.g., Jane Doe">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contact Phone</label>
                        <input type="tel" name="emergency_contact_phone" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['emergency_contact_phone'] ?? ''); ?>"
                               placeholder="+255 712 345 678">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Relationship</label>
                        <select name="emergency_contact_relationship" class="form-select">
                            <option value="">Select Relationship</option>
                            <option value="spouse" <?php echo ($_POST['emergency_contact_relationship'] ?? '') === 'spouse' ? 'selected' : ''; ?>>Spouse</option>
                            <option value="parent" <?php echo ($_POST['emergency_contact_relationship'] ?? '') === 'parent' ? 'selected' : ''; ?>>Parent</option>
                            <option value="sibling" <?php echo ($_POST['emergency_contact_relationship'] ?? '') === 'sibling' ? 'selected' : ''; ?>>Sibling</option>
                            <option value="child" <?php echo ($_POST['emergency_contact_relationship'] ?? '') === 'child' ? 'selected' : ''; ?>>Child</option>
                            <option value="other" <?php echo ($_POST['emergency_contact_relationship'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
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
                            <strong>Important:</strong> Default password will be generated automatically and shown after successful submission.
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <a href="index.php" class="btn btn-outline-secondary btn-lg px-4 me-2">
                            <i class="fas fa-times me-2"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                            <i class="fas fa-save me-2"></i> Save Employee
                        </button>
                    </div>
                </div>
            </div>

        </form>

    </div>
</section>

<script>
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
        positionSelect.innerHTML = '<option value="">Select Position</option>';
        
        if (!selectedDeptId) {
            allPositions.forEach(option => {
                positionSelect.appendChild(option.cloneNode(true));
            });
            return;
        }
        
        allPositions.forEach(option => {
            if (option.dataset.department == selectedDeptId) {
                positionSelect.appendChild(option.cloneNode(true));
            }
        });
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
                regionSelect.appendChild(option);
            });
            
            console.log('Loaded ' + result.data.length + ' regions');
        } else {
            console.error('Failed to load regions:', result.error);
            alert('Failed to load regions: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error loading regions:', error);
        alert('Error loading regions. Please check console for details.');
    }
}

// Load districts based on selected region
async function loadDistricts() {
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
                districtSelect.appendChild(option);
            });
            
            console.log('Loaded ' + result.data.length + ' districts for ' + selectedRegion);
        } else {
            console.error('Failed to load districts:', result.error);
        }
    } catch (error) {
        console.error('Error loading districts:', error);
    }
}

// Load wards based on selected district
async function loadWards() {
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
                wardSelect.appendChild(option);
            });
            
            console.log('Loaded ' + result.data.length + ' wards for ' + selectedDistrict);
        } else {
            console.error('Failed to load wards:', result.error);
        }
    } catch (error) {
        console.error('Error loading wards:', error);
    }
}

// Load streets based on selected ward
async function loadStreets() {
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
                villageSelect.appendChild(option);
            });
            
            console.log('Loaded ' + result.data.length + ' streets for ' + selectedWard);
        } else {
            console.error('Failed to load streets:', result.error);
        }
    } catch (error) {
        console.error('Error loading streets:', error);
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>