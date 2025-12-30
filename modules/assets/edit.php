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

$asset_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

if (!$asset_id) {
    header("Location: index.php");
    exit;
}

// ==================== FETCH ASSET DETAILS ====================
try {
    $stmt = $conn->prepare("
        SELECT * FROM fixed_assets 
        WHERE asset_id = ? AND company_id = ?
    ");
    $stmt->execute([$asset_id, $company_id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$asset) {
        header("Location: index.php?error=" . urlencode("Asset not found"));
        exit;
    }
} catch (Exception $e) {
    error_log("Asset fetch error: " . $e->getMessage());
    header("Location: index.php?error=" . urlencode("Failed to load asset"));
    exit;
}

// ==================== HANDLE UPDATE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $asset_name = trim($_POST['asset_name']);
        $purchase_date = $_POST['purchase_date'];
        $purchase_cost = (float)$_POST['purchase_cost'];
        $installation_cost = !empty($_POST['installation_cost']) ? (float)$_POST['installation_cost'] : 0;
        
        if (!$category_id || !$asset_name || !$purchase_date || $purchase_cost <= 0) {
            throw new Exception("Please fill in all required fields");
        }
        
        // Calculate total cost
        $total_cost = $purchase_cost + $installation_cost;
        
        // Get category defaults if depreciation info not provided
        $stmt = $conn->prepare("
            SELECT depreciation_method, useful_life_years, salvage_value_percentage
            FROM asset_categories WHERE category_id = ?
        ");
        $stmt->execute([$category_id]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $depreciation_method = !empty($_POST['depreciation_method']) ? $_POST['depreciation_method'] : $category['depreciation_method'];
        $useful_life_years = !empty($_POST['useful_life_years']) ? (int)$_POST['useful_life_years'] : $category['useful_life_years'];
        $salvage_value_percentage = !empty($_POST['salvage_value_percentage']) ? (float)$_POST['salvage_value_percentage'] : $category['salvage_value_percentage'];
        
        // Calculate salvage value
        $salvage_value = $total_cost * ($salvage_value_percentage / 100);
        
        // Recalculate current book value if total cost changed
        $old_total_cost = $asset['total_cost'];
        $current_book_value = $asset['current_book_value'];
        if ($total_cost != $old_total_cost) {
            // Adjust book value proportionally
            $depreciation_rate = $asset['accumulated_depreciation'] / $old_total_cost;
            $accumulated_depreciation = $total_cost * $depreciation_rate;
            $current_book_value = $total_cost - $accumulated_depreciation;
        } else {
            $accumulated_depreciation = $asset['accumulated_depreciation'];
        }
        
        // Update asset
        $stmt = $conn->prepare("
            UPDATE fixed_assets SET
                category_id = ?,
                asset_name = ?,
                description = ?,
                purchase_date = ?,
                purchase_cost = ?,
                installation_cost = ?,
                total_cost = ?,
                supplier_id = ?,
                invoice_number = ?,
                serial_number = ?,
                model_number = ?,
                manufacturer = ?,
                warranty_expiry_date = ?,
                location = ?,
                department_id = ?,
                custodian_id = ?,
                account_code = ?,
                depreciation_account_code = ?,
                depreciation_method = ?,
                useful_life_years = ?,
                salvage_value = ?,
                accumulated_depreciation = ?,
                current_book_value = ?,
                status = ?,
                notes = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE asset_id = ? AND company_id = ?
        ");
        
        $stmt->execute([
            $category_id,
            $asset_name,
            trim($_POST['description']),
            $purchase_date,
            $purchase_cost,
            $installation_cost,
            $total_cost,
            !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
            trim($_POST['invoice_number']),
            trim($_POST['serial_number']),
            trim($_POST['model_number']),
            trim($_POST['manufacturer']),
            !empty($_POST['warranty_expiry_date']) ? $_POST['warranty_expiry_date'] : null,
            trim($_POST['location']),
            !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
            !empty($_POST['custodian_id']) ? (int)$_POST['custodian_id'] : null,
            trim($_POST['account_code']),
            trim($_POST['depreciation_account_code']),
            $depreciation_method,
            $useful_life_years,
            $salvage_value,
            $accumulated_depreciation,
            $current_book_value,
            $_POST['status'],
            trim($_POST['notes']),
            $user_id,
            $asset_id,
            $company_id
        ]);
        
        $conn->commit();
        $success = "Asset updated successfully!";
        
        // Refresh asset data
        $stmt = $conn->prepare("SELECT * FROM fixed_assets WHERE asset_id = ? AND company_id = ?");
        $stmt->execute([$asset_id, $company_id]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
        error_log("Asset update error: " . $e->getMessage());
    }
}

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
} catch (Exception $e) {
    error_log("Departments fetch error: " . $e->getMessage());
}

// ==================== FETCH CUSTODIANS (USERS) ====================
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

$page_title = 'Edit Asset - ' . $asset['asset_number'];
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

.form-control, .form-select {
    font-size: 0.875rem;
    border-radius: 4px;
}

.form-control:focus, .form-select:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.info-text {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.calculated-field {
    background-color: #e9ecef;
    cursor: not-allowed;
}

.btn-save {
    min-width: 120px;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-edit me-2"></i>Edit Asset
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="view.php?id=<?= $asset_id ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Asset
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

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" id="editAssetForm">
        
        <div class="row">
            <div class="col-md-8">
                
                <!-- Basic Information -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-info-circle me-2"></i>Basic Information
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Asset Number</label>
                            <input type="text" class="form-control calculated-field" 
                                   value="<?= htmlspecialchars($asset['asset_number']) ?>" readonly>
                            <div class="info-text">Auto-generated, cannot be changed</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Category</label>
                            <select name="category_id" id="category_id" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>" 
                                        <?= $asset['category_id'] == $cat['category_id'] ? 'selected' : '' ?>
                                        data-depreciation-method="<?= $cat['depreciation_method'] ?>"
                                        data-useful-life="<?= $cat['useful_life_years'] ?>"
                                        data-salvage-percentage="<?= $cat['salvage_value_percentage'] ?>">
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required-field">Asset Name</label>
                        <input type="text" name="asset_name" class="form-control" 
                               value="<?= htmlspecialchars($asset['asset_name']) ?>"
                               placeholder="e.g., Dell Latitude 5520 Laptop" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($asset['description']) ?></textarea>
                    </div>
                </div>

                <!-- Purchase Information -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-shopping-cart me-2"></i>Purchase Information
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Purchase Date</label>
                            <input type="date" name="purchase_date" class="form-control" 
                                   value="<?= $asset['purchase_date'] ?>" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Invoice Number</label>
                            <input type="text" name="invoice_number" class="form-control" 
                                   value="<?= htmlspecialchars($asset['invoice_number']) ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label required-field">Purchase Cost (TSH)</label>
                            <input type="number" name="purchase_cost" id="purchase_cost" 
                                   class="form-control" step="0.01" min="0" 
                                   value="<?= $asset['purchase_cost'] ?>" required>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Installation Cost (TSH)</label>
                            <input type="number" name="installation_cost" id="installation_cost" 
                                   class="form-control" step="0.01" min="0" 
                                   value="<?= $asset['installation_cost'] ?>">
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Total Cost (TSH)</label>
                            <input type="number" name="total_cost" id="total_cost" 
                                   class="form-control calculated-field" readonly 
                                   value="<?= $asset['total_cost'] ?>">
                        </div>
                    </div>
                </div>

                <!-- Asset Details -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-barcode me-2"></i>Asset Details
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Serial Number</label>
                            <input type="text" name="serial_number" class="form-control" 
                                   value="<?= htmlspecialchars($asset['serial_number']) ?>"
                                   placeholder="SN123456789">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Model Number</label>
                            <input type="text" name="model_number" class="form-control" 
                                   value="<?= htmlspecialchars($asset['model_number']) ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Manufacturer</label>
                            <input type="text" name="manufacturer" class="form-control" 
                                   value="<?= htmlspecialchars($asset['manufacturer']) ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Warranty Expiry Date</label>
                            <input type="date" name="warranty_expiry_date" class="form-control" 
                                   value="<?= $asset['warranty_expiry_date'] ?>">
                        </div>
                    </div>
                </div>

                <!-- Location & Assignment -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-map-marker-alt me-2"></i>Location & Assignment
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-control" 
                               value="<?= htmlspecialchars($asset['location']) ?>"
                               placeholder="e.g., Head Office, 3rd Floor, Room 301">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['department_id'] ?>" 
                                        <?= $asset['department_id'] == $dept['department_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['department_name']) ?>
                                    <?php if ($dept['department_code']): ?>
                                        (<?= htmlspecialchars($dept['department_code']) ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Custodian</label>
                            <select name="custodian_id" class="form-select">
                                <option value="">Select Custodian</option>
                                <?php foreach ($custodians as $custodian): ?>
                                <option value="<?= $custodian['user_id'] ?>" 
                                        <?= $asset['custodian_id'] == $custodian['user_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($custodian['full_name']) ?>
                                    <?php if ($custodian['department_name']): ?>
                                        - <?= htmlspecialchars($custodian['department_name']) ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Additional Notes -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-sticky-note me-2"></i>Additional Notes
                    </div>
                    <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($asset['notes']) ?></textarea>
                </div>

            </div>

            <div class="col-md-4">
                
                <!-- Status -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-toggle-on me-2"></i>Status
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label required-field">Asset Status</label>
                        <select name="status" class="form-select" required>
                            <option value="active" <?= $asset['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $asset['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="under_maintenance" <?= $asset['status'] == 'under_maintenance' ? 'selected' : '' ?>>Under Maintenance</option>
                            <option value="disposed" <?= $asset['status'] == 'disposed' ? 'selected' : '' ?>>Disposed</option>
                            <option value="stolen" <?= $asset['status'] == 'stolen' ? 'selected' : '' ?>>Stolen</option>
                            <option value="damaged" <?= $asset['status'] == 'damaged' ? 'selected' : '' ?>>Damaged</option>
                        </select>
                    </div>
                </div>

                <!-- Depreciation Settings -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-calculator me-2"></i>Depreciation Settings
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Depreciation Method</label>
                        <select name="depreciation_method" class="form-select">
                            <option value="straight_line" <?= $asset['depreciation_method'] == 'straight_line' ? 'selected' : '' ?>>Straight Line</option>
                            <option value="declining_balance" <?= $asset['depreciation_method'] == 'declining_balance' ? 'selected' : '' ?>>Declining Balance</option>
                            <option value="units_of_production" <?= $asset['depreciation_method'] == 'units_of_production' ? 'selected' : '' ?>>Units of Production</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Useful Life (Years)</label>
                        <input type="number" name="useful_life_years" class="form-control" 
                               min="1" value="<?= $asset['useful_life_years'] ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Salvage Value %</label>
                        <input type="number" name="salvage_value_percentage" class="form-control" 
                               step="0.01" min="0" max="100" 
                               value="<?= ($asset['total_cost'] > 0 ? ($asset['salvage_value'] / $asset['total_cost'] * 100) : 0) ?>">
                    </div>
                    
                    <div class="alert alert-info" style="font-size: 0.75rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        Changing these values will affect future depreciation calculations
                    </div>
                </div>

                <!-- Accounting Information -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-book me-2"></i>Accounting
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Asset Account Code</label>
                        <input type="text" name="account_code" class="form-control" 
                               value="<?= htmlspecialchars($asset['account_code']) ?>"
                               placeholder="1500">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Depreciation Account Code</label>
                        <input type="text" name="depreciation_account_code" class="form-control" 
                               value="<?= htmlspecialchars($asset['depreciation_account_code']) ?>"
                               placeholder="6200">
                    </div>
                </div>

                <!-- Current Values (Read-only) -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-chart-line me-2"></i>Current Values
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Accumulated Depreciation</label>
                        <input type="text" class="form-control calculated-field" 
                               value="TSH <?= number_format($asset['accumulated_depreciation'], 2) ?>" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Current Book Value</label>
                        <input type="text" class="form-control calculated-field" 
                               value="TSH <?= number_format($asset['current_book_value'], 2) ?>" readonly>
                    </div>
                    
                    <div class="alert alert-warning" style="font-size: 0.75rem;">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        These values are calculated automatically
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="form-section">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-save">
                            <i class="fas fa-save me-2"></i>Update Asset
                        </button>
                        <a href="view.php?id=<?= $asset_id ?>" class="btn btn-secondary">
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
    // Auto-calculate total cost
    const purchaseCost = document.getElementById('purchase_cost');
    const installationCost = document.getElementById('installation_cost');
    const totalCost = document.getElementById('total_cost');
    
    function calculateTotal() {
        const purchase = parseFloat(purchaseCost.value) || 0;
        const installation = parseFloat(installationCost.value) || 0;
        totalCost.value = (purchase + installation).toFixed(2);
    }
    
    purchaseCost.addEventListener('input', calculateTotal);
    installationCost.addEventListener('input', calculateTotal);
    
    // Form validation
    document.getElementById('editAssetForm').addEventListener('submit', function(e) {
        const purchase = parseFloat(purchaseCost.value) || 0;
        if (purchase <= 0) {
            e.preventDefault();
            alert('Purchase cost must be greater than 0');
            purchaseCost.focus();
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>