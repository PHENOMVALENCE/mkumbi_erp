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

$page_title = 'Add Service Type';
require_once '../../includes/header.php';
?>

<style>
.service-card {
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

.code-preview {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: #007bff;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-plus-circle text-info me-2"></i>Add Service Type
                </h1>
                <p class="text-muted small mb-0 mt-1">Create a new service offering</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="types.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Service Types
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

                <div class="service-card">
                    <form action="process-type.php" method="POST" id="serviceTypeForm">
                        
                        <!-- Basic Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Basic Information
                            </div>
                            
                            <div class="alert-info-custom">
                                <i class="fas fa-lightbulb me-2"></i>
                                <strong>Tip:</strong> Create clear service types that match your business offerings. The service code will be auto-generated from the name.
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-8">
                                    <label class="form-label fw-bold">Service Name *</label>
                                    <input type="text" 
                                           name="service_name" 
                                           id="serviceName"
                                           class="form-control" 
                                           placeholder="e.g., Land Survey and Mapping"
                                           required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Service Code</label>
                                    <input type="text" 
                                           name="service_code" 
                                           id="serviceCode"
                                           class="form-control" 
                                           placeholder="Auto-generated"
                                           readonly>
                                    <small class="text-muted">Auto-generated from name</small>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold">Service Category *</label>
                                    <select name="service_category" class="form-select" required>
                                        <option value="">-- Select Category --</option>
                                        <option value="land_evaluation">Land Evaluation</option>
                                        <option value="title_processing">Title Processing</option>
                                        <option value="consultation">Consultation</option>
                                        <option value="construction">Construction</option>
                                        <option value="survey">Survey</option>
                                        <option value="legal">Legal</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Description</label>
                                <textarea name="description" 
                                          class="form-control" 
                                          rows="4" 
                                          placeholder="Detailed description of what this service includes, deliverables, scope of work..."></textarea>
                            </div>
                        </div>

                        <!-- Pricing Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-dollar-sign"></i>
                                Pricing Information
                            </div>

                            <div class="alert-info-custom">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Note:</strong> Base price is optional. You can quote prices individually for each service request. Use price unit to specify how pricing is calculated (e.g., per square meter, per plot, flat fee).
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Base Price (TSH)</label>
                                    <input type="number" 
                                           name="base_price" 
                                           class="form-control" 
                                           min="0"
                                           step="0.01"
                                           placeholder="0.00">
                                    <small class="text-muted">Leave blank if pricing varies by request</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Price Unit</label>
                                    <select name="price_unit" class="form-select">
                                        <option value="">-- Select Unit --</option>
                                        <option value="flat fee">Flat Fee</option>
                                        <option value="per sqm">Per Square Meter</option>
                                        <option value="per plot">Per Plot</option>
                                        <option value="per acre">Per Acre</option>
                                        <option value="per hour">Per Hour</option>
                                        <option value="per day">Per Day</option>
                                        <option value="per project">Per Project</option>
                                        <option value="per document">Per Document</option>
                                        <option value="other">Other</option>
                                    </select>
                                    <small class="text-muted">How the price is calculated</small>
                                </div>
                            </div>
                        </div>

                        <!-- Service Details -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-clipboard-list"></i>
                                Service Details
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Estimated Duration (Days)</label>
                                    <input type="number" 
                                           name="estimated_duration_days" 
                                           class="form-control" 
                                           min="1"
                                           step="1"
                                           placeholder="e.g., 7">
                                    <small class="text-muted">Typical time to complete this service</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Status</label>
                                    <div class="mt-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" 
                                                   type="checkbox" 
                                                   name="is_active" 
                                                   id="isActive"
                                                   value="1"
                                                   checked>
                                            <label class="form-check-label" for="isActive">
                                                <strong>Active</strong> - Service is available for new requests
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Examples Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-lightbulb"></i>
                                Service Examples
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="card">
                                        <div class="card-header bg-info text-white">
                                            <strong>Example 1: Land Survey</strong>
                                        </div>
                                        <div class="card-body small">
                                            <p><strong>Name:</strong> Professional Land Survey</p>
                                            <p><strong>Category:</strong> Survey</p>
                                            <p><strong>Base Price:</strong> TSH 150,000</p>
                                            <p><strong>Price Unit:</strong> Per Plot</p>
                                            <p><strong>Duration:</strong> 5 days</p>
                                            <p class="mb-0"><strong>Description:</strong> Comprehensive land surveying including boundary marking, GPS coordinates, topographic mapping, and survey report.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="card">
                                        <div class="card-header bg-success text-white">
                                            <strong>Example 2: Title Processing</strong>
                                        </div>
                                        <div class="card-body small">
                                            <p><strong>Name:</strong> Title Deed Processing</p>
                                            <p><strong>Category:</strong> Title Processing</p>
                                            <p><strong>Base Price:</strong> (Leave blank - varies)</p>
                                            <p><strong>Price Unit:</strong> Per Document</p>
                                            <p><strong>Duration:</strong> 30 days</p>
                                            <p class="mb-0"><strong>Description:</strong> Complete title deed processing including application, documentation, government approvals, and registration.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="types.php" class="btn btn-secondary">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-info btn-lg">
                                <i class="fas fa-save me-1"></i> Save Service Type
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
    const serviceNameInput = document.getElementById('serviceName');
    const serviceCodeInput = document.getElementById('serviceCode');

    // Auto-generate service code from name
    serviceNameInput.addEventListener('input', function() {
        const name = this.value;
        if (name) {
            // Take first 3 letters of each word, uppercase
            const words = name.split(' ');
            let code = '';
            
            for (let i = 0; i < Math.min(words.length, 3); i++) {
                const word = words[i].trim();
                if (word) {
                    code += word.substring(0, 3);
                }
            }
            
            // Add random 3-digit number
            const randomNum = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            code = (code.toUpperCase().substring(0, 10) + randomNum).substring(0, 13);
            
            serviceCodeInput.value = code;
        } else {
            serviceCodeInput.value = '';
        }
    });

    // Form validation
    document.getElementById('serviceTypeForm').addEventListener('submit', function(e) {
        const serviceName = document.querySelector('input[name="service_name"]').value;
        const category = document.querySelector('select[name="service_category"]').value;

        if (!serviceName || !category) {
            e.preventDefault();
            alert('Please fill in all required fields (Service Name and Category)!');
            return false;
        }

        return confirm('Are you sure you want to save this service type?');
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>