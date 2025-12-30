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

// Get processing_id from URL
$processing_id = $_GET['id'] ?? 0;

if (!$processing_id) {
    $_SESSION['error'] = "Invalid processing ID";
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

// Fetch processing details
try {
    $stmt = $conn->prepare("
        SELECT 
            tdp.*,
            c.full_name as customer_name,
            COALESCE(c.phone, c.phone1) as customer_phone,
            p.plot_number,
            p.block_number,
            pr.project_name,
            r.reservation_number
        FROM title_deed_processing tdp
        LEFT JOIN customers c ON tdp.customer_id = c.customer_id
        LEFT JOIN plots p ON tdp.plot_id = p.plot_id
        LEFT JOIN projects pr ON p.project_id = pr.project_id
        LEFT JOIN reservations r ON tdp.reservation_id = r.reservation_id
        WHERE tdp.processing_id = ? AND tdp.company_id = ?
    ");
    $stmt->execute([$processing_id, $company_id]);
    $processing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$processing) {
        $_SESSION['error'] = "Processing record not found";
        header("Location: index.php");
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading processing details";
    header("Location: index.php");
    exit;
}

// Handle update stage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stage'])) {
    try {
        $conn->beginTransaction();
        
        $new_stage = $_POST['new_stage'];
        $stage_status = $_POST['stage_status'];
        $started_date = $_POST['started_date'] ?? date('Y-m-d');
        $completed_date = $_POST['completed_date'] ?? null;
        $notes = $_POST['notes'] ?? '';
        
        // Stage order mapping
        $stage_orders = [
            'startup' => 1,
            'municipal' => 2,
            'ministry_of_land' => 3,
            'approved' => 4,
            'received' => 5,
            'delivered' => 6
        ];
        
        $stage_order = $stage_orders[$new_stage] ?? 0;
        
        // Check if stage exists
        $check_stmt = $conn->prepare("
            SELECT stage_id 
            FROM title_deed_stages 
            WHERE processing_id = ? AND stage_name = ? AND company_id = ?
        ");
        $check_stmt->execute([$processing_id, $new_stage, $company_id]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing stage
            $update_stmt = $conn->prepare("
                UPDATE title_deed_stages 
                SET stage_status = ?,
                    started_date = ?,
                    completed_date = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE stage_id = ? AND company_id = ?
            ");
            $update_stmt->execute([
                $stage_status, $started_date, $completed_date, $notes,
                $existing['stage_id'], $company_id
            ]);
        } else {
            // Insert new stage
            $insert_stmt = $conn->prepare("
                INSERT INTO title_deed_stages (
                    company_id, processing_id, stage_name, stage_order,
                    stage_status, started_date, completed_date, notes,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insert_stmt->execute([
                $company_id, $processing_id, $new_stage, $stage_order,
                $stage_status, $started_date, $completed_date, $notes,
                $user_id
            ]);
        }
        
        // Update current stage in processing table
        if ($stage_status === 'completed' || $stage_status === 'in_progress') {
            $update_processing = $conn->prepare("
                UPDATE title_deed_processing 
                SET current_stage = ?,
                    actual_completion_date = CASE WHEN ? = 'delivered' AND ? = 'completed' THEN ? ELSE actual_completion_date END,
                    updated_at = NOW()
                WHERE processing_id = ? AND company_id = ?
            ");
            $update_processing->execute([
                $new_stage, $new_stage, $stage_status, $completed_date,
                $processing_id, $company_id
            ]);
        }
        
        $conn->commit();
        $success = "Stage updated successfully!";
        
        // Refresh data
        $stmt->execute([$processing_id, $company_id]);
        $processing = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch all stages for this processing
try {
    $stages_stmt = $conn->prepare("
        SELECT *
        FROM title_deed_stages
        WHERE processing_id = ? AND company_id = ?
        ORDER BY stage_order ASC
    ");
    $stages_stmt->execute([$processing_id, $company_id]);
    $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stages = [];
}

// Define all stages
$all_stages = [
    'startup' => ['label' => 'Startup', 'icon' => 'play-circle', 'color' => 'secondary'],
    'municipal' => ['label' => 'Municipal', 'icon' => 'building', 'color' => 'info'],
    'ministry_of_land' => ['label' => 'Ministry of Land', 'icon' => 'landmark', 'color' => 'primary'],
    'approved' => ['label' => 'Approved', 'icon' => 'check-circle', 'color' => 'success'],
    'received' => ['label' => 'Received', 'icon' => 'inbox', 'color' => 'warning'],
    'delivered' => ['label' => 'Delivered', 'icon' => 'handshake', 'color' => 'dark']
];

// Create stage map
$stage_map = [];
foreach ($stages as $stage) {
    $stage_map[$stage['stage_name']] = $stage;
}

// Calculate progress
$completed_count = count(array_filter($stages, fn($s) => $s['stage_status'] === 'completed'));
$progress_percentage = ($completed_count / 6) * 100;

$page_title = 'Manage Stages';
require_once '../../includes/header.php';
?>

<style>
.processing-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.2);
}

.progress-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.progress-bar-custom {
    height: 30px;
    border-radius: 15px;
    background: #e9ecef;
    overflow: hidden;
}

.progress-fill-custom {
    height: 100%;
    background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 0.875rem;
    transition: width 0.3s;
}

.stage-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #dee2e6;
    transition: all 0.3s;
}

.stage-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.stage-card.completed {
    border-left-color: #28a745;
    background: #f8fff9;
}

.stage-card.in-progress {
    border-left-color: #007bff;
    background: #f0f8ff;
}

.stage-card.pending {
    border-left-color: #dee2e6;
    opacity: 0.8;
}

.stage-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-right: 1rem;
}

.stage-icon.completed {
    background: #28a745;
    color: white;
}

.stage-icon.in-progress {
    background: #007bff;
    color: white;
}

.stage-icon.pending {
    background: #e9ecef;
    color: #6c757d;
}

.stage-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.stage-meta {
    font-size: 0.875rem;
    color: #6c757d;
}

.update-form-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    position: sticky;
    top: 20px;
}
</style>

<div class="content-header mb-3">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-tasks text-primary me-2"></i>Manage Stages
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="view.php?id=<?php echo $processing_id; ?>" class="btn btn-info btn-sm">
                    <i class="fas fa-eye me-1"></i>View Details
                </a>
                <a href="stages.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Stages Overview
                </a>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Processing Header -->
        <div class="processing-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h4 class="mb-2">
                        <i class="fas fa-file-contract me-2"></i><?php echo htmlspecialchars($processing['processing_number']); ?>
                    </h4>
                    <div>
                        <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($processing['customer_name']); ?>
                        <span class="ms-3"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($processing['customer_phone']); ?></span>
                    </div>
                    <div class="mt-1">
                        <i class="fas fa-map-marker-alt me-2"></i>
                        Plot <?php echo htmlspecialchars($processing['plot_number']); ?> - 
                        Block <?php echo htmlspecialchars($processing['block_number']); ?> - 
                        <?php echo htmlspecialchars($processing['project_name']); ?>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <h5>TSH <?php echo number_format($processing['total_cost']); ?></h5>
                    <small>Started: <?php echo date('d M Y', strtotime($processing['started_date'])); ?></small>
                </div>
            </div>
        </div>

        <!-- Progress Card -->
        <div class="progress-card">
            <h6 class="mb-3">Overall Progress</h6>
            <div class="progress-bar-custom">
                <div class="progress-fill-custom" style="width: <?php echo $progress_percentage; ?>%">
                    <?php echo $completed_count; ?>/6 Stages Complete (<?php echo number_format($progress_percentage, 0); ?>%)
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Stages List -->
            <div class="col-md-8">
                <?php foreach ($all_stages as $stage_key => $stage_info): 
                    $stage_data = $stage_map[$stage_key] ?? null;
                    $stage_status = $stage_data['stage_status'] ?? 'pending';
                    $is_current = ($processing['current_stage'] === $stage_key);
                ?>
                <div class="stage-card <?php echo $stage_status; ?>">
                    <div class="d-flex align-items-start">
                        <div class="stage-icon <?php echo $stage_status; ?>">
                            <i class="fas fa-<?php echo $stage_info['icon']; ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="stage-title">
                                        <?php echo $stage_info['label']; ?>
                                        <?php if ($is_current): ?>
                                            <span class="badge bg-primary ms-2">Current</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stage-meta">
                                        <span class="badge bg-<?php echo $stage_info['color']; ?> me-2">
                                            <?php echo ucfirst($stage_status); ?>
                                        </span>
                                        
                                        <?php if ($stage_data): ?>
                                            <?php if ($stage_data['started_date']): ?>
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                Started: <?php echo date('d M Y', strtotime($stage_data['started_date'])); ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($stage_data['completed_date']): ?>
                                                <span class="ms-3">
                                                    <i class="fas fa-calendar-check me-1"></i>
                                                    Completed: <?php echo date('d M Y', strtotime($stage_data['completed_date'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($stage_data && $stage_data['notes']): ?>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="fas fa-sticky-note me-1"></i>
                                                <?php echo nl2br(htmlspecialchars($stage_data['notes'])); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Update Form -->
            <div class="col-md-4">
                <div class="update-form-card">
                    <h6 class="mb-3">
                        <i class="fas fa-edit me-2"></i>Update Stage
                    </h6>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Stage <span class="text-danger">*</span></label>
                            <select name="new_stage" class="form-select" required>
                                <option value="">-- Select Stage --</option>
                                <?php foreach ($all_stages as $key => $info): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $processing['current_stage'] === $key ? 'selected' : ''; ?>>
                                        <?php echo $info['label']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Status <span class="text-danger">*</span></label>
                            <select name="stage_status" class="form-select" required>
                                <option value="pending">Pending</option>
                                <option value="in_progress" selected>In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Started Date</label>
                            <input type="date" name="started_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Completed Date</label>
                            <input type="date" name="completed_date" class="form-control">
                            <small class="text-muted">Leave empty if not completed</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Enter stage notes..."></textarea>
                        </div>

                        <div class="d-grid">
                            <button type="submit" name="update_stage" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Stage
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>