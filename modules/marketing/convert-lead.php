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

// Get lead ID from URL
$lead_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$lead_id) {
    header('Location: leads.php');
    exit;
}

// Fetch lead details
try {
    $lead_query = "
        SELECT * FROM leads 
        WHERE lead_id = ? AND company_id = ?
    ";
    $stmt = $conn->prepare($lead_query);
    $stmt->execute([$lead_id, $company_id]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lead) {
        header('Location: leads.php');
        exit;
    }
    
    // Check if already converted
    if (!empty($lead['converted_to_customer_id'])) {
        $_SESSION['error'] = "This lead has already been converted to a customer.";
        header('Location: view-lead.php?id=' . $lead_id);
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching lead: " . $e->getMessage());
    $errors[] = "Failed to load lead details.";
}

// Fetch regions for dropdown
try {
    $regions_query = "SELECT region_id, region_name FROM regions WHERE company_id = ? AND is_active = 1 ORDER BY region_name";
    $stmt = $conn->prepare($regions_query);
    $stmt->execute([$company_id]);
    $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching regions: " . $e->getMessage());
    $regions = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get form data
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $alternative_phone = trim($_POST['alternative_phone'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $passport_number = trim($_POST['passport_number'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $nationality = trim($_POST['nationality'] ?? 'Tanzanian');
    $occupation = trim($_POST['occupation'] ?? '');
    
    // Address information
    $region_id = intval($_POST['region_id'] ?? 0);
    $district = trim($_POST['district'] ?? '');
    $ward = trim($_POST['ward'] ?? '');
    $village = trim($_POST['village'] ?? '');
    $street_address = trim($_POST['street_address'] ?? '');
    $postal_address = trim($_POST['postal_address'] ?? '');
    
    // Next of kin
    $next_of_kin_name = trim($_POST['next_of_kin_name'] ?? '');
    $next_of_kin_phone = trim($_POST['next_of_kin_phone'] ?? '');
    $next_of_kin_relationship = trim($_POST['next_of_kin_relationship'] ?? '');
    
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (empty($first_name)) {
        $errors[] = "First name is required";
    }
    
    if (empty($last_name)) {
        $errors[] = "Last name is required";
    }
    
    if (empty($phone)) {
        $errors[] = "Phone number is required";
    }
    
    // Validate email format if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // If no errors, proceed with conversion
    if (empty($errors)) {
        try {
            // Start transaction
            $conn->beginTransaction();
            
            // Get region name if region_id is provided
            $region_name = null;
            if ($region_id > 0) {
                $region_query = "SELECT region_name FROM regions WHERE region_id = ?";
                $stmt = $conn->prepare($region_query);
                $stmt->execute([$region_id]);
                $region_result = $stmt->fetch(PDO::FETCH_ASSOC);
                $region_name = $region_result ? $region_result['region_name'] : null;
            }
            
            // Insert into customers table
            $customer_query = "
                INSERT INTO customers (
                    company_id,
                    first_name,
                    middle_name,
                    last_name,
                    email,
                    phone,
                    alternative_phone,
                    national_id,
                    passport_number,
                    gender,
                    nationality,
                    occupation,
                    region,
                    district,
                    ward,
                    village,
                    street_address,
                    postal_address,
                    next_of_kin_name,
                    next_of_kin_phone,
                    next_of_kin_relationship,
                    notes,
                    is_active,
                    created_by,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
            ";
            
            $stmt = $conn->prepare($customer_query);
            $stmt->execute([
                $company_id,
                $first_name,
                $middle_name,
                $last_name,
                !empty($email) ? $email : null,
                $phone,
                !empty($alternative_phone) ? $alternative_phone : null,
                !empty($national_id) ? $national_id : null,
                !empty($passport_number) ? $passport_number : null,
                !empty($gender) ? $gender : null,
                $nationality,
                !empty($occupation) ? $occupation : null,
                $region_name,
                !empty($district) ? $district : null,
                !empty($ward) ? $ward : null,
                !empty($village) ? $village : null,
                !empty($street_address) ? $street_address : null,
                !empty($postal_address) ? $postal_address : null,
                !empty($next_of_kin_name) ? $next_of_kin_name : null,
                !empty($next_of_kin_phone) ? $next_of_kin_phone : null,
                !empty($next_of_kin_relationship) ? $next_of_kin_relationship : null,
                !empty($notes) ? $notes : null,
                $_SESSION['user_id']
            ]);
            
            $customer_id = $conn->lastInsertId();
            
            // Update lead with customer ID and status
            $update_lead_query = "
                UPDATE leads 
                SET converted_to_customer_id = ?,
                    conversion_date = CURDATE(),
                    lead_status = 'won',
                    updated_at = NOW()
                WHERE lead_id = ? AND company_id = ?
            ";
            
            $stmt = $conn->prepare($update_lead_query);
            $stmt->execute([$customer_id, $lead_id, $company_id]);
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['success'] = "Lead successfully converted to customer!";
            header('Location: view-customer.php?id=' . $customer_id);
            exit;
            
        } catch (PDOException $e) {
            // Rollback on error
            $conn->rollBack();
            error_log("Error converting lead: " . $e->getMessage());
            $errors[] = "Failed to convert lead. Please try again.";
        }
    }
}

// Pre-fill form with lead data
$form_data = [
    'first_name' => $_POST['first_name'] ?? explode(' ', $lead['full_name'])[0] ?? '',
    'middle_name' => $_POST['middle_name'] ?? '',
    'last_name' => $_POST['last_name'] ?? (count(explode(' ', $lead['full_name'])) > 1 ? end(explode(' ', $lead['full_name'])) : ''),
    'email' => $_POST['email'] ?? $lead['email'] ?? '',
    'phone' => $_POST['phone'] ?? $lead['phone'] ?? '',
    'alternative_phone' => $_POST['alternative_phone'] ?? $lead['alternative_phone'] ?? '',
    'preferred_location' => $lead['preferred_location'] ?? '',
    'budget_range' => $lead['budget_range'] ?? '',
];

$page_title = 'Convert Lead to Customer';
require_once '../../includes/header.php';
?>

<style>
.conversion-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
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
    color: #28a745;
}

.lead-info-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.lead-info-box h5 {
    margin-bottom: 1rem;
    font-weight: 700;
}

.lead-info-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.lead-info-item:last-child {
    border-bottom: none;
}

.lead-info-label {
    opacity: 0.9;
}

.lead-info-value {
    font-weight: 600;
}

.info-card {
    background: #f8f9fa;
    border-left: 4px solid #28a745;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.required-field::after {
    content: " *";
    color: #dc3545;
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
                    <i class="fas fa-user-check text-success me-2"></i>Convert Lead to Customer
                </h1>
                <p class="text-muted small mb-0 mt-1">Create a new customer from lead</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="view-lead.php?id=<?php echo $lead_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Lead
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        
        <!-- Error Messages -->
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

        <div class="row">
            <!-- Main Form -->
            <div class="col-lg-8">
                
                <form method="POST" action="" id="convertForm">
                    <div class="conversion-card">
                        
                        <!-- Personal Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-user"></i>
                                Personal Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="first_name" class="form-label required-field">First Name</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="first_name" 
                                           name="first_name"
                                           value="<?php echo htmlspecialchars($form_data['first_name']); ?>"
                                           required>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="middle_name" class="form-label">Middle Name</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="middle_name" 
                                           name="middle_name"
                                           value="<?php echo htmlspecialchars($form_data['middle_name']); ?>">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="last_name" class="form-label required-field">Last Name</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="last_name" 
                                           name="last_name"
                                           value="<?php echo htmlspecialchars($form_data['last_name']); ?>"
                                           required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="gender" class="form-label">Gender</label>
                                    <select class="form-select" id="gender" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="nationality" class="form-label">Nationality</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="nationality" 
                                           name="nationality"
                                           value="Tanzanian">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="national_id" class="form-label">National ID</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="national_id" 
                                           name="national_id"
                                           placeholder="e.g., 19850101-12345-67890-12">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="passport_number" class="form-label">Passport Number</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="passport_number" 
                                           name="passport_number"
                                           placeholder="e.g., AB1234567">
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <label for="occupation" class="form-label">Occupation</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="occupation" 
                                           name="occupation"
                                           placeholder="e.g., Business Owner, Teacher">
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-phone"></i>
                                Contact Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label required-field">Phone Number</label>
                                    <input type="tel" 
                                           class="form-control" 
                                           id="phone" 
                                           name="phone"
                                           value="<?php echo htmlspecialchars($form_data['phone']); ?>"
                                           placeholder="+255123456789"
                                           required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="alternative_phone" class="form-label">Alternative Phone</label>
                                    <input type="tel" 
                                           class="form-control" 
                                           id="alternative_phone" 
                                           name="alternative_phone"
                                           value="<?php echo htmlspecialchars($form_data['alternative_phone']); ?>"
                                           placeholder="+255987654321">
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" 
                                           class="form-control" 
                                           id="email" 
                                           name="email"
                                           value="<?php echo htmlspecialchars($form_data['email']); ?>"
                                           placeholder="customer@example.com">
                                </div>
                            </div>
                        </div>

                        <!-- Address Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-map-marker-alt"></i>
                                Address Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="region_id" class="form-label">Region</label>
                                    <select class="form-select" id="region_id" name="region_id">
                                        <option value="">Select Region</option>
                                        <?php foreach ($regions as $region): ?>
                                            <option value="<?php echo $region['region_id']; ?>">
                                                <?php echo htmlspecialchars($region['region_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="district" class="form-label">District</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="district" 
                                           name="district"
                                           placeholder="e.g., Kinondoni">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="ward" class="form-label">Ward</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="ward" 
                                           name="ward"
                                           placeholder="e.g., Mikocheni">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="village" class="form-label">Village/Street</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="village" 
                                           name="village"
                                           placeholder="e.g., Mikocheni A">
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <label for="street_address" class="form-label">Street Address</label>
                                    <textarea class="form-control" 
                                              id="street_address" 
                                              name="street_address" 
                                              rows="2"
                                              placeholder="Enter full street address"></textarea>
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <label for="postal_address" class="form-label">Postal Address</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="postal_address" 
                                           name="postal_address"
                                           placeholder="P.O. Box 12345, Dar es Salaam">
                                </div>
                            </div>
                        </div>

                        <!-- Next of Kin -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-user-friends"></i>
                                Next of Kin / Emergency Contact
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="next_of_kin_name" class="form-label">Full Name</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="next_of_kin_name" 
                                           name="next_of_kin_name"
                                           placeholder="Enter full name">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="next_of_kin_phone" class="form-label">Phone Number</label>
                                    <input type="tel" 
                                           class="form-control" 
                                           id="next_of_kin_phone" 
                                           name="next_of_kin_phone"
                                           placeholder="+255123456789">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="next_of_kin_relationship" class="form-label">Relationship</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="next_of_kin_relationship" 
                                           name="next_of_kin_relationship"
                                           placeholder="e.g., Spouse, Parent, Sibling">
                                </div>
                            </div>
                        </div>

                        <!-- Additional Notes -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-sticky-note"></i>
                                Additional Notes
                            </h5>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" 
                                          id="notes" 
                                          name="notes" 
                                          rows="4"
                                          placeholder="Any additional information about the customer..."></textarea>
                            </div>
                        </div>

                    </div>

                    <!-- Form Actions -->
                    <div class="d-flex justify-content-between mb-4">
                        <a href="view-lead.php?id=<?php echo $lead_id; ?>" class="btn btn-outline-secondary btn-lg">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-check me-1"></i> Convert to Customer
                        </button>
                    </div>
                </form>

            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                
                <!-- Lead Information -->
                <div class="lead-info-box">
                    <h5>
                        <i class="fas fa-info-circle me-2"></i>
                        Lead Information
                    </h5>
                    
                    <div class="lead-info-item">
                        <span class="lead-info-label">Name:</span>
                        <span class="lead-info-value"><?php echo htmlspecialchars($lead['full_name']); ?></span>
                    </div>
                    
                    <?php if (!empty($lead['email'])): ?>
                    <div class="lead-info-item">
                        <span class="lead-info-label">Email:</span>
                        <span class="lead-info-value"><?php echo htmlspecialchars($lead['email']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="lead-info-item">
                        <span class="lead-info-label">Phone:</span>
                        <span class="lead-info-value"><?php echo htmlspecialchars($lead['phone']); ?></span>
                    </div>
                    
                    <div class="lead-info-item">
                        <span class="lead-info-label">Source:</span>
                        <span class="lead-info-value"><?php echo ucfirst(str_replace('_', ' ', $lead['lead_source'])); ?></span>
                    </div>
                    
                    <div class="lead-info-item">
                        <span class="lead-info-label">Status:</span>
                        <span class="lead-info-value"><?php echo ucfirst($lead['lead_status']); ?></span>
                    </div>
                </div>

                <!-- Lead Interest Details -->
                <?php if (!empty($lead['interested_in']) || !empty($lead['budget_range']) || !empty($lead['preferred_location'])): ?>
                <div class="info-card">
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-bullseye me-2"></i>
                        Interest Details
                    </h6>
                    
                    <?php if (!empty($lead['interested_in'])): ?>
                    <p class="mb-2">
                        <strong>Interested In:</strong><br>
                        <span class="badge bg-info mt-1">
                            <?php echo ucfirst(str_replace('_', ' ', $lead['interested_in'])); ?>
                        </span>
                    </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($lead['budget_range'])): ?>
                    <p class="mb-2">
                        <strong>Budget:</strong><br>
                        <?php echo htmlspecialchars($lead['budget_range']); ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($lead['preferred_location'])): ?>
                    <p class="mb-0">
                        <strong>Preferred Location:</strong><br>
                        <?php echo htmlspecialchars($lead['preferred_location']); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Instructions -->
                <div class="info-card">
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        Instructions
                    </h6>
                    
                    <ul class="mb-0 ps-3">
                        <li class="mb-2">Review and complete the customer information</li>
                        <li class="mb-2">All fields marked with <span class="text-danger">*</span> are required</li>
                        <li class="mb-2">Ensure phone numbers and email are accurate</li>
                        <li class="mb-2">Add next of kin details for emergency contact</li>
                        <li>Once converted, the lead status will be updated to "Won"</li>
                    </ul>
                </div>

            </div>
        </div>

    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Phone number formatting
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        input.addEventListener('blur', function() {
            let value = this.value.trim();
            // Add +255 prefix if starts with 0
            if (value.startsWith('0') && value.length >= 10) {
                this.value = '+255' + value.substring(1);
            }
        });
    });
    
    // Form validation
    const form = document.getElementById('convertForm');
    form.addEventListener('submit', function(e) {
        const firstName = document.getElementById('first_name').value.trim();
        const lastName = document.getElementById('last_name').value.trim();
        const phone = document.getElementById('phone').value.trim();
        
        if (!firstName || !lastName || !phone) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return false;
        }
        
        // Confirm conversion
        if (!confirm('Are you sure you want to convert this lead to a customer?')) {
            e.preventDefault();
            return false;
        }
        
        return true;
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>