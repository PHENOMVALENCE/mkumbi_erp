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

// Helper function to safely format numbers
function safe_format($number, $decimals = 0) {
    return number_format((float)$number ?: 0, $decimals);
}

// Fetch filter parameters
$status_filter = $_GET['status'] ?? '';
$project_filter = $_GET['project'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_clauses = ["r.company_id = ?"];
$params = [$company_id];

if ($status_filter) {
    $where_clauses[] = "r.status = ?";
    $params[] = $status_filter;
}

if ($project_filter) {
    $where_clauses[] = "p.project_id = ?";
    $params[] = $project_filter;
}

if ($date_from) {
    $where_clauses[] = "r.reservation_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "r.reservation_date <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_clauses[] = "(r.reservation_number LIKE ? OR c.full_name LIKE ? OR p.plot_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where_clauses);

// Calculate statistics
try {
    $stats_sql = "SELECT 
                    COUNT(*) as total_reservations,
                    SUM(CASE WHEN r.status = 'active' THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                    COALESCE(SUM(r.total_amount), 0) as total_revenue,
                    COALESCE(SUM((
                        SELECT SUM(amount) 
                        FROM payments 
                        WHERE reservation_id = r.reservation_id 
                          AND status = 'approved'
                    )), 0) as total_collected
                  FROM reservations r
                  LEFT JOIN plots p ON r.plot_id = p.plot_id
                  LEFT JOIN customers c ON r.customer_id = c.customer_id
                  WHERE $where_sql";
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute($params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    $total_outstanding = $stats['total_revenue'] - $stats['total_collected'];
} catch (PDOException $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
    $stats = [
        'total_reservations' => 0,
        'active_count' => 0,
        'completed_count' => 0,
        'cancelled_count' => 0,
        'total_revenue' => 0,
        'total_collected' => 0
    ];
    $total_outstanding = 0;
}

// Fetch all reservations
try {
    $sql = "SELECT r.*,
                   c.full_name as customer_name,
                   c.phone as customer_phone,
                   c.email as customer_email,
                   p.plot_number,
                   p.block_number,
                   p.area,
                   pr.project_name,
                   u.full_name as creator_name,
                   COALESCE((
                       SELECT SUM(amount) 
                       FROM payments 
                       WHERE reservation_id = r.reservation_id 
                         AND status = 'approved'
                   ), 0) as total_paid
            FROM reservations r
            LEFT JOIN customers c ON r.customer_id = c.customer_id
            LEFT JOIN plots p ON r.plot_id = p.plot_id
            LEFT JOIN projects pr ON p.project_id = pr.project_id
            LEFT JOIN users u ON r.created_by = u.user_id
            WHERE $where_sql
            ORDER BY r.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching reservations: " . $e->getMessage());
    $reservations = [];
}

// Fetch projects for filter
try {
    $stmt = $conn->prepare("
        SELECT project_id, project_name, project_code
        FROM projects
        WHERE company_id = ? AND is_active = 1
        ORDER BY project_name
    ");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $projects = [];
}

// Status badge function
function getStatusBadge($status) {
    $badges = [
        'draft' => 'secondary',
        'active' => 'success',
        'completed' => 'primary',
        'cancelled' => 'danger'
    ];
    $color = $badges[strtolower($status)] ?? 'secondary';
    return "<span class='badge bg-$color'>" . ucfirst($status) . "</span>";
}

$page_title = 'Reservations / Sales';
require_once '../../includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
/* DataTables Custom Styling */
.dataTables_wrapper {
    padding: 0;
}

.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    padding: 1rem 1.5rem;
}

.dataTables_wrapper .dataTables_filter input {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 0.5rem 1rem;
    margin-left: 0.5rem;
}

.dataTables_wrapper .dataTables_length select {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 0.5rem 1rem;
    margin: 0 0.5rem;
}

.dataTables_wrapper .dataTables_info {
    padding: 1rem 1.5rem;
    color: #6c757d;
    font-size: 0.875rem;
}

.dataTables_wrapper .dataTables_paginate {
    padding: 1rem 1.5rem;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 0.375rem 0.75rem;
    margin: 0 0.125rem;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    background: white;
    color: #495057;
    transition: all 0.2s;
}

.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: #f8f9fa;
    border-color: #dee2e6;
    color: #495057;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-color: #667eea;
    color: white !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    border-color: #764ba2;
    color: white !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

div.dt-buttons {
    padding: 1rem 1.5rem;
    display: inline-block;
}

.dt-button {
    background: white;
    border: 1px solid #dee2e6;
    color: #495057;
    padding: 0.5rem 1rem;
    border-radius: 6px;
    margin-right: 0.5rem;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.dt-button:hover {
    background: #f8f9fa;
    border-color: #adb5bd;
}

.dt-button:active {
    background: #e9ecef;
}

/* Responsive table styling */
table.dataTable.dtr-inline.collapsed > tbody > tr > td.dtr-control:before,
table.dataTable.dtr-inline.collapsed > tbody > tr > th.dtr-control:before {
    background-color: #667eea;
    border: 2px solid #667eea;
    box-shadow: 0 0 0 2px white, 0 0 0 4px #667eea;
}

table.dataTable.dtr-inline.collapsed > tbody > tr.parent > td.dtr-control:before,
table.dataTable.dtr-inline.collapsed > tbody > tr.parent > th.dtr-control:before {
    background-color: #dc3545;
    border: 2px solid #dc3545;
}

.dtr-details {
    width: 100%;
}

.dtr-details li {
    border-bottom: 1px solid #f0f0f0;
    padding: 0.75rem 0;
}

.dtr-details li:last-child {
    border-bottom: none;
}

.dtr-title {
    font-weight: 600;
    color: #495057;
    min-width: 150px;
    display: inline-block;
}

.dtr-data {
    color: #6c757d;
}

/* Loading indicator */
.dataTables_processing {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 200px;
    margin-left: -100px;
    margin-top: -26px;
    text-align: center;
    padding: 1rem;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
    height: 100%;
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
    font-size: 2rem;
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

.stats-subtext {
    color: #6c757d;
    font-size: 0.8rem;
    margin-top: 0.25rem;
}

.filter-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.table-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.table-card .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.25rem 1.5rem;
    border: none;
}

.table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 1rem;
    white-space: nowrap;
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.action-buttons {
    display: flex;
    gap: 0.25rem;
    flex-wrap: nowrap;
}

.reservation-number {
    font-weight: 600;
    color: #007bff;
}

.customer-name {
    font-weight: 500;
}

.amount-cell {
    font-weight: 600;
}

.progress-wrapper {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.progress-bar-custom {
    flex: 1;
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
    border-radius: 4px;
    transition: width 0.3s;
}

.progress-fill.complete {
    background: linear-gradient(90deg, #007bff 0%, #0056b3 100%);
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.badge {
    font-size: 0.75rem;
    padding: 0.35rem 0.65rem;
    font-weight: 600;
}

@media (max-width: 768px) {
    .stats-number {
        font-size: 1.5rem;
    }
    
    .table {
        font-size: 0.875rem;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-contract text-primary me-2"></i>Reservations / Sales
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage plot reservations and sales</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="create.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> New Reservation
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo safe_format($stats['total_reservations']); ?></div>
                    <div class="stats-label">Total Reservations</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo safe_format($stats['active_count']); ?></div>
                    <div class="stats-label">Active</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo safe_format($stats['completed_count']); ?></div>
                    <div class="stats-label">Completed</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number">TSH <?php echo safe_format($stats['total_revenue'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Revenue</div>
                    <div class="stats-subtext">Collected: TSH <?php echo safe_format($stats['total_collected'] / 1000000, 1); ?>M</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Reservation #, customer, plot..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Project</label>
                    <select name="project" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['project_id']; ?>" <?php echo $project_filter == $proj['project_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($proj['project_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                    <a href="create.php" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i> New Reservation
                    </a>
                </div>
            </form>
        </div>

        <!-- Reservations Table -->
        <div class="table-card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>All Reservations
                    <span class="badge bg-light text-dark ms-2"><?php echo safe_format(count($reservations)); ?> reservations</span>
                </h5>
            </div>
            <div class="table-responsive">
                <?php if (empty($reservations)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-contract"></i>
                    <h4>No Reservations Found</h4>
                    <p class="text-muted">No reservations match your current filters</p>
                    <a href="create.php" class="btn btn-primary mt-3">
                        <i class="fas fa-plus-circle me-1"></i> Create Your First Reservation
                    </a>
                </div>
                <?php else: ?>
                <table id="reservationsTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Reservation #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Plot Details</th>
                            <th>Project</th>
                            <th>Total Amount</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $reservation): 
                            $total_amount = (float)($reservation['total_amount'] ?? 0);
                            $total_paid   = (float)($reservation['total_paid'] ?? 0);
                            $balance      = $total_amount - $total_paid;
                            $progress     = $total_amount > 0 ? ($total_paid / $total_amount) * 100 : 0;
                        ?>
                        <tr>
                            <td>
                                <span class="reservation-number"><?php echo htmlspecialchars($reservation['reservation_number'] ?? 'N/A'); ?></span>
                            </td>
                            <td>
                                <div><?php echo $reservation['reservation_date'] ? date('d M Y', strtotime($reservation['reservation_date'])) : 'N/A'; ?></div>
                            </td>
                            <td>
                                <div class="customer-name"><?php echo htmlspecialchars($reservation['customer_name'] ?? 'Unknown'); ?></div>
                                <?php if (!empty($reservation['customer_phone'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($reservation['customer_phone']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong>Plot <?php echo htmlspecialchars($reservation['plot_number'] ?? 'N/A'); ?></strong></div>
                                <?php if (!empty($reservation['block_number'])): ?>
                                    <small class="text-muted">Block <?php echo htmlspecialchars($reservation['block_number']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($reservation['area'])): ?>
                                    <br><small class="text-muted"><?php echo safe_format($reservation['area']); ?> m²</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($reservation['project_name'] ?? 'Unknown'); ?></td>
                            <td>
                                <div class="amount-cell text-primary">
                                    TSH <?php echo safe_format($total_amount); ?>
                                </div>
                            </td>
                            <td>
                                <div class="amount-cell text-success">
                                    TSH <?php echo safe_format($total_paid); ?>
                                </div>
                            </td>
                            <td>
                                <div class="amount-cell text-danger">
                                    TSH <?php echo safe_format($balance); ?>
                                </div>
                            </td>
                            <td>
                                <div class="progress-wrapper">
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill <?php echo $progress >= 100 ? 'complete' : ''; ?>" 
                                             style="width: <?php echo min(100, $progress); ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?php echo number_format($progress, 0); ?>%</small>
                                </div>
                            </td>
                            <td><?php echo getStatusBadge($reservation['status'] ?? 'draft'); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view.php?id=<?php echo $reservation['reservation_id']; ?>" 
                                       class="btn btn-sm btn-info" 
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $reservation['reservation_id']; ?>" 
                                       class="btn btn-sm btn-warning" 
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="../payments/record.php?reservation_id=<?php echo $reservation['reservation_id']; ?>" 
                                       class="btn btn-sm btn-success" 
                                       title="Add Payment">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </a>
                                    <a href="view.php?id=<?php echo $reservation['reservation_id']; ?>#payments" 
                                       class="btn btn-sm btn-primary" 
                                       title="View Payments">
                                        <i class="fas fa-list"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="5" class="text-end">Totals:</th>
                            <th class="amount-cell text-primary">TSH <?php echo safe_format($stats['total_revenue']); ?></th>
                            <th class="amount-cell text-success">TSH <?php echo safe_format($stats['total_collected']); ?></th>
                            <th class="amount-cell text-danger">TSH <?php echo safe_format($total_outstanding); ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script>
$(document).ready(function() {
    $('#reservationsTable').DataTable({
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[1, 'desc']], // Sort by date descending
        columnDefs: [
            { orderable: false, targets: 10 }, // Disable sorting on Actions column
            { responsivePriority: 1, targets: 0 }, // Reservation # - always visible
            { responsivePriority: 2, targets: 2 }, // Customer - always visible
            { responsivePriority: 3, targets: 9 }, // Status - always visible
            { responsivePriority: 4, targets: 10 }, // Actions - always visible
        ],
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12 col-md-6"B>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            {
                extend: 'copy',
                text: '<i class="fas fa-copy me-1"></i> Copy',
                className: 'btn btn-sm btn-secondary',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 9] // Exclude progress and actions
                }
            },
            {
                extend: 'excel',
                text: '<i class="fas fa-file-excel me-1"></i> Excel',
                className: 'btn btn-sm btn-success',
                title: 'Reservations Report',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 9]
                }
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf me-1"></i> PDF',
                className: 'btn btn-sm btn-danger',
                title: 'Reservations Report',
                orientation: 'landscape',
                pageSize: 'A4',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 9]
                },
                customize: function(doc) {
                    doc.defaultStyle.fontSize = 9;
                    doc.styles.tableHeader.fontSize = 10;
                    doc.styles.tableHeader.fillColor = '#667eea';
                    doc.styles.tableHeader.color = 'white';
                }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print me-1"></i> Print',
                className: 'btn btn-sm btn-info',
                title: 'Reservations Report',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 9]
                },
                customize: function(win) {
                    $(win.document.body)
                        .css('font-size', '10pt')
                        .prepend(
                            '<div style="text-align:center; margin-bottom: 20px;">' +
                            '<h2>Reservations / Sales Report</h2>' +
                            '<p>Generated on: ' + new Date().toLocaleString() + '</p>' +
                            '</div>'
                        );
                    
                    $(win.document.body).find('table')
                        .addClass('compact')
                        .css('font-size', '9pt');
                }
            }
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search reservations...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ reservations",
            infoEmpty: "Showing 0 to 0 of 0 reservations",
            infoFiltered: "(filtered from _MAX_ total reservations)",
            zeroRecords: "No matching reservations found",
            emptyTable: "No reservations available",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        drawCallback: function() {
            // Re-initialize tooltips after table redraw
            $('[title]').tooltip();
        }
    });

    // Initialize tooltips
    $('[title]').tooltip();
});
</script>

<?php require_once '../../includes/footer.php'; ?>