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

// Initialize variables
$errors = [];
$success = '';

// Fetch projects
try {
    $projects_sql = "SELECT project_id, project_name, project_code, 
                     total_area, selling_price_per_sqm, total_plots,
                     COALESCE((SELECT COUNT(*) FROM plots WHERE project_id = projects.project_id), 0) as created_plots,
                     COALESCE((SELECT SUM(area) FROM plots WHERE project_id = projects.project_id), 0) as used_area
                     FROM projects 
                     WHERE company_id = ? AND is_active = 1 
                     ORDER BY project_name";
    $projects_stmt = $conn->prepare($projects_sql);
    $projects_stmt->execute([$company_id]);
    $projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching projects: " . $e->getMessage());
    $projects = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    if (empty($_POST['project_id'])) {
        $errors[] = "Project is required";
    }
    if (empty($_POST['plot_number'])) {
        $errors[] = "Plot number is required";
    }
    if (empty($_POST['area'])) {
        $errors[] = "Plot area is required";
    }
    if (empty($_POST['selling_price'])) {
        $errors[] = "Selling price is required";
    }

    // **Check if maximum plots limit is reached**
    if (! empty($_POST['project_id'])) {
        $project_check_sql = "SELECT 
                                p.total_plots,
                                p.total_area,
                                p.project_name,
                                COALESCE((SELECT COUNT(*) FROM plots WHERE project_id = p.project_id), 0) as created_plots,
                                COALESCE((SELECT SUM(area) FROM plots WHERE project_id = p.project_id), 0) as used_area
                              FROM projects p
                              WHERE p.project_id = ? AND p.company_id = ? ";
        $project_check_stmt = $conn->prepare($project_check_sql);
        $project_check_stmt->execute([$_POST['project_id'], $company_id]);
        $project_info = $project_check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($project_info) {
            $max_plots = (int)$project_info['total_plots'];
            $current_plots = (int)$project_info['created_plots'];
            $total_area = (float)$project_info['total_area'];
            $used_area = (float)$project_info['used_area'];
            $plot_area = (float)$_POST['area'];
            $remaining_area = $total_area - $used_area;
            
            // Check plots limit
            if ($max_plots > 0 && $current_plots >= $max_plots) {
                $errors[] = "Cannot create plot.  Maximum plots limit reached for project '{$project_info['project_name']}'. Maximum allowed: {$max_plots}, Currently created: {$current_plots}";
            }
            
            // **NEW: Check area limit**
            if ($total_area > 0 && $plot_area > $remaining_area) {
                $errors[] = "Cannot create plot. Plot area ({$plot_area} m²) exceeds remaining available area ({$remaining_area} m²) for project '{$project_info['project_name']}'. Total project area: {$total_area} m², Already used: {$used_area} m²";
            }
            
            // Warning if area will be fully utilized
            if ($total_area > 0 && $plot_area == $remaining_area && $plot_area > 0) {
                // This is acceptable but we can log it
                error_log("Plot creation will fully utilize remaining area for project {$project_info['project_name']}");
            }
        }
    }

    // Check for duplicate plot number in the same project
    if (!empty($_POST['project_id']) && ! empty($_POST['plot_number'])) {
        $check_sql = "SELECT COUNT(*) FROM plots 
                      WHERE project_id = ? AND plot_number = ? AND company_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([$_POST['project_id'], $_POST['plot_number'], $company_id]);
        
        if ($check_stmt->fetchColumn() > 0) {
            $errors[] = "Plot number already exists in this project";
        }
    }

    // If no errors, proceed with insertion
    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Calculate prices
            $area = floatval($_POST['area']);
            $price_per_sqm = floatval($_POST['price_per_sqm']);
            $selling_price = floatval($_POST['selling_price']);
            $discount_amount = floatval($_POST['discount_amount'] ?? 0);

            // Insert plot
            $sql = "INSERT INTO plots (
                company_id, project_id, plot_number, block_number,
                area, price_per_sqm, selling_price, discount_amount,
                survey_plan_number, town_plan_number, gps_coordinates,
                status, corner_plot, coordinates, notes, created_by
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ? 
            )";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $company_id,
                $_POST['project_id'],
                $_POST['plot_number'],
                $_POST['block_number'] ?? null,
                $area,
                $price_per_sqm,
                $selling_price,
                $discount_amount,
                $_POST['survey_plan_number'] ?? null,
                $_POST['town_plan_number'] ??  null,
                $_POST['gps_coordinates'] ?? null,
                $_POST['status'] ?? 'available',
                isset($_POST['corner_plot']) ? 1 : 0,
                $_POST['coordinates'] ?? null,
                $_POST['notes'] ?? null,
                $_SESSION['user_id']
            ]);

            // Update project plot counts
            $update_project_sql = "UPDATE projects 
                                   SET available_plots = (
                                       SELECT COUNT(*) FROM plots 
                                       WHERE project_id = ? AND status = 'available'
                                   ),
                                   reserved_plots = (
                                       SELECT COUNT(*) FROM plots 
                                       WHERE project_id = ? AND status = 'reserved'
                                   ),
                                   sold_plots = (
                                       SELECT COUNT(*) FROM plots 
                                       WHERE project_id = ? AND status = 'sold'
                                   )
                                   WHERE project_id = ?  AND company_id = ?";
            
            $update_stmt = $conn->prepare($update_project_sql);
            $update_stmt->execute([
                $_POST['project_id'],
                $_POST['project_id'],
                $_POST['project_id'],
                $_POST['project_id'],
                $company_id
            ]);

            $conn->commit();
            $success = "Plot created successfully!";
            
            // Redirect after 2 seconds
            header("refresh:2;url=index.php?project_id=" . $_POST['project_id']);
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error creating plot: " . $e->getMessage());
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title = 'Add New Plot';
require_once '../../includes/header.php';
?>

<style>
. form-section {
    background:  #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
}

.form-section-header {
    font-size: 1.1rem;
    font-weight:  600;
    color: #2c3e50;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom:  2px solid #e9ecef;
    display: flex;
    align-items: center;
}

.form-section-header i {
    margin-right: 0.5rem;
    color: #007bff;
}

.form-label {
    font-weight: 500;
    color: #495057;
    margin-bottom:  0.5rem;
}

.required-field:: after {
    content: " *";
    color: #dc3545;
}

.info-box {
    background: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom:  1rem;
}

.warning-box {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 1rem;
    border-radius:  6px;
    margin-bottom: 1rem;
}

.danger-box {
    background: #f8d7da;
    border-left: 4px solid #dc3545;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.project-info-display {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 1. 5rem;
    margin-top: 1rem;
    display: none;
}

.project-info-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e9ecef;
}

.project-info-item:last-child {
    border-bottom: none;
}

. project-info-label {
    font-weight: 600;
    color: #495057;
}

.project-info-value {
    color: #007bff;
    font-weight: 600;
}

.project-info-value.text-danger {
    color: #dc3545 !important;
}

.project-info-value.text-success {
    color: #28a745 !important;
}

.project-info-value.text-warning {
    color: #ffc107 !important;
}

.calculate-btn {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
    padding: 0.5rem 1.5rem;
    border-radius: 6px;
    font-weight: 500;
    transition: transform 0.2s;
}

.calculate-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.btn-save {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    border: none;
    padding: 0.75rem 2rem;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(17, 153, 142, 0.3);
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(17, 153, 142, 0.4);
}

.btn-save:disabled {
    background: #6c757d;
    cursor: not-allowed;
    opacity: 0.65;
}

.auto-fill-badge {
    background: #28a745;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    margin-left: 0.5rem;
}

.progress-bar-wrapper {
    margin-top: 0.5rem;
}

. progress {
    height: 25px;
    border-radius: 8px;
}

.progress-bar {
    font-weight: 600;
    font-size: 0.875rem;
}

.area-indicator {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size:  0.85rem;
    font-weight:  600;
    margin-left: 0.5rem;
}

.area-indicator.danger {
    background: #f8d7da;
    color:  #721c24;
}

.area-indicator.warning {
    background: #fff3cd;
    color: #856404;
}

.area-indicator.success {
    background: #d4edda;
    color:  #155724;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-plus-circle text-primary me-2"></i>Add New Plot
                </h1>
                <p class="text-muted small mb-0 mt-1">Create a new plot for a project</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Plots
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Display Errors -->
        <?php if (! empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following errors:</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Display Success -->
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <p class="mb-0 mt-2"><i class="fas fa-spinner fa-spin me-2"></i>Redirecting to plots list...</p>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Plot Form -->
        <form method="POST" id="plotForm">
            
            <!-- Section 1: Project Selection -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-project-diagram"></i>
                    <span>Section 1: Project Selection</span>
                </div>

                <div class="info-box">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Select a project to auto-fill pricing and plot information</strong>
                </div>

                <div id="plotLimitWarning" class="warning-box" style="display: none;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Warning:</strong> <span id="warningMessage"></span>
                </div>

                <div id="plotLimitError" class="danger-box" style="display: none;">
                    <i class="fas fa-ban me-2"></i>
                    <strong>Cannot Create Plot:</strong> <span id="errorMessage"></span>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label required-field">
                            Select Project
                            <span class="auto-fill-badge"><i class="fas fa-magic"></i> Auto-fills below</span>
                        </label>
                        <select name="project_id" id="project_id" class="form-select" required onchange="loadProjectInfo()">
                            <option value="">-- Select a Project --</option>
                            <?php foreach ($projects as $proj): ?>
                                <?php 
                                $remaining_area = $proj['total_area'] - $proj['used_area'];
                                $is_full = ($proj['total_plots'] > 0 && $proj['created_plots'] >= $proj['total_plots']) || 
                                          ($proj['total_area'] > 0 && $remaining_area <= 0);
                                ?>
                                <option value="<?php echo $proj['project_id']; ?>"
                                        data-name="<?php echo htmlspecialchars($proj['project_name']); ?>"
                                        data-code="<?php echo htmlspecialchars($proj['project_code']); ?>"
                                        data-area="<?php echo $proj['total_area']; ?>"
                                        data-used-area="<?php echo $proj['used_area']; ?>"
                                        data-price="<?php echo $proj['selling_price_per_sqm']; ?>"
                                        data-total-plots="<?php echo $proj['total_plots']; ?>"
                                        data-created-plots="<?php echo $proj['created_plots']; ?>"
                                        <?php echo $is_full ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($proj['project_name']); ?> 
                                    (<?php echo htmlspecialchars($proj['project_code']); ?>) - 
                                    <?php echo $proj['created_plots']; ?>/<?php echo $proj['total_plots']; ?> plots, 
                                    <?php echo number_format($remaining_area, 2); ?> m² remaining
                                    <?php if ($is_full): ?>
                                        - FULL
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Project Information Display -->
                <div id="projectInfoDisplay" class="project-info-display">
                    <h6 class="mb-3"><i class="fas fa-info-circle text-primary me-2"></i>Project Information</h6>
                    <div class="project-info-item">
                        <span class="project-info-label">Project Name:</span>
                        <span class="project-info-value" id="display_project_name">-</span>
                    </div>
                    <div class="project-info-item">
                        <span class="project-info-label">Project Code:</span>
                        <span class="project-info-value" id="display_project_code">-</span>
                    </div>
                    <div class="project-info-item">
                        <span class="project-info-label">Total Project Area:</span>
                        <span class="project-info-value" id="display_project_area">-</span>
                    </div>
                    <div class="project-info-item">
                        <span class="project-info-label">Used Area:</span>
                        <span class="project-info-value" id="display_used_area">-</span>
                    </div>
                    <div class="project-info-item">
                        <span class="project-info-label">Remaining Area:</span>
                        <span class="project-info-value" id="display_remaining_area">-</span>
                    </div>
                    <div class="project-info-item">
                        <span class="project-info-label">Default Price per m²:</span>
                        <span class="project-info-value" id="display_price_per_sqm">-</span>
                    </div>
                    <div class="project-info-item">
                        <span class="project-info-label">Plots Progress:</span>
                        <span class="project-info-value" id="display_plots_progress">-</span>
                    </div>
                    <div class="progress-bar-wrapper">
                        <label class="form-label mb-1">Area Utilization:</label>
                        <div class="progress">
                            <div id="areaProgressBar" class="progress-bar" role="progressbar" style="width:  0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                0%
                            </div>
                        </div>
                    </div>
                    <div class="progress-bar-wrapper">
                        <label class="form-label mb-1">Plots Created:</label>
                        <div class="progress">
                            <div id="plotProgressBar" class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                0%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Plot Identification -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-hashtag"></i>
                    <span>Section 2: Plot Identification</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required-field">Plot Number</label>
                        <input type="text" 
                               name="plot_number" 
                               class="form-control" 
                               placeholder="e.g., A01, B12, 001"
                               value="<?php echo htmlspecialchars($_POST['plot_number'] ?? ''); ?>"
                               required>
                        <small class="text-muted">Unique identifier for this plot</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Block Number</label>
                        <input type="text" 
                               name="block_number" 
                               class="form-control" 
                               placeholder="e.g., Block A, Block 1"
                               value="<?php echo htmlspecialchars($_POST['block_number'] ?? ''); ?>">
                        <small class="text-muted">Optional grouping identifier</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Survey Plan Number</label>
                        <input type="text" 
                               name="survey_plan_number" 
                               class="form-control" 
                               placeholder="Official survey plan reference"
                               value="<?php echo htmlspecialchars($_POST['survey_plan_number'] ??  ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Town Plan Number</label>
                        <input type="text" 
                               name="town_plan_number" 
                               class="form-control" 
                               placeholder="Town planning reference"
                               value="<?php echo htmlspecialchars($_POST['town_plan_number'] ?? ''); ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">GPS Coordinates</label>
                        <input type="text" 
                               name="gps_coordinates" 
                               class="form-control" 
                               placeholder="e.g., -6.7924, 39.2083"
                               value="<?php echo htmlspecialchars($_POST['gps_coordinates'] ?? ''); ?>">
                        <small class="text-muted">Latitude, Longitude format</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Coordinates/Location Details</label>
                        <textarea name="coordinates" 
                                  class="form-control" 
                                  rows="2"
                                  placeholder="Additional location information"><?php echo htmlspecialchars($_POST['coordinates'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Section 3: Plot Size & Pricing -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-calculator"></i>
                    <span>Section 3: Plot Size & Pricing</span>
                </div>

                <div id="areaWarning" class="warning-box" style="display: none;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Area Warning:</strong> <span id="areaWarningMessage"></span>
                </div>

                <div id="areaError" class="danger-box" style="display: none;">
                    <i class="fas fa-ban me-2"></i>
                    <strong>Area Exceeded:</strong> <span id="areaErrorMessage"></span>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label required-field">
                            Plot Area (m²)
                            <span id="areaIndicator"></span>
                        </label>
                        <input type="number" 
                               name="area" 
                               id="area"
                               class="form-control" 
                               step="0.01"
                               placeholder="e.g., 500"
                               value="<?php echo htmlspecialchars($_POST['area'] ?? ''); ?>"
                               oninput="validatePlotArea()"
                               required>
                        <small class="text-muted">Max:  <span id="maxAreaDisplay">-</span> m²</small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required-field">
                            Price per m² (TSH)
                            <span class="auto-fill-badge"><i class="fas fa-magic"></i> Auto-filled</span>
                        </label>
                        <input type="number" 
                               name="price_per_sqm" 
                               id="price_per_sqm"
                               class="form-control" 
                               step="0.01"
                               placeholder="e.g., 3000"
                               value="<?php echo htmlspecialchars($_POST['price_per_sqm'] ?? ''); ?>"
                               onchange="calculatePrice()"
                               required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required-field">
                            Selling Price (TSH)
                            <span class="auto-fill-badge"><i class="fas fa-magic"></i> Auto-calculated</span>
                        </label>
                        <input type="number" 
                               name="selling_price" 
                               id="selling_price"
                               class="form-control" 
                               step="0.01"
                               placeholder="Auto-calculated"
                               value="<?php echo htmlspecialchars($_POST['selling_price'] ??  ''); ?>"
                               readonly
                               required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Discount Amount (TSH)</label>
                        <input type="number" 
                               name="discount_amount" 
                               id="discount_amount"
                               class="form-control" 
                               step="0.01"
                               placeholder="0"
                               value="<?php echo htmlspecialchars($_POST['discount_amount'] ?? '0'); ?>"
                               onchange="calculateFinalPrice()">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Final Price (After Discount)</label>
                        <input type="text" 
                               id="final_price_display"
                               class="form-control" 
                               placeholder="TSH 0"
                               readonly>
                    </div>

                    <div class="col-12">
                        <button type="button" class="calculate-btn" onclick="calculatePrice()">
                            <i class="fas fa-calculator me-2"></i>Calculate Selling Price
                        </button>
                    </div>
                </div>
            </div>

            <!-- Section 4: Plot Details -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-info-circle"></i>
                    <span>Section 4: Plot Details</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Plot Status</label>
                        <select name="status" class="form-select">
                            <option value="available" selected>Available</option>
                            <option value="reserved">Reserved</option>
                            <option value="sold">Sold</option>
                            <option value="blocked">Blocked</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <div class="form-check mt-4">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   name="corner_plot" 
                                   id="corner_plot"
                                   value="1">
                            <label class="form-check-label" for="corner_plot">
                                <strong>Corner Plot</strong>
                                <br>
                                <small class="text-muted">Check if this is a corner plot (usually premium)</small>
                            </label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Additional Notes</label>
                        <textarea name="notes" 
                                  class="form-control" 
                                  rows="3"
                                  placeholder="Any special notes or features about this plot"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-section">
                <div class="d-flex justify-content-between align-items-center">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                    <button type="submit" id="submitBtn" class="btn btn-save text-white">
                        <i class="fas fa-save me-2"></i>Create Plot
                    </button>
                </div>
            </div>

        </form>

    </div>
</section>

<script>
// Global variables to store project data
let currentProjectData = null;

// Projects data as JavaScript object
const projectsData = <?php echo json_encode($projects); ?>;

// Load project information when project is selected
function loadProjectInfo() {
    const projectSelect = document.getElementById('project_id');
    const selectedOption = projectSelect.options[projectSelect.selectedIndex];
    const submitBtn = document.getElementById('submitBtn');
    const warningBox = document.getElementById('plotLimitWarning');
    const errorBox = document.getElementById('plotLimitError');
    
    if (projectSelect.value) {
        // Get data from option attributes
        const projectName = selectedOption.getAttribute('data-name');
        const projectCode = selectedOption.getAttribute('data-code');
        const totalArea = parseFloat(selectedOption. getAttribute('data-area'));
        const usedArea = parseFloat(selectedOption.getAttribute('data-used-area'));
        const pricePerSqm = selectedOption.getAttribute('data-price');
        const totalPlots = parseInt(selectedOption.getAttribute('data-total-plots'));
        const createdPlots = parseInt(selectedOption.getAttribute('data-created-plots'));
        
        const remainingArea = totalArea - usedArea;
        
        // Store current project data globally
        currentProjectData = {
            name: projectName,
            totalArea: totalArea,
            usedArea: usedArea,
            remainingArea: remainingArea,
            totalPlots: totalPlots,
            createdPlots: createdPlots
        };
        
        // Calculate progress percentages
        const areaPercent = totalArea > 0 ? (usedArea / totalArea) * 100 : 0;
        const plotPercent = totalPlots > 0 ? (createdPlots / totalPlots) * 100 : 0;
        const remainingPlots = totalPlots - createdPlots;
        
        // Display project information
        document.getElementById('display_project_name').textContent = projectName;
        document.getElementById('display_project_code').textContent = projectCode;
        document.getElementById('display_project_area').textContent = totalArea. toLocaleString() + ' m²';
        document.getElementById('display_used_area').textContent = usedArea. toLocaleString() + ' m²';
        document.getElementById('display_remaining_area').textContent = remainingArea.toLocaleString() + ' m²';
        document.getElementById('display_price_per_sqm').textContent = 'TSH ' + parseFloat(pricePerSqm).toLocaleString();
        document.getElementById('display_plots_progress').textContent = createdPlots + ' / ' + totalPlots + ' plots created';
        document.getElementById('maxAreaDisplay').textContent = remainingArea.toLocaleString();
        
        // Update area progress bar
        const areaProgressBar = document.getElementById('areaProgressBar');
        areaProgressBar.style.width = areaPercent + '%';
        areaProgressBar.setAttribute('aria-valuenow', areaPercent);
        areaProgressBar. textContent = areaPercent. toFixed(1) + '%';
        
        // Change area progress bar color
        areaProgressBar.className = 'progress-bar';
        if (areaPercent < 50) {
            areaProgressBar.classList.add('bg-success');
        } else if (areaPercent < 80) {
            areaProgressBar.classList.add('bg-warning');
        } else if (areaPercent < 100) {
            areaProgressBar.classList.add('bg-danger');
        } else {
            areaProgressBar.classList.add('bg-dark');
        }
        
        // Update plot progress bar
        const plotProgressBar = document.getElementById('plotProgressBar');
        plotProgressBar.style.width = plotPercent + '%';
        plotProgressBar.setAttribute('aria-valuenow', plotPercent);
        plotProgressBar.textContent = plotPercent.toFixed(1) + '%';
        
        // Change plot progress bar color
        plotProgressBar.className = 'progress-bar';
        if (plotPercent < 50) {
            plotProgressBar.classList.add('bg-success');
        } else if (plotPercent < 80) {
            plotProgressBar.classList.add('bg-warning');
        } else if (plotPercent < 100) {
            plotProgressBar.classList.add('bg-danger');
        } else {
            plotProgressBar. classList.add('bg-dark');
        }
        
        // Show warnings or errors
        warningBox.style.display = 'none';
        errorBox. style.display = 'none';
        
        let hasError = false;
        let warningMessages = [];
        let errorMessages = [];
        
        // Check plot limit
        if (totalPlots > 0 && createdPlots >= totalPlots) {
            errorMessages.push(`Maximum plots limit reached (${totalPlots} plots)`);
            hasError = true;
        } else if (totalPlots > 0 && remainingPlots <= 3) {
            warningMessages. push(`Only ${remainingPlots} plot(s) remaining out of ${totalPlots}`);
        }
        
        // Check area limit
        if (totalArea > 0 && remainingArea <= 0) {
            errorMessages.push(`No remaining area available (${totalArea. toLocaleString()} m² fully utilized)`);
            hasError = true;
        } else if (totalArea > 0 && remainingArea < totalArea * 0.1) {
            warningMessages. push(`Only ${remainingArea.toLocaleString()} m² remaining out of ${totalArea.toLocaleString()} m²`);
        }
        
        // Display messages
        if (hasError) {
            errorBox.style.display = 'block';
            document.getElementById('errorMessage').innerHTML = errorMessages.join('<br>');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-ban me-2"></i>Cannot Create - Limit Reached';
            document.getElementById('display_plots_progress').className = 'project-info-value text-danger';
            document.getElementById('display_remaining_area').className = 'project-info-value text-danger';
        } else if (warningMessages.length > 0) {
            warningBox.style.display = 'block';
            document.getElementById('warningMessage').innerHTML = warningMessages.join('<br>');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Create Plot';
            document.getElementById('display_plots_progress').className = 'project-info-value text-warning';
            document.getElementById('display_remaining_area').className = 'project-info-value text-warning';
        } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Create Plot';
            document.getElementById('display_plots_progress').className = 'project-info-value text-success';
            document.getElementById('display_remaining_area').className = 'project-info-value text-success';
        }
        
        // Auto-fill price per sqm
        document.getElementById('price_per_sqm').value = pricePerSqm;
        
        // Show project info display
        document.getElementById('projectInfoDisplay').style.display = 'block';
        
        // Validate area if already entered
        if (document.getElementById('area').value) {
            validatePlotArea();
        }
    } else {
        // Reset everything
        currentProjectData = null;
        document.getElementById('projectInfoDisplay').style.display = 'none';
        warningBox.style.display = 'none';
        errorBox. style.display = 'none';
        document.getElementById('areaWarning').style.display = 'none';
        document. getElementById('areaError').style.display = 'none';
        document.getElementById('price_per_sqm').value = '';
        document.getElementById('selling_price').value = '';
        document.getElementById('maxAreaDisplay').textContent = '-';
        document.getElementById('areaIndicator').innerHTML = '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Create Plot';
    }
}

// Validate plot area against remaining area
function validatePlotArea() {
    const areaInput = document.getElementById('area');
    const plotArea = parseFloat(areaInput.value) || 0;
    const areaWarning = document.getElementById('areaWarning');
    const areaError = document.getElementById('areaError');
    const submitBtn = document.getElementById('submitBtn');
    const areaIndicator = document.getElementById('areaIndicator');
    
    if (! currentProjectData || currentProjectData.totalArea <= 0) {
        areaWarning.style.display = 'none';
        areaError.style.display = 'none';
        areaIndicator.innerHTML = '';
        calculatePrice();
        return;
    }
    
    const remainingArea = currentProjectData. remainingArea;
    
    if (plotArea > remainingArea) {
        // Error: exceeds remaining area
        areaError.style.display = 'block';
        areaWarning.style.display = 'none';
        document.getElementById('areaErrorMessage').textContent = 
            `Plot area (${plotArea.toLocaleString()} m²) exceeds remaining available area (${remainingArea.toLocaleString()} m²). Please reduce the plot area.`;
        areaInput.classList.add('is-invalid');
        areaIndicator.innerHTML = '<span class="area-indicator danger">Exceeds Limit! </span>';
        submitBtn.disabled = true;
        submitBtn. innerHTML = '<i class="fas fa-ban me-2"></i>Area Exceeded';
    } else if (plotArea > remainingArea * 0.8) {
        // Warning: uses more than 80% of remaining area
        areaWarning.style.display = 'block';
        areaError.style.display = 'none';
        document.getElementById('areaWarningMessage').textContent = 
            `This plot will use ${((plotArea / remainingArea) * 100).toFixed(1)}% of the remaining area. Only ${(remainingArea - plotArea).toLocaleString()} m² will be left.`;
        areaInput.classList.remove('is-invalid');
        areaInput.classList.add('is-valid');
        areaIndicator. innerHTML = '<span class="area-indicator warning">High Usage</span>';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Create Plot';
    } else if (plotArea > 0) {
        // Success: within acceptable range
        areaWarning.style.display = 'none';
        areaError. style.display = 'none';
        areaInput.classList.remove('is-invalid');
        areaInput.classList.add('is-valid');
        areaIndicator.innerHTML = '<span class="area-indicator success">✓ Valid</span>';
        submitBtn. disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Create Plot';
    } else {
        areaWarning.style.display = 'none';
        areaError. style.display = 'none';
        areaInput.classList. remove('is-invalid', 'is-valid');
        areaIndicator.innerHTML = '';
    }
    
    calculatePrice();
}

// Calculate selling price based on area and price per sqm
function calculatePrice() {
    const area = parseFloat(document.getElementById('area').value) || 0;
    const pricePerSqm = parseFloat(document.getElementById('price_per_sqm').value) || 0;
    
    if (area > 0 && pricePerSqm > 0) {
        const sellingPrice = area * pricePerSqm;
        document. getElementById('selling_price').value = sellingPrice.toFixed(2);
        calculateFinalPrice();
    } else {
        document.getElementById('selling_price').value = '';
        document.getElementById('final_price_display').value = '';
    }
}

// Calculate final price after discount
function calculateFinalPrice() {
    const sellingPrice = parseFloat(document. getElementById('selling_price').value) || 0;
    const discountAmount = parseFloat(document.getElementById('discount_amount').value) || 0;
    
    if (sellingPrice > 0) {
        const finalPrice = sellingPrice - discountAmount;
        document.getElementById('final_price_display').value = 'TSH ' + finalPrice.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
}

// Auto-calculate when inputs change
document.getElementById('price_per_sqm').addEventListener('input', calculatePrice);
document.getElementById('discount_amount').addEventListener('input', calculateFinalPrice);

// Prevent form submission if validation fails
document.getElementById('plotForm').addEventListener('submit', function(e) {
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn.disabled) {
        e.preventDefault();
        alert('Cannot create plot.  Please check the errors and try again.');
        return false;
    }
    
    // Final validation before submission
    if (currentProjectData && currentProjectData.totalArea > 0) {
        const plotArea = parseFloat(document.getElementById('area').value) || 0;
        if (plotArea > currentProjectData. remainingArea) {
            e.preventDefault();
            alert(`Plot area (${plotArea} m²) exceeds remaining available area (${currentProjectData.remainingArea} m²).`);
            return false;
        }
    }
});
</script>

<?php 
require_once '../../includes/footer.php';
?>