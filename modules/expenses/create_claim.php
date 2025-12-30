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

$errors = [];
$success = '';

// Fetch user's employee record
try {
    $stmt = $conn->prepare("
        SELECT employee_id, department_id 
        FROM employees 
        WHERE company_id = ? AND user_id = ? AND is_active = 1
    ");
    $stmt->execute([$company_id, $user_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching employee: " . $e->getMessage());
    $employee = null;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'submit_claim') {
        // Validation
        if (empty($_POST['claim_date'])) {
            $errors[] = "Claim date is required";
        }
        if (empty($_POST['description'])) {
            $errors[] = "Description is required";
        }
        if (!$employee) {
            $errors[] = "You must be registered as an employee to submit claims";
        }
        
        // Validate items
        $items = [];
        if (!empty($_POST['items'])) {
            foreach ($_POST['items'] as $index => $item) {
                if (!empty($item['category_id']) && !empty($item['amount'])) {
                    $items[] = [
                        'category_id' => $item['category_id'],
                        'expense_date' => $item['expense_date'] ?? $_POST['claim_date'],
                        'description' => $item['description'] ?? '',
                        'quantity' => $item['quantity'] ?? 1,
                        'unit_price' => $item['unit_price'] ?? 0,
                        'amount' => $item['amount'],
                        'tax_amount' => $item['tax_amount'] ?? 0,
                        'vendor_name' => $item['vendor_name'] ?? '',
                        'receipt_number' => $item['receipt_number'] ?? '',
                        'notes' => $item['notes'] ?? ''
                    ];
                }
            }
        }
        
        if (empty($items)) {
            $errors[] = "Please add at least one expense item";
        }
        
        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                
                // Generate claim number
                $stmt = $conn->prepare("
                    SELECT COALESCE(MAX(CAST(SUBSTRING(claim_number, 5) AS UNSIGNED)), 0) + 1 as next_num
                    FROM expense_claims 
                    WHERE company_id = ? AND claim_number LIKE 'CLM-%'
                ");
                $stmt->execute([$company_id]);
                $next_num = $stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
                $claim_number = 'CLM-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
                
                // Calculate total amount
                $total_amount = array_sum(array_column($items, 'amount'));
                
                // Determine status based on auto-submit checkbox
                $status = isset($_POST['auto_submit']) ? 'pending_approval' : 'draft';
                
                // Insert claim
                $stmt = $conn->prepare("
                    INSERT INTO expense_claims (
                        company_id, claim_number, employee_id, department_id,
                        claim_date, total_amount, currency, payment_method,
                        bank_account_id, description, purpose, status,
                        submitted_at, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $company_id,
                    $claim_number,
                    $employee['employee_id'],
                    $employee['department_id'],
                    $_POST['claim_date'],
                    $total_amount,
                    'TSH',
                    $_POST['payment_method'] ?? 'bank_transfer',
                    $_POST['bank_account_id'] ?? null,
                    $_POST['description'],
                    $_POST['purpose'] ?? null,
                    $status,
                    $status === 'pending_approval' ? date('Y-m-d H:i:s') : null,
                    $user_id
                ]);
                
                $claim_id = $conn->lastInsertId();
                
                // Insert claim items
                $stmt = $conn->prepare("
                    INSERT INTO expense_claim_items (
                        claim_id, category_id, expense_date, description,
                        quantity, unit_price, total_amount, tax_amount,
                        account_code, vendor_name, receipt_number, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($items as $item) {
                    // Get account code for category
                    $cat_stmt = $conn->prepare("SELECT account_code FROM expense_categories WHERE category_id = ?");
                    $cat_stmt->execute([$item['category_id']]);
                    $account_code = $cat_stmt->fetch(PDO::FETCH_ASSOC)['account_code'] ?? '';
                    
                    $stmt->execute([
                        $claim_id,
                        $item['category_id'],
                        $item['expense_date'],
                        $item['description'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['amount'],
                        $item['tax_amount'],
                        $account_code,
                        $item['vendor_name'],
                        $item['receipt_number'],
                        $item['notes']
                    ]);
                }
                
                $conn->commit();
                
                $status_msg = $status === 'pending_approval' ? 'submitted for approval' : 'saved as draft';
                $success = "Expense claim {$claim_number} has been {$status_msg} successfully!";
                
                // Redirect to claims list after 2 seconds
                header("refresh:2;url=claims.php");
                
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Error creating claim: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Fetch expense categories
try {
    $stmt = $conn->prepare("
        SELECT category_id, category_name, account_code, description
        FROM expense_categories
        WHERE company_id = ? AND is_active = 1
        ORDER BY category_name
    ");
    $stmt->execute([$company_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Fetch bank accounts for payment method
try {
    $stmt = $conn->prepare("
        SELECT bank_account_id, account_name, bank_name, account_number
        FROM bank_accounts
        WHERE company_id = ? AND is_active = 1
        ORDER BY is_default DESC, account_name
    ");
    $stmt->execute([$company_id]);
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching bank accounts: " . $e->getMessage());
    $bank_accounts = [];
}

$page_title = 'Submit Expense Claim';
require_once '../../includes/header.php';
?>

<style>
.form-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.form-section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.item-card {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    position: relative;
}

.item-number {
    position: absolute;
    top: -12px;
    left: 16px;
    background: #007bff;
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
}

.remove-item-btn {
    position: absolute;
    top: 8px;
    right: 8px;
}

.add-item-btn {
    width: 100%;
    border: 2px dashed #dee2e6;
    background: white;
    color: #6c757d;
    padding: 1rem;
    border-radius: 8px;
    transition: all 0.3s;
}

.add-item-btn:hover {
    border-color: #007bff;
    background: #f8f9fa;
    color: #007bff;
}

.summary-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-top: 1.5rem;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.summary-row:last-child {
    border-bottom: none;
    font-size: 1.25rem;
    font-weight: 700;
    margin-top: 0.5rem;
    padding-top: 1rem;
    border-top: 2px solid rgba(255,255,255,0.3);
}

.help-text {
    font-size: 0.875rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.alert-info-custom {
    background: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .item-card {
        padding: 1rem;
        padding-top: 2rem;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-receipt text-primary me-2"></i>Submit Expense Claim
                </h1>
                <p class="text-muted small mb-0 mt-1">Submit your expense reimbursement claim</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="claims.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Claims
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Errors:</h5>
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
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <p class="mb-0 mt-2">Redirecting to claims list...</p>
        </div>
        <?php endif; ?>

        <?php if (!$employee): ?>
        <div class="alert alert-warning">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Employee Record Required</h5>
            <p>You must be registered as an employee to submit expense claims.</p>
            <p class="mb-0">Please contact your administrator to set up your employee profile.</p>
        </div>
        <?php else: ?>

        <form method="POST" id="claimForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_claim">

            <!-- Basic Information -->
            <div class="form-card">
                <div class="form-section-title">
                    <i class="fas fa-info-circle"></i> Basic Information
                </div>

                <div class="alert-info-custom">
                    <i class="fas fa-lightbulb me-2"></i>
                    <strong>Tip:</strong> Make sure to provide clear descriptions and attach receipts for all expenses.
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label required-field">Claim Date</label>
                        <input type="date" name="claim_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required-field">Payment Method</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Preferred Bank Account</label>
                        <select name="bank_account_id" class="form-select">
                            <option value="">-- Select Account --</option>
                            <?php foreach ($bank_accounts as $account): ?>
                                <option value="<?php echo $account['bank_account_id']; ?>">
                                    <?php echo htmlspecialchars($account['account_name']); ?>
                                    <?php if ($account['bank_name']): ?>
                                        - <?php echo htmlspecialchars($account['bank_name']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Optional: For bank transfers</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label required-field">Claim Description</label>
                        <input type="text" name="description" class="form-control" required placeholder="e.g., Travel expenses for client meeting">
                        <div class="help-text">Brief summary of this claim</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Purpose/Justification</label>
                        <textarea name="purpose" class="form-control" rows="2" placeholder="Explain the business purpose of these expenses..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Expense Items -->
            <div class="form-card">
                <div class="form-section-title">
                    <i class="fas fa-list"></i> Expense Items
                </div>

                <div id="itemsContainer">
                    <!-- Initial item will be added by JavaScript -->
                </div>

                <button type="button" class="add-item-btn" onclick="addItem()">
                    <i class="fas fa-plus-circle me-2"></i> Add Another Item
                </button>
            </div>

            <!-- Summary -->
            <div class="form-card">
                <div class="summary-box">
                    <h5 class="mb-3"><i class="fas fa-calculator me-2"></i>Claim Summary</h5>
                    <div class="summary-row">
                        <span>Number of Items:</span>
                        <span id="totalItems">0</span>
                    </div>
                    <div class="summary-row">
                        <span>Total Amount:</span>
                        <span id="totalAmount">TSH 0.00</span>
                    </div>
                </div>
            </div>

            <!-- Submit Actions -->
            <div class="form-card">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="auto_submit" id="auto_submit" checked>
                            <label class="form-check-label" for="auto_submit">
                                <strong>Submit for approval immediately</strong>
                                <div class="help-text">Uncheck to save as draft</div>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <a href="claims.php" class="btn btn-secondary me-2">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-1"></i> Submit Claim
                        </button>
                    </div>
                </div>
            </div>

        </form>

        <?php endif; ?>

    </div>
</section>

<script>
let itemCounter = 0;
const categories = <?php echo json_encode($categories); ?>;

function addItem() {
    itemCounter++;
    const container = document.getElementById('itemsContainer');
    
    const itemHtml = `
        <div class="item-card" id="item-${itemCounter}">
            <div class="item-number">Item #${itemCounter}</div>
            ${itemCounter > 1 ? `<button type="button" class="btn btn-sm btn-danger remove-item-btn" onclick="removeItem(${itemCounter})">
                <i class="fas fa-times"></i>
            </button>` : ''}
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required-field">Expense Category</label>
                    <select name="items[${itemCounter}][category_id]" class="form-select" required onchange="updateAccountCode(${itemCounter})">
                        <option value="">-- Select Category --</option>
                        ${categories.map(cat => `<option value="${cat.category_id}" data-code="${cat.account_code}">${cat.category_name}</option>`).join('')}
                    </select>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label required-field">Expense Date</label>
                    <input type="date" name="items[${itemCounter}][expense_date]" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="col-12">
                    <label class="form-label required-field">Description</label>
                    <input type="text" name="items[${itemCounter}][description]" class="form-control" required placeholder="e.g., Taxi fare from airport to hotel">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="items[${itemCounter}][quantity]" class="form-control" value="1" step="0.01" min="0" onchange="calculateItemTotal(${itemCounter})">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Unit Price</label>
                    <input type="number" name="items[${itemCounter}][unit_price]" class="form-control" value="0" step="0.01" min="0" onchange="calculateItemTotal(${itemCounter})">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label required-field">Amount (TSH)</label>
                    <input type="number" name="items[${itemCounter}][amount]" id="amount-${itemCounter}" class="form-control" required step="0.01" min="0" onchange="updateSummary()">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Tax Amount</label>
                    <input type="number" name="items[${itemCounter}][tax_amount]" class="form-control" value="0" step="0.01" min="0">
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Vendor/Supplier</label>
                    <input type="text" name="items[${itemCounter}][vendor_name]" class="form-control" placeholder="e.g., XYZ Taxi Services">
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Receipt Number</label>
                    <input type="text" name="items[${itemCounter}][receipt_number]" class="form-control" placeholder="e.g., RCP-12345">
                </div>
                
                <div class="col-12">
                    <label class="form-label">Additional Notes</label>
                    <textarea name="items[${itemCounter}][notes]" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', itemHtml);
    updateSummary();
}

function removeItem(itemId) {
    const item = document.getElementById(`item-${itemId}`);
    if (item) {
        item.remove();
        updateSummary();
        renumberItems();
    }
}

function renumberItems() {
    const items = document.querySelectorAll('.item-card');
    items.forEach((item, index) => {
        const number = item.querySelector('.item-number');
        if (number) {
            number.textContent = `Item #${index + 1}`;
        }
    });
}

function calculateItemTotal(itemId) {
    const quantity = parseFloat(document.querySelector(`input[name="items[${itemId}][quantity]"]`).value) || 0;
    const unitPrice = parseFloat(document.querySelector(`input[name="items[${itemId}][unit_price]"]`).value) || 0;
    const total = quantity * unitPrice;
    document.getElementById(`amount-${itemId}`).value = total.toFixed(2);
    updateSummary();
}

function updateSummary() {
    const items = document.querySelectorAll('.item-card');
    let total = 0;
    let count = 0;
    
    items.forEach(item => {
        const amountInput = item.querySelector('input[name*="[amount]"]');
        if (amountInput && amountInput.value) {
            total += parseFloat(amountInput.value) || 0;
            count++;
        }
    });
    
    document.getElementById('totalItems').textContent = count;
    document.getElementById('totalAmount').textContent = `TSH ${total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
}

// Initialize with one item
document.addEventListener('DOMContentLoaded', function() {
    addItem();
});

// Form validation
document.getElementById('claimForm').addEventListener('submit', function(e) {
    const items = document.querySelectorAll('.item-card');
    if (items.length === 0) {
        e.preventDefault();
        alert('Please add at least one expense item');
        return false;
    }
    
    let hasValidItem = false;
    items.forEach(item => {
        const amountInput = item.querySelector('input[name*="[amount]"]');
        if (amountInput && parseFloat(amountInput.value) > 0) {
            hasValidItem = true;
        }
    });
    
    if (!hasValidItem) {
        e.preventDefault();
        alert('Please enter at least one expense item with an amount greater than zero');
        return false;
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>