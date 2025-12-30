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

// Get filter parameters
$project_id = $_GET['project_id'] ?? null;
$search = $_GET['search'] ?? '';
$min_balance = $_GET['min_balance'] ?? 0;

// Fetch projects for filter
try {
    $projects_sql = "SELECT project_id, project_name, project_code 
                     FROM projects 
                     WHERE company_id = ? AND is_active = 1 
                     ORDER BY project_name";
    $projects_stmt = $conn->prepare($projects_sql);
    $projects_stmt->execute([$company_id]);
    $projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $projects = [];
}

// Build aging query
$aging_sql = "SELECT 
    c.customer_id,
    c.full_name as customer_name,
    COALESCE(c.phone, c.phone1) as phone,
    c.email,
    COUNT(DISTINCT r.reservation_id) as total_reservations,
    SUM(r.total_amount) as total_amount,
    SUM(COALESCE(p.amount, 0)) as total_paid,
    SUM(r.total_amount) - SUM(COALESCE(p.amount, 0)) as current_balance,
    
    -- Aging buckets based on reservation date
    SUM(CASE 
        WHEN DATEDIFF(CURDATE(), r.reservation_date) <= 30 
        THEN r.total_amount - COALESCE(paid.amount, 0)
        ELSE 0 
    END) as current_0_30,
    
    SUM(CASE 
        WHEN DATEDIFF(CURDATE(), r.reservation_date) BETWEEN 31 AND 60 
        THEN r.total_amount - COALESCE(paid.amount, 0)
        ELSE 0 
    END) as days_31_60,
    
    SUM(CASE 
        WHEN DATEDIFF(CURDATE(), r.reservation_date) BETWEEN 61 AND 90 
        THEN r.total_amount - COALESCE(paid.amount, 0)
        ELSE 0 
    END) as days_61_90,
    
    SUM(CASE 
        WHEN DATEDIFF(CURDATE(), r.reservation_date) > 90 
        THEN r.total_amount - COALESCE(paid.amount, 0)
        ELSE 0 
    END) as days_over_90,
    
    MAX(r.reservation_date) as last_reservation_date,
    MAX(p.payment_date) as last_payment_date,
    DATEDIFF(CURDATE(), MAX(p.payment_date)) as days_since_payment
    
FROM customers c
INNER JOIN reservations r ON c.customer_id = r.customer_id 
    AND r.company_id = c.company_id 
    AND r.is_active = 1
LEFT JOIN plots pl ON r.plot_id = pl.plot_id
LEFT JOIN projects pr ON pl.project_id = pr.project_id
LEFT JOIN payments p ON r.reservation_id = p.reservation_id 
    AND p.status = 'approved'
LEFT JOIN (
    SELECT reservation_id, SUM(amount) as amount
    FROM payments
    WHERE status = 'approved'
    GROUP BY reservation_id
) paid ON r.reservation_id = paid.reservation_id
WHERE c.company_id = ? AND c.is_active = 1";

$params = [$company_id];

if ($project_id) {
    $aging_sql .= " AND pr.project_id = ?";
    $params[] = $project_id;
}

if ($search) {
    $aging_sql .= " AND (c.full_name LIKE ? OR c.phone LIKE ? OR c.phone1 LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$aging_sql .= " GROUP BY c.customer_id, c.full_name, c.phone, c.phone1, c.email
                HAVING current_balance > ?
                ORDER BY current_balance DESC";
$params[] = $min_balance;

try {
    $stmt = $conn->prepare($aging_sql);
    $stmt->execute($params);
    $aging_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching aging data: " . $e->getMessage());
    $aging_data = [];
}

// Calculate summary statistics
$total_customers = count($aging_data);
$total_outstanding = array_sum(array_column($aging_data, 'current_balance'));
$total_0_30 = array_sum(array_column($aging_data, 'current_0_30'));
$total_31_60 = array_sum(array_column($aging_data, 'days_31_60'));
$total_61_90 = array_sum(array_column($aging_data, 'days_61_90'));
$total_over_90 = array_sum(array_column($aging_data, 'days_over_90'));

$page_title = 'Customer Aging Report';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: #fff;
    border-radius: 8px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    height: 100%;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.stats-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    font-weight: 600;
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
    padding: 0.75rem 0.5rem;
    white-space: nowrap;
}

.table-professional tbody td {
    padding: 0.75rem 0.5rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.table-professional tbody tr:hover {
    background-color: #f8f9fa;
}

.aging-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.aging-badge.current { background: #d4edda; color: #155724; }
.aging-badge.medium { background: #fff3cd; color: #856404; }
.aging-badge.high { background: #f8d7da; color: #721c24; }
.aging-badge.critical { background: #dc3545; color: white; }

.filter-card {
    background: #fff;
    border-radius: 8px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.action-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-chart-line text-primary me-2"></i>Customer Aging Report
                </h1>
                <p class="text-muted small mb-0 mt-1">Track outstanding balances by age</p>
            </div>
            <div class="col-sm-6 text-end">
                <a href="statements.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-file-invoice me-1"></i>Statements
                </a>
                <button type="button" class="btn btn-success btn-sm" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i>Export to Excel
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Summary Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-lg-2 col-md-4 col-6">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($total_customers) ?></div>
                <div class="stats-label">Customers</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="stats-card danger">
                <div class="stats-number">TZS <?= number_format($total_outstanding / 1000000, 1) ?>M</div>
                <div class="stats-label">Total Outstanding</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="stats-card success">
                <div class="stats-number">TZS <?= number_format($total_0_30 / 1000000, 1) ?>M</div>
                <div class="stats-label">0-30 Days</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="stats-card warning">
                <div class="stats-number">TZS <?= number_format($total_31_60 / 1000000, 1) ?>M</div>
                <div class="stats-label">31-60 Days</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="stats-card warning">
                <div class="stats-number">TZS <?= number_format($total_61_90 / 1000000, 1) ?>M</div>
                <div class="stats-label">61-90 Days</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="stats-card danger">
                <div class="stats-number">TZS <?= number_format($total_over_90 / 1000000, 1) ?>M</div>
                <div class="stats-label">Over 90 Days</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Project</label>
                <select name="project_id" class="form-select">
                    <option value="">All Projects</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= $project['project_id'] ?>"
                                <?= ($project_id == $project['project_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($project['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold">Search</label>
                <input type="text" name="search" class="form-control" 
                       placeholder="Customer name or phone..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Min Balance</label>
                <input type="number" name="min_balance" class="form-control" 
                       placeholder="0"
                       value="<?= htmlspecialchars($min_balance) ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>Filter
                    </button>
                    <a href="aging.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Aging Report Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($aging_data)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No outstanding balances found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="agingTable">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Contact</th>
                                <th class="text-end">Total Balance</th>
                                <th class="text-end">Current (0-30)</th>
                                <th class="text-end">31-60 Days</th>
                                <th class="text-end">61-90 Days</th>
                                <th class="text-end">Over 90 Days</th>
                                <th>Risk</th>
                                <th>Last Payment</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($aging_data as $customer): ?>
                                <?php
                                // Calculate risk level
                                $risk = 'Low';
                                $risk_class = 'success';
                                if ($customer['days_over_90'] > 0) {
                                    $risk = 'Critical';
                                    $risk_class = 'critical';
                                } elseif ($customer['days_61_90'] > 0) {
                                    $risk = 'High';
                                    $risk_class = 'high';
                                } elseif ($customer['days_31_60'] > 0) {
                                    $risk = 'Medium';
                                    $risk_class = 'medium';
                                } else {
                                    $risk = 'Current';
                                    $risk_class = 'current';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($customer['customer_name']) ?></strong>
                                        <div class="small text-muted">
                                            <?= $customer['total_reservations'] ?> reservation(s)
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($customer['phone']): ?>
                                            <div><i class="fas fa-phone me-1"></i><?= htmlspecialchars($customer['phone']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($customer['email']): ?>
                                            <div class="small"><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($customer['email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-danger">
                                            <?= number_format($customer['current_balance'], 0) ?>
                                        </strong>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($customer['current_0_30'] > 0): ?>
                                            <span class="text-success fw-bold">
                                                <?= number_format($customer['current_0_30'], 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($customer['days_31_60'] > 0): ?>
                                            <span class="text-warning fw-bold">
                                                <?= number_format($customer['days_31_60'], 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($customer['days_61_90'] > 0): ?>
                                            <span class="text-warning fw-bold">
                                                <?= number_format($customer['days_61_90'], 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($customer['days_over_90'] > 0): ?>
                                            <span class="text-danger fw-bold">
                                                <?= number_format($customer['days_over_90'], 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="aging-badge <?= $risk_class ?>">
                                            <?= $risk ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($customer['last_payment_date']): ?>
                                            <small><?= date('M d, Y', strtotime($customer['last_payment_date'])) ?></small>
                                            <div class="small text-muted">
                                                <?= $customer['days_since_payment'] ?> days ago
                                            </div>
                                        <?php else: ?>
                                            <small class="text-muted">No payments</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-center">
                                            <a href="view_statement.php?customer_id=<?= $customer['customer_id'] ?>" 
                                               class="btn btn-sm btn-outline-primary action-btn" 
                                               title="View Statement">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-success action-btn"
                                                    onclick="sendReminder(<?= $customer['customer_id'] ?>)"
                                                    title="Send Reminder">
                                                <i class="fas fa-bell"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background: #f8f9fa; font-weight: 700;">
                            <tr>
                                <td colspan="2">TOTALS:</td>
                                <td class="text-end text-danger"><?= number_format($total_outstanding, 0) ?></td>
                                <td class="text-end text-success"><?= number_format($total_0_30, 0) ?></td>
                                <td class="text-end text-warning"><?= number_format($total_31_60, 0) ?></td>
                                <td class="text-end text-warning"><?= number_format($total_61_90, 0) ?></td>
                                <td class="text-end text-danger"><?= number_format($total_over_90, 0) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- DataTables -->
                <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
                <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

                <script>
                $(document).ready(function() {
                    $('#agingTable').DataTable({
                        pageLength: 50,
                        order: [[2, 'desc']],
                        columnDefs: [
                            { targets: [2,3,4,5,6], className: 'text-end' },
                            { targets: 9, orderable: false }
                        ]
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function sendReminder(customerId) {
    if (confirm('Send payment reminder to this customer?')) {
        // Implement SMS/Email reminder functionality
        alert('Reminder functionality coming soon!');
    }
}

function exportToExcel() {
    window.location.href = 'export_aging.php';
}
</script>

<?php require_once '../../includes/footer.php'; ?>