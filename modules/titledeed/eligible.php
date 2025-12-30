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

// Helper function to safely format numbers
function safe_format($number, $decimals = 0) {
    return number_format((float)$number ?: 0, $decimals);
}

// Fetch filters
$project_filter = $_GET['project'] ?? '';
$payment_status = $_GET['payment_status'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_clauses = ["r.company_id = ?", "r.is_active = 1"];
$params = [$company_id];

// Customers who have fully paid reservations and don't have title deed processing yet
$where_clauses[] = "r.reservation_id NOT IN (SELECT reservation_id FROM title_deed_processing WHERE company_id = ? AND reservation_id IS NOT NULL)";
$params[] = $company_id;

if ($project_filter) {
    $where_clauses[] = "pr.project_id = ?";
    $params[] = $project_filter;
}

if ($payment_status === 'fully_paid') {
    $where_clauses[] = "(r.total_amount - COALESCE(payment_summary.total_paid, 0)) <= 0";
} elseif ($payment_status === 'partial') {
    $where_clauses[] = "(r.total_amount - COALESCE(payment_summary.total_paid, 0)) > 0";
}

if ($search) {
    $where_clauses[] = "(c.full_name LIKE ? OR c.phone LIKE ? OR c.phone1 LIKE ? OR p.plot_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch eligible customers
try {
    $sql = "SELECT 
                r.reservation_id,
                r.reservation_number,
                r.reservation_date,
                r.total_amount,
                c.customer_id,
                c.full_name as customer_name,
                COALESCE(c.phone, c.phone1) as customer_phone,
                c.email as customer_email,
                p.plot_id,
                p.plot_number,
                p.block_number,
                p.area as plot_area,
                pr.project_id,
                pr.project_name,
                COALESCE(payment_summary.total_paid, 0) as total_paid,
                (r.total_amount - COALESCE(payment_summary.total_paid, 0)) as balance
            FROM reservations r
            INNER JOIN customers c ON r.customer_id = c.customer_id
            INNER JOIN plots p ON r.plot_id = p.plot_id
            INNER JOIN projects pr ON p.project_id = pr.project_id
            LEFT JOIN (
                SELECT reservation_id, SUM(amount_paid) as total_paid
                FROM reservation_payments
                WHERE company_id = ? AND is_active = 1
                GROUP BY reservation_id
            ) payment_summary ON r.reservation_id = payment_summary.reservation_id
            WHERE $where_sql
            ORDER BY r.reservation_date DESC";
    
    array_unshift($params, $company_id);
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $eligible_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching eligible customers: " . $e->getMessage());
    $eligible_customers = [];
}

// Fetch projects for filter
try {
    $stmt = $conn->prepare("
        SELECT project_id, project_name
        FROM projects
        WHERE company_id = ? AND is_active = 1
        ORDER BY project_name
    ");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $projects = [];
}

// Calculate statistics
$total_eligible = count($eligible_customers);
$fully_paid = count(array_filter($eligible_customers, fn($c) => $c['balance'] <= 0));
$partial_payment = count(array_filter($eligible_customers, fn($c) => $c['balance'] > 0));
$total_value = array_sum(array_column($eligible_customers, 'total_amount'));
$total_collected = array_sum(array_column($eligible_customers, 'total_paid'));

$page_title = 'Eligible Customers';
require_once '../../includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

<style>
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
    height: 100%;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 2rem;
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

.stats-icon {
    font-size: 2.5rem;
    opacity: 0.3;
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
}

.filter-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.table-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.table-card .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.25rem 1.5rem;
    border: none;
}

.table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 1rem;
    white-space: nowrap;
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.payment-status {
    display: inline-block;
    padding: 0.35rem 0.65rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.payment-status.paid {
    background: #d4edda;
    color: #155724;
}

.payment-status.partial {
    background: #fff3cd;
    color: #856404;
}

.reservation-number {
    font-weight: 600;
    color: #007bff;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-users-check text-primary me-2"></i>Eligible Customers
                </h1>
                <p class="text-muted small mb-0 mt-1">Customers eligible for title deed processing</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="initiate.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i>Initiate Processing
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Processing
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stats-card primary position-relative">
                    <i class="fas fa-users stats-icon"></i>
                    <div class="stats-number"><?php echo $total_eligible; ?></div>
                    <div class="stats-label">Total Eligible</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card success position-relative">
                    <i class="fas fa-check-circle stats-icon"></i>
                    <div class="stats-number"><?php echo $fully_paid; ?></div>
                    <div class="stats-label">Fully Paid</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card warning position-relative">
                    <i class="fas fa-clock stats-icon"></i>
                    <div class="stats-number"><?php echo $partial_payment; ?></div>
                    <div class="stats-label">Partial Payment</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card info position-relative">
                    <i class="fas fa-dollar-sign stats-icon"></i>
                    <div class="stats-number">TSH <?php echo safe_format($total_value / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Value</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Customer name, phone, plot..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Project</label>
                    <select name="project" class="form-select">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['project_id']; ?>" 
                                    <?php echo $project_filter == $proj['project_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($proj['project_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Payment Status</label>
                    <select name="payment_status" class="form-select">
                        <option value="">All Status</option>
                        <option value="fully_paid" <?php echo $payment_status === 'fully_paid' ? 'selected' : ''; ?>>Fully Paid</option>
                        <option value="partial" <?php echo $payment_status === 'partial' ? 'selected' : ''; ?>>Partial Payment</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                    </div>
                </div>
                <div class="col-12">
                    <a href="eligible.php" class="btn btn-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Eligible Customers Table -->
        <div class="table-card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Eligible Customers
                    <span class="badge bg-light text-dark ms-2"><?php echo $total_eligible; ?> customers</span>
                </h5>
            </div>
            <div class="table-responsive">
                <?php if (empty($eligible_customers)): ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <h4>No Eligible Customers Found</h4>
                    <p class="text-muted">No customers match your current filters or all eligible customers already have processing initiated</p>
                </div>
                <?php else: ?>
                <table id="eligibleTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Reservation #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Plot Details</th>
                            <th>Project</th>
                            <th>Total Amount</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eligible_customers as $customer): 
                            $is_fully_paid = $customer['balance'] <= 0;
                        ?>
                        <tr>
                            <td>
                                <span class="reservation-number"><?php echo htmlspecialchars($customer['reservation_number']); ?></span>
                            </td>
                            <td><?php echo date('d M Y', strtotime($customer['reservation_date'])); ?></td>
                            <td>
                                <div><strong><?php echo htmlspecialchars($customer['customer_name']); ?></strong></div>
                                <?php if ($customer['customer_phone']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($customer['customer_phone']); ?></small>
                                <?php endif; ?>
                                <?php if ($customer['customer_email']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($customer['customer_email']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong>Plot <?php echo htmlspecialchars($customer['plot_number']); ?></strong></div>
                                <?php if ($customer['block_number']): ?>
                                    <small class="text-muted">Block <?php echo htmlspecialchars($customer['block_number']); ?></small>
                                <?php endif; ?>
                                <?php if ($customer['plot_area']): ?>
                                    <br><small class="text-muted"><?php echo safe_format($customer['plot_area']); ?> m²</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($customer['project_name']); ?></td>
                            <td>
                                <div class="fw-bold text-primary">TSH <?php echo safe_format($customer['total_amount']); ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-success">TSH <?php echo safe_format($customer['total_paid']); ?></div>
                            </td>
                            <td>
                                <div class="fw-bold <?php echo $is_fully_paid ? 'text-success' : 'text-warning'; ?>">
                                    TSH <?php echo safe_format($customer['balance']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="payment-status <?php echo $is_fully_paid ? 'paid' : 'partial'; ?>">
                                    <?php echo $is_fully_paid ? 'Fully Paid' : 'Partial'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="initiate.php?customer_id=<?php echo $customer['customer_id']; ?>&reservation_id=<?php echo $customer['reservation_id']; ?>" 
                                   class="btn btn-sm btn-primary" 
                                   title="Initiate Processing">
                                    <i class="fas fa-play-circle me-1"></i>Initiate
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#eligibleTable').DataTable({
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[1, 'desc']],
        columnDefs: [
            { orderable: false, targets: 9 },
            { responsivePriority: 1, targets: 0 },
            { responsivePriority: 2, targets: 2 },
            { responsivePriority: 3, targets: 8 },
            { responsivePriority: 4, targets: 9 }
        ]
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>