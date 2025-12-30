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
    // CORRECT: Using region_name, district_name, ward_name, village_name from projects table
    $plot_sql = "SELECT p.*, 
                 pr.project_id, pr.project_name, pr.project_code,
                 pr.region_name, pr.district_name, pr.ward_name, pr.village_name,
                 u.full_name as created_by_name
                 FROM plots p
                 LEFT JOIN projects pr ON p.project_id = pr.project_id
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
$customer = null;
if (in_array($plot['status'], ['sold', 'reserved'])) {
    try {
        $reservation_sql = "SELECT r.*, 
                     c.customer_id, c.first_name, c.middle_name, c.last_name, c.full_name,
                     c.phone, c.alternative_phone, c.email, c.national_id, c.passport_number,
                     c.gender, c.region, c.district, c.ward, c.village, c.street_address,
                     c.customer_type, c.id_number, c.tin_number, c.nationality, c.occupation,
                     c.next_of_kin_name, c.next_of_kin_phone, c.next_of_kin_relationship,
                     c.postal_address, c.address,
                     c.guardian1_name, c.guardian1_relationship, c.guardian2_name, c.guardian2_relationship,
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
        
        if ($reservation) {
            $customer = $reservation;
        }
    } catch (PDOException $e) {
        error_log("Error fetching reservation: " . $e->getMessage());
    }
}

// ==================== FETCH PAYMENT HISTORY WITH ENHANCED DETAILS ====================
$payments = [];
$payment_summary = [
    'total_paid' => 0,
    'pending_amount' => 0,
    'balance' => 0,
    'payment_count' => 0,
    'pending_count' => 0
];

if ($reservation) {
    try {
        $payments_sql = "SELECT p.*, 
                        u.full_name as received_by_name,
                        approver.full_name as approved_by_name
                        FROM payments p
                        LEFT JOIN users u ON p.created_by = u.user_id
                        LEFT JOIN users approver ON p.approved_by = approver.user_id
                        WHERE p.reservation_id = ? AND p.company_id = ?
                        ORDER BY p.payment_date DESC, p.created_at DESC";
        $payments_stmt = $conn->prepare($payments_sql);
        $payments_stmt->execute([$reservation['reservation_id'], $company_id]);
        $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate payment summary with pending amounts
        $summary_sql = "SELECT 
                        COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as total_paid,
                        COALESCE(SUM(CASE WHEN status = 'pending_approval' THEN amount ELSE 0 END), 0) as pending_amount,
                        COUNT(CASE WHEN status = 'approved' THEN 1 END) as payment_count,
                        COUNT(CASE WHEN status = 'pending_approval' THEN 1 END) as pending_count
                        FROM payments
                        WHERE reservation_id = ? AND company_id = ?";
        $summary_stmt = $conn->prepare($summary_sql);
        $summary_stmt->execute([$reservation['reservation_id'], $company_id]);
        $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
        
        $payment_summary['total_paid'] = $summary['total_paid'] ?? 0;
        $payment_summary['pending_amount'] = $summary['pending_amount'] ?? 0;
        $payment_summary['payment_count'] = $summary['payment_count'] ?? 0;
        $payment_summary['pending_count'] = $summary['pending_count'] ?? 0;
        $payment_summary['balance'] = ($reservation['total_amount'] ?? 0) - $payment_summary['total_paid'];
        
    } catch (PDOException $e) {
        error_log("Error fetching payments: " . $e->getMessage());
    }
}

$page_title = 'Plot Details - ' . htmlspecialchars($plot['plot_number']);
require_once '../../includes/header.php';
?>

<style>
.stats-card { background:#fff; border-radius:12px; padding:1.5rem; box-shadow:0 4px 12px rgba(0,0,0,0.08); text-align:center; border-left:5px solid; transition: transform 0.2s; }
.stats-card:hover { transform: translateY(-2px); }
.stats-card.primary { border-left-color:#007bff; }
.stats-card.success { border-left-color:#28a745; }
.stats-card.warning { border-left-color:#ffc107; }
.stats-card.info { border-left-color:#17a2b8; }
.stats-card.danger { border-left-color:#dc3545; }
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
.payment-item { position:relative; padding:1rem; margin-bottom:1rem; background:#f8f9fa; border-radius:8px; border-left:4px solid; }
.payment-item.approved { border-left-color:#28a745; }
.payment-item.pending_approval { border-left-color:#ffc107; }
.payment-item.rejected { border-left-color:#dc3545; }
.payment-item::before { content:''; position:absolute; left:-24px; top:1.5rem; width:12px; height:12px; border-radius:50%; border:2px solid #fff; }
.payment-item.approved::before { background:#28a745; }
.payment-item.pending_approval::before { background:#ffc107; }
.payment-item.rejected::before { background:#dc3545; }

.customer-header { background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); color:#fff; padding:1.5rem; border-radius:12px 12px 0 0; margin:-1.5rem -1.5rem 1rem -1.5rem; }
.customer-avatar { width:80px; height:80px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-size:2rem; font-weight:700; color:#667eea; border:4px solid rgba(255,255,255,0.3); }
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
                <div class="stats-number">TSH <?= number_format($plot['selling_price']/1000000, 1) ?>M</div>
                <div class="stats-label">Base Price</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card info">
                <div class="stats-number">TSH <?= number_format($final_price/1000000, 1) ?>M</div>
                <div class="stats-label">Final Price</div>
            </div>
        </div>
        
        <!-- Payment Stats (shown only if reservation exists) -->
        <?php if ($reservation): ?>
        <div class="col-md-4">
            <div class="stats-card success">
                <div class="stats-number">TSH <?= number_format($payment_summary['total_paid']/1000000, 1) ?>M</div>
                <div class="stats-label">Total Paid (<?= $payment_summary['payment_count'] ?> payments)</div>
            </div>
        </div>
        <?php if ($payment_summary['pending_count'] > 0): ?>
        <div class="col-md-4">
            <div class="stats-card warning">
                <div class="stats-number">TSH <?= number_format($payment_summary['pending_amount']/1000000, 1) ?>M</div>
                <div class="stats-label">Pending Approval (<?= $payment_summary['pending_count'] ?>)</div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-md-4">
            <div class="stats-card danger">
                <div class="stats-number">TSH <?= number_format($payment_summary['balance']/1000000, 1) ?>M</div>
                <div class="stats-label">Balance Remaining</div>
            </div>
        </div>
        <?php endif; ?>
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

    <!-- Location Information (from Project CSV locations) -->
    <?php if (!empty($plot['region_name']) || !empty($plot['district_name']) || !empty($plot['ward_name']) || !empty($plot['village_name'])): ?>
    <div class="info-card">
        <div class="info-card-header">
            <i class="fas fa-map-marker-alt me-2"></i>Location Information
        </div>

        <?php if (!empty($plot['region_name'])): ?>
        <div class="info-row">
            <div class="info-label">Region:</div>
            <div class="info-value"><?= htmlspecialchars($plot['region_name']) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($plot['district_name'])): ?>
        <div class="info-row">
            <div class="info-label">District:</div>
            <div class="info-value"><?= htmlspecialchars($plot['district_name']) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($plot['ward_name'])): ?>
        <div class="info-row">
            <div class="info-label">Ward:</div>
            <div class="info-value"><?= htmlspecialchars($plot['ward_name']) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($plot['village_name'])): ?>
        <div class="info-row">
            <div class="info-label">Village/Street:</div>
            <div class="info-value"><?= htmlspecialchars($plot['village_name']) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($plot['survey_plan_number'])): ?>
        <div class="info-row">
            <div class="info-label">Survey Plan Number:</div>
            <div class="info-value"><strong class="text-primary"><?= htmlspecialchars($plot['survey_plan_number']) ?></strong></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($plot['town_plan_number'])): ?>
        <div class="info-row">
            <div class="info-label">Town Plan Number:</div>
            <div class="info-value"><strong class="text-info"><?= htmlspecialchars($plot['town_plan_number']) ?></strong></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($plot['gps_coordinates'])): ?>
        <div class="info-row">
            <div class="info-label">GPS Coordinates:</div>
            <div class="info-value">
                <code><?= htmlspecialchars($plot['gps_coordinates']) ?></code>
                <a href="https://www.google.com/maps?q=<?= urlencode($plot['gps_coordinates']) ?>" 
                   target="_blank" 
                   class="btn btn-sm btn-outline-primary ms-2">
                    <i class="fas fa-map me-1"></i>View on Map
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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
            <div class="info-value">
                <strong class="text-danger">- TSH <?= number_format($plot['discount_amount'], 2) ?></strong>
                <?php if (!empty($plot['discount_reason'])): ?>
                    <div class="small text-muted"><?= htmlspecialchars($plot['discount_reason']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="info-row">
            <div class="info-label">Final Selling Price:</div>
            <div class="info-value"><strong class="text-success fs-5">TSH <?= number_format($final_price, 2) ?></strong></div>
        </div>
    </div>

    <!-- Rest of the file continues exactly as the original with customer info, payments, etc. -->
    <!-- I'm truncating here since the rest is identical -->

    <!-- Customer Information -->
    <?php if ($customer): 
        $customer_initials = '';
        if (!empty($customer['first_name'])) $customer_initials .= strtoupper($customer['first_name'][0]);
        if (!empty($customer['last_name'])) $customer_initials .= strtoupper($customer['last_name'][0]);
    ?>
    <div class="info-card" style="padding:0;">
        <div class="customer-header">
            <div class="d-flex align-items-center gap-3">
                <div class="customer-avatar"><?= $customer_initials ?></div>
                <div>
                    <h4 class="mb-1">
                        <?= htmlspecialchars($customer['full_name']) ?>
                        <?php if ($customer['gender']): ?>
                            <i class="fas fa-<?= $customer['gender'] === 'male' ? 'mars' : 'venus' ?> ms-2" style="font-size:1.2rem;"></i>
                        <?php endif; ?>
                    </h4>
                    <div style="opacity:0.9;">
                        <i class="fas fa-user-tag me-2"></i><?= $plot['status'] === 'sold' ? 'Plot Owner' : 'Plot Reserved By' ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="padding:1.5rem;">
            <div class="row mb-3">
                <div class="col-md-6">
                    <h6 class="mb-3"><i class="fas fa-id-card me-2 text-primary"></i>Personal Information</h6>
                    
                    <div class="info-row">
                        <div class="info-label">Customer Type:</div>
                        <div class="info-value">
                            <span class="badge bg-info"><?= strtoupper($customer['customer_type'] ?? 'Individual') ?></span>
                        </div>
                    </div>

                    <?php if (!empty($customer['id_number']) || !empty($customer['national_id'])): ?>
                    <div class="info-row">
                        <div class="info-label">ID Number:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['id_number'] ?? $customer['national_id']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['passport_number'])): ?>
                    <div class="info-row">
                        <div class="info-label">Passport Number:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['passport_number']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['tin_number'])): ?>
                    <div class="info-row">
                        <div class="info-label">TIN Number:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['tin_number']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['nationality'])): ?>
                    <div class="info-row">
                        <div class="info-label">Nationality:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['nationality']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['occupation'])): ?>
                    <div class="info-row">
                        <div class="info-label">Occupation:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['occupation']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <h6 class="mb-3"><i class="fas fa-phone me-2 text-success"></i>Contact Information</h6>
                    
                    <div class="info-row">
                        <div class="info-label">Primary Phone:</div>
                        <div class="info-value">
                            <a href="tel:<?= $customer['phone'] ?>" class="text-decoration-none">
                                <i class="fas fa-phone-alt me-1"></i><?= htmlspecialchars($customer['phone'] ?? 'N/A') ?>
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($customer['alternative_phone'])): ?>
                    <div class="info-row">
                        <div class="info-label">Alternative Phone:</div>
                        <div class="info-value">
                            <a href="tel:<?= $customer['alternative_phone'] ?>" class="text-decoration-none">
                                <i class="fas fa-phone-alt me-1"></i><?= htmlspecialchars($customer['alternative_phone']) ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['email'])): ?>
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value">
                            <a href="mailto:<?= $customer['email'] ?>" class="text-decoration-none">
                                <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($customer['email']) ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <h6 class="mb-3"><i class="fas fa-map-marker-alt me-2 text-danger"></i>Address Information</h6>
                    
                    <?php if (!empty($customer['region'])): ?>
                    <div class="info-row">
                        <div class="info-label">Region:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['region']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['district'])): ?>
                    <div class="info-row">
                        <div class="info-label">District:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['district']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['ward'])): ?>
                    <div class="info-row">
                        <div class="info-label">Ward:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['ward']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['village'])): ?>
                    <div class="info-row">
                        <div class="info-label">Village:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['village']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['street_address']) || !empty($customer['address'])): ?>
                    <div class="info-row">
                        <div class="info-label">Street Address:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['street_address'] ?? $customer['address']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['postal_address'])): ?>
                    <div class="info-row">
                        <div class="info-label">Postal Address:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['postal_address']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <h6 class="mb-3"><i class="fas fa-users me-2 text-warning"></i>Next of Kin / Guardian</h6>
                    
                    <?php if (!empty($customer['next_of_kin_name'])): ?>
                    <div class="info-row">
                        <div class="info-label">Name:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['next_of_kin_name']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['next_of_kin_phone'])): ?>
                    <div class="info-row">
                        <div class="info-label">Phone:</div>
                        <div class="info-value">
                            <a href="tel:<?= $customer['next_of_kin_phone'] ?>" class="text-decoration-none">
                                <i class="fas fa-phone-alt me-1"></i><?= htmlspecialchars($customer['next_of_kin_phone']) ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['next_of_kin_relationship'])): ?>
                    <div class="info-row">
                        <div class="info-label">Relationship:</div>
                        <div class="info-value"><?= htmlspecialchars($customer['next_of_kin_relationship']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['guardian1_name'])): ?>
                    <div class="info-row">
                        <div class="info-label">Guardian 1:</div>
                        <div class="info-value">
                            <?= htmlspecialchars($customer['guardian1_name']) ?>
                            <?php if (!empty($customer['guardian1_relationship'])): ?>
                                <span class="text-muted">(<?= htmlspecialchars($customer['guardian1_relationship']) ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customer['guardian2_name'])): ?>
                    <div class="info-row">
                        <div class="info-label">Guardian 2:</div>
                        <div class="info-value">
                            <?= htmlspecialchars($customer['guardian2_name']) ?>
                            <?php if (!empty($customer['guardian2_relationship'])): ?>
                                <span class="text-muted">(<?= htmlspecialchars($customer['guardian2_relationship']) ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-end">
                <a href="../customers/view.php?id=<?= $customer['customer_id'] ?>" class="btn btn-outline-primary">
                    <i class="fas fa-user me-1"></i>View Full Customer Profile
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Reservation/Sale Information -->
    <?php if ($reservation): ?>
    <div class="info-card">
        <div class="info-card-header">
            <i class="fas fa-shopping-cart me-2"></i><?= $plot['status'] === 'sold' ? 'Sale' : 'Reservation' ?> Information
        </div>

        <div class="info-row">
            <div class="info-label">Reservation Number:</div>
            <div class="info-value"><strong><?= htmlspecialchars($reservation['reservation_number']) ?></strong></div>
        </div>

        <div class="info-row">
            <div class="info-label">Reservation Date:</div>
            <div class="info-value"><?= date('d M Y', strtotime($reservation['reservation_date'])) ?></div>
        </div>

        <div class="info-row">
            <div class="info-label">Status:</div>
            <div class="info-value">
                <span class="badge bg-<?= $reservation['status'] === 'completed' ? 'success' : 'warning' ?>">
                    <?= strtoupper($reservation['status']) ?>
                </span>
            </div>
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

        <?php if (!empty($reservation['title_holder_name'])): ?>
        <div class="info-row">
            <div class="info-label">Title Holder:</div>
            <div class="info-value"><?= htmlspecialchars($reservation['title_holder_name']) ?></div>
        </div>
        <?php endif; ?>

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
                    <small class="text-muted"><?= $payment_summary['payment_count'] ?> approved payment(s)</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-light rounded">
                    <h6 class="text-muted">Balance</h6>
                    <h4 class="<?= $payment_summary['balance'] > 0 ? 'text-danger' : 'text-success' ?>">TSH <?= number_format($payment_summary['balance'], 2) ?></h4>
                </div>
            </div>
        </div>

        <!-- Pending Payments Alert -->
        <?php if ($payment_summary['pending_count'] > 0): ?>
        <div class="alert alert-warning mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong><?= $payment_summary['pending_count'] ?></strong> payment(s) pending approval 
            (Total: TSH <?= number_format($payment_summary['pending_amount'], 2) ?>)
        </div>
        <?php endif; ?>

        <!-- Payment History -->
        <?php if (!empty($payments)): ?>
        <h6 class="mb-3 mt-4"><i class="fas fa-history me-2"></i>Payment History (<?= count($payments) ?> payments)</h6>
        <div class="payment-timeline">
            <?php foreach ($payments as $payment): ?>
            <div class="payment-item <?= $payment['status'] ?>">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <strong class="text-<?= $payment['status'] === 'approved' ? 'success' : ($payment['status'] === 'pending_approval' ? 'warning' : 'danger') ?>">
                            TSH <?= number_format($payment['amount'], 2) ?>
                        </strong>
                        <span class="badge bg-<?= $payment['status'] === 'approved' ? 'success' : ($payment['status'] === 'pending_approval' ? 'warning' : 'danger') ?> ms-2">
                            <?= strtoupper(str_replace('_', ' ', $payment['status'])) ?>
                        </span>
                    </div>
                </div>
                <div class="small text-muted">
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
                <?php if ($payment['status'] === 'approved' && !empty($payment['approved_by_name'])): ?>
                <div class="small text-muted">
                    <i class="fas fa-check-circle me-1"></i>Approved by: <?= htmlspecialchars($payment['approved_by_name']) ?>
                    <?php if (!empty($payment['approved_at'])): ?>
                        on <?= date('d M Y', strtotime($payment['approved_at'])) ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($payment['remarks']): ?>
                <div class="small mt-1 fst-italic">"<?= htmlspecialchars($payment['remarks']) ?>"</div>
                <?php endif; ?>
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

            <?php if ($customer): ?>
            <a href="../customers/view.php?id=<?= $customer['customer_id'] ?>" class="btn btn-info">
                <i class="fas fa-user me-1"></i>View Customer Details
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