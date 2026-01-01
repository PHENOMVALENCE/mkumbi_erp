<?php
/**
 * View Leave Application Details
 * Mkumbi Investments ERP System - READ operation
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

// Get leave ID
$leave_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$leave_id) {
    $_SESSION['error_message'] = "Leave application not found.";
    header('Location: index.php');
    exit;
}

// Fetch leave application with all related data
$sql = "SELECT la.*, 
                lt.leave_type_name, lt.is_paid, lt.days_per_year,
                e.employee_id, e.employee_number,
                u.full_name as employee_name, u.email as employee_email, u.phone1,
                d.department_name, p.position_title,
                approver.full_name as approved_by_name, approver.email as approver_email
        FROM leave_applications la
        JOIN leave_types lt ON la.leave_type_id = lt.leave_type_id
        JOIN employees e ON la.employee_id = e.employee_id
        JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        LEFT JOIN users approver ON la.approved_by = approver.user_id
        WHERE la.leave_id = ? AND la.company_id = ?";

$stmt = $conn->prepare($sql);
$stmt->execute([$leave_id, $company_id]);
$leave = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leave) {
    $_SESSION['error_message'] = "Leave application not found.";
    header('Location: index.php');
    exit;
}

// Check permissions - user can view their own leaves, HR can view all
$employee_data = getEmployeeByUserId($conn, $user_id, $company_id);
$is_owner = ($employee_data && isset($employee_data['employee_id']) && $leave['employee_id'] == $employee_data['employee_id']);
$is_admin = isAdmin($conn, $user_id);
$is_management = isManagement($conn, $user_id);
$can_view = $is_owner || $is_admin || $is_management;

if (!$can_view) {
    $_SESSION['error_message'] = "You don't have permission to view this leave application.";
    header('Location: index.php');
    exit;
}

$page_title = "Leave Application Details";
require_once '../../includes/header.php';
?>

<style>
    .detail-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 30px;
        margin-bottom: 20px;
    }
    .detail-row {
        padding: 15px 0;
        border-bottom: 1px solid #eee;
    }
    .detail-row:last-child {
        border-bottom: none;
    }
    .detail-label {
        font-weight: 600;
        color: #6c757d;
        font-size: 0.875rem;
        text-transform: uppercase;
    }
    .detail-value {
        font-size: 1rem;
        color: #2c3e50;
        margin-top: 5px;
    }
    .status-timeline {
        position: relative;
        padding-left: 40px;
    }
    .status-timeline::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #6c757d;
    }
    .status-timeline.approved::before {
        background: #28a745;
    }
    .status-timeline.rejected::before {
        background: #dc3545;
    }
    .status-timeline.pending::before {
        background: #ffc107;
    }
    .reason-box {
        background: #f8f9fa;
        border-left: 4px solid #007bff;
        padding: 15px;
        border-radius: 8px;
        margin-top: 10px;
    }
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-alt text-primary me-2"></i>
                    Leave Application Details
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Reference: LA-<?php echo str_pad($leave['leave_id'], 6, '0', STR_PAD_LEFT); ?>
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <?php if ($is_owner && $leave['status'] === 'pending'): ?>
                    <a href="apply.php?id=<?php echo $leave['leave_id']; ?>" class="btn btn-warning me-2">
                        <i class="fas fa-edit me-1"></i> Edit
                    </a>
                    <a href="process.php?id=<?php echo $leave['leave_id']; ?>&action=cancel" 
                       onclick="return confirm('Are you sure you want to cancel this leave application?');"
                       class="btn btn-danger me-2">
                        <i class="fas fa-times me-1"></i> Cancel
                    </a>
                    <?php endif; ?>
                    <a href="my-leaves.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-8">
                <!-- Leave Details -->
                <div class="detail-card">
                    <h5 class="mb-4"><i class="fas fa-calendar-alt me-2"></i>Leave Details</h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Leave Type</div>
                                <div class="detail-value">
                                    <span class="badge bg-<?php echo $leave['is_paid'] ? 'info' : 'secondary'; ?>">
                                        <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                    </span>
                                    <?php if ($leave['is_paid']): ?>
                                    <small class="text-success"><i class="fas fa-check-circle me-1"></i>Paid Leave</small>
                                    <?php else: ?>
                                    <small class="text-warning"><i class="fas fa-info-circle me-1"></i>Unpaid Leave</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Total Days</div>
                                <div class="detail-value">
                                    <span class="badge bg-primary fs-6"><?php echo $leave['total_days']; ?> days</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Start Date</div>
                                <div class="detail-value">
                                    <i class="fas fa-calendar me-2 text-primary"></i>
                                    <?php echo date('l, F d, Y', strtotime($leave['start_date'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">End Date</div>
                                <div class="detail-value">
                                    <i class="fas fa-calendar me-2 text-primary"></i>
                                    <?php echo date('l, F d, Y', strtotime($leave['end_date'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Reason for Leave</div>
                        <div class="detail-value">
                            <div class="reason-box">
                                <?php echo nl2br(htmlspecialchars($leave['reason'])); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Employee Information -->
                <div class="detail-card">
                    <h5 class="mb-4"><i class="fas fa-user me-2"></i>Employee Information</h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Employee Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($leave['employee_name']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Employee Number</div>
                                <div class="detail-value"><?php echo htmlspecialchars($leave['employee_number']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Department</div>
                                <div class="detail-value"><?php echo htmlspecialchars($leave['department_name'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Position</div>
                                <div class="detail-value"><?php echo htmlspecialchars($leave['position_title'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Email</div>
                                <div class="detail-value">
                                    <a href="mailto:<?php echo htmlspecialchars($leave['employee_email']); ?>">
                                        <?php echo htmlspecialchars($leave['employee_email']); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Phone</div>
                                <div class="detail-value"><?php echo htmlspecialchars($leave['phone1'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status & Timeline -->
                <div class="detail-card">
                    <h5 class="mb-4"><i class="fas fa-history me-2"></i>Status & Timeline</h5>
                    
                    <div class="detail-row">
                        <div class="detail-label">Application Status</div>
                        <div class="detail-value mt-2">
                            <div class="status-timeline <?php echo $leave['status']; ?>">
                                <?php echo getStatusBadge($leave['status']); ?>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Applied On</div>
                                <div class="detail-value">
                                    <?php echo date('l, F d, Y \a\t h:i A', strtotime($leave['application_date'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Last Updated</div>
                                <div class="detail-value">
                                    <?php echo date('l, F d, Y \a\t h:i A', strtotime($leave['updated_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($leave['status'] !== 'pending'): ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Processed By</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($leave['approved_by_name'] ?? 'System'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-row">
                                <div class="detail-label">Processed On</div>
                                <div class="detail-value">
                                    <?php echo $leave['approved_at'] ? date('l, F d, Y \a\t h:i A', strtotime($leave['approved_at'])) : 'N/A'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($leave['status'] === 'rejected' && $leave['rejection_reason']): ?>
                    <div class="detail-row">
                        <div class="detail-label">Rejection Reason</div>
                        <div class="detail-value">
                            <div class="reason-box bg-danger-light">
                                <i class="fas fa-exclamation-circle text-danger me-2"></i>
                                <?php echo nl2br(htmlspecialchars($leave['rejection_reason'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Quick Actions -->
                <div class="detail-card">
                    <h6 class="fw-bold mb-3"><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</h6>
                    <div class="d-grid gap-2">
                        <?php if ($is_owner && $leave['status'] === 'pending'): ?>
                        <a href="apply.php?id=<?php echo $leave['leave_id']; ?>" class="btn btn-warning">
                            <i class="fas fa-edit me-2"></i>Edit Request
                        </a>
                        <a href="process.php?id=<?php echo $leave['leave_id']; ?>&action=cancel" 
                           onclick="return confirm('Are you sure?');"
                           class="btn btn-danger">
                            <i class="fas fa-times me-2"></i>Cancel Request
                        </a>
                        <?php endif; ?>
                        <a href="my-leaves.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                </div>

                <!-- Status Info -->
                <div class="detail-card">
                    <h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2 text-info"></i>Status Info</h6>
                    <?php 
                    $status_info = [
                        'pending' => 'Your request is awaiting approval from HR/Management. You can still edit or cancel it.',
                        'approved' => 'Your leave request has been approved. You are authorized to take leave during the specified period.',
                        'rejected' => 'Your leave request has been rejected. Review the rejection reason and apply again if needed.',
                        'cancelled' => 'You have cancelled this leave request. You can apply for new dates if needed.'
                    ];
                    ?>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-check me-2"></i>
                        <?php echo isset($status_info[$leave['status']]) ? $status_info[$leave['status']] : 'No status information available.'; ?>
                    </p>
                </div>

                <!-- Print & Export -->
                <div class="detail-card">
                    <h6 class="fw-bold mb-3"><i class="fas fa-download me-2"></i>Export</h6>
                    <div class="d-grid gap-2">
                        <button onclick="window.print()" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-print me-2"></i>Print
                        </button>
                        <a href="#" class="btn btn-outline-success btn-sm" title="PDF export coming soon" disabled>
                            <i class="fas fa-file-pdf me-2"></i>Download PDF
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
