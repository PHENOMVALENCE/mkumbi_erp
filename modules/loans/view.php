<?php
/**
 * Loan Details View
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

$loan_id = (int)($_GET['id'] ?? 0);
if (!$loan_id) {
    $_SESSION['error_message'] = "Invalid loan ID.";
    header('Location: index.php');
    exit;
}

// Get loan details with employee info
$sql = "SELECT el.*, COALESCE(lt.type_name, lt.loan_type_name) as loan_type_name, lt.interest_rate as type_rate, lt.requires_guarantor,
               u.full_name, u.email as emp_email, e.employee_number, e.basic_salary,
               d.department_name, p.position_title,
               (SELECT u2.full_name FROM users u2 WHERE u2.user_id = el.approved_by) as approver_name,
               (SELECT u3.full_name FROM users u3 WHERE u3.user_id = el.disbursed_by) as disburser_name
        FROM employee_loans el
        JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
        JOIN employees e ON el.employee_id = e.employee_id
        JOIN users u ON e.user_id = u.user_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        WHERE el.loan_id = ? AND el.company_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$loan_id, $company_id]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    $_SESSION['error_message'] = "Loan not found.";
    header('Location: index.php');
    exit;
}

// Check access - employee can view own loans, HR/Finance can view all
$employee = getEmployeeByUserId($conn, $user_id, $company_id);
$is_hr = hasPermission($conn, $user_id, ['HR_OFFICER', 'FINANCE_OFFICER', 'COMPANY_ADMIN', 'SUPER_ADMIN']);
$is_owner = $employee && $loan['employee_id'] == $employee['employee_id'];

if (!$is_hr && !$is_owner) {
    $_SESSION['error_message'] = "You don't have permission to view this loan.";
    header('Location: index.php');
    exit;
}

// Get repayment schedule
$sql = "SELECT * FROM loan_repayment_schedule WHERE loan_id = ? ORDER BY installment_number";
$stmt = $conn->prepare($sql);
$stmt->execute([$loan_id]);
$schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment history
$sql = "SELECT lp.*, 
               (SELECT u.full_name FROM users u WHERE u.user_id = lp.created_by) as recorder_name
        FROM loan_payments lp 
        WHERE lp.loan_id = ? 
        ORDER BY lp.payment_date DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$loan_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get guarantors - Note: loan_guarantors table may not exist, check if needed
$guarantors = [];
// Commented out as table may not exist in schema
// $sql = "SELECT lg.*, u.full_name, e.employee_number
//         FROM loan_guarantors lg
//         JOIN employees e ON lg.guarantor_employee_id = e.employee_id
//         JOIN users u ON e.user_id = u.user_id
//         WHERE lg.loan_id = ?";
// $stmt = $conn->prepare($sql);
// $stmt->execute([$loan_id]);
// $guarantors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary
$total_paid = array_sum(array_column($payments, 'total_paid'));
$total_repayable = $loan['loan_amount'] + $loan['interest_outstanding'];
$paid_percent = $total_repayable > 0 ? ($total_paid / $total_repayable) * 100 : 0;

$page_title = "Loan Details - " . $loan['loan_number'];
require_once '../../includes/header.php';
?>

<style>
    .detail-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 25px;
        margin-bottom: 20px;
    }
    .detail-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px;
        padding: 30px;
        margin-bottom: 20px;
    }
    .loan-ref { font-size: 0.9rem; opacity: 0.9; }
    .loan-amount-big { font-size: 2.5rem; font-weight: 700; }
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    .info-item { padding: 10px 0; border-bottom: 1px solid #eee; }
    .info-item:last-child { border-bottom: none; }
    .info-label { color: #6c757d; font-size: 0.85rem; }
    .info-value { font-weight: 600; }
    
    .progress-bar-thick { height: 15px; border-radius: 8px; }
    
    .timeline { position: relative; padding-left: 30px; }
    .timeline::before {
        content: '';
        position: absolute;
        left: 10px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #e9ecef;
    }
    .timeline-item {
        position: relative;
        padding-bottom: 20px;
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -24px;
        top: 5px;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #667eea;
    }
    .timeline-item.completed::before { background: #28a745; }
    .timeline-item.pending::before { background: #ffc107; }
    
    .schedule-table th { background: #f8f9fa; }
    .schedule-table .paid { background: #d4edda; }
    .schedule-table .overdue { background: #f8d7da; }
</style>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1><i class="fas fa-file-invoice-dollar me-2"></i>Loan Details</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Loans</a></li>
                        <li class="breadcrumb-item active">Details</li>
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

            <!-- Loan Header -->
            <div class="detail-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <span class="loan-ref">Reference: <?php echo htmlspecialchars($loan['loan_number']); ?></span>
                        <div class="loan-amount-big"><?php echo formatCurrency($loan['loan_amount']); ?></div>
                        <span><?php echo htmlspecialchars($loan['loan_type_name']); ?></span>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <?php echo getStatusBadge($loan['status']); ?>
                        <div class="mt-2">
                            <small>Applied: <?php echo date('M d, Y', strtotime($loan['created_at'])); ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Left Column -->
                <div class="col-lg-8">
                    
                    <!-- Loan Summary -->
                    <div class="detail-card">
                        <h5 class="mb-4"><i class="fas fa-chart-pie me-2"></i>Loan Summary</h5>
                        
                        <div class="row mb-4">
                            <div class="col-md-3 text-center">
                                <h4 class="text-primary mb-0"><?php echo formatCurrency($loan['loan_amount']); ?></h4>
                                <small class="text-muted">Principal</small>
                            </div>
                            <div class="col-md-3 text-center">
                                <h4 class="text-warning mb-0"><?php echo formatCurrency($loan['interest_outstanding']); ?></h4>
                                <small class="text-muted">Interest</small>
                            </div>
                            <div class="col-md-3 text-center">
                                <h4 class="text-success mb-0"><?php echo formatCurrency($total_paid); ?></h4>
                                <small class="text-muted">Paid</small>
                            </div>
                            <div class="col-md-3 text-center">
                                <h4 class="text-danger mb-0"><?php echo formatCurrency($loan['total_outstanding']); ?></h4>
                                <small class="text-muted">Outstanding</small>
                            </div>
                        </div>
                        
                        <div class="mb-2 d-flex justify-content-between">
                            <span>Repayment Progress</span>
                            <strong><?php echo round($paid_percent); ?>%</strong>
                        </div>
                        <div class="progress progress-bar-thick">
                            <div class="progress-bar bg-success" style="width: <?php echo $paid_percent; ?>%"></div>
                        </div>
                    </div>

                    <!-- Repayment Schedule -->
                    <div class="detail-card">
                        <h5 class="mb-4"><i class="fas fa-calendar-alt me-2"></i>Repayment Schedule</h5>
                        
                        <div class="table-responsive">
                            <table class="table schedule-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Due Date</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Total</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($schedule as $s): 
                                        $is_overdue = strtolower($s['payment_status'] ?? 'pending') === 'pending' && strtotime($s['due_date']) < time();
                                        $row_class = strtolower($s['payment_status'] ?? 'pending') === 'paid' ? 'paid' : ($is_overdue ? 'overdue' : '');
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td><?php echo $s['installment_number']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($s['due_date'])); ?></td>
                                        <td><?php echo formatCurrency($s['principal_amount']); ?></td>
                                        <td><?php echo formatCurrency($s['interest_amount']); ?></td>
                                        <td><strong><?php echo formatCurrency($s['total_amount']); ?></strong></td>
                                        <td><?php echo formatCurrency($s['balance_outstanding'] ?? 0); ?></td>
                                        <td>
                                            <?php if (strtolower($s['payment_status'] ?? 'pending') === 'paid'): ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i> Paid</span>
                                            <?php elseif ($is_overdue): ?>
                                            <span class="badge bg-danger"><i class="fas fa-exclamation"></i> Overdue</span>
                                            <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <?php if (!empty($payments)): ?>
                    <div class="detail-card">
                        <h5 class="mb-4"><i class="fas fa-money-bill-wave me-2"></i>Payment History</h5>
                        
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($p['payment_reference']); ?></code></td>
                                        <td><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                                        <td><strong><?php echo formatCurrency($p['total_paid'] ?? $p['amount_paid'] ?? 0); ?></strong></td>
                                        <td><?php echo htmlspecialchars(str_replace('_', ' ', $p['payment_method'] ?? 'N/A')); ?></td>
                                        <td><?php echo htmlspecialchars($p['recorder_name'] ?? 'System'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- Right Column -->
                <div class="col-lg-4">
                    
                    <!-- Loan Details -->
                    <div class="detail-card">
                        <h5 class="mb-4"><i class="fas fa-info-circle me-2"></i>Loan Details</h5>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Interest Rate</div>
                                <div class="info-value"><?php echo $loan['interest_rate']; ?>% p.a.</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Term</div>
                                <div class="info-value"><?php echo $loan['repayment_period_months']; ?> months</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Monthly Payment</div>
                                <div class="info-value"><?php echo formatCurrency($loan['monthly_deduction']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Total Repayable</div>
                                <div class="info-value"><?php echo formatCurrency($loan['loan_amount'] + $loan['interest_outstanding']); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Employee Info -->
                    <div class="detail-card">
                        <h5 class="mb-4"><i class="fas fa-user me-2"></i>Employee Information</h5>
                        <div class="text-center mb-3">
                            <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" 
                                 style="width: 60px; height: 60px; font-size: 1.5rem;">
                                <?php echo strtoupper(substr($loan['full_name'] ?? 'U', 0, 1)); ?>
                            </div>
                            <h6 class="mt-2 mb-0"><?php echo htmlspecialchars($loan['full_name'] ?? 'N/A'); ?></h6>
                            <small class="text-muted"><?php echo htmlspecialchars($loan['employee_number'] ?? 'N/A'); ?></small>
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Department</div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['department_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Position</div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['position_title'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Basic Salary</div>
                                <div class="info-value"><?php echo formatCurrency($loan['basic_salary']); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Guarantors -->
                    <?php if (!empty($guarantors)): ?>
                    <div class="detail-card">
                        <h5 class="mb-4"><i class="fas fa-users me-2"></i>Guarantors</h5>
                        <?php foreach ($guarantors as $g): ?>
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-3" 
                                 style="width: 40px; height: 40px;">
                                <?php echo strtoupper(substr($g['first_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <strong><?php echo htmlspecialchars($g['first_name'] . ' ' . $g['last_name']); ?></strong>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($g['employee_number']); ?></small>
                            </div>
                            <span class="ms-auto badge bg-<?php echo $g['status'] === 'APPROVED' ? 'success' : 'warning'; ?>">
                                <?php echo $g['status']; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Status Timeline -->
                    <div class="detail-card">
                        <h5 class="mb-4"><i class="fas fa-history me-2"></i>Status Timeline</h5>
                        <div class="timeline">
                            <div class="timeline-item completed">
                                <strong>Applied</strong>
                                <small class="d-block text-muted"><?php echo date('M d, Y H:i', strtotime($loan['created_at'])); ?></small>
                            </div>
                            <?php if ($loan['approved_at']): ?>
                            <div class="timeline-item completed">
                                <strong><?php echo strtolower($loan['status']) === 'rejected' ? 'Rejected' : 'Approved'; ?></strong>
                                <small class="d-block text-muted">
                                    <?php echo date('M d, Y H:i', strtotime($loan['approved_at'])); ?>
                                    <?php if ($loan['approver_name']): ?> by <?php echo htmlspecialchars($loan['approver_name']); ?><?php endif; ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            <?php if ($loan['disbursement_date']): ?>
                            <div class="timeline-item completed">
                                <strong>Disbursed</strong>
                                <small class="d-block text-muted">
                                    <?php echo date('M d, Y H:i', strtotime($loan['disbursement_date'])); ?>
                                    <?php if ($loan['disburser_name']): ?> by <?php echo htmlspecialchars($loan['disburser_name']); ?><?php endif; ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            <?php if (strtolower($loan['status']) === 'completed'): ?>
                            <div class="timeline-item completed">
                                <strong>Completed</strong>
                                <small class="d-block text-muted">Fully repaid</small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Actions -->
                    <?php if ($is_hr): ?>
                    <div class="detail-card">
                        <h5 class="mb-4"><i class="fas fa-cog me-2"></i>Actions</h5>
                        <?php if (in_array(strtolower($loan['status']), ['disbursed', 'active'])): ?>
                        <button type="button" class="btn btn-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                            <i class="fas fa-plus me-2"></i>Record Payment
                        </button>
                        <?php endif; ?>
                        <a href="approvals.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </section>
</div>

<!-- Record Payment Modal -->
<?php if ($is_hr && in_array(strtolower($loan['status']), ['disbursed', 'active'])): ?>
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="process.php">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
                <input type="hidden" name="redirect" value="view.php?id=<?php echo $loan_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-money-bill me-2"></i>Record Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Outstanding:</strong> <?php echo formatCurrency($loan['total_outstanding']); ?><br>
                        <strong>Monthly Installment:</strong> <?php echo formatCurrency($loan['monthly_deduction']); ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                        <input type="number" name="payment_amount" class="form-control" 
                               value="<?php echo $loan['monthly_deduction']; ?>" 
                               max="<?php echo $loan['total_outstanding']; ?>" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="SALARY_DEDUCTION">Salary Deduction</option>
                            <option value="BANK_TRANSFER">Bank Transfer</option>
                            <option value="CASH">Cash</option>
                            <option value="CHEQUE">Cheque</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reference Number</label>
                        <input type="text" name="payment_reference" class="form-control" placeholder="Transaction/Cheque number">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
