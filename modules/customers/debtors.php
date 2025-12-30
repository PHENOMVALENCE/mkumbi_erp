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
            COUNT(DISTINCT r.customer_id) as total_debtors,
            COALESCE(SUM(r.total_amount), 0) as total_amount_due,
            COALESCE(SUM(payments.total_paid), 0) as total_received,
            COALESCE(SUM(r.total_amount) - SUM(COALESCE(payments.total_paid, 0)), 0) as total_outstanding,
            COUNT(CASE WHEN DATEDIFF(CURDATE(), last_payment.last_payment_date) <= 30 THEN 1 END) as current_due,
            COUNT(CASE WHEN DATEDIFF(CURDATE(), last_payment.last_payment_date) BETWEEN 31 AND 60 THEN 1 END) as days_30,
            COUNT(CASE WHEN DATEDIFF(CURDATE(), last_payment.last_payment_date) BETWEEN 61 AND 90 THEN 1 END) as days_60,
            COUNT(CASE WHEN DATEDIFF(CURDATE(), last_payment.last_payment_date) > 90 THEN 1 END) as days_90_plus
        FROM reservations r
        LEFT JOIN (
            SELECT reservation_id, SUM(amount) as total_paid
            FROM payments
            WHERE status = 'approved'
            GROUP BY reservation_id
        ) payments ON r.reservation_id = payments.reservation_id
        LEFT JOIN (
            SELECT reservation_id, MAX(payment_date) as last_payment_date
            FROM payments
            WHERE status = 'approved'
            GROUP BY reservation_id
        ) last_payment ON r.reservation_id = last_payment.reservation_id
        WHERE r.company_id = ? 
        AND r.is_active = 1 
        AND r.status IN ('active', 'completed')
        AND (r.total_amount - COALESCE(payments.total_paid, 0)) > 0
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure all values are properly typed
    $stats = [
        'total_debtors' => (int)($stats['total_debtors'] ?? 0),
        'total_amount_due' => (float)($stats['total_amount_due'] ?? 0),
        'total_received' => (float)($stats['total_received'] ?? 0),
        'total_outstanding' => (float)($stats['total_outstanding'] ?? 0),
        'current_due' => (int)($stats['current_due'] ?? 0),
        'days_30' => (int)($stats['days_30'] ?? 0),
        'days_60' => (int)($stats['days_60'] ?? 0),
        'days_90_plus' => (int)($stats['days_90_plus'] ?? 0)
    ];
} catch (PDOException $e) {
    error_log("Error fetching debtor stats: " . $e->getMessage());
    $stats = [
        'total_debtors' => 0,
        'total_amount_due' => 0,
        'total_received' => 0,
        'total_outstanding' => 0,
        'current_due' => 0,
        'days_30' => 0,
        'days_60' => 0,
        'days_90_plus' => 0
    ];
}

// Build filter conditions
$where_conditions = ["r.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['aging'])) {
    $aging = $_GET['aging'];
    if ($aging == 'current') {
        $where_conditions[] = "DATEDIFF(CURDATE(), last_payment.last_payment_date) <= 30";
    } elseif ($aging == '30') {
        $where_conditions[] = "DATEDIFF(CURDATE(), last_payment.last_payment_date) BETWEEN 31 AND 60";
    } elseif ($aging == '60') {
        $where_conditions[] = "DATEDIFF(CURDATE(), last_payment.last_payment_date) BETWEEN 61 AND 90";
    } elseif ($aging == '90') {
        $where_conditions[] = "DATEDIFF(CURDATE(), last_payment.last_payment_date) > 90";
    }
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(c.full_name LIKE ? OR c.phone LIKE ? OR c.phone1 LIKE ? OR r.reservation_number LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch debtors
try {
    $debtors_query = "
        SELECT 
            c.customer_id,
            c.full_name as customer_name,
            COALESCE(c.phone, c.phone1) as phone,
            c.email,
            COUNT(DISTINCT r.reservation_id) as total_reservations,
            COALESCE(SUM(r.total_amount), 0) as total_amount_due,
            COALESCE(SUM(payments.total_paid), 0) as amount_received,
            COALESCE(SUM(r.total_amount) - SUM(COALESCE(payments.total_paid, 0)), 0) as outstanding_balance,
            MAX(last_payment.last_payment_date) as last_payment_date,
            DATEDIFF(CURDATE(), MAX(last_payment.last_payment_date)) as days_overdue,
            GROUP_CONCAT(DISTINCT CONCAT(p.plot_number, ' (', pr.project_name, ')') SEPARATOR ', ') as plots
        FROM customers c
        INNER JOIN reservations r ON c.customer_id = r.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects pr ON p.project_id = pr.project_id
        LEFT JOIN (
            SELECT reservation_id, SUM(amount) as total_paid
            FROM payments
            WHERE status = 'approved'
            GROUP BY reservation_id
        ) payments ON r.reservation_id = payments.reservation_id
        LEFT JOIN (
            SELECT reservation_id, MAX(payment_date) as last_payment_date
            FROM payments
            WHERE status = 'approved'
            GROUP BY reservation_id
        ) last_payment ON r.reservation_id = last_payment.reservation_id
        WHERE $where_clause
        AND r.is_active = 1 
        AND r.status IN ('active', 'completed')
        GROUP BY c.customer_id, c.full_name, c.phone, c.phone1, c.email
        HAVING outstanding_balance > 0
        ORDER BY outstanding_balance DESC
    ";
    $stmt = $conn->prepare($debtors_query);
    $stmt->execute($params);
    $debtors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching debtors: " . $e->getMessage());
    $debtors = [];
}

$page_title = 'Debtors Management';
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
.stats-card.purple { border-left-color: #6f42c1; }
.stats-card.orange { border-left-color: #fd7e14; }
.stats-card.dark { border-left-color: #343a40; }

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

.overdue-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.overdue-badge.current {
    background: #d4edda;
    color: #155724;
}

.overdue-badge.days-30 {
    background: #fff3cd;
    color: #856404;
}

.overdue-badge.days-60 {
    background: #fff3cd;
    color: #856404;
}

.overdue-badge.days-90 {
    background: #f8d7da;
    color: #721c24;
}

.balance-amount {
    font-weight: 700;
    font-size: 1.1rem;
}

.customer-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
}

.progress-custom {
    height: 8px;
    border-radius: 10px;
}

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.plots-list {
    font-size: 0.85rem;
    color: #6c757d;
    max-width: 250px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-clock text-danger me-2"></i>Debtors Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Track and manage outstanding customer balances</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-users me-1"></i> All Customers
                    </a>
                    <button class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-print me-1"></i> Print Report
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
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format($stats['total_debtors']); ?></div>
                    <div class="stats-label">Total Debtors</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number">TSH <?php echo number_format($stats['total_amount_due'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Due</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format($stats['total_received'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Received</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number">TSH <?php echo number_format($stats['total_outstanding'] / 1000000, 1); ?>M</div>
                    <div class="stats-label">Outstanding</div>
                </div>
            </div>
        </div>

        <!-- Aging Analysis -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format($stats['current_due']); ?></div>
                    <div class="stats-label">Current (0-30 days)</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($stats['days_30']); ?></div>
                    <div class="stats-label">31-60 Days</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card orange">
                    <div class="stats-number"><?php echo number_format($stats['days_60']); ?></div>
                    <div class="stats-label">61-90 Days</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card dark">
                    <div class="stats-number"><?php echo number_format($stats['days_90_plus']); ?></div>
                    <div class="stats-label">90+ Days</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Customer name, phone, reservation #..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Aging Period</label>
                    <select name="aging" class="form-select">
                        <option value="">All Periods</option>
                        <option value="current" <?php echo (isset($_GET['aging']) && $_GET['aging'] == 'current') ? 'selected' : ''; ?>>Current (0-30 days)</option>
                        <option value="30" <?php echo (isset($_GET['aging']) && $_GET['aging'] == '30') ? 'selected' : ''; ?>>31-60 Days</option>
                        <option value="60" <?php echo (isset($_GET['aging']) && $_GET['aging'] == '60') ? 'selected' : ''; ?>>61-90 Days</option>
                        <option value="90" <?php echo (isset($_GET['aging']) && $_GET['aging'] == '90') ? 'selected' : ''; ?>>90+ Days</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search me-1"></i> Filter
                    </button>
                    <a href="debtors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Debtors Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover" id="debtorsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Plots</th>
                            <th>Total Due</th>
                            <th>Received</th>
                            <th>Outstanding</th>
                            <th>Last Payment</th>
                            <th>Days Overdue</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($debtors)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-smile fa-3x mb-3 d-block"></i>
                                <p class="mb-2">No outstanding debts found. All customers are up to date!</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($debtors as $debtor): ?>
                        <?php
                        $percentage_paid = $debtor['total_amount_due'] > 0 ? 
                            ($debtor['amount_received'] / $debtor['total_amount_due']) * 100 : 0;
                        
                        $days_overdue = (int)$debtor['days_overdue'];
                        if ($days_overdue <= 30) {
                            $overdue_class = 'current';
                            $overdue_label = 'Current';
                        } elseif ($days_overdue <= 60) {
                            $overdue_class = 'days-30';
                            $overdue_label = '31-60 Days';
                        } elseif ($days_overdue <= 90) {
                            $overdue_class = 'days-60';
                            $overdue_label = '61-90 Days';
                        } else {
                            $overdue_class = 'days-90';
                            $overdue_label = '90+ Days';
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="customer-avatar me-3">
                                        <?php echo strtoupper(substr($debtor['customer_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($debtor['customer_name']); ?></div>
                                        <small class="text-muted">
                                            <?php echo $debtor['total_reservations']; ?> 
                                            <?php echo $debtor['total_reservations'] == 1 ? 'Reservation' : 'Reservations'; ?>
                                        </small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($debtor['phone'])): ?>
                                <div><i class="fas fa-phone text-primary me-1"></i><?php echo htmlspecialchars($debtor['phone']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($debtor['email'])): ?>
                                <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($debtor['email']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="plots-list" title="<?php echo htmlspecialchars($debtor['plots']); ?>">
                                    <i class="fas fa-map-marked-alt text-info me-1"></i>
                                    <?php echo htmlspecialchars($debtor['plots']); ?>
                                </div>
                            </td>
                            <td class="fw-bold">TSH <?php echo number_format($debtor['total_amount_due'], 0); ?></td>
                            <td class="text-success">
                                TSH <?php echo number_format($debtor['amount_received'], 0); ?>
                                <div class="progress progress-custom mt-2">
                                    <div class="progress-bar bg-success" 
                                         style="width: <?php echo min($percentage_paid, 100); ?>%"
                                         title="<?php echo number_format($percentage_paid, 1); ?>% paid">
                                    </div>
                                </div>
                                <small class="text-muted"><?php echo number_format($percentage_paid, 1); ?>%</small>
                            </td>
                            <td>
                                <span class="balance-amount text-danger">
                                    TSH <?php echo number_format($debtor['outstanding_balance'], 0); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($debtor['last_payment_date']): ?>
                                    <i class="fas fa-calendar text-muted me-1"></i>
                                    <?php echo date('M d, Y', strtotime($debtor['last_payment_date'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">No payment yet</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="overdue-badge <?php echo $overdue_class; ?>">
                                    <?php echo $overdue_label; ?>
                                </span>
                                <div class="small text-muted mt-1"><?php echo $days_overdue; ?> days</div>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="view.php?id=<?php echo $debtor['customer_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View Customer">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../payments/create.php?customer_id=<?php echo $debtor['customer_id']; ?>" 
                                       class="btn btn-outline-success action-btn"
                                       title="Record Payment">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </a>
                                    <a href="send-reminder.php?customer_id=<?php echo $debtor['customer_id']; ?>" 
                                       class="btn btn-outline-warning action-btn"
                                       title="Send Reminder">
                                        <i class="fas fa-bell"></i>
                                    </a>
                                    <a href="../sales/payment-recovery.php?customer_id=<?php echo $debtor['customer_id']; ?>" 
                                       class="btn btn-outline-danger action-btn"
                                       title="Recovery Action">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($debtors)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">TOTALS:</th>
                            <th>TSH <?php echo number_format(array_sum(array_column($debtors, 'total_amount_due')), 0); ?></th>
                            <th>TSH <?php echo number_format(array_sum(array_column($debtors, 'amount_received')), 0); ?></th>
                            <th>TSH <?php echo number_format(array_sum(array_column($debtors, 'outstanding_balance')), 0); ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    </div>
</section>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    const tableBody = $('#debtorsTable tbody tr');
    const hasData = tableBody.length > 0 && !tableBody.first().find('td[colspan]').length;
    
    if (hasData) {
        $('#debtorsTable').DataTable({
            order: [[5, 'desc']], // Sort by outstanding balance
            pageLength: 25,
            responsive: true,
            columnDefs: [
                { orderable: false, targets: -1 }
            ],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search debtors..."
            }
        });
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>