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
$user_id = $_SESSION['user_id'];

$error = '';
$success = '';

// ==================== HANDLE FORM SUBMISSION ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $transaction_date = $_POST['transaction_date'];
        $transaction_type = $_POST['transaction_type'];
        $amount = (float)$_POST['amount'];
        $description = trim($_POST['description']);
        $custodian_id = (int)$_POST['custodian_id'];
        
        if (!$transaction_date || !$transaction_type || $amount <= 0 || !$description || !$custodian_id) {
            throw new Exception("Please fill in all required fields");
        }
        
        // Generate reference number
        $prefix = 'PC-' . date('Y');
        $stmt = $conn->prepare("
            SELECT MAX(CAST(SUBSTRING(reference_number, LENGTH(?) + 1) AS UNSIGNED)) as max_num
            FROM petty_cash_transactions 
            WHERE reference_number LIKE CONCAT(?, '%') AND company_id = ?
        ");
        $stmt->execute([$prefix, $prefix, $company_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_num = ($result['max_num'] ?? 0) + 1;
        $reference_number = $prefix . str_pad($next_num, 5, '0', STR_PAD_LEFT);
        
        // Handle receipt upload
        $receipt_path = null;
        if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../uploads/petty_cash/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Invalid file type. Only JPG, PNG, and PDF files are allowed.");
            }
            
            $file_name = $reference_number . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], $file_path)) {
                $receipt_path = $file_path;
            }
        }
        
        // Insert transaction
        $stmt = $conn->prepare("
            INSERT INTO petty_cash_transactions (
                company_id, reference_number, transaction_date, transaction_type,
                category_id, amount, description, payee, custodian_id,
                payment_method, receipt_number, receipt_path, account_code,
                approval_status, notes, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $approval_status = 'pending'; // Auto-approve for certain users can be added later
        
        $stmt->execute([
            $company_id,
            $reference_number,
            $transaction_date,
            $transaction_type,
            $category_id,
            $amount,
            $description,
            trim($_POST['payee']),
            $custodian_id,
            $_POST['payment_method'],
            trim($_POST['receipt_number']),
            $receipt_path,
            trim($_POST['account_code']),
            $approval_status,
            trim($_POST['notes']),
            $user_id
        ]);
        
        $transaction_id = $conn->lastInsertId();
        
        $conn->commit();
        
        $_SESSION['success_message'] = "Transaction $reference_number created successfully!";
        header("Location: view.php?id=$transaction_id");
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
        error_log("Petty cash add error: " . $e->getMessage());
    }
}

// ==================== FETCH CATEGORIES ====================
$categories = [];
try {
    $stmt = $conn->prepare("
        SELECT category_id, category_name, category_code, account_code
        FROM petty_cash_categories
        WHERE company_id = ? AND is_active = 1
        ORDER BY category_name
    ");
    $stmt->execute([$company_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Categories fetch error: " . $e->getMessage());
}

// ==================== FETCH CUSTODIANS ====================
$custodians = [];
try {
    $stmt = $conn->prepare("
        SELECT u.user_id, u.full_name, d.department_name
        FROM users u
        LEFT JOIN employees e ON u.user_id = e.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE u.company_id = ? AND u.is_active = 1
        ORDER BY u.full_name
    ");
    $stmt->execute([$company_id]);
    $custodians = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Custodians fetch error: " . $e->getMessage());
}

$page_title = 'Add Petty Cash Transaction';
require_once '../../includes/header.php';
?>

<style>
.form-section {
    background: #fff;
    border-radius: 6px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #28a745;
}

.section-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-label {
    font-weight: 600;
    font-size: 0.85rem;
    color: #495057;
    margin-bottom: 0.375rem;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.form-control, .form-select {
    font-size: 0.875rem;
    border-radius: 4px;
}

.form-control:focus, .form-select:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}

.info-text {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.type-selector {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.type-option {
    flex: 1;
    padding: 1.25rem;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    cursor: pointer;
    text-align: center;
    transition: all 0.2s;
    position: relative;
}

.type-option:hover {
    border-color: #007bff;
    background: #f8f9fa;
}

.type-option input[type="radio"] {
    position: absolute;
    opacity: 0;
}

.type-option input[type="radio"]:checked + .type-content {
    color: #007bff;
}

.type-option input[type="radio"]:checked ~ .type-icon {
    background: #007bff;
    color: white;
}

.type-option.selected {
    border-color: #007bff;
    background: #e7f3ff;
}

.type-icon {
    width: 60px;
    height: 60px;
    margin: 0 auto 0.75rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    background: #f8f9fa;
    color: #6c757d;
    transition: all 0.2s;
}

.type-content {
    transition: all 0.2s;
}

.type-title {
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 0.25rem;
}

.type-description {
    font-size: 0.75rem;
    color: #6c757d;
}

.upload-area {
    border: 2px dashed #dee2e6;
    border-radius: 6px;
    padding: 2rem;
    text-align: center;
    background: #f8f9fa;
    cursor: pointer;
    transition: all 0.2s;
}

.upload-area:hover {
    border-color: #007bff;
    background: #e7f3ff;
}

.upload-area.dragover {
    border-color: #28a745;
    background: #d4edda;
}

.file-info {
    margin-top: 1rem;
    padding: 0.75rem;
    background: #e7f3ff;
    border-radius: 4px;
    border-left: 3px solid #007bff;
}

@media (max-width: 768px) {
    .type-selector {
        flex-direction: column;
    }
    
    .type-icon {
        width: 50px;
        height: 50px;
        font-size: 1.25rem;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-plus-circle me-2"></i>Add Petty Cash Transaction
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to List
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="pettyCashForm">
        
        <div class="row">
            <div class="col-md-8">
                
                <!-- Transaction Type Selection -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-list-alt me-2"></i>Transaction Type
                    </div>
                    
                    <div class="type-selector">
                        <label class="type-option">
                            <input type="radio" name="transaction_type" value="replenishment" required>
                            <div class="type-content">
                                <div class="type-icon">
                                    <i class="fas fa-arrow-circle-down"></i>
                                </div>
                                <div class="type-title">Replenishment</div>
                                <div class="type-description">Add money to petty cash</div>
                            </div>
                        </label>
                        
                        <label class="type-option" id="expenseOption">
                            <input type="radio" name="transaction_type" value="expense" checked required>
                            <div class="type-content">
                                <div class="type-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="type-title">Expense</div>
                                <div class="type-description">Record an expense</div>
                            </div>
                        </label>
                        
                        <label class="type-option">
                            <input type="radio" name="transaction_type" value="return" required>
                            <div class="type-content">
                                <div class="type-icon">
                                    <i class="fas fa-undo-alt"></i>
                                </div>
                                <div class="type-title">Return</div>
                                <div class="type-description">Return cash to bank</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Basic Information -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-info-circle me-2"></i>Basic Information
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Transaction Date</label>
                            <input type="date" name="transaction_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Amount (TSH)</label>
                            <input type="number" name="amount" id="amount" class="form-control" 
                                   step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="categoryField">
                        <label class="form-label">Category</label>
                        <select name="category_id" id="category_id" class="form-select">
                            <option value="">Select Category (Optional)</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>" 
                                    data-account-code="<?= htmlspecialchars($cat['account_code']) ?>">
                                <?= htmlspecialchars($cat['category_name']) ?>
                                <?php if ($cat['category_code']): ?>
                                    (<?= htmlspecialchars($cat['category_code']) ?>)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="info-text">Categorize the transaction for better tracking</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required-field">Description</label>
                        <textarea name="description" class="form-control" rows="3" 
                                  placeholder="Describe the transaction in detail..." required></textarea>
                    </div>
                    
                    <div class="mb-3" id="payeeField">
                        <label class="form-label">Payee/Vendor</label>
                        <input type="text" name="payee" class="form-control" 
                               placeholder="Name of person/company receiving payment">
                        <div class="info-text">Who received the payment?</div>
                    </div>
                </div>

                <!-- Payment Details -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-credit-card me-2"></i>Payment Details
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Custodian</label>
                            <select name="custodian_id" class="form-select" required>
                                <option value="">Select Custodian</option>
                                <?php foreach ($custodians as $custodian): ?>
                                <option value="<?= $custodian['user_id'] ?>" 
                                        <?= $custodian['user_id'] == $user_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($custodian['full_name']) ?>
                                    <?php if ($custodian['department_name']): ?>
                                        - <?= htmlspecialchars($custodian['department_name']) ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="info-text">Person responsible for this transaction</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash" selected>Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Receipt Number</label>
                        <input type="text" name="receipt_number" class="form-control" 
                               placeholder="Receipt or reference number">
                    </div>
                </div>

                <!-- Receipt Upload -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-file-upload me-2"></i>Receipt/Supporting Document
                    </div>
                    
                    <div class="upload-area" id="uploadArea">
                        <input type="file" name="receipt" id="receiptFile" 
                               accept=".jpg,.jpeg,.png,.pdf" style="display: none;">
                        <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                        <p class="mb-1">Click to upload or drag and drop</p>
                        <small class="text-muted">JPG, PNG or PDF (Max 5MB)</small>
                    </div>
                    <div id="fileInfo" class="file-info" style="display: none;">
                        <i class="fas fa-file me-2"></i>
                        <span id="fileName"></span>
                        <a href="#" id="removeFile" class="float-end text-danger">
                            <i class="fas fa-times"></i> Remove
                        </a>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-sticky-note me-2"></i>Additional Information
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Account Code</label>
                        <input type="text" name="account_code" id="account_code" class="form-control" 
                               placeholder="GL Account Code">
                        <div class="info-text">General Ledger account code (auto-filled from category)</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Internal Notes</label>
                        <textarea name="notes" class="form-control" rows="2" 
                                  placeholder="Any additional notes or comments..."></textarea>
                    </div>
                </div>

            </div>

            <div class="col-md-4">
                
                <!-- Quick Summary -->
                <div class="form-section" style="border-left-color: #007bff;">
                    <div class="section-title">
                        <i class="fas fa-calculator me-2"></i>Transaction Summary
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <div id="summaryType" class="form-control-plaintext">
                            <span class="badge bg-secondary">Not selected</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <div id="summaryAmount" class="form-control-plaintext" style="font-size: 1.5rem; font-weight: 700; color: #2c3e50;">
                            TSH 0.00
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Impact on Balance</label>
                        <div id="summaryImpact" class="form-control-plaintext">
                            <span class="text-muted">-</span>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label">Status After Submit</label>
                        <div class="form-control-plaintext">
                            <span class="badge bg-warning">Pending Approval</span>
                        </div>
                        <div class="info-text">Transaction will require approval before affecting the balance</div>
                    </div>
                </div>

                <!-- Help & Guidelines -->
                <div class="form-section" style="border-left-color: #17a2b8;">
                    <div class="section-title">
                        <i class="fas fa-info-circle me-2"></i>Guidelines
                    </div>
                    
                    <ul style="font-size: 0.85rem; padding-left: 1.25rem; margin-bottom: 0;">
                        <li class="mb-2"><strong>Replenishment:</strong> Use when adding money to petty cash from main account</li>
                        <li class="mb-2"><strong>Expense:</strong> Use for all payments made from petty cash</li>
                        <li class="mb-2"><strong>Return:</strong> Use when returning unused cash to main account</li>
                        <li class="mb-2"><strong>Receipts:</strong> Always attach receipt/invoice when available</li>
                        <li class="mb-2"><strong>Description:</strong> Be specific about what was purchased or paid for</li>
                        <li><strong>Approval:</strong> All transactions require approval before affecting balance</li>
                    </ul>
                </div>

                <!-- Action Buttons -->
                <div class="form-section" style="border-left-color: #28a745;">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-check-circle me-2"></i>Submit Transaction
                        </button>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                    </div>
                </div>

            </div>
        </div>

    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('pettyCashForm');
    const typeOptions = document.querySelectorAll('.type-option');
    const categoryField = document.getElementById('categoryField');
    const payeeField = document.getElementById('payeeField');
    const amountInput = document.getElementById('amount');
    const categorySelect = document.getElementById('category_id');
    const accountCodeInput = document.getElementById('account_code');
    
    // Summary elements
    const summaryType = document.getElementById('summaryType');
    const summaryAmount = document.getElementById('summaryAmount');
    const summaryImpact = document.getElementById('summaryImpact');
    
    // File upload
    const uploadArea = document.getElementById('uploadArea');
    const receiptFile = document.getElementById('receiptFile');
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    const removeFile = document.getElementById('removeFile');
    
    // Handle transaction type selection
    typeOptions.forEach(option => {
        const radio = option.querySelector('input[type="radio"]');
        
        option.addEventListener('click', function() {
            typeOptions.forEach(opt => opt.classList.remove('selected'));
            option.classList.add('selected');
            updateSummary();
            
            // Show/hide fields based on type
            const type = radio.value;
            if (type === 'replenishment') {
                categoryField.style.display = 'none';
                payeeField.querySelector('label').textContent = 'Source';
                payeeField.querySelector('input').placeholder = 'Bank/Account Name';
            } else if (type === 'expense') {
                categoryField.style.display = 'block';
                payeeField.querySelector('label').textContent = 'Payee/Vendor';
                payeeField.querySelector('input').placeholder = 'Name of person/company receiving payment';
            } else if (type === 'return') {
                categoryField.style.display = 'none';
                payeeField.querySelector('label').textContent = 'Returned To';
                payeeField.querySelector('input').placeholder = 'Bank/Account Name';
            }
        });
        
        // Set initial state for checked option
        if (radio.checked) {
            option.classList.add('selected');
            option.click();
        }
    });
    
    // Update summary when amount changes
    amountInput.addEventListener('input', updateSummary);
    
    // Auto-fill account code from category
    categorySelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const accountCode = selectedOption.getAttribute('data-account-code');
        if (accountCode) {
            accountCodeInput.value = accountCode;
        }
    });
    
    function updateSummary() {
        const selectedType = document.querySelector('input[name="transaction_type"]:checked');
        const amount = parseFloat(amountInput.value) || 0;
        
        if (selectedType) {
            const type = selectedType.value;
            let badgeClass = 'bg-secondary';
            let badgeText = type.charAt(0).toUpperCase() + type.slice(1);
            let impactText = '';
            let impactClass = '';
            
            if (type === 'replenishment') {
                badgeClass = 'bg-success';
                impactText = '+' + amount.toFixed(2);
                impactClass = 'text-success';
            } else if (type === 'expense') {
                badgeClass = 'bg-danger';
                impactText = '-' + amount.toFixed(2);
                impactClass = 'text-danger';
            } else if (type === 'return') {
                badgeClass = 'bg-info';
                impactText = '-' + amount.toFixed(2);
                impactClass = 'text-info';
            }
            
            summaryType.innerHTML = `<span class="badge ${badgeClass}">${badgeText}</span>`;
            summaryAmount.textContent = 'TSH ' + amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            if (amount > 0) {
                summaryImpact.innerHTML = `<strong class="${impactClass}" style="font-size: 1.25rem;">${impactText}</strong>`;
            } else {
                summaryImpact.innerHTML = '<span class="text-muted">-</span>';
            }
        }
    }
    
    // File upload handling
    uploadArea.addEventListener('click', function() {
        receiptFile.click();
    });
    
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', function() {
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        if (e.dataTransfer.files.length) {
            receiptFile.files = e.dataTransfer.files;
            handleFileSelect();
        }
    });
    
    receiptFile.addEventListener('change', handleFileSelect);
    
    function handleFileSelect() {
        if (receiptFile.files.length > 0) {
            const file = receiptFile.files[0];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (file.size > maxSize) {
                alert('File size exceeds 5MB. Please choose a smaller file.');
                receiptFile.value = '';
                return;
            }
            
            fileName.textContent = file.name;
            fileInfo.style.display = 'block';
            uploadArea.style.display = 'none';
        }
    }
    
    removeFile.addEventListener('click', function(e) {
        e.preventDefault();
        receiptFile.value = '';
        fileInfo.style.display = 'none';
        uploadArea.style.display = 'block';
    });
    
    // Form validation
    form.addEventListener('submit', function(e) {
        const amount = parseFloat(amountInput.value);
        if (amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid amount greater than 0');
            amountInput.focus();
        }
    });
    
    // Initialize summary
    updateSummary();
});
</script>

<?php require_once '../../includes/footer.php'; ?>