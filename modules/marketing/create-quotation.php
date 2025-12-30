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

// Get quotation_id if editing
$quotation_id = $_GET['id'] ?? null;
$quotation = null;
$quotation_items = [];

if ($quotation_id) {
    // Fetch quotation details
    $stmt = $conn->prepare("
        SELECT q.*, c.first_name, c.last_name, c.email, c.phone, c.address
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.customer_id
        WHERE q.quotation_id = ? AND q.company_id = ?
    ");
    $stmt->execute([$quotation_id, $company_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch quotation items
    $items_stmt = $conn->prepare("
        SELECT * FROM quotation_items WHERE quotation_id = ?
    ");
    $items_stmt->execute([$quotation_id]);
    $quotation_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'get_customer') {
            $stmt = $conn->prepare("
                SELECT * FROM customers 
                WHERE customer_id = ? AND company_id = ?
            ");
            $stmt->execute([$_POST['customer_id'], $company_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'customer' => $customer]);
            
        } elseif ($_POST['action'] === 'get_plot_details') {
            $stmt = $conn->prepare("
                SELECT 
                    p.*,
                    pr.project_name,
                    pr.region_id,
                    pr.district_id,
                    pr.physical_location
                FROM plots p
                INNER JOIN projects pr ON p.project_id = pr.project_id
                WHERE p.plot_id = ? AND p.company_id = ? AND p.status = 'available'
            ");
            $stmt->execute([$_POST['plot_id'], $company_id]);
            $plot = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($plot) {
                // Create item description
                $description = "Plot #{$plot['plot_number']} - {$plot['project_name']}\n";
                $description .= "Block: {$plot['block_number']}\n";
                $description .= "Area: {$plot['area']} sqm\n";
                $description .= "Location: {$plot['physical_location']}\n";
                if ($plot['corner_plot']) {
                    $description .= "Corner Plot";
                }
                
                $item = [
                    'item_type' => 'plot',
                    'reference_id' => $plot['plot_id'],
                    'description' => $description,
                    'quantity' => 1,
                    'unit' => 'plot',
                    'unit_price' => $plot['selling_price'],
                    'discount' => $plot['discount_amount'] ?? 0,
                    'total_price' => $plot['selling_price'] - ($plot['discount_amount'] ?? 0)
                ];
                
                echo json_encode(['success' => true, 'item' => $item]);
            } else {
                throw new Exception('Plot not found or not available');
            }
            
        } elseif ($_POST['action'] === 'get_service_details') {
            $stmt = $conn->prepare("
                SELECT * FROM service_types 
                WHERE service_type_id = ? AND company_id = ? AND is_active = 1
            ");
            $stmt->execute([$_POST['service_type_id'], $company_id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($service) {
                $description = "{$service['service_name']}\n";
                $description .= "Category: " . ucfirst(str_replace('_', ' ', $service['service_category'])) . "\n";
                if ($service['description']) {
                    $description .= $service['description'];
                }
                
                $item = [
                    'item_type' => 'service',
                    'reference_id' => $service['service_type_id'],
                    'description' => $description,
                    'quantity' => 1,
                    'unit' => $service['price_unit'] ?? 'service',
                    'unit_price' => $service['base_price'],
                    'discount' => 0,
                    'total_price' => $service['base_price']
                ];
                
                echo json_encode(['success' => true, 'item' => $item]);
            } else {
                throw new Exception('Service not found');
            }
            
        } elseif ($_POST['action'] === 'get_inventory_item') {
            $stmt = $conn->prepare("
                SELECT * FROM items 
                WHERE item_id = ? AND company_id = ? AND is_active = 1
            ");
            $stmt->execute([$_POST['item_id'], $company_id]);
            $inv_item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inv_item) {
                $description = "{$inv_item['item_name']}\n";
                $description .= "Code: {$inv_item['item_code']}\n";
                if ($inv_item['description']) {
                    $description .= $inv_item['description'];
                }
                
                $item = [
                    'item_type' => 'inventory',
                    'reference_id' => $inv_item['item_id'],
                    'description' => $description,
                    'quantity' => 1,
                    'unit' => $inv_item['unit_of_measure'],
                    'unit_price' => $inv_item['selling_price'],
                    'discount' => 0,
                    'total_price' => $inv_item['selling_price']
                ];
                
                echo json_encode(['success' => true, 'item' => $item]);
            } else {
                throw new Exception('Item not found');
            }
            
        } elseif ($_POST['action'] === 'save_quotation') {
            // Validate - must have customer (lead is optional reference)
            $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
            $lead_id = !empty($_POST['lead_id']) ? (int)$_POST['lead_id'] : null;
            
            // If no customer but has lead, try to get customer from lead conversion
            if (!$customer_id && $lead_id) {
                // Check if lead has been converted to customer
                $lead_check = $conn->prepare("
                    SELECT customer_id FROM leads 
                    WHERE lead_id = ? AND company_id = ? AND customer_id IS NOT NULL
                ");
                $lead_check->execute([$lead_id, $company_id]);
                $lead_data = $lead_check->fetch(PDO::FETCH_ASSOC);
                
                if ($lead_data && $lead_data['customer_id']) {
                    $customer_id = $lead_data['customer_id'];
                } else {
                    throw new Exception('Please select a customer. If creating quotation from a lead, the lead must first be converted to a customer.');
                }
            }
            
            if (!$customer_id) {
                throw new Exception('Customer is required. Please select a customer from the dropdown.');
            }
            
            // Parse items from JSON string
            $items = [];
            if (!empty($_POST['items'])) {
                $items = json_decode($_POST['items'], true);
            }
            
            if (empty($items) || !is_array($items)) {
                throw new Exception('At least one item is required');
            }
            
            // Begin transaction
            $conn->beginTransaction();
            
            try {
                if ($quotation_id && $_POST['quotation_id']) {
                    // Update existing quotation
                    $stmt = $conn->prepare("
                        UPDATE quotations SET
                            customer_id = ?,
                            lead_id = ?,
                            quotation_date = ?,
                            valid_until_date = ?,
                            subtotal = ?,
                            tax_amount = ?,
                            discount_amount = ?,
                            total_amount = ?,
                            payment_terms = ?,
                            delivery_terms = ?,
                            terms_conditions = ?,
                            notes = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE quotation_id = ? AND company_id = ?
                    ");
                    
                    $stmt->execute([
                        $customer_id,
                        $lead_id,
                        $_POST['quotation_date'],
                        $_POST['valid_until_date'],
                        $_POST['subtotal'],
                        $_POST['tax_amount'] ?? 0,
                        $_POST['discount_amount'] ?? 0,
                        $_POST['total_amount'],
                        $_POST['payment_terms'] ?? null,
                        $_POST['delivery_terms'] ?? null,
                        $_POST['terms_conditions'] ?? null,
                        $_POST['notes'] ?? null,
                        $_POST['status'] ?? 'draft',
                        $_POST['quotation_id'],
                        $company_id
                    ]);
                    
                    $quote_id = $_POST['quotation_id'];
                    
                    // Delete old items
                    $delete_items = $conn->prepare("DELETE FROM quotation_items WHERE quotation_id = ?");
                    $delete_items->execute([$quote_id]);
                    
                } else {
                    // Generate quotation number
                    $year = date('Y');
                    $stmt = $conn->prepare("
                        SELECT MAX(CAST(SUBSTRING(quotation_number, -4) AS UNSIGNED)) as max_num 
                        FROM quotations 
                        WHERE company_id = ? AND YEAR(quotation_date) = ?
                    ");
                    $stmt->execute([$company_id, $year]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $next_num = ($result['max_num'] ?? 0) + 1;
                    $quotation_number = 'QT-' . $year . '-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);
                    
                    // Insert quotation
                    $stmt = $conn->prepare("
                        INSERT INTO quotations (
                            company_id, quotation_number, quotation_date, valid_until_date,
                            customer_id, lead_id, subtotal, tax_amount, discount_amount,
                            total_amount, payment_terms, delivery_terms, terms_conditions,
                            notes, status, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $company_id,
                        $quotation_number,
                        $_POST['quotation_date'],
                        $_POST['valid_until_date'],
                        $customer_id,
                        $lead_id,
                        $_POST['subtotal'],
                        $_POST['tax_amount'] ?? 0,
                        $_POST['discount_amount'] ?? 0,
                        $_POST['total_amount'],
                        $_POST['payment_terms'] ?? null,
                        $_POST['delivery_terms'] ?? null,
                        $_POST['terms_conditions'] ?? null,
                        $_POST['notes'] ?? null,
                        $_POST['status'] ?? 'draft',
                        $user_id
                    ]);
                    
                    $quote_id = $conn->lastInsertId();
                }
                
                // Insert items
                $item_stmt = $conn->prepare("
                    INSERT INTO quotation_items (
                        quotation_id, item_description, quantity, unit, 
                        unit_price, total_price, details, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                foreach ($items as $item) {
                    $item_stmt->execute([
                        $quote_id,
                        $item['description'],
                        $item['quantity'],
                        $item['unit'] ?? 'unit',
                        $item['unit_price'],
                        $item['total_price'],
                        $item['details'] ?? null
                    ]);
                }
                
                $conn->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Quotation saved successfully',
                    'quotation_id' => $quote_id
                ]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch customers
$customers_stmt = $conn->prepare("
    SELECT customer_id, first_name, last_name, email, phone 
    FROM customers 
    WHERE company_id = ? AND is_active = 1 
    ORDER BY first_name, last_name
");
$customers_stmt->execute([$company_id]);
$customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available plots
$plots_stmt = $conn->prepare("
    SELECT 
        p.plot_id,
        p.plot_number,
        p.block_number,
        p.area,
        p.selling_price,
        p.discount_amount,
        pr.project_name
    FROM plots p
    INNER JOIN projects pr ON p.project_id = pr.project_id
    WHERE p.company_id = ? AND p.status = 'available' AND p.is_active = 1
    ORDER BY pr.project_name, p.plot_number
");
$plots_stmt->execute([$company_id]);
$plots = $plots_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch service types
$services_stmt = $conn->prepare("
    SELECT * FROM service_types 
    WHERE company_id = ? AND is_active = 1 
    ORDER BY service_category, service_name
");
$services_stmt->execute([$company_id]);
$services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch inventory items
$items_stmt = $conn->prepare("
    SELECT item_id, item_code, item_name, selling_price, unit_of_measure
    FROM items 
    WHERE company_id = ? AND is_active = 1 
    ORDER BY item_name
");
$items_stmt->execute([$company_id]);
$inventory_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch leads for reference
$leads_stmt = $conn->prepare("
    SELECT lead_id, full_name, phone, email 
    FROM leads 
    WHERE company_id = ? AND status NOT IN ('converted', 'lost') 
    ORDER BY created_at DESC 
    LIMIT 50
");
$leads_stmt->execute([$company_id]);
$leads = $leads_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = $quotation_id ? 'Edit Quotation' : 'Create Quotation';
require_once '../../includes/header.php';
?>

<style>
.form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
}

.form-control, .form-select, .form-textarea {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 0.625rem 0.875rem;
}

.form-control:focus, .form-select:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 12px 12px 0 0 !important;
    padding: 1.25rem 1.5rem;
}

.btn {
    border-radius: 8px;
    padding: 0.625rem 1.25rem;
    font-weight: 500;
}

.section-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e5e7eb;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e5e7eb;
}

.item-row {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    position: relative;
}

.remove-item-btn {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
}

.calculation-row {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.calculation-row:last-child {
    border-bottom: none;
}

.total-row {
    background: #f3f4f6;
    font-weight: 700;
    font-size: 1.1rem;
}

.item-type-selector {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.item-type-btn {
    flex: 1;
    padding: 0.75rem;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
}

.item-type-btn:hover {
    border-color: #667eea;
    background: #f0f4ff;
}

.item-type-btn.active {
    border-color: #667eea;
    background: #667eea;
    color: #fff;
}

.customer-info-card {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice text-primary me-2"></i><?php echo $page_title; ?>
                </h1>
                <p class="text-muted small mb-0 mt-1">Create professional quotations for customers</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="quotations.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Quotations
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        
        <form id="quotationForm">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="action" value="save_quotation">
            <input type="hidden" name="quotation_id" id="quotation_id" value="<?php echo $quotation_id ?? ''; ?>">
            
            <div class="row">
                <!-- Left Column -->
                <div class="col-lg-8">
                    
                    <!-- Customer Section -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-user me-2 text-primary"></i>Customer Information
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> Customer is required. Lead is optional for reference/tracking purposes.
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Select Customer <span class="text-danger">*</span></label>
                                <select class="form-select" name="customer_id" id="customer_id" required onchange="loadCustomerInfo()">
                                    <option value="">-- Select Customer --</option>
                                    <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['customer_id']; ?>" 
                                            <?php echo ($quotation && $quotation['customer_id'] == $customer['customer_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">OR Select Lead</label>
                                <select class="form-select" name="lead_id" id="lead_id">
                                    <option value="">-- Select Lead --</option>
                                    <?php foreach ($leads as $lead): ?>
                                    <option value="<?php echo $lead['lead_id']; ?>"
                                            <?php echo ($quotation && $quotation['lead_id'] == $lead['lead_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($lead['full_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12" id="customerInfoDisplay" style="display: none;">
                                <div class="customer-info-card">
                                    <h6 class="fw-bold mb-2">Customer Details</h6>
                                    <div id="customerInfoContent"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Items Section -->
                    <div class="section-card">
                        <div class="section-title d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-list me-2 text-success"></i>Quotation Items</span>
                            <button type="button" class="btn btn-sm btn-primary" onclick="showAddItemModal()">
                                <i class="fas fa-plus me-1"></i>Add Item
                            </button>
                        </div>
                        
                        <div id="itemsContainer">
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>No items added yet. Click "Add Item" to get started.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Terms & Notes -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-file-alt me-2 text-info"></i>Terms & Conditions
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Payment Terms</label>
                                <textarea class="form-control" name="payment_terms" rows="3" 
                                          placeholder="e.g., 30% deposit, balance on delivery"><?php echo $quotation['payment_terms'] ?? ''; ?></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Delivery Terms</label>
                                <textarea class="form-control" name="delivery_terms" rows="3" 
                                          placeholder="e.g., Delivery within 30 days"><?php echo $quotation['delivery_terms'] ?? ''; ?></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Terms & Conditions</label>
                                <textarea class="form-control" name="terms_conditions" rows="4" 
                                          placeholder="General terms and conditions..."><?php echo $quotation['terms_conditions'] ?? ''; ?></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Internal Notes</label>
                                <textarea class="form-control" name="notes" rows="3" 
                                          placeholder="Internal notes (not visible to customer)"><?php echo $quotation['notes'] ?? ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                </div>
                
                <!-- Right Column -->
                <div class="col-lg-4">
                    
                    <!-- Quotation Details -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-info-circle me-2 text-warning"></i>Quotation Details
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Quotation Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="quotation_date" id="quotation_date" 
                                   value="<?php echo $quotation['quotation_date'] ?? date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Valid Until <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="valid_until_date" id="valid_until_date" 
                                   value="<?php echo $quotation['valid_until_date'] ?? date('Y-m-d', strtotime('+30 days')); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="draft" <?php echo ($quotation && $quotation['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                <option value="sent" <?php echo ($quotation && $quotation['status'] == 'sent') ? 'selected' : ''; ?>>Sent</option>
                                <option value="accepted" <?php echo ($quotation && $quotation['status'] == 'accepted') ? 'selected' : ''; ?>>Accepted</option>
                                <option value="rejected" <?php echo ($quotation && $quotation['status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                <option value="expired" <?php echo ($quotation && $quotation['status'] == 'expired') ? 'selected' : ''; ?>>Expired</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Calculation Summary -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-calculator me-2 text-danger"></i>Summary
                        </div>
                        
                        <div class="calculation-row d-flex justify-content-between">
                            <span>Subtotal:</span>
                            <strong id="displaySubtotal">TZS 0.00</strong>
                        </div>
                        
                        <div class="calculation-row d-flex justify-content-between align-items-center">
                            <span>Tax (VAT):</span>
                            <div class="d-flex align-items-center gap-2">
                                <input type="number" class="form-control form-control-sm" id="taxRate" 
                                       value="18" min="0" max="100" step="0.01" style="width: 70px;" 
                                       onchange="calculateTotals()">
                                <span>%</span>
                                <strong id="displayTax">TZS 0.00</strong>
                            </div>
                        </div>
                        
                        <div class="calculation-row d-flex justify-content-between align-items-center">
                            <span>Discount:</span>
                            <div class="d-flex align-items-center gap-2">
                                <input type="number" class="form-control form-control-sm" id="discountAmount" 
                                       value="0" min="0" step="0.01" style="width: 100px;" 
                                       onchange="calculateTotals()">
                                <strong id="displayDiscount">TZS 0.00</strong>
                            </div>
                        </div>
                        
                        <div class="calculation-row total-row d-flex justify-content-between">
                            <span>TOTAL:</span>
                            <strong id="displayTotal">TZS 0.00</strong>
                        </div>
                        
                        <input type="hidden" name="subtotal" id="subtotal" value="0">
                        <input type="hidden" name="tax_amount" id="tax_amount" value="0">
                        <input type="hidden" name="discount_amount" id="discount_amount_hidden" value="0">
                        <input type="hidden" name="total_amount" id="total_amount" value="0">
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save me-2"></i>Save Quotation
                        </button>
                        <a href="quotations.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                    </div>
                    
                </div>
            </div>
        </form>
        
    </div>
</section>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>Add Item to Quotation
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                
                <!-- Item Type Selector -->
                <div class="item-type-selector">
                    <div class="item-type-btn active" data-type="plot" onclick="selectItemType('plot')">
                        <i class="fas fa-map-marked-alt fa-2x mb-2"></i>
                        <div class="fw-bold">Plot/Land</div>
                    </div>
                    <div class="item-type-btn" data-type="service" onclick="selectItemType('service')">
                        <i class="fas fa-concierge-bell fa-2x mb-2"></i>
                        <div class="fw-bold">Service</div>
                    </div>
                    <div class="item-type-btn" data-type="inventory" onclick="selectItemType('inventory')">
                        <i class="fas fa-box fa-2x mb-2"></i>
                        <div class="fw-bold">Inventory Item</div>
                    </div>
                    <div class="item-type-btn" data-type="custom" onclick="selectItemType('custom')">
                        <i class="fas fa-edit fa-2x mb-2"></i>
                        <div class="fw-bold">Custom Item</div>
                    </div>
                </div>
                
                <!-- Plot Selection -->
                <div id="plotSelection" class="item-selection-panel">
                    <label class="form-label">Select Plot</label>
                    <select class="form-select" id="plot_id" onchange="loadPlotDetails()">
                        <option value="">-- Select Plot --</option>
                        <?php foreach ($plots as $plot): ?>
                        <option value="<?php echo $plot['plot_id']; ?>" 
                                data-price="<?php echo $plot['selling_price']; ?>"
                                data-discount="<?php echo $plot['discount_amount'] ?? 0; ?>">
                            <?php echo htmlspecialchars($plot['project_name'] . ' - Plot #' . $plot['plot_number'] . ' (' . $plot['area'] . ' sqm)'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Service Selection -->
                <div id="serviceSelection" class="item-selection-panel" style="display: none;">
                    <label class="form-label">Select Service</label>
                    <select class="form-select" id="service_type_id" onchange="loadServiceDetails()">
                        <option value="">-- Select Service --</option>
                        <?php 
                        $current_category = '';
                        foreach ($services as $service): 
                            if ($current_category != $service['service_category']) {
                                if ($current_category != '') echo '</optgroup>';
                                $current_category = $service['service_category'];
                                echo '<optgroup label="' . ucfirst(str_replace('_', ' ', $current_category)) . '">';
                            }
                        ?>
                        <option value="<?php echo $service['service_type_id']; ?>" 
                                data-price="<?php echo $service['base_price']; ?>"
                                data-unit="<?php echo $service['price_unit']; ?>">
                            <?php echo htmlspecialchars($service['service_name']); ?>
                        </option>
                        <?php 
                        endforeach; 
                        if ($current_category != '') echo '</optgroup>';
                        ?>
                    </select>
                </div>
                
                <!-- Inventory Selection -->
                <div id="inventorySelection" class="item-selection-panel" style="display: none;">
                    <label class="form-label">Select Item</label>
                    <select class="form-select" id="item_id" onchange="loadInventoryDetails()">
                        <option value="">-- Select Item --</option>
                        <?php foreach ($inventory_items as $item): ?>
                        <option value="<?php echo $item['item_id']; ?>" 
                                data-price="<?php echo $item['selling_price']; ?>"
                                data-unit="<?php echo $item['unit_of_measure']; ?>">
                            <?php echo htmlspecialchars($item['item_code'] . ' - ' . $item['item_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Custom Item -->
                <div id="customSelection" class="item-selection-panel" style="display: none;">
                    <p class="text-muted">Enter custom item details below</p>
                </div>
                
                <!-- Item Details Form -->
                <div id="itemDetailsForm" style="display: none;">
                    <hr class="my-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="item_description" rows="4" required></textarea>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="item_quantity" value="1" min="0.01" step="0.01" required onchange="calculateItemTotal()">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Unit</label>
                            <input type="text" class="form-control" id="item_unit" value="unit">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Unit Price <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="item_unit_price" step="0.01" min="0" required onchange="calculateItemTotal()">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Discount</label>
                            <input type="number" class="form-control" id="item_discount" value="0" step="0.01" min="0" onchange="calculateItemTotal()">
                        </div>
                        
                        <div class="col-12">
                            <div class="alert alert-info mb-0">
                                <strong>Total Price:</strong> <span id="item_total_display">TZS 0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="addItemToQuotation()">
                    <i class="fas fa-plus me-2"></i>Add to Quotation
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let addItemModal;
let quotationItems = [];
let currentItemType = 'plot';

$(document).ready(function() {
    addItemModal = new bootstrap.Modal(document.getElementById('addItemModal'));
    
    // Load existing items if editing
    <?php if ($quotation_id && count($quotation_items) > 0): ?>
    quotationItems = <?php echo json_encode(array_map(function($item) {
        return [
            'description' => $item['item_description'],
            'quantity' => $item['quantity'],
            'unit' => $item['unit'],
            'unit_price' => $item['unit_price'],
            'discount' => 0,
            'total_price' => $item['total_price']
        ];
    }, $quotation_items)); ?>;
    renderItems();
    calculateTotals();
    <?php endif; ?>
    
    // Load customer info if editing
    <?php if ($quotation): ?>
    loadCustomerInfo();
    <?php endif; ?>
});

function loadCustomerInfo() {
    const customerId = document.getElementById('customer_id').value;
    if (!customerId) {
        document.getElementById('customerInfoDisplay').style.display = 'none';
        return;
    }
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_customer',
            customer_id: customerId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.customer) {
                const c = response.customer;
                let html = `
                    <div class="row g-2">
                        <div class="col-md-6">
                            <strong>Name:</strong> ${c.first_name} ${c.last_name}
                        </div>
                        <div class="col-md-6">
                            <strong>Phone:</strong> ${c.phone ?? 'N/A'}
                        </div>
                        <div class="col-md-6">
                            <strong>Email:</strong> ${c.email ?? 'N/A'}
                        </div>
                        <div class="col-md-6">
                            <strong>ID:</strong> ${c.national_id ?? 'N/A'}
                        </div>
                    </div>
                `;
                document.getElementById('customerInfoContent').innerHTML = html;
                document.getElementById('customerInfoDisplay').style.display = 'block';
            }
        }
    });
}

function showAddItemModal() {
    document.getElementById('itemDetailsForm').style.display = 'none';
    addItemModal.show();
}

function selectItemType(type) {
    currentItemType = type;
    
    // Update active button
    document.querySelectorAll('.item-type-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-type="${type}"]`).classList.add('active');
    
    // Show appropriate selection panel
    document.querySelectorAll('.item-selection-panel').forEach(panel => {
        panel.style.display = 'none';
    });
    
    document.getElementById('itemDetailsForm').style.display = 'none';
    
    switch(type) {
        case 'plot':
            document.getElementById('plotSelection').style.display = 'block';
            break;
        case 'service':
            document.getElementById('serviceSelection').style.display = 'block';
            break;
        case 'inventory':
            document.getElementById('inventorySelection').style.display = 'block';
            break;
        case 'custom':
            document.getElementById('customSelection').style.display = 'block';
            document.getElementById('itemDetailsForm').style.display = 'block';
            document.getElementById('item_description').value = '';
            document.getElementById('item_quantity').value = 1;
            document.getElementById('item_unit').value = 'unit';
            document.getElementById('item_unit_price').value = '';
            document.getElementById('item_discount').value = 0;
            break;
    }
}

function loadPlotDetails() {
    const plotId = document.getElementById('plot_id').value;
    if (!plotId) {
        document.getElementById('itemDetailsForm').style.display = 'none';
        return;
    }
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_plot_details',
            plot_id: plotId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                populateItemForm(response.item);
            } else {
                alert('Error: ' + response.message);
            }
        }
    });
}

function loadServiceDetails() {
    const serviceId = document.getElementById('service_type_id').value;
    if (!serviceId) {
        document.getElementById('itemDetailsForm').style.display = 'none';
        return;
    }
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_service_details',
            service_type_id: serviceId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                populateItemForm(response.item);
            } else {
                alert('Error: ' + response.message);
            }
        }
    });
}

function loadInventoryDetails() {
    const itemId = document.getElementById('item_id').value;
    if (!itemId) {
        document.getElementById('itemDetailsForm').style.display = 'none';
        return;
    }
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_inventory_item',
            item_id: itemId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                populateItemForm(response.item);
            } else {
                alert('Error: ' + response.message);
            }
        }
    });
}

function populateItemForm(item) {
    document.getElementById('item_description').value = item.description;
    document.getElementById('item_quantity').value = item.quantity;
    document.getElementById('item_unit').value = item.unit;
    document.getElementById('item_unit_price').value = item.unit_price;
    document.getElementById('item_discount').value = item.discount;
    
    calculateItemTotal();
    document.getElementById('itemDetailsForm').style.display = 'block';
}

function calculateItemTotal() {
    const quantity = parseFloat(document.getElementById('item_quantity').value) || 0;
    const unitPrice = parseFloat(document.getElementById('item_unit_price').value) || 0;
    const discount = parseFloat(document.getElementById('item_discount').value) || 0;
    
    const total = (quantity * unitPrice) - discount;
    document.getElementById('item_total_display').textContent = 'TZS ' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
}

function addItemToQuotation() {
    const description = document.getElementById('item_description').value;
    const quantity = parseFloat(document.getElementById('item_quantity').value);
    const unit = document.getElementById('item_unit').value;
    const unitPrice = parseFloat(document.getElementById('item_unit_price').value);
    const discount = parseFloat(document.getElementById('item_discount').value) || 0;
    
    if (!description || !quantity || !unitPrice) {
        alert('Please fill in all required fields');
        return;
    }
    
    const totalPrice = (quantity * unitPrice) - discount;
    
    quotationItems.push({
        description: description,
        quantity: quantity,
        unit: unit,
        unit_price: unitPrice,
        discount: discount,
        total_price: totalPrice
    });
    
    renderItems();
    calculateTotals();
    addItemModal.hide();
    
    // Reset form
    document.getElementById('plot_id').value = '';
    document.getElementById('service_type_id').value = '';
    document.getElementById('item_id').value = '';
    document.getElementById('itemDetailsForm').style.display = 'none';
}

function renderItems() {
    const container = document.getElementById('itemsContainer');
    
    if (quotationItems.length === 0) {
        container.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="fas fa-inbox fa-3x mb-3"></i>
                <p>No items added yet. Click "Add Item" to get started.</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    quotationItems.forEach((item, index) => {
        html += `
            <div class="item-row">
                <button type="button" class="btn btn-sm btn-danger remove-item-btn" onclick="removeItem(${index})">
                    <i class="fas fa-times"></i>
                </button>
                <div class="row g-2">
                    <div class="col-12">
                        <strong>Description:</strong>
                        <pre class="mb-0" style="white-space: pre-wrap; font-family: inherit;">${item.description}</pre>
                    </div>
                    <div class="col-md-3">
                        <strong>Quantity:</strong> ${item.quantity} ${item.unit}
                    </div>
                    <div class="col-md-3">
                        <strong>Unit Price:</strong> TZS ${parseFloat(item.unit_price).toLocaleString('en-US', {minimumFractionDigits: 2})}
                    </div>
                    <div class="col-md-3">
                        <strong>Discount:</strong> TZS ${parseFloat(item.discount).toLocaleString('en-US', {minimumFractionDigits: 2})}
                    </div>
                    <div class="col-md-3">
                        <strong>Total:</strong> <span class="text-primary fw-bold">TZS ${parseFloat(item.total_price).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function removeItem(index) {
    if (confirm('Are you sure you want to remove this item?')) {
        quotationItems.splice(index, 1);
        renderItems();
        calculateTotals();
    }
}

function calculateTotals() {
    const subtotal = quotationItems.reduce((sum, item) => sum + parseFloat(item.total_price), 0);
    const taxRate = parseFloat(document.getElementById('taxRate').value) || 0;
    const discountAmount = parseFloat(document.getElementById('discountAmount').value) || 0;
    
    const taxAmount = (subtotal * taxRate) / 100;
    const total = subtotal + taxAmount - discountAmount;
    
    // Update display
    document.getElementById('displaySubtotal').textContent = 'TZS ' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('displayTax').textContent = 'TZS ' + taxAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('displayDiscount').textContent = 'TZS ' + discountAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('displayTotal').textContent = 'TZS ' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
    
    // Update hidden fields
    document.getElementById('subtotal').value = subtotal.toFixed(2);
    document.getElementById('tax_amount').value = taxAmount.toFixed(2);
    document.getElementById('discount_amount_hidden').value = discountAmount.toFixed(2);
    document.getElementById('total_amount').value = total.toFixed(2);
}

// Save quotation
document.getElementById('quotationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Validate customer is required
    const customerId = document.getElementById('customer_id').value;
    
    if (!customerId) {
        alert('Please select a Customer (required)');
        return;
    }
    
    // Validate items
    if (quotationItems.length === 0) {
        alert('Please add at least one item to the quotation');
        return;
    }
    
    const formData = new FormData(this);
    
    // Add items as JSON string
    formData.append('items', JSON.stringify(quotationItems));
    
    // Debug: Show what we're sending
    console.log('Sending items:', quotationItems);
    console.log('Form data items:', formData.get('items'));
    
    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                window.location.href = 'quotations.php';
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            console.error('Response:', xhr.responseText);
            alert('Error saving quotation. Check console for details.');
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>