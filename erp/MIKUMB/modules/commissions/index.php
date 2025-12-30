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

// Filters
$filter_status = $_GET['status'] ?? 'all';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build query
$where_conditions = ["c.company_id = ?"];
$params = [$company_id];

if ($filter_status !== 'all') {
    $where_conditions[] = "c.payment_status = ?";
    $params[] = $filter_status;
}

if ($filter_date_from) {
    $where_conditions[] = "DATE(r.reservation_date) >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to) {
    $where_conditions[] = "DATE(r.reservation_date) <= ?";
    $params[] = $filter_date_to;
}

if ($search_query) {
    $where_conditions[] = "(cu.full_name LIKE ? OR r.reservation_number LIKE ? OR c.recipient_name LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch commissions
try {
    $commissions_sql = "SELECT 
                            c.commission_id,
                            c.recipient_name,
                            c.recipient_phone,
                            c.commission_type,
                            c.commission_percentage,
                            c.commission_amount,
                            c.payment_status,
                            c.paid_date,
                            c.payment_reference,
                            c.remarks,
                            c.created_at,
                            r.reservation_id,
                            r.reservation_number,
                            r.reservation_date,
                            r.total_amount as contract_value,
                            cu.full_name as customer_name,
                            pl.plot_number,
                            pl.area,
                            pr.project_name,
                            u.full_name as created_by_name,
                            -- Calculate withholding tax (5%)
                            (c.commission_amount * 0.05) as withholding_tax,
                            -- Calculate entitled commission (after tax)
                            (c.commission_amount - (c.commission_amount * 0.05)) as entitled_commission
                        FROM commissions c
                        INNER JOIN reservations r ON c.reservation_id = r.reservation_id
                        INNER JOIN customers cu ON r.customer_id = cu.customer_id
                        INNER JOIN plots pl ON r.plot_id = pl.plot_id
                        INNER JOIN projects pr ON pl.project_id = pr.project_id
                        LEFT JOIN users u ON c.created_by = u.user_id
                        WHERE {$where_clause}
                        ORDER BY c.created_at DESC";
    
    $stmt = $conn->prepare($commissions_sql);
    $stmt->execute($params);
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $total_commissions = count($commissions);
    $total_amount = array_sum(array_column($commissions, 'commission_amount'));
    $total_paid = array_sum(array_column(array_filter($commissions, function($c) {
        return $c['payment_status'] === 'paid';
    }), 'commission_amount'));
    $total_pending = $total_amount - $total_paid;
    
    $paid_count = count(array_filter($commissions, function($c) {
        return $c['payment_status'] === 'paid';
    }));
    $pending_count = $total_commissions - $paid_count;
    
} catch (PDOException $e) {
    $commissions = [];
    $total_commissions = 0;
    $total_amount = 0;
    $total_paid = 0;
    $total_pending = 0;
    $paid_count = 0;
    $pending_count = 0;
}

$page_title = 'Commissions';
require_once '../../includes/header.php';
?>

<style>
.commission-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 20px;
    overflow: hidden;
    border-left: 5px solid #667eea;
}

.commission-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.commission-body {
    padding: 20px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.info-item {
    padding: 10px;
    background: #f8f9fa;
    border-radius: 6px;
    border-left: 3px solid #667eea;
}

.info-label {
    font-size: 11px;
    color: #6c757d;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 14px;
    color: #212529;
    font-weight: 600;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 700;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-paid {
    background: #d4edda;
    color: #155724;
}

.status-cancelled {
    background: #f8d7da;
    color: #721c24;
}

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.stats-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    text-align: center;
    border-top: 4px solid #667eea;
}

.stats-card.paid {
    border-top-color: #28a745;
}

.stats-card.pending {
    border-top-color: #ffc107;
}

.stats-number {
    font-size: 32px;
    font-weight: 800;
    color: #667eea;
    margin-bottom: 5px;
}

.stats-card.paid .stats-number {
    color: #28a745;
}

.stats-card.pending .stats-number {
    color: #ffc107;
}

.stats-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
}

.filter-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 25px;
}

.commission-summary {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
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
    color: #28a745;
    padding-top: 10px;
    margin-top: 5px;
    border-top: 2px solid #28a745;
}

.btn-pay {
    background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    color: white;
    border: none;
    padding: 8px 20px;
    border-radius: 6px;
    font-weight: 600;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-percentage"></i> Commissions</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item active">Commissions</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-row">
            <div class="stats-card">
                <div class="stats-number"><?php echo $total_commissions; ?></div>
                <div class="stats-label">Total Commissions</div>
            </div>
            <div class="stats-card">
                <div class="stats-number">TZS <?php echo number_format($total_amount, 0); ?></div>
                <div class="stats-label">Total Amount</div>
            </div>
            <div class="stats-card paid">
                <div class="stats-number"><?php echo $paid_count; ?></div>
                <div class="stats-label">Paid</div>
                <div style="font-size: 14px; color: #28a745; font-weight: 600; margin-top: 5px;">
                    TZS <?php echo number_format($total_paid, 0); ?>
                </div>
            </div>
            <div class="stats-card pending">
                <div class="stats-number"><?php echo $pending_count; ?></div>
                <div class="stats-label">Pending</div>
                <div style="font-size: 14px; color: #ffc107; font-weight: 600; margin-top: 5px;">
                    TZS <?php echo number_format($total_pending, 0); ?>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <h5 style="margin-bottom: 15px; font-weight: 600;">
                <i class="fas fa-filter"></i> Filters
            </h5>
            <form method="GET" class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Recipient, Customer..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                </div>

                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                    <a href="structures.php" class="btn btn-info">
                        <i class="fas fa-cogs"></i> Commission Structures
                    </a>
                    <a href="pay.php" class="btn btn-success float-right">
                        <i class="fas fa-dollar-sign"></i> Pay Commissions
                    </a>
                </div>
            </form>
        </div>

        <!-- Commissions List -->
        <?php if (empty($commissions)): ?>
        <div class="commission-card">
            <div class="commission-body" style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-inbox" style="font-size: 80px; color: #dee2e6; margin-bottom: 20px;"></i>
                <h4>No Commissions Found</h4>
                <p>No commission records match your filters.</p>
            </div>
        </div>
        <?php else: ?>

        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h3 class="card-title" style="color: white; font-weight: 700;">
                    <i class="fas fa-list"></i> Commission Records (<?php echo count($commissions); ?>)
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead style="background: #f8f9fa;">
                            <tr>
                                <th>Recipient</th>
                                <th>Customer</th>
                                <th>Reservation</th>
                                <th>Project/Plot</th>
                                <th>Type</th>
                                <th>Rate</th>
                                <th>Gross Amount</th>
                                <th>Tax (5%)</th>
                                <th>Net Amount</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($commissions as $commission): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($commission['recipient_name']); ?></strong>
                                    <?php if ($commission['recipient_phone']): ?>
                                        <br><small class="text-muted">
                                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($commission['recipient_phone']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($commission['customer_name']); ?>
                                    <br><small class="text-muted">
                                        Contract: TZS <?php echo number_format($commission['contract_value'], 0); ?>
                                    </small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($commission['reservation_number']); ?></strong>
                                    <br><small class="text-muted">
                                        <?php echo date('M d, Y', strtotime($commission['reservation_date'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($commission['project_name']); ?>
                                    <br><small class="text-muted">
                                        Plot <?php echo htmlspecialchars($commission['plot_number']); ?> 
                                        (<?php echo number_format($commission['area'], 0); ?> m²)
                                    </small>
                                </td>
                                <td>
                                    <?php 
                                    $type_colors = [
                                        'sales' => 'background: #28a745;',
                                        'referral' => 'background: #17a2b8;',
                                        'consultant' => 'background: #6f42c1;',
                                        'marketing' => 'background: #fd7e14;',
                                        'collection' => 'background: #20c997;'
                                    ];
                                    $color = $type_colors[$commission['commission_type']] ?? 'background: #6c757d;';
                                    ?>
                                    <span class="badge" style="<?php echo $color; ?> color: white;">
                                        <?php echo ucwords($commission['commission_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo number_format($commission['commission_percentage'], 2); ?>%</strong>
                                </td>
                                <td>
                                    <strong style="color: #667eea;">
                                        TZS <?php echo number_format($commission['commission_amount'], 2); ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="text-muted">
                                        TZS <?php echo number_format($commission['withholding_tax'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #28a745;">
                                        TZS <?php echo number_format($commission['entitled_commission'], 2); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($commission['payment_status'] === 'paid'): ?>
                                        <span class="badge badge-success" style="background: #28a745;">
                                            <i class="fas fa-check-circle"></i> PAID
                                        </span>
                                        <?php if ($commission['paid_date']): ?>
                                            <br><small class="text-muted">
                                                <?php echo date('M d, Y', strtotime($commission['paid_date'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php elseif ($commission['payment_status'] === 'cancelled'): ?>
                                        <span class="badge badge-danger" style="background: #dc3545;">
                                            <i class="fas fa-ban"></i> CANCELLED
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="background: #ffc107; color: #856404;">
                                            <i class="fas fa-clock"></i> PENDING
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo date('M d, Y', strtotime($commission['created_at'])); ?>
                                    </small>
                                    <?php if ($commission['created_by_name']): ?>
                                        <br><small class="text-muted">
                                            <?php echo htmlspecialchars($commission['created_by_name']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" 
                                            onclick='viewCommissionDetails(<?php echo json_encode($commission); ?>)'
                                            title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background: #f8f9fa; font-weight: 700;">
                            <tr>
                                <td colspan="6" class="text-right">TOTALS:</td>
                                <td>
                                    <strong style="color: #667eea;">
                                        TZS <?php echo number_format($total_amount, 2); ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="text-muted">
                                        TZS <?php echo number_format($total_amount * 0.05, 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #28a745;">
                                        TZS <?php echo number_format($total_amount - ($total_amount * 0.05), 2); ?>
                                    </strong>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>

    </div>
</section>

<!-- View Commission Details Modal -->
<div class="modal fade" id="viewCommissionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h5 class="modal-title" style="color: white;">
                    <i class="fas fa-eye"></i> Commission Details
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Left Column -->
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header" style="background: #667eea; color: white; font-weight: 700;">
                                <i class="fas fa-user"></i> Recipient Information
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">Recipient Name:</td>
                                        <td><strong id="detail_recipient_name"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Phone:</td>
                                        <td id="detail_recipient_phone"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Commission Type:</td>
                                        <td id="detail_commission_type"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header" style="background: #28a745; color: white; font-weight: 700;">
                                <i class="fas fa-user-tie"></i> Customer Information
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">Customer Name:</td>
                                        <td><strong id="detail_customer_name"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Reservation #:</td>
                                        <td id="detail_reservation_number"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Reservation Date:</td>
                                        <td id="detail_reservation_date"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header" style="background: #17a2b8; color: white; font-weight: 700;">
                                <i class="fas fa-map-marked-alt"></i> Property Information
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">Project:</td>
                                        <td><strong id="detail_project_name"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Plot Number:</td>
                                        <td id="detail_plot_number"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Plot Area:</td>
                                        <td id="detail_plot_area"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header" style="background: #ffc107; color: #856404; font-weight: 700;">
                                <i class="fas fa-calculator"></i> Commission Calculation
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <td class="text-muted">Contract Value:</td>
                                        <td class="text-right"><strong id="detail_contract_value"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Commission Rate:</td>
                                        <td class="text-right"><strong id="detail_commission_rate"></strong></td>
                                    </tr>
                                    <tr style="border-top: 2px solid #dee2e6;">
                                        <td class="text-muted">Gross Commission:</td>
                                        <td class="text-right"><strong style="color: #667eea;" id="detail_gross_commission"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Withholding Tax (5%):</td>
                                        <td class="text-right"><span class="text-danger" id="detail_withholding_tax"></span></td>
                                    </tr>
                                    <tr style="border-top: 2px solid #28a745;">
                                        <td class="text-muted"><strong>Net Entitled Commission:</strong></td>
                                        <td class="text-right"><strong style="color: #28a745; font-size: 18px;" id="detail_net_commission"></strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header" id="detail_status_header" style="font-weight: 700;">
                                <i class="fas fa-info-circle"></i> Payment Status
                            </div>
                            <div class="card-body">
                                <div id="detail_payment_status"></div>
                            </div>
                        </div>

                        <div class="card" id="detail_remarks_card" style="display: none;">
                            <div class="card-header" style="background: #6c757d; color: white; font-weight: 700;">
                                <i class="fas fa-comment"></i> Remarks
                            </div>
                            <div class="card-body">
                                <p id="detail_remarks" style="white-space: pre-wrap;"></p>
                            </div>
                        </div>

                        <div class="card" style="display: none;" id="detail_created_card">
                            <div class="card-header" style="background: #e9ecef; font-weight: 700;">
                                <i class="fas fa-clock"></i> Record Information
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">Created On:</td>
                                        <td id="detail_created_at"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Created By:</td>
                                        <td id="detail_created_by"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function viewCommissionDetails(commission) {
    // Recipient Information
    document.getElementById('detail_recipient_name').textContent = commission.recipient_name;
    document.getElementById('detail_recipient_phone').textContent = commission.recipient_phone || 'N/A';
    
    // Commission Type with color
    const typeColors = {
        'sales': 'background: #28a745; color: white;',
        'referral': 'background: #17a2b8; color: white;',
        'consultant': 'background: #6f42c1; color: white;',
        'marketing': 'background: #fd7e14; color: white;',
        'collection': 'background: #20c997; color: white;'
    };
    const typeColor = typeColors[commission.commission_type] || 'background: #6c757d; color: white;';
    document.getElementById('detail_commission_type').innerHTML = 
        '<span class="badge" style="' + typeColor + '">' + 
        commission.commission_type.charAt(0).toUpperCase() + commission.commission_type.slice(1) + 
        '</span>';
    
    // Customer Information
    document.getElementById('detail_customer_name').textContent = commission.customer_name;
    document.getElementById('detail_reservation_number').textContent = commission.reservation_number;
    document.getElementById('detail_reservation_date').textContent = 
        new Date(commission.reservation_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'});
    
    // Property Information
    document.getElementById('detail_project_name').textContent = commission.project_name;
    document.getElementById('detail_plot_number').textContent = 'Plot ' + commission.plot_number;
    document.getElementById('detail_plot_area').textContent = parseFloat(commission.area).toLocaleString() + ' m²';
    
    // Commission Calculation
    document.getElementById('detail_contract_value').textContent = 
        'TZS ' + parseFloat(commission.contract_value).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('detail_commission_rate').textContent = 
        parseFloat(commission.commission_percentage).toFixed(2) + '%';
    document.getElementById('detail_gross_commission').textContent = 
        'TZS ' + parseFloat(commission.commission_amount).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('detail_withholding_tax').textContent = 
        'TZS ' + parseFloat(commission.withholding_tax).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('detail_net_commission').textContent = 
        'TZS ' + parseFloat(commission.entitled_commission).toLocaleString('en-US', {minimumFractionDigits: 2});
    
    // Payment Status
    const statusHeader = document.getElementById('detail_status_header');
    const statusContent = document.getElementById('detail_payment_status');
    
    if (commission.payment_status === 'paid') {
        statusHeader.style.background = '#28a745';
        statusHeader.style.color = 'white';
        statusContent.innerHTML = 
            '<div class="alert alert-success mb-0">' +
            '<h5><i class="fas fa-check-circle"></i> PAID</h5>' +
            '<p class="mb-0"><strong>Paid On:</strong> ' + 
            new Date(commission.paid_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'}) + 
            '</p>' +
            (commission.payment_reference ? '<p class="mb-0"><strong>Reference:</strong> ' + commission.payment_reference + '</p>' : '') +
            '</div>';
    } else if (commission.payment_status === 'cancelled') {
        statusHeader.style.background = '#dc3545';
        statusHeader.style.color = 'white';
        statusContent.innerHTML = 
            '<div class="alert alert-danger mb-0">' +
            '<h5><i class="fas fa-ban"></i> CANCELLED</h5>' +
            '<p class="mb-0">This commission has been cancelled.</p>' +
            '</div>';
    } else {
        statusHeader.style.background = '#ffc107';
        statusHeader.style.color = '#856404';
        statusContent.innerHTML = 
            '<div class="alert alert-warning mb-0">' +
            '<h5><i class="fas fa-clock"></i> PENDING</h5>' +
            '<p class="mb-0">This commission is awaiting payment approval.</p>' +
            '</div>';
    }
    
    // Remarks
    if (commission.remarks && commission.remarks.trim() !== '') {
        document.getElementById('detail_remarks_card').style.display = 'block';
        document.getElementById('detail_remarks').textContent = commission.remarks;
    } else {
        document.getElementById('detail_remarks_card').style.display = 'none';
    }
    
    // Created Information
    if (commission.created_at) {
        document.getElementById('detail_created_card').style.display = 'block';
        document.getElementById('detail_created_at').textContent = 
            new Date(commission.created_at).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'});
        document.getElementById('detail_created_by').textContent = commission.created_by_name || 'System';
    }
    
    // Show modal
    $('#viewCommissionModal').modal('show');
}
}
</script>

<?php require_once '../../includes/footer.php'; ?>