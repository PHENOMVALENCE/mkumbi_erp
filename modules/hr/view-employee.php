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
            u.full_name,
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
            u.street_address,
            d.department_name,
            p.position_title,
            creator.full_name as created_by_name,
            TIMESTAMPDIFF(YEAR, e.hire_date, CURDATE()) as years_of_service,
            TIMESTAMPDIFF(MONTH, e.hire_date, CURDATE()) as months_of_service,
            DATEDIFF(CURDATE(), e.hire_date) as days_of_service
        FROM employees e
        INNER JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        LEFT JOIN users creator ON e.created_by = creator.user_id
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

// Calculate age if date of birth is available
$age = null;
if (!empty($employee['date_of_birth'])) {
    $dob = new DateTime($employee['date_of_birth']);
    $now = new DateTime();
    $age = $dob->diff($now)->y;
}

// Calculate total salary
$total_salary = ($employee['basic_salary'] ?? 0) + ($employee['allowances'] ?? 0);

$page_title = 'View Employee - ' . htmlspecialchars($employee['full_name']);
require_once '../../includes/header.php';
?>

<style>
.employee-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
}

.employee-photo-large {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid white;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.info-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
}

.info-card h5 {
    color: #007bff;
    font-weight: 700;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
}

.info-card h5 i {
    margin-right: 0.5rem;
}

.info-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #495057;
    min-width: 200px;
}

.info-value {
    color: #212529;
    flex: 1;
}

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-badge.terminated, .status-badge.resigned {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.status-badge.suspended {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}

.employment-type-badge {
    background: #e7f3ff;
    color: #0066cc;
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
}

.salary-box {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
    margin-bottom: 1rem;
}

.salary-amount {
    font-size: 2rem;
    font-weight: 700;
    margin: 0.5rem 0;
}

.salary-label {
    font-size: 0.9rem;
    opacity: 0.9;
}

.service-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
}

.service-years {
    font-size: 2rem;
    font-weight: 700;
    margin: 0.5rem 0;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.stat-box {
    background: white;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
    border: 2px solid #e9ecef;
}

.stat-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: #007bff;
}

.stat-label {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.empty-value {
    color: #adb5bd;
    font-style: italic;
}

@media print {
    .action-buttons, .btn, .content-header {
        display: none !important;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user text-primary me-2"></i>
                    Employee Details
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Complete employee information
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end action-buttons">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to List
                    </a>
                    <a href="edit-employee.php?id=<?php echo $employee_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-1"></i> Edit Employee
                    </a>
                    <button onclick="window.print()" class="btn btn-info">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Employee Header -->
        <div class="employee-header">
            <div class="row align-items-center">
                <div class="col-auto">
                    <?php if (!empty($employee['profile_picture'])): ?>
                    <img src="../../<?php echo htmlspecialchars($employee['profile_picture']); ?>" 
                         alt="Photo" class="employee-photo-large">
                    <?php else: ?>
                    <div class="employee-photo-large bg-white text-primary d-flex align-items-center justify-content-center" 
                         style="font-size: 2.5rem; font-weight: 700;">
                        <?php echo strtoupper(substr($employee['full_name'], 0, 2)); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col">
                    <h2 class="mb-2"><?php echo htmlspecialchars($employee['full_name']); ?></h2>
                    <div class="mb-2">
                        <span class="status-badge <?php echo strtolower($employee['employment_status']); ?>">
                            <?php echo ucfirst($employee['employment_status']); ?>
                        </span>
                        <span class="employment-type-badge ms-2">
                            <?php echo ucfirst($employee['employment_type']); ?>
                        </span>
                    </div>
                    <div>
                        <strong>Employee #:</strong> <?php echo htmlspecialchars($employee['employee_number']); ?>
                        <span class="ms-3"><strong>Email:</strong> <?php echo htmlspecialchars($employee['email']); ?></span>
                        <?php if ($employee['phone1']): ?>
                        <span class="ms-3"><strong>Phone:</strong> <?php echo htmlspecialchars($employee['phone1']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                
                <!-- Personal Information -->
                <div class="info-card">
                    <h5><i class="fas fa-user"></i> Personal Information</h5>
                    
                    <div class="info-row">
                        <div class="info-label">Full Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($employee['full_name']); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">First Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($employee['first_name'] ?? '—'); ?></div>
                    </div>
                    
                    <?php if ($employee['middle_name']): ?>
                    <div class="info-row">
                        <div class="info-label">Middle Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($employee['middle_name']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-row">
                        <div class="info-label">Last Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($employee['last_name'] ?? '—'); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Gender:</div>
                        <div class="info-value">
                            <?php echo $employee['gender'] ? ucfirst($employee['gender']) : '<span class="empty-value">Not specified</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Date of Birth:</div>
                        <div class="info-value">
                            <?php if ($employee['date_of_birth']): ?>
                                <?php echo date('F d, Y', strtotime($employee['date_of_birth'])); ?>
                                <?php if ($age): ?>
                                    <span class="text-muted">(<?php echo $age; ?> years old)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="empty-value">Not specified</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">National ID:</div>
                        <div class="info-value">
                            <?php echo $employee['national_id'] ? htmlspecialchars($employee['national_id']) : '<span class="empty-value">Not provided</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Email Address:</div>
                        <div class="info-value">
                            <a href="mailto:<?php echo htmlspecialchars($employee['email']); ?>">
                                <?php echo htmlspecialchars($employee['email']); ?>
                            </a>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Primary Phone:</div>
                        <div class="info-value">
                            <?php echo $employee['phone1'] ? htmlspecialchars($employee['phone1']) : '<span class="empty-value">Not provided</span>'; ?>
                        </div>
                    </div>
                    
                    <?php if ($employee['phone2']): ?>
                    <div class="info-row">
                        <div class="info-label">Secondary Phone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($employee['phone2']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Address Information -->
                <div class="info-card" style="border-left-color: #28a745;">
                    <h5 style="color: #28a745;"><i class="fas fa-map-marker-alt"></i> Address Information</h5>
                    
                    <div class="info-row">
                        <div class="info-label">Region:</div>
                        <div class="info-value">
                            <?php echo $employee['region'] ? htmlspecialchars($employee['region']) : '<span class="empty-value">Not specified</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">District:</div>
                        <div class="info-value">
                            <?php echo $employee['district'] ? htmlspecialchars($employee['district']) : '<span class="empty-value">Not specified</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Ward:</div>
                        <div class="info-value">
                            <?php echo $employee['ward'] ? htmlspecialchars($employee['ward']) : '<span class="empty-value">Not specified</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Street/Village:</div>
                        <div class="info-value">
                            <?php echo $employee['village'] ? htmlspecialchars($employee['village']) : '<span class="empty-value">Not specified</span>'; ?>
                        </div>
                    </div>
                    
                    <?php if ($employee['street_address']): ?>
                    <div class="info-row">
                        <div class="info-label">Full Address:</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($employee['street_address'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Employment Details -->
                <div class="info-card" style="border-left-color: #17a2b8;">
                    <h5 style="color: #17a2b8;"><i class="fas fa-briefcase"></i> Employment Details</h5>
                    
                    <div class="info-row">
                        <div class="info-label">Employee Number:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($employee['employee_number']); ?></strong>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Department:</div>
                        <div class="info-value">
                            <?php echo $employee['department_name'] ? htmlspecialchars($employee['department_name']) : '<span class="empty-value">Not assigned</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Position:</div>
                        <div class="info-value">
                            <?php echo $employee['position_title'] ? htmlspecialchars($employee['position_title']) : '<span class="empty-value">Not assigned</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Employment Type:</div>
                        <div class="info-value">
                            <span class="employment-type-badge">
                                <?php echo ucfirst($employee['employment_type']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Employment Status:</div>
                        <div class="info-value">
                            <span class="status-badge <?php echo strtolower($employee['employment_status']); ?>">
                                <?php echo ucfirst($employee['employment_status']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Hire Date:</div>
                        <div class="info-value">
                            <?php echo date('F d, Y', strtotime($employee['hire_date'])); ?>
                            <span class="text-muted">
                                (<?php echo $employee['years_of_service']; ?> years, 
                                <?php echo $employee['months_of_service'] % 12; ?> months)
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($employee['confirmation_date']): ?>
                    <div class="info-row">
                        <div class="info-label">Confirmation Date:</div>
                        <div class="info-value"><?php echo date('F d, Y', strtotime($employee['confirmation_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($employee['employment_type'] === 'contract' && $employee['contract_end_date']): ?>
                    <div class="info-row">
                        <div class="info-label">Contract End Date:</div>
                        <div class="info-value">
                            <?php echo date('F d, Y', strtotime($employee['contract_end_date'])); ?>
                            <?php
                            $end_date = new DateTime($employee['contract_end_date']);
                            $now = new DateTime();
                            if ($end_date < $now) {
                                echo '<span class="badge bg-danger ms-2">Expired</span>';
                            } else {
                                $days_remaining = $now->diff($end_date)->days;
                                echo '<span class="badge bg-warning text-dark ms-2">' . $days_remaining . ' days remaining</span>';
                            }
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tax & Social Security Information -->
                <div class="info-card" style="border-left-color: #6f42c1;">
                    <h5 style="color: #6f42c1;"><i class="fas fa-id-card"></i> Tax & Social Security</h5>
                    
                    <div class="info-row">
                        <div class="info-label">NSSF Number:</div>
                        <div class="info-value">
                            <?php echo $employee['nssf_number'] ? htmlspecialchars($employee['nssf_number']) : '<span class="empty-value">Not provided</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">TIN Number:</div>
                        <div class="info-value">
                            <?php echo $employee['tin_number'] ? htmlspecialchars($employee['tin_number']) : '<span class="empty-value">Not provided</span>'; ?>
                        </div>
                    </div>
                </div>

                <!-- Bank Information -->
                <div class="info-card" style="border-left-color: #20c997;">
                    <h5 style="color: #20c997;"><i class="fas fa-university"></i> Bank Information</h5>
                    
                    <div class="info-row">
                        <div class="info-label">Bank Name:</div>
                        <div class="info-value">
                            <?php echo $employee['bank_name'] ? htmlspecialchars($employee['bank_name']) : '<span class="empty-value">Not provided</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Account Number:</div>
                        <div class="info-value">
                            <?php echo $employee['account_number'] ? htmlspecialchars($employee['account_number']) : '<span class="empty-value">Not provided</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Bank Branch:</div>
                        <div class="info-value">
                            <?php echo $employee['bank_branch'] ? htmlspecialchars($employee['bank_branch']) : '<span class="empty-value">Not provided</span>'; ?>
                        </div>
                    </div>
                </div>

                <!-- Emergency Contact -->
                <div class="info-card" style="border-left-color: #dc3545;">
                    <h5 style="color: #dc3545;"><i class="fas fa-phone-alt"></i> Emergency Contact</h5>
                    
                    <div class="info-row">
                        <div class="info-label">Contact Name:</div>
                        <div class="info-value">
                            <?php echo $employee['emergency_contact_name'] ? htmlspecialchars($employee['emergency_contact_name']) : '<span class="empty-value">Not provided</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Contact Phone:</div>
                        <div class="info-value">
                            <?php echo $employee['emergency_contact_phone'] ? htmlspecialchars($employee['emergency_contact_phone']) : '<span class="empty-value">Not provided</span>'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Relationship:</div>
                        <div class="info-value">
                            <?php echo $employee['emergency_contact_relationship'] ? ucfirst($employee['emergency_contact_relationship']) : '<span class="empty-value">Not specified</span>'; ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                
                <!-- Salary Information -->
                <div class="salary-box">
                    <div class="salary-label">Total Monthly Salary</div>
                    <div class="salary-amount">TSH <?php echo number_format($total_salary, 0); ?></div>
                    <hr style="border-color: rgba(255,255,255,0.3);">
                    <div class="row text-start">
                        <div class="col-6">
                            <small>Basic Salary:</small>
                            <div><strong>TSH <?php echo number_format($employee['basic_salary'] ?? 0, 0); ?></strong></div>
                        </div>
                        <div class="col-6">
                            <small>Allowances:</small>
                            <div><strong>TSH <?php echo number_format($employee['allowances'] ?? 0, 0); ?></strong></div>
                        </div>
                    </div>
                </div>

                <!-- Service Duration -->
                <div class="service-box mb-3">
                    <div class="salary-label">Years of Service</div>
                    <div class="service-years">
                        <?php 
                        $years = $employee['years_of_service'];
                        $months = $employee['months_of_service'] % 12;
                        echo $years . 'y ' . $months . 'm';
                        ?>
                    </div>
                    <div>Since <?php echo date('F Y', strtotime($employee['hire_date'])); ?></div>
                </div>

                <!-- Quick Stats -->
                <div class="info-card">
                    <h5><i class="fas fa-chart-line"></i> Quick Stats</h5>
                    
                    <div class="stat-box mb-2">
                        <div class="stat-number"><?php echo $employee['days_of_service']; ?></div>
                        <div class="stat-label">Total Days of Service</div>
                    </div>
                    
                    <div class="stat-box mb-2">
                        <div class="stat-number"><?php echo date('l', strtotime($employee['hire_date'])); ?></div>
                        <div class="stat-label">Hired on</div>
                    </div>
                    
                    <?php if ($employee['confirmation_date']): ?>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                            $confirmed = new DateTime($employee['confirmation_date']);
                            $hired = new DateTime($employee['hire_date']);
                            echo $hired->diff($confirmed)->days;
                            ?>
                        </div>
                        <div class="stat-label">Days to Confirmation</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- System Information -->
                <div class="info-card" style="border-left-color: #6c757d;">
                    <h5 style="color: #6c757d;"><i class="fas fa-info-circle"></i> System Information</h5>
                    
                    <div class="info-row">
                        <div class="info-label">Created By:</div>
                        <div class="info-value">
                            <?php echo $employee['created_by_name'] ? htmlspecialchars($employee['created_by_name']) : 'System'; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Created On:</div>
                        <div class="info-value">
                            <?php echo date('F d, Y g:i A', strtotime($employee['created_at'])); ?>
                        </div>
                    </div>
                    
                    <?php if ($employee['updated_at']): ?>
                    <div class="info-row">
                        <div class="info-label">Last Updated:</div>
                        <div class="info-value">
                            <?php echo date('F d, Y g:i A', strtotime($employee['updated_at'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>