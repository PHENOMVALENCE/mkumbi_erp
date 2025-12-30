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

// Handle payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    try {
        $conn->beginTransaction();
        
        $commission_id_to_pay = $_POST['commission_id'];
        
        // Check if commission is approved
        $check_sql = "SELECT payment_status, COALESCE(balance, 0) as balance, 
                     commission_number, entitled_amount, COALESCE(total_paid, 0) as total_paid 
                     FROM commissions WHERE commission_id = ? AND company_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([$commission_id_to_pay, $company_id]);
        $comm = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($comm['payment_status'] !== 'approved') {
            throw new Exception("Can only record payments for approved commissions");
        }
        
        $payment_amount = floatval($_POST['payment_amount']);
        
        if ($payment_amount <= 0) {
            throw new Exception("Payment amount must be greater than zero");
        }
        
        if ($payment_amount > $comm['balance']) {
            throw new Exception("Payment amount cannot exceed balance: TZS " . number_format($comm['balance'], 2));
        }
        
        // Generate payment number
        $year = date('Y');
        $count_sql = "SELECT COUNT(*) FROM commission_payments WHERE company_id = ? AND YEAR(payment_date) = ?";
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->execute([$company_id, $year]);
        $count = $count_stmt->fetchColumn() + 1;
        $payment_number = 'PAY-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
        
        // Insert payment
        $insert_payment_sql = "INSERT INTO commission_payments (
            commission_id, company_id, payment_number, payment_date, payment_amount,
            payment_method, reference_number, bank_account_id, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $insert_payment_stmt = $conn->prepare($insert_payment_sql);
        $insert_payment_stmt->execute([
            $commission_id_to_pay,
            $company_id,
            $payment_number,
            $_POST['payment_date'],
            $payment_amount,
            $_POST['payment_method'],
            $_POST['reference_number'] ?? null,
            !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null,
            $_POST['payment_notes'] ?? null,
            $user_id
        ]);
        
        // Update commission totals
        $new_total_paid = $comm['total_paid'] + $payment_amount;
        $new_balance = $comm['balance'] - $payment_amount;
        $new_status = ($new_balance <= 0.01) ? 'paid' : 'approved';
        
        $update_comm_sql = "UPDATE commissions SET
            total_paid = ?, balance = ?, payment_status = ?,
            paid_by = ?, paid_at = NOW(), updated_at = NOW()
            WHERE commission_id = ? AND company_id = ?";
        
        $update_comm_stmt = $conn->prepare($update_comm_sql);
        $update_comm_stmt->execute([
            $new_total_paid,
            $new_balance,
            $new_status,
            $user_id,
            $commission_id_to_pay,
            $company_id
        ]);
        
        $conn->commit();
        $_SESSION['success'] = "Payment of TZS " . number_format($payment_amount, 2) . " recorded successfully!";
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}

// Get commission ID or user ID from query string
$commission_id = $_GET['commission_id'] ?? null;
$user_id_filter = $_GET['user_id'] ?? null;

if (!$commission_id && !$user_id_filter) {
    die("Error: Commission ID or User ID required");
}

// Fetch commissions
if ($commission_id) {
    // Single commission statement
    $sql = "SELECT c.*, COALESCE(u.full_name, c.recipient_name) as recipient_display_name,
                   c.recipient_phone
            FROM commissions c
            LEFT JOIN users u ON c.user_id = u.user_id
            WHERE c.commission_id = ? AND c.company_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$commission_id, $company_id]);
    $main_commission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$main_commission) {
        die("Commission not found");
    }
    
    $recipient_name = $main_commission['recipient_display_name'];
    $recipient_phone = $main_commission['recipient_phone'];
    
    // Get all commissions for this recipient
    if ($main_commission['user_id']) {
        $where_clause = "c.user_id = ?";
        $param = $main_commission['user_id'];
    } else {
        $where_clause = "c.recipient_name = ? AND c.recipient_type != 'user'";
        $param = $main_commission['recipient_name'];
    }
} else {
    // All commissions for a user
    $user_sql = "SELECT full_name, phone1 FROM users WHERE user_id = ? AND company_id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->execute([$user_id_filter, $company_id]);
    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        die("User not found");
    }
    
    $recipient_name = $user_data['full_name'];
    $recipient_phone = $user_data['phone1'];
    $where_clause = "c.user_id = ?";
    $param = $user_id_filter;
}

// Fetch all commissions with details
$commissions_sql = "SELECT c.*,
                           COALESCE(c.plot_size_sqm, pl.area_sqm) as size_sqm,
                           r.reservation_number, r.reservation_date, r.total_amount as contract_value,
                           cust.full_name as customer_name,
                           pl.plot_number,
                           pr.project_name,
                           r.status as reservation_status
                    FROM commissions c
                    JOIN reservations r ON c.reservation_id = r.reservation_id
                    JOIN customers cust ON r.customer_id = cust.customer_id
                    LEFT JOIN plots pl ON r.plot_id = pl.plot_id
                    LEFT JOIN projects pr ON pl.project_id = pr.project_id
                    WHERE {$where_clause} AND c.company_id = ?
                    ORDER BY c.commission_date ASC";

$comm_stmt = $conn->prepare($commissions_sql);
$comm_stmt->execute([$param, $company_id]);
$commissions = $comm_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_commission = 0;
$total_tax = 0;
$total_entitled = 0;
$total_paid = 0;
$total_balance = 0;

foreach ($commissions as $c) {
    $total_commission += $c['commission_amount'];
    $total_tax += $c['withholding_tax_amount'];
    $total_entitled += $c['entitled_amount'];
    $total_paid += $c['total_paid'];
    $total_balance += $c['balance'];
}

// Fetch payments for each commission
$payments_by_commission = [];
foreach ($commissions as $c) {
    $payments_sql = "SELECT * FROM commission_payments 
                     WHERE commission_id = ? AND company_id = ?
                     ORDER BY payment_date ASC";
    $payments_stmt = $conn->prepare($payments_sql);
    $payments_stmt->execute([$c['commission_id'], $company_id]);
    $payments_by_commission[$c['commission_id']] = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get company information for print header (with fallback for missing columns)
try {
    $company_sql = "SELECT company_name";
    
    // Check if columns exist and add them to query
    $check_columns = ['address', 'phone', 'email', 'logo'];
    foreach ($check_columns as $col) {
        $col_check = $conn->query("SHOW COLUMNS FROM companies LIKE '{$col}'");
        if ($col_check->rowCount() > 0) {
            $company_sql .= ", {$col}";
        }
    }
    $company_sql .= " FROM companies WHERE company_id = ?";
    
    $company_stmt = $conn->prepare($company_sql);
    $company_stmt->execute([$company_id]);
    $company_info = $company_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set defaults for missing fields
    if (!isset($company_info['address'])) $company_info['address'] = '';
    if (!isset($company_info['phone'])) $company_info['phone'] = '';
    if (!isset($company_info['email'])) $company_info['email'] = '';
    if (!isset($company_info['logo'])) $company_info['logo'] = '';
    
} catch (Exception $e) {
    // Fallback if query fails
    $company_info = [
        'company_name' => 'Company Name',
        'address' => '',
        'phone' => '',
        'email' => '',
        'logo' => ''
    ];
}

$page_title = "Commission Statement";
require_once '../../includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

<style>
/* Responsive Design */
@media (max-width: 768px) {
    .statement-container {
        padding: 15px;
    }
    
    .statement-header h2 {
        font-size: 1.5rem;
    }
    
    .summary-item .value {
        font-size: 20px;
    }
    
    .summary-item .label {
        font-size: 11px;
    }
    
    .statement-table {
        font-size: 11px;
    }
    
    .statement-table th,
    .statement-table td {
        padding: 6px 4px;
    }
}

@media print {
    .no-print {
        display: none !important;
    }
    .print-only {
        display: block !important;
    }
    .statement-container {
        box-shadow: none !important;
        margin: 0 !important;
        padding: 20px !important;
    }
    body {
        background: white !important;
        margin: 0;
        padding: 0;
    }
    table {
        font-size: 10px !important;
        page-break-inside: auto;
    }
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    .print-header {
        margin-bottom: 30px;
        border-bottom: 3px solid #333;
        padding-bottom: 15px;
    }
    .company-logo {
        max-height: 80px;
        max-width: 200px;
    }
    .statement-header {
        border: none !important;
        padding: 0 !important;
    }
    /* Hide DataTables pagination when printing */
    .dataTables_wrapper .dataTables_paginate,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter {
        display: none !important;
    }
}

.print-only {
    display: none;
}

/* Print Header Styles */
.print-header {
    display: none;
}

@media print {
    .print-header {
        display: block !important;
    }
    .print-header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .company-info h1 {
        font-size: 24px;
        margin: 0 0 5px 0;
        color: #333;
    }
    .company-info p {
        margin: 2px 0;
        font-size: 11px;
        color: #666;
    }
}

.statement-container {
    background: white;
    padding: 30px;
    box-shadow: 0 0 20px rgba(0,0,0,0.1);
    margin: 20px auto;
    max-width: 100%;
}

@media (min-width: 1200px) {
    .statement-container {
        max-width: 1400px;
    }
}

.statement-header {
    border-bottom: 3px solid #0d6efd;
    padding-bottom: 20px;
    margin-bottom: 30px;
}

.statement-header h2 {
    color: #0d6efd;
    font-weight: 700;
    margin-bottom: 5px;
}

.summary-section {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
}

.summary-item {
    text-align: center;
    padding: 15px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.summary-item .label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
}

.summary-item .value {
    font-size: 24px;
    font-weight: 700;
    color: #212529;
}

.summary-item.highlight .value {
    color: #dc3545;
}

.statement-table {
    width: 100%;
    font-size: 12px;
    border-collapse: collapse;
}

.statement-table th {
    background: #0d6efd;
    color: white;
    padding: 10px 8px;
    text-align: left;
    font-weight: 600;
    font-size: 11px;
    white-space: nowrap;
}

.statement-table td {
    padding: 8px;
    border-bottom: 1px solid #dee2e6;
    vertical-align: top;
}

.statement-table tbody tr:hover {
    background: #f8f9fa;
}

.statement-table .text-end {
    text-align: right;
}

.statement-table .text-center {
    text-align: center;
}

.totals-row {
    background: #e7f3ff !important;
    font-weight: 700;
    font-size: 13px;
}

.totals-row td {
    border-top: 2px solid #0d6efd;
    border-bottom: 2px solid #0d6efd;
    padding: 12px 8px;
}

.payment-cell {
    font-size: 11px;
}

.payment-item {
    margin-bottom: 3px;
}

.status-active {
    color: #28a745;
    font-weight: 600;
}

.status-cancelled {
    color: #dc3545;
    font-weight: 600;
}

.payment-action-btn {
    padding: 4px 8px;
    font-size: 11px;
    margin-left: 5px;
    white-space: nowrap;
}

/* Print Preview Modal Styles */
#printPreviewModal .modal-body {
    background: #f5f5f5;
}

.print-preview-header {
    background: white;
    border-bottom: 3px solid #0d6efd;
}

#printPreviewContent {
    background: white;
}

#printPreviewContent .card {
    border: 2px solid #dee2e6;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

#printPreviewContent .table {
    font-size: 11px;
}

#printPreviewContent .table th {
    background-color: #0d6efd !important;
    color: white !important;
}

/* Payment Selection Modal */
.list-group-item:hover {
    background-color: #f8f9fa;
}

/* DataTables Responsive */
.dtr-details {
    width: 100%;
}

.dtr-details li {
    padding: 8px 0;
    border-bottom: 1px solid #dee2e6;
}

.dtr-title {
    font-weight: 600;
    color: #495057;
}
</style>

<div class="container-fluid">
    <div class="statement-container">
        
        <!-- Professional Print Header with Company Logo -->
        <div class="print-header">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <?php if (!empty($company_info['logo'])): ?>
                        <img src="../../uploads/company/<?= htmlspecialchars($company_info['logo']) ?>" 
                             alt="Company Logo" class="company-logo">
                    <?php endif; ?>
                </div>
                <div class="col-md-6 text-center">
                    <h1><?= htmlspecialchars($company_info['company_name'] ?? 'Company Name') ?></h1>
                    <?php if (!empty($company_info['address'])): ?>
                        <p><?= htmlspecialchars($company_info['address']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($company_info['phone']) || !empty($company_info['email'])): ?>
                        <p>
                            <?php if (!empty($company_info['phone'])): ?>
                                Tel: <?= htmlspecialchars($company_info['phone']) ?>
                            <?php endif; ?>
                            <?php if (!empty($company_info['phone']) && !empty($company_info['email'])): ?>
                                | 
                            <?php endif; ?>
                            <?php if (!empty($company_info['email'])): ?>
                                Email: <?= htmlspecialchars($company_info['email']) ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="col-md-3 text-end">
                    <h3>COMMISSION STATEMENT</h3>
                    <p><strong>Date:</strong> <?= date('d F Y') ?></p>
                </div>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <div class="no-print">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?= $_SESSION['success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
        </div>
        
        <!-- Action Buttons -->
        <div class="no-print mb-4">
            <div class="row g-2">
                <div class="col-12 col-md-auto">
                    <a href="index.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-arrow-left"></i> Back to Commissions
                    </a>
                </div>
                <div class="col-12 col-md text-md-end">
                    <?php 
                    // Check if there are any approved commissions with balance
                    $has_payable = false;
                    foreach ($commissions as $c) {
                        if ($c['payment_status'] === 'approved' && $c['balance'] > 0) {
                            $has_payable = true;
                            break;
                        }
                    }
                    if ($has_payable): 
                    ?>
                        <button class="btn btn-success w-100 w-md-auto mb-2 mb-md-0 me-md-2" onclick="showPaymentOptions()">
                            <i class="fas fa-money-bill-wave"></i> Record Payment
                        </button>
                    <?php endif; ?>
                    <button onclick="showPrintPreview()" class="btn btn-primary w-100 w-md-auto">
                        <i class="fas fa-print"></i> Print Statement
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Statement Header -->
        <div class="statement-header">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <h2 class="h4 h-md-2">COMMISSION STATEMENT</h2>
                    <p class="mb-1"><strong>Recipient:</strong> <?= htmlspecialchars(strtoupper($recipient_name)) ?></p>
                    <?php if ($recipient_phone): ?>
                    <p class="mb-1"><strong>Phone:</strong> <?= htmlspecialchars($recipient_phone) ?></p>
                    <?php endif; ?>
                    <p class="mb-0"><strong>Statement Date:</strong> <?= date('d F Y') ?></p>
                </div>
                <div class="col-12 col-md-6 text-md-end">
                    <p class="mb-1"><strong>Total Commissions:</strong> <?= count($commissions) ?></p>
                    <p class="mb-0"><strong>Period:</strong> 
                        <?php if (count($commissions) > 0): ?>
                            <?= date('d M Y', strtotime($commissions[0]['commission_date'])) ?> - 
                            <?= date('d M Y', strtotime(end($commissions)['commission_date'])) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Summary Section -->
        <div class="summary-section">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <div class="summary-item">
                        <div class="label">Total Commission</div>
                        <div class="value">TZS <?= number_format($total_commission, 2) ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="summary-item">
                        <div class="label">Commission Paid</div>
                        <div class="value text-success">TZS <?= number_format($total_paid, 2) ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="summary-item highlight">
                        <div class="label">Balance</div>
                        <div class="value">TZS <?= number_format($total_balance, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Detailed Statement Table -->
        <div class="table-responsive">
            <table class="statement-table" id="statementTable">
                <thead>
                <tr>
                    <th>Purchase Date</th>
                    <th>Client Name</th>
                    <th>Project</th>
                    <th class="text-end">Size (sqm)</th>
                    <th class="text-end">Contract Value</th>
                    <th class="text-center">Comm %</th>
                    <th class="text-end">Total Commission</th>
                    <th class="text-end">WHT Tax</th>
                    <th class="text-end">Entitled</th>
                    <th>Payments</th>
                    <th class="text-end">Total Paid</th>
                    <th class="text-end">Balance</th>
                    <th class="text-center">Status</th>
                    <th class="text-center no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($commissions as $c): 
                    $payments = $payments_by_commission[$c['commission_id']] ?? [];
                ?>
                <tr>
                    <td><?= date('d-M-y', strtotime($c['commission_date'])) ?></td>
                    <td><?= htmlspecialchars($c['customer_name']) ?></td>
                    <td><?= htmlspecialchars($c['project_name']) ?></td>
                    <td class="text-end"><?= number_format($c['size_sqm'], 0) ?></td>
                    <td class="text-end"><?= number_format($c['contract_value'], 0) ?></td>
                    <td class="text-center"><?= $c['commission_percentage'] ?>%</td>
                    <td class="text-end"><?= number_format($c['commission_amount'], 2) ?></td>
                    <td class="text-end"><?= number_format($c['withholding_tax_amount'], 2) ?></td>
                    <td class="text-end"><?= number_format($c['entitled_amount'], 2) ?></td>
                    <td class="payment-cell">
                        <?php if (count($payments) > 0): ?>
                            <?php foreach ($payments as $idx => $p): ?>
                                <div class="payment-item">
                                    Payment <?= ($idx + 1) ?>: TZS <?= number_format($p['payment_amount'], 2) ?>
                                    <small class="text-muted">(<?= date('d-M-y', strtotime($p['payment_date'])) ?>)</small>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= number_format($c['total_paid'], 2) ?></td>
                    <td class="text-end"><?= number_format($c['balance'], 2) ?></td>
                    <td class="text-center status-<?= strtolower($c['reservation_status']) ?>">
                        <?= ucfirst($c['reservation_status']) ?>
                    </td>
                    <td class="text-center no-print">
                        <?php if ($c['payment_status'] === 'approved' && $c['balance'] > 0): ?>
                            <button class="btn btn-sm btn-success payment-action-btn" 
                                    onclick='recordPayment(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    title="Record Payment">
                                <i class="fas fa-money-bill-wave"></i> Pay
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <!-- Totals Row -->
                <tr class="totals-row">
                    <td colspan="6" class="text-end"><strong>TOTAL</strong></td>
                    <td class="text-end"><strong><?= number_format($total_commission, 2) ?></strong></td>
                    <td class="text-end"><strong><?= number_format($total_tax, 2) ?></strong></td>
                    <td class="text-end"><strong><?= number_format($total_entitled, 2) ?></strong></td>
                    <td></td>
                    <td class="text-end"><strong><?= number_format($total_paid, 2) ?></strong></td>
                    <td class="text-end"><strong><?= number_format($total_balance, 2) ?></strong></td>
                    <td></td>
                    <td class="no-print"></td>
                </tr>
            </tfoot>
        </table>
        </div>
        
        <!-- Footer -->
        <div class="mt-4 pt-3 border-top">
            <p class="text-muted mb-0 text-center"><small>This is a computer-generated statement and does not require a signature.</small></p>
        </div>
        
    </div>
</div>

<!-- PRINT PREVIEW MODAL -->
<div class="modal fade" id="printPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice"></i> Commission Statement - Print Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="printPreviewContent">
                <!-- Company Header -->
                <div class="print-preview-header">
                    <div class="container">
                        <div class="row align-items-center py-4">
                            <div class="col-md-3 text-center">
                                <?php if (!empty($company_info['logo'])): ?>
                                    <img src="../../uploads/company/<?= htmlspecialchars($company_info['logo']) ?>" 
                                         alt="Company Logo" style="max-height: 100px; max-width: 250px;">
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-center">
                                <h2 class="mb-2"><?= htmlspecialchars($company_info['company_name'] ?? 'Company Name') ?></h2>
                                <?php if (!empty($company_info['address'])): ?>
                                    <p class="mb-1"><?= htmlspecialchars($company_info['address']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($company_info['phone']) || !empty($company_info['email'])): ?>
                                    <p class="mb-0">
                                        <?php if (!empty($company_info['phone'])): ?>
                                            Tel: <?= htmlspecialchars($company_info['phone']) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($company_info['phone']) && !empty($company_info['email'])): ?>
                                            | 
                                        <?php endif; ?>
                                        <?php if (!empty($company_info['email'])): ?>
                                            Email: <?= htmlspecialchars($company_info['email']) ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3 text-center">
                                <h4>COMMISSION STATEMENT</h4>
                                <p><strong>Date:</strong> <?= date('d F Y') ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="container py-4">
                    <!-- Recipient Info -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h4>RECIPIENT INFORMATION</h4>
                            <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars(strtoupper($recipient_name)) ?></p>
                            <?php if ($recipient_phone): ?>
                            <p class="mb-1"><strong>Phone:</strong> <?= htmlspecialchars($recipient_phone) ?></p>
                            <?php endif; ?>
                            <p class="mb-0"><strong>Statement Date:</strong> <?= date('d F Y') ?></p>
                        </div>
                        <div class="col-md-6 text-end">
                            <p class="mb-1"><strong>Total Commissions:</strong> <?= count($commissions) ?></p>
                            <p class="mb-0"><strong>Period:</strong> 
                                <?php if (count($commissions) > 0): ?>
                                    <?= date('d M Y', strtotime($commissions[0]['commission_date'])) ?> - 
                                    <?= date('d M Y', strtotime(end($commissions)['commission_date'])) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-muted">TOTAL COMMISSION</h6>
                                    <h3 class="card-title text-primary">TZS <?= number_format($total_commission, 2) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-muted">COMMISSION PAID</h6>
                                    <h3 class="card-title text-success">TZS <?= number_format($total_paid, 2) ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-muted">OUTSTANDING BALANCE</h6>
                                    <h3 class="card-title text-danger">TZS <?= number_format($total_balance, 2) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Detailed Table -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead class="table-primary">
                                <tr>
                                    <th>Date</th>
                                    <th>Client</th>
                                    <th>Project</th>
                                    <th class="text-end">Size</th>
                                    <th class="text-end">Value</th>
                                    <th class="text-center">%</th>
                                    <th class="text-end">Commission</th>
                                    <th class="text-end">WHT</th>
                                    <th class="text-end">Entitled</th>
                                    <th>Payments</th>
                                    <th class="text-end">Paid</th>
                                    <th class="text-end">Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($commissions as $c): 
                                    $payments = $payments_by_commission[$c['commission_id']] ?? [];
                                ?>
                                <tr>
                                    <td><?= date('d-M-y', strtotime($c['commission_date'])) ?></td>
                                    <td><?= htmlspecialchars($c['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($c['project_name']) ?></td>
                                    <td class="text-end"><?= number_format($c['size_sqm'], 0) ?></td>
                                    <td class="text-end"><?= number_format($c['contract_value'], 0) ?></td>
                                    <td class="text-center"><?= $c['commission_percentage'] ?>%</td>
                                    <td class="text-end"><?= number_format($c['commission_amount'], 2) ?></td>
                                    <td class="text-end"><?= number_format($c['withholding_tax_amount'], 2) ?></td>
                                    <td class="text-end"><?= number_format($c['entitled_amount'], 2) ?></td>
                                    <td style="font-size: 11px;">
                                        <?php if (count($payments) > 0): ?>
                                            <?php foreach ($payments as $idx => $p): ?>
                                                <div>Pay<?= ($idx + 1) ?>: <?= number_format($p['payment_amount'], 2) ?> (<?= date('d-M-y', strtotime($p['payment_date'])) ?>)</div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= number_format($c['total_paid'], 2) ?></td>
                                    <td class="text-end"><?= number_format($c['balance'], 2) ?></td>
                                    <td><span class="badge bg-<?= $c['reservation_status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($c['reservation_status']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <tr class="table-info fw-bold">
                                    <td colspan="6" class="text-end">TOTAL</td>
                                    <td class="text-end"><?= number_format($total_commission, 2) ?></td>
                                    <td class="text-end"><?= number_format($total_tax, 2) ?></td>
                                    <td class="text-end"><?= number_format($total_entitled, 2) ?></td>
                                    <td></td>
                                    <td class="text-end"><?= number_format($total_paid, 2) ?></td>
                                    <td class="text-end"><?= number_format($total_balance, 2) ?></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Footer -->
                    <div class="text-center mt-5 pt-4 border-top">
                        <p class="text-muted mb-0"><small>This is a computer-generated statement and does not require a signature.</small></p>
                        <p class="text-muted mb-0"><small>Generated on <?= date('d F Y H:i') ?></small></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printModalContent()">
                    <i class="fas fa-print"></i> Print This Statement
                </button>
            </div>
        </div>
    </div>
</div>

<!-- PAYMENT SELECTION MODAL -->
<div class="modal fade" id="paymentSelectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> Select Commission to Pay</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Select which commission you want to record a payment for:
                </div>
                
                <div class="list-group">
                    <?php foreach ($commissions as $c): ?>
                        <?php if ($c['payment_status'] === 'approved' && $c['balance'] > 0): ?>
                        <a href="javascript:void(0)" 
                           class="list-group-item list-group-item-action"
                           onclick='selectCommissionForPayment(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?= htmlspecialchars($c['commission_number']) ?></h6>
                                <span class="badge bg-danger">TZS <?= number_format($c['balance'], 2) ?></span>
                            </div>
                            <p class="mb-1">
                                <strong>Project:</strong> <?= htmlspecialchars($c['project_name']) ?> | 
                                <strong>Customer:</strong> <?= htmlspecialchars($c['customer_name']) ?>
                            </p>
                            <small>
                                <strong>Entitled:</strong> TZS <?= number_format($c['entitled_amount'], 2) ?> | 
                                <strong>Paid:</strong> TZS <?= number_format($c['total_paid'], 2) ?> | 
                                <strong>Balance:</strong> TZS <?= number_format($c['balance'], 2) ?>
                            </small>
                        </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- RECORD PAYMENT MODAL -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> Record Commission Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="commission_id" id="payment_commission_id">
                <div class="modal-body">
                    <div class="row g-3">
                        
                        <div class="col-12">
                            <div class="alert alert-info">
                                <strong>Commission:</strong> <span id="payment_commission_number"></span><br>
                                <strong>Project:</strong> <span id="payment_project_name"></span><br>
                                <strong>Customer:</strong> <span id="payment_customer_name"></span><br>
                                <strong>Entitled Amount:</strong> TZS <span id="payment_entitled_amount"></span><br>
                                <strong>Already Paid:</strong> TZS <span id="payment_total_paid"></span><br>
                                <strong>Outstanding Balance:</strong> <strong class="text-danger">TZS <span id="payment_balance"></span></strong>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Payment Amount (TZS) <span class="text-danger">*</span></label>
                            <input type="number" name="payment_amount" id="payment_amount" class="form-control" 
                                   step="0.01" min="0.01" required>
                            <small class="text-muted">Maximum: <span id="payment_max_amount"></span></small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" class="form-control" 
                                   placeholder="Transaction reference / cheque number">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Payment Notes</label>
                            <textarea name="payment_notes" class="form-control" rows="2" 
                                      placeholder="Additional notes about this payment..."></textarea>
                        </div>
                        
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables JS (footer already includes jQuery and Bootstrap) -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
// Initialize DataTables with responsive features
$(document).ready(function() {
    $('#statementTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'desc']], // Sort by date descending
        language: {
            search: "Search commissions:",
            lengthMenu: "Show _MENU_ commissions per page",
            info: "Showing _START_ to _END_ of _TOTAL_ commissions",
            infoEmpty: "No commissions available",
            infoFiltered: "(filtered from _MAX_ total commissions)",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        columnDefs: [
            { orderable: false, targets: -1 } // Disable sorting on Actions column
        ],
        drawCallback: function() {
            // Reattach event handlers after table redraw
            $('.payment-action-btn').off('click').on('click', function() {
                var data = $(this).data('commission');
                recordPayment(data);
            });
        }
    });
});

// Show print preview modal
function showPrintPreview() {
    new bootstrap.Modal(document.getElementById('printPreviewModal')).show();
}

// Print modal content
function printModalContent() {
    var printContent = document.getElementById('printPreviewContent').innerHTML;
    var originalContent = document.body.innerHTML;
    
    document.body.innerHTML = printContent;
    window.print();
    document.body.innerHTML = originalContent;
    location.reload(); // Reload to restore functionality
}

// Show payment selection modal
function showPaymentOptions() {
    new bootstrap.Modal(document.getElementById('paymentSelectionModal')).show();
}

// Select commission for payment
function selectCommissionForPayment(data) {
    // Close payment selection modal
    bootstrap.Modal.getInstance(document.getElementById('paymentSelectionModal')).hide();
    
    // Open payment recording modal
    setTimeout(function() {
        recordPayment(data);
    }, 300);
}

// Record payment
function recordPayment(data) {
    document.getElementById('payment_commission_id').value = data.commission_id;
    document.getElementById('payment_commission_number').textContent = data.commission_number;
    document.getElementById('payment_project_name').textContent = data.project_name || '-';
    document.getElementById('payment_customer_name').textContent = data.customer_name || '-';
    document.getElementById('payment_entitled_amount').textContent = parseFloat(data.entitled_amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('payment_total_paid').textContent = parseFloat(data.total_paid || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('payment_balance').textContent = parseFloat(data.balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('payment_max_amount').textContent = 'TZS ' + parseFloat(data.balance).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    // Set max amount for payment
    document.getElementById('payment_amount').max = data.balance;
    document.getElementById('payment_amount').value = data.balance; // Default to full balance
    
    new bootstrap.Modal(document.getElementById('recordPaymentModal')).show();
}

// Auto-dismiss alerts
setTimeout(function() {
    $('.alert').not('.info').fadeOut();
}, 5000);
</script>

<?php require_once '../../includes/footer.php'; ?>