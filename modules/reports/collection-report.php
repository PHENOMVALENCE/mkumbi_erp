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

// Initialize data
$summary_stats = [
    'total_collected' => 0,
    'total_outstanding' => 0,
    'collection_rate' => 0,
    'total_reservations' => 0
];
$collection_data = [];
$projects = [];

try {
    // Summary statistics
    $query = "
        SELECT 
            COUNT(DISTINCT r.reservation_id) as total_reservations,
            SUM(r.total_amount) as total_value,
            COALESCE(SUM((SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved')), 0) as total_collected,
            (SUM(r.total_amount) - COALESCE(SUM((SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved')), 0)) as total_outstanding
        FROM reservations r
        WHERE r.company_id = ?
        AND r.reservation_date <= ?
        AND r.status IN ('active', 'completed')
    ";
    
    $params = [$company_id, $end_date];
    if ($project_id) {
        $query .= " AND r.plot_id IN (SELECT plot_id FROM plots WHERE project_id = ?)";
        $params[] = $project_id;
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $summary_stats['total_collected'] = $stats['total_collected'] ?? 0;
    $summary_stats['total_outstanding'] = $stats['total_outstanding'] ?? 0;
    $summary_stats['total_reservations'] = $stats['total_reservations'] ?? 0;
    
    if ($stats['total_value'] > 0) {
        $summary_stats['collection_rate'] = ($stats['total_collected'] / $stats['total_value']) * 100;
    }
    
    // Detailed collection data
    $query = "
        SELECT 
            r.reservation_id,
            r.reservation_number,
            r.reservation_date,
            r.total_amount,
            r.down_payment,
            c.full_name as customer_name,
            c.phone,
            c.email,
            p.plot_number,
            p.block_number,
            pr.project_name,
            pr.project_code,
            COALESCE((SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved'), 0) as total_paid,
            (r.total_amount - COALESCE((SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved'), 0)) as balance,
            (SELECT MAX(payment_date) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved') as last_payment_date
        FROM reservations r
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects pr ON p.project_id = pr.project_id
        WHERE r.company_id = ?
        AND r.reservation_date <= ?
        AND r.status IN ('active', 'completed')
    ";
    
    $params = [$company_id, $end_date];
    if ($project_id) {
        $query .= " AND pr.project_id = ?";
        $params[] = $project_id;
    }
    $query .= " ORDER BY balance DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $collection_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get projects for filter
    $stmt = $conn->prepare("SELECT project_id, project_name FROM projects WHERE company_id = ? AND is_active = 1 ORDER BY project_name");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Collection query error: " . $e->getMessage());
}

$page_title = 'Collection Report';
require_once '../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: white !important; }
}

.collection-page {
    background: #f4f6f9;
    min-height: 100vh;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
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

.metric-box.green { border-left-color: #10b981; }
.metric-box.red { border-left-color: #ef4444; }
.metric-box.blue { border-left-color: #3b82f6; }
.metric-box.purple { border-left-color: #8b5cf6; }

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

.progress-bar-custom {
    height: 8px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 5px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
    border-radius: 10px;
    transition: width 0.3s;
}

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
    border-top: 2px solid #10b981 !important;
}

.status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 5px;
}

.status-good { background: #10b981; }
.status-warning { background: #f59e0b; }
.status-critical { background: #ef4444; }
</style>

<div class="collection-page">
    
    <!-- Page Header -->
    <div class="page-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2" style="font-size: 32px; font-weight: 700;">
                    <i class="fas fa-hand-holding-usd me-2"></i>Collection Report
                </h1>
                <p class="mb-0" style="opacity: 0.9;">
                    As of: <?= date('d M Y', strtotime($end_date)) ?>
                </p>
            </div>
            <div>
                <button onclick="window.print()" class="btn-modern btn-gray me-2">
                    <i class="fas fa-print me-1"></i>Print
                </button>
                <button onclick="exportToExcel()" class="btn-modern btn-green me-2">
                    <i class="fas fa-file-excel me-1"></i>Export
                </button>
                <a href="index.php" class="btn-modern btn-green">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card no-print">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">As of Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>" style="border-radius: 8px;">
            </div>
            <div class="col-md-5">
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
                <button type="submit" class="btn-modern btn-green w-100">
                    <i class="fas fa-search me-1"></i>Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Metrics -->
    <div class="metrics-row">
        <div class="metric-box green">
            <div class="metric-label">Total Collected</div>
            <div class="metric-value">TSH <?= number_format(($summary_stats['total_collected'])/1000000, 2) ?>M</div>
        </div>
        <div class="metric-box red">
            <div class="metric-label">Outstanding Balance</div>
            <div class="metric-value">TSH <?= number_format(($summary_stats['total_outstanding'])/1000000, 2) ?>M</div>
        </div>
        <div class="metric-box blue">
            <div class="metric-label">Collection Rate</div>
            <div class="metric-value"><?= number_format($summary_stats['collection_rate'], 1) ?>%</div>
        </div>
        <div class="metric-box purple">
            <div class="metric-label">Total Accounts</div>
            <div class="metric-value"><?= number_format($summary_stats['total_reservations']) ?></div>
        </div>
    </div>

    <!-- Chart -->
    <div class="content-card no-print">
        <div class="card-header-custom">
            <h5><i class="fas fa-chart-pie me-2"></i>Collection Overview</h5>
        </div>
        <div class="chart-box">
            <canvas id="collectionChart"></canvas>
        </div>
    </div>

    <!-- Data Table -->
    <div class="content-card">
        <div class="card-header-custom">
            <h5><i class="fas fa-money-check-alt me-2"></i>Collection Details</h5>
            <span style="color: #6b7280; font-size: 13px;"><?= count($collection_data) ?> accounts</span>
        </div>
        <div style="padding: 25px;">
            <?php if (empty($collection_data)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-4x mb-3" style="color: #d1d5db;"></i>
                    <p style="color: #6b7280;">No collection data found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Reservation #</th>
                                <th>Customer</th>
                                <th>Project</th>
                                <th>Plot</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-end">Collected</th>
                                <th class="text-end">Balance</th>
                                <th>Collection %</th>
                                <th>Last Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_amount = 0;
                            $total_collected = 0;
                            $total_balance = 0;
                            foreach ($collection_data as $row): 
                                $total_amount += $row['total_amount'];
                                $total_collected += $row['total_paid'];
                                $total_balance += $row['balance'];
                                $collection_pct = $row['total_amount'] > 0 ? ($row['total_paid'] / $row['total_amount']) * 100 : 0;
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['reservation_number']) ?></strong></td>
                                <td>
                                    <?= htmlspecialchars($row['customer_name']) ?>
                                    <br><small style="color: #9ca3af;"><?= htmlspecialchars($row['phone']) ?></small>
                                </td>
                                <td>
                                    <small style="color: #6b7280;"><?= htmlspecialchars($row['project_code']) ?></small><br>
                                    <?= htmlspecialchars($row['project_name']) ?>
                                </td>
                                <td><?= htmlspecialchars($row['plot_number'] . ($row['block_number'] ? '/' . $row['block_number'] : '')) ?></td>
                                <td class="text-end">TSH <?= number_format($row['total_amount'], 0) ?></td>
                                <td class="text-end" style="color: #10b981;"><strong>TSH <?= number_format($row['total_paid'], 0) ?></strong></td>
                                <td class="text-end" style="color: #ef4444;"><strong>TSH <?= number_format($row['balance'], 0) ?></strong></td>
                                <td>
                                    <div>
                                        <?php 
                                        $status_class = $collection_pct >= 80 ? 'status-good' : ($collection_pct >= 50 ? 'status-warning' : 'status-critical');
                                        ?>
                                        <span class="status-indicator <?= $status_class ?>"></span>
                                        <strong><?= number_format($collection_pct, 0) ?>%</strong>
                                    </div>
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill" style="width: <?= $collection_pct ?>%"></div>
                                    </div>
                                </td>
                                <td><?= $row['last_payment_date'] ? date('d M Y', strtotime($row['last_payment_date'])) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="4" class="text-end"><strong>TOTALS</strong></td>
                                <td class="text-end"><strong>TSH <?= number_format($total_amount, 0) ?></strong></td>
                                <td class="text-end"><strong>TSH <?= number_format($total_collected, 0) ?></strong></td>
                                <td class="text-end"><strong>TSH <?= number_format($total_balance, 0) ?></strong></td>
                                <td colspan="2"></td>
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
const ctx = document.getElementById('collectionChart');
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: ['Collected', 'Outstanding'],
        datasets: [{
            data: [
                <?= $summary_stats['total_collected'] ?>,
                <?= $summary_stats['total_outstanding'] ?>
            ],
            backgroundColor: ['#10b981', '#ef4444'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 20,
                    font: { size: 14 }
                }
            }
        }
    }
});

function exportToExcel() {
    const data = [
        ['COLLECTION REPORT'],
        ['As of: <?= date('d M Y', strtotime($end_date)) ?>'],
        ['Generated: <?= date('d M Y, h:i A') ?>'],
        [],
        ['SUMMARY'],
        ['Total Collected', 'TSH <?= number_format($summary_stats['total_collected'], 2) ?>'],
        ['Total Outstanding', 'TSH <?= number_format($summary_stats['total_outstanding'], 2) ?>'],
        ['Collection Rate', '<?= number_format($summary_stats['collection_rate'], 2) ?>%'],
        ['Total Accounts', '<?= $summary_stats['total_reservations'] ?>'],
        [],
        ['COLLECTION DETAILS'],
        ['Reservation #', 'Customer', 'Phone', 'Project', 'Plot', 'Total Amount', 'Collected', 'Balance', 'Collection %', 'Last Payment']
    ];
    
    <?php foreach ($collection_data as $row): 
        $collection_pct = $row['total_amount'] > 0 ? ($row['total_paid'] / $row['total_amount']) * 100 : 0;
    ?>
    data.push([
        '<?= $row['reservation_number'] ?>',
        '<?= addslashes($row['customer_name']) ?>',
        '<?= $row['phone'] ?>',
        '<?= addslashes($row['project_name']) ?>',
        '<?= $row['plot_number'] ?>',
        <?= $row['total_amount'] ?>,
        <?= $row['total_paid'] ?>,
        <?= $row['balance'] ?>,
        '<?= number_format($collection_pct, 2) ?>%',
        '<?= $row['last_payment_date'] ? date('d M Y', strtotime($row['last_payment_date'])) : '-' ?>'
    ]);
    <?php endforeach; ?>
    
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:15},{wch:25},{wch:15},{wch:30},{wch:10},{wch:15},{wch:15},{wch:15},{wch:12},{wch:15}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Collection');
    XLSX.writeFile(wb, 'Collection_<?= date('Y-m-d') ?>.xlsx');
}
</script>

<?php require_once '../../includes/footer.php'; ?>