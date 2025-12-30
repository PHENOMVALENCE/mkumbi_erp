<?php
/**
 * Leave Application Form
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
    $_SESSION['error_message'] = "You must be registered as an employee to apply for leave.";
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';

// Fetch leave types
$leave_types_sql = "SELECT * FROM leave_types WHERE company_id = ? AND is_active = 1 ORDER BY leave_type_name";
$stmt = $conn->prepare($leave_types_sql);
$stmt->execute([$company_id]);
$leave_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch leave balances
$balances = [];
foreach ($leave_types as $lt) {
    $balance_sql = "SELECT COALESCE(SUM(total_days), 0) as used 
                    FROM leave_applications 
                    WHERE employee_id = ? AND leave_type_id = ? AND status = 'approved' 
                    AND YEAR(start_date) = YEAR(CURDATE())";
    $stmt = $conn->prepare($balance_sql);
    $stmt->execute([$employee['employee_id'], $lt['leave_type_id']]);
    $used = $stmt->fetch(PDO::FETCH_ASSOC)['used'];
    $balances[$lt['leave_type_id']] = [
        'entitled' => $lt['days_per_year'],
        'used' => $used,
        'remaining' => $lt['days_per_year'] - $used
    ];
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_type_id = (int)$_POST['leave_type_id'];
    $start_date = sanitize($_POST['start_date']);
    $end_date = sanitize($_POST['end_date']);
    $reason = sanitize($_POST['reason']);
    
    // Validation
    if (empty($leave_type_id)) {
        $errors[] = "Please select a leave type.";
    }
    
    if (empty($start_date) || !isValidDate($start_date)) {
        $errors[] = "Please enter a valid start date.";
    }
    
    if (empty($end_date) || !isValidDate($end_date)) {
        $errors[] = "Please enter a valid end date.";
    }
    
    if (!empty($start_date) && !empty($end_date)) {
        if (strtotime($end_date) < strtotime($start_date)) {
            $errors[] = "End date cannot be before start date.";
        }
        
        if (strtotime($start_date) < strtotime('today')) {
            $errors[] = "Start date cannot be in the past.";
        }
        
        // Calculate total days
        $total_days = getBusinessDays($start_date, $end_date);
        
        // Check balance
        if (isset($balances[$leave_type_id])) {
            if ($total_days > $balances[$leave_type_id]['remaining']) {
                $errors[] = "Insufficient leave balance. You have {$balances[$leave_type_id]['remaining']} days remaining.";
            }
        }
        
        // Check for overlapping applications
        $overlap_sql = "SELECT COUNT(*) as count FROM leave_applications 
                        WHERE employee_id = ? AND status IN ('pending', 'approved')
                        AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?)
                        OR (? BETWEEN start_date AND end_date))";
        $stmt = $conn->prepare($overlap_sql);
        $stmt->execute([$employee['employee_id'], $start_date, $end_date, $start_date, $end_date, $start_date]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors[] = "You already have a leave application for this period.";
        }
    }
    
    if (empty($reason)) {
        $errors[] = "Please provide a reason for your leave.";
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            $insert_sql = "INSERT INTO leave_applications 
                (company_id, employee_id, leave_type_id, start_date, end_date, total_days, reason, application_date, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pending', ?)";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                $company_id,
                $employee['employee_id'],
                $leave_type_id,
                $start_date,
                $end_date,
                $total_days,
                $reason,
                $user_id
            ]);
            
            $leave_id = $conn->lastInsertId();
            
            // Log audit
            logAudit($conn, $company_id, $user_id, 'create', 'leave', 'leave_applications', $leave_id, null, [
                'leave_type_id' => $leave_type_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'total_days' => $total_days
            ]);
            
            $conn->commit();
            
            $_SESSION['success_message'] = "Leave application submitted successfully! Your request is pending approval.";
            header('Location: my-leaves.php');
            exit;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Leave application error: " . $e->getMessage());
            $errors[] = "An error occurred. Please try again.";
        }
    }
}

$page_title = "Apply for Leave";
require_once '../../includes/header.php';
?>

<style>
    .leave-form-card {
        background: white;
        border-radius: 15px;
        padding: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }
    .balance-info {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .balance-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
    }
    .balance-item-card {
        background: rgba(255,255,255,0.2);
        border-radius: 8px;
        padding: 15px;
        text-align: center;
    }
    .balance-number {
        font-size: 24px;
        font-weight: 700;
    }
    .date-range-preview {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-top: 15px;
        display: none;
    }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-paper-plane me-2"></i>Apply for Leave</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Leave</a></li>
                        <li class="breadcrumb-item active">Apply</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-8">
                    <div class="leave-form-card">
                        
                        <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="leaveForm">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label fw-semibold">Leave Type <span class="text-danger">*</span></label>
                                    <select name="leave_type_id" id="leave_type_id" class="form-select" required>
                                        <option value="">-- Select Leave Type --</option>
                                        <?php foreach ($leave_types as $lt): ?>
                                        <option value="<?php echo $lt['leave_type_id']; ?>"
                                                data-balance="<?php echo $balances[$lt['leave_type_id']]['remaining']; ?>"
                                                data-entitled="<?php echo $lt['days_per_year']; ?>"
                                                data-used="<?php echo $balances[$lt['leave_type_id']]['used']; ?>"
                                                <?php echo (isset($_POST['leave_type_id']) && $_POST['leave_type_id'] == $lt['leave_type_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($lt['leave_type_name']); ?> 
                                            (<?php echo $balances[$lt['leave_type_id']]['remaining']; ?> days available)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" name="start_date" id="start_date" class="form-control" 
                                           min="<?php echo date('Y-m-d'); ?>"
                                           value="<?php echo $_POST['start_date'] ?? ''; ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label>
                                    <input type="date" name="end_date" id="end_date" class="form-control"
                                           min="<?php echo date('Y-m-d'); ?>"
                                           value="<?php echo $_POST['end_date'] ?? ''; ?>" required>
                                </div>
                            </div>

                            <div class="date-range-preview" id="datePreview">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <small class="text-muted">Total Days</small>
                                        <h4 class="text-primary mb-0" id="totalDays">0</h4>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted">Available Balance</small>
                                        <h4 class="text-success mb-0" id="availableBalance">0</h4>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted">After Approval</small>
                                        <h4 id="afterApproval" class="mb-0">0</h4>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-12 mb-3">
                                    <label class="form-label fw-semibold">Reason for Leave <span class="text-danger">*</span></label>
                                    <textarea name="reason" class="form-control" rows="4" 
                                              placeholder="Please provide a detailed reason for your leave request..."
                                              required><?php echo $_POST['reason'] ?? ''; ?></textarea>
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Application
                                    </button>
                                    <a href="index.php" class="btn btn-outline-secondary btn-lg ms-2">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Leave Balance Summary -->
                    <div class="balance-info">
                        <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Your Leave Balance</h5>
                        <div class="balance-grid">
                            <?php foreach ($leave_types as $lt): ?>
                            <div class="balance-item-card">
                                <div class="balance-number"><?php echo $balances[$lt['leave_type_id']]['remaining']; ?></div>
                                <small><?php echo htmlspecialchars($lt['leave_type_name']); ?></small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Guidelines -->
                    <div class="leave-form-card">
                        <h6 class="fw-bold mb-3"><i class="fas fa-lightbulb me-2 text-warning"></i>Guidelines</h6>
                        <ul class="list-unstyled">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Apply at least 3 days in advance</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Ensure you have sufficient balance</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Provide a clear reason</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Weekend days are excluded</li>
                            <li class="mb-2"><i class="fas fa-info-circle text-info me-2"></i>Approval may take 1-2 business days</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    const leaveType = document.getElementById('leave_type_id');
    const datePreview = document.getElementById('datePreview');
    const totalDaysEl = document.getElementById('totalDays');
    const availableEl = document.getElementById('availableBalance');
    const afterEl = document.getElementById('afterApproval');
    
    function calculateDays() {
        if (startDate.value && endDate.value && leaveType.value) {
            const start = new Date(startDate.value);
            const end = new Date(endDate.value);
            let days = 0;
            let current = new Date(start);
            
            while (current <= end) {
                const dayOfWeek = current.getDay();
                if (dayOfWeek !== 0 && dayOfWeek !== 6) {
                    days++;
                }
                current.setDate(current.getDate() + 1);
            }
            
            const selected = leaveType.options[leaveType.selectedIndex];
            const balance = parseInt(selected.dataset.balance) || 0;
            const remaining = balance - days;
            
            totalDaysEl.textContent = days;
            availableEl.textContent = balance;
            afterEl.textContent = remaining;
            afterEl.className = remaining >= 0 ? 'mb-0 text-success' : 'mb-0 text-danger';
            
            datePreview.style.display = 'block';
        } else {
            datePreview.style.display = 'none';
        }
    }
    
    startDate.addEventListener('change', function() {
        endDate.min = this.value;
        calculateDays();
    });
    
    endDate.addEventListener('change', calculateDays);
    leaveType.addEventListener('change', calculateDays);
});
</script>

<?php require_once '../../includes/footer.php'; ?>
