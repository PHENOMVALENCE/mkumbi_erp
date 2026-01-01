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
$sales_person_id = $_GET['sales_person_id'] ?? '';

// Initialize data
$summary_stats = [
    'total_sales' => 0,
    'total_commission_earned' => 0,
    'total_commission_paid' => 0,
    'total_commission_pending' => 0
];
$commission_data = [];
$sales_people = [];

try {
    // Summary statistics - using commissions table
    $query = "
        SELECT 
            COUNT(DISTINCT c.reservation_id) as total_sales,
            SUM(r.total_amount) as total_sales_value,
            SUM(c.commission_amount) as total_commission_earned,
            SUM(CASE WHEN c.payment_status = 'paid' THEN c.commission_amount ELSE 0 END) as total_commission_paid,
            SUM(CASE WHEN c.payment_status = 'pending' THEN c.commission_amount ELSE 0 END) as total_commission_pending
        FROM commissions c
        INNER JOIN reservations r ON c.reservation_id = r.reservation_id
        WHERE c.company_id = ?
        AND DATE(c.created_at) BETWEEN ? AND ?
        AND c.commission_amount > 0
    ";
    
    $params = [$company_id, $start_date, $end_date];
    if ($sales_person_id) {
        $query .= " AND c.user_id = ?";
        $params[] = $sales_person_id;
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $summary_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Detailed commission data
    $query = "
        SELECT 
            c.commission_id,
            c.commission_number,
            DATE(c.created_at) as commission_date,
            r.reservation_id,
            r.reservation_number,
            r.reservation_date,
            r.total_amount,
            c.commission_amount,
            c.commission_percentage,
            c.payment_status as commission_status,
            c.recipient_name,
            c.recipient_phone,
            cust.full_name as customer_name,
            cust.phone1 as phone,
            p.plot_number,
            p.block_number,
            pr.project_name,
            pr.project_code,
            u.full_name as sales_person_name
        FROM commissions c
        INNER JOIN reservations r ON c.reservation_id = r.reservation_id
        INNER JOIN customers cust ON r.customer_id = cust.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects pr ON p.project_id = pr.project_id
        LEFT JOIN users u ON c.user_id = u.user_id
        WHERE c.company_id = ?
        AND DATE(c.created_at) BETWEEN ? AND ?
        AND c.commission_amount > 0
    ";
    
    $params = [$company_id, $start_date, $end_date];
    if ($sales_person_id) {
        $query .= " AND c.user_id = ?";
        $params[] = $sales_person_id;
    }
    $query .= " ORDER BY c.commission_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $commission_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get sales people for filter - users who have commissions
    $stmt = $conn->prepare("
        SELECT DISTINCT u.user_id, u.full_name 
        FROM users u
        INNER JOIN commissions c ON u.user_id = c.user_id
        WHERE c.company_id = ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$company_id]);
    $sales_people = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Commission query error: " . $e->getMessage());
}

$page_title = 'Commission Report';
require_once '../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: white !important; }
}

.commission-page {
    background: #f4f6f9;
    min-height: 100vh;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
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

.metric-box.orange { border-left-color: #f59e0b; }
.metric-box.green { border-left-color: #10b981; }
.metric-box.red { border-left-color: #ef4444; }
.metric-box.blue { border-left-color: #3b82f6; }

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

.badge-custom {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.badge-paid { background: #d1fae5; color: #065f46; }
.badge-pending { background: #fef3c7; color: #92400e; }
.badge-cancelled { background: #fee2e2; color: #991b1b; }

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

.btn-orange {
    background: #f59e0b;
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
    border-top: 2px solid #f59e0b !important;
}
</style>

<div class="commission-page">
    
    <!-- Page Header -->
    <div class="page-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2" style="font-size: 32px; font-weight: 700;">
                    <i class="fas fa-percentage me-2"></i>Commission Report
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
                <a href="index.php" class="btn-modern btn-orange">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card no-print">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>" style="border-radius: 8px;">
            </div>
            <div class="col-md-4">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>" style="border-radius: 8px;">
            </div>
            <div class="col-md-3">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">Sales Person</label>
                <select name="sales_person_id" class="form-select" style="border-radius: 8px;">
                    <option value="">All Sales People</option>
                    <?php foreach ($sales_people as $person): ?>
                        <option value="<?= $person['user_id'] ?>" <?= $sales_person_id == $person['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($person['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn-modern btn-orange w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>

    <!-- Metrics -->
    <div class="metrics-row">
        <div class="metric-box orange">
            <div class="metric-label">Total Earned</div>
            <div class="metric-value">TSH <?= number_format(($summary_stats['total_commission_earned'] ?? 0)/1000000, 2) ?>M</div>
        </div>
        <div class="metric-box green">
            <div class="metric-label">Commission Paid</div>
            <div class="metric-value">TSH <?= number_format(($summary_stats['total_commission_paid'] ?? 0)/1000000, 2) ?>M</div>
        </div>
        <div class="metric-box red">
            <div class="metric-label">Pending Payment</div>
            <div class="metric-value">TSH <?= number_format(($summary_stats['total_commission_pending'] ?? 0)/1000000, 2) ?>M</div>
        </div>
        <div class="metric-box blue">
            <div class="metric-label">Total Sales</div>
            <div class="metric-value"><?= number_format($summary_stats['total_sales'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Chart -->
    <div class="content-card no-print">
        <div class="card-header-custom">
            <h5><i class="fas fa-chart-bar me-2"></i>Commission Breakdown</h5>
        </div>
        <div class="chart-box">
            <canvas id="commissionChart"></canvas>
        </div>
    </div>

    <!-- Data Table -->
    <div class="content-card">
        <div class="card-header-custom">
            <h5><i class="fas fa-list-ul me-2"></i>Commission Details</h5>
            <span style="color: #6b7280; font-size: 13px;"><?= count($commission_data) ?> records</span>
        </div>
        <div style="padding: 25px;">
            <?php if (empty($commission_data)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-4x mb-3" style="color: #d1d5db;"></i>
                    <p style="color: #6b7280;">No commission data found for the selected period</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reservation #</th>
                                <th>Customer</th>
                                <th>Project</th>
                                <th>Plot</th>
                                <th class="text-end">Sale Amount</th>
                                <th class="text-center">Rate</th>
                                <th class="text-end">Commission</th>
                                <th>Status</th>
                                <th>Sales Person</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_sales = 0;
                            $total_commission = 0;
                            foreach ($commission_data as $row): 
                                $total_sales += $row['total_amount'];
                                $total_commission += $row['commission_amount'];
                            ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($row['commission_date'] ?? $row['reservation_date'])) ?></td>
                                <td><strong><?= htmlspecialchars($row['reservation_number']) ?></strong></td>
                                <td>
                                    <?= htmlspecialchars($row['customer_name']) ?>
                                    <br><small style="color: #9ca3af;"><?= htmlspecialchars($row['phone'] ?? '') ?></small>
                                </td>
                                <td>
                                    <small style="color: #6b7280;"><?= htmlspecialchars($row['project_code']) ?></small><br>
                                    <?= htmlspecialchars($row['project_name']) ?>
                                </td>
                                <td><?= htmlspecialchars($row['plot_number'] . ($row['block_number'] ? '/' . $row['block_number'] : '')) ?></td>
                                <td class="text-end">TSH <?= number_format($row['total_amount'], 0) ?></td>
                                <td class="text-center"><strong><?= $row['commission_percentage'] ?? 0 ?>%</strong></td>
                                <td class="text-end"><strong>TSH <?= number_format($row['commission_amount'], 0) ?></strong></td>
                                <td>
                                    <span class="badge-custom badge-<?= strtolower($row['commission_status']) ?>">
                                        <?= ucfirst($row['commission_status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['recipient_name'] ?? $row['sales_person_name'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="5" class="text-end"><strong>TOTALS</strong></td>
                                <td class="text-end"><strong>TSH <?= number_format($total_sales, 0) ?></strong></td>
                                <td></td>
                                <td class="text-end"><strong>TSH <?= number_format($total_commission, 0) ?></strong></td>
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
const ctx = document.getElementById('commissionChart');
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: ['Paid', 'Pending'],
        datasets: [{
            data: [
                <?= $summary_stats['total_commission_paid'] ?? 0 ?>,
                <?= $summary_stats['total_commission_pending'] ?? 0 ?>
            ],
            backgroundColor: ['#10b981', '#f59e0b'],
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
        ['COMMISSION REPORT'],
        ['Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?>'],
        ['Generated: <?= date('d M Y, h:i A') ?>'],
        [],
        ['SUMMARY'],
        ['Total Commission Earned', 'TSH <?= number_format($summary_stats['total_commission_earned'] ?? 0, 2) ?>'],
        ['Commission Paid', 'TSH <?= number_format($summary_stats['total_commission_paid'] ?? 0, 2) ?>'],
        ['Commission Pending', 'TSH <?= number_format($summary_stats['total_commission_pending'] ?? 0, 2) ?>'],
        ['Total Sales', '<?= $summary_stats['total_sales'] ?? 0 ?>'],
        [],
        ['COMMISSION DETAILS'],
        ['Date', 'Reservation #', 'Customer', 'Phone', 'Project', 'Plot', 'Sale Amount', 'Rate', 'Commission', 'Status', 'Sales Person']
    ];
    
    <?php foreach ($commission_data as $row): ?>
    data.push([
        '<?= date('d M Y', strtotime($row['commission_date'] ?? $row['reservation_date'])) ?>',
        '<?= $row['reservation_number'] ?>',
        '<?= addslashes($row['customer_name']) ?>',
        '<?= $row['phone'] ?? '' ?>',
        '<?= addslashes($row['project_name']) ?>',
        '<?= $row['plot_number'] ?>',
        <?= $row['total_amount'] ?>,
        '<?= $row['commission_percentage'] ?? 0 ?>%',
        <?= $row['commission_amount'] ?>,
        '<?= ucfirst($row['commission_status']) ?>',
        '<?= addslashes($row['recipient_name'] ?? $row['sales_person_name'] ?? '-') ?>'
    ]);
    <?php endforeach; ?>
    
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:12},{wch:15},{wch:25},{wch:15},{wch:30},{wch:10},{wch:15},{wch:8},{wch:15},{wch:12},{wch:25}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Commission');
    XLSX.writeFile(wb, 'Commission_<?= date('Y-m-d') ?>.xlsx');
}
</script>

<?php require_once '../../includes/footer.php'; ?>