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
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$project_id = $_GET['project_id'] ?? '';
$status = $_GET['status'] ?? '';

// Fetch sales data
$sales_data = [];
$summary_stats = [];

try {
    // Summary statistics
    $query = "
        SELECT 
            COUNT(*) as total_reservations,
            SUM(total_amount) as total_value,
            SUM(down_payment) as total_down_payments,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            AVG(total_amount) as avg_sale_value
        FROM reservations
        WHERE company_id = ?
        AND reservation_date BETWEEN ? AND ?
    ";
    
    $params = [$company_id, $start_date, $end_date];
    
    if ($project_id) {
        $query .= " AND plot_id IN (SELECT plot_id FROM plots WHERE project_id = ?)";
        $params[] = $project_id;
    }
    
    if ($status) {
        $query .= " AND status = ?";
        $params[] = $status;
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $summary_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Detailed sales data
    $query = "
        SELECT 
            r.*,
            c.full_name as customer_name,
            c.phone,
            c.email,
            p.plot_number,
            p.block_number,
            p.area,
            pr.project_name,
            pr.project_code,
            u.full_name as created_by_name,
            (SELECT SUM(amount) 
             FROM payments 
             WHERE reservation_id = r.reservation_id 
             AND status = 'approved') as total_paid,
            (r.total_amount - COALESCE((
                SELECT SUM(amount) 
                FROM payments 
                WHERE reservation_id = r.reservation_id 
                AND status = 'approved'
            ), 0)) as balance
        FROM reservations r
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects pr ON p.project_id = pr.project_id
        LEFT JOIN users u ON r.created_by = u.user_id
        WHERE r.company_id = ?
        AND r.reservation_date BETWEEN ? AND ?
    ";
    
    $params = [$company_id, $start_date, $end_date];
    
    if ($project_id) {
        $query .= " AND pr.project_id = ?";
        $params[] = $project_id;
    }
    
    if ($status) {
        $query .= " AND r.status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY r.reservation_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $sales_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Sales query error: " . $e->getMessage());
}

// Get projects for filter
$projects = [];
try {
    $stmt = $conn->prepare("SELECT project_id, project_name FROM projects WHERE company_id = ? AND is_active = 1 ORDER BY project_name");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Projects query error: " . $e->getMessage());
}

$page_title = 'Sales Summary Report';
require_once '../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .content-wrapper { margin: 0 !important; }
    .table { font-size: 10px; }
}

.report-header {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    padding: 2rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    border-left: 4px solid;
}

.stat-card.primary { border-left-color: #007bff; }
.stat-card.success { border-left-color: #28a745; }
.stat-card.warning { border-left-color: #ffc107; }
.stat-card.info { border-left-color: #17a2b8; }

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
}

.stat-label {
    font-size: 0.875rem;
    color: #6c757d;
    text-transform: uppercase;
}

.table-professional {
    font-size: 0.85rem;
}

.table-professional thead th {
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    padding: 0.65rem 0.5rem;
    border-bottom: 2px solid #dee2e6;
}

.status-badge {
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.active { background: #d4edda; color: #155724; }
.status-badge.completed { background: #d1ecf1; color: #0c5460; }
.status-badge.cancelled { background: #f8d7da; color: #721c24; }
.status-badge.draft { background: #fff3cd; color: #856404; }

.chart-container {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
}
</style>

<div class="content-header no-print">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Sales Summary Report</h1>
            </div>
            <div class="col-sm-6 text-end">
                <button onclick="window.print()" class="btn btn-primary btn-sm">
                    <i class="fas fa-print me-1"></i>Print
                </button>
                <button onclick="exportToExcel()" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i>Export
                </button>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Report Header -->
    <div class="report-header">
        <h2 class="mb-1">Sales Summary Report</h2>
        <p class="mb-0">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
        <p class="mb-0"><small>Generated: <?= date('d M Y, h:i A') ?></small></p>
    </div>

    <!-- Filters -->
    <div class="card no-print mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Start Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">End Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $end_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Project</label>
                    <select name="project_id" class="form-select form-select-sm">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?= $proj['project_id'] ?>" <?= $project_id == $proj['project_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($proj['project_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="active" <?= $status == 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="completed" <?= $status == 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $status == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-value"><?= number_format($summary_stats['total_reservations'] ?? 0) ?></div>
            <div class="stat-label">Total Sales</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value">TSH <?= number_format(($summary_stats['total_value'] ?? 0)/1000000, 1) ?>M</div>
            <div class="stat-label">Total Value</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value">TSH <?= number_format(($summary_stats['total_down_payments'] ?? 0)/1000000, 1) ?>M</div>
            <div class="stat-label">Down Payments</div>
        </div>
        <div class="stat-card info">
            <div class="stat-value"><?= number_format($summary_stats['active_count'] ?? 0) ?></div>
            <div class="stat-label">Active Sales</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value"><?= number_format($summary_stats['completed_count'] ?? 0) ?></div>
            <div class="stat-label">Completed</div>
        </div>
        <div class="stat-card primary">
            <div class="stat-value">TSH <?= number_format(($summary_stats['avg_sale_value'] ?? 0)/1000000, 2) ?>M</div>
            <div class="stat-label">Average Sale</div>
        </div>
    </div>

    <!-- Sales Chart -->
    <div class="chart-container no-print">
        <h5 class="mb-3">Sales Trend</h5>
        <canvas id="salesChart" style="max-height: 300px;"></canvas>
    </div>

    <!-- Sales Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($sales_data)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No sales found for the selected period</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="salesTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Res. No.</th>
                                <th>Customer</th>
                                <th>Project</th>
                                <th>Plot</th>
                                <th class="text-end">Area (m²)</th>
                                <th class="text-end">Sale Value</th>
                                <th class="text-end">Down Payment</th>
                                <th class="text-end">Total Paid</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                                <th>Sales Person</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_value = $total_down = $total_paid = $total_balance = 0;
                            foreach ($sales_data as $row): 
                                $total_value += $row['total_amount'];
                                $total_down += $row['down_payment'];
                                $total_paid += $row['total_paid'];
                                $total_balance += $row['balance'];
                            ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($row['reservation_date'])) ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($row['reservation_number']) ?></td>
                                <td>
                                    <?= htmlspecialchars($row['customer_name']) ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($row['phone']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($row['project_code']) ?></span>
                                    <?= htmlspecialchars($row['project_name']) ?>
                                </td>
                                <td><?= htmlspecialchars($row['plot_number']) . ($row['block_number'] ? ' / ' . $row['block_number'] : '') ?></td>
                                <td class="text-end"><?= number_format($row['area'], 2) ?></td>
                                <td class="text-end fw-bold"><?= number_format($row['total_amount'], 0) ?></td>
                                <td class="text-end"><?= number_format($row['down_payment'], 0) ?></td>
                                <td class="text-end text-success"><?= number_format($row['total_paid'], 0) ?></td>
                                <td class="text-end text-danger"><?= number_format($row['balance'], 0) ?></td>
                                <td><span class="status-badge <?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span></td>
                                <td><?= htmlspecialchars($row['created_by_name'] ?: '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <tr class="fw-bold bg-light">
                                <td colspan="6" class="text-end">TOTALS:</td>
                                <td class="text-end"><?= number_format($total_value, 0) ?></td>
                                <td class="text-end"><?= number_format($total_down, 0) ?></td>
                                <td class="text-end text-success"><?= number_format($total_paid, 0) ?></td>
                                <td class="text-end text-danger"><?= number_format($total_balance, 0) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
// Sales Trend Chart
<?php
// Prepare chart data
$chart_labels = [];
$chart_values = [];
$days = (strtotime($end_date) - strtotime($start_date)) / 86400;

if ($days <= 31) {
    // Daily chart
    for ($i = 0; $i <= $days; $i++) {
        $date = date('Y-m-d', strtotime($start_date . " +$i days"));
        $chart_labels[] = date('d M', strtotime($date));
        $daily_total = 0;
        foreach ($sales_data as $sale) {
            if (date('Y-m-d', strtotime($sale['reservation_date'])) == $date) {
                $daily_total += $sale['total_amount'];
            }
        }
        $chart_values[] = $daily_total;
    }
} else {
    // Monthly chart
    $start_month = date('Y-m', strtotime($start_date));
    $end_month = date('Y-m', strtotime($end_date));
    $current = $start_month;
    
    while ($current <= $end_month) {
        $chart_labels[] = date('M Y', strtotime($current . '-01'));
        $monthly_total = 0;
        foreach ($sales_data as $sale) {
            if (date('Y-m', strtotime($sale['reservation_date'])) == $current) {
                $monthly_total += $sale['total_amount'];
            }
        }
        $chart_values[] = $monthly_total;
        $current = date('Y-m', strtotime($current . '-01 +1 month'));
    }
}
?>

const ctx = document.getElementById('salesChart');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [{
            label: 'Sales Value (TSH)',
            data: <?= json_encode($chart_values) ?>,
            borderColor: '#28a745',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return 'TSH ' + (value/1000000).toFixed(1) + 'M';
                    }
                }
            }
        }
    }
});

function exportToExcel() {
    // Prepare data for Excel
    const data = [];
    
    // Add header
    data.push(['SALES SUMMARY REPORT']);
    data.push(['Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?>']);
    data.push(['Generated: <?= date('d M Y, h:i A') ?>']);
    data.push([]);
    
    // Add summary statistics
    data.push(['SUMMARY STATISTICS']);
    data.push(['Total Sales', '<?= number_format($summary_stats['total_reservations'] ?? 0) ?>']);
    data.push(['Total Value', 'TSH <?= number_format($summary_stats['total_value'] ?? 0, 2) ?>']);
    data.push(['Total Down Payments', 'TSH <?= number_format($summary_stats['total_down_payments'] ?? 0, 2) ?>']);
    data.push(['Active Sales', '<?= number_format($summary_stats['active_count'] ?? 0) ?>']);
    data.push(['Completed Sales', '<?= number_format($summary_stats['completed_count'] ?? 0) ?>']);
    data.push(['Average Sale Value', 'TSH <?= number_format($summary_stats['avg_sale_value'] ?? 0, 2) ?>']);
    data.push([]);
    
    // Add detailed sales
    data.push(['DETAILED SALES TRANSACTIONS']);
    data.push([
        'Date', 'Reservation No.', 'Customer', 'Phone', 'Project Code', 
        'Project Name', 'Plot Number', 'Block', 'Area (m²)', 'Sale Value', 
        'Down Payment', 'Total Paid', 'Balance', 'Status', 'Sales Person'
    ]);
    
    <?php 
    $total_value = $total_down = $total_paid = $total_balance = 0;
    foreach ($sales_data as $row): 
        $total_value += $row['total_amount'];
        $total_down += $row['down_payment'];
        $total_paid += $row['total_paid'];
        $total_balance += $row['balance'];
    ?>
    data.push([
        '<?= date('d M Y', strtotime($row['reservation_date'])) ?>',
        '<?= $row['reservation_number'] ?>',
        '<?= addslashes($row['customer_name']) ?>',
        '<?= $row['phone'] ?>',
        '<?= $row['project_code'] ?>',
        '<?= addslashes($row['project_name']) ?>',
        '<?= $row['plot_number'] ?>',
        '<?= $row['block_number'] ?>',
        <?= $row['area'] ?>,
        <?= $row['total_amount'] ?>,
        <?= $row['down_payment'] ?>,
        <?= $row['total_paid'] ?>,
        <?= $row['balance'] ?>,
        '<?= ucfirst($row['status']) ?>',
        '<?= addslashes($row['created_by_name'] ?: '-') ?>'
    ]);
    <?php endforeach; ?>
    
    // Add totals
    data.push([]);
    data.push([
        '', '', '', '', '', '', '', '', 'TOTALS',
        <?= $total_value ?>,
        <?= $total_down ?>,
        <?= $total_paid ?>,
        <?= $total_balance ?>,
        '', ''
    ]);
    
    // Create workbook
    const ws = XLSX.utils.aoa_to_sheet(data);
    
    // Set column widths
    ws['!cols'] = [
        {wch: 12}, {wch: 18}, {wch: 25}, {wch: 15}, {wch: 12},
        {wch: 30}, {wch: 12}, {wch: 10}, {wch: 12}, {wch: 15},
        {wch: 15}, {wch: 15}, {wch: 15}, {wch: 12}, {wch: 25}
    ];
    
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Sales Summary');
    
    XLSX.writeFile(wb, 'Sales_Report_<?= date('Y-m-d') ?>.xlsx');
}
</script>

<?php require_once '../../includes/footer.php'; ?>