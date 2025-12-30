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

// Get store filter
$store_id = $_GET['store_id'] ?? null;

// Build filter conditions
$where_conditions = ["i.company_id = ?"];
$params = [$company_id];

if ($store_id) {
    $where_conditions[] = "i.store_id = ?";
    $params[] = $store_id;
}

if (!empty($_GET['category'])) {
    $where_conditions[] = "itm.category = ?";
    $params[] = $_GET['category'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(itm.item_code LIKE ? OR itm.item_name LIKE ? OR itm.description LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

if (isset($_GET['low_stock']) && $_GET['low_stock'] == '1') {
    $where_conditions[] = "i.quantity_on_hand <= i.reorder_level";
}

if (isset($_GET['out_of_stock']) && $_GET['out_of_stock'] == '1') {
    $where_conditions[] = "i.quantity_on_hand = 0";
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch inventory
try {
    $stmt = $conn->prepare("
        SELECT 
            i.*,
            itm.item_code,
            itm.item_name,
            itm.description,
            itm.category,
            itm.unit_of_measure,
            s.store_name,
            s.store_code,
            (i.quantity_on_hand * i.unit_cost) as stock_value
        FROM inventory i
        INNER JOIN items itm ON i.item_id = itm.item_id
        INNER JOIN stores s ON i.store_id = s.store_id
        WHERE $where_clause
        ORDER BY 
            CASE 
                WHEN i.quantity_on_hand = 0 THEN 1
                WHEN i.quantity_on_hand <= i.reorder_level THEN 2
                ELSE 3
            END,
            i.last_updated DESC
    ");
    $stmt->execute($params);
    $inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching inventory: " . $e->getMessage());
    $inventory_items = [];
}

// Calculate statistics
$stats = [
    'total_items' => 0,
    'total_value' => 0,
    'low_stock' => 0,
    'out_of_stock' => 0
];

foreach ($inventory_items as $item) {
    $stats['total_items'] += $item['quantity_on_hand'];
    $stats['total_value'] += $item['stock_value'];
    
    if ($item['quantity_on_hand'] == 0) {
        $stats['out_of_stock']++;
    } elseif ($item['quantity_on_hand'] <= $item['reorder_level']) {
        $stats['low_stock']++;
    }
}

// Fetch stores for dropdown
try {
    $stmt = $conn->prepare("
        SELECT store_id, store_code, store_name 
        FROM stores 
        WHERE company_id = ? AND is_active = 1
        ORDER BY store_name
    ");
    $stmt->execute([$company_id]);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stores = [];
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

$page_title = 'Stock Levels';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.danger { border-left-color: #dc3545; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
}

.stats-label {
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.stock-level-bar {
    height: 20px;
    background: #e9ecef;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.stock-level-fill {
    height: 100%;
    background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
    transition: width 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
}

.stock-level-fill.low {
    background: linear-gradient(90deg, #ffc107 0%, #fd7e14 100%);
}

.stock-level-fill.critical {
    background: linear-gradient(90deg, #dc3545 0%, #c82333 100%);
}

.stock-level-fill.out {
    background: #6c757d;
}

.item-code {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-weight: 600;
    font-size: 0.9rem;
}

.stock-status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.stock-status-badge.in-stock {
    background: #d4edda;
    color: #155724;
}

.stock-status-badge.low-stock {
    background: #fff3cd;
    color: #856404;
}

.stock-status-badge.out-of-stock {
    background: #f8d7da;
    color: #721c24;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-boxes text-primary me-2"></i>Stock Levels
                </h1>
                <p class="text-muted small mb-0 mt-1">Monitor inventory across all locations</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="stores.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-warehouse me-1"></i> Stores
                    </a>
                    <a href="movements.php" class="btn btn-outline-info">
                        <i class="fas fa-exchange-alt me-1"></i> Movements
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format($stats['total_items']); ?></div>
                    <div class="stats-label">Total Items</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format($stats['total_value'] / 1000000, 2); ?>M</div>
                    <div class="stats-label">Stock Value</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($stats['low_stock']); ?></div>
                    <div class="stats-label">Low Stock Items</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format($stats['out_of_stock']); ?></div>
                    <div class="stats-label">Out of Stock</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Store</label>
                    <select name="store_id" class="form-select">
                        <option value="">All Stores</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['store_id']; ?>" 
                                    <?php echo ($store_id == $store['store_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($store['store_code']); ?> - 
                                <?php echo htmlspecialchars($store['store_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                <div class="col-md-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Item code, name..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Quick Filters</label>
                    <div class="btn-group w-100">
                        <a href="?low_stock=1<?php echo $store_id ? '&store_id='.$store_id : ''; ?>" 
                           class="btn btn-sm btn-outline-warning">
                            Low Stock
                        </a>
                        <a href="?out_of_stock=1<?php echo $store_id ? '&store_id='.$store_id : ''; ?>" 
                           class="btn btn-sm btn-outline-danger">
                            Out of Stock
                        </a>
                    </div>
                </div>
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="stock.php" class="btn btn-outline-secondary ms-2">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Stock List -->
        <?php if (empty($inventory_items)): ?>
        <div class="text-center py-5">
            <i class="fas fa-boxes fa-4x text-muted mb-3"></i>
            <h4>No Inventory Data</h4>
            <p class="text-muted">No items found matching your criteria</p>
        </div>
        <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="stockTable">
                        <thead class="table-light">
                            <tr>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Store</th>
                                <th class="text-center">On Hand</th>
                                <th class="text-center">Reorder Level</th>
                                <th class="text-center">Reserved</th>
                                <th class="text-center">Available</th>
                                <th class="text-end">Unit Cost</th>
                                <th class="text-end">Stock Value</th>
                                <th>Status</th>
                                <th>Stock Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory_items as $item): 
                                $available = $item['quantity_on_hand'] - ($item['quantity_reserved'] ?? 0);
                                
                                // Determine stock status
                                $status = 'in-stock';
                                $status_text = 'In Stock';
                                $bar_class = '';
                                
                                if ($item['quantity_on_hand'] == 0) {
                                    $status = 'out-of-stock';
                                    $status_text = 'Out of Stock';
                                    $bar_class = 'out';
                                } elseif ($item['quantity_on_hand'] <= $item['reorder_level']) {
                                    $status = 'low-stock';
                                    $status_text = 'Low Stock';
                                    $bar_class = 'critical';
                                } elseif ($item['quantity_on_hand'] <= ($item['reorder_level'] * 1.5)) {
                                    $bar_class = 'low';
                                }
                                
                                // Calculate percentage for stock bar
                                $max_level = $item['reorder_level'] * 3; // Assume 3x reorder is "full"
                                $percentage = $max_level > 0 ? min(($item['quantity_on_hand'] / $max_level) * 100, 100) : 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="item-code"><?php echo htmlspecialchars($item['item_code']); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                    <?php if (!empty($item['description'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($item['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['category'] ?? '-'); ?></td>
                                <td>
                                    <small><?php echo htmlspecialchars($item['store_name']); ?></small>
                                </td>
                                <td class="text-center">
                                    <strong><?php echo number_format($item['quantity_on_hand']); ?></strong>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($item['unit_of_measure']); ?></small>
                                </td>
                                <td class="text-center">
                                    <?php echo number_format($item['reorder_level']); ?>
                                </td>
                                <td class="text-center text-warning">
                                    <?php echo number_format($item['quantity_reserved'] ?? 0); ?>
                                </td>
                                <td class="text-center">
                                    <strong class="<?php echo $available > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($available); ?>
                                    </strong>
                                </td>
                                <td class="text-end">
                                    TSH <?php echo number_format($item['unit_cost'], 2); ?>
                                </td>
                                <td class="text-end">
                                    <strong>TSH <?php echo number_format($item['stock_value'], 2); ?></strong>
                                </td>
                                <td>
                                    <span class="stock-status-badge <?php echo $status; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td style="min-width: 150px;">
                                    <div class="stock-level-bar">
                                        <div class="stock-level-fill <?php echo $bar_class; ?>" 
                                             style="width: <?php echo $percentage; ?>%">
                                            <?php if ($percentage > 20): ?>
                                                <?php echo number_format($item['quantity_on_hand']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <td colspan="9" class="text-end"><strong>Total Stock Value:</strong></td>
                                <td class="text-end">
                                    <strong>TSH <?php echo number_format($stats['total_value'], 2); ?></strong>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</section>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#stockTable').DataTable({
        order: [[10, 'asc']], // Sort by status (out of stock first)
        pageLength: 25,
        columnDefs: [
            { orderable: false, targets: 11 }
        ]
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>