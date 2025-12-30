<?php
/**
 * Asset Register / List
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
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$department_filter = $_GET['department'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$sql = "SELECT a.*, ac.category_name, ac.depreciation_method, ac.useful_life_years, 
               (100 / NULLIF(ac.useful_life_years, 0)) as depreciation_rate,
               d.department_name,
               u.full_name as assigned_to_name
        FROM fixed_assets a
        LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
        LEFT JOIN departments d ON a.department_id = d.department_id
        LEFT JOIN employees e ON a.custodian_id = e.employee_id
        LEFT JOIN users u ON e.user_id = u.user_id
        WHERE a.company_id = ?";
$params = [$company_id];

if ($category_filter) {
    $sql .= " AND a.category_id = ?";
    $params[] = $category_filter;
}
if ($status_filter) {
    $sql .= " AND a.status = ?";
    $params[] = $status_filter;
}
if ($department_filter) {
    $sql .= " AND a.department_id = ?";
    $params[] = $department_filter;
}
if ($search) {
    $sql .= " AND (a.asset_name LIKE ? OR a.asset_number LIKE ? OR a.serial_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY a.asset_name";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$cat_stmt = $conn->prepare("SELECT category_id, category_name FROM asset_categories WHERE company_id = ? ORDER BY category_name");
$cat_stmt->execute([$company_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

$dept_stmt = $conn->prepare("SELECT department_id, department_name FROM departments WHERE company_id = ? ORDER BY department_name");
$dept_stmt->execute([$company_id]);
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'count' => count($assets),
    'purchase_cost' => array_sum(array_column($assets, 'purchase_cost')),
    'current_value' => array_sum(array_column($assets, 'current_book_value'))
];

$page_title = "Asset Register";
require_once '../../includes/header.php';
?>

<style>
    .filter-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 20px;
        margin-bottom: 20px;
    }
    .asset-table {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .asset-table th { background: #f8f9fa; font-weight: 600; }
    .asset-table tbody tr:hover { background: #f8f9fe; }
    .summary-bar {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 15px 25px;
        margin-bottom: 20px;
    }
    .depreciation-bar {
        height: 6px;
        border-radius: 3px;
        background: #e9ecef;
        margin-top: 5px;
    }
    .depreciation-bar .fill {
        height: 100%;
        border-radius: 3px;
        background: linear-gradient(90deg, #28a745, #ffc107, #dc3545);
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-list me-2"></i>Asset Register</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Assets</a></li>
                        <li class="breadcrumb-item active">Register</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Summary Bar -->
            <div class="summary-bar">
                <div class="row align-items-center">
                    <div class="col-md-3">
                        <h4 class="mb-0"><?php echo $totals['count']; ?></h4>
                        <small>Total Assets</small>
                    </div>
                    <div class="col-md-3">
                        <h4 class="mb-0"><?php echo formatCurrency($totals['purchase_cost']); ?></h4>
                        <small>Purchase Cost</small>
                    </div>
                    <div class="col-md-3">
                        <h4 class="mb-0"><?php echo formatCurrency($totals['current_value']); ?></h4>
                        <small>Current Value</small>
                    </div>
                    <div class="col-md-3 text-end">
                        <a href="add.php" class="btn btn-light">
                            <i class="fas fa-plus me-2"></i>Add Asset
                        </a>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" 
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Name, code, or serial...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $c): ?>
                            <option value="<?php echo $c['category_id']; ?>" <?php echo $category_filter == $c['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['category_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="ACTIVE" <?php echo $status_filter === 'ACTIVE' ? 'selected' : ''; ?>>Active</option>
                            <option value="MAINTENANCE" <?php echo $status_filter === 'MAINTENANCE' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="DISPOSED" <?php echo $status_filter === 'DISPOSED' ? 'selected' : ''; ?>>Disposed</option>
                            <option value="LOST" <?php echo $status_filter === 'LOST' ? 'selected' : ''; ?>>Lost</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['department_id']; ?>" <?php echo $department_filter == $d['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Filter</button>
                        <a href="list.php" class="btn btn-outline-secondary">Reset</a>
                        <button type="button" class="btn btn-outline-success" onclick="exportAssets()">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Assets Table -->
            <div class="asset-table">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Category</th>
                                <th>Purchase Date</th>
                                <th>Purchase Cost</th>
                                <th>Current Value</th>
                                <th>Depreciation</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($assets)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No assets found.</p>
                                    <a href="add.php" class="btn btn-primary">
                                        <i class="fas fa-plus me-2"></i>Add First Asset
                                    </a>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($assets as $a): 
                                $dep_percent = $a['purchase_cost'] > 0 ? 
                                    (($a['purchase_cost'] - $a['current_book_value']) / $a['purchase_cost']) * 100 : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($a['asset_name']); ?></strong>
                                    <br><code><?php echo htmlspecialchars($a['asset_number']); ?></code>
                                    <?php if ($a['serial_number']): ?>
                                    <br><small class="text-muted">S/N: <?php echo htmlspecialchars($a['serial_number']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($a['category_name'] ?? 'N/A'); ?></td>
                                <td><?php echo date('M d, Y', strtotime($a['purchase_date'])); ?></td>
                                <td><?php echo formatCurrency($a['purchase_cost']); ?></td>
                                <td>
                                    <strong><?php echo formatCurrency($a['current_book_value']); ?></strong>
                                </td>
                                <td>
                                    <small><?php echo round($dep_percent); ?>%</small>
                                    <div class="depreciation-bar">
                                        <div class="fill" style="width: <?php echo min($dep_percent, 100); ?>%"></div>
                                    </div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($a['department_name'] ?? $a['location'] ?? 'N/A'); ?>
                                    <?php if ($a['assigned_to_name']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($a['assigned_to_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo getStatusBadge($a['status']); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="view.php?id=<?php echo $a['asset_id']; ?>" class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="add.php?id=<?php echo $a['asset_id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-warning maintenance-btn" 
                                                data-id="<?php echo $a['asset_id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($a['asset_name']); ?>" title="Maintenance">
                                            <i class="fas fa-wrench"></i>
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
    </section>
</div>

<!-- Schedule Maintenance Modal -->
<div class="modal fade" id="maintenanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="process.php">
                <input type="hidden" name="action" value="schedule_maintenance">
                <input type="hidden" name="asset_id" id="maintenanceAssetId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-wrench me-2"></i>Schedule Maintenance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Asset: <strong id="maintenanceAssetName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Maintenance Type <span class="text-danger">*</span></label>
                        <select name="maintenance_type" class="form-select" required>
                            <option value="PREVENTIVE">Preventive</option>
                            <option value="CORRECTIVE">Corrective</option>
                            <option value="INSPECTION">Inspection</option>
                            <option value="UPGRADE">Upgrade</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Scheduled Date <span class="text-danger">*</span></label>
                        <input type="date" name="scheduled_date" class="form-control" required
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Estimated Cost</label>
                        <input type="number" name="estimated_cost" class="form-control" min="0" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-calendar-plus me-2"></i>Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Maintenance button
    document.querySelectorAll('.maintenance-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('maintenanceAssetId').value = this.dataset.id;
            document.getElementById('maintenanceAssetName').textContent = this.dataset.name;
            new bootstrap.Modal(document.getElementById('maintenanceModal')).show();
        });
    });
});

function exportAssets() {
    window.location.href = 'export.php?' + new URLSearchParams(window.location.search).toString();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
