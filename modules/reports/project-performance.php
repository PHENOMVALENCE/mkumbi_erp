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

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// Initialize data
$summary_stats = [
    'total_projects' => 0,
    'total_plots' => 0,
    'plots_sold' => 0,
    'total_revenue' => 0
];
$project_data = [];
$projects = [];
$total_records = 0;

try {
    // Summary statistics
    $query = "
        SELECT 
            COUNT(DISTINCT pr.project_id) as total_projects,
            COUNT(p.plot_id) as total_plots,
            SUM(CASE WHEN p.status = 'sold' THEN 1 ELSE 0 END) as plots_sold,
            COALESCE(SUM(r.total_amount), 0) as total_revenue
        FROM projects pr
        LEFT JOIN plots p ON pr.project_id = p.project_id
        LEFT JOIN reservations r ON p.plot_id = r.plot_id AND r.status IN ('active', 'completed')
        WHERE pr.company_id = ?
    ";
    
    $params = [$company_id];
    if ($project_id) {
        $query .= " AND pr.project_id = ?";
        $params[] = $project_id;
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $summary_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Count total records for pagination
    $count_query = "
        SELECT COUNT(DISTINCT pr.project_id) as total
        FROM projects pr
        WHERE pr.company_id = ?
    ";
    $count_params = [$company_id];
    if ($project_id) {
        $count_query .= " AND pr.project_id = ?";
        $count_params[] = $project_id;
    }
    
    $stmt = $conn->prepare($count_query);
    $stmt->execute($count_params);
    $total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Project performance data with pagination
    $query = "
        SELECT 
            pr.project_id,
            pr.project_name,
            pr.project_code,
            pr.location,
            pr.status as project_status,
            COUNT(DISTINCT p.plot_id) as total_plots,
            SUM(CASE WHEN p.status = 'available' THEN 1 ELSE 0 END) as available_plots,
            SUM(CASE WHEN p.status = 'reserved' THEN 1 ELSE 0 END) as reserved_plots,
            SUM(CASE WHEN p.status = 'sold' THEN 1 ELSE 0 END) as sold_plots,
            SUM(p.area) as total_area,
            COUNT(DISTINCT r.reservation_id) as total_sales,
            COALESCE(SUM(r.total_amount), 0) as total_revenue,
            COALESCE(SUM((SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved')), 0) as total_collected,
            COALESCE(SUM(r.total_amount) - SUM((SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved')), 0) as outstanding_balance
        FROM projects pr
        LEFT JOIN plots p ON pr.project_id = p.project_id
        LEFT JOIN reservations r ON p.plot_id = r.plot_id AND r.status IN ('active', 'completed')
        WHERE pr.company_id = ?
    ";
    
    $params = [$company_id];
    if ($project_id) {
        $query .= " AND pr.project_id = ?";
        $params[] = $project_id;
    }
    
    $query .= " GROUP BY pr.project_id, pr.project_name, pr.project_code, pr.location, pr.status
                ORDER BY total_revenue DESC
                LIMIT $records_per_page OFFSET $offset";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $project_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all projects for filter
    $stmt = $conn->prepare("SELECT project_id, project_name FROM projects WHERE company_id = ? AND is_active = 1 ORDER BY project_name");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get ALL project data for Excel/PDF export (no pagination)
    $export_query = "
        SELECT 
            pr.project_id,
            pr.project_name,
            pr.project_code,
            pr.location,
            pr.status as project_status,
            COUNT(DISTINCT p.plot_id) as total_plots,
            SUM(CASE WHEN p.status = 'available' THEN 1 ELSE 0 END) as available_plots,
            SUM(CASE WHEN p.status = 'reserved' THEN 1 ELSE 0 END) as reserved_plots,
            SUM(CASE WHEN p.status = 'sold' THEN 1 ELSE 0 END) as sold_plots,
            SUM(p.area) as total_area,
            COUNT(DISTINCT r.reservation_id) as total_sales,
            COALESCE(SUM(r.total_amount), 0) as total_revenue,
            COALESCE(SUM((SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved')), 0) as total_collected,
            COALESCE(SUM(r.total_amount) - SUM((SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved')), 0) as outstanding_balance
        FROM projects pr
        LEFT JOIN plots p ON pr.project_id = p.project_id
        LEFT JOIN reservations r ON p.plot_id = r.plot_id AND r.status IN ('active', 'completed')
        WHERE pr.company_id = ?
    ";
    
    $export_params = [$company_id];
    if ($project_id) {
        $export_query .= " AND pr.project_id = ?";
        $export_params[] = $project_id;
    }
    
    $export_query .= " GROUP BY pr.project_id, pr.project_name, pr.project_code, pr.location, pr.status
                       ORDER BY total_revenue DESC";
    
    $stmt = $conn->prepare($export_query);
    $stmt->execute($export_params);
    $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Project performance query error: " . $e->getMessage());
}

// Calculate pagination
$total_pages = ceil($total_records / $records_per_page);

$page_title = 'Project Performance Report';
require_once '../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: white !important; }
}

.performance-page {
    background: #f4f6f9;
    min-height: 100vh;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
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

.metric-box.purple { border-left-color: #8b5cf6; }
.metric-box.blue { border-left-color: #3b82f6; }
.metric-box.green { border-left-color: #10b981; }
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

.badge-active { background: #d1fae5; color: #065f46; }
.badge-completed { background: #dbeafe; color: #1e40af; }
.badge-planning { background: #fef3c7; color: #92400e; }

.progress-bar-custom {
    height: 8px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 5px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #8b5cf6 0%, #7c3aed 100%);
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

.btn-purple { background: #8b5cf6; color: white; }
.btn-green { background: #10b981; color: white; }
.btn-red { background: #ef4444; color: white; }
.btn-gray { background: #6b7280; color: white; }

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
}

.pagination a, .pagination span {
    padding: 8px 12px;
    border-radius: 6px;
    text-decoration: none;
    color: #374151;
    background: white;
    border: 1px solid #e5e7eb;
}

.pagination a:hover {
    background: #8b5cf6;
    color: white;
    border-color: #8b5cf6;
}

.pagination .active {
    background: #8b5cf6;
    color: white;
    border-color: #8b5cf6;
}

.total-row {
    background: #f9fafb;
    font-weight: 700;
    border-top: 2px solid #8b5cf6 !important;
}
</style>

<div class="performance-page">
    
    <!-- Page Header -->
    <div class="page-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2" style="font-size: 32px; font-weight: 700;">
                    <i class="fas fa-chart-line me-2"></i>Project Performance Report
                </h1>
                <p class="mb-0" style="opacity: 0.9;">
                    Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?>
                </p>
            </div>
            <div>
                <button onclick="exportToPDF()" class="btn-modern btn-red me-2">
                    <i class="fas fa-file-pdf me-1"></i>PDF
                </button>
                <button onclick="exportToExcel()" class="btn-modern btn-green me-2">
                    <i class="fas fa-file-excel me-1"></i>Excel
                </button>
                <button onclick="window.print()" class="btn-modern btn-gray me-2">
                    <i class="fas fa-print me-1"></i>Print
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

    <!-- Summary Metrics -->
    <div class="metrics-row">
        <div class="metric-box purple">
            <div class="metric-label">Total Projects</div>
            <div class="metric-value"><?= number_format($summary_stats['total_projects'] ?? 0) ?></div>
        </div>
        <div class="metric-box blue">
            <div class="metric-label">Total Plots</div>
            <div class="metric-value"><?= number_format($summary_stats['total_plots'] ?? 0) ?></div>
        </div>
        <div class="metric-box green">
            <div class="metric-label">Plots Sold</div>
            <div class="metric-value"><?= number_format($summary_stats['plots_sold'] ?? 0) ?></div>
        </div>
        <div class="metric-box orange">
            <div class="metric-label">Total Revenue</div>
            <div class="metric-value">TSH <?= number_format(($summary_stats['total_revenue'] ?? 0)/1000000, 1) ?>M</div>
        </div>
    </div>

    <!-- Project Performance Table -->
    <div class="content-card">
        <div class="card-header-custom">
            <h5><i class="fas fa-building me-2"></i>Project Performance Details</h5>
            <span style="color: #6b7280; font-size: 13px;">
                Showing <?= (($page - 1) * $records_per_page) + 1 ?> - <?= min($page * $records_per_page, $total_records) ?> of <?= $total_records ?> projects
            </span>
        </div>
        <div style="padding: 25px;">
            <?php if (empty($project_data)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-4x mb-3" style="color: #d1d5db;"></i>
                    <p style="color: #6b7280;">No project data found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project Code</th>
                                <th>Project Name</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th class="text-center">Total Plots</th>
                                <th class="text-center">Available</th>
                                <th class="text-center">Reserved</th>
                                <th class="text-center">Sold</th>
                                <th class="text-end">Total Area (m²)</th>
                                <th class="text-end">Revenue</th>
                                <th class="text-end">Collected</th>
                                <th class="text-end">Outstanding</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_plots_sum = 0;
                            $total_sold_sum = 0;
                            $total_revenue_sum = 0;
                            $total_collected_sum = 0;
                            $total_outstanding_sum = 0;
                            
                            foreach ($project_data as $row): 
                                $sold_percentage = $row['total_plots'] > 0 ? ($row['sold_plots'] / $row['total_plots']) * 100 : 0;
                                $collection_rate = $row['total_revenue'] > 0 ? ($row['total_collected'] / $row['total_revenue']) * 100 : 0;
                                
                                $total_plots_sum += $row['total_plots'];
                                $total_sold_sum += $row['sold_plots'];
                                $total_revenue_sum += $row['total_revenue'];
                                $total_collected_sum += $row['total_collected'];
                                $total_outstanding_sum += $row['outstanding_balance'];
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['project_code']) ?></strong></td>
                                <td><?= htmlspecialchars($row['project_name']) ?></td>
                                <td><?= htmlspecialchars($row['location']) ?></td>
                                <td>
                                    <span class="badge-custom badge-<?= strtolower($row['project_status']) ?>">
                                        <?= ucfirst($row['project_status']) ?>
                                    </span>
                                </td>
                                <td class="text-center"><strong><?= number_format($row['total_plots']) ?></strong></td>
                                <td class="text-center" style="color: #10b981;"><?= number_format($row['available_plots']) ?></td>
                                <td class="text-center" style="color: #f59e0b;"><?= number_format($row['reserved_plots']) ?></td>
                                <td class="text-center" style="color: #8b5cf6;"><?= number_format($row['sold_plots']) ?></td>
                                <td class="text-end"><?= number_format($row['total_area'], 2) ?></td>
                                <td class="text-end"><strong>TSH <?= number_format($row['total_revenue'], 0) ?></strong></td>
                                <td class="text-end" style="color: #10b981;">TSH <?= number_format($row['total_collected'], 0) ?></td>
                                <td class="text-end" style="color: #ef4444;">TSH <?= number_format($row['outstanding_balance'], 0) ?></td>
                                <td>
                                    <div>
                                        <small style="color: #6b7280;">Sales: <?= number_format($sold_percentage, 1) ?>%</small>
                                        <div class="progress-bar-custom">
                                            <div class="progress-fill" style="width: <?= $sold_percentage ?>%; background: linear-gradient(90deg, #8b5cf6 0%, #7c3aed 100%);"></div>
                                        </div>
                                    </div>
                                    <div style="margin-top: 8px;">
                                        <small style="color: #6b7280;">Collection: <?= number_format($collection_rate, 1) ?>%</small>
                                        <div class="progress-bar-custom">
                                            <div class="progress-fill" style="width: <?= $collection_rate ?>%; background: linear-gradient(90deg, #10b981 0%, #059669 100%);"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <tr class="total-row">
                                <td colspan="4" class="text-end"><strong>TOTALS</strong></td>
                                <td class="text-center"><strong><?= number_format($total_plots_sum) ?></strong></td>
                                <td colspan="2"></td>
                                <td class="text-center"><strong><?= number_format($total_sold_sum) ?></strong></td>
                                <td></td>
                                <td class="text-end"><strong>TSH <?= number_format($total_revenue_sum, 0) ?></strong></td>
                                <td class="text-end"><strong>TSH <?= number_format($total_collected_sum, 0) ?></strong></td>
                                <td class="text-end"><strong>TSH <?= number_format($total_outstanding_sum, 0) ?></strong></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination no-print">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&project_id=<?= $project_id ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&project_id=<?= $project_id ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&project_id=<?= $project_id ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

<script>
function exportToExcel() {
    const data = [];
    
    data.push(['PROJECT PERFORMANCE REPORT']);
    data.push(['Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?>']);
    data.push(['Generated: <?= date('d M Y, h:i A') ?>']);
    data.push([]);
    
    // Summary
    data.push(['SUMMARY']);
    data.push(['Total Projects', '<?= $summary_stats['total_projects'] ?? 0 ?>']);
    data.push(['Total Plots', '<?= $summary_stats['total_plots'] ?? 0 ?>']);
    data.push(['Plots Sold', '<?= $summary_stats['plots_sold'] ?? 0 ?>']);
    data.push(['Total Revenue', 'TSH <?= number_format($summary_stats['total_revenue'] ?? 0, 2) ?>']);
    data.push([]);
    
    // Project Performance
    data.push(['PROJECT PERFORMANCE DETAILS']);
    data.push(['Project Code', 'Project Name', 'Location', 'Status', 'Total Plots', 'Available', 'Reserved', 'Sold', 'Total Area (m²)', 'Revenue', 'Collected', 'Outstanding', 'Sales %', 'Collection %']);
    
    <?php foreach ($export_data as $row): 
        $sold_percentage = $row['total_plots'] > 0 ? ($row['sold_plots'] / $row['total_plots']) * 100 : 0;
        $collection_rate = $row['total_revenue'] > 0 ? ($row['total_collected'] / $row['total_revenue']) * 100 : 0;
    ?>
    data.push([
        '<?= $row['project_code'] ?>',
        '<?= addslashes($row['project_name']) ?>',
        '<?= addslashes($row['location']) ?>',
        '<?= ucfirst($row['project_status']) ?>',
        <?= $row['total_plots'] ?>,
        <?= $row['available_plots'] ?>,
        <?= $row['reserved_plots'] ?>,
        <?= $row['sold_plots'] ?>,
        <?= $row['total_area'] ?>,
        <?= $row['total_revenue'] ?>,
        <?= $row['total_collected'] ?>,
        <?= $row['outstanding_balance'] ?>,
        '<?= number_format($sold_percentage, 2) ?>%',
        '<?= number_format($collection_rate, 2) ?>%'
    ]);
    <?php endforeach; ?>
    
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:15},{wch:30},{wch:25},{wch:12},{wch:12},{wch:12},{wch:12},{wch:12},{wch:15},{wch:18},{wch:18},{wch:18},{wch:12},{wch:12}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Project Performance');
    XLSX.writeFile(wb, 'Project_Performance_<?= date('Y-m-d') ?>.xlsx');
}

function exportToPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    
    // Header
    doc.setFontSize(20);
    doc.setTextColor(139, 92, 246);
    doc.text('PROJECT PERFORMANCE REPORT', 148, 15, { align: 'center' });
    
    doc.setFontSize(10);
    doc.setTextColor(100, 100, 100);
    doc.text('Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?>', 148, 22, { align: 'center' });
    
    // Summary boxes
    let y = 32;
    const boxWidth = 60;
    const boxHeight = 18;
    const gap = 10;
    let x = 20;
    
    // Total Projects
    doc.setFillColor(139, 92, 246);
    doc.roundedRect(x, y, boxWidth, boxHeight, 3, 3, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(9);
    doc.text('Total Projects', x + boxWidth/2, y + 7, { align: 'center' });
    doc.setFontSize(14);
    doc.text('<?= $summary_stats['total_projects'] ?? 0 ?>', x + boxWidth/2, y + 14, { align: 'center' });
    
    // Total Plots
    x += boxWidth + gap;
    doc.setFillColor(59, 130, 246);
    doc.roundedRect(x, y, boxWidth, boxHeight, 3, 3, 'F');
    doc.setFontSize(9);
    doc.text('Total Plots', x + boxWidth/2, y + 7, { align: 'center' });
    doc.setFontSize(14);
    doc.text('<?= $summary_stats['total_plots'] ?? 0 ?>', x + boxWidth/2, y + 14, { align: 'center' });
    
    // Plots Sold
    x += boxWidth + gap;
    doc.setFillColor(16, 185, 129);
    doc.roundedRect(x, y, boxWidth, boxHeight, 3, 3, 'F');
    doc.setFontSize(9);
    doc.text('Plots Sold', x + boxWidth/2, y + 7, { align: 'center' });
    doc.setFontSize(14);
    doc.text('<?= $summary_stats['plots_sold'] ?? 0 ?>', x + boxWidth/2, y + 14, { align: 'center' });
    
    // Total Revenue
    x += boxWidth + gap;
    doc.setFillColor(245, 158, 11);
    doc.roundedRect(x, y, boxWidth, boxHeight, 3, 3, 'F');
    doc.setFontSize(9);
    doc.text('Revenue', x + boxWidth/2, y + 7, { align: 'center' });
    doc.setFontSize(14);
    doc.text('TSH <?= number_format(($summary_stats['total_revenue'] ?? 0)/1000000, 1) ?>M', x + boxWidth/2, y + 14, { align: 'center' });
    
    // Table
    y += 25;
    const tableData = [
        <?php foreach ($export_data as $row): 
            $sold_percentage = $row['total_plots'] > 0 ? ($row['sold_plots'] / $row['total_plots']) * 100 : 0;
            $collection_rate = $row['total_revenue'] > 0 ? ($row['total_collected'] / $row['total_revenue']) * 100 : 0;
        ?>
        [
            '<?= $row['project_code'] ?>',
            '<?= addslashes($row['project_name']) ?>',
            '<?= $row['total_plots'] ?>',
            '<?= $row['sold_plots'] ?>',
            '<?= number_format($sold_percentage, 1) ?>%',
            '<?= number_format($row['total_revenue'], 0) ?>',
            '<?= number_format($row['total_collected'], 0) ?>',
            '<?= number_format($row['outstanding_balance'], 0) ?>',
            '<?= number_format($collection_rate, 1) ?>%'
        ],
        <?php endforeach; ?>
    ];
    
    doc.autoTable({
        startY: y,
        head: [['Code', 'Project', 'Plots', 'Sold', 'Sales %', 'Revenue', 'Collected', 'Outstanding', 'Coll %']],
        body: tableData,
        theme: 'grid',
        headStyles: { 
            fillColor: [139, 92, 246],
            fontSize: 7,
            fontStyle: 'bold',
            halign: 'center'
        },
        styles: { 
            fontSize: 7,
            cellPadding: 2
        },
        columnStyles: {
            0: { cellWidth: 20, halign: 'center' },
            1: { cellWidth: 45 },
            2: { cellWidth: 15, halign: 'center' },
            3: { cellWidth: 15, halign: 'center' },
            4: { cellWidth: 18, halign: 'center' },
            5: { cellWidth: 28, halign: 'right' },
            6: { cellWidth: 28, halign: 'right' },
            7: { cellWidth: 28, halign: 'right' },
            8: { cellWidth: 18, halign: 'center' }
        },
        didDrawPage: function(data) {
            // Header on each page
            doc.setFontSize(12);
            doc.setTextColor(139, 92, 246);
            doc.text('PROJECT PERFORMANCE REPORT', 148, 10, { align: 'center' });
            
            // Footer
            const pageCount = doc.internal.getNumberOfPages();
            const currentPage = doc.internal.getCurrentPageInfo().pageNumber;
            
            doc.setFontSize(8);
            doc.setTextColor(100, 100, 100);
            doc.text('Page ' + currentPage + ' of ' + pageCount, 148, 200, { align: 'center' });
            doc.text('All amounts in TSH', 148, 205, { align: 'center' });
        }
    });
    
    doc.save('Project_Performance_<?= date('Y-m-d') ?>.pdf');
}
</script>

<?php require_once '../../includes/footer.php'; ?>