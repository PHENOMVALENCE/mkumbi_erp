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
.stats-card { background:#fff; border-radius:12px; padding:1.5rem; box-shadow:0 4px 12px rgba(0,0,0,0.08); border-left:5px solid; transition:0.2s; }
.stats-card:hover { transform:translateY(-5px); }
.stats-card.primary { border-left-color:#007bff; }
.stats-card.success { border-left-color:#28a745; }
.stats-card.warning { border-left-color:#ffc107; }
.stats-card.info { border-left-color:#17a2b8; }
.stats-card.danger { border-left-color:#dc3545; }
.stats-card.purple { border-left-color:#6f42c1; }
.stats-number { font-size:2.4rem; font-weight:700; }
.stats-label { font-size:0.9rem; text-transform:uppercase; letter-spacing:1px; color:#6c757d; }

.status-badge { padding:0.4rem 0.9rem; border-radius:50px; font-size:0.8rem; font-weight:600; text-transform:capitalize; }
.status-badge.available { background:#d4edda; color:#155724; }
.status-badge.reserved { background:#fff3cd; color:#856404; }
.status-badge.sold { background:#d1ecf1; color:#0c5460; }
.status-badge.blocked { background:#f8d7da; color:#721c24; }

/* Ensure links work */
.btn-group a { pointer-events: auto !important; }
table tbody tr { cursor: default !important; }
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Plots Management</h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="create.php" class="btn btn-primary btn-lg">Add New Plot</a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-6"><div class="stats-card primary"><div class="stats-number"><?= number_format($stats['total_plots']) ?></div><div class="stats-label">Total Plots</div></div></div>
        <div class="col-xl-2 col-md-4 col-6"><div class="stats-card success"><div class="stats-number"><?= number_format($stats['available_plots']) ?></div><div class="stats-label">Available</div></div></div>
        <div class="col-xl-2 col-md-4 col-6"><div class="stats-card warning"><div class="stats-number"><?= number_format($stats['reserved_plots']) ?></div><div class="stats-label">Reserved</div></div></div>
        <div class="col-xl-2 col-md-4 col-6"><div class="stats-card info"><div class="stats-number"><?= number_format($stats['sold_plots']) ?></div><div class="stats-label">Sold</div></div></div>
        <div class="col-xl-2 col-md-4 col-6"><div class="stats-card purple"><div class="stats-number"><?= number_format($stats['total_area']) ?></div><div class="stats-label">Total m²</div></div></div>
        <div class="col-xl-2 col-md-4 col-6"><div class="stats-card danger"><div class="stats-number">TSH <?= number_format($stats['total_revenue']/1000000, 1) ?>M</div><div class="stats-label">Revenue</div></div></div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search plot, block, project..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <select name="project_id" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['project_id'] ?>" <?= ($_GET['project_id'] ?? '') == $p['project_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['project_name']) ?> (<?= $p['plot_count'] ?> plots)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="available" <?= ($_GET['status'] ?? '') === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="reserved" <?= ($_GET['status'] ?? '') === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                        <option value="sold" <?= ($_GET['status'] ?? '') === 'sold' ? 'selected' : '' ?>>Sold</option>
                        <option value="blocked" <?= ($_GET['status'] ?? '') === 'blocked' ? 'selected' : '' ?>>Blocked</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($plots)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No plots found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="plotsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Plot Number</th>
                                <th>Block</th>
                                <th>Project</th>
                                <th>Area (m²)</th>
                                <th>Price/m²</th>
                                <th>Total Price</th>
                                <th>Status</th>
                                <th>Customer</th>
                                <th style="width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plots as $p): 
                                $customer = trim(($p['first_name']??'') . ' ' . ($p['middle_name']??'') . ' ' . ($p['last_name']??''));
                                $customer = $customer ?: '-';
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($p['plot_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($p['block_number'] ?: '-') ?></td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($p['project_code'] ?: '') ?></small><br>
                                        <?= htmlspecialchars($p['project_name'] ?: 'Unknown') ?>
                                    </td>
                                    <td><?= number_format($p['area'], 2) ?></td>
                                    <td>TSH <?= number_format($p['price_per_sqm']) ?></td>
                                    <td>
                                        <?php if ($p['discount_amount'] > 0): ?>
                                            <small class="text-muted text-decoration-line-through">TSH <?= number_format($p['selling_price']) ?></small><br>
                                        <?php endif; ?>
                                        <strong>TSH <?= number_format($p['final_price']) ?></strong>
                                    </td>
                                    <td><span class="status-badge <?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></td>
                                    <td><?= htmlspecialchars($customer) ?></td>
                                    <td>
                                        <a href="view.php?id=<?= $p['plot_id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="edit.php?id=<?= $p['plot_id'] ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= $p['plot_id'] ?>, '<?= htmlspecialchars(addslashes($p['plot_number'])) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
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
                        responsive: true,
                        order: [[2, 'asc']],
                        columnDefs: [
                            { 
                                targets: 8, 
                                orderable: false,
                                className: 'dt-body-nowrap'
                            }
                        ],
                        // Prevent row click interfering with buttons
                        drawCallback: function() {
                            // Remove any row click handlers
                            $('#plotsTable tbody tr').off('click');
                        }
                    });
                });

                function confirmDelete(id, num) {
                    if (confirm('Delete plot ' + num + '? This action cannot be undone.')) {
                        window.location.href = 'delete.php?id=' + id;
                    }
                }
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>