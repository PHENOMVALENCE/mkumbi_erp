<?php
/**
 * Loan Types Management
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

// Check permission
if (!hasPermission($conn, $user_id, ['HR_OFFICER', 'FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN'])) {
    $_SESSION['error_message'] = "You don't have permission to manage loan types.";
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $loan_type_id = (int)($_POST['loan_type_id'] ?? 0);
        $loan_type_name = sanitize($_POST['loan_type_name']);
        $loan_code = sanitize($_POST['loan_code']);
        $interest_rate = (float)$_POST['interest_rate'];
        $min_amount = (float)$_POST['min_amount'];
        $max_amount = (float)$_POST['max_amount'];
        $max_term_months = (int)$_POST['max_term_months'];
        $requires_guarantor = isset($_POST['requires_guarantor']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validation
        if (empty($loan_type_name)) {
            $errors[] = "Loan type name is required.";
        }
        if ($interest_rate < 0 || $interest_rate > 100) {
            $errors[] = "Interest rate must be between 0 and 100.";
        }
        if ($min_amount < 0) {
            $errors[] = "Minimum amount must be 0 or greater.";
        }
        if ($max_amount < $min_amount) {
            $errors[] = "Maximum amount must be greater than minimum amount.";
        }
        if ($max_term_months < 1) {
            $errors[] = "Maximum term must be at least 1 month.";
        }
        
        // Check for duplicate name
        $check_sql = "SELECT COUNT(*) as count FROM loan_types 
                      WHERE company_id = ? AND type_name = ? AND loan_type_id != ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->execute([$company_id, $loan_type_name, $loan_type_id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors[] = "A loan type with this name already exists.";
        }
        
        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    // Try both column name variations for compatibility
                    $sql = "INSERT INTO loan_types (company_id, type_name, type_code, interest_rate, 
                                min_amount, max_amount, max_repayment_months, requires_guarantor, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    try {
                        $stmt->execute([$company_id, $loan_type_name, $loan_code, $interest_rate, 
                                       $min_amount, $max_amount, $max_term_months, $requires_guarantor, $is_active]);
                    } catch (PDOException $e) {
                        // Fallback: try with alternative column names
                        $sql = "INSERT INTO loan_types (company_id, loan_type_name, loan_code, interest_rate, 
                                    min_amount, max_amount, max_term_months, requires_guarantor, is_active)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([$company_id, $loan_type_name, $loan_code, $interest_rate, 
                                       $min_amount, $max_amount, $max_term_months, $requires_guarantor, $is_active]);
                    }
                    $success = "Loan type added successfully.";
                } else {
                    // Try both column name variations for compatibility
                    $sql = "UPDATE loan_types 
                            SET type_name = ?, type_code = ?, interest_rate = ?, 
                                min_amount = ?, max_amount = ?, max_repayment_months = ?, 
                                requires_guarantor = ?, is_active = ?
                            WHERE loan_type_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($sql);
                    try {
                        $stmt->execute([$loan_type_name, $loan_code, $interest_rate, $min_amount, $max_amount, 
                                       $max_term_months, $requires_guarantor, $is_active, $loan_type_id, $company_id]);
                    } catch (PDOException $e) {
                        // Fallback: try with alternative column names
                        $sql = "UPDATE loan_types 
                                SET loan_type_name = ?, loan_code = ?, interest_rate = ?, 
                                    min_amount = ?, max_amount = ?, max_term_months = ?, 
                                    requires_guarantor = ?, is_active = ?
                                WHERE loan_type_id = ? AND company_id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([$loan_type_name, $loan_code, $interest_rate, $min_amount, $max_amount, 
                                       $max_term_months, $requires_guarantor, $is_active, $loan_type_id, $company_id]);
                    }
                    $success = "Loan type updated successfully.";
                }
                
                logAudit($conn, $company_id, $user_id, $action === 'add' ? 'create' : 'update', 'loans', 'loan_types', 
                         $loan_type_id ?: $conn->lastInsertId(), null, ['loan_type_name' => $loan_type_name]);
                
            } catch (PDOException $e) {
                error_log("Loan type error: " . $e->getMessage());
                $errors[] = "An error occurred. Please try again.";
            }
        }
    }
    
    if ($action === 'delete') {
        $loan_type_id = (int)$_POST['loan_type_id'];
        
        // Check if loan type is in use
        $check_sql = "SELECT COUNT(*) as count FROM employee_loans WHERE loan_type_id = ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->execute([$loan_type_id]);
        
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors[] = "Cannot delete loan type that has existing loans.";
        } else {
            $sql = "DELETE FROM loan_types WHERE loan_type_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$loan_type_id, $company_id]);
            $success = "Loan type deleted successfully.";
            
            logAudit($conn, $company_id, $user_id, 'delete', 'loans', 'loan_types', $loan_type_id);
        }
    }
}

// Fetch loan types
$sql = "SELECT lt.*, 
               COALESCE(lt.type_name, lt.loan_type_name) as loan_type_name,
               COALESCE(lt.max_repayment_months, lt.max_term_months) as max_term_months,
               (SELECT COUNT(*) FROM employee_loans el WHERE el.loan_type_id = lt.loan_type_id) as usage_count,
               (SELECT SUM(loan_amount) FROM employee_loans el WHERE el.loan_type_id = lt.loan_type_id AND el.status IN ('disbursed', 'active')) as total_disbursed
        FROM loan_types lt
        WHERE lt.company_id = ?
        ORDER BY COALESCE(lt.type_name, lt.loan_type_name)";
$stmt = $conn->prepare($sql);
$stmt->execute([$company_id]);
$loan_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Manage Loan Types";
require_once '../../includes/header.php';
?>

<style>
    .types-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .type-row {
        padding: 20px;
        border-bottom: 1px solid #eee;
        transition: background 0.2s;
    }
    .type-row:hover { background: #f8f9fa; }
    .type-row:last-child { border-bottom: none; }
    .rate-badge {
        display: inline-block;
        padding: 5px 15px;
        border-radius: 20px;
        font-weight: 600;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-cogs me-2"></i>Loan Types</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                        <li class="breadcrumb-item active">Loan Types</li>
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
            
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-12">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTypeModal">
                        <i class="fas fa-plus-circle me-2"></i>Add Loan Type
                    </button>
                </div>
            </div>

            <div class="types-card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Loan Type</th>
                                <th>Interest Rate</th>
                                <th>Amount Range</th>
                                <th>Max Term</th>
                                <th>Guarantor</th>
                                <th>Status</th>
                                <th>Usage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($loan_types)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No loan types configured. Add your first loan type.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($loan_types as $lt): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($lt['loan_type_name']); ?></strong>
                                    <?php if ($lt['loan_code']): ?>
                                    <br><code><?php echo htmlspecialchars($lt['loan_code']); ?></code>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="rate-badge"><?php echo $lt['interest_rate']; ?>%</span>
                                </td>
                                <td>
                                    <?php echo formatCurrency($lt['min_amount']); ?> - 
                                    <?php echo formatCurrency($lt['max_amount']); ?>
                                </td>
                                <td><?php echo $lt['max_term_months']; ?> months</td>
                                <td>
                                    <?php if ($lt['requires_guarantor']): ?>
                                    <span class="badge bg-info">Required</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Not Required</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($lt['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-muted"><?php echo $lt['usage_count']; ?> loans</span>
                                    <?php if ($lt['total_disbursed']): ?>
                                    <br><small class="text-success"><?php echo formatCurrency($lt['total_disbursed']); ?> active</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary edit-btn"
                                            data-id="<?php echo $lt['loan_type_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($lt['loan_type_name']); ?>"
                                            data-code="<?php echo htmlspecialchars($lt['loan_code']); ?>"
                                            data-rate="<?php echo $lt['interest_rate']; ?>"
                                            data-min="<?php echo $lt['min_amount']; ?>"
                                            data-max="<?php echo $lt['max_amount']; ?>"
                                            data-term="<?php echo $lt['max_term_months']; ?>"
                                            data-guarantor="<?php echo $lt['requires_guarantor']; ?>"
                                            data-active="<?php echo $lt['is_active']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($lt['usage_count'] == 0): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this loan type?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="loan_type_id" value="<?php echo $lt['loan_type_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </section>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="loan_type_id" id="loanTypeId" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Loan Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Loan Type Name <span class="text-danger">*</span></label>
                            <input type="text" name="loan_type_name" id="loanTypeName" class="form-control" required
                                   placeholder="e.g., Personal Loan, Emergency Loan">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Loan Code</label>
                            <input type="text" name="loan_code" id="loanCode" class="form-control"
                                   placeholder="e.g., PL, EL, SL">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Interest Rate (% per annum) <span class="text-danger">*</span></label>
                            <input type="number" name="interest_rate" id="interestRate" class="form-control" 
                                   min="0" max="100" step="0.01" value="12" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Minimum Amount <span class="text-danger">*</span></label>
                            <input type="number" name="min_amount" id="minAmount" class="form-control" 
                                   min="0" step="1000" value="100000" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Maximum Amount <span class="text-danger">*</span></label>
                            <input type="number" name="max_amount" id="maxAmount" class="form-control" 
                                   min="0" step="1000" value="5000000" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Maximum Term (Months) <span class="text-danger">*</span></label>
                            <input type="number" name="max_term_months" id="maxTermMonths" class="form-control" 
                                   min="1" max="120" value="24" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="requires_guarantor" id="requiresGuarantor">
                                <label class="form-check-label" for="requiresGuarantor">Requires Guarantor</label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive" checked>
                                <label class="form-check-label" for="isActive">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('addTypeModal');
    const editBtns = document.querySelectorAll('.edit-btn');
    
    // Reset modal on open
    modal.addEventListener('show.bs.modal', function(event) {
        if (!event.relatedTarget || !event.relatedTarget.classList.contains('edit-btn')) {
            document.getElementById('formAction').value = 'add';
            document.getElementById('modalTitle').textContent = 'Add Loan Type';
            document.getElementById('loanTypeId').value = '0';
            document.getElementById('loanTypeName').value = '';
            document.getElementById('loanCode').value = '';
            document.getElementById('interestRate').value = '12';
            document.getElementById('minAmount').value = '100000';
            document.getElementById('maxAmount').value = '5000000';
            document.getElementById('maxTermMonths').value = '24';
            document.getElementById('requiresGuarantor').checked = false;
            document.getElementById('isActive').checked = true;
        }
    });
    
    // Edit button click
    editBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('formAction').value = 'edit';
            document.getElementById('modalTitle').textContent = 'Edit Loan Type';
            document.getElementById('loanTypeId').value = this.dataset.id;
            document.getElementById('loanTypeName').value = this.dataset.name;
            document.getElementById('loanCode').value = this.dataset.code;
            document.getElementById('interestRate').value = this.dataset.rate;
            document.getElementById('minAmount').value = this.dataset.min;
            document.getElementById('maxAmount').value = this.dataset.max;
            document.getElementById('maxTermMonths').value = this.dataset.term;
            document.getElementById('requiresGuarantor').checked = this.dataset.guarantor === '1';
            document.getElementById('isActive').checked = this.dataset.active === '1';
            
            new bootstrap.Modal(modal).show();
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
