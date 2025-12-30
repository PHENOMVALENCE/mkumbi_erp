<?php
define('APP_ACCESS', true);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

$errors = [];
$success = [];

// Fetch approved/rejected payments
try {
    $payments_sql = "SELECT 
                        p.payment_id,
                        p.payment_number,
                        p.payment_date,
                        p.amount,
                        p.payment_method,
                        p.payment_type,
                        p.status,
                        p.approved_at,
                        p.rejected_at,
                        p.rejection_reason,
                        p.remarks,
                        r.reservation_number,
                        c.full_name as customer_name,
                        c.phone as customer_phone,
                        pl.plot_number,
                        pr.project_name,
                        u_approved.full_name as approved_by_name,
                        u_rejected.full_name as rejected_by_name,
                        u_submitted.full_name as submitted_by_name
                     FROM payments p
                     LEFT JOIN reservations r ON p.reservation_id = r.reservation_id
                     LEFT JOIN customers c ON r.customer_id = c.customer_id
                     LEFT JOIN plots pl ON r.plot_id = pl.plot_id
                     LEFT JOIN projects pr ON pl.project_id = pr.project_id
                     LEFT JOIN users u_approved ON p.approved_by = u_approved.user_id
                     LEFT JOIN users u_rejected ON p.rejected_by = u_rejected.user_id
                     LEFT JOIN users u_submitted ON p.submitted_by = u_submitted.user_id
                     WHERE p.status IN ('approved', 'rejected') 
                     AND p.company_id = ?
                     ORDER BY COALESCE(p.approved_at, p.rejected_at) DESC
                     LIMIT 100";
    
    $stmt = $conn->prepare($payments_sql);
    $stmt->execute([$company_id]);
    $payment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $payment_stats = [
        'total' => count($payment_history),
        'approved' => count(array_filter($payment_history, fn($p) => $p['status'] === 'approved')),
        'rejected' => count(array_filter($payment_history, fn($p) => $p['status'] === 'rejected'))
    ];
    
} catch (PDOException $e) {
    $payment_history = [];
    $payment_stats = ['total' => 0, 'approved' => 0, 'rejected' => 0];
    $errors[] = "Error fetching payment history: " . $e->getMessage();
}

// Fetch commission history
try {
    $commissions_sql = "SELECT 
                            co.commission_id,
                            co.commission_type,
                            co.commission_percentage,
                            co.commission_amount,
                            co.recipient_name,
                            co.recipient_phone,
                            co.payment_status,
                            co.approved_at,
                            co.rejected_at,
                            co.rejection_reason,
                            co.remarks,
                            r.reservation_number,
                            c.full_name as customer_name,
                            pl.plot_number,
                            pr.project_name,
                            u_approved.full_name as approved_by_name,
                            u_rejected.full_name as rejected_by_name
                         FROM commissions co
                         LEFT JOIN reservations r ON co.reservation_id = r.reservation_id
                         LEFT JOIN customers c ON r.customer_id = c.customer_id
                         LEFT JOIN plots pl ON r.plot_id = pl.plot_id
                         LEFT JOIN projects pr ON pl.project_id = pr.project_id
                         LEFT JOIN users u_approved ON co.approved_by = u_approved.user_id
                         LEFT JOIN users u_rejected ON co.rejected_by = u_rejected.user_id
                         WHERE co.payment_status IN ('paid', 'cancelled') 
                         AND co.company_id = ?
                         ORDER BY COALESCE(co.approved_at, co.rejected_at) DESC
                         LIMIT 100";
    
    $stmt = $conn->prepare($commissions_sql);
    $stmt->execute([$company_id]);
    $commission_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $commission_stats = [
        'total' => count($commission_history),
        'approved' => count(array_filter($commission_history, fn($c) => $c['payment_status'] === 'paid')),
        'rejected' => count(array_filter($commission_history, fn($c) => $c['payment_status'] === 'cancelled'))
    ];
    
} catch (PDOException $e) {
    $commission_history = [];
    $commission_stats = ['total' => 0, 'approved' => 0, 'rejected' => 0];
    $errors[] = "Error fetching commission history: " . $e->getMessage();
}

// Fetch refund history
try {
    $refunds_sql = "SELECT 
                        rf.refund_id,
                        rf.refund_number,
                        rf.refund_date,
                        rf.refund_amount,
                        rf.penalty_amount,
                        rf.refund_method,
                        rf.refund_reason,
                        rf.detailed_reason,
                        rf.status,
                        rf.approved_at,
                        rf.rejection_reason,
                        p.payment_number,
                        c.full_name as customer_name,
                        c.phone as customer_phone,
                        pl.plot_number,
                        pr.project_name,
                        u_approved.full_name as approved_by_name
                     FROM refunds rf
                     LEFT JOIN payments p ON rf.original_payment_id = p.payment_id
                     LEFT JOIN customers c ON rf.customer_id = c.customer_id
                     LEFT JOIN plots pl ON rf.plot_id = pl.plot_id
                     LEFT JOIN projects pr ON pl.project_id = pr.project_id
                     LEFT JOIN users u_approved ON rf.approved_by = u_approved.user_id
                     WHERE rf.status IN ('approved', 'rejected', 'processed') 
                     AND rf.company_id = ?
                     ORDER BY rf.approved_at DESC
                     LIMIT 100";
    
    $stmt = $conn->prepare($refunds_sql);
    $stmt->execute([$company_id]);
    $refund_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $refund_stats = [
        'total' => count($refund_history),
        'approved' => count(array_filter($refund_history, fn($r) => in_array($r['status'], ['approved', 'processed']))),
        'rejected' => count(array_filter($refund_history, fn($r) => $r['status'] === 'rejected'))
    ];
    
} catch (PDOException $e) {
    $refund_history = [];
    $refund_stats = ['total' => 0, 'approved' => 0, 'rejected' => 0];
    $errors[] = "Error fetching refund history: " . $e->getMessage();
}

// Fetch payroll history
try {
    $payroll_sql = "SELECT 
                        pr.payroll_id,
                        pr.payroll_month,
                        pr.payroll_year,
                        pr.payment_date,
                        pr.status,
                        pr.processed_at,
                        COUNT(pd.payroll_detail_id) as employee_count,
                        SUM(pd.gross_salary) as total_gross,
                        SUM(pd.total_deductions) as total_deductions,
                        SUM(pd.net_salary) as total_net,
                        u_processed.full_name as processed_by_name,
                        u_created.full_name as created_by_name
                     FROM payroll pr
                     LEFT JOIN payroll_details pd ON pr.payroll_id = pd.payroll_id
                     LEFT JOIN users u_processed ON pr.processed_by = u_processed.user_id
                     LEFT JOIN users u_created ON pr.created_by = u_created.user_id
                     WHERE pr.status IN ('processed', 'paid', 'cancelled') 
                     AND pr.company_id = ?
                     GROUP BY pr.payroll_id
                     ORDER BY pr.processed_at DESC
                     LIMIT 100";
    
    $stmt = $conn->prepare($payroll_sql);
    $stmt->execute([$company_id]);
    $payroll_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $payroll_stats = [
        'total' => count($payroll_history),
        'approved' => count(array_filter($payroll_history, fn($p) => in_array($p['status'], ['processed', 'paid']))),
        'rejected' => count(array_filter($payroll_history, fn($p) => $p['status'] === 'cancelled'))
    ];
    
} catch (PDOException $e) {
    $payroll_history = [];
    $payroll_stats = ['total' => 0, 'approved' => 0, 'rejected' => 0];
    $errors[] = "Error fetching payroll history: " . $e->getMessage();
}

$page_title = 'Approval History';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    text-align: center;
    transition: all 0.3s;
}

.stats-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.stats-number {
    font-size: 36px;
    font-weight: 800;
}

.stats-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    margin-top: 5px;
}

.nav-tabs .nav-link {
    color: #6c757d;
    font-weight: 600;
    border: none;
    border-bottom: 3px solid transparent;
    padding: 12px 20px;
}

.nav-tabs .nav-link:hover {
    border-color: transparent;
    color: #667eea;
}

.nav-tabs .nav-link.active {
    color: #667eea;
    border-bottom: 3px solid #667eea;
    background: transparent;
}

.table-container {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    overflow: hidden;
}

.table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    padding: 15px 12px;
}

.table tbody td {
    padding: 12px;
    vertical-align: middle;
}

.badge-approved {
    background: #28a745;
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.badge-rejected {
    background: #dc3545;
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.badge-processed {
    background: #17a2b8;
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.badge-paid {
    background: #28a745;
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.badge-cancelled {
    background: #6c757d;
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-history"></i> Approval History</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="pending.php">Pending Approvals</a></li>
                    <li class="breadcrumb-item active">History</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-ban"></i> Error!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#payments-tab">
                    <i class="fas fa-money-bill-wave"></i> Payments 
                    <span class="badge bg-primary"><?php echo $payment_stats['total']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#commissions-tab">
                    <i class="fas fa-percentage"></i> Commissions 
                    <span class="badge bg-info"><?php echo $commission_stats['total']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#refunds-tab">
                    <i class="fas fa-undo"></i> Refunds 
                    <span class="badge bg-warning"><?php echo $refund_stats['total']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#payroll-tab">
                    <i class="fas fa-users"></i> Payroll 
                    <span class="badge bg-success"><?php echo $payroll_stats['total']; ?></span>
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content">
            
            <!-- PAYMENTS TAB -->
            <div class="tab-pane fade show active" id="payments-tab">
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-primary"><?php echo $payment_stats['total']; ?></div>
                            <div class="stats-label">Total Processed</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-success"><?php echo $payment_stats['approved']; ?></div>
                            <div class="stats-label">Approved</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-danger"><?php echo $payment_stats['rejected']; ?></div>
                            <div class="stats-label">Rejected</div>
                        </div>
                    </div>
                </div>

                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payment #</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Action Date</th>
                                <th>Action By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payment_history)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No payment history found</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($payment_history as $payment): ?>
                                <tr>
                                    <td><?php echo $payment['payment_date'] ? date('M d, Y', strtotime($payment['payment_date'])) : 'N/A'; ?></td>
                                    <td><strong><?php echo htmlspecialchars($payment['payment_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($payment['customer_name'] ?? 'N/A'); ?></td>
                                    <td><strong class="text-success">TZS <?php echo number_format($payment['amount'], 2); ?></strong></td>
                                    <td>
                                        <?php if ($payment['status'] === 'approved'): ?>
                                            <span class="badge-approved"><i class="fas fa-check"></i> Approved</span>
                                        <?php else: ?>
                                            <span class="badge-rejected"><i class="fas fa-times"></i> Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $action_date = $payment['approved_at'] ?? $payment['rejected_at'];
                                        echo $action_date ? date('M d, Y H:i', strtotime($action_date)) : 'N/A'; 
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['approved_by_name'] ?? $payment['rejected_by_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick='viewPaymentDetails(<?php echo json_encode($payment); ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- COMMISSIONS TAB -->
            <div class="tab-pane fade" id="commissions-tab">
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-info"><?php echo $commission_stats['total']; ?></div>
                            <div class="stats-label">Total Processed</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-success"><?php echo $commission_stats['approved']; ?></div>
                            <div class="stats-label">Paid</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-danger"><?php echo $commission_stats['rejected']; ?></div>
                            <div class="stats-label">Cancelled</div>
                        </div>
                    </div>
                </div>

                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Recipient</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Rate</th>
                                <th>Status</th>
                                <th>Action Date</th>
                                <th>Action By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($commission_history)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No commission history found</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($commission_history as $commission): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($commission['recipient_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($commission['customer_name'] ?? 'N/A'); ?></td>
                                    <td><strong class="text-success">TZS <?php echo number_format($commission['commission_amount'], 2); ?></strong></td>
                                    <td><?php echo $commission['commission_percentage']; ?>%</td>
                                    <td>
                                        <?php if ($commission['payment_status'] === 'paid'): ?>
                                            <span class="badge-paid"><i class="fas fa-check"></i> Paid</span>
                                        <?php else: ?>
                                            <span class="badge-cancelled"><i class="fas fa-ban"></i> Cancelled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $commission['approved_at'] ? date('M d, Y H:i', strtotime($commission['approved_at'])) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($commission['approved_by_name'] ?? $commission['rejected_by_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick='viewCommissionDetails(<?php echo json_encode($commission); ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- REFUNDS TAB -->
            <div class="tab-pane fade" id="refunds-tab">
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-warning"><?php echo $refund_stats['total']; ?></div>
                            <div class="stats-label">Total Processed</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-success"><?php echo $refund_stats['approved']; ?></div>
                            <div class="stats-label">Approved</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-danger"><?php echo $refund_stats['rejected']; ?></div>
                            <div class="stats-label">Rejected</div>
                        </div>
                    </div>
                </div>

                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Refund #</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Action Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($refund_history)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No refund history found</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($refund_history as $refund): ?>
                                <tr>
                                    <td><?php echo $refund['refund_date'] ? date('M d, Y', strtotime($refund['refund_date'])) : 'N/A'; ?></td>
                                    <td><strong><?php echo htmlspecialchars($refund['refund_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($refund['customer_name'] ?? 'N/A'); ?></td>
                                    <td><strong class="text-danger">TZS <?php echo number_format($refund['refund_amount'], 2); ?></strong></td>
                                    <td><?php echo ucwords(str_replace('_', ' ', $refund['refund_reason'])); ?></td>
                                    <td>
                                        <?php if (in_array($refund['status'], ['approved', 'processed'])): ?>
                                            <span class="badge-approved"><i class="fas fa-check"></i> <?php echo ucfirst($refund['status']); ?></span>
                                        <?php else: ?>
                                            <span class="badge-rejected"><i class="fas fa-times"></i> Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $refund['approved_at'] ? date('M d, Y H:i', strtotime($refund['approved_at'])) : 'N/A'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick='viewRefundDetails(<?php echo json_encode($refund); ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PAYROLL TAB -->
            <div class="tab-pane fade" id="payroll-tab">
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-success"><?php echo $payroll_stats['total']; ?></div>
                            <div class="stats-label">Total Processed</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-primary"><?php echo $payroll_stats['approved']; ?></div>
                            <div class="stats-label">Approved</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-danger"><?php echo $payroll_stats['rejected']; ?></div>
                            <div class="stats-label">Cancelled</div>
                        </div>
                    </div>
                </div>

                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Employees</th>
                                <th>Gross</th>
                                <th>Deductions</th>
                                <th>Net</th>
                                <th>Status</th>
                                <th>Processed Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payroll_history)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No payroll history found</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($payroll_history as $payroll): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('F Y', mktime(0, 0, 0, $payroll['payroll_month'], 1, $payroll['payroll_year'])); ?></strong>
                                    </td>
                                    <td><?php echo $payroll['employee_count']; ?> employees</td>
                                    <td>TZS <?php echo number_format($payroll['total_gross'] ?? 0, 2); ?></td>
                                    <td class="text-danger">TZS <?php echo number_format($payroll['total_deductions'] ?? 0, 2); ?></td>
                                    <td class="text-success"><strong>TZS <?php echo number_format($payroll['total_net'] ?? 0, 2); ?></strong></td>
                                    <td>
                                        <?php
                                        $status_badges = [
                                            'processed' => '<span class="badge-processed"><i class="fas fa-check"></i> Processed</span>',
                                            'paid' => '<span class="badge-paid"><i class="fas fa-check-double"></i> Paid</span>',
                                            'cancelled' => '<span class="badge-cancelled"><i class="fas fa-ban"></i> Cancelled</span>'
                                        ];
                                        echo $status_badges[$payroll['status']] ?? '<span class="badge-secondary">' . $payroll['status'] . '</span>';
                                        ?>
                                    </td>
                                    <td><?php echo $payroll['processed_at'] ? date('M d, Y H:i', strtotime($payroll['processed_at'])) : 'N/A'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick='viewPayrollDetails(<?php echo json_encode($payroll); ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>
</section>

<!-- Payment Details Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> Payment Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Payment Number:</strong> <span id="pm_payment_number"></span></p>
                        <p><strong>Date:</strong> <span id="pm_date"></span></p>
                        <p><strong>Amount:</strong> <span id="pm_amount"></span></p>
                        <p><strong>Method:</strong> <span id="pm_method"></span></p>
                        <p><strong>Type:</strong> <span id="pm_type"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Customer:</strong> <span id="pm_customer"></span></p>
                        <p><strong>Reservation:</strong> <span id="pm_reservation"></span></p>
                        <p><strong>Plot:</strong> <span id="pm_plot"></span></p>
                        <p><strong>Project:</strong> <span id="pm_project"></span></p>
                        <p><strong>Status:</strong> <span id="pm_status"></span></p>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Action Date:</strong> <span id="pm_action_date"></span></p>
                        <p><strong>Action By:</strong> <span id="pm_action_by"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Submitted By:</strong> <span id="pm_submitted_by"></span></p>
                    </div>
                </div>
                <div id="pm_remarks_section" style="display:none;">
                    <hr>
                    <p><strong>Remarks:</strong></p>
                    <div class="alert alert-info" id="pm_remarks"></div>
                </div>
                <div id="pm_rejection_section" style="display:none;">
                    <hr>
                    <p><strong>Rejection Reason:</strong></p>
                    <div class="alert alert-danger" id="pm_rejection_reason"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Commission Details Modal -->
<div class="modal fade" id="commissionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-percentage"></i> Commission Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Recipient:</strong> <span id="cm_recipient"></span></p>
                        <p><strong>Phone:</strong> <span id="cm_phone"></span></p>
                        <p><strong>Type:</strong> <span id="cm_type"></span></p>
                        <p><strong>Amount:</strong> <span id="cm_amount"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Percentage:</strong> <span id="cm_percentage"></span></p>
                        <p><strong>Customer:</strong> <span id="cm_customer"></span></p>
                        <p><strong>Plot:</strong> <span id="cm_plot"></span></p>
                        <p><strong>Status:</strong> <span id="cm_status"></span></p>
                    </div>
                </div>
                <hr>
                <p><strong>Action Date:</strong> <span id="cm_action_date"></span></p>
                <p><strong>Action By:</strong> <span id="cm_action_by"></span></p>
                <div id="cm_remarks_section" style="display:none;">
                    <hr>
                    <p><strong>Remarks:</strong></p>
                    <div class="alert alert-info" id="cm_remarks"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Refund Details Modal -->
<div class="modal fade" id="refundModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-undo"></i> Refund Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Refund Number:</strong> <span id="rf_refund_number"></span></p>
                        <p><strong>Date:</strong> <span id="rf_date"></span></p>
                        <p><strong>Customer:</strong> <span id="rf_customer"></span></p>
                        <p><strong>Payment:</strong> <span id="rf_payment"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Amount:</strong> <span id="rf_amount"></span></p>
                        <p><strong>Penalty:</strong> <span id="rf_penalty"></span></p>
                        <p><strong>Net Refund:</strong> <span id="rf_net"></span></p>
                        <p><strong>Method:</strong> <span id="rf_method"></span></p>
                    </div>
                </div>
                <hr>
                <p><strong>Reason:</strong> <span id="rf_reason"></span></p>
                <p><strong>Status:</strong> <span id="rf_status"></span></p>
                <p><strong>Approved Date:</strong> <span id="rf_approved_date"></span></p>
                <p><strong>Approved By:</strong> <span id="rf_approved_by"></span></p>
                <div class="alert alert-info" id="rf_detailed_reason"></div>
            </div>
        </div>
    </div>
</div>

<!-- Payroll Details Modal -->
<div class="modal fade" id="payrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-users"></i> Payroll Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Period:</strong> <span id="pr_period"></span></p>
                        <p><strong>Employees:</strong> <span id="pr_employees"></span></p>
                        <p><strong>Payment Date:</strong> <span id="pr_payment_date"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Gross Amount:</strong> <span id="pr_gross"></span></p>
                        <p><strong>Deductions:</strong> <span id="pr_deductions"></span></p>
                        <p><strong>Net Amount:</strong> <span id="pr_net"></span></p>
                    </div>
                </div>
                <hr>
                <p><strong>Status:</strong> <span id="pr_status"></span></p>
                <p><strong>Processed Date:</strong> <span id="pr_processed_date"></span></p>
                <p><strong>Processed By:</strong> <span id="pr_processed_by"></span></p>
                <p><strong>Created By:</strong> <span id="pr_created_by"></span></p>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// View Payment Details
function viewPaymentDetails(data) {
    document.getElementById('pm_payment_number').textContent = data.payment_number;
    document.getElementById('pm_date').textContent = data.payment_date ? new Date(data.payment_date).toLocaleDateString() : 'N/A';
    document.getElementById('pm_amount').textContent = 'TZS ' + parseFloat(data.amount).toLocaleString();
    document.getElementById('pm_method').textContent = data.payment_method ? data.payment_method.replace(/_/g, ' ').toUpperCase() : 'N/A';
    document.getElementById('pm_type').textContent = data.payment_type ? data.payment_type.replace(/_/g, ' ').toUpperCase() : 'N/A';
    document.getElementById('pm_customer').textContent = data.customer_name || 'N/A';
    document.getElementById('pm_reservation').textContent = data.reservation_number || 'N/A';
    document.getElementById('pm_plot').textContent = data.plot_number || 'N/A';
    document.getElementById('pm_project').textContent = data.project_name || 'N/A';
    
    const statusBadge = data.status === 'approved' 
        ? '<span class="badge-approved">Approved</span>' 
        : '<span class="badge-rejected">Rejected</span>';
    document.getElementById('pm_status').innerHTML = statusBadge;
    
    const actionDate = data.approved_at || data.rejected_at;
    document.getElementById('pm_action_date').textContent = actionDate ? new Date(actionDate).toLocaleString() : 'N/A';
    
    const actionBy = data.approved_by_name || data.rejected_by_name;
    document.getElementById('pm_action_by').textContent = actionBy || 'N/A';
    
    document.getElementById('pm_submitted_by').textContent = data.submitted_by_name || 'N/A';
    
    if (data.remarks) {
        document.getElementById('pm_remarks_section').style.display = 'block';
        document.getElementById('pm_remarks').textContent = data.remarks;
    } else {
        document.getElementById('pm_remarks_section').style.display = 'none';
    }
    
    if (data.rejection_reason) {
        document.getElementById('pm_rejection_section').style.display = 'block';
        document.getElementById('pm_rejection_reason').textContent = data.rejection_reason;
    } else {
        document.getElementById('pm_rejection_section').style.display = 'none';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

// View Commission Details
function viewCommissionDetails(data) {
    document.getElementById('cm_recipient').textContent = data.recipient_name;
    document.getElementById('cm_phone').textContent = data.recipient_phone || 'N/A';
    document.getElementById('cm_type').textContent = data.commission_type ? data.commission_type.toUpperCase() : 'N/A';
    document.getElementById('cm_amount').textContent = 'TZS ' + parseFloat(data.commission_amount).toLocaleString();
    document.getElementById('cm_percentage').textContent = data.commission_percentage + '%';
    document.getElementById('cm_customer').textContent = data.customer_name || 'N/A';
    document.getElementById('cm_plot').textContent = data.plot_number || 'N/A';
    
    const statusBadge = data.payment_status === 'paid' 
        ? '<span class="badge-paid">Paid</span>' 
        : '<span class="badge-cancelled">Cancelled</span>';
    document.getElementById('cm_status').innerHTML = statusBadge;
    
    document.getElementById('cm_action_date').textContent = data.approved_at ? new Date(data.approved_at).toLocaleString() : 'N/A';
    document.getElementById('cm_action_by').textContent = data.approved_by_name || data.rejected_by_name || 'N/A';
    
    if (data.remarks) {
        document.getElementById('cm_remarks_section').style.display = 'block';
        document.getElementById('cm_remarks').textContent = data.remarks;
    } else {
        document.getElementById('cm_remarks_section').style.display = 'none';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('commissionModal'));
    modal.show();
}

// View Refund Details
function viewRefundDetails(data) {
    document.getElementById('rf_refund_number').textContent = data.refund_number;
    document.getElementById('rf_date').textContent = data.refund_date ? new Date(data.refund_date).toLocaleDateString() : 'N/A';
    document.getElementById('rf_customer').textContent = data.customer_name;
    document.getElementById('rf_payment').textContent = data.payment_number;
    document.getElementById('rf_amount').textContent = 'TZS ' + parseFloat(data.refund_amount).toLocaleString();
    document.getElementById('rf_penalty').textContent = 'TZS ' + parseFloat(data.penalty_amount).toLocaleString();
    document.getElementById('rf_net').textContent = 'TZS ' + (parseFloat(data.refund_amount) - parseFloat(data.penalty_amount)).toLocaleString();
    document.getElementById('rf_method').textContent = data.refund_method ? data.refund_method.replace(/_/g, ' ').toUpperCase() : 'N/A';
    document.getElementById('rf_reason').textContent = data.refund_reason ? data.refund_reason.replace(/_/g, ' ').toUpperCase() : 'N/A';
    
    const statusBadge = ['approved', 'processed'].includes(data.status)
        ? '<span class="badge-approved">' + data.status.toUpperCase() + '</span>' 
        : '<span class="badge-rejected">Rejected</span>';
    document.getElementById('rf_status').innerHTML = statusBadge;
    
    document.getElementById('rf_approved_date').textContent = data.approved_at ? new Date(data.approved_at).toLocaleString() : 'N/A';
    document.getElementById('rf_approved_by').textContent = data.approved_by_name || 'N/A';
    document.getElementById('rf_detailed_reason').textContent = data.detailed_reason || 'N/A';
    
    const modal = new bootstrap.Modal(document.getElementById('refundModal'));
    modal.show();
}

// View Payroll Details
function viewPayrollDetails(data) {
    const monthName = new Date(data.payroll_year, data.payroll_month - 1).toLocaleDateString('en-US', {month: 'long', year: 'numeric'});
    document.getElementById('pr_period').textContent = monthName;
    document.getElementById('pr_employees').textContent = data.employee_count + ' employees';
    document.getElementById('pr_payment_date').textContent = data.payment_date ? new Date(data.payment_date).toLocaleDateString() : 'N/A';
    document.getElementById('pr_gross').textContent = 'TZS ' + parseFloat(data.total_gross || 0).toLocaleString();
    document.getElementById('pr_deductions').textContent = 'TZS ' + parseFloat(data.total_deductions || 0).toLocaleString();
    document.getElementById('pr_net').textContent = 'TZS ' + parseFloat(data.total_net || 0).toLocaleString();
    
    const statusBadges = {
        'processed': '<span class="badge-processed">Processed</span>',
        'paid': '<span class="badge-paid">Paid</span>',
        'cancelled': '<span class="badge-cancelled">Cancelled</span>'
    };
    document.getElementById('pr_status').innerHTML = statusBadges[data.status] || data.status;
    
    document.getElementById('pr_processed_date').textContent = data.processed_at ? new Date(data.processed_at).toLocaleString() : 'N/A';
    document.getElementById('pr_processed_by').textContent = data.processed_by_name || 'N/A';
    document.getElementById('pr_created_by').textContent = data.created_by_name || 'N/A';
    
    const modal = new bootstrap.Modal(document.getElementById('payrollModal'));
    modal.show();
}

// Auto-dismiss alerts
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        bsAlert.close();
    });
}, 5000);
</script>