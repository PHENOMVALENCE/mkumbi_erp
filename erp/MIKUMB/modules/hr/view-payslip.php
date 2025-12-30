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

// Get payroll detail ID
$payroll_detail_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($payroll_detail_id <= 0) {
    $_SESSION['error_message'] = 'Invalid payslip ID';
    header('Location: payroll.php');
    exit;
}

// Fetch payslip details
try {
    $query = "
        SELECT 
            pd.*,
            p.payroll_month,
            p.payroll_year,
            p.payment_date as payroll_payment_date,
            p.status as payroll_status,
            e.employee_number,
            e.hire_date,
            e.bank_name,
            e.account_number,
            u.full_name,
            u.email,
            u.phone1,
            d.department_name,
            pos.position_title,
            c.company_name,
            c.tax_identification_number as company_tin,
            c.phone as company_phone,
            c.email as company_email,
            c.physical_address as company_address,
            c.logo_path
        FROM payroll_details pd
        INNER JOIN payroll p ON pd.payroll_id = p.payroll_id
        INNER JOIN employees e ON pd.employee_id = e.employee_id
        INNER JOIN users u ON e.user_id = u.user_id
        INNER JOIN companies c ON p.company_id = c.company_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions pos ON e.position_id = pos.position_id
        WHERE pd.payroll_detail_id = ? 
        AND p.company_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$payroll_detail_id, $company_id]);
    $payslip = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payslip) {
        $_SESSION['error_message'] = 'Payslip not found';
        header('Location: payroll.php');
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching payslip: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error loading payslip';
    header('Location: payroll.php');
    exit;
}

$period_name = date('F Y', mktime(0, 0, 0, $payslip['payroll_month'], 1, $payslip['payroll_year']));

$page_title = 'View Payslip';
require_once '../../includes/header.php';
?>

<style>
.payslip-container {
    max-width: 900px;
    margin: 0 auto;
}

.payslip-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    overflow: hidden;
}

.payslip-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    text-align: center;
}

.payslip-header h2 {
    margin: 0;
    font-size: 1.75rem;
    font-weight: 700;
}

.company-info {
    background: #f8f9fa;
    padding: 1.5rem;
    border-bottom: 2px solid #e9ecef;
}

.company-logo {
    max-width: 150px;
    max-height: 80px;
}

.employee-section {
    padding: 2rem;
    border-bottom: 2px solid #e9ecef;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f8f9fa;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
}

.info-value {
    color: #212529;
    font-weight: 500;
}

.earnings-section, .deductions-section {
    padding: 2rem;
}

.section-title {
    color: #007bff;
    font-weight: 700;
    font-size: 1.1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #007bff;
}

.amount-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    background: #f8f9fa;
    margin-bottom: 0.5rem;
    border-radius: 6px;
}

.amount-label {
    font-weight: 500;
    color: #495057;
}

.amount-value {
    font-weight: 700;
    font-family: 'SF Mono', monospace;
}

.amount-value.earning {
    color: #28a745;
}

.amount-value.deduction {
    color: #dc3545;
}

.total-section {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: white;
    padding: 2rem;
}

.total-row {
    display: flex;
    justify-content: space-between;
    padding: 1rem 0;
    font-size: 1.25rem;
    font-weight: 700;
}

.payment-info {
    background: #fff3cd;
    padding: 1.5rem;
    border-left: 4px solid #ffc107;
}

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 25px;
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
}

.status-badge.paid {
    background: #d4edda;
    color: #155724;
}

.status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.print-actions {
    padding: 1.5rem;
    background: #f8f9fa;
    text-align: center;
}

@media print {
    .content-header, .print-actions, .btn {
        display: none !important;
    }
    
    .payslip-card {
        box-shadow: none;
    }
    
    body {
        background: white;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice text-primary me-2"></i>
                    Payslip Details
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    View employee payslip for <?php echo $period_name; ?>
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="payroll.php?month=<?php echo $payslip['payroll_month']; ?>&year=<?php echo $payslip['payroll_year']; ?>" 
                       class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Payroll
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        <div class="payslip-container">
            
            <div class="payslip-card">
                
                <!-- Payslip Header -->
                <div class="payslip-header">
                    <h2>PAYSLIP</h2>
                    <p class="mb-0 mt-2">
                        <i class="fas fa-calendar-alt me-2"></i>
                        <?php echo $period_name; ?>
                    </p>
                </div>

                <!-- Company Information -->
                <div class="company-info">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="mb-2 fw-bold"><?php echo htmlspecialchars($payslip['company_name']); ?></h4>
                            <?php if (!empty($payslip['company_address'])): ?>
                            <p class="mb-1 text-muted"><?php echo htmlspecialchars($payslip['company_address']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($payslip['company_phone'])): ?>
                            <p class="mb-1 text-muted">
                                <i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($payslip['company_phone']); ?>
                            </p>
                            <?php endif; ?>
                            <?php if (!empty($payslip['company_email'])): ?>
                            <p class="mb-1 text-muted">
                                <i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($payslip['company_email']); ?>
                            </p>
                            <?php endif; ?>
                            <?php if (!empty($payslip['company_tin'])): ?>
                            <p class="mb-0 text-muted">
                                <i class="fas fa-id-card me-1"></i> TIN: <?php echo htmlspecialchars($payslip['company_tin']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-end">
                            <?php if (!empty($payslip['logo_path'])): ?>
                            <img src="../../<?php echo htmlspecialchars($payslip['logo_path']); ?>" 
                                 alt="Company Logo" class="company-logo">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Employee Information -->
                <div class="employee-section">
                    <h5 class="mb-3 fw-bold text-primary">
                        <i class="fas fa-user me-2"></i>Employee Information
                    </h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Employee Name:</span>
                                <span class="info-value"><?php echo htmlspecialchars($payslip['full_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Employee Number:</span>
                                <span class="info-value"><?php echo htmlspecialchars($payslip['employee_number']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Department:</span>
                                <span class="info-value"><?php echo htmlspecialchars($payslip['department_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Position:</span>
                                <span class="info-value"><?php echo htmlspecialchars($payslip['position_title'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Email:</span>
                                <span class="info-value"><?php echo htmlspecialchars($payslip['email']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone:</span>
                                <span class="info-value"><?php echo htmlspecialchars($payslip['phone1'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Hire Date:</span>
                                <span class="info-value"><?php echo date('M j, Y', strtotime($payslip['hire_date'])); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Payment Status:</span>
                                <span class="info-value">
                                    <span class="status-badge <?php echo strtolower($payslip['payment_status']); ?>">
                                        <?php echo ucfirst($payslip['payment_status']); ?>
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Earnings Section -->
                <div class="earnings-section">
                    <h5 class="section-title">
                        <i class="fas fa-plus-circle me-2"></i>Earnings
                    </h5>
                    
                    <div class="amount-row">
                        <span class="amount-label">Basic Salary</span>
                        <span class="amount-value earning">TSH <?php echo number_format($payslip['basic_salary'], 2); ?></span>
                    </div>
                    
                    <?php if ($payslip['allowances'] > 0): ?>
                    <div class="amount-row">
                        <span class="amount-label">Allowances</span>
                        <span class="amount-value earning">TSH <?php echo number_format($payslip['allowances'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($payslip['overtime_pay'] > 0): ?>
                    <div class="amount-row">
                        <span class="amount-label">Overtime Pay</span>
                        <span class="amount-value earning">TSH <?php echo number_format($payslip['overtime_pay'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($payslip['bonus'] > 0): ?>
                    <div class="amount-row">
                        <span class="amount-label">Bonus</span>
                        <span class="amount-value earning">TSH <?php echo number_format($payslip['bonus'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="amount-row" style="background: #d4edda; margin-top: 1rem;">
                        <span class="amount-label fw-bold">GROSS SALARY</span>
                        <span class="amount-value earning fw-bold">TSH <?php echo number_format($payslip['gross_salary'], 2); ?></span>
                    </div>
                </div>

                <!-- Deductions Section -->
                <div class="deductions-section">
                    <h5 class="section-title" style="color: #dc3545; border-bottom-color: #dc3545;">
                        <i class="fas fa-minus-circle me-2"></i>Deductions
                    </h5>
                    
                    <?php if ($payslip['tax_amount'] > 0): ?>
                    <div class="amount-row">
                        <span class="amount-label">Income Tax (PAYE)</span>
                        <span class="amount-value deduction">TSH <?php echo number_format($payslip['tax_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($payslip['nssf_amount'] > 0): ?>
                    <div class="amount-row">
                        <span class="amount-label">NSSF Contribution</span>
                        <span class="amount-value deduction">TSH <?php echo number_format($payslip['nssf_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($payslip['nhif_amount'] > 0): ?>
                    <div class="amount-row">
                        <span class="amount-label">NHIF Contribution</span>
                        <span class="amount-value deduction">TSH <?php echo number_format($payslip['nhif_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($payslip['loan_deduction'] > 0): ?>
                    <div class="amount-row">
                        <span class="amount-label">Loan Deduction</span>
                        <span class="amount-value deduction">TSH <?php echo number_format($payslip['loan_deduction'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($payslip['other_deductions'] > 0): ?>
                    <div class="amount-row">
                        <span class="amount-label">Other Deductions</span>
                        <span class="amount-value deduction">TSH <?php echo number_format($payslip['other_deductions'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="amount-row" style="background: #f8d7da; margin-top: 1rem;">
                        <span class="amount-label fw-bold">TOTAL DEDUCTIONS</span>
                        <span class="amount-value deduction fw-bold">TSH <?php echo number_format($payslip['total_deductions'], 2); ?></span>
                    </div>
                </div>

                <!-- Net Salary -->
                <div class="total-section">
                    <div class="total-row">
                        <span>NET SALARY</span>
                        <span>TSH <?php echo number_format($payslip['net_salary'], 2); ?></span>
                    </div>
                    <div class="text-center mt-2" style="opacity: 0.9;">
                        <small>Amount payable to employee</small>
                    </div>
                </div>

                <!-- Payment Information -->
                <?php if (!empty($payslip['bank_name']) || !empty($payslip['account_number'])): ?>
                <div class="payment-info">
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-university me-2"></i>Payment Information
                    </h6>
                    <?php if (!empty($payslip['bank_name'])): ?>
                    <p class="mb-1">
                        <strong>Bank:</strong> <?php echo htmlspecialchars($payslip['bank_name']); ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($payslip['account_number'])): ?>
                    <p class="mb-1">
                        <strong>Account Number:</strong> <?php echo htmlspecialchars($payslip['account_number']); ?>
                    </p>
                    <?php endif; ?>
                    <?php if ($payslip['payment_date']): ?>
                    <p class="mb-0">
                        <strong>Payment Date:</strong> <?php echo date('F j, Y', strtotime($payslip['payment_date'])); ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($payslip['payment_reference'])): ?>
                    <p class="mb-0">
                        <strong>Reference:</strong> <?php echo htmlspecialchars($payslip['payment_reference']); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Print Actions -->
                <div class="print-actions">
                    <a href="print-payslip.php?id=<?php echo $payroll_detail_id; ?>" 
                       class="btn btn-primary btn-lg" target="_blank">
                        <i class="fas fa-print me-2"></i> Print Payslip
                    </a>
                    <button onclick="window.print()" class="btn btn-success btn-lg ms-2">
                        <i class="fas fa-file-pdf me-2"></i> Save as PDF
                    </button>
                    <a href="email-payslip.php?id=<?php echo $payroll_detail_id; ?>" 
                       class="btn btn-info btn-lg ms-2">
                        <i class="fas fa-envelope me-2"></i> Email Payslip
                    </a>
                </div>

            </div>

            <!-- Footer Note -->
            <div class="mt-3 text-center text-muted">
                <small>
                    <i class="fas fa-info-circle me-1"></i>
                    This is a computer-generated payslip and does not require a signature. 
                    For any discrepancies, please contact the HR department.
                </small>
            </div>

        </div>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>