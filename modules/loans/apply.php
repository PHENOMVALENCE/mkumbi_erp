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

$employee = getEmployeeByUserId($conn, $user_id, $company_id);
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

// Get loan types
$sql = "SELECT *, COALESCE(type_name, loan_type_name) as loan_type_name, COALESCE(max_repayment_months, max_term_months) as max_term_months FROM loan_types WHERE company_id = ? AND is_active = 1 ORDER BY COALESCE(type_name, loan_type_name)";
$stmt = $conn->prepare($sql);
$stmt->execute([$company_id]);
$loan_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get potential guarantors (other employees)
$sql = "SELECT employee_id, first_name, last_name, employee_number 
        FROM employees 
        WHERE company_id = ? AND employee_id != ? AND is_active = 1
        ORDER BY first_name, last_name";
$stmt = $conn->prepare($sql);
$stmt->execute([$company_id, $employee['employee_id']]);
$guarantors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loan_type_id = (int)$_POST['loan_type_id'];
    $loan_amount = (float)$_POST['loan_amount'];
    $loan_term_months = (int)$_POST['loan_term_months'];
    $purpose = sanitize($_POST['purpose']);
    $guarantor1_id = !empty($_POST['guarantor1_id']) ? (int)$_POST['guarantor1_id'] : null;
    $guarantor2_id = !empty($_POST['guarantor2_id']) ? (int)$_POST['guarantor2_id'] : null;
    
    // Get loan type details
    $sql = "SELECT * FROM loan_types WHERE loan_type_id = ? AND company_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$loan_type_id, $company_id]);
    $loan_type = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Validation
    if (!$loan_type) {
        $errors[] = "Invalid loan type selected.";
    } else {
        if ($loan_amount < $loan_type['min_amount']) {
            $errors[] = "Minimum loan amount is " . formatCurrency($loan_type['min_amount']);
        }
        if ($loan_amount > $loan_type['max_amount']) {
            $errors[] = "Maximum loan amount is " . formatCurrency($loan_type['max_amount']);
        }
        if ($loan_term_months < 1 || $loan_term_months > $loan_type['max_term_months']) {
            $errors[] = "Loan term must be between 1 and " . $loan_type['max_term_months'] . " months.";
        }
        if ($loan_type['requires_guarantor'] && !$guarantor1_id) {
            $errors[] = "This loan type requires at least one guarantor.";
        }
    }
    
    if (empty($purpose)) {
        $errors[] = "Please specify the purpose of the loan.";
    }
    
    // Check salary-based limit (max 3x basic salary)
    $max_based_on_salary = $employee['basic_salary'] * 3;
    if ($loan_amount > $max_based_on_salary) {
        $errors[] = "Loan amount cannot exceed 3x your basic salary (" . formatCurrency($max_based_on_salary) . ")";
    }
    
    // Guarantors cannot be the same
    if ($guarantor1_id && $guarantor2_id && $guarantor1_id === $guarantor2_id) {
        $errors[] = "Please select different guarantors.";
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Generate loan number
            $loan_number = generateReference('LN', $conn, $company_id, 'employee_loans', 'loan_number');
            
            // Calculate monthly deduction (simple calculation)
            $monthly_interest = ($loan_amount * $loan_type['interest_rate'] / 100) / 12;
            $monthly_principal = $loan_amount / $loan_term_months;
            $monthly_deduction = $monthly_principal + $monthly_interest;
            $total_repayable = $monthly_deduction * $loan_term_months;
            
            // Insert loan application
            $sql = "INSERT INTO employee_loans (
                        company_id, employee_id, loan_type_id, loan_number, loan_amount,
                        interest_rate, repayment_period_months, monthly_deduction,
                        principal_outstanding, interest_outstanding, total_outstanding,
                        purpose, guarantor1_id, guarantor2_id, status, application_date, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $company_id, $employee['employee_id'], $loan_type_id, $loan_number, $loan_amount,
                $loan_type['interest_rate'], $loan_term_months, $monthly_deduction,
                $loan_amount, ($total_repayable - $loan_amount), $total_repayable,
                $purpose, $guarantor1_id, $guarantor2_id, date('Y-m-d'), $user_id
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
            $errors[] = "An error occurred. Please try again.";
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

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-plus-circle me-2"></i>Apply for Loan</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                        <li class="breadcrumb-item active">Apply</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <ul class="mb-0">
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
                            <div class="row mb-4">
                                <?php foreach ($loan_types as $lt): ?>
                                <div class="col-md-6">
                                    <label class="loan-type-card d-block" data-max="<?php echo $lt['max_amount']; ?>"
                                           data-min="<?php echo $lt['min_amount']; ?>" data-rate="<?php echo $lt['interest_rate']; ?>"
                                           data-term="<?php echo $lt['max_term_months']; ?>" 
                                           data-guarantor="<?php echo $lt['requires_guarantor']; ?>">
                                        <input type="radio" name="loan_type_id" value="<?php echo $lt['loan_type_id']; ?>" required>
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong><?php echo htmlspecialchars($lt['loan_type_name']); ?></strong>
                                                <small class="d-block text-muted"><?php echo $lt['interest_rate']; ?>% interest</small>
                                            </div>
                                            <span class="badge bg-primary"><?php echo formatCurrency($lt['max_amount']); ?></span>
                                        </div>
                                        <small class="text-muted">Up to <?php echo $lt['max_term_months']; ?> months</small>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>

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
                                            <?php echo htmlspecialchars($g['first_name'] . ' ' . $g['last_name']); ?> 
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
                                            <?php echo htmlspecialchars($g['first_name'] . ' ' . $g['last_name']); ?> 
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
                                <button type="submit" class="btn btn-primary btn-lg">
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
                            <strong><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></strong>
                        </p>
                        <p class="mb-2">
                            <small>Employee #: <?php echo htmlspecialchars($employee['employee_number']); ?></small>
                        </p>
                        <p class="mb-2">
                            <small>Basic Salary: <?php echo formatCurrency($employee['basic_salary']); ?></small>
                        </p>
                        <p class="mb-0">
                            <small>Max Eligible: <?php echo formatCurrency($employee['basic_salary'] * 3); ?></small>
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
    </section>
</div>

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
            
            document.getElementById('amountHelp').textContent = 
                `Min: TZS ${selectedType.min.toLocaleString()} | Max: TZS ${selectedType.max.toLocaleString()}`;
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
