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

if ($employee_id <= 0) {
    $_SESSION['error_message'] = "Invalid employee ID";
    header('Location: employees.php');
    exit;
}

// Fetch employee data
$employee = null;
try {
    $query = "
        SELECT 
            e.*,
            u.username, u.email, u.first_name, u.middle_name, u.last_name,
            u.phone1, u.phone2, u.gender, u.date_of_birth, u.national_id,
            u.region, u.district, u.ward, u.village, u.street_address,
            d.department_name,
            p.position_title,
            u.created_at as user_created_at,
            e.created_at
        FROM employees e
        LEFT JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        WHERE e.employee_id = ? AND e.company_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$employee_id, $company_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        $_SESSION['error_message'] = "Employee not found";
        header('Location: employees.php');
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching employee: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading employee data";
    header('Location: employees.php');
    exit;
}

// Fetch recent payroll (last 3 months)
$recent_payrolls = [];
try {
    $payroll_query = "
        SELECT 
            payroll_month, basic_salary, allowances, total_salary, 
            payment_date, status
        FROM payroll 
        WHERE employee_id = ? 
        AND payroll_month >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
        ORDER BY payroll_month DESC
    ";
    $stmt = $conn->prepare($payroll_query);
    $stmt->execute([$employee_id]);
    $recent_payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching payroll: " . $e->getMessage());
}

// Calculate tenure
$hire_date = new DateTime($employee['hire_date']);
$today = new DateTime();
$tenure = $today->diff($hire_date);
$tenure_text = $tenure->y . ' years, ' . $tenure->m . ' months';

$page_title = 'View Employee - ' . ($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '');
require_once '../../includes/header.php';
?>

<style>
.employee-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 20px;
    padding: 2.5rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.employee-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 5px solid rgba(255,255,255,0.2);
    object-fit: cover;
    margin-bottom: 1.5rem;
}

.employee-name {
    font-size: 2.2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    line-height: 1.2;
}

.employee-title {
    font-size: 1.1rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
}

.employee-id {
    background: rgba(255,255,255,0.2);
    padding: 0.5rem 1rem;
    border-radius: 25px;
    font-size: 0.9rem;
    font-weight: 600;
    display: inline-block;
}

.info-card {
    background: white;
    border-radius: 16px;
    padding: 1.75rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
    margin-bottom: 1.5rem;
}

.info-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.info-value {
    font-size: 1.1rem;
    font-weight: 500;
    color: #212529;
}

.status-badge {
    padding: 0.6rem 1.2rem;
    border-radius: 25px;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
}

.status-active {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
}

.status-inactive {
    background: linear-gradient(135deg, #f8d7da, #f5c6cb);
    color: #721c24;
}

.salary-card {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: white;
    border-radius: 16px;
    padding: 2rem;
    text-align: center;
}

.salary-amount {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.salary-breakdown {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}

.salary-item {
    background: rgba(255,255,255,0.1);
    padding: 1rem;
    border-radius: 12px;
    backdrop-filter: blur(10px);
}

.payroll-table {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.payroll-table th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    padding: 1.2rem 1rem;
    border: none;
}

.payroll-table td {
    padding: 1rem;
    vertical-align: middle;
    border-color: #f8f9fa;
}

.action-buttons .btn {
    margin: 0 0.25rem;
    border-radius: 10px;
    padding: 0.5rem 1rem;
    font-weight: 600;
}

.btn-group-sm .btn {
    padding: 0.375rem 0.75rem;
}

@media (max-width: 768px) {
    .employee-header {
        padding: 1.5rem;
        text-align: center;
    }
    
    .employee-avatar {
        width: 100px;
        height: 100px;
        margin: 0 auto 1rem;
    }
    
    .employee-name {
        font-size: 1.8rem;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold text-primary">
                    <i class="fas fa-user me-2"></i>
                    Employee Profile
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="employees.php">Employees</a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')); ?></li>
                    </ol>
                </nav>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <div class="btn-group action-buttons" role="group">
                        <a href="edit-employee.php?id=<?php echo $employee_id; ?>" class="btn btn-outline-success" title="Edit Employee">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                        <a href="print-employee.php?id=<?php echo $employee_id; ?>" class="btn btn-outline-primary" title="Print Profile" target="_blank">
                            <i class="fas fa-print me-1"></i> Print
                        </a>
                        <a href="employees.php" class="btn btn-outline-secondary" title="Back to List">
                            <i class="fas fa-arrow-left me-1"></i> Back
                        </a>
                    </div>
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
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Employee Header -->
        <div class="employee-header">
            <div class="row align-items-center">
                <div class="col-md-3 text-center">
                    <div class="position-relative">
                        <?php if (!empty($employee['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($employee['profile_picture']); ?>" 
                             alt="Profile Picture" class="employee-avatar" 
                             onerror="this.src='../../assets/images/default-avatar.png'">
                        <?php else: ?>
                        <div class="employee-avatar bg-light d-flex align-items-center justify-content-center">
                            <i class="fas fa-user fa-3x text-muted"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-9">
                    <div class="row">
                        <div class="col-md-8">
                            <h1 class="employee-name">
                                <?php echo htmlspecialchars(($employee['first_name'] ?? '') . ' ' . ($employee['middle_name'] ? $employee['middle_name'] . ' ' : '') . ($employee['last_name'] ?? '')); ?>
                            </h1>
                            <p class="employee-title">
                                <i class="fas fa-briefcase me-2"></i>
                                <?php echo htmlspecialchars($employee['position_title'] ?? 'Position Not Assigned'); ?>
                            </p>
                            <p class="mb-0">
                                <span class="employee-id">
                                    <i class="fas fa-id-badge me-2"></i>
                                    <?php echo htmlspecialchars($employee['employee_number'] ?? 'N/A'); ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <span class="status-badge status-<?php echo $employee['employment_status']; ?>">
                                <i class="fas fa-circle me-1"></i>
                                <?php echo ucwords(str_replace('_', ' ', $employee['employment_status'] ?? 'unknown')); ?>
                            </span>
                            <br>
                            <small class="opacity-75 mt-2 d-block">
                                <i class="fas fa-calendar-alt me-1"></i>
                                Employed since <?php echo date('M d, Y', strtotime($employee['hire_date'])); ?>
                                <br>
                                <i class="fas fa-clock me-1"></i>
                                <?php echo $tenure_text; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">

            <!-- Personal Information -->
            <div class="col-lg-6">
                <div class="info-card">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-user-circle me-2 text-primary"></i>
                        Personal Information
                    </h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="info-label"><i class="fas fa-envelope me-2"></i>Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['email'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-phone me-2"></i>Phone 1</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['phone1'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-phone-alt me-2"></i>Phone 2</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['phone2'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-venus-mars me-2"></i>Gender</div>
                            <div class="info-value"><?php echo ucfirst(htmlspecialchars($employee['gender'] ?? 'N/A')); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-birthday-cake me-2"></i>Date of Birth</div>
                            <div class="info-value">
                                <?php if ($employee['date_of_birth']): ?>
                                    <?php echo date('M d, Y', strtotime($employee['date_of_birth'])); ?>
                                    (<?php echo $today->diff(new DateTime($employee['date_of_birth']))->y; ?> years old)
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="info-label"><i class="fas fa-id-card me-2"></i>National ID</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['national_id'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Employment Information -->
            <div class="col-lg-6">
                <div class="info-card">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-briefcase me-2 text-success"></i>
                        Employment Information
                    </h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="info-label"><i class="fas fa-building me-2"></i>Department</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['department_name'] ?? 'Not Assigned'); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="info-label"><i class="fas fa-user-tag me-2"></i>Position</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['position_title'] ?? 'Not Assigned'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-calendar-check me-2"></i>Hire Date</div>
                            <div class="info-value"><?php echo date('M d, Y', strtotime($employee['hire_date'])); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-calendar-alt me-2"></i>Confirmation Date</div>
                            <div class="info-value">
                                <?php echo $employee['confirmation_date'] ? date('M d, Y', strtotime($employee['confirmation_date'])) : 'N/A'; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-file-contract me-2"></i>Employment Type</div>
                            <div class="info-value">
                                <span class="badge bg-<?php 
                                    echo $employee['employment_type'] === 'permanent' ? 'success' : 
                                         ($employee['employment_type'] === 'contract' ? 'warning' : 'info');
                                ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $employee['employment_type'] ?? 'N/A')); ?>
                                </span>
                            </div>
                        </div>
                        <?php if ($employee['contract_end_date']): ?>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-stopwatch me-2"></i>Contract Ends</div>
                            <div class="info-value"><?php echo date('M d, Y', strtotime($employee['contract_end_date'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Salary Information -->
            <div class="col-12">
                <div class="salary-card">
                    <h3 class="mb-4">
                        <i class="fas fa-money-bill-wave me-2"></i>
                        Salary Information
                    </h3>
                    <div class="salary-amount">
                        TSH <?php echo number_format(($employee['basic_salary'] ?? 0) + ($employee['allowances'] ?? 0), 0); ?>
                    </div>
                    <small class="opacity-75">Monthly Total Salary</small>
                    
                    <div class="salary-breakdown">
                        <div class="salary-item">
                            <div class="h6 mb-1">Basic Salary</div>
                            <div class="fw-bold">TSH <?php echo number_format($employee['basic_salary'] ?? 0, 0); ?></div>
                        </div>
                        <div class="salary-item">
                            <div class="h6 mb-1">Allowances</div>
                            <div class="fw-bold">TSH <?php echo number_format($employee['allowances'] ?? 0, 0); ?></div>
                        </div>
                        <div class="salary-item">
                            <div class="h6 mb-1">Total</div>
                            <div class="fw-bold text-warning">TSH <?php echo number_format(($employee['basic_salary'] ?? 0) + ($employee['allowances'] ?? 0), 0); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Address Information -->
            <div class="col-lg-6">
                <div class="info-card">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-map-marker-alt me-2 text-info"></i>
                        Address Information
                    </h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="info-label"><i class="fas fa-home me-2"></i>Full Address</div>
                            <div class="info-value">
                                <?php 
                                $address_parts = array_filter([
                                    $employee['region'],
                                    $employee['district'],
                                    $employee['ward'],
                                    $employee['village'],
                                    $employee['street_address']
                                ]);
                                echo !empty($address_parts) ? implode(', ', $address_parts) : 'N/A';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bank Information -->
            <div class="col-lg-6">
                <div class="info-card">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-university me-2 text-warning"></i>
                        Bank Information
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-building-columns me-2"></i>Bank Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['bank_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-credit-card me-2"></i>Account Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['account_number'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="info-label"><i class="fas fa-map-pin me-2"></i>Branch</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['bank_branch'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Emergency Contact -->
            <div class="col-lg-6">
                <div class="info-card">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-phone-square me-2 text-danger"></i>
                        Emergency Contact
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-user me-2"></i>Contact Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['emergency_contact_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label"><i class="fas fa-mobile-alt me-2"></i>Phone</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['emergency_contact_phone'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="info-label"><i class="fas fa-link me-2"></i>Relationship</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['emergency_contact_relationship'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Payroll -->
            <?php if (!empty($recent_payrolls)): ?>
            <div class="col-12">
                <div class="info-card">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-file-invoice-dollar me-2 text-success"></i>
                        Recent Payroll (Last 3 Months)
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover payroll-table mb-0">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Basic Salary</th>
                                    <th>Allowances</th>
                                    <th>Total</th>
                                    <th>Payment Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_payrolls as $payroll): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('M Y', strtotime($payroll['payroll_month'])); ?></strong>
                                    </td>
                                    <td><strong>TSH <?php echo number_format($payroll['basic_salary'], 0); ?></strong></td>
                                    <td>TSH <?php echo number_format($payroll['allowances'], 0); ?></td>
                                    <td class="fw-bold text-success">
                                        TSH <?php echo number_format($payroll['total_salary'], 0); ?>
                                    </td>
                                    <td>
                                        <?php echo $payroll['payment_date'] ? date('M d, Y', strtotime($payroll['payment_date'])) : 'Pending'; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $payroll['status'] === 'paid' ? 'success' : 
                                                 ($payroll['status'] === 'pending' ? 'warning' : 'secondary');
                                        ?>">
                                            <?php echo ucfirst($payroll['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- System Information -->
            <div class="col-12">
                <div class="info-card">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-cogs me-2 text-secondary"></i>
                        System Information
                    </h5>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="info-label">Username</div>
                            <div class="info-value"><?php echo htmlspecialchars($employee['username'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Account Created</div>
                            <div class="info-value"><?php echo date('M d, Y H:i', strtotime($employee['user_created_at'] ?? $employee['created_at'])); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Last Updated</div>
                            <div class="info-value"><?php echo date('M d, Y H:i', strtotime($employee['updated_at'] ?? $employee['created_at'])); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Record ID</div>
                            <div class="info-value">#<?php echo $employee['employee_id']; ?></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Action Buttons -->
        <div class="row mt-5">
            <div class="col-12 text-center">
                <div class="btn-group action-buttons" role="group">
                    <a href="edit-employee.php?id=<?php echo $employee_id; ?>" class="btn btn-primary btn-lg px-4 me-2">
                        <i class="fas fa-edit me-2"></i> Edit Employee
                    </a>
                    <a href="print-employee.php?id=<?php echo $employee_id; ?>" class="btn btn-outline-primary btn-lg px-4 me-2" target="_blank">
                        <i class="fas fa-print me-2"></i> Print Profile
                    </a>
                    <a href="generate-payslip.php?id=<?php echo $employee_id; ?>" class="btn btn-outline-success btn-lg px-4 me-2">
                        <i class="fas fa-file-invoice me-2"></i> Generate Payslip
                    </a>
                    <a href="employees.php" class="btn btn-outline-secondary btn-lg px-4">
                        <i class="fas fa-arrow-left me-2"></i> Back to Employees
                    </a>
                </div>
            </div>
        </div>

    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add print functionality
    const printBtn = document.querySelectorAll('[href*="print-employee"]');
    printBtn.forEach(btn => {
        btn.addEventListener('click', function(e) {
            // Optional: Add loading state
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Preparing Print...';
        });
    });

    // Smooth scroll to sections
    const infoCards = document.querySelectorAll('.info-card');
    infoCards.forEach(card => {
        card.addEventListener('click', function() {
            // Add subtle animation
            this.style.transform = 'scale(1.02)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 150);
        });
    });

    // Copy employee number to clipboard
    const employeeId = document.querySelector('.employee-id');
    if (employeeId) {
        employeeId.addEventListener('click', function() {
            navigator.clipboard.writeText('<?php echo addslashes($employee['employee_number'] ?? ''); ?>');
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-check me-2"></i>Copied!';
            this.style.background = 'rgba(40, 167, 69, 0.2)';
            setTimeout(() => {
                this.innerHTML = originalText;
                this.style.background = 'rgba(255,255,255,0.2)';
            }, 2000);
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>