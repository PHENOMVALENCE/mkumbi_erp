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
$user_id = $_SESSION['user_id'];

$errors = [];
$success = '';

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $payment_id = intval($_POST['payment_id']);
    $action = $_POST['action'];
    
    try {
        $conn->beginTransaction();
        
        if ($action === 'approve') {
            $sql = "UPDATE payments 
                    SET status = 'approved',
                        approved_by = ?,
                        approved_at = NOW()
                    WHERE payment_id = ? 
                    AND company_id = ? 
                    AND status = 'pending_approval'";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id, $payment_id, $company_id]);
            
            if ($stmt->rowCount() > 0) {
                $success = "Payment approved successfully!";
            } else {
                $errors[] = "Payment not found or already processed.";
            }
            
        } elseif ($action === 'reject') {
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            
            if (empty($rejection_reason)) {
                $errors[] = "Rejection reason is required.";
            } else {
                $sql = "UPDATE payments 
                        SET status = 'rejected',
                            rejection_reason = ?,
                            rejected_by = ?,
                            rejected_at = NOW()
                        WHERE payment_id = ? 
                        AND company_id = ? 
                        AND status = 'pending_approval'";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([$rejection_reason, $user_id, $payment_id, $company_id]);
                
                if ($stmt->rowCount() > 0) {
                    $success = "Payment rejected successfully!";
                } else {
                    $errors[] = "Payment not found or already processed.";
                }
            }
        }
        
        $conn->commit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Payment approval error: " . $e->getMessage());
        $errors[] = "Database error: " . $e->getMessage();
    }
}

// Fetch pending payments
try {
    $sql = "SELECT * FROM v_pending_payment_approvals 
            WHERE company_id = ? 
            ORDER BY submitted_at ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$company_id]);
    $pending_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching pending payments: " . $e->getMessage());
    $pending_payments = [];
}

$page_title = 'Payment Approvals';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - ERP System</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f6f9;
            padding: 20px;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .payment-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .payment-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .badge-pending {
            background: #ffc107;
            color: #000;
        }
        .btn-approve {
            background: #28a745;
            color: white;
        }
        .btn-reject {
            background: #dc3545;
            color: white;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col-12">
            <h1><i class="fas fa-check-circle"></i> Payment Approvals</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Payments</a></li>
                    <li class="breadcrumb-item active">Approvals</li>
                </ol>
            </nav>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>

    <div class="stats-card">
        <div class="row">
            <div class="col-md-4">
                <h3><?php echo count($pending_payments); ?></h3>
                <p>Pending Approvals</p>
            </div>
            <div class="col-md-4">
                <h3>TZS <?php echo number_format(array_sum(array_column($pending_payments, 'amount')), 2); ?></h3>
                <p>Total Amount</p>
            </div>
            <div class="col-md-4">
                <h3><?php echo !empty($pending_payments) ? max(array_column($pending_payments, 'days_pending')) : 0; ?></h3>
                <p>Oldest (days)</p>
            </div>
        </div>
    </div>

    <div class="row">
        <?php if (empty($pending_payments)): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No payments pending approval.
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($pending_payments as $payment): ?>
            <div class="col-md-6">
                <div class="payment-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($payment['payment_number']); ?></h5>
                            <span class="badge badge-pending">Pending Approval</span>
                        </div>
                        <div class="text-end">
                            <h4 class="text-success mb-0">TZS <?php echo number_format($payment['amount'], 2); ?></h4>
                            <small class="text-muted"><?php echo htmlspecialchars($payment['payment_type']); ?></small>
                        </div>
                    </div>

                    <table class="table table-sm table-borderless mb-3">
                        <tr>
                            <td><strong>Customer:</strong></td>
                            <td><?php echo htmlspecialchars($payment['customer_name']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Phone:</strong></td>
                            <td><?php echo htmlspecialchars($payment['phone']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Reservation:</strong></td>
                            <td><?php echo htmlspecialchars($payment['reservation_number']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Plot:</strong></td>
                            <td><?php echo htmlspecialchars($payment['plot_number']); ?> - <?php echo htmlspecialchars($payment['project_name']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Payment Date:</strong></td>
                            <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Method:</strong></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                        </tr>
                        <?php if (!empty($payment['bank_name'])): ?>
                        <tr>
                            <td><strong>Bank:</strong></td>
                            <td><?php echo htmlspecialchars($payment['bank_name']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($payment['transaction_reference'])): ?>
                        <tr>
                            <td><strong>Reference:</strong></td>
                            <td><?php echo htmlspecialchars($payment['transaction_reference']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Submitted By:</strong></td>
                            <td><?php echo htmlspecialchars($payment['submitted_by_name']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Submitted:</strong></td>
                            <td><?php echo date('d M Y H:i', strtotime($payment['submitted_at'])); ?> 
                                (<?php echo $payment['days_pending']; ?> days ago)</td>
                        </tr>
                        <?php if (!empty($payment['remarks'])): ?>
                        <tr>
                            <td><strong>Remarks:</strong></td>
                            <td><?php echo htmlspecialchars($payment['remarks']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-approve flex-fill" 
                                onclick="approvePayment(<?php echo $payment['payment_id']; ?>)">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="btn btn-reject flex-fill" 
                                data-bs-toggle="modal" 
                                data-bs-target="#rejectModal<?php echo $payment['payment_id']; ?>">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        <a href="view.php?id=<?php echo $payment['payment_id']; ?>" 
                           class="btn btn-secondary">
                            <i class="fas fa-eye"></i>
                        </a>
                    </div>
                </div>

                <!-- Reject Modal -->
                <div class="modal fade" id="rejectModal<?php echo $payment['payment_id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header">
                                    <h5 class="modal-title">Reject Payment</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="payment_id" value="<?php echo $payment['payment_id']; ?>">
                                    <input type="hidden" name="action" value="reject">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                                        <textarea name="rejection_reason" class="form-control" rows="4" required 
                                                  placeholder="Please provide a reason for rejecting this payment..."></textarea>
                                    </div>

                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        <strong>Warning:</strong> This action will reject the payment and notify the customer.
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-danger">Reject Payment</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Approve Form (hidden) -->
<form id="approveForm" method="POST" style="display: none;">
    <input type="hidden" name="payment_id" id="approve_payment_id">
    <input type="hidden" name="action" value="approve">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function approvePayment(paymentId) {
    if (confirm('Are you sure you want to approve this payment?')) {
        document.getElementById('approve_payment_id').value = paymentId;
        document.getElementById('approveForm').submit();
    }
}
</script>

</body>
</html>