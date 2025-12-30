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

$page_title = 'Add Supplier';
require_once '../../includes/header.php';
?>

<style>
.supplier-card {
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
    padding: 0.75rem;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    font-family: 'Courier New', monospace;
    font-size: 1.1rem;
    font-weight: 700;
    color: #17a2b8;
    text-align: center;
}

.rating-stars {
    display: flex;
    gap: 0.5rem;
}

.rating-stars input[type="radio"] {
    display: none;
}

.rating-stars label {
    font-size: 1.5rem;
    color: #dee2e6;
    cursor: pointer;
    transition: color 0.2s;
}

.rating-stars input[type="radio"]:checked ~ label,
.rating-stars label:hover,
.rating-stars label:hover ~ label {
    color: #ffc107;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-truck text-info me-2"></i>Add New Supplier
                </h1>
                <p class="text-muted small mb-0 mt-1">Register a new supplier</p>
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

                <div class="supplier-card">
                    <form action="process-supplier.php" method="POST" id="supplierForm">
                        
                        <!-- Basic Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Basic Information
                            </div>
                            
                            <div class="alert-info-custom">
                                <i class="fas fa-lightbulb me-2"></i>
                                <strong>Tip:</strong> Provide complete and accurate supplier information for better procurement management.
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-8">
                                    <label class="form-label fw-bold">Supplier Name *</label>
                                    <input type="text" 
                                           name="supplier_name" 
                                           id="supplierName"
                                           class="form-control" 
                                           placeholder="Enter supplier business name"
                                           required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Supplier Code</label>
                                    <input type="text" 
                                           name="supplier_code" 
                                           id="supplierCode"
                                           class="form-control" 
                                           placeholder="Auto-generated"
                                           readonly>
                                    <small class="text-muted">Auto-generated from name</small>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Supplier Type</label>
                                    <select name="supplier_type" class="form-select">
                                        <option value="manufacturer">Manufacturer</option>
                                        <option value="distributor">Distributor</option>
                                        <option value="wholesaler">Wholesaler</option>
                                        <option value="retailer">Retailer</option>
                                        <option value="service_provider">Service Provider</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Category</label>
                                    <input type="text" 
                                           name="category" 
                                           class="form-control" 
                                           placeholder="e.g., Office Supplies, IT Equipment">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Tax Identification Number (TIN)</label>
                                <input type="text" 
                                       name="tin_number" 
                                       class="form-control" 
                                       placeholder="Tax ID / TIN">
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-address-book"></i>
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
                                    <label class="form-label fw-bold">Position / Title</label>
                                    <input type="text" 
                                           name="contact_title" 
                                           class="form-control" 
                                           placeholder="e.g., Sales Manager">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Phone Number *</label>
                                    <input type="tel" 
                                           name="phone" 
                                           class="form-control" 
                                           placeholder="+255 XXX XXX XXX"
                                           required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Alternative Phone</label>
                                    <input type="tel" 
                                           name="alternative_phone" 
                                           class="form-control" 
                                           placeholder="+255 XXX XXX XXX">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Email Address *</label>
                                    <input type="email" 
                                           name="email" 
                                           class="form-control" 
                                           placeholder="supplier@example.com"
                                           required>
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

                        <!-- Address Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-map-marker-alt"></i>
                                Address Information
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Street Address</label>
                                <textarea name="address" 
                                          class="form-control" 
                                          rows="2" 
                                          placeholder="Building name, street, P.O. Box..."></textarea>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">City *</label>
                                    <input type="text" 
                                           name="city" 
                                           class="form-control" 
                                           placeholder="e.g., Dar es Salaam"
                                           required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Region / State</label>
                                    <input type="text" 
                                           name="region" 
                                           class="form-control" 
                                           placeholder="Region or State">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Country *</label>
                                    <input type="text" 
                                           name="country" 
                                           class="form-control" 
                                           value="Tanzania"
                                           required>
                                </div>
                            </div>
                        </div>

                        <!-- Banking Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-university"></i>
                                Banking Information (Optional)
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Bank Name</label>
                                    <input type="text" 
                                           name="bank_name" 
                                           class="form-control" 
                                           placeholder="e.g., CRDB Bank">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Account Number</label>
                                    <input type="text" 
                                           name="bank_account" 
                                           class="form-control" 
                                           placeholder="Bank account number">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Account Name</label>
                                    <input type="text" 
                                           name="account_name" 
                                           class="form-control" 
                                           placeholder="Account holder name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">SWIFT / Bank Code</label>
                                    <input type="text" 
                                           name="swift_code" 
                                           class="form-control" 
                                           placeholder="SWIFT or bank branch code">
                                </div>
                            </div>
                        </div>

                        <!-- Business Terms -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-handshake"></i>
                                Business Terms & Settings
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Payment Terms</label>
                                    <select name="payment_terms" class="form-select">
                                        <option value="net_30">Net 30 Days</option>
                                        <option value="net_15">Net 15 Days</option>
                                        <option value="net_7">Net 7 Days</option>
                                        <option value="due_on_receipt">Due on Receipt</option>
                                        <option value="advance_payment">Advance Payment</option>
                                        <option value="cod">Cash on Delivery (COD)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Credit Limit (TSH)</label>
                                    <input type="number" 
                                           name="credit_limit" 
                                           class="form-control" 
                                           min="0"
                                           step="0.01"
                                           placeholder="0.00">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Lead Time (Days)</label>
                                    <input type="number" 
                                           name="lead_time_days" 
                                           class="form-control" 
                                           min="0"
                                           placeholder="Average delivery days">
                                    <small class="text-muted">Typical delivery time</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Supplier Rating</label>
                                    <div class="rating-stars" id="ratingStars">
                                        <input type="radio" name="rating" value="5" id="star5">
                                        <label for="star5">★</label>
                                        <input type="radio" name="rating" value="4" id="star4">
                                        <label for="star4">★</label>
                                        <input type="radio" name="rating" value="3" id="star3" checked>
                                        <label for="star3">★</label>
                                        <input type="radio" name="rating" value="2" id="star2">
                                        <label for="star2">★</label>
                                        <input type="radio" name="rating" value="1" id="star1">
                                        <label for="star1">★</label>
                                    </div>
                                    <small class="text-muted">Rate supplier performance</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Notes / Comments</label>
                                <textarea name="notes" 
                                          class="form-control" 
                                          rows="3" 
                                          placeholder="Additional information, special terms, certifications..."></textarea>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" 
                                       class="form-check-input" 
                                       id="isActive" 
                                       name="is_active" 
                                       value="1" 
                                       checked>
                                <label class="form-check-label" for="isActive">
                                    <strong>Active Supplier</strong>
                                    <small class="d-block text-muted">Inactive suppliers won't appear in purchase order forms</small>
                                </label>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="suppliers.php" class="btn btn-secondary">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-info btn-lg">
                                <i class="fas fa-save me-1"></i> Save Supplier
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
    const supplierNameInput = document.getElementById('supplierName');
    const supplierCodeInput = document.getElementById('supplierCode');
    
    // Auto-generate supplier code from name
    supplierNameInput.addEventListener('input', function() {
        const name = this.value.trim();
        if (name) {
            // Take first 3 letters of each word, uppercase
            const words = name.split(' ').filter(word => word.length > 0);
            let code = words.map(word => word.substring(0, 3).toUpperCase()).join('');
            
            // Limit to 10 characters
            code = code.substring(0, 10);
            
            // Add random number suffix
            const randomNum = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            code = code + randomNum;
            
            supplierCodeInput.value = code;
        } else {
            supplierCodeInput.value = '';
        }
    });

    // Rating stars functionality
    const ratingStars = document.getElementById('ratingStars');
    const stars = ratingStars.querySelectorAll('label');
    const radios = ratingStars.querySelectorAll('input[type="radio"]');
    
    radios.forEach(radio => {
        radio.addEventListener('change', function() {
            updateStars();
        });
    });
    
    function updateStars() {
        const checkedValue = document.querySelector('input[name="rating"]:checked')?.value || 3;
        stars.forEach(star => {
            const starValue = star.getAttribute('for').replace('star', '');
            if (parseInt(starValue) >= parseInt(checkedValue)) {
                star.style.color = '#ffc107';
            } else {
                star.style.color = '#dee2e6';
            }
        });
    }
    
    // Initialize stars
    updateStars();

    // Form validation
    document.getElementById('supplierForm').addEventListener('submit', function(e) {
        const email = document.querySelector('input[name="email"]').value;
        const phone = document.querySelector('input[name="phone"]').value;
        
        // Basic email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            e.preventDefault();
            alert('Please enter a valid email address!');
            return false;
        }

        return confirm('Are you sure you want to save this supplier?');
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>