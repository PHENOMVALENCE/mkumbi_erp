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

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

if (!$transaction_id) {
    header("Location: index.php");
    exit;
}

// Check for success message from session
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// ==================== HANDLE APPROVAL ACTIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $conn->beginTransaction();
        
        $action = $_POST['action'];
        
        if ($action === 'approve') {
            $stmt = $conn->prepare("
                UPDATE petty_cash_transactions 
                SET approval_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    approval_notes = ?
                WHERE transaction_id = ? AND company_id = ? AND approval_status = 'pending'
            ");
            $stmt->execute([$user_id, trim($_POST['approval_notes']), $transaction_id, $company_id]);
            $success = "Transaction approved successfully!";
            
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("
                UPDATE petty_cash_transactions 
                SET approval_status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW(),
                    approval_notes = ?
                WHERE transaction_id = ? AND company_id = ? AND approval_status = 'pending'
            ");
            $stmt->execute([$user_id, trim($_POST['approval_notes']), $transaction_id, $company_id]);
            $success = "Transaction rejected.";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
        error_log("Approval action error: " . $e->getMessage());
    }
}

// ==================== FETCH TRANSACTION DETAILS ====================
try {
    $stmt = $conn->prepare("
        SELECT 
            pc.*,
            c.category_name,
            c.category_code,
            custodian.full_name as custodian_name,
            custodian.phone1 as custodian_phone,
            custodian.email as custodian_email,
            dept.department_name as custodian_department,
            created_user.full_name as created_by_name,
            approved_user.full_name as approved_by_name
        FROM petty_cash_transactions pc
        LEFT JOIN petty_cash_categories c ON pc.category_id = c.category_id
        LEFT JOIN users custodian ON pc.custodian_id = custodian.user_id
        LEFT JOIN employees emp ON custodian.user_id = emp.user_id
        LEFT JOIN departments dept ON emp.department_id = dept.department_id
        LEFT JOIN users created_user ON pc.created_by = created_user.user_id
        LEFT JOIN users approved_user ON pc.approved_by = approved_user.user_id
        WHERE pc.transaction_id = ? AND pc.company_id = ?
    ");
    $stmt->execute([$transaction_id, $company_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        header("Location: index.php?error=" . urlencode("Transaction not found"));
        exit;
    }
} catch (Exception $e) {
    error_log("Transaction fetch error: " . $e->getMessage());
    header("Location: index.php?error=" . urlencode("Failed to load transaction"));
    exit;
}

// ==================== CALCULATE RUNNING BALANCE ====================
try {
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE 
                WHEN transaction_type = 'replenishment' THEN amount
                WHEN transaction_type = 'expense' THEN -amount
                WHEN transaction_type = 'return' THEN -amount
                ELSE 0 
            END), 0) as balance_before_this
        FROM petty_cash_transactions
        WHERE company_id = ? 
        AND transaction_date < ?
        AND approval_status = 'approved'
    ");
    $stmt->execute([$company_id, $transaction['transaction_date']]);
    $balance_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $balance_before = $balance_result['balance_before_this'];
    
    // Calculate balance after this transaction
    $transaction_impact = 0;
    if ($transaction['transaction_type'] == 'replenishment') {
        $transaction_impact = $transaction['amount'];
    } elseif ($transaction['transaction_type'] == 'expense') {
        $transaction_impact = -$transaction['amount'];
    } elseif ($transaction['transaction_type'] == 'return') {
        $transaction_impact = -$transaction['amount'];
    }
    
    $balance_after = $balance_before + $transaction_impact;
} catch (Exception $e) {
    error_log("Balance calculation error: " . $e->getMessage());
    $balance_before = 0;
    $balance_after = 0;
}

$page_title = 'View Transaction - ' . $transaction['reference_number'];
require_once '../../includes/header.php';
?>

<style>
.transaction-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.reference-number {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    font-family: 'Courier New', monospace;
}

.transaction-type {
    font-size: 1.25rem;
    opacity: 0.95;
}

.info-card {
    background: #fff;
    border-radius: 6px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #007bff;
}

.info-card h5 {
    font-size: 1rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
}

.info-row {
    display: flex;
    padding: 0.6rem 0;
    border-bottom: 1px solid #f8f9fa;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    flex: 0 0 180px;
    font-weight: 600;
    color: #6c757d;
    font-size: 0.85rem;
}

.info-value {
    flex: 1;
    color: #2c3e50;
    font-size: 0.85rem;
}

.type-badge {
    display: inline-block;
    padding: 0.4rem 0.8rem;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.type-badge.replenishment {
    background: #d4edda;
    color: #155724;
}

.type-badge.expense {
    background: #f8d7da;
    color: #721c24;
}

.type-badge.return {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge {
    display: inline-block;
    padding: 0.4rem 0.8rem;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.status-badge.approved {
    background: #d4edda;
    color: #155724;
}

.status-badge.rejected {
    background: #f8d7da;
    color: #721c24;
}

.amount-card {
    background: #fff;
    border-radius: 6px;
    padding: 1.5rem;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-top: 3px solid #007bff;
}

.amount-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.amount-value.positive {
    color: #28a745;
}

.amount-value.negative {
    color: #dc3545;
}

.amount-value.neutral {
    color: #17a2b8;
}

.amount-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.receipt-preview {
    border: 2px dashed #dee2e6;
    border-radius: 6px;
    padding: 1rem;
    text-align: center;
    background: #f8f9fa;
}

.receipt-preview img {
    max-width: 100%;
    height: auto;
    border-radius: 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.approval-section {
    background: #fff3cd;
    border: 2px solid #ffc107;
    border-radius: 6px;
    padding: 1.5rem;
    margin-bottom: 1rem;
}

.approval-actions {
    display: flex;
    gap: 1rem;
    margin-top: 1rem;
}

.timeline-item {
    padding-left: 2rem;
    position: relative;
    padding-bottom: 1rem;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item::after {
    content: '';
    position: absolute;
    left: -4px;
    top: 8px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #007bff;
    border: 2px solid white;
}

.timeline-item:last-child::before {
    display: none;
}

@media (max-width: 768px) {
    .approval-actions {
        flex-direction: column;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-file-invoice me-2"></i>Transaction Details
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <?php if ($transaction['approval_status'] == 'pending'): ?>
                <a href="edit.php?id=<?= $transaction_id ?>" class="btn btn-warning btn-sm">
                    <i class="fas fa-edit me-1"></i>Edit
                </a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to List
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Transaction Header -->
    <div class="transaction-header">
        <div class="reference-number"><?= htmlspecialchars($transaction['reference_number']) ?></div>
        <div class="transaction-type">
            <span class="type-badge <?= $transaction['transaction_type'] ?>">
                <?= ucfirst($transaction['transaction_type']) ?>
            </span>
            <span class="status-badge <?= $transaction['approval_status'] ?> ms-2">
                <?= ucfirst($transaction['approval_status']) ?>
            </span>
        </div>
    </div>

    <!-- Approval Section (for pending transactions) -->
    <?php if ($transaction['approval_status'] == 'pending'): ?>
    <div class="approval-section">
        <h5 style="margin-bottom: 1rem;">
            <i class="fas fa-clock me-2"></i>Pending Approval
        </h5>
        <p style="margin-bottom: 1rem;">This transaction is awaiting approval. Review the details below and approve or reject.</p>
        
        <form method="POST" id="approvalForm">
            <div class="mb-3">
                <label class="form-label">Approval Notes (Optional)</label>
                <textarea name="approval_notes" class="form-control" rows="2" 
                          placeholder="Add any comments about this approval/rejection..."></textarea>
            </div>
            
            <div class="approval-actions">
                <button type="submit" name="action" value="approve" class="btn btn-success flex-fill"
                        onclick="return confirm('Are you sure you want to approve this transaction?')">
                    <i class="fas fa-check-circle me-2"></i>Approve Transaction
                </button>
                <button type="submit" name="action" value="reject" class="btn btn-danger flex-fill"
                        onclick="return confirm('Are you sure you want to reject this transaction?')">
                    <i class="fas fa-times-circle me-2"></i>Reject Transaction
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Left Column -->
        <div class="col-md-8">
            
            <!-- Transaction Information -->
            <div class="info-card">
                <h5><i class="fas fa-info-circle me-2"></i>Transaction Information</h5>
                <div class="info-row">
                    <div class="info-label">Reference Number</div>
                    <div class="info-value">
                        <strong style="font-family: 'Courier New', monospace;">
                            <?= htmlspecialchars($transaction['reference_number']) ?>
                        </strong>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Transaction Date</div>
                    <div class="info-value">
                        <?= date('l, F j, Y', strtotime($transaction['transaction_date'])) ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Transaction Type</div>
                    <div class="info-value">
                        <span class="type-badge <?= $transaction['transaction_type'] ?>">
                            <?= ucfirst($transaction['transaction_type']) ?>
                        </span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Category</div>
                    <div class="info-value">
                        <?php if ($transaction['category_name']): ?>
                            <?= htmlspecialchars($transaction['category_name']) ?>
                            <?php if ($transaction['category_code']): ?>
                                <span class="text-muted">(<?= htmlspecialchars($transaction['category_code']) ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Not categorized</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Description</div>
                    <div class="info-value">
                        <strong><?= nl2br(htmlspecialchars($transaction['description'])) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Payment Details -->
            <div class="info-card">
                <h5><i class="fas fa-credit-card me-2"></i>Payment Details</h5>
                <div class="info-row">
                    <div class="info-label">
                        <?php if ($transaction['transaction_type'] == 'replenishment'): ?>
                            Source
                        <?php elseif ($transaction['transaction_type'] == 'return'): ?>
                            Returned To
                        <?php else: ?>
                            Payee/Vendor
                        <?php endif; ?>
                    </div>
                    <div class="info-value">
                        <?= htmlspecialchars($transaction['payee'] ?: '-') ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Payment Method</div>
                    <div class="info-value">
                        <?= ucfirst(str_replace('_', ' ', $transaction['payment_method'])) ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Receipt Number</div>
                    <div class="info-value">
                        <?= htmlspecialchars($transaction['receipt_number'] ?: '-') ?>
                    </div>
                </div>
                <?php if ($transaction['account_code']): ?>
                <div class="info-row">
                    <div class="info-label">Account Code</div>
                    <div class="info-value">
                        <code><?= htmlspecialchars($transaction['account_code']) ?></code>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Custodian Information -->
            <div class="info-card">
                <h5><i class="fas fa-user me-2"></i>Custodian Information</h5>
                <div class="info-row">
                    <div class="info-label">Name</div>
                    <div class="info-value">
                        <strong><?= htmlspecialchars($transaction['custodian_name']) ?></strong>
                    </div>
                </div>
                <?php if ($transaction['custodian_department']): ?>
                <div class="info-row">
                    <div class="info-label">Department</div>
                    <div class="info-value">
                        <?= htmlspecialchars($transaction['custodian_department']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($transaction['custodian_email']): ?>
                <div class="info-row">
                    <div class="info-label">Email</div>
                    <div class="info-value">
                        <a href="mailto:<?= htmlspecialchars($transaction['custodian_email']) ?>">
                            <?= htmlspecialchars($transaction['custodian_email']) ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($transaction['custodian_phone']): ?>
                <div class="info-row">
                    <div class="info-label">Phone</div>
                    <div class="info-value">
                        <a href="tel:<?= htmlspecialchars($transaction['custodian_phone']) ?>">
                            <?= htmlspecialchars($transaction['custodian_phone']) ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Receipt/Document -->
            <?php if ($transaction['receipt_path']): ?>
            <div class="info-card">
                <h5><i class="fas fa-file-invoice me-2"></i>Receipt/Supporting Document</h5>
                <div class="receipt-preview">
                    <?php
                    $file_extension = strtolower(pathinfo($transaction['receipt_path'], PATHINFO_EXTENSION));
                    if (in_array($file_extension, ['jpg', 'jpeg', 'png'])):
                    ?>
                        <img src="<?= htmlspecialchars($transaction['receipt_path']) ?>" alt="Receipt">
                        <div class="mt-3">
                            <a href="<?= htmlspecialchars($transaction['receipt_path']) ?>" 
                               target="_blank" class="btn btn-primary btn-sm">
                                <i class="fas fa-external-link-alt me-1"></i>Open in New Tab
                            </a>
                        </div>
                    <?php else: ?>
                        <i class="fas fa-file-pdf fa-4x text-danger mb-3"></i>
                        <p class="mb-2"><strong>PDF Document</strong></p>
                        <a href="<?= htmlspecialchars($transaction['receipt_path']) ?>" 
                           target="_blank" class="btn btn-primary btn-sm">
                            <i class="fas fa-download me-1"></i>Download Receipt
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Additional Notes -->
            <?php if ($transaction['notes']): ?>
            <div class="info-card">
                <h5><i class="fas fa-sticky-note me-2"></i>Additional Notes</h5>
                <p style="white-space: pre-wrap; margin-bottom: 0;">
                    <?= nl2br(htmlspecialchars($transaction['notes'])) ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Approval Information -->
            <?php if ($transaction['approval_status'] != 'pending'): ?>
            <div class="info-card" style="border-left-color: <?= $transaction['approval_status'] == 'approved' ? '#28a745' : '#dc3545' ?>;">
                <h5><i class="fas fa-<?= $transaction['approval_status'] == 'approved' ? 'check' : 'times' ?>-circle me-2"></i>
                    Approval Information
                </h5>
                <div class="info-row">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="status-badge <?= $transaction['approval_status'] ?>">
                            <?= ucfirst($transaction['approval_status']) ?>
                        </span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">
                        <?= $transaction['approval_status'] == 'approved' ? 'Approved' : 'Rejected' ?> By
                    </div>
                    <div class="info-value">
                        <?= htmlspecialchars($transaction['approved_by_name']) ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">
                        <?= $transaction['approval_status'] == 'approved' ? 'Approved' : 'Rejected' ?> At
                    </div>
                    <div class="info-value">
                        <?= date('l, F j, Y - H:i', strtotime($transaction['approved_at'])) ?>
                    </div>
                </div>
                <?php if ($transaction['approval_notes']): ?>
                <div class="info-row">
                    <div class="info-label">Notes</div>
                    <div class="info-value">
                        <?= nl2br(htmlspecialchars($transaction['approval_notes'])) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- Right Column -->
        <div class="col-md-4">
            
            <!-- Amount -->
            <div class="amount-card" style="border-top-color: <?= $transaction['transaction_type'] == 'replenishment' ? '#28a745' : ($transaction['transaction_type'] == 'expense' ? '#dc3545' : '#17a2b8') ?>;">
                <div class="amount-value <?= $transaction['transaction_type'] == 'replenishment' ? 'positive' : ($transaction['transaction_type'] == 'expense' ? 'negative' : 'neutral') ?>">
                    <?php
                    $sign = '';
                    if ($transaction['transaction_type'] == 'replenishment') $sign = '+';
                    elseif ($transaction['transaction_type'] == 'expense') $sign = '-';
                    elseif ($transaction['transaction_type'] == 'return') $sign = '-';
                    ?>
                    <?= $sign ?>TSH <?= number_format($transaction['amount'], 2) ?>
                </div>
                <div class="amount-label">Transaction Amount</div>
            </div>

            <!-- Balance Impact (for approved transactions) -->
            <?php if ($transaction['approval_status'] == 'approved'): ?>
            <div class="info-card" style="border-left-color: #17a2b8;">
                <h5><i class="fas fa-balance-scale me-2"></i>Balance Impact</h5>
                <div class="info-row">
                    <div class="info-label">Balance Before</div>
                    <div class="info-value">
                        TSH <?= number_format($balance_before, 2) ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">This Transaction</div>
                    <div class="info-value" style="font-weight: 700; color: <?= $transaction_impact >= 0 ? '#28a745' : '#dc3545' ?>;">
                        <?= $transaction_impact >= 0 ? '+' : '' ?>TSH <?= number_format($transaction_impact, 2) ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Balance After</div>
                    <div class="info-value">
                        <strong>TSH <?= number_format($balance_after, 2) ?></strong>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Timeline -->
            <div class="info-card">
                <h5><i class="fas fa-history me-2"></i>Timeline</h5>
                
                <div class="timeline-item">
                    <small class="text-muted">
                        <?= date('M j, Y H:i', strtotime($transaction['created_at'])) ?>
                    </small>
                    <p style="margin-bottom: 0; margin-top: 0.25rem; font-size: 0.875rem;">
                        <strong>Created</strong> by <?= htmlspecialchars($transaction['created_by_name']) ?>
                    </p>
                </div>
                
                <?php if ($transaction['approval_status'] != 'pending'): ?>
                <div class="timeline-item">
                    <small class="text-muted">
                        <?= date('M j, Y H:i', strtotime($transaction['approved_at'])) ?>
                    </small>
                    <p style="margin-bottom: 0; margin-top: 0.25rem; font-size: 0.875rem;">
                        <strong><?= ucfirst($transaction['approval_status']) ?></strong> by 
                        <?= htmlspecialchars($transaction['approved_by_name']) ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>