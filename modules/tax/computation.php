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

// ==================== FETCH ACTIVE TAX TYPES ====================
$tax_types = [];
try {
    $stmt = $conn->prepare("
        SELECT tax_type_id, tax_name, tax_code, tax_category, tax_rate, 
               calculation_method, is_inclusive
        FROM tax_types
        WHERE company_id = ? AND is_active = 1
        ORDER BY tax_category, tax_name
    ");
    $stmt->execute([$company_id]);
    $tax_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Tax types fetch error: " . $e->getMessage());
}

$page_title = 'Tax Computation';
require_once '../../includes/header.php';
?>

<style>
.calculator-card {
    background: #fff;
    border-radius: 8px;
    padding: 2rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
}

.calculator-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 8px 8px 0 0;
    margin: -2rem -2rem 2rem -2rem;
}

.amount-input-group {
    position: relative;
    margin-bottom: 1.5rem;
}

.amount-input {
    font-size: 2rem;
    font-weight: 700;
    text-align: center;
    border: 3px solid #007bff;
    border-radius: 8px;
    padding: 1rem;
}

.amount-input:focus {
    border-color: #0056b3;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}

.currency-label {
    position: absolute;
    left: 1.5rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1.5rem;
    font-weight: 700;
    color: #6c757d;
}

.tax-selector {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.tax-option {
    display: flex;
    align-items: center;
    padding: 1rem;
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    margin-bottom: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
}

.tax-option:hover {
    border-color: #007bff;
    background: #f0f7ff;
}

.tax-option.selected {
    border-color: #007bff;
    background: #e7f3ff;
}

.tax-option input[type="checkbox"] {
    width: 20px;
    height: 20px;
    margin-right: 1rem;
}

.tax-info {
    flex: 1;
}

.tax-name {
    font-weight: 700;
    font-size: 0.95rem;
    color: #2c3e50;
}

.tax-details {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.tax-rate {
    font-size: 1.25rem;
    font-weight: 700;
    color: #007bff;
    min-width: 80px;
    text-align: right;
}

.results-section {
    background: #fff;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.result-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid #e9ecef;
}

.result-row:last-child {
    border-bottom: none;
}

.result-label {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 600;
}

.result-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2c3e50;
}

.total-row {
    background: #f8f9fa;
    margin: 0 -1.5rem;
    padding: 1rem 1.5rem;
    border-radius: 0 0 8px 8px;
}

.total-row .result-label {
    font-size: 1rem;
    color: #2c3e50;
}

.total-row .result-value {
    font-size: 1.5rem;
    color: #007bff;
}

.breakdown-card {
    background: #fff;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 1rem;
}

.breakdown-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
}

.calculation-mode {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.mode-option {
    flex: 1;
    padding: 1rem;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}

.mode-option:hover {
    border-color: #007bff;
}

.mode-option.active {
    border-color: #007bff;
    background: #e7f3ff;
}

.mode-title {
    font-weight: 700;
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
}

.mode-description {
    font-size: 0.75rem;
    color: #6c757d;
}

@media (max-width: 768px) {
    .amount-input {
        font-size: 1.5rem;
    }
    
    .currency-label {
        font-size: 1.25rem;
        left: 1rem;
    }
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-calculator me-2"></i>Tax Computation
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="types.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-list me-1"></i>Tax Types
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <div class="row">
        <!-- Calculator Section -->
        <div class="col-md-7">
            <div class="calculator-card">
                <div class="calculator-header">
                    <h4 style="margin: 0;">
                        <i class="fas fa-calculator me-2"></i>Tax Calculator
                    </h4>
                    <p style="margin: 0.5rem 0 0 0; opacity: 0.9; font-size: 0.9rem;">
                        Calculate taxes on your transactions
                    </p>
                </div>

                <!-- Calculation Mode -->
                <div class="calculation-mode">
                    <div class="mode-option active" data-mode="exclusive">
                        <div class="mode-title">Tax Exclusive</div>
                        <div class="mode-description">Add tax to amount</div>
                    </div>
                    <div class="mode-option" data-mode="inclusive">
                        <div class="mode-title">Tax Inclusive</div>
                        <div class="mode-description">Extract tax from amount</div>
                    </div>
                </div>

                <!-- Amount Input -->
                <div class="amount-input-group">
                    <span class="currency-label">TSH</span>
                    <input type="number" id="baseAmount" class="form-control amount-input" 
                           placeholder="0.00" value="100000" step="0.01" min="0">
                </div>

                <!-- Tax Selection -->
                <div class="tax-selector">
                    <h6 style="font-weight: 700; margin-bottom: 1rem;">Select Taxes to Apply</h6>
                    
                    <?php if (empty($tax_types)): ?>
                        <div class="text-center py-3">
                            <p class="text-muted">No active tax types configured</p>
                            <a href="add-type.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus me-1"></i>Add Tax Type
                            </a>
                        </div>
                    <?php else: ?>
                        <?php
                        $categories = [];
                        foreach ($tax_types as $tax) {
                            $categories[$tax['tax_category']][] = $tax;
                        }
                        ?>
                        
                        <?php foreach ($categories as $category => $taxes): ?>
                            <h6 style="font-size: 0.8rem; text-transform: uppercase; color: #6c757d; margin-top: 1rem; margin-bottom: 0.5rem;">
                                <?= ucfirst($category) ?> Taxes
                            </h6>
                            <?php foreach ($taxes as $tax): ?>
                            <label class="tax-option" data-tax-id="<?= $tax['tax_type_id'] ?>">
                                <input type="checkbox" class="tax-checkbox" 
                                       data-rate="<?= $tax['tax_rate'] ?>"
                                       data-name="<?= htmlspecialchars($tax['tax_name']) ?>"
                                       data-code="<?= htmlspecialchars($tax['tax_code']) ?>"
                                       data-inclusive="<?= $tax['is_inclusive'] ?>">
                                <div class="tax-info">
                                    <div class="tax-name"><?= htmlspecialchars($tax['tax_name']) ?></div>
                                    <div class="tax-details">
                                        Code: <?= htmlspecialchars($tax['tax_code']) ?> • 
                                        <?= ucfirst($tax['calculation_method']) ?>
                                    </div>
                                </div>
                                <div class="tax-rate"><?= number_format($tax['tax_rate'], 2) ?>%</div>
                            </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary flex-fill" id="clearBtn">
                        <i class="fas fa-eraser me-1"></i>Clear All
                    </button>
                    <button type="button" class="btn btn-primary flex-fill" id="calculateBtn">
                        <i class="fas fa-calculator me-1"></i>Calculate
                    </button>
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <div class="col-md-5">
            <div class="results-section">
                <h5 style="font-weight: 700; margin-bottom: 1.5rem;">
                    <i class="fas fa-chart-line me-2"></i>Calculation Results
                </h5>

                <div id="resultsContent">
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-calculator fa-3x mb-3"></i>
                        <p>Enter amount and select taxes to see results</p>
                    </div>
                </div>
            </div>

            <!-- Breakdown Section -->
            <div id="breakdownSection" style="display: none;">
                <div class="breakdown-card">
                    <div class="breakdown-title">
                        <i class="fas fa-list-ul me-2"></i>Detailed Breakdown
                    </div>
                    <div id="breakdownContent"></div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const baseAmountInput = document.getElementById('baseAmount');
    const calculateBtn = document.getElementById('calculateBtn');
    const clearBtn = document.getElementById('clearBtn');
    const resultsContent = document.getElementById('resultsContent');
    const breakdownSection = document.getElementById('breakdownSection');
    const breakdownContent = document.getElementById('breakdownContent');
    const taxOptions = document.querySelectorAll('.tax-option');
    const taxCheckboxes = document.querySelectorAll('.tax-checkbox');
    const modeOptions = document.querySelectorAll('.mode-option');
    
    let calculationMode = 'exclusive';
    
    // Mode selection
    modeOptions.forEach(option => {
        option.addEventListener('click', function() {
            modeOptions.forEach(opt => opt.classList.remove('active'));
            option.classList.add('active');
            calculationMode = option.dataset.mode;
            calculate();
        });
    });
    
    // Tax option selection
    taxOptions.forEach(option => {
        option.addEventListener('click', function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = option.querySelector('.tax-checkbox');
                checkbox.checked = !checkbox.checked;
            }
            option.classList.toggle('selected', option.querySelector('.tax-checkbox').checked);
            calculate();
        });
    });
    
    // Auto-calculate on amount change
    baseAmountInput.addEventListener('input', calculate);
    
    // Calculate button
    calculateBtn.addEventListener('click', calculate);
    
    // Clear button
    clearBtn.addEventListener('click', function() {
        taxCheckboxes.forEach(cb => {
            cb.checked = false;
        });
        taxOptions.forEach(opt => opt.classList.remove('selected'));
        baseAmountInput.value = '';
        resultsContent.innerHTML = `
            <div class="text-center py-5 text-muted">
                <i class="fas fa-calculator fa-3x mb-3"></i>
                <p>Enter amount and select taxes to see results</p>
            </div>
        `;
        breakdownSection.style.display = 'none';
    });
    
    function calculate() {
        const baseAmount = parseFloat(baseAmountInput.value) || 0;
        
        if (baseAmount <= 0) {
            resultsContent.innerHTML = `
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-calculator fa-3x mb-3"></i>
                    <p>Enter amount and select taxes to see results</p>
                </div>
            `;
            breakdownSection.style.display = 'none';
            return;
        }
        
        const selectedTaxes = Array.from(taxCheckboxes)
            .filter(cb => cb.checked)
            .map(cb => ({
                name: cb.dataset.name,
                code: cb.dataset.code,
                rate: parseFloat(cb.dataset.rate),
                isInclusive: cb.dataset.inclusive === '1'
            }));
        
        if (selectedTaxes.length === 0) {
            resultsContent.innerHTML = `
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-hand-pointer fa-3x mb-3"></i>
                    <p>Select at least one tax to calculate</p>
                </div>
            `;
            breakdownSection.style.display = 'none';
            return;
        }
        
        let results = {};
        let breakdown = [];
        
        if (calculationMode === 'exclusive') {
            // Tax Exclusive: Add taxes to base amount
            let runningTotal = baseAmount;
            let totalTax = 0;
            
            selectedTaxes.forEach(tax => {
                const taxAmount = (baseAmount * tax.rate) / 100;
                totalTax += taxAmount;
                
                breakdown.push({
                    name: tax.name,
                    code: tax.code,
                    rate: tax.rate,
                    base: baseAmount,
                    amount: taxAmount
                });
            });
            
            results = {
                baseAmount: baseAmount,
                totalTax: totalTax,
                grandTotal: baseAmount + totalTax,
                breakdown: breakdown
            };
            
        } else {
            // Tax Inclusive: Extract taxes from total amount
            let totalRate = selectedTaxes.reduce((sum, tax) => sum + tax.rate, 0);
            let baseAmountCalc = baseAmount / (1 + (totalRate / 100));
            let totalTax = 0;
            
            selectedTaxes.forEach(tax => {
                const taxAmount = (baseAmountCalc * tax.rate) / 100;
                totalTax += taxAmount;
                
                breakdown.push({
                    name: tax.name,
                    code: tax.code,
                    rate: tax.rate,
                    base: baseAmountCalc,
                    amount: taxAmount
                });
            });
            
            results = {
                baseAmount: baseAmountCalc,
                totalTax: totalTax,
                grandTotal: baseAmount,
                breakdown: breakdown
            };
        }
        
        // Display results
        displayResults(results);
        displayBreakdown(results.breakdown);
    }
    
    function displayResults(results) {
        resultsContent.innerHTML = `
            <div class="result-row">
                <div class="result-label">
                    ${calculationMode === 'exclusive' ? 'Base Amount' : 'Amount (Incl. Tax)'}
                </div>
                <div class="result-value">TSH ${formatNumber(results.baseAmount)}</div>
            </div>
            <div class="result-row">
                <div class="result-label">Total Tax</div>
                <div class="result-value" style="color: #dc3545;">TSH ${formatNumber(results.totalTax)}</div>
            </div>
            <div class="total-row result-row">
                <div class="result-label">
                    ${calculationMode === 'exclusive' ? 'Total Amount' : 'Base Amount (Excl. Tax)'}
                </div>
                <div class="result-value">
                    TSH ${formatNumber(calculationMode === 'exclusive' ? results.grandTotal : results.baseAmount)}
                </div>
            </div>
        `;
    }
    
    function displayBreakdown(breakdown) {
        if (breakdown.length === 0) {
            breakdownSection.style.display = 'none';
            return;
        }
        
        let html = '';
        breakdown.forEach(item => {
            html += `
                <div class="result-row">
                    <div>
                        <div style="font-weight: 600; font-size: 0.85rem;">${item.name}</div>
                        <div style="font-size: 0.75rem; color: #6c757d;">
                            ${item.rate}% on TSH ${formatNumber(item.base)}
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-weight: 700; color: #dc3545;">TSH ${formatNumber(item.amount)}</div>
                        <div style="font-size: 0.75rem; color: #6c757d;">${item.code}</div>
                    </div>
                </div>
            `;
        });
        
        breakdownContent.innerHTML = html;
        breakdownSection.style.display = 'block';
    }
    
    function formatNumber(num) {
        return num.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // Initial calculation if amount is present
    if (baseAmountInput.value) {
        calculate();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>