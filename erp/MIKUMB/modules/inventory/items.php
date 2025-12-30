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
        if (empty($_POST['item_code'])) {
            $errors[] = "Item code is required";
        }
        if (empty($_POST['item_name'])) {
            $errors[] = "Item name is required";
        }
        
        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    // Check for duplicate item code
                    $stmt = $conn->prepare("SELECT item_id FROM items WHERE company_id = ? AND item_code = ?");
                    $stmt->execute([$company_id, $_POST['item_code']]);
                    if ($stmt->fetch()) {
                        $errors[] = "Item code already exists";
                    } else {
                        $sql = "INSERT INTO items (
                            company_id, item_code, item_name, description, category,
                            unit_of_measure, unit_cost, selling_price, reorder_level,
                            barcode, sku, is_active, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)";
                        
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([
                            $company_id,
                            $_POST['item_code'],
                            $_POST['item_name'],
                            $_POST['description'] ?? null,
                            $_POST['category'] ?? null,
                            $_POST['unit_of_measure'] ?? 'PCS',
                            floatval($_POST['unit_cost'] ?? 0),
                            floatval($_POST['selling_price'] ?? 0),
                            floatval($_POST['reorder_level'] ?? 0),
                            $_POST['barcode'] ?? null,
                            $_POST['sku'] ?? null,
                            $_SESSION['user_id']
                        ]);
                        
                        $item_id = $conn->lastInsertId();
                        
                        // Auto-create inventory records for all active stores
                        if (isset($_POST['auto_create_inventory']) && $_POST['auto_create_inventory'] == '1') {
                            $stmt = $conn->prepare("
                                INSERT INTO inventory (company_id, item_id, store_id, quantity_on_hand, quantity_reserved, reorder_level, unit_cost)
                                SELECT ?, ?, store_id, 0, 0, ?, ?
                                FROM stores 
                                WHERE company_id = ? AND is_active = 1
                            ");
                            $stmt->execute([
                                $company_id,
                                $item_id,
                                floatval($_POST['reorder_level'] ?? 0),
                                floatval($_POST['unit_cost'] ?? 0),
                                $company_id
                            ]);
                        }
                        
                        $success = "Item created successfully! " . ($stmt->rowCount() > 0 ? "Inventory records created for all active stores." : "");
                    }
                } else {
                    $sql = "UPDATE items SET 
                        item_code = ?, item_name = ?, description = ?, category = ?,
                        unit_of_measure = ?, unit_cost = ?, selling_price = ?, reorder_level = ?,
                        barcode = ?, sku = ?, is_active = ?,
                        updated_at = NOW()
                        WHERE item_id = ? AND company_id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $_POST['item_code'],
                        $_POST['item_name'],
                        $_POST['description'] ?? null,
                        $_POST['category'] ?? null,
                        $_POST['unit_of_measure'] ?? 'PCS',
                        floatval($_POST['unit_cost'] ?? 0),
                        floatval($_POST['selling_price'] ?? 0),
                        floatval($_POST['reorder_level'] ?? 0),
                        $_POST['barcode'] ?? null,
                        $_POST['sku'] ?? null,
                        isset($_POST['is_active']) ? 1 : 0,
                        $_POST['item_id'],
                        $company_id
                    ]);
                    
                    $success = "Item updated successfully!";
                }
            } catch (PDOException $e) {
                error_log("Error saving item: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        try {
            // Check if item has inventory
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count FROM inventory 
                WHERE item_id = ? AND quantity_on_hand > 0
            ");
            $stmt->execute([$_POST['item_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                $errors[] = "Cannot delete item with existing inventory. Adjust stock to zero first.";
            } else {
                // Delete inventory records first
                $stmt = $conn->prepare("DELETE FROM inventory WHERE item_id = ?");
                $stmt->execute([$_POST['item_id']]);
                
                // Delete item
                $stmt = $conn->prepare("DELETE FROM items WHERE item_id = ? AND company_id = ?");
                $stmt->execute([$_POST['item_id'], $company_id]);
                $success = "Item deleted successfully!";
            }
        } catch (PDOException $e) {
            error_log("Error deleting item: " . $e->getMessage());
            $errors[] = "Error deleting item";
        }
    }
}

// Build filter conditions
$where_conditions = ["i.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['category'])) {
    $where_conditions[] = "i.category = ?";
    $params[] = $_GET['category'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(i.item_code LIKE ? OR i.item_name LIKE ? OR i.description LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
    $where_conditions[] = "i.is_active = ?";
    $params[] = intval($_GET['is_active']);
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch items with stock information
try {
    $stmt = $conn->prepare("
        SELECT i.*,
               COALESCE(SUM(inv.quantity_on_hand), 0) as total_stock,
               COUNT(DISTINCT inv.store_id) as store_count,
               COALESCE(SUM(inv.quantity_on_hand * inv.unit_cost), 0) as total_value,
               u.full_name as created_by_name
        FROM items i
        LEFT JOIN inventory inv ON i.item_id = inv.item_id
        LEFT JOIN users u ON i.created_by = u.user_id
        WHERE $where_clause
        GROUP BY i.item_id
        ORDER BY i.is_active DESC, i.created_at DESC
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching items: " . $e->getMessage());
    $items = [];
}

// Fetch categories
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT category 
        FROM items 
        WHERE company_id = ? AND category IS NOT NULL
        ORDER BY category
    ");
    $stmt->execute([$company_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $categories = [];
}

// Calculate statistics
$stats = [
    'total' => count($items),
    'active' => 0,
    'total_value' => 0,
    'low_stock' => 0
];

foreach ($items as $item) {
    if ($item['is_active']) {
        $stats['active']++;
    }
    $stats['total_value'] += $item['total_value'];
    if ($item['total_stock'] > 0 && $item['total_stock'] <= $item['reorder_level']) {
        $stats['low_stock']++;
    }
}

$page_title = 'Items & Products Management';
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
    height: 100%;
}

.stats-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 35px rgba(0,0,0,0.15);
}

.stats-card.primary { border-left-color: #667eea; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.danger { border-left-color: #dc3545; }

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
    font-size: 2.5rem;
    opacity: 0.2;
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
}

.filter-section {
    background: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
}

.item-card {
    background: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    border-left: 5px solid #667eea;
    position: relative;
}

.item-card:hover {
    box-shadow: 0 10px 35px rgba(0,0,0,0.15);
    transform: translateX(5px);
}

.item-card.inactive {
    border-left-color: #6c757d;
    opacity: 0.7;
}

.item-code {
    font-family: 'Courier New', monospace;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
    letter-spacing: 1px;
}

.category-badge {
    padding: 0.4rem 1rem;
    border-radius: 25px;
    font-size: 0.85rem;
    font-weight: 600;
    background: linear-gradient(135deg, #e7f3ff 0%, #d4edda 100%);
    color: #004085;
    border: 2px solid rgba(102, 126, 234, 0.2);
}

.price-display {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    padding: 1rem;
    border-radius: 12px;
    border: 2px solid rgba(40, 167, 69, 0.2);
}

.stock-indicator {
    display: inline-block;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    margin-right: 0.5rem;
    box-shadow: 0 0 10px currentColor;
}

.stock-indicator.high { background: #28a745; color: #28a745; }
.stock-indicator.low { background: #ffc107; color: #ffc107; }
.stock-indicator.out { background: #dc3545; color: #dc3545; }

.metric-box {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 10px;
    border: 2px solid #e9ecef;
    text-align: center;
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

.empty-state h4 {
    color: #6c757d;
    margin-bottom: 1rem;
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
                <i class="fas fa-boxes me-3"></i>Items & Products Management
            </h1>
            <p class="mb-0 opacity-75">
                <i class="fas fa-info-circle me-2"></i>Manage your inventory items, goods and services
            </p>
        </div>
        <div class="col-md-6 text-end">
            <a href="stock.php" class="btn btn-light btn-lg me-2">
                <i class="fas fa-chart-line me-2"></i>Stock Levels
            </a>
            <button type="button" class="btn btn-warning btn-lg" data-bs-toggle="modal" data-bs-target="#itemModal">
                <i class="fas fa-plus-circle me-2"></i>Add New Item
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
            <div class="stats-card primary position-relative">
                <div class="stats-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stats-label">Total Items</div>
                <i class="fas fa-boxes stats-icon"></i>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stats-card success position-relative">
                <div class="stats-number"><?php echo number_format($stats['active']); ?></div>
                <div class="stats-label">Active Items</div>
                <i class="fas fa-check-circle stats-icon"></i>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stats-card warning position-relative">
                <div class="stats-number">TSH <?php echo number_format($stats['total_value'] / 1000000, 2); ?>M</div>
                <div class="stats-label">Total Stock Value</div>
                <i class="fas fa-dollar-sign stats-icon"></i>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stats-card danger position-relative">
                <div class="stats-number"><?php echo number_format($stats['low_stock']); ?></div>
                <div class="stats-label">Low Stock Alerts</div>
                <i class="fas fa-exclamation-triangle stats-icon"></i>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Items</h5>
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Item code, name, description..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Category</label>
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo htmlspecialchars($category); ?>"
                                <?php echo (isset($_GET['category']) && $_GET['category'] == $category) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category); ?>
                        </option>
                    <?php endforeach; ?>
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
                <a href="items.php" class="btn btn-outline-secondary">
                    <i class="fas fa-redo"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Items List -->
    <?php if (empty($items)): ?>
    <div class="empty-state">
        <i class="fas fa-boxes"></i>
        <h4>No Items Found</h4>
        <p class="text-muted mb-4">Start by creating your first inventory item</p>
        <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#itemModal">
            <i class="fas fa-plus-circle me-2"></i>Add First Item
        </button>
    </div>
    <?php else: ?>
        <?php foreach ($items as $item): 
            $stock_status = 'out';
            if ($item['total_stock'] > $item['reorder_level']) {
                $stock_status = 'high';
            } elseif ($item['total_stock'] > 0) {
                $stock_status = 'low';
            }
            
            $margin = $item['selling_price'] > 0 ? 
                (($item['selling_price'] - $item['unit_cost']) / $item['selling_price']) * 100 : 0;
        ?>
        <div class="item-card <?php echo $item['is_active'] ? '' : 'inactive'; ?>">
            <div class="row">
                <div class="col-lg-9">
                    <!-- Header -->
                    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
                        <span class="item-code"><?php echo htmlspecialchars($item['item_code']); ?></span>
                        <?php if (!empty($item['category'])): ?>
                            <span class="category-badge">
                                <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($item['category']); ?>
                            </span>
                        <?php endif; ?>
                        <span class="badge-status <?php echo $item['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo $item['is_active'] ? '✓ Active' : '✕ Inactive'; ?>
                        </span>
                        <span class="ms-auto text-muted small">
                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($item['created_by_name'] ?? 'System'); ?>
                        </span>
                    </div>
                    
                    <!-- Item Name -->
                    <h4 class="mb-2"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                    
                    <!-- Description -->
                    <?php if (!empty($item['description'])): ?>
                    <p class="text-muted mb-3">
                        <i class="fas fa-align-left me-2"></i><?php echo htmlspecialchars($item['description']); ?>
                    </p>
                    <?php endif; ?>
                    
                    <!-- Metrics Grid -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Unit Cost</div>
                                <div class="metric-value text-primary">
                                    TSH <?php echo number_format($item['unit_cost'], 0); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Selling Price</div>
                                <div class="metric-value text-success">
                                    TSH <?php echo number_format($item['selling_price'], 0); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Profit Margin</div>
                                <div class="metric-value text-info">
                                    <?php echo number_format($margin, 1); ?>%
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Unit</div>
                                <div class="metric-value">
                                    <?php echo htmlspecialchars($item['unit_of_measure']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stock Information -->
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Total Stock</div>
                                <div class="metric-value">
                                    <span class="stock-indicator <?php echo $stock_status; ?>"></span>
                                    <?php echo number_format($item['total_stock']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Reorder Level</div>
                                <div class="metric-value text-warning">
                                    <?php echo number_format($item['reorder_level']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Locations</div>
                                <div class="metric-value">
                                    <i class="fas fa-warehouse me-2"></i><?php echo $item['store_count']; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric-box">
                                <div class="metric-label">Stock Value</div>
                                <div class="metric-value text-success">
                                    TSH <?php echo number_format($item['total_value'] / 1000, 0); ?>K
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Info -->
                    <?php if (!empty($item['barcode']) || !empty($item['sku'])): ?>
                    <div class="mt-3 pt-3 border-top">
                        <small class="text-muted">
                            <?php if (!empty($item['barcode'])): ?>
                                <i class="fas fa-barcode me-2"></i>
                                <strong>Barcode:</strong> <?php echo htmlspecialchars($item['barcode']); ?>
                            <?php endif; ?>
                            <?php if (!empty($item['sku'])): ?>
                                <span class="ms-4">
                                    <i class="fas fa-tag me-2"></i>
                                    <strong>SKU:</strong> <?php echo htmlspecialchars($item['sku']); ?>
                                </span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Action Buttons -->
                <div class="col-lg-3">
                    <div class="action-buttons d-grid gap-2">
                        <a href="stock.php?item_id=<?php echo $item['item_id']; ?>" 
                           class="btn btn-outline-info">
                            <i class="fas fa-chart-bar me-2"></i>View Stock Details
                        </a>
                        <a href="movements.php?item_id=<?php echo $item['item_id']; ?>" 
                           class="btn btn-outline-success">
                            <i class="fas fa-exchange-alt me-2"></i>Stock Movements
                        </a>
                        <button type="button" 
                                class="btn btn-outline-primary"
                                onclick='editItem(<?php echo json_encode($item); ?>)'>
                            <i class="fas fa-edit me-2"></i>Edit Item
                        </button>
                        <?php if ($item['total_stock'] == 0): ?>
                        <button type="button" 
                                class="btn btn-outline-danger"
                                onclick="deleteItem(<?php echo $item['item_id']; ?>, '<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>')">
                            <i class="fas fa-trash me-2"></i>Delete Item
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<!-- Item Modal -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: 15px; overflow: hidden;">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">
                    <i class="fas fa-box me-2"></i>Add New Item
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="itemForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="item_id" id="item_id">

                    <!-- Basic Information -->
                    <div class="form-section-title">
                        <i class="fas fa-info-circle me-2"></i>Basic Information
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Item Code <span class="text-danger">*</span></label>
                            <input type="text" name="item_code" id="item_code" class="form-control" required>
                            <small class="text-muted">Unique identifier (e.g., ITM-001)</small>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Item Name <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" id="item_name" class="form-control" required>
                            <small class="text-muted">Full descriptive name</small>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="3" placeholder="Detailed description of the item..."></textarea>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Category</label>
                            <input type="text" name="category" id="category" class="form-control" list="categoryList" placeholder="e.g., Electronics, Furniture">
                            <datalist id="categoryList">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Barcode</label>
                            <input type="text" name="barcode" id="barcode" class="form-control" placeholder="Enter barcode">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">SKU</label>
                            <input type="text" name="sku" id="sku" class="form-control" placeholder="Stock Keeping Unit">
                        </div>
                    </div>
                    
                    <!-- Pricing & Measurement -->
                    <div class="form-section-title">
                        <i class="fas fa-dollar-sign me-2"></i>Pricing & Measurement
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Unit of Measure</label>
                            <select name="unit_of_measure" id="unit_of_measure" class="form-select">
                                <option value="PCS">Pieces (PCS)</option>
                                <option value="BOX">Box</option>
                                <option value="KG">Kilogram (KG)</option>
                                <option value="LITER">Liter</option>
                                <option value="METER">Meter</option>
                                <option value="SET">Set</option>
                                <option value="UNIT">Unit</option>
                                <option value="PACK">Pack</option>
                                <option value="CARTON">Carton</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Unit Cost (TSH)</label>
                            <input type="number" name="unit_cost" id="unit_cost" class="form-control" step="0.01" value="0.00">
                            <small class="text-muted">Purchase/cost price</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Selling Price (TSH)</label>
                            <input type="number" name="selling_price" id="selling_price" class="form-control" step="0.01" value="0.00">
                            <small class="text-muted">Retail price</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Reorder Level</label>
                            <input type="number" name="reorder_level" id="reorder_level" class="form-control" step="0.01" value="10">
                            <small class="text-muted">Minimum stock alert</small>
                        </div>
                    </div>
                    
                    <!-- Settings -->
                    <div class="row g-3">
                        <div class="col-md-6" id="activeCheckbox" style="display: none;">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                <label class="form-check-label fw-bold" for="is_active">
                                    <i class="fas fa-toggle-on me-2"></i>Active Item
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-12" id="autoInventoryCheckbox">
                            <div class="alert alert-info mb-0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auto_create_inventory" id="auto_create_inventory" value="1" checked>
                                    <label class="form-check-label fw-bold" for="auto_create_inventory">
                                        <i class="fas fa-magic me-2"></i>Automatically create inventory records for all active stores
                                    </label>
                                    <br><small>This will create inventory entries with zero quantity for all active warehouses/stores</small>
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
                        <i class="fas fa-save me-2"></i>Save Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editItem(item) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Item';
    document.getElementById('formAction').value = 'update';
    document.getElementById('item_id').value = item.item_id;
    document.getElementById('item_code').value = item.item_code;
    document.getElementById('item_name').value = item.item_name;
    document.getElementById('description').value = item.description || '';
    document.getElementById('category').value = item.category || '';
    document.getElementById('unit_of_measure').value = item.unit_of_measure;
    document.getElementById('unit_cost').value = item.unit_cost;
    document.getElementById('selling_price').value = item.selling_price;
    document.getElementById('reorder_level').value = item.reorder_level;
    document.getElementById('barcode').value = item.barcode || '';
    document.getElementById('sku').value = item.sku || '';
    document.getElementById('is_active').checked = item.is_active == 1;
    document.getElementById('activeCheckbox').style.display = 'block';
    document.getElementById('autoInventoryCheckbox').style.display = 'none';
    
    const modal = new bootstrap.Modal(document.getElementById('itemModal'));
    modal.show();
}

function deleteItem(itemId, itemName) {
    if (confirm(`⚠️ Delete Item?\n\nAre you sure you want to delete "${itemName}"?\n\nThis will also remove all associated inventory records.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="item_id" value="${itemId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Reset modal when closed
document.getElementById('itemModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('itemForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-box me-2"></i>Add New Item';
    document.getElementById('formAction').value = 'create';
    document.getElementById('item_id').value = '';
    document.getElementById('activeCheckbox').style.display = 'none';
    document.getElementById('autoInventoryCheckbox').style.display = 'block';
    document.getElementById('unit_cost').value = '0.00';
    document.getElementById('selling_price').value = '0.00';
    document.getElementById('reorder_level').value = '10';
});
</script>

<?php 
require_once '../../includes/footer.php';
?>