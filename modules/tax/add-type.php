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

$error = '';
$success = '';

// ==================== HANDLE FORM SUBMISSION ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $tax_name = trim($_POST['tax_name']);
        $tax_code = trim($_POST['tax_code']);
        $tax_category = $_POST['tax_category'];
        $tax_rate = (float)$_POST['tax_rate'];
        
        if (!$tax_name || !$tax_code || !$tax_category || $tax_rate < 0) {
            throw new Exception("Please fill in all required fields");
        }
        
        // Check for duplicate code
        $stmt = $conn->prepare("
            SELECT tax_type_id FROM tax_types 
            WHERE tax_code = ? AND company_id = ?
        ");
        $stmt->execute([$tax_code, $company_id]);
        if ($stmt->rowCount() > 0) {
            throw new Exception("Tax code already exists");
        }
        
        // Insert tax type
        $stmt = $conn->prepare("
            INSERT INTO tax_types (
                company_id, tax_name, tax_code, tax_category, tax_rate,
                calculation_method, applies_to, account_code, 
                effective_date, expiry_date, description,
                is_compound, is_inclusive, is_active,
                created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $company_id,
            $tax_name,
            $tax_code,
            $tax_category,
            $tax_rate,
            $_POST['calculation_method'],
            $_POST['applies_to'],
            trim($_POST['account_code']),
            !empty($_POST['effective_date']) ? $_POST['effective_date'] : null,
            !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
            trim($_POST['description']),
            isset($_POST['is_compound']) ? 1 : 0,
            isset($_POST['is_inclusive']) ? 1 : 0,
            isset($_POST['is_active']) ? 1 : 0,
            $user_id
        ]);
        
        $conn->commit();
        
        $_SESSION['success_message'] = "Tax type '$tax_name' created successfully!";
        header("Location: types.php");
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
        error_log("Tax type add error: " . $e->getMessage());
    }
}

$page_title = 'Add Tax Type';
require_once '../../includes/header.php';
?>

<style>
.form-section {
    background: #fff;
    border-radius: 6px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #007bff;
}

.section-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-label {
    font-weight: 600;
    font-size: 0.85rem;
    color: #495057;
    margin-bottom: 0.375rem;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.info-text {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.info-box {
    background: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1rem;
    font-size: 0.85rem;
}

.tax-category-selector {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.category-option {
    padding: 1rem;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    cursor: pointer;
    text-align: center;
    transition: all 0.2s;
    position: relative;
}

.category-option:hover {
    border-color: #007bff;
    background: #f8f9fa;
}

.category-option input[type="radio"] {
    position: absolute;
    opacity: 0;
}

.category-option input[type="radio"]:checked + .category-content {
    color: #007bff;
}

.category-option.selected {
    border-color: #007bff;
    background: #e7f3ff;
}

.category-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    color: #6c757d;
}

.category-option.selected .category-icon {
    color: #007bff;
}

.category-name {
    font-weight: 700;
    font-size: 0.9rem;
}

.category-description {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.25rem;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-plus-circle me-2"></i>Add Tax Type
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="types.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Tax Types
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="info-box">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Tax Types</strong> define the various taxes applicable to your business transactions such as VAT, Withholding Tax, Excise Duty, etc.
    </div>

    <form method="POST" id="taxTypeForm">
        
        <div class="row">
            <div class="col-md-8">
                
                <!-- Tax Category Selection -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-tags me-2"></i>Tax Category
                    </div>
                    
                    <div class="tax-category-selector">
                        <label class="category-option">
                            <input type="radio" name="tax_category" value="vat" required>
                            <div class="category-content">
                                <div class="category-icon"><i class="fas fa-receipt"></i></div>
                                <div class="category-name">VAT</div>
                                <div class="category-description">Value Added Tax</div>
                            </div>
                        </label>
                        
                        <label class="category-option">
                            <input type="radio" name="tax_category" value="withholding" required>
                            <div class="category-content">
                                <div class="category-icon"><i class="fas fa-hand-holding-usd"></i></div>
                                <div class="category-name">Withholding</div>
                                <div class="category-description">Withholding Tax</div>
                            </div>
                        </label>
                        
                        <label class="category-option">
                            <input type="radio" name="tax_category" value="excise" required>
                            <div class="category-content">
                                <div class="category-icon"><i class="fas fa-wine-bottle"></i></div>
                                <div class="category-name">Excise</div>
                                <div class="category-description">Excise Duty</div>
                            </div>
                        </label>
                        
                        <label class="category-option">
                            <input type="radio" name="tax_category" value="customs" required>
                            <div class="category-content">
                                <div class="category-icon"><i class="fas fa-globe-africa"></i></div>
                                <div class="category-name">Customs</div>
                                <div class="category-description">Import/Export Duty</div>
                            </div>
                        </label>
                        
                        <label class="category-option">
                            <input type="radio" name="tax_category" value="other" required>
                            <div class="category-content">
                                <div class="category-icon"><i class="fas fa-ellipsis-h"></i></div>
                                <div class="category-name">Other</div>
                                <div class="category-description">Other Taxes</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Basic Information -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-info-circle me-2"></i>Basic Information
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Tax Name</label>
                            <input type="text" name="tax_name" class="form-control" 
                                   placeholder="e.g., Standard VAT 18%" required>
                            <div class="info-text">Descriptive name for this tax type</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Tax Code</label>
                            <input type="text" name="tax_code" class="form-control" 
                                   placeholder="e.g., VAT18, WHT5" required>
                            <div class="info-text">Unique code identifier</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label required-field">Tax Rate (%)</label>
                            <input type="number" name="tax_rate" class="form-control" 
                                   step="0.01" min="0" max="100" placeholder="18.00" required>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Calculation Method</label>
                            <select name="calculation_method" class="form-select">
                                <option value="percentage" selected>Percentage</option>
                                <option value="fixed">Fixed Amount</option>
                                <option value="tiered">Tiered Rate</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Applies To</label>
                            <select name="applies_to" class="form-select">
                                <option value="sales" selected>Sales</option>
                                <option value="purchases">Purchases</option>
                                <option value="both">Both</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" 
                                  placeholder="Brief description of this tax type..."></textarea>
                    </div>
                </div>

                <!-- Tax Configuration -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-cog me-2"></i>Tax Configuration
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Effective Date</label>
                            <input type="date" name="effective_date" class="form-control">
                            <div class="info-text">Date when this tax becomes effective</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control">
                            <div class="info-text">Optional expiry date for temporary taxes</div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Account Code</label>
                        <input type="text" name="account_code" class="form-control" 
                               placeholder="e.g., 2100, 2200">
                        <div class="info-text">General Ledger account code for this tax</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_compound" id="isCompound">
                                <label class="form-check-label" for="isCompound">
                                    <strong>Compound Tax</strong>
                                    <div class="info-text">Tax calculated on top of other taxes</div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_inclusive" id="isInclusive">
                                <label class="form-check-label" for="isInclusive">
                                    <strong>Tax Inclusive</strong>
                                    <div class="info-text">Tax included in price</div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive" checked>
                                <label class="form-check-label" for="isActive">
                                    <strong>Active</strong>
                                    <div class="info-text">Enable this tax type</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="col-md-4">
                
                <!-- Quick Reference -->
                <div class="form-section" style="border-left-color: #17a2b8;">
                    <div class="section-title">
                        <i class="fas fa-book me-2"></i>Quick Reference
                    </div>
                    
                    <h6 style="font-size: 0.85rem; font-weight: 700; margin-bottom: 0.75rem;">Tanzania Tax Rates</h6>
                    
                    <div style="font-size: 0.8rem;">
                        <div style="padding: 0.5rem 0; border-bottom: 1px solid #e9ecef;">
                            <strong>Standard VAT:</strong> 18%
                        </div>
                        <div style="padding: 0.5rem 0; border-bottom: 1px solid #e9ecef;">
                            <strong>Zero-Rated VAT:</strong> 0%
                        </div>
                        <div style="padding: 0.5rem 0; border-bottom: 1px solid #e9ecef;">
                            <strong>WHT - Services:</strong> 5%
                        </div>
                        <div style="padding: 0.5rem 0; border-bottom: 1px solid #e9ecef;">
                            <strong>WHT - Consultancy:</strong> 15%
                        </div>
                        <div style="padding: 0.5rem 0; border-bottom: 1px solid #e9ecef;">
                            <strong>WHT - Dividends:</strong> 10%
                        </div>
                        <div style="padding: 0.5rem 0;">
                            <strong>Skills & Development Levy:</strong> 5%
                        </div>
                    </div>
                </div>

                <!-- Examples -->
                <div class="form-section" style="border-left-color: #28a745;">
                    <div class="section-title">
                        <i class="fas fa-lightbulb me-2"></i>Examples
                    </div>
                    
                    <div style="font-size: 0.8rem;">
                        <p style="margin-bottom: 0.75rem;">
                            <strong>Standard VAT:</strong><br>
                            Name: Standard VAT 18%<br>
                            Code: VAT18<br>
                            Rate: 18%
                        </p>
                        
                        <p style="margin-bottom: 0.75rem;">
                            <strong>Withholding Tax:</strong><br>
                            Name: WHT Services 5%<br>
                            Code: WHT5<br>
                            Rate: 5%
                        </p>
                        
                        <p style="margin-bottom: 0;">
                            <strong>Excise Duty:</strong><br>
                            Name: Excise - Alcohol<br>
                            Code: EXC30<br>
                            Rate: 30%
                        </p>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="form-section">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save me-2"></i>Save Tax Type
                        </button>
                        <a href="types.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                    </div>
                </div>

            </div>
        </div>

    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Category selection
    const categoryOptions = document.querySelectorAll('.category-option');
    
    categoryOptions.forEach(option => {
        const radio = option.querySelector('input[type="radio"]');
        
        option.addEventListener('click', function() {
            categoryOptions.forEach(opt => opt.classList.remove('selected'));
            option.classList.add('selected');
        });
        
        if (radio.checked) {
            option.classList.add('selected');
        }
    });
    
    // Form validation
    document.getElementById('taxTypeForm').addEventListener('submit', function(e) {
        const taxRate = parseFloat(document.querySelector('[name="tax_rate"]').value);
        
        if (taxRate < 0 || taxRate > 100) {
            e.preventDefault();
            alert('Tax rate must be between 0 and 100');
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>