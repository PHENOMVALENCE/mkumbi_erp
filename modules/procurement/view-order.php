<?php
define('APP_ACCESS', true);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

$errors = [];
$success = [];

// Get order ID from URL
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$order_id) {
    header('Location: orders.php');
    exit;
}

// Fetch purchase order details
try {
    $order_sql = "SELECT 
                        po.purchase_order_id,
                        po.po_number,
                        po.po_date,
                        po.delivery_date,
                        po.status,
                        po.subtotal,
                        po.tax_amount,
                        po.total_amount,
                        po.payment_terms,
                        po.delivery_terms,
                        po.notes,
                        po.created_at,
                        po.updated_at,
                        s.supplier_id,
                        s.supplier_name,
                        s.contact_person,
                        s.phone,
                        s.email,
                        s.physical_address,
                        s.supplier_code,
                        u.full_name as created_by_name
                  FROM purchase_orders po
                  INNER JOIN suppliers s ON po.supplier_id = s.supplier_id
                  LEFT JOIN users u ON po.created_by = u.user_id
                  WHERE po.purchase_order_id = ? AND po.company_id = ?";
    
    $stmt = $conn->prepare($order_sql);
    $stmt->execute([$order_id, $company_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $_SESSION['error'] = "Purchase order not found";
        header('Location: orders.php');
        exit;
    }
    
} catch (PDOException $e) {
    $errors[] = "Error fetching order: " . $e->getMessage();
}

// Fetch order items
try {
    $items_sql = "SELECT 
                        po_item_id,
                        item_description,
                        quantity,
                        unit_of_measure,
                        unit_price,
                        quantity_received,
                        (quantity * unit_price) as line_total,
                        (quantity - quantity_received) as quantity_remaining
                  FROM purchase_order_items
                  WHERE purchase_order_id = ?
                  ORDER BY po_item_id";
    
    $stmt = $conn->prepare($items_sql);
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $items = [];
    $errors[] = "Error fetching order items: " . $e->getMessage();
}

// Calculate fulfillment
$total_ordered = array_sum(array_column($items, 'quantity'));
$total_received = array_sum(array_column($items, 'quantity_received'));
$fulfillment_percent = $total_ordered > 0 ? ($total_received / $total_ordered) * 100 : 0;

$page_title = 'View Purchase Order - ' . ($order['po_number'] ?? '');
require_once '../../includes/header.php';
?>

<style>
.info-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.section-title {
    font-size: 16px;
    font-weight: 700;
    color: #495057;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-row {
    display: flex;
    padding: 10px 0;
    border-bottom: 1px solid #f1f1f1;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    width: 200px;
    flex-shrink: 0;
}

.info-value {
    color: #212529;
    flex-grow: 1;
}

.status-badge-large {
    padding: 10px 20px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
}

.status-draft {
    background: #6c757d;
    color: white;
}

.status-submitted {
    background: #ffc107;
    color: #000;
}

.status-approved {
    background: #0d6efd;
    color: white;
}

.status-received {
    background: #28a745;
    color: white;
}

.status-closed {
    background: #28a745;
    color: white;
}

.status-cancelled {
    background: #dc3545;
    color: white;
}

.items-table {
    width: 100%;
    margin-top: 15px;
}

.items-table th {
    background: #f8f9fa;
    padding: 12px;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    border-bottom: 2px solid #dee2e6;
}

.items-table td {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
    vertical-align: middle;
}

.totals-section {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-top: 20px;
}

.totals-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    font-size: 15px;
}

.totals-row.grand-total {
    font-size: 20px;
    font-weight: 700;
    color: #28a745;
    padding-top: 15px;
    margin-top: 10px;
    border-top: 2px solid #dee2e6;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.fulfillment-progress {
    margin-top: 15px;
}

.progress {
    height: 30px;
}

.progress-bar {
    font-weight: 700;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.company-header {
    text-align: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 3px solid #0d6efd;
}

.company-name {
    font-size: 28px;
    font-weight: 800;
    color: #0d6efd;
    margin-bottom: 5px;
}

.document-title {
    font-size: 24px;
    font-weight: 700;
    color: #495057;
    margin-top: 20px;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .info-card {
        box-shadow: none;
        border: 1px solid #dee2e6;
    }
}
</style>

<div class="content-header no-print">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-file-invoice"></i> Purchase Order Details</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="orders.php">Purchase Orders</a></li>
                    <li class="breadcrumb-item active">View Order</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show no-print">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-ban"></i> Error!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="info-card no-print">
            <div class="action-buttons">
                <a href="orders.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Orders
                </a>
                
                <?php if ($order['status'] === 'draft'): ?>
                    <a href="edit-order.php?id=<?php echo $order_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit Order
                    </a>
                <?php endif; ?>
                
                <?php if (in_array($order['status'], ['submitted', 'approved'])): ?>
                    <a href="receive-order.php?id=<?php echo $order_id; ?>" class="btn btn-success">
                        <i class="fas fa-truck"></i> Receive Items
                    </a>
                <?php endif; ?>
                
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Order
                </button>
                
                <button type="button" class="btn btn-info" onclick="exportToPDF()">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
            </div>
        </div>

        <!-- Company Header (for print) -->
        <div class="company-header" style="display: none;">
            <div class="company-name">GAMMA SYSTEMS</div>
            <div>Purchase Order</div>
        </div>

        <!-- Order Header Information -->
        <div class="info-card">
            <div class="row">
                <div class="col-md-6">
                    <h3 class="document-title">
                        <?php echo htmlspecialchars($order['po_number']); ?>
                    </h3>
                    <p class="text-muted mb-0">
                        <i class="fas fa-calendar"></i> 
                        Order Date: <?php echo date('d F Y', strtotime($order['po_date'])); ?>
                    </p>
                </div>
                <div class="col-md-6 text-end">
                    <span class="status-badge-large status-<?php echo $order['status']; ?>">
                        <?php echo ucfirst($order['status']); ?>
                    </span>
                    <p class="text-muted mt-2 mb-0">
                        <small>Created: <?php echo date('d-M-Y H:i', strtotime($order['created_at'])); ?></small>
                    </p>
                </div>
            </div>
        </div>

        <!-- Supplier & Delivery Information -->
        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <div class="section-title">
                        <i class="fas fa-building"></i> Supplier Information
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Supplier Name:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($order['supplier_name']); ?></strong>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Supplier Code:</div>
                        <div class="info-value"><?php echo htmlspecialchars($order['supplier_code'] ?? '-'); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Contact Person:</div>
                        <div class="info-value"><?php echo htmlspecialchars($order['contact_person'] ?? '-'); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Phone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($order['phone'] ?? '-'); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value"><?php echo htmlspecialchars($order['email'] ?? '-'); ?></div>
                    </div>
                    
                    <?php if ($order['physical_address']): ?>
                    <div class="info-row">
                        <div class="info-label">Address:</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($order['physical_address'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="info-card">
                    <div class="section-title">
                        <i class="fas fa-truck"></i> Delivery & Payment Information
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Expected Delivery:</div>
                        <div class="info-value">
                            <?php if ($order['delivery_date']): ?>
                                <strong><?php echo date('d F Y', strtotime($order['delivery_date'])); ?></strong>
                            <?php else: ?>
                                <span class="text-muted">Not specified</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Payment Terms:</div>
                        <div class="info-value"><?php echo htmlspecialchars($order['payment_terms'] ?? 'Not specified'); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Delivery Terms:</div>
                        <div class="info-value"><?php echo htmlspecialchars($order['delivery_terms'] ?? 'Not specified'); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Created By:</div>
                        <div class="info-value"><?php echo htmlspecialchars($order['created_by_name'] ?? 'System'); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Last Updated:</div>
                        <div class="info-value"><?php echo date('d-M-Y H:i', strtotime($order['updated_at'])); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fulfillment Status -->
        <?php if ($total_ordered > 0): ?>
        <div class="info-card">
            <div class="section-title">
                <i class="fas fa-chart-bar"></i> Order Fulfillment Status
            </div>
            
            <div class="row">
                <div class="col-md-3">
                    <strong>Total Ordered:</strong>
                    <div class="h4"><?php echo number_format($total_ordered, 2); ?></div>
                </div>
                <div class="col-md-3">
                    <strong>Total Received:</strong>
                    <div class="h4 text-success"><?php echo number_format($total_received, 2); ?></div>
                </div>
                <div class="col-md-3">
                    <strong>Remaining:</strong>
                    <div class="h4 text-warning"><?php echo number_format($total_ordered - $total_received, 2); ?></div>
                </div>
                <div class="col-md-3">
                    <strong>Completion:</strong>
                    <div class="h4 text-primary"><?php echo number_format($fulfillment_percent, 1); ?>%</div>
                </div>
            </div>
            
            <div class="fulfillment-progress">
                <div class="progress">
                    <div class="progress-bar <?php echo $fulfillment_percent == 100 ? 'bg-success' : ($fulfillment_percent > 0 ? 'bg-warning' : 'bg-secondary'); ?>" 
                         style="width: <?php echo $fulfillment_percent; ?>%">
                        <?php echo number_format($fulfillment_percent, 1); ?>% Complete
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Order Items -->
        <div class="info-card">
            <div class="section-title">
                <i class="fas fa-list"></i> Order Items (<?php echo count($items); ?>)
            </div>
            
            <div class="table-responsive">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">S/N</th>
                            <th style="width: 40%;">Item Description</th>
                            <th style="width: 10%;">Quantity</th>
                            <th style="width: 10%;">Unit</th>
                            <th style="width: 12%;">Unit Price</th>
                            <th style="width: 10%;">Received</th>
                            <th style="width: 13%;" class="text-end">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                    <p class="text-muted mb-0">No items found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $sn = 1;
                            foreach ($items as $item): 
                            ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><?php echo nl2br(htmlspecialchars($item['item_description'])); ?></td>
                                <td><strong><?php echo number_format($item['quantity'], 2); ?></strong></td>
                                <td><?php echo htmlspecialchars($item['unit_of_measure'] ?? 'pcs'); ?></td>
                                <td>TZS <?php echo number_format($item['unit_price'], 2); ?></td>
                                <td>
                                    <?php if ($item['quantity_received'] > 0): ?>
                                        <span class="badge bg-success">
                                            <?php echo number_format($item['quantity_received'], 2); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <strong>TZS <?php echo number_format($item['line_total'], 2); ?></strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Totals Section -->
            <div class="row">
                <div class="col-md-7"></div>
                <div class="col-md-5">
                    <div class="totals-section">
                        <div class="totals-row">
                            <span>Subtotal:</span>
                            <strong>TZS <?php echo number_format($order['subtotal'], 2); ?></strong>
                        </div>
                        
                        <div class="totals-row">
                            <span>Tax/VAT:</span>
                            <strong>TZS <?php echo number_format($order['tax_amount'], 2); ?></strong>
                        </div>
                        
                        <div class="totals-row grand-total">
                            <span>GRAND TOTAL:</span>
                            <strong>TZS <?php echo number_format($order['total_amount'], 2); ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <?php if ($order['notes']): ?>
        <div class="info-card">
            <div class="section-title">
                <i class="fas fa-sticky-note"></i> Notes / Special Instructions
            </div>
            <div class="alert alert-info mb-0">
                <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Show company header when printing
window.onbeforeprint = function() {
    document.querySelector('.company-header').style.display = 'block';
};

window.onafterprint = function() {
    document.querySelector('.company-header').style.display = 'none';
};

// Export to PDF (basic implementation)
function exportToPDF() {
    window.print();
}

// Auto-dismiss alerts
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        bsAlert.close();
    });
}, 5000);
</script>