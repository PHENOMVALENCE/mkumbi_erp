<?php
define('APP_ACCESS', true);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

$errors = [];
$success = '';

// Handle add commission form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_commission') {
        $reservation_id = intval($_POST['reservation_id']);
        $recipient_name = trim($_POST['recipient_name']);
        $recipient_phone = trim($_POST['recipient_phone'] ?? '');
        $commission_type = $_POST['commission_type'];
        $commission_percentage = floatval($_POST['commission_percentage']);
        $remarks = trim($_POST['remarks'] ?? '');
        
        if (empty($reservation_id) || empty($recipient_name) || empty($commission_percentage)) {
            $errors[] = "Reservation, recipient name, and commission percentage are required";
        } elseif ($commission_percentage <= 0 || $commission_percentage > 100) {
            $errors[] = "Commission percentage must be between 0 and 100";
        } else {
            try {
                // Get reservation details
                $reservation_sql = "SELECT r.*, cu.full_name as customer_name 
                                   FROM reservations r
                                   INNER JOIN customers cu ON r.customer_id = cu.customer_id
                                   WHERE r.reservation_id = ? AND r.company_id = ?";
                $stmt = $conn->prepare($reservation_sql);
                $stmt->execute([$reservation_id, $company_id]);
                $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$reservation) {
                    $errors[] = "Reservation not found";
                } else {
                    // Calculate commission amount
                    $commission_amount = ($reservation['total_amount'] * $commission_percentage) / 100;
                    
                    // Insert commission (PENDING status)
                    $insert_sql = "INSERT INTO commissions 
                                  (company_id, reservation_id, recipient_name, recipient_phone, 
                                   commission_type, commission_percentage, 
                                   commission_amount, payment_status, remarks, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)";
                    
                    $stmt = $conn->prepare($insert_sql);
                    $stmt->execute([
                        $company_id,
                        $reservation_id,
                        $recipient_name,
                        $recipient_phone,
                        $commission_type,
                        $commission_percentage,
                        $commission_amount,
                        $remarks,
                        $_SESSION['user_id']
                    ]);
                    
                    $_SESSION['success'] = "Commission added successfully! Amount: TZS " . number_format($commission_amount, 2) . " (pending payment approval).";
                    header("Location: pay.php");
                    exit;
                }
                
            } catch (PDOException $e) {
                $errors[] = "Error adding commission: " . $e->getMessage();
            }
        }
    }
}

// Fetch all commissions
try {
    $commissions_sql = "SELECT 
                            c.commission_id,
                            c.recipient_name,
                            c.recipient_phone,
                            c.commission_type,
                            c.commission_percentage,
                            c.commission_amount,
                            c.payment_status,
                            c.remarks,
                            c.created_at,
                            r.reservation_id,
                            r.reservation_number,
                            r.reservation_date,
                            r.total_amount as contract_value,
                            cu.full_name as customer_name,
                            pl.plot_number,
                            pl.area,
                            pr.project_name,
                            (c.commission_amount * 0.05) as withholding_tax,
                            (c.commission_amount - (c.commission_amount * 0.05)) as entitled_commission,
                            DATEDIFF(NOW(), c.created_at) as days_since_created,
                            u.full_name as created_by_name
                        FROM commissions c
                        INNER JOIN reservations r ON c.reservation_id = r.reservation_id
                        INNER JOIN customers cu ON r.customer_id = cu.customer_id
                        INNER JOIN plots pl ON r.plot_id = pl.plot_id
                        INNER JOIN projects pr ON pl.project_id = pr.project_id
                        LEFT JOIN users u ON c.created_by = u.user_id
                        WHERE c.company_id = ?
                        ORDER BY c.created_at DESC";
    
    $stmt = $conn->prepare($commissions_sql);
    $stmt->execute([$company_id]);
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $commissions = [];
    $errors[] = "Error fetching commissions: " . $e->getMessage();
}

// Calculate statistics (with proper defaults)
$total_commissions = count($commissions);
$total_amount = $total_commissions > 0 ? array_sum(array_column($commissions, 'commission_amount')) : 0;
$pending_commissions = array_filter($commissions, function($c) { return $c['payment_status'] === 'pending'; });
$paid_commissions = array_filter($commissions, function($c) { return $c['payment_status'] === 'paid'; });
$total_pending = count($pending_commissions) > 0 ? array_sum(array_column($pending_commissions, 'commission_amount')) : 0;
$total_paid = count($paid_commissions) > 0 ? array_sum(array_column($paid_commissions, 'commission_amount')) : 0;

// Fetch active reservations for dropdown
try {
    $reservations_sql = "SELECT r.reservation_id, r.reservation_number, r.total_amount,
                                cu.full_name as customer_name,
                                pl.plot_number,
                                pr.project_name
                         FROM reservations r
                         INNER JOIN customers cu ON r.customer_id = cu.customer_id
                         INNER JOIN plots pl ON r.plot_id = pl.plot_id
                         INNER JOIN projects pr ON pl.project_id = pr.project_id
                         WHERE r.company_id = ? AND r.status = 'active'
                         ORDER BY r.reservation_date DESC";
    $stmt = $conn->prepare($reservations_sql);
    $stmt->execute([$company_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reservations = [];
}

$page_title = 'Commission Management';
require_once '../../includes/header.php';
?>

<!-- Ensure jQuery and Bootstrap are loaded -->
<script>
// Load jQuery if not present
if (typeof jQuery === 'undefined') {
    var jqScript = document.createElement('script');
    jqScript.src = 'https://code.jquery.com/jquery-3.6.0.min.js';
    jqScript.onload = function() {
        console.log('jQuery loaded dynamically');
        // Load Bootstrap after jQuery
        loadBootstrap();
    };
    document.head.appendChild(jqScript);
} else {
    console.log('jQuery already loaded');
    // Check Bootstrap
    if (typeof jQuery.fn.modal === 'undefined') {
        loadBootstrap();
    }
}

function loadBootstrap() {
    if (typeof jQuery !== 'undefined' && typeof jQuery.fn.modal === 'undefined') {
        // Load Bootstrap CSS
        var bsCss = document.createElement('link');
        bsCss.rel = 'stylesheet';
        bsCss.href = 'https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css';
        document.head.appendChild(bsCss);
        
        // Load Bootstrap JS
        var bsScript = document.createElement('script');
        bsScript.src = 'https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js';
        bsScript.onload = function() {
            console.log('Bootstrap loaded dynamically');
        };
        document.head.appendChild(bsScript);
    }
}
</script>

<style>
.commission-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 20px;
    overflow: hidden;
    border-left: 5px solid #667eea;
}

.commission-card.paid {
    border-left-color: #28a745;
}

.commission-card.cancelled {
    border-left-color: #dc3545;
    opacity: 0.7;
}

.commission-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.commission-card.paid .commission-header {
    background: linear-gradient(135deg, #28a745 0%, #218838 100%);
}

.commission-card.cancelled .commission-header {
    background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
}

.commission-body {
    padding: 20px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.info-box {
    padding: 10px;
    background: #f8f9fa;
    border-radius: 6px;
    border-left: 3px solid #667eea;
}

.info-label {
    font-size: 11px;
    color: #6c757d;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 14px;
    color: #212529;
    font-weight: 600;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 700;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-paid {
    background: #d4edda;
    color: #155724;
}

.status-cancelled {
    background: #f8d7da;
    color: #721c24;
}

.stats-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.stats-box {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    text-align: center;
    border-top: 4px solid #667eea;
}

.stats-box.paid {
    border-top-color: #28a745;
}

.stats-box.pending {
    border-top-color: #ffc107;
}

.stats-number {
    font-size: 32px;
    font-weight: 800;
    color: #667eea;
    margin-bottom: 5px;
}

.stats-box.paid .stats-number {
    color: #28a745;
}

.stats-box.pending .stats-number {
    color: #ffc107;
}

.stats-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
}

.financial-summary {
    background: #e7f3ff;
    border-left: 4px solid #0066cc;
    padding: 15px;
    border-radius: 6px;
    margin-top: 15px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #cde4f5;
}

.summary-row:last-child {
    border-bottom: none;
    font-weight: 700;
    color: #28a745;
    font-size: 16px;
    padding-top: 10px;
    margin-top: 5px;
    border-top: 2px solid #28a745;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-percentage"></i> Commission Management</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Commissions</a></li>
                    <li class="breadcrumb-item active">Manage</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <h5><i class="fas fa-ban"></i> Errors!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-summary">
            <div class="stats-box">
                <div class="stats-number"><?php echo $total_commissions; ?></div>
                <div class="stats-label">Total Commissions</div>
            </div>
            <div class="stats-box">
                <div class="stats-number">TZS <?php echo number_format($total_amount, 0); ?></div>
                <div class="stats-label">Total Amount</div>
            </div>
            <div class="stats-box pending">
                <div class="stats-number"><?php echo count($pending_commissions); ?></div>
                <div class="stats-label">Pending Payment</div>
                <div style="font-size: 14px; color: #ffc107; font-weight: 600; margin-top: 5px;">
                    TZS <?php echo number_format($total_pending, 0); ?>
                </div>
            </div>
            <div class="stats-box paid">
                <div class="stats-number"><?php echo count($paid_commissions); ?></div>
                <div class="stats-label">Paid</div>
                <div style="font-size: 14px; color: #28a745; font-weight: 600; margin-top: 5px;">
                    TZS <?php echo number_format($total_paid, 0); ?>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="margin-bottom: 20px;">
            <button type="button" class="btn btn-primary btn-lg" id="addCommissionBtn" data-toggle="modal" data-target="#addCommissionModal">
                <i class="fas fa-plus-circle"></i> Add New Commission
            </button>
            <a href="structures.php" class="btn btn-info">
                <i class="fas fa-cogs"></i> Commission Structures
            </a>
        </div>

        <!-- Commissions List -->
        <?php if (empty($commissions)): ?>
        <div class="commission-card">
            <div class="commission-body" style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-inbox" style="font-size: 80px; color: #dee2e6; margin-bottom: 20px;"></i>
                <h4>No Commissions Found</h4>
                <p>Click "Add New Commission" to create your first commission record.</p>
            </div>
        </div>
        <?php else: ?>

        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h3 class="card-title" style="color: white; font-weight: 700;">
                    <i class="fas fa-list"></i> Commission Records (<?php echo count($commissions); ?>)
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead style="background: #f8f9fa;">
                            <tr>
                                <th>Recipient</th>
                                <th>Customer</th>
                                <th>Reservation</th>
                                <th>Project/Plot</th>
                                <th>Type</th>
                                <th>Rate</th>
                                <th>Gross Amount</th>
                                <th>Tax (5%)</th>
                                <th>Net Amount</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($commissions as $commission): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($commission['recipient_name']); ?></strong>
                                    <?php if ($commission['recipient_phone']): ?>
                                        <br><small class="text-muted">
                                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($commission['recipient_phone']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($commission['customer_name']); ?>
                                    <br><small class="text-muted">
                                        Contract: TZS <?php echo number_format($commission['contract_value'], 0); ?>
                                    </small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($commission['reservation_number']); ?></strong>
                                    <br><small class="text-muted">
                                        <?php echo date('M d, Y', strtotime($commission['reservation_date'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($commission['project_name']); ?>
                                    <br><small class="text-muted">
                                        Plot <?php echo htmlspecialchars($commission['plot_number']); ?> 
                                        (<?php echo number_format($commission['area'], 0); ?> m²)
                                    </small>
                                </td>
                                <td>
                                    <?php 
                                    $type_colors = [
                                        'sales' => 'background: #28a745;',
                                        'referral' => 'background: #17a2b8;',
                                        'consultant' => 'background: #6f42c1;',
                                        'marketing' => 'background: #fd7e14;',
                                        'collection' => 'background: #20c997;'
                                    ];
                                    $color = $type_colors[$commission['commission_type']] ?? 'background: #6c757d;';
                                    ?>
                                    <span class="badge" style="<?php echo $color; ?> color: white;">
                                        <?php echo ucwords($commission['commission_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo number_format($commission['commission_percentage'], 2); ?>%</strong>
                                </td>
                                <td>
                                    <strong style="color: #667eea;">
                                        TZS <?php echo number_format($commission['commission_amount'], 2); ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="text-muted">
                                        TZS <?php echo number_format($commission['withholding_tax'], 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #28a745;">
                                        TZS <?php echo number_format($commission['entitled_commission'], 2); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($commission['payment_status'] === 'paid'): ?>
                                        <span class="badge badge-success" style="background: #28a745;">
                                            <i class="fas fa-check-circle"></i> PAID
                                        </span>
                                    <?php elseif ($commission['payment_status'] === 'cancelled'): ?>
                                        <span class="badge badge-danger" style="background: #dc3545;">
                                            <i class="fas fa-ban"></i> CANCELLED
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="background: #ffc107; color: #856404;">
                                            <i class="fas fa-clock"></i> PENDING
                                        </span>
                                        <?php if ($commission['days_since_created'] > 0): ?>
                                            <br><small class="text-muted">
                                                <?php echo $commission['days_since_created']; ?> days
                                            </small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo date('M d, Y', strtotime($commission['created_at'])); ?>
                                    </small>
                                    <br><small class="text-muted">
                                        <?php echo htmlspecialchars($commission['created_by_name'] ?? 'System'); ?>
                                    </small>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" 
                                            onclick='viewCommissionDetails(<?php echo json_encode($commission); ?>)'
                                            title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background: #f8f9fa; font-weight: 700;">
                            <tr>
                                <td colspan="6" class="text-right">TOTALS:</td>
                                <td>
                                    <strong style="color: #667eea;">
                                        TZS <?php echo number_format($total_amount, 2); ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="text-muted">
                                        TZS <?php echo number_format($total_amount * 0.05, 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #28a745;">
                                        TZS <?php echo number_format($total_amount - ($total_amount * 0.05), 2); ?>
                                    </strong>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>

    </div>
</section>

<!-- View Commission Details Modal -->
<div class="modal fade" id="viewCommissionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h5 class="modal-title" style="color: white;">
                    <i class="fas fa-eye"></i> Commission Details
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Left Column -->
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header" style="background: #667eea; color: white; font-weight: 700;">
                                <i class="fas fa-user"></i> Recipient Information
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">Recipient Name:</td>
                                        <td><strong id="detail_recipient_name"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Phone:</td>
                                        <td id="detail_recipient_phone"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Commission Type:</td>
                                        <td id="detail_commission_type"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header" style="background: #28a745; color: white; font-weight: 700;">
                                <i class="fas fa-user-tie"></i> Customer Information
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">Customer Name:</td>
                                        <td><strong id="detail_customer_name"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Reservation #:</td>
                                        <td id="detail_reservation_number"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Reservation Date:</td>
                                        <td id="detail_reservation_date"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header" style="background: #17a2b8; color: white; font-weight: 700;">
                                <i class="fas fa-map-marked-alt"></i> Property Information
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">Project:</td>
                                        <td><strong id="detail_project_name"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Plot Number:</td>
                                        <td id="detail_plot_number"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Plot Area:</td>
                                        <td id="detail_plot_area"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header" style="background: #ffc107; color: #856404; font-weight: 700;">
                                <i class="fas fa-calculator"></i> Commission Calculation
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <td class="text-muted">Contract Value:</td>
                                        <td class="text-right"><strong id="detail_contract_value"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Commission Rate:</td>
                                        <td class="text-right"><strong id="detail_commission_rate"></strong></td>
                                    </tr>
                                    <tr style="border-top: 2px solid #dee2e6;">
                                        <td class="text-muted">Gross Commission:</td>
                                        <td class="text-right"><strong style="color: #667eea;" id="detail_gross_commission"></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Withholding Tax (5%):</td>
                                        <td class="text-right"><span class="text-danger" id="detail_withholding_tax"></span></td>
                                    </tr>
                                    <tr style="border-top: 2px solid #28a745;">
                                        <td class="text-muted"><strong>Net Entitled Commission:</strong></td>
                                        <td class="text-right"><strong style="color: #28a745; font-size: 18px;" id="detail_net_commission"></strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header" id="detail_status_header" style="font-weight: 700;">
                                <i class="fas fa-info-circle"></i> Payment Status
                            </div>
                            <div class="card-body">
                                <div id="detail_payment_status"></div>
                            </div>
                        </div>

                        <div class="card" id="detail_remarks_card" style="display: none;">
                            <div class="card-header" style="background: #6c757d; color: white; font-weight: 700;">
                                <i class="fas fa-comment"></i> Remarks
                            </div>
                            <div class="card-body">
                                <p id="detail_remarks" style="white-space: pre-wrap;"></p>
                            </div>
                        </div>

                        <div class="card" style="display: none;" id="detail_created_card">
                            <div class="card-header" style="background: #e9ecef; font-weight: 700;">
                                <i class="fas fa-clock"></i> Record Information
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">Created On:</td>
                                        <td id="detail_created_at"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Created By:</td>
                                        <td id="detail_created_by"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Commission Modal -->
<div class="modal fade" id="addCommissionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i> Add New Commission
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" id="addCommissionForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_commission">

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Note:</strong> Commission will be created with "Pending" status. Payment approval will be handled through the approvals module.
                    </div>

                    <div class="form-group">
                        <label>Select Reservation <span style="color: red;">*</span></label>
                        <select name="reservation_id" id="reservation_id" class="form-control" required onchange="loadReservationDetails()">
                            <option value="">-- Select Reservation --</option>
                            <?php foreach ($reservations as $reservation): ?>
                                <option value="<?php echo $reservation['reservation_id']; ?>" 
                                        data-amount="<?php echo $reservation['total_amount']; ?>"
                                        data-customer="<?php echo htmlspecialchars($reservation['customer_name']); ?>"
                                        data-plot="<?php echo htmlspecialchars($reservation['plot_number']); ?>"
                                        data-project="<?php echo htmlspecialchars($reservation['project_name']); ?>">
                                    <?php echo htmlspecialchars($reservation['reservation_number']); ?> - 
                                    <?php echo htmlspecialchars($reservation['customer_name']); ?> - 
                                    <?php echo htmlspecialchars($reservation['project_name']); ?> Plot <?php echo htmlspecialchars($reservation['plot_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Reservation Details Display -->
                    <div id="reservationDetails" style="display: none; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #667eea;">
                        <div style="font-weight: 700; margin-bottom: 10px; color: #667eea;">
                            <i class="fas fa-file-invoice"></i> Reservation Details
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 14px;">
                            <div><strong>Customer:</strong> <span id="detail_customer"></span></div>
                            <div><strong>Project:</strong> <span id="detail_project"></span></div>
                            <div><strong>Plot:</strong> <span id="detail_plot"></span></div>
                            <div><strong>Contract Value:</strong> <span id="detail_amount"></span></div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Recipient Name <span style="color: red;">*</span></label>
                                <input type="text" name="recipient_name" class="form-control" required
                                       placeholder="Full name of commission recipient">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Recipient Phone</label>
                                <input type="text" name="recipient_phone" class="form-control"
                                       placeholder="+255...">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Commission Type <span style="color: red;">*</span></label>
                                <select name="commission_type" class="form-control" required>
                                    <option value="sales">Sales Commission</option>
                                    <option value="referral">Referral Commission</option>
                                    <option value="consultant">Consultant Commission</option>
                                    <option value="marketing">Marketing Commission</option>
                                    <option value="collection">Collection Commission</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Commission Percentage (%) <span style="color: red;">*</span></label>
                                <input type="number" name="commission_percentage" id="commission_percentage" 
                                       class="form-control" step="0.01" min="0" max="100" required
                                       placeholder="e.g., 5.00" onkeyup="calculateCommission()">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Calculated Commission Amount</label>
                                <input type="text" id="calculated_amount" class="form-control" 
                                       readonly style="background: #e9ecef; font-weight: 700; color: #28a745;">
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Remarks / Notes</label>
                                <textarea name="remarks" class="form-control" rows="3"
                                          placeholder="Additional notes about this commission..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Commission Calculation Summary -->
                    <div id="commissionSummary" style="display: none; padding: 15px; background: #e7f3ff; border-radius: 6px; border-left: 4px solid #0066cc;">
                        <div style="font-weight: 700; margin-bottom: 10px; color: #0066cc;">
                            <i class="fas fa-calculator"></i> Commission Calculation
                        </div>
                        <div style="font-size: 14px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Contract Value:</span>
                                <strong id="summary_contract">TZS 0</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Commission Rate:</span>
                                <strong id="summary_rate">0%</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Gross Commission:</span>
                                <strong id="summary_gross">TZS 0</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Withholding Tax (5%):</span>
                                <strong id="summary_tax">TZS 0</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding-top: 8px; margin-top: 8px; border-top: 2px solid #0066cc; font-size: 16px; color: #28a745;">
                                <span>Net Payable:</span>
                                <strong id="summary_net">TZS 0</strong>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Commission
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Function to initialize modal functionality
function initializeModal() {
    console.log('Initializing modal functionality');
    console.log('jQuery version:', jQuery.fn.jquery);
    
    // Check if modal element exists
    const modalElement = $('#addCommissionModal');
    console.log('Modal element exists:', modalElement.length > 0);
    
    if (modalElement.length === 0) {
        console.error('Modal element not found in DOM!');
        return;
    }
    
    // Add click handler to button
    $('#addCommissionBtn').off('click').on('click', function(e) {
        console.log('Add Commission button clicked');
        e.preventDefault();
        e.stopPropagation();
        
        // Try to open modal
        try {
            $('#addCommissionModal').modal('show');
            console.log('Modal show command executed');
        } catch (error) {
            console.error('Error opening modal:', error);
            alert('Error opening modal. Check console for details.');
        }
    });
    
    // Listen for modal events
    $('#addCommissionModal').on('show.bs.modal', function() {
        console.log('Modal is about to show');
    });
    
    $('#addCommissionModal').on('shown.bs.modal', function() {
        console.log('Modal is now visible');
    });
}

// Wait for everything to be ready
function waitForDependencies() {
    if (typeof jQuery !== 'undefined' && typeof jQuery.fn.modal !== 'undefined') {
        console.log('All dependencies loaded!');
        $(document).ready(function() {
            console.log('Document ready');
            initializeModal();
        });
    } else {
        console.log('Waiting for dependencies...');
        setTimeout(waitForDependencies, 100);
    }
}

// Start waiting
waitForDependencies();

let selectedReservationAmount = 0;

function loadReservationDetails() {
    const select = document.getElementById('reservation_id');
    const selectedOption = select.options[select.selectedIndex];
    const detailsDiv = document.getElementById('reservationDetails');
    
    if (select.value) {
        selectedReservationAmount = parseFloat(selectedOption.dataset.amount);
        
        document.getElementById('detail_customer').textContent = selectedOption.dataset.customer;
        document.getElementById('detail_project').textContent = selectedOption.dataset.project;
        document.getElementById('detail_plot').textContent = 'Plot ' + selectedOption.dataset.plot;
        document.getElementById('detail_amount').textContent = 'TZS ' + selectedReservationAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
        
        detailsDiv.style.display = 'block';
        
        // Trigger calculation if percentage already entered
        calculateCommission();
    } else {
        detailsDiv.style.display = 'none';
        selectedReservationAmount = 0;
        document.getElementById('calculated_amount').value = '';
        document.getElementById('commissionSummary').style.display = 'none';
    }
}

function calculateCommission() {
    const percentage = parseFloat(document.getElementById('commission_percentage').value) || 0;
    
    if (selectedReservationAmount > 0 && percentage > 0) {
        const grossCommission = (selectedReservationAmount * percentage) / 100;
        const withholdingTax = grossCommission * 0.05;
        const netCommission = grossCommission - withholdingTax;
        
        // Update calculated amount field
        document.getElementById('calculated_amount').value = 'TZS ' + grossCommission.toLocaleString('en-US', {minimumFractionDigits: 2});
        
        // Update summary
        document.getElementById('summary_contract').textContent = 'TZS ' + selectedReservationAmount.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_rate').textContent = percentage.toFixed(2) + '%';
        document.getElementById('summary_gross').textContent = 'TZS ' + grossCommission.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_tax').textContent = 'TZS ' + withholdingTax.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('summary_net').textContent = 'TZS ' + netCommission.toLocaleString('en-US', {minimumFractionDigits: 2});
        
        document.getElementById('commissionSummary').style.display = 'block';
    } else {
        document.getElementById('calculated_amount').value = '';
        document.getElementById('commissionSummary').style.display = 'none';
    }
}

// View commission details
function viewCommissionDetails(commission) {
    // Recipient Information
    document.getElementById('detail_recipient_name').textContent = commission.recipient_name;
    document.getElementById('detail_recipient_phone').textContent = commission.recipient_phone || 'N/A';
    
    // Commission Type with color
    const typeColors = {
        'sales': 'background: #28a745; color: white;',
        'referral': 'background: #17a2b8; color: white;',
        'consultant': 'background: #6f42c1; color: white;',
        'marketing': 'background: #fd7e14; color: white;',
        'collection': 'background: #20c997; color: white;'
    };
    const typeColor = typeColors[commission.commission_type] || 'background: #6c757d; color: white;';
    document.getElementById('detail_commission_type').innerHTML = 
        '<span class="badge" style="' + typeColor + '">' + 
        commission.commission_type.charAt(0).toUpperCase() + commission.commission_type.slice(1) + 
        '</span>';
    
    // Customer Information
    document.getElementById('detail_customer_name').textContent = commission.customer_name;
    document.getElementById('detail_reservation_number').textContent = commission.reservation_number;
    document.getElementById('detail_reservation_date').textContent = 
        new Date(commission.reservation_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'});
    
    // Property Information
    document.getElementById('detail_project_name').textContent = commission.project_name;
    document.getElementById('detail_plot_number').textContent = 'Plot ' + commission.plot_number;
    document.getElementById('detail_plot_area').textContent = parseFloat(commission.area).toLocaleString() + ' m²';
    
    // Commission Calculation
    document.getElementById('detail_contract_value').textContent = 
        'TZS ' + parseFloat(commission.contract_value).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('detail_commission_rate').textContent = 
        parseFloat(commission.commission_percentage).toFixed(2) + '%';
    document.getElementById('detail_gross_commission').textContent = 
        'TZS ' + parseFloat(commission.commission_amount).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('detail_withholding_tax').textContent = 
        'TZS ' + parseFloat(commission.withholding_tax).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('detail_net_commission').textContent = 
        'TZS ' + parseFloat(commission.entitled_commission).toLocaleString('en-US', {minimumFractionDigits: 2});
    
    // Payment Status
    const statusHeader = document.getElementById('detail_status_header');
    const statusContent = document.getElementById('detail_payment_status');
    
    if (commission.payment_status === 'paid') {
        statusHeader.style.background = '#28a745';
        statusHeader.style.color = 'white';
        statusContent.innerHTML = 
            '<div class="alert alert-success mb-0">' +
            '<h5><i class="fas fa-check-circle"></i> PAID</h5>' +
            '<p class="mb-0">This commission has been paid.</p>' +
            '</div>';
    } else if (commission.payment_status === 'cancelled') {
        statusHeader.style.background = '#dc3545';
        statusHeader.style.color = 'white';
        statusContent.innerHTML = 
            '<div class="alert alert-danger mb-0">' +
            '<h5><i class="fas fa-ban"></i> CANCELLED</h5>' +
            '<p class="mb-0">This commission has been cancelled.</p>' +
            '</div>';
    } else {
        statusHeader.style.background = '#ffc107';
        statusHeader.style.color = '#856404';
        statusContent.innerHTML = 
            '<div class="alert alert-warning mb-0">' +
            '<h5><i class="fas fa-clock"></i> PENDING</h5>' +
            '<p class="mb-0">This commission is awaiting payment approval.</p>' +
            (commission.days_since_created > 0 ? '<p class="mb-0"><strong>Days Pending:</strong> ' + commission.days_since_created + ' days</p>' : '') +
            '</div>';
    }
    
    // Remarks
    if (commission.remarks && commission.remarks.trim() !== '') {
        document.getElementById('detail_remarks_card').style.display = 'block';
        document.getElementById('detail_remarks').textContent = commission.remarks;
    } else {
        document.getElementById('detail_remarks_card').style.display = 'none';
    }
    
    // Created Information
    if (commission.created_at) {
        document.getElementById('detail_created_card').style.display = 'block';
        document.getElementById('detail_created_at').textContent = 
            new Date(commission.created_at).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'});
        document.getElementById('detail_created_by').textContent = commission.created_by_name || 'System';
    }
    
    // Show modal
    $('#viewCommissionModal').modal('show');
}

// Form validation
document.getElementById('addCommissionForm').addEventListener('submit', function(e) {
    const reservationId = document.getElementById('reservation_id').value;
    const percentage = parseFloat(document.getElementById('commission_percentage').value);
    
    if (!reservationId) {
        e.preventDefault();
        alert('Please select a reservation');
        return false;
    }
    
    if (!percentage || percentage <= 0 || percentage > 100) {
        e.preventDefault();
        alert('Please enter a valid commission percentage (0-100)');
        return false;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>