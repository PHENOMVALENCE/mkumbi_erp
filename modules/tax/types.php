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

if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// ==================== HANDLE DELETE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tax'])) {
    try {
        $tax_type_id = (int)$_POST['tax_type_id'];
        
        $stmt = $conn->prepare("DELETE FROM tax_types WHERE tax_type_id = ? AND company_id = ?");
        $stmt->execute([$tax_type_id, $company_id]);
        $success = "Tax type deleted successfully";
    } catch (Exception $e) {
        $error = "Failed to delete tax type: " . $e->getMessage();
    }
}

// ==================== HANDLE EDIT ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_tax'])) {
    try {
        $conn->beginTransaction();
        
        $tax_type_id = (int)$_POST['tax_type_id'];
        $tax_name = trim($_POST['tax_name']);
        $tax_code = trim($_POST['tax_code']);
        $tax_category = $_POST['tax_category'];
        $tax_rate = (float)$_POST['tax_rate'];
        
        if (!$tax_name || !$tax_code || !$tax_category || $tax_rate < 0) {
            throw new Exception("Please fill in all required fields");
        }
        
        $stmt = $conn->prepare("
            SELECT tax_type_id FROM tax_types 
            WHERE tax_code = ? AND company_id = ? AND tax_type_id != ?
        ");
        $stmt->execute([$tax_code, $company_id, $tax_type_id]);
        if ($stmt->rowCount() > 0) {
            throw new Exception("Tax code already exists");
        }
        
        $stmt = $conn->prepare("
            UPDATE tax_types SET
                tax_name = ?, tax_code = ?, tax_category = ?, tax_rate = ?,
                calculation_method = ?, applies_to = ?, account_code = ?,
                description = ?, is_active = ?, updated_at = NOW()
            WHERE tax_type_id = ? AND company_id = ?
        ");
        
        $stmt->execute([
            $tax_name, $tax_code, $tax_category, $tax_rate,
            $_POST['calculation_method'], $_POST['applies_to'],
            trim($_POST['account_code']), trim($_POST['description']),
            isset($_POST['is_active']) ? 1 : 0,
            $tax_type_id, $company_id
        ]);
        
        $conn->commit();
        $success = "Tax type updated successfully";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// ==================== HANDLE TOGGLE ====================
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    try {
        $tax_type_id = (int)$_GET['id'];
        $stmt = $conn->prepare("UPDATE tax_types SET is_active = NOT is_active WHERE tax_type_id = ? AND company_id = ?");
        $stmt->execute([$tax_type_id, $company_id]);
        $success = "Status updated successfully";
    } catch (Exception $e) {
        $error = "Failed to update status: " . $e->getMessage();
    }
}

// ==================== FETCH TAX TYPES ====================
$tax_types = [];
try {
    $stmt = $conn->prepare("
        SELECT t.*, u.full_name as created_by_name, DATEDIFF(NOW(), t.created_at) as days_old
        FROM tax_types t
        LEFT JOIN users u ON t.created_by = u.user_id
        WHERE t.company_id = ?
        ORDER BY t.tax_category, t.tax_name
    ");
    $stmt->execute([$company_id]);
    $tax_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Failed to load tax types: " . $e->getMessage();
}

$stats = [
    'total_types' => count($tax_types),
    'active_types' => count(array_filter($tax_types, fn($t) => $t['is_active'])),
    'vat_types' => count(array_filter($tax_types, fn($t) => $t['tax_category'] == 'vat')),
    'wht_types' => count(array_filter($tax_types, fn($t) => $t['tax_category'] == 'withholding')),
    'recently_added' => count(array_filter($tax_types, fn($t) => isset($t['days_old']) && $t['days_old'] <= 7))
];

$page_title = 'Tax Types';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 0.875rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #007bff;
    height: 100%;
}
.stats-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}
.stats-label {
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}
.table-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    overflow: hidden;
}
.table thead th {
    background: #f8f9fa;
    font-weight: 700;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    padding: 1rem 0.75rem;
}
.table tbody td {
    padding: 0.875rem 0.75rem;
    vertical-align: middle;
    font-size: 0.875rem;
}
.table tbody tr:hover {
    background: #f8f9fa;
}
.category-badge {
    display: inline-block;
    padding: 0.35rem 0.65rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}
.category-badge.vat { background: #d4edda; color: #155724; }
.category-badge.withholding { background: #fff3cd; color: #856404; }
.category-badge.excise { background: #f8d7da; color: #721c24; }
.category-badge.customs { background: #d1ecf1; color: #0c5460; }
.category-badge.other { background: #e2e3e5; color: #383d41; }
.tax-code {
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: #495057;
    font-size: 0.85rem;
}
.tax-rate {
    font-weight: 700;
    color: #007bff;
    font-size: 1rem;
}
.new-badge {
    background: #6f42c1;
    color: white;
    padding: 0.2rem 0.5rem;
    border-radius: 3px;
    font-size: 0.65rem;
    font-weight: 600;
    margin-left: 0.5rem;
}
.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
.modal-header .btn-close {
    filter: brightness(0) invert(1);
}
.info-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}
.info-value {
    font-size: 0.95rem;
    color: #2c3e50;
    font-weight: 500;
    margin-bottom: 1rem;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-percentage me-2"></i>Tax Types
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="add-type.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i>Add Tax Type
                </a>
                <a href="computation.php" class="btn btn-info btn-sm">
                    <i class="fas fa-calculator me-1"></i>Tax Computation
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

    <!-- Statistics -->
    <div class="row mb-3 g-2">
        <div class="col-md-2 col-6">
            <div class="stats-card" style="border-left-color: #007bff;">
                <div class="stats-value"><?= $stats['total_types'] ?></div>
                <div class="stats-label">Total Types</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stats-card" style="border-left-color: #28a745;">
                <div class="stats-value"><?= $stats['active_types'] ?></div>
                <div class="stats-label">Active</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stats-card" style="border-left-color: #17a2b8;">
                <div class="stats-value"><?= $stats['vat_types'] ?></div>
                <div class="stats-label">VAT</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stats-card" style="border-left-color: #ffc107;">
                <div class="stats-value"><?= $stats['wht_types'] ?></div>
                <div class="stats-label">Withholding</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stats-card" style="border-left-color: #6f42c1;">
                <div class="stats-value"><?= $stats['recently_added'] ?></div>
                <div class="stats-label">New (7 Days)</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stats-card" style="border-left-color: #dc3545;">
                <div class="stats-value"><?= $stats['total_types'] - $stats['active_types'] ?></div>
                <div class="stats-label">Inactive</div>
            </div>
        </div>
    </div>

    <!-- Tax Types Table -->
    <div class="table-card">
        <?php if (empty($tax_types)): ?>
            <div class="text-center py-5">
                <i class="fas fa-percentage fa-4x text-muted mb-3"></i>
                <h5 class="text-muted">No Tax Types Configured</h5>
                <p class="text-muted">Start by adding your first tax type</p>
                <a href="add-type.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add Tax Type
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 10%;">Code</th>
                            <th style="width: 20%;">Tax Name</th>
                            <th style="width: 12%;">Category</th>
                            <th style="width: 10%;">Rate</th>
                            <th style="width: 13%;">Applies To</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 20%;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        foreach ($tax_types as $tax): 
                        ?>
                        <tr>
                            <td><?= $counter++ ?></td>
                            <td><span class="tax-code"><?= htmlspecialchars($tax['tax_code']) ?></span></td>
                            <td>
                                <strong><?= htmlspecialchars($tax['tax_name']) ?></strong>
                                <?php if (isset($tax['days_old']) && $tax['days_old'] <= 7): ?>
                                <span class="new-badge"><i class="fas fa-star"></i> NEW</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="category-badge <?= $tax['tax_category'] ?>">
                                    <?= ucfirst($tax['tax_category']) ?>
                                </span>
                            </td>
                            <td><span class="tax-rate"><?= number_format($tax['tax_rate'], 2) ?>%</span></td>
                            <td><?= ucfirst($tax['applies_to']) ?></td>
                            <td>
                                <?php if ($tax['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-info btn-sm" onclick='viewTax(<?= json_encode($tax, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-warning btn-sm" onclick='editTax(<?= json_encode($tax, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="?toggle=1&id=<?= $tax['tax_type_id'] ?>" class="btn btn-secondary btn-sm"
                                   onclick="return confirm('Toggle status?')">
                                    <i class="fas fa-<?= $tax['is_active'] ? 'toggle-off' : 'toggle-on' ?>"></i>
                                </a>
                                <button type="button" class="btn btn-danger btn-sm" 
                                        onclick='deleteTax(<?= $tax['tax_type_id'] ?>, <?= json_encode($tax['tax_name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewTaxModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Tax Type Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewTaxContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editTaxModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Tax Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="tax_type_id" id="edit_tax_type_id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Tax Name <span class="text-danger">*</span></label>
                            <input type="text" name="tax_name" id="edit_tax_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Tax Code <span class="text-danger">*</span></label>
                            <input type="text" name="tax_code" id="edit_tax_code" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                            <select name="tax_category" id="edit_tax_category" class="form-select" required>
                                <option value="vat">VAT</option>
                                <option value="withholding">Withholding</option>
                                <option value="excise">Excise</option>
                                <option value="customs">Customs</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Tax Rate (%) <span class="text-danger">*</span></label>
                            <input type="number" name="tax_rate" id="edit_tax_rate" class="form-control" 
                                   step="0.01" min="0" max="100" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Applies To</label>
                            <select name="applies_to" id="edit_applies_to" class="form-select">
                                <option value="sales">Sales</option>
                                <option value="purchases">Purchases</option>
                                <option value="both">Both</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Calculation Method</label>
                            <select name="calculation_method" id="edit_calculation_method" class="form-select">
                                <option value="percentage">Percentage</option>
                                <option value="fixed">Fixed Amount</option>
                                <option value="tiered">Tiered Rate</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Account Code</label>
                            <input type="text" name="account_code" id="edit_account_code" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                        <label class="form-check-label fw-bold">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_tax" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteTaxModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Delete Tax Type</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="tax_type_id" id="delete_tax_type_id">
                    <div class="text-center py-3">
                        <i class="fas fa-exclamation-triangle fa-4x text-warning mb-3"></i>
                        <h5>Are you sure?</h5>
                        <p class="text-muted mb-0" id="delete_tax_name"></p>
                        <p class="text-danger mt-2"><small>This action cannot be undone.</small></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_tax" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function viewTax(tax) {
    const content = `
        <div class="row">
            <div class="col-md-6">
                <div class="info-label">Tax Name</div>
                <div class="info-value">${tax.tax_name}</div>
            </div>
            <div class="col-md-6">
                <div class="info-label">Tax Code</div>
                <div class="info-value"><code>${tax.tax_code}</code></div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <div class="info-label">Category</div>
                <div class="info-value">
                    <span class="category-badge ${tax.tax_category}">
                        ${tax.tax_category.toUpperCase()}
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-label">Tax Rate</div>
                <div class="info-value"><span class="tax-rate" style="font-size: 1.5rem;">${parseFloat(tax.tax_rate).toFixed(2)}%</span></div>
            </div>
            <div class="col-md-4">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <span class="badge ${tax.is_active == 1 ? 'bg-success' : 'bg-secondary'}">
                        ${tax.is_active == 1 ? 'Active' : 'Inactive'}
                    </span>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <div class="info-label">Calculation Method</div>
                <div class="info-value">${tax.calculation_method || 'N/A'}</div>
            </div>
            <div class="col-md-4">
                <div class="info-label">Applies To</div>
                <div class="info-value">${tax.applies_to || 'N/A'}</div>
            </div>
            <div class="col-md-4">
                <div class="info-label">Account Code</div>
                <div class="info-value">${tax.account_code || 'N/A'}</div>
            </div>
        </div>
        ${tax.description ? `<div class="row"><div class="col-12"><div class="info-label">Description</div><div class="info-value">${tax.description}</div></div></div>` : ''}
        <div class="row">
            <div class="col-md-6">
                <div class="info-label">Created By</div>
                <div class="info-value">${tax.created_by_name || 'System'}</div>
            </div>
            <div class="col-md-6">
                <div class="info-label">Created At</div>
                <div class="info-value">${new Date(tax.created_at).toLocaleString()}</div>
            </div>
        </div>
    `;
    document.getElementById('viewTaxContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('viewTaxModal')).show();
}

function editTax(tax) {
    document.getElementById('edit_tax_type_id').value = tax.tax_type_id;
    document.getElementById('edit_tax_name').value = tax.tax_name;
    document.getElementById('edit_tax_code').value = tax.tax_code;
    document.getElementById('edit_tax_category').value = tax.tax_category;
    document.getElementById('edit_tax_rate').value = tax.tax_rate;
    document.getElementById('edit_applies_to').value = tax.applies_to || 'both';
    document.getElementById('edit_calculation_method').value = tax.calculation_method || 'percentage';
    document.getElementById('edit_account_code').value = tax.account_code || '';
    document.getElementById('edit_description').value = tax.description || '';
    document.getElementById('edit_is_active').checked = tax.is_active == 1;
    new bootstrap.Modal(document.getElementById('editTaxModal')).show();
}

function deleteTax(taxId, taxName) {
    document.getElementById('delete_tax_type_id').value = taxId;
    document.getElementById('delete_tax_name').textContent = taxName;
    new bootstrap.Modal(document.getElementById('deleteTaxModal')).show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>