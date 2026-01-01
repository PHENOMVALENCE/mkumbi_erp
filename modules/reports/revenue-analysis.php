<?php
define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

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

// Initialize data arrays
$summary_stats = ['total_revenue' => 0, 'total_transactions' => 0, 'avg_transaction' => 0];
$revenue_data = [];
$projects = [];
$growth_rate = 0;

try {
    // Summary statistics
    $query = "
        SELECT 
            COUNT(*) as total_transactions,
            SUM(p.amount) as total_revenue,
            AVG(p.amount) as avg_transaction
        FROM payments p
        INNER JOIN reservations r ON p.reservation_id = r.reservation_id
        WHERE p.company_id = ?
        AND p.status = 'approved'
        AND p.payment_date BETWEEN ? AND ?
    ";
    
    $params = [$company_id, $start_date, $end_date];
    if ($project_id) {
        $query .= " AND r.plot_id IN (SELECT plot_id FROM plots WHERE project_id = ?)";
        $params[] = $project_id;
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $summary_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate growth rate
    $period_days = (strtotime($end_date) - strtotime($start_date)) / 86400;
    $prev_start = date('Y-m-d', strtotime($start_date . " -" . ($period_days + 1) . " days"));
    $prev_end = date('Y-m-d', strtotime($start_date . " -1 day"));
    
    $prev_query = "SELECT SUM(p.amount) as prev_revenue FROM payments p
                   INNER JOIN reservations r ON p.reservation_id = r.reservation_id
                   WHERE p.company_id = ? AND p.status = 'approved'
                   AND p.payment_date BETWEEN ? AND ?";
    $prev_params = [$company_id, $prev_start, $prev_end];
    if ($project_id) {
        $prev_query .= " AND r.plot_id IN (SELECT plot_id FROM plots WHERE project_id = ?)";
        $prev_params[] = $project_id;
    }
    
    $stmt = $conn->prepare($prev_query);
    $stmt->execute($prev_params);
    $prev_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $prev_revenue = $prev_data['prev_revenue'] ?? 0;
    
    if ($prev_revenue > 0) {
        $growth_rate = ((($summary_stats['total_revenue'] ?? 0) - $prev_revenue) / $prev_revenue) * 100;
    }
    
    // Detailed revenue data
    $query = "
        SELECT 
            p.payment_date,
            p.payment_number,
            p.amount,
            p.payment_method,
            CASE 
                WHEN p.amount = r.total_amount THEN 'full_payment'
                WHEN p.amount = r.down_payment THEN 'down_payment'
                ELSE 'installment'
            END as payment_type,
            r.reservation_number,
            c.full_name as customer_name,
            COALESCE(c.phone1, c.phone) as phone,
            pl.plot_number,
            pl.block_number,
            pr.project_name,
            pr.project_code,
            u.full_name as received_by_name
        FROM payments p
        INNER JOIN reservations r ON p.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots pl ON r.plot_id = pl.plot_id
        INNER JOIN projects pr ON pl.project_id = pr.project_id
        LEFT JOIN users u ON p.created_by = u.user_id
        WHERE p.company_id = ?
        AND p.status = 'approved'
        AND p.payment_date BETWEEN ? AND ?
    ";
    
    $params = [$company_id, $start_date, $end_date];
    if ($project_id) {
        $query .= " AND pr.project_id = ?";
        $params[] = $project_id;
    }
    $query .= " ORDER BY p.payment_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $revenue_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get projects for filter
    $stmt = $conn->prepare("SELECT project_id, project_name FROM projects WHERE company_id = ? AND is_active = 1 ORDER BY project_name");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Revenue query error: " . $e->getMessage());
}

$page_title = 'Revenue Analysis';
require_once '../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: white !important; }
}

.revenue-page {
    background: #f4f6f9;
    min-height: 100vh;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.metrics-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

@media (max-width: 1200px) {
    .metrics-row { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .metrics-row { grid-template-columns: 1fr; }
}

.metric-box {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: transform 0.2s, box-shadow 0.2s;
    border-left: 4px solid;
}

.metric-box:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.metric-box.purple { border-left-color: #667eea; }
.metric-box.green { border-left-color: #10b981; }
.metric-box.blue { border-left-color: #3b82f6; }
.metric-box.orange { border-left-color: #f59e0b; }

.metric-label {
    font-size: 13px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    font-weight: 600;
}

.metric-value {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 5px;
}

.metric-change {
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 5px;
    display: inline-block;
}

.metric-change.positive { background: #d1fae5; color: #065f46; }
.metric-change.negative { background: #fee2e2; color: #991b1b; }

.content-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 25px;
    overflow: hidden;
}

.card-header-custom {
    padding: 20px 25px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-header-custom h5 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #111827;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 25px;
}

.chart-box {
    padding: 25px;
    height: 350px;
}

.data-table {
    width: 100%;
    font-size: 14px;
}

.data-table thead {
    background: #f9fafb;
}

.data-table thead th {
    padding: 12px 15px;
    font-weight: 600;
    color: #374151;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.data-table tbody td {
    padding: 12px 15px;
    border-bottom: 1px solid #f3f4f6;
    color: #4b5563;
}

.data-table tbody tr:hover {
    background: #f9fafb;
}

.badge-custom {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.badge-down { background: #fef3c7; color: #92400e; }
.badge-installment { background: #dbeafe; color: #1e40af; }
.badge-full { background: #d1fae5; color: #065f46; }

.btn-modern {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}

.btn-purple {
    background: #667eea;
    color: white;
}

.btn-green {
    background: #10b981;
    color: white;
}

.btn-gray {
    background: #6b7280;
    color: white;
}

.total-row {
    background: #f9fafb;
    font-weight: 700;
    border-top: 2px solid #667eea !important;
}
</style>

<div class="revenue-page">
    
    <!-- Page Header -->
    <div class="page-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2" style="font-size: 32px; font-weight: 700;">
                    <i class="fas fa-chart-line me-2"></i>Revenue Analysis
                </h1>
                <p class="mb-0" style="opacity: 0.9;">
                    Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?>
                </p>
            </div>
            <div>
                <button onclick="window.print()" class="btn-modern btn-gray me-2">
                    <i class="fas fa-print me-1"></i>Print
                </button>
                <button onclick="exportToExcel()" class="btn-modern btn-green me-2">
                    <i class="fas fa-file-excel me-1"></i>Export
                </button>
                <a href="index.php" class="btn-modern btn-purple">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card no-print">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>" style="border-radius: 8px;">
            </div>
            <div class="col-md-3">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>" style="border-radius: 8px;">
            </div>
            <div class="col-md-4">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">Project</label>
                <select name="project_id" class="form-select" style="border-radius: 8px;">
                    <option value="">All Projects</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?= $proj['project_id'] ?>" <?= $project_id == $proj['project_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($proj['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn-modern btn-purple w-100">
                    <i class="fas fa-search me-1"></i>Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Metrics -->
    <div class="metrics-row">
        <div class="metric-box purple">
            <div class="metric-label">Total Revenue</div>
            <div class="metric-value">TSH <?= number_format(($summary_stats['total_revenue'] ?? 0)/1000000, 2) ?>M</div>
        </div>
        <div class="metric-box green">
            <div class="metric-label">Transactions</div>
            <div class="metric-value"><?= number_format($summary_stats['total_transactions'] ?? 0) ?></div>
        </div>
        <div class="metric-box blue">
            <div class="metric-label">Average Transaction</div>
            <div class="metric-value">TSH <?= number_format(($summary_stats['avg_transaction'] ?? 0)/1000, 1) ?>K</div>
        </div>
        <div class="metric-box orange">
            <div class="metric-label">Growth Rate</div>
            <div class="metric-value"><?= number_format($growth_rate, 1) ?>%</div>
            <?php if ($growth_rate >= 0): ?>
                <span class="metric-change positive"><i class="fas fa-arrow-up"></i> Positive</span>
            <?php else: ?>
                <span class="metric-change negative"><i class="fas fa-arrow-down"></i> Negative</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chart -->
    <div class="content-card no-print">
        <div class="card-header-custom">
            <h5><i class="fas fa-chart-area me-2"></i>Revenue Trend</h5>
        </div>
        <div class="chart-box">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    <!-- Data Table -->
    <div class="content-card">
        <div class="card-header-custom">
            <h5><i class="fas fa-table me-2"></i>Transaction Details</h5>
            <span style="color: #6b7280; font-size: 13px;"><?= count($revenue_data) ?> records</span>
        </div>
        <div style="padding: 25px;">
            <?php if (empty($revenue_data)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-4x mb-3" style="color: #d1d5db;"></i>
                    <p style="color: #6b7280;">No revenue data found for the selected period</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payment #</th>
                                <th>Customer</th>
                                <th>Project</th>
                                <th>Plot</th>
                                <th>Type</th>
                                <th>Method</th>
                                <th class="text-end">Amount</th>
                                <th>Received By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total = 0;
                            foreach ($revenue_data as $row): 
                                $total += $row['amount'];
                            ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($row['payment_date'])) ?></td>
                                <td><strong><?= htmlspecialchars($row['payment_number']) ?></strong></td>
                                <td>
                                    <?= htmlspecialchars($row['customer_name']) ?>
                                    <br><small style="color: #9ca3af;"><?= htmlspecialchars($row['phone'] ?? '') ?></small>
                                </td>
                                <td>
                                    <small style="color: #6b7280;"><?= htmlspecialchars($row['project_code']) ?></small><br>
                                    <?= htmlspecialchars($row['project_name']) ?>
                                </td>
                                <td><?= htmlspecialchars($row['plot_number'] . ($row['block_number'] ? '/' . $row['block_number'] : '')) ?></td>
                                <td>
                                    <span class="badge-custom badge-<?= $row['payment_type'] == 'down_payment' ? 'down' : ($row['payment_type'] == 'full_payment' ? 'full' : 'installment') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $row['payment_type'])) ?>
                                    </span>
                                </td>
                                <td><?= ucfirst(str_replace('_', ' ', $row['payment_method'])) ?></td>
                                <td class="text-end"><strong>TSH <?= number_format($row['amount'], 0) ?></strong></td>
                                <td><?= htmlspecialchars($row['received_by_name'] ?: '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="7" class="text-end"><strong>TOTAL</strong></td>
                                <td class="text-end"><strong>TSH <?= number_format($total, 0) ?></strong></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
// Chart
<?php
$chart_labels = [];
$chart_values = [];
$days = (strtotime($end_date) - strtotime($start_date)) / 86400;

if ($days <= 31) {
    for ($i = 0; $i <= $days; $i++) {
        $date = date('Y-m-d', strtotime($start_date . " +$i days"));
        $chart_labels[] = date('d M', strtotime($date));
        $daily_total = 0;
        foreach ($revenue_data as $payment) {
            if (date('Y-m-d', strtotime($payment['payment_date'])) == $date) {
                $daily_total += $payment['amount'];
            }
        }
        $chart_values[] = $daily_total;
    }
} else {
    $start_month = date('Y-m', strtotime($start_date));
    $end_month = date('Y-m', strtotime($end_date));
    $current = $start_month;
    
    while ($current <= $end_month) {
        $chart_labels[] = date('M Y', strtotime($current . '-01'));
        $monthly_total = 0;
        foreach ($revenue_data as $payment) {
            if (date('Y-m', strtotime($payment['payment_date'])) == $current) {
                $monthly_total += $payment['amount'];
            }
        }
        $chart_values[] = $monthly_total;
        $current = date('Y-m', strtotime($current . '-01 +1 month'));
    }
}
?>

const ctx = document.getElementById('revenueChart');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [{
            label: 'Revenue',
            data: <?= json_encode($chart_values) ?>,
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            borderWidth: 3,
            tension: 0.4,
            fill: true,
            pointRadius: 4,
            pointHoverRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
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
    const data = [
        ['REVENUE ANALYSIS REPORT'],
        ['Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?>'],
        ['Generated: <?= date('d M Y, h:i A') ?>'],
        [],
        ['SUMMARY'],
        ['Total Revenue', 'TSH <?= number_format($summary_stats['total_revenue'] ?? 0, 2) ?>'],
        ['Transactions', '<?= $summary_stats['total_transactions'] ?? 0 ?>'],
        ['Average', 'TSH <?= number_format($summary_stats['avg_transaction'] ?? 0, 2) ?>'],
        ['Growth Rate', '<?= number_format($growth_rate, 2) ?>%'],
        [],
        ['TRANSACTIONS'],
        ['Date', 'Payment #', 'Customer', 'Phone', 'Project', 'Plot', 'Type', 'Method', 'Amount', 'Received By']
    ];
    
    <?php foreach ($revenue_data as $row): ?>
    data.push([
        '<?= date('d M Y', strtotime($row['payment_date'])) ?>',
        '<?= $row['payment_number'] ?>',
        '<?= addslashes($row['customer_name']) ?>',
        '<?= $row['phone'] ?? '' ?>',
        '<?= addslashes($row['project_name']) ?>',
        '<?= $row['plot_number'] ?>',
        '<?= ucfirst(str_replace('_', ' ', $row['payment_type'])) ?>',
        '<?= ucfirst(str_replace('_', ' ', $row['payment_method'])) ?>',
        <?= $row['amount'] ?>,
        '<?= addslashes($row['received_by_name'] ?: '-') ?>'
    ]);
    <?php endforeach; ?>
    
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:12},{wch:15},{wch:25},{wch:15},{wch:30},{wch:10},{wch:15},{wch:15},{wch:15},{wch:25}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Revenue');
    XLSX.writeFile(wb, 'Revenue_<?= date('Y-m-d') ?>.xlsx');
}
</script>

<?php require_once '../../includes/footer.php'; ?>