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
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_orders,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_orders,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_orders,
            SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received_orders,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_orders,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END), 0) as approved_amount,
            COALESCE(SUM(CASE WHEN status IN ('approved', 'received', 'closed') THEN total_amount ELSE 0 END), 0) as total_value,
            COUNT(CASE WHEN delivery_date < CURDATE() AND status NOT IN ('received', 'closed', 'cancelled') THEN 1 END) as overdue_orders
        FROM purchase_orders
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching order stats: " . $e->getMessage());
    $stats = [
        'total_orders' => 0,
        'draft_orders' => 0,
        'submitted_orders' => 0,
        'approved_orders' => 0,
        'received_orders' => 0,
        'closed_orders' => 0,
        'approved_amount' => 0,
        'total_value' => 0,
        'overdue_orders' => 0
    ];
}

// Build filter conditions
$where_conditions = ["po.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['status'])) {
    $where_conditions[] = "po.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['supplier_id'])) {
    $where_conditions[] = "po.supplier_id = ?";
    $params[] = $_GET['supplier_id'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "po.po_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "po.po_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(po.po_number LIKE ? OR s.supplier_name LIKE ? OR po.notes LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch purchase orders
try {
    $orders_query = "
        SELECT 
            po.*,
            s.supplier_name,
            s.contact_person,
            s.phone as supplier_phone,
            pr.requisition_number,
            u.full_name as created_by_name,
            approver.full_name as approved_by_name,
            COUNT(poi.po_item_id) as item_count
        FROM purchase_orders po
        INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
        LEFT JOIN purchase_requisitions pr ON po.requisition_id = pr.requisition_id
        LEFT JOIN users u ON po.created_by = u.user_id
        LEFT JOIN users approver ON po.approved_by = approver.user_id
        LEFT JOIN purchase_order_items poi ON po.purchase_order_id = poi.purchase_order_id
        WHERE $where_clause
        GROUP BY po.purchase_order_id
        ORDER BY po.po_date DESC, po.created_at DESC
    ";
    $stmt = $conn->prepare($orders_query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching purchase orders: " . $e->getMessage());
    $orders = [];
}

// Fetch suppliers for filter
try {
    $supplier_query = "SELECT supplier_id, supplier_name FROM suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name";
    $stmt = $conn->prepare($supplier_query);
    $stmt->execute([$company_id]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
}

$page_title = 'Purchase Orders';
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

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.draft {
    background: #e9ecef;
    color: #495057;
}

.status-badge.submitted {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.approved {
    background: #d4edda;
    color: #155724;
}

.status-badge.received {
    background: #cfe2ff;
    color: #084298;
}

.status-badge.closed {
    background: #6c757d;
    color: white;
}

.status-badge.cancelled {
    background: #f8d7da;
    color: #721c24;
}

.po-number {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
}

.amount-highlight {
    font-weight: 700;
    font-size: 1.1rem;
    color: #007bff;
}

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.overdue-badge {
    background: #dc3545;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.supplier-info {
    display: flex;
    align-items: center;
}

.supplier-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.9rem;
    margin-right: 10px;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-shopping-cart text-success me-2"></i>Purchase Orders
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage purchase orders and deliveries</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="requisitions.php" class="btn btn-outline-primary me-2">
                        <i class="fas fa-file-alt me-1"></i> Requisitions
                    </a>
                    <a href="suppliers.php" class="btn btn-outline-info me-2">
                        <i class="fas fa-truck me-1"></i> Suppliers
                    </a>
                    <a href="create-order.php" class="btn btn-success">
                        <i class="fas fa-plus-circle me-1"></i> New Purchase Order
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
                    <div class="stats-number"><?php echo number_format((int)$stats['total_orders']); ?></div>
                    <div class="stats-label">Total Orders</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['approved_orders']); ?></div>
                    <div class="stats-label">Approved Orders</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['total_value'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Value</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format((int)$stats['overdue_orders']); ?></div>
                    <div class="stats-label">Overdue Deliveries</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="PO #, supplier, notes..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo (isset($_GET['status']) && $_GET['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="submitted" <?php echo (isset($_GET['status']) && $_GET['status'] == 'submitted') ? 'selected' : ''; ?>>Submitted</option>
                        <option value="approved" <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                        <option value="received" <?php echo (isset($_GET['status']) && $_GET['status'] == 'received') ? 'selected' : ''; ?>>Received</option>
                        <option value="closed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'closed') ? 'selected' : ''; ?>>Closed</option>
                        <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Supplier</label>
                    <select name="supplier_id" class="form-select">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo $supplier['supplier_id']; ?>" 
                                <?php echo (isset($_GET['supplier_id']) && $_GET['supplier_id'] == $supplier['supplier_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Date From</label>
                    <input type="date" 
                           name="date_from" 
                           class="form-control"
                           value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Date To</label>
                    <input type="date" 
                           name="date_to" 
                           class="form-control"
                           value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
            <div class="mt-2">
                <a href="orders.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-redo me-1"></i> Reset Filters
                </a>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover" id="ordersTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Requisition</th>
                            <th>Items</th>
                            <th>Total Amount</th>
                            <th>Delivery Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                <p class="mb-2">No purchase orders found.</p>
                                <a href="create-order.php" class="btn btn-success btn-sm">
                                    <i class="fas fa-plus me-1"></i> Create First Purchase Order
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                        <?php
                        $isOverdue = !empty($order['delivery_date']) && 
                                    strtotime($order['delivery_date']) < time() && 
                                    !in_array($order['status'], ['received', 'closed', 'cancelled']);
                        ?>
                        <tr>
                            <td>
                                <i class="fas fa-calendar text-success me-1"></i>
                                <?php echo date('M d, Y', strtotime($order['po_date'])); ?>
                            </td>
                            <td>
                                <span class="po-number">
                                    <?php echo htmlspecialchars($order['po_number']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="supplier-info">
                                    <div class="supplier-avatar">
                                        <?php echo strtoupper(substr($order['supplier_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($order['supplier_name']); ?></div>
                                        <?php if (!empty($order['supplier_phone'])): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($order['supplier_phone']); ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($order['requisition_number'])): ?>
                                <a href="view-requisition.php?id=<?php echo $order['requisition_id']; ?>">
                                    <?php echo htmlspecialchars($order['requisition_number']); ?>
                                </a>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo number_format((int)$order['item_count']); ?> items</span>
                            </td>
                            <td>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format((float)$order['total_amount'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($order['delivery_date'])): ?>
                                <?php echo date('M d, Y', strtotime($order['delivery_date'])); ?>
                                <?php if ($isOverdue): ?>
                                <br><span class="overdue-badge">OVERDUE</span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $order['status']; ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </span>
                                <?php if ($order['status'] == 'approved' && !empty($order['approved_by_name'])): ?>
                                <div class="small text-muted mt-1">
                                    <i class="fas fa-user-check me-1"></i>
                                    <?php echo htmlspecialchars($order['approved_by_name']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="view-order.php?id=<?php echo $order['purchase_order_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="print-po.php?id=<?php echo $order['purchase_order_id']; ?>" 
                                       class="btn btn-outline-secondary action-btn"
                                       title="Print PO"
                                       target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php if ($order['status'] == 'approved'): ?>
                                    <a href="receive-order.php?id=<?php echo $order['purchase_order_id']; ?>" 
                                       class="btn btn-outline-info action-btn"
                                       title="Receive Items">
                                        <i class="fas fa-box-open"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($orders)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end">TOTALS:</th>
                            <th>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format(array_sum(array_column($orders, 'total_amount')), 0); ?>
                                </span>
                            </th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    </div>
</section>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    const tableBody = $('#ordersTable tbody tr');
    const hasData = tableBody.length > 0 && !tableBody.first().find('td[colspan]').length;
    
    if (hasData) {
        $('#ordersTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            columnDefs: [
                { orderable: false, targets: -1 }
            ],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search orders..."
            }
        });
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>