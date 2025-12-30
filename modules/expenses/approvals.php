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

$page_title = "Expense Approvals";
require_once '../../includes/header.php';

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending_approval';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build query - using direct_expenses table
$query = "SELECT de.*, u.full_name as created_by_name, u2.full_name as approver_name,
                 ec.category_name
          FROM direct_expenses de
          LEFT JOIN users u ON de.created_by = u.user_id
          LEFT JOIN users u2 ON de.approved_by = u2.user_id
          LEFT JOIN expense_categories ec ON de.category_id = ec.category_id
          WHERE de.company_id = ? AND de.status = ?";

$params = [$company_id, $filter_status];

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

// Handle approval action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $expense_id = intval($_POST['expense_id']);
    $action = $_POST['action'];
    
    if ($action == 'approve') {
        $update_query = "UPDATE direct_expenses SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE expense_id = ? AND company_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->execute([$user_id, $expense_id, $company_id]);
        
        if (function_exists('logActivity')) {
            logActivity($conn, $company_id, $user_id, 'APPROVED', 'expenses', 'direct_expenses', $expense_id);
        }
        echo '<div class="alert alert-success">Expense approved successfully.</div>';
    } elseif ($action == 'reject') {
        $reason = $_POST['rejection_reason'] ?? '';
        $update_query = "UPDATE direct_expenses SET status = 'rejected', notes = ?, approved_by = ?, approved_at = NOW() WHERE expense_id = ? AND company_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->execute([$reason, $user_id, $expense_id, $company_id]);
        
        if (function_exists('logActivity')) {
            logActivity($conn, $company_id, $user_id, 'REJECTED', 'expenses', 'direct_expenses', $expense_id);
        }
        echo '<div class="alert alert-info">Expense rejected successfully.</div>';
    }
}

// Get status counts
$counts_query = "SELECT 
                SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM direct_expenses 
                WHERE company_id = ?";

$counts_stmt = $conn->prepare($counts_query);
$counts_stmt->execute([$company_id]);
$counts = $counts_stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><i class="fas fa-check-circle me-2"></i>Expense Approvals</h2>
        </div>
    </div>

    <!-- Status Tabs -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter_status == 'pending' ? 'active' : ''; ?>" href="?status=pending">
                        Pending <span class="badge bg-warning ms-2"><?php echo $counts['pending'] ?? 0; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter_status == 'approved' ? 'active' : ''; ?>" href="?status=approved">
                        Approved <span class="badge bg-success ms-2"><?php echo $counts['approved'] ?? 0; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $filter_status == 'rejected' ? 'active' : ''; ?>" href="?status=rejected">
                        Rejected <span class="badge bg-danger ms-2"><?php echo $counts['rejected'] ?? 0; ?></span>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Search -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Search by reference or description..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="fas fa-search me-2"></i>Search
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
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Requested By</th>
                        <th>Date</th>
                        <?php if ($filter_status == 'pending'): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($expenses) > 0): ?>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($expense['reference_number'] ?? $expense['expense_id']); ?></td>
                                <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                <td><?php echo number_format($expense['amount'], 2); ?> TSH</td>
                                <td><?php echo htmlspecialchars($expense['created_by_name'] ?? 'System'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($expense['created_at'])); ?></td>
                                <?php if ($filter_status == 'pending'): ?>
                                    <td>
                                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveModal" onclick="setExpenseId(<?php echo $expense['expense_id']; ?>)">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </button>
                                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal" onclick="setExpenseId(<?php echo $expense['expense_id']; ?>)">
                                            <i class="fas fa-times me-1"></i>Reject
                                        </button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $filter_status == 'pending' ? '6' : '5'; ?>" class="text-center py-4 text-muted">
                                No <?php echo htmlspecialchars($filter_status); ?> expenses found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Approve Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Are you sure you want to approve this expense?</p>
                    <input type="hidden" id="expense_id" name="expense_id">
                    <input type="hidden" name="action" value="approve">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <label class="form-label">Rejection Reason</label>
                    <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                    <input type="hidden" id="expense_id2" name="expense_id">
                    <input type="hidden" name="action" value="reject">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function setExpenseId(id) {
    document.getElementById('expense_id').value = id;
    document.getElementById('expense_id2').value = id;
}
</script>

<?php include '../../includes/footer.php'; ?>
