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
        SELECT a.*, c.category_name
        FROM fixed_assets a
        LEFT JOIN asset_categories c ON a.category_id = c.category_id
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

// ==================== HANDLE ADD MAINTENANCE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $maintenance_date = $_POST['maintenance_date'];
        $maintenance_type = $_POST['maintenance_type'];
        $description = trim($_POST['description']);
        $cost = (float)$_POST['cost'];
        
        if (!$maintenance_date || !$maintenance_type || !$description) {
            throw new Exception("Please fill in all required fields");
        }
        
        // Insert maintenance record
        $stmt = $conn->prepare("
            INSERT INTO asset_maintenance (
                asset_id, company_id, maintenance_date, maintenance_type,
                description, cost, vendor, next_maintenance_date,
                status, notes, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $asset_id,
            $company_id,
            $maintenance_date,
            $maintenance_type,
            $description,
            $cost,
            trim($_POST['vendor']),
            !empty($_POST['next_maintenance_date']) ? $_POST['next_maintenance_date'] : null,
            $_POST['status'],
            trim($_POST['notes']),
            $user_id
        ]);
        
        $maintenance_id = $conn->lastInsertId();
        
        // Update asset status if maintenance is in progress
        if ($_POST['status'] == 'in_progress' && isset($_POST['update_asset_status'])) {
            $stmt = $conn->prepare("
                UPDATE fixed_assets 
                SET status = 'under_maintenance', updated_at = NOW(), updated_by = ?
                WHERE asset_id = ? AND company_id = ?
            ");
            $stmt->execute([$user_id, $asset_id, $company_id]);
        }
        
        // If maintenance is completed, restore asset status to active
        if ($_POST['status'] == 'completed' && isset($_POST['update_asset_status'])) {
            $stmt = $conn->prepare("
                UPDATE fixed_assets 
                SET status = 'active', updated_at = NOW(), updated_by = ?
                WHERE asset_id = ? AND company_id = ?
            ");
            $stmt->execute([$user_id, $asset_id, $company_id]);
        }
        
        $conn->commit();
        $success = "Maintenance record added successfully!";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
        error_log("Maintenance add error: " . $e->getMessage());
    }
}

// ==================== HANDLE UPDATE MAINTENANCE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_maintenance'])) {
    try {
        $conn->beginTransaction();
        
        $maintenance_id = (int)$_POST['maintenance_id'];
        $status = $_POST['status'];
        
        // Update maintenance record
        $stmt = $conn->prepare("
            UPDATE asset_maintenance 
            SET status = ?, updated_at = NOW(), updated_by = ?
            WHERE maintenance_id = ? AND asset_id = ? AND company_id = ?
        ");
        $stmt->execute([$status, $user_id, $maintenance_id, $asset_id, $company_id]);
        
        // Update asset status if needed
        if ($status == 'completed' && isset($_POST['restore_asset_status'])) {
            $stmt = $conn->prepare("
                UPDATE fixed_assets 
                SET status = 'active', updated_at = NOW(), updated_by = ?
                WHERE asset_id = ? AND company_id = ?
            ");
            $stmt->execute([$user_id, $asset_id, $company_id]);
        }
        
        $conn->commit();
        $success = "Maintenance status updated successfully!";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
        error_log("Maintenance update error: " . $e->getMessage());
    }
}

// ==================== FETCH MAINTENANCE HISTORY ====================
$maintenance_records = [];
$total_maintenance_cost = 0;
try {
    $stmt = $conn->prepare("
        SELECT 
            m.*,
            u.full_name as created_by_name
        FROM asset_maintenance m
        LEFT JOIN users u ON m.created_by = u.user_id
        WHERE m.asset_id = ? AND m.company_id = ?
        ORDER BY m.maintenance_date DESC
    ");
    $stmt->execute([$asset_id, $company_id]);
    $maintenance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total maintenance cost
    foreach ($maintenance_records as $record) {
        $total_maintenance_cost += $record['cost'];
    }
} catch (Exception $e) {
    error_log("Maintenance fetch error: " . $e->getMessage());
}

// ==================== STATISTICS ====================
$stats = [
    'total_records' => count($maintenance_records),
    'preventive' => 0,
    'corrective' => 0,
    'in_progress' => 0,
    'total_cost' => $total_maintenance_cost
];

foreach ($maintenance_records as $record) {
    if ($record['maintenance_type'] == 'preventive') $stats['preventive']++;
    if ($record['maintenance_type'] == 'corrective') $stats['corrective']++;
    if ($record['status'] == 'in_progress') $stats['in_progress']++;
}

$page_title = 'Asset Maintenance - ' . $asset['asset_number'];
require_once '../../includes/header.php';
?>

<style>
.asset-info-bar {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 6px;
    margin-bottom: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.asset-info-bar h5 {
    margin: 0;
    font-size: 1.1rem;
}

.asset-info-bar small {
    opacity: 0.9;
    font-size: 0.85rem;
}

.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 1rem;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-top: 3px solid #007bff;
}

.stats-value {
    font-size: 1.75rem;
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

.form-section {
    background: #fff;
    border-radius: 6px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #28a745;
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

.maintenance-card {
    background: #fff;
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #17a2b8;
}

.maintenance-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid #e9ecef;
}

.maintenance-date {
    font-size: 0.9rem;
    font-weight: 600;
    color: #2c3e50;
}

.maintenance-type {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
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

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.scheduled {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.in_progress {
    background: #fff3cd;
    color: #856404;
}

.status-badge.completed {
    background: #d4edda;
    color: #155724;
}

.status-badge.cancelled {
    background: #f8d7da;
    color: #721c24;
}

.maintenance-cost {
    font-size: 1rem;
    font-weight: 700;
    color: #dc3545;
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
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-wrench me-2"></i>Asset Maintenance
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

    <!-- Asset Info Bar -->
    <div class="asset-info-bar">
        <div>
            <h5><?= htmlspecialchars($asset['asset_number']) ?> - <?= htmlspecialchars($asset['asset_name']) ?></h5>
            <small>
                <i class="fas fa-tag me-1"></i><?= htmlspecialchars($asset['category_name']) ?>
                <?php if ($asset['serial_number']): ?>
                    <span class="mx-2">|</span>
                    <i class="fas fa-barcode me-1"></i><?= htmlspecialchars($asset['serial_number']) ?>
                <?php endif; ?>
            </small>
        </div>
        <div>
            <span class="badge" style="background: rgba(255,255,255,0.2); font-size: 0.85rem; padding: 0.5rem 1rem;">
                Status: <?= ucfirst(str_replace('_', ' ', $asset['status'])) ?>
            </span>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="stats-card" style="border-top-color: #007bff;">
                <div class="stats-value"><?= $stats['total_records'] ?></div>
                <div class="stats-label">Total Records</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="border-top-color: #28a745;">
                <div class="stats-value"><?= $stats['preventive'] ?></div>
                <div class="stats-label">Preventive</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="border-top-color: #ffc107;">
                <div class="stats-value"><?= $stats['corrective'] ?></div>
                <div class="stats-label">Corrective</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="border-top-color: #dc3545;">
                <div class="stats-value"><?= number_format($stats['total_cost'], 0) ?></div>
                <div class="stats-label">Total Cost (TSH)</div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Add Maintenance Form -->
        <div class="col-md-5">
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-plus-circle me-2"></i>Add Maintenance Record
                </div>
                
                <form method="POST">
                    <input type="hidden" name="add_maintenance" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">Maintenance Date <span class="text-danger">*</span></label>
                        <input type="date" name="maintenance_date" class="form-control" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Maintenance Type <span class="text-danger">*</span></label>
                        <select name="maintenance_type" class="form-select" required>
                            <option value="preventive">Preventive</option>
                            <option value="corrective">Corrective</option>
                            <option value="upgrade">Upgrade</option>
                            <option value="inspection">Inspection</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="3" 
                                  placeholder="Describe the maintenance work performed..." required></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cost (TSH)</label>
                            <input type="number" name="cost" class="form-control" 
                                   step="0.01" min="0" value="0">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="scheduled">Scheduled</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed" selected>Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Vendor/Service Provider</label>
                        <input type="text" name="vendor" class="form-control" 
                               placeholder="Name of vendor or service provider">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Next Maintenance Date</label>
                        <input type="date" name="next_maintenance_date" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Additional Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="update_asset_status" 
                               id="updateAssetStatus" checked>
                        <label class="form-check-label" for="updateAssetStatus" style="font-size: 0.85rem;">
                            Update asset status based on maintenance status
                        </label>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-plus me-2"></i>Add Maintenance Record
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Maintenance History -->
        <div class="col-md-7">
            <div style="background: #fff; border-radius: 6px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                <div class="section-title" style="border-left: 3px solid #17a2b8; padding-left: 0.75rem;">
                    <i class="fas fa-history me-2"></i>Maintenance History
                </div>

                <?php if (empty($maintenance_records)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No maintenance records found</p>
                        <small class="text-muted">Add your first maintenance record using the form on the left</small>
                    </div>
                <?php else: ?>
                    <div style="max-height: 700px; overflow-y: auto;">
                        <?php foreach ($maintenance_records as $record): ?>
                        <div class="maintenance-card">
                            <div class="maintenance-header">
                                <div>
                                    <div class="maintenance-date">
                                        <i class="fas fa-calendar me-1"></i>
                                        <?= date('d M Y', strtotime($record['maintenance_date'])) ?>
                                    </div>
                                    <div class="mt-1">
                                        <span class="maintenance-type <?= $record['maintenance_type'] ?>">
                                            <?= ucfirst($record['maintenance_type']) ?>
                                        </span>
                                        <span class="status-badge <?= $record['status'] ?> ms-2">
                                            <?= ucfirst(str_replace('_', ' ', $record['status'])) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="maintenance-cost">
                                        TSH <?= number_format($record['cost'], 2) ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-2">
                                <strong style="font-size: 0.85rem;">Description:</strong>
                                <p style="font-size: 0.85rem; margin: 0.25rem 0 0 0; color: #495057;">
                                    <?= nl2br(htmlspecialchars($record['description'])) ?>
                                </p>
                            </div>
                            
                            <?php if ($record['vendor']): ?>
                            <div class="mb-2">
                                <small class="text-muted">
                                    <i class="fas fa-user-tie me-1"></i>Vendor: 
                                    <strong><?= htmlspecialchars($record['vendor']) ?></strong>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($record['next_maintenance_date']): ?>
                            <div class="mb-2">
                                <small class="text-muted">
                                    <i class="fas fa-calendar-check me-1"></i>Next Maintenance: 
                                    <strong><?= date('d M Y', strtotime($record['next_maintenance_date'])) ?></strong>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($record['notes']): ?>
                            <div class="mb-2">
                                <small class="text-muted">
                                    <i class="fas fa-sticky-note me-1"></i>
                                    <?= nl2br(htmlspecialchars($record['notes'])) ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2" 
                                 style="border-top: 1px solid #e9ecef; font-size: 0.75rem;">
                                <small class="text-muted">
                                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($record['created_by_name']) ?>
                                    <span class="mx-1">•</span>
                                    <?= date('d M Y H:i', strtotime($record['created_at'])) ?>
                                </small>
                                
                                <?php if ($record['status'] == 'in_progress'): ?>
                                <form method="POST" class="d-inline" 
                                      onsubmit="return confirm('Mark this maintenance as completed?')">
                                    <input type="hidden" name="update_maintenance" value="1">
                                    <input type="hidden" name="maintenance_id" value="<?= $record['maintenance_id'] ?>">
                                    <input type="hidden" name="status" value="completed">
                                    <input type="hidden" name="restore_asset_status" value="1">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="fas fa-check me-1"></i>Complete
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>