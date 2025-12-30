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

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate inputs
    $campaign_name = trim($_POST['campaign_name'] ?? '');
    $campaign_type = $_POST['campaign_type'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $budget = floatval($_POST['budget'] ?? 0);
    $actual_cost = floatval($_POST['actual_cost'] ?? 0);
    $target_audience = trim($_POST['target_audience'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    if (empty($campaign_name)) {
        $errors[] = "Campaign name is required";
    }
    
    if (empty($campaign_type)) {
        $errors[] = "Campaign type is required";
    }
    
    if (empty($start_date)) {
        $errors[] = "Start date is required";
    }
    
    if ($budget < 0) {
        $errors[] = "Budget cannot be negative";
    }
    
    if (!empty($end_date) && strtotime($end_date) < strtotime($start_date)) {
        $errors[] = "End date cannot be before start date";
    }
    
    // If no errors, insert campaign
    if (empty($errors)) {
        try {
            $insert_query = "
                INSERT INTO campaigns (
                    company_id,
                    campaign_name,
                    campaign_type,
                    description,
                    start_date,
                    end_date,
                    budget,
                    actual_cost,
                    target_audience,
                    is_active,
                    created_by,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->execute([
                $company_id,
                $campaign_name,
                $campaign_type,
                $description,
                $start_date,
                !empty($end_date) ? $end_date : null,
                $budget,
                $actual_cost,
                $target_audience,
                $is_active,
                $_SESSION['user_id']
            ]);
            
            $success = "Campaign created successfully!";
            
            // Redirect to campaigns page after 2 seconds
            header("refresh:2;url=campaigns.php");
            
        } catch (PDOException $e) {
            error_log("Error creating campaign: " . $e->getMessage());
            $errors[] = "Failed to create campaign. Please try again.";
        }
    }
}

$page_title = 'Create Marketing Campaign';
require_once '../../includes/header.php';
?>

<style>
.form-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.form-section {
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 2px solid #f0f0f0;
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
}

.section-title i {
    margin-right: 10px;
    color: #17a2b8;
}

.form-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
}

.form-control:focus,
.form-select:focus {
    border-color: #17a2b8;
    box-shadow: 0 0 0 0.2rem rgba(23, 162, 184, 0.25);
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.alert {
    border-radius: 8px;
    padding: 1rem 1.25rem;
}

.btn-lg {
    padding: 0.75rem 2rem;
    font-weight: 600;
}

.character-count {
    font-size: 0.875rem;
    color: #6c757d;
    text-align: right;
    margin-top: 0.25rem;
}

.help-text {
    font-size: 0.875rem;
    color: #6c757d;
    margin-top: 0.25rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-plus-circle text-info me-2"></i>Create Marketing Campaign
                </h1>
                <p class="text-muted small mb-0 mt-1">Add a new marketing campaign</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="campaigns.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Campaigns
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
        <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Please fix the following errors:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Campaign Form -->
        <form method="POST" action="" id="campaignForm">
            <div class="row">
                <div class="col-lg-8">
                    <div class="form-card">
                        
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Basic Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="campaign_name" class="form-label required-field">Campaign Name</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="campaign_name" 
                                           name="campaign_name"
                                           placeholder="e.g., Summer Sale 2025"
                                           value="<?php echo htmlspecialchars($_POST['campaign_name'] ?? ''); ?>"
                                           required
                                           maxlength="200">
                                    <div class="help-text">Enter a descriptive name for your campaign</div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="campaign_type" class="form-label required-field">Campaign Type</label>
                                    <select class="form-select" id="campaign_type" name="campaign_type" required>
                                        <option value="">Select Type</option>
                                        <option value="email" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'email') ? 'selected' : ''; ?>>Email Marketing</option>
                                        <option value="social_media" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'social_media') ? 'selected' : ''; ?>>Social Media</option>
                                        <option value="ppc" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'ppc') ? 'selected' : ''; ?>>PPC Advertising</option>
                                        <option value="event" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'event') ? 'selected' : ''; ?>>Event Marketing</option>
                                        <option value="content" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'content') ? 'selected' : ''; ?>>Content Marketing</option>
                                        <option value="sms" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'sms') ? 'selected' : ''; ?>>SMS Marketing</option>
                                        <option value="print" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'print') ? 'selected' : ''; ?>>Print Media</option>
                                        <option value="radio" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'radio') ? 'selected' : ''; ?>>Radio</option>
                                        <option value="tv" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'tv') ? 'selected' : ''; ?>>Television</option>
                                        <option value="other" <?php echo (isset($_POST['campaign_type']) && $_POST['campaign_type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="target_audience" class="form-label">Target Audience</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="target_audience" 
                                           name="target_audience"
                                           placeholder="e.g., Young professionals, 25-35 years"
                                           value="<?php echo htmlspecialchars($_POST['target_audience'] ?? ''); ?>"
                                           maxlength="200">
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" 
                                              id="description" 
                                              name="description" 
                                              rows="4"
                                              maxlength="1000"
                                              placeholder="Describe the campaign objectives and details..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                    <div class="character-count">
                                        <span id="charCount">0</span> / 1000 characters
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Timeline Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-calendar-alt"></i>
                                Campaign Timeline
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="start_date" class="form-label required-field">Start Date</label>
                                    <input type="date" 
                                           class="form-control" 
                                           id="start_date" 
                                           name="start_date"
                                           value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>"
                                           required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="end_date" class="form-label">End Date</label>
                                    <input type="date" 
                                           class="form-control" 
                                           id="end_date" 
                                           name="end_date"
                                           value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>">
                                    <div class="help-text">Leave empty for ongoing campaigns</div>
                                </div>
                            </div>
                        </div>

                        <!-- Budget Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-money-bill-wave"></i>
                                Budget & Costs
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="budget" class="form-label">Campaign Budget (TSH)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">TSH</span>
                                        <input type="number" 
                                               class="form-control" 
                                               id="budget" 
                                               name="budget"
                                               min="0"
                                               step="0.01"
                                               placeholder="0.00"
                                               value="<?php echo htmlspecialchars($_POST['budget'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="actual_cost" class="form-label">Actual Cost (TSH)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">TSH</span>
                                        <input type="number" 
                                               class="form-control" 
                                               id="actual_cost" 
                                               name="actual_cost"
                                               min="0"
                                               step="0.01"
                                               placeholder="0.00"
                                               value="<?php echo htmlspecialchars($_POST['actual_cost'] ?? ''); ?>">
                                    </div>
                                    <div class="help-text">Amount spent so far</div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <div class="form-card mb-3">
                        <h5 class="section-title">
                            <i class="fas fa-cog"></i>
                            Campaign Status
                        </h5>
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   id="is_active" 
                                   name="is_active"
                                   <?php echo (isset($_POST['is_active']) || !isset($_POST['campaign_name'])) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">
                                <strong>Active Campaign</strong>
                                <div class="help-text">Campaign is currently running</div>
                            </label>
                        </div>
                    </div>

                    <div class="form-card">
                        <h5 class="section-title">
                            <i class="fas fa-lightbulb"></i>
                            Quick Tips
                        </h5>
                        
                        <div class="alert alert-info mb-0">
                            <ul class="mb-0 ps-3">
                                <li class="mb-2">Use clear, descriptive campaign names</li>
                                <li class="mb-2">Set realistic budgets and timelines</li>
                                <li class="mb-2">Define your target audience clearly</li>
                                <li class="mb-2">Track actual costs regularly</li>
                                <li>Link campaigns to leads for better tracking</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between">
                        <a href="campaigns.php" class="btn btn-outline-secondary btn-lg">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-info btn-lg">
                            <i class="fas fa-save me-1"></i> Create Campaign
                        </button>
                    </div>
                </div>
            </div>
        </form>

    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Character counter for description
    const description = document.getElementById('description');
    const charCount = document.getElementById('charCount');
    
    function updateCharCount() {
        charCount.textContent = description.value.length;
    }
    
    description.addEventListener('input', updateCharCount);
    updateCharCount();
    
    // Date validation
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    
    startDate.addEventListener('change', function() {
        endDate.min = this.value;
        if (endDate.value && endDate.value < this.value) {
            endDate.value = '';
        }
    });
    
    // Budget validation
    const budget = document.getElementById('budget');
    const actualCost = document.getElementById('actual_cost');
    
    actualCost.addEventListener('change', function() {
        const budgetValue = parseFloat(budget.value) || 0;
        const actualValue = parseFloat(this.value) || 0;
        
        if (actualValue > budgetValue && budgetValue > 0) {
            if (confirm('Actual cost exceeds budget. Do you want to continue?')) {
                // Continue
            } else {
                this.value = budgetValue;
            }
        }
    });
    
    // Form validation
    const form = document.getElementById('campaignForm');
    form.addEventListener('submit', function(e) {
        const campaignName = document.getElementById('campaign_name').value.trim();
        const campaignType = document.getElementById('campaign_type').value;
        const startDateVal = document.getElementById('start_date').value;
        
        if (!campaignName) {
            e.preventDefault();
            alert('Please enter a campaign name');
            return false;
        }
        
        if (!campaignType) {
            e.preventDefault();
            alert('Please select a campaign type');
            return false;
        }
        
        if (!startDateVal) {
            e.preventDefault();
            alert('Please select a start date');
            return false;
        }
        
        return true;
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>