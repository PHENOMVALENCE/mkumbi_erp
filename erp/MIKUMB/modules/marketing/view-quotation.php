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

$errors = [];
$success = '';

// Get quotation ID from URL
$quotation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$quotation_id) {
    header('Location: sales-quotations.php');
    exit;
}

// Fetch quotation details
try {
    $quotation_query = "
        SELECT 
            sq.*,
            c.full_name as customer_name,
            c.phone as customer_phone,
            c.email as customer_email,
            l.full_name as lead_name,
            l.phone as lead_phone,
            p.plot_number,
            p.block_number,
            p.area,
            pr.project_name,
            u.full_name as created_by_name
        FROM sales_quotations sq
        LEFT JOIN customers c ON sq.customer_id = c.customer_id
        LEFT JOIN leads l ON sq.lead_id = l.lead_id
        LEFT JOIN plots p ON sq.plot_id = p.plot_id
        LEFT JOIN projects pr ON p.project_id = pr.project_id
        LEFT JOIN users u ON sq.created_by = u.user_id
        WHERE sq.quotation_id = ? AND sq.company_id = ?
    ";
    $stmt = $conn->prepare($quotation_query);
    $stmt->execute([$quotation_id, $company_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quotation) {
        header('Location: sales-quotations.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching quotation: " . $e->getMessage());
    $errors[] = "Failed to load quotation details.";
}

// Fetch company details for quotation header
try {
    $company_query = "SELECT * FROM companies WHERE company_id = ?";
    $stmt = $conn->prepare($company_query);
    $stmt->execute([$company_id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching company: " . $e->getMessage());
    $company = null;
}

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try {
            if ($action === 'accept') {
                // Accept quotation
                $update_query = "
                    UPDATE sales_quotations 
                    SET status = 'accepted',
                        accepted_date = CURDATE(),
                        updated_at = NOW()
                    WHERE quotation_id = ? AND company_id = ?
                ";
                $stmt = $conn->prepare($update_query);
                $stmt->execute([$quotation_id, $company_id]);
                
                $success = "Quotation accepted successfully!";
                
            } elseif ($action === 'reject') {
                // Reject quotation
                $rejection_reason = trim($_POST['rejection_reason'] ?? '');
                
                $update_query = "
                    UPDATE sales_quotations 
                    SET status = 'rejected',
                        rejection_reason = ?,
                        updated_at = NOW()
                    WHERE quotation_id = ? AND company_id = ?
                ";
                $stmt = $conn->prepare($update_query);
                $stmt->execute([$rejection_reason, $quotation_id, $company_id]);
                
                $success = "Quotation rejected.";
                
            } elseif ($action === 'send') {
                // Mark as sent
                $update_query = "
                    UPDATE sales_quotations 
                    SET status = 'sent',
                        updated_at = NOW()
                    WHERE quotation_id = ? AND company_id = ?
                ";
                $stmt = $conn->prepare($update_query);
                $stmt->execute([$quotation_id, $company_id]);
                
                $success = "Quotation marked as sent!";
            }
            
            // Refresh quotation data
            $stmt = $conn->prepare($quotation_query);
            $stmt->execute([$quotation_id, $company_id]);
            $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error updating quotation: " . $e->getMessage());
            $errors[] = "Failed to update quotation status.";
        }
    }
}

// Helper function for status badges
function getStatusBadge($status) {
    $badges = [
        'draft' => 'badge bg-secondary',
        'sent' => 'badge bg-info',
        'viewed' => 'badge bg-primary',
        'accepted' => 'badge bg-success',
        'rejected' => 'badge bg-danger',
        'expired' => 'badge bg-warning text-dark',
        'revised' => 'badge bg-purple'
    ];
    return $badges[$status] ?? 'badge bg-secondary';
}

// Calculate if expired
$is_expired = false;
if (!empty($quotation['valid_until_date']) && strtotime($quotation['valid_until_date']) < time() && $quotation['status'] === 'sent') {
    $is_expired = true;
}

$page_title = 'Quotation Details';
require_once '../../includes/header.php';
?>

<style>
.quotation-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.quotation-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    padding-bottom: 2rem;
    border-bottom: 3px solid #17a2b8;
    margin-bottom: 2rem;
}

.company-info h2 {
    color: #2c3e50;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.company-info p {
    margin-bottom: 0.25rem;
    color: #6c757d;
}

.quotation-meta {
    text-align: right;
}

.quotation-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: #17a2b8;
    margin-bottom: 0.5rem;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
}

.info-item {
    display: flex;
    flex-direction: column;
}

.info-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #6c757d;
    margin-bottom: 0.25rem;
}

.info-value {
    color: #2c3e50;
    font-weight: 500;
}

.quotation-table {
    width: 100%;
    margin-bottom: 2rem;
}

.quotation-table th {
    background: #f8f9fa;
    padding: 1rem;
    font-weight: 700;
    color: #2c3e50;
    border-bottom: 2px solid #dee2e6;
}

.quotation-table td {
    padding: 1rem;
    border-bottom: 1px solid #dee2e6;
}

.quotation-table tr:last-child td {
    border-bottom: none;
}

.totals-section {
    display: flex;
    justify-content: flex-end;
    margin-top: 2rem;
}

.totals-box {
    min-width: 350px;
}

.total-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-bottom: 1px solid #dee2e6;
}

.total-row.grand-total {
    border-top: 3px solid #17a2b8;
    border-bottom: 3px solid #17a2b8;
    font-size: 1.25rem;
    font-weight: 700;
    color: #17a2b8;
    margin-top: 0.5rem;
}

.terms-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-top: 2rem;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.status-alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.status-alert.expired {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
}

.status-alert.accepted {
    background: #d4edda;
    border-left: 4px solid #28a745;
}

.status-alert.rejected {
    background: #f8d7da;
    border-left: 4px solid #dc3545;
}

.bg-purple {
    background-color: #6f42c1;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .quotation-card {
        box-shadow: none;
        padding: 0;
    }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4 no-print">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice text-info me-2"></i>Quotation Details
                </h1>
                <p class="text-muted small mb-0 mt-1"><?php echo htmlspecialchars($quotation['quotation_number']); ?></p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="sales-quotations.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Quotations
                    </a>
                    <button onclick="window.print()" class="btn btn-info">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        
        <!-- Success/Error Messages -->
        <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show no-print">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show no-print">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php foreach ($errors as $error): ?>
                <div><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Status Alerts -->
        <?php if ($is_expired): ?>
        <div class="status-alert expired no-print">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Expired:</strong> This quotation expired on <?php echo date('M d, Y', strtotime($quotation['valid_until_date'])); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($quotation['status'] === 'accepted'): ?>
        <div class="status-alert accepted no-print">
            <i class="fas fa-check-circle me-2"></i>
            <strong>Accepted:</strong> This quotation was accepted on <?php echo date('M d, Y', strtotime($quotation['accepted_date'])); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($quotation['status'] === 'rejected'): ?>
        <div class="status-alert rejected no-print">
            <i class="fas fa-times-circle me-2"></i>
            <strong>Rejected:</strong> <?php echo htmlspecialchars($quotation['rejection_reason'] ?? 'No reason provided'); ?>
        </div>
        <?php endif; ?>

        <!-- Quotation Document -->
        <div class="quotation-card">
            
            <!-- Header -->
            <div class="quotation-header">
                <div class="company-info">
                    <?php if ($company): ?>
                    <h2><?php echo htmlspecialchars($company['company_name']); ?></h2>
                    <?php if (!empty($company['physical_address'])): ?>
                        <p><?php echo htmlspecialchars($company['physical_address']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($company['phone'])): ?>
                        <p><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($company['phone']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($company['email'])): ?>
                        <p><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($company['email']); ?></p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <div class="quotation-meta">
                    <div class="quotation-number"><?php echo htmlspecialchars($quotation['quotation_number']); ?></div>
                    <span class="<?php echo getStatusBadge($quotation['status']); ?>">
                        <?php echo ucfirst($quotation['status']); ?>
                    </span>
                </div>
            </div>

            <!-- Quotation Info -->
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Quotation Date:</span>
                    <span class="info-value"><?php echo date('M d, Y', strtotime($quotation['quotation_date'])); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Valid Until:</span>
                    <span class="info-value">
                        <?php echo !empty($quotation['valid_until_date']) ? date('M d, Y', strtotime($quotation['valid_until_date'])) : 'N/A'; ?>
                    </span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Quotation Type:</span>
                    <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $quotation['quotation_type'])); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Created By:</span>
                    <span class="info-value"><?php echo htmlspecialchars($quotation['created_by_name'] ?? 'N/A'); ?></span>
                </div>
            </div>

            <!-- Customer/Lead Info -->
            <div class="section-title">
                <?php if (!empty($quotation['customer_id'])): ?>
                    Customer Information
                <?php else: ?>
                    Lead Information
                <?php endif; ?>
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Name:</span>
                    <span class="info-value">
                        <?php 
                        if (!empty($quotation['customer_id'])) {
                            echo htmlspecialchars($quotation['customer_name']);
                        } else {
                            echo htmlspecialchars($quotation['lead_name']);
                        }
                        ?>
                    </span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Phone:</span>
                    <span class="info-value">
                        <?php 
                        if (!empty($quotation['customer_id'])) {
                            echo htmlspecialchars($quotation['customer_phone']);
                        } else {
                            echo htmlspecialchars($quotation['lead_phone']);
                        }
                        ?>
                    </span>
                </div>
                
                <?php if (!empty($quotation['customer_email']) || !empty($quotation['lead_email'])): ?>
                <div class="info-item">
                    <span class="info-label">Email:</span>
                    <span class="info-value">
                        <?php 
                        if (!empty($quotation['customer_id'])) {
                            echo htmlspecialchars($quotation['customer_email'] ?? 'N/A');
                        }
                        ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Plot Information (if applicable) -->
            <?php if (!empty($quotation['plot_id'])): ?>
            <div class="section-title">Plot Information</div>
            
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Project:</span>
                    <span class="info-value"><?php echo htmlspecialchars($quotation['project_name']); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Plot Number:</span>
                    <span class="info-value">
                        <?php echo htmlspecialchars($quotation['plot_number']); ?>
                        <?php if (!empty($quotation['block_number'])): ?>
                            - Block <?php echo htmlspecialchars($quotation['block_number']); ?>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Plot Size:</span>
                    <span class="info-value"><?php echo number_format($quotation['area'], 2); ?> sqm</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Items/Services Table -->
            <div class="section-title">Quotation Details</div>
            
            <table class="quotation-table">
                <thead>
                    <tr>
                        <th width="50%">Description</th>
                        <th width="15%" class="text-end">Unit Price</th>
                        <th width="15%" class="text-end">Quantity</th>
                        <th width="20%" class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($quotation['plot_id'])): ?>
                        <tr>
                            <td>
                                <strong>Plot Sale</strong><br>
                                Plot <?php echo htmlspecialchars($quotation['plot_number']); ?> 
                                - <?php echo htmlspecialchars($quotation['project_name']); ?><br>
                                <small class="text-muted"><?php echo number_format($quotation['area'], 2); ?> sqm</small>
                            </td>
                            <td class="text-end">TSH <?php echo number_format($quotation['subtotal'], 2); ?></td>
                            <td class="text-end">1</td>
                            <td class="text-end"><strong>TSH <?php echo number_format($quotation['subtotal'], 2); ?></strong></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">
                                <em>No items specified</em>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Totals -->
            <div class="totals-section">
                <div class="totals-box">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span><strong>TSH <?php echo number_format($quotation['subtotal'], 2); ?></strong></span>
                    </div>
                    
                    <?php if ($quotation['discount_amount'] > 0): ?>
                    <div class="total-row">
                        <span>Discount (<?php echo number_format($quotation['discount_percentage'], 2); ?>%):</span>
                        <span><strong>-TSH <?php echo number_format($quotation['discount_amount'], 2); ?></strong></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($quotation['tax_amount'] > 0): ?>
                    <div class="total-row">
                        <span>Tax:</span>
                        <span><strong>TSH <?php echo number_format($quotation['tax_amount'], 2); ?></strong></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="total-row grand-total">
                        <span>Total Amount:</span>
                        <span>TSH <?php echo number_format($quotation['total_amount'], 2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Payment Terms -->
            <?php if (!empty($quotation['payment_terms'])): ?>
            <div class="terms-section">
                <h6 class="fw-bold mb-2">Payment Terms:</h6>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($quotation['payment_terms'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- Terms & Conditions -->
            <?php if (!empty($quotation['terms_conditions'])): ?>
            <div class="terms-section">
                <h6 class="fw-bold mb-2">Terms & Conditions:</h6>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($quotation['terms_conditions'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- Internal Notes (Not for Print) -->
            <?php if (!empty($quotation['internal_notes'])): ?>
            <div class="terms-section no-print">
                <h6 class="fw-bold mb-2"><i class="fas fa-lock me-2"></i>Internal Notes:</h6>
                <p class="mb-0 text-muted"><?php echo nl2br(htmlspecialchars($quotation['internal_notes'])); ?></p>
            </div>
            <?php endif; ?>

        </div>

        <!-- Action Buttons -->
        <div class="quotation-card no-print">
            <h5 class="mb-3">
                <i class="fas fa-tasks me-2"></i>Actions
            </h5>
            
            <div class="action-buttons">
                <?php if ($quotation['status'] === 'draft'): ?>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="action" value="send">
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-paper-plane me-1"></i> Mark as Sent
                        </button>
                    </form>
                    <a href="edit-quotation.php?id=<?php echo $quotation_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit me-1"></i> Edit Quotation
                    </a>
                <?php endif; ?>
                
                <?php if (in_array($quotation['status'], ['sent', 'viewed'])): ?>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="action" value="accept">
                        <button type="submit" class="btn btn-success" onclick="return confirm('Accept this quotation?')">
                            <i class="fas fa-check me-1"></i> Accept Quotation
                        </button>
                    </form>
                    
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                        <i class="fas fa-times me-1"></i> Reject Quotation
                    </button>
                <?php endif; ?>
                
                <?php if ($quotation['status'] === 'accepted' && empty($quotation['converted_to_reservation_id'])): ?>
                    <a href="create-reservation.php?quotation_id=<?php echo $quotation_id; ?>" class="btn btn-success">
                        <i class="fas fa-file-contract me-1"></i> Convert to Reservation
                    </a>
                <?php endif; ?>
                
                <button onclick="window.print()" class="btn btn-outline-secondary">
                    <i class="fas fa-print me-1"></i> Print Quotation
                </button>
                
                <a href="download-quotation.php?id=<?php echo $quotation_id; ?>" class="btn btn-outline-primary">
                    <i class="fas fa-download me-1"></i> Download PDF
                </a>
            </div>
        </div>

    </div>
</section>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle me-2"></i>Reject Quotation
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="reject">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection</label>
                        <textarea name="rejection_reason" 
                                  class="form-control" 
                                  rows="4" 
                                  placeholder="Please provide a reason for rejecting this quotation..."
                                  required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Quotation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php 
require_once '../../includes/footer.php';
?>