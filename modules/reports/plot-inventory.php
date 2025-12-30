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
$project_id = $_GET['project_id'] ?? '';
$status = $_GET['status'] ?? '';
$reservation_id = $_GET['reservation_id'] ?? '';
$installment_months = $_GET['installment_months'] ?? 12;

// Initialize data
$summary_stats = [
    'total_plots' => 0,
    'available_plots' => 0,
    'reserved_plots' => 0,
    'sold_plots' => 0
];
$plot_data = [];
$projects = [];
$reservation_details = null;
$installment_schedule = [];

try {
    // Summary statistics
    $query = "
        SELECT 
            COUNT(*) as total_plots,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_plots,
            SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved_plots,
            SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_plots
        FROM plots
        WHERE company_id = ?
    ";
    
    $params = [$company_id];
    if ($project_id) {
        $query .= " AND project_id = ?";
        $params[] = $project_id;
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $summary_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If reservation_id provided, get reservation details and generate installment schedule
    if ($reservation_id) {
        $query = "
            SELECT 
                r.*,
                c.full_name as customer_name,
                c.phone,
                c.email,
                c.address,
                p.plot_number,
                p.block_number,
                p.area,
                p.price_per_sqm,
                pr.project_name,
                pr.project_code,
                (SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved') as total_paid
            FROM reservations r
            INNER JOIN customers c ON r.customer_id = c.customer_id
            INNER JOIN plots p ON r.plot_id = p.plot_id
            INNER JOIN projects pr ON p.project_id = pr.project_id
            WHERE r.reservation_id = ?
            AND r.company_id = ?
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([$reservation_id, $company_id]);
        $reservation_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reservation_details) {
            // Get existing payments
            $stmt = $conn->prepare("
                SELECT payment_date, amount, payment_type, payment_method, payment_number
                FROM payments 
                WHERE reservation_id = ? AND status = 'approved'
                ORDER BY payment_date ASC
            ");
            $stmt->execute([$reservation_id]);
            $existing_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate installment schedule
            $total_amount = $reservation_details['total_amount'];
            $down_payment = $reservation_details['down_payment'];
            $total_paid = $reservation_details['total_paid'] ?? 0;
            $balance = $total_amount - $total_paid;
            $remaining_balance = $balance;
            
            // Monthly installment amount
            $monthly_installment = $balance / $installment_months;
            
            // Start date for installments (from reservation date or last payment)
            $start_date = $reservation_details['reservation_date'];
            if (!empty($existing_payments)) {
                $last_payment = end($existing_payments);
                $start_date = $last_payment['payment_date'];
            }
            
            // Generate schedule
            for ($i = 1; $i <= $installment_months; $i++) {
                $due_date = date('Y-m-d', strtotime($start_date . " +$i months"));
                $installment_amount = ($i == $installment_months) ? $remaining_balance : $monthly_installment;
                
                $installment_schedule[] = [
                    'month' => $i,
                    'due_date' => $due_date,
                    'amount' => $installment_amount,
                    'balance_before' => $remaining_balance,
                    'balance_after' => $remaining_balance - $installment_amount
                ];
                
                $remaining_balance -= $installment_amount;
            }
        }
    }
    
    // Plot inventory data
    $query = "
        SELECT 
            p.*,
            pr.project_name,
            pr.project_code,
            r.reservation_id,
            r.reservation_number,
            r.total_amount as sale_amount,
            r.down_payment,
            c.full_name as customer_name,
            c.phone,
            (SELECT SUM(amount) FROM payments WHERE reservation_id = r.reservation_id AND status = 'approved') as total_paid
        FROM plots p
        INNER JOIN projects pr ON p.project_id = pr.project_id
        LEFT JOIN reservations r ON p.plot_id = r.plot_id AND r.status IN ('active', 'completed')
        LEFT JOIN customers c ON r.customer_id = c.customer_id
        WHERE p.company_id = ?
    ";
    
    $params = [$company_id];
    if ($project_id) {
        $query .= " AND p.project_id = ?";
        $params[] = $project_id;
    }
    if ($status) {
        $query .= " AND p.status = ?";
        $params[] = $status;
    }
    $query .= " ORDER BY pr.project_name, p.plot_number";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $plot_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get projects for filter
    $stmt = $conn->prepare("SELECT project_id, project_name FROM projects WHERE company_id = ? AND is_active = 1 ORDER BY project_name");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Plot inventory query error: " . $e->getMessage());
}

$page_title = 'Plot Inventory & Payment Schedule';
require_once '../../includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: white !important; }
}

.inventory-page {
    background: #f4f6f9;
    min-height: 100vh;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}

.metrics-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

@media (max-width: 1200px) {
    .metrics-row { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .metrics-row { grid-template-columns: 1fr; }
}

.metric-box {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: transform 0.2s, box-shadow 0.2s;
    border-left: 4px solid;
}

.metric-box:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.metric-box.blue { border-left-color: #3b82f6; }
.metric-box.green { border-left-color: #10b981; }
.metric-box.yellow { border-left-color: #f59e0b; }
.metric-box.purple { border-left-color: #8b5cf6; }

.metric-label {
    font-size: 13px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    font-weight: 600;
}

.metric-value {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
}

.content-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 25px;
    overflow: hidden;
}

.card-header-custom {
    padding: 20px 25px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: between;
    align-items: center;
}

.card-header-custom h5 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #111827;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 25px;
}

.data-table {
    width: 100%;
    font-size: 14px;
}

.data-table thead {
    background: #f9fafb;
}

.data-table thead th {
    padding: 12px 15px;
    font-weight: 600;
    color: #374151;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.data-table tbody td {
    padding: 12px 15px;
    border-bottom: 1px solid #f3f4f6;
    color: #4b5563;
}

.data-table tbody tr:hover {
    background: #f9fafb;
}

.badge-custom {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.badge-available { background: #d1fae5; color: #065f46; }
.badge-reserved { background: #fef3c7; color: #92400e; }
.badge-sold { background: #dbeafe; color: #1e40af; }

.btn-modern {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}

.btn-blue { background: #3b82f6; color: white; }
.btn-green { background: #10b981; color: white; }
.btn-red { background: #ef4444; color: white; }
.btn-gray { background: #6b7280; color: white; }

.schedule-card {
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
}

.customer-info {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.schedule-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
}

.payment-summary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.payment-summary h4 {
    margin: 0 0 15px 0;
    font-size: 20px;
}

.payment-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.payment-row:last-child {
    border-bottom: none;
}
</style>

<div class="inventory-page">
    
    <!-- Page Header -->
    <div class="page-header no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2" style="font-size: 32px; font-weight: 700;">
                    <i class="fas fa-map-marked-alt me-2"></i>Plot Inventory & Payment Schedule
                </h1>
                <p class="mb-0" style="opacity: 0.9;">Complete plot inventory with installment payment plans</p>
            </div>
            <div>
                <?php if ($reservation_id): ?>
                    <button onclick="exportScheduleToPDF()" class="btn-modern btn-red me-2">
                        <i class="fas fa-file-pdf me-1"></i>Schedule PDF
                    </button>
                <?php endif; ?>
                <button onclick="exportInventoryToPDF()" class="btn-modern btn-red me-2">
                    <i class="fas fa-file-pdf me-1"></i>Inventory PDF
                </button>
                <button onclick="exportToExcel()" class="btn-modern btn-green me-2">
                    <i class="fas fa-file-excel me-1"></i>Excel
                </button>
                <button onclick="window.print()" class="btn-modern btn-gray me-2">
                    <i class="fas fa-print me-1"></i>Print
                </button>
                <a href="index.php" class="btn-modern btn-blue">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card no-print">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">Project</label>
                <select name="project_id" class="form-select" style="border-radius: 8px;">
                    <option value="">All Projects</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?= $proj['project_id'] ?>" <?= $project_id == $proj['project_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($proj['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">Status</label>
                <select name="status" class="form-select" style="border-radius: 8px;">
                    <option value="">All Status</option>
                    <option value="available" <?= $status == 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="reserved" <?= $status == 'reserved' ? 'selected' : '' ?>>Reserved</option>
                    <option value="sold" <?= $status == 'sold' ? 'selected' : '' ?>>Sold</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">Reservation ID (for schedule)</label>
                <input type="text" name="reservation_id" class="form-control" value="<?= $reservation_id ?>" placeholder="Enter reservation ID" style="border-radius: 8px;">
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-weight: 600; font-size: 13px; color: #374151;">Installment Months</label>
                <select name="installment_months" class="form-select" style="border-radius: 8px;">
                    <option value="6" <?= $installment_months == 6 ? 'selected' : '' ?>>6 Months</option>
                    <option value="12" <?= $installment_months == 12 ? 'selected' : '' ?>>12 Months</option>
                    <option value="18" <?= $installment_months == 18 ? 'selected' : '' ?>>18 Months</option>
                    <option value="24" <?= $installment_months == 24 ? 'selected' : '' ?>>24 Months</option>
                    <option value="36" <?= $installment_months == 36 ? 'selected' : '' ?>>36 Months</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn-modern btn-blue w-100">
                    <i class="fas fa-search me-1"></i>Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Metrics -->
    <div class="metrics-row">
        <div class="metric-box blue">
            <div class="metric-label">Total Plots</div>
            <div class="metric-value"><?= number_format($summary_stats['total_plots'] ?? 0) ?></div>
        </div>
        <div class="metric-box green">
            <div class="metric-label">Available</div>
            <div class="metric-value"><?= number_format($summary_stats['available_plots'] ?? 0) ?></div>
        </div>
        <div class="metric-box yellow">
            <div class="metric-label">Reserved</div>
            <div class="metric-value"><?= number_format($summary_stats['reserved_plots'] ?? 0) ?></div>
        </div>
        <div class="metric-box purple">
            <div class="metric-label">Sold</div>
            <div class="metric-value"><?= number_format($summary_stats['sold_plots'] ?? 0) ?></div>
        </div>
    </div>

    <?php if ($reservation_details && !empty($installment_schedule)): ?>
    <!-- Payment Schedule Section -->
    <div class="schedule-card" id="payment-schedule">
        <h3 style="margin-bottom: 25px; color: #111827;">
            <i class="fas fa-calendar-alt me-2"></i>Payment Schedule for <?= htmlspecialchars($reservation_details['customer_name']) ?>
        </h3>
        
        <!-- Customer Information -->
        <div class="customer-info">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Customer:</strong> <?= htmlspecialchars($reservation_details['customer_name']) ?></p>
                    <p><strong>Phone:</strong> <?= htmlspecialchars($reservation_details['phone']) ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($reservation_details['email']) ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Reservation #:</strong> <?= htmlspecialchars($reservation_details['reservation_number']) ?></p>
                    <p><strong>Plot:</strong> <?= htmlspecialchars($reservation_details['plot_number']) ?> - <?= htmlspecialchars($reservation_details['project_name']) ?></p>
                    <p><strong>Area:</strong> <?= number_format($reservation_details['area'], 2) ?> m²</p>
                </div>
            </div>
        </div>

        <!-- Payment Summary -->
        <div class="payment-summary">
            <h4><i class="fas fa-money-bill-wave me-2"></i>Payment Summary</h4>
            <div class="payment-row">
                <span>Total Amount:</span>
                <strong>TSH <?= number_format($reservation_details['total_amount'], 2) ?></strong>
            </div>
            <div class="payment-row">
                <span>Total Paid:</span>
                <strong>TSH <?= number_format($reservation_details['total_paid'] ?? 0, 2) ?></strong>
            </div>
            <div class="payment-row">
                <span>Remaining Balance:</span>
                <strong>TSH <?= number_format($reservation_details['total_amount'] - ($reservation_details['total_paid'] ?? 0), 2) ?></strong>
            </div>
            <div class="payment-row">
                <span>Installment Plan:</span>
                <strong><?= $installment_months ?> Months</strong>
            </div>
            <div class="payment-row">
                <span>Monthly Payment:</span>
                <strong>TSH <?= number_format(($reservation_details['total_amount'] - ($reservation_details['total_paid'] ?? 0)) / $installment_months, 2) ?></strong>
            </div>
        </div>

        <!-- Installment Schedule Table -->
        <div class="schedule-table">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Due Date</th>
                        <th class="text-end">Payment Amount</th>
                        <th class="text-end">Balance Before</th>
                        <th class="text-end">Balance After</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($installment_schedule as $installment): ?>
                    <tr>
                        <td><strong>Month <?= $installment['month'] ?></strong></td>
                        <td><?= date('d M Y', strtotime($installment['due_date'])) ?></td>
                        <td class="text-end"><strong>TSH <?= number_format($installment['amount'], 2) ?></strong></td>
                        <td class="text-end">TSH <?= number_format($installment['balance_before'], 2) ?></td>
                        <td class="text-end">TSH <?= number_format($installment['balance_after'], 2) ?></td>
                        <td>
                            <?php 
                            $is_due = strtotime($installment['due_date']) <= time();
                            ?>
                            <span class="badge-custom <?= $is_due ? 'badge-reserved' : 'badge-available' ?>">
                                <?= $is_due ? 'Due' : 'Upcoming' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 20px; padding: 15px; background: #fef3c7; border-radius: 8px; border-left: 4px solid #f59e0b;">
            <p style="margin: 0; color: #92400e;">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Note:</strong> This is a suggested payment schedule. Actual payments may vary. Please contact our office for payment instructions.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Plot Inventory Table -->
    <div class="content-card">
        <div class="card-header-custom">
            <h5><i class="fas fa-th me-2"></i>Plot Inventory</h5>
            <span style="color: #6b7280; font-size: 13px;"><?= count($plot_data) ?> plots</span>
        </div>
        <div style="padding: 25px;">
            <?php if (empty($plot_data)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-4x mb-3" style="color: #d1d5db;"></i>
                    <p style="color: #6b7280;">No plots found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="plotTable">
                        <thead>
                            <tr>
                                <th>Plot #</th>
                                <th>Block</th>
                                <th>Project</th>
                                <th class="text-end">Area (m²)</th>
                                <th class="text-end">Price/m²</th>
                                <th class="text-end">Total Price</th>
                                <th>Status</th>
                                <th>Customer</th>
                                <th class="text-end">Down Payment (20%)</th>
                                <th class="text-end">3 Months</th>
                                <th class="text-end">6 Months</th>
                                <th class="text-end">12 Months</th>
                                <th class="text-end">24 Months</th>
                                <th class="no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plot_data as $row): 
                                $total_price = $row['area'] * $row['price_per_sqm'];
                                $down_payment = $row['down_payment'] ?? 0;
                                $balance = $row['sale_amount'] ? ($row['sale_amount'] - ($row['total_paid'] ?? 0)) : ($total_price * 0.8);
                                $down_payment_amount = $down_payment > 0 ? $down_payment : ($total_price * 0.2);
                                
                                // Calculate installments for different periods
                                $installment_3m = $balance / 3;
                                $installment_6m = $balance / 6;
                                $installment_12m = $balance / 12;
                                $installment_24m = $balance / 24;
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['plot_number']) ?></strong></td>
                                <td><?= htmlspecialchars($row['block_number'] ?: '-') ?></td>
                                <td>
                                    <small style="color: #6b7280;"><?= htmlspecialchars($row['project_code']) ?></small><br>
                                    <?= htmlspecialchars($row['project_name']) ?>
                                </td>
                                <td class="text-end"><?= number_format($row['area'], 2) ?></td>
                                <td class="text-end">TSH <?= number_format($row['price_per_sqm'], 0) ?></td>
                                <td class="text-end"><strong>TSH <?= number_format($total_price, 0) ?></strong></td>
                                <td>
                                    <span class="badge-custom badge-<?= $row['status'] ?>">
                                        <?= ucfirst($row['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['customer_name'] ?: '-') ?></td>
                                <td class="text-end" style="color: #10b981;">
                                    <strong>TSH <?= number_format($down_payment_amount, 0) ?></strong>
                                </td>
                                <td class="text-end" style="color: #ef4444;">
                                    <strong>TSH <?= number_format($installment_3m, 0) ?></strong>
                                </td>
                                <td class="text-end" style="color: #f59e0b;">
                                    <strong>TSH <?= number_format($installment_6m, 0) ?></strong>
                                </td>
                                <td class="text-end" style="color: #3b82f6;">
                                    <strong>TSH <?= number_format($installment_12m, 0) ?></strong>
                                </td>
                                <td class="text-end" style="color: #8b5cf6;">
                                    <strong>TSH <?= number_format($installment_24m, 0) ?></strong>
                                </td>
                                <td class="no-print">
                                    <?php if ($row['reservation_id']): ?>
                                        <a href="?reservation_id=<?= $row['reservation_id'] ?>&installment_months=<?= $installment_months ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-calendar-alt"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

<script>
function exportToExcel() {
    const data = [];
    
    data.push(['PLOT INVENTORY & PAYMENT SCHEDULE REPORT']);
    data.push(['Generated: <?= date('d M Y, h:i A') ?>']);
    data.push([]);
    
    // Summary
    data.push(['SUMMARY']);
    data.push(['Total Plots', '<?= $summary_stats['total_plots'] ?? 0 ?>']);
    data.push(['Available', '<?= $summary_stats['available_plots'] ?? 0 ?>']);
    data.push(['Reserved', '<?= $summary_stats['reserved_plots'] ?? 0 ?>']);
    data.push(['Sold', '<?= $summary_stats['sold_plots'] ?? 0 ?>']);
    data.push([]);
    
    <?php if ($reservation_details && !empty($installment_schedule)): ?>
    // Payment Schedule
    data.push(['PAYMENT SCHEDULE']);
    data.push(['Customer', '<?= addslashes($reservation_details['customer_name']) ?>']);
    data.push(['Phone', '<?= $reservation_details['phone'] ?>']);
    data.push(['Reservation #', '<?= $reservation_details['reservation_number'] ?>']);
    data.push(['Plot', '<?= $reservation_details['plot_number'] ?> - <?= addslashes($reservation_details['project_name']) ?>']);
    data.push([]);
    data.push(['Total Amount', 'TSH <?= number_format($reservation_details['total_amount'], 2) ?>']);
    data.push(['Total Paid', 'TSH <?= number_format($reservation_details['total_paid'] ?? 0, 2) ?>']);
    data.push(['Balance', 'TSH <?= number_format($reservation_details['total_amount'] - ($reservation_details['total_paid'] ?? 0), 2) ?>']);
    data.push(['Installment Plan', '<?= $installment_months ?> Months']);
    data.push([]);
    data.push(['INSTALLMENT SCHEDULE']);
    data.push(['Month', 'Due Date', 'Payment Amount', 'Balance Before', 'Balance After']);
    <?php foreach ($installment_schedule as $inst): ?>
    data.push([
        'Month <?= $inst['month'] ?>',
        '<?= date('d M Y', strtotime($inst['due_date'])) ?>',
        <?= $inst['amount'] ?>,
        <?= $inst['balance_before'] ?>,
        <?= $inst['balance_after'] ?>
    ]);
    <?php endforeach; ?>
    data.push([]);
    <?php endif; ?>
    
    // Plot Inventory
    data.push(['PLOT INVENTORY']);
    data.push(['Plot #', 'Block', 'Project', 'Area (m²)', 'Price/m²', 'Total Price', 'Status', 'Customer', 'Down Payment (20%)', '3 Months Installment', '6 Months Installment', '12 Months Installment', '24 Months Installment']);
    
    <?php foreach ($plot_data as $row): 
        $total_price = $row['area'] * $row['price_per_sqm'];
        $down_payment = $row['down_payment'] ?? 0;
        $balance = $row['sale_amount'] ? ($row['sale_amount'] - ($row['total_paid'] ?? 0)) : ($total_price * 0.8);
        $down_payment_amount = $down_payment > 0 ? $down_payment : ($total_price * 0.2);
        $installment_3m = $balance / 3;
        $installment_6m = $balance / 6;
        $installment_12m = $balance / 12;
        $installment_24m = $balance / 24;
    ?>
    data.push([
        '<?= $row['plot_number'] ?>',
        '<?= $row['block_number'] ?: '-' ?>',
        '<?= addslashes($row['project_name']) ?>',
        <?= $row['area'] ?>,
        <?= $row['price_per_sqm'] ?>,
        <?= $total_price ?>,
        '<?= ucfirst($row['status']) ?>',
        '<?= addslashes($row['customer_name'] ?: '-') ?>',
        <?= $down_payment_amount ?>,
        <?= $installment_3m ?>,
        <?= $installment_6m ?>,
        <?= $installment_12m ?>,
        <?= $installment_24m ?>
    ]);
    <?php endforeach; ?>
    
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:12},{wch:10},{wch:30},{wch:12},{wch:12},{wch:15},{wch:12},{wch:25},{wch:18},{wch:18},{wch:18},{wch:18},{wch:18}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Plot Inventory');
    XLSX.writeFile(wb, 'Plot_Inventory_<?= date('Y-m-d') ?>.xlsx');
}

function exportInventoryToPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    
    // Header
    doc.setFontSize(20);
    doc.setTextColor(59, 130, 246);
    doc.text('PLOT INVENTORY & INSTALLMENT OPTIONS', 148, 15, { align: 'center' });
    
    doc.setFontSize(10);
    doc.setTextColor(100, 100, 100);
    doc.text('Generated: <?= date('d M Y, h:i A') ?>', 148, 22, { align: 'center' });
    
    // Summary boxes
    let y = 32;
    const boxWidth = 60;
    const boxHeight = 18;
    const gap = 10;
    let x = 20;
    
    // Total Plots
    doc.setFillColor(59, 130, 246);
    doc.roundedRect(x, y, boxWidth, boxHeight, 3, 3, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(9);
    doc.text('Total Plots', x + boxWidth/2, y + 7, { align: 'center' });
    doc.setFontSize(14);
    doc.text('<?= $summary_stats['total_plots'] ?? 0 ?>', x + boxWidth/2, y + 14, { align: 'center' });
    
    // Available
    x += boxWidth + gap;
    doc.setFillColor(16, 185, 129);
    doc.roundedRect(x, y, boxWidth, boxHeight, 3, 3, 'F');
    doc.setFontSize(9);
    doc.text('Available', x + boxWidth/2, y + 7, { align: 'center' });
    doc.setFontSize(14);
    doc.text('<?= $summary_stats['available_plots'] ?? 0 ?>', x + boxWidth/2, y + 14, { align: 'center' });
    
    // Reserved
    x += boxWidth + gap;
    doc.setFillColor(245, 158, 11);
    doc.roundedRect(x, y, boxWidth, boxHeight, 3, 3, 'F');
    doc.setFontSize(9);
    doc.text('Reserved', x + boxWidth/2, y + 7, { align: 'center' });
    doc.setFontSize(14);
    doc.text('<?= $summary_stats['reserved_plots'] ?? 0 ?>', x + boxWidth/2, y + 14, { align: 'center' });
    
    // Sold
    x += boxWidth + gap;
    doc.setFillColor(139, 92, 246);
    doc.roundedRect(x, y, boxWidth, boxHeight, 3, 3, 'F');
    doc.setFontSize(9);
    doc.text('Sold', x + boxWidth/2, y + 7, { align: 'center' });
    doc.setFontSize(14);
    doc.text('<?= $summary_stats['sold_plots'] ?? 0 ?>', x + boxWidth/2, y + 14, { align: 'center' });
    
    // Installment Options Legend
    y += 25;
    doc.setFontSize(8);
    doc.setTextColor(0, 0, 0);
    doc.text('Installment Options: Down Payment (20%) + Monthly Payments', 20, y);
    
    // Plot Inventory Table
    y += 5;
    const tableData = [
        <?php foreach ($plot_data as $row): 
            $total_price = $row['area'] * $row['price_per_sqm'];
            $down_payment = $row['down_payment'] ?? 0;
            $balance = $row['sale_amount'] ? ($row['sale_amount'] - ($row['total_paid'] ?? 0)) : ($total_price * 0.8);
            $down_payment_amount = $down_payment > 0 ? $down_payment : ($total_price * 0.2);
            $installment_3m = $balance / 3;
            $installment_6m = $balance / 6;
            $installment_12m = $balance / 12;
            $installment_24m = $balance / 24;
        ?>
        [
            '<?= $row['plot_number'] ?>',
            '<?= $row['block_number'] ?: '-' ?>',
            '<?= addslashes($row['project_name']) ?>',
            '<?= number_format($row['area'], 1) ?>',
            '<?= number_format($row['price_per_sqm'], 0) ?>',
            '<?= number_format($total_price, 0) ?>',
            '<?= ucfirst($row['status']) ?>',
            '<?= number_format($down_payment_amount, 0) ?>',
            '<?= number_format($installment_3m, 0) ?>',
            '<?= number_format($installment_6m, 0) ?>',
            '<?= number_format($installment_12m, 0) ?>',
            '<?= number_format($installment_24m, 0) ?>'
        ],
        <?php endforeach; ?>
    ];
    
    doc.autoTable({
        startY: y,
        head: [[
            'Plot #', 
            'Block', 
            'Project', 
            'Area (m²)', 
            'Price/m²', 
            'Total Price',
            'Status',
            'Down Pay\n(20%)',
            '3 Mo\nInstall',
            '6 Mo\nInstall',
            '12 Mo\nInstall',
            '24 Mo\nInstall'
        ]],
        body: tableData,
        theme: 'grid',
        headStyles: { 
            fillColor: [59, 130, 246],
            fontSize: 7,
            fontStyle: 'bold',
            halign: 'center',
            valign: 'middle'
        },
        styles: { 
            fontSize: 6.5,
            cellPadding: 1.5,
            overflow: 'linebreak',
            cellWidth: 'wrap'
        },
        columnStyles: {
            0: { cellWidth: 15, halign: 'center' },
            1: { cellWidth: 13, halign: 'center' },
            2: { cellWidth: 35 },
            3: { cellWidth: 16, halign: 'right' },
            4: { cellWidth: 18, halign: 'right' },
            5: { cellWidth: 22, halign: 'right' },
            6: { cellWidth: 16, halign: 'center' },
            7: { cellWidth: 20, halign: 'right', fillColor: [220, 252, 231] },
            8: { cellWidth: 18, halign: 'right', fillColor: [254, 226, 226] },
            9: { cellWidth: 18, halign: 'right', fillColor: [254, 243, 199] },
            10: { cellWidth: 18, halign: 'right', fillColor: [219, 234, 254] },
            11: { cellWidth: 18, halign: 'right', fillColor: [237, 233, 254] }
        },
        didDrawPage: function(data) {
            // Header on each page
            doc.setFontSize(10);
            doc.setTextColor(59, 130, 246);
            doc.text('PLOT INVENTORY & INSTALLMENT OPTIONS', 148, 10, { align: 'center' });
            
            // Footer on each page
            const pageCount = doc.internal.getNumberOfPages();
            const currentPage = doc.internal.getCurrentPageInfo().pageNumber;
            
            doc.setFontSize(8);
            doc.setTextColor(100, 100, 100);
            doc.text('Page ' + currentPage + ' of ' + pageCount, 148, 200, { align: 'center' });
            doc.text('All prices in TSH | Down Payment = 20% of Total Price', 148, 205, { align: 'center' });
        }
    });
    
    // Legend at the end
    const finalY = doc.lastAutoTable.finalY + 10;
    if (finalY < 180) {
        doc.setFontSize(7);
        doc.setTextColor(100, 100, 100);
        doc.text('Note: Monthly installments calculated on 80% balance after down payment.', 20, finalY);
        doc.text('Actual payment terms may vary. Contact sales office for customized payment plans.', 20, finalY + 4);
    }
    
    doc.save('Plot_Inventory_Installments_<?= date('Y-m-d') ?>.pdf');
}

<?php if ($reservation_details && !empty($installment_schedule)): ?>
function exportScheduleToPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    
    // Header
    doc.setFontSize(18);
    doc.setTextColor(59, 130, 246);
    doc.text('PAYMENT SCHEDULE', 105, 20, { align: 'center' });
    
    // Customer Info
    doc.setFontSize(10);
    doc.setTextColor(0, 0, 0);
    let y = 35;
    doc.text('Customer: <?= addslashes($reservation_details['customer_name']) ?>', 20, y);
    y += 7;
    doc.text('Phone: <?= $reservation_details['phone'] ?>', 20, y);
    y += 7;
    doc.text('Email: <?= $reservation_details['email'] ?>', 20, y);
    y += 7;
    doc.text('Reservation #: <?= $reservation_details['reservation_number'] ?>', 20, y);
    y += 7;
    doc.text('Plot: <?= $reservation_details['plot_number'] ?> - <?= addslashes($reservation_details['project_name']) ?>', 20, y);
    
    // Payment Summary
    y += 15;
    doc.setFillColor(59, 130, 246);
    doc.rect(20, y, 170, 8, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(12);
    doc.text('PAYMENT SUMMARY', 25, y + 5.5);
    
    y += 15;
    doc.setTextColor(0, 0, 0);
    doc.setFontSize(10);
    doc.text('Total Amount:', 25, y);
    doc.text('TSH <?= number_format($reservation_details['total_amount'], 2) ?>', 150, y, { align: 'right' });
    y += 7;
    doc.text('Total Paid:', 25, y);
    doc.text('TSH <?= number_format($reservation_details['total_paid'] ?? 0, 2) ?>', 150, y, { align: 'right' });
    y += 7;
    doc.text('Remaining Balance:', 25, y);
    doc.text('TSH <?= number_format($reservation_details['total_amount'] - ($reservation_details['total_paid'] ?? 0), 2) ?>', 150, y, { align: 'right' });
    y += 7;
    doc.text('Installment Plan:', 25, y);
    doc.text('<?= $installment_months ?> Months', 150, y, { align: 'right' });
    
    // Installment Schedule Table
    y += 10;
    const tableData = [
        <?php foreach ($installment_schedule as $inst): ?>
        ['Month <?= $inst['month'] ?>', '<?= date('d M Y', strtotime($inst['due_date'])) ?>', 
         'TSH <?= number_format($inst['amount'], 2) ?>', 
         'TSH <?= number_format($inst['balance_before'], 2) ?>', 
         'TSH <?= number_format($inst['balance_after'], 2) ?>'],
        <?php endforeach; ?>
    ];
    
    doc.autoTable({
        startY: y,
        head: [['Month', 'Due Date', 'Payment', 'Balance Before', 'Balance After']],
        body: tableData,
        theme: 'striped',
        headStyles: { fillColor: [59, 130, 246] },
        styles: { fontSize: 9 }
    });
    
    // Footer note
    const finalY = doc.lastAutoTable.finalY + 10;
    doc.setFontSize(8);
    doc.setTextColor(146, 64, 14);
    doc.text('Note: This is a suggested payment schedule. Actual payments may vary.', 20, finalY);
    doc.text('Please contact our office for payment instructions.', 20, finalY + 5);
    
    doc.save('Payment_Schedule_<?= $reservation_details['reservation_number'] ?>.pdf');
}
<?php endif; ?>
</script>

<?php require_once '../../includes/footer.php'; ?>