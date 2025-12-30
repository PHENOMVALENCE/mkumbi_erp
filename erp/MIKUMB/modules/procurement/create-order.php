<?php
define('APP_ACCESS', true);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

$errors = [];
$success = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['supplier_id'])) {
            $errors[] = "Please select a supplier";
        }
        if (empty($_POST['po_date'])) {
            $errors[] = "Please enter order date";
        }
        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            $errors[] = "Please add at least one item";
        }
        
        if (empty($errors)) {
            // Start transaction
            $conn->beginTransaction();
            
            // Generate PO number
            $po_number = 'PO-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Calculate totals
            $subtotal = 0;
            foreach ($_POST['items'] as $item) {
                if (!empty($item['description']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                    $subtotal += $item['quantity'] * $item['unit_price'];
                }
            }
            
            $tax_amount = isset($_POST['tax_amount']) ? floatval($_POST['tax_amount']) : 0;
            $total_amount = $subtotal + $tax_amount;
            
            // Insert purchase order
            $insert_po = "INSERT INTO purchase_orders (
                            company_id, po_number, po_date, delivery_date,
                            supplier_id, payment_terms, delivery_terms,
                            subtotal, tax_amount, total_amount, status, notes,
                            created_by, created_at
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($insert_po);
            $stmt->execute([
                $company_id,
                $po_number,
                $_POST['po_date'],
                !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null,
                $_POST['supplier_id'],
                !empty($_POST['payment_terms']) ? $_POST['payment_terms'] : null,
                !empty($_POST['delivery_terms']) ? $_POST['delivery_terms'] : null,
                $subtotal,
                $tax_amount,
                $total_amount,
                $_POST['status'] ?? 'draft',
                !empty($_POST['notes']) ? $_POST['notes'] : null,
                $_SESSION['user_id']
            ]);
            
            $purchase_order_id = $conn->lastInsertId();
            
            // Insert purchase order items
            $insert_item = "INSERT INTO purchase_order_items (
                                purchase_order_id, item_description, quantity,
                                unit_of_measure, unit_price, created_at
                            ) VALUES (?, ?, ?, ?, ?, NOW())";
            
            $stmt_item = $conn->prepare($insert_item);
            
            foreach ($_POST['items'] as $item) {
                if (!empty($item['description']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                    $stmt_item->execute([
                        $purchase_order_id,
                        $item['description'],
                        $item['quantity'],
                        !empty($item['unit']) ? $item['unit'] : 'pcs',
                        $item['unit_price']
                    ]);
                }
            }
            
            $conn->commit();
            
            $success[] = "Purchase order {$po_number} created successfully!";
            
            // Redirect after 2 seconds
            header("refresh:2;url=orders.php");
            
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $errors[] = "Error creating purchase order: " . $e->getMessage();
    }
}

// Fetch suppliers
try {
    $suppliers_sql = "SELECT supplier_id, supplier_name, contact_person, phone, email, payment_terms 
                      FROM suppliers 
                      WHERE company_id = ? AND is_active = 1 
                      ORDER BY supplier_name";
    $stmt = $conn->prepare($suppliers_sql);
    $stmt->execute([$company_id]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
    $errors[] = "Error fetching suppliers: " . $e->getMessage();
}

$page_title = 'Create Purchase Order';
require_once '../../includes/header.php';
?>

<style>
.form-card {
    background: white;
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.form-section-title {
    font-size: 16px;
    font-weight: 700;
    color: #495057;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.item-row {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
    border-left: 4px solid #0d6efd;
}

.item-row.highlight-new {
    animation: highlightFade 2s ease-in-out;
}

@keyframes highlightFade {
    0% { background: #d1e7dd; }
    100% { background: #f8f9fa; }
}

.btn-remove-item {
    background: #dc3545;
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-remove-item:hover {
    background: #bb2d3b;
    transform: scale(1.05);
}

.totals-box {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border-left: 4px solid #28a745;
}

.totals-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    font-size: 14px;
}

.totals-row.grand-total {
    font-size: 18px;
    font-weight: 700;
    color: #28a745;
    padding-top: 10px;
    border-top: 2px solid #dee2e6;
    margin-top: 10px;
}

.supplier-info-box {
    background: #e7f1ff;
    padding: 15px;
    border-radius: 8px;
    margin-top: 10px;
    display: none;
}

.supplier-info-box.show {
    display: block;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-plus-circle"></i> Create Purchase Order</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="orders.php">Purchase Orders</a></li>
                    <li class="breadcrumb-item active">Create Order</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-ban"></i> Error!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-check"></i> Success!</h5>
            <ul class="mb-0">
                <?php foreach ($success as $msg): ?>
                    <li><?php echo htmlspecialchars($msg); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" id="orderForm">
            
            <!-- Order Information -->
            <div class="form-card">
                <div class="form-section-title">
                    <i class="fas fa-file-alt"></i> Order Information
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label required-field">Order Date</label>
                        <input type="date" name="po_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Expected Delivery Date</label>
                        <input type="date" name="delivery_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="draft">Draft</option>
                            <option value="submitted">Submit for Approval</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Supplier Information -->
            <div class="form-card">
                <div class="form-section-title">
                    <i class="fas fa-building"></i> Supplier Information
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required-field">Select Supplier</label>
                        <select name="supplier_id" id="supplierSelect" class="form-select" required>
                            <option value="">-- Select Supplier --</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['supplier_id']; ?>" 
                                        data-contact="<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>"
                                        data-phone="<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>"
                                        data-email="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>"
                                        data-terms="<?php echo htmlspecialchars($supplier['payment_terms'] ?? 'net_30'); ?>">
                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Payment Terms</label>
                        <input type="text" name="payment_terms" id="paymentTerms" class="form-control" placeholder="e.g., Net 30, Net 60">
                    </div>
                </div>
                
                <div id="supplierInfo" class="supplier-info-box">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Contact Person:</strong>
                            <p id="supplierContact" class="mb-0">-</p>
                        </div>
                        <div class="col-md-4">
                            <strong>Phone:</strong>
                            <p id="supplierPhone" class="mb-0">-</p>
                        </div>
                        <div class="col-md-4">
                            <strong>Email:</strong>
                            <p id="supplierEmail" class="mb-0">-</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Items -->
            <div class="form-card">
                <div class="form-section-title">
                    <i class="fas fa-list"></i> Order Items
                </div>
                
                <div id="itemsContainer">
                    <!-- Item rows will be added here -->
                </div>
                
                <button type="button" class="btn btn-primary" onclick="addItemRow()">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </div>

            <!-- Totals -->
            <div class="form-card">
                <div class="form-section-title">
                    <i class="fas fa-calculator"></i> Order Totals
                </div>
                
                <div class="row">
                    <div class="col-md-8"></div>
                    <div class="col-md-4">
                        <div class="totals-box">
                            <div class="totals-row">
                                <span>Subtotal:</span>
                                <strong id="subtotalDisplay">TZS 0</strong>
                            </div>
                            <div class="totals-row">
                                <span>Tax/VAT:</span>
                                <div>
                                    <input type="number" name="tax_amount" id="taxAmount" 
                                           class="form-control form-control-sm" 
                                           placeholder="0" 
                                           step="0.01" 
                                           min="0"
                                           style="width: 150px; display: inline-block;"
                                           onchange="calculateTotals()">
                                </div>
                            </div>
                            <div class="totals-row grand-total">
                                <span>GRAND TOTAL:</span>
                                <strong id="grandTotalDisplay">TZS 0</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Information -->
            <div class="form-card">
                <div class="form-section-title">
                    <i class="fas fa-info-circle"></i> Additional Information
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Delivery Terms</label>
                        <input type="text" name="delivery_terms" class="form-control" placeholder="e.g., FOB, CIF, DDP">
                    </div>
                    
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Notes / Special Instructions</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Enter any special instructions or notes"></textarea>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="form-card">
                <div class="row">
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-save"></i> Create Purchase Order
                        </button>
                        <a href="orders.php" class="btn btn-secondary btn-lg">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </div>
            </div>

        </form>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let itemCounter = 0;

// Add item row
function addItemRow() {
    itemCounter++;
    const container = document.getElementById('itemsContainer');
    
    const itemRow = document.createElement('div');
    itemRow.className = 'item-row highlight-new';
    itemRow.id = 'itemRow' + itemCounter;
    
    itemRow.innerHTML = `
        <div class="row align-items-end">
            <div class="col-md-4 mb-2">
                <label class="form-label">Item Description</label>
                <textarea name="items[${itemCounter}][description]" 
                          class="form-control" 
                          rows="2" 
                          placeholder="Enter item description"
                          required></textarea>
            </div>
            
            <div class="col-md-2 mb-2">
                <label class="form-label">Quantity</label>
                <input type="number" 
                       name="items[${itemCounter}][quantity]" 
                       class="form-control item-quantity" 
                       placeholder="0" 
                       step="0.01" 
                       min="0"
                       onchange="calculateTotals()"
                       required>
            </div>
            
            <div class="col-md-2 mb-2">
                <label class="form-label">Unit</label>
                <select name="items[${itemCounter}][unit]" class="form-select">
                    <option value="pcs">Pieces</option>
                    <option value="kg">Kilograms</option>
                    <option value="ltr">Liters</option>
                    <option value="mtr">Meters</option>
                    <option value="box">Box</option>
                    <option value="pkt">Packet</option>
                    <option value="set">Set</option>
                    <option value="unit">Unit</option>
                </select>
            </div>
            
            <div class="col-md-2 mb-2">
                <label class="form-label">Unit Price (TZS)</label>
                <input type="number" 
                       name="items[${itemCounter}][unit_price]" 
                       class="form-control item-price" 
                       placeholder="0" 
                       step="0.01" 
                       min="0"
                       onchange="calculateTotals()"
                       required>
            </div>
            
            <div class="col-md-1 mb-2">
                <label class="form-label">Line Total</label>
                <div class="line-total" id="lineTotal${itemCounter}">TZS 0</div>
            </div>
            
            <div class="col-md-1 mb-2">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn-remove-item" onclick="removeItemRow(${itemCounter})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
    
    container.appendChild(itemRow);
}

// Remove item row
function removeItemRow(id) {
    const row = document.getElementById('itemRow' + id);
    if (row) {
        row.remove();
        calculateTotals();
    }
}

// Calculate totals
function calculateTotals() {
    let subtotal = 0;
    
    // Calculate each line total
    const itemRows = document.querySelectorAll('.item-row');
    itemRows.forEach((row, index) => {
        const quantity = parseFloat(row.querySelector('.item-quantity')?.value || 0);
        const price = parseFloat(row.querySelector('.item-price')?.value || 0);
        const lineTotal = quantity * price;
        
        const lineTotalDisplay = row.querySelector('.line-total');
        if (lineTotalDisplay) {
            lineTotalDisplay.textContent = 'TZS ' + lineTotal.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2});
        }
        
        subtotal += lineTotal;
    });
    
    // Update subtotal
    document.getElementById('subtotalDisplay').textContent = 'TZS ' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2});
    
    // Calculate grand total
    const taxAmount = parseFloat(document.getElementById('taxAmount')?.value || 0);
    const grandTotal = subtotal + taxAmount;
    
    document.getElementById('grandTotalDisplay').textContent = 'TZS ' + grandTotal.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2});
}

// Handle supplier selection
document.getElementById('supplierSelect').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    
    if (this.value) {
        // Show supplier info
        document.getElementById('supplierInfo').classList.add('show');
        
        // Populate supplier details
        document.getElementById('supplierContact').textContent = selected.dataset.contact || '-';
        document.getElementById('supplierPhone').textContent = selected.dataset.phone || '-';
        document.getElementById('supplierEmail').textContent = selected.dataset.email || '-';
        
        // Set payment terms
        document.getElementById('paymentTerms').value = selected.dataset.terms || 'net_30';
    } else {
        // Hide supplier info
        document.getElementById('supplierInfo').classList.remove('show');
    }
});

// Add first item row on page load
document.addEventListener('DOMContentLoaded', function() {
    addItemRow();
});

// Auto-dismiss alerts
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        bsAlert.close();
    });
}, 5000);

// Form validation
document.getElementById('orderForm').addEventListener('submit', function(e) {
    const itemRows = document.querySelectorAll('.item-row');
    if (itemRows.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the purchase order');
        return false;
    }
    
    // Check if at least one item has data
    let hasValidItem = false;
    itemRows.forEach(row => {
        const description = row.querySelector('textarea[name*="[description]"]')?.value;
        const quantity = row.querySelector('input[name*="[quantity]"]')?.value;
        const price = row.querySelector('input[name*="[unit_price]"]')?.value;
        
        if (description && quantity && price) {
            hasValidItem = true;
        }
    });
    
    if (!hasValidItem) {
        e.preventDefault();
        alert('Please fill in at least one complete item (description, quantity, and price)');
        return false;
    }
});
</script>