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

// Fetch users for assignment
try {
    $users_query = "SELECT user_id, full_name FROM users WHERE company_id = ? AND is_active = 1 ORDER BY full_name";
    $stmt = $conn->prepare($users_query);
    $stmt->execute([$company_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// Fetch campaigns
try {
    $campaigns_query = "SELECT campaign_id, campaign_name FROM campaigns WHERE company_id = ? AND is_active = 1 ORDER BY campaign_name";
    $stmt = $conn->prepare($campaigns_query);
    $stmt->execute([$company_id]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
}

$page_title = 'Create Lead';
require_once '../../includes/header.php';
?>

<style>
.lead-card {
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
    border-left: 4px solid #007bff;
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
    color: #007bff;
}

.alert-info-custom {
    background: #d1ecf1;
    border: 1px solid #0c5460;
    border-left: 4px solid #17a2b8;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.score-indicator {
    display: flex;
    gap: 5px;
    align-items: center;
}

.score-bar {
    flex: 1;
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.3s;
}

.score-bar.active {
    background: #ffc107;
}

.score-bar:hover {
    background: #ffca28;
}

.score-value {
    font-weight: 700;
    color: #007bff;
    min-width: 30px;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-plus text-primary me-2"></i>Create New Lead
                </h1>
                <p class="text-muted small mb-0 mt-1">Add a new sales lead to your pipeline</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="leads.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Leads
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

                <div class="lead-card">
                    <form action="process-lead.php" method="POST" id="leadForm">
                        
                        <!-- Company Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-building"></i>
                                Company Information
                            </div>
                            
                            <div class="alert-info-custom">
                                <i class="fas fa-lightbulb me-2"></i>
                                <strong>Tip:</strong> Provide as much information as possible to help qualify and convert this lead.
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-8">
                                    <label class="form-label fw-bold">Company Name *</label>
                                    <input type="text" 
                                           name="company_name" 
                                           class="form-control" 
                                           placeholder="Enter company or business name"
                                           required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Industry</label>
                                    <input type="text" 
                                           name="industry" 
                                           class="form-control" 
                                           placeholder="e.g., Real Estate, Retail">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Company Size</label>
                                    <select name="company_size" class="form-select">
                                        <option value="">-- Select Size --</option>
                                        <option value="1-10">1-10 employees</option>
                                        <option value="11-50">11-50 employees</option>
                                        <option value="51-200">51-200 employees</option>
                                        <option value="201-500">201-500 employees</option>
                                        <option value="500+">500+ employees</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Website</label>
                                    <input type="url" 
                                           name="website" 
                                           class="form-control" 
                                           placeholder="https://www.example.com">
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-user"></i>
                                Contact Information
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Contact Person *</label>
                                    <input type="text" 
                                           name="contact_person" 
                                           class="form-control" 
                                           placeholder="Full name"
                                           required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Job Title</label>
                                    <input type="text" 
                                           name="job_title" 
                                           class="form-control" 
                                           placeholder="e.g., CEO, Manager">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Email Address *</label>
                                    <input type="email" 
                                           name="email" 
                                           class="form-control" 
                                           placeholder="contact@example.com"
                                           required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Phone Number *</label>
                                    <input type="tel" 
                                           name="phone" 
                                           class="form-control" 
                                           placeholder="+255 XXX XXX XXX"
                                           required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Address</label>
                                <textarea name="address" 
                                          class="form-control" 
                                          rows="2" 
                                          placeholder="Company address"></textarea>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">City</label>
                                    <input type="text" 
                                           name="city" 
                                           class="form-control" 
                                           placeholder="e.g., Dar es Salaam">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Country</label>
                                    <input type="text" 
                                           name="country" 
                                           class="form-control" 
                                           value="Tanzania">
                                </div>
                            </div>
                        </div>

                        <!-- Lead Details -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Lead Details
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Lead Source *</label>
                                    <select name="source" class="form-select" required>
                                        <option value="">-- Select Source --</option>
                                        <option value="website">Website</option>
                                        <option value="referral">Referral</option>
                                        <option value="social_media">Social Media</option>
                                        <option value="email_campaign">Email Campaign</option>
                                        <option value="cold_call">Cold Call</option>
                                        <option value="event">Event/Trade Show</option>
                                        <option value="advertisement">Advertisement</option>
                                        <option value="partner">Partner</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Campaign</label>
                                    <select name="campaign_id" class="form-select">
                                        <option value="">-- No Campaign --</option>
                                        <?php foreach ($campaigns as $campaign): ?>
                                        <option value="<?php echo $campaign['campaign_id']; ?>">
                                            <?php echo htmlspecialchars($campaign['campaign_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="new" selected>New</option>
                                        <option value="contacted">Contacted</option>
                                        <option value="qualified">Qualified</option>
                                        <option value="converted">Converted</option>
                                        <option value="lost">Lost</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Assigned To</label>
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
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Estimated Value (TSH)</label>
                                    <input type="number" 
                                           name="estimated_value" 
                                           class="form-control" 
                                           min="0"
                                           step="0.01"
                                           placeholder="0.00">
                                    <small class="text-muted">Potential deal value</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Expected Close Date</label>
                                    <input type="date" 
                                           name="expected_close_date" 
                                           class="form-control"
                                           min="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Lead Score (1-10)</label>
                                <input type="hidden" name="lead_score" id="leadScoreValue" value="5">
                                <div class="score-indicator" id="scoreIndicator">
                                    <div class="score-bar" data-score="1"></div>
                                    <div class="score-bar" data-score="2"></div>
                                    <div class="score-bar" data-score="3"></div>
                                    <div class="score-bar" data-score="4"></div>
                                    <div class="score-bar active" data-score="5"></div>
                                    <div class="score-bar" data-score="6"></div>
                                    <div class="score-bar" data-score="7"></div>
                                    <div class="score-bar" data-score="8"></div>
                                    <div class="score-bar" data-score="9"></div>
                                    <div class="score-bar" data-score="10"></div>
                                    <span class="score-value" id="scoreDisplay">5</span>
                                </div>
                                <small class="text-muted">Rate the quality of this lead (1=Low, 10=High)</small>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-sticky-note"></i>
                                Additional Information
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Requirements / Needs</label>
                                <textarea name="requirements" 
                                          class="form-control" 
                                          rows="3" 
                                          placeholder="What are they looking for? What problems do they need solved?"></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Notes</label>
                                <textarea name="notes" 
                                          class="form-control" 
                                          rows="3" 
                                          placeholder="Any additional information about this lead..."></textarea>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="leads.php" class="btn btn-secondary">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save me-1"></i> Save Lead
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
    // Lead score functionality
    const scoreBars = document.querySelectorAll('.score-bar');
    const scoreValue = document.getElementById('leadScoreValue');
    const scoreDisplay = document.getElementById('scoreDisplay');

    scoreBars.forEach(bar => {
        bar.addEventListener('click', function() {
            const score = parseInt(this.dataset.score);
            scoreValue.value = score;
            scoreDisplay.textContent = score;

            // Update visual indicators
            scoreBars.forEach(b => {
                const barScore = parseInt(b.dataset.score);
                if (barScore <= score) {
                    b.classList.add('active');
                } else {
                    b.classList.remove('active');
                }
            });
        });
    });

    // Form validation
    document.getElementById('leadForm').addEventListener('submit', function(e) {
        const email = document.querySelector('input[name="email"]').value;
        const phone = document.querySelector('input[name="phone"]').value;

        // Basic email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            e.preventDefault();
            alert('Please enter a valid email address!');
            return false;
        }

        return confirm('Are you sure you want to save this lead?');
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>