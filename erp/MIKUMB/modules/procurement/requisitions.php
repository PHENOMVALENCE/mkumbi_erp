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
            COUNT(*) as total_requisitions,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_requisitions,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_requisitions,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requisitions,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_requisitions,
            SUM(CASE WHEN status = 'ordered' THEN 1 ELSE 0 END) as ordered_requisitions,
            COUNT(CASE WHEN required_date < CURDATE() AND status NOT IN ('ordered', 'cancelled') THEN 1 END) as overdue_requisitions
        FROM purchase_requisitions
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching requisition stats: " . $e->getMessage());
    $stats = [
        'total_requisitions' => 0,
        'draft_requisitions' => 0,
        'submitted_requisitions' => 0,
        'approved_requisitions' => 0,
        'rejected_requisitions' => 0,
        'ordered_requisitions' => 0,
        'overdue_requisitions' => 0
    ];
}

// Build filter conditions
$where_conditions = ["pr.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['status'])) {
    $where_conditions[] = "pr.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['department_id'])) {
    $where_conditions[] = "pr.department_id = ?";
    $params[] = $_GET['department_id'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "pr.requisition_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "pr.requisition_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(pr.requisition_number LIKE ? OR pr.purpose LIKE ? OR u.full_name LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch requisitions
try {
    $requisitions_query = "
        SELECT 
            pr.*,
            d.department_name,
            u.full_name as requested_by_name,
            approver.full_name as approved_by_name,
            COUNT(pri.requisition_item_id) as item_count,
            COALESCE(SUM(pri.quantity * pri.estimated_unit_price), 0) as estimated_total
        FROM purchase_requisitions pr
        LEFT JOIN departments d ON pr.department_id = d.department_id
        INNER JOIN users u ON pr.requested_by = u.user_id
        LEFT JOIN users approver ON pr.approved_by = approver.user_id
        LEFT JOIN requisition_items pri ON pr.requisition_id = pri.requisition_id
        WHERE $where_clause
        GROUP BY pr.requisition_id
        ORDER BY pr.requisition_date DESC, pr.created_at DESC
    ";
    $stmt = $conn->prepare($requisitions_query);
    $stmt->execute($params);
    $requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching requisitions: " . $e->getMessage());
    $requisitions = [];
}

// Fetch departments for filter
try {
    $dept_query = "SELECT department_id, department_name FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY department_name";
    $stmt = $conn->prepare($dept_query);
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

$page_title = 'Purchase Requisitions';
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
.stats-card.secondary { border-left-color: #6c757d; }

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

.status-badge.rejected {
    background: #f8d7da;
    color: #721c24;
}

.status-badge.ordered {
    background: #cfe2ff;
    color: #084298;
}

.status-badge.cancelled {
    background: #f8d7da;
    color: #721c24;
}

.requisition-number {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
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
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-alt text-primary me-2"></i>Purchase Requisitions
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage purchase requisition requests</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="orders.php" class="btn btn-outline-primary me-2">
                        <i class="fas fa-shopping-cart me-1"></i> Purchase Orders
                    </a>
                    <a href="create-requisition.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> New Requisition
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
                    <div class="stats-number"><?php echo number_format((int)$stats['total_requisitions']); ?></div>
                    <div class="stats-label">Total Requisitions</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format((int)$stats['submitted_requisitions']); ?></div>
                    <div class="stats-label">Submitted</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['approved_requisitions']); ?></div>
                    <div class="stats-label">Approved</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format((int)$stats['ordered_requisitions']); ?></div>
                    <div class="stats-label">Ordered</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format((int)$stats['overdue_requisitions']); ?></div>
                    <div class="stats-label">Overdue</div>
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
                           placeholder="Requisition #, purpose, requester..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo (isset($_GET['status']) && $_GET['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="submitted" <?php echo (isset($_GET['status']) && $_GET['status'] == 'submitted') ? 'selected' : ''; ?>>Submitted</option>
                        <option value="approved" <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                        <option value="ordered" <?php echo (isset($_GET['status']) && $_GET['status'] == 'ordered') ? 'selected' : ''; ?>>Ordered</option>
                        <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department_id']; ?>" 
                                <?php echo (isset($_GET['department_id']) && $_GET['department_id'] == $dept['department_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_name']); ?>
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
                <a href="requisitions.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-redo me-1"></i> Reset Filters
                </a>
            </div>
        </div>

        <!-- Requisitions Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover" id="requisitionsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Requisition #</th>
                            <th>Department</th>
                            <th>Requested By</th>
                            <th>Purpose</th>
                            <th>Items</th>
                            <th>Est. Total</th>
                            <th>Required By</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requisitions)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                <p class="mb-2">No requisitions found.</p>
                                <a href="create-requisition.php" class="btn btn-primary btn-sm">
                                    <i class="fas fa-plus me-1"></i> Create First Requisition
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($requisitions as $req): ?>
                        <?php
                        $isOverdue = !empty($req['required_date']) && 
                                    strtotime($req['required_date']) < time() && 
                                    !in_array($req['status'], ['ordered', 'cancelled']);
                        ?>
                        <tr>
                            <td>
                                <i class="fas fa-calendar text-primary me-1"></i>
                                <?php echo date('M d, Y', strtotime($req['requisition_date'])); ?>
                            </td>
                            <td>
                                <span class="requisition-number">
                                    <?php echo htmlspecialchars($req['requisition_number']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($req['department_name'] ?? 'N/A'); ?>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($req['requested_by_name']); ?></div>
                                <small class="text-muted"><?php echo date('M d, Y', strtotime($req['created_at'])); ?></small>
                            </td>
                            <td>
                                <?php if (!empty($req['purpose'])): ?>
                                <?php echo htmlspecialchars(substr($req['purpose'], 0, 50)) . (strlen($req['purpose']) > 50 ? '...' : ''); ?>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo number_format((int)$req['item_count']); ?> items</span>
                            </td>
                            <td>
                                <span class="fw-bold text-primary">
                                    TSH <?php echo number_format((float)$req['estimated_total'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($req['required_date'])): ?>
                                <?php echo date('M d, Y', strtotime($req['required_date'])); ?>
                                <?php if ($isOverdue): ?>
                                <br><span class="overdue-badge">OVERDUE</span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $req['status']; ?>">
                                    <?php echo ucfirst($req['status']); ?>
                                </span>
                                <?php if ($req['status'] == 'approved' && !empty($req['approved_by_name'])): ?>
                                <div class="small text-muted mt-1">
                                    <i class="fas fa-user-check me-1"></i>
                                    <?php echo htmlspecialchars($req['approved_by_name']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="view-requisition.php?id=<?php echo $req['requisition_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($req['status'] == 'draft'): ?>
                                    <a href="edit-requisition.php?id=<?php echo $req['requisition_id']; ?>" 
                                       class="btn btn-outline-warning action-btn"
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($req['status'] == 'approved'): ?>
                                    <a href="create-order.php?requisition_id=<?php echo $req['requisition_id']; ?>" 
                                       class="btn btn-outline-success action-btn"
                                       title="Create PO">
                                        <i class="fas fa-shopping-cart"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
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
    const tableBody = $('#requisitionsTable tbody tr');
    const hasData = tableBody.length > 0 && !tableBody.first().find('td[colspan]').length;
    
    if (hasData) {
        $('#requisitionsTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            columnDefs: [
                { orderable: false, targets: -1 }
            ],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search requisitions..."
            }
        });
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>