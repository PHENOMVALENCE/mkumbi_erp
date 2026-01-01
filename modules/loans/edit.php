<?php
/**
 * Edit Loan Application
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

$loan_id = (int)($_GET['id'] ?? 0);
if (!$loan_id) {
    $_SESSION['error_message'] = "Invalid loan ID.";
    header('Location: index.php');
    exit;
}

// Get loan details
$sql = "SELECT el.*, lt.type_name as loan_type_name, lt.requires_guarantor, u.full_name as employee_name
        FROM employee_loans el
        JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
        JOIN employees e ON el.employee_id = e.employee_id
        JOIN users u ON e.user_id = u.user_id
        WHERE el.loan_id = ? AND el.company_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$loan_id, $company_id]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    $_SESSION['error_message'] = "Loan not found.";
    header('Location: index.php');
    exit;
}

// Check permission
$employee = getEmployeeByUserId($conn, $user_id, $company_id);
$is_owner = $employee && $loan['employee_id'] == $employee['employee_id'];
$is_hr = hasPermission($conn, $user_id, ['HR_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN']);

if (!$is_owner && !$is_hr) {
    $_SESSION['error_message'] = "You don't have permission to edit this loan.";
    header('Location: index.php');
    exit;
}

// Only pending loans can be edited
if (strtolower($loan['status']) !== 'pending') {
    $_SESSION['error_message'] = "Only pending loan applications can be edited.";
    header('Location: view.php?id=' . $loan_id);
    exit;
}

// Get potential guarantors (other employees)
$sql = "SELECT e.employee_id, u.full_name, e.employee_number 
        FROM employees e
        JOIN users u ON e.user_id = u.user_id
        WHERE e.company_id = ? AND e.employee_id != ? AND e.is_active = 1 AND e.employment_status = 'active'
        ORDER BY u.full_name";
$stmt = $conn->prepare($sql);
$stmt->execute([$company_id, $loan['employee_id']]);
$guarantors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Edit Loan Application - " . $loan['loan_number'];
require_once '../../includes/header.php';
?>

<style>
    .form-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 30px;
    }
    .info-box {
        background: #f8f9fe;
        border-left: 4px solid #667eea;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0"><i class="fas fa-edit text-primary"></i> Edit Loan Application</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                    <li class="breadcrumb-item active">Edit Application</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-8 offset-lg-2">
                
                <div class="info-box">
                    <strong>Loan Reference:</strong> <?= htmlspecialchars($loan['loan_number']) ?><br>
                    <strong>Loan Type:</strong> <?= htmlspecialchars($loan['loan_type_name']) ?><br>
                    <strong>Applicant:</strong> <?= htmlspecialchars($loan['employee_name']) ?><br>
                    <strong>Application Date:</strong> <?= date('M d, Y', strtotime($loan['application_date'])) ?>
                </div>

                <div class="form-card">
                    <form action="process.php" method="POST" id="editLoanForm">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="loan_id" value="<?= $loan_id ?>">
                        <input type="hidden" name="redirect" value="view.php?id=<?= $loan_id ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Loan Amount (TSH) <span class="text-danger">*</span></label>
                                    <input type="number" name="loan_amount" class="form-control" 
                                           value="<?= $loan['loan_amount'] ?>" required min="1" step="0.01">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Repayment Period (Months) <span class="text-danger">*</span></label>
                                    <input type="number" name="loan_term_months" class="form-control" 
                                           value="<?= $loan['repayment_period_months'] ?>" required min="1">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Purpose <span class="text-danger">*</span></label>
                            <textarea name="purpose" class="form-control" rows="3" required><?= htmlspecialchars($loan['purpose']) ?></textarea>
                        </div>

                        <?php if ($loan['requires_guarantor']): ?>
                        <h5 class="mt-4 mb-3">Guarantors</h5>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>First Guarantor</label>
                                    <select name="guarantor1_id" class="form-control">
                                        <option value="">-- Select Guarantor --</option>
                                        <?php foreach ($guarantors as $g): ?>
                                            <option value="<?= $g['employee_id'] ?>" 
                                                <?= $loan['guarantor1_id'] == $g['employee_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($g['full_name']) ?> (<?= htmlspecialchars($g['employee_number']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Second Guarantor (Optional)</label>
                                    <select name="guarantor2_id" class="form-control">
                                        <option value="">-- Select Guarantor --</option>
                                        <?php foreach ($guarantors as $g): ?>
                                            <option value="<?= $g['employee_id'] ?>" 
                                                <?= $loan['guarantor2_id'] == $g['employee_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($g['full_name']) ?> (<?= htmlspecialchars($g['employee_number']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="form-group mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> Update Loan Application
                            </button>
                            <a href="view.php?id=<?= $loan_id ?>" class="btn btn-secondary btn-lg">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    $('#editLoanForm').on('submit', function(e) {
        const amount = parseFloat($('[name="loan_amount"]').val());
        const term = parseInt($('[name="loan_term_months"]').val());
        
        if (amount <= 0) {
            e.preventDefault();
            alert('Loan amount must be greater than zero.');
            return false;
        }
        
        if (term < 1) {
            e.preventDefault();
            alert('Repayment period must be at least 1 month.');
            return false;
        }
        
        // Confirm update
        if (!confirm('Are you sure you want to update this loan application?')) {
            e.preventDefault();
            return false;
        }
    });
});
</script>
