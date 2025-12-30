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

// Fetch statistics
try {
    $stats_query = "
        SELECT 
            COUNT(*) as total_suppliers,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_suppliers,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_suppliers
        FROM suppliers
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get purchase order stats
    $po_stats_query = "
        SELECT 
            COUNT(DISTINCT po.supplier_id) as suppliers_with_orders,
            COALESCE(SUM(po.total_amount), 0) as total_purchases
        FROM purchase_orders po
        WHERE po.company_id = ?
        AND po.status IN ('approved', 'received', 'closed')
    ";
    $stmt = $conn->prepare($po_stats_query);
    $stmt->execute([$company_id]);
    $po_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = array_merge($stats, $po_stats);
} catch (PDOException $e) {
    error_log("Error fetching supplier stats: " . $e->getMessage());
    $stats = [
        'total_suppliers' => 0,
        'active_suppliers' => 0,
        'inactive_suppliers' => 0,
        'suppliers_with_orders' => 0,
        'total_purchases' => 0
    ];
}

// Build filter conditions
$where_conditions = ["s.company_id = ?"];
$params = [$company_id];

if (isset($_GET['is_active'])) {
    $where_conditions[] = "s.is_active = ?";
    $params[] = $_GET['is_active'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(s.supplier_name LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch suppliers
try {
    $suppliers_query = "
        SELECT 
            s.*,
            COUNT(DISTINCT po.purchase_order_id) as order_count,
            COALESCE(SUM(CASE WHEN po.status IN ('approved', 'received', 'closed') THEN po.total_amount ELSE 0 END), 0) as total_purchases,
            MAX(po.po_date) as last_order_date,
            u.full_name as created_by_name
        FROM suppliers s
        LEFT JOIN purchase_orders po ON s.supplier_id = po.supplier_id
        LEFT JOIN users u ON s.created_by = u.user_id
        WHERE $where_clause
        GROUP BY s.supplier_id
        ORDER BY s.supplier_name ASC
    ";
    $stmt = $conn->prepare($suppliers_query);
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching suppliers: " . $e->getMessage());
    $suppliers = [];
}

$page_title = 'Suppliers Management';
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
.stats-card.info { border-left-color: #17a2b8; }

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

.table-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.supplier-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: transform 0.2s;
}

.supplier-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.supplier-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
}

.supplier-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.5rem;
    margin-right: 15px;
}

.supplier-name {
    font-size: 1.2rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.supplier-code {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.8rem;
    color: #6c757d;
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.inactive {
    background: #f8d7da;
    color: #721c24;
}

.supplier-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.info-item {
    display: flex;
    align-items: center;
    padding: 0.5rem;
    background: #f8f9fa;
    border-radius: 6px;
}

.info-item i {
    color: #007bff;
    margin-right: 10px;
    width: 20px;
}

.info-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}

.info-value {
    font-weight: 600;
    color: #2c3e50;
}

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.stat-box {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 6px;
    text-align: center;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #007bff;
}

.stat-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-truck text-info me-2"></i>Suppliers Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage supplier information and relationships</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="orders.php" class="btn btn-outline-success me-2">
                        <i class="fas fa-shopping-cart me-1"></i> Purchase Orders
                    </a>
                    <a href="add-supplier.php" class="btn btn-info">
                        <i class="fas fa-plus-circle me-1"></i> Add Supplier
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
                    <div class="stats-number"><?php echo number_format((int)$stats['total_suppliers']); ?></div>
                    <div class="stats-label">Total Suppliers</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['active_suppliers']); ?></div>
                    <div class="stats-label">Active Suppliers</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format((int)$stats['suppliers_with_orders']); ?></div>
                    <div class="stats-label">With Orders</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['total_purchases'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Purchases</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Supplier name, contact, phone, email..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="is_active" class="form-select">
                        <option value="">All Status</option>
                        <option value="1" <?php echo (isset($_GET['is_active']) && $_GET['is_active'] == '1') ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo (isset($_GET['is_active']) && $_GET['is_active'] == '0') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search me-1"></i> Search
                    </button>
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Suppliers Grid -->
        <?php if (empty($suppliers)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-truck fa-4x mb-3 d-block"></i>
            <h4>No Suppliers Found</h4>
            <p class="mb-3">Start by adding your first supplier to begin managing purchases.</p>
            <a href="add-supplier.php" class="btn btn-info btn-lg">
                <i class="fas fa-plus-circle me-1"></i> Add First Supplier
            </a>
        </div>
        <?php else: ?>
        <div class="row">
            <?php foreach ($suppliers as $supplier): ?>
            <div class="col-lg-6 col-xl-4">
                <div class="supplier-card">
                    <div class="supplier-header">
                        <div class="d-flex align-items-start">
                            <div class="supplier-avatar">
                                <?php echo strtoupper(substr($supplier['supplier_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div class="supplier-name"><?php echo htmlspecialchars($supplier['supplier_name']); ?></div>
                                <?php if (!empty($supplier['supplier_code'])): ?>
                                <span class="supplier-code"><?php echo htmlspecialchars($supplier['supplier_code']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="status-badge <?php echo $supplier['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $supplier['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <!-- Contact Information -->
                    <div class="supplier-info">
                        <?php if (!empty($supplier['contact_person'])): ?>
                        <div class="info-item">
                            <i class="fas fa-user"></i>
                            <div>
                                <div class="info-label">Contact Person</div>
                                <div class="info-value"><?php echo htmlspecialchars($supplier['contact_person']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($supplier['phone'])): ?>
                        <div class="info-item">
                            <i class="fas fa-phone"></i>
                            <div>
                                <div class="info-label">Phone</div>
                                <div class="info-value"><?php echo htmlspecialchars($supplier['phone']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($supplier['email'])): ?>
                        <div class="info-item">
                            <i class="fas fa-envelope"></i>
                            <div>
                                <div class="info-label">Email</div>
                                <div class="info-value"><?php echo htmlspecialchars($supplier['email']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($supplier['city'])): ?>
                        <div class="info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <div class="info-label">Location</div>
                                <div class="info-value"><?php echo htmlspecialchars($supplier['city']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Statistics -->
                    <div class="row g-2 mt-3">
                        <div class="col-6">
                            <div class="stat-box">
                                <div class="stat-value"><?php echo number_format((int)$supplier['order_count']); ?></div>
                                <div class="stat-label">Orders</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-box">
                                <div class="stat-value">TSH <?php echo number_format((float)$supplier['total_purchases'] / 1000, 0); ?>K</div>
                                <div class="stat-label">Total Purchases</div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($supplier['last_order_date'])): ?>
                    <div class="mt-2 text-muted text-center small">
                        <i class="fas fa-clock me-1"></i>
                        Last order: <?php echo date('M d, Y', strtotime($supplier['last_order_date'])); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div class="mt-3 d-flex justify-content-between">
                        <a href="view-supplier.php?id=<?php echo $supplier['supplier_id']; ?>" 
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye me-1"></i> View Details
                        </a>
                        <div class="btn-group btn-group-sm">
                            <a href="edit-supplier.php?id=<?php echo $supplier['supplier_id']; ?>" 
                               class="btn btn-outline-warning"
                               title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="create-order.php?supplier_id=<?php echo $supplier['supplier_id']; ?>" 
                               class="btn btn-outline-success"
                               title="Create PO">
                                <i class="fas fa-shopping-cart"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php 
require_once '../../includes/footer.php';
?>