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

// Get project ID from URL
$project_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$project_id) {
    header('Location: index.php');
    exit;
}

// Fetch project details with location information
try {
    $sql = "SELECT p.*, 
            r.region_name,
            d.district_name,
            w.ward_name,
            v.village_name, v.chairman_name, v.chairman_phone, v.mtendaji_name, v.mtendaji_phone,
            u.full_name as created_by_name
            FROM projects p
            LEFT JOIN regions r ON p.region_id = r.region_id
            LEFT JOIN districts d ON p.district_id = d.district_id
            LEFT JOIN wards w ON p.ward_id = w.ward_id
            LEFT JOIN villages v ON p.village_id = v.village_id
            LEFT JOIN users u ON p.created_by = u.user_id
            WHERE p.project_id = ? AND p.company_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$project_id, $company_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        header('Location: index.php');
        exit;
    }

    // Fetch project seller/owner information
    $seller_sql = "SELECT * FROM project_sellers 
                   WHERE project_id = ? AND company_id = ?";
    $seller_stmt = $conn->prepare($seller_sql);
    $seller_stmt->execute([$project_id, $company_id]);
    $seller = $seller_stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch plots for this project
    $plots_sql = "SELECT 
                  COALESCE(COUNT(*), 0) as total_plots,
                  COALESCE(SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END), 0) as available_plots,
                  COALESCE(SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END), 0) as reserved_plots,
                  COALESCE(SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END), 0) as sold_plots,
                  COALESCE(SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END), 0) as blocked_plots
                  FROM plots 
                  WHERE project_id = ? AND company_id = ?";
    $plots_stmt = $conn->prepare($plots_sql);
    $plots_stmt->execute([$project_id, $company_id]);
    $plots_stats = $plots_stmt->fetch(PDO::FETCH_ASSOC);

    // Ensure all plot stats have default values
    $plots_stats['total_plots'] = $plots_stats['total_plots'] ?? 0;
    $plots_stats['available_plots'] = $plots_stats['available_plots'] ?? 0;
    $plots_stats['reserved_plots'] = $plots_stats['reserved_plots'] ?? 0;
    $plots_stats['sold_plots'] = $plots_stats['sold_plots'] ?? 0;
    $plots_stats['blocked_plots'] = $plots_stats['blocked_plots'] ?? 0;

    // Fetch project costs
    $costs_sql = "SELECT COALESCE(SUM(cost_amount), 0) as total_costs, 
                  COALESCE(COUNT(*), 0) as cost_count
                  FROM project_costs 
                  WHERE project_id = ? AND company_id = ?";
    $costs_stmt = $conn->prepare($costs_sql);
    $costs_stmt->execute([$project_id, $company_id]);
    $costs_stats = $costs_stmt->fetch(PDO::FETCH_ASSOC);

    // Ensure default values for project fields
    $project['total_area'] = $project['total_area'] ?? 0;
    $project['land_purchase_price'] = $project['land_purchase_price'] ?? 0;
    $project['total_operational_costs'] = $project['total_operational_costs'] ?? 0;
    $project['cost_per_sqm'] = $project['cost_per_sqm'] ?? 0;
    $project['selling_price_per_sqm'] = $project['selling_price_per_sqm'] ?? 0;
    $project['profit_margin_percentage'] = $project['profit_margin_percentage'] ?? 0;
    $project['total_expected_revenue'] = $project['total_expected_revenue'] ?? 0;
    $project['total_actual_revenue'] = $project['total_actual_revenue'] ?? 0;

} catch (PDOException $e) {
    error_log("Error fetching project: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'View Project - ' . $project['project_name'];
require_once '../../includes/header.php';
?>

<style>
.info-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.info-card-header {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.info-card-header i {
    margin-right: 0.5rem;
    color: #007bff;
}

.info-item {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f3f5;
}

.info-item:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #495057;
    min-width: 200px;
}

.info-value {
    color: #212529;
    flex: 1;
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 500;
}

.status-planning {
    background: #fff3cd;
    color: #856404;
}

.status-active {
    background: #d1ecf1;
    color: #0c5460;
}

.status-completed {
    background: #d4edda;
    color: #155724;
}

.status-suspended {
    background: #f8d7da;
    color: #721c24;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.875rem;
    opacity: 0.9;
}

.document-preview {
    display: inline-block;
    padding: 0.5rem 1rem;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    color: #495057;
    text-decoration: none;
    transition: all 0.3s;
}

.document-preview:hover {
    background: #007bff;
    color: white;
    border-color: #007bff;
}

.financial-metric {
    background: #f8f9fa;
    border-left: 4px solid #007bff;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.metric-label {
    font-size: 0.875rem;
    color: #6c757d;
    margin-bottom: 0.25rem;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #212529;
}

.action-button {
    margin-right: 0.5rem;
    margin-bottom: 0.5rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-eye text-primary me-2"></i><?php echo htmlspecialchars($project['project_name']); ?>
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    <i class="fas fa-hashtag me-1"></i><?php echo htmlspecialchars($project['project_code']); ?>
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="edit.php?id=<?php echo $project_id; ?>" class="btn btn-warning action-button">
                        <i class="fas fa-edit me-1"></i> Edit
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary action-button">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($project['total_area'], 2); ?> m²</div>
                    <div class="stat-label">Total Area</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stat-value"><?php echo number_format($plots_stats['total_plots']); ?></div>
                    <div class="stat-label">Total Plots</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stat-value"><?php echo number_format($plots_stats['available_plots']); ?></div>
                    <div class="stat-label">Available Plots</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="stat-value"><?php echo number_format($plots_stats['sold_plots']); ?></div>
                    <div class="stat-label">Sold Plots</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column -->
            <div class="col-md-6">

                <!-- Basic Information -->
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-info-circle"></i>Basic Information</span>
                        <span class="status-badge status-<?php echo strtolower($project['status']); ?>">
                            <?php echo ucfirst($project['status']); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Project Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['project_name']); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Project Code:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['project_code']); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Description:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['description'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Acquisition Date:</div>
                        <div class="info-value">
                            <?php echo $project['acquisition_date'] ? date('d M Y', strtotime($project['acquisition_date'])) : 'N/A'; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Closing Date:</div>
                        <div class="info-value">
                            <?php echo $project['closing_date'] ? date('d M Y', strtotime($project['closing_date'])) : 'N/A'; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Created By:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['created_by_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Created At:</div>
                        <div class="info-value"><?php echo date('d M Y H:i', strtotime($project['created_at'])); ?></div>
                    </div>
                </div>

                <!-- Location Information -->
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-map-marker-alt"></i>Location Information</span>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Region:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['region_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">District:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['district_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Ward:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['ward_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Village:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['village_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Physical Location:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['physical_location'] ?? 'N/A'); ?></div>
                    </div>
                </div>

                <!-- Village Leadership -->
                <?php if ($project['village_id']): ?>
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-users"></i>Village Leadership</span>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Village Chairman:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['chairman_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Chairman Phone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['chairman_phone'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Mtendaji (VEO):</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['mtendaji_name'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Mtendaji Phone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($project['mtendaji_phone'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Right Column -->
            <div class="col-md-6">

                <!-- Financial Information -->
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-money-bill-wave"></i>Financial Information</span>
                    </div>

                    <div class="financial-metric">
                        <div class="metric-label">Land Purchase Price</div>
                        <div class="metric-value">TSH <?php echo number_format($project['land_purchase_price'], 2); ?></div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #f093fb;">
                        <div class="metric-label">Operational Costs</div>
                        <div class="metric-value">TSH <?php echo number_format($project['total_operational_costs'], 2); ?></div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #4facfe;">
                        <div class="metric-label">Total Investment</div>
                        <div class="metric-value">TSH <?php echo number_format($project['land_purchase_price'] + $project['total_operational_costs'], 2); ?></div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #43e97b;">
                        <div class="metric-label">Cost per m²</div>
                        <div class="metric-value">TSH <?php echo number_format($project['cost_per_sqm'], 2); ?></div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #fa709a;">
                        <div class="metric-label">Selling Price per m²</div>
                        <div class="metric-value">TSH <?php echo number_format($project['selling_price_per_sqm'], 2); ?></div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #feca57;">
                        <div class="metric-label">Profit Margin</div>
                        <div class="metric-value"><?php echo number_format($project['profit_margin_percentage'], 2); ?>%</div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #48dbfb;">
                        <div class="metric-label">Expected Revenue</div>
                        <div class="metric-value">TSH <?php echo number_format($project['total_expected_revenue'], 2); ?></div>
                    </div>

                    <div class="financial-metric" style="border-left-color: #1dd1a1;">
                        <div class="metric-label">Actual Revenue</div>
                        <div class="metric-value">TSH <?php echo number_format($project['total_actual_revenue'], 2); ?></div>
                    </div>
                </div>

                <!-- Project Owner Information -->
                <?php if ($seller): ?>
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-handshake"></i>Project Owner/Seller</span>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Owner Name:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['seller_name']); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Phone Number:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['seller_phone'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">NIDA Number:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['seller_nida'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">TIN Number:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['seller_tin'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Purchase Date:</div>
                        <div class="info-value">
                            <?php echo $seller['purchase_date'] ? date('d M Y', strtotime($seller['purchase_date'])) : 'N/A'; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Address:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['seller_address'] ?? 'N/A'); ?></div>
                    </div>

                    <?php if ($seller['notes']): ?>
                    <div class="info-item">
                        <div class="info-label">Notes:</div>
                        <div class="info-value"><?php echo htmlspecialchars($seller['notes']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Documents -->
                <div class="info-card">
                    <div class="info-card-header">
                        <span><i class="fas fa-paperclip"></i>Attached Documents</span>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Title Deed:</div>
                        <div class="info-value">
                            <?php if ($project['title_deed_path']): ?>
                                <a href="../../<?php echo htmlspecialchars($project['title_deed_path']); ?>" 
                                   target="_blank" 
                                   class="document-preview">
                                    <i class="fas fa-file-pdf me-1"></i> View Document
                                </a>
                            <?php else: ?>
                                <span class="text-muted">Not uploaded</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Survey Plan:</div>
                        <div class="info-value">
                            <?php if ($project['survey_plan_path']): ?>
                                <a href="../../<?php echo htmlspecialchars($project['survey_plan_path']); ?>" 
                                   target="_blank" 
                                   class="document-preview">
                                    <i class="fas fa-file-pdf me-1"></i> View Document
                                </a>
                            <?php else: ?>
                                <span class="text-muted">Not uploaded</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Contract:</div>
                        <div class="info-value">
                            <?php if ($project['contract_attachment_path']): ?>
                                <a href="../../<?php echo htmlspecialchars($project['contract_attachment_path']); ?>" 
                                   target="_blank" 
                                   class="document-preview">
                                    <i class="fas fa-file-pdf me-1"></i> View Document
                                </a>
                            <?php else: ?>
                                <span class="text-muted">Not uploaded</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Coordinates:</div>
                        <div class="info-value">
                            <?php if ($project['coordinates_path']): ?>
                                <a href="../../<?php echo htmlspecialchars($project['coordinates_path']); ?>" 
                                   target="_blank" 
                                   class="document-preview">
                                    <i class="fas fa-file-pdf me-1"></i> View Document
                                </a>
                            <?php else: ?>
                                <span class="text-muted">Not uploaded</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Action Buttons -->
        <div class="row">
            <div class="col-12">
                <div class="info-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <a href="edit.php?id=<?php echo $project_id; ?>" class="btn btn-warning action-button">
                                <i class="fas fa-edit me-1"></i> Edit Project
                            </a>
                            <a href="index.php" class="btn btn-outline-secondary action-button">
                                <i class="fas fa-arrow-left me-1"></i> Back to List
                            </a>
                        </div>
                        <div>
                            <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                                <i class="fas fa-trash me-1"></i> Delete Project
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<script>
function confirmDelete() {
    if (confirm('Are you sure you want to delete this project? This action cannot be undone and will delete all associated plots and data.')) {
        window.location.href = 'delete.php?id=<?php echo $project_id; ?>';
    }
}
</script>

<?php 
require_once '../../includes/footer.php';
?>