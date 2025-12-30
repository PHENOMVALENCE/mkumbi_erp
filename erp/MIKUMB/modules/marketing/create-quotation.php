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

// Fetch customers
try {
    $customers_query = "SELECT customer_id, full_name, phone, email FROM customers WHERE company_id = ? ORDER BY full_name";
    $stmt = $conn->prepare($customers_query);
    $stmt->execute([$company_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Fetch qualified leads
try {
    $leads_query = "SELECT lead_id, company_name, contact_person, email, phone 
                    FROM leads 
                    WHERE company_id = ? AND status IN ('qualified', 'contacted')
                    ORDER BY company_name";
    $stmt = $conn->prepare($leads_query);
    $stmt->execute([$company_id]);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $leads = [];
}

// If lead_id is provided, fetch lead details
$selected_lead = null;
if (!empty($_GET['lead_id'])) {
    try {
        $lead_query = "SELECT * FROM leads WHERE lead_id = ? AND company_id = ?";
        $stmt = $conn->prepare($lead_query);
        $stmt->execute([$_GET['lead_id'], $company_id]);
        $selected_lead = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $selected_lead = null;
    }
}

$page_title = 'Create Quotation';
require_once '../../includes/header.php';
?>

<style>
.quotation-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.form-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    border-left: 4px solid #28a745;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
}

.section-title i {
    margin-right: 10px;
    color: #28a745;
}

.alert-info-custom {
    background: #d1ecf1;
    border: 1px solid #0c5460;
    border-left: 4px solid #17a2b8;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.customer-details {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    display: none;
}

.info-row {
    display: flex;
    padding: 0.5rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    width: 120px;
    flex-shrink: 0;
}

.info-value {
    color: #212529;
    flex: 1;
}

.items-section {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 2px solid #e9ecef;
}

.item-row {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    border: 1px solid #dee2e6;
}

.item-row-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #dee2e6;
}

.item-number {
    font-weight: 700;
    color: #28a745;
    font-size: 1.1rem;
}

.btn-remove-item {
    color: #dc3545;
    cursor: pointer;
    font-size: 1.2rem;
}

.btn-remove-item:hover {
    color: #a71d2a;
}

.calculation-summary {
    background: linear-gradient(135deg, #56ab2f 0%, #a8e063 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 8px;
    margin-top: 1rem;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.summary-row:last-child {
    border-bottom: none;
    font-size: 1.3rem;
    font-weight: 700;
    margin-top: 0.5rem;
    padding-top: 1rem;
    border-top: 2px solid rgba(255,255,255,0.3);
}

#addItemBtn {
    border: 2px dashed #28a745;
    background: #f8f9fa;
    color: #28a745;
    padding: 1rem;
    text-align: center;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
}

#addItemBtn:hover {
    background: #e8f5e9;
    border-color: #1e7e34;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice text-success me-2"></i>Create Quotation
                </h1>
                <p class="text-muted small mb-0 mt-1">Create a new sales quotation</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="quotations.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Quotations
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-lg-10">

                <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php 
                    echo htmlspecialchars($_SESSION['error_message']); 
                    unset($_SESSION['error_message']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="quotation-card">
                    <form action="process-quotation.php" method="POST" id="quotationForm">
                        
                        <!-- Quotation Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Quotation Information
                            </div>
                            
                            <div class="alert-info-custom">
                                <i class="fas fa-lightbulb me-2"></i>
                                <strong>Tip:</strong> Select either an existing customer or a lead. Items can be plots, vehicles, or services.
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Quotation Date *</label>
                                    <input type="date" 
                                           name="quote_date" 
                                           class="form-control" 
                                           value="<?php echo date('Y-m-d'); ?>"
                                           required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Valid Until *</label>
                                    <input type="date" 
                                           name="valid_until" 
                                           class="form-control"
                                           value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                                           min="<?php echo date('Y-m-d'); ?>"
                                           required>
                                    <small class="text-muted">Quotation expiry date</small>
                                </div>
                            </div>
                        </div>

                        <!-- Customer/Lead Selection -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-user"></i>
                                Customer / Lead Information
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Select Customer</label>
                                    <select name="customer_id" id="customerSelect" class="form-select">
                                        <option value="">-- Select Customer --</option>
                                        <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo $customer['customer_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($customer['full_name']); ?>"
                                                data-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                                data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($customer['full_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Or Select Lead</label>
                                    <select name="lead_id" id="leadSelect" class="form-select">
                                        <option value="">-- Select Lead --</option>
                                        <?php foreach ($leads as $lead): ?>
                                        <option value="<?php echo $lead['lead_id']; ?>"
                                                <?php echo ($selected_lead && $selected_lead['lead_id'] == $lead['lead_id']) ? 'selected' : ''; ?>
                                                data-name="<?php echo htmlspecialchars($lead['company_name']); ?>"
                                                data-contact="<?php echo htmlspecialchars($lead['contact_person']); ?>"
                                                data-phone="<?php echo htmlspecialchars($lead['phone'] ?? ''); ?>"
                                                data-email="<?php echo htmlspecialchars($lead['email'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($lead['company_name']); ?> - <?php echo htmlspecialchars($lead['contact_person']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div id="customerDetails" class="customer-details" <?php echo $selected_lead ? 'style="display:block;"' : ''; ?>>
                                <div class="info-row">
                                    <div class="info-label">Name:</div>
                                    <div class="info-value" id="detailName">
                                        <?php echo $selected_lead ? htmlspecialchars($selected_lead['company_name']) : ''; ?>
                                    </div>
                                </div>
                                <div class="info-row" id="contactRow" style="<?php echo $selected_lead ? '' : 'display:none;'; ?>">
                                    <div class="info-label">Contact Person:</div>
                                    <div class="info-value" id="detailContact">
                                        <?php echo $selected_lead ? htmlspecialchars($selected_lead['contact_person']) : ''; ?>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Phone:</div>
                                    <div class="info-value" id="detailPhone">
                                        <?php echo $selected_lead ? htmlspecialchars($selected_lead['phone']) : ''; ?>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Email:</div>
                                    <div class="info-value" id="detailEmail">
                                        <?php echo $selected_lead ? htmlspecialchars($selected_lead['email']) : ''; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Items Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-list"></i>
                                Quotation Items
                            </div>

                            <div id="itemsContainer">
                                <!-- Item rows will be added here dynamically -->
                            </div>

                            <button type="button" id="addItemBtn" class="btn btn-outline-success w-100">
                                <i class="fas fa-plus-circle me-2"></i> Add Item
                            </button>

                            <!-- Summary -->
                            <div class="calculation-summary" id="summarySection" style="display: none;">
                                <h5 class="mb-3">Quotation Summary</h5>
                                <div id="summaryContent"></div>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-sticky-note"></i>
                                Additional Information
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Terms & Conditions</label>
                                <textarea name="terms_conditions" 
                                          class="form-control" 
                                          rows="4" 
                                          placeholder="Payment terms, delivery terms, warranties...">1. Prices are valid for <?php echo date('d'); ?> days from quotation date.
2. Payment terms: 50% deposit, balance on delivery.
3. Delivery within 14 days of order confirmation.
4. Prices are subject to change without notice.</textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Notes / Special Instructions</label>
                                <textarea name="notes" 
                                          class="form-control" 
                                          rows="3" 
                                          placeholder="Additional notes, special requirements..."></textarea>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="saveAsDraft" name="save_as_draft" value="1">
                                <label class="form-check-label" for="saveAsDraft">
                                    Save as draft (you can send it later)
                                </label>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="quotations.php" class="btn btn-secondary">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-paper-plane me-1"></i> Create Quotation
                            </button>
                        </div>

                    </form>
                </div>

            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let itemCount = 0;
    const itemsContainer = document.getElementById('itemsContainer');
    const addItemBtn = document.getElementById('addItemBtn');
    const summarySection = document.getElementById('summarySection');
    const customerSelect = document.getElementById('customerSelect');
    const leadSelect = document.getElementById('leadSelect');
    const customerDetails = document.getElementById('customerDetails');

    // Add first item automatically
    addItem();

    // Handle customer selection
    customerSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        
        if (this.value) {
            // Clear lead selection
            leadSelect.value = '';
            
            document.getElementById('detailName').textContent = selectedOption.dataset.name || '-';
            document.getElementById('detailPhone').textContent = selectedOption.dataset.phone || '-';
            document.getElementById('detailEmail').textContent = selectedOption.dataset.email || '-';
            document.getElementById('contactRow').style.display = 'none';
            customerDetails.style.display = 'block';
        } else if (!leadSelect.value) {
            customerDetails.style.display = 'none';
        }
    });

    // Handle lead selection
    leadSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        
        if (this.value) {
            // Clear customer selection
            customerSelect.value = '';
            
            document.getElementById('detailName').textContent = selectedOption.dataset.name || '-';
            document.getElementById('detailContact').textContent = selectedOption.dataset.contact || '-';
            document.getElementById('detailPhone').textContent = selectedOption.dataset.phone || '-';
            document.getElementById('detailEmail').textContent = selectedOption.dataset.email || '-';
            document.getElementById('contactRow').style.display = 'flex';
            customerDetails.style.display = 'block';
        } else if (!customerSelect.value) {
            customerDetails.style.display = 'none';
        }
    });

    // Trigger display if lead is preselected
    if (leadSelect.value) {
        leadSelect.dispatchEvent(new Event('change'));
    }

    addItemBtn.addEventListener('click', function() {
        addItem();
    });

    function addItem() {
        itemCount++;
        const itemRow = document.createElement('div');
        itemRow.className = 'item-row';
        itemRow.id = `item-${itemCount}`;
        itemRow.innerHTML = `
            <div class="item-row-header">
                <span class="item-number">Item #${itemCount}</span>
                <span class="btn-remove-item" onclick="removeItem(${itemCount})">
                    <i class="fas fa-times-circle"></i>
                </span>
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    <label class="form-label fw-bold">Item Description *</label>
                    <textarea name="items[${itemCount}][description]" 
                              class="form-control" 
                              rows="2" 
                              placeholder="Describe the item (plot, vehicle, service, etc.)..."
                              required></textarea>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Quantity *</label>
                    <input type="number" 
                           name="items[${itemCount}][quantity]" 
                           class="form-control item-quantity" 
                           min="1"
                           step="0.01"
                           value="1"
                           placeholder="0"
                           data-item="${itemCount}"
                           required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Unit</label>
                    <select name="items[${itemCount}][unit]" class="form-select">
                        <option value="unit" selected>Unit(s)</option>
                        <option value="plot">Plot(s)</option>
                        <option value="vehicle">Vehicle(s)</option>
                        <option value="sqm">Square Meters</option>
                        <option value="acre">Acre(s)</option>
                        <option value="service">Service</option>
                        <option value="month">Month(s)</option>
                        <option value="year">Year(s)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Unit Price *</label>
                    <div class="input-group">
                        <span class="input-group-text">TSH</span>
                        <input type="number" 
                               name="items[${itemCount}][unit_price]" 
                               class="form-control item-price" 
                               min="0"
                               step="0.01"
                               placeholder="0.00"
                               data-item="${itemCount}"
                               required>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Total</label>
                    <div class="input-group">
                        <span class="input-group-text">TSH</span>
                        <input type="text" 
                               class="form-control item-total" 
                               id="total-${itemCount}"
                               readonly
                               placeholder="0.00">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <label class="form-label fw-bold">Additional Details</label>
                    <textarea name="items[${itemCount}][details]" 
                              class="form-control" 
                              rows="2" 
                              placeholder="Specifications, features, location details..."></textarea>
                </div>
            </div>
        `;

        itemsContainer.appendChild(itemRow);
        attachCalculationListeners();
    }

    window.removeItem = function(itemId) {
        if (document.querySelectorAll('.item-row').length <= 1) {
            alert('You must have at least one item in the quotation!');
            return;
        }

        if (confirm('Are you sure you want to remove this item?')) {
            const itemRow = document.getElementById(`item-${itemId}`);
            if (itemRow) {
                itemRow.remove();
                updateSummary();
                renumberItems();
            }
        }
    };

    function renumberItems() {
        const items = document.querySelectorAll('.item-row');
        items.forEach((item, index) => {
            const itemNumber = item.querySelector('.item-number');
            if (itemNumber) {
                itemNumber.textContent = `Item #${index + 1}`;
            }
        });
    }

    function attachCalculationListeners() {
        const quantityInputs = document.querySelectorAll('.item-quantity');
        const priceInputs = document.querySelectorAll('.item-price');

        quantityInputs.forEach(input => {
            input.addEventListener('input', function() {
                calculateItemTotal(this.dataset.item);
            });
        });

        priceInputs.forEach(input => {
            input.addEventListener('input', function() {
                calculateItemTotal(this.dataset.item);
            });
        });
    }

    function calculateItemTotal(itemId) {
        const quantity = parseFloat(document.querySelector(`input[name="items[${itemId}][quantity]"]`).value) || 0;
        const price = parseFloat(document.querySelector(`input[name="items[${itemId}][unit_price]"]`).value) || 0;
        const total = quantity * price;

        const totalInput = document.getElementById(`total-${itemId}`);
        if (totalInput) {
            totalInput.value = total.toFixed(2);
        }

        updateSummary();
    }

    function updateSummary() {
        const items = document.querySelectorAll('.item-row');
        let subtotal = 0;
        let itemsCount = 0;

        items.forEach(item => {
            const totalInput = item.querySelector('.item-total');
            if (totalInput && totalInput.value) {
                const itemTotal = parseFloat(totalInput.value) || 0;
                subtotal += itemTotal;
                itemsCount++;
            }
        });

        const taxRate = 0; // Can be made dynamic
        const taxAmount = subtotal * taxRate;
        const grandTotal = subtotal + taxAmount;

        if (itemsCount > 0) {
            const summaryContent = document.getElementById('summaryContent');
            summaryContent.innerHTML = `
                <div class="summary-row">
                    <span>Number of Items:</span>
                    <span>${items.length}</span>
                </div>
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span>TSH ${subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
                ${taxRate > 0 ? `
                <div class="summary-row">
                    <span>Tax (${(taxRate * 100).toFixed(0)}%):</span>
                    <span>TSH ${taxAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
                ` : ''}
                <div class="summary-row">
                    <span>Total Amount:</span>
                    <span>TSH ${grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
            `;
            summarySection.style.display = 'block';
        } else {
            summarySection.style.display = 'none';
        }
    }

    // Form validation
    document.getElementById('quotationForm').addEventListener('submit', function(e) {
        const customerSelected = customerSelect.value;
        const leadSelected = leadSelect.value;

        if (!customerSelected && !leadSelected) {
            e.preventDefault();
            alert('Please select either a customer or a lead!');
            return false;
        }

        const items = document.querySelectorAll('.item-row');
        if (items.length === 0) {
            e.preventDefault();
            alert('Please add at least one item to the quotation!');
            return false;
        }

        return confirm('Are you sure you want to create this quotation?');
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>