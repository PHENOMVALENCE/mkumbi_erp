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

// CRITICAL FIX: Set the current user for all triggers
$user_id = $_SESSION['user_id'] ?? 0;
$conn->exec("SET @current_user_id = " . (int)$user_id);

$company_id = $_SESSION['company_id'];

// Get plot ID from URL
$plot_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$plot_id) {
    $_SESSION['error'] = "Invalid plot ID";
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';
$plot = null;
$projects = [];

// ==================== FETCH PLOT DATA ====================
try {
    $plot_sql = "SELECT p.*, pr.project_name, pr.project_code
                 FROM plots p
                 LEFT JOIN projects pr ON p.project_id = pr.project_id
                 WHERE p.plot_id = ? AND p.company_id = ?";
    $plot_stmt = $conn->prepare($plot_sql);
    $plot_stmt->execute([$plot_id, $company_id]);
    $plot = $plot_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plot) {
        $_SESSION['error'] = "Plot not found";
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching plot: " . $e->getMessage());
    $_SESSION['error'] = "Error loading plot details";
    header('Location: index.php');
    exit;
}

// ==================== FETCH PROJECTS ====================
try {
    $projects_sql = "SELECT project_id, project_name, project_code,
                            total_area, selling_price_per_sqm, total_plots,
                            COALESCE((SELECT COUNT(*) FROM plots WHERE project_id = projects.project_id AND company_id = ?), 0) as created_plots
                     FROM projects
                     WHERE company_id = ? AND is_active = 1
                     ORDER BY project_name";
    $projects_stmt = $conn->prepare($projects_sql);
    $projects_stmt->execute([$company_id, $company_id]);
    $projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching projects: " . $e->getMessage());
    $projects = [];
}

// ==================== HANDLE FORM SUBMISSION ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    // Validation
    if (empty($_POST['project_id'])) $errors[] = "Project is required";
    if (empty($_POST['plot_number'])) $errors[] = "Plot number is required";
    if (empty($_POST['area']) || !is_numeric($_POST['area']) || $_POST['area'] <= 0) $errors[] = "Valid plot area is required";
    if (empty($_POST['price_per_sqm']) || !is_numeric($_POST['price_per_sqm']) || $_POST['price_per_sqm'] <= 0) $errors[] = "Valid price per m² is required";

    // Check duplicate plot number in same project
    if (empty($errors)) {
        $check_sql = "SELECT COUNT(*) FROM plots 
                      WHERE project_id = ? AND plot_number = ? 
                      AND plot_id != ? AND company_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([
            $_POST['project_id'],
            trim($_POST['plot_number']),
            $plot_id,
            $company_id
        ]);
        if ($check_stmt->fetchColumn() > 0) {
            $errors[] = "Plot number already exists in this project";
        }
    }

    // Prevent status change on sold plots with active reservation
    if ($plot['status'] === 'sold' && isset($_POST['status']) && $_POST['status'] !== 'sold') {
        $res_check = $conn->prepare("SELECT COUNT(*) FROM reservations 
                                     WHERE plot_id = ? AND company_id = ? 
                                     AND status IN ('active', 'completed')");
        $res_check->execute([$plot_id, $company_id]);
        if ($res_check->fetchColumn() > 0) {
            $errors[] = "Cannot change status of a sold plot with active reservation";
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $old_project_id = $plot['project_id'];
            $new_project_id = (int)$_POST['project_id'];

            $area = floatval($_POST['area']);
            $price_per_sqm = floatval($_POST['price_per_sqm']);
            $selling_price = $area * $price_per_sqm;
            $discount_amount = floatval($_POST['discount_amount'] ?? 0);

            $update_sql = "UPDATE plots SET
                project_id = ?,
                plot_number = ?,
                block_number = ?,
                area = ?,
                price_per_sqm = ?,
                selling_price = ?,
                discount_amount = ?,
                survey_plan_number = ?,
                town_plan_number = ?,
                gps_coordinates = ?,
                status = ?,
                corner_plot = ?,
                coordinates = ?,
                notes = ?,
                updated_at = CURRENT_TIMESTAMP
                WHERE plot_id = ? AND company_id = ?";

            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->execute([
                $new_project_id,
                trim($_POST['plot_number']),
                $_POST['block_number'] ?? null,
                $area,
                $price_per_sqm,
                $selling_price,
                $discount_amount,
                $_POST['survey_plan_number'] ?? null,
                $_POST['town_plan_number'] ?? null,
                $_POST['gps_coordinates'] ?? null,
                $_POST['status'] ?? 'available',
                isset($_POST['corner_plot']) ? 1 : 0,
                $_POST['coordinates'] ?? null,
                $_POST['notes'] ?? null,
                $plot_id,
                $company_id
            ]);

            // Update project counters
            $update_project_counts = function($proj_id) use ($conn, $company_id) {
                $sql = "UPDATE projects SET
                    available_plots = (SELECT COUNT(*) FROM plots WHERE project_id = ? AND company_id = ? AND status = 'available'),
                    reserved_plots = (SELECT COUNT(*) FROM plots WHERE project_id = ? AND company_id = ? AND status = 'reserved'),
                    sold_plots = (SELECT COUNT(*) FROM plots WHERE project_id = ? AND company_id = ? AND status = 'sold')
                    WHERE project_id = ? AND company_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$proj_id, $company_id, $proj_id, $company_id, $proj_id, $company_id, $proj_id, $company_id]);
            };

            if ($old_project_id != $new_project_id) {
                $update_project_counts($old_project_id);
            }
            $update_project_counts($new_project_id);

            $conn->commit();
            $success = "Plot updated successfully! Redirecting...";
            header("refresh:2;url=view.php?id=$plot_id");
        } catch (Exception $e) {
            $conn->rollBack();
            error_log("Update failed: " . $e->getMessage());
            $errors[] = "Failed to update plot. Please try again.";
        }
    }
}

$page_title = 'Edit Plot - ' . htmlspecialchars($plot['plot_number'] ?? 'Unknown');
require_once '../../includes/header.php';
?>
<style>
.form-section {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #ffc107;
}

.form-section-header {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
}

.form-section-header i {
    margin-right: 0.5rem;
    color: #ffc107;
}

.form-label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 0.5rem;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.info-box {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.warning-box {
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
    padding: 1.5rem;
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

.project-info-label {
    font-weight: 600;
    color: #495057;
}

.project-info-value {
    color: #ffc107;
    font-weight: 600;
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

.btn-update {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    border: none;
    padding: 0.75rem 2rem;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(240, 147, 251, 0.3);
    color: white;
}

.btn-update:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(240, 147, 251, 0.4);
    color: white;
}

.auto-fill-badge {
    background: #28a745;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    margin-left: 0.5rem;
}

.current-value-badge {
    background: #17a2b8;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    margin-left: 0.5rem;
}

.status-info {
    background: #e7f3ff;
    border: 1px solid #b8daff;
    border-radius: 6px;
    padding: 0.75rem;
    margin-top: 0.5rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-edit text-warning me-2"></i>Edit Plot
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Update plot information - <?php echo htmlspecialchars($plot['plot_number']); ?>
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="view.php?id=<?php echo $plot_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-eye me-1"></i> View Plot
                    </a>
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
        <?php if (!empty($errors)): ?>
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
            <p class="mb-0 mt-2"><i class="fas fa-spinner fa-spin me-2"></i>Redirecting to plot details...</p>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Status Warning -->
        <?php if ($plot['status'] === 'sold' || $plot['status'] === 'reserved'): ?>
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Warning:</strong> This plot is currently <strong><?php echo strtoupper($plot['status']); ?></strong>. 
            Changing certain details may affect existing reservations or sales records.
        </div>
        <?php endif; ?>

        <!-- Plot Edit Form -->
        <form method="POST" id="plotForm">
            
            <!-- Section 1: Project Selection -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-project-diagram"></i>
                    <span>Section 1: Project Selection</span>
                </div>

                <div class="info-box">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Current Project:</strong> <?php echo htmlspecialchars($plot['project_name']); ?> 
                    (<?php echo htmlspecialchars($plot['project_code']); ?>)
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
                                <option value="<?php echo $proj['project_id']; ?>"
                                        data-name="<?php echo htmlspecialchars($proj['project_name']); ?>"
                                        data-code="<?php echo htmlspecialchars($proj['project_code']); ?>"
                                        data-area="<?php echo $proj['total_area']; ?>"
                                        data-price="<?php echo $proj['selling_price_per_sqm']; ?>"
                                        data-total-plots="<?php echo $proj['total_plots']; ?>"
                                        data-created-plots="<?php echo $proj['created_plots']; ?>"
                                        <?php echo ($plot['project_id'] == $proj['project_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($proj['project_name']); ?> 
                                    (<?php echo htmlspecialchars($proj['project_code']); ?>) - 
                                    <?php echo $proj['created_plots']; ?>/<?php echo $proj['total_plots']; ?> plots created
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Project Information Display -->
                <div id="projectInfoDisplay" class="project-info-display" style="display: <?php echo $plot['project_id'] ? 'block' : 'none'; ?>;">
                    <h6 class="mb-3"><i class="fas fa-info-circle text-warning me-2"></i>Project Information</h6>
                    <div class="project-info-item">
                        <span class="project-info-label">Project Name:</span>
                        <span class="project-info-value" id="display_project_name"><?php echo htmlspecialchars($plot['project_name']); ?></span>
                    </div>
                    <div class="project-info-item">
                        <span class="project-info-label">Project Code:</span>
                        <span class="project-info-value" id="display_project_code"><?php echo htmlspecialchars($plot['project_code']); ?></span>
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
                               value="<?php echo htmlspecialchars($plot['plot_number']); ?>"
                               required>
                        <small class="text-muted">Unique identifier for this plot</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Block Number</label>
                        <input type="text" 
                               name="block_number" 
                               class="form-control" 
                               placeholder="e.g., Block A, Block 1"
                               value="<?php echo htmlspecialchars($plot['block_number'] ?? ''); ?>">
                        <small class="text-muted">Optional grouping identifier</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Survey Plan Number</label>
                        <input type="text" 
                               name="survey_plan_number" 
                               class="form-control" 
                               placeholder="Official survey plan reference"
                               value="<?php echo htmlspecialchars($plot['survey_plan_number'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Town Plan Number</label>
                        <input type="text" 
                               name="town_plan_number" 
                               class="form-control" 
                               placeholder="Town planning reference"
                               value="<?php echo htmlspecialchars($plot['town_plan_number'] ?? ''); ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">GPS Coordinates</label>
                        <input type="text" 
                               name="gps_coordinates" 
                               class="form-control" 
                               placeholder="e.g., -6.7924, 39.2083"
                               value="<?php echo htmlspecialchars($plot['gps_coordinates'] ?? ''); ?>">
                        <small class="text-muted">Latitude, Longitude format</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Coordinates/Location Details</label>
                        <textarea name="coordinates" 
                                  class="form-control" 
                                  rows="2"
                                  placeholder="Additional location information"><?php echo htmlspecialchars($plot['coordinates'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Section 3: Plot Size & Pricing -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-calculator"></i>
                    <span>Section 3: Plot Size & Pricing</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label required-field">
                            Plot Area (m²)
                            <span class="current-value-badge">Current: <?php echo number_format($plot['area'], 2); ?> m²</span>
                        </label>
                        <input type="number" 
                               name="area" 
                               id="area"
                               class="form-control" 
                               step="0.01"
                               placeholder="e.g., 500"
                               value="<?php echo $plot['area']; ?>"
                               onchange="calculatePrice()"
                               required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required-field">
                            Price per m² (TSH)
                            <span class="current-value-badge">Current: TSH <?php echo number_format($plot['price_per_sqm'], 0); ?></span>
                        </label>
                        <input type="number" 
                               name="price_per_sqm" 
                               id="price_per_sqm"
                               class="form-control" 
                               step="0.01"
                               placeholder="e.g., 3000"
                               value="<?php echo $plot['price_per_sqm']; ?>"
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
                               value="<?php echo $plot['selling_price']; ?>"
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
                               value="<?php echo $plot['discount_amount'] ?? 0; ?>"
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
                            <i class="fas fa-calculator me-2"></i>Recalculate Selling Price
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
                        <label class="form-label">
                            Plot Status
                            <span class="current-value-badge">Current: <?php echo strtoupper($plot['status']); ?></span>
                        </label>
                        <select name="status" id="status" class="form-select" onchange="showStatusWarning()">
                            <option value="available" <?php echo ($plot['status'] === 'available') ? 'selected' : ''; ?>>Available</option>
                            <option value="reserved" <?php echo ($plot['status'] === 'reserved') ? 'selected' : ''; ?>>Reserved</option>
                            <option value="sold" <?php echo ($plot['status'] === 'sold') ? 'selected' : ''; ?>>Sold</option>
                            <option value="blocked" <?php echo ($plot['status'] === 'blocked') ? 'selected' : ''; ?>>Blocked</option>
                        </select>
                        <div id="statusWarning" class="status-info" style="display: none;">
                            <i class="fas fa-info-circle me-1"></i>
                            <small>Changing status may affect existing reservations or sales.</small>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-check mt-4">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   name="corner_plot" 
                                   id="corner_plot"
                                   value="1"
                                   <?php echo $plot['corner_plot'] ? 'checked' : ''; ?>>
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
                                  placeholder="Any special notes or features about this plot"><?php echo htmlspecialchars($plot['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-section">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <a href="view.php?id=<?php echo $plot_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                    <button type="submit" class="btn btn-update">
                        <i class="fas fa-save me-2"></i>Update Plot
                    </button>
                </div>
            </div>

        </form>

    </div>
</section>

<script>
// Projects data as JavaScript object
const projectsData = <?php echo json_encode($projects); ?>;
const currentStatus = '<?php echo $plot['status']; ?>';

// Load project information when project is selected
function loadProjectInfo() {
    const projectSelect = document.getElementById('project_id');
    const selectedOption = projectSelect.options[projectSelect.selectedIndex];
    
    if (projectSelect.value) {
        // Get data from option attributes
        const projectName = selectedOption.getAttribute('data-name');
        const projectCode = selectedOption.getAttribute('data-code');
        const totalArea = selectedOption.getAttribute('data-area');
        const pricePerSqm = selectedOption.getAttribute('data-price');
        const totalPlots = selectedOption.getAttribute('data-total-plots');
        const createdPlots = selectedOption.getAttribute('data-created-plots');
        
        // Display project information
        document.getElementById('display_project_name').textContent = projectName;
        document.getElementById('display_project_code').textContent = projectCode;
        
        // Show project info display
        document.getElementById('projectInfoDisplay').style.display = 'block';
    } else {
        // Hide project info display
        document.getElementById('projectInfoDisplay').style.display = 'none';
    }
}

// Calculate selling price based on area and price per sqm
function calculatePrice() {
    const area = parseFloat(document.getElementById('area').value) || 0;
    const pricePerSqm = parseFloat(document.getElementById('price_per_sqm').value) || 0;
    
    if (area > 0 && pricePerSqm > 0) {
        const sellingPrice = area * pricePerSqm;
        document.getElementById('selling_price').value = sellingPrice.toFixed(2);
        
        // Calculate final price
        calculateFinalPrice();
    } else {
        document.getElementById('selling_price').value = '';
        document.getElementById('final_price_display').value = '';
    }
}

// Calculate final price after discount
function calculateFinalPrice() {
    const sellingPrice = parseFloat(document.getElementById('selling_price').value) || 0;
    const discountAmount = parseFloat(document.getElementById('discount_amount').value) || 0;
    
    if (sellingPrice > 0) {
        const finalPrice = sellingPrice - discountAmount;
        document.getElementById('final_price_display').value = 'TSH ' + finalPrice.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
}

// Show warning when changing status
function showStatusWarning() {
    const statusSelect = document.getElementById('status');
    const statusWarning = document.getElementById('statusWarning');
    
    if (statusSelect.value !== currentStatus) {
        statusWarning.style.display = 'block';
    } else {
        statusWarning.style.display = 'none';
    }
}

// Auto-calculate when area or price changes
document.getElementById('area').addEventListener('input', calculatePrice);
document.getElementById('price_per_sqm').addEventListener('input', calculatePrice);
document.getElementById('discount_amount').addEventListener('input', calculateFinalPrice);

// Calculate final price on page load
window.addEventListener('DOMContentLoaded', function() {
    calculateFinalPrice();
});
</script>

<?php 
require_once '../../includes/footer.php';
?>