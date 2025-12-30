<?php
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

$page_title = "Expenses Management";
require_once '../../includes/header.php';

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_category = isset($_GET['category']) ? $_GET['category'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build query - using direct_expenses table
$query = "SELECT de.*, u.full_name as created_by_name,
                 ec.category_name
          FROM direct_expenses de
          LEFT JOIN users u ON de.created_by = u.user_id
          LEFT JOIN expense_categories ec ON de.category_id = ec.category_id
          WHERE de.company_id = ?";

$params = [$company_id];

if ($filter_status) {
    $query .= " AND de.status = ?";
    $params[] = $filter_status;
}

if ($filter_category) {
    $query .= " AND de.category_id = ?";
    $params[] = $filter_category;
}

if ($search) {
    $query .= " AND (de.description LIKE ? OR de.expense_number LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY de.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get expense categories
$cat_query = "SELECT * FROM expense_categories WHERE company_id = ? AND is_active = 1 ORDER BY category_name";
$cat_stmt = $conn->prepare($cat_query);
$cat_stmt->execute([$company_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(total_amount) as total_amount
                FROM direct_expenses 
                WHERE company_id = ?";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute([$company_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2><i class="fas fa-receipt me-2"></i>Expenses Management</h2>
                <a href="direct.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Record Expense
                </a>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Total Expenses</h6>
                    <h3><?php echo $stats['total'] ?? 0; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Pending</h6>
                    <h3 class="text-warning"><?php echo $stats['pending'] ?? 0; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Approved</h6>
                    <h3 class="text-success"><?php echo $stats['approved'] ?? 0; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Total Amount</h6>
                    <h3><?php echo number_format($stats['total_amount'] ?? 0, 2); ?> TSH</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['category_id']; ?>" <?php echo $filter_category == $cat['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="fas fa-search me-2"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Expenses Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Reference</th>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($expenses) > 0): ?>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($expense['reference_number'] ?? $expense['expense_id']); ?></td>
                                <td><?php echo htmlspecialchars($expense['category_name'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($expense['amount'], 2); ?> TSH</td>
                                <td>
                                    <span class="badge bg-<?php echo $expense['status'] == 'approved' ? 'success' : ($expense['status'] == 'rejected' ? 'danger' : 'warning'); ?>">
                                        <?php echo ucfirst($expense['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($expense['created_by_name'] ?? 'System'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($expense['created_at'])); ?></td>
                                <td>
                                    <a href="view.php?id=<?php echo $expense['expense_id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No expenses found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
