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

// Get project_id if viewing specific project
$project_id = $_GET['project_id'] ?? null;
$project = null;

if ($project_id) {
    // Fetch project details
    $stmt = $conn->prepare("
        SELECT p.*, 
            r.region_name,
            d.district_name,
            w.ward_name,
            v.village_name
        FROM projects p
        LEFT JOIN regions r ON p.region_id = r.region_id
        LEFT JOIN districts d ON p.district_id = d.district_id
        LEFT JOIN wards w ON p.ward_id = w.ward_id
        LEFT JOIN villages v ON p.village_id = v.village_id
        WHERE p.project_id = ? AND p.company_id = ?
    ");
    $stmt->execute([$project_id, $company_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'add_cost') {
            // Validate required fields
            if (empty($_POST['project_id']) || empty($_POST['cost_category']) || empty($_POST['cost_amount'])) {
                throw new Exception('Project, category, and amount are required');
            }
            
            // Handle file upload
            $attachment_path = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../uploads/project_costs/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $file_name = 'cost_' . time() . '_' . uniqid() . '.' . $file_extension;
                $target_file = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file)) {
                    $attachment_path = 'uploads/project_costs/' . $file_name;
                }
            }
            
            // Insert cost
            $stmt = $conn->prepare("
                INSERT INTO project_costs (
                    company_id, project_id, cost_category, cost_description,
                    cost_amount, cost_date, receipt_number, attachment_path,
                    remarks, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $company_id,
                $_POST['project_id'],
                $_POST['cost_category'],
                $_POST['cost_description'],
                $_POST['cost_amount'],
                $_POST['cost_date'],
                $_POST['receipt_number'] ?? null,
                $attachment_path,
                $_POST['remarks'] ?? null,
                $user_id
            ]);
            
            // Update project total operational costs
            $update_project = $conn->prepare("
                UPDATE projects 
                SET total_operational_costs = (
                    SELECT COALESCE(SUM(cost_amount), 0) 
                    FROM project_costs 
                    WHERE project_id = ?
                )
                WHERE project_id = ? AND company_id = ?
            ");
            $update_project->execute([$_POST['project_id'], $_POST['project_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Cost added successfully']);
            
        } elseif ($_POST['action'] === 'update_cost') {
            if (empty($_POST['cost_id'])) {
                throw new Exception('Cost ID is required');
            }
            
            // Get current cost details
            $current = $conn->prepare("SELECT project_id, attachment_path FROM project_costs WHERE cost_id = ? AND company_id = ?");
            $current->execute([$_POST['cost_id'], $company_id]);
            $current_cost = $current->fetch(PDO::FETCH_ASSOC);
            
            if (!$current_cost) {
                throw new Exception('Cost not found');
            }
            
            // Handle file upload
            $attachment_path = $current_cost['attachment_path'];
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../uploads/project_costs/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $file_name = 'cost_' . time() . '_' . uniqid() . '.' . $file_extension;
                $target_file = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file)) {
                    // Delete old file
                    if ($attachment_path && file_exists('../../' . $attachment_path)) {
                        unlink('../../' . $attachment_path);
                    }
                    $attachment_path = 'uploads/project_costs/' . $file_name;
                }
            }
            
            $stmt = $conn->prepare("
                UPDATE project_costs SET
                    cost_category = ?,
                    cost_description = ?,
                    cost_amount = ?,
                    cost_date = ?,
                    receipt_number = ?,
                    attachment_path = ?,
                    remarks = ?
                WHERE cost_id = ? AND company_id = ?
            ");
            
            $stmt->execute([
                $_POST['cost_category'],
                $_POST['cost_description'],
                $_POST['cost_amount'],
                $_POST['cost_date'],
                $_POST['receipt_number'] ?? null,
                $attachment_path,
                $_POST['remarks'] ?? null,
                $_POST['cost_id'],
                $company_id
            ]);
            
            // Update project total
            $update_project = $conn->prepare("
                UPDATE projects 
                SET total_operational_costs = (
                    SELECT COALESCE(SUM(cost_amount), 0) 
                    FROM project_costs 
                    WHERE project_id = ?
                )
                WHERE project_id = ? AND company_id = ?
            ");
            $update_project->execute([$current_cost['project_id'], $current_cost['project_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Cost updated successfully']);
            
        } elseif ($_POST['action'] === 'delete_cost') {
            if (empty($_POST['cost_id'])) {
                throw new Exception('Cost ID is required');
            }
            
            // Get cost details
            $stmt = $conn->prepare("SELECT project_id, attachment_path FROM project_costs WHERE cost_id = ? AND company_id = ?");
            $stmt->execute([$_POST['cost_id'], $company_id]);
            $cost = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cost) {
                throw new Exception('Cost not found');
            }
            
            // Delete file if exists
            if ($cost['attachment_path'] && file_exists('../../' . $cost['attachment_path'])) {
                unlink('../../' . $cost['attachment_path']);
            }
            
            // Delete cost
            $delete = $conn->prepare("DELETE FROM project_costs WHERE cost_id = ? AND company_id = ?");
            $delete->execute([$_POST['cost_id'], $company_id]);
            
            // Update project total
            $update_project = $conn->prepare("
                UPDATE projects 
                SET total_operational_costs = (
                    SELECT COALESCE(SUM(cost_amount), 0) 
                    FROM project_costs 
                    WHERE project_id = ?
                )
                WHERE project_id = ? AND company_id = ?
            ");
            $update_project->execute([$cost['project_id'], $cost['project_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Cost deleted successfully']);
            
        } elseif ($_POST['action'] === 'get_cost') {
            if (empty($_POST['cost_id'])) {
                throw new Exception('Cost ID is required');
            }
            
            $stmt = $conn->prepare("
                SELECT c.*, p.project_name 
                FROM project_costs c
                INNER JOIN projects p ON c.project_id = p.project_id
                WHERE c.cost_id = ? AND c.company_id = ?
            ");
            $stmt->execute([$_POST['cost_id'], $company_id]);
            $cost = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cost) {
                throw new Exception('Cost not found');
            }
            
            echo json_encode(['success' => true, 'cost' => $cost]);
            
        } elseif ($_POST['action'] === 'approve_cost') {
            if (empty($_POST['cost_id'])) {
                throw new Exception('Cost ID is required');
            }
            
            $stmt = $conn->prepare("
                UPDATE project_costs 
                SET approved_by = ?,
                    approved_at = NOW()
                WHERE cost_id = ? AND company_id = ?
            ");
            
            $stmt->execute([$user_id, $_POST['cost_id'], $company_id]);
            
            echo json_encode(['success' => true, 'message' => 'Cost approved successfully']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch all projects for dropdown
$projects_stmt = $conn->prepare("
    SELECT project_id, project_name, project_code 
    FROM projects 
    WHERE company_id = ? AND is_active = 1 
    ORDER BY project_name
");
$projects_stmt->execute([$company_id]);
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch costs
try {
    $where_clause = "WHERE pc.company_id = ?";
    $params = [$company_id];
    
    if ($project_id) {
        $where_clause .= " AND pc.project_id = ?";
        $params[] = $project_id;
    }
    
    $stmt = $conn->prepare("
        SELECT 
            pc.*,
            p.project_name,
            p.project_code,
            u1.first_name as creator_first_name,
            u1.last_name as creator_last_name,
            u2.first_name as approver_first_name,
            u2.last_name as approver_last_name
        FROM project_costs pc
        INNER JOIN projects p ON pc.project_id = p.project_id
        LEFT JOIN users u1 ON pc.created_by = u1.user_id
        LEFT JOIN users u2 ON pc.approved_by = u2.user_id
        $where_clause
        ORDER BY pc.cost_date DESC, pc.created_at DESC
    ");
    $stmt->execute($params);
    $costs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    if ($project_id) {
        $stats = $conn->prepare("
            SELECT 
                COUNT(*) as total_costs,
                COALESCE(SUM(cost_amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN approved_by IS NOT NULL THEN cost_amount ELSE 0 END), 0) as approved_amount,
                COALESCE(SUM(CASE WHEN approved_by IS NULL THEN cost_amount ELSE 0 END), 0) as pending_amount
            FROM project_costs 
            WHERE company_id = ? AND project_id = ?
        ");
        $stats->execute([$company_id, $project_id]);
    } else {
        $stats = $conn->prepare("
            SELECT 
                COUNT(*) as total_costs,
                COALESCE(SUM(cost_amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN approved_by IS NOT NULL THEN cost_amount ELSE 0 END), 0) as approved_amount,
                COALESCE(SUM(CASE WHEN approved_by IS NULL THEN cost_amount ELSE 0 END), 0) as pending_amount
            FROM project_costs 
            WHERE company_id = ?
        ");
        $stats->execute([$company_id]);
    }
    $statistics = $stats->fetch(PDO::FETCH_ASSOC);
    
    // Get cost breakdown by category
    if ($project_id) {
        $breakdown_stmt = $conn->prepare("
            SELECT 
                cost_category,
                COUNT(*) as count,
                COALESCE(SUM(cost_amount), 0) as total
            FROM project_costs
            WHERE company_id = ? AND project_id = ?
            GROUP BY cost_category
            ORDER BY total DESC
        ");
        $breakdown_stmt->execute([$company_id, $project_id]);
    } else {
        $breakdown_stmt = $conn->prepare("
            SELECT 
                cost_category,
                COUNT(*) as count,
                COALESCE(SUM(cost_amount), 0) as total
            FROM project_costs
            WHERE company_id = ?
            GROUP BY cost_category
            ORDER BY total DESC
        ");
        $breakdown_stmt->execute([$company_id]);
    }
    $cost_breakdown = $breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Error fetching costs: " . $e->getMessage();
    $costs = [];
    $statistics = ['total_costs' => 0, 'total_amount' => 0, 'approved_amount' => 0, 'pending_amount' => 0];
    $cost_breakdown = [];
}

$page_title = $project ? $project['project_name'] . ' - Costs' : 'Project Costs';
require_once '../../includes/header.php';
?>

<style>
.cost-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
}

.cost-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.category-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.card-header {
    background: #fff;
    border-bottom: 2px solid #f3f4f6;
    border-radius: 12px 12px 0 0 !important;
    padding: 1.25rem 1.5rem;
}

.form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 0.625rem 0.875rem;
}

.btn {
    border-radius: 8px;
    padding: 0.625rem 1.25rem;
    font-weight: 500;
}

.project-info-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.breakdown-item {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.2s;
}

.breakdown-item:hover {
    background: #f9fafb;
}

.breakdown-item:last-child {
    border-bottom: none;
}

.progress-thin {
    height: 6px;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-dollar-sign text-primary me-2"></i>
                    <?php echo $project ? htmlspecialchars($project['project_name']) . ' - Costs' : 'Project Costs'; ?>
                </h1>
                <p class="text-muted small mb-0 mt-1">Track and manage project operational costs</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <?php if ($project): ?>
                    <a href="index.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-arrow-left me-2"></i>All Projects
                    </a>
                    <?php endif; ?>
                    <button class="btn btn-primary" onclick="showCostModal()">
                        <i class="fas fa-plus me-2"></i>Add Cost
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Project Info (if viewing specific project) -->
        <?php if ($project): ?>
        <div class="project-info-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-2"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                    <p class="mb-1 opacity-90">
                        <i class="fas fa-code me-2"></i><?php echo htmlspecialchars($project['project_code']); ?>
                    </p>
                    <p class="mb-1 opacity-90">
                        <i class="fas fa-map-marker-alt me-2"></i>
                        <?php 
                        $location = [];
                        if ($project['village_name']) $location[] = $project['village_name'];
                        if ($project['ward_name']) $location[] = $project['ward_name'];
                        if ($project['district_name']) $location[] = $project['district_name'];
                        if ($project['region_name']) $location[] = $project['region_name'];
                        echo htmlspecialchars(implode(', ', $location));
                        ?>
                    </p>
                    <p class="mb-0 opacity-90">
                        <i class="fas fa-ruler-combined me-2"></i>Total Area: <?php echo number_format((float)$project['total_area'], 2); ?> sqm
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <h6 class="mb-2 opacity-90">Total Investment</h6>
                    <h2 class="fw-bold mb-2">TZS <?php echo number_format((float)($project['land_purchase_price'] ?? 0) + (float)($project['total_operational_costs'] ?? 0), 0); ?></h2>
                    <small class="opacity-75">
                        Land: TZS <?php echo number_format((float)($project['land_purchase_price'] ?? 0), 0); ?><br>
                        Operations: TZS <?php echo number_format((float)($project['total_operational_costs'] ?? 0), 0); ?>
                    </small>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Total Costs</h6>
                                <h2 class="fw-bold mb-0"><?php echo (int)$statistics['total_costs']; ?></h2>
                            </div>
                            <div class="fs-1 text-primary">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Total Amount</h6>
                                <h2 class="fw-bold mb-0">TZS <?php echo number_format((float)$statistics['total_amount'], 0); ?></h2>
                            </div>
                            <div class="fs-1 text-success">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Approved</h6>
                                <h2 class="fw-bold mb-0 text-success">TZS <?php echo number_format((float)$statistics['approved_amount'], 0); ?></h2>
                            </div>
                            <div class="fs-1 text-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stats-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-2">Pending</h6>
                                <h2 class="fw-bold mb-0 text-warning">TZS <?php echo number_format((float)$statistics['pending_amount'], 0); ?></h2>
                            </div>
                            <div class="fs-1 text-warning">
                                <i class="fas fa-clock"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Costs List -->
            <div class="col-lg-8">
                <?php if (count($costs) > 0): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Cost Records
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="costsTable">
                                <thead>
                                    <tr>
                                        <?php if (!$project): ?>
                                        <th>Project</th>
                                        <?php endif; ?>
                                        <th>Date</th>
                                        <th>Category</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Receipt</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($costs as $cost): ?>
                                    <tr>
                                        <?php if (!$project): ?>
                                        <td>
                                            <strong><?php echo htmlspecialchars($cost['project_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($cost['project_code']); ?></small>
                                        </td>
                                        <?php endif; ?>
                                        <td><?php echo date('M d, Y', strtotime($cost['cost_date'])); ?></td>
                                        <td>
                                            <span class="category-badge <?php 
                                                echo match($cost['cost_category']) {
                                                    'land_purchase' => 'bg-primary text-white',
                                                    'survey' => 'bg-info text-white',
                                                    'legal_fees' => 'bg-warning text-dark',
                                                    'title_processing' => 'bg-secondary text-white',
                                                    'development' => 'bg-success text-white',
                                                    'marketing' => 'bg-danger text-white',
                                                    'consultation' => 'bg-purple text-white',
                                                    default => 'bg-dark text-white'
                                                };
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $cost['cost_category'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(substr($cost['cost_description'], 0, 50)); ?>
                                            <?php if (strlen($cost['cost_description']) > 50): ?>...<?php endif; ?>
                                        </td>
                                        <td><strong>TZS <?php echo number_format((float)$cost['cost_amount'], 2); ?></strong></td>
                                        <td>
                                            <?php if ($cost['receipt_number']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($cost['receipt_number']); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">N/A</small>
                                            <?php endif; ?>
                                            <?php if ($cost['attachment_path']): ?>
                                                <br><a href="../../<?php echo htmlspecialchars($cost['attachment_path']); ?>" target="_blank" class="text-primary">
                                                    <i class="fas fa-paperclip"></i> View
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($cost['approved_by']): ?>
                                                <span class="badge bg-success">Approved</span><br>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($cost['approver_first_name'] . ' ' . $cost['approver_last_name']); ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" onclick="viewCost(<?php echo $cost['cost_id']; ?>)" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (!$cost['approved_by']): ?>
                                                <button class="btn btn-outline-success" onclick="editCost(<?php echo $cost['cost_id']; ?>)" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-outline-info" onclick="approveCost(<?php echo $cost['cost_id']; ?>)" title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-outline-danger" onclick="deleteCost(<?php echo $cost['cost_id']; ?>)" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-dollar-sign fa-4x text-muted mb-4"></i>
                        <h4 class="text-muted">No Costs Recorded</h4>
                        <p class="text-muted">Start tracking project costs by adding your first cost entry</p>
                        <button class="btn btn-primary mt-3" onclick="showCostModal()">
                            <i class="fas fa-plus me-2"></i>Add First Cost
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Cost Breakdown Sidebar -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie me-2"></i>Cost Breakdown
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (count($cost_breakdown) > 0): ?>
                            <?php 
                            $total_for_percent = array_sum(array_column($cost_breakdown, 'total'));
                            foreach ($cost_breakdown as $item): 
                                $percentage = $total_for_percent > 0 ? ($item['total'] / $total_for_percent * 100) : 0;
                            ?>
                            <div class="breakdown-item">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold"><?php echo ucfirst(str_replace('_', ' ', $item['cost_category'])); ?></span>
                                    <span class="text-primary fw-bold">TZS <?php echo number_format((float)$item['total'], 0); ?></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <small class="text-muted"><?php echo (int)$item['count']; ?> entries</small>
                                    <small class="text-muted"><?php echo number_format($percentage, 1); ?>%</small>
                                </div>
                                <div class="progress progress-thin">
                                    <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-inbox fa-2x mb-3"></i>
                                <p class="mb-0">No cost breakdown available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</section>

<!-- Cost Modal -->
<div class="modal fade" id="costModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-dollar-sign me-2"></i>
                    <span id="modalTitle">Add Cost</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="costForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" id="formAction" value="add_cost">
                    <input type="hidden" name="cost_id" id="cost_id">
                    
                    <div class="row g-3">
                        <?php if (!$project): ?>
                        <div class="col-12">
                            <label class="form-label">Project <span class="text-danger">*</span></label>
                            <select class="form-select" name="project_id" id="project_id" required>
                                <option value="">-- Select Project --</option>
                                <?php foreach ($projects as $proj): ?>
                                <option value="<?php echo $proj['project_id']; ?>">
                                    <?php echo htmlspecialchars($proj['project_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                        <?php endif; ?>
                        
                        <div class="col-md-6">
                            <label class="form-label">Cost Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="cost_category" id="cost_category" required>
                                <option value="">-- Select Category --</option>
                                <option value="land_purchase">Land Purchase</option>
                                <option value="survey">Survey & Mapping</option>
                                <option value="legal_fees">Legal Fees</option>
                                <option value="title_processing">Title Processing</option>
                                <option value="development">Development</option>
                                <option value="marketing">Marketing</option>
                                <option value="consultation">Consultation</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Cost Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="cost_date" id="cost_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="cost_description" id="cost_description" 
                                      rows="3" required placeholder="Detailed description of the cost"></textarea>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Amount (TZS) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="cost_amount" id="cost_amount" 
                                   step="0.01" min="0" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Receipt Number</label>
                            <input type="text" class="form-control" name="receipt_number" id="receipt_number" 
                                   placeholder="e.g., RCT-001">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Attachment (Receipt/Invoice)</label>
                            <input type="file" class="form-control" name="attachment" id="attachment" 
                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <small class="text-muted">Supported formats: PDF, JPG, PNG, DOC, DOCX (Max 5MB)</small>
                            <div id="currentAttachment" style="display: none;" class="mt-2">
                                <small class="text-muted">Current attachment: <span id="attachmentName"></span></small>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" id="remarks" 
                                      rows="2" placeholder="Additional notes or comments"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Cost
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Cost Modal -->
<div class="modal fade" id="viewCostModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-eye me-2"></i>Cost Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="costDetailsContent">
                <!-- Content loaded via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
let costModal, viewCostModal;

$(document).ready(function() {
    costModal = new bootstrap.Modal(document.getElementById('costModal'));
    viewCostModal = new bootstrap.Modal(document.getElementById('viewCostModal'));
    
    // Initialize DataTable
    $('#costsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25
    });
});

function showCostModal() {
    document.getElementById('costForm').reset();
    document.getElementById('cost_id').value = '';
    document.getElementById('formAction').value = 'add_cost';
    document.getElementById('modalTitle').textContent = 'Add Cost';
    document.getElementById('currentAttachment').style.display = 'none';
    costModal.show();
}

function editCost(costId) {
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_cost',
            cost_id: costId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const cost = response.cost;
                document.getElementById('cost_id').value = cost.cost_id;
                document.getElementById('formAction').value = 'update_cost';
                <?php if (!$project): ?>
                document.getElementById('project_id').value = cost.project_id;
                <?php endif; ?>
                document.getElementById('cost_category').value = cost.cost_category;
                document.getElementById('cost_date').value = cost.cost_date;
                document.getElementById('cost_description').value = cost.cost_description;
                document.getElementById('cost_amount').value = cost.cost_amount;
                document.getElementById('receipt_number').value = cost.receipt_number || '';
                document.getElementById('remarks').value = cost.remarks || '';
                
                // Show current attachment if exists
                if (cost.attachment_path) {
                    document.getElementById('attachmentName').textContent = cost.attachment_path.split('/').pop();
                    document.getElementById('currentAttachment').style.display = 'block';
                } else {
                    document.getElementById('currentAttachment').style.display = 'none';
                }
                
                document.getElementById('modalTitle').textContent = 'Edit Cost';
                costModal.show();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error loading cost: ' + error);
        }
    });
}

function viewCost(costId) {
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            ajax: 1,
            action: 'get_cost',
            cost_id: costId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const cost = response.cost;
                let html = `
                    <div class="row g-3">
                        <div class="col-12">
                            <strong>Project:</strong> ${cost.project_name}
                        </div>
                        <div class="col-12">
                            <strong>Category:</strong> ${cost.cost_category.replace(/_/g, ' ').toUpperCase()}
                        </div>
                        <div class="col-6">
                            <strong>Date:</strong> ${new Date(cost.cost_date).toLocaleDateString()}
                        </div>
                        <div class="col-6">
                            <strong>Amount:</strong> TZS ${parseFloat(cost.cost_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}
                        </div>
                        <div class="col-12">
                            <strong>Description:</strong><br>
                            <p class="mt-2">${cost.cost_description}</p>
                        </div>
                        ${cost.receipt_number ? `<div class="col-12"><strong>Receipt Number:</strong> ${cost.receipt_number}</div>` : ''}
                        ${cost.attachment_path ? `<div class="col-12"><strong>Attachment:</strong> <a href="../../${cost.attachment_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-download me-1"></i>Download</a></div>` : ''}
                        ${cost.remarks ? `<div class="col-12"><strong>Remarks:</strong><br><p class="mt-2">${cost.remarks}</p></div>` : ''}
                    </div>
                `;
                document.getElementById('costDetailsContent').innerHTML = html;
                viewCostModal.show();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error loading cost: ' + error);
        }
    });
}

function deleteCost(costId) {
    if (confirm('Are you sure you want to delete this cost? This action cannot be undone.')) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'delete_cost',
                cost_id: costId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error deleting cost: ' + error);
            }
        });
    }
}

function approveCost(costId) {
    if (confirm('Are you sure you want to approve this cost?')) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                ajax: 1,
                action: 'approve_cost',
                cost_id: costId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error approving cost: ' + error);
            }
        });
    }
}

// Save cost
document.getElementById('costForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    // Set the correct action based on the hidden field
    const action = document.getElementById('formAction').value;
    formData.set('action', action);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error saving cost: ' + error);
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>