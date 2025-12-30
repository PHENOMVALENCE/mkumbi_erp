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
$preview_data = [];

// ==================== HANDLE RUN DEPRECIATION ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_depreciation'])) {
    try {
        $conn->beginTransaction();
        
        $period_date = $_POST['period_date'];
        $selected_assets = $_POST['assets'] ?? [];
        
        if (empty($selected_assets)) {
            throw new Exception("Please select at least one asset to depreciate");
        }
        
        $depreciated_count = 0;
        $total_depreciation = 0;
        
        foreach ($selected_assets as $asset_id) {
            // Get asset details
            $stmt = $conn->prepare("
                SELECT * FROM fixed_assets 
                WHERE asset_id = ? AND company_id = ? AND status IN ('active', 'inactive', 'under_maintenance')
            ");
            $stmt->execute([$asset_id, $company_id]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$asset) continue;
            
            // Calculate depreciation
            $depreciation_amount = calculateDepreciation($asset, $period_date);
            
            if ($depreciation_amount <= 0) continue;
            
            // Check if already depreciated for this period
            $stmt = $conn->prepare("
                SELECT depreciation_id FROM asset_depreciation 
                WHERE asset_id = ? AND period_date = ?
            ");
            $stmt->execute([$asset_id, $period_date]);
            
            if ($stmt->rowCount() > 0) {
                continue; // Already depreciated for this period
            }
            
            // Calculate new values
            $new_accumulated = $asset['accumulated_depreciation'] + $depreciation_amount;
            $new_book_value = $asset['total_cost'] - $new_accumulated;
            
            // Don't depreciate below salvage value
            if ($new_book_value < $asset['salvage_value']) {
                $depreciation_amount = $asset['current_book_value'] - $asset['salvage_value'];
                $new_accumulated = $asset['total_cost'] - $asset['salvage_value'];
                $new_book_value = $asset['salvage_value'];
            }
            
            if ($depreciation_amount <= 0) continue;
            
            // Insert depreciation record
            $stmt = $conn->prepare("
                INSERT INTO asset_depreciation (
                    asset_id, company_id, period_date, depreciation_amount,
                    accumulated_depreciation, book_value, depreciation_method,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $asset_id,
                $company_id,
                $period_date,
                $depreciation_amount,
                $new_accumulated,
                $new_book_value,
                $asset['depreciation_method'],
                $user_id
            ]);
            
            // Update asset
            $stmt = $conn->prepare("
                UPDATE fixed_assets SET
                    accumulated_depreciation = ?,
                    current_book_value = ?,
                    last_depreciation_date = ?,
                    updated_at = NOW(),
                    updated_by = ?
                WHERE asset_id = ? AND company_id = ?
            ");
            
            $stmt->execute([
                $new_accumulated,
                $new_book_value,
                $period_date,
                $user_id,
                $asset_id,
                $company_id
            ]);
            
            $depreciated_count++;
            $total_depreciation += $depreciation_amount;
        }
        
        $conn->commit();
        $success = "Successfully depreciated $depreciated_count asset(s). Total depreciation: TSH " . number_format($total_depreciation, 2);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
        error_log("Depreciation error: " . $e->getMessage());
    }
}

// ==================== HANDLE PREVIEW ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_depreciation'])) {
    try {
        $period_date = $_POST['period_date'];
        $preview_status = $_POST['preview_status'] ?? 'active';
        
        // Get assets eligible for depreciation
        $status_filter = $preview_status == 'all' 
            ? "status IN ('active', 'inactive', 'under_maintenance')"
            : "status = '$preview_status'";
        
        $stmt = $conn->prepare("
            SELECT 
                a.*,
                c.category_name,
                DATEDIFF(?, a.last_depreciation_date) as days_since_last,
                DATEDIFF(?, a.purchase_date) as days_since_purchase
            FROM fixed_assets a
            LEFT JOIN asset_categories c ON a.category_id = c.category_id
            WHERE a.company_id = ? AND $status_filter
            AND a.current_book_value > a.salvage_value
            ORDER BY a.asset_number
        ");
        $stmt->execute([$period_date, $period_date, $company_id]);
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($assets as $asset) {
            $depreciation_amount = calculateDepreciation($asset, $period_date);
            
            // Check if already depreciated
            $stmt = $conn->prepare("
                SELECT depreciation_id FROM asset_depreciation 
                WHERE asset_id = ? AND period_date = ?
            ");
            $stmt->execute([$asset['asset_id'], $period_date]);
            $already_depreciated = $stmt->rowCount() > 0;
            
            $new_accumulated = $asset['accumulated_depreciation'] + $depreciation_amount;
            $new_book_value = $asset['total_cost'] - $new_accumulated;
            
            // Don't go below salvage value
            if ($new_book_value < $asset['salvage_value']) {
                $depreciation_amount = $asset['current_book_value'] - $asset['salvage_value'];
                $new_accumulated = $asset['total_cost'] - $asset['salvage_value'];
                $new_book_value = $asset['salvage_value'];
            }
            
            $preview_data[] = [
                'asset_id' => $asset['asset_id'],
                'asset_number' => $asset['asset_number'],
                'asset_name' => $asset['asset_name'],
                'category_name' => $asset['category_name'],
                'depreciation_method' => $asset['depreciation_method'],
                'current_book_value' => $asset['current_book_value'],
                'depreciation_amount' => $depreciation_amount,
                'new_book_value' => $new_book_value,
                'new_accumulated' => $new_accumulated,
                'already_depreciated' => $already_depreciated,
                'can_depreciate' => $depreciation_amount > 0 && !$already_depreciated
            ];
        }
        
    } catch (Exception $e) {
        $error = "Preview error: " . $e->getMessage();
        error_log("Preview error: " . $e->getMessage());
    }
}

// ==================== DEPRECIATION CALCULATION FUNCTION ====================
function calculateDepreciation($asset, $period_date) {
    $method = $asset['depreciation_method'];
    $total_cost = $asset['total_cost'];
    $salvage_value = $asset['salvage_value'];
    $useful_life_years = $asset['useful_life_years'];
    $current_book_value = $asset['current_book_value'];
    
    $depreciable_amount = $total_cost - $salvage_value;
    
    switch ($method) {
        case 'straight_line':
            // Monthly depreciation = (Cost - Salvage) / (Useful Life in months)
            $monthly_depreciation = $depreciable_amount / ($useful_life_years * 12);
            return $monthly_depreciation;
            
        case 'declining_balance':
            // Double declining balance: 2 / Useful Life * Book Value
            $rate = 2 / $useful_life_years;
            $monthly_rate = $rate / 12;
            return $current_book_value * $monthly_rate;
            
        case 'units_of_production':
            // For now, default to straight line
            // TODO: Implement units tracking
            $monthly_depreciation = $depreciable_amount / ($useful_life_years * 12);
            return $monthly_depreciation;
            
        default:
            return 0;
    }
}

// ==================== FETCH DEPRECIATION HISTORY ====================
$history = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            d.period_date,
            COUNT(DISTINCT d.asset_id) as asset_count,
            SUM(d.depreciation_amount) as total_depreciation,
            u.full_name as created_by_name,
            d.created_at
        FROM asset_depreciation d
        LEFT JOIN users u ON d.created_by = u.user_id
        WHERE d.company_id = ?
        GROUP BY d.period_date, d.created_by, d.created_at
        ORDER BY d.period_date DESC
        LIMIT 12
    ");
    $stmt->execute([$company_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("History fetch error: " . $e->getMessage());
}

// ==================== FETCH STATISTICS ====================
$stats = [
    'total_assets' => 0,
    'depreciating_assets' => 0,
    'total_book_value' => 0,
    'total_accumulated' => 0
];

try {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_assets,
            SUM(CASE WHEN current_book_value > salvage_value THEN 1 ELSE 0 END) as depreciating_assets,
            SUM(current_book_value) as total_book_value,
            SUM(accumulated_depreciation) as total_accumulated
        FROM fixed_assets
        WHERE company_id = ? AND status IN ('active', 'inactive', 'under_maintenance')
    ");
    $stmt->execute([$company_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'total_assets' => (int)($result['total_assets'] ?? 0),
        'depreciating_assets' => (int)($result['depreciating_assets'] ?? 0),
        'total_book_value' => (float)($result['total_book_value'] ?? 0),
        'total_accumulated' => (float)($result['total_accumulated'] ?? 0)
    ];
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
}

$page_title = 'Asset Depreciation';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: #fff;
    border-radius: 6px;
    padding: 1rem;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-top: 3px solid #007bff;
}

.stats-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.stats-label {
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.form-section {
    background: #fff;
    border-radius: 6px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #28a745;
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

.preview-table {
    font-size: 0.85rem;
    margin-top: 1rem;
}

.preview-table th {
    background: #f8f9fa;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.3px;
    padding: 0.75rem 0.5rem;
    white-space: nowrap;
}

.preview-table td {
    padding: 0.6rem 0.5rem;
    vertical-align: middle;
}

.method-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.method-badge.straight_line {
    background: #d4edda;
    color: #155724;
}

.method-badge.declining_balance {
    background: #fff3cd;
    color: #856404;
}

.method-badge.units_of_production {
    background: #d1ecf1;
    color: #0c5460;
}

.history-card {
    background: #fff;
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border-left: 3px solid #17a2b8;
}

.period-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e9ecef;
}

.period-date {
    font-size: 1rem;
    font-weight: 700;
    color: #2c3e50;
}

.depreciation-amount {
    font-size: 1.1rem;
    font-weight: 700;
    color: #dc3545;
}

.already-depreciated {
    background-color: #f8f9fa;
    opacity: 0.7;
}

.can-depreciate {
    background-color: #fff;
}

.depreciation-highlight {
    font-weight: 700;
    color: #dc3545;
}

.info-alert {
    background: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1rem;
    font-size: 0.85rem;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-calculator me-2"></i>Asset Depreciation
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Assets
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

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="stats-card" style="border-top-color: #007bff;">
                <div class="stats-value"><?= $stats['total_assets'] ?></div>
                <div class="stats-label">Total Assets</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="border-top-color: #28a745;">
                <div class="stats-value"><?= $stats['depreciating_assets'] ?></div>
                <div class="stats-label">Depreciating Assets</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="border-top-color: #17a2b8;">
                <div class="stats-value"><?= number_format($stats['total_book_value'] / 1000000, 1) ?>M</div>
                <div class="stats-label">Total Book Value (TSH)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="border-top-color: #dc3545;">
                <div class="stats-value"><?= number_format($stats['total_accumulated'] / 1000000, 1) ?>M</div>
                <div class="stats-label">Accumulated Depreciation</div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Run Depreciation Form -->
        <div class="col-md-7">
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-play-circle me-2"></i>Run Depreciation
                </div>

                <div class="info-alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>How it works:</strong> Select a period date (typically end of month), preview the depreciation 
                    that will be calculated, then run the depreciation process to update asset values.
                </div>

                <form method="POST" id="depreciationForm">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Period Date <span class="text-danger">*</span></label>
                            <input type="date" name="period_date" id="period_date" class="form-control" 
                                   value="<?= date('Y-m-t') ?>" required>
                            <small class="text-muted">Typically last day of the month</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Asset Status Filter</label>
                            <select name="preview_status" class="form-select">
                                <option value="active" selected>Active Assets Only</option>
                                <option value="all">All Depreciating Assets</option>
                                <option value="inactive">Inactive Only</option>
                                <option value="under_maintenance">Under Maintenance Only</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-grid gap-2 d-md-flex">
                        <button type="submit" name="preview_depreciation" class="btn btn-info flex-fill">
                            <i class="fas fa-eye me-2"></i>Preview Depreciation
                        </button>
                    </div>
                </form>

                <!-- Preview Results -->
                <?php if (!empty($preview_data)): ?>
                <div class="mt-4">
                    <h5>Preview Results</h5>
                    <p class="text-muted mb-3">
                        Review the depreciation calculation below. Select assets to depreciate and click "Run Depreciation".
                    </p>

                    <form method="POST" id="runDepreciationForm">
                        <input type="hidden" name="period_date" value="<?= htmlspecialchars($_POST['period_date']) ?>">
                        
                        <div class="table-responsive">
                            <table class="table table-bordered preview-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="selectAll" class="form-check-input">
                                        </th>
                                        <th>Asset</th>
                                        <th>Method</th>
                                        <th class="text-end">Current Book Value</th>
                                        <th class="text-end">Depreciation</th>
                                        <th class="text-end">New Book Value</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_preview_dep = 0;
                                    foreach ($preview_data as $item): 
                                        if ($item['can_depreciate']) {
                                            $total_preview_dep += $item['depreciation_amount'];
                                        }
                                    ?>
                                    <tr class="<?= $item['can_depreciate'] ? 'can-depreciate' : 'already-depreciated' ?>">
                                        <td class="text-center">
                                            <?php if ($item['can_depreciate']): ?>
                                            <input type="checkbox" name="assets[]" value="<?= $item['asset_id'] ?>" 
                                                   class="form-check-input asset-checkbox" checked>
                                            <?php else: ?>
                                            <i class="fas fa-check text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($item['asset_number']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($item['asset_name']) ?></small>
                                        </td>
                                        <td>
                                            <span class="method-badge <?= $item['depreciation_method'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $item['depreciation_method'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-end"><?= number_format($item['current_book_value'], 2) ?></td>
                                        <td class="text-end depreciation-highlight">
                                            <?= number_format($item['depreciation_amount'], 2) ?>
                                        </td>
                                        <td class="text-end"><strong><?= number_format($item['new_book_value'], 2) ?></strong></td>
                                        <td>
                                            <?php if ($item['already_depreciated']): ?>
                                                <span class="badge bg-secondary">Already Depreciated</span>
                                            <?php elseif ($item['can_depreciate']): ?>
                                                <span class="badge bg-success">Ready</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">No Depreciation</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background: #f8f9fa; font-weight: 700;">
                                        <td colspan="4" class="text-end">Total Depreciation:</td>
                                        <td class="text-end depreciation-highlight">
                                            TSH <?= number_format($total_preview_dep, 2) ?>
                                        </td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="d-grid mt-3">
                            <button type="submit" name="run_depreciation" class="btn btn-success btn-lg"
                                    onclick="return confirm('Are you sure you want to run depreciation for selected assets? This action cannot be undone.')">
                                <i class="fas fa-play me-2"></i>Run Depreciation for Selected Assets
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Depreciation History -->
        <div class="col-md-5">
            <div style="background: #fff; border-radius: 6px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                <div class="section-title" style="border-left: 3px solid #17a2b8; padding-left: 0.75rem;">
                    <i class="fas fa-history me-2"></i>Depreciation History
                </div>

                <?php if (empty($history)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No depreciation history found</p>
                        <small class="text-muted">Run your first depreciation to see history here</small>
                    </div>
                <?php else: ?>
                    <div style="max-height: 600px; overflow-y: auto;">
                        <?php foreach ($history as $record): ?>
                        <div class="history-card">
                            <div class="period-header">
                                <div>
                                    <div class="period-date">
                                        <i class="fas fa-calendar me-1"></i>
                                        <?= date('F Y', strtotime($record['period_date'])) ?>
                                    </div>
                                    <small class="text-muted">
                                        <?= $record['asset_count'] ?> asset(s) depreciated
                                    </small>
                                </div>
                                <div class="text-end">
                                    <div class="depreciation-amount">
                                        TSH <?= number_format($record['total_depreciation'], 2) ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="font-size: 0.75rem; color: #6c757d;">
                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($record['created_by_name']) ?>
                                <span class="mx-1">•</span>
                                <?= date('d M Y H:i', strtotime($record['created_at'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select All functionality
    const selectAll = document.getElementById('selectAll');
    const assetCheckboxes = document.querySelectorAll('.asset-checkbox');
    
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            assetCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        });
        
        // Update select all if individual checkboxes change
        assetCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = Array.from(assetCheckboxes).every(cb => cb.checked);
                selectAll.checked = allChecked;
            });
        });
    }
    
    // Auto-set period date to last day of current month
    const periodDate = document.getElementById('period_date');
    if (periodDate && !periodDate.value) {
        const today = new Date();
        const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        periodDate.value = lastDay.toISOString().split('T')[0];
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>