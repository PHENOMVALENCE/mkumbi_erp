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

$customer_id = $_GET['customer_id'] ?? null;

if (!$customer_id) {
    $_SESSION['error_message'] = "No customer specified";
    header("Location: statements.php");
    exit();
}

// Get customer details
try {
    $customer_sql = "SELECT customer_id, full_name as customer_name,
                            phone, phone1, email, region, district, ward, street_address
                     FROM customers 
                     WHERE customer_id = ? AND company_id = ? AND is_active = 1";
    $customer_stmt = $conn->prepare($customer_sql);
    $customer_stmt->execute([$customer_id, $company_id]);
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        $_SESSION['error_message'] = "Customer not found";
        header("Location: statements.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Customer fetch error: " . $e->getMessage());
    $_SESSION['error_message'] = "Failed to load customer details";
    header("Location: statements.php");
    exit();
}

// Get all reservations for this customer - EXACT SAME QUERY AS PRINT
try {
    $reservations_sql = "SELECT 
        r.reservation_id,
        r.reservation_date,
        r.reservation_number,
        r.total_amount,
        pl.plot_number,
        pl.block_number,
        pr.project_name,
        COALESCE(SUM(p.amount), 0) as total_paid
    FROM reservations r
    INNER JOIN plots pl ON r.plot_id = pl.plot_id
    INNER JOIN projects pr ON pl.project_id = pr.project_id
    LEFT JOIN payments p ON r.reservation_id = p.reservation_id AND p.status = 'approved'
    WHERE r.customer_id = ? AND r.company_id = ? AND r.is_active = 1
    GROUP BY r.reservation_id, r.reservation_date, r.reservation_number, r.total_amount,
             pl.plot_number, pl.block_number, pr.project_name
    ORDER BY r.reservation_date DESC";
    
    $res_stmt = $conn->prepare($reservations_sql);
    $res_stmt->execute([$customer_id, $company_id]);
    $reservations = $res_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Reservations fetch error: " . $e->getMessage());
    $reservations = [];
}

// Get all payments for this customer - EXACT SAME QUERY AS PRINT
try {
    $payments_sql = "SELECT 
        p.payment_id,
        p.payment_date,
        p.payment_number,
        p.amount,
        p.payment_method,
        p.receipt_number,
        p.transaction_reference,
        p.status,
        r.reservation_number,
        CONCAT('Plot ', pl.plot_number, 
               CASE WHEN pl.block_number IS NOT NULL THEN CONCAT(' Block ', pl.block_number) ELSE '' END) as plot_info
    FROM payments p
    INNER JOIN reservations r ON p.reservation_id = r.reservation_id
    INNER JOIN plots pl ON r.plot_id = pl.plot_id
    WHERE r.customer_id = ? AND r.company_id = ?
    ORDER BY p.payment_date DESC";
    
    $pay_stmt = $conn->prepare($payments_sql);
    $pay_stmt->execute([$customer_id, $company_id]);
    $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Payments fetch error: " . $e->getMessage());
    $payments = [];
}

// Calculate statistics - EXACT SAME AS PRINT
$total_amount = array_sum(array_column($reservations, 'total_amount'));
$total_credits = array_sum(array_column($payments, 'amount'));
$closing_balance = $total_amount - $total_credits;
$total_reservations = count($reservations);

$page_title = 'Customer Statement';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 0.875rem 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid;
    height: 100%;
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.warning { border-left-color: #ffc107; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.15rem;
    line-height: 1;
}

.stats-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #6c757d;
    font-weight: 600;
}

.customer-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.customer-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.customer-meta {
    font-size: 0.9rem;
    opacity: 0.95;
}

.table-professional {
    font-size: 0.85rem;
}

.table-professional thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #495057;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.3px;
    padding: 0.65rem 0.5rem;
    white-space: nowrap;
}

.table-professional tbody td {
    padding: 0.65rem 0.5rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.table-professional tbody tr:hover {
    background-color: #f8f9fa;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.approved { background: #d4edda; color: #155724; }
.status-badge.pending { background: #fff3cd; color: #856404; }
.status-badge.active { background: #d1ecf1; color: #0c5460; }
.status-badge.completed { background: #d4edda; color: #155724; }

.payment-number {
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">Customer Statement</h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="statements.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-arrow-left me-1"></i>Back to Statements
                </a>
                <a href="print_statement.php?customer_id=<?= $customer_id ?>" 
                   class="btn btn-info btn-sm" 
                   target="_blank">
                    <i class="fas fa-print me-1"></i>Print Statement
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Customer Header Card -->
    <div class="customer-header">
        <div class="row">
            <div class="col-md-8">
                <div class="customer-title"><?= htmlspecialchars($customer['customer_name']) ?></div>
                <div class="customer-meta">
                    <?php if ($customer['phone'] || $customer['phone1']): ?>
                        <div><strong>Phone:</strong> <?= htmlspecialchars($customer['phone'] ?? $customer['phone1']) ?></div>
                    <?php endif; ?>
                    <?php if ($customer['email']): ?>
                        <div><strong>Email:</strong> <?= htmlspecialchars($customer['email']) ?></div>
                    <?php endif; ?>
                    <?php if ($customer['street_address']): ?>
                        <div><strong>Address:</strong> <?= htmlspecialchars($customer['street_address']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div style="font-size: 0.9rem; margin-bottom: 0.5rem;">
                    <strong>Statement Date</strong>
                </div>
                <div style="font-size: 1.5rem; font-weight: 700;">
                    <?= date('M d, Y') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($total_reservations) ?></div>
                <div class="stats-label">Total Reservations</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card danger">
                <div class="stats-number"><?= number_format($total_amount, 0) ?></div>
                <div class="stats-label">Total Amount (TZS)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($total_credits, 0) ?></div>
                <div class="stats-label">Total Paid (TZS)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format($closing_balance, 0) ?></div>
                <div class="stats-label">Balance Due (TZS)</div>
            </div>
        </div>
    </div>

    <!-- Reservations Section -->
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="card-title mb-3">Reservations</h5>
            
            <?php if (empty($reservations)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-contract fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No reservations found for this customer</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reservation #</th>
                                <th>Plot/Project</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $res): ?>
                                <?php $balance = $res['total_amount'] - $res['total_paid']; ?>
                                <tr>
                                    <td><?= date('M d, Y', strtotime($res['reservation_date'])) ?></td>
                                    <td>
                                        <span class="payment-number">
                                            <?= htmlspecialchars($res['reservation_number']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold">
                                            Plot <?= htmlspecialchars($res['plot_number']) ?>
                                            <?php if ($res['block_number']): ?>
                                                Block <?= htmlspecialchars($res['block_number']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?= htmlspecialchars($res['project_name']) ?></small>
                                    </td>
                                    <td class="text-end">
                                        <span style="font-weight: 600;">
                                            <?= number_format($res['total_amount'], 0) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-success" style="font-weight: 600;">
                                            <?= number_format($res['total_paid'], 0) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <strong class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= number_format($balance, 0) ?>
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background: #f8f9fa; font-weight: 700;">
                            <tr>
                                <td colspan="3" class="text-end">TOTALS:</td>
                                <td class="text-end"><?= number_format($total_amount, 0) ?></td>
                                <td class="text-end text-success"><?= number_format($total_credits, 0) ?></td>
                                <td class="text-end text-danger"><?= number_format($closing_balance, 0) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payments Section -->
    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-3">Payment History</h5>
            
            <?php if (empty($payments)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No payments recorded yet</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="paymentsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payment #</th>
                                <th>Reservation #</th>
                                <th>Plot</th>
                                <th class="text-end">Amount</th>
                                <th>Method</th>
                                <th>Receipt #</th>
                                <th>Reference</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $pay): ?>
                                <tr>
                                    <td><?= date('M d, Y', strtotime($pay['payment_date'])) ?></td>
                                    <td>
                                        <span class="payment-number">
                                            <?= htmlspecialchars($pay['payment_number']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($pay['reservation_number']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($pay['plot_info']) ?></small>
                                    </td>
                                    <td class="text-end">
                                        <span class="text-success" style="font-weight: 600;">
                                            <?= number_format($pay['amount'], 0) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?= ucfirst(str_replace('_', ' ', $pay['payment_method'])) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($pay['receipt_number']): ?>
                                            <span class="payment-number">
                                                <?= htmlspecialchars($pay['receipt_number']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($pay['transaction_reference']): ?>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($pay['transaction_reference']) ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $pay['status'] ?>">
                                            <?= ucfirst($pay['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- DataTables -->
                <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
                <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

                <script>
                $(document).ready(function() {
                    $('#paymentsTable').DataTable({
                        pageLength: 25,
                        order: [[0, 'desc']],
                        columnDefs: [
                            { targets: [4], className: 'text-end' },
                            { targets: 8, orderable: false }
                        ]
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>