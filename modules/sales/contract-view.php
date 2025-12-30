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

// Get contract ID with proper validation
$contract_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$contract_id || $contract_id <= 0) {
    $_SESSION['error'] = "Contract ID is required.";
    header('Location: contracts.php');
    exit;
}

// Fetch contract details with all related information
try {
    $query = "
        SELECT 
            pc.*,
            -- Reservation details
            r.reservation_id, r.reservation_number, r.reservation_date, 
            r.total_amount as reservation_total, r.down_payment, r.payment_periods,
            r.installment_amount, r.discount_percentage, r.discount_amount,
            -- Customer details
            c.customer_id, c.full_name as customer_name, c.first_name, c.middle_name, c.last_name,
            COALESCE(c.phone, c.phone1) as customer_phone, c.alternative_phone,
            c.email as customer_email, c.national_id as customer_id_number,
            c.address as customer_address, c.region as customer_region,
            c.district as customer_district, c.ward as customer_ward,
            -- Plot details
            p.plot_id, p.plot_number, p.block_number, p.area, p.selling_price,
            p.price_per_sqm, p.survey_plan_number, p.town_plan_number,
            p.gps_coordinates, p.corner_plot,
            -- Project details
            pr.project_id, pr.project_name, pr.project_code,
            pr.region_name as project_region, pr.district_name as project_district,
            pr.ward_name as project_ward, pr.village_name as project_village,
            pr.physical_location as project_location,
            -- Company details
            co.company_name, co.registration_number as company_reg_number,
            co.tax_identification_number as company_tin, co.email as company_email,
            co.phone as company_phone, co.physical_address as company_address,
            -- Created by user
            u.full_name as created_by_name,
            -- Approved/Cancelled by users
            u2.full_name as approved_by_name,
            u3.full_name as cancelled_by_name
        FROM plot_contracts pc
        INNER JOIN reservations r ON pc.reservation_id = r.reservation_id
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects pr ON p.project_id = pr.project_id
        INNER JOIN companies co ON pc.company_id = co.company_id
        LEFT JOIN users u ON pc.created_by = u.user_id
        LEFT JOIN users u2 ON pc.approved_by = u2.user_id
        LEFT JOIN users u3 ON pc.cancelled_by = u3.user_id
        WHERE pc.contract_id = ? AND pc.company_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$contract_id, $company_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        $_SESSION['error'] = "Contract not found.";
        header('Location: contracts.php');
        exit;
    }
    
    // Fetch payment history for this reservation
    $payment_query = "
        SELECT 
            p.payment_id, p.payment_date, p.payment_number,
            p.amount, p.payment_method, p.reference_number,
            p.status, p.remarks,
            u.full_name as created_by_name
        FROM payments p
        LEFT JOIN users u ON p.created_by = u.user_id
        WHERE p.reservation_id = ? AND p.company_id = ?
        AND p.status IN ('approved', 'paid')
        ORDER BY p.payment_date ASC
    ";
    $stmt = $conn->prepare($payment_query);
    $stmt->execute([$contract['reservation_id'], $company_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate payment totals
    $total_paid = array_sum(array_column($payments, 'amount'));
    $balance_remaining = $contract['reservation_total'] - $total_paid;
    
} catch (PDOException $e) {
    error_log("Error fetching contract: " . $e->getMessage());
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: contracts.php');
    exit;
}

$page_title = 'Contract Details - ' . $contract['contract_number'];
require_once '../../includes/header.php';
?>

<style>
.contract-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 20px rgba(102, 126, 234, 0.4);
}

.info-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    border-left: 4px solid #667eea;
}

.info-card h5 {
    color: #667eea;
    font-weight: 600;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f0f0f0;
}

.info-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f5f5f5;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #555;
    width: 200px;
    flex-shrink: 0;
}

.info-value {
    color: #333;
    flex-grow: 1;
}

.status-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-draft {
    background-color: #fef3c7;
    color: #92400e;
}

.status-pending_signature {
    background-color: #dbeafe;
    color: #1e40af;
}

.status-signed {
    background-color: #d1fae5;
    color: #065f46;
}

.status-completed {
    background-color: #dcfce7;
    color: #166534;
}

.status-cancelled {
    background-color: #fee2e2;
    color: #991b1b;
}

.payment-table {
    margin-top: 1rem;
}

.payment-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #555;
}

.btn-action {
    margin: 0 0.25rem;
}

.financial-summary {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
}

.financial-summary .summary-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.financial-summary .summary-item:last-child {
    border-bottom: none;
    font-size: 1.1rem;
    font-weight: 700;
    margin-top: 0.5rem;
    padding-top: 1rem;
    border-top: 2px solid rgba(255,255,255,0.3);
}

@media print {
    .no-print {
        display: none !important;
    }
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="contract-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="mb-2">
                    <i class="fas fa-file-contract me-2"></i>
                    Contract Details
                </h3>
                <h4 class="mb-0"><?php echo htmlspecialchars($contract['contract_number']); ?></h4>
            </div>
            <div class="no-print">
                <span class="status-badge status-<?php echo $contract['status']; ?>">
                    <?php echo ucwords(str_replace('_', ' ', $contract['status'])); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="mb-4 no-print">
        <a href="contracts.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Contracts
        </a>
        <a href="contract-print.php?id=<?php echo $contract_id; ?>" target="_blank" class="btn btn-primary">
            <i class="fas fa-print me-2"></i>Print Contract
        </a>
        <a href="contract-edit.php?id=<?php echo $contract_id; ?>" class="btn btn-warning">
            <i class="fas fa-edit me-2"></i>Edit
        </a>
        <?php if ($contract['status'] == 'draft'): ?>
        <button class="btn btn-success" onclick="changeStatus('pending_signature')">
            <i class="fas fa-check me-2"></i>Send for Signature
        </button>
        <?php endif; ?>
        <?php if ($contract['status'] == 'pending_signature'): ?>
        <button class="btn btn-success" onclick="changeStatus('signed')">
            <i class="fas fa-signature me-2"></i>Mark as Signed
        </button>
        <?php endif; ?>
        <?php if ($contract['status'] == 'signed'): ?>
        <button class="btn btn-info" onclick="changeStatus('completed')">
            <i class="fas fa-check-double me-2"></i>Mark as Completed
        </button>
        <?php endif; ?>
    </div>

    <div class="row">
        <!-- Left Column -->
        <div class="col-lg-8">
            <!-- Contract Information -->
            <div class="info-card">
                <h5><i class="fas fa-file-alt me-2"></i>Contract Information</h5>
                <div class="info-row">
                    <div class="info-label">Contract Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($contract['contract_number']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Contract Date:</div>
                    <div class="info-value"><?php echo date('d M Y', strtotime($contract['contract_date'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Signing Date:</div>
                    <div class="info-value">
                        <?php echo $contract['signing_date'] ? date('d M Y', strtotime($contract['signing_date'])) : 'Not yet signed'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Expiry Date:</div>
                    <div class="info-value">
                        <?php echo $contract['expiry_date'] ? date('d M Y', strtotime($contract['expiry_date'])) : 'N/A'; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div class="info-value">
                        <span class="status-badge status-<?php echo $contract['status']; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $contract['status'])); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Customer Information -->
            <div class="info-card">
                <h5><i class="fas fa-user me-2"></i>Customer Information</h5>
                <div class="info-row">
                    <div class="info-label">Full Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($contract['customer_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">National ID:</div>
                    <div class="info-value"><?php echo !empty($contract['customer_id_number']) ? htmlspecialchars($contract['customer_id_number']) : 'N/A'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Phone:</div>
                    <div class="info-value"><?php echo !empty($contract['customer_phone']) ? htmlspecialchars($contract['customer_phone']) : 'N/A'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value"><?php echo !empty($contract['customer_email']) ? htmlspecialchars($contract['customer_email']) : 'N/A'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Address:</div>
                    <div class="info-value">
                        <?php 
                        $address_parts = array_filter([
                            $contract['customer_address'],
                            $contract['customer_ward'],
                            $contract['customer_district'],
                            $contract['customer_region']
                        ]);
                        echo htmlspecialchars(implode(', ', $address_parts) ?: 'N/A');
                        ?>
                    </div>
                </div>
            </div>

            <!-- Plot Information -->
            <div class="info-card">
                <h5><i class="fas fa-map-marked-alt me-2"></i>Plot Information</h5>
                <div class="info-row">
                    <div class="info-label">Plot Number:</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($contract['plot_number']); ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Block Number:</div>
                    <div class="info-value"><?php echo !empty($contract['block_number']) ? htmlspecialchars($contract['block_number']) : 'N/A'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Area:</div>
                    <div class="info-value"><?php echo number_format($contract['area'], 2); ?> sqm</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Price per SQM:</div>
                    <div class="info-value">TZS <?php echo number_format($contract['price_per_sqm'], 2); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Total Price:</div>
                    <div class="info-value"><strong>TZS <?php echo number_format($contract['selling_price'], 2); ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Corner Plot:</div>
                    <div class="info-value">
                        <?php echo $contract['corner_plot'] ? '<span class="badge bg-info">Yes</span>' : 'No'; ?>
                    </div>
                </div>
                <?php if (!empty($contract['survey_plan_number'])): ?>
                <div class="info-row">
                    <div class="info-label">Survey Plan No:</div>
                    <div class="info-value"><?php echo htmlspecialchars($contract['survey_plan_number']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($contract['gps_coordinates'])): ?>
                <div class="info-row">
                    <div class="info-label">GPS Coordinates:</div>
                    <div class="info-value"><?php echo htmlspecialchars($contract['gps_coordinates']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Project Information -->
            <div class="info-card">
                <h5><i class="fas fa-building me-2"></i>Project Information</h5>
                <div class="info-row">
                    <div class="info-label">Project Name:</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($contract['project_name']); ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Project Code:</div>
                    <div class="info-value"><?php echo htmlspecialchars($contract['project_code']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Location:</div>
                    <div class="info-value">
                        <?php 
                        $location_parts = array_filter([
                            $contract['project_village'],
                            $contract['project_ward'],
                            $contract['project_district'],
                            $contract['project_region']
                        ]);
                        echo htmlspecialchars(implode(', ', $location_parts));
                        ?>
                    </div>
                </div>
                <?php if (!empty($contract['project_location'])): ?>
                <div class="info-row">
                    <div class="info-label">Physical Location:</div>
                    <div class="info-value"><?php echo htmlspecialchars($contract['project_location']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payment History -->
            <div class="info-card">
                <h5><i class="fas fa-money-bill-wave me-2"></i>Payment History</h5>
                <?php if (count($payments) > 0): ?>
                <div class="table-responsive payment-table">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payment No.</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($payment['payment_number']); ?></td>
                                <td><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                <td><?php echo !empty($payment['reference_number']) ? htmlspecialchars($payment['reference_number']) : '-'; ?></td>
                                <td class="text-end"><strong>TZS <?php echo number_format($payment['amount'], 2); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="4" class="text-end"><strong>Total Paid:</strong></td>
                                <td class="text-end"><strong>TZS <?php echo number_format($total_paid, 2); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted mb-0">No payments recorded yet.</p>
                <?php endif; ?>
            </div>

            <!-- Contract Terms -->
            <?php if (!empty($contract['terms_conditions'])): ?>
            <div class="info-card">
                <h5><i class="fas fa-list-alt me-2"></i>Terms & Conditions</h5>
                <div style="white-space: pre-wrap;"><?php echo htmlspecialchars($contract['terms_conditions']); ?></div>
            </div>
            <?php endif; ?>

            <!-- Notes -->
            <?php if (!empty($contract['notes'])): ?>
            <div class="info-card">
                <h5><i class="fas fa-sticky-note me-2"></i>Notes</h5>
                <div style="white-space: pre-wrap;"><?php echo htmlspecialchars($contract['notes']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Column -->
        <div class="col-lg-4">
            <!-- Financial Summary -->
            <div class="financial-summary">
                <h5 class="mb-3"><i class="fas fa-calculator me-2"></i>Financial Summary</h5>
                <div class="summary-item">
                    <span>Total Contract Value:</span>
                    <strong>TZS <?php echo number_format($contract['reservation_total'], 2); ?></strong>
                </div>
                <div class="summary-item">
                    <span>Down Payment:</span>
                    <strong>TZS <?php echo number_format($contract['down_payment'], 2); ?></strong>
                </div>
                <div class="summary-item">
                    <span>Total Paid:</span>
                    <strong>TZS <?php echo number_format($total_paid, 2); ?></strong>
                </div>
                <div class="summary-item">
                    <span>Balance Remaining:</span>
                    <strong>TZS <?php echo number_format($balance_remaining, 2); ?></strong>
                </div>
            </div>

            <!-- Payment Plan -->
            <div class="info-card">
                <h5><i class="fas fa-calendar-alt me-2"></i>Payment Plan</h5>
                <div class="info-row">
                    <div class="info-label">Payment Periods:</div>
                    <div class="info-value"><?php echo $contract['payment_periods']; ?> months</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Monthly Installment:</div>
                    <div class="info-value"><strong>TZS <?php echo number_format($contract['installment_amount'], 2); ?></strong></div>
                </div>
                <?php if ($contract['discount_percentage'] > 0): ?>
                <div class="info-row">
                    <div class="info-label">Discount:</div>
                    <div class="info-value">
                        <?php echo $contract['discount_percentage']; ?>% 
                        (TZS <?php echo number_format($contract['discount_amount'], 2); ?>)
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Reservation Info -->
            <div class="info-card">
                <h5><i class="fas fa-bookmark me-2"></i>Reservation Details</h5>
                <div class="info-row">
                    <div class="info-label">Reservation No:</div>
                    <div class="info-value"><?php echo htmlspecialchars($contract['reservation_number']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Reservation Date:</div>
                    <div class="info-value"><?php echo date('d M Y', strtotime($contract['reservation_date'])); ?></div>
                </div>
            </div>

            <!-- Audit Information -->
            <div class="info-card">
                <h5><i class="fas fa-history me-2"></i>Audit Trail</h5>
                <div class="info-row">
                    <div class="info-label">Created By:</div>
                    <div class="info-value"><?php echo !empty($contract['created_by_name']) ? htmlspecialchars($contract['created_by_name']) : 'System'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Created At:</div>
                    <div class="info-value"><?php echo date('d M Y H:i', strtotime($contract['created_at'])); ?></div>
                </div>
                <?php if ($contract['approved_by']): ?>
                <div class="info-row">
                    <div class="info-label">Approved By:</div>
                    <div class="info-value"><?php echo htmlspecialchars($contract['approved_by_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Approved At:</div>
                    <div class="info-value"><?php echo date('d M Y H:i', strtotime($contract['approved_at'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($contract['status'] == 'cancelled' && $contract['cancelled_by']): ?>
                <div class="info-row">
                    <div class="info-label">Cancelled By:</div>
                    <div class="info-value"><?php echo htmlspecialchars($contract['cancelled_by_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Cancelled At:</div>
                    <div class="info-value"><?php echo date('d M Y H:i', strtotime($contract['cancelled_at'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Cancellation Reason:</div>
                    <div class="info-value"><?php echo !empty($contract['cancellation_reason']) ? htmlspecialchars($contract['cancellation_reason']) : 'N/A'; ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function changeStatus(newStatus) {
    if (confirm('Are you sure you want to change the contract status to ' + newStatus.replace('_', ' ') + '?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'contract-update-status.php';
        
        const contractIdInput = document.createElement('input');
        contractIdInput.type = 'hidden';
        contractIdInput.name = 'contract_id';
        contractIdInput.value = '<?php echo $contract_id; ?>';
        form.appendChild(contractIdInput);
        
        const statusInput = document.createElement('input');
        statusInput.type = 'hidden';
        statusInput.name = 'status';
        statusInput.value = newStatus;
        form.appendChild(statusInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>