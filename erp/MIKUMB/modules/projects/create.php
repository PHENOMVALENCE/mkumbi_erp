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

// Fetch regions, districts, wards, villages
try {
    $regions = $conn->query("SELECT region_id, region_name FROM regions ORDER BY region_name")->fetchAll(PDO::FETCH_ASSOC);
    $districts = $conn->query("SELECT district_id, district_name, region_id FROM districts ORDER BY district_name")->fetchAll(PDO::FETCH_ASSOC);
    $wards = $conn->query("SELECT ward_id, ward_name, district_id FROM wards ORDER BY ward_name")->fetchAll(PDO::FETCH_ASSOC);
    $villages = $conn->query("SELECT village_id, village_name, ward_id FROM villages ORDER BY village_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching locations: " . $e->getMessage());
    $regions = [];
    $districts = [];
    $wards = [];
    $villages = [];
}

// Generate auto project code
$project_code = 'PRJ-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    if (empty($_POST['project_name'])) {
        $errors[] = "Project name is required";
    }
    
    // Auto-generate project code if empty
    if (empty($_POST['project_code'])) {
        $_POST['project_code'] = 'PRJ-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    // If no errors, proceed with insertion
    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Handle file uploads
            $upload_dir = '../../uploads/projects/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $title_deed_path = null;
            $survey_plan_path = null;
            $contract_path = null;
            $coordinates_path = null;

            // Upload title deed
            if (isset($_FILES['title_deed']) && $_FILES['title_deed']['error'] === UPLOAD_ERR_OK) {
                $file_ext = pathinfo($_FILES['title_deed']['name'], PATHINFO_EXTENSION);
                $file_name = 'title_deed_' . time() . '_' . uniqid() . '.' . $file_ext;
                $title_deed_path = $upload_dir . $file_name;
                move_uploaded_file($_FILES['title_deed']['tmp_name'], $title_deed_path);
                $title_deed_path = 'uploads/projects/' . $file_name;
            }

            // Upload survey plan
            if (isset($_FILES['survey_plan']) && $_FILES['survey_plan']['error'] === UPLOAD_ERR_OK) {
                $file_ext = pathinfo($_FILES['survey_plan']['name'], PATHINFO_EXTENSION);
                $file_name = 'survey_plan_' . time() . '_' . uniqid() . '.' . $file_ext;
                $survey_plan_path = $upload_dir . $file_name;
                move_uploaded_file($_FILES['survey_plan']['tmp_name'], $survey_plan_path);
                $survey_plan_path = 'uploads/projects/' . $file_name;
            }

            // Upload contract
            if (isset($_FILES['contract_attachment']) && $_FILES['contract_attachment']['error'] === UPLOAD_ERR_OK) {
                $file_ext = pathinfo($_FILES['contract_attachment']['name'], PATHINFO_EXTENSION);
                $file_name = 'contract_' . time() . '_' . uniqid() . '.' . $file_ext;
                $contract_path = $upload_dir . $file_name;
                move_uploaded_file($_FILES['contract_attachment']['tmp_name'], $contract_path);
                $contract_path = 'uploads/projects/' . $file_name;
            }

            // Upload coordinates
            if (isset($_FILES['coordinates']) && $_FILES['coordinates']['error'] === UPLOAD_ERR_OK) {
                $file_ext = pathinfo($_FILES['coordinates']['name'], PATHINFO_EXTENSION);
                $file_name = 'coordinates_' . time() . '_' . uniqid() . '.' . $file_ext;
                $coordinates_path = $upload_dir . $file_name;
                move_uploaded_file($_FILES['coordinates']['tmp_name'], $coordinates_path);
                $coordinates_path = 'uploads/projects/' . $file_name;
            }

            // Calculate financial metrics
            $land_purchase_price = !empty($_POST['land_purchase_price']) ? floatval($_POST['land_purchase_price']) : 0;
            $total_operational_costs = !empty($_POST['total_operational_costs']) ? floatval($_POST['total_operational_costs']) : 0;
            $total_area = !empty($_POST['total_area']) ? floatval($_POST['total_area']) : 0;
            $selling_price_per_sqm = !empty($_POST['selling_price_per_sqm']) ? floatval($_POST['selling_price_per_sqm']) : 0;
            
            $cost_per_sqm = $total_area > 0 ? ($land_purchase_price + $total_operational_costs) / $total_area : 0;
            $profit_margin = $cost_per_sqm > 0 ? (($selling_price_per_sqm - $cost_per_sqm) / $cost_per_sqm) * 100 : 0;
            $total_expected_revenue = $total_area * $selling_price_per_sqm;

            // Insert project
            $sql = "INSERT INTO projects (
                company_id, project_name, project_code, description,
                region_id, district_id, ward_id, village_id,
                physical_location, total_area, total_plots,
                acquisition_date, closing_date,
                title_deed_path, survey_plan_path, contract_attachment_path, coordinates_path,
                status, land_purchase_price, total_operational_costs,
                cost_per_sqm, selling_price_per_sqm, profit_margin_percentage,
                total_expected_revenue, created_by
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?
            )";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $company_id,
                $_POST['project_name'],
                $_POST['project_code'],
                $_POST['description'] ?? null,
                !empty($_POST['region_id']) ? $_POST['region_id'] : null,
                !empty($_POST['district_id']) ? $_POST['district_id'] : null,
                !empty($_POST['ward_id']) ? $_POST['ward_id'] : null,
                !empty($_POST['village_id']) ? $_POST['village_id'] : null,
                $_POST['physical_location'] ?? null,
                $total_area,
                !empty($_POST['total_plots']) ? intval($_POST['total_plots']) : 0,
                !empty($_POST['acquisition_date']) ? $_POST['acquisition_date'] : null,
                !empty($_POST['closing_date']) ? $_POST['closing_date'] : null,
                $title_deed_path,
                $survey_plan_path,
                $contract_path,
                $coordinates_path,
                $_POST['status'] ?? 'planning',
                $land_purchase_price,
                $total_operational_costs,
                $cost_per_sqm,
                $selling_price_per_sqm,
                $profit_margin,
                $total_expected_revenue,
                $_SESSION['user_id']
            ]);

            $project_id = $conn->lastInsertId();

            // Update village with leadership information if provided
            if (!empty($_POST['village_id']) && (!empty($_POST['chairman_name']) || !empty($_POST['mtendaji_name']))) {
                $update_village_sql = "UPDATE villages SET 
                    chairman_name = ?,
                    chairman_phone = ?,
                    mtendaji_name = ?,
                    mtendaji_phone = ?
                    WHERE village_id = ? AND company_id = ?";
                
                $update_stmt = $conn->prepare($update_village_sql);
                $update_stmt->execute([
                    $_POST['chairman_name'] ?? null,
                    $_POST['chairman_phone'] ?? null,
                    $_POST['mtendaji_name'] ?? null,
                    $_POST['mtendaji_phone'] ?? null,
                    $_POST['village_id'],
                    $company_id
                ]);
            }

            // Insert project owner/seller information (simplified)
            if (!empty($_POST['seller_name'])) {
                $seller_sql = "INSERT INTO project_sellers (
                    project_id, company_id, seller_name, seller_phone, 
                    seller_nida, seller_tin, seller_address, purchase_date, 
                    purchase_amount, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $seller_stmt = $conn->prepare($seller_sql);
                $seller_stmt->execute([
                    $project_id,
                    $company_id,
                    $_POST['seller_name'],
                    $_POST['seller_phone'] ?? null,
                    $_POST['seller_nida'] ?? null,
                    $_POST['seller_tin'] ?? null,
                    $_POST['seller_address'] ?? null,
                    $_POST['purchase_date'] ?? null,
                    $land_purchase_price,
                    $_POST['seller_notes'] ?? null,
                    $_SESSION['user_id']
                ]);
            }

            $conn->commit();
            $success = "Project created successfully!";
            
            // Redirect after 2 seconds
            header("refresh:2;url=index.php");
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error creating project: " . $e->getMessage());
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title = 'Add New Project';
require_once '../../includes/header.php';
?>

<style>
.form-section {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
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
    color: #007bff;
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

.file-upload-box {
    border: 2px dashed #cbd5e0;
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
    background: #f8f9fa;
    transition: all 0.3s;
    cursor: pointer;
}

.file-upload-box:hover {
    border-color: #007bff;
    background: #e7f3ff;
}

.file-upload-box i {
    font-size: 2rem;
    color: #007bff;
    margin-bottom: 0.5rem;
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

.metric-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1rem;
    border-radius: 8px;
    text-align: center;
}

.metric-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 700;
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
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-plus-circle text-primary me-2"></i>Add New Project
                </h1>
                <p class="text-muted small mb-0 mt-1">Create a new land development project</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Projects
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
            <p class="mb-0 mt-2"><i class="fas fa-spinner fa-spin me-2"></i>Redirecting to projects list...</p>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Project Form -->
        <form method="POST" enctype="multipart/form-data" id="projectForm">
            
            <!-- Section 1: Basic Project Info -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-info-circle"></i>
                    <span>Section 1: Basic Project Information</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required-field">Project Name</label>
                        <input type="text" 
                               name="project_name" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['project_name'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Project Code</label>
                        <input type="text" 
                               name="project_code" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['project_code'] ?? $project_code); ?>"
                               readonly>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" 
                                  class="form-control" 
                                  rows="2"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="planning">Planning</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Acquisition Date</label>
                        <input type="date" 
                               name="acquisition_date" 
                               class="form-control"
                               value="<?php echo htmlspecialchars($_POST['acquisition_date'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Closing Date</label>
                        <input type="date" 
                               name="closing_date" 
                               class="form-control"
                               value="<?php echo htmlspecialchars($_POST['closing_date'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Section 2: Location Information -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>Section 2: Location Information</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Region</label>
                        <select name="region_id" id="region_id" class="form-select" onchange="filterDistricts()">
                            <option value="">Select Region</option>
                            <?php foreach ($regions as $region): ?>
                                <option value="<?php echo $region['region_id']; ?>">
                                    <?php echo htmlspecialchars($region['region_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">District</label>
                        <select name="district_id" id="district_id" class="form-select" onchange="filterWards()">
                            <option value="">Select District</option>
                            <?php foreach ($districts as $district): ?>
                                <option value="<?php echo $district['district_id']; ?>" data-region="<?php echo $district['region_id']; ?>" style="display:none;">
                                    <?php echo htmlspecialchars($district['district_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Ward</label>
                        <select name="ward_id" id="ward_id" class="form-select" onchange="filterVillages()">
                            <option value="">Select Ward</option>
                            <?php foreach ($wards as $ward): ?>
                                <option value="<?php echo $ward['ward_id']; ?>" data-district="<?php echo $ward['district_id']; ?>" style="display:none;">
                                    <?php echo htmlspecialchars($ward['ward_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Village</label>
                        <select name="village_id" id="village_id" class="form-select">
                            <option value="">Select Village</option>
                            <?php foreach ($villages as $village): ?>
                                <option value="<?php echo $village['village_id']; ?>" data-ward="<?php echo $village['ward_id']; ?>" style="display:none;">
                                    <?php echo htmlspecialchars($village['village_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Physical Location</label>
                        <textarea name="physical_location" 
                                  class="form-control" 
                                  rows="2"
                                  placeholder="Enter detailed physical location"><?php echo htmlspecialchars($_POST['physical_location'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Section 3: Village Leadership Information (Manual Input) -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-users"></i>
                    <span>Section 3: Village Leadership Information</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Village Chairman Name</label>
                        <input type="text" 
                               name="chairman_name" 
                               class="form-control"
                               placeholder="Enter chairman's full name"
                               value="<?php echo htmlspecialchars($_POST['chairman_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Chairman Phone Number</label>
                        <input type="tel" 
                               name="chairman_phone" 
                               class="form-control"
                               placeholder="+255 XXX XXX XXX"
                               value="<?php echo htmlspecialchars($_POST['chairman_phone'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Mtendaji Name (Village Executive Officer)</label>
                        <input type="text" 
                               name="mtendaji_name" 
                               class="form-control"
                               placeholder="Enter Mtendaji's full name"
                               value="<?php echo htmlspecialchars($_POST['mtendaji_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Mtendaji Phone Number</label>
                        <input type="tel" 
                               name="mtendaji_phone" 
                               class="form-control"
                               placeholder="+255 XXX XXX XXX"
                               value="<?php echo htmlspecialchars($_POST['mtendaji_phone'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Section 4: Project Owner Information (Simplified) -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-handshake"></i>
                    <span>Section 4: Project Owner/Land Seller Information</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Project Owner Name</label>
                        <input type="text" 
                               name="seller_name" 
                               class="form-control"
                               placeholder="Full name of land owner/seller"
                               value="<?php echo htmlspecialchars($_POST['seller_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" 
                               name="seller_phone" 
                               class="form-control"
                               placeholder="+255 XXX XXX XXX"
                               value="<?php echo htmlspecialchars($_POST['seller_phone'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">NIDA Number</label>
                        <input type="text" 
                               name="seller_nida" 
                               class="form-control"
                               placeholder="National ID Number"
                               value="<?php echo htmlspecialchars($_POST['seller_nida'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">TIN Number</label>
                        <input type="text" 
                               name="seller_tin" 
                               class="form-control"
                               placeholder="Tax Identification Number"
                               value="<?php echo htmlspecialchars($_POST['seller_tin'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" 
                               name="purchase_date" 
                               class="form-control"
                               value="<?php echo htmlspecialchars($_POST['purchase_date'] ?? ''); ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea name="seller_address" 
                                  class="form-control" 
                                  rows="2"
                                  placeholder="Physical address of the project owner"><?php echo htmlspecialchars($_POST['seller_address'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Additional Notes</label>
                        <textarea name="seller_notes" 
                                  class="form-control" 
                                  rows="2"
                                  placeholder="Any additional information about the owner or purchase"><?php echo htmlspecialchars($_POST['seller_notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Section 5: Land & Plot Details -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-ruler-combined"></i>
                    <span>Section 5: Land & Plot Details</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Total Area (m²)</label>
                        <input type="number" 
                               name="total_area" 
                               id="total_area"
                               class="form-control" 
                               step="0.01"
                               placeholder="e.g., 50000"
                               value="<?php echo htmlspecialchars($_POST['total_area'] ?? ''); ?>"
                               onchange="calculateMetrics()">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Total Plots</label>
                        <input type="number" 
                               name="total_plots" 
                               class="form-control"
                               placeholder="e.g., 100"
                               value="<?php echo htmlspecialchars($_POST['total_plots'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Section 6: Financial Information -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Section 6: Financial Information</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Land Purchase Price (TSH)</label>
                        <input type="number" 
                               name="land_purchase_price" 
                               id="land_purchase_price"
                               class="form-control" 
                               step="0.01"
                               placeholder="e.g., 100000000"
                               value="<?php echo htmlspecialchars($_POST['land_purchase_price'] ?? ''); ?>"
                               onchange="calculateMetrics()">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Operational Costs (TSH)</label>
                        <input type="number" 
                               name="total_operational_costs" 
                               id="total_operational_costs"
                               class="form-control" 
                               step="0.01"
                               placeholder="e.g., 20000000"
                               value="<?php echo htmlspecialchars($_POST['total_operational_costs'] ?? ''); ?>"
                               onchange="calculateMetrics()">
                        <small class="text-muted">Survey, legal fees, development costs, etc.</small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Selling Price per m² (TSH)</label>
                        <input type="number" 
                               name="selling_price_per_sqm" 
                               id="selling_price_per_sqm"
                               class="form-control" 
                               step="0.01"
                               placeholder="e.g., 3000"
                               value="<?php echo htmlspecialchars($_POST['selling_price_per_sqm'] ?? ''); ?>"
                               onchange="calculateMetrics()">
                    </div>

                    <div class="col-12">
                        <button type="button" class="calculate-btn" onclick="calculateMetrics()">
                            <i class="fas fa-calculator me-2"></i>Calculate Financial Metrics
                        </button>
                    </div>

                    <!-- Calculated Metrics Display -->
                    <div class="col-12 mt-3">
                        <div class="row g-3" id="metricsDisplay" style="display: none;">
                            <div class="col-md-3">
                                <div class="metric-card">
                                    <div class="metric-label">Cost per m²</div>
                                    <div class="metric-value" id="cost_per_sqm_display">TSH 0</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="metric-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                    <div class="metric-label">Total Investment</div>
                                    <div class="metric-value" id="total_investment_display">TSH 0</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="metric-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                    <div class="metric-label">Profit Margin</div>
                                    <div class="metric-value" id="profit_margin_display">0%</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="metric-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                                    <div class="metric-label">Expected Revenue</div>
                                    <div class="metric-value" id="expected_revenue_display">TSH 0</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 7: Document Attachments -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-paperclip"></i>
                    <span>Section 7: Document Attachments</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Title Deed</label>
                        <div class="file-upload-box" onclick="document.getElementById('title_deed').click()">
                            <i class="fas fa-file-upload d-block"></i>
                            <p class="mb-0">Upload Title Deed</p>
                            <small class="text-muted">PDF, JPG, PNG</small>
                        </div>
                        <input type="file" 
                               id="title_deed"
                               name="title_deed" 
                               class="d-none"
                               accept=".pdf,.jpg,.jpeg,.png"
                               onchange="displayFileName(this, 'title_deed_name')">
                        <small id="title_deed_name" class="text-success d-block mt-1"></small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Survey Plan</label>
                        <div class="file-upload-box" onclick="document.getElementById('survey_plan').click()">
                            <i class="fas fa-file-upload d-block"></i>
                            <p class="mb-0">Upload Survey Plan</p>
                            <small class="text-muted">PDF, JPG, PNG</small>
                        </div>
                        <input type="file" 
                               id="survey_plan"
                               name="survey_plan" 
                               class="d-none"
                               accept=".pdf,.jpg,.jpeg,.png"
                               onchange="displayFileName(this, 'survey_plan_name')">
                        <small id="survey_plan_name" class="text-success d-block mt-1"></small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Purchase Contract/Agreement</label>
                        <div class="file-upload-box" onclick="document.getElementById('contract_attachment').click()">
                            <i class="fas fa-file-upload d-block"></i>
                            <p class="mb-0">Upload Contract</p>
                            <small class="text-muted">PDF, DOC, DOCX</small>
                        </div>
                        <input type="file" 
                               id="contract_attachment"
                               name="contract_attachment" 
                               class="d-none"
                               accept=".pdf,.doc,.docx"
                               onchange="displayFileName(this, 'contract_name')">
                        <small id="contract_name" class="text-success d-block mt-1"></small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Coordinates/Map</label>
                        <div class="file-upload-box" onclick="document.getElementById('coordinates').click()">
                            <i class="fas fa-file-upload d-block"></i>
                            <p class="mb-0">Upload Coordinates</p>
                            <small class="text-muted">PDF, KML, GPX, Image</small>
                        </div>
                        <input type="file" 
                               id="coordinates"
                               name="coordinates" 
                               class="d-none"
                               accept=".pdf,.kml,.gpx,.jpg,.jpeg,.png"
                               onchange="displayFileName(this, 'coordinates_name')">
                        <small id="coordinates_name" class="text-success d-block mt-1"></small>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-section">
                <div class="d-flex justify-content-between align-items-center">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-save text-white">
                        <i class="fas fa-save me-2"></i>Create Project
                    </button>
                </div>
            </div>

        </form>

    </div>
</section>

<script>
// Filter districts based on region
function filterDistricts() {
    const regionId = document.getElementById('region_id').value;
    const districtSelect = document.getElementById('district_id');
    const wardSelect = document.getElementById('ward_id');
    const villageSelect = document.getElementById('village_id');
    
    // Reset all dependent dropdowns
    districtSelect.value = '';
    wardSelect.value = '';
    villageSelect.value = '';
    
    // Hide all ward and village options
    Array.from(wardSelect.options).forEach(opt => opt.style.display = 'none');
    Array.from(villageSelect.options).forEach(opt => opt.style.display = 'none');
    
    // Show/hide districts based on region
    Array.from(districtSelect.options).forEach(option => {
        if (option.value === '' || option.getAttribute('data-region') === regionId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
}

// Filter wards based on district
function filterWards() {
    const districtId = document.getElementById('district_id').value;
    const wardSelect = document.getElementById('ward_id');
    const villageSelect = document.getElementById('village_id');
    
    wardSelect.value = '';
    villageSelect.value = '';
    
    // Hide all village options
    Array.from(villageSelect.options).forEach(opt => opt.style.display = 'none');
    
    // Show/hide wards based on district
    Array.from(wardSelect.options).forEach(option => {
        if (option.value === '' || option.getAttribute('data-district') === districtId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
}

// Filter villages based on ward
function filterVillages() {
    const wardId = document.getElementById('ward_id').value;
    const villageSelect = document.getElementById('village_id');
    
    villageSelect.value = '';
    
    // Show/hide villages based on ward
    Array.from(villageSelect.options).forEach(option => {
        if (option.value === '' || option.getAttribute('data-ward') === wardId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
}

// Calculate financial metrics
function calculateMetrics() {
    const totalArea = parseFloat(document.getElementById('total_area').value) || 0;
    const landPrice = parseFloat(document.getElementById('land_purchase_price').value) || 0;
    const operationalCosts = parseFloat(document.getElementById('total_operational_costs').value) || 0;
    const sellingPrice = parseFloat(document.getElementById('selling_price_per_sqm').value) || 0;
    
    if (totalArea > 0 && (landPrice > 0 || operationalCosts > 0 || sellingPrice > 0)) {
        const totalInvestment = landPrice + operationalCosts;
        const costPerSqm = totalInvestment / totalArea;
        const profitMargin = costPerSqm > 0 ? ((sellingPrice - costPerSqm) / costPerSqm) * 100 : 0;
        const expectedRevenue = totalArea * sellingPrice;
        
        document.getElementById('cost_per_sqm_display').textContent = 
            'TSH ' + costPerSqm.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        document.getElementById('total_investment_display').textContent = 
            'TSH ' + totalInvestment.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        document.getElementById('profit_margin_display').textContent = 
            profitMargin.toFixed(1) + '%';
        document.getElementById('expected_revenue_display').textContent = 
            'TSH ' + expectedRevenue.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        
        document.getElementById('metricsDisplay').style.display = 'block';
    }
}

// Display selected file name
function displayFileName(input, displayId) {
    const fileName = input.files[0]?.name;
    if (fileName) {
        document.getElementById(displayId).textContent = '✓ ' + fileName;
    }
}
</script>

<?php 
require_once '../../includes/footer.php';
?>