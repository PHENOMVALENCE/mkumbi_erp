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
            COUNT(*) as total_assets,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_assets,
            SUM(CASE WHEN status = 'under_maintenance' THEN 1 ELSE 0 END) as maintenance_assets,
            SUM(CASE WHEN status = 'disposed' THEN 1 ELSE 0 END) as disposed_assets,
            COALESCE(SUM(CASE WHEN status IN ('active', 'under_maintenance', 'inactive') THEN total_cost ELSE 0 END), 0) as total_cost,
            COALESCE(SUM(CASE WHEN status IN ('active', 'under_maintenance', 'inactive') THEN current_book_value ELSE 0 END), 0) as current_book_value,
            COALESCE(SUM(CASE WHEN status IN ('active', 'under_maintenance', 'inactive') THEN accumulated_depreciation ELSE 0 END), 0) as total_depreciation
        FROM fixed_assets 
        WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure all values are numeric (not null)
    $stats = [
        'total_assets' => (int)($result['total_assets'] ?? 0),
        'active_assets' => (int)($result['active_assets'] ?? 0),
        'maintenance_assets' => (int)($result['maintenance_assets'] ?? 0),
        'disposed_assets' => (int)($result['disposed_assets'] ?? 0),
        'total_cost' => (float)($result['total_cost'] ?? 0),
        'current_book_value' => (float)($result['current_book_value'] ?? 0),
        'total_depreciation' => (float)($result['total_depreciation'] ?? 0)
    ];
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
    $stats = [
        'total_assets' => 0,
        'active_assets' => 0,
        'maintenance_assets' => 0,
        'disposed_assets' => 0,
        'total_cost' => 0,
        'current_book_value' => 0,
        'total_depreciation' => 0
    ];
}

// ==================== CATEGORIES FOR FILTER ====================
$categories = [];
try {
    $stmt = $conn->prepare("
        SELECT c.category_id, c.category_name, c.depreciation_method,
        (SELECT COUNT(*) FROM fixed_assets WHERE category_id = c.category_id AND company_id = ?) as asset_count
        FROM asset_categories c
        WHERE c.company_id = ? 
        ORDER BY c.category_name
    ");
    $stmt->execute([$company_id, $company_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Categories error: " . $e->getMessage());
    $categories = [];
}

// ==================== BUILD FILTERS ====================
$where = ['a.company_id = ?'];
$params = [$company_id];

if (!empty($_GET['category_id'])) {
    $where[] = 'a.category_id = ?';
    $params[] = (int)$_GET['category_id'];
}

if (!empty($_GET['status']) && in_array($_GET['status'], ['active', 'inactive', 'under_maintenance', 'disposed', 'stolen', 'damaged'])) {
    $where[] = 'a.status = ?';
    $params[] = $_GET['status'];
}

if (!empty($_GET['department_id'])) {
    $where[] = 'a.department_id = ?';
    $params[] = (int)$_GET['department_id'];
}

if (!empty($_GET['search'])) {
    $s = '%' . trim($_GET['search']) . '%';
    $where[] = '(a.asset_number LIKE ? OR a.asset_name LIKE ? OR a.serial_number LIKE ? OR a.model_number LIKE ?)';
    $params[] = $s; 
    $params[] = $s; 
    $params[] = $s;
    $params[] = $s;
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// ==================== FETCH DEPARTMENTS ====================
$departments = [];
try {
    $stmt = $conn->prepare("
        SELECT department_id, department_name, department_code
        FROM departments
        WHERE company_id = ? AND is_active = 1
        ORDER BY department_name
    ");
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Departments error: " . $e->getMessage());
}

// ==================== FETCH ASSETS ====================
$assets = [];
try {
    $query = "
        SELECT 
            a.asset_id,
            a.asset_number,
            a.asset_name,
            a.serial_number,
            a.model_number,
            a.purchase_date,
            a.purchase_cost,
            a.total_cost,
            a.current_book_value,
            a.accumulated_depreciation,
            a.status,
            a.location,
            c.category_name,
            d.department_name,
            u.full_name as custodian_name,
            DATEDIFF(CURDATE(), a.purchase_date) as age_days
        FROM fixed_assets a
        LEFT JOIN asset_categories c ON a.category_id = c.category_id
        LEFT JOIN departments d ON a.department_id = d.department_id
        LEFT JOIN users u ON a.custodian_id = u.user_id
        $where_clause
        ORDER BY a.purchase_date DESC, a.asset_number
        LIMIT 1000
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Assets query failed: " . $e->getMessage());
    $assets = [];
}

$page_title = 'Fixed Assets Management';
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

.stats-sublabel {
    font-size: 0.7rem;
    color: #adb5bd;
    margin-top: 0.25rem;
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

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.inactive {
    background: #e2e3e5;
    color: #383d41;
}

.status-badge.under_maintenance {
    background: #fff3cd;
    color: #856404;
}

.status-badge.disposed {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.stolen,
.status-badge.damaged {
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

/* Table Cell Specific */
.asset-number {
    font-weight: 700;
    color: #2c3e50;
    font-size: 0.85rem;
}

.asset-name {
    color: #495057;
    font-size: 0.85rem;
    font-weight: 500;
}

.asset-info {
    line-height: 1.4;
}

.serial-number {
    display: block;
    font-size: 0.7rem;
    color: #6c757d;
    font-family: 'Courier New', monospace;
}

.value-cell {
    font-weight: 600;
    color: #2c3e50;
    white-space: nowrap;
}

.depreciation-info {
    line-height: 1.3;
}

.book-value {
    font-weight: 600;
    color: #28a745;
    font-size: 0.85rem;
}

.depreciation-amount {
    font-size: 0.75rem;
    color: #dc3545;
}

.location-info {
    color: #6c757d;
    font-size: 0.8rem;
}

.age-badge {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    background: #e9ecef;
    color: #495057;
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

/* Progress Bar */
.depreciation-progress {
    height: 4px;
    background: #e9ecef;
    border-radius: 2px;
    overflow: hidden;
    margin-top: 0.25rem;
}

.depreciation-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #28a745, #ffc107, #dc3545);
    transition: width 0.3s ease;
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
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-laptop me-2"></i>Fixed Assets Management
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="add.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i>Register New Asset
                </a>
                <a href="depreciation.php" class="btn btn-info btn-sm">
                    <i class="fas fa-chart-line me-1"></i>Depreciation
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
                <div class="stats-number"><?= number_format($stats['total_assets']) ?></div>
                <div class="stats-label">Total Assets</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($stats['active_assets']) ?></div>
                <div class="stats-label">Active Assets</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($stats['maintenance_assets']) ?></div>
                <div class="stats-label">Maintenance</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card info">
                <div class="stats-number"><?= number_format($stats['disposed_assets']) ?></div>
                <div class="stats-label">Disposed</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card purple">
                <div class="stats-number"><?= number_format($stats['total_cost']/1000000, 1) ?>M</div>
                <div class="stats-label">Total Cost</div>
                <div class="stats-sublabel">TSH</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($stats['current_book_value']/1000000, 1) ?>M</div>
                <div class="stats-label">Book Value</div>
                <div class="stats-sublabel">TSH</div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card filter-card mb-3">
        <div class="card-body">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">SEARCH</label>
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Asset number, name, serial..." 
                           value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">CATEGORY</label>
                    <select name="category_id" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>" 
                                    <?= ($_GET['category_id'] ?? '') == $cat['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category_name']) ?> (<?= $cat['asset_count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">DEPARTMENT</label>
                    <select name="department_id" class="form-select form-select-sm">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" 
                                    <?= ($_GET['department_id'] ?? '') == $dept['department_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">STATUS</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="active" <?= ($_GET['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($_GET['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="under_maintenance" <?= ($_GET['status'] ?? '') === 'under_maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="disposed" <?= ($_GET['status'] ?? '') === 'disposed' ? 'selected' : '' ?>>Disposed</option>
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
            <?php if (empty($assets)): ?>
                <div class="empty-state">
                    <i class="fas fa-laptop"></i>
                    <p>No assets found matching your criteria</p>
                    <a href="add.php" class="btn btn-primary btn-sm mt-2">
                        <i class="fas fa-plus me-1"></i>Register First Asset
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="assetsTable">
                        <thead>
                            <tr>
                                <th>Asset No.</th>
                                <th>Asset Name</th>
                                <th>Category</th>
                                <th>Purchase Date</th>
                                <th class="text-end">Purchase Cost</th>
                                <th class="text-end">Book Value</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assets as $asset): 
                                $depreciation_percent = $asset['total_cost'] > 0 
                                    ? ($asset['accumulated_depreciation'] / $asset['total_cost']) * 100 
                                    : 0;
                                $age_years = floor($asset['age_days'] / 365);
                                $age_months = floor(($asset['age_days'] % 365) / 30);
                            ?>
                                <tr>
                                    <td>
                                        <div class="asset-number"><?= htmlspecialchars($asset['asset_number']) ?></div>
                                        <?php if ($asset['serial_number']): ?>
                                            <span class="serial-number">SN: <?= htmlspecialchars($asset['serial_number']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="asset-info">
                                            <div class="asset-name"><?= htmlspecialchars($asset['asset_name']) ?></div>
                                            <?php if ($asset['model_number']): ?>
                                                <small class="text-muted"><?= htmlspecialchars($asset['model_number']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($asset['category_name'] ?: '-') ?></td>
                                    <td>
                                        <?= date('d M Y', strtotime($asset['purchase_date'])) ?>
                                        <div>
                                            <span class="age-badge">
                                                <?= $age_years ?>y <?= $age_months ?>m
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-end value-cell">
                                        <?= number_format($asset['purchase_cost'], 0) ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="depreciation-info">
                                            <div class="book-value"><?= number_format($asset['current_book_value'], 0) ?></div>
                                            <div class="depreciation-amount">
                                                -<?= number_format($asset['accumulated_depreciation'], 0) ?>
                                            </div>
                                            <div class="depreciation-progress">
                                                <div class="depreciation-progress-bar" 
                                                     style="width: <?= min($depreciation_percent, 100) ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $asset['status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $asset['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="location-info">
                                            <?php if ($asset['location']): ?>
                                                <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($asset['location']) ?>
                                            <?php endif; ?>
                                            <?php if ($asset['department_name']): ?>
                                                <div class="small"><?= htmlspecialchars($asset['department_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($asset['custodian_name']): ?>
                                                <div class="small text-muted">
                                                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($asset['custodian_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-actions">
                                            <a href="view.php?id=<?= $asset['asset_id'] ?>" 
                                               class="btn btn-sm btn-outline-primary action-btn" 
                                               title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?= $asset['asset_id'] ?>" 
                                               class="btn btn-sm btn-outline-warning action-btn"
                                               title="Edit Asset">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="maintenance.php?id=<?= $asset['asset_id'] ?>" 
                                               class="btn btn-sm btn-outline-info action-btn"
                                               title="Maintenance">
                                                <i class="fas fa-wrench"></i>
                                            </a>
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
                    $('#assetsTable').DataTable({
                        pageLength: 25,
                        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                        responsive: true,
                        order: [[3, 'desc']],
                        columnDefs: [
                            { 
                                targets: [4, 5],
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
                            info: "Showing _START_ to _END_ of _TOTAL_ assets",
                            infoEmpty: "Showing 0 to 0 of 0 assets",
                            infoFiltered: "(filtered from _MAX_ total assets)",
                            paginate: {
                                first: "First",
                                last: "Last",
                                next: "Next",
                                previous: "Previous"
                            }
                        }
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>