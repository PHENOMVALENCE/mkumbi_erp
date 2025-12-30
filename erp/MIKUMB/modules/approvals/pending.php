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

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->beginTransaction();
    
    try {
        $record_id = $_POST['record_id'] ?? 0;
        $approval_type = $_POST['approval_type'] ?? ''; // payment, commission, refund, purchase, transfer
        $action = $_POST['action'] ?? ''; // approve or reject
        $comments = trim($_POST['comments'] ?? '');
        
        if ($action === 'approve') {
            // Handle different approval types
            switch ($approval_type) {
                case 'payment':
                    // Update payment status
                    $update_sql = "UPDATE payments SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE payment_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$_SESSION['user_id'], $record_id, $company_id]);
                    
                    // Insert approval record
                    $approval_sql = "INSERT INTO payment_approvals (payment_id, action, action_by, action_at, comments, previous_status, new_status, company_id) 
                                    VALUES (?, 'approved', ?, NOW(), ?, 'pending', 'approved', ?)";
                    $stmt = $conn->prepare($approval_sql);
                    $stmt->execute([$record_id, $_SESSION['user_id'], $comments, $company_id]);
                    
                    $success[] = "Payment approved successfully";
                    break;
                    
                case 'commission':
                    // Update commission status
                    $update_sql = "UPDATE commissions SET payment_status = 'paid', approved_by = ?, approved_at = NOW() WHERE commission_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$_SESSION['user_id'], $record_id, $company_id]);
                    
                    $success[] = "Commission approved successfully";
                    break;
                    
                case 'refund':
                    // Update refund status
                    $update_sql = "UPDATE refunds SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE refund_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$_SESSION['user_id'], $record_id, $company_id]);
                    
                    $success[] = "Refund approved successfully";
                    break;
                    
                case 'purchase':
                    // Update purchase order status
                    $update_sql = "UPDATE purchase_orders SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE purchase_order_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$_SESSION['user_id'], $record_id, $company_id]);
                    
                    $success[] = "Purchase order approved successfully";
                    break;
                    
                case 'payroll':
                    // Update payroll status
                    $update_sql = "UPDATE payroll SET status = 'processed', processed_by = ?, processed_at = NOW() WHERE payroll_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$_SESSION['user_id'], $record_id, $company_id]);
                    
                    $success[] = "Payroll approved successfully";
                    break;
            }
            
        } elseif ($action === 'reject') {
            // Handle different rejection types
            switch ($approval_type) {
                case 'payment':
                    $update_sql = "UPDATE payments SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE payment_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$_SESSION['user_id'], $comments, $record_id, $company_id]);
                    
                    $approval_sql = "INSERT INTO payment_approvals (payment_id, action, action_by, action_at, comments, previous_status, new_status, company_id) 
                                    VALUES (?, 'rejected', ?, NOW(), ?, 'pending', 'rejected', ?)";
                    $stmt = $conn->prepare($approval_sql);
                    $stmt->execute([$record_id, $_SESSION['user_id'], $comments, $company_id]);
                    
                    $success[] = "Payment rejected";
                    break;
                    
                case 'commission':
                    $update_sql = "UPDATE commissions SET payment_status = 'cancelled', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE commission_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$_SESSION['user_id'], $comments, $record_id, $company_id]);
                    
                    $success[] = "Commission rejected";
                    break;
                    
                case 'refund':
                    $update_sql = "UPDATE refunds SET status = 'rejected', rejection_reason = ? WHERE refund_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$comments, $record_id, $company_id]);
                    
                    $success[] = "Refund rejected";
                    break;
                    
                case 'purchase':
                    $update_sql = "UPDATE purchase_orders SET status = 'rejected' WHERE purchase_order_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$record_id, $company_id]);
                    
                    $success[] = "Purchase order rejected";
                    break;
                    
                case 'payroll':
                    $update_sql = "UPDATE payroll SET status = 'cancelled' WHERE payroll_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute([$record_id, $company_id]);
                    
                    $success[] = "Payroll rejected";
                    break;
            }
        }
        
        $conn->commit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $errors[] = "Error processing approval: " . $e->getMessage();
    }
}

// Fetch pending payments
try {
    $pending_payments_sql = "SELECT 
                                p.payment_id as record_id,
                                p.payment_number,
                                p.payment_date,
                                p.amount,
                                p.payment_method,
                                p.payment_type,
                                p.bank_name,
                                p.transaction_reference,
                                p.remarks,
                                p.created_at,
                                r.reservation_id,
                                r.reservation_number,
                                c.full_name as customer_name,
                                c.phone as customer_phone,
                                pl.plot_number,
                                pr.project_name,
                                u.full_name as submitted_by_name,
                                DATEDIFF(NOW(), p.created_at) as days_pending,
                                'payment' as approval_type
                            FROM payments p
                            LEFT JOIN reservations r ON p.reservation_id = r.reservation_id
                            LEFT JOIN customers c ON r.customer_id = c.customer_id
                            LEFT JOIN plots pl ON r.plot_id = pl.plot_id
                            LEFT JOIN projects pr ON pl.project_id = pr.project_id
                            LEFT JOIN users u ON p.created_by = u.user_id
                            WHERE p.status = 'pending_approval' 
                            AND p.company_id = ?
                            ORDER BY p.created_at DESC";
    
    $stmt = $conn->prepare($pending_payments_sql);
    $stmt->execute([$company_id]);
    $pending_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errors[] = "Error fetching pending payments: " . $e->getMessage();
    $pending_payments = [];
}

// Fetch pending commissions
try {
    $pending_commissions_sql = "SELECT 
                                    co.commission_id as record_id,
                                    co.commission_type,
                                    co.commission_percentage,
                                    co.commission_amount,
                                    co.remarks,
                                    co.created_at,
                                    r.reservation_number,
                                    c.full_name as customer_name,
                                    co.recipient_name,
                                    co.recipient_phone,
                                    pl.plot_number,
                                    pr.project_name,
                                    u.full_name as submitted_by_name,
                                    DATEDIFF(NOW(), co.created_at) as days_pending,
                                    'commission' as approval_type
                                FROM commissions co
                                LEFT JOIN reservations r ON co.reservation_id = r.reservation_id
                                LEFT JOIN customers c ON r.customer_id = c.customer_id
                                LEFT JOIN plots pl ON r.plot_id = pl.plot_id
                                LEFT JOIN projects pr ON pl.project_id = pr.project_id
                                LEFT JOIN users u ON co.created_by = u.user_id
                                WHERE co.payment_status = 'pending' 
                                AND co.company_id = ?
                                ORDER BY co.created_at DESC";
    
    $stmt = $conn->prepare($pending_commissions_sql);
    $stmt->execute([$company_id]);
    $pending_commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errors[] = "Error fetching pending commissions: " . $e->getMessage();
    $pending_commissions = [];
}

// Fetch pending refunds
try {
    $pending_refunds_sql = "SELECT 
                                rf.refund_id as record_id,
                                rf.refund_number,
                                rf.refund_date,
                                rf.refund_amount,
                                rf.penalty_amount,
                                rf.refund_method,
                                rf.refund_reason,
                                rf.detailed_reason,
                                rf.created_at,
                                p.payment_number,
                                c.full_name as customer_name,
                                c.phone as customer_phone,
                                pl.plot_number,
                                pr.project_name,
                                u.full_name as submitted_by_name,
                                DATEDIFF(NOW(), rf.created_at) as days_pending,
                                'refund' as approval_type
                            FROM refunds rf
                            LEFT JOIN payments p ON rf.original_payment_id = p.payment_id
                            LEFT JOIN customers c ON rf.customer_id = c.customer_id
                            LEFT JOIN plots pl ON rf.plot_id = pl.plot_id
                            LEFT JOIN projects pr ON pl.project_id = pr.project_id
                            LEFT JOIN users u ON rf.created_by = u.user_id
                            WHERE rf.status = 'pending' 
                            AND rf.company_id = ?
                            ORDER BY rf.created_at DESC";
    
    $stmt = $conn->prepare($pending_refunds_sql);
    $stmt->execute([$company_id]);
    $pending_refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errors[] = "Error fetching pending refunds: " . $e->getMessage();
    $pending_refunds = [];
}

// Fetch pending purchase orders
try {
    $pending_purchases_sql = "SELECT 
                                po.purchase_order_id as record_id,
                                po.po_number,
                                po.po_date,
                                po.total_amount,
                                po.delivery_date,
                                po.payment_terms,
                                po.notes,
                                po.created_at,
                                s.supplier_name,
                                s.phone as supplier_phone,
                                u.full_name as submitted_by_name,
                                COUNT(poi.po_item_id) as item_count,
                                DATEDIFF(NOW(), po.created_at) as days_pending,
                                'purchase' as approval_type
                            FROM purchase_orders po
                            LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
                            LEFT JOIN purchase_order_items poi ON po.purchase_order_id = poi.purchase_order_id
                            LEFT JOIN users u ON po.created_by = u.user_id
                            WHERE po.status = 'submitted' 
                            AND po.company_id = ?
                            GROUP BY po.purchase_order_id
                            ORDER BY po.created_at DESC";
    
    $stmt = $conn->prepare($pending_purchases_sql);
    $stmt->execute([$company_id]);
    $pending_purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errors[] = "Error fetching pending purchases: " . $e->getMessage();
    $pending_purchases = [];
}

// Fetch pending payroll
try {
    $pending_payroll_sql = "SELECT 
                                pr.payroll_id as record_id,
                                pr.payroll_month,
                                pr.payroll_year,
                                pr.payment_date,
                                pr.created_at,
                                COUNT(pd.payroll_detail_id) as employee_count,
                                SUM(pd.net_salary) as total_amount,
                                u.full_name as submitted_by_name,
                                DATEDIFF(NOW(), pr.created_at) as days_pending,
                                'payroll' as approval_type
                            FROM payroll pr
                            LEFT JOIN payroll_details pd ON pr.payroll_id = pd.payroll_id
                            LEFT JOIN users u ON pr.created_by = u.user_id
                            WHERE pr.status = 'draft' 
                            AND pr.company_id = ?
                            GROUP BY pr.payroll_id
                            ORDER BY pr.created_at DESC";
    
    $stmt = $conn->prepare($pending_payroll_sql);
    $stmt->execute([$company_id]);
    $pending_payroll = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errors[] = "Error fetching pending payroll: " . $e->getMessage();
    $pending_payroll = [];
}

// Calculate statistics
$stats = [
    'payments' => count($pending_payments),
    'commissions' => count($pending_commissions),
    'refunds' => count($pending_refunds),
    'purchases' => count($pending_purchases),
    'payroll' => count($pending_payroll)
];

$stats['total'] = array_sum($stats);

$page_title = 'Pending Approvals';
require_once '../../includes/header.php';
?>

<style>
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
    color: #667eea;
}

.stats-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    margin-top: 5px;
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

.badge-pending {
    background: #fff3cd;
    color: #856404;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.badge-urgent {
    background: #f8d7da;
    color: #721c24;
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
                <h1>
                    <i class="fas fa-clock"></i> Pending Approvals
                </h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="history.php">Approval History</a></li>
                    <li class="breadcrumb-item active">Pending</li>
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

        <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h5><i class="fas fa-check-circle"></i> Success!</h5>
            <ul class="mb-0">
                <?php foreach ($success as $msg): ?>
                    <li><?php echo htmlspecialchars($msg); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-2 mb-3">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $stats['total']; ?></div>
                    <div class="stats-label">Total Pending</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-primary"><?php echo $stats['payments']; ?></div>
                    <div class="stats-label">Payments</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-info"><?php echo $stats['commissions']; ?></div>
                    <div class="stats-label">Commissions</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-warning"><?php echo $stats['refunds']; ?></div>
                    <div class="stats-label">Refunds</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $stats['purchases']; ?></div>
                    <div class="stats-label">Purchases</div>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-danger"><?php echo $stats['payroll']; ?></div>
                    <div class="stats-label">Payroll</div>
                </div>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#payments-tab">
                    <i class="fas fa-money-bill-wave"></i> Payments 
                    <span class="badge bg-primary"><?php echo $stats['payments']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#commissions-tab">
                    <i class="fas fa-percentage"></i> Commissions 
                    <span class="badge bg-info"><?php echo $stats['commissions']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#refunds-tab">
                    <i class="fas fa-undo"></i> Refunds 
                    <span class="badge bg-warning"><?php echo $stats['refunds']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#purchases-tab">
                    <i class="fas fa-shopping-cart"></i> Purchases 
                    <span class="badge bg-success"><?php echo $stats['purchases']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#payroll-tab">
                    <i class="fas fa-users"></i> Payroll 
                    <span class="badge bg-danger"><?php echo $stats['payroll']; ?></span>
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content">
            
            <!-- PAYMENTS TAB -->
            <div class="tab-pane fade show active" id="payments-tab">
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Submitted</th>
                                <th>Payment #</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Days</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_payments)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <p class="text-muted">No pending payments</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($pending_payments as $payment): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($payment['created_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($payment['payment_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($payment['customer_name'] ?? 'N/A'); ?></td>
                                    <td><strong class="text-success">TZS <?php echo number_format($payment['amount'], 2); ?></strong></td>
                                    <td><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                    <td>
                                        <?php if ($payment['days_pending'] > 3): ?>
                                            <span class="badge-urgent"><i class="fas fa-exclamation-triangle"></i> Urgent</span>
                                        <?php else: ?>
                                            <span class="badge-pending"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $payment['days_pending']; ?> days</td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick='viewPaymentDetails(<?php echo json_encode($payment); ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick='approveRecord(<?php echo $payment['record_id']; ?>, "payment", "<?php echo htmlspecialchars($payment['payment_number']); ?>")'>
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick='rejectRecord(<?php echo $payment['record_id']; ?>, "payment", "<?php echo htmlspecialchars($payment['payment_number']); ?>")'>
                                            <i class="fas fa-times"></i>
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
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Submitted</th>
                                <th>Recipient</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Rate</th>
                                <th>Status</th>
                                <th>Days</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_commissions)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <p class="text-muted">No pending commissions</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($pending_commissions as $commission): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($commission['recipient_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($commission['customer_name'] ?? 'N/A'); ?></td>
                                    <td><strong class="text-success">TZS <?php echo number_format($commission['commission_amount'], 2); ?></strong></td>
                                    <td><?php echo $commission['commission_percentage']; ?>%</td>
                                    <td>
                                        <?php if ($commission['days_pending'] > 3): ?>
                                            <span class="badge-urgent"><i class="fas fa-exclamation-triangle"></i> Urgent</span>
                                        <?php else: ?>
                                            <span class="badge-pending"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $commission['days_pending']; ?> days</td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick='viewCommissionDetails(<?php echo json_encode($commission); ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick='approveRecord(<?php echo $commission['record_id']; ?>, "commission", "Commission")'>
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick='rejectRecord(<?php echo $commission['record_id']; ?>, "commission", "Commission")'>
                                            <i class="fas fa-times"></i>
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
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Submitted</th>
                                <th>Refund #</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Days</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_refunds)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <p class="text-muted">No pending refunds</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($pending_refunds as $refund): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($refund['created_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($refund['refund_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($refund['customer_name'] ?? 'N/A'); ?></td>
                                    <td><strong class="text-danger">TZS <?php echo number_format($refund['refund_amount'], 2); ?></strong></td>
                                    <td><?php echo ucwords(str_replace('_', ' ', $refund['refund_reason'])); ?></td>
                                    <td>
                                        <?php if ($refund['days_pending'] > 2): ?>
                                            <span class="badge-urgent"><i class="fas fa-exclamation-triangle"></i> Urgent</span>
                                        <?php else: ?>
                                            <span class="badge-pending"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $refund['days_pending']; ?> days</td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick='viewRefundDetails(<?php echo json_encode($refund); ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick='approveRecord(<?php echo $refund['record_id']; ?>, "refund", "<?php echo htmlspecialchars($refund['refund_number']); ?>")'>
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick='rejectRecord(<?php echo $refund['record_id']; ?>, "refund", "<?php echo htmlspecialchars($refund['refund_number']); ?>")'>
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PURCHASES TAB -->
            <div class="tab-pane fade" id="purchases-tab">
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Submitted</th>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Amount</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th>Days</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_purchases)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <p class="text-muted">No pending purchase orders</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($pending_purchases as $purchase): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($purchase['created_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($purchase['po_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($purchase['supplier_name'] ?? 'N/A'); ?></td>
                                    <td><strong class="text-success">TZS <?php echo number_format($purchase['total_amount'], 2); ?></strong></td>
                                    <td><?php echo $purchase['item_count']; ?> items</td>
                                    <td>
                                        <?php if ($purchase['days_pending'] > 3): ?>
                                            <span class="badge-urgent"><i class="fas fa-exclamation-triangle"></i> Urgent</span>
                                        <?php else: ?>
                                            <span class="badge-pending"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $purchase['days_pending']; ?> days</td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick='viewPurchaseDetails(<?php echo json_encode($purchase); ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick='approveRecord(<?php echo $purchase['record_id']; ?>, "purchase", "<?php echo htmlspecialchars($purchase['po_number']); ?>")'>
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick='rejectRecord(<?php echo $purchase['record_id']; ?>, "purchase", "<?php echo htmlspecialchars($purchase['po_number']); ?>")'>
                                            <i class="fas fa-times"></i>
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
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Submitted</th>
                                <th>Month/Year</th>
                                <th>Employees</th>
                                <th>Total Amount</th>
                                <th>Payment Date</th>
                                <th>Status</th>
                                <th>Days</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_payroll)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <p class="text-muted">No pending payroll</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($pending_payroll as $payroll): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($payroll['created_at'])); ?></td>
                                    <td><strong><?php echo date('F Y', mktime(0, 0, 0, $payroll['payroll_month'], 1, $payroll['payroll_year'])); ?></strong></td>
                                    <td><?php echo $payroll['employee_count']; ?> employees</td>
                                    <td><strong class="text-success">TZS <?php echo number_format($payroll['total_amount'], 2); ?></strong></td>
                                    <td><?php echo $payroll['payment_date'] ? date('M d, Y', strtotime($payroll['payment_date'])) : 'Not set'; ?></td>
                                    <td>
                                        <?php if ($payroll['days_pending'] > 3): ?>
                                            <span class="badge-urgent"><i class="fas fa-exclamation-triangle"></i> Urgent</span>
                                        <?php else: ?>
                                            <span class="badge-pending"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $payroll['days_pending']; ?> days</td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick='viewPayrollDetails(<?php echo json_encode($payroll); ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick='approveRecord(<?php echo $payroll['record_id']; ?>, "payroll", "Payroll")'>
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick='rejectRecord(<?php echo $payroll['record_id']; ?>, "payroll", "Payroll")'>
                                            <i class="fas fa-times"></i>
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
                    </div>
                    <div class="col-md-6">
                        <p><strong>Customer:</strong> <span id="pm_customer"></span></p>
                        <p><strong>Reservation:</strong> <span id="pm_reservation"></span></p>
                        <p><strong>Plot:</strong> <span id="pm_plot"></span></p>
                        <p><strong>Project:</strong> <span id="pm_project"></span></p>
                    </div>
                </div>
                <div id="pm_remarks_section" style="display:none;">
                    <hr>
                    <p><strong>Remarks:</strong></p>
                    <div class="alert alert-info" id="pm_remarks"></div>
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
                    </div>
                    <div class="col-md-6">
                        <p><strong>Amount:</strong> <span id="cm_amount"></span></p>
                        <p><strong>Percentage:</strong> <span id="cm_percentage"></span></p>
                        <p><strong>Customer:</strong> <span id="cm_customer"></span></p>
                    </div>
                </div>
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
                <div class="alert alert-info" id="rf_detailed_reason"></div>
            </div>
        </div>
    </div>
</div>

<!-- Purchase Details Modal -->
<div class="modal fade" id="purchaseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-shopping-cart"></i> Purchase Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>PO Number:</strong> <span id="po_number"></span></p>
                        <p><strong>Date:</strong> <span id="po_date"></span></p>
                        <p><strong>Supplier:</strong> <span id="po_supplier"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Total Amount:</strong> <span id="po_amount"></span></p>
                        <p><strong>Items Count:</strong> <span id="po_items"></span></p>
                        <p><strong>Delivery Date:</strong> <span id="po_delivery"></span></p>
                    </div>
                </div>
                <div id="po_notes_section" style="display:none;">
                    <hr>
                    <p><strong>Notes:</strong></p>
                    <div class="alert alert-info" id="po_notes"></div>
                </div>
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
                    </div>
                    <div class="col-md-6">
                        <p><strong>Total Amount:</strong> <span id="pr_amount"></span></p>
                        <p><strong>Payment Date:</strong> <span id="pr_payment_date"></span></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approval Modal -->
<div class="modal fade" id="approvalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check-circle"></i> Confirm Approval</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Are you sure you want to approve <strong id="approval_record_number"></strong>?</p>
                    <input type="hidden" name="record_id" id="approval_record_id">
                    <input type="hidden" name="approval_type" id="approval_type">
                    <input type="hidden" name="action" value="approve">
                    <div class="mb-3">
                        <label class="form-label">Comments (Optional)</label>
                        <textarea name="comments" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div class="modal fade" id="rejectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-times-circle"></i> Confirm Rejection</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Are you sure you want to reject <strong id="rejection_record_number"></strong>?</p>
                    <input type="hidden" name="record_id" id="rejection_record_id">
                    <input type="hidden" name="approval_type" id="rejection_type">
                    <input type="hidden" name="action" value="reject">
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea name="comments" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </div>
            </form>
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
    document.getElementById('pm_date').textContent = new Date(data.payment_date).toLocaleDateString();
    document.getElementById('pm_amount').textContent = 'TZS ' + parseFloat(data.amount).toLocaleString();
    document.getElementById('pm_method').textContent = data.payment_method.replace(/_/g, ' ').toUpperCase();
    document.getElementById('pm_customer').textContent = data.customer_name || 'N/A';
    document.getElementById('pm_reservation').textContent = data.reservation_number || 'N/A';
    document.getElementById('pm_plot').textContent = data.plot_number || 'N/A';
    document.getElementById('pm_project').textContent = data.project_name || 'N/A';
    
    if (data.remarks) {
        document.getElementById('pm_remarks_section').style.display = 'block';
        document.getElementById('pm_remarks').textContent = data.remarks;
    } else {
        document.getElementById('pm_remarks_section').style.display = 'none';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

// View Commission Details
function viewCommissionDetails(data) {
    document.getElementById('cm_recipient').textContent = data.recipient_name;
    document.getElementById('cm_phone').textContent = data.recipient_phone || 'N/A';
    document.getElementById('cm_type').textContent = data.commission_type.toUpperCase();
    document.getElementById('cm_amount').textContent = 'TZS ' + parseFloat(data.commission_amount).toLocaleString();
    document.getElementById('cm_percentage').textContent = data.commission_percentage + '%';
    document.getElementById('cm_customer').textContent = data.customer_name || 'N/A';
    
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
    document.getElementById('rf_date').textContent = new Date(data.refund_date).toLocaleDateString();
    document.getElementById('rf_customer').textContent = data.customer_name;
    document.getElementById('rf_payment').textContent = data.payment_number;
    document.getElementById('rf_amount').textContent = 'TZS ' + parseFloat(data.refund_amount).toLocaleString();
    document.getElementById('rf_penalty').textContent = 'TZS ' + parseFloat(data.penalty_amount).toLocaleString();
    document.getElementById('rf_net').textContent = 'TZS ' + (parseFloat(data.refund_amount) - parseFloat(data.penalty_amount)).toLocaleString();
    document.getElementById('rf_method').textContent = data.refund_method.replace(/_/g, ' ').toUpperCase();
    document.getElementById('rf_reason').textContent = data.refund_reason.replace(/_/g, ' ').toUpperCase();
    document.getElementById('rf_detailed_reason').textContent = data.detailed_reason;
    
    const modal = new bootstrap.Modal(document.getElementById('refundModal'));
    modal.show();
}

// View Purchase Details
function viewPurchaseDetails(data) {
    document.getElementById('po_number').textContent = data.po_number;
    document.getElementById('po_date').textContent = new Date(data.po_date).toLocaleDateString();
    document.getElementById('po_supplier').textContent = data.supplier_name || 'N/A';
    document.getElementById('po_amount').textContent = 'TZS ' + parseFloat(data.total_amount).toLocaleString();
    document.getElementById('po_items').textContent = data.item_count + ' items';
    document.getElementById('po_delivery').textContent = data.delivery_date ? new Date(data.delivery_date).toLocaleDateString() : 'N/A';
    
    if (data.notes) {
        document.getElementById('po_notes_section').style.display = 'block';
        document.getElementById('po_notes').textContent = data.notes;
    } else {
        document.getElementById('po_notes_section').style.display = 'none';
    }
    
    const modal = new bootstrap.Modal(document.getElementById('purchaseModal'));
    modal.show();
}

// View Payroll Details
function viewPayrollDetails(data) {
    const monthName = new Date(data.payroll_year, data.payroll_month - 1).toLocaleDateString('en-US', {month: 'long', year: 'numeric'});
    document.getElementById('pr_period').textContent = monthName;
    document.getElementById('pr_employees').textContent = data.employee_count + ' employees';
    document.getElementById('pr_amount').textContent = 'TZS ' + parseFloat(data.total_amount).toLocaleString();
    document.getElementById('pr_payment_date').textContent = data.payment_date ? new Date(data.payment_date).toLocaleDateString() : 'Not set';
    
    const modal = new bootstrap.Modal(document.getElementById('payrollModal'));
    modal.show();
}

// Approve Record
function approveRecord(recordId, approvalType, recordNumber) {
    document.getElementById('approval_record_id').value = recordId;
    document.getElementById('approval_type').value = approvalType;
    document.getElementById('approval_record_number').textContent = recordNumber;
    
    const modal = new bootstrap.Modal(document.getElementById('approvalModal'));
    modal.show();
}

// Reject Record
function rejectRecord(recordId, approvalType, recordNumber) {
    document.getElementById('rejection_record_id').value = recordId;
    document.getElementById('rejection_type').value = approvalType;
    document.getElementById('rejection_record_number').textContent = recordNumber;
    
    const modal = new bootstrap.Modal(document.getElementById('rejectionModal'));
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