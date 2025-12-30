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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    try {
        $conn->beginTransaction();
        
        // Generate lead number
        $lead_number = 'LEAD-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check if lead number exists
        $check = $conn->prepare("SELECT lead_id FROM leads WHERE lead_number = ? AND company_id = ?");
        $check->execute([$lead_number, $company_id]);
        
        while ($check->rowCount() > 0) {
            $lead_number = 'LEAD-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            $check->execute([$lead_number, $company_id]);
        }
        
        // Insert lead
        $stmt = $conn->prepare("
            INSERT INTO leads (
                company_id, lead_number, full_name, email, phone, alternative_phone,
                address, city, country, source, campaign_id, status,
                interested_in, budget_range, preferred_location, preferred_plot_size,
                assigned_to, estimated_value, expected_close_date, lead_score,
                requirements, notes, next_follow_up_date, is_active, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
        ");
        
        $stmt->execute([
            $company_id,
            $lead_number,
            $_POST['full_name'],
            $_POST['email'] ?? null,
            $_POST['phone'],
            $_POST['alternative_phone'] ?? null,
            $_POST['address'] ?? null,
            $_POST['city'] ?? null,
            $_POST['country'] ?? 'Tanzania',
            $_POST['source'],
            !empty($_POST['campaign_id']) ? $_POST['campaign_id'] : null,
            $_POST['status'] ?? 'new',
            $_POST['interested_in'] ?? null,
            $_POST['budget_range'] ?? null,
            $_POST['preferred_location'] ?? null,
            $_POST['preferred_plot_size'] ?? null,
            !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null,
            !empty($_POST['estimated_value']) ? $_POST['estimated_value'] : null,
            !empty($_POST['expected_close_date']) ? $_POST['expected_close_date'] : null,
            $_POST['lead_score'] ?? 5,
            $_POST['requirements'] ?? null,
            $_POST['notes'] ?? null,
            !empty($_POST['next_follow_up_date']) ? $_POST['next_follow_up_date'] : null,
            $user_id
        ]);
        
        $lead_id = $conn->lastInsertId();
        
        $conn->commit();
        
        $_SESSION['success_message'] = 'Lead created successfully!';
        header('Location: leads.php');
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = 'Error creating lead: ' . $e->getMessage();
    }
}

// Fetch campaigns for dropdown
try {
    $campaigns_stmt = $conn->prepare("
        SELECT campaign_id, campaign_name, campaign_code
        FROM campaigns
        WHERE company_id = ? AND is_active = 1
        ORDER BY campaign_name
    ");
    $campaigns_stmt->execute([$company_id]);
    $campaigns = $campaigns_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
}

// Fetch users for assignment
try {
    $users_stmt = $conn->prepare("
        SELECT user_id, first_name, last_name, email
        FROM users
        WHERE company_id = ? AND is_active = 1
        ORDER BY first_name, last_name
    ");
    $users_stmt->execute([$company_id]);
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

$page_title = 'Create New Lead';
require_once '../../includes/header.php';
?>

<style>
.create-lead-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.section-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.section-header h5 {
    margin: 0;
    font-weight: 600;
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

.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.btn {
    border-radius: 8px;
    padding: 0.625rem 1.5rem;
    font-weight: 500;
}

.required-field::after {
    content: ' *';
    color: #dc3545;
}

.info-box {
    background: #f0f9ff;
    border-left: 4px solid #0284c7;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.score-slider {
    width: 100%;
}

.score-display {
    font-size: 2rem;
    font-weight: 700;
    color: #667eea;
    text-align: center;
}

.score-label {
    text-align: center;
    font-size: 0.875rem;
    color: #6b7280;
}
</style>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-plus text-primary me-2"></i>Create New Lead
                </h1>
                <p class="text-muted small mb-0 mt-1">Add a new potential customer to your pipeline</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="leads.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Leads
                    </a>
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
        
        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="info-box">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Lead Management:</strong> Capture essential information about potential customers. All leads can be nurtured through the sales pipeline and converted to customers when ready.
        </div>
        
        <form method="POST" id="createLeadForm">
            <div class="card create-lead-card">
                <div class="card-body p-4">
                    
                    <!-- Personal Information -->
                    <div class="section-header">
                        <h5><i class="fas fa-user me-2"></i>Personal Information</h5>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label required-field">Full Name</label>
                            <input type="text" class="form-control" name="full_name" required 
                                   placeholder="Enter full name">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="email" 
                                   placeholder="email@example.com">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label required-field">Phone Number</label>
                            <input type="text" class="form-control" name="phone" required 
                                   placeholder="e.g., 0712345678">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Alternative Phone</label>
                            <input type="text" class="form-control" name="alternative_phone" 
                                   placeholder="e.g., 0712345678">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city" 
                                   placeholder="e.g., Dar es Salaam">
                        </div>
                        
                        <div class="col-md-8">
                            <label class="form-label">Address</label>
                            <input type="text" class="form-control" name="address" 
                                   placeholder="Street address or location">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Country</label>
                            <input type="text" class="form-control" name="country" 
                                   value="Tanzania" placeholder="Country">
                        </div>
                    </div>
                    
                    <!-- Lead Source & Status -->
                    <div class="section-header">
                        <h5><i class="fas fa-chart-line me-2"></i>Lead Source & Status</h5>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label required-field">Lead Source</label>
                            <select class="form-select" name="source" required>
                                <option value="">-- Select Source --</option>
                                <option value="website">Website</option>
                                <option value="referral">Referral</option>
                                <option value="social_media">Social Media</option>
                                <option value="email_campaign">Email Campaign</option>
                                <option value="cold_call">Cold Call</option>
                                <option value="event">Event</option>
                                <option value="advertisement">Advertisement</option>
                                <option value="partner">Partner</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Campaign</label>
                            <select class="form-select" name="campaign_id">
                                <option value="">-- No Campaign --</option>
                                <?php foreach ($campaigns as $campaign): ?>
                                <option value="<?php echo $campaign['campaign_id']; ?>">
                                    <?php echo htmlspecialchars($campaign['campaign_name']); ?>
                                    (<?php echo htmlspecialchars($campaign['campaign_code']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Lead Status</label>
                            <select class="form-select" name="status">
                                <option value="new" selected>New</option>
                                <option value="contacted">Contacted</option>
                                <option value="qualified">Qualified</option>
                                <option value="proposal">Proposal</option>
                                <option value="negotiation">Negotiation</option>
                                <option value="converted">Converted</option>
                                <option value="lost">Lost</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Assign To</label>
                            <select class="form-select" name="assigned_to">
                                <option value="">-- Not Assigned --</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>">
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                    (<?php echo htmlspecialchars($user['email']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Next Follow-up Date</label>
                            <input type="date" class="form-control" name="next_follow_up_date" 
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <!-- Interest & Requirements -->
                    <div class="section-header">
                        <h5><i class="fas fa-bullseye me-2"></i>Interest & Requirements</h5>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Interested In</label>
                            <select class="form-select" name="interested_in">
                                <option value="">-- Select Interest --</option>
                                <option value="plot_purchase">Plot Purchase</option>
                                <option value="land_services">Land Services</option>
                                <option value="consultation">Consultation</option>
                                <option value="construction">Construction</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Budget Range</label>
                            <input type="text" class="form-control" name="budget_range" 
                                   placeholder="e.g., 10M - 20M TZS">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Preferred Location</label>
                            <input type="text" class="form-control" name="preferred_location" 
                                   placeholder="e.g., Mbezi, Tegeta">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Preferred Plot Size</label>
                            <input type="text" class="form-control" name="preferred_plot_size" 
                                   placeholder="e.g., 500 sqm, 1000 sqm">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Requirements</label>
                            <textarea class="form-control" name="requirements" rows="3" 
                                      placeholder="What are the lead's specific requirements or needs?"></textarea>
                        </div>
                    </div>
                    
                    <!-- Sales Information -->
                    <div class="section-header">
                        <h5><i class="fas fa-dollar-sign me-2"></i>Sales Information</h5>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Estimated Value (TZS)</label>
                            <input type="number" class="form-control" name="estimated_value" 
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Expected Close Date</label>
                            <input type="date" class="form-control" name="expected_close_date" 
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Lead Score (1-10)</label>
                            <div class="score-display" id="scoreDisplay">5</div>
                            <input type="range" class="score-slider" name="lead_score" 
                                   id="leadScore" min="1" max="10" value="5" 
                                   oninput="updateScoreDisplay()">
                            <div class="score-label">
                                <span id="scoreLabel">Medium Priority</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Notes -->
                    <div class="section-header">
                        <h5><i class="fas fa-sticky-note me-2"></i>Additional Notes</h5>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="4" 
                                      placeholder="Any additional information about this lead..."></textarea>
                        </div>
                    </div>
                    
                </div>
                
                <div class="card-footer bg-light">
                    <div class="d-flex justify-content-between">
                        <a href="leads.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create Lead
                        </button>
                    </div>
                </div>
            </div>
        </form>
        
    </div>
</section>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function updateScoreDisplay() {
    const score = document.getElementById('leadScore').value;
    const display = document.getElementById('scoreDisplay');
    const label = document.getElementById('scoreLabel');
    
    display.textContent = score;
    
    if (score <= 3) {
        label.textContent = 'Low Priority';
        display.style.color = '#dc3545';
    } else if (score <= 6) {
        label.textContent = 'Medium Priority';
        display.style.color = '#ffc107';
    } else if (score <= 8) {
        label.textContent = 'High Priority';
        display.style.color = '#0dcaf0';
    } else {
        label.textContent = 'Very High Priority';
        display.style.color = '#198754';
    }
}

// Form validation
document.getElementById('createLeadForm').addEventListener('submit', function(e) {
    const phone = document.querySelector('input[name="phone"]').value;
    const fullName = document.querySelector('input[name="full_name"]').value;
    
    if (fullName.trim().length < 3) {
        e.preventDefault();
        alert('Please enter a valid full name (at least 3 characters)');
        return false;
    }
    
    if (phone.trim().length < 10) {
        e.preventDefault();
        alert('Please enter a valid phone number (at least 10 digits)');
        return false;
    }
    
    return true;
});

// Initialize score display
updateScoreDisplay();
</script>

<?php require_once '../../includes/footer.php'; ?>