<?php
define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$page_title = 'Add Tax Type';
require_once '../../includes/header.php';
?>

<style>
.form-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.form-section {
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 2px solid #e9ecef;
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
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #007bff;
    display: flex;
    align-items: center;
}

.section-title i {
    margin-right: 10px;
    color: #007bff;
}

.form-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
}

.form-label .required {
    color: #dc3545;
    margin-left: 3px;
}

.help-text {
    font-size: 0.875rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.example-card {
    background: #f8f9fa;
    border-left: 4px solid #17a2b8;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.example-header {
    font-weight: 700;
    color: #0c5460;
    margin-bottom: 0.5rem;
}

.example-item {
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0;
}

.switch-container {
    display: flex;
    align-items: center;
}

.form-switch .form-check-input {
    width: 3rem;
    height: 1.5rem;
    cursor: pointer;
}

.form-switch .form-check-label {
    margin-left: 0.5rem;
    cursor: pointer;
}

.btn-action {
    min-width: 120px;
}

.tax-code-preview {
    font-family: 'Courier New', monospace;
    font-size: 1.1rem;
    font-weight: 700;
    color: #007bff;
    padding: 0.5rem 1rem;
    background: #e7f3ff;
    border-radius: 6px;
    display: inline-block;
    margin-top: 0.5rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-plus-circle text-primary me-2"></i>Add Tax Type
                </h1>
                <p class="text-muted small mb-0 mt-1">Create a new tax type (VAT, WHT, etc.)</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="types.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Tax Types
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <div class="row">
            <!-- Form Column -->
            <div class="col-lg-8">
                <div class="form-card">
                    <form action="process-type.php" method="POST" id="taxTypeForm">
                        
                        <!-- Basic Information -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Basic Information
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="tax_name" class="form-label">
                                        Tax Name<span class="required">*</span>
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="tax_name" 
                                           name="tax_name" 
                                           placeholder="e.g., Value Added Tax"
                                           required>
                                    <div class="help-text">Full name of the tax type</div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="tax_code" class="form-label">
                                        Tax Code<span class="required">*</span>
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="tax_code" 
                                           name="tax_code" 
                                           placeholder="e.g., VAT-18"
                                           maxlength="20"
                                           required>
                                    <div class="help-text">Unique identifier code</div>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" 
                                              id="description" 
                                              name="description" 
                                              rows="3"
                                              placeholder="Describe this tax type, when it applies, etc."></textarea>
                                    <div class="help-text">Optional details about this tax</div>
                                </div>
                            </div>
                        </div>

                        <!-- Tax Rate -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-percentage"></i>
                                Tax Rate
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="tax_rate" class="form-label">
                                        Rate (%)<span class="required">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="number" 
                                               class="form-control" 
                                               id="tax_rate" 
                                               name="tax_rate" 
                                               placeholder="0.00"
                                               step="0.01"
                                               min="0"
                                               max="100"
                                               required>
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <div class="help-text">Tax rate as a percentage (0-100)</div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Rate Preview</label>
                                    <div class="tax-code-preview" id="ratePreview">
                                        0.00%
                                    </div>
                                    <div class="help-text">Preview of the tax rate</div>
                                </div>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-toggle-on"></i>
                                Status
                            </div>
                            
                            <div class="form-check form-switch switch-container">
                                <input class="form-check-input" 
                                       type="checkbox" 
                                       id="is_active" 
                                       name="is_active" 
                                       value="1"
                                       checked>
                                <label class="form-check-label" for="is_active">
                                    <strong>Active</strong> - This tax type is available for use in transactions
                                </label>
                            </div>
                            <div class="help-text mt-2">
                                Inactive tax types cannot be used in new transactions
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="types.php" class="btn btn-outline-secondary btn-action">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary btn-action">
                                <i class="fas fa-save me-1"></i> Save Tax Type
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Examples Column -->
            <div class="col-lg-4">
                <div class="form-card">
                    <h5 class="mb-4">
                        <i class="fas fa-lightbulb text-warning me-2"></i>
                        Common Tax Types
                    </h5>

                    <div class="example-card">
                        <div class="example-header">Value Added Tax (VAT)</div>
                        <div class="example-item">
                            <span>Code:</span>
                            <strong>VAT-18</strong>
                        </div>
                        <div class="example-item">
                            <span>Rate:</span>
                            <strong>18%</strong>
                        </div>
                        <div class="example-item">
                            <span>Applies to:</span>
                            <span>Goods & Services</span>
                        </div>
                    </div>

                    <div class="example-card" style="border-left-color: #28a745;">
                        <div class="example-header">Withholding Tax (WHT)</div>
                        <div class="example-item">
                            <span>Code:</span>
                            <strong>WHT-5</strong>
                        </div>
                        <div class="example-item">
                            <span>Rate:</span>
                            <strong>5%</strong>
                        </div>
                        <div class="example-item">
                            <span>Applies to:</span>
                            <span>Professional Services</span>
                        </div>
                    </div>

                    <div class="example-card" style="border-left-color: #ffc107;">
                        <div class="example-header">Pay As You Earn (PAYE)</div>
                        <div class="example-item">
                            <span>Code:</span>
                            <strong>PAYE-30</strong>
                        </div>
                        <div class="example-item">
                            <span>Rate:</span>
                            <strong>30%</strong>
                        </div>
                        <div class="example-item">
                            <span>Applies to:</span>
                            <span>Employee Income</span>
                        </div>
                    </div>

                    <div class="example-card" style="border-left-color: #dc3545;">
                        <div class="example-header">Excise Duty</div>
                        <div class="example-item">
                            <span>Code:</span>
                            <strong>EXC-10</strong>
                        </div>
                        <div class="example-item">
                            <span>Rate:</span>
                            <strong>10%</strong>
                        </div>
                        <div class="example-item">
                            <span>Applies to:</span>
                            <span>Specific Goods</span>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Tip:</strong> Choose descriptive codes that include the rate 
                            (e.g., VAT-18, WHT-5) for easy identification.
                        </small>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const taxRateInput = document.getElementById('tax_rate');
    const ratePreview = document.getElementById('ratePreview');
    const form = document.getElementById('taxTypeForm');

    // Update rate preview
    taxRateInput.addEventListener('input', function() {
        const rate = parseFloat(this.value) || 0;
        ratePreview.textContent = rate.toFixed(2) + '%';
    });

    // Form validation
    form.addEventListener('submit', function(e) {
        const taxName = document.getElementById('tax_name').value.trim();
        const taxCode = document.getElementById('tax_code').value.trim();
        const taxRate = parseFloat(document.getElementById('tax_rate').value);

        if (!taxName || !taxCode) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return false;
        }

        if (isNaN(taxRate) || taxRate < 0 || taxRate > 100) {
            e.preventDefault();
            alert('Tax rate must be between 0 and 100');
            return false;
        }

        // Confirm before submit
        const message = `Create tax type:\n\nName: ${taxName}\nCode: ${taxCode}\nRate: ${taxRate.toFixed(2)}%\n\nContinue?`;
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });

    // Auto-generate code suggestion
    const taxNameInput = document.getElementById('tax_name');
    const taxCodeInput = document.getElementById('tax_code');
    
    taxNameInput.addEventListener('blur', function() {
        if (!taxCodeInput.value) {
            // Generate code from name
            const name = this.value.trim().toUpperCase();
            const words = name.split(/\s+/);
            let code = '';
            
            if (words.length === 1) {
                code = words[0].substring(0, 4);
            } else {
                code = words.slice(0, 2).map(w => w.substring(0, 3)).join('-');
            }
            
            taxCodeInput.value = code;
        }
    });
});
</script>

<?php 
require_once '../../includes/footer.php';
?>