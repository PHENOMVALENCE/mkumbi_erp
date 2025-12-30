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
$user_id = $_SESSION['user_id'];

$processing_id = $_GET['id'] ?? null;

if (!$processing_id) {
    $_SESSION['error'] = "Processing ID is required";
    header("Location: index.php");
    exit;
}

// Fetch processing details - FIXED: Removed pr.location
try {
    $sql = "SELECT 
        tdp.*,
        c.full_name as customer_name,
        c.phone as customer_phone,
        c.email as customer_email,
        c.id_number as customer_id_number,
        p.plot_number,
        p.block_number,
        p.area as plot_area,
        pr.project_name,
        r.reservation_number,
        r.reservation_date,
        u.full_name as assigned_to_name,
        creator.full_name as created_by_name
    FROM title_deed_processing tdp
    LEFT JOIN customers c ON tdp.customer_id = c.customer_id
    LEFT JOIN plots p ON tdp.plot_id = p.plot_id
    LEFT JOIN projects pr ON p.project_id = pr.project_id
    LEFT JOIN reservations r ON tdp.reservation_id = r.reservation_id
    LEFT JOIN users u ON tdp.assigned_to = u.user_id
    LEFT JOIN users creator ON tdp.created_by = creator.user_id
    WHERE tdp.processing_id = ? AND tdp.company_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$processing_id, $company_id]);
    $processing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$processing) {
        $_SESSION['error'] = "Processing record not found";
        header("Location: index.php");
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading processing details: " . $e->getMessage();
    header("Location: index.php");
    exit;
}

// Fetch all stages
try {
    $stages_sql = "SELECT * FROM title_deed_stages 
                  WHERE processing_id = ? AND company_id = ?
                  ORDER BY stage_order ASC";
    $stages_stmt = $conn->prepare($stages_sql);
    $stages_stmt->execute([$processing_id, $company_id]);
    $stages = $stages_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching stages: " . $e->getMessage());
    $stages = [];
}

// Fetch all costs
try {
    $costs_sql = "SELECT * FROM title_deed_costs 
                 WHERE processing_id = ? AND company_id = ?
                 ORDER BY created_at DESC";
    $costs_stmt = $conn->prepare($costs_sql);
    $costs_stmt->execute([$processing_id, $company_id]);
    $costs = $costs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_costs = array_sum(array_column($costs, 'amount'));
} catch (PDOException $e) {
    error_log("Error fetching costs: " . $e->getMessage());
    $costs = [];
    $total_costs = 0;
}

// Fetch staff for assignment
try {
    $staff_sql = "SELECT user_id, full_name FROM users WHERE company_id = ? AND is_active = 1 ORDER BY full_name";
    $staff_stmt = $conn->prepare($staff_sql);
    $staff_stmt->execute([$company_id]);
    $staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $staff = [];
}

// Calculate progress
$total_stages = 6;
$completed_stages = count(array_filter($stages, function($s) { 
    return $s['stage_status'] == 'completed'; 
}));
$progress_percentage = $total_stages > 0 ? ($completed_stages / $total_stages) * 100 : 0;

// Stage status function
function getStageStatusBadge($status) {
    $badges = [
        'pending' => 'secondary',
        'in_progress' => 'primary',
        'completed' => 'success',
        'on_hold' => 'warning',
        'cancelled' => 'danger'
    ];
    $color = $badges[$status] ?? 'secondary';
    return "<span class='status-badge " . strtolower(str_replace('_', '-', $status)) . "'>" . ucwords(str_replace('_', ' ', $status)) . "</span>";
}

// Get current stage status
function getCurrentStageStatus($stages, $current_stage) {
    foreach ($stages as $stage) {
        if ($stage['stage_name'] == $current_stage) {
            return $stage['stage_status'];
        }
    }
    return 'pending';
}

$page_title = 'View Title Deed Processing';
require_once '../../includes/header.php';
?>

<style>
/* Match plots page styling */
.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 0.875rem 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid;
    height: 100%;
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.purple { border-left-color: #6f42c1; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.15rem;
    line-height: 1;
}

.stats-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #6c757d;
    font-weight: 600;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.pending { background: #e2e3e5; color: #383d41; }
.status-badge.in-progress { background: #cce5ff; color: #004085; }
.status-badge.completed { background: #d4edda; color: #155724; }
.status-badge.on-hold { background: #fff3cd; color: #856404; }
.status-badge.cancelled { background: #f8d7da; color: #721c24; }

.info-card {
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    margin-bottom: 1rem;
}

.info-row {
    display: flex;
    padding: 0.5rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    font-size: 0.8rem;
    min-width: 160px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.info-value {
    color: #2c3e50;
    flex: 1;
    font-size: 0.85rem;
}

.timeline {
    position: relative;
    padding-left: 2rem;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-marker {
    position: absolute;
    left: -1.6rem;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 3px solid;
    background: white;
}

.timeline-marker.completed {
    border-color: #28a745;
    background: #28a745;
}

.timeline-marker.in-progress {
    border-color: #007bff;
    background: #007bff;
    animation: pulse 2s infinite;
}

.timeline-marker.pending {
    border-color: #6c757d;
    background: white;
}

@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(0,123,255,0.7); }
    50% { box-shadow: 0 0 0 10px rgba(0,123,255,0); }
}

.timeline-content {
    background: #f8f9fa;
    padding: 0.875rem;
    border-radius: 4px;
    border-left: 3px solid #dee2e6;
}

.timeline-content.completed { border-left-color: #28a745; }
.timeline-content.in-progress { 
    border-left-color: #007bff;
    background: #e7f3ff;
}

.stage-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.stage-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: #2c3e50;
}

.stage-dates {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.35rem;
}

.action-btn {
    padding: 0.3rem 0.6rem;
    font-size: 0.75rem;
    border-radius: 3px;
    margin-right: 0.2rem;
    margin-bottom: 0.2rem;
    white-space: nowrap;
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
    padding: 0.65rem 0.5rem;
}

.table-professional tbody td {
    padding: 0.65rem 0.5rem;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    Title Deed Processing
                    <span class="badge bg-primary" style="font-size: 0.75rem;">
                        <?= htmlspecialchars($processing['processing_number']) ?>
                    </span>
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
                <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editModal">
                    <i class="fas fa-edit me-1"></i>Edit
                </button>
                <button class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            
            <!-- Customer & Plot Information -->
            <div class="info-card">
                <div class="card-body">
                    <h6 class="mb-3" style="font-size: 0.9rem; font-weight: 600; color: #2c3e50;">
                        <i class="fas fa-info-circle text-primary me-2"></i>PROCESSING INFORMATION
                    </h6>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Customer:</div>
                                <div class="info-value"><?= htmlspecialchars($processing['customer_name']) ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Phone:</div>
                                <div class="info-value"><?= htmlspecialchars($processing['customer_phone'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Email:</div>
                                <div class="info-value"><?= htmlspecialchars($processing['customer_email'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">ID Number:</div>
                                <div class="info-value"><?= htmlspecialchars($processing['customer_id_number'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Plot Number:</div>
                                <div class="info-value"><strong><?= htmlspecialchars($processing['plot_number']) ?></strong></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Block:</div>
                                <div class="info-value"><?= htmlspecialchars($processing['block_number'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Plot Area:</div>
                                <div class="info-value"><?= number_format($processing['plot_area']) ?> m²</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Project:</div>
                                <div class="info-value"><?= htmlspecialchars($processing['project_name']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Processing Details -->
            <div class="info-card">
                <div class="card-body">
                    <h6 class="mb-3" style="font-size: 0.9rem; font-weight: 600; color: #2c3e50;">
                        <i class="fas fa-tasks text-success me-2"></i>PROCESSING DETAILS
                    </h6>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Current Stage:</div>
                                <div class="info-value">
                                    <strong><?= ucwords(str_replace('_', ' ', $processing['current_stage'])) ?></strong>
                                    <?= getStageStatusBadge(getCurrentStageStatus($stages, $processing['current_stage'])) ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Started:</div>
                                <div class="info-value">
                                    <?= $processing['started_date'] ? date('M d, Y', strtotime($processing['started_date'])) : 'N/A' ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Expected:</div>
                                <div class="info-value">
                                    <?= $processing['expected_completion_date'] ? date('M d, Y', strtotime($processing['expected_completion_date'])) : 'Not set' ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Assigned To:</div>
                                <div class="info-value"><?= htmlspecialchars($processing['assigned_to_name'] ?? 'Unassigned') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Reservation:</div>
                                <div class="info-value"><?= htmlspecialchars($processing['reservation_number'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Created By:</div>
                                <div class="info-value"><?= htmlspecialchars($processing['created_by_name']) ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($processing['notes'])): ?>
                    <div class="mt-3 p-2" style="background: #fff3cd; border-left: 3px solid #ffc107; border-radius: 3px; font-size: 0.85rem;">
                        <strong>Notes:</strong> <?= nl2br(htmlspecialchars($processing['notes'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cost Information -->
            <div class="info-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0" style="font-size: 0.9rem; font-weight: 600; color: #2c3e50;">
                            <i class="fas fa-dollar-sign text-warning me-2"></i>COST BREAKDOWN
                        </h6>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCostModal">
                            <i class="fas fa-plus me-1"></i>Add Cost
                        </button>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="stats-card warning">
                                <div class="stats-number" style="font-size: 1.2rem;"><?= number_format($processing['total_cost']) ?></div>
                                <div class="stats-label" style="font-size: 0.7rem;">Total Cost (TSH)</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stats-card success">
                                <div class="stats-number" style="font-size: 1.2rem;"><?= number_format($processing['customer_contribution']) ?></div>
                                <div class="stats-label" style="font-size: 0.7rem;">Customer Pays (TSH)</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stats-card primary">
                                <div class="stats-number" style="font-size: 1.2rem;"><?= number_format($processing['total_cost'] - $processing['customer_contribution']) ?></div>
                                <div class="stats-label" style="font-size: 0.7rem;">Company Absorbs (TSH)</div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($costs)): ?>
                    <div class="table-responsive">
                        <table class="table table-professional table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th class="text-end">Amount</th>
                                    <th>Paid By</th>
                                    <th>Date</th>
                                    <th>Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($costs as $cost): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cost['cost_type']) ?></td>
                                    <td class="text-end"><?= number_format($cost['amount']) ?></td>
                                    <td>
                                        <span class="status-badge <?= $cost['paid_by'] == 'customer' ? 'completed' : 'in-progress' ?>">
                                            <?= ucfirst($cost['paid_by']) ?>
                                        </span>
                                    </td>
                                    <td><?= $cost['payment_date'] ? date('M d, Y', strtotime($cost['payment_date'])) : 'N/A' ?></td>
                                    <td><?= htmlspecialchars($cost['receipt_number'] ?? '-') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info mb-0" style="font-size: 0.85rem;">
                        <i class="fas fa-info-circle me-2"></i>No cost entries yet
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Processing Timeline -->
            <div class="info-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0" style="font-size: 0.9rem; font-weight: 600; color: #2c3e50;">
                            <i class="fas fa-timeline text-info me-2"></i>PROCESSING TIMELINE
                        </h6>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#updateStageModal">
                            <i class="fas fa-arrow-right me-1"></i>Update Stage
                        </button>
                    </div>
                    
                    <div class="timeline">
                        <?php 
                        $stage_names = [
                            'startup' => 'Startup',
                            'municipal' => 'Municipal',
                            'ministry_of_land' => 'Ministry of Land',
                            'approved' => 'Approved',
                            'received' => 'Received',
                            'delivered' => 'Delivered'
                        ];
                        
                        $stage_map = [];
                        foreach ($stages as $stage) {
                            $stage_map[$stage['stage_name']] = $stage;
                        }
                        
                        foreach ($stage_names as $stage_key => $stage_label): 
                            $stage_data = $stage_map[$stage_key] ?? null;
                            $status = $stage_data ? $stage_data['stage_status'] : 'pending';
                            $status_class = str_replace('_', '-', $status);
                        ?>
                        <div class="timeline-item">
                            <div class="timeline-marker <?= $status_class ?>"></div>
                            <div class="timeline-content <?= $status_class ?>">
                                <div class="stage-header">
                                    <div class="stage-title"><?= $stage_label ?></div>
                                    <?= getStageStatusBadge($status) ?>
                                </div>
                                
                                <?php if ($stage_data): ?>
                                <div class="stage-dates">
                                    <?php if ($stage_data['started_date']): ?>
                                        <i class="fas fa-play-circle me-1"></i>
                                        Started: <?= date('M d, Y', strtotime($stage_data['started_date'])) ?>
                                    <?php endif; ?>
                                    
                                    <?php if ($stage_data['completed_date']): ?>
                                        <br><i class="fas fa-check-circle me-1"></i>
                                        Completed: <?= date('M d, Y', strtotime($stage_data['completed_date'])) ?>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($stage_data['notes'])): ?>
                                <div class="mt-2" style="font-size: 0.75rem;">
                                    <strong>Notes:</strong> <?= nl2br(htmlspecialchars($stage_data['notes'])) ?>
                                </div>
                                <?php endif; ?>
                                <?php else: ?>
                                <div class="text-muted" style="font-size: 0.75rem;">Not started yet</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            
            <!-- Progress Card -->
            <div class="info-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                <div class="card-body text-center">
                    <h6 class="text-white mb-3" style="font-size: 0.9rem; font-weight: 600;">OVERALL PROGRESS</h6>
                    <div style="width: 100px; height: 100px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                        <div style="font-size: 2rem; font-weight: bold;"><?= number_format($progress_percentage, 0) ?>%</div>
                    </div>
                    <div class="mt-3" style="font-size: 1rem;">
                        <?= $completed_stages ?> of <?= $total_stages ?> stages completed
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="info-card">
                <div class="card-body">
                    <h6 class="mb-3" style="font-size: 0.9rem; font-weight: 600; color: #2c3e50;">
                        <i class="fas fa-bolt text-warning me-2"></i>QUICK ACTIONS
                    </h6>
                    
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#updateStageModal">
                            <i class="fas fa-arrow-right me-2"></i>Update Stage
                        </button>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCostModal">
                            <i class="fas fa-plus me-2"></i>Add Cost Entry
                        </button>
                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editModal">
                            <i class="fas fa-edit me-2"></i>Edit Details
                        </button>
                        <button class="btn btn-secondary btn-sm" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print Report
                        </button>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="info-card">
                <div class="card-body">
                    <h6 class="mb-3" style="font-size: 0.9rem; font-weight: 600; color: #2c3e50;">
                        <i class="fas fa-history text-info me-2"></i>RECENT ACTIVITY
                    </h6>
                    
                    <div class="list-group list-group-flush">
                        <div class="list-group-item px-0" style="font-size: 0.8rem;">
                            <div class="d-flex justify-content-between">
                                <strong>Processing Created</strong>
                                <small class="text-muted"><?= date('M d', strtotime($processing['created_at'])) ?></small>
                            </div>
                            <small class="text-muted">By <?= htmlspecialchars($processing['created_by_name']) ?></small>
                        </div>
                        
                        <?php foreach (array_slice($stages, 0, 5) as $stage): ?>
                        <?php if ($stage['started_date']): ?>
                        <div class="list-group-item px-0" style="font-size: 0.8rem;">
                            <div class="d-flex justify-content-between">
                                <strong><?= ucwords(str_replace('_', ' ', $stage['stage_name'])) ?></strong>
                                <small class="text-muted"><?= date('M d', strtotime($stage['started_date'])) ?></small>
                            </div>
                            <small class="text-muted">Status: <?= ucfirst($stage['stage_status']) ?></small>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="update_details.php">
                <input type="hidden" name="processing_id" value="<?= $processing_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 1rem;">Edit Processing Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Expected Completion Date</label>
                        <input type="date" name="expected_completion_date" class="form-control form-control-sm" 
                               value="<?= $processing['expected_completion_date'] ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Assign To</label>
                        <select name="assigned_to" class="form-select form-select-sm">
                            <option value="">Unassigned</option>
                            <?php foreach ($staff as $s): ?>
                                <option value="<?= $s['user_id'] ?>" <?= $processing['assigned_to'] == $s['user_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($processing['notes']) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Stage Modal -->
<div class="modal fade" id="updateStageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="update_stage_action.php">
                <input type="hidden" name="processing_id" value="<?= $processing_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 1rem;">Update Stage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Stage</label>
                        <select name="stage_name" class="form-select form-select-sm" required>
                            <option value="startup">Startup</option>
                            <option value="municipal">Municipal</option>
                            <option value="ministry_of_land">Ministry of Land</option>
                            <option value="approved">Approved</option>
                            <option value="received">Received</option>
                            <option value="delivered">Delivered</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Status</label>
                        <select name="stage_status" class="form-select form-select-sm" required>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="on_hold">On Hold</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Completed Date (if completed)</label>
                        <input type="date" name="completed_date" class="form-control form-control-sm">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Update Stage</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Cost Modal -->
<div class="modal fade" id="addCostModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="add_cost_action.php">
                <input type="hidden" name="processing_id" value="<?= $processing_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 1rem;">Add Cost Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Cost Type</label>
                        <input type="text" name="cost_type" class="form-control form-control-sm" required 
                               placeholder="e.g., Application Fee, Survey Fee">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Amount (TSH)</label>
                        <input type="number" name="amount" class="form-control form-control-sm" required min="0" step="1000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Paid By</label>
                        <select name="paid_by" class="form-select form-select-sm" required>
                            <option value="customer">Customer</option>
                            <option value="company">Company</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control form-control-sm">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Receipt Number</label>
                        <input type="text" name="receipt_number" class="form-control form-control-sm">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm">Add Cost</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>