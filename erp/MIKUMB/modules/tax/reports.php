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

// Fetch filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$tax_type_filter = $_GET['tax_type'] ?? '';
$transaction_type_filter = $_GET['transaction_type'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Fetch tax transactions
try {
    $transactions_sql = "SELECT 
                            tt.tax_transaction_id,
                            tt.transaction_number,
                            tt.transaction_date,
                            tt.transaction_type,
                            tt.taxable_amount,
                            tt.tax_amount,
                            tt.total_amount,
                            tt.status,
                            tt.payment_date,
                            tt.invoice_number,
                            tt.description,
                            tt.remarks,
                            t.tax_code,
                            t.tax_name,
                            t.tax_rate,
                            c.full_name as customer_name,
                            s.supplier_name,
                            tt.created_at
                         FROM tax_transactions tt
                         INNER JOIN tax_types t ON tt.tax_type_id = t.tax_type_id
                         LEFT JOIN customers c ON tt.customer_id = c.customer_id
                         LEFT JOIN suppliers s ON tt.supplier_id = s.supplier_id
                         WHERE tt.company_id = ?";
    
    $params = [$company_id];
    
    // Add date filters
    if (!empty($date_from) && !empty($date_to)) {
        $transactions_sql .= " AND tt.transaction_date BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    }
    
    if (!empty($tax_type_filter)) {
        $transactions_sql .= " AND tt.tax_type_id = ?";
        $params[] = $tax_type_filter;
    }
    
    if (!empty($transaction_type_filter)) {
        $transactions_sql .= " AND tt.transaction_type = ?";
        $params[] = $transaction_type_filter;
    }
    
    if (!empty($status_filter)) {
        $transactions_sql .= " AND tt.status = ?";
        $params[] = $status_filter;
    }
    
    $transactions_sql .= " ORDER BY tt.transaction_date DESC, tt.transaction_number DESC";
    
    $stmt = $conn->prepare($transactions_sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $total_transactions = count($transactions);
    $total_taxable = array_sum(array_column($transactions, 'taxable_amount'));
    $total_tax = array_sum(array_column($transactions, 'tax_amount'));
    $total_amount = array_sum(array_column($transactions, 'total_amount'));
    
    // Count by status
    $pending_count = count(array_filter($transactions, fn($t) => $t['status'] === 'pending'));
    $filed_count = count(array_filter($transactions, fn($t) => $t['status'] === 'filed'));
    $paid_count = count(array_filter($transactions, fn($t) => $t['status'] === 'paid'));
    $cancelled_count = count(array_filter($transactions, fn($t) => $t['status'] === 'cancelled'));
    
    // Count by transaction type
    $sales_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'sales'));
    $purchase_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'purchase'));
    $payroll_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'payroll'));
    
    $stats = [
        'total_transactions' => $total_transactions,
        'total_taxable' => $total_taxable,
        'total_tax' => $total_tax,
        'total_amount' => $total_amount,
        'pending_count' => $pending_count,
        'filed_count' => $filed_count,
        'paid_count' => $paid_count,
        'cancelled_count' => $cancelled_count,
        'sales_count' => $sales_count,
        'purchase_count' => $purchase_count,
        'payroll_count' => $payroll_count
    ];
    
} catch (PDOException $e) {
    $transactions = [];
    $stats = [
        'total_transactions' => 0,
        'total_taxable' => 0,
        'total_tax' => 0,
        'total_amount' => 0,
        'pending_count' => 0,
        'filed_count' => 0,
        'paid_count' => 0,
        'cancelled_count' => 0,
        'sales_count' => 0,
        'purchase_count' => 0,
        'payroll_count' => 0
    ];
    $errors[] = "Error fetching tax transactions: " . $e->getMessage();
}

// Fetch tax types for filter
try {
    $tax_types_sql = "SELECT tax_type_id, tax_name, tax_code, tax_rate 
                      FROM tax_types 
                      WHERE company_id = ? AND is_active = 1 
                      ORDER BY tax_name";
    $stmt = $conn->prepare($tax_types_sql);
    $stmt->execute([$company_id]);
    $tax_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tax_types = [];
}

$page_title = 'Tax Reports';
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
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 5px;
}

.stats-label {
    font-size: 11px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
}

.filter-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 20px;
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
    font-size: 10px;
    letter-spacing: 0.5px;
    padding: 12px 8px;
    white-space: nowrap;
}

.table tbody td {
    padding: 10px 8px;
    vertical-align: middle;
    font-size: 13px;
}

/* Row colors based on status */
.row-pending {
    background: linear-gradient(90deg, #fff3cd 0%, #ffeeba 100%) !important;
    border-left: 4px solid #ffc107;
}

.row-filed {
    background: linear-gradient(90deg, #cfe2ff 0%, #b6d4fe 100%) !important;
    border-left: 4px solid #0d6efd;
}

.row-paid {
    background: linear-gradient(90deg, #d4edda 0%, #c3e6cb 100%) !important;
    border-left: 4px solid #28a745;
}

.row-cancelled {
    background: linear-gradient(90deg, #f8d7da 0%, #f5c6cb 100%) !important;
    border-left: 4px solid #dc3545;
}

.badge-status {
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 10px;
    font-weight: 700;
}

.badge-pending {
    background: #ffc107;
    color: #000;
}

.badge-filed {
    background: #0d6efd;
    color: #fff;
}

.badge-paid {
    background: #28a745;
    color: #fff;
}

.badge-cancelled {
    background: #dc3545;
    color: #fff;
}

.badge-type {
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 10px;
    font-weight: 700;
}

.badge-sales {
    background: #28a745;
    color: #fff;
}

.badge-purchase {
    background: #0d6efd;
    color: #fff;
}

.badge-payroll {
    background: #6f42c1;
    color: #fff;
}

.badge-withholding {
    background: #fd7e14;
    color: #fff;
}

.badge-other {
    background: #6c757d;
    color: #fff;
}

.legend {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 15px;
    padding: 15px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 600;
}

.legend-color {
    width: 30px;
    height: 20px;
    border-radius: 4px;
    border: 1px solid #dee2e6;
}

.summary-box {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #dee2e6;
}

.summary-row:last-child {
    border-bottom: none;
    font-weight: 700;
    font-size: 18px;
    color: #0d6efd;
    padding-top: 15px;
    margin-top: 10px;
    border-top: 2px solid #dee2e6;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-file-invoice-dollar"></i> Tax Reports</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Tax Management</a></li>
                    <li class="breadcrumb-item active">Reports</li>
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

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-primary"><?php echo $stats['total_transactions']; ?></div>
                    <div class="stats-label">Total Transactions</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-info">TZS <?php echo number_format($stats['total_taxable'], 0); ?></div>
                    <div class="stats-label">Total Taxable Amount</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-warning">TZS <?php echo number_format($stats['total_tax'], 0); ?></div>
                    <div class="stats-label">Total Tax Amount</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-success">TZS <?php echo number_format($stats['total_amount'], 0); ?></div>
                    <div class="stats-label">Total Amount</div>
                </div>
            </div>
        </div>

        <!-- Secondary Statistics -->
        <div class="row mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-warning"><?php echo $stats['pending_count']; ?></div>
                    <div class="stats-label">Pending</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-info"><?php echo $stats['filed_count']; ?></div>
                    <div class="stats-label">Filed</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $stats['paid_count']; ?></div>
                    <div class="stats-label">Paid</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $stats['sales_count']; ?></div>
                    <div class="stats-label">Sales Tax</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-info"><?php echo $stats['purchase_count']; ?></div>
                    <div class="stats-label">Purchase Tax</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number text-secondary"><?php echo $stats['payroll_count']; ?></div>
                    <div class="stats-label">Payroll Tax</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="row mb-3">
            <div class="col-12">
                <a href="create-transaction.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Tax Transaction
                </a>
                <button type="button" class="btn btn-success" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </button>
                <button type="button" class="btn btn-info" onclick="printReport()">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" id="filterForm">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Tax Type</label>
                        <select name="tax_type" class="form-select">
                            <option value="">All Tax Types</option>
                            <?php foreach ($tax_types as $type): ?>
                                <option value="<?php echo $type['tax_type_id']; ?>" <?php echo $tax_type_filter == $type['tax_type_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['tax_name']); ?> (<?php echo $type['tax_rate']; ?>%)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Transaction Type</label>
                        <select name="transaction_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="sales" <?php echo $transaction_type_filter === 'sales' ? 'selected' : ''; ?>>Sales</option>
                            <option value="purchase" <?php echo $transaction_type_filter === 'purchase' ? 'selected' : ''; ?>>Purchase</option>
                            <option value="payroll" <?php echo $transaction_type_filter === 'payroll' ? 'selected' : ''; ?>>Payroll</option>
                            <option value="withholding" <?php echo $transaction_type_filter === 'withholding' ? 'selected' : ''; ?>>Withholding</option>
                            <option value="other" <?php echo $transaction_type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="filed" <?php echo $status_filter === 'filed' ? 'selected' : ''; ?>>Filed</option>
                            <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-9 mb-3">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="reports.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Period Summary -->
        <div class="summary-box">
            <h5 class="mb-3">
                <i class="fas fa-calendar-alt"></i> 
                Period Summary: <?php echo date('d M Y', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?>
            </h5>
            <div class="row">
                <div class="col-md-6">
                    <div class="summary-row">
                        <span>Taxable Amount:</span>
                        <strong>TZS <?php echo number_format($stats['total_taxable'], 2); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>Tax Amount:</span>
                        <strong>TZS <?php echo number_format($stats['total_tax'], 2); ?></strong>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="summary-row">
                        <span>Total Transactions:</span>
                        <strong><?php echo $stats['total_transactions']; ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>TOTAL AMOUNT:</span>
                        <strong>TZS <?php echo number_format($stats['total_amount'], 2); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div class="legend">
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(90deg, #fff3cd, #ffeeba); border-left: 4px solid #ffc107;"></div>
                <span>Pending</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(90deg, #cfe2ff, #b6d4fe); border-left: 4px solid #0d6efd;"></div>
                <span>Filed</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(90deg, #d4edda, #c3e6cb); border-left: 4px solid #28a745;"></div>
                <span>Paid</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(90deg, #f8d7da, #f5c6cb); border-left: 4px solid #dc3545;"></div>
                <span>Cancelled</span>
            </div>
        </div>

        <!-- Tax Transactions Table -->
        <div class="table-container">
            <table class="table table-hover mb-0" id="taxTable">
                <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Transaction #</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Tax Type</th>
                        <th>Party</th>
                        <th>Invoice #</th>
                        <th>Taxable Amount</th>
                        <th>Tax Amount</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="12" class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No tax transactions found for the selected period</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php 
                        $sn = 1;
                        foreach ($transactions as $transaction): 
                            // Determine row class
                            switch($transaction['status']) {
                                case 'pending':
                                    $row_class = 'row-pending';
                                    $status_badge = 'badge-pending';
                                    break;
                                case 'filed':
                                    $row_class = 'row-filed';
                                    $status_badge = 'badge-filed';
                                    break;
                                case 'paid':
                                    $row_class = 'row-paid';
                                    $status_badge = 'badge-paid';
                                    break;
                                case 'cancelled':
                                    $row_class = 'row-cancelled';
                                    $status_badge = 'badge-cancelled';
                                    break;
                                default:
                                    $row_class = '';
                                    $status_badge = 'badge-secondary';
                            }
                            
                            // Transaction type badge
                            $type_class = 'badge-' . $transaction['transaction_type'];
                            
                            // Party name
                            $party_name = $transaction['customer_name'] ?? $transaction['supplier_name'] ?? '-';
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><strong><?php echo $sn++; ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($transaction['transaction_number']); ?></strong>
                            </td>
                            <td>
                                <strong><?php echo date('d-M-Y', strtotime($transaction['transaction_date'])); ?></strong>
                            </td>
                            <td>
                                <span class="badge-type <?php echo $type_class; ?>">
                                    <?php echo ucfirst($transaction['transaction_type']); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($transaction['tax_name']); ?></strong>
                                <br>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($transaction['tax_code']); ?> (<?php echo $transaction['tax_rate']; ?>%)
                                </small>
                            </td>
                            <td><?php echo htmlspecialchars($party_name); ?></td>
                            <td>
                                <small><?php echo htmlspecialchars($transaction['invoice_number'] ?? '-'); ?></small>
                            </td>
                            <td>
                                <strong>TZS <?php echo number_format($transaction['taxable_amount'], 0); ?></strong>
                            </td>
                            <td>
                                <strong class="text-warning">TZS <?php echo number_format($transaction['tax_amount'], 0); ?></strong>
                            </td>
                            <td>
                                <strong class="text-primary">TZS <?php echo number_format($transaction['total_amount'], 0); ?></strong>
                            </td>
                            <td>
                                <span class="badge-status <?php echo $status_badge; ?>">
                                    <?php echo ucfirst($transaction['status']); ?>
                                </span>
                                <?php if ($transaction['payment_date']): ?>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-calendar"></i> <?php echo date('d-M', strtotime($transaction['payment_date'])); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick='viewDetails(<?php echo json_encode($transaction); ?>)'>
                                    <i class="fas fa-eye"></i>
                                </button>
                                <a href="view-transaction.php?id=<?php echo $transaction['tax_transaction_id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-folder-open"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f8f9fa; font-weight: 700;">
                        <td colspan="7" class="text-end"><strong>TOTALS:</strong></td>
                        <td><strong>TZS <?php echo number_format($stats['total_taxable'], 0); ?></strong></td>
                        <td><strong class="text-warning">TZS <?php echo number_format($stats['total_tax'], 0); ?></strong></td>
                        <td><strong class="text-primary">TZS <?php echo number_format($stats['total_amount'], 0); ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

    </div>
</section>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle"></i> Tax Transaction Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6 class="text-muted">Transaction Information</h6>
                        <p><strong>Transaction #:</strong> <span id="detail_transaction_number"></span></p>
                        <p><strong>Transaction Date:</strong> <span id="detail_transaction_date"></span></p>
                        <p><strong>Transaction Type:</strong> <span id="detail_transaction_type"></span></p>
                        <p><strong>Status:</strong> <span id="detail_status"></span></p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">Tax Information</h6>
                        <p><strong>Tax Type:</strong> <span id="detail_tax_name"></span></p>
                        <p><strong>Tax Code:</strong> <span id="detail_tax_code"></span></p>
                        <p><strong>Tax Rate:</strong> <span id="detail_tax_rate"></span></p>
                        <p><strong>Invoice Number:</strong> <span id="detail_invoice"></span></p>
                    </div>
                </div>
                <hr>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6 class="text-muted">Amount Breakdown</h6>
                        <p><strong>Taxable Amount:</strong> <span id="detail_taxable_amount"></span></p>
                        <p><strong>Tax Amount:</strong> <span id="detail_tax_amount" class="text-warning"></span></p>
                        <p><strong>Total Amount:</strong> <span id="detail_total_amount" class="text-primary"></span></p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">Payment Information</h6>
                        <p><strong>Payment Date:</strong> <span id="detail_payment_date"></span></p>
                        <p><strong>Party:</strong> <span id="detail_party"></span></p>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-12">
                        <h6 class="text-muted">Description / Remarks</h6>
                        <p id="detail_description" class="text-muted"></p>
                        <p id="detail_remarks" class="text-muted"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// View Details
function viewDetails(data) {
    // Transaction info
    document.getElementById('detail_transaction_number').textContent = data.transaction_number;
    document.getElementById('detail_transaction_date').textContent = new Date(data.transaction_date).toLocaleDateString();
    document.getElementById('detail_transaction_type').textContent = data.transaction_type.charAt(0).toUpperCase() + data.transaction_type.slice(1);
    
    // Status
    const statusBadges = {
        'pending': '<span class="badge-pending">Pending</span>',
        'filed': '<span class="badge-filed">Filed</span>',
        'paid': '<span class="badge-paid">Paid</span>',
        'cancelled': '<span class="badge-cancelled">Cancelled</span>'
    };
    document.getElementById('detail_status').innerHTML = statusBadges[data.status] || data.status;
    
    // Tax info
    document.getElementById('detail_tax_name').textContent = data.tax_name;
    document.getElementById('detail_tax_code').textContent = data.tax_code;
    document.getElementById('detail_tax_rate').textContent = data.tax_rate + '%';
    document.getElementById('detail_invoice').textContent = data.invoice_number || '-';
    
    // Amounts
    document.getElementById('detail_taxable_amount').textContent = 'TZS ' + parseFloat(data.taxable_amount).toLocaleString();
    document.getElementById('detail_tax_amount').textContent = 'TZS ' + parseFloat(data.tax_amount).toLocaleString();
    document.getElementById('detail_total_amount').textContent = 'TZS ' + parseFloat(data.total_amount).toLocaleString();
    
    // Payment info
    document.getElementById('detail_payment_date').textContent = data.payment_date ? 
        new Date(data.payment_date).toLocaleDateString() : 'Not paid';
    
    const party = data.customer_name || data.supplier_name || '-';
    document.getElementById('detail_party').textContent = party;
    
    // Description/Remarks
    document.getElementById('detail_description').textContent = data.description || 'No description';
    document.getElementById('detail_remarks').textContent = data.remarks || 'No remarks';
    
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();
}

// Export to Excel
function exportToExcel() {
    const table = document.getElementById('taxTable');
    let html = table.outerHTML;
    window.open('data:application/vnd.ms-excel,' + encodeURIComponent(html));
}

// Print Report
function printReport() {
    window.print();
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