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
        if (empty($_POST['requisition_date'])) {
            $errors[] = "Please enter requisition date";
        }
        if (empty($_POST['required_date'])) {
            $errors[] = "Please enter required date";
        }
        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            $errors[] = "Please add at least one item";
        }
        
        if (empty($errors)) {
            // Start transaction
            $conn->beginTransaction();
            
            // Generate requisition number
            $req_number = 'REQ-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Insert purchase requisition
            $insert_req = "INSERT INTO purchase_requisitions (
                            company_id, requisition_number, requisition_date, 
                            required_date, department_id, requested_by, purpose, 
                            status, created_by, created_at
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($insert_req);
            $stmt->execute([
                $company_id,
                $req_number,
                $_POST['requisition_date'],
                $_POST['required_date'],
                !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                $_SESSION['user_id'],
                !empty($_POST['purpose']) ? $_POST['purpose'] : null,
                $_POST['status'] ?? 'draft',
                $_SESSION['user_id']
            ]);
            
            $requisition_id = $conn->lastInsertId();
            
            // Insert requisition items
            $insert_item = "INSERT INTO requisition_items (
                                requisition_id, item_description, quantity,
                                unit_of_measure, estimated_unit_price, specifications,
                                created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt_item = $conn->prepare($insert_item);
            
            foreach ($_POST['items'] as $item) {
                if (!empty($item['description']) && !empty($item['quantity'])) {
                    $stmt_item->execute([
                        $requisition_id,
                        $item['description'],
                        $item['quantity'],
                        !empty($item['unit']) ? $item['unit'] : 'pcs',
                        !empty($item['estimated_price']) ? $item['estimated_price'] : null,
                        !empty($item['specifications']) ? $item['specifications'] : null
                    ]);
                }
            }
            
            $conn->commit();
            
            $success[] = "Purchase requisition {$req_number} created successfully!";
            
            // Redirect after 2 seconds
            header("refresh:2;url=requisitions.php");
            
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $errors[] = "Error creating requisition: " . $e->getMessage();
    }
}

// Fetch departments
try {
    $departments_sql = "SELECT department_id, department_name, department_code 
                        FROM departments 
                        WHERE company_id = ? AND is_active = 1 
                        ORDER BY department_name";
    $stmt = $conn->prepare($departments_sql);
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

$page_title = 'Create Purchase Requisition';
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
    border-left: 4px solid #6c757d;
}

.item-row.highlight-new {
    animation: highlightFade 2s ease-in-out;
}

@keyframes highlightFade {
    0% { background: #fff3cd; }
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

.info-box {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.info-box i {
    color: #0c5460;
    margin-right: 10px;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.estimated-total-box {
    background: #e7f1ff;
    padding: 15px;
    border-radius: 8px;
    margin-top: 20px;
    border-left: 4px solid #0d6efd;
}

.estimated-total-row {
    display: flex;
    justify-content: space-between;
    font-size: 18px;
    font-weight: 700;
    color: #0d6efd;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-file-alt"></i> Create Purchase Requisition</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="requisitions.php">Requisitions</a></li>
                    <li class="breadcrumb-item active">Create Requisition</li>
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

        <!-- Info Box -->
        <div class="info-box">
            <i class="fas fa-info-circle fa-2x"></i>
            <strong>Purchase Requisition:</strong> A purchase requisition is an internal document used to request the purchase of goods or services. 
            Once approved, it can be converted to a purchase order.
        </div>

        <form method="POST" id="requisitionForm">
            
            <!-- Requisition Information -->
            <div class="form-card">
                <div class="form-section-title">
                    <i class="fas fa-calendar-alt"></i> Requisition Information
                </div>
                
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label required-field">Requisition Date</label>
                        <input type="date" name="requisition_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label class="form-label required-field">Required By Date</label>
                        <input type="date" name="required_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-select">
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>">
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                    <?php if ($dept['department_code']): ?>
                                        (<?php echo htmlspecialchars($dept['department_code']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="draft">Save as Draft</option>
                            <option value="submitted">Submit for Approval</option>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Purpose / Justification</label>
                        <textarea name="purpose" class="form-control" rows="3" placeholder="Explain the purpose and justification for this requisition"></textarea>
                    </div>
                </div>
            </div>

            <!-- Requisition Items -->
            <div class="form-card">
                <div class="form-section-title">
                    <i class="fas fa-list"></i> Requested Items
                </div>
                
                <div id="itemsContainer">
                    <!-- Item rows will be added here -->
                </div>
                
                <button type="button" class="btn btn-primary" onclick="addItemRow()">
                    <i class="fas fa-plus"></i> Add Item
                </button>
                
                <!-- Estimated Total -->
                <div class="estimated-total-box">
                    <div class="estimated-total-row">
                        <span>ESTIMATED TOTAL:</span>
                        <strong id="estimatedTotalDisplay">TZS 0</strong>
                    </div>
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i> This is an estimated total based on the unit prices provided. Actual prices may vary.
                    </small>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="form-card">
                <div class="row">
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-save"></i> Create Requisition
                        </button>
                        <a href="requisitions.php" class="btn btn-secondary btn-lg">
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
        <div class="row">
            <div class="col-md-12 mb-2">
                <label class="form-label"><strong>Item ${itemCounter}</strong></label>
            </div>
        </div>
        
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
                       onchange="calculateTotal()"
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
                    <option value="roll">Roll</option>
                    <option value="bag">Bag</option>
                    <option value="carton">Carton</option>
                </select>
            </div>
            
            <div class="col-md-2 mb-2">
                <label class="form-label">Est. Unit Price (TZS)</label>
                <input type="number" 
                       name="items[${itemCounter}][estimated_price]" 
                       class="form-control item-price" 
                       placeholder="0" 
                       step="0.01" 
                       min="0"
                       onchange="calculateTotal()">
                <small class="text-muted">Optional</small>
            </div>
            
            <div class="col-md-1 mb-2">
                <label class="form-label">Est. Total</label>
                <div class="line-total fw-bold text-primary" id="lineTotal${itemCounter}">TZS 0</div>
            </div>
            
            <div class="col-md-1 mb-2">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn-remove-item" onclick="removeItemRow(${itemCounter})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
        
        <div class="row mt-2">
            <div class="col-md-12">
                <label class="form-label">Specifications / Additional Requirements</label>
                <input type="text" 
                       name="items[${itemCounter}][specifications]" 
                       class="form-control" 
                       placeholder="Enter any specific requirements, brand preferences, or technical specifications">
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
        calculateTotal();
    }
}

// Calculate total
function calculateTotal() {
    let total = 0;
    
    // Calculate each line total
    const itemRows = document.querySelectorAll('.item-row');
    itemRows.forEach((row) => {
        const quantity = parseFloat(row.querySelector('.item-quantity')?.value || 0);
        const price = parseFloat(row.querySelector('.item-price')?.value || 0);
        const lineTotal = quantity * price;
        
        const lineTotalDisplay = row.querySelector('.line-total');
        if (lineTotalDisplay) {
            lineTotalDisplay.textContent = 'TZS ' + lineTotal.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2});
        }
        
        total += lineTotal;
    });
    
    // Update estimated total
    document.getElementById('estimatedTotalDisplay').textContent = 'TZS ' + total.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2});
}

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
document.getElementById('requisitionForm').addEventListener('submit', function(e) {
    const itemRows = document.querySelectorAll('.item-row');
    if (itemRows.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the requisition');
        return false;
    }
    
    // Check if at least one item has data
    let hasValidItem = false;
    itemRows.forEach(row => {
        const description = row.querySelector('textarea[name*="[description]"]')?.value;
        const quantity = row.querySelector('input[name*="[quantity]"]')?.value;
        
        if (description && quantity) {
            hasValidItem = true;
        }
    });
    
    if (!hasValidItem) {
        e.preventDefault();
        alert('Please fill in at least one complete item (description and quantity)');
        return false;
    }
    
    // Validate required by date is not before requisition date
    const reqDate = new Date(document.querySelector('input[name="requisition_date"]').value);
    const requiredDate = new Date(document.querySelector('input[name="required_date"]').value);
    
    if (requiredDate < reqDate) {
        e.preventDefault();
        alert('Required by date cannot be before requisition date');
        return false;
    }
});
</script>