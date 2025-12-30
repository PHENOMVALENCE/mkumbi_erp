<?php
/**
 * Asset Register Report
 * Mkumbi Investments ERP System
 */

define('APP_ACCESS', true);
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$department_filter = $_GET['department'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$sql = "SELECT a.*, ac.category_name, ac.depreciation_method, ac.useful_life_years,
               (100 / NULLIF(ac.useful_life_years, 0)) as depreciation_rate,
               d.department_name,
               u.full_name as custodian_name
        FROM fixed_assets a
        LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
        LEFT JOIN departments d ON a.department_id = d.department_id
        LEFT JOIN employees e ON a.custodian_id = e.employee_id
        LEFT JOIN users u ON e.user_id = u.user_id
        WHERE a.company_id = ?";
$params = [$company_id];

if ($status_filter) {
    $sql .= " AND a.status = ?";
    $params[] = $status_filter;
}

if ($category_filter) {
    $sql .= " AND a.category_id = ?";
    $params[] = $category_filter;
}

if ($department_filter) {
    $sql .= " AND a.department_id = ?";
    $params[] = $department_filter;
}

if ($search) {
    $sql .= " AND (a.asset_name LIKE ? OR a.asset_number LIKE ? OR a.serial_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$sql .= " ORDER BY ac.category_name, a.asset_name";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'count' => count($assets),
    'purchase_cost' => 0,
    'current_value' => 0,
    'accumulated_depreciation' => 0
];
foreach ($assets as $asset) {
    $totals['purchase_cost'] += $asset['purchase_cost'] ?? 0;
    $totals['current_value'] += $asset['current_book_value'] ?? 0;
    $totals['accumulated_depreciation'] += $asset['accumulated_depreciation'] ?? 0;
}

// Get categories for filter
$cat_stmt = $conn->prepare("SELECT category_id, category_name FROM asset_categories WHERE company_id = ? ORDER BY category_name");
$cat_stmt->execute([$company_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filter
$dept_stmt = $conn->prepare("SELECT department_id, department_name FROM departments WHERE company_id = ? ORDER BY department_name");
$dept_stmt->execute([$company_id]);
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Asset Register";
require_once '../../includes/header.php';
?>

<style>
    .report-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }
    .filter-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 20px;
        margin-bottom: 20px;
    }
    .stats-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        border-left: 4px solid #667eea;
    }
    .report-table {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .badge-status {
        padding: 6px 12px;
        border-radius: 20px;
        font-weight: 500;
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-list-alt me-2"></i>Asset Register</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Reports</a></li>
                        <li class="breadcrumb-item active">Asset Register</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <!-- Summary Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <h6 class="text-muted mb-2">Total Assets</h6>
                        <h3 class="mb-0"><?php echo number_format($totals['count']); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="border-left-color: #28a745;">
                        <h6 class="text-muted mb-2">Purchase Cost</h6>
                        <h3 class="mb-0"><?php echo formatCurrency($totals['purchase_cost']); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="border-left-color: #ffc107;">
                        <h6 class="text-muted mb-2">Current Value</h6>
                        <h3 class="mb-0"><?php echo formatCurrency($totals['current_value']); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="border-left-color: #17a2b8;">
                        <h6 class="text-muted mb-2">Accumulated Depreciation</h6>
                        <h3 class="mb-0"><?php echo formatCurrency($totals['accumulated_depreciation']); ?></h3>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="disposed" <?php echo $status_filter === 'disposed' ? 'selected' : ''; ?>>Disposed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['category_id']; ?>" <?php echo $category_filter == $cat['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>" <?php echo $department_filter == $dept['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Asset name, number, or serial" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Filter</button>
                    </div>
                </form>
            </div>

            <!-- Report Table -->
            <div class="report-table">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Asset Number</th>
                                <th>Asset Name</th>
                                <th>Category</th>
                                <th>Department</th>
                                <th>Purchase Cost</th>
                                <th>Current Value</th>
                                <th>Depreciation</th>
                                <th>Status</th>
                                <th>Custodian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($assets)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">No assets found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($assets as $asset): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($asset['asset_number']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($asset['asset_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($asset['category_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($asset['department_name'] ?? 'N/A'); ?></td>
                                <td><?php echo formatCurrency($asset['purchase_cost']); ?></td>
                                <td><strong><?php echo formatCurrency($asset['current_book_value']); ?></strong></td>
                                <td>
                                    <?php echo formatCurrency($asset['accumulated_depreciation'] ?? 0); ?><br>
                                    <small class="text-muted"><?php echo number_format($asset['depreciation_rate'] ?? 0, 2); ?>% p.a.</small>
                                </td>
                                <td>
                                    <?php
                                    $status_class = [
                                        'active' => 'success',
                                        'inactive' => 'warning',
                                        'disposed' => 'danger'
                                    ];
                                    $status = strtolower($asset['status']);
                                    $class = $status_class[$status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $class; ?> badge-status"><?php echo ucfirst($status); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($asset['custodian_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4">Totals</th>
                                <th><?php echo formatCurrency($totals['purchase_cost']); ?></th>
                                <th><?php echo formatCurrency($totals['current_value']); ?></th>
                                <th><?php echo formatCurrency($totals['accumulated_depreciation']); ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Export Options -->
            <div class="mt-3 text-end">
                <button onclick="window.print()" class="btn btn-outline-primary"><i class="fas fa-print me-2"></i>Print</button>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-success"><i class="fas fa-file-csv me-2"></i>Export CSV</a>
            </div>
        </div>
    </section>
</div>

<?php require_once '../../includes/footer.php'; ?>
