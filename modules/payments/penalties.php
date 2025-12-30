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

// Fetch statistics
try {
    $stats_query = "
        SELECT 
            COUNT(DISTINCT ps.schedule_id) as total_overdue_installments,
            COUNT(DISTINCT ps.reservation_id) as total_overdue_reservations,
            COUNT(DISTINCT c.customer_id) as total_affected_customers,
            COALESCE(SUM(ps.installment_amount - ps.paid_amount), 0) as total_outstanding,
            COALESCE(SUM(ps.late_fee), 0) as total_penalties_recorded,
            COALESCE(SUM(CASE 
                WHEN ps.payment_status IN ('overdue', 'unpaid', 'pending_approval') AND DATEDIFF(CURDATE(), ps.due_date) > 0 
                THEN ROUND((ps.installment_amount - ps.paid_amount) * 0.02 * DATEDIFF(CURDATE(), ps.due_date) / 30, 2)
                ELSE 0
            END), 0) as total_penalties_calculated
        FROM payment_schedules ps
        INNER JOIN reservations r ON ps.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects proj ON p.project_id = proj.project_id
        WHERE ps.company_id = ? 
        AND (ps.payment_status = 'overdue' OR (ps.payment_status IN ('unpaid', 'pending_approval') AND ps.due_date < CURDATE()))
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure all stats are numeric
    $stats = array_map(function($val) {
        return $val !== null ? $val : 0;
    }, $stats);
    
} catch (PDOException $e) {
    error_log("Error fetching penalty stats: " . $e->getMessage());
    $stats = [
        'total_overdue_installments' => 0,
        'total_overdue_reservations' => 0,
        'total_affected_customers' => 0,
        'total_outstanding' => 0,
        'total_penalties_recorded' => 0,
        'total_penalties_calculated' => 0
    ];
}

// Build filter conditions
$where_conditions = ["ps.company_id = ?"];
$params = [$company_id];

// Default to overdue status
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'overdue';

if ($status_filter === 'overdue') {
    $where_conditions[] = "(ps.payment_status = 'overdue' OR (ps.payment_status IN ('unpaid', 'pending_approval') AND ps.due_date < CURDATE()))";
} elseif ($status_filter !== 'all') {
    $where_conditions[] = "ps.payment_status = ?";
    $params[] = $status_filter;
}

if (!empty($_GET['project'])) {
    $where_conditions[] = "proj.project_id = ?";
    $params[] = $_GET['project'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "ps.due_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "ps.due_date <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(c.full_name LIKE ? OR r.reservation_number LIKE ? OR proj.project_name LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch penalties
try {
    $penalties_query = "
        SELECT 
            ps.schedule_id,
            ps.reservation_id,
            ps.installment_number,
            ps.due_date,
            ps.installment_amount,
            ps.paid_amount,
            ps.payment_status,
            ps.is_overdue,
            ps.days_overdue,
            ps.late_fee,
            ps.paid_date,
            r.reservation_number,
            r.total_amount as reservation_total,
            r.payment_periods,
            c.customer_id,
            c.full_name as customer_name,
            c.phone1 as customer_phone,
            c.email as customer_email,
            p.plot_number,
            p.block_number,
            proj.project_id,
            proj.project_name,
            proj.project_code,
            (ps.installment_amount - ps.paid_amount) as balance_due,
            DATEDIFF(CURDATE(), ps.due_date) as current_days_overdue,
            CASE 
                WHEN ps.payment_status IN ('overdue', 'unpaid', 'pending_approval') AND DATEDIFF(CURDATE(), ps.due_date) > 0 
                THEN ROUND((ps.installment_amount - ps.paid_amount) * 0.02 * DATEDIFF(CURDATE(), ps.due_date) / 30, 2)
                ELSE COALESCE(ps.late_fee, 0)
            END as calculated_penalty
        FROM payment_schedules ps
        INNER JOIN reservations r ON ps.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects proj ON p.project_id = proj.project_id
        WHERE $where_clause
        ORDER BY ps.due_date DESC, current_days_overdue DESC
    ";
    $stmt = $conn->prepare($penalties_query);
    $stmt->execute($params);
    $penalties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching penalties: " . $e->getMessage());
    $penalties = [];
}

// Fetch projects for filter
try {
    $projects_query = "SELECT project_id, project_name, project_code FROM projects WHERE company_id = ? AND is_active = 1 ORDER BY project_name";
    $stmt = $conn->prepare($projects_query);
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching projects: " . $e->getMessage());
    $projects = [];
}

$page_title = 'Payment Penalties';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
}

.stats-label {
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.table-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.penalty-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.penalty-badge.overdue {
    background: #f8d7da;
    color: #721c24;
}

.penalty-badge.unpaid {
    background: #fff3cd;
    color: #856404;
}

.penalty-badge.paid {
    background: #d4edda;
    color: #155724;
}

.penalty-badge.pending_approval {
    background: #d1ecf1;
    color: #0c5460;
}

.amount-highlight {
    font-weight: 700;
    font-size: 1.1rem;
    color: #dc3545;
}

.penalty-amount {
    font-weight: 700;
    font-size: 1.05rem;
    color: #ffc107;
}

.customer-info {
    display: flex;
    align-items: center;
}

.customer-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.9rem;
    margin-right: 10px;
}

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.days-overdue {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 600;
    background: #f8d7da;
    color: #721c24;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>Payment Penalties
                </h1>
                <p class="text-muted small mb-0 mt-1">Track and manage delayed payment penalties</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-primary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Payments
                    </a>
                    <button onclick="window.print()" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                    <button onclick="exportToExcel()" class="btn btn-success">
                        <i class="fas fa-file-excel me-1"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_overdue_installments']); ?></div>
                    <div class="stats-label">Overdue Installments</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_affected_customers']); ?></div>
                    <div class="stats-label">Affected Customers</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['total_outstanding'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Outstanding Amount</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format((float)$stats['total_penalties_calculated'] / 1000, 1); ?>K</div>
                    <div class="stats-label">Total Penalties (2%/month)</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Customer, reservation..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?php echo (isset($_GET['status']) && $_GET['status'] == 'all') ? 'selected' : ''; ?>>All Status</option>
                        <option value="overdue" <?php echo (!isset($_GET['status']) || $_GET['status'] == 'overdue') ? 'selected' : ''; ?>>Overdue</option>
                        <option value="unpaid" <?php echo (isset($_GET['status']) && $_GET['status'] == 'unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                        <option value="pending_approval" <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending_approval') ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="paid" <?php echo (isset($_GET['status']) && $_GET['status'] == 'paid') ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Project</label>
                    <select name="project" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['project_id']; ?>" <?php echo (isset($_GET['project']) && $_GET['project'] == $project['project_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($project['project_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Date From</label>
                    <input type="date" 
                           name="date_from" 
                           class="form-control"
                           value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Date To</label>
                    <input type="date" 
                           name="date_to" 
                           class="form-control"
                           value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
            <div class="mt-2">
                <a href="penalties.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-redo me-1"></i> Reset Filters
                </a>
            </div>
        </div>

        <!-- Penalties Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover" id="penaltiesTable">
                    <thead class="table-light">
                        <tr>
                            <th>Installment</th>
                            <th>Customer</th>
                            <th>Project</th>
                            <th>Plot</th>
                            <th>Due Date</th>
                            <th>Amount Due</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Days Overdue</th>
                            <th>Penalty</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($penalties)): ?>
                        <?php foreach ($penalties as $penalty): ?>
                        <tr>
                            <td>
                                <div class="fw-bold">#<?php echo $penalty['installment_number']; ?>/<?php echo $penalty['payment_periods']; ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($penalty['reservation_number']); ?></small>
                            </td>
                            <td>
                                <div class="customer-info">
                                    <div class="customer-avatar">
                                        <?php echo strtoupper(substr($penalty['customer_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($penalty['customer_name']); ?></div>
                                        <?php if (!empty($penalty['customer_phone'])): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($penalty['customer_phone']); ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($penalty['project_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($penalty['project_code']); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo htmlspecialchars($penalty['block_number']); ?>-<?php echo htmlspecialchars($penalty['plot_number']); ?>
                                </span>
                            </td>
                            <td>
                                <div>
                                    <i class="fas fa-calendar text-danger me-1"></i>
                                    <?php echo date('M d, Y', strtotime($penalty['due_date'])); ?>
                                </div>
                                <?php if ($penalty['current_days_overdue'] > 0): ?>
                                <small class="text-danger">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    <?php echo $penalty['current_days_overdue']; ?> days late
                                </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-bold">
                                    TSH <?php echo number_format((float)$penalty['installment_amount'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-success fw-bold">
                                    TSH <?php echo number_format((float)$penalty['paid_amount'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format((float)$penalty['balance_due'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($penalty['current_days_overdue'] > 0): ?>
                                <span class="days-overdue">
                                    <?php echo $penalty['current_days_overdue']; ?> days
                                </span>
                                <?php else: ?>
                                <span class="badge bg-success">On Time</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="penalty-amount">
                                    TSH <?php echo number_format((float)$penalty['calculated_penalty'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="penalty-badge <?php echo $penalty['payment_status']; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $penalty['payment_status'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <?php if ($penalty['payment_status'] !== 'paid'): ?>
                                    <a href="record.php?reservation_id=<?php echo $penalty['reservation_id']; ?>&schedule_id=<?php echo $penalty['schedule_id']; ?>" 
                                       class="btn btn-outline-success action-btn"
                                       title="Record Payment">
                                        <i class="fas fa-money-bill"></i>
                                    </a>
                                    <?php endif; ?>
                                    <button type="button" 
                                            class="btn btn-outline-info action-btn"
                                            onclick="alert('SMS reminder to: <?php echo htmlspecialchars($penalty['customer_phone']); ?>')"
                                            title="Send Reminder">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($penalties)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end">TOTALS:</th>
                            <th>
                                TSH <?php echo number_format((float)array_sum(array_column($penalties, 'installment_amount')), 0); ?>
                            </th>
                            <th>
                                TSH <?php echo number_format((float)array_sum(array_column($penalties, 'paid_amount')), 0); ?>
                            </th>
                            <th>
                                <span class="amount-highlight">
                                    TSH <?php echo number_format((float)array_sum(array_column($penalties, 'balance_due')), 0); ?>
                                </span>
                            </th>
                            <th></th>
                            <th>
                                <span class="penalty-amount">
                                    TSH <?php echo number_format((float)array_sum(array_column($penalties, 'calculated_penalty')), 2); ?>
                                </span>
                            </th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            
            <?php if (empty($penalties)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-check-circle fa-3x mb-3 d-block text-success"></i>
                <p class="mb-2">No penalty records found.</p>
                <p class="small">All payments are up to date!</p>
            </div>
            <?php endif; ?>
        </div>

    </div>
</section>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
$(document).ready(function() {
    <?php if (!empty($penalties)): ?>
    $('#penaltiesTable').DataTable({
        order: [[4, 'desc']],
        pageLength: 25,
        responsive: true,
        columnDefs: [
            { orderable: false, targets: -1 }
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search penalties..."
        }
    });
    <?php endif; ?>
});

function exportToExcel() {
    const table = document.getElementById('penaltiesTable');
    const wb = XLSX.utils.table_to_book(table, {sheet: 'Penalties'});
    XLSX.writeFile(wb, 'payment_penalties_' + new Date().toISOString().split('T')[0] + '.xlsx');
}
</script>

<?php 
require_once '../../includes/footer.php';
?>