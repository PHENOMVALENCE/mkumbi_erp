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

// Fetch active tax types
try {
    $tax_types_query = "SELECT tax_type_id, tax_name, tax_code, tax_rate FROM tax_types WHERE company_id = ? AND is_active = 1 ORDER BY tax_name";
    $stmt = $conn->prepare($tax_types_query);
    $stmt->execute([$company_id]);
    $tax_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching tax types: " . $e->getMessage());
    $tax_types = [];
}

// Fetch customers for sales tax
try {
    $customers_query = "SELECT customer_id, full_name FROM customers WHERE company_id = ? ORDER BY full_name";
    $stmt = $conn->prepare($customers_query);
    $stmt->execute([$company_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Fetch suppliers for purchase tax
try {
    $suppliers_query = "SELECT supplier_id, supplier_name FROM suppliers WHERE company_id = ? ORDER BY supplier_name";
    $stmt = $conn->prepare($suppliers_query);
    $stmt->execute([$company_id]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
}

// Generate next transaction number
$year = date('Y');
$prefix = "TX-$year-";
try {
    $number_query = "SELECT transaction_number FROM tax_transactions WHERE company_id = ? AND transaction_number LIKE ? ORDER BY tax_transaction_id DESC LIMIT 1";
    $stmt = $conn->prepare($number_query);
    $stmt->execute([$company_id, $prefix . '%']);
    $last_number = $stmt->fetchColumn();
    
    if ($last_number) {
        $last_seq = (int)substr($last_number, -4);
        $next_seq = $last_seq + 1;
    } else {
        $next_seq = 1;
    }
    
    $transaction_number = $prefix . str_pad($next_seq, 4, '0', STR_PAD_LEFT);
} catch (PDOException $e) {
    $transaction_number = $prefix . '0001';
}

$page_title = 'Add Tax Transaction';
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

.form-section {
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 2px solid #e9ecef;
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #17a2b8;
    display: flex;
    align-items: center;
}

.section-title i {
    margin-right: 10px;
    color: #17a2b8;
}

.form-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
}

.form-label .required {
    color: #dc3545;
    margin-left: 3px;
}

.help-text {
    font-size: 0.875rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.calculation-card {
    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-top: 1.5rem;
}

.calc-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.calc-row:last-child {
    border-bottom: none;
    border-top: 2px solid rgba(255,255,255,0.5);
    padding-top: 1rem;
    margin-top: 0.5rem;
}

.calc-label {
    font-size: 0.9rem;
}

.calc-value {
    font-weight: 700;
    font-size: 1.1rem;
}

.calc-row.total .calc-value {
    font-size: 1.5rem;
}

.transaction-number {
    font-family: 'Courier New', monospace;
    font-size: 1.3rem;
    font-weight: 700;
    color: #17a2b8;
    padding: 0.5rem 1rem;
    background: #e7f6f8;
    border-radius: 6px;
    display: inline-block;
}

.info-box {
    background: #e7f6f8;
    border-left: 4px solid #17a2b8;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.party-section {
    display: none;
}

.btn-action {
    min-width: 120px;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-plus-circle text-info me-2"></i>Add Tax Transaction
                </h1>
                <p class="text-muted small mb-0 mt-1">Record a new tax collection or payment</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="transactions.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Transactions
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <div class="row">
            <!-- Form Column -->
            <div class="col-lg-8">
                <div class="form-card">
                    <form action="process-transaction.php" method="POST" id="taxTransactionForm">
                        
                        <!-- Transaction Details -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-file-invoice"></i>
                                Transaction Details
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="transaction_number" class="form-label">
                                        Transaction Number
                                    </label>
                                    <div class="transaction-number"><?php echo $transaction_number; ?></div>
                                    <input type="hidden" name="transaction_number" value="<?php echo $transaction_number; ?>">
                                    <div class="help-text">Auto-generated</div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="transaction_date" class="form-label">
                                        Transaction Date<span class="required">*</span>
                                    </label>
                                    <input type="date" 
                                           class="form-control" 
                                           id="transaction_date" 
                                           name="transaction_date" 
                                           value="<?php echo date('Y-m-d'); ?>"
                                           required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="transaction_type" class="form-label">
                                        Transaction Type<span class="required">*</span>
                                    </label>
                                    <select class="form-select" 
                                            id="transaction_type" 
                                            name="transaction_type" 
                                            required>
                                        <option value="">Select type...</option>
                                        <option value="sales">Sales Tax (Collected from customers)</option>
                                        <option value="purchase">Purchase Tax (Paid to suppliers)</option>
                                        <option value="payroll">Payroll Tax (PAYE, etc.)</option>
                                        <option value="withholding">Withholding Tax (WHT)</option>
                                        <option value="other">Other</option>
                                    </select>
                                    <div class="help-text">What type of tax transaction is this?</div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="tax_type_id" class="form-label">
                                        Tax Type<span class="required">*</span>
                                    </label>
                                    <select class="form-select" 
                                            id="tax_type_id" 
                                            name="tax_type_id" 
                                            required>
                                        <option value="">Select tax type...</option>
                                        <?php foreach ($tax_types as $tax): ?>
                                        <option value="<?php echo $tax['tax_type_id']; ?>" 
                                                data-rate="<?php echo $tax['tax_rate']; ?>">
                                            <?php echo htmlspecialchars($tax['tax_code']); ?> - <?php echo htmlspecialchars($tax['tax_name']); ?> (<?php echo number_format($tax['tax_rate'], 2); ?>%)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="help-text">VAT, WHT, PAYE, etc.</div>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label for="invoice_number" class="form-label">Invoice/Reference Number</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="invoice_number" 
                                           name="invoice_number" 
                                           placeholder="e.g., INV-2025-0001">
                                    <div class="help-text">Optional reference to invoice or document</div>
                                </div>
                            </div>
                        </div>

                        <!-- Party Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-users"></i>
                                Party Information
                            </div>

                            <div class="info-box">
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>Note:</strong> Select customer for sales tax, supplier for purchase tax
                            </div>

                            <div class="row">
                                <div class="col-md-12 mb-3 party-section" id="customerSection">
                                    <label for="customer_id" class="form-label">Customer</label>
                                    <select class="form-select" id="customer_id" name="customer_id">
                                        <option value="">Select customer...</option>
                                        <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo $customer['customer_id']; ?>">
                                            <?php echo htmlspecialchars($customer['full_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="help-text">For sales tax transactions</div>
                                </div>

                                <div class="col-md-12 mb-3 party-section" id="supplierSection">
                                    <label for="supplier_id" class="form-label">Supplier</label>
                                    <select class="form-select" id="supplier_id" name="supplier_id">
                                        <option value="">Select supplier...</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['supplier_id']; ?>">
                                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="help-text">For purchase tax transactions</div>
                                </div>
                            </div>
                        </div>

                        <!-- Amount Calculation -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-calculator"></i>
                                Amount Calculation
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="taxable_amount" class="form-label">
                                        Taxable Amount (TSH)<span class="required">*</span>
                                    </label>
                                    <input type="number" 
                                           class="form-control" 
                                           id="taxable_amount" 
                                           name="taxable_amount" 
                                           placeholder="0.00"
                                           step="0.01"
                                           min="0"
                                           required>
                                    <div class="help-text">Amount before tax</div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="tax_amount" class="form-label">
                                        Tax Amount (TSH)<span class="required">*</span>
                                    </label>
                                    <input type="number" 
                                           class="form-control" 
                                           id="tax_amount" 
                                           name="tax_amount" 
                                           placeholder="0.00"
                                           step="0.01"
                                           min="0"
                                           readonly
                                           required>
                                    <div class="help-text">Auto-calculated from rate</div>
                                </div>
                            </div>

                            <!-- Calculation Summary -->
                            <div class="calculation-card">
                                <h6 class="mb-3">
                                    <i class="fas fa-receipt me-2"></i>
                                    Calculation Summary
                                </h6>
                                <div class="calc-row">
                                    <span class="calc-label">Taxable Amount:</span>
                                    <span class="calc-value" id="displayTaxable">TSH 0.00</span>
                                </div>
                                <div class="calc-row">
                                    <span class="calc-label">Tax Rate:</span>
                                    <span class="calc-value" id="displayRate">0.00%</span>
                                </div>
                                <div class="calc-row">
                                    <span class="calc-label">Tax Amount:</span>
                                    <span class="calc-value" id="displayTax">TSH 0.00</span>
                                </div>
                                <div class="calc-row total">
                                    <span class="calc-label">Total Amount:</span>
                                    <span class="calc-value" id="displayTotal">TSH 0.00</span>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Additional Information
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="pending" selected>Pending</option>
                                        <option value="filed">Filed</option>
                                        <option value="paid">Paid</option>
                                    </select>
                                    <div class="help-text">Current status of this transaction</div>
                                </div>

                                <div class="col-md-6 mb-3" id="paymentDateSection" style="display: none;">
                                    <label for="payment_date" class="form-label">Payment Date</label>
                                    <input type="date" class="form-control" id="payment_date" name="payment_date">
                                    <div class="help-text">Date when tax was paid</div>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" 
                                              id="description" 
                                              name="description" 
                                              rows="3"
                                              placeholder="Additional details about this transaction..."></textarea>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label for="remarks" class="form-label">Remarks</label>
                                    <textarea class="form-control" 
                                              id="remarks" 
                                              name="remarks" 
                                              rows="2"
                                              placeholder="Internal notes or comments..."></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="transactions.php" class="btn btn-outline-secondary btn-action">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-info btn-action">
                                <i class="fas fa-save me-1"></i> Save Transaction
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Help Column -->
            <div class="col-lg-4">
                <div class="form-card">
                    <h5 class="mb-4">
                        <i class="fas fa-question-circle text-info me-2"></i>
                        Transaction Types
                    </h5>

                    <div class="info-box">
                        <strong>Sales Tax</strong>
                        <p class="mb-0 small mt-1">Tax collected from customers when selling goods or services. This increases your tax liability.</p>
                    </div>

                    <div class="info-box" style="border-left-color: #007bff;">
                        <strong>Purchase Tax</strong>
                        <p class="mb-0 small mt-1">Tax paid to suppliers when purchasing goods or services. This reduces your net tax liability.</p>
                    </div>

                    <div class="info-box" style="border-left-color: #ffc107;">
                        <strong>Payroll Tax</strong>
                        <p class="mb-0 small mt-1">Tax deducted from employee salaries (PAYE, NHIF, etc.). Must be remitted to tax authority.</p>
                    </div>

                    <div class="info-box" style="border-left-color: #28a745;">
                        <strong>Withholding Tax</strong>
                        <p class="mb-0 small mt-1">Tax withheld from payments to suppliers for professional services. Must be remitted to TRA.</p>
                    </div>

                    <div class="alert alert-warning mt-3">
                        <small>
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <strong>Important:</strong> Tax amounts are auto-calculated based on the tax rate. Ensure you select the correct tax type.
                        </small>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const transactionTypeSelect = document.getElementById('transaction_type');
    const taxTypeSelect = document.getElementById('tax_type_id');
    const taxableAmountInput = document.getElementById('taxable_amount');
    const taxAmountInput = document.getElementById('tax_amount');
    const statusSelect = document.getElementById('status');
    const paymentDateSection = document.getElementById('paymentDateSection');
    const customerSection = document.getElementById('customerSection');
    const supplierSection = document.getElementById('supplierSection');
    
    // Display elements
    const displayTaxable = document.getElementById('displayTaxable');
    const displayRate = document.getElementById('displayRate');
    const displayTax = document.getElementById('displayTax');
    const displayTotal = document.getElementById('displayTotal');

    // Show/hide party sections based on transaction type
    transactionTypeSelect.addEventListener('change', function() {
        const type = this.value;
        
        customerSection.style.display = 'none';
        supplierSection.style.display = 'none';
        
        if (type === 'sales') {
            customerSection.style.display = 'block';
        } else if (type === 'purchase') {
            supplierSection.style.display = 'block';
        }
    });

    // Show payment date when status is 'paid'
    statusSelect.addEventListener('change', function() {
        if (this.value === 'paid') {
            paymentDateSection.style.display = 'block';
        } else {
            paymentDateSection.style.display = 'none';
        }
    });

    // Auto-calculate tax
    function calculateTax() {
        const taxableAmount = parseFloat(taxableAmountInput.value) || 0;
        const selectedOption = taxTypeSelect.options[taxTypeSelect.selectedIndex];
        const taxRate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
        
        const taxAmount = (taxableAmount * taxRate) / 100;
        const totalAmount = taxableAmount + taxAmount;
        
        taxAmountInput.value = taxAmount.toFixed(2);
        
        // Update display
        displayTaxable.textContent = 'TSH ' + taxableAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        displayRate.textContent = taxRate.toFixed(2) + '%';
        displayTax.textContent = 'TSH ' + taxAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        displayTotal.textContent = 'TSH ' + totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    taxableAmountInput.addEventListener('input', calculateTax);
    taxTypeSelect.addEventListener('change', calculateTax);

    // Form validation
    const form = document.getElementById('taxTransactionForm');
    form.addEventListener('submit', function(e) {
        const taxableAmount = parseFloat(taxableAmountInput.value);
        const taxAmount = parseFloat(taxAmountInput.value);
        
        if (!taxableAmount || taxableAmount <= 0) {
            e.preventDefault();
            alert('Please enter a valid taxable amount');
            return false;
        }
        
        if (!taxAmount || taxAmount <= 0) {
            e.preventDefault();
            alert('Tax amount must be greater than zero. Please select a tax type.');
            return false;
        }

        // Confirm before submit
        const message = `Record this tax transaction?\n\nTaxable: TSH ${taxableAmount.toFixed(2)}\nTax: TSH ${taxAmount.toFixed(2)}\nTotal: TSH ${(taxableAmount + taxAmount).toFixed(2)}\n\nContinue?`;
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>