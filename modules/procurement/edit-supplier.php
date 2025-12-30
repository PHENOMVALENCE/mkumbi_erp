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

$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$success_message = '';

if ($supplier_id <= 0) {
    $_SESSION['error_message'] = "Invalid supplier ID";
    header('Location: suppliers.php');
    exit;
}

// Fetch current supplier data
$supplier = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            s.*,
            COUNT(DISTINCT po.purchase_order_id) as order_count,
            COALESCE(SUM(CASE WHEN po.status IN ('approved', 'received', 'closed') THEN po.total_amount ELSE 0 END), 0) as total_purchases,
            MAX(po.po_date) as last_order_date
        FROM suppliers s
        LEFT JOIN purchase_orders po ON s.supplier_id = po.supplier_id AND po.company_id = ?
        WHERE s.supplier_id = ? AND s.company_id = ? AND s.is_active = 1
        GROUP BY s.supplier_id
    ");
    $stmt->execute([$company_id, $supplier_id, $company_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        $_SESSION['error_message'] = "Supplier not found";
        header('Location: suppliers.php');
        exit;
    }
    
    // Ensure all expected fields exist with default values
    $supplier = array_merge([
        'supplier_code' => '',
        'supplier_name' => '',
        'contact_person' => '',
        'phone' => '',
        'email' => '',
        'city' => '',
        'address' => '',
        'payment_terms' => '30',
        'credit_limit' => 0,
        'notes' => '',
        'order_count' => 0,
        'total_purchases' => 0,
        'last_order_date' => null
    ], $supplier);
    
} catch (PDOException $e) {
    error_log("Error fetching supplier: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading supplier data";
    header('Location: suppliers.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_code = trim($_POST['supplier_code']);
    $supplier_name = trim($_POST['supplier_name']);
    $contact_person = trim($_POST['contact_person']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $city = trim($_POST['city']);
    $address = trim($_POST['address']);
    $payment_terms = trim($_POST['payment_terms']);
    $credit_limit = (float)($_POST['credit_limit'] ?? 0);
    $notes = trim($_POST['notes']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validation
    if (empty($supplier_name)) {
        $errors[] = "Supplier name is required";
    }

    if (empty($phone)) {
        $errors[] = "Phone number is required";
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address";
    }

    // Check if supplier code already exists (excluding current supplier)
    if (!empty($supplier_code)) {
        try {
            $stmt = $conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_code = ? AND company_id = ? AND supplier_id != ? AND is_active = 1");
            $stmt->execute([$supplier_code, $company_id, $supplier_id]);
            if ($stmt->fetch()) {
                $errors[] = "Supplier code already exists";
            }
        } catch (PDOException $e) {
            error_log("Error checking supplier code: " . $e->getMessage());
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $update_query = "
                UPDATE suppliers SET 
                    supplier_code = ?,
                    supplier_name = ?,
                    contact_person = ?,
                    phone = ?,
                    email = ?,
                    city = ?,
                    address = ?,
                    payment_terms = ?,
                    credit_limit = ?,
                    notes = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE supplier_id = ? AND company_id = ?
            ";

            $stmt = $conn->prepare($update_query);
            $stmt->execute([
                $supplier_code ?: null,
                $supplier_name,
                $contact_person ?: null,
                $phone,
                $email ?: null,
                $city ?: null,
                $address ?: null,
                $payment_terms ?: '30',
                $credit_limit,
                $notes ?: null,
                $is_active,
                $supplier_id,
                $company_id
            ]);

            $conn->commit();
            $_SESSION['success_message'] = "Supplier updated successfully!";
            header('Location: suppliers.php?success=1');
            exit;

        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error updating supplier: " . $e->getMessage());
            $errors[] = "Error updating supplier. Please try again.";
        }
    }
}

$page_title = 'Edit Supplier - ' . $supplier['supplier_name'];
require_once '../../includes/header.php';
?>

<style>
.supplier-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.supplier-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    font-weight: 700;
    backdrop-filter: blur(10px);
    margin-right: 1.5rem;
}

.supplier-name {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.supplier-code {
    font-family: 'Courier New', monospace;
    background: rgba(255,255,255,0.2);
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
    display: inline-block;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-top: 1.5rem;
}

.stat-card {
    background: rgba(255,255,255,0.1);
    padding: 1.5rem;
    border-radius: 12px;
    text-align: center;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.2);
}

.stat-number {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.85rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-section {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
    margin-bottom: 1.5rem;
}

.form-section h5 {
    color: #007bff;
    font-weight: 700;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
}

.required-indicator {
    color: #dc3545;
    font-weight: bold;
}

.form-help-text {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.payment-terms-select {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.payment-term-tag {
    background: #e9ecef;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 2px solid transparent;
}

.payment-term-tag:hover,
.payment-term-tag.active {
    background: #007bff;
    color: white;
    border-color: #0056b3;
}

.status-toggle {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.toggle-switch {
    position: relative;
    width: 60px;
    height: 30px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 30px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 24px;
    width: 24px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #28a745;
}

input:checked + .slider:before {
    transform: translateX(30px);
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold text-primary">
                    <i class="fas fa-user-tie me-2"></i>
                    Edit Supplier
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="suppliers.php">Suppliers</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit - <?php echo htmlspecialchars($supplier['supplier_name']); ?></li>
                    </ol>
                </nav>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Suppliers
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Supplier Header -->
        <div class="supplier-header">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center">
                        <div class="supplier-avatar">
                            <?php echo strtoupper(substr($supplier['supplier_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <h1 class="supplier-name"><?php echo htmlspecialchars($supplier['supplier_name']); ?></h1>
                            <?php if (!empty($supplier['supplier_code'])): ?>
                            <div class="supplier-code">#<?php echo htmlspecialchars($supplier['supplier_code']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($supplier['order_count']); ?></div>
                            <div class="stat-label">Purchase Orders</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">TSH <?php echo number_format($supplier['total_purchases'] / 1000000, 1); ?>M</div>
                            <div class="stat-label">Total Purchases</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $supplier['payment_terms']; ?> days</div>
                            <div class="stat-label">Payment Terms</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Validation Errors:</h5>
            <ul class="mb-0 mt-3">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="editSupplierForm">
            
            <!-- Basic Information -->
            <div class="form-section">
                <h5><i class="fas fa-building me-2"></i>Basic Information</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Supplier Code</label>
                        <input type="text" name="supplier_code" class="form-control" maxlength="20"
                               value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>"
                               placeholder="e.g., SUP001">
                        <div class="form-help-text">Optional: Unique supplier identifier</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Supplier Name <span class="required-indicator">*</span></label>
                        <input type="text" name="supplier_name" class="form-control" required maxlength="150"
                               value="<?php echo htmlspecialchars($supplier['supplier_name']); ?>"
                               placeholder="Enter supplier company name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control" maxlength="100"
                               value="<?php echo htmlspecialchars($supplier['contact_person']); ?>"
                               placeholder="e.g., John Doe">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone <span class="required-indicator">*</span></label>
                        <input type="tel" name="phone" class="form-control" required maxlength="20"
                               value="<?php echo htmlspecialchars($supplier['phone']); ?>"
                               placeholder="e.g., +255 712 345 678">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="100"
                               value="<?php echo htmlspecialchars($supplier['email']); ?>"
                               placeholder="supplier@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">City</label>
                        <input type="text" name="city" class="form-control" maxlength="100"
                               value="<?php echo htmlspecialchars($supplier['city']); ?>"
                               placeholder="e.g., Dar es Salaam">
                    </div>
                </div>
            </div>

            <!-- Address & Financial -->
            <div class="form-section" style="border-left-color: #28a745;">
                <h5 style="color: #28a745;"><i class="fas fa-map-marker-alt me-2"></i>Address & Financial Information</h5>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2" maxlength="200"
                                  placeholder="Full address"><?php echo htmlspecialchars($supplier['address']); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Payment Terms (Days) <span class="required-indicator">*</span></label>
                        <input type="number" name="payment_terms" class="form-control" required min="0" max="365"
                               value="<?php echo htmlspecialchars($supplier['payment_terms']); ?>"
                               placeholder="30">
                        <div class="payment-terms-select">
                            <span class="payment-term-tag <?php echo ($supplier['payment_terms'] == 15) ? 'active' : ''; ?>" data-days="15">15 Days</span>
                            <span class="payment-term-tag <?php echo ($supplier['payment_terms'] == 30) ? 'active' : ''; ?>" data-days="30">30 Days</span>
                            <span class="payment-term-tag <?php echo ($supplier['payment_terms'] == 45) ? 'active' : ''; ?>" data-days="45">45 Days</span>
                            <span class="payment-term-tag <?php echo ($supplier['payment_terms'] == 60) ? 'active' : ''; ?>" data-days="60">60 Days</span>
                            <span class="payment-term-tag <?php echo ($supplier['payment_terms'] == 90) ? 'active' : ''; ?>" data-days="90">90 Days</span>
                        </div>
                        <div class="form-help-text">Number of days for payment after invoice date</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Credit Limit (TSH)</label>
                        <input type="number" name="credit_limit" class="form-control" step="1000" min="0"
                               value="<?php echo htmlspecialchars($supplier['credit_limit']); ?>"
                               placeholder="0">
                        <div class="form-help-text">Maximum credit allowed for this supplier</div>
                    </div>
                </div>
            </div>

            <!-- Additional Information -->
            <div class="form-section" style="border-left-color: #17a2b8;">
                <h5 style="color: #17a2b8;"><i class="fas fa-sticky-note me-2"></i>Additional Information</h5>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="4" maxlength="1000"
                                  placeholder="Additional notes about this supplier..."><?php echo htmlspecialchars($supplier['notes']); ?></textarea>
                        <div class="form-help-text">Internal notes, special terms, or important information</div>
                    </div>
                </div>
            </div>

            <!-- Status -->
            <div class="form-section" style="border-left-color: #6f42c1;">
                <h5 style="color: #6f42c1;"><i class="fas fa-toggle-on me-2"></i>Supplier Status</h5>
                <div class="row g-3 align-items-center">
                    <div class="col-md-8">
                        <div class="status-toggle">
                            <label class="form-label me-3">Supplier Status:</label>
                            <label class="switch">
                                <input type="checkbox" name="is_active" id="is_active" 
                                       <?php echo $supplier['is_active'] ? 'checked' : ''; ?> value="1">
                                <span class="slider"></span>
                            </label>
                            <span class="ms-3 fs-6 fw-semibold" id="statusText">
                                <?php echo $supplier['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="form-help-text mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Inactive suppliers will be hidden from purchase order creation
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-section" style="border-left-color: #6c757d; background: #f8f9fa;">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Important:</strong> All changes will be saved immediately and will affect 
                            all existing purchase orders for this supplier.
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <a href="suppliers.php" class="btn btn-outline-secondary btn-lg px-4 me-2">
                            <i class="fas fa-times me-2"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                            <i class="fas fa-save me-2"></i> Update Supplier
                        </button>
                    </div>
                </div>
            </div>

        </form>

    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('editSupplierForm');
    const paymentTermsInput = document.querySelector('input[name="payment_terms"]');
    const paymentTermTags = document.querySelectorAll('.payment-term-tag');
    const isActiveToggle = document.getElementById('is_active');
    const statusText = document.getElementById('statusText');
    const submitBtn = document.getElementById('submitBtn');

    // Payment terms tags functionality
    paymentTermTags.forEach(tag => {
        tag.addEventListener('click', function() {
            paymentTermTags.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            paymentTermsInput.value = this.dataset.days;
        });
    });

    // Status toggle
    isActiveToggle.addEventListener('change', function() {
        statusText.textContent = this.checked ? 'Active' : 'Inactive';
        statusText.className = `ms-3 fs-6 fw-semibold text-${this.checked ? 'success' : 'danger'}`;
    });

    // Phone number formatting
    const phoneInput = document.querySelector('input[name="phone"]');
    phoneInput.addEventListener('input', function() {
        let value = this.value.replace(/\D/g, '');
        if (value.startsWith('255')) {
            value = value.substring(3);
        }
        if (value.length > 9) {
            value = value.substring(0, 9);
        }
        if (value.length >= 3) {
            this.value = '+255 ' + value;
        } else {
            this.value = value;
        }
    });

    // Form validation and submission
    form.addEventListener('submit', function(e) {
        const requiredFields = ['supplier_name', 'phone', 'payment_terms'];
        let isValid = true;

        requiredFields.forEach(field => {
            const input = document.querySelector(`[name="${field}"]`);
            if (!input.value.trim()) {
                input.classList.add('is-invalid');
                isValid = false;
            } else {
                input.classList.remove('is-invalid');
            }
        });

        // Email validation
        const emailInput = document.querySelector('[name="email"]');
        if (emailInput.value && !emailInput.value.includes('@')) {
            emailInput.classList.add('is-invalid');
            isValid = false;
        } else {
            emailInput.classList.remove('is-invalid');
        }

        if (!isValid) {
            e.preventDefault();
            alert('Please fill all required fields correctly');
            return false;
        }

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating Supplier...';
    });

    // Real-time validation feedback
    const inputs = document.querySelectorAll('input[required], select[required]');
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value.trim()) {
                this.classList.add('is-valid');
                this.classList.remove('is-invalid');
            }
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>