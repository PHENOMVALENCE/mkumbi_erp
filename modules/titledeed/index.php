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

// Helper function to safely format numbers
function safe_format($number, $decimals = 0) {
    return number_format((float)$number ?: 0, $decimals);
}

$error = '';
$success = '';

if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Fetch filter parameters
$stage_filter = $_GET['stage'] ?? '';
$customer_filter = $_GET['customer'] ?? '';
$project_filter = $_GET['project'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_clauses = ["tdp.company_id = ?"];
$params = [$company_id];

if ($stage_filter) {
    $where_clauses[] = "tdp.current_stage = ?";
    $params[] = $stage_filter;
}

if ($customer_filter) {
    $where_clauses[] = "c.customer_id = ?";
    $params[] = $customer_filter;
}

if ($project_filter) {
    $where_clauses[] = "pr.project_id = ?";
    $params[] = $project_filter;
}

if ($date_from) {
    $where_clauses[] = "tdp.started_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "tdp.started_date <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_clauses[] = "(tdp.processing_number LIKE ? OR c.full_name LIKE ? OR p.plot_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where_clauses);

// Calculate statistics
try {
    $stats_sql = "SELECT 
                    COUNT(*) as total_processing,
                    SUM(CASE WHEN tdp.current_stage = 'startup' THEN 1 ELSE 0 END) as startup_count,
                    SUM(CASE WHEN tdp.current_stage = 'municipal' THEN 1 ELSE 0 END) as municipal_count,
                    SUM(CASE WHEN tdp.current_stage = 'ministry_of_land' THEN 1 ELSE 0 END) as ministry_count,
                    SUM(CASE WHEN tdp.current_stage = 'approved' THEN 1 ELSE 0 END) as approved_count,
                    SUM(CASE WHEN tdp.current_stage = 'received' THEN 1 ELSE 0 END) as received_count,
                    SUM(CASE WHEN tdp.current_stage = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                    COALESCE(SUM(tdp.total_cost), 0) as total_costs,
                    COALESCE(SUM(tdp.customer_contribution), 0) as total_customer_contribution
                  FROM title_deed_processing tdp
                  LEFT JOIN customers c ON tdp.customer_id = c.customer_id
                  LEFT JOIN plots p ON tdp.plot_id = p.plot_id
                  LEFT JOIN projects pr ON p.project_id = pr.project_id
                  WHERE $where_sql";
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute($params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
    $stats = [
        'total_processing' => 0,
        'startup_count' => 0,
        'municipal_count' => 0,
        'ministry_count' => 0,
        'approved_count' => 0,
        'received_count' => 0,
        'delivered_count' => 0,
        'total_costs' => 0,
        'total_customer_contribution' => 0
    ];
}

// Fetch all processing records
try {
    $sql = "SELECT 
                tdp.processing_id,
                tdp.processing_number,
                tdp.current_stage,
                tdp.total_cost,
                tdp.customer_contribution,
                tdp.started_date,
                tdp.expected_completion_date,
                tdp.actual_completion_date,
                tdp.notes,
                tdp.created_at,
                c.customer_id,
                c.full_name as customer_name,
                COALESCE(c.phone, c.phone1) as customer_phone,
                p.plot_id,
                p.plot_number,
                p.block_number,
                p.area as plot_area,
                pr.project_id,
                pr.project_name,
                r.reservation_number,
                u.full_name as assigned_to_name,
                (SELECT COUNT(*) FROM title_deed_stages 
                 WHERE processing_id = tdp.processing_id AND stage_status = 'completed') as completed_stages
            FROM title_deed_processing tdp
            LEFT JOIN customers c ON tdp.customer_id = c.customer_id
            LEFT JOIN plots p ON tdp.plot_id = p.plot_id
            LEFT JOIN projects pr ON p.project_id = pr.project_id
            LEFT JOIN reservations r ON tdp.reservation_id = r.reservation_id
            LEFT JOIN users u ON tdp.assigned_to = u.user_id
            WHERE $where_sql
            ORDER BY tdp.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $processing_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching processing records: " . $e->getMessage());
    $processing_records = [];
}

// Fetch customers for filter
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT c.customer_id, c.full_name
        FROM customers c
        INNER JOIN title_deed_processing tdp ON c.customer_id = tdp.customer_id
        WHERE c.company_id = ? AND c.is_active = 1
        ORDER BY c.full_name
    ");
    $stmt->execute([$company_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Fetch projects for filter
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT pr.project_id, pr.project_name
        FROM projects pr
        INNER JOIN plots p ON pr.project_id = p.project_id
        INNER JOIN title_deed_processing tdp ON p.plot_id = tdp.plot_id
        WHERE pr.company_id = ? AND pr.is_active = 1
        ORDER BY pr.project_name
    ");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $projects = [];
}

// Stage badge function
function getStageBadge($stage) {
    $badges = [
        'startup' => ['color' => 'secondary', 'icon' => 'play-circle', 'label' => 'Startup'],
        'municipal' => ['color' => 'info', 'icon' => 'building', 'label' => 'Municipal'],
        'ministry_of_land' => ['color' => 'primary', 'icon' => 'landmark', 'label' => 'Ministry'],
        'approved' => ['color' => 'success', 'icon' => 'check-circle', 'label' => 'Approved'],
        'received' => ['color' => 'warning', 'icon' => 'inbox', 'label' => 'Received'],
        'delivered' => ['color' => 'dark', 'icon' => 'handshake', 'label' => 'Delivered']
    ];
    
    $badge = $badges[$stage] ?? ['color' => 'secondary', 'icon' => 'question', 'label' => ucfirst($stage)];
    return "<span class='badge bg-{$badge['color']}'><i class='fas fa-{$badge['icon']} me-1'></i>{$badge['label']}</span>";
}

// Calculate progress
function calculateProgress($completed_stages) {
    $total_stages = 6;
    return $total_stages > 0 ? ($completed_stages / $total_stages) * 100 : 0;
}

$page_title = 'Title Deed Processing';
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

.stats-card.secondary { border-left-color: #6c757d; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.dark { border-left-color: #343a40; }

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

.stats-icon {
    font-size: 2.5rem;
    opacity: 0.3;
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
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

.processing-number {
    font-weight: 600;
    color: #007bff;
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
    background: linear-gradient(90deg, #007bff 0%, #667eea 100%);
    border-radius: 4px;
    transition: width 0.3s;
}

.progress-fill.complete {
    background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
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
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-contract text-primary me-2"></i>Title Deed Processing
                </h1>
                <p class="text-muted small mb-0 mt-1">Track and manage title deed processing</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="initiate.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> Initiate New Processing
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
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card secondary position-relative">
                    <i class="fas fa-play-circle stats-icon"></i>
                    <div class="stats-number"><?php echo safe_format($stats['startup_count']); ?></div>
                    <div class="stats-label">Startup</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card info position-relative">
                    <i class="fas fa-building stats-icon"></i>
                    <div class="stats-number"><?php echo safe_format($stats['municipal_count']); ?></div>
                    <div class="stats-label">Municipal</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card primary position-relative">
                    <i class="fas fa-landmark stats-icon"></i>
                    <div class="stats-number"><?php echo safe_format($stats['ministry_count']); ?></div>
                    <div class="stats-label">Ministry</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card success position-relative">
                    <i class="fas fa-check-circle stats-icon"></i>
                    <div class="stats-number"><?php echo safe_format($stats['approved_count']); ?></div>
                    <div class="stats-label">Approved</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card warning position-relative">
                    <i class="fas fa-inbox stats-icon"></i>
                    <div class="stats-number"><?php echo safe_format($stats['received_count']); ?></div>
                    <div class="stats-label">Received</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card dark position-relative">
                    <i class="fas fa-handshake stats-icon"></i>
                    <div class="stats-number"><?php echo safe_format($stats['delivered_count']); ?></div>
                    <div class="stats-label">Delivered</div>
                </div>
            </div>
        </div>

        <!-- Cost Summary -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stats-card primary position-relative">
                    <i class="fas fa-file-contract stats-icon"></i>
                    <div class="stats-number"><?php echo safe_format($stats['total_processing']); ?></div>
                    <div class="stats-label">Total Processing</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card warning position-relative">
                    <i class="fas fa-dollar-sign stats-icon"></i>
                    <div class="stats-number">TSH <?php echo safe_format($stats['total_costs'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Costs</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card success position-relative">
                    <i class="fas fa-hand-holding-usd stats-icon"></i>
                    <div class="stats-number">TSH <?php echo safe_format($stats['total_customer_contribution'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Customer Contribution</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Processing #, customer, plot..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Stage</label>
                    <select name="stage" class="form-select">
                        <option value="">All Stages</option>
                        <option value="startup" <?php echo $stage_filter === 'startup' ? 'selected' : ''; ?>>Startup</option>
                        <option value="municipal" <?php echo $stage_filter === 'municipal' ? 'selected' : ''; ?>>Municipal</option>
                        <option value="ministry_of_land" <?php echo $stage_filter === 'ministry_of_land' ? 'selected' : ''; ?>>Ministry of Land</option>
                        <option value="approved" <?php echo $stage_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="received" <?php echo $stage_filter === 'received' ? 'selected' : ''; ?>>Received</option>
                        <option value="delivered" <?php echo $stage_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Customer</label>
                    <select name="customer" class="form-select">
                        <option value="">All Customers</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?php echo $cust['customer_id']; ?>" <?php echo $customer_filter == $cust['customer_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cust['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
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
                <div class="col-md-1">
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
                    <a href="initiate.php" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i> New Processing
                    </a>
                </div>
            </form>
        </div>

        <!-- Processing Table -->
        <div class="table-card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>All Processing Records
                    <span class="badge bg-light text-dark ms-2"><?php echo safe_format(count($processing_records)); ?> records</span>
                </h5>
            </div>
            <div class="table-responsive">
                <?php if (empty($processing_records)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-contract"></i>
                    <h4>No Processing Records Found</h4>
                    <p class="text-muted">No title deed processing records match your current filters</p>
                    <a href="initiate.php" class="btn btn-primary mt-3">
                        <i class="fas fa-plus-circle me-1"></i> Initiate Your First Processing
                    </a>
                </div>
                <?php else: ?>
                <table id="processingTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Processing #</th>
                            <th>Started Date</th>
                            <th>Customer</th>
                            <th>Plot Details</th>
                            <th>Project</th>
                            <th>Current Stage</th>
                            <th>Progress</th>
                            <th>Total Cost</th>
                            <th>Expected Date</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processing_records as $record): 
                            $progress = calculateProgress($record['completed_stages']);
                        ?>
                        <tr>
                            <td>
                                <span class="processing-number"><?php echo htmlspecialchars($record['processing_number']); ?></span>
                            </td>
                            <td>
                                <?php echo $record['started_date'] ? date('d M Y', strtotime($record['started_date'])) : 'N/A'; ?>
                            </td>
                            <td>
                                <div class="customer-name"><?php echo htmlspecialchars($record['customer_name']); ?></div>
                                <?php if (!empty($record['customer_phone'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($record['customer_phone']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong>Plot <?php echo htmlspecialchars($record['plot_number']); ?></strong></div>
                                <?php if (!empty($record['block_number'])): ?>
                                    <small class="text-muted">Block <?php echo htmlspecialchars($record['block_number']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($record['plot_area'])): ?>
                                    <br><small class="text-muted"><?php echo safe_format($record['plot_area']); ?> m²</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($record['project_name'] ?? 'N/A'); ?></td>
                            <td><?php echo getStageBadge($record['current_stage']); ?></td>
                            <td>
                                <div class="progress-wrapper">
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill <?php echo $progress >= 100 ? 'complete' : ''; ?>" 
                                             style="width: <?php echo min(100, $progress); ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?php echo number_format($progress, 0); ?>%</small>
                                </div>
                                <small class="text-muted"><?php echo $record['completed_stages']; ?>/6 stages</small>
                            </td>
                            <td>
                                <div class="text-primary fw-bold">TSH <?php echo safe_format($record['total_cost']); ?></div>
                                <small class="text-muted">Customer: TSH <?php echo safe_format($record['customer_contribution']); ?></small>
                            </td>
                            <td>
                                <?php echo $record['expected_completion_date'] ? date('d M Y', strtotime($record['expected_completion_date'])) : 'Not set'; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($record['assigned_to_name'] ?? 'Unassigned'); ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view.php?id=<?php echo $record['processing_id']; ?>" 
                                       class="btn btn-sm btn-info" 
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $record['processing_id']; ?>" 
                                       class="btn btn-sm btn-warning" 
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="update_stage.php?id=<?php echo $record['processing_id']; ?>" 
                                       class="btn btn-sm btn-primary" 
                                       title="Update Stage">
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                    <a href="add_cost.php?id=<?php echo $record['processing_id']; ?>" 
                                       class="btn btn-sm btn-success" 
                                       title="Add Cost">
                                        <i class="fas fa-dollar-sign"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
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
    $('#processingTable').DataTable({
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[1, 'desc']],
        columnDefs: [
            { orderable: false, targets: 10 },
            { responsivePriority: 1, targets: 0 },
            { responsivePriority: 2, targets: 2 },
            { responsivePriority: 3, targets: 5 },
            { responsivePriority: 4, targets: 10 },
        ],
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12 col-md-6"B>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            {
                extend: 'excel',
                text: '<i class="fas fa-file-excel me-1"></i> Excel',
                className: 'btn btn-sm btn-success'
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf me-1"></i> PDF',
                className: 'btn btn-sm btn-danger'
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print me-1"></i> Print',
                className: 'btn btn-sm btn-info'
            }
        ]
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>