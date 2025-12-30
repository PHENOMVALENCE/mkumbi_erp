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
// Handle status filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
// Build query with filters - ONLY INCLUDE APPROVED COSTS
$sql = "SELECT
    p.*,
    COUNT(DISTINCT pl.plot_id) as plot_count,
    SUM(CASE WHEN pl.status = 'available' THEN 1 ELSE 0 END) as available_count,
    SUM(CASE WHEN pl.status = 'reserved' THEN 1 ELSE 0 END) as reserved_count,
    SUM(CASE WHEN pl.status = 'sold' THEN 1 ELSE 0 END) as sold_count,
    COALESCE(SUM(CASE WHEN pc.approved_by IS NOT NULL THEN pc.cost_amount ELSE 0 END), 0) as total_costs
FROM projects p
LEFT JOIN plots pl ON p.project_id = pl.project_id AND pl.is_active = 1
LEFT JOIN project_costs pc ON p.project_id = pc.project_id
WHERE p.company_id = ?";
$params = [$company_id];
// Apply status filter
if ($status_filter != 'all') {
    $sql .= " AND p.status = ?";
    $params[] = $status_filter;
}
// Apply search filter
if (!empty($search_query)) {
    $sql .= " AND (p.project_name LIKE ? OR p.project_code LIKE ? OR p.physical_location LIKE ? OR p.region_name LIKE ? OR p.district_name LIKE ? OR p.ward_name LIKE ? OR p.village_name LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}
$sql .= " GROUP BY p.project_id
ORDER BY p.created_at DESC";
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Get summary statistics
    $stats_sql = "SELECT
        COUNT(*) as total_projects,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_projects,
        SUM(CASE WHEN status = 'planning' THEN 1 ELSE 0 END) as planning_projects,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_projects,
        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_projects,
        SUM(total_area) as total_area,
        SUM(total_plots) as total_plots
    FROM projects
    WHERE company_id = ? AND is_active = 1";
   
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute([$company_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Projects query error: " . $e->getMessage());
    $projects = [];
    $stats = [
        'total_projects' => 0,
        'active_projects' => 0,
        'planning_projects' => 0,
        'completed_projects' => 0,
        'suspended_projects' => 0,
        'total_area' => 0,
        'total_plots' => 0
    ];
}
$page_title = 'Projects Management';
require_once '../../includes/header.php';
?>
<!-- Include DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
<style>
.project-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
}
.project-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 1.5rem;
    color: white;
    margin-bottom: 1rem;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}
.stats-card.success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
}
.stats-card.warning {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}
.stats-card.info {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}
.stats-number {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}
.stats-label {
    font-size: 0.875rem;
    opacity: 0.9;
}
.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 1.5rem;
}
.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.status-planning { background: #e3f2fd; color: #1976d2; }
.status-active { background: #e8f5e9; color: #388e3c; }
.status-completed { background: #f3e5f5; color: #7b1fa2; }
.status-suspended { background: #fff3e0; color: #f57c00; }
.progress-custom {
    height: 8px;
    border-radius: 4px;
    background: #e9ecef;
}
.progress-bar-custom {
    border-radius: 4px;
}
.action-btn {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 6px;
    transition: all 0.2s;
}
.action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}
.table-actions {
    white-space: nowrap;
}
.badge-counter {
    font-size: 0.7rem;
    padding: 0.25rem 0.5rem;
    border-radius: 10px;
}
@media (max-width: 768px) {
    .stats-number {
        font-size: 1.5rem;
    }
   
    .filter-card {
        padding: 1rem;
    }
}
</style>
<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
    <?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success alert-dismissible fade show position-fixed" style="top: 20px; right: 20px; z-index: 9999;">
    <strong>Success!</strong> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show position-fixed" style="top: 20px; right: 20px; z-index: 9999;">
    <strong>Error!</strong> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    Projects Management
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Manage and monitor all your land development projects
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="create.php" class="btn btn-primary">
                        Add New Project
                    </a>
                    <button class="btn btn-outline-secondary" onclick="window.print()">
                        Print
                    </button>
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
                <div class="stats-card">
                    <div class="stats-number"><?php echo number_format($stats['total_projects'] ?? 0); ?></div>
                    <div class="stats-label">Total Projects</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format($stats['active_projects'] ?? 0); ?></div>
                    <div class="stats-label">Active Projects</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format(($stats['total_area'] ?? 0) / 4046.86, 1); ?> Acre</div>
                    <div class="stats-label">Total Area (Acres)</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format($stats['total_plots'] ?? 0); ?></div>
                    <div class="stats-label">Total Plots</div>
                </div>
            </div>
        </div>
        <!-- Filters and Search -->
        <div class="filter-card">
            <form method="GET" action="" id="filterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-4 col-md-6">
                        <label class="form-label fw-semibold">
                            Search Projects
                        </label>
                        <input type="text"
                               name="search"
                               class="form-control"
                               placeholder="Project name, code, or location..."
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label fw-semibold">
                            Status Filter
                        </label>
                        <select name="status" class="form-select" onchange="document.getElementById('filterForm').submit()">
                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="planning" <?php echo $status_filter == 'planning' ? 'selected' : ''; ?>>Planning</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="suspended" <?php echo $status_filter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="col-lg-5 col-md-12">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                Search
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary">
                                Reset
                            </a>
                            <button type="button" class="btn btn-outline-success ms-auto" onclick="exportToExcel()">
                                Export Excel
                            </button>
                            <button type="button" class="btn btn-outline-danger" onclick="exportToPDF()">
                                Export PDF
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <!-- Projects Table -->
        <div class="card project-card">
            <div class="card-header bg-white border-bottom">
                <h5 class="mb-0">
                    Projects List
                    <span class="badge bg-primary ms-2"><?php echo count($projects); ?> Projects</span>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="projectsTable">
                        <thead class="table-light">
                            <tr>
                                <th width="5%">#</th>
                                <th>Project Name</th>
                                <th>Location</th>
                                <th>Area (m²)</th>
                                <th>Plots</th>
                                <th>Availability</th>
                                <th>Investment</th>
                                <th>Status</th>
                                <th width="12%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($projects)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    <h5>No Projects Found</h5>
                                    <p>Start by creating your first project</p>
                                    <a href="create.php" class="btn btn-primary mt-2">
                                        Add New Project
                                    </a>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($projects as $index => $project):
                                $total_plots = $project['plot_count'];
                                $available = $project['available_count'];
                                $reserved = $project['reserved_count'];
                                $sold = $project['sold_count'];
                                $availability_percentage = $total_plots > 0 ? ($available / $total_plots) * 100 : 0;
                               
                                // Calculate total investment (land purchase + APPROVED operational costs)
                                $total_investment = ($project['land_purchase_price'] ?? 0) + ($project['total_costs'] ?? 0);
                               
                                // Status badge classes
                                $status_class = match($project['status']) {
                                    'planning' => 'status-planning',
                                    'active' => 'status-active',
                                    'completed' => 'status-completed',
                                    'suspended' => 'status-suspended',
                                    default => 'bg-secondary text-white'
                                };

                                // Convert m² to Acre
                                $area_acres = ($project['total_area'] ?? 0) / 4046.86;
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-2">
                                            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center"
                                                 style="width: 40px; height: 40px;">
                                                P
                                            </div>
                                        </div>
                                        <div>
                                            <div class="fw-semibold">
                                                <?php echo htmlspecialchars($project['project_name']); ?>
                                            </div>
                                            <small class="text-muted">
                                                Code: <?php echo htmlspecialchars($project['project_code'] ?? 'N/A'); ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <?php
                                        $location_parts = array_filter([
                                            $project['village_name'],
                                            $project['ward_name'],
                                            $project['district_name'],
                                            $project['region_name']
                                        ]);
                                        echo htmlspecialchars(implode(', ', $location_parts) ?: 'Not specified');
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark">
                                        <?php echo number_format($area_acres, 2); ?> Acre
                                    </span>
                                    <br><small class="text-muted"><?php echo number_format($project['total_area'] ?? 0); ?> m²</small>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold"><?php echo number_format($total_plots); ?> Total</span>
                                        <small class="text-muted">
                                            <span class="badge badge-counter bg-success"><?php echo $sold; ?> Sold</span>
                                            <span class="badge badge-counter bg-info"><?php echo $reserved; ?> Reserved</span>
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <div class="progress-custom mb-1">
                                        <div class="progress-bar bg-success progress-bar-custom"
                                             role="progressbar"
                                             style="width: <?php echo $availability_percentage; ?>%"
                                             aria-valuenow="<?php echo $availability_percentage; ?>"
                                             aria-valuemin="0"
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo $available; ?> Available (<?php echo number_format($availability_percentage, 1); ?>%)
                                    </small>
                                </td>
                                <td>
                                    <div class="fw-semibold text-success">
                                        TSH <?php echo number_format($total_investment); ?>
                                    </div>
                                    <small class="text-muted" title="Approved costs only">
                                        Costs: TSH <?php echo number_format($project['total_costs']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo ucfirst($project['status']); ?>
                                    </span>
                                </td>
                                <td class="table-actions">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="view.php?id=<?php echo $project['project_id']; ?>"
                                           class="btn btn-outline-primary action-btn"
                                           title="View Details">
                                            View
                                        </a>
                                        <a href="edit.php?id=<?php echo $project['project_id']; ?>"
                                           class="btn btn-outline-warning action-btn"
                                           title="Edit Project">
                                            Edit
                                        </a>
                                        <button type="button"
                                                class="btn btn-outline-danger action-btn"
                                                onclick="confirmDelete(<?php echo $project['project_id']; ?>, '<?php echo htmlspecialchars(addslashes($project['project_name'])); ?>')"
                                                title="Delete Project">
                                            Delete
                                        </button>
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
    </div>
</section>
<!-- DataTables Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script>
$(document).ready(function() {
    const tableBody = $('#projectsTable tbody tr');
    const hasData = tableBody.length > 0 && !tableBody.first().find('td[colspan]').length;
   
    if (hasData) {
        const table = $('#projectsTable').DataTable({
            responsive: true,
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: -1 }
            ],
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search projects...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ projects",
                infoEmpty: "No projects available",
                infoFiltered: "(filtered from _MAX_ total projects)",
                zeroRecords: "No matching projects found",
                emptyTable: "No projects available",
                paginate: {
                    first: 'First',
                    previous: 'Previous',
                    next: 'Next',
                    last: 'Last'
                }
            },
            drawCallback: function() {
                $('.dataTables_paginate > .pagination').addClass('pagination-sm');
            }
        });
    }
});

function confirmDelete(projectId, projectName) {
    if (confirm(`Are you sure you want to delete the project "${projectName}"?\n\nThis action cannot be undone and will affect all associated plots and data.`)) {
        window.location.href = `delete.php?id=${projectId}`;
    }
}

function exportToExcel() {
    const table = $('#projectsTable').DataTable();
    table.button('.buttons-excel').trigger();
}

function exportToPDF() {
    const table = $('#projectsTable').DataTable();
    table.button('.buttons-pdf').trigger();
}

window.addEventListener('beforeprint', function() {
    document.querySelectorAll('.table-actions').forEach(el => {
        el.style.display = 'none';
    });
});
window.addEventListener('afterprint', function() {
    document.querySelectorAll('.table-actions').forEach(el => {
        el.style.display = '';
    });
});
</script>
<?php
require_once '../../includes/footer.php';
?>