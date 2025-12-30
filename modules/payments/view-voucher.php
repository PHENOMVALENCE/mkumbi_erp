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

// Get voucher ID
$voucher_id = $_GET['id'] ?? 0;

if (!$voucher_id) {
    $_SESSION['error_message'] = "Invalid voucher ID.";
    header("Location: vouchers.php");
    exit;
}

// Fetch voucher details
try {
    $query = "
        SELECT 
            pv.*,
            c.customer_id,
            c.full_name as customer_name,
            c.phone as customer_phone,
            c.email as customer_email,
            c.address as customer_address,
            r.reservation_number,
            r.total_amount as reservation_total,
            r.down_payment,
            pl.plot_id,
            pl.plot_number,
            pl.block_number,
            pl.area,
            pr.project_name,
            pr.physical_location as project_location,
            p.payment_number,
            p.payment_method,
            p.payment_date,
            creator.full_name as created_by_name,
            approver.full_name as approved_by_name
        FROM payment_vouchers pv
        LEFT JOIN customers c ON pv.customer_id = c.customer_id
        LEFT JOIN reservations r ON pv.reservation_id = r.reservation_id
        LEFT JOIN plots pl ON r.plot_id = pl.plot_id
        LEFT JOIN projects pr ON pl.project_id = pr.project_id
        LEFT JOIN payments p ON pv.payment_id = p.payment_id
        LEFT JOIN users creator ON pv.created_by = creator.user_id
        LEFT JOIN users approver ON pv.approved_by = approver.user_id
        WHERE pv.voucher_id = ? AND pv.company_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$voucher_id, $company_id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$voucher) {
        $_SESSION['error_message'] = "Voucher not found.";
        header("Location: vouchers.php");
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching voucher: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading voucher details.";
    header("Location: vouchers.php");
    exit;
}

$page_title = 'View Voucher - ' . $voucher['voucher_number'];
require_once '../../includes/header.php';
?>

<style>
.voucher-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.voucher-header {
    border-bottom: 3px solid #007bff;
    padding-bottom: 1.5rem;
    margin-bottom: 1.5rem;
}

.voucher-number {
    font-size: 1.5rem;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    color: #007bff;
}

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
}

.status-badge.approved {
    background: #d4edda;
    color: #155724;
}

.status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.status-badge.rejected {
    background: #f8d7da;
    color: #721c24;
}

.type-badge {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
}

.type-badge.payment {
    background: #d1ecf1;
    color: #0c5460;
}

.type-badge.receipt {
    background: #d4edda;
    color: #155724;
}

.type-badge.refund {
    background: #fff3cd;
    color: #856404;
}

.type-badge.adjustment {
    background: #e2e3e5;
    color: #383d41;
}

.info-section {
    margin-bottom: 2rem;
}

.info-section h5 {
    color: #495057;
    font-weight: 600;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9ecef;
}

.info-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f8f9fa;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    width: 200px;
    flex-shrink: 0;
}

.info-value {
    color: #212529;
    flex-grow: 1;
}

.amount-display {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    text-align: center;
    margin-bottom: 2rem;
}

.amount-label {
    font-size: 0.9rem;
    opacity: 0.9;
    margin-bottom: 0.5rem;
}

.amount-value {
    font-size: 2.5rem;
    font-weight: 700;
}

.print-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-top: 2rem;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .voucher-card {
        box-shadow: none;
        padding: 1rem;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4 no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice text-primary me-2"></i>Voucher Details
                </h1>
                <p class="text-muted small mb-0 mt-1"><?php echo htmlspecialchars($voucher['voucher_number']); ?></p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="vouchers.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to List
                    </a>
                    <button onclick="window.print()" class="btn btn-primary me-2">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                    <?php if ($voucher['approval_status'] == 'pending'): ?>
                    <a href="approve-voucher.php?id=<?php echo $voucher_id; ?>" class="btn btn-success">
                        <i class="fas fa-check me-1"></i> Approve
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php 
            echo htmlspecialchars($_SESSION['success_message']); 
            unset($_SESSION['success_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="voucher-card">
            <!-- Voucher Header -->
            <div class="voucher-header">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="voucher-number"><?php echo htmlspecialchars($voucher['voucher_number']); ?></div>
                        <div class="mt-2">
                            <span class="type-badge <?php echo $voucher['voucher_type']; ?> me-2">
                                <?php echo ucfirst($voucher['voucher_type']); ?> Voucher
                            </span>
                            <span class="status-badge <?php echo $voucher['approval_status']; ?>">
                                <?php echo ucfirst($voucher['approval_status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="text-muted">Date</div>
                        <div class="h5 mb-0"><?php echo date('F d, Y', strtotime($voucher['voucher_date'])); ?></div>
                    </div>
                </div>
            </div>

            <!-- Amount Display -->
            <div class="amount-display">
                <div class="amount-label">VOUCHER AMOUNT</div>
                <div class="amount-value">TSH <?php echo number_format($voucher['amount'], 2); ?></div>
                <div class="amount-label mt-2">
                    <?php echo ucwords(str_replace('_', ' ', strtolower(convertNumberToWords($voucher['amount'])))); ?> Only
                </div>
            </div>

            <div class="row">
                <!-- Customer Information -->
                <div class="col-md-6">
                    <div class="info-section">
                        <h5><i class="fas fa-user me-2 text-primary"></i>Customer Information</h5>
                        <?php if (!empty($voucher['customer_name'])): ?>
                        <div class="info-row">
                            <div class="info-label">Name:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['customer_name']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['customer_phone'])): ?>
                        <div class="info-row">
                            <div class="info-label">Phone:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['customer_phone']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['customer_email'])): ?>
                        <div class="info-row">
                            <div class="info-label">Email:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['customer_email']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['customer_address'])): ?>
                        <div class="info-row">
                            <div class="info-label">Address:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['customer_address']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Details -->
                <div class="col-md-6">
                    <div class="info-section">
                        <h5><i class="fas fa-money-bill-wave me-2 text-success"></i>Payment Details</h5>
                        <?php if (!empty($voucher['payment_number'])): ?>
                        <div class="info-row">
                            <div class="info-label">Payment #:</div>
                            <div class="info-value">
                                <a href="view.php?id=<?php echo $voucher['payment_id']; ?>">
                                    <?php echo htmlspecialchars($voucher['payment_number']); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['payment_method'])): ?>
                        <div class="info-row">
                            <div class="info-label">Method:</div>
                            <div class="info-value"><?php echo ucwords(str_replace('_', ' ', $voucher['payment_method'])); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['payment_date'])): ?>
                        <div class="info-row">
                            <div class="info-label">Payment Date:</div>
                            <div class="info-value"><?php echo date('F d, Y', strtotime($voucher['payment_date'])); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['reservation_number'])): ?>
                        <div class="info-row">
                            <div class="info-label">Reservation #:</div>
                            <div class="info-value">
                                <a href="../reservations/view.php?id=<?php echo $voucher['reservation_id']; ?>">
                                    <?php echo htmlspecialchars($voucher['reservation_number']); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Plot Information -->
                <?php if (!empty($voucher['plot_number'])): ?>
                <div class="col-md-6">
                    <div class="info-section">
                        <h5><i class="fas fa-map-marked-alt me-2 text-info"></i>Plot Information</h5>
                        <div class="info-row">
                            <div class="info-label">Plot Number:</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($voucher['plot_number']); ?>
                                <?php if (!empty($voucher['block_number'])): ?>
                                - Block <?php echo htmlspecialchars($voucher['block_number']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($voucher['project_name'])): ?>
                        <div class="info-row">
                            <div class="info-label">Project:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['project_name']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['area'])): ?>
                        <div class="info-row">
                            <div class="info-label">Area:</div>
                            <div class="info-value"><?php echo number_format($voucher['area'], 2); ?> sq.m</div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['project_location'])): ?>
                        <div class="info-row">
                            <div class="info-label">Location:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['project_location']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Bank/Transaction Details -->
                <div class="col-md-6">
                    <div class="info-section">
                        <h5><i class="fas fa-university me-2 text-warning"></i>Bank/Transaction Details</h5>
                        <?php if (!empty($voucher['bank_name'])): ?>
                        <div class="info-row">
                            <div class="info-label">Bank Name:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['bank_name']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['account_number'])): ?>
                        <div class="info-row">
                            <div class="info-label">Account Number:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['account_number']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['cheque_number'])): ?>
                        <div class="info-row">
                            <div class="info-label">Cheque Number:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['cheque_number']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($voucher['transaction_reference'])): ?>
                        <div class="info-row">
                            <div class="info-label">Reference:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['transaction_reference']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <?php if (!empty($voucher['description'])): ?>
            <div class="info-section">
                <h5><i class="fas fa-file-alt me-2 text-secondary"></i>Description</h5>
                <div class="p-3 bg-light rounded">
                    <?php echo nl2br(htmlspecialchars($voucher['description'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Approval Information -->
            <div class="row">
                <div class="col-md-6">
                    <div class="info-section">
                        <h5><i class="fas fa-user-plus me-2 text-info"></i>Created By</h5>
                        <div class="info-row">
                            <div class="info-label">User:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['created_by_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Date/Time:</div>
                            <div class="info-value"><?php echo date('F d, Y h:i A', strtotime($voucher['created_at'])); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($voucher['approval_status'] == 'approved' && !empty($voucher['approved_by_name'])): ?>
                <div class="col-md-6">
                    <div class="info-section">
                        <h5><i class="fas fa-check-circle me-2 text-success"></i>Approved By</h5>
                        <div class="info-row">
                            <div class="info-label">User:</div>
                            <div class="info-value"><?php echo htmlspecialchars($voucher['approved_by_name']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Date/Time:</div>
                            <div class="info-value"><?php echo date('F d, Y h:i A', strtotime($voucher['approved_at'])); ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Action Buttons (No Print) -->
            <div class="print-section no-print">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <button onclick="window.print()" class="btn btn-primary">
                            <i class="fas fa-print me-1"></i> Print Voucher
                        </button>
                        <a href="generate-voucher-pdf.php?id=<?php echo $voucher_id; ?>" class="btn btn-secondary" target="_blank">
                            <i class="fas fa-file-pdf me-1"></i> Download PDF
                        </a>
                    </div>
                    <div>
                        <?php if ($voucher['approval_status'] == 'pending'): ?>
                        <a href="approve-voucher.php?id=<?php echo $voucher_id; ?>" class="btn btn-success">
                            <i class="fas fa-check me-1"></i> Approve Voucher
                        </a>
                        <a href="reject-voucher.php?id=<?php echo $voucher_id; ?>" class="btn btn-danger">
                            <i class="fas fa-times me-1"></i> Reject Voucher
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<?php 
// Simple number to words conversion function
function convertNumberToWords($number) {
    $ones = array(
        '', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE',
        'TEN', 'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN',
        'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'
    );
    
    $tens = array(
        '', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'
    );
    
    $number = (int)$number;
    
    if ($number < 20) {
        return $ones[$number];
    } elseif ($number < 100) {
        return $tens[intval($number / 10)] . ' ' . $ones[$number % 10];
    } elseif ($number < 1000) {
        return $ones[intval($number / 100)] . ' HUNDRED ' . convertNumberToWords($number % 100);
    } elseif ($number < 1000000) {
        return convertNumberToWords(intval($number / 1000)) . ' THOUSAND ' . convertNumberToWords($number % 1000);
    } elseif ($number < 1000000000) {
        return convertNumberToWords(intval($number / 1000000)) . ' MILLION ' . convertNumberToWords($number % 1000000);
    }
    
    return 'AMOUNT TOO LARGE';
}

require_once '../../includes/footer.php';
?>