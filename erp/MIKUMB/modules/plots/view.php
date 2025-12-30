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

// Get plot ID from URL
$plot_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$plot_id) {
    $_SESSION['error'] = "Invalid plot ID";
    header('Location: index.php');
    exit;
}

// ==================== FETCH PLOT DETAILS ====================
try {
    $plot_sql = "SELECT p.*, 
                 pr.project_id, pr.project_name, pr.project_code,
                 r.region_name, d.district_name, w.ward_name, v.village_name,
                 u.full_name as created_by_name
                 FROM plots p
                 LEFT JOIN projects pr ON p.project_id = pr.project_id
                 LEFT JOIN regions r ON pr.region_id = r.region_id
                 LEFT JOIN districts d ON pr.district_id = d.district_id
                 LEFT JOIN wards w ON pr.ward_id = w.ward_id
                 LEFT JOIN villages v ON pr.village_id = v.village_id
                 LEFT JOIN users u ON p.created_by = u.user_id
                 WHERE p.plot_id = ? AND p.company_id = ?";
    $plot_stmt = $conn->prepare($plot_sql);
    $plot_stmt->execute([$plot_id, $company_id]);
    $plot = $plot_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plot) {
        $_SESSION['error'] = "Plot not found";
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching plot: " . $e->getMessage());
    $_SESSION['error'] = "Error loading plot details";
    header('Location: index.php');
    exit;
}

// Calculate final price
$final_price = $plot['selling_price'] - ($plot['discount_amount'] ?? 0);

// ==================== FETCH RESERVATION/SALE INFORMATION ====================
$reservation = null;
if (in_array($plot['status'], ['sold', 'reserved'])) {
    try {
        $reservation_sql = "SELECT r.*, 
                     c.first_name, c.middle_name, c.last_name,
                     c.phone1, c.email, c.national_id,
                     u.full_name as sold_by_name
                     FROM reservations r
                     LEFT JOIN customers c ON r.customer_id = c.customer_id
                     LEFT JOIN users u ON r.created_by = u.user_id
                     WHERE r.plot_id = ? AND r.company_id = ?
                     ORDER BY r.reservation_date DESC
                     LIMIT 1";
        $reservation_stmt = $conn->prepare($reservation_sql);
        $reservation_stmt->execute([$plot_id, $company_id]);
        $reservation = $reservation_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching reservation: " . $e->getMessage());
    }
}

// ==================== FETCH PAYMENT HISTORY ====================
$payments = [];
$payment_summary = [
    'total_paid' => 0,
    'balance' => 0,
    'payment_count' => 0
];

if ($reservation) {
    try {
        $payments_sql = "SELECT p.*, u.full_name as received_by_name
                        FROM payments p
                        LEFT JOIN users u ON p.created_by = u.user_id
                        WHERE p.reservation_id = ? AND p.company_id = ?
                        ORDER BY p.payment_date DESC";
        $payments_stmt = $conn->prepare($payments_sql);
        $payments_stmt->execute([$reservation['reservation_id'], $company_id]);
        $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate payment summary
        $summary_sql = "SELECT 
                        COALESCE(SUM(amount), 0) as total_paid,
                        COUNT(*) as payment_count
                        FROM payments
                        WHERE reservation_id = ? AND company_id = ? AND status = 'approved'";
        $summary_stmt = $conn->prepare($summary_sql);
        $summary_stmt->execute([$reservation['reservation_id'], $company_id]);
        $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
        
        $payment_summary['total_paid'] = $summary['total_paid'] ?? 0;
        $payment_summary['payment_count'] = $summary['payment_count'] ?? 0;
        $payment_summary['balance'] = ($reservation['total_amount'] ?? 0) - $payment_summary['total_paid'];
        
    } catch (PDOException $e) {
        error_log("Error fetching payments: " . $e->getMessage());
    }
}

$page_title = 'Plot Details - ' . htmlspecialchars($plot['plot_number']);
require_once '../../includes/header.php';
?>

<style>
.stats-card { background:#fff; border-radius:12px; padding:1.5rem; box-shadow:0 4px 12px rgba(0,0,0,0.08); text-align:center; border-left:5px solid; }
.stats-card.primary { border-left-color:#007bff; }
.stats-card.success { border-left-color:#28a745; }
.stats-card.warning { border-left-color:#ffc107; }
.stats-card.info { border-left-color:#17a2b8; }
.stats-number { font-size:2rem; font-weight:700; color:#2c3e50; }
.stats-label { font-size:0.875rem; color:#6c757d; margin-top:0.5rem; }

.info-card { background:#fff; border-radius:12px; padding:1.5rem; margin-bottom:1.5rem; box-shadow:0 2px 8px rgba(0,0,0,0.08); }
.info-card-header { font-size:1.1rem; font-weight:600; color:#2c3e50; margin-bottom:1rem; padding-bottom:0.75rem; border-bottom:2px solid #e9ecef; }
.info-row { display:flex; padding:0.75rem 0; border-bottom:1px solid #f0f0f0; }
.info-row:last-child { border-bottom:none; }
.info-label { flex:0 0 200px; font-weight:600; color:#495057; }
.info-value { flex:1; color:#2c3e50; }

.status-badge { padding:0.5rem 1rem; border-radius:50px; font-weight:600; font-size:0.875rem; }
.status-badge.available { background:#d4edda; color:#155724; }
.status-badge.reserved { background:#fff3cd; color:#856404; }
.status-badge.sold { background:#d1ecf1; color:#0c5460; }
.status-badge.blocked { background:#f8d7da; color:#721c24; }

.corner-badge { background:#f093fb; color:#fff; padding:0.35rem 0.75rem; border-radius:20px; font-size:0.875rem; font-weight:600; }

.payment-timeline { position:relative; padding-left:30px; margin-top:1rem; }
.payment-timeline::before { content:''; position:absolute; left:10px; top:0; bottom:0; width:2px; background:#dee2e6; }
.payment-item { position:relative; padding:1rem; margin-bottom:1rem; background:#f8f9fa; border-radius:8px; border-left:4px solid #007bff; }
.payment-item::before { content:''; position:absolute; left:-24px; top:1.5rem; width:12px; height:12px; border-radius:50%; background:#007bff; border:2px solid #fff; }
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0"><i class="fas fa-map-marked-alt me-2"></i>Plot Details</h1>
                <p class="text-muted small mb-0 mt-1">
                    <?= htmlspecialchars($plot['project_name'] ?? 'N/A') ?> - Plot <?= htmlspecialchars($plot['plot_number']) ?>
                </p>
            </div>
            <div class="col-sm-6 text-end">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Plots
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($plot['area'], 2) ?> m²</div>
                <div class="stats-label">Plot Area</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card success">
                <div class="stats-number">TSH <?= number_format($plot['price_per_sqm'], 0) ?></div>
                <div class="stats-label">Price per m²</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card warning">
                <div class="stats-number">TSH <?= number_format($plot['selling_price'], 0) ?></div>
                <div class="stats-label">Base Price</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card info">
                <div class="stats-number">TSH <?= number_format($final_price, 0) ?></div>
                <div class="stats-label">Final Price</div>
            </div>
        </div>
    </div>

    <!-- Basic Information -->
    <div class="info-card">
        <div class="info-card-header">
            <i class="fas fa-info-circle me-2"></i>Basic Information
        </div>

        <div class="info-row">
            <div class="info-label">Plot Number:</div>
            <div class="info-value">
                <strong class="text-primary"><?= htmlspecialchars($plot['plot_number']) ?></strong>
                <?php if ($plot['corner_plot']): ?>
                    <span class="corner-badge ms-2"><i class="fas fa-star me-1"></i>Corner Plot</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($plot['block_number']): ?>
        <div class="info-row">
            <div class="info-label">Block Number:</div>
            <div class="info-value"><?= htmlspecialchars($plot['block_number']) ?></div>
        </div>
        <?php endif; ?>

        <div class="info-row">
            <div class="info-label">Status:</div>
            <div class="info-value">
                <span class="status-badge <?= $plot['status'] ?>">
                    <?= strtoupper($plot['status']) ?>
                </span>
            </div>
        </div>

        <div class="info-row">
            <div class="info-label">Project:</div>
            <div class="info-value">
                <?php if ($plot['project_id']): ?>
                    <a href="../projects/view.php?id=<?= $plot['project_id'] ?>" class="text-decoration-none">
                        <?= htmlspecialchars($plot['project_name']) ?>
                        <span class="text-muted">(<?= htmlspecialchars($plot['project_code']) ?>)</span>
                    </a>
                <?php else: ?>
                    N/A
                <?php endif; ?>
            </div>
        </div>

        <div class="info-row">
            <div class="info-label">Created By:</div>
            <div class="info-value"><?= htmlspecialchars($plot['created_by_name'] ?? 'N/A') ?></div>
        </div>

        <div class="info-row">
            <div class="info-label">Created Date:</div>
            <div class="info-value"><?= date('d M Y, h:i A', strtotime($plot['created_at'])) ?></div>
        </div>
    </div>

    <!-- Location Information -->
    <div class="info-card">
        <div class="info-card-header">
            <i class="fas fa-map-marker-alt me-2"></i>Location Information
        </div>

        <div class="info-row">
            <div class="info-label">Region:</div>
            <div class="info-value"><?= htmlspecialchars($plot['region_name'] ?? 'N/A') ?></div>
        </div>

        <div class="info-row">
            <div class="info-label">District:</div>
            <div class="info-value"><?= htmlspecialchars($plot['district_name'] ?? 'N/A') ?></div>
        </div>

        <div class="info-row">
            <div class="info-label">Ward:</div>
            <div class="info-value"><?= htmlspecialchars($plot['ward_name'] ?? 'N/A') ?></div>
        </div>

        <div class="info-row">
            <div class="info-label">Village:</div>
            <div class="info-value"><?= htmlspecialchars($plot['village_name'] ?? 'N/A') ?></div>
        </div>

        <?php if (!empty($plot['survey_plan_number'])): ?>
        <div class="info-row">
            <div class="info-label">Survey Plan Number:</div>
            <div class="info-value"><?= htmlspecialchars($plot['survey_plan_number']) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($plot['town_plan_number'])): ?>
        <div class="info-row">
            <div class="info-label">Town Plan Number:</div>
            <div class="info-value"><?= htmlspecialchars($plot['town_plan_number']) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($plot['gps_coordinates'])): ?>
        <div class="info-row">
            <div class="info-label">GPS Coordinates:</div>
            <div class="info-value">
                <?= htmlspecialchars($plot['gps_coordinates']) ?>
                <a href="https://www.google.com/maps?q=<?= urlencode($plot['gps_coordinates']) ?>" 
                   target="_blank" 
                   class="btn btn-sm btn-outline-primary ms-2">
                    <i class="fas fa-map me-1"></i>View on Map
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Pricing Details -->
    <div class="info-card">
        <div class="info-card-header">
            <i class="fas fa-dollar-sign me-2"></i>Pricing Details
        </div>

        <div class="info-row">
            <div class="info-label">Plot Area:</div>
            <div class="info-value"><strong><?= number_format($plot['area'], 2) ?> m²</strong></div>
        </div>

        <div class="info-row">
            <div class="info-label">Price per m²:</div>
            <div class="info-value"><strong>TSH <?= number_format($plot['price_per_sqm'], 2) ?></strong></div>
        </div>

        <div class="info-row">
            <div class="info-label">Base Selling Price:</div>
            <div class="info-value"><strong class="text-primary">TSH <?= number_format($plot['selling_price'], 2) ?></strong></div>
        </div>

        <?php if ($plot['discount_amount'] > 0): ?>
        <div class="info-row">
            <div class="info-label">Discount:</div>
            <div class="info-value"><strong class="text-danger">- TSH <?= number_format($plot['discount_amount'], 2) ?></strong></div>
        </div>
        <?php endif; ?>

        <div class="info-row">
            <div class="info-label">Final Selling Price:</div>
            <div class="info-value"><strong class="text-success fs-5">TSH <?= number_format($final_price, 2) ?></strong></div>
        </div>
    </div>

    <!-- Reservation/Sale Information -->
    <?php if ($reservation): 
        $customer_name = trim(($reservation['first_name']??'') . ' ' . ($reservation['middle_name']??'') . ' ' . ($reservation['last_name']??''));
    ?>
    <div class="info-card">
        <div class="info-card-header">
            <i class="fas fa-shopping-cart me-2"></i><?= $plot['status'] === 'sold' ? 'Sale' : 'Reservation' ?> Information
        </div>

        <div class="info-row">
            <div class="info-label">Reservation Number:</div>
            <div class="info-value"><strong><?= htmlspecialchars($reservation['reservation_number']) ?></strong></div>
        </div>

        <div class="info-row">
            <div class="info-label">Customer Name:</div>
            <div class="info-value">
                <a href="../customers/view.php?id=<?= $reservation['customer_id'] ?>" class="text-decoration-none">
                    <strong><?= htmlspecialchars($customer_name) ?></strong>
                </a>
            </div>
        </div>

        <div class="info-row">
            <div class="info-label">Customer Phone:</div>
            <div class="info-value"><?= htmlspecialchars($reservation['phone1'] ?? 'N/A') ?></div>
        </div>

        <div class="info-row">
            <div class="info-label">Customer Email:</div>
            <div class="info-value"><?= htmlspecialchars($reservation['email'] ?? 'N/A') ?></div>
        </div>

        <?php if (!empty($reservation['national_id'])): ?>
        <div class="info-row">
            <div class="info-label">Customer NIDA:</div>
            <div class="info-value"><?= htmlspecialchars($reservation['national_id']) ?></div>
        </div>
        <?php endif; ?>

        <div class="info-row">
            <div class="info-label">Reservation Date:</div>
            <div class="info-value"><?= date('d M Y', strtotime($reservation['reservation_date'])) ?></div>
        </div>

        <div class="info-row">
            <div class="info-label">Total Amount:</div>
            <div class="info-value"><strong class="text-primary">TSH <?= number_format($reservation['total_amount'], 2) ?></strong></div>
        </div>

        <div class="info-row">
            <div class="info-label">Down Payment:</div>
            <div class="info-value"><strong>TSH <?= number_format($reservation['down_payment'] ?? 0, 2) ?></strong></div>
        </div>

        <div class="info-row">
            <div class="info-label">Payment Periods:</div>
            <div class="info-value"><?= $reservation['payment_periods'] ?? 0 ?> months</div>
        </div>

        <div class="info-row">
            <div class="info-label">Installment Amount:</div>
            <div class="info-value"><strong>TSH <?= number_format($reservation['installment_amount'] ?? 0, 2) ?></strong></div>
        </div>

        <div class="info-row">
            <div class="info-label">Sold By:</div>
            <div class="info-value"><?= htmlspecialchars($reservation['sold_by_name'] ?? 'N/A') ?></div>
        </div>
    </div>

    <!-- Payment Summary -->
    <div class="info-card">
        <div class="info-card-header">
            <i class="fas fa-money-bill-wave me-2"></i>Payment Summary
        </div>

        <div class="row text-center mb-3">
            <div class="col-md-4">
                <div class="p-3 bg-light rounded">
                    <h6 class="text-muted">Total Amount</h6>
                    <h4 class="text-primary">TSH <?= number_format($reservation['total_amount'], 2) ?></h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-light rounded">
                    <h6 class="text-muted">Total Paid</h6>
                    <h4 class="text-success">TSH <?= number_format($payment_summary['total_paid'], 2) ?></h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-light rounded">
                    <h6 class="text-muted">Balance</h6>
                    <h4 class="<?= $payment_summary['balance'] > 0 ? 'text-danger' : 'text-success' ?>">TSH <?= number_format($payment_summary['balance'], 2) ?></h4>
                </div>
            </div>
        </div>

        <!-- Payment History -->
        <?php if (!empty($payments)): ?>
        <h6 class="mb-3 mt-4"><i class="fas fa-history me-2"></i>Payment History (<?= count($payments) ?> payments)</h6>
        <div class="payment-timeline">
            <?php foreach ($payments as $payment): ?>
            <div class="payment-item">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong class="text-primary">TSH <?= number_format($payment['amount'], 2) ?></strong>
                        <div class="small text-muted mt-1">
                            <i class="fas fa-calendar me-1"></i><?= date('d M Y', strtotime($payment['payment_date'])) ?>
                            <span class="mx-2">|</span>
                            <i class="fas fa-credit-card me-1"></i><?= ucwords(str_replace('_', ' ', $payment['payment_method'])) ?>
                        </div>
                        <?php if ($payment['transaction_reference']): ?>
                        <div class="small text-muted">
                            <i class="fas fa-hashtag me-1"></i>Ref: <?= htmlspecialchars($payment['transaction_reference']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="small text-muted">
                            <i class="fas fa-user me-1"></i>Received by: <?= htmlspecialchars($payment['received_by_name'] ?? 'N/A') ?>
                        </div>
                        <?php if ($payment['remarks']): ?>
                        <div class="small mt-1"><?= htmlspecialchars($payment['remarks']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>No payment history available yet.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Additional Notes -->
    <?php if (!empty($plot['notes'])): ?>
    <div class="info-card">
        <div class="info-card-header">
            <i class="fas fa-sticky-note me-2"></i>Additional Notes
        </div>
        <div class="p-2">
            <?= nl2br(htmlspecialchars($plot['notes'])) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="info-card">
        <div class="d-flex gap-2 flex-wrap">
            <a href="edit.php?id=<?= $plot_id ?>" class="btn btn-warning">
                <i class="fas fa-edit me-1"></i>Edit Plot
            </a>
            
            <?php if ($plot['status'] === 'available'): ?>
            <a href="../sales/create.php?plot_id=<?= $plot_id ?>" class="btn btn-success">
                <i class="fas fa-shopping-cart me-1"></i>Reserve/Sell Plot
            </a>
            <?php endif; ?>

            <?php if ($reservation && $payment_summary['balance'] > 0): ?>
            <a href="../payments/create.php?reservation_id=<?= $reservation['reservation_id'] ?>" class="btn btn-primary">
                <i class="fas fa-money-bill me-1"></i>Add Payment
            </a>
            <?php endif; ?>

            <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                <i class="fas fa-trash me-1"></i>Delete Plot
            </button>

            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Plots
            </a>
        </div>
    </div>

</div>

<script>
function confirmDelete() {
    if (confirm('Are you sure you want to delete this plot?\n\nThis action cannot be undone.')) {
        window.location.href = 'delete.php?id=<?= $plot_id ?>';
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>