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

// ==================== FETCH CATEGORIES ====================
$categories = [];
try {
    $stmt = $conn->prepare("
        SELECT category_id, category_name, depreciation_method, useful_life_years, salvage_value_percentage
        FROM asset_categories
        WHERE company_id = ?
        ORDER BY category_name
    ");
    $stmt->execute([$company_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Categories fetch error: " . $e->getMessage());
}

// ==================== FETCH DEPARTMENTS ====================
$departments = [];
try {
    $stmt = $conn->prepare("
        SELECT department_id, department_name, department_code
        FROM departments
        WHERE company_id = ? AND is_active = 1
        ORDER BY department_name
    ");
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log department count
    error_log("Departments found: " . count($departments));
} catch (Exception $e) {
    error_log("Departments fetch error: " . $e->getMessage());
}

// ==================== FETCH EMPLOYEES (CUSTODIANS) ====================
$employees = [];
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
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Employees fetch error: " . $e->getMessage());
}

// ==================== FORM SUBMISSION ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $required = ['category_id', 'asset_name', 'purchase_date', 'purchase_cost'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        if (empty($errors)) {
            // Generate asset number
            $stmt = $conn->prepare("
                SELECT COALESCE(MAX(CAST(SUBSTRING(asset_number, 4) AS UNSIGNED)), 0) + 1 as next_num
                FROM fixed_assets
                WHERE company_id = ? AND asset_number LIKE 'AST%'
            ");
            $stmt->execute([$company_id]);
            $next_num = $stmt->fetchColumn();
            $asset_number = 'AST' . str_pad($next_num, 5, '0', STR_PAD_LEFT);
            
            // Calculate total cost
            $purchase_cost = floatval($_POST['purchase_cost']);
            $installation_cost = floatval($_POST['installation_cost'] ?? 0);
            $total_cost = $purchase_cost + $installation_cost;
            
            // Get category defaults
            $stmt = $conn->prepare("
                SELECT depreciation_method, useful_life_years, salvage_value_percentage
                FROM asset_categories
                WHERE category_id = ?
            ");
            $stmt->execute([$_POST['category_id']]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate salvage value
            $salvage_value = $total_cost * ($category['salvage_value_percentage'] / 100);
            
            // Insert asset
            $stmt = $conn->prepare("
                INSERT INTO fixed_assets (
                    company_id, asset_number, category_id, asset_name, description,
                    purchase_date, supplier_id, invoice_number,
                    purchase_cost, installation_cost, total_cost,
                    serial_number, model_number, manufacturer,
                    warranty_expiry_date, location, department_id, custodian_id,
                    account_code, depreciation_account_code,
                    depreciation_method, useful_life_years, salvage_value,
                    accumulated_depreciation, current_book_value,
                    status, approval_status, notes, created_by
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    0, ?,
                    'active', 'pending', ?, ?
                )
            ");
            
            $stmt->execute([
                $company_id,
                $asset_number,
                $_POST['category_id'],
                trim($_POST['asset_name']),
                trim($_POST['description'] ?? ''),
                $_POST['purchase_date'],
                !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null,
                trim($_POST['invoice_number'] ?? ''),
                $purchase_cost,
                $installation_cost,
                $total_cost,
                trim($_POST['serial_number'] ?? ''),
                trim($_POST['model_number'] ?? ''),
                trim($_POST['manufacturer'] ?? ''),
                !empty($_POST['warranty_expiry_date']) ? $_POST['warranty_expiry_date'] : null,
                trim($_POST['location'] ?? ''),
                !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                !empty($_POST['custodian_id']) ? $_POST['custodian_id'] : null,
                trim($_POST['account_code'] ?? '1500'),
                trim($_POST['depreciation_account_code'] ?? '6200'),
                $category['depreciation_method'],
                $category['useful_life_years'],
                $salvage_value,
                $total_cost, // current_book_value initially equals total_cost
                trim($_POST['notes'] ?? ''),
                $user_id
            ]);
            
            $conn->commit();
            $success = "Asset registered successfully with number: $asset_number";
            
            // Redirect after success
            header("Location: index.php?success=" . urlencode($success));
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Asset creation error: " . $e->getMessage());
        $errors[] = "Failed to register asset: " . $e->getMessage();
    }
}

$page_title = 'Register Fixed Asset';
require_once '../../includes/header.php';
?>

<style>
.form-section {
    background: #fff;
    border-radius: 6px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #007bff;
}

.section-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #2c3e50;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
}

.form-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.3rem;
}

.form-control,
.form-select {
    font-size: 0.85rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

.form-control:focus,
.form-select:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.15);
}

.required-field::after {
    content: " *";
    color: #dc3545;
    font-weight: 700;
}

.info-text {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.calculated-field {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #2c3e50;
}

.btn-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
    padding-top: 1rem;
    border-top: 2px solid #f0f0f0;
    margin-top: 1.5rem;
}

.alert {
    border-radius: 4px;
    font-size: 0.85rem;
}

@media (max-width: 768px) {
    .btn-actions {
        flex-direction: column-reverse;
    }
    
    .btn-actions .btn {
        width: 100%;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-plus-circle me-2"></i>Register Fixed Asset
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Assets
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <strong>Error!</strong>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <strong>Success!</strong> <?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="assetForm">
        
        <!-- Basic Information -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-info-circle me-2"></i>Basic Information
            </h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required-field">Asset Category</label>
                    <select name="category_id" id="category_id" class="form-select" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>" 
                                    data-method="<?= $cat['depreciation_method'] ?>"
                                    data-life="<?= $cat['useful_life_years'] ?>"
                                    data-salvage="<?= $cat['salvage_value_percentage'] ?>"
                                    <?= ($_POST['category_id'] ?? '') == $cat['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="info-text">Select the asset category for depreciation</small>
                </div>
                
                <div class="col-md-8">
                    <label class="form-label required-field">Asset Name</label>
                    <input type="text" name="asset_name" class="form-control" 
                           placeholder="e.g., Dell Latitude 5520 Laptop" 
                           value="<?= htmlspecialchars($_POST['asset_name'] ?? '') ?>" required>
                    <small class="info-text">Descriptive name of the asset</small>
                </div>
                
                <div class="col-md-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2" 
                              placeholder="Additional details about the asset..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Purchase Information -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-shopping-cart me-2"></i>Purchase Information
            </h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label required-field">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control" 
                           value="<?= $_POST['purchase_date'] ?? date('Y-m-d') ?>" required>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label required-field">Purchase Cost (TSH)</label>
                    <input type="number" name="purchase_cost" id="purchase_cost" class="form-control" 
                           step="0.01" min="0" placeholder="0.00" 
                           value="<?= $_POST['purchase_cost'] ?? '' ?>" required>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Installation Cost (TSH)</label>
                    <input type="number" name="installation_cost" id="installation_cost" class="form-control" 
                           step="0.01" min="0" placeholder="0.00" 
                           value="<?= $_POST['installation_cost'] ?? '0' ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Total Cost (TSH)</label>
                    <input type="number" id="total_cost" class="form-control calculated-field" 
                           readonly value="<?= ($_POST['purchase_cost'] ?? 0) + ($_POST['installation_cost'] ?? 0) ?>">
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Supplier</label>
                    <select name="supplier_id" class="form-select">
                        <option value="">Select Supplier (Optional)</option>
                        <!-- Suppliers will be loaded from database -->
                    </select>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Invoice Number</label>
                    <input type="text" name="invoice_number" class="form-control" 
                           placeholder="INV-2024-001" 
                           value="<?= htmlspecialchars($_POST['invoice_number'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- Asset Details -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-barcode me-2"></i>Asset Details
            </h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control" 
                           placeholder="SN123456789" 
                           value="<?= htmlspecialchars($_POST['serial_number'] ?? '') ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Model Number</label>
                    <input type="text" name="model_number" class="form-control" 
                           placeholder="Model 5520" 
                           value="<?= htmlspecialchars($_POST['model_number'] ?? '') ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Manufacturer</label>
                    <input type="text" name="manufacturer" class="form-control" 
                           placeholder="Dell Technologies" 
                           value="<?= htmlspecialchars($_POST['manufacturer'] ?? '') ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Warranty Expiry Date</label>
                    <input type="date" name="warranty_expiry_date" class="form-control" 
                           value="<?= $_POST['warranty_expiry_date'] ?? '' ?>">
                </div>
            </div>
        </div>

        <!-- Location & Assignment -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-map-marker-alt me-2"></i>Location & Assignment
            </h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Location</label>
                    <input type="text" name="location" class="form-control" 
                           placeholder="Head Office, 3rd Floor" 
                           value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">Select Department (Optional)</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" 
                                    <?= ($_POST['department_id'] ?? '') == $dept['department_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Custodian (User)</label>
                    <select name="custodian_id" class="form-select">
                        <option value="">Select Custodian (Optional)</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['user_id'] ?>" 
                                    <?= ($_POST['custodian_id'] ?? '') == $emp['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['full_name']) ?>
                                <?php if ($emp['department_name']): ?>
                                    - <?= htmlspecialchars($emp['department_name']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Accounting Information -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-calculator me-2"></i>Accounting Information
            </h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Asset Account Code</label>
                    <input type="text" name="account_code" class="form-control" 
                           placeholder="1500" value="<?= $_POST['account_code'] ?? '1500' ?>">
                    <small class="info-text">GL account for asset capitalization</small>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Depreciation Expense Account</label>
                    <input type="text" name="depreciation_account_code" class="form-control" 
                           placeholder="6200" value="<?= $_POST['depreciation_account_code'] ?? '6200' ?>">
                    <small class="info-text">GL account for depreciation expense</small>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="form-section">
            <h5 class="section-title">
                <i class="fas fa-sticky-note me-2"></i>Additional Notes
            </h5>
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" 
                              placeholder="Any additional information about this asset..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="btn-actions">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-times me-1"></i>Cancel
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i>Register Asset
            </button>
        </div>
    </form>
</div>

<script>
// Auto-calculate total cost
document.getElementById('purchase_cost').addEventListener('input', calculateTotal);
document.getElementById('installation_cost').addEventListener('input', calculateTotal);

function calculateTotal() {
    const purchase = parseFloat(document.getElementById('purchase_cost').value) || 0;
    const installation = parseFloat(document.getElementById('installation_cost').value) || 0;
    const total = purchase + installation;
    document.getElementById('total_cost').value = total.toFixed(2);
}

// Form validation
document.getElementById('assetForm').addEventListener('submit', function(e) {
    const purchaseCost = parseFloat(document.getElementById('purchase_cost').value) || 0;
    
    if (purchaseCost <= 0) {
        e.preventDefault();
        alert('Purchase cost must be greater than zero');
        return false;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>