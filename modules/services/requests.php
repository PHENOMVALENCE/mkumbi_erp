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
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_requests,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_requests,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN final_price ELSE 0 END), 0) as completed_revenue,
            COALESCE(SUM(quoted_price), 0) as total_quoted
        FROM service_requests
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching service request stats: " . $e->getMessage());
    $stats = [
        'total_requests' => 0,
        'pending_requests' => 0,
        'in_progress_requests' => 0,
        'completed_requests' => 0,
        'completed_revenue' => 0,
        'total_quoted' => 0
    ];
}

// Build filter conditions
$where_conditions = ["sr.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['status'])) {
    $where_conditions[] = "sr.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['service_type'])) {
    $where_conditions[] = "sr.service_type_id = ?";
    $params[] = $_GET['service_type'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "sr.request_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(sr.request_number LIKE ? OR c.full_name LIKE ? OR sr.service_description LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch service requests
try {
    $requests_query = "
        SELECT 
            sr.*,
            st.service_name,
            st.service_category,
            c.full_name as customer_name,
            c.phone as customer_phone,
            u.full_name as assigned_to_name
        FROM service_requests sr
        INNER JOIN service_types st ON sr.service_type_id = st.service_type_id
        LEFT JOIN customers c ON sr.customer_id = c.customer_id
        LEFT JOIN users u ON sr.assigned_to = u.user_id
        WHERE $where_clause
        ORDER BY sr.request_date DESC, sr.created_at DESC
    ";
    $stmt = $conn->prepare($requests_query);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching service requests: " . $e->getMessage());
    $requests = [];
}

// Fetch service types for filter
try {
    $types_query = "SELECT service_type_id, service_name FROM service_types WHERE company_id = ? AND is_active = 1 ORDER BY service_name";
    $stmt = $conn->prepare($types_query);
    $stmt->execute([$company_id]);
    $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $service_types = [];
}

$page_title = 'Service Requests';
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

.status-badge.pending { background: #e9ecef; color: #495057; }
.status-badge.quoted { background: #cfe2ff; color: #084298; }
.status-badge.approved { background: #fff3cd; color: #856404; }
.status-badge.in_progress { background: #d1ecf1; color: #0c5460; }
.status-badge.completed { background: #d4edda; color: #155724; }
.status-badge.cancelled { background: #f8d7da; color: #721c24; }
.status-badge.on_hold { background: #e2e3e5; color: #383d41; }

.request-number {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
}

.amount-display {
    font-weight: 700;
    color: #28a745;
}

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-clipboard-list text-info me-2"></i>Service Requests
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage customer service requests and orders</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="types.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-briefcase me-1"></i> Service Types
                    </a>
                    <a href="create.php" class="btn btn-info">
                        <i class="fas fa-plus-circle me-1"></i> New Request
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
                    <div class="stats-number"><?php echo number_format((int)$stats['total_requests']); ?></div>
                    <div class="stats-label">Total Requests</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format((int)$stats['pending_requests']); ?></div>
                    <div class="stats-label">Pending</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format((int)$stats['in_progress_requests']); ?></div>
                    <div class="stats-label">In Progress</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['completed_requests']); ?></div>
                    <div class="stats-label">Completed</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['completed_revenue'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Revenue</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Search</label>
                        <input type="text" 
                               name="search" 
                               class="form-control" 
                               placeholder="Request #, customer, description..."
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="quoted" <?php echo (isset($_GET['status']) && $_GET['status'] == 'quoted') ? 'selected' : ''; ?>>Quoted</option>
                            <option value="approved" <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                            <option value="in_progress" <?php echo (isset($_GET['status']) && $_GET['status'] == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Service Type</label>
                        <select name="service_type" class="form-select">
                            <option value="">All Services</option>
                            <?php foreach ($service_types as $type): ?>
                            <option value="<?php echo $type['service_type_id']; ?>"
                                    <?php echo (isset($_GET['service_type']) && $_GET['service_type'] == $type['service_type_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['service_name']); ?>
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
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search"></i>
                        </button>
                        <a href="requests.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Requests Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover" id="requestsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Request #</th>
                            <th>Service Type</th>
                            <th>Customer</th>
                            <th>Description</th>
                            <th>Quoted Price</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                <p class="mb-2">No service requests found.</p>
                                <a href="create.php" class="btn btn-info btn-sm">
                                    <i class="fas fa-plus me-1"></i> Create First Request
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($requests as $request): ?>
                        <tr>
                            <td>
                                <i class="fas fa-calendar text-info me-1"></i>
                                <?php echo date('M d, Y', strtotime($request['request_date'])); ?>
                            </td>
                            <td>
                                <span class="request-number">
                                    <?php echo htmlspecialchars($request['request_number']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($request['service_name']); ?></div>
                                <small class="text-muted"><?php echo ucfirst(str_replace('_', ' ', $request['service_category'])); ?></small>
                            </td>
                            <td>
                                <?php if (!empty($request['customer_name'])): ?>
                                <div class="fw-bold"><?php echo htmlspecialchars($request['customer_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($request['customer_phone']); ?></small>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars(substr($request['service_description'], 0, 60)) . (strlen($request['service_description']) > 60 ? '...' : ''); ?></small>
                            </td>
                            <td>
                                <?php if (!empty($request['quoted_price'])): ?>
                                <span class="amount-display">
                                    TSH <?php echo number_format((float)$request['quoted_price'], 0); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $request['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($request['assigned_to_name'])): ?>
                                <i class="fas fa-user me-1"></i>
                                <?php echo htmlspecialchars($request['assigned_to_name']); ?>
                                <?php else: ?>
                                <span class="text-muted">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="view-request.php?id=<?php echo $request['service_request_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($request['status'] == 'pending'): ?>
                                    <a href="edit-request.php?id=<?php echo $request['service_request_id']; ?>" 
                                       class="btn btn-outline-warning action-btn"
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
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
    const tableBody = $('#requestsTable tbody tr');
    const hasData = tableBody.length > 0 && !tableBody.first().find('td[colspan]').length;
    
    if (hasData) {
        $('#requestsTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            columnDefs: [
                { orderable: false, targets: -1 }
            ],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search requests..."
            }
        });
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>