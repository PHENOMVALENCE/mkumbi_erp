<?php
/**
 * Depreciation Report
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
$period = $_GET['period'] ?? date('Y-m');
$category_filter = $_GET['category'] ?? '';

$year = substr($period, 0, 4);
$month = substr($period, 5, 2);

// Get depreciation history for the period
$sql = "SELECT ad.*, a.asset_name, a.asset_number, a.purchase_cost, a.current_book_value,
               ac.category_name, ac.depreciation_method, ac.useful_life_years
        FROM asset_depreciation ad
        JOIN fixed_assets a ON ad.asset_id = a.asset_id
        LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
        WHERE a.company_id = ? AND YEAR(ad.period_date) = ? AND MONTH(ad.period_date) = ?";
$params = [$company_id, $year, $month];

if ($category_filter) {
    $sql .= " AND a.category_id = ?";
    $params[] = $category_filter;
}

$sql .= " ORDER BY ac.category_name, a.asset_name";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$depreciations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get assets that should be depreciated but haven't been
$sql_pending = "SELECT a.*, ac.category_name, ac.depreciation_method, ac.useful_life_years,
                       (100 / NULLIF(ac.useful_life_years, 0)) as depreciation_rate,
                       (SELECT MAX(period_date) FROM asset_depreciation WHERE asset_id = a.asset_id) as last_depreciation
                FROM fixed_assets a
                LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
                WHERE a.company_id = ? AND a.status = 'active' AND a.current_book_value > 0";
$params_pending = [$company_id];

if ($category_filter) {
    $sql_pending .= " AND a.category_id = ?";
    $params_pending[] = $category_filter;
}

$sql_pending .= " ORDER BY ac.category_name, a.asset_name";

$stmt_pending = $conn->prepare($sql_pending);
$stmt_pending->execute($params_pending);
$pending_assets = $stmt_pending->fetchAll(PDO::FETCH_ASSOC);

// Filter pending assets that haven't been depreciated for this period
$pending_for_period = [];
foreach ($pending_assets as $asset) {
    $last_dep = $asset['last_depreciation'] ? date('Y-m', strtotime($asset['last_depreciation'])) : null;
    if ($last_dep !== $period) {
        // Calculate expected depreciation
        $depreciable_cost = $asset['purchase_cost'] - ($asset['salvage_value'] ?? 0);
        $monthly_dep = $asset['useful_life_years'] > 0 ? ($depreciable_cost / ($asset['useful_life_years'] * 12)) : 0;
        $asset['expected_depreciation'] = $monthly_dep;
        $pending_for_period[] = $asset;
    }
}

// Calculate totals
$totals = [
    'count' => count($depreciations),
    'total_depreciation' => 0
];
foreach ($depreciations as $dep) {
    $totals['total_depreciation'] += $dep['depreciation_amount'] ?? 0;
}

// Get categories for filter
$cat_stmt = $conn->prepare("SELECT category_id, category_name FROM asset_categories WHERE company_id = ? ORDER BY category_name");
$cat_stmt->execute([$company_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Depreciation Report";
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
        margin-bottom: 20px;
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-chart-line me-2"></i>Depreciation Report</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Reports</a></li>
                        <li class="breadcrumb-item active">Depreciation Report</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <!-- Summary Stats -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card">
                        <h6 class="text-muted mb-2">Depreciations This Period</h6>
                        <h3 class="mb-0"><?php echo number_format($totals['count']); ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="border-left-color: #28a745;">
                        <h6 class="text-muted mb-2">Total Depreciation</h6>
                        <h3 class="mb-0"><?php echo formatCurrency($totals['total_depreciation']); ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="border-left-color: #ffc107;">
                        <h6 class="text-muted mb-2">Pending Assets</h6>
                        <h3 class="mb-0"><?php echo number_format(count($pending_for_period)); ?></h3>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Period</label>
                        <input type="month" name="period" class="form-control" value="<?php echo htmlspecialchars($period); ?>">
                    </div>
                    <div class="col-md-3">
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
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Filter</button>
                    </div>
                </form>
            </div>

            <!-- Depreciation History -->
            <div class="report-table">
                <h5 class="p-3 mb-0 border-bottom">Depreciation for <?php echo date('F Y', strtotime($period . '-01')); ?></h5>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Asset Number</th>
                                <th>Asset Name</th>
                                <th>Category</th>
                                <th>Purchase Cost</th>
                                <th>Book Value Before</th>
                                <th>Depreciation Amount</th>
                                <th>Book Value After</th>
                                <th>Method</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($depreciations)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">No depreciation recorded for this period</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($depreciations as $dep): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($dep['asset_number']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($dep['asset_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($dep['category_name'] ?? 'N/A'); ?></td>
                                <td><?php echo formatCurrency($dep['purchase_cost']); ?></td>
                                <td><?php echo formatCurrency($dep['book_value_before']); ?></td>
                                <td><strong class="text-danger"><?php echo formatCurrency($dep['depreciation_amount']); ?></strong></td>
                                <td><?php echo formatCurrency($dep['book_value_after']); ?></td>
                                <td>
                                    <small><?php echo ucfirst(str_replace('_', ' ', $dep['depreciation_method'] ?? 'N/A')); ?></small><br>
                                    <small class="text-muted"><?php echo $dep['useful_life_years']; ?> years</small>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($dep['period_date'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5">Totals</th>
                                <th><?php echo formatCurrency($totals['total_depreciation']); ?></th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Pending Depreciations -->
            <?php if (!empty($pending_for_period)): ?>
            <div class="report-table">
                <h5 class="p-3 mb-0 border-bottom">Assets Pending Depreciation</h5>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Asset Number</th>
                                <th>Asset Name</th>
                                <th>Category</th>
                                <th>Current Book Value</th>
                                <th>Expected Monthly Depreciation</th>
                                <th>Last Depreciation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_for_period as $asset): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($asset['asset_number']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($asset['asset_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($asset['category_name'] ?? 'N/A'); ?></td>
                                <td><?php echo formatCurrency($asset['current_book_value']); ?></td>
                                <td><strong><?php echo formatCurrency($asset['expected_depreciation']); ?></strong></td>
                                <td><?php echo $asset['last_depreciation'] ? date('M d, Y', strtotime($asset['last_depreciation'])) : 'Never'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Export Options -->
            <div class="mt-3 text-end">
                <button onclick="window.print()" class="btn btn-outline-primary"><i class="fas fa-print me-2"></i>Print</button>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-success"><i class="fas fa-file-csv me-2"></i>Export CSV</a>
            </div>
        </div>
    </section>
</div>

<?php require_once '../../includes/footer.php'; ?>
