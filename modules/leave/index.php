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
$user_id = $_SESSION['user_id'];

// Get employee info for current user
$stmt = $conn->prepare("SELECT * FROM employees WHERE user_id = ? AND company_id = ? AND employment_status = 'active'");
$stmt->execute([$user_id, $company_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if user is HR/Admin
$is_hr = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'hr', 'super_admin']);

// Get leave statistics
$stats = ['pending' => 0, 'approved' => 0, 'my_balance' => 0, 'on_leave_today' => 0];

try {
    if ($is_hr) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM leave_applications WHERE company_id = ? AND status = 'pending'");
        $stmt->execute([$company_id]);
        $stats['pending'] = $stmt->fetch()['count'];
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM leave_applications WHERE company_id = ? AND status = 'approved' AND MONTH(approved_at) = MONTH(CURDATE())");
    $stmt->execute([$company_id]);
    $stats['approved'] = $stmt->fetch()['count'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM leave_applications WHERE company_id = ? AND status = 'approved' AND CURDATE() BETWEEN start_date AND end_date");
    $stmt->execute([$company_id]);
    $stats['on_leave_today'] = $stmt->fetch()['count'];
    
    if ($employee) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(balance), 0) as balance FROM leave_balances WHERE employee_id = ? AND year = YEAR(CURDATE())");
        $stmt->execute([$employee['employee_id']]);
        $stats['my_balance'] = $stmt->fetch()['balance'];
    }
} catch (Exception $e) {}

// Get my recent leave applications
$my_leaves = [];
if ($employee) {
    try {
        $stmt = $conn->prepare("SELECT la.*, lt.leave_type_name FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.leave_type_id
            WHERE la.employee_id = ? ORDER BY la.created_at DESC LIMIT 5");
        $stmt->execute([$employee['employee_id']]);
        $my_leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Get pending approvals (for HR)
$pending_approvals = [];
if ($is_hr) {
    try {
        $stmt = $conn->prepare("SELECT la.*, lt.leave_type_name, u.full_name as employee_name, d.department_name
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.leave_type_id
            JOIN employees e ON la.employee_id = e.employee_id
            JOIN users u ON e.user_id = u.user_id
            LEFT JOIN departments d ON e.department_id = d.department_id
            WHERE la.company_id = ? AND la.status = 'pending'
            ORDER BY la.created_at ASC LIMIT 10");
        $stmt->execute([$company_id]);
        $pending_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Get leave types
$leave_types = [];
try {
    $stmt = $conn->prepare("SELECT * FROM leave_types WHERE company_id = ? AND is_active = 1 ORDER BY leave_type_name");
    $stmt->execute([$company_id]);
    $leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$page_title = 'Leave Management';
require_once '../../includes/header.php';
?>

<style>
.stats-card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,0.08);border-left:4px solid;transition:transform .2s}
.stats-card:hover{transform:translateY(-4px)}
.stats-card.primary{border-left-color:#007bff}.stats-card.success{border-left-color:#28a745}
.stats-card.warning{border-left-color:#ffc107}.stats-card.info{border-left-color:#17a2b8}
.stats-number{font-size:2rem;font-weight:700;color:#2c3e50}
.stats-label{color:#6c757d;font-size:.875rem;font-weight:500}
.action-card{background:white;border-radius:12px;padding:2rem;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,0.08);transition:all .3s;text-decoration:none;color:inherit;display:block}
.action-card:hover{transform:translateY(-5px);box-shadow:0 8px 25px rgba(0,0,0,0.15);color:inherit}
.action-card i{font-size:2.5rem;color:#007bff;margin-bottom:1rem}
.table-card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,0.08)}
.status-badge{padding:.35rem .75rem;border-radius:20px;font-size:.8rem;font-weight:600}
.status-badge.pending{background:#fff3cd;color:#856404}
.status-badge.approved{background:#d4edda;color:#155724}
.status-badge.rejected{background:#f8d7da;color:#721c24}
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6"><h1 class="m-0 fw-bold"><i class="fas fa-calendar-alt me-2"></i>Leave Management</h1></div>
            <div class="col-sm-6 text-end">
                <a href="apply.php" class="btn btn-primary"><i class="fas fa-plus-circle me-1"></i> Apply for Leave</a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <?php if ($is_hr): ?>
        <div class="col-lg-3 col-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= $stats['pending'] ?></div>
                <div class="stats-label">Pending Requests</div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-lg-3 col-6">
            <div class="stats-card success">
                <div class="stats-number"><?= $stats['approved'] ?></div>
                <div class="stats-label">Approved This Month</div>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="stats-card info">
                <div class="stats-number"><?= $stats['on_leave_today'] ?></div>
                <div class="stats-label">On Leave Today</div>
            </div>
        </div>
        <?php if ($employee): ?>
        <div class="col-lg-3 col-6">
            <div class="stats-card primary">
                <div class="stats-number"><?= $stats['my_balance'] ?></div>
                <div class="stats-label">My Leave Balance</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mb-4">
        <div class="col-md-3"><a href="apply.php" class="action-card"><i class="fas fa-paper-plane"></i><h5>Apply for Leave</h5><p class="text-muted small mb-0">Submit new request</p></a></div>
        <div class="col-md-3"><a href="my-leaves.php" class="action-card"><i class="fas fa-history"></i><h5>My Leaves</h5><p class="text-muted small mb-0">View leave history</p></a></div>
        <?php if ($is_hr): ?>
        <div class="col-md-3"><a href="approvals.php" class="action-card"><i class="fas fa-clipboard-check"></i><h5>Approvals</h5><p class="text-muted small mb-0">Review requests</p></a></div>
        <div class="col-md-3"><a href="leave-types.php" class="action-card"><i class="fas fa-cog"></i><h5>Leave Types</h5><p class="text-muted small mb-0">Configure types</p></a></div>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-lg-<?= $is_hr ? '6' : '12' ?>">
            <div class="table-card">
                <h5 class="mb-3"><i class="fas fa-clock me-2"></i>My Recent Applications</h5>
                <?php if (empty($my_leaves)): ?>
                <p class="text-muted text-center py-4">No leave applications found.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light"><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($my_leaves as $leave): ?>
                            <tr>
                                <td><?= htmlspecialchars($leave['leave_type_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($leave['start_date'])) ?></td>
                                <td><?= date('M d, Y', strtotime($leave['end_date'])) ?></td>
                                <td><?= $leave['total_days'] ?></td>
                                <td><?php echo getStatusBadge($leave['status']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_hr && !empty($pending_approvals)): ?>
        <div class="col-lg-6">
            <div class="table-card">
                <h5 class="mb-3"><i class="fas fa-hourglass-half me-2"></i>Pending Approvals</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light"><tr><th>Employee</th><th>Type</th><th>Days</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($pending_approvals as $app): ?>
                            <tr>
                                <td><?= htmlspecialchars($app['employee_name']) ?><br><small class="text-muted"><?= htmlspecialchars($app['department_name'] ?? '') ?></small></td>
                                <td><?= htmlspecialchars($app['leave_type_name']) ?></td>
                                <td><?= $app['total_days'] ?></td>
                                <td><a href="approvals.php?id=<?= $app['leave_id'] ?>" class="btn btn-sm btn-primary">Review</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($leave_types)): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="table-card">
                <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Available Leave Types</h5>
                <div class="row">
                    <?php foreach ($leave_types as $lt): ?>
                    <div class="col-md-4 col-lg-2 mb-3">
                        <div class="border rounded p-3 text-center">
                            <h4 class="text-primary mb-1"><?= $lt['days_per_year'] ?></h4>
                            <small class="text-muted"><?= htmlspecialchars($lt['leave_type_name']) ?></small><br>
                            <span class="badge <?= $lt['is_paid'] ? 'bg-success' : 'bg-secondary' ?>"><?= $lt['is_paid'] ? 'Paid' : 'Unpaid' ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
