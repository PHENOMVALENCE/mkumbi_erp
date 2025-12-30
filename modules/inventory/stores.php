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

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        if (empty($_POST['store_name'])) {
            $errors[] = "Store name is required";
        }
        if (empty($_POST['store_code'])) {
            $errors[] = "Store code is required";
        }
        
        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    // Check for duplicate store code
                    $stmt = $conn->prepare("SELECT store_id FROM stores WHERE company_id = ? AND store_code = ?");
                    $stmt->execute([$company_id, $_POST['store_code']]);
                    if ($stmt->fetch()) {
                        $errors[] = "Store code already exists";
                    } else {
                        $sql = "INSERT INTO stores (
                            company_id, store_code, store_name, store_type,
                            location, address, phone, email,
                            manager_name, capacity, is_active, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)";
                        
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([
                            $company_id,
                            $_POST['store_code'],
                            $_POST['store_name'],
                            $_POST['store_type'] ?? 'warehouse',
                            $_POST['location'] ?? null,
                            $_POST['address'] ?? null,
                            $_POST['phone'] ?? null,
                            $_POST['email'] ?? null,
                            $_POST['manager_name'] ?? null,
                            !empty($_POST['capacity']) ? floatval($_POST['capacity']) : null,
                            $_SESSION['user_id']
                        ]);
                        
                        $success = "Store created successfully!";
                    }
                } else {
                    $sql = "UPDATE stores SET 
                        store_code = ?, store_name = ?, store_type = ?,
                        location = ?, address = ?, phone = ?, email = ?,
                        manager_name = ?, capacity = ?, is_active = ?,
                        updated_at = NOW()
                        WHERE store_id = ? AND company_id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $_POST['store_code'],
                        $_POST['store_name'],
                        $_POST['store_type'] ?? 'warehouse',
                        $_POST['location'] ?? null,
                        $_POST['address'] ?? null,
                        $_POST['phone'] ?? null,
                        $_POST['email'] ?? null,
                        $_POST['manager_name'] ?? null,
                        !empty($_POST['capacity']) ? floatval($_POST['capacity']) : null,
                        isset($_POST['is_active']) ? 1 : 0,
                        $_POST['store_id'],
                        $company_id
                    ]);
                    
                    $success = "Store updated successfully!";
                }
            } catch (PDOException $e) {
                error_log("Error saving store: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        try {
            // Check if store has inventory
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count FROM inventory 
                WHERE store_id = ? AND quantity_on_hand > 0
            ");
            $stmt->execute([$_POST['store_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                $errors[] = "Cannot delete store with existing inventory. Transfer items first.";
            } else {
                $stmt = $conn->prepare("DELETE FROM stores WHERE store_id = ? AND company_id = ?");
                $stmt->execute([$_POST['store_id'], $company_id]);
                $success = "Store deleted successfully!";
            }
        } catch (PDOException $e) {
            error_log("Error deleting store: " . $e->getMessage());
            $errors[] = "Error deleting store";
        }
    }
}

// Build filter conditions
$where_conditions = ["s.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['store_type'])) {
    $where_conditions[] = "s.store_type = ?";
    $params[] = $_GET['store_type'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(s.store_code LIKE ? OR s.store_name LIKE ? OR s.location LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
    $where_conditions[] = "s.is_active = ?";
    $params[] = intval($_GET['is_active']);
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch stores
try {
    $stmt = $conn->prepare("
        SELECT s.*,
               COALESCE(SUM(i.quantity_on_hand), 0) as total_items,
               COUNT(DISTINCT i.item_id) as unique_items,
               COALESCE(SUM(i.quantity_on_hand * i.unit_cost), 0) as inventory_value,
               u.full_name as created_by_name
        FROM stores s
        LEFT JOIN inventory i ON s.store_id = i.store_id
        LEFT JOIN users u ON s.created_by = u.user_id
        WHERE $where_clause
        GROUP BY s.store_id
        ORDER BY s.is_active DESC, s.created_at DESC
    ");
    $stmt->execute($params);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching stores: " . $e->getMessage());
    $stores = [];
}

// Calculate statistics
$stats = [
    'total' => count($stores),
    'active' => 0,
    'total_value' => 0,
    'total_items' => 0
];

foreach ($stores as $store) {
    if ($store['is_active']) {
        $stats['active']++;
    }
    $stats['total_value'] += $store['inventory_value'];
    $stats['total_items'] += $store['total_items'];
}

$page_title = 'Store/Warehouse Management';
require_once '../../includes/header.php';
?>

<style>
.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 15px;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
}

.stats-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    border-left: 5px solid;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stats-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 100px;
    height: 100px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    transform: translate(30%, -30%);
}

.stats-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 35px rgba(0,0,0,0.15);
}

.stats-card.primary { border-left-color: #667eea; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.warning { border-left-color: #ffc107; }

.stats-number {
    font-size: 2.5rem;
    font-weight: 800;
    color: #2c3e50;
    line-height: 1;
    margin-bottom: 0.5rem;
}

.stats-label {
    color: #6c757d;
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.stats-icon {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 3rem;
    opacity: 0.15;
}

.filter-section {
    background: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
}

.store-card {
    background: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    border-left: 5px solid #667eea;
    position: relative;
}

.store-card:hover {
    box-shadow: 0 10px 35px rgba(0,0,0,0.15);
    transform: translateX(5px);
}

.store-card.inactive {
    border-left-color: #6c757d;
    opacity: 0.7;
}

.store-code {
    font-family: 'Courier New', monospace;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
    letter-spacing: 1px;
}

.store-type-badge {
    padding: 0.4rem 1rem;
    border-radius: 25px;
    font-size: 0.85rem;
    font-weight: 600;
}

.store-type-badge.warehouse {
    background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
    color: #0c5460;
    border: 2px solid rgba(23, 162, 184, 0.2);
}

.store-type-badge.retail {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    color: #155724;
    border: 2px solid rgba(40, 167, 69, 0.2);
}

.store-type-badge.distribution {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
    color: #856404;
    border: 2px solid rgba(255, 193, 7, 0.2);
}

.store-type-badge.transit {
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    color: #721c24;
    border: 2px solid rgba(220, 53, 69, 0.2);
}

.capacity-indicator {
    background: #f8f9fa;
    height: 12px;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 0.5rem;
    border: 2px solid #e9ecef;
}

.capacity-fill {
    height: 100%;
    background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
    transition: width 0.3s;
    position: relative;
}

.capacity-fill::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: shimmer 2s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.capacity-fill.warning {
    background: linear-gradient(90deg, #ffc107 0%, #fd7e14 100%);
}

.capacity-fill.danger {
    background: linear-gradient(90deg, #dc3545 0%, #c82333 100%);
}

.metric-box {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 10px;
    border: 2px solid #e9ecef;
    text-align: center;
    transition: all 0.3s ease;
}

.metric-box:hover {
    border-color: #667eea;
    transform: translateY(-2px);
}

.metric-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.metric-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2c3e50;
}

.action-buttons .btn {
    margin-bottom: 0.5rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.action-buttons .btn:hover {
    transform: translateX(5px);
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px 15px 0 0;
}

.form-section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #667eea;
    border-bottom: 3px solid #667eea;
    padding-bottom: 0.5rem;
    margin-bottom: 1.5rem;
}

.empty-state {
    text-align: center;
    padding: 5rem 2rem;
}

.empty-state i {
    font-size: 6rem;
    color: #dee2e6;
    margin-bottom: 2rem;
}

.badge-status {
    padding: 0.4rem 1rem;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.85rem;
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="row align-items-center">
        <div class="col-md-6">
            <h1 class="mb-2">
                <i class="fas fa-warehouse me-3"></i>Store & Warehouse Management
            </h1>
            <p class="mb-0 opacity-75">
                <i class="fas fa-info-circle me-2"></i>Manage storage locations and inventory facilities
            </p>
        </div>
        <div class="col-md-6 text-end">
            <a href="stock.php" class="btn btn-light btn-lg me-2">
                <i class="fas fa-boxes me-2"></i>Stock Levels
            </a>
            <button type="button" class="btn btn-warning btn-lg" data-bs-toggle="modal" data-bs-target="#storeModal">
                <i class="fas fa-plus-circle me-2"></i>Add New Store
            </button>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container-fluid">

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Error!</h5>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stats-card primary">
                <i class="fas fa-warehouse stats-icon"></i>
                <div class="stats-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stats-label">Total Stores</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stats-card success">
                <i class="fas fa-check-circle stats-icon"></i>
                <div class="stats-number"><?php echo number_format($stats['active']); ?></div>
                <div class="stats-label">Active Locations</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stats-card info">
                <i class="fas fa-boxes stats-icon"></i>
                <div class="stats-number"><?php echo number_format($stats['total_items']); ?></div>
                <div class="stats-label">Total Items Stored</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stats-card warning">
                <i class="fas fa-dollar-sign stats-icon"></i>
                <div class="stats-number">TSH <?php echo number_format($stats['total_value'] / 1000000, 2); ?>M</div>
                <div class="stats-label">Total Inventory Value</div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Stores</h5>
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Store code, name, location..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Store Type</label>
                <select name="store_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="warehouse" <?php echo (isset($_GET['store_type']) && $_GET['store_type'] == 'warehouse') ? 'selected' : ''; ?>>Warehouse</option>
                    <option value="retail" <?php echo (isset($_GET['store_type']) && $_GET['store_type'] == 'retail') ? 'selected' : ''; ?>>Retail Store</option>
                    <option value="distribution" <?php echo (isset($_GET['store_type']) && $_GET['store_type'] == 'distribution') ? 'selected' : ''; ?>>Distribution Center</option>
                    <option value="transit" <?php echo (isset($_GET['store_type']) && $_GET['store_type'] == 'transit') ? 'selected' : ''; ?>>Transit Point</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Status</label>
                <select name="is_active" class="form-select">
                    <option value="">All Status</option>
                    <option value="1" <?php echo (isset($_GET['is_active']) && $_GET['is_active'] == '1') ? 'selected' : ''; ?>>Active Only</option>
                    <option value="0" <?php echo (isset($_GET['is_active']) && $_GET['is_active'] == '0') ? 'selected' : ''; ?>>Inactive Only</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="fas fa-search me-2"></i>Apply Filters
                </button>
                <a href="stores.php" class="btn btn-outline-secondary">
                    <i class="fas fa-redo"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Stores List -->
    <?php if (empty($stores)): ?>
    <div class="empty-state">
        <i class="fas fa-warehouse"></i>
        <h4>No Stores Found</h4>
        <p class="text-muted mb-4">Create your first store or warehouse to start managing inventory</p>
        <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#storeModal">
            <i class="fas fa-plus-circle me-2"></i>Add First Store
        </button>
    </div>
    <?php else: ?>
        <?php foreach ($stores as $store): 
            $utilization = 0;
            if ($store['capacity'] && $store['capacity'] > 0) {
                $utilization = ($store['total_items'] / $store['capacity']) * 100;
            }
            
            $capacity_class = '';
            if ($utilization >= 90) {
                $capacity_class = 'danger';
            } elseif ($utilization >= 75) {
                $capacity_class = 'warning';
            }
        ?>
        <div class="store-card <?php echo $store['is_active'] ? '' : 'inactive'; ?>">
            <div class="row">
                <div class="col-lg-9">
                    <!-- Header -->
                    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
                        <span class="store-code"><?php echo htmlspecialchars($store['store_code']); ?></span>
                        <span class="store-type-badge <?php echo $store['store_type']; ?>">
                            <i class="fas fa-tag me-1"></i><?php echo ucfirst($store['store_type']); ?>
                        </span>
                        <span class="badge-status <?php echo $store['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo $store['is_active'] ? '✓ Active' : '✕ Inactive'; ?>
                        </span>
                        <span class="ms-auto text-muted small">
                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($store['created_by_name'] ?? 'System'); ?>
                        </span>
                    </div>
                    
                    <!-- Store Name -->
                    <h4 class="mb-2"><?php echo htmlspecialchars($store['store_name']); ?></h4>
                    
                    <!-- Location & Contact Info -->
                    <div class="text-muted mb-3">
                        <?php if (!empty($store['location'])): ?>
                            <i class="fas fa-map-marker-alt me-2"></i><?php echo htmlspecialchars($store['location']); ?>
                        <?php endif; ?>
                        <?php if (!empty($store['manager_name'])): ?>
                            <span class="mx-2">|</span>
                            <i class="fas fa-user-tie me-1"></i>Manager: <?php echo htmlspecialchars($store['manager_name']); ?>
                        <?php endif; ?>
                        <?php if (!empty($store['phone'])): ?>
                            <span class="mx-2">|</span>
                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($store['phone']); ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($store['address'])): ?>
                    <div class="text-muted small mb-3">
                        <i class="fas fa-map me-2"></i><?php echo htmlspecialchars($store['address']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Metrics Grid -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Total Items</div>
                                <div class="metric-value text-primary">
                                    <?php echo number_format($store['total_items']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Unique Items</div>
                                <div class="metric-value text-info">
                                    <?php echo number_format($store['unique_items']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Inventory Value</div>
                                <div class="metric-value text-success">
                                    TSH <?php echo number_format($store['inventory_value'] / 1000, 0); ?>K
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Capacity</div>
                                <div class="metric-value">
                                    <?php echo $store['capacity'] ? number_format($store['capacity']) : 'N/A'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Capacity Utilization -->
                    <?php if ($store['capacity']): ?>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold">
                                <i class="fas fa-chart-pie me-2"></i>Capacity Utilization
                            </span>
                            <span class="badge bg-<?php echo $capacity_class ?: 'success'; ?> rounded-pill">
                                <?php echo number_format($utilization, 1); ?>%
                            </span>
                        </div>
                        <div class="capacity-indicator">
                            <div class="capacity-fill <?php echo $capacity_class; ?>" 
                                 style="width: <?php echo min($utilization, 100); ?>%"></div>
                        </div>
                        <small class="text-muted">
                            <?php echo number_format($store['total_items']); ?> / <?php echo number_format($store['capacity']); ?> items
                            <?php if ($utilization >= 90): ?>
                                <span class="text-danger ms-2">⚠️ Near capacity!</span>
                            <?php elseif ($utilization >= 75): ?>
                                <span class="text-warning ms-2">⚠️ High utilization</span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Action Buttons -->
                <div class="col-lg-3">
                    <div class="action-buttons d-grid gap-2">
                        <a href="stock.php?store_id=<?php echo $store['store_id']; ?>" 
                           class="btn btn-outline-info">
                            <i class="fas fa-boxes me-2"></i>View Stock
                        </a>
                        <a href="movements.php?store_id=<?php echo $store['store_id']; ?>" 
                           class="btn btn-outline-success">
                            <i class="fas fa-exchange-alt me-2"></i>Movements
                        </a>
                        <button type="button" 
                                class="btn btn-outline-primary"
                                onclick='editStore(<?php echo json_encode($store); ?>)'>
                            <i class="fas fa-edit me-2"></i>Edit Store
                        </button>
                        <?php if ($store['total_items'] == 0): ?>
                        <button type="button" 
                                class="btn btn-outline-danger"
                                onclick="deleteStore(<?php echo $store['store_id']; ?>, '<?php echo htmlspecialchars($store['store_name'], ENT_QUOTES); ?>')">
                            <i class="fas fa-trash me-2"></i>Delete
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<!-- Store Modal -->
<div class="modal fade" id="storeModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: 15px; overflow: hidden;">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">
                    <i class="fas fa-warehouse me-2"></i>Add New Store
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="storeForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="store_id" id="store_id">

                    <!-- Basic Information -->
                    <div class="form-section-title">
                        <i class="fas fa-info-circle me-2"></i>Basic Information
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Store Code <span class="text-danger">*</span></label>
                            <input type="text" name="store_code" id="store_code" class="form-control" required>
                            <small class="text-muted">Unique identifier (e.g., WH-001)</small>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Store Name <span class="text-danger">*</span></label>
                            <input type="text" name="store_name" id="store_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Store Type</label>
                            <select name="store_type" id="store_type" class="form-select">
                                <option value="warehouse">Warehouse</option>
                                <option value="retail">Retail Store</option>
                                <option value="distribution">Distribution Center</option>
                                <option value="transit">Transit Point</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Capacity (Items)</label>
                            <input type="number" name="capacity" id="capacity" class="form-control" step="1" min="0">
                            <small class="text-muted">Maximum storage capacity</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Manager Name</label>
                            <input type="text" name="manager_name" id="manager_name" class="form-control">
                        </div>
                    </div>
                    
                    <!-- Location Details -->
                    <div class="form-section-title">
                        <i class="fas fa-map-marker-alt me-2"></i>Location & Contact
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Location</label>
                            <input type="text" name="location" id="location" class="form-control" placeholder="City/Region">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Full Address</label>
                            <textarea name="address" id="address" class="form-control" rows="2" placeholder="Complete address with street, building, etc."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Phone Number</label>
                            <input type="tel" name="phone" id="phone" class="form-control" placeholder="+255 XXX XXX XXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control" placeholder="store@example.com">
                        </div>
                    </div>
                    
                    <!-- Status -->
                    <div class="row g-3">
                        <div class="col-md-12" id="activeCheckbox" style="display: none;">
                            <div class="alert alert-info mb-0">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                    <label class="form-check-label fw-bold" for="is_active">
                                        <i class="fas fa-toggle-on me-2"></i>Active Store
                                    </label>
                                    <br><small>Inactive stores won't be available for inventory operations</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Store
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editStore(store) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Store';
    document.getElementById('formAction').value = 'update';
    document.getElementById('store_id').value = store.store_id;
    document.getElementById('store_code').value = store.store_code;
    document.getElementById('store_name').value = store.store_name;
    document.getElementById('store_type').value = store.store_type;
    document.getElementById('location').value = store.location || '';
    document.getElementById('address').value = store.address || '';
    document.getElementById('phone').value = store.phone || '';
    document.getElementById('email').value = store.email || '';
    document.getElementById('manager_name').value = store.manager_name || '';
    document.getElementById('capacity').value = store.capacity || '';
    document.getElementById('is_active').checked = store.is_active == 1;
    document.getElementById('activeCheckbox').style.display = 'block';
    
    const modal = new bootstrap.Modal(document.getElementById('storeModal'));
    modal.show();
}

function deleteStore(storeId, storeName) {
    if (confirm(`⚠️ Delete Store?\n\nAre you sure you want to delete "${storeName}"?\n\nThis action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="store_id" value="${storeId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Reset modal when closed
document.getElementById('storeModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('storeForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-warehouse me-2"></i>Add New Store';
    document.getElementById('formAction').value = 'create';
    document.getElementById('store_id').value = '';
    document.getElementById('activeCheckbox').style.display = 'none';
});
</script>

<?php 
require_once '../../includes/footer.php';
?>