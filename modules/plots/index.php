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

// ==================== STATISTICS ====================
try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_plots,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_plots,
            SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved_plots,
            SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_plots,
            COALESCE(SUM(area), 0) as total_area,
            COALESCE(SUM(CASE WHEN status = 'sold' THEN (selling_price - COALESCE(discount_amount, 0)) ELSE 0 END), 0) as total_revenue
        FROM plots 
        WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
    $stats = ['total_plots'=>0,'available_plots'=>0,'reserved_plots'=>0,'sold_plots'=>0,'total_area'=>0,'total_revenue'=>0];
}

// ==================== PROJECTS FOR FILTER ====================
$projects = [];
try {
    $stmt = $conn->prepare("
        SELECT p.project_id, p.project_name, p.project_code,
        (SELECT COUNT(*) FROM plots WHERE project_id = p.project_id AND company_id = ?) as plot_count
        FROM projects p
        WHERE p.company_id = ? AND p.is_active = 1 
        ORDER BY p.project_name
    ");
    $stmt->execute([$company_id, $company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Projects error: " . $e->getMessage());
    $projects = [];
}

// ==================== BUILD FILTERS ====================
$where = ['p.company_id = ?'];
$params = [$company_id];

if (!empty($_GET['project_id'])) {
    $where[] = 'p.project_id = ?';
    $params[] = (int)$_GET['project_id'];
}
if (!empty($_GET['status']) && in_array($_GET['status'], ['available','reserved','sold','blocked'])) {
    $where[] = 'p.status = ?';
    $params[] = $_GET['status'];
}
if (!empty($_GET['search'])) {
    $s = '%' . trim($_GET['search']) . '%';
    $where[] = '(p.plot_number LIKE ? OR p.block_number LIKE ? OR pr.project_name LIKE ?)';
    $params[] = $s; $params[] = $s; $params[] = $s;
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// ==================== FETCH PLOTS ====================
$plots = [];
try {
    $query = "
        SELECT 
            p.plot_id,
            p.plot_number,
            p.block_number,
            p.area,
            p.price_per_sqm,
            p.selling_price,
            p.discount_amount,
            (p.selling_price - COALESCE(p.discount_amount, 0)) as final_price,
            p.status,
            p.corner_plot,
            pr.project_name,
            pr.project_code,
            r.customer_id,
            c.first_name,
            c.middle_name,
            c.last_name
        FROM plots p
        LEFT JOIN projects pr ON p.project_id = pr.project_id
        LEFT JOIN reservations r ON p.plot_id = r.plot_id AND r.status = 'active'
        LEFT JOIN customers c ON r.customer_id = c.customer_id
        $where_clause
        ORDER BY pr.project_name, COALESCE(p.block_number, ''), p.plot_number
        LIMIT 1000
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $plots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Plots query failed: " . $e->getMessage());
    $plots = [];
}

$page_title = 'Plots Management';
require_once '../../includes/header.php';
?>

<style>
/* Stats Cards */
.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 0.875rem 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid;
    height: 100%;
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.purple { border-left-color: #6f42c1; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.15rem;
    line-height: 1;
}

.stats-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #6c757d;
    font-weight: 600;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.available {
    background: #d4edda;
    color: #155724;
}

.status-badge.reserved {
    background: #fff3cd;
    color: #856404;
}

.status-badge.sold {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.blocked {
    background: #f8d7da;
    color: #721c24;
}

/* Table Styling */
.table-professional {
    font-size: 0.85rem;
}

.table-professional thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #495057;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.3px;
    padding: 0.65rem 0.5rem;
    white-space: nowrap;
}

.table-professional tbody td {
    padding: 0.65rem 0.5rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.table-professional tbody tr:hover {
    background-color: #f8f9fa;
}

/* Table Cell Specific Styling */
.plot-number {
    font-weight: 700;
    color: #2c3e50;
    font-size: 0.9rem;
}

.block-number {
    color: #6c757d;
    font-size: 0.85rem;
}

.project-info {
    line-height: 1.3;
}

.project-code {
    display: block;
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 0.1rem;
}

.project-name {
    font-weight: 500;
    color: #2c3e50;
    font-size: 0.85rem;
}

.area-value {
    font-weight: 600;
    color: #2c3e50;
    font-size: 0.85rem;
}

.price-value {
    font-weight: 600;
    color: #2c3e50;
    white-space: nowrap;
    font-size: 0.85rem;
}

.price-discount {
    display: block;
    font-size: 0.75rem;
    color: #dc3545;
    text-decoration: line-through;
    margin-bottom: 0.1rem;
}

.customer-name {
    color: #495057;
    font-size: 0.85rem;
}

/* Action Buttons */
.action-btn {
    padding: 0.3rem 0.6rem;
    font-size: 0.75rem;
    border-radius: 3px;
    margin-right: 0.2rem;
    margin-bottom: 0.2rem;
    white-space: nowrap;
}

.btn-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.2rem;
}

/* Cards */
.filter-card {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.main-card {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
}

.empty-state i {
    font-size: 3rem;
    color: #dee2e6;
    margin-bottom: 1rem;
}

.empty-state p {
    color: #6c757d;
    font-size: 1rem;
}

/* DataTables Overrides */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    font-size: 0.875rem;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 0.375rem 0.75rem;
    margin: 0 0.125rem;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-number {
        font-size: 1.5rem;
    }
    
    .stats-label {
        font-size: 0.7rem;
    }
    
    .btn-actions {
        flex-direction: column;
    }
    
    .action-btn {
        margin-right: 0;
        width: 100%;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">Plots Management</h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="create.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i>Add New Plot
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Statistics Cards -->
    <div class="row g-2 mb-3">
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($stats['total_plots']) ?></div>
                <div class="stats-label">Total Plots</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($stats['available_plots']) ?></div>
                <div class="stats-label">Available</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($stats['reserved_plots']) ?></div>
                <div class="stats-label">Reserved</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card info">
                <div class="stats-number"><?= number_format($stats['sold_plots']) ?></div>
                <div class="stats-label">Sold</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card purple">
                <div class="stats-number"><?= number_format($stats['total_area'], 0) ?></div>
                <div class="stats-label">Total m²</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($stats['total_revenue']/1000000, 1) ?>M</div>
                <div class="stats-label">Revenue (TSH)</div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card filter-card mb-3">
        <div class="card-body">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted mb-1">SEARCH</label>
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Plot number, block, or project..." 
                           value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">PROJECT</label>
                    <select name="project_id" class="form-select form-select-sm">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['project_id'] ?>" 
                                    <?= ($_GET['project_id'] ?? '') == $p['project_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['project_name']) ?> (<?= $p['plot_count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">STATUS</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="available" <?= ($_GET['status'] ?? '') === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="reserved" <?= ($_GET['status'] ?? '') === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                        <option value="sold" <?= ($_GET['status'] ?? '') === 'sold' ? 'selected' : '' ?>>Sold</option>
                        <option value="blocked" <?= ($_GET['status'] ?? '') === 'blocked' ? 'selected' : '' ?>>Blocked</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="card main-card">
        <div class="card-body">
            <?php if (empty($plots)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No plots found matching your criteria</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="plotsTable">
                        <thead>
                            <tr>
                                <th>Plot No.</th>
                                <th>Block</th>
                                <th>Project</th>
                                <th class="text-end">Area (m²)</th>
                                <th class="text-end">Price/m²</th>
                                <th class="text-end">Total Price</th>
                                <th>Status</th>
                                <th>Customer</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plots as $p): 
                                $customer = trim(($p['first_name']??'') . ' ' . ($p['middle_name']??'') . ' ' . ($p['last_name']??''));
                                $customer = $customer ?: '-';
                            ?>
                                <tr>
                                    <td class="plot-number"><?= htmlspecialchars($p['plot_number']) ?></td>
                                    <td class="block-number"><?= htmlspecialchars($p['block_number'] ?: '-') ?></td>
                                    <td>
                                        <div class="project-info">
                                            <span class="project-code"><?= htmlspecialchars($p['project_code'] ?: '') ?></span>
                                            <span class="project-name"><?= htmlspecialchars($p['project_name'] ?: 'Unknown') ?></span>
                                        </div>
                                    </td>
                                    <td class="text-end area-value"><?= number_format($p['area'], 2) ?></td>
                                    <td class="text-end price-value">
                                        <?= number_format($p['price_per_sqm'], 0) ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($p['discount_amount'] > 0): ?>
                                            <span class="price-discount"><?= number_format($p['selling_price'], 0) ?></span>
                                        <?php endif; ?>
                                        <span class="price-value"><?= number_format($p['final_price'], 0) ?></span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $p['status'] ?>">
                                            <?= ucfirst($p['status']) ?>
                                        </span>
                                    </td>
                                    <td class="customer-name"><?= htmlspecialchars($customer) ?></td>
                                    <td>
                                        <div class="btn-actions">
                                            <a href="view.php?id=<?= $p['plot_id'] ?>" 
                                               class="btn btn-sm btn-outline-primary action-btn">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="edit.php?id=<?= $p['plot_id'] ?>" 
                                               class="btn btn-sm btn-outline-warning action-btn">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-danger action-btn" 
                                                    onclick="confirmDelete(<?= $p['plot_id'] ?>, '<?= htmlspecialchars(addslashes($p['plot_number'])) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- DataTables -->
                <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
                <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

                <script>
                $(document).ready(function() {
                    $('#plotsTable').DataTable({
                        pageLength: 25,
                        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                        responsive: true,
                        order: [[2, 'asc'], [0, 'asc']],
                        columnDefs: [
                            { 
                                targets: [3, 4, 5],
                                className: 'text-end'
                            },
                            { 
                                targets: 8,
                                orderable: false,
                                className: 'text-center'
                            }
                        ],
                        language: {
                            search: "Search:",
                            lengthMenu: "Show _MENU_ entries",
                            info: "Showing _START_ to _END_ of _TOTAL_ plots",
                            infoEmpty: "Showing 0 to 0 of 0 plots",
                            infoFiltered: "(filtered from _MAX_ total plots)",
                            paginate: {
                                first: "First",
                                last: "Last",
                                next: "Next",
                                previous: "Previous"
                            }
                        },
                        drawCallback: function() {
                            $('#plotsTable tbody tr').off('click');
                        }
                    });
                });

                function confirmDelete(id, num) {
                    if (confirm('Are you sure you want to delete plot "' + num + '"?\n\nThis action cannot be undone.')) {
                        window.location.href = 'delete.php?id=' + id;
                    }
                }
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>