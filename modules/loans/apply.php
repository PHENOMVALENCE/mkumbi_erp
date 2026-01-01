<?php
/**
 * Loan Application Form
 * Mkumbi Investments ERP System
 */

define('APP_ACCESS', true);
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

$employee = getOrCreateEmployeeForSuperAdmin($conn, $user_id, $company_id);
if (!$employee) {
    $_SESSION['error_message'] = "Employee record not found.";
    header('Location: index.php');
    exit;
}

// Check for existing pending/active loans
$sql = "SELECT el.*, lt.type_name as loan_type_name 
        FROM employee_loans el 
        JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
        WHERE el.employee_id = ? AND el.status IN ('pending', 'approved', 'disbursed', 'active')";
$stmt = $conn->prepare($sql);
$stmt->execute([$employee['employee_id']]);
$existing_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get loan types (matching exact schema - type_name, max_term_months, no min_amount)
$loan_types = [];
try {
    $sql = "SELECT *, type_name as loan_type_name, max_term_months
            FROM loan_types WHERE company_id = ? AND is_active = 1 
            ORDER BY type_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$company_id]);
    $loan_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($loan_types)) {
        $_SESSION['error_message'] = "No loan types are available. Please contact HR to configure loan types.";
    }
} catch (PDOException $e) {
    error_log("Error fetching loan types: " . $e->getMessage());
    $loan_types = [];
    $_SESSION['error_message'] = "Error loading loan types. Please try again later.";
}

// Get potential guarantors (other employees) - join with users table for full_name
$sql = "SELECT e.employee_id, u.full_name, e.employee_number 
        FROM employees e
        JOIN users u ON e.user_id = u.user_id
        WHERE e.company_id = ? AND e.employee_id != ? AND e.is_active = 1 AND e.employment_status = 'active'
        ORDER BY u.full_name";
$stmt = $conn->prepare($sql);
$stmt->execute([$company_id, $employee['employee_id']]);
$guarantors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields first
    if (empty($_POST['loan_type_id'])) {
        $errors[] = "Please select a loan type.";
    }
    if (empty($_POST['loan_amount']) || (float)$_POST['loan_amount'] <= 0) {
        $errors[] = "Please enter a valid loan amount greater than zero.";
    }
    if (empty($_POST['loan_term_months']) || (int)$_POST['loan_term_months'] < 1) {
        $errors[] = "Please enter a valid repayment period (at least 1 month).";
    }
    if (empty($_POST['purpose'])) {
        $errors[] = "Please specify the purpose of the loan.";
    }
    
    if (empty($errors)) {
        $loan_type_id = (int)$_POST['loan_type_id'];
        $loan_amount = (float)$_POST['loan_amount'];
        $loan_term_months = (int)$_POST['loan_term_months'];
        $purpose = sanitize($_POST['purpose']);
        $guarantor1_id = !empty($_POST['guarantor1_id']) ? (int)$_POST['guarantor1_id'] : null;
        $guarantor2_id = !empty($_POST['guarantor2_id']) ? (int)$_POST['guarantor2_id'] : null;
        
        // Get loan type details (matching exact schema)
        $sql = "SELECT * FROM loan_types WHERE loan_type_id = ? AND company_id = ? AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$loan_type_id, $company_id]);
        $loan_type = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Validation (schema has no min_amount, so minimum is 0)
        if (!$loan_type) {
            $errors[] = "Invalid loan type selected or loan type is not active.";
        } else {
            if ($loan_amount <= 0) {
                $errors[] = "Loan amount must be greater than zero.";
            }
            if ($loan_type['max_amount'] && $loan_amount > $loan_type['max_amount']) {
                $errors[] = "Maximum loan amount is " . formatCurrency($loan_type['max_amount']);
            }
            if ($loan_type['max_term_months'] && ($loan_term_months < 1 || $loan_term_months > $loan_type['max_term_months'])) {
                $errors[] = "Loan term must be between 1 and " . $loan_type['max_term_months'] . " months.";
            }
            if ($loan_type['requires_guarantor'] && !$guarantor1_id) {
                $errors[] = "This loan type requires at least one guarantor.";
            }
        }
        
        // Check salary-based limit (max 3x basic salary) - handle NULL basic_salary
        $basic_salary = (float)($employee['basic_salary'] ?? 0);
        if ($basic_salary > 0) {
            $max_based_on_salary = $basic_salary * 3;
            if ($loan_amount > $max_based_on_salary) {
                $errors[] = "Loan amount cannot exceed 3x your basic salary (" . formatCurrency($max_based_on_salary) . ")";
            }
        }
        
        // Guarantors cannot be the same
        if ($guarantor1_id && $guarantor2_id && $guarantor1_id === $guarantor2_id) {
            $errors[] = "Please select different guarantors.";
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Generate loan number - improved to handle cases with no existing loans
            $year = date('Y');
            $pattern = 'LN' . $year . '-%';
            
            // Get the highest number for this year
            $max_sql = "SELECT loan_number FROM employee_loans 
                       WHERE company_id = ? AND loan_number LIKE ?
                       ORDER BY loan_number DESC LIMIT 1";
            $max_stmt = $conn->prepare($max_sql);
            $max_stmt->execute([$company_id, $pattern]);
            $last_loan = $max_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($last_loan) {
                // Extract number from last loan (format: LN2025-00001)
                preg_match('/' . $year . '-(\d+)$/', $last_loan['loan_number'], $matches);
                $next_num = isset($matches[1]) ? (int)$matches[1] + 1 : 1;
            } else {
                $next_num = 1;
            }
            
            $loan_number = 'LN' . $year . '-' . str_pad($next_num, 5, '0', STR_PAD_LEFT);
            
            // Calculate monthly deduction and installment (matching schema)
            $monthly_interest = ($loan_amount * $loan_type['interest_rate'] / 100) / 12;
            $monthly_principal = $loan_amount / $loan_term_months;
            $monthly_deduction = $monthly_principal + $monthly_interest;
            $monthly_installment = $monthly_deduction; // Same value for both
            $total_repayable = $monthly_deduction * $loan_term_months;
            $remaining_balance = $total_repayable;
            
            // Insert loan application (matching exact schema)
            // Handle NULL values for guarantors properly
            $sql = "INSERT INTO employee_loans (
                        company_id, employee_id, loan_type_id, loan_number, loan_amount,
                        interest_rate, repayment_period_months, monthly_installment, monthly_deduction,
                        remaining_balance, principal_outstanding, interest_outstanding, total_outstanding,
                        purpose, guarantor1_id, guarantor2_id, status, application_date, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $company_id, 
                $employee['employee_id'], 
                $loan_type_id, 
                $loan_number, 
                $loan_amount,
                $loan_type['interest_rate'], 
                $loan_term_months, 
                $monthly_installment, 
                $monthly_deduction,
                $remaining_balance, 
                $loan_amount, 
                ($total_repayable - $loan_amount), 
                $total_repayable,
                $purpose, 
                $guarantor1_id ?: null, 
                $guarantor2_id ?: null, 
                date('Y-m-d'), 
                $user_id
            ]);
            $loan_id = $conn->lastInsertId();
            
            // Generate repayment schedule
            $due_date = date('Y-m-d', strtotime('+1 month'));
            $balance = $loan_amount;
            for ($i = 1; $i <= $loan_term_months; $i++) {
                $principal = $monthly_principal;
                $interest = $monthly_interest;
                $total = $monthly_deduction;
                $balance -= $principal;
                
                $sql = "INSERT INTO loan_repayment_schedule (
                            loan_id, installment_number, due_date, principal_amount, interest_amount,
                            total_amount, balance_outstanding
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $loan_id, $i, $due_date, $principal, $interest, $total, max(0, $balance)
                ]);
                
                $due_date = date('Y-m-d', strtotime($due_date . ' +1 month'));
            }
            
            // Audit log
            logAudit($conn, $company_id, $user_id, 'create', 'loans', 'employee_loans', $loan_id, null, [
                'loan_number' => $loan_number,
                'amount' => $loan_amount,
                'term' => $loan_term_months
            ]);
            
            $conn->commit();
            
            $_SESSION['success_message'] = "Loan application submitted successfully! Reference: " . $loan_number;
            header('Location: my-loans.php');
            exit;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Loan application error: " . $e->getMessage());
            $error_msg = "An error occurred. Please try again.";
            // Show more detailed error in development
            if (ini_get('display_errors')) {
                $error_msg .= " Error: " . $e->getMessage();
            }
            $errors[] = $error_msg;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Loan application error: " . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }
}

$page_title = "Apply for Loan";
require_once '../../includes/header.php';
?>

<style>
    .form-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 30px;
    }
    .info-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }
    .loan-type-card {
        border: 2px solid #e9ecef;
        border-radius: 10px;
        padding: 15px;
        cursor: pointer;
        transition: all 0.2s;
        margin-bottom: 10px;
    }
    .loan-type-card:hover, .loan-type-card.selected {
        border-color: #667eea;
        background: #f8f9fe;
    }
    .loan-type-card input { display: none; }
    .schedule-preview {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 20px;
        margin-top: 20px;
    }
    .existing-loan-warning {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
    }
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-plus-circle text-primary me-2"></i>
                    Apply for Loan
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Submit a new loan application
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Loans
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
            
            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Please fix the following errors:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($existing_loans)): ?>
            <div class="existing-loan-warning">
                <h6><i class="fas fa-exclamation-triangle me-2"></i>You have existing loans:</h6>
                <ul class="mb-0">
                    <?php foreach ($existing_loans as $loan): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($loan['loan_type_name']); ?></strong> - 
                        <?php echo formatCurrency($loan['loan_amount']); ?> 
                        (<?php echo $loan['status']; ?>)
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <div class="form-card">
                        <form method="POST" id="loanForm">
                            <!-- Loan Type Selection -->
                            <h5 class="mb-3"><i class="fas fa-tags me-2"></i>Select Loan Type</h5>
                            <?php if (empty($loan_types)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No loan types are currently available. Please contact HR to configure loan types.
                            </div>
                            <?php else: ?>
                            <div class="row mb-4">
                                <?php foreach ($loan_types as $lt): ?>
                                <div class="col-md-6">
                                    <label class="loan-type-card d-block" 
                                           data-max="<?php echo $lt['max_amount'] ?? 999999999; ?>"
                                           data-min="0" 
                                           data-rate="<?php echo $lt['interest_rate']; ?>"
                                           data-term="<?php echo $lt['max_term_months'] ?? 36; ?>" 
                                           data-guarantor="<?php echo $lt['requires_guarantor']; ?>">
                                        <input type="radio" name="loan_type_id" value="<?php echo $lt['loan_type_id']; ?>" required>
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong><?php echo htmlspecialchars($lt['loan_type_name']); ?></strong>
                                                <small class="d-block text-muted"><?php echo $lt['interest_rate']; ?>% interest</small>
                                            </div>
                                            <span class="badge bg-primary"><?php echo $lt['max_amount'] ? formatCurrency($lt['max_amount']) : 'Unlimited'; ?></span>
                                        </div>
                                        <small class="text-muted">Up to <?php echo $lt['max_term_months'] ?? 36; ?> months</small>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <hr class="my-4">

                            <!-- Loan Details -->
                            <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Loan Details</h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Loan Amount (TZS) <span class="text-danger">*</span></label>
                                    <input type="number" name="loan_amount" id="loanAmount" class="form-control" 
                                           min="0" step="1000" required>
                                    <small id="amountHelp" class="text-muted">Enter amount between min and max limits</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Repayment Period (Months) <span class="text-danger">*</span></label>
                                    <input type="number" name="loan_term_months" id="loanTerm" class="form-control" 
                                           min="1" max="36" required>
                                    <small id="termHelp" class="text-muted">Max: 36 months</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Purpose of Loan <span class="text-danger">*</span></label>
                                <textarea name="purpose" class="form-control" rows="3" required
                                          placeholder="Briefly describe why you need this loan..."></textarea>
                            </div>

                            <hr class="my-4">

                            <!-- Guarantors -->
                            <h5 class="mb-3" id="guarantorSection">
                                <i class="fas fa-users me-2"></i>Guarantors 
                                <small class="text-muted">(if required)</small>
                            </h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guarantor 1</label>
                                    <select name="guarantor1_id" id="guarantor1" class="form-select">
                                        <option value="">-- Select Guarantor --</option>
                                        <?php foreach ($guarantors as $g): ?>
                                        <option value="<?php echo $g['employee_id']; ?>">
                                            <?php echo htmlspecialchars($g['full_name']); ?> 
                                            (<?php echo $g['employee_number']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guarantor 2 <small class="text-muted">(optional)</small></label>
                                    <select name="guarantor2_id" id="guarantor2" class="form-select">
                                        <option value="">-- Select Guarantor --</option>
                                        <?php foreach ($guarantors as $g): ?>
                                        <option value="<?php echo $g['employee_id']; ?>">
                                            <?php echo htmlspecialchars($g['full_name']); ?> 
                                            (<?php echo $g['employee_number']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Schedule Preview -->
                            <div class="schedule-preview" id="schedulePreview" style="display:none;">
                                <h6><i class="fas fa-calculator me-2"></i>Loan Summary</h6>
                                <div class="row">
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Monthly Payment</small>
                                        <strong id="previewMonthly">TZS 0</strong>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Total Interest</small>
                                        <strong id="previewInterest">TZS 0</strong>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Total Repayable</small>
                                        <strong id="previewTotal">TZS 0</strong>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">First Payment</small>
                                        <strong id="previewFirstPayment">-</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary btn-lg" <?php echo empty($loan_types) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-paper-plane me-2"></i>Submit Application
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary btn-lg ms-2">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Employee Info -->
                    <div class="info-card">
                        <h6><i class="fas fa-user me-2"></i>Your Information</h6>
                        <hr style="border-color: rgba(255,255,255,0.3);">
                        <p class="mb-2">
                            <strong><?php echo htmlspecialchars($employee['full_name'] ?? 'Employee'); ?></strong>
                        </p>
                        <p class="mb-2">
                            <small>Employee #: <?php echo htmlspecialchars($employee['employee_number']); ?></small>
                        </p>
                        <p class="mb-2">
                            <small>Basic Salary: <?php echo formatCurrency($employee['basic_salary'] ?? 0); ?></small>
                        </p>
                        <p class="mb-0">
                            <small>Max Eligible: <?php 
                                $basic_salary = (float)($employee['basic_salary'] ?? 0);
                                echo $basic_salary > 0 ? formatCurrency($basic_salary * 3) : 'Not set';
                            ?></small>
                        </p>
                    </div>

                    <!-- Guidelines -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Loan Guidelines</h6>
                        </div>
                        <div class="card-body">
                            <ul class="small mb-0">
                                <li class="mb-2">Maximum loan amount is 3x your basic salary</li>
                                <li class="mb-2">Loan repayments are deducted from salary</li>
                                <li class="mb-2">Some loan types require guarantors</li>
                                <li class="mb-2">Approval takes 2-5 business days</li>
                                <li>Early repayment is allowed without penalty</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const loanTypeCards = document.querySelectorAll('.loan-type-card');
    const loanAmount = document.getElementById('loanAmount');
    const loanTerm = document.getElementById('loanTerm');
    const schedulePreview = document.getElementById('schedulePreview');
    const guarantor1 = document.getElementById('guarantor1');
    
    let selectedType = null;
    
    // Loan type selection
    loanTypeCards.forEach(card => {
        card.addEventListener('click', function() {
            loanTypeCards.forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
            this.querySelector('input').checked = true;
            
            selectedType = {
                max: parseFloat(this.dataset.max),
                min: parseFloat(this.dataset.min),
                rate: parseFloat(this.dataset.rate),
                term: parseInt(this.dataset.term),
                guarantor: this.dataset.guarantor === '1'
            };
            
            loanAmount.max = selectedType.max;
            loanAmount.min = selectedType.min;
            loanTerm.max = selectedType.term;
            
            const maxDisplay = selectedType.max === 999999999 ? 'Unlimited' : 'TZS ' + selectedType.max.toLocaleString();
            document.getElementById('amountHelp').textContent = 
                `Min: TZS ${selectedType.min.toLocaleString()} | Max: ${maxDisplay}`;
            document.getElementById('termHelp').textContent = `Max: ${selectedType.term} months`;
            
            // Toggle guarantor requirement
            if (selectedType.guarantor) {
                guarantor1.required = true;
                document.getElementById('guarantorSection').innerHTML = 
                    '<i class="fas fa-users me-2"></i>Guarantors <span class="text-danger">*</span>';
            } else {
                guarantor1.required = false;
                document.getElementById('guarantorSection').innerHTML = 
                    '<i class="fas fa-users me-2"></i>Guarantors <small class="text-muted">(optional)</small>';
            }
            
            calculatePreview();
        });
    });
    
    // Calculate preview on input change
    loanAmount.addEventListener('input', calculatePreview);
    loanTerm.addEventListener('input', calculatePreview);
    
    function calculatePreview() {
        if (!selectedType || !loanAmount.value || !loanTerm.value) {
            schedulePreview.style.display = 'none';
            return;
        }
        
        const amount = parseFloat(loanAmount.value);
        const term = parseInt(loanTerm.value);
        const rate = selectedType.rate / 100 / 12; // Monthly rate
        
        // Calculate monthly payment using amortization formula
        let monthly;
        if (rate === 0) {
            monthly = amount / term;
        } else {
            monthly = (amount * rate * Math.pow(1 + rate, term)) / (Math.pow(1 + rate, term) - 1);
        }
        
        const totalRepayable = monthly * term;
        const totalInterest = totalRepayable - amount;
        
        // First payment date (next month)
        const firstPayment = new Date();
        firstPayment.setMonth(firstPayment.getMonth() + 1);
        
        document.getElementById('previewMonthly').textContent = 'TZS ' + Math.round(monthly).toLocaleString();
        document.getElementById('previewInterest').textContent = 'TZS ' + Math.round(totalInterest).toLocaleString();
        document.getElementById('previewTotal').textContent = 'TZS ' + Math.round(totalRepayable).toLocaleString();
        document.getElementById('previewFirstPayment').textContent = firstPayment.toLocaleDateString('en-US', {month: 'short', year: 'numeric'});
        
        schedulePreview.style.display = 'block';
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
