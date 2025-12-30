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

// ==================== FILTER PARAMETERS ====================
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$project_filter = $_GET['project'] ?? '';
$payment_type_filter = $_GET['payment_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$overdue_only = isset($_GET['overdue_only']) ? 1 : 0;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

// ==================== GET PROJECTS FOR FILTER ====================
$projects_query = $conn->prepare("
    SELECT DISTINCT pr.project_id, pr.project_name 
    FROM projects pr
    INNER JOIN plots pl ON pr.project_id = pl.project_id
    INNER JOIN reservations r ON pl.plot_id = r.plot_id
    WHERE pr.company_id = ?
    ORDER BY pr.project_name
");
$projects_query->execute([$company_id]);
$projects = $projects_query->fetchAll(PDO::FETCH_ASSOC);

// ==================== BASE QUERY ====================
$where_conditions = ["r.company_id = ?"];
$params = [$company_id];

// Apply project filter
if (!empty($project_filter)) {
    $where_conditions[] = "pr.project_id = ?";
    $params[] = $project_filter;
}

// Build the WHERE clause
$where_sql = implode(' AND ', $where_conditions);

// Get ALL reservations with filters
$query = "
    SELECT 
        r.reservation_id,
        r.reservation_number,
        r.total_amount,
        r.down_payment,
        r.payment_periods,
        r.reservation_date,
        r.status as reservation_status,
        c.customer_id,
        c.full_name as customer_name,
        COALESCE(c.phone, c.alternative_phone) as phone,
        pl.plot_number,
        pr.project_id,
        pr.project_name
    FROM reservations r
    INNER JOIN customers c ON r.customer_id = c.customer_id
    INNER JOIN plots pl ON r.plot_id = pl.plot_id
    INNER JOIN projects pr ON pl.project_id = pr.project_id
    WHERE $where_sql
    ORDER BY r.reservation_date DESC, r.reservation_number
";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================== BUILD ALL SCHEDULES ====================
$all_schedules = [];
$sn = 1;

foreach ($reservations as $r) {
    // Skip if payment_periods is 0 or null
    if (empty($r['payment_periods']) || $r['payment_periods'] <= 0) {
        continue;
    }

    $installment_amount = round(($r['total_amount'] - $r['down_payment']) / $r['payment_periods'], 2);

    // ==================== DOWN PAYMENT ROW ====================
    $down_paid = 0;
    $down_payment_date = null;
    $down_payment_status = 'unpaid';
    
    $down_payment_query = $conn->prepare("
        SELECT SUM(amount) as total_amount, MAX(payment_date) as payment_date, status 
        FROM payments 
        WHERE reservation_id = ? 
        AND payment_type = 'down_payment'
        AND status = 'approved'
        GROUP BY reservation_id
    ");
    $down_payment_query->execute([$r['reservation_id']]);
    $dp = $down_payment_query->fetch(PDO::FETCH_ASSOC);
    
    if ($dp && $dp['total_amount']) {
        $down_paid = $dp['total_amount'];
        $down_payment_date = $dp['payment_date'];
        $down_payment_status = 'approved';
    } else {
        // Check for pending payments
        $pending_dp = $conn->prepare("
            SELECT status 
            FROM payments 
            WHERE reservation_id = ? 
            AND payment_type = 'down_payment'
            ORDER BY payment_date DESC
            LIMIT 1
        ");
        $pending_dp->execute([$r['reservation_id']]);
        $pending_status = $pending_dp->fetch(PDO::FETCH_ASSOC);
        if ($pending_status) {
            $down_payment_status = $pending_status['status'];
        }
    }

    $down_balance = $r['down_payment'] - $down_paid;

    // Determine down payment row styling
    if ($down_paid >= $r['down_payment']) {
        $row_class = 'table-success';
        $status = 'paid';
        $badge = 'paid';
    } elseif ($down_paid > 0) {
        $row_class = 'table-warning';
        $status = 'partially paid';
        $badge = 'partially-paid';
    } elseif ($down_payment_status === 'pending_approval') {
        $row_class = 'table-info';
        $status = 'pending approval';
        $badge = 'pending-approval';
    } elseif ($down_payment_status === 'rejected') {
        $row_class = 'table-danger';
        $status = 'rejected';
        $badge = 'rejected';
    } else {
        $row_class = 'table-danger';
        $status = 'not paid';
        $badge = 'overdue';
    }

    $all_schedules[] = [
        'sn' => $sn++,
        'due_date' => $r['reservation_date'],
        'customer_name' => $r['customer_name'],
        'phone' => $r['phone'] ?? '',
        'reservation_number' => $r['reservation_number'],
        'plot_number' => $r['plot_number'],
        'project_id' => $r['project_id'],
        'project_name' => $r['project_name'],
        'installment_number' => 'DP',
        'payment_periods' => $r['payment_periods'],
        'installment_amount' => $r['down_payment'],
        'paid_amount' => $down_paid,
        'balance' => $down_balance,
        'late_fee' => 0,
        'days_overdue' => 0,
        'row_class' => $row_class,
        'status_text' => $status,
        'badge' => $badge,
        'is_downpayment' => true,
        'payment_status' => $down_payment_status
    ];

    // ==================== INSTALLMENTS ====================
    $installment_payments_query = $conn->prepare("
        SELECT 
            payment_id,
            SUM(amount) as total_amount,
            MAX(payment_date) as payment_date,
            status
        FROM payments 
        WHERE reservation_id = ? 
        AND payment_type = 'installment'
        AND status = 'approved'
        GROUP BY reservation_id
    ");
    $installment_payments_query->execute([$r['reservation_id']]);
    $installment_data = $installment_payments_query->fetch(PDO::FETCH_ASSOC);
    
    $total_installments_paid = $installment_data ? $installment_data['total_amount'] : 0;
    $remaining_paid = $total_installments_paid;
    
    for ($i = 1; $i <= $r['payment_periods']; $i++) {
        $due_date = date('Y-m-d', strtotime($r['reservation_date'] . " + $i months"));

        // Allocate payments to installments in order
        $paid = 0;
        if ($remaining_paid >= $installment_amount) {
            $paid = $installment_amount;
            $remaining_paid -= $installment_amount;
        } elseif ($remaining_paid > 0) {
            $paid = $remaining_paid;
            $remaining_paid = 0;
        }

        $balance = $installment_amount - $paid;
        $days_overdue = (strtotime($due_date) < time()) ? (int)((time() - strtotime($due_date)) / 86400) : 0;
        $is_overdue = $days_overdue > 0 && $balance > 0;

        // Determine row styling
        if ($paid >= $installment_amount) {
            $row_class = 'table-success';
            $status = 'paid';
            $badge = 'paid';
        } elseif ($paid > 0) {
            $row_class = 'table-warning';
            $status = 'partially paid';
            $badge = 'partially-paid';
        } elseif ($is_overdue) {
            $row_class = 'table-danger';
            $status = 'overdue';
            $badge = 'overdue';
        } else {
            $row_class = '';
            $status = 'pending';
            $badge = 'pending';
        }

        $all_schedules[] = [
            'sn' => $sn++,
            'due_date' => $due_date,
            'customer_name' => $r['customer_name'],
            'phone' => $r['phone'] ?? '',
            'reservation_number' => $r['reservation_number'],
            'plot_number' => $r['plot_number'],
            'project_id' => $r['project_id'],
            'project_name' => $r['project_name'],
            'installment_number' => $i,
            'payment_periods' => $r['payment_periods'],
            'installment_amount' => $installment_amount,
            'paid_amount' => $paid,
            'balance' => $balance,
            'late_fee' => 0,
            'days_overdue' => $days_overdue,
            'row_class' => $row_class,
            'status_text' => $status,
            'badge' => $badge,
            'is_downpayment' => false,
            'payment_status' => $paid > 0 ? 'approved' : 'unpaid'
        ];
    }
}

// ==================== APPLY FILTERS ====================
$filtered_schedules = $all_schedules;

// Universal search
if (!empty($search)) {
    $filtered_schedules = array_filter($filtered_schedules, function($s) use ($search) {
        $search_lower = strtolower($search);
        return (
            stripos($s['customer_name'], $search) !== false ||
            stripos($s['phone'], $search) !== false ||
            stripos($s['reservation_number'], $search) !== false ||
            stripos($s['plot_number'], $search) !== false ||
            stripos($s['project_name'], $search) !== false
        );
    });
}

// Status filter
if (!empty($status_filter)) {
    $filtered_schedules = array_filter($filtered_schedules, function($s) use ($status_filter) {
        return $s['badge'] === $status_filter;
    });
}

// Payment type filter
if (!empty($payment_type_filter)) {
    $filtered_schedules = array_filter($filtered_schedules, function($s) use ($payment_type_filter) {
        if ($payment_type_filter === 'down_payment') {
            return $s['is_downpayment'] === true;
        } elseif ($payment_type_filter === 'installment') {
            return $s['is_downpayment'] === false;
        }
        return true;
    });
}

// Date range filter
if (!empty($date_from)) {
    $filtered_schedules = array_filter($filtered_schedules, function($s) use ($date_from) {
        return $s['due_date'] >= $date_from;
    });
}

if (!empty($date_to)) {
    $filtered_schedules = array_filter($filtered_schedules, function($s) use ($date_to) {
        return $s['due_date'] <= $date_to;
    });
}

// Overdue only filter
if ($overdue_only) {
    $filtered_schedules = array_filter($filtered_schedules, function($s) {
        return $s['badge'] === 'overdue';
    });
}

// Re-index array after filtering
$filtered_schedules = array_values($filtered_schedules);

// ==================== PAGINATION ====================
$total_records = count($filtered_schedules);
$total_pages = ceil($total_records / $per_page);
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $per_page;

$schedules = array_slice($filtered_schedules, $offset, $per_page);

// ==================== CALCULATE TOTALS ====================
$total_expected = array_sum(array_column($filtered_schedules, 'installment_amount'));
$total_collected = array_sum(array_column($filtered_schedules, 'paid_amount'));
$total_outstanding = $total_expected - $total_collected;
$total_overdue = 0;

foreach ($filtered_schedules as $s) {
    if ($s['days_overdue'] > 0 && $s['balance'] > 0 && $s['payment_status'] !== 'pending_approval') {
        $total_overdue += $s['balance'];
    }
}

$page_title = 'Payment Recovery';
require_once '../../includes/header.php';
?>

<style>
.stats-card { 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px;
    padding: 1.5rem;
    color: white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    margin-bottom: 1rem;
    transition: all 0.3s;
    border: none;
}
.stats-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.25);
}
.stats-card.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stats-card.success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
.stats-card.danger { background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%); }
.stats-card.warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }

.stats-icon {
    font-size: 3rem;
    opacity: 0.3;
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
}

.stats-number {
    font-size: 2rem;
    font-weight: 800;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
}

.stats-label {
    font-size: 0.9rem;
    text-transform: uppercase;
    font-weight: 600;
    opacity: 0.95;
    margin-top: 0.5rem;
}

.filter-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
}

.filter-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e9ecef;
}

.filter-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 10px;
}

.search-box {
    position: relative;
    flex: 1;
    max-width: 400px;
}

.search-box input {
    width: 100%;
    padding: 12px 45px 12px 20px;
    border: 2px solid #e9ecef;
    border-radius: 50px;
    font-size: 0.95rem;
    transition: all 0.3s;
}

.search-box input:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    outline: none;
}

.search-icon {
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
}

.filter-group {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: end;
}

.filter-item {
    flex: 1;
    min-width: 180px;
}

.filter-item label {
    display: block;
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.filter-item select,
.filter-item input {
    width: 100%;
    padding: 10px 15px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 0.9rem;
    transition: all 0.3s;
}

.filter-item select:focus,
.filter-item input:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    outline: none;
}

.btn-filter {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 10px 25px;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s;
    white-space: nowrap;
}

.btn-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
    color: white;
}

.btn-reset {
    background: white;
    color: #6c757d;
    border: 2px solid #e9ecef;
    padding: 10px 25px;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s;
}

.btn-reset:hover {
    background: #f8f9fa;
    border-color: #dc3545;
    color: #dc3545;
}

.active-filters {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 1rem;
}

.filter-badge {
    background: #e7f3ff;
    color: #0066cc;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.filter-badge i {
    cursor: pointer;
    transition: all 0.2s;
}

.filter-badge i:hover {
    color: #dc3545;
}

.legend-container { 
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    padding: 15px;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    border-radius: 12px;
    margin: 20px 0;
}

.legend-item { 
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    color: #2c3e50;
}

.legend-color { 
    width: 30px;
    height: 24px;
    border-radius: 6px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.legend-color.paid { background: #d4edda; }
.legend-color.partially { background: #fff3cd; }
.legend-color.overdue { background: #f8d7da; }
.legend-color.pending { background: #fff; border: 2px solid #dee2e6; }
.legend-color.pending-approval { background: #d1ecf1; }
.legend-color.rejected { background: #f5c6cb; }

.status-badge { 
    padding: 7px 16px;
    border-radius: 30px;
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
}

.status-badge.paid { background: #28a745; color: white; }
.status-badge.partially-paid { background: #ffc107; color: black; }
.status-badge.overdue { background: #dc3545; color: white; }
.status-badge.pending { background: #6c757d; color: white; }
.status-badge.pending-approval { background: #17a2b8; color: white; }
.status-badge.rejected { background: #dc3545; color: white; }

.dp-badge { 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: bold;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}

.installment-circle { 
    display: inline-block;
    width: 42px;
    height: 42px;
    line-height: 42px;
    text-align: center;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: bold;
    font-size: 1.05rem;
    box-shadow: 0 3px 10px rgba(102, 126, 234, 0.3);
}

.table-info {
    background-color: #d1ecf1 !important;
}

.pagination-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 2rem;
    padding: 1.5rem;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.pagination {
    margin: 0;
}

.pagination .page-link {
    border: none;
    color: #667eea;
    font-weight: 600;
    margin: 0 3px;
    border-radius: 8px;
    transition: all 0.3s;
}

.pagination .page-link:hover {
    background: #667eea;
    color: white;
}

.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
}

.export-buttons {
    display: flex;
    gap: 10px;
}

.btn-export {
    padding: 8px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.3s;
}

.btn-export-excel {
    background: #217346;
    color: white;
    border: none;
}

.btn-export-excel:hover {
    background: #1a5c37;
    color: white;
    transform: translateY(-2px);
}

.btn-export-pdf {
    background: #dc3545;
    color: white;
    border: none;
}

.btn-export-pdf:hover {
    background: #c82333;
    color: white;
    transform: translateY(-2px);
}

.table-hover tbody tr {
    transition: all 0.3s;
}

.table-hover tbody tr:hover {
    transform: scale(1.01);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.info-badge {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: white;
    padding: 8px 15px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    display: inline-block;
}

.checkbox-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
}

.checkbox-wrapper input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.checkbox-wrapper label {
    margin: 0;
    cursor: pointer;
    font-weight: 600;
    color: #495057;
}
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <h1 class="m-0 fw-bold" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
            <i class="fas fa-money-bill-wave me-2"></i>Payment Recovery Dashboard
        </h1>
        <p class="text-muted mb-0">Advanced payment tracking with filters, search, and pagination</p>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary position-relative">
                    <i class="fas fa-chart-line stats-icon"></i>
                    <div class="stats-number">TSH <?=number_format($total_expected/1000000,1)?>M</div>
                    <div class="stats-label">Total Expected</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success position-relative">
                    <i class="fas fa-check-circle stats-icon"></i>
                    <div class="stats-number">TSH <?=number_format($total_collected/1000000,1)?>M</div>
                    <div class="stats-label">Collected (Approved)</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger position-relative">
                    <i class="fas fa-exclamation-circle stats-icon"></i>
                    <div class="stats-number">TSH <?=number_format($total_outstanding/1000000,1)?>M</div>
                    <div class="stats-label">Outstanding Balance</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning position-relative">
                    <i class="fas fa-clock stats-icon"></i>
                    <div class="stats-number">TSH <?=number_format($total_overdue/1000000,1)?>M</div>
                    <div class="stats-label">Overdue Amount</div>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card">
            <div class="filter-header">
                <div class="filter-title">
                    <i class="fas fa-filter"></i>
                    <span>Advanced Filters</span>
                </div>
                <div class="search-box">
                    <input type="text" 
                           id="universalSearch" 
                           placeholder="Search by customer, phone, reservation, plot, project..."
                           value="<?=htmlspecialchars($search)?>">
                    <i class="fas fa-search search-icon"></i>
                </div>
            </div>

            <form method="GET" id="filterForm">
                <input type="hidden" name="search" id="searchInput" value="<?=htmlspecialchars($search)?>">
                
                <div class="filter-group">
                    <div class="filter-item">
                        <label><i class="fas fa-tasks me-1"></i>Payment Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="paid" <?=$status_filter==='paid'?'selected':''?>>Paid</option>
                            <option value="partially-paid" <?=$status_filter==='partially-paid'?'selected':''?>>Partially Paid</option>
                            <option value="overdue" <?=$status_filter==='overdue'?'selected':''?>>Overdue</option>
                            <option value="pending" <?=$status_filter==='pending'?'selected':''?>>Pending</option>
                            <option value="pending-approval" <?=$status_filter==='pending-approval'?'selected':''?>>Pending Approval</option>
                            <option value="rejected" <?=$status_filter==='rejected'?'selected':''?>>Rejected</option>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label><i class="fas fa-building me-1"></i>Project</label>
                        <select name="project" class="form-select">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?=$proj['project_id']?>" <?=$project_filter==$proj['project_id']?'selected':''?>>
                                    <?=htmlspecialchars($proj['project_name'])?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label><i class="fas fa-wallet me-1"></i>Payment Type</label>
                        <select name="payment_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="down_payment" <?=$payment_type_filter==='down_payment'?'selected':''?>>Down Payment</option>
                            <option value="installment" <?=$payment_type_filter==='installment'?'selected':''?>>Installment</option>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label><i class="fas fa-calendar-alt me-1"></i>Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?=htmlspecialchars($date_from)?>">
                    </div>

                    <div class="filter-item">
                        <label><i class="fas fa-calendar-alt me-1"></i>Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?=htmlspecialchars($date_to)?>">
                    </div>

                    <div class="filter-item">
                        <label><i class="fas fa-list me-1"></i>Per Page</label>
                        <select name="per_page" class="form-select">
                            <option value="25" <?=$per_page==25?'selected':''?>>25</option>
                            <option value="50" <?=$per_page==50?'selected':''?>>50</option>
                            <option value="100" <?=$per_page==100?'selected':''?>>100</option>
                            <option value="200" <?=$per_page==200?'selected':''?>>200</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="checkbox-wrapper">
                        <input type="checkbox" 
                               name="overdue_only" 
                               id="overdue_only" 
                               <?=$overdue_only?'checked':''?>>
                        <label for="overdue_only">
                            <i class="fas fa-exclamation-triangle text-danger me-1"></i>
                            Show Overdue Only
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-filter">
                            <i class="fas fa-search me-2"></i>Apply Filters
                        </button>
                        <a href="?" class="btn btn-reset">
                            <i class="fas fa-redo me-2"></i>Reset
                        </a>
                    </div>
                </div>
            </form>

            <!-- Active Filters Display -->
            <?php if ($search || $status_filter || $project_filter || $payment_type_filter || $date_from || $date_to || $overdue_only): ?>
            <div class="active-filters">
                <span class="info-badge"><i class="fas fa-filter me-2"></i>Active Filters:</span>
                
                <?php if ($search): ?>
                <span class="filter-badge">
                    Search: "<?=htmlspecialchars($search)?>"
                    <i class="fas fa-times" onclick="removeFilter('search')"></i>
                </span>
                <?php endif; ?>

                <?php if ($status_filter): ?>
                <span class="filter-badge">
                    Status: <?=ucwords(str_replace('-', ' ', $status_filter))?>
                    <i class="fas fa-times" onclick="removeFilter('status')"></i>
                </span>
                <?php endif; ?>

                <?php if ($project_filter): 
                    $proj_name = array_filter($projects, fn($p) => $p['project_id'] == $project_filter);
                    $proj_name = reset($proj_name);
                ?>
                <span class="filter-badge">
                    Project: <?=htmlspecialchars($proj_name['project_name'] ?? 'Unknown')?>
                    <i class="fas fa-times" onclick="removeFilter('project')"></i>
                </span>
                <?php endif; ?>

                <?php if ($payment_type_filter): ?>
                <span class="filter-badge">
                    Type: <?=ucwords(str_replace('_', ' ', $payment_type_filter))?>
                    <i class="fas fa-times" onclick="removeFilter('payment_type')"></i>
                </span>
                <?php endif; ?>

                <?php if ($date_from): ?>
                <span class="filter-badge">
                    From: <?=date('d M Y', strtotime($date_from))?>
                    <i class="fas fa-times" onclick="removeFilter('date_from')"></i>
                </span>
                <?php endif; ?>

                <?php if ($date_to): ?>
                <span class="filter-badge">
                    To: <?=date('d M Y', strtotime($date_to))?>
                    <i class="fas fa-times" onclick="removeFilter('date_to')"></i>
                </span>
                <?php endif; ?>

                <?php if ($overdue_only): ?>
                <span class="filter-badge">
                    Overdue Only
                    <i class="fas fa-times" onclick="removeFilter('overdue_only')"></i>
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Results Info -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <span class="info-badge">
                    <i class="fas fa-list me-2"></i>
                    Showing <?=number_format(count($schedules))?> of <?=number_format($total_records)?> records
                </span>
            </div>
            <div class="export-buttons">
                <button class="btn btn-export btn-export-excel" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-2"></i>Export Excel
                </button>
                <button class="btn btn-export btn-export-pdf" onclick="exportToPDF()">
                    <i class="fas fa-file-pdf me-2"></i>Export PDF
                </button>
            </div>
        </div>

        <!-- Legend -->
        <div class="legend-container">
            <div class="legend-item">
                <div class="legend-color paid"></div>
                <span>Paid (Approved)</span>
            </div>
            <div class="legend-item">
                <div class="legend-color partially"></div>
                <span>Partially Paid</span>
            </div>
            <div class="legend-item">
                <div class="legend-color overdue"></div>
                <span>Overdue / Not Paid</span>
            </div>
            <div class="legend-item">
                <div class="legend-color pending"></div>
                <span>Pending (Not Due)</span>
            </div>
            <div class="legend-item">
                <span class="dp-badge">DP</span>
                <span>Down Payment</span>
            </div>
        </div>

        <!-- Table -->
        <div class="card shadow-lg border-0" style="border-radius: 15px; overflow: hidden;">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-primary">
                            <tr>
                                <th>SN</th>
                                <th>Due Date</th>
                                <th>Customer</th>
                                <th>Reservation</th>
                                <th>Total Due</th>
                                <th>Inst Amount</th>
                                <th>Inst #</th>
                                <th>Penalty</th>
                                <th>Amount Paid</th>
                                <th>Outstanding</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($schedules)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No payment schedules found matching your filters</p>
                                    <a href="?" class="btn btn-primary mt-2">
                                        <i class="fas fa-redo me-2"></i>Clear Filters
                                    </a>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($schedules as $s): ?>
                                <tr class="<?=$s['row_class']?>">
                                    <td class="text-center fw-bold"><?=$s['sn']?></td>
                                    <td><?=date('d M Y', strtotime($s['due_date']))?></td>
                                    <td>
                                        <div class="fw-bold"><?=htmlspecialchars($s['customer_name'])?></div>
                                        <?php if ($s['phone']): ?>
                                            <small class="text-muted">
                                                <i class="fas fa-phone-alt me-1"></i><?=htmlspecialchars($s['phone'])?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-primary"><?=$s['reservation_number']?></div>
                                        <small class="text-muted">
                                            <i class="fas fa-map-marker-alt me-1"></i>Plot <?=$s['plot_number']?> - <?=htmlspecialchars($s['project_name'])?>
                                        </small>
                                    </td>
                                    <td class="text-end fw-bold">TSH <?=number_format($s['installment_amount'] + $s['late_fee'])?></td>
                                    <td class="text-end fw-bold">TSH <?=number_format($s['installment_amount'])?></td>
                                    <td class="text-center">
                                        <?php if ($s['is_downpayment']): ?>
                                            <span class="dp-badge">DP</span>
                                        <?php else: ?>
                                            <div class="installment-circle"><?=$s['installment_number']?></div>
                                            <small class="d-block text-muted mt-1">of <?=$s['payment_periods']?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-danger fw-bold">TSH <?=number_format($s['late_fee'])?></td>
                                    <td class="text-end text-success fw-bold">TSH <?=number_format($s['paid_amount'])?></td>
                                    <td class="text-end text-danger fw-bold">TSH <?=number_format($s['balance'])?></td>
                                    <td>
                                        <span class="status-badge <?=$s['badge']?>"><?=$s['status_text']?></span>
                                        <?php if ($s['days_overdue'] > 0 && !$s['is_downpayment']): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-danger">
                                                    <i class="fas fa-exclamation-triangle me-1"></i><?=$s['days_overdue']?> days overdue
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">TOTALS (Filtered Results):</td>
                                <td class="text-end">TSH <?=number_format($total_expected)?></td>
                                <td colspan="3"></td>
                                <td class="text-end text-success">TSH <?=number_format($total_collected)?></td>
                                <td class="text-end text-danger">TSH <?=number_format($total_outstanding)?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-wrapper">
            <div>
                <span class="text-muted">
                    Page <?=$page?> of <?=$total_pages?> 
                    (<?=number_format($total_records)?> total records)
                </span>
            </div>
            
            <nav>
                <ul class="pagination mb-0">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?=http_build_query(array_merge($_GET, ['page' => 1]))?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?<?=http_build_query(array_merge($_GET, ['page' => $page - 1]))?>">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                    <li class="page-item <?=$i === $page ? 'active' : ''?>">
                        <a class="page-link" href="?<?=http_build_query(array_merge($_GET, ['page' => $i]))?>">
                            <?=$i?>
                        </a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?=http_build_query(array_merge($_GET, ['page' => $page + 1]))?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?<?=http_build_query(array_merge($_GET, ['page' => $total_pages]))?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

    </div>
</section>

<script>
// Universal search with debounce
let searchTimeout;
document.getElementById('universalSearch').addEventListener('input', function(e) {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        document.getElementById('searchInput').value = e.target.value;
        document.getElementById('filterForm').submit();
    }, 500);
});

// Remove filter function
function removeFilter(filterName) {
    const url = new URL(window.location.href);
    url.searchParams.delete(filterName);
    window.location.href = url.toString();
}

// Export to Excel
function exportToExcel() {
    alert('Excel export functionality would be implemented here');
    // You can implement actual Excel export using libraries like PHPExcel or JavaScript libraries
}

// Export to PDF
function exportToPDF() {
    alert('PDF export functionality would be implemented here');
    // You can implement actual PDF export using libraries like TCPDF or FPDF
}

// Auto-submit on filter change (optional)
document.querySelectorAll('select[name="status"], select[name="project"], select[name="payment_type"]').forEach(select => {
    select.addEventListener('change', function() {
        // Uncomment to auto-submit on select change
        // document.getElementById('filterForm').submit();
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>