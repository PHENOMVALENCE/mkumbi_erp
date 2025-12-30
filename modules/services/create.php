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

// Fetch service types
try {
    $types_query = "SELECT * FROM service_types WHERE company_id = ? AND is_active = 1 ORDER BY service_name";
    $stmt = $conn->prepare($types_query);
    $stmt->execute([$company_id]);
    $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $service_types = [];
}

// Fetch customers
try {
    $customers_query = "SELECT customer_id, full_name, phone, email FROM customers WHERE company_id = ? ORDER BY full_name";
    $stmt = $conn->prepare($customers_query);
    $stmt->execute([$company_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Fetch plots
try {
    $plots_query = "SELECT p.plot_id, p.plot_number, p.block_number, pr.project_name 
                    FROM plots p
                    INNER JOIN projects pr ON p.project_id = pr.project_id
                    WHERE p.company_id = ?
                    ORDER BY pr.project_name, p.plot_number";
    $stmt = $conn->prepare($plots_query);
    $stmt->execute([$company_id]);
    $plots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $plots = [];
}

// Fetch projects
try {
    $projects_query = "SELECT project_id, project_name FROM projects WHERE company_id = ? AND is_active = 1 ORDER BY project_name";
    $stmt = $conn->prepare($projects_query);
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $projects = [];
}

// Fetch users for assignment
try {
    $users_query = "SELECT user_id, full_name FROM users WHERE company_id = ? AND is_active = 1 ORDER BY full_name";
    $stmt = $conn->prepare($users_query);
    $stmt->execute([$company_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// Pre-select service type if provided
$selected_service_type_id = $_GET['service_type_id'] ?? null;
$selected_service_type = null;
if ($selected_service_type_id) {
    foreach ($service_types as $type) {
        if ($type['service_type_id'] == $selected_service_type_id) {
            $selected_service_type = $type;
            break;
        }
    }
}

$page_title = 'Create Service Request';
require_once '../../includes/header.php';
?>

<style>
.request-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.form-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    border-left: 4px solid #17a2b8;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
}

.section-title i {
    margin-right: 10px;
    color: #17a2b8;
}

.alert-info-custom {
    background: #d1ecf1;
    border: 1px solid #0c5460;
    border-left: 4px solid #17a2b8;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.service-details {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    display: none;
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
    width: 150px;
    flex-shrink: 0;
}

.info-value {
    color: #212529;
    flex: 1;
}

.price-highlight {
    font-size: 1.2rem;
    font-weight: 700;
    color: #28a745;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-plus-circle text-info me-2"></i>Create Service Request
                </h1>
                <p class="text-muted small mb-0 mt-1">Submit a new service request</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="requests.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Requests
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-lg-10">

                <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php 
                    echo htmlspecialchars($_SESSION['error_message']); 
                    unset($_SESSION['error_message']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="request-card">
                    <form action="process-request.php" method="POST" id="requestForm">
                        
                        <!-- Request Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Request Information
                            </div>
                            
                            <div class="alert-info-custom">
                                <i class="fas fa-lightbulb me-2"></i>
                                <strong>Tip:</strong> Select the service type first to see pricing and duration estimates.
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Request Date *</label>
                                    <input type="date" 
                                           name="request_date" 
                                           class="form-control" 
                                           value="<?php echo date('Y-m-d'); ?>"
                                           required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Service Type *</label>
                                    <select name="service_type_id" id="serviceTypeSelect" class="form-select" required>
                                        <option value="">-- Select Service Type --</option>
                                        <?php foreach ($service_types as $type): ?>
                                        <option value="<?php echo $type['service_type_id']; ?>"
                                                <?php echo ($selected_service_type_id == $type['service_type_id']) ? 'selected' : ''; ?>
                                                data-base-price="<?php echo $type['base_price'] ?? 0; ?>"
                                                data-price-unit="<?php echo htmlspecialchars($type['price_unit'] ?? ''); ?>"
                                                data-duration="<?php echo $type['estimated_duration_days'] ?? ''; ?>"
                                                data-category="<?php echo htmlspecialchars($type['service_category']); ?>"
                                                data-description="<?php echo htmlspecialchars($type['description'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($type['service_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div id="serviceDetails" class="service-details" <?php echo $selected_service_type ? 'style="display:block;"' : ''; ?>>
                                <div class="info-row">
                                    <div class="info-label">Category:</div>
                                    <div class="info-value" id="detailCategory">
                                        <?php echo $selected_service_type ? ucfirst(str_replace('_', ' ', $selected_service_type['service_category'])) : ''; ?>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Base Price:</div>
                                    <div class="info-value price-highlight" id="detailPrice">
                                        <?php echo $selected_service_type && $selected_service_type['base_price'] ? 'TSH ' . number_format($selected_service_type['base_price'], 0) : 'On Request'; ?>
                                    </div>
                                </div>
                                <div class="info-row" id="priceUnitRow" style="<?php echo ($selected_service_type && $selected_service_type['price_unit']) ? '' : 'display:none;'; ?>">
                                    <div class="info-label">Price Unit:</div>
                                    <div class="info-value" id="detailPriceUnit">
                                        <?php echo $selected_service_type ? htmlspecialchars($selected_service_type['price_unit'] ?? '') : ''; ?>
                                    </div>
                                </div>
                                <div class="info-row" id="durationRow" style="<?php echo ($selected_service_type && $selected_service_type['estimated_duration_days']) ? '' : 'display:none;'; ?>">
                                    <div class="info-label">Est. Duration:</div>
                                    <div class="info-value" id="detailDuration">
                                        <?php echo $selected_service_type && $selected_service_type['estimated_duration_days'] ? $selected_service_type['estimated_duration_days'] . ' days' : ''; ?>
                                    </div>
                                </div>
                                <div class="info-row" id="descriptionRow" style="<?php echo ($selected_service_type && $selected_service_type['description']) ? '' : 'display:none;'; ?>">
                                    <div class="info-label">Description:</div>
                                    <div class="info-value" id="detailDescription">
                                        <?php echo $selected_service_type ? htmlspecialchars($selected_service_type['description'] ?? '') : ''; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-user"></i>
                                Customer Information
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold">Select Customer (Optional)</label>
                                    <select name="customer_id" id="customerSelect" class="form-select">
                                        <option value="">-- Select Customer --</option>
                                        <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo $customer['customer_id']; ?>">
                                            <?php echo htmlspecialchars($customer['full_name']); ?> - <?php echo htmlspecialchars($customer['phone']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Leave blank if this is a general service request</small>
                                </div>
                            </div>
                        </div>

                        <!-- Service Details -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-clipboard"></i>
                                Service Details
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Service Description *</label>
                                <textarea name="service_description" 
                                          class="form-control" 
                                          rows="4" 
                                          placeholder="Describe what service you need in detail..."
                                          required></textarea>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Plot Size (if applicable)</label>
                                    <div class="input-group">
                                        <input type="number" 
                                               name="plot_size" 
                                               class="form-control" 
                                               min="0"
                                               step="0.01"
                                               placeholder="0.00">
                                        <span class="input-group-text">sqm</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Related Plot</label>
                                    <select name="plot_id" class="form-select">
                                        <option value="">-- No Plot --</option>
                                        <?php foreach ($plots as $plot): ?>
                                        <option value="<?php echo $plot['plot_id']; ?>">
                                            <?php echo htmlspecialchars($plot['project_name']); ?> - Plot <?php echo htmlspecialchars($plot['plot_number']); ?>
                                            <?php if (!empty($plot['block_number'])): ?>
                                            (Block <?php echo htmlspecialchars($plot['block_number']); ?>)
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Related Project</label>
                                    <select name="project_id" class="form-select">
                                        <option value="">-- No Project --</option>
                                        <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo $project['project_id']; ?>">
                                            <?php echo htmlspecialchars($project['project_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Location Details</label>
                                <textarea name="location_details" 
                                          class="form-control" 
                                          rows="2" 
                                          placeholder="Specific location information, GPS coordinates, landmarks..."></textarea>
                            </div>
                        </div>

                        <!-- Scheduling -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-calendar"></i>
                                Scheduling & Assignment
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Requested Start Date</label>
                                    <input type="date" 
                                           name="requested_start_date" 
                                           class="form-control"
                                           min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Expected Completion Date</label>
                                    <input type="date" 
                                           name="expected_completion_date" 
                                           class="form-control"
                                           min="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Assign To</label>
                                    <select name="assigned_to" class="form-select">
                                        <option value="">-- Unassigned --</option>
                                        <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['user_id']; ?>"
                                                <?php echo ($user['user_id'] == $user_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="pending" selected>Pending</option>
                                        <option value="quoted">Quoted</option>
                                        <option value="approved">Approved</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-sticky-note"></i>
                                Additional Information
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Remarks / Special Instructions</label>
                                <textarea name="remarks" 
                                          class="form-control" 
                                          rows="3" 
                                          placeholder="Any additional information, special requirements, or notes..."></textarea>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="requests.php" class="btn btn-secondary">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-info btn-lg">
                                <i class="fas fa-paper-plane me-1"></i> Submit Request
                            </button>
                        </div>

                    </form>
                </div>

            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const serviceTypeSelect = document.getElementById('serviceTypeSelect');
    const serviceDetails = document.getElementById('serviceDetails');

    serviceTypeSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        
        if (this.value) {
            const basePrice = parseFloat(selectedOption.dataset.basePrice) || 0;
            const priceUnit = selectedOption.dataset.priceUnit || '';
            const duration = selectedOption.dataset.duration || '';
            const category = selectedOption.dataset.category || '';
            const description = selectedOption.dataset.description || '';

            document.getElementById('detailCategory').textContent = category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            document.getElementById('detailPrice').textContent = basePrice > 0 ? 'TSH ' + basePrice.toLocaleString() : 'On Request';
            document.getElementById('detailPriceUnit').textContent = priceUnit;
            document.getElementById('detailDuration').textContent = duration ? duration + ' days' : '';
            document.getElementById('detailDescription').textContent = description;

            // Show/hide optional rows
            document.getElementById('priceUnitRow').style.display = priceUnit ? 'flex' : 'none';
            document.getElementById('durationRow').style.display = duration ? 'flex' : 'none';
            document.getElementById('descriptionRow').style.display = description ? 'flex' : 'none';

            serviceDetails.style.display = 'block';
        } else {
            serviceDetails.style.display = 'none';
        }
    });

    // Form validation
    document.getElementById('requestForm').addEventListener('submit', function(e) {
        return confirm('Are you sure you want to submit this service request?');
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>