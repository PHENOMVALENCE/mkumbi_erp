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

$asset_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$asset_id) {
    header("Location: index.php");
    exit;
}

// ==================== FETCH ASSET DETAILS ====================
try {
    $stmt = $conn->prepare("
        SELECT 
            a.*,
            c.category_name,
            c.depreciation_method as cat_depreciation_method,
            d.department_name,
            d.department_code,
            u.full_name as custodian_name,
            u.phone1 as custodian_phone,
            s.supplier_name,
            created_user.full_name as created_by_name,
            approved_user.full_name as approved_by_name,
            DATEDIFF(CURDATE(), a.purchase_date) as age_days,
            DATEDIFF(CURDATE(), a.last_depreciation_date) as days_since_depreciation
        FROM fixed_assets a
        LEFT JOIN asset_categories c ON a.category_id = c.category_id
        LEFT JOIN departments d ON a.department_id = d.department_id
        LEFT JOIN users u ON a.custodian_id = u.user_id
        LEFT JOIN suppliers s ON a.supplier_id = s.supplier_id
        LEFT JOIN users created_user ON a.created_by = created_user.user_id
        LEFT JOIN users approved_user ON a.approved_by = approved_user.user_id
        WHERE a.asset_id = ? AND a.company_id = ?
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

// ==================== FETCH DEPRECIATION HISTORY ====================
$depreciation_history = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            d.*,
            u.full_name as created_by_name
        FROM asset_depreciation d
        LEFT JOIN users u ON d.created_by = u.user_id
        WHERE d.asset_id = ?
        ORDER BY d.period_date DESC
        LIMIT 12
    ");
    $stmt->execute([$asset_id]);
    $depreciation_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Depreciation history error: " . $e->getMessage());
}

// ==================== FETCH MAINTENANCE HISTORY ====================
$maintenance_history = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            m.*,
            u.full_name as created_by_name
        FROM asset_maintenance m
        LEFT JOIN users u ON m.created_by = u.user_id
        WHERE m.asset_id = ?
        ORDER BY m.maintenance_date DESC
        LIMIT 10
    ");
    $stmt->execute([$asset_id]);
    $maintenance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Maintenance history error: " . $e->getMessage());
}

// Calculate age
$age_years = floor($asset['age_days'] / 365);
$age_months = floor(($asset['age_days'] % 365) / 30);

// Calculate depreciation percentage
$depreciation_percent = $asset['total_cost'] > 0 
    ? ($asset['accumulated_depreciation'] / $asset['total_cost']) * 100 
    : 0;

$page_title = 'View Asset - ' . $asset['asset_number'];
require_once '../../includes/header.php';
?>

<style>
.asset-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.asset-number {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.asset-name {
    font-size: 1.25rem;
    opacity: 0.95;
}

.info-card {
    background: #fff;
    border-radius: 6px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #007bff;
}

.info-card h5 {
    font-size: 1rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
}

.info-row {
    display: flex;
    padding: 0.6rem 0;
    border-bottom: 1px solid #f8f9fa;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    flex: 0 0 200px;
    font-weight: 600;
    color: #6c757d;
    font-size: 0.85rem;
}

.info-value {
    flex: 1;
    color: #2c3e50;
    font-size: 0.85rem;
}

.status-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.inactive {
    background: #e2e3e5;
    color: #383d41;
}

.status-badge.under_maintenance {
    background: #fff3cd;
    color: #856404;
}

.status-badge.disposed {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.stolen,
.status-badge.damaged {
    background: #f8d7da;
    color: #721c24;
}

.approval-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.approval-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.approval-badge.approved {
    background: #d4edda;
    color: #155724;
}

.approval-badge.rejected {
    background: #f8d7da;
    color: #721c24;
}

.value-card {
    background: #fff;
    border-radius: 6px;
    padding: 1rem;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-top: 3px solid #007bff;
}

.value-amount {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.value-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.depreciation-chart {
    background: #fff;
    border-radius: 6px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.progress-bar-container {
    height: 25px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
    margin: 1rem 0;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #28a745, #ffc107, #dc3545);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 0.75rem;
    transition: width 0.3s ease;
}

.table-sm {
    font-size: 0.85rem;
}

.table-sm th {
    background: #f8f9fa;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.3px;
    padding: 0.6rem 0.5rem;
}

.table-sm td {
    padding: 0.6rem 0.5rem;
}

.maintenance-type {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.maintenance-type.preventive {
    background: #d4edda;
    color: #155724;
}

.maintenance-type.corrective {
    background: #fff3cd;
    color: #856404;
}

.maintenance-type.upgrade {
    background: #d1ecf1;
    color: #0c5460;
}

.maintenance-type.inspection {
    background: #e2e3e5;
    color: #383d41;
}

.btn-action-group {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

@media (max-width: 768px) {
    .btn-action-group {
        flex-direction: column;
    }
    
    .btn-action-group .btn {
        width: 100%;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-laptop me-2"></i>Asset Details
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <div class="btn-action-group justify-content-end">
                    <a href="edit.php?id=<?= $asset_id ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-edit me-1"></i>Edit Asset
                    </a>
                    <a href="maintenance.php?id=<?= $asset_id ?>" class="btn btn-info btn-sm">
                        <i class="fas fa-wrench me-1"></i>Maintenance
                    </a>
                    <a href="index.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Asset Header -->
    <div class="asset-header">
        <div class="asset-number"><?= htmlspecialchars($asset['asset_number']) ?></div>
        <div class="asset-name"><?= htmlspecialchars($asset['asset_name']) ?></div>
        <?php if ($asset['serial_number']): ?>
            <div class="mt-2" style="font-size: 0.9rem; opacity: 0.9;">
                <i class="fas fa-barcode me-2"></i>Serial: <?= htmlspecialchars($asset['serial_number']) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="row">
        <!-- Left Column -->
        <div class="col-md-8">
            
            <!-- Basic Information -->
            <div class="info-card">
                <h5><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                <div class="info-row">
                    <div class="info-label">Asset Number</div>
                    <div class="info-value"><strong><?= htmlspecialchars($asset['asset_number']) ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Asset Name</div>
                    <div class="info-value"><strong><?= htmlspecialchars($asset['asset_name']) ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Category</div>
                    <div class="info-value"><?= htmlspecialchars($asset['category_name'] ?: '-') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Description</div>
                    <div class="info-value"><?= htmlspecialchars($asset['description'] ?: '-') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="status-badge <?= $asset['status'] ?>">
                            <?= ucfirst(str_replace('_', ' ', $asset['status'])) ?>
                        </span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Approval Status</div>
                    <div class="info-value">
                        <span class="approval-badge <?= $asset['approval_status'] ?>">
                            <?= ucfirst($asset['approval_status']) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Asset Details -->
            <div class="info-card">
                <h5><i class="fas fa-barcode me-2"></i>Asset Details</h5>
                <div class="info-row">
                    <div class="info-label">Serial Number</div>
                    <div class="info-value"><?= htmlspecialchars($asset['serial_number'] ?: '-') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Model Number</div>
                    <div class="info-value"><?= htmlspecialchars($asset['model_number'] ?: '-') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Manufacturer</div>
                    <div class="info-value"><?= htmlspecialchars($asset['manufacturer'] ?: '-') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Purchase Date</div>
                    <div class="info-value">
                        <?= date('d M Y', strtotime($asset['purchase_date'])) ?>
                        <span class="badge bg-secondary ms-2"><?= $age_years ?>y <?= $age_months ?>m old</span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Warranty Expiry</div>
                    <div class="info-value">
                        <?php if ($asset['warranty_expiry_date']): ?>
                            <?= date('d M Y', strtotime($asset['warranty_expiry_date'])) ?>
                            <?php if (strtotime($asset['warranty_expiry_date']) < time()): ?>
                                <span class="badge bg-danger ms-2">Expired</span>
                            <?php else: ?>
                                <span class="badge bg-success ms-2">Active</span>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Purchase Information -->
            <div class="info-card">
                <h5><i class="fas fa-shopping-cart me-2"></i>Purchase Information</h5>
                <div class="info-row">
                    <div class="info-label">Supplier</div>
                    <div class="info-value"><?= htmlspecialchars($asset['supplier_name'] ?: '-') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Invoice Number</div>
                    <div class="info-value"><?= htmlspecialchars($asset['invoice_number'] ?: '-') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Purchase Cost</div>
                    <div class="info-value"><strong>TSH <?= number_format($asset['purchase_cost'], 2) ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Installation Cost</div>
                    <div class="info-value">TSH <?= number_format($asset['installation_cost'], 2) ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Total Cost</div>
                    <div class="info-value"><strong>TSH <?= number_format($asset['total_cost'], 2) ?></strong></div>
                </div>
            </div>

            <!-- Location & Assignment -->
            <div class="info-card">
                <h5><i class="fas fa-map-marker-alt me-2"></i>Location & Assignment</h5>
                <div class="info-row">
                    <div class="info-label">Location</div>
                    <div class="info-value"><?= htmlspecialchars($asset['location'] ?: '-') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Department</div>
                    <div class="info-value">
                        <?php if ($asset['department_name']): ?>
                            <?= htmlspecialchars($asset['department_name']) ?>
                            <?php if ($asset['department_code']): ?>
                                <span class="text-muted">(<?= htmlspecialchars($asset['department_code']) ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Custodian</div>
                    <div class="info-value">
                        <?php if ($asset['custodian_name']): ?>
                            <i class="fas fa-user me-1"></i><?= htmlspecialchars($asset['custodian_name']) ?>
                            <?php if ($asset['custodian_phone']): ?>
                                <br><small class="text-muted"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($asset['custodian_phone']) ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Depreciation History -->
            <?php if (!empty($depreciation_history)): ?>
            <div class="info-card">
                <h5><i class="fas fa-chart-line me-2"></i>Depreciation History (Last 12 Months)</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th class="text-end">Depreciation</th>
                                <th class="text-end">Accumulated</th>
                                <th class="text-end">Book Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($depreciation_history as $dep): ?>
                            <tr>
                                <td><?= date('M Y', strtotime($dep['period_date'])) ?></td>
                                <td class="text-end"><?= number_format($dep['depreciation_amount'], 2) ?></td>
                                <td class="text-end"><?= number_format($dep['accumulated_depreciation'], 2) ?></td>
                                <td class="text-end"><strong><?= number_format($dep['book_value'], 2) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Maintenance History -->
            <?php if (!empty($maintenance_history)): ?>
            <div class="info-card">
                <h5><i class="fas fa-wrench me-2"></i>Maintenance History</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th class="text-end">Cost</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenance_history as $maint): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($maint['maintenance_date'])) ?></td>
                                <td>
                                    <span class="maintenance-type <?= $maint['maintenance_type'] ?>">
                                        <?= ucfirst($maint['maintenance_type']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($maint['description']) ?></td>
                                <td class="text-end"><?= number_format($maint['cost'], 2) ?></td>
                                <td><?= ucfirst($maint['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-end mt-2">
                    <a href="maintenance.php?id=<?= $asset_id ?>" class="btn btn-sm btn-info">
                        <i class="fas fa-plus me-1"></i>Add Maintenance
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Additional Notes -->
            <?php if ($asset['notes']): ?>
            <div class="info-card">
                <h5><i class="fas fa-sticky-note me-2"></i>Additional Notes</h5>
                <p style="white-space: pre-wrap;"><?= htmlspecialchars($asset['notes']) ?></p>
            </div>
            <?php endif; ?>

        </div>

        <!-- Right Column -->
        <div class="col-md-4">
            
            <!-- Financial Summary -->
            <div class="row g-2 mb-3">
                <div class="col-12">
                    <div class="value-card" style="border-top-color: #28a745;">
                        <div class="value-amount"><?= number_format($asset['current_book_value'], 0) ?></div>
                        <div class="value-label">Current Book Value (TSH)</div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="value-card" style="border-top-color: #dc3545;">
                        <div class="value-amount"><?= number_format($asset['accumulated_depreciation'], 0) ?></div>
                        <div class="value-label">Accumulated Depreciation</div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="value-card" style="border-top-color: #ffc107;">
                        <div class="value-amount"><?= number_format($asset['salvage_value'], 0) ?></div>
                        <div class="value-label">Salvage Value (TSH)</div>
                    </div>
                </div>
            </div>

            <!-- Depreciation Progress -->
            <div class="depreciation-chart">
                <h6 class="mb-3"><i class="fas fa-chart-line me-2"></i>Depreciation Progress</h6>
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" style="width: <?= min($depreciation_percent, 100) ?>%">
                        <?= number_format($depreciation_percent, 1) ?>%
                    </div>
                </div>
                <div class="text-center mt-2">
                    <small class="text-muted">
                        <?= number_format($asset['accumulated_depreciation'], 0) ?> of 
                        <?= number_format($asset['total_cost'] - $asset['salvage_value'], 0) ?> depreciated
                    </small>
                </div>
            </div>

            <!-- Depreciation Details -->
            <div class="info-card">
                <h5><i class="fas fa-calculator me-2"></i>Depreciation Details</h5>
                <div class="info-row">
                    <div class="info-label">Method</div>
                    <div class="info-value"><?= ucfirst(str_replace('_', ' ', $asset['depreciation_method'])) ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Useful Life</div>
                    <div class="info-value"><?= $asset['useful_life_years'] ?> years</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Last Depreciation</div>
                    <div class="info-value">
                        <?php if ($asset['last_depreciation_date']): ?>
                            <?= date('d M Y', strtotime($asset['last_depreciation_date'])) ?>
                        <?php else: ?>
                            Never
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Accounting Info -->
            <div class="info-card">
                <h5><i class="fas fa-book me-2"></i>Accounting</h5>
                <div class="info-row">
                    <div class="info-label">Asset Account</div>
                    <div class="info-value"><code><?= htmlspecialchars($asset['account_code']) ?></code></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Depreciation Account</div>
                    <div class="info-value"><code><?= htmlspecialchars($asset['depreciation_account_code']) ?></code></div>
                </div>
            </div>

            <!-- System Info -->
            <div class="info-card">
                <h5><i class="fas fa-info-circle me-2"></i>System Information</h5>
                <div class="info-row">
                    <div class="info-label">Created By</div>
                    <div class="info-value"><?= htmlspecialchars($asset['created_by_name'] ?: '-') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Created At</div>
                    <div class="info-value"><?= date('d M Y, H:i', strtotime($asset['created_at'])) ?></div>
                </div>
                <?php if ($asset['approved_by']): ?>
                <div class="info-row">
                    <div class="info-label">Approved By</div>
                    <div class="info-value"><?= htmlspecialchars($asset['approved_by_name']) ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Approved At</div>
                    <div class="info-value"><?= date('d M Y, H:i', strtotime($asset['approved_at'])) ?></div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>