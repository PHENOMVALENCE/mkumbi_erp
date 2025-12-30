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

// Fetch statistics
try {
    $stats_query = "
        SELECT 
            COUNT(*) as total_services,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_services,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_services
        FROM service_types
        WHERE company_id = ? AND is_active = 1
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get requests count
    $requests_query = "
        SELECT 
            COUNT(*) as total_requests,
            COALESCE(SUM(final_price), 0) as total_revenue
        FROM service_requests
        WHERE company_id = ? AND status = 'completed'
    ";
    $stmt = $conn->prepare($requests_query);
    $stmt->execute([$company_id]);
    $requests_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = array_merge($stats ?: [], $requests_stats ?: []);
} catch (PDOException $e) {
    error_log("Error fetching service type stats: " . $e->getMessage());
    $stats = [
        'total_services' => 0,
        'active_services' => 0,
        'inactive_services' => 0,
        'total_requests' => 0,
        'total_revenue' => 0
    ];
}

// Build filter conditions
$where_conditions = ["st.company_id = ?"];
$params = [$company_id];

if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
    $where_conditions[] = "st.is_active = ?";
    $params[] = $_GET['is_active'];
}

if (!empty($_GET['category'])) {
    $where_conditions[] = "st.service_category = ?";
    $params[] = $_GET['category'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(st.service_name LIKE ? OR st.service_code LIKE ? OR st.description LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// ✅ FIXED: Correct table aliases and column names
try {
    $services_query = "
        SELECT 
            st.*,
            COUNT(DISTINCT sr.service_request_id) as request_count,
            COALESCE(SUM(CASE WHEN sr.status = 'completed' THEN sr.final_price ELSE 0 END), 0) as total_revenue
        FROM service_types st
        LEFT JOIN service_requests sr ON st.service_type_id = sr.service_type_id AND sr.company_id = ?
        WHERE $where_clause
        GROUP BY st.service_type_id
        ORDER BY st.service_name ASC
    ";
    // Add company_id parameter for JOIN condition
    $params[] = $company_id;
    $stmt = $conn->prepare($services_query);
    $stmt->execute($params);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching service types: " . $e->getMessage());
    error_log("Query: " . $services_query);
    error_log("Params: " . print_r($params, true));
    $services = [];
}

$page_title = 'Service Types';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 1.75rem;
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

.service-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: transform 0.2s, box-shadow 0.2s;
    border: 1px solid #e9ecef;
}

.service-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
}

.service-card.active { border-left: 4px solid #28a745; }
.service-card.inactive { border-left: 4px solid #dc3545; }

.service-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-right: 15px;
    flex-shrink: 0;
}

.service-name {
    font-size: 1.2rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
    line-height: 1.3;
}

.service-code {
    background: #e9ecef;
    color: #495057;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}

.category-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: capitalize;
}

.category-badge.land_evaluation { background: #d1ecf1; color: #0c5460; }
.category-badge.title_processing { background: #fff3cd; color: #856404; }
.category-badge.consultation { background: #cfe2ff; color: #084298; }
.category-badge.construction { background: #f8d7da; color: #721c24; }
.category-badge.survey { background: #d4edda; color: #155724; }
.category-badge.legal { background: #e2e3e5; color: #383d41; }
.category-badge.other { background: #e9ecef; color: #495057; }

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.inactive {
    background: #f8d7da;
    color: #721c24;
}

.price-display {
    font-size: 1.3rem;
    font-weight: 700;
    color: #28a745;
}

.stat-box {
    background: #f8f9fa;
    padding: 0.75rem;
    border-radius: 8px;
    text-align: center;
    margin-top: 1rem;
    border: 1px solid #e9ecef;
}

.stat-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: #007bff;
}

.stat-label {
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    opacity: 0.5;
    margin-bottom: 1rem;
    display: block;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-briefcase text-primary me-2"></i>Service Types
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage service offerings and pricing</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="requests.php" class="btn btn-outline-info me-2">
                        <i class="fas fa-clipboard-list me-1"></i> Service Requests
                    </a>
                    <a href="add-type.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> New Service Type
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_services']); ?></div>
                    <div class="stats-label">Total Services</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['active_services']); ?></div>
                    <div class="stats-label">Active</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_requests']); ?></div>
                    <div class="stats-label">Total Requests</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number">TSH <?php echo number_format((float)($stats['total_revenue'] ?? 0) / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Revenue</div>
                </div>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>Filter Services
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Search</label>
                        <input type="text" 
                               name="search" 
                               class="form-control" 
                               placeholder="Service name, code, or description..."
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Category</label>
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            <option value="land_evaluation" <?php echo (isset($_GET['category']) && $_GET['category'] == 'land_evaluation') ? 'selected' : ''; ?>>Land Evaluation</option>
                            <option value="title_processing" <?php echo (isset($_GET['category']) && $_GET['category'] == 'title_processing') ? 'selected' : ''; ?>>Title Processing</option>
                            <option value="consultation" <?php echo (isset($_GET['category']) && $_GET['category'] == 'consultation') ? 'selected' : ''; ?>>Consultation</option>
                            <option value="construction" <?php echo (isset($_GET['category']) && $_GET['category'] == 'construction') ? 'selected' : ''; ?>>Construction</option>
                            <option value="survey" <?php echo (isset($_GET['category']) && $_GET['category'] == 'survey') ? 'selected' : ''; ?>>Survey</option>
                            <option value="legal" <?php echo (isset($_GET['category']) && $_GET['category'] == 'legal') ? 'selected' : ''; ?>>Legal</option>
                            <option value="other" <?php echo (isset($_GET['category']) && $_GET['category'] == 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Status</label>
                        <select name="is_active" class="form-select">
                            <option value="">All Status</option>
                            <option value="1" <?php echo (isset($_GET['is_active']) && $_GET['is_active'] == '1') ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo (isset($_GET['is_active']) && $_GET['is_active'] == '0') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i> Filter
                        </button>
                        <a href="types.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo me-1"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Services Grid -->
        <?php if (empty($services)): ?>
        <div class="empty-state">
            <i class="fas fa-briefcase"></i>
            <h5>No Service Types Found</h5>
            <p class="mb-4">Start by creating your first service type to offer to customers.</p>
            <a href="add-type.php" class="btn btn-primary btn-lg">
                <i class="fas fa-plus-circle me-2"></i>Create First Service Type
            </a>
        </div>
        <?php else: ?>
        <div class="row g-4">
            <?php foreach ($services as $service): ?>
            <div class="col-lg-6 col-xl-4">
                <div class="service-card <?php echo $service['is_active'] ? 'active' : 'inactive'; ?>">
                    <div class="d-flex align-items-start mb-3">
                        <div class="service-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="service-name"><?php echo htmlspecialchars($service['service_name']); ?></div>
                                    <?php if (!empty($service['service_code'])): ?>
                                    <span class="service-code"><?php echo htmlspecialchars($service['service_code']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="status-badge <?php echo $service['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <span class="category-badge <?php echo htmlspecialchars($service['service_category']); ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $service['service_category'])); ?>
                        </span>
                    </div>

                    <?php if (!empty($service['description'])): ?>
                    <p class="text-muted small mb-3">
                        <?php echo htmlspecialchars(substr($service['description'], 0, 120)) . (strlen($service['description']) > 120 ? '...' : ''); ?>
                    </p>
                    <?php endif; ?>

                    <div class="border-top pt-3 mb-3">
                        <?php if (!empty($service['base_price'])): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Base Price:</span>
                            <span class="price-display">
                                TSH <?php echo number_format((float)$service['base_price'], 0); ?>
                                <?php if (!empty($service['price_unit'])): ?>
                                <small class="text-muted">/ <?php echo htmlspecialchars($service['price_unit']); ?></small>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($service['estimated_duration_days'])): ?>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Duration:</span>
                            <span class="fw-bold"><?php echo (int)$service['estimated_duration_days']; ?> days</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <div class="stat-box">
                                <div class="stat-value"><?php echo number_format((int)$service['request_count']); ?></div>
                                <div class="stat-label">Requests</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-box">
                                <div class="stat-value">TSH <?php echo number_format((float)$service['total_revenue'] / 1000, 0); ?>K</div>
                                <div class="stat-label">Revenue</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 pt-3 border-top d-flex justify-content-between align-items-center">
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="view-type.php?id=<?php echo $service['service_type_id']; ?>" 
                               class="btn btn-outline-primary"
                               title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="edit-type.php?id=<?php echo $service['service_type_id']; ?>" 
                               class="btn btn-outline-warning"
                               title="Edit Service">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if ($service['is_active']): ?>
                            <a href="toggle-status.php?id=<?php echo $service['service_type_id']; ?>&status=0" 
                               class="btn btn-outline-danger"
                               title="Deactivate"
                               onclick="return confirm('Deactivate this service type?')">
                                <i class="fas fa-toggle-off"></i>
                            </a>
                            <?php else: ?>
                            <a href="toggle-status.php?id=<?php echo $service['service_type_id']; ?>&status=1" 
                               class="btn btn-outline-success"
                               title="Activate"
                               onclick="return confirm('Activate this service type?')">
                                <i class="fas fa-toggle-on"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <a href="../services/create.php?service_type_id=<?php echo $service['service_type_id']; ?>" 
                           class="btn btn-sm btn-success">
                            <i class="fas fa-plus me-1"></i> New Request
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php 
require_once '../../includes/footer.php';
?>