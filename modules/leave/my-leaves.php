<?php
/**
 * My Leave History
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

$employee = getEmployeeByUserId($conn, $user_id, $company_id);

if (!$employee) {
    $_SESSION['error_message'] = "Employee record not found. Please contact HR to set up your employee profile.";
    header('Location: ../../index.php');
    exit;
}

// Fetch filter parameters
$filter_year = $_GET['year'] ?? date('Y');
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? '';

// Build query
$where = ["la.company_id = ?", "la.employee_id = ?"];
$params = [$company_id, $employee['employee_id']];

if ($filter_year) {
    $where[] = "YEAR(la.start_date) = ?";
    $params[] = $filter_year;
}

if ($filter_status) {
    $where[] = "la.status = ?";
    $params[] = $filter_status;
}

if ($filter_type) {
    $where[] = "la.leave_type_id = ?";
    $params[] = $filter_type;
}

$where_clause = implode(' AND ', $where);

// Fetch leave history
$sql = "SELECT la.*, lt.leave_type_name, lt.is_paid, u.full_name as approved_by_name
        FROM leave_applications la
        JOIN leave_types lt ON la.leave_type_id = lt.leave_type_id
        LEFT JOIN users u ON la.approved_by = u.user_id
        WHERE $where_clause
        ORDER BY la.application_date DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch leave types for filter
$leave_types = $conn->prepare("SELECT leave_type_id, leave_type_name FROM leave_types WHERE company_id = ? AND is_active = 1");
$leave_types->execute([$company_id]);
$leave_types = $leave_types->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary
$summary = [
    'total_days' => 0,
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0
];

foreach ($leaves as $leave) {
    if ($leave['status'] === 'approved') {
        $summary['total_days'] += $leave['total_days'];
        $summary['approved']++;
    } elseif ($leave['status'] === 'pending') {
        $summary['pending']++;
    } elseif ($leave['status'] === 'rejected') {
        $summary['rejected']++;
    }
}

$page_title = "My Leave History";
require_once '../../includes/header.php';
?>

<style>
    .history-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .summary-bar {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 25px;
        color: white;
    }
    .summary-item {
        text-align: center;
    }
    .summary-number {
        font-size: 2rem;
        font-weight: 700;
    }
    .leave-item {
        padding: 20px;
        border-bottom: 1px solid #eee;
        transition: background 0.2s;
    }
    .leave-item:hover {
        background: #f8f9fa;
    }
    .leave-item:last-child {
        border-bottom: none;
    }
    .leave-dates {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 10px 15px;
        display: inline-block;
    }
    .timeline-indicator {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 10px;
    }
    .timeline-indicator.pending { background: #ffc107; }
    .timeline-indicator.approved { background: #28a745; }
    .timeline-indicator.rejected { background: #dc3545; }
    .timeline-indicator.cancelled { background: #6c757d; }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-history me-2"></i>My Leave History</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Leave</a></li>
                        <li class="breadcrumb-item active">My History</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-12">
                    <a href="apply.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i>Apply for Leave
                    </a>
                </div>
            </div>

            <div class="history-card">
                <!-- Summary Bar -->
                <div class="summary-bar">
                    <div class="row">
                        <div class="col-md-3 summary-item">
                            <div class="summary-number"><?php echo $summary['total_days']; ?></div>
                            <div>Days Taken (<?php echo $filter_year; ?>)</div>
                        </div>
                        <div class="col-md-3 summary-item">
                            <div class="summary-number"><?php echo $summary['approved']; ?></div>
                            <div>Approved</div>
                        </div>
                        <div class="col-md-3 summary-item">
                            <div class="summary-number"><?php echo $summary['pending']; ?></div>
                            <div>Pending</div>
                        </div>
                        <div class="col-md-3 summary-item">
                            <div class="summary-number"><?php echo $summary['rejected']; ?></div>
                            <div>Rejected</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="p-3 bg-light border-bottom">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small">Year</label>
                            <select name="year" class="form-select">
                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Leave Type</label>
                            <select name="type" class="form-select">
                                <option value="">All Types</option>
                                <?php foreach ($leave_types as $lt): ?>
                                <option value="<?php echo $lt['leave_type_id']; ?>"
                                        <?php echo $filter_type == $lt['leave_type_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($lt['leave_type_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Leave List -->
                <?php if (empty($leaves)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No leave records found for the selected criteria.</p>
                </div>
                <?php else: ?>
                    <?php foreach ($leaves as $leave): ?>
                    <div class="leave-item">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <div class="d-flex align-items-start">
                                    <span class="timeline-indicator <?php echo $leave['status']; ?>"></span>
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($leave['leave_type_name']); ?></h6>
                                        <small class="text-muted">
                                            Applied: <?php echo date('M d, Y', strtotime($leave['application_date'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="leave-dates">
                                    <i class="fas fa-calendar me-2 text-primary"></i>
                                    <?php echo date('M d', strtotime($leave['start_date'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($leave['end_date'])); ?>
                                </div>
                            </div>
                            <div class="col-md-2 text-center">
                                <span class="badge bg-primary fs-6"><?php echo $leave['total_days']; ?> days</span>
                            </div>
                            <div class="col-md-2 text-center">
                                <?php echo getStatusBadge($leave['status']); ?>
                                <?php if ($leave['status'] === 'approved' && $leave['approved_by_name']): ?>
                                <br><small class="text-muted">by <?php echo htmlspecialchars($leave['approved_by_name']); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-1 text-end">
                                <a href="view.php?id=<?php echo $leave['leave_id']; ?>" 
                                   class="btn btn-sm btn-outline-info" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($leave['status'] === 'pending'): ?>
                                <a href="process.php?id=<?php echo $leave['leave_id']; ?>&action=cancel" 
                                   class="btn btn-sm btn-outline-danger" title="Cancel"
                                   onclick="return confirm('Are you sure you want to cancel this leave application?');">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($leave['status'] === 'rejected' && $leave['rejection_reason']): ?>
                        <div class="mt-2 ms-4">
                            <small class="text-danger">
                                <i class="fas fa-info-circle me-1"></i>
                                Rejection Reason: <?php echo htmlspecialchars($leave['rejection_reason']); ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </section>
</div>

<?php require_once '../../includes/footer.php'; ?>
