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

// Helper function
function safe_format($number, $decimals = 0) {
    return number_format((float)$number ?: 0, $decimals);
}

// Fetch overall stage statistics
try {
    $stats_sql = "SELECT 
        COUNT(DISTINCT tdp.processing_id) as total_processing,
        COUNT(DISTINCT CASE WHEN tdp.current_stage = 'startup' THEN tdp.processing_id END) as startup_count,
        COUNT(DISTINCT CASE WHEN tdp.current_stage = 'municipal' THEN tdp.processing_id END) as municipal_count,
        COUNT(DISTINCT CASE WHEN tdp.current_stage = 'ministry_of_land' THEN tdp.processing_id END) as ministry_count,
        COUNT(DISTINCT CASE WHEN tdp.current_stage = 'approved' THEN tdp.processing_id END) as approved_count,
        COUNT(DISTINCT CASE WHEN tdp.current_stage = 'received' THEN tdp.processing_id END) as received_count,
        COUNT(DISTINCT CASE WHEN tdp.current_stage = 'delivered' THEN tdp.processing_id END) as delivered_count
    FROM title_deed_processing tdp
    WHERE tdp.company_id = ?";
    
    $stmt = $conn->prepare($stats_sql);
    $stmt->execute([$company_id]);
    $overall_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
    $overall_stats = [
        'total_processing' => 0,
        'startup_count' => 0,
        'municipal_count' => 0,
        'ministry_count' => 0,
        'approved_count' => 0,
        'received_count' => 0,
        'delivered_count' => 0
    ];
}

// Fetch stage detail statistics
try {
    $stage_details_sql = "SELECT 
        tds.stage_name,
        COUNT(*) as total_entries,
        SUM(CASE WHEN tds.stage_status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN tds.stage_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
        SUM(CASE WHEN tds.stage_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        AVG(CASE 
            WHEN tds.completed_date IS NOT NULL AND tds.started_date IS NOT NULL 
            THEN DATEDIFF(tds.completed_date, tds.started_date) 
            ELSE NULL 
        END) as avg_duration_days
    FROM title_deed_stages tds
    WHERE tds.company_id = ?
    GROUP BY tds.stage_name";
    
    $stmt = $conn->prepare($stage_details_sql);
    $stmt->execute([$company_id]);
    $stage_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create map
    $stage_map = [];
    foreach ($stage_details as $detail) {
        $stage_map[$detail['stage_name']] = $detail;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching stage details: " . $e->getMessage());
    $stage_map = [];
}

// Fetch all processing records with stage info
try {
    $processing_sql = "SELECT 
        tdp.processing_id,
        tdp.processing_number,
        tdp.current_stage,
        tdp.started_date,
        tdp.expected_completion_date,
        tdp.actual_completion_date,
        c.full_name as customer_name,
        COALESCE(c.phone, c.phone1) as customer_phone,
        p.plot_number,
        p.block_number,
        pr.project_name,
        u.full_name as assigned_to_name,
        (SELECT COUNT(*) FROM title_deed_stages 
         WHERE processing_id = tdp.processing_id AND stage_status = 'completed') as completed_stages_count
    FROM title_deed_processing tdp
    LEFT JOIN customers c ON tdp.customer_id = c.customer_id
    LEFT JOIN plots p ON tdp.plot_id = p.plot_id
    LEFT JOIN projects pr ON p.project_id = pr.project_id
    LEFT JOIN users u ON tdp.assigned_to = u.user_id
    WHERE tdp.company_id = ?
    ORDER BY tdp.created_at DESC";
    
    $stmt = $conn->prepare($processing_sql);
    $stmt->execute([$company_id]);
    $all_processing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching processing records: " . $e->getMessage());
    $all_processing = [];
}

// Define stage configurations
$stages_config = [
    'startup' => [
        'label' => 'Startup',
        'icon' => 'play-circle',
        'color' => 'secondary',
        'description' => 'Initial documentation and application'
    ],
    'municipal' => [
        'label' => 'Municipal',
        'icon' => 'building',
        'color' => 'info',
        'description' => 'Municipal council review'
    ],
    'ministry_of_land' => [
        'label' => 'Ministry of Land',
        'icon' => 'landmark',
        'color' => 'primary',
        'description' => 'National ministry processing'
    ],
    'approved' => [
        'label' => 'Approved',
        'icon' => 'check-circle',
        'color' => 'success',
        'description' => 'Title deed approved'
    ],
    'received' => [
        'label' => 'Received',
        'icon' => 'inbox',
        'color' => 'warning',
        'description' => 'Physical deed received'
    ],
    'delivered' => [
        'label' => 'Delivered',
        'icon' => 'handshake',
        'color' => 'dark',
        'description' => 'Handed over to customer'
    ]
];

$page_title = 'Processing Stages Overview';
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

.stats-card.secondary { border-left-color: #6c757d; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.dark { border-left-color: #343a40; }

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

.stage-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
}

.stage-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 3px solid #e9ecef;
}

.stage-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
}

.stage-description {
    font-size: 0.875rem;
    color: #6c757d;
    font-style: italic;
}

.mini-stats {
    display: flex;
    gap: 2rem;
}

.mini-stat {
    text-align: center;
}

.mini-stat-number {
    font-size: 1.5rem;
    font-weight: 700;
}

.mini-stat-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 0.75rem;
    white-space: nowrap;
}

.table tbody td {
    padding: 0.75rem;
    vertical-align: middle;
    font-size: 0.875rem;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.processing-number {
    font-weight: 600;
    color: #007bff;
}

.progress-mini {
    width: 100px;
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
}

.progress-mini-fill {
    height: 100%;
    background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: #6c757d;
}
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-chart-pie text-primary me-2"></i>Processing Stages Overview
                </h1>
                <p class="text-muted small mb-0 mt-1">Comprehensive view of all title deed processing stages</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Back to Processing
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <!-- Overall Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card secondary position-relative">
                    <i class="fas fa-play-circle stats-icon"></i>
                    <div class="stats-number"><?php echo $overall_stats['startup_count']; ?></div>
                    <div class="stats-label">Startup</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card info position-relative">
                    <i class="fas fa-building stats-icon"></i>
                    <div class="stats-number"><?php echo $overall_stats['municipal_count']; ?></div>
                    <div class="stats-label">Municipal</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card primary position-relative">
                    <i class="fas fa-landmark stats-icon"></i>
                    <div class="stats-number"><?php echo $overall_stats['ministry_count']; ?></div>
                    <div class="stats-label">Ministry</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card success position-relative">
                    <i class="fas fa-check-circle stats-icon"></i>
                    <div class="stats-number"><?php echo $overall_stats['approved_count']; ?></div>
                    <div class="stats-label">Approved</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card warning position-relative">
                    <i class="fas fa-inbox stats-icon"></i>
                    <div class="stats-number"><?php echo $overall_stats['received_count']; ?></div>
                    <div class="stats-label">Received</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stats-card dark position-relative">
                    <i class="fas fa-handshake stats-icon"></i>
                    <div class="stats-number"><?php echo $overall_stats['delivered_count']; ?></div>
                    <div class="stats-label">Delivered</div>
                </div>
            </div>
        </div>

        <!-- Stage Sections -->
        <?php foreach ($stages_config as $stage_key => $config): 
            $stage_stats = $stage_map[$stage_key] ?? null;
            
            // Filter processing records in this stage
            $stage_processing = array_filter($all_processing, fn($p) => $p['current_stage'] === $stage_key);
        ?>
        <div class="stage-section">
            <div class="stage-header">
                <div>
                    <div class="stage-title">
                        <i class="fas fa-<?php echo $config['icon']; ?> text-<?php echo $config['color']; ?> me-2"></i>
                        <?php echo $config['label']; ?>
                    </div>
                    <div class="stage-description"><?php echo $config['description']; ?></div>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-number text-<?php echo $config['color']; ?>">
                            <?php echo count($stage_processing); ?>
                        </div>
                        <div class="mini-stat-label">Current</div>
                    </div>
                    <?php if ($stage_stats): ?>
                    <div class="mini-stat">
                        <div class="mini-stat-number text-success">
                            <?php echo $stage_stats['completed_count'] ?? 0; ?>
                        </div>
                        <div class="mini-stat-label">Completed</div>
                    </div>
                    <div class="mini-stat">
                        <div class="mini-stat-number text-primary">
                            <?php echo $stage_stats['in_progress_count'] ?? 0; ?>
                        </div>
                        <div class="mini-stat-label">In Progress</div>
                    </div>
                    <?php if (isset($stage_stats['avg_duration_days']) && $stage_stats['avg_duration_days'] > 0): ?>
                    <div class="mini-stat">
                        <div class="mini-stat-number text-info">
                            <?php echo round($stage_stats['avg_duration_days']); ?>
                        </div>
                        <div class="mini-stat-label">Avg Days</div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($stage_processing)): ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm stage-table">
                    <thead>
                        <tr>
                            <th>Processing #</th>
                            <th>Customer</th>
                            <th>Plot</th>
                            <th>Project</th>
                            <th>Progress</th>
                            <th>Started</th>
                            <th>Expected</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stage_processing as $proc): 
                            $progress_pct = ($proc['completed_stages_count'] / 6) * 100;
                        ?>
                        <tr>
                            <td>
                                <span class="processing-number"><?php echo htmlspecialchars($proc['processing_number']); ?></span>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($proc['customer_name']); ?></div>
                                <?php if ($proc['customer_phone']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($proc['customer_phone']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>Plot <?php echo htmlspecialchars($proc['plot_number']); ?></div>
                                <?php if ($proc['block_number']): ?>
                                    <small class="text-muted">Block <?php echo htmlspecialchars($proc['block_number']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($proc['project_name'] ?? 'N/A'); ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress-mini">
                                        <div class="progress-mini-fill" style="width: <?php echo $progress_pct; ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?php echo $proc['completed_stages_count']; ?>/6</small>
                                </div>
                            </td>
                            <td>
                                <small><?php echo date('d M Y', strtotime($proc['started_date'])); ?></small>
                            </td>
                            <td>
                                <small><?php echo $proc['expected_completion_date'] ? date('d M Y', strtotime($proc['expected_completion_date'])) : '-'; ?></small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($proc['assigned_to_name'] ?? 'Unassigned'); ?></small>
                            </td>
                            <td>
                                <a href="manage_stages.php?id=<?php echo $proc['processing_id']; ?>" 
                                   class="btn btn-sm btn-primary" 
                                   title="Manage Stages">
                                    <i class="fas fa-tasks"></i>
                                </a>
                                <a href="view.php?id=<?php echo $proc['processing_id']; ?>" 
                                   class="btn btn-sm btn-info" 
                                   title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-info-circle fa-2x mb-2"></i>
                <p class="mb-0">No processing records currently in this stage</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

    </div>
</section>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('.stage-table').each(function() {
        $(this).DataTable({
            responsive: true,
            pageLength: 10,
            lengthMenu: [[5, 10, 25, 50], [5, 10, 25, 50]],
            order: [[5, 'desc']],
            columnDefs: [
                { orderable: false, targets: 8 }
            ]
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>