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

// Get customer ID from URL
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$customer_id) {
    $_SESSION['error'] = "Invalid customer ID";
    header('Location: index.php');
    exit;
}

// Initialize variables
$errors = [];
$success = '';
$customer = null;

// ==================== FETCH CUSTOMER DATA ====================
try {
    $customer_sql = "SELECT * FROM customers 
                     WHERE customer_id = ? AND company_id = ?";
    $customer_stmt = $conn->prepare($customer_sql);
    $customer_stmt->execute([$customer_id, $company_id]);
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $_SESSION['error'] = "Customer not found";
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching customer: " . $e->getMessage());
    $_SESSION['error'] = "Error loading customer details";
    header('Location: index.php');
    exit;
}

// Fetch sales persons/users
try {
    $sales_persons_sql = "SELECT user_id, full_name, email 
                          FROM users 
                          WHERE company_id = ? AND is_active = 1 
                          ORDER BY full_name";
    $sales_persons_stmt = $conn->prepare($sales_persons_sql);
    $sales_persons_stmt->execute([$company_id]);
    $sales_persons = $sales_persons_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching sales persons: " . $e->getMessage());
    $sales_persons = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    if (empty($_POST['first_name'])) {
        $errors[] = "First name is required";
    }
    if (empty($_POST['last_name'])) {
        $errors[] = "Last name is required";
    }
    if (empty($_POST['phone'])) {
        $errors[] = "Phone number is required";
    }

    // Validate phone number format
    if (!empty($_POST['phone']) && !preg_match('/^[0-9+\-\s()]+$/', $_POST['phone'])) {
        $errors[] = "Invalid phone number format";
    }

    // Validate email format if provided
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    // Check for duplicate phone number (excluding current customer)
    if (!empty($_POST['phone'])) {
        $check_phone_sql = "SELECT COUNT(*) FROM customers 
                           WHERE phone = ? AND company_id = ? AND customer_id != ?";
        $check_phone_stmt = $conn->prepare($check_phone_sql);
        $check_phone_stmt->execute([$_POST['phone'], $company_id, $customer_id]);
        
        if ($check_phone_stmt->fetchColumn() > 0) {
            $errors[] = "A customer with this phone number already exists";
        }
    }

    // Check for duplicate email if provided (excluding current customer)
    if (!empty($_POST['email'])) {
        $check_email_sql = "SELECT COUNT(*) FROM customers 
                           WHERE email = ? AND company_id = ? AND customer_id != ?";
        $check_email_stmt = $conn->prepare($check_email_sql);
        $check_email_stmt->execute([$_POST['email'], $company_id, $customer_id]);
        
        if ($check_email_stmt->fetchColumn() > 0) {
            $errors[] = "A customer with this email address already exists";
        }
    }

    // Handle profile picture upload
    $profile_picture_path = $customer['profile_picture']; // Keep existing if no new upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../../uploads/customers/';
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_extension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed";
        } else {
            $new_filename = 'customer_' . time() . '_' . uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                // Delete old profile picture if exists
                if (!empty($customer['profile_picture']) && file_exists('../../' . $customer['profile_picture'])) {
                    unlink('../../' . $customer['profile_picture']);
                }
                $profile_picture_path = 'uploads/customers/' . $new_filename;
            } else {
                $errors[] = "Failed to upload profile picture";
            }
        }
    }

    // If no errors, proceed with update
    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Update customer
            $sql = "UPDATE customers SET
                customer_type = ?,
                first_name = ?,
                middle_name = ?,
                last_name = ?,
                email = ?,
                phone = ?,
                alternative_phone = ?,
                national_id = ?,
                passport_number = ?,
                id_number = ?,
                tin_number = ?,
                nationality = ?,
                occupation = ?,
                region = ?,
                district = ?,
                ward = ?,
                village = ?,
                address = ?,
                postal_address = ?,
                street_address = ?,
                gender = ?,
                profile_picture = ?,
                guardian1_name = ?,
                guardian1_relationship = ?,
                guardian2_name = ?,
                guardian2_relationship = ?,
                next_of_kin_name = ?,
                next_of_kin_phone = ?,
                next_of_kin_relationship = ?,
                notes = ?,
                updated_at = CURRENT_TIMESTAMP
                WHERE customer_id = ? AND company_id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $_POST['customer_type'] ?? 'individual',
                $_POST['first_name'],
                $_POST['middle_name'] ?? null,
                $_POST['last_name'],
                $_POST['email'] ?? null,
                $_POST['phone'],
                $_POST['alternative_phone'] ?? null,
                $_POST['national_id'] ?? null,
                $_POST['passport_number'] ?? null,
                $_POST['id_number'] ?? null,
                $_POST['tin_number'] ?? null,
                $_POST['nationality'] ?? 'Tanzanian',
                $_POST['occupation'] ?? null,
                $_POST['region'] ?? null,
                $_POST['district'] ?? null,
                $_POST['ward'] ?? null,
                $_POST['village'] ?? null,
                $_POST['address'] ?? null,
                $_POST['postal_address'] ?? null,
                $_POST['street_address'] ?? null,
                $_POST['gender'] ?? null,
                $profile_picture_path,
                $_POST['guardian1_name'] ?? null,
                $_POST['guardian1_relationship'] ?? null,
                $_POST['guardian2_name'] ?? null,
                $_POST['guardian2_relationship'] ?? null,
                $_POST['next_of_kin_name'] ?? null,
                $_POST['next_of_kin_phone'] ?? null,
                $_POST['next_of_kin_relationship'] ?? null,
                $_POST['notes'] ?? null,
                $customer_id,
                $company_id
            ]);

            $conn->commit();
            $success = "Customer updated successfully!";
            
            // Refresh customer data
            $customer_stmt->execute([$customer_id, $company_id]);
            $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Redirect after 2 seconds
            header("refresh:2;url=view.php?id=" . $customer_id);
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error updating customer: " . $e->getMessage());
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title = 'Edit Customer - ' . htmlspecialchars($customer['full_name']);
require_once '../../includes/header.php';
?>

<style>
.nav-tabs-custom {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.nav-tabs {
    border-bottom: 2px solid #e9ecef;
    padding: 0 1rem;
}

.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
    padding: 1rem 1.5rem;
    font-weight: 500;
    transition: all 0.3s;
    position: relative;
}

.nav-tabs .nav-link:hover {
    color: #ffc107;
    background: transparent;
}

.nav-tabs .nav-link.active {
    color: #ffc107;
    background: transparent;
    border: none;
}

.nav-tabs .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #ffc107 0%, #ff9800 100%);
    border-radius: 3px 3px 0 0;
}

.nav-tabs .nav-link i {
    margin-right: 0.5rem;
}

.form-section {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.form-section-header {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
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

.profile-picture-preview {
    width: 150px;
    height: 150px;
    border: 2px dashed #dee2e6;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #f8f9fa;
    margin-top: 0.5rem;
}

.profile-picture-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: cover;
}

.profile-picture-preview .placeholder {
    text-align: center;
    color: #6c757d;
}

.profile-picture-preview .placeholder i {
    font-size: 3rem;
    margin-bottom: 0.5rem;
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

.current-value-badge {
    background: #17a2b8;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    margin-left: 0.5rem;
}

.info-box {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-edit text-warning me-2"></i>Edit Customer
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Update customer information - <?php echo htmlspecialchars($customer['full_name']); ?>
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="view.php?id=<?php echo $customer_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-eye me-1"></i> View Customer
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to List
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
            <p class="mb-0 mt-2"><i class="fas fa-spinner fa-spin me-2"></i>Redirecting to customer details...</p>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <div class="nav-tabs-custom">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" href="#" onclick="return false;">
                        <i class="fas fa-user-edit"></i> Edit Customer
                    </a>
                </li>
            </ul>
        </div>

        <!-- Info Box -->
        <div class="info-box">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Editing:</strong> <?php echo htmlspecialchars($customer['full_name']); ?> 
            (Customer ID: #<?php echo $customer_id; ?>)
        </div>

        <!-- Customer Edit Form -->
        <form method="POST" id="customerForm" enctype="multipart/form-data">
            
            <!-- Basic Information Section -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-user me-2 text-warning"></i>
                    Basic Information
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Customer Type</label>
                        <select name="customer_type" class="form-select">
                            <option value="individual" <?php echo ($customer['customer_type'] === 'individual') ? 'selected' : ''; ?>>Individual</option>
                            <option value="company" <?php echo ($customer['customer_type'] === 'company') ? 'selected' : ''; ?>>Company</option>
                        </select>
                    </div>

                    <div class="col-md-9">
                        <label class="form-label">Type Name</label>
                        <input type="text" 
                               name="type_name" 
                               class="form-control" 
                               placeholder="Type name (optional)"
                               value="<?php echo htmlspecialchars($customer['type_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required-field">First Name</label>
                        <input type="text" 
                               name="first_name" 
                               class="form-control" 
                               placeholder="Enter first name"
                               value="<?php echo htmlspecialchars($customer['first_name']); ?>"
                               required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" 
                               name="middle_name" 
                               class="form-control" 
                               placeholder="Enter middle name"
                               value="<?php echo htmlspecialchars($customer['middle_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required-field">Last Name</label>
                        <input type="text" 
                               name="last_name" 
                               class="form-control" 
                               placeholder="Enter last name"
                               value="<?php echo htmlspecialchars($customer['last_name']); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label required-field">Phone1</label>
                        <input type="text" 
                               name="phone" 
                               class="form-control" 
                               placeholder="e.g., +255 745 381 762"
                               value="<?php echo htmlspecialchars($customer['phone']); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Phone2</label>
                        <input type="text" 
                               name="alternative_phone" 
                               class="form-control" 
                               placeholder="Alternative phone number"
                               value="<?php echo htmlspecialchars($customer['alternative_phone'] ?? ''); ?>">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">Email address</label>
                        <input type="email" 
                               name="email" 
                               class="form-control" 
                               placeholder="customer@example.com"
                               value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Location Information Section -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-map-marker-alt me-2 text-warning"></i>
                    Location Information
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Region</label>
                        <select name="region" id="region" class="form-select" onchange="loadDistricts()">
                            <option value="">Select Region</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">District</label>
                        <select name="district" id="district" class="form-select" onchange="loadWards()">
                            <option value="">Select District</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Ward</label>
                        <select name="ward" id="ward" class="form-select" onchange="loadVillages()">
                            <option value="">Select Ward</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Village</label>
                        <select name="village" id="village" class="form-select">
                            <option value="">Select Village</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">Street Address</label>
                        <textarea name="street_address" 
                                  class="form-control" 
                                  rows="2"
                                  placeholder="Enter street address"><?php echo htmlspecialchars($customer['street_address'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Postal Address</label>
                        <input type="text" 
                               name="postal_address" 
                               class="form-control" 
                               placeholder="P.O. Box..."
                               value="<?php echo htmlspecialchars($customer['postal_address'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Sales Person</label>
                        <select name="sales_person_id" class="form-select">
                            <option value="">Select Sales Person</option>
                            <?php foreach ($sales_persons as $person): ?>
                                <option value="<?php echo $person['user_id']; ?>">
                                    <?php echo htmlspecialchars($person['full_name']); ?>
                                    <?php if ($person['email']): ?>
                                        - <?php echo htmlspecialchars($person['email']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Assign a sales person to this customer</small>
                    </div>
                </div>
            </div>

            <!-- Identification Section -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-id-card me-2 text-warning"></i>
                    Identification Information
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Aina ya kitambulisho (ID Type)</label>
                        <select name="id_type" class="form-select">
                            <option value="">Select ID Type</option>
                            <option value="national_id">National ID (NIDA)</option>
                            <option value="voter_id">Voter ID</option>
                            <option value="passport">Passport</option>
                            <option value="driver_license">Driver License</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Namba ya kitambulisho (ID Number)</label>
                        <input type="text" 
                               name="id_number" 
                               class="form-control" 
                               placeholder="Enter ID number"
                               value="<?php echo htmlspecialchars($customer['id_number'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">National ID (NIDA)</label>
                        <input type="text" 
                               name="national_id" 
                               class="form-control" 
                               placeholder="Enter NIDA number"
                               value="<?php echo htmlspecialchars($customer['national_id'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Passport Number</label>
                        <input type="text" 
                               name="passport_number" 
                               class="form-control" 
                               placeholder="Enter passport number"
                               value="<?php echo htmlspecialchars($customer['passport_number'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">TIN Number</label>
                        <input type="text" 
                               name="tin_number" 
                               class="form-control" 
                               placeholder="Enter TIN number"
                               value="<?php echo htmlspecialchars($customer['tin_number'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nationality</label>
                        <input type="text" 
                               name="nationality" 
                               class="form-control" 
                               placeholder="e.g., Tanzanian"
                               value="<?php echo htmlspecialchars($customer['nationality'] ?? 'Tanzanian'); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Occupation</label>
                        <input type="text" 
                               name="occupation" 
                               class="form-control" 
                               placeholder="Enter occupation"
                               value="<?php echo htmlspecialchars($customer['occupation'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Additional Details Section -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-info-circle me-2 text-warning"></i>
                    Additional Details
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Profile Picture</label>
                        <input type="file" 
                               name="profile_picture" 
                               id="profile_picture"
                               class="form-control"
                               accept="image/*"
                               onchange="previewImage(this)">
                        <small class="text-muted">Upload JPG, JPEG, PNG, or GIF</small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">Select Gender</option>
                            <option value="male" <?php echo ($customer['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($customer['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo ($customer['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Can get commission?</label>
                        <select name="can_get_commission" class="form-select">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                        <small class="text-muted">Enable if customer can earn commissions</small>
                    </div>

                    <!-- Profile Picture Preview -->
                    <div class="col-md-12">
                        <div class="profile-picture-preview" id="imagePreview">
                            <?php if (!empty($customer['profile_picture']) && file_exists('../../' . $customer['profile_picture'])): ?>
                                <img src="../../<?php echo htmlspecialchars($customer['profile_picture']); ?>" alt="Profile Picture">
                            <?php else: ?>
                                <div class="placeholder">
                                    <i class="fas fa-user-circle"></i>
                                    <div>Profile Picture Preview</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Guardian Information Section -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-users me-2 text-warning"></i>
                    Guardian Information
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Guardian 1 Name</label>
                        <input type="text" 
                               name="guardian1_name" 
                               class="form-control" 
                               placeholder="Enter guardian name"
                               value="<?php echo htmlspecialchars($customer['guardian1_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Guardian 1 Relationship</label>
                        <select name="guardian1_relationship" class="form-select">
                            <option value="">Select Relationship</option>
                            <option value="parent" <?php echo ($customer['guardian1_relationship'] === 'parent') ? 'selected' : ''; ?>>Parent</option>
                            <option value="spouse" <?php echo ($customer['guardian1_relationship'] === 'spouse') ? 'selected' : ''; ?>>Spouse</option>
                            <option value="sibling" <?php echo ($customer['guardian1_relationship'] === 'sibling') ? 'selected' : ''; ?>>Sibling</option>
                            <option value="child" <?php echo ($customer['guardian1_relationship'] === 'child') ? 'selected' : ''; ?>>Child</option>
                            <option value="friend" <?php echo ($customer['guardian1_relationship'] === 'friend') ? 'selected' : ''; ?>>Friend</option>
                            <option value="other" <?php echo ($customer['guardian1_relationship'] === 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Guardian 2 Name</label>
                        <input type="text" 
                               name="guardian2_name" 
                               class="form-control" 
                               placeholder="Enter guardian name"
                               value="<?php echo htmlspecialchars($customer['guardian2_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Guardian 2 Relationship</label>
                        <select name="guardian2_relationship" class="form-select">
                            <option value="">Select Relationship</option>
                            <option value="parent" <?php echo ($customer['guardian2_relationship'] === 'parent') ? 'selected' : ''; ?>>Parent</option>
                            <option value="spouse" <?php echo ($customer['guardian2_relationship'] === 'spouse') ? 'selected' : ''; ?>>Spouse</option>
                            <option value="sibling" <?php echo ($customer['guardian2_relationship'] === 'sibling') ? 'selected' : ''; ?>>Sibling</option>
                            <option value="child" <?php echo ($customer['guardian2_relationship'] === 'child') ? 'selected' : ''; ?>>Child</option>
                            <option value="friend" <?php echo ($customer['guardian2_relationship'] === 'friend') ? 'selected' : ''; ?>>Friend</option>
                            <option value="other" <?php echo ($customer['guardian2_relationship'] === 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Next of Kin Section -->
            <div class="form-section">
                <div class="form-section-header">
                    <i class="fas fa-user-friends me-2 text-warning"></i>
                    Next of Kin Information
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Next of Kin Name</label>
                        <input type="text" 
                               name="next_of_kin_name" 
                               class="form-control" 
                               placeholder="Enter next of kin name"
                               value="<?php echo htmlspecialchars($customer['next_of_kin_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Next of Kin Phone</label>
                        <input type="text" 
                               name="next_of_kin_phone" 
                               class="form-control" 
                               placeholder="Enter phone number"
                               value="<?php echo htmlspecialchars($customer['next_of_kin_phone'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Next of Kin Relationship</label>
                        <select name="next_of_kin_relationship" class="form-select">
                            <option value="">Select Relationship</option>
                            <option value="parent" <?php echo ($customer['next_of_kin_relationship'] === 'parent') ? 'selected' : ''; ?>>Parent</option>
                            <option value="spouse" <?php echo ($customer['next_of_kin_relationship'] === 'spouse') ? 'selected' : ''; ?>>Spouse</option>
                            <option value="sibling" <?php echo ($customer['next_of_kin_relationship'] === 'sibling') ? 'selected' : ''; ?>>Sibling</option>
                            <option value="child" <?php echo ($customer['next_of_kin_relationship'] === 'child') ? 'selected' : ''; ?>>Child</option>
                            <option value="friend" <?php echo ($customer['next_of_kin_relationship'] === 'friend') ? 'selected' : ''; ?>>Friend</option>
                            <option value="other" <?php echo ($customer['next_of_kin_relationship'] === 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">Additional Notes</label>
                        <textarea name="notes" 
                                  class="form-control" 
                                  rows="3"
                                  placeholder="Enter any additional notes about this customer"><?php echo htmlspecialchars($customer['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-section">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <a href="view.php?id=<?php echo $customer_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                    <button type="submit" class="btn btn-update">
                        <i class="fas fa-save me-2"></i>Update Customer
                    </button>
                </div>
            </div>

        </form>

    </div>
</section>

<script src="../../assets/js/tanzania-locations.js"></script>
<script>
// Store current values from database
const currentRegion = '<?php echo addslashes($customer['region'] ?? ''); ?>';
const currentDistrict = '<?php echo addslashes($customer['district'] ?? ''); ?>';
const currentWard = '<?php echo addslashes($customer['ward'] ?? ''); ?>';
const currentVillage = '<?php echo addslashes($customer['village'] ?? ''); ?>';

// Initialize location dropdowns on page load
document.addEventListener('DOMContentLoaded', function() {
    populateRegions();
});

// Populate regions dropdown
function populateRegions() {
    const regionSelect = document.getElementById('region');
    const regions = getRegions();
    
    regions.forEach(region => {
        const option = document.createElement('option');
        option.value = region.name;
        option.textContent = region.name;
        option.setAttribute('data-region-id', region.id);
        
        // Pre-select current region
        if (region.name === currentRegion) {
            option.selected = true;
        }
        
        regionSelect.appendChild(option);
    });
    
    // Load districts if region is selected
    if (currentRegion) {
        loadDistricts();
    }
}

// Load districts based on selected region
function loadDistricts() {
    const regionSelect = document.getElementById('region');
    const districtSelect = document.getElementById('district');
    const wardSelect = document.getElementById('ward');
    const villageSelect = document.getElementById('village');
    
    const selectedOption = regionSelect.options[regionSelect.selectedIndex];
    const regionId = selectedOption.getAttribute('data-region-id');
    
    districtSelect.innerHTML = '<option value="">Select District</option>';
    wardSelect.innerHTML = '<option value="">Select Ward</option>';
    villageSelect.innerHTML = '<option value="">Select Village</option>';
    
    if (regionId) {
        const districts = getDistrictsByRegion(regionId);
        
        districts.forEach(district => {
            const option = document.createElement('option');
            option.value = district.name;
            option.textContent = district.name;
            option.setAttribute('data-district-id', district.id);
            
            // Pre-select current district
            if (district.name === currentDistrict) {
                option.selected = true;
            }
            
            districtSelect.appendChild(option);
        });
        
        // Load wards if district is selected
        if (currentDistrict) {
            loadWards();
        }
    }
}

// Load wards based on selected district
function loadWards() {
    const districtSelect = document.getElementById('district');
    const wardSelect = document.getElementById('ward');
    const villageSelect = document.getElementById('village');
    
    const selectedOption = districtSelect.options[districtSelect.selectedIndex];
    const districtId = selectedOption.getAttribute('data-district-id');
    
    wardSelect.innerHTML = '<option value="">Select Ward</option>';
    villageSelect.innerHTML = '<option value="">Select Village</option>';
    
    if (districtId) {
        const wards = getWardsByDistrict(districtId);
        
        wards.forEach(ward => {
            const option = document.createElement('option');
            option.value = ward.name;
            option.textContent = ward.name;
            option.setAttribute('data-ward-id', ward.id);
            
            // Pre-select current ward
            if (ward.name === currentWard) {
                option.selected = true;
            }
            
            wardSelect.appendChild(option);
        });
        
        // Load villages if ward is selected
        if (currentWard) {
            loadVillages();
        }
    }
}

// Load villages based on selected ward
function loadVillages() {
    const wardSelect = document.getElementById('ward');
    const villageSelect = document.getElementById('village');
    
    const selectedOption = wardSelect.options[wardSelect.selectedIndex];
    const wardId = selectedOption.getAttribute('data-ward-id');
    
    villageSelect.innerHTML = '<option value="">Select Village</option>';
    
    if (wardId) {
        const villages = getVillagesByWard(wardId);
        
        villages.forEach(village => {
            const option = document.createElement('option');
            option.value = village.name;
            option.textContent = village.name;
            
            // Pre-select current village
            if (village.name === currentVillage) {
                option.selected = true;
            }
            
            villageSelect.appendChild(option);
        });
    }
}

// Preview uploaded image
function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Profile Picture">';
        };
        
        reader.readAsDataURL(input.files[0]);
    }
}

// Form validation before submit
document.getElementById('customerForm').addEventListener('submit', function(e) {
    const firstName = document.querySelector('input[name="first_name"]').value.trim();
    const lastName = document.querySelector('input[name="last_name"]').value.trim();
    const phone = document.querySelector('input[name="phone"]').value.trim();
    
    if (!firstName || !lastName || !phone) {
        e.preventDefault();
        alert('Please fill in all required fields: First Name, Last Name, and Phone Number');
        return false;
    }
    
    return true;
});
</script>

<?php 
require_once '../../includes/footer.php';
?>