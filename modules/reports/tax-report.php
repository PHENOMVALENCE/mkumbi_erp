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

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$tax_category = $_GET['tax_category'] ?? '';

// Fetch tax transactions
$tax_data = [];
$category_totals = [];

try {
    $query = "
        SELECT 
            tt.*,
            tax.tax_name,
            tax.tax_code,
            tax.tax_rate,
            tax.tax_category,
            c.full_name as customer_name,
            s.supplier_name
        FROM tax_transactions tt
        INNER JOIN tax_types tax ON tt.tax_type_id = tax.tax_type_id
        LEFT JOIN customers c ON tt.customer_id = c.customer_id
        LEFT JOIN suppliers s ON tt.supplier_id = s.supplier_id
        WHERE tt.company_id = ?
        AND tt.transaction_date BETWEEN ? AND ?
    ";
    
    $params = [$company_id, $start_date, $end_date];
    
    if ($tax_category) {
        $query .= " AND tax.tax_category = ?";
        $params[] = $tax_category;
    }
    
    $query .= " ORDER BY tt.transaction_date DESC, tax.tax_category";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $tax_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate category totals
    foreach ($tax_data as $row) {
        $cat = $row['tax_category'];
        if (!isset($category_totals[$cat])) {
            $category_totals[$cat] = [
                'taxable_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'count' => 0
            ];
        }
        $category_totals[$cat]['taxable_amount'] += $row['taxable_amount'];
        $category_totals[$cat]['tax_amount'] += $row['tax_amount'];
        $category_totals[$cat]['total_amount'] += $row['total_amount'];
        $category_totals[$cat]['count']++;
    }
    
} catch (Exception $e) {
    error_log("Tax query error: " . $e->getMessage());
}

$page_title = 'Tax Report';
require_once '../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .content-wrapper { margin: 0 !important; }
    .table { font-size: 10px; }
}

.report-header {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    color: white;
    padding: 2rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.tax-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.tax-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    border-left: 4px solid;
}

.tax-card.vat { border-left-color: #007bff; }
.tax-card.wht { border-left-color: #28a745; }
.tax-card.excise { border-left-color: #ffc107; }
.tax-card.other { border-left-color: #6c757d; }

.tax-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
}

.tax-label {
    font-size: 0.875rem;
    color: #6c757d;
    text-transform: uppercase;
}

.table-professional {
    font-size: 0.85rem;
}

.table-professional thead th {
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    padding: 0.65rem 0.5rem;
    border-bottom: 2px solid #dee2e6;
}

.category-header {
    background: #e9ecef;
    font-weight: 600;
}

.totals-row {
    background: #f8f9fa;
    font-weight: 700;
    border-top: 2px solid #dee2e6;
}
</style>

<div class="content-header no-print">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Tax Report</h1>
            </div>
            <div class="col-sm-6 text-end">
                <button onclick="window.print()" class="btn btn-primary btn-sm">
                    <i class="fas fa-print me-1"></i>Print
                </button>
                <button onclick="exportToExcel()" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i>Export
                </button>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Report Header -->
    <div class="report-header">
        <h2 class="mb-1">Tax Report</h2>
        <p class="mb-0">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
        <p class="mb-0"><small>Generated: <?= date('d M Y, h:i A') ?></small></p>
    </div>

    <!-- Filters -->
    <div class="card no-print mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Start Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">End Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $end_date ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Tax Category</label>
                    <select name="tax_category" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        <option value="vat" <?= $tax_category == 'vat' ? 'selected' : '' ?>>VAT</option>
                        <option value="withholding" <?= $tax_category == 'withholding' ? 'selected' : '' ?>>Withholding Tax</option>
                        <option value="excise" <?= $tax_category == 'excise' ? 'selected' : '' ?>>Excise Duty</option>
                        <option value="customs" <?= $tax_category == 'customs' ? 'selected' : '' ?>>Customs Duty</option>
                        <option value="other" <?= $tax_category == 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tax Summary by Category -->
    <div class="tax-summary">
        <?php foreach ($category_totals as $cat => $totals): ?>
        <div class="tax-card <?= $cat ?>">
            <div class="tax-label"><?= strtoupper(str_replace('_', ' ', $cat)) ?></div>
            <div class="tax-value">TSH <?= number_format($totals['tax_amount'], 2) ?></div>
            <div class="small text-muted mt-1">
                <?= $totals['count'] ?> transactions | 
                Taxable: TSH <?= number_format($totals['taxable_amount'], 0) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tax Transactions Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($tax_data)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No tax transactions found for the selected period</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-professional table-hover" id="taxTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transaction No.</th>
                                <th>Tax Type</th>
                                <th>Category</th>
                                <th>Counterparty</th>
                                <th class="text-end">Taxable Amount</th>
                                <th class="text-center">Rate</th>
                                <th class="text-end">Tax Amount</th>
                                <th class="text-end">Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $current_category = '';
                            $grand_taxable = $grand_tax = $grand_total = 0;
                            
                            foreach ($tax_data as $row): 
                                // Category header
                                if ($current_category != $row['tax_category']):
                                    $current_category = $row['tax_category'];
                            ?>
                                <tr class="category-header">
                                    <td colspan="10">
                                        <i class="fas fa-tag me-2"></i><?= strtoupper(str_replace('_', ' ', $current_category)) ?>
                                    </td>
                                </tr>
                            <?php 
                                endif; 
                                
                                $counterparty = $row['customer_name'] ?: $row['supplier_name'] ?: '-';
                                $grand_taxable += $row['taxable_amount'];
                                $grand_tax += $row['tax_amount'];
                                $grand_total += $row['total_amount'];
                            ?>
                            
                            <tr>
                                <td><?= date('d M Y', strtotime($row['transaction_date'])) ?></td>
                                <td><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td>
                                    <span class="badge bg-primary"><?= htmlspecialchars($row['tax_code']) ?></span>
                                    <?= htmlspecialchars($row['tax_name']) ?>
                                </td>
                                <td><?= ucfirst($row['transaction_type']) ?></td>
                                <td><?= htmlspecialchars($counterparty) ?></td>
                                <td class="text-end"><?= number_format($row['taxable_amount'], 2) ?></td>
                                <td class="text-center"><?= number_format($row['tax_rate'], 2) ?>%</td>
                                <td class="text-end fw-bold"><?= number_format($row['tax_amount'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['total_amount'], 2) ?></td>
                                <td>
                                    <?php
                                    $badge_class = match($row['status']) {
                                        'filed' => 'bg-info',
                                        'paid' => 'bg-success',
                                        'cancelled' => 'bg-danger',
                                        default => 'bg-warning'
                                    };
                                    ?>
                                    <span class="badge <?= $badge_class ?>"><?= ucfirst($row['status']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <!-- Grand Totals -->
                            <tr class="totals-row">
                                <td colspan="5" class="text-end">GRAND TOTALS:</td>
                                <td class="text-end"><?= number_format($grand_taxable, 2) ?></td>
                                <td></td>
                                <td class="text-end"><?= number_format($grand_tax, 2) ?></td>
                                <td class="text-end"><?= number_format($grand_total, 2) ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
function exportToExcel() {
    // Prepare data for Excel
    const data = [];
    
    // Add header
    data.push(['TAX REPORT']);
    data.push(['Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?>']);
    data.push(['Generated: <?= date('d M Y, h:i A') ?>']);
    data.push([]);
    
    // Add category summary
    data.push(['TAX SUMMARY BY CATEGORY']);
    data.push(['Category', 'Transactions', 'Taxable Amount', 'Tax Amount', 'Total Amount']);
    <?php foreach ($category_totals as $cat => $totals): ?>
    data.push([
        '<?= strtoupper(str_replace('_', ' ', $cat)) ?>',
        '<?= $totals['count'] ?>',
        <?= $totals['taxable_amount'] ?>,
        <?= $totals['tax_amount'] ?>,
        <?= $totals['total_amount'] ?>
    ]);
    <?php endforeach; ?>
    data.push([]);
    
    // Add detailed transactions
    data.push(['DETAILED TAX TRANSACTIONS']);
    data.push([
        'Date', 'Transaction No.', 'Tax Type', 'Tax Code', 'Category', 
        'Transaction Type', 'Counterparty', 'Taxable Amount', 'Rate %', 
        'Tax Amount', 'Total Amount', 'Status'
    ]);
    
    <?php foreach ($tax_data as $row): 
        $counterparty = $row['customer_name'] ?: $row['supplier_name'] ?: '-';
    ?>
    data.push([
        '<?= date('d M Y', strtotime($row['transaction_date'])) ?>',
        '<?= addslashes($row['transaction_number']) ?>',
        '<?= addslashes($row['tax_name']) ?>',
        '<?= $row['tax_code'] ?>',
        '<?= ucfirst($row['tax_category']) ?>',
        '<?= ucfirst($row['transaction_type']) ?>',
        '<?= addslashes($counterparty) ?>',
        <?= $row['taxable_amount'] ?>,
        <?= $row['tax_rate'] ?>,
        <?= $row['tax_amount'] ?>,
        <?= $row['total_amount'] ?>,
        '<?= ucfirst($row['status']) ?>'
    ]);
    <?php endforeach; ?>
    
    // Add grand totals
    <?php 
    $grand_taxable = array_sum(array_column($category_totals, 'taxable_amount'));
    $grand_tax = array_sum(array_column($category_totals, 'tax_amount'));
    $grand_total = array_sum(array_column($category_totals, 'total_amount'));
    ?>
    data.push([]);
    data.push([
        '', '', '', '', '', '', 'GRAND TOTALS',
        <?= $grand_taxable ?>,
        '',
        <?= $grand_tax ?>,
        <?= $grand_total ?>,
        ''
    ]);
    
    // Create workbook
    const ws = XLSX.utils.aoa_to_sheet(data);
    
    // Set column widths
    ws['!cols'] = [
        {wch: 12}, {wch: 18}, {wch: 25}, {wch: 10}, {wch: 15},
        {wch: 15}, {wch: 25}, {wch: 15}, {wch: 8}, {wch: 15},
        {wch: 15}, {wch: 10}
    ];
    
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Tax Report');
    
    XLSX.writeFile(wb, 'Tax_Report_<?= date('Y-m-d') ?>.xlsx');
}
</script>

<?php require_once '../../includes/footer.php'; ?>