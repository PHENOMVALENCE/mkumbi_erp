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

// ================ STATS ================
try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_customers,
            SUM(CASE WHEN customer_type = 'individual' THEN 1 ELSE 0 END) as individual_customers,
            SUM(CASE WHEN customer_type = 'company' THEN 1 ELSE 0 END) as company_customers,
            COALESCE(COUNT(DISTINCT r.plot_id), 0) as plots_owned,
            COALESCE(SUM(pay. total_paid), 0) as total_payments,
            COALESCE(SUM(pay.balance), 0) as total_outstanding
        FROM customers c
        LEFT JOIN reservations r ON c.customer_id = r.customer_id AND r.is_active = 1
        LEFT JOIN (
            SELECT r.customer_id,
                   SUM(p.amount) as total_paid,
                   SUM(r.total_amount) - SUM(COALESCE(p.amount, 0)) as balance
            FROM reservations r
            LEFT JOIN payments p ON r.reservation_id = p.reservation_id AND p.status = 'approved'
            WHERE r.company_id = ? AND r.is_active = 1
            GROUP BY r.customer_id
        ) pay ON c.customer_id = pay.customer_id
        WHERE c.company_id = ?  AND c.is_active = 1
    ");
    $stmt->execute([$company_id, $company_id]);
    $stats = $stmt->fetch(PDO:: FETCH_ASSOC);
    
    // Ensure all stats are numeric
    $stats = array_map(function($val) {
        return $val !== null ?  $val : 0;
    }, $stats);
    
} catch (Exception $e) {
    $stats = [
        'total_customers' => 0,
        'individual_customers' => 0,
        'company_customers' => 0,
        'plots_owned' => 0,
        'total_payments' => 0,
        'total_outstanding' => 0
    ];
    $error_message = $e->getMessage();
}

// ================ FILTERS & LIST ================
$where = "c.company_id = ? AND c.is_active = 1";
$params = [$company_id];

if (! empty($_GET['customer_type'])) {
    $where .= " AND c.customer_type = ?";
    $params[] = $_GET['customer_type'];
}
if (!empty($_GET['search'])) {
    $s = '%' . trim($_GET['search']) . '%';
    $where = " AND (c.full_name LIKE ? OR c.phone LIKE ? OR c.phone1 LIKE ? OR c.email LIKE ?  OR c.national_id LIKE ?  OR c.tin_number LIKE ?)";
    $params = array_merge($params, [$s, $s, $s, $s, $s, $s]);
}

try {
    $sql = "SELECT 
                c. customer_id,
                c. full_name,
                c. customer_type,
                COALESCE(c.phone, c.phone1) AS phone,
                c.email,
                COALESCE(c.national_id, c.tin_number, c.id_number, '') AS id_display,
                COALESCE(pl.plots_count, 0) AS plots_count,
                COALESCE(pl.total_value, 0) AS total_value,
                COALESCE(py.total_paid, 0) AS total_paid,
                COALESCE(py.balance, 0) AS balance,
                DATE_FORMAT(c.created_at, '%b %Y') AS join_month
            FROM customers c
            LEFT JOIN (
                SELECT r.customer_id, 
                       COUNT(DISTINCT r.plot_id) AS plots_count,
                       SUM(r.total_amount) AS total_value
                FROM reservations r
                WHERE r.is_active = 1 AND r.status IN ('active', 'completed')
                GROUP BY r.customer_id
            ) pl ON c.customer_id = pl.customer_id
            LEFT JOIN (
                SELECT r.customer_id,
                       SUM(p.amount) AS total_paid,
                       SUM(r.total_amount) - SUM(COALESCE(p.amount, 0)) AS balance
                FROM reservations r
                LEFT JOIN payments p ON r.reservation_id = p.reservation_id AND p.status = 'approved'
                WHERE r. company_id = ? AND r.is_active = 1
                GROUP BY r.customer_id
            ) py ON c.customer_id = py.customer_id
            WHERE $where
            ORDER BY c.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$company_id], $params));
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = [];
    $error_message = $e->getMessage();
}

$page_title = 'Customers Management';
require_once '../../includes/header.php';
?>

<style>
. stats-card{background: white;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,0.08);border-left:4px solid;transition:transform . 2s}
.stats-card:hover{transform:translateY(-4px)}
.stats-card. primary{border-left-color:#007bff}. stats-card.success{border-left-color:#28a745}
.stats-card.info{border-left-color:#17a2b8}. stats-card.warning{border-left-color:#ffc107}
.stats-card.purple{border-left-color:#6f42c1}.stats-card. danger{border-left-color:#dc3545}
.stats-number{font-size:2rem;font-weight:700;color:#2c3e50}
.stats-label{color:#6c757d;font-size:. 875rem;font-weight:500;text-transform:uppercase}
.table-card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-bottom:2rem}
.customer-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem}
.customer-badge{padding:.35rem .75rem;border-radius:20px;font-size:. 8rem;font-weight:600}
.customer-badge.individual{background:#d4edda;color:#155724}
.customer-badge.company{background:#d1ecf1;color:#0c5460}
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6"><h1 class="m-0 fw-bold">Customers Management</h1></div>
            <div class="col-sm-6 text-end">
                <a href="create.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Add Customer</a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <?php if (isset($error_message)): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <strong>Note:</strong> Some features may not work if tables are missing. Error: <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-lg-2 col-6">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format((int)$stats['total_customers']) ?></div>
                <div class="stats-label">Total</div>
            </div>
        </div>
        <div class="col-lg-2 col-6">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format((int)$stats['individual_customers']) ?></div>
                <div class="stats-label">Individuals</div>
            </div>
        </div>
        <div class="col-lg-2 col-6">
            <div class="stats-card info">
                <div class="stats-number"><?= number_format((int)$stats['company_customers']) ?></div>
                <div class="stats-label">Companies</div>
            </div>
        </div>
        <div class="col-lg-2 col-6">
            <div class="stats-card warning">
                <div class="stats-number"><?= number_format((int)$stats['plots_owned']) ?></div>
                <div class="stats-label">Plots</div>
            </div>
        </div>
        <div class="col-lg-2 col-6">
            <div class="stats-card purple">
                <div class="stats-number">TSH <?= number_format((float)$stats['total_payments'] / 1000000, 1) ?>M</div>
                <div class="stats-label">Paid</div>
            </div>
        </div>
        <div class="col-lg-2 col-6">
            <div class="stats-card danger">
                <div class="stats-number">TSH <?= number_format((float)$stats['total_outstanding'] / 1000000, 1) ?>M</div>
                <div class="stats-label">Outstanding</div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="customersTable">
                <thead class="table-light">
                    <tr>
                        <th>Customer</th>
                        <th>Type</th>
                        <th>Contact</th>
                        <th>ID/TIN</th>
                        <th class="text-center">Plots</th>
                        <th>Total Value</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! empty($customers)): ?>
                        <?php foreach ($customers as $c): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="customer-avatar me-3"><?= strtoupper(substr($c['full_name'], 0, 1)) ?></div>
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($c['full_name']) ?></div>
                                        <small class="text-muted">Joined <?= $c['join_month'] ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="customer-badge <?= $c['customer_type'] ?>">
                                    <?= ucfirst($c['customer_type']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($c['phone'])): ?>
                                    <div><i class="fas fa-phone-alt me-1"></i><?= htmlspecialchars($c['phone']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($c['email'])): ?>
                                    <div><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($c['email']) ?></div>
                                <?php endif; ?>
                                <?php if (empty($c['phone']) && empty($c['email'])): ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['id_display'])): ?>
                                    <code><?= htmlspecialchars($c['id_display']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary"><?= $c['plots_count'] ?></span>
                            </td>
                            <td class="fw-bold">TSH <?= number_format($c['total_value']) ?></td>
                            <td class="text-success fw-bold">TSH <?= number_format($c['total_paid']) ?></td>
                            <td>
                                <?php if ($c['balance'] > 0): ?>
                                    <span class="text-danger fw-bold">TSH <?= number_format($c['balance']) ?></span>
                                <?php elseif ($c['balance'] < 0): ?>
                                    <span class="text-success fw-bold">Overpaid TSH <?= number_format(abs($c['balance'])) ?></span>
                                <?php else: ?>
                                    <span class="text-success fw-bold">Paid</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="view.php?id=<?= $c['customer_id'] ?>" class="btn btn-outline-primary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?= $c['customer_id'] ?>" class="btn btn-outline-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-danger" title="Delete"
                                            onclick="if(confirm('Delete <?= addslashes(htmlspecialchars($c['full_name'])) ?>?')) location.href='delete.php?id=<?= $c['customer_id'] ?>'">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min. css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min. js"></script>

<script>
$(document).ready(function() {
    <?php if (!empty($customers)): ?>
    // Only initialize DataTables if there are customers
    $('#customersTable').DataTable({
        pageLength: 25,
        responsive: true,
        order: [[0, 'asc']], // Sort by customer name
        columnDefs: [
            { orderable: false, targets: [8] }, // Actions column not sortable
            { className: 'text-center', targets: [4, 8] } // Center align plots and actions
        ],
        language: { 
            search: "_INPUT_",
            searchPlaceholder: "Search customers..."
        }
    });
    <?php else: ?>
    // Show a message if no customers
    $('#customersTable tbody').html('<tr><td colspan="9" class="text-center py-5 text-muted">No customers found.  <a href="create.php" class="btn btn-sm btn-primary ms-2"><i class="fas fa-plus-circle"></i> Add Customer</a></td></tr>');
    <?php endif; ?>
});
</script>

<?php require_once '../../includes/footer.php'; ?>