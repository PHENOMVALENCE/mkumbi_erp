<?php
/**
 * Petty Cash Request Form
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

// Get active petty cash accounts
$sql = "SELECT * FROM petty_cash_accounts WHERE company_id = ? AND is_active = 1 ORDER BY account_name";
$stmt = $conn->prepare($sql);
$stmt->execute([$company_id]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get expense categories
$expense_categories = [
    'OFFICE_SUPPLIES' => 'Office Supplies',
    'TRANSPORT' => 'Transport/Fuel',
    'MEALS' => 'Meals & Entertainment',
    'UTILITIES' => 'Utilities',
    'REPAIRS' => 'Repairs & Maintenance',
    'MISCELLANEOUS' => 'Miscellaneous',
    'OTHER' => 'Other'
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $petty_cash_id = (int)$_POST['petty_cash_id'];
    $amount = (float)$_POST['amount'];
    $description = sanitize($_POST['description']);
    $category = sanitize($_POST['category']);
    $receipt_number = sanitize($_POST['receipt_number']);
    
    // Validation
    if (!$petty_cash_id) {
        $errors[] = "Please select a petty cash account.";
    }
    if ($amount <= 0) {
        $errors[] = "Amount must be greater than zero.";
    }
    if (empty($description)) {
        $errors[] = "Please provide a description.";
    }
    
    // Check account balance
    $sql = "SELECT * FROM petty_cash_accounts WHERE petty_cash_id = ? AND company_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$petty_cash_id, $company_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        $errors[] = "Invalid account selected.";
    } elseif ($amount > $account['current_balance']) {
        $errors[] = "Requested amount exceeds available balance (" . formatCurrency($account['current_balance']) . ")";
    } elseif ($account['single_transaction_limit'] > 0 && $amount > $account['single_transaction_limit']) {
        $errors[] = "Amount exceeds single transaction limit (" . formatCurrency($account['single_transaction_limit']) . ")";
    }
    
    if (empty($errors)) {
        try {
            $reference = generateReference('PC', $conn, $company_id, 'petty_cash_transactions', 'transaction_number');
            
            $balance_before = $account['current_balance'];
            $balance_after = $balance_before - $amount;
            
            $sql = "INSERT INTO petty_cash_transactions (
                        petty_cash_id, transaction_number, transaction_type, transaction_date,
                        amount, description, category_id, receipt_number, status, balance_before, balance_after, created_by
                    ) VALUES (?, ?, 'disbursement', CURDATE(), ?, ?, NULL, ?, 'pending', ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$petty_cash_id, $reference, $amount, $description, $receipt_number, $balance_before, $balance_after, $user_id]);
            
            logAudit($conn, $company_id, $user_id, 'create', 'petty_cash', 'petty_cash_transactions', 
                     $conn->lastInsertId(), null, ['reference' => $reference, 'amount' => $amount]);
            
            $_SESSION['success_message'] = "Request submitted successfully! Reference: " . $reference;
            header('Location: my-requests.php');
            exit;
            
        } catch (PDOException $e) {
            error_log("Petty cash request error: " . $e->getMessage());
            $errors[] = "An error occurred. Please try again.";
        }
    }
}

$page_title = "Request Petty Cash";
require_once '../../includes/header.php';
?>

<style>
    .form-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 30px;
    }
    .account-option {
        border: 2px solid #e9ecef;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .account-option:hover, .account-option.selected {
        border-color: #667eea;
        background: #f8f9fe;
    }
    .account-option input { display: none; }
    .account-balance {
        font-size: 1.2rem;
        font-weight: 600;
        color: #28a745;
    }
    .account-balance.low { color: #dc3545; }
    .info-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-hand-holding-usd me-2"></i>Request Petty Cash</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Petty Cash</a></li>
                        <li class="breadcrumb-item active">Request</li>
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

            <?php if (empty($accounts)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No petty cash accounts are available. Please contact finance department.
            </div>
            <?php else: ?>
            
            <div class="row">
                <div class="col-lg-8">
                    <div class="form-card">
                        <form method="POST">
                            <!-- Select Account -->
                            <h5 class="mb-3"><i class="fas fa-wallet me-2"></i>Select Account</h5>
                            <div class="row mb-4">
                                <?php foreach ($accounts as $acc): 
                                    $balance_percent = $acc['maximum_balance'] > 0 ? ($acc['current_balance'] / $acc['maximum_balance']) * 100 : 0;
                                ?>
                                <div class="col-md-6">
                                    <label class="account-option d-block">
                                        <input type="radio" name="petty_cash_id" value="<?php echo $acc['petty_cash_id']; ?>" 
                                               data-balance="<?php echo $acc['current_balance']; ?>"
                                               data-limit="<?php echo $acc['transaction_limit']; ?>" required>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($acc['account_name']); ?></strong>
                                                <small class="d-block text-muted">
                                                    Limit: <?php echo formatCurrency($acc['transaction_limit']); ?> per transaction
                                                </small>
                                            </div>
                                            <span class="account-balance <?php echo $balance_percent < 20 ? 'low' : ''; ?>">
                                                <?php echo formatCurrency($acc['current_balance']); ?>
                                            </span>
                                        </div>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <hr class="my-4">

                            <!-- Request Details -->
                            <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Request Details</h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Amount (TZS) <span class="text-danger">*</span></label>
                                    <input type="number" name="amount" id="requestAmount" class="form-control" 
                                           min="0" step="100" required placeholder="0.00">
                                    <small id="amountHelp" class="text-muted">Enter requested amount</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Category <span class="text-danger">*</span></label>
                                    <select name="category" class="form-select" required>
                                        <option value="">-- Select Category --</option>
                                        <?php foreach ($expense_categories as $key => $label): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Description <span class="text-danger">*</span></label>
                                <textarea name="description" class="form-control" rows="3" required
                                          placeholder="Describe what this expense is for..."></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Receipt/Invoice Number</label>
                                <input type="text" name="receipt_number" class="form-control"
                                       placeholder="Optional: Enter receipt or invoice number">
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Request
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary btn-lg ms-2">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Employee Info -->
                    <div class="info-card mb-4">
                        <h6><i class="fas fa-user me-2"></i>Requestor</h6>
                        <hr style="border-color: rgba(255,255,255,0.3);">
                        <p class="mb-2">
                            <strong><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></strong>
                        </p>
                        <p class="mb-0">
                            <small>Employee #: <?php echo htmlspecialchars($employee['employee_number']); ?></small>
                        </p>
                    </div>

                    <!-- Guidelines -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Guidelines</h6>
                        </div>
                        <div class="card-body">
                            <ul class="small mb-0">
                                <li class="mb-2">Keep all receipts for expenses</li>
                                <li class="mb-2">Provide detailed descriptions</li>
                                <li class="mb-2">Requests are subject to approval</li>
                                <li class="mb-2">Large expenses may require additional documentation</li>
                                <li>Submit receipts within 24 hours of disbursement</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const accountOptions = document.querySelectorAll('.account-option');
    const amountInput = document.getElementById('requestAmount');
    const amountHelp = document.getElementById('amountHelp');
    
    let selectedBalance = 0;
    let selectedLimit = 0;
    
    accountOptions.forEach(option => {
        option.addEventListener('click', function() {
            accountOptions.forEach(o => o.classList.remove('selected'));
            this.classList.add('selected');
            this.querySelector('input').checked = true;
            
            selectedBalance = parseFloat(this.querySelector('input').dataset.balance);
            selectedLimit = parseFloat(this.querySelector('input').dataset.limit);
            
            amountInput.max = Math.min(selectedBalance, selectedLimit || selectedBalance);
            amountHelp.textContent = `Max: TZS ${amountInput.max.toLocaleString()}`;
        });
    });
    
    amountInput.addEventListener('input', function() {
        if (selectedLimit > 0 && parseFloat(this.value) > selectedLimit) {
            amountHelp.textContent = 'Exceeds transaction limit!';
            amountHelp.classList.add('text-danger');
        } else if (parseFloat(this.value) > selectedBalance) {
            amountHelp.textContent = 'Exceeds available balance!';
            amountHelp.classList.add('text-danger');
        } else {
            amountHelp.textContent = `Max: TZS ${(selectedLimit > 0 ? Math.min(selectedBalance, selectedLimit) : selectedBalance).toLocaleString()}`;
            amountHelp.classList.remove('text-danger');
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
