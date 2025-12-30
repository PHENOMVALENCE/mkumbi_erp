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

// Get customer ID from URL
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$customer_id) {
    $_SESSION['error'] = "Invalid customer ID";
    header('Location: index.php');
    exit;
}

// ==================== FETCH CUSTOMER DETAILS ====================
try {
    $customer_sql = "SELECT c.*, 
                     u.full_name as created_by_name,
                     sales.full_name as sales_person_name
                     FROM customers c
                     LEFT JOIN users u ON c.created_by = u.user_id
                     LEFT JOIN users sales ON c.created_by = sales.user_id
                     WHERE c.customer_id = ? AND c.company_id = ?";
    $customer_stmt = $conn->prepare($customer_sql);
    $customer_stmt->execute([$customer_id, $company_id]);
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $_SESSION['error'] = "Customer not found";
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching customer: " . $e->getMessage());
    $_SESSION['error'] = "Error loading customer details";
    header('Location: index.php');
    exit;
}

// ==================== FETCH RESERVATIONS ====================
$reservations = [];
$reservation_summary = [
    'total_count' => 0,
    'total_amount' => 0,
    'total_paid' => 0,
    'total_balance' => 0
];

try {
    $reservations_sql = "SELECT r.*, 
                         p.plot_number, p.block_number,
                         pr.project_name, pr.project_code,
                         COALESCE((SELECT SUM(amount) FROM payments 
                                  WHERE reservation_id = r.reservation_id 
                                  AND status = 'approved'), 0) as total_paid
                         FROM reservations r
                         LEFT JOIN plots p ON r.plot_id = p.plot_id
                         LEFT JOIN projects pr ON p.project_id = pr.project_id
                         WHERE r.customer_id = ? AND r.company_id = ?
                         ORDER BY r.reservation_date DESC";
    $reservations_stmt = $conn->prepare($reservations_sql);
    $reservations_stmt->execute([$customer_id, $company_id]);
    $reservations = $reservations_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    foreach ($reservations as $reservation) {
        $reservation_summary['total_count']++;
        $reservation_summary['total_amount'] += $reservation['total_amount'];
        $reservation_summary['total_paid'] += $reservation['total_paid'];
    }
    $reservation_summary['total_balance'] = $reservation_summary['total_amount'] - $reservation_summary['total_paid'];
    
} catch (PDOException $e) {
    error_log("Error fetching reservations: " . $e->getMessage());
}

// ==================== FETCH PAYMENTS ====================
$payments = [];
$payment_summary = [
    'total_payments' => 0,
    'total_amount' => 0,
    'last_payment_date' => null
];

try {
    $payments_sql = "SELECT p.*, 
                     r.reservation_number,
                     pl.plot_number,
                     u.full_name as received_by_name
                     FROM payments p
                     LEFT JOIN reservations r ON p.reservation_id = r.reservation_id
                     LEFT JOIN plots pl ON r.plot_id = pl.plot_id
                     LEFT JOIN users u ON p.created_by = u.user_id
                     WHERE r.customer_id = ? AND p.company_id = ?
                     ORDER BY p.payment_date DESC
                     LIMIT 10";
    $payments_stmt = $conn->prepare($payments_sql);
    $payments_stmt->execute([$customer_id, $company_id]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary_sql = "SELECT 
                    COUNT(*) as total_payments,
                    COALESCE(SUM(amount), 0) as total_amount,
                    MAX(payment_date) as last_payment_date
                    FROM payments p
                    LEFT JOIN reservations r ON p.reservation_id = r.reservation_id
                    WHERE r.customer_id = ? AND p.company_id = ? AND p.status = 'approved'";
    $summary_stmt = $conn->prepare($summary_sql);
    $summary_stmt->execute([$customer_id, $company_id]);
    $payment_summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching payments: " . $e->getMessage());
}

// ==================== FETCH QUOTATIONS ====================
$quotations = [];
try {
    $quotations_sql = "SELECT q.*, 
                       u.full_name as created_by_name
                       FROM quotations q
                       LEFT JOIN users u ON q.created_by = u.user_id
                       WHERE q.customer_id = ? AND q.company_id = ?
                       ORDER BY q.quotation_date DESC
                       LIMIT 5";
    $quotations_stmt = $conn->prepare($quotations_sql);
    $quotations_stmt->execute([$customer_id, $company_id]);
    $quotations = $quotations_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching quotations: " . $e->getMessage());
}

$page_title = 'Customer Details - ' . htmlspecialchars($customer['full_name']);
require_once '../../includes/header.php';
?>

<style>
.customer-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.customer-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid white;
    object-fit: cover;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.customer-avatar-placeholder {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid white;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.stats-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    text-align: center;
    border-left: 5px solid;
    transition: transform 0.3s;
}

.stats-card:hover {
    transform: translateY(-5px);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
}

.stats-label {
    font-size: 0.875rem;
    color: #6c757d;
    margin-top: 0.5rem;
}

.nav-tabs-custom {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.nav-tabs {
    border-bottom: 2px solid #e9ecef;
    padding: 0 1rem;
}

.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
    padding: 1rem 1.5rem;
    font-weight: 500;
    transition: all 0.3s;
    position: relative;
}

.nav-tabs .nav-link:hover {
    color: #007bff;
    background: transparent;
}

.nav-tabs .nav-link.active {
    color: #007bff;
    background: transparent;
    border: none;
}

.nav-tabs .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #007bff 0%, #0056b3 100%);
    border-radius: 3px 3px 0 0;
}

.info-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.info-card-header {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
}

.info-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    flex: 0 0 200px;
    font-weight: 600;
    color: #495057;
}

.info-value {
    flex: 1;
    color: #2c3e50;
}

.badge-status {
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.875rem;
}

.table-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.reservation-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-left: 4px solid #007bff;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    transition: transform 0.2s;
}

.reservation-card:hover {
    transform: translateX(5px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.payment-item {
    background: #f8f9fa;
    border-left: 4px solid #28a745;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.75rem;
}

.btn-sell {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    border: none;
    color: white;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(17, 153, 142, 0.3);
}

.btn-sell:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(17, 153, 142, 0.4);
    color: white;
}
</style>

<!-- Customer Header -->
<div class="customer-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-auto">
                <?php if (!empty($customer['profile_picture']) && file_exists('../../' . $customer['profile_picture'])): ?>
                    <img src="../../<?php echo htmlspecialchars($customer['profile_picture']); ?>" 
                         alt="Profile Picture" 
                         class="customer-avatar">
                <?php else: ?>
                    <div class="customer-avatar-placeholder">
                        <i class="fas fa-user"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col">
                <h2 class="mb-1"><?php echo htmlspecialchars($customer['full_name']); ?></h2>
                <p class="mb-0 opacity-75">
                    <i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($customer['email'] ?? 'No email'); ?>
                    <span class="mx-2">|</span>
                    <i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($customer['phone']); ?>
                </p>
                <p class="mb-0 mt-2">
                    <span class="badge bg-light text-dark">
                        Customer ID: #<?php echo $customer['customer_id']; ?>
                    </span>
                    <span class="badge bg-light text-dark ms-2">
                        <?php echo ucfirst($customer['customer_type'] ?? 'individual'); ?>
                    </span>
                    <?php if ($customer['gender']): ?>
                    <span class="badge bg-light text-dark ms-2">
                        <i class="fas fa-<?php echo $customer['gender'] === 'male' ? 'mars' : 'venus'; ?> me-1"></i>
                        <?php echo ucfirst($customer['gender']); ?>
                    </span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <a href="../sales/create.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-sell">
                        <i class="fas fa-shopping-cart me-1"></i>Sell Plot
                    </a>
                    <a href="edit.php?id=<?php echo $customer_id; ?>" class="btn btn-light">
                        <i class="fas fa-edit me-1"></i>Edit
                    </a>
                    <a href="index.php" class="btn btn-outline-light">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="container-fluid">
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stats-card primary">
                <div class="stats-number"><?php echo $reservation_summary['total_count']; ?></div>
                <div class="stats-label">Total Reservations</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card success">
                <div class="stats-number">TSH <?php echo number_format($payment_summary['total_amount'] ?? 0, 0); ?></div>
                <div class="stats-label">Total Payments</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card warning">
                <div class="stats-number">TSH <?php echo number_format($reservation_summary['total_balance'], 0); ?></div>
                <div class="stats-label">Outstanding Balance</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card info">
                <div class="stats-number"><?php echo $payment_summary['total_payments'] ?? 0; ?></div>
                <div class="stats-label">Payment Count</div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="nav-tabs-custom">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#overview">
                    <i class="fas fa-info-circle"></i> Overview
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#reservations">
                    <i class="fas fa-bookmark"></i> Reservations (<?php echo $reservation_summary['total_count']; ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#payments">
                    <i class="fas fa-money-bill"></i> Payments (<?php echo $payment_summary['total_payments'] ?? 0; ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#quotations">
                    <i class="fas fa-file-invoice"></i> Quotations (<?php echo count($quotations); ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#documents">
                    <i class="fas fa-folder"></i> Documents
                </a>
            </li>
        </ul>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        
        <!-- Overview Tab -->
        <div class="tab-pane fade show active" id="overview">
            
            <!-- Basic Information -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-user me-2 text-primary"></i>Basic Information
                </div>

                <div class="info-row">
                    <div class="info-label">Full Name:</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($customer['full_name']); ?></strong></div>
                </div>

                <div class="info-row">
                    <div class="info-label">Customer Type:</div>
                    <div class="info-value">
                        <span class="badge bg-primary">
                            <?php echo ucfirst($customer['customer_type'] ?? 'individual'); ?>
                        </span>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value">
                        <?php if ($customer['email']): ?>
                            <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>">
                                <?php echo htmlspecialchars($customer['email']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Not provided</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-label">Primary Phone:</div>
                    <div class="info-value">
                        <a href="tel:<?php echo htmlspecialchars($customer['phone']); ?>">
                            <i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($customer['phone']); ?>
                        </a>
                    </div>
                </div>

                <?php if ($customer['alternative_phone']): ?>
                <div class="info-row">
                    <div class="info-label">Alternative Phone:</div>
                    <div class="info-value">
                        <a href="tel:<?php echo htmlspecialchars($customer['alternative_phone']); ?>">
                            <i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($customer['alternative_phone']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($customer['gender']): ?>
                <div class="info-row">
                    <div class="info-label">Gender:</div>
                    <div class="info-value">
                        <i class="fas fa-<?php echo $customer['gender'] === 'male' ? 'mars' : 'venus'; ?> me-1"></i>
                        <?php echo ucfirst($customer['gender']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($customer['nationality']): ?>
                <div class="info-row">
                    <div class="info-label">Nationality:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['nationality']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($customer['occupation']): ?>
                <div class="info-row">
                    <div class="info-label">Occupation:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['occupation']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Location Information -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-map-marker-alt me-2 text-primary"></i>Location Information
                </div>

                <?php if ($customer['region']): ?>
                <div class="info-row">
                    <div class="info-label">Region:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['region']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($customer['district']): ?>
                <div class="info-row">
                    <div class="info-label">District:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['district']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($customer['ward']): ?>
                <div class="info-row">
                    <div class="info-label">Ward:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['ward']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($customer['village']): ?>
                <div class="info-row">
                    <div class="info-label">Village:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['village']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($customer['street_address']): ?>
                <div class="info-row">
                    <div class="info-label">Street Address:</div>
                    <div class="info-value"><?php echo nl2br(htmlspecialchars($customer['street_address'])); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($customer['postal_address']): ?>
                <div class="info-row">
                    <div class="info-label">Postal Address:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['postal_address']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Identification Information -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-id-card me-2 text-primary"></i>Identification Information
                </div>

                <?php if ($customer['national_id']): ?>
                <div class="info-row">
                    <div class="info-label">National ID (NIDA):</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($customer['national_id']); ?></strong></div>
                </div>
                <?php endif; ?>

                <?php if ($customer['passport_number']): ?>
                <div class="info-row">
                    <div class="info-label">Passport Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['passport_number']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($customer['id_number']): ?>
                <div class="info-row">
                    <div class="info-label">ID Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['id_number']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($customer['tin_number']): ?>
                <div class="info-row">
                    <div class="info-label">TIN Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['tin_number']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Guardian & Next of Kin Information -->
            <div class="row">
                <div class="col-md-6">
                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="fas fa-users me-2 text-primary"></i>Guardian Information
                        </div>

                        <?php if ($customer['guardian1_name']): ?>
                        <h6 class="text-muted mb-2">Guardian 1</h6>
                        <div class="info-row">
                            <div class="info-label">Name:</div>
                            <div class="info-value"><?php echo htmlspecialchars($customer['guardian1_name']); ?></div>
                        </div>
                        <?php if ($customer['guardian1_phone']): ?>
                        <div class="info-row">
                            <div class="info-label">Phone:</div>
                            <div class="info-value">
                                <a href="tel:<?php echo htmlspecialchars($customer['guardian1_phone']); ?>">
                                    <i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($customer['guardian1_phone']); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($customer['guardian1_relationship']): ?>
                        <div class="info-row">
                            <div class="info-label">Relationship:</div>
                            <div class="info-value"><?php echo ucfirst($customer['guardian1_relationship']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($customer['guardian2_name']): ?>
                        <h6 class="text-muted mb-2 mt-3">Guardian 2</h6>
                        <div class="info-row">
                            <div class="info-label">Name:</div>
                            <div class="info-value"><?php echo htmlspecialchars($customer['guardian2_name']); ?></div>
                        </div>
                        <?php if ($customer['guardian2_phone']): ?>
                        <div class="info-row">
                            <div class="info-label">Phone:</div>
                            <div class="info-value">
                                <a href="tel:<?php echo htmlspecialchars($customer['guardian2_phone']); ?>">
                                    <i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($customer['guardian2_phone']); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($customer['guardian2_relationship']): ?>
                        <div class="info-row">
                            <div class="info-label">Relationship:</div>
                            <div class="info-value"><?php echo ucfirst($customer['guardian2_relationship']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!$customer['guardian1_name'] && !$customer['guardian2_name']): ?>
                        <p class="text-muted mb-0">No guardian information provided</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="fas fa-user-friends me-2 text-primary"></i>Next of Kin
                        </div>

                        <?php if ($customer['next_of_kin_name']): ?>
                        <div class="info-row">
                            <div class="info-label">Name:</div>
                            <div class="info-value"><?php echo htmlspecialchars($customer['next_of_kin_name']); ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if ($customer['next_of_kin_phone']): ?>
                        <div class="info-row">
                            <div class="info-label">Phone:</div>
                            <div class="info-value">
                                <a href="tel:<?php echo htmlspecialchars($customer['next_of_kin_phone']); ?>">
                                    <i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($customer['next_of_kin_phone']); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($customer['next_of_kin_relationship']): ?>
                        <div class="info-row">
                            <div class="info-label">Relationship:</div>
                            <div class="info-value"><?php echo ucfirst($customer['next_of_kin_relationship']); ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!$customer['next_of_kin_name']): ?>
                        <p class="text-muted mb-0">No next of kin information provided</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Additional Information -->
            <?php if ($customer['notes']): ?>
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-sticky-note me-2 text-primary"></i>Additional Notes
                </div>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($customer['notes'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- System Information -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="fas fa-cog me-2 text-primary"></i>System Information
                </div>

                <div class="info-row">
                    <div class="info-label">Created By:</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['created_by_name'] ?? 'N/A'); ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">Created Date:</div>
                    <div class="info-value"><?php echo date('d M Y, h:i A', strtotime($customer['created_at'])); ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">Last Updated:</div>
                    <div class="info-value"><?php echo date('d M Y, h:i A', strtotime($customer['updated_at'])); ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div class="info-value">
                        <span class="badge bg-<?php echo $customer['is_active'] ? 'success' : 'danger'; ?>">
                            <?php echo $customer['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                </div>
            </div>

        </div>

        <!-- Reservations Tab -->
        <div class="tab-pane fade" id="reservations">
            <div class="table-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="fas fa-bookmark me-2 text-primary"></i>
                        Customer Reservations
                    </h5>
                    <a href="../sales/create.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-sell">
                        <i class="fas fa-plus me-1"></i>New Reservation
                    </a>
                </div>

                <?php if (!empty($reservations)): ?>
                    <?php foreach ($reservations as $reservation): 
                        $balance = $reservation['total_amount'] - $reservation['total_paid'];
                    ?>
                    <div class="reservation-card">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <strong><?php echo htmlspecialchars($reservation['reservation_number']); ?></strong>
                                <br>
                                <small class="text-muted">
                                    <?php echo date('d M Y', strtotime($reservation['reservation_date'])); ?>
                                </small>
                            </div>
                            <div class="col-md-3">
                                <strong>Plot:</strong> <?php echo htmlspecialchars($reservation['plot_number']); ?>
                                <br>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($reservation['project_name']); ?>
                                </small>
                            </div>
                            <div class="col-md-2">
                                <strong>Amount:</strong>
                                <br>TSH <?php echo number_format($reservation['total_amount'], 0); ?>
                            </div>
                            <div class="col-md-2">
                                <strong>Balance:</strong>
                                <br>
                                <span class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                    TSH <?php echo number_format($balance, 0); ?>
                                </span>
                            </div>
                            <div class="col-md-2 text-end">
                                <a href="../sales/view.php?id=<?php echo $reservation['reservation_id']; ?>" 
                                   class="btn btn-sm btn-outline-primary">
                                    View Details
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No reservations found for this customer.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payments Tab -->
        <div class="tab-pane fade" id="payments">
            <div class="table-card">
                <h5 class="mb-3">
                    <i class="fas fa-money-bill-wave me-2 text-success"></i>
                    Payment History
                </h5>

                <?php if (!empty($payments)): ?>
                    <?php foreach ($payments as $payment): ?>
                    <div class="payment-item">
                        <div class="row align-items-center">
                            <div class="col-md-2">
                                <strong>TSH <?php echo number_format($payment['amount'], 0); ?></strong>
                            </div>
                            <div class="col-md-2">
                                <small class="text-muted">
                                    <?php echo date('d M Y', strtotime($payment['payment_date'])); ?>
                                </small>
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-info">
                                    <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <small>Reservation: <?php echo htmlspecialchars($payment['reservation_number']); ?></small>
                            </div>
                            <div class="col-md-3 text-end">
                                <small class="text-muted">
                                    By: <?php echo htmlspecialchars($payment['received_by_name'] ?? 'N/A'); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No payment records found.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quotations Tab -->
        <div class="tab-pane fade" id="quotations">
            <div class="table-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="fas fa-file-invoice me-2 text-warning"></i>
                        Quotations
                    </h5>
                    <a href="../quotations/create.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-warning">
                        <i class="fas fa-plus me-1"></i>New Quotation
                    </a>
                </div>

                <?php if (!empty($quotations)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Quotation #</th>
                                    <th>Date</th>
                                    <th>Valid Until</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($quotations as $quotation): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($quotation['quotation_number']); ?></strong></td>
                                    <td><?php echo date('d M Y', strtotime($quotation['quotation_date'])); ?></td>
                                    <td><?php echo date('d M Y', strtotime($quotation['valid_until'])); ?></td>
                                    <td>TSH <?php echo number_format($quotation['total_amount'], 0); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $quotation['status'] === 'sent' ? 'primary' : 
                                                ($quotation['status'] === 'accepted' ? 'success' : 'secondary'); 
                                        ?>">
                                            <?php echo ucfirst($quotation['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="../quotations/view.php?id=<?php echo $quotation['quotation_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No quotations found for this customer.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Documents Tab -->
        <div class="tab-pane fade" id="documents">
            <div class="table-card">
                <h5 class="mb-3">
                    <i class="fas fa-folder me-2 text-info"></i>
                    Customer Documents
                </h5>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Document management feature coming soon.
                </div>
            </div>
        </div>

    </div>

</div>

<?php 
require_once '../../includes/footer.php';
?>