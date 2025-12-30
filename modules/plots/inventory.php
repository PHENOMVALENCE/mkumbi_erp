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

// ==================== HANDLE FORM SUBMISSIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add_plot':
                $stmt = $conn->prepare("INSERT INTO plots 
                    (company_id, project_id, plot_number, block_number, area, 
                     selling_price, price_per_sqm, status, corner_plot, coordinates, 
                     description, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $price_per_sqm = $_POST['area'] > 0 ? 
                    ($_POST['selling_price'] / $_POST['area']) : 0;
                
                $stmt->execute([
                    $company_id,
                    $_POST['project_id'],
                    $_POST['plot_number'],
                    $_POST['block_number'] ?? null,
                    $_POST['area'],
                    $_POST['selling_price'],
                    $price_per_sqm,
                    $_POST['status'],
                    isset($_POST['corner_plot']) ? 1 : 0,
                    $_POST['coordinates'] ?? null,
                    $_POST['description'] ?? null,
                    $_SESSION['user_id']
                ]);
                $_SESSION['success_message'] = "Plot added successfully!";
                header("Location: inventory.php");
                exit();
                break;

            case 'update_plot':
                $price_per_sqm = $_POST['area'] > 0 ? 
                    ($_POST['selling_price'] / $_POST['area']) : 0;
                
                $stmt = $conn->prepare("UPDATE plots 
                    SET project_id = ?, plot_number = ?, block_number = ?, area = ?, 
                        selling_price = ?, price_per_sqm = ?, status = ?, 
                        corner_plot = ?, coordinates = ?, description = ?
                    WHERE plot_id = ? AND company_id = ?");
                $stmt->execute([
                    $_POST['project_id'],
                    $_POST['plot_number'],
                    $_POST['block_number'] ?? null,
                    $_POST['area'],
                    $_POST['selling_price'],
                    $price_per_sqm,
                    $_POST['status'],
                    isset($_POST['corner_plot']) ? 1 : 0,
                    $_POST['coordinates'] ?? null,
                    $_POST['description'] ?? null,
                    $_POST['plot_id'],
                    $company_id
                ]);
                $_SESSION['success_message'] = "Plot updated successfully!";
                header("Location: inventory.php");
                exit();
                break;

            case 'delete_plot':
                $stmt = $conn->prepare("UPDATE plots SET is_active = 0 
                    WHERE plot_id = ? AND company_id = ?");
                $stmt->execute([$_POST['plot_id'], $company_id]);
                $_SESSION['success_message'] = "Plot deleted successfully!";
                header("Location: inventory.php");
                exit();
                break;

            case 'bulk_update_status':
                if (!empty($_POST['plot_ids']) && !empty($_POST['new_status'])) {
                    $plot_ids = $_POST['plot_ids'];
                    $placeholders = str_repeat('?,', count($plot_ids) - 1) . '?';
                    
                    $stmt = $conn->prepare("UPDATE plots 
                        SET status = ? 
                        WHERE plot_id IN ($placeholders) AND company_id = ?");
                    
                    $params = array_merge([$_POST['new_status']], $plot_ids, [$company_id]);
                    $stmt->execute($params);
                    
                    $_SESSION['success_message'] = count($plot_ids) . " plots updated successfully!";
                }
                header("Location: inventory.php");
                exit();
                break;
        }
    } catch (Exception $e) {
        error_log("Plot operation error: " . $e->getMessage());
        $_SESSION['error_message'] = "Operation failed: " . $e->getMessage();
        header("Location: inventory.php");
        exit();
    }
}

// ==================== STATISTICS ====================
try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_plots,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_plots,
            SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved_plots,
            SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_plots,
            SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_plots,
            COALESCE(SUM(selling_price), 0) as total_value,
            COALESCE(SUM(CASE WHEN status = 'available' THEN selling_price ELSE 0 END), 0) as available_value,
            COALESCE(SUM(CASE WHEN status = 'sold' THEN selling_price ELSE 0 END), 0) as sold_value,
            COALESCE(AVG(selling_price), 0) as avg_price,
            COALESCE(SUM(area), 0) as total_area
        FROM plots 
        WHERE company_id = ? AND is_active = 1
    ");
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
    $stats = [
        'total_plots'=>0, 'available_plots'=>0, 'reserved_plots'=>0, 
        'sold_plots'=>0, 'blocked_plots'=>0, 'total_value'=>0, 
        'available_value'=>0, 'sold_value'=>0, 'avg_price'=>0, 'total_area'=>0
    ];
}

// ==================== PROJECTS FOR FILTER ====================
$projects = [];
try {
    $stmt = $conn->prepare("
        SELECT project_id, project_name, project_code
        FROM projects 
        WHERE company_id = ? AND is_active = 1 
        ORDER BY project_name
    ");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Projects error: " . $e->getMessage());
}

// ==================== BUILD FILTERS ====================
$where = ['p.company_id = ?', 'p.is_active = 1'];
$params = [$company_id];

if (!empty($_GET['project_id'])) {
    $where[] = 'p.project_id = ?';
    $params[] = (int)$_GET['project_id'];
}
if (!empty($_GET['status'])) {
    $where[] = 'p.status = ?';
    $params[] = $_GET['status'];
}
if (!empty($_GET['block'])) {
    $where[] = 'p.block_number = ?';
    $params[] = $_GET['block'];
}
if (!empty($_GET['corner_plot'])) {
    $where[] = 'p.corner_plot = 1';
}
if (!empty($_GET['min_price'])) {
    $where[] = 'p.selling_price >= ?';
    $params[] = (float)$_GET['min_price'];
}
if (!empty($_GET['max_price'])) {
    $where[] = 'p.selling_price <= ?';
    $params[] = (float)$_GET['max_price'];
}
if (!empty($_GET['min_area'])) {
    $where[] = 'p.area >= ?';
    $params[] = (float)$_GET['min_area'];
}
if (!empty($_GET['max_area'])) {
    $where[] = 'p.area <= ?';
    $params[] = (float)$_GET['max_area'];
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// ==================== FETCH PLOTS ====================
$plots = [];
try {
    $query = "
        SELECT 
            p.*,
            pr.project_name,
            pr.project_code,
            DATEDIFF(CURDATE(), p.created_at) as days_in_inventory
        FROM plots p
        LEFT JOIN projects pr ON p.project_id = pr.project_id
        $where_clause
        ORDER BY p.created_at DESC
        LIMIT 1000
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $plots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Plots query failed: " . $e->getMessage());
    $plots = [];
}

// Get unique blocks for filter
$blocks = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT block_number 
        FROM plots 
        WHERE company_id = ? AND is_active = 1 AND block_number IS NOT NULL
        ORDER BY block_number
    ");
    $stmt->execute([$company_id]);
    $blocks = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Blocks error: " . $e->getMessage());
}

$page_title = 'Plot Inventory';
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

.status-badge.available { background: #d4edda; color: #155724; }
.status-badge.reserved { background: #fff3cd; color: #856404; }
.status-badge.sold { background: #d1ecf1; color: #0c5460; }
.status-badge.blocked { background: #f8d7da; color: #721c24; }

/* Feature Badges */
.feature-badge {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 3px;
    font-size: 0.65rem;
    font-weight: 600;
    background: #e7f3ff;
    color: #0066cc;
    margin-right: 0.3rem;
}

.corner-badge {
    background: #fff4e6;
    color: #ff8c00;
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

/* Plot Info */
.plot-number {
    font-weight: 600;
    color: #2c3e50;
    font-size: 0.9rem;
}

.plot-block {
    display: block;
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
}

.project-info {
    line-height: 1.3;
}

.project-code {
    display: block;
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
}

.project-name {
    font-weight: 500;
    color: #2c3e50;
    font-size: 0.85rem;
}

/* Amount Values */
.amount-value {
    font-weight: 600;
    color: #2c3e50;
    white-space: nowrap;
}

.price-per-sqm {
    display: block;
    font-size: 0.7rem;
    color: #6c757d;
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
.filter-card, .main-card {
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

/* Bulk Actions Bar */
.bulk-actions-bar {
    position: fixed;
    bottom: -100px;
    left: 0;
    right: 0;
    background: #2c3e50;
    color: white;
    padding: 1rem;
    box-shadow: 0 -2px 10px rgba(0,0,0,0.2);
    transition: bottom 0.3s ease;
    z-index: 1000;
}

.bulk-actions-bar.active {
    bottom: 0;
}

.bulk-actions-content {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Inventory Age Indicator */
.inventory-age {
    font-size: 0.7rem;
}

.inventory-age.new { color: #28a745; }
.inventory-age.normal { color: #ffc107; }
.inventory-age.slow { color: #dc3545; }
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">Plot Inventory</h1>
            </div>
            <div class="col-sm-6 text-end">
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPlotModal">
                    <i class="fas fa-plus me-1"></i>Add Plot
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

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
                <div class="stats-number"><?= number_format($stats['total_value']/1000000, 1) ?>M</div>
                <div class="stats-label">Total Value</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($stats['available_value']/1000000, 1) ?>M</div>
                <div class="stats-label">Available Value</div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card filter-card mb-3">
        <div class="card-body">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">PROJECT</label>
                    <select name="project_id" class="form-select form-select-sm">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['project_id'] ?>" 
                                    <?= ($_GET['project_id'] ?? '') == $p['project_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['project_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
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
                    <label class="form-label small fw-semibold text-muted mb-1">BLOCK</label>
                    <select name="block" class="form-select form-select-sm">
                        <option value="">All Blocks</option>
                        <?php foreach ($blocks as $block): ?>
                            <option value="<?= htmlspecialchars($block) ?>" 
                                    <?= ($_GET['block'] ?? '') === $block ? 'selected' : '' ?>>
                                Block <?= htmlspecialchars($block) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">PRICE RANGE</label>
                    <div class="input-group input-group-sm">
                        <input type="number" name="min_price" class="form-control" placeholder="Min" 
                               value="<?= $_GET['min_price'] ?? '' ?>" step="1000000">
                        <input type="number" name="max_price" class="form-control" placeholder="Max" 
                               value="<?= $_GET['max_price'] ?? '' ?>" step="1000000">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">AREA (SQM)</label>
                    <div class="input-group input-group-sm">
                        <input type="number" name="min_area" class="form-control" placeholder="Min" 
                               value="<?= $_GET['min_area'] ?? '' ?>" step="100">
                        <input type="number" name="max_area" class="form-control" placeholder="Max" 
                               value="<?= $_GET['max_area'] ?? '' ?>" step="100">
                    </div>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter"></i>
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
                    <i class="fas fa-map-marked-alt"></i>
                    <p>No plots found matching your criteria</p>
                </div>
            <?php else: ?>
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleBulkSelection()">
                        <i class="fas fa-check-square me-1"></i>Bulk Actions
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="plotsTable">
                        <thead>
                            <tr>
                                <th width="30">
                                    <input type="checkbox" id="selectAll" onchange="toggleAllPlots(this)">
                                </th>
                                <th>Plot</th>
                                <th>Project</th>
                                <th class="text-end">Area (sqm)</th>
                                <th class="text-end">Price</th>
                                <th>Status</th>
                                <th>Features</th>
                                <th>Age</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plots as $plot): 
                                $age_class = $plot['days_in_inventory'] > 180 ? 'slow' : 
                                            ($plot['days_in_inventory'] > 90 ? 'normal' : 'new');
                            ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="plot-checkbox" 
                                               value="<?= $plot['plot_id'] ?>" 
                                               onchange="updateBulkActionsBar()">
                                    </td>
                                    <td>
                                        <div class="plot-number">
                                            Plot <?= htmlspecialchars($plot['plot_number']) ?>
                                        </div>
                                        <?php if ($plot['block_number']): ?>
                                            <span class="plot-block">Block <?= htmlspecialchars($plot['block_number']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="project-info">
                                            <span class="project-code"><?= htmlspecialchars($plot['project_code'] ?: '') ?></span>
                                            <span class="project-name"><?= htmlspecialchars($plot['project_name'] ?: 'Unknown') ?></span>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <span class="amount-value"><?= number_format($plot['area'], 0) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <div class="amount-value"><?= number_format($plot['selling_price'], 0) ?></div>
                                        <span class="price-per-sqm"><?= number_format($plot['price_per_sqm'], 0) ?>/sqm</span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $plot['status'] ?>">
                                            <?= ucfirst($plot['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($plot['corner_plot']): ?>
                                            <span class="feature-badge corner-badge">
                                                <i class="fas fa-location-arrow"></i> Corner
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="inventory-age <?= $age_class ?>">
                                            <?= $plot['days_in_inventory'] ?> days
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-actions">
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-warning action-btn"
                                                    onclick='editPlot(<?= json_encode($plot, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                    title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-danger action-btn"
                                                    onclick="deletePlot(<?= $plot['plot_id'] ?>, '<?= htmlspecialchars(addslashes($plot['plot_number'])) ?>')"
                                                    title="Delete">
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
                        order: [[7, 'desc']],
                        columnDefs: [
                            { targets: [0, 8], orderable: false },
                            { targets: [3, 4], className: 'text-end' }
                        ]
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bulk Actions Bar -->
<div class="bulk-actions-bar" id="bulkActionsBar">
    <div class="bulk-actions-content">
        <div>
            <span id="selectedCount">0</span> plots selected
        </div>
        <div>
            <select class="form-select form-select-sm d-inline-block w-auto me-2" id="bulkStatusSelect">
                <option value="">Change Status To...</option>
                <option value="available">Available</option>
                <option value="reserved">Reserved</option>
                <option value="sold">Sold</option>
                <option value="blocked">Blocked</option>
            </select>
            <button type="button" class="btn btn-sm btn-light me-2" onclick="applyBulkAction()">
                <i class="fas fa-check me-1"></i>Apply
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" onclick="clearSelection()">
                <i class="fas fa-times me-1"></i>Clear
            </button>
        </div>
    </div>
</div>

<!-- Add Plot Modal -->
<div class="modal fade" id="addPlotModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Plot</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_plot">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Project <span class="text-danger">*</span></label>
                            <select name="project_id" class="form-select" required>
                                <option value="">Select Project</option>
                                <?php foreach ($projects as $p): ?>
                                    <option value="<?= $p['project_id'] ?>">
                                        <?= htmlspecialchars($p['project_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Plot Number <span class="text-danger">*</span></label>
                            <input type="text" name="plot_number" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Block Number</label>
                            <input type="text" name="block_number" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Area (sqm) <span class="text-danger">*</span></label>
                            <input type="number" name="area" class="form-control" required 
                                   step="0.01" min="0" id="add_area" onchange="calculatePricePerSqm('add')">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Selling Price (TZS) <span class="text-danger">*</span></label>
                            <input type="number" name="selling_price" class="form-control" required 
                                   step="0.01" min="0" id="add_selling_price" onchange="calculatePricePerSqm('add')">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Price/Sqm</label>
                            <input type="text" class="form-control" id="add_price_per_sqm" readonly 
                                   style="background: #e9ecef;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select" required>
                                <option value="available">Available</option>
                                <option value="reserved">Reserved</option>
                                <option value="sold">Sold</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Coordinates (GPS)</label>
                            <input type="text" name="coordinates" class="form-control" 
                                   placeholder="-6.7924, 39.2083">
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="corner_plot" id="add_corner_plot">
                                <label class="form-check-label" for="add_corner_plot">
                                    Corner Plot (Premium)
                                </label>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description / Notes</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Add Plot
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Plot Modal -->
<div class="modal fade" id="editPlotModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Plot</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_plot">
                <input type="hidden" name="plot_id" id="edit_plot_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Project <span class="text-danger">*</span></label>
                            <select name="project_id" id="edit_project_id" class="form-select" required>
                                <?php foreach ($projects as $p): ?>
                                    <option value="<?= $p['project_id'] ?>">
                                        <?= htmlspecialchars($p['project_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Plot Number <span class="text-danger">*</span></label>
                            <input type="text" name="plot_number" id="edit_plot_number" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Block Number</label>
                            <input type="text" name="block_number" id="edit_block_number" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Area (sqm) <span class="text-danger">*</span></label>
                            <input type="number" name="area" id="edit_area" class="form-control" required 
                                   step="0.01" min="0" onchange="calculatePricePerSqm('edit')">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Selling Price (TZS) <span class="text-danger">*</span></label>
                            <input type="number" name="selling_price" id="edit_selling_price" class="form-control" required 
                                   step="0.01" min="0" onchange="calculatePricePerSqm('edit')">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Price/Sqm</label>
                            <input type="text" class="form-control" id="edit_price_per_sqm" readonly 
                                   style="background: #e9ecef;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="available">Available</option>
                                <option value="reserved">Reserved</option>
                                <option value="sold">Sold</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Coordinates (GPS)</label>
                            <input type="text" name="coordinates" id="edit_coordinates" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="corner_plot" id="edit_corner_plot">
                                <label class="form-check-label" for="edit_corner_plot">
                                    Corner Plot (Premium)
                                </label>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description / Notes</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i>Update Plot
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Plot Modal -->
<div class="modal fade" id="deletePlotModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="delete_plot">
                <input type="hidden" name="plot_id" id="delete_plot_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete plot:</p>
                    <p class="fw-bold" id="delete_plot_number"></p>
                    <p class="text-danger"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editPlot(plot) {
    document.getElementById('edit_plot_id').value = plot.plot_id;
    document.getElementById('edit_project_id').value = plot.project_id;
    document.getElementById('edit_plot_number').value = plot.plot_number;
    document.getElementById('edit_block_number').value = plot.block_number || '';
    document.getElementById('edit_area').value = plot.area;
    document.getElementById('edit_selling_price').value = plot.selling_price;
    document.getElementById('edit_status').value = plot.status;
    document.getElementById('edit_coordinates').value = plot.coordinates || '';
    document.getElementById('edit_corner_plot').checked = plot.corner_plot == 1;
    document.getElementById('edit_description').value = plot.description || '';
    
    calculatePricePerSqm('edit');
    
    new bootstrap.Modal(document.getElementById('editPlotModal')).show();
}

function deletePlot(id, plotNumber) {
    document.getElementById('delete_plot_id').value = id;
    document.getElementById('delete_plot_number').textContent = 'Plot ' + plotNumber;
    
    new bootstrap.Modal(document.getElementById('deletePlotModal')).show();
}

function calculatePricePerSqm(prefix) {
    const area = parseFloat(document.getElementById(prefix + '_area').value) || 0;
    const price = parseFloat(document.getElementById(prefix + '_selling_price').value) || 0;
    const pricePerSqm = area > 0 ? (price / area) : 0;
    
    document.getElementById(prefix + '_price_per_sqm').value = 
        pricePerSqm > 0 ? 'TZS ' + pricePerSqm.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",") : '';
}

function toggleAllPlots(checkbox) {
    const checkboxes = document.querySelectorAll('.plot-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateBulkActionsBar();
}

function updateBulkActionsBar() {
    const selected = document.querySelectorAll('.plot-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = selected;
    
    if (selected > 0) {
        document.getElementById('bulkActionsBar').classList.add('active');
    } else {
        document.getElementById('bulkActionsBar').classList.remove('active');
    }
}

function clearSelection() {
    document.querySelectorAll('.plot-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkActionsBar();
}

function applyBulkAction() {
    const newStatus = document.getElementById('bulkStatusSelect').value;
    if (!newStatus) {
        alert('Please select a status');
        return;
    }
    
    const selectedPlots = Array.from(document.querySelectorAll('.plot-checkbox:checked'))
        .map(cb => cb.value);
    
    if (selectedPlots.length === 0) {
        alert('No plots selected');
        return;
    }
    
    if (confirm(`Update ${selectedPlots.length} plots to ${newStatus}?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'bulk_update_status';
        form.appendChild(actionInput);
        
        const statusInput = document.createElement('input');
        statusInput.type = 'hidden';
        statusInput.name = 'new_status';
        statusInput.value = newStatus;
        form.appendChild(statusInput);
        
        selectedPlots.forEach(plotId => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'plot_ids[]';
            input.value = plotId;
            form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleBulkSelection() {
    const selectAll = document.getElementById('selectAll');
    selectAll.checked = !selectAll.checked;
    toggleAllPlots(selectAll);
}
</script>

<?php require_once '../../includes/footer.php'; ?>